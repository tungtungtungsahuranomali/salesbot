<?php
/**
 * Bot Logic — state machine + AI hybrid untuk Sales Bot LIGAT
 */
class Bot
{
    private WuzAPI $wuzapi;
    private StateManager $state;
    private CoverageChecker $coverage;
    private ?AIClient $ai;
    private ?Geocoder $geocoder;

    private const MAX_HISTORY = 6;
    private const DEBOUNCE_SEC = 5;    // tunggu 5 detik setelah pesan terakhir
    private const MAX_WAIT_SEC = 15;   // hard limit sejak buffer pertama

    public function __construct(WuzAPI $wuzapi, StateManager $state, CoverageChecker $coverage, ?AIClient $ai = null, ?Geocoder $geocoder = null)
    {
        $this->wuzapi = $wuzapi;
        $this->state = $state;
        $this->coverage = $coverage;
        $this->ai = $ai;
        $this->geocoder = $geocoder;
    }

    /**
     * Proses pesan masuk dari webhook
     */
    public function handle(array $event): void
    {
        $info = $event['Info'] ?? [];
        $fromMe = $info['IsFromMe'] ?? false;
        $isGroup = $info['IsGroup'] ?? false;
        if ($fromMe || $isGroup) return;

        $senderAlt = $info['SenderAlt'] ?? '';
        $phone = $this->extractPhone($senderAlt);
        if (!$phone) return;

        // Cek apakah user sudah punya buffer (lagi ngirim banyak pesan)
        $userState = $this->state->get($phone);
        $alreadyBuffering = !empty($userState['pending_buffer'] ?? []);

        // Buffer message
        $this->bufferMessage($phone, $event);

        // Proses buffer lain yang expired
        $this->processExpiredBuffers();

        // Proses buffer user ini:
        // - Jika sudah punya buffer sebelumnya → user rapid-fire → tunggu expire
        // - Jika baru (1 pesan) → langsung proses
        if (!$alreadyBuffering) {
            $this->processUserBufferIfExpired($phone, true);
        } else {
            $this->processUserBufferIfExpired($phone, false);
        }
    }

    /**
     * Cek & proses buffer user tertentu jika expired
     */
    private function processUserBufferIfExpired(string $phone, bool $force): void
    {
        $userState = $this->state->get($phone);
        if (!$userState) return;

        $processAt = $userState['pending_process_at'] ?? null;
        if ($processAt && $processAt > time() && !$force) return;

        $buffer = $userState['pending_buffer'] ?? [];
        if (empty($buffer)) return;

        $this->state->update($phone, [
            'pending_buffer' => [], 'pending_buffer_created' => null, 'pending_process_at' => null,
        ]);

        // Cek lokasi
        foreach ($buffer as $b) {
            if (isset($b['raw']['locationMessage']['degreesLatitude'])) {
                $this->handleLocation($phone, $b['raw']['locationMessage']);
                return;
            }
        }

        // Cek foto tanpa caption
        $allPhotos = true;
        foreach ($buffer as $b) {
            if ($b['type'] !== 'image' && $b['type'] !== 'photo') { $allPhotos = false; break; }
        }
        if ($allPhotos && count($buffer) > 0) {
            $this->handleImage($phone);
            return;
        }

        // Gabung text
        $parts = array_map(fn($b) => $b['summary'], $buffer);
        $this->handleText($phone, implode("\n", $parts));
    }

    /**
     * Handle pesan text — AI first, fallback rule-based
     */
    private function handleText(string $phone, string $text): void
    {
        $userState = $this->state->getOrCreate($phone);
        $currentState = $userState['state'];

        // Simpan pesan user ke history
        $this->addHistory($phone, 'user', $text);

        // Coba AI dulu
        if ($this->ai) {
            $aiResult = $this->generateWithAI($phone, $text, $userState);
            if ($aiResult !== null) {
                // Simpan data form ke state jika sedang proses registrasi
                if (in_array($currentState, [STATE_COLLECTING_NAME, STATE_CLOSING])) {
                    $this->saveFormData($phone, $text);
                }

                // 🔒 Guard: jangan biarkan AI minta lokasi lagi jika sudah tercover
                if (in_array($currentState, [STATE_COVERED, STATE_OFFERING, STATE_NOT_COVERED]) && $aiResult['intent'] === 'cek_lokasi') {
                    $aiResult['intent'] = 'lainnya'; // override
                }

                // Jika AI intent cek_lokasi, coba geocode dulu sebelum response
                if ($aiResult['intent'] === 'cek_lokasi' && $this->geocoder) {
                    $coords = $this->geocoder->geocode($text);
                    if ($coords !== null) {
                        // Geocode berhasil, langsung cek coverage — skip AI response
                        $this->processGeocodedAddress($phone, $text, $coords);
                        return;
                    }
                }

                // Kirim AI response normal
                $this->addHistory($phone, 'bot', $aiResult['response']);
                $this->sendText($phone, $aiResult['response']);
                $this->applyIntent($phone, $aiResult['intent'], $currentState);

                // Jika di closing state dan user konfirmasi lengkap, forward data
                if ($currentState === STATE_CLOSING && $this->isConfirmComplete($text)) {
                    $this->forwardRegistrasi($phone);
                }
                return;
            }
        }

        // Fallback: rule-based existing
        $this->handleTextFallback($phone, $text, $userState);
    }

    // ─── Buffer / Debounce ───

    /**
     * Simpan pesan ke buffer, jangan langsung diproses
     */
    private function bufferMessage(string $phone, array $event): void
    {
        $userState = $this->state->getOrCreate($phone);
        $buffer = $userState['pending_buffer'] ?? [];
        $now = time();

        $info = $event['Info'] ?? [];
        $message = $event['Message'] ?? [];
        $msgType = $info['Type'] ?? '';
        $mediaType = $info['MediaType'] ?? '';
        $summary = '';

        if ($msgType === 'text' || $msgType === '') {
            $summary = $this->getText($message) ?? '';
        } elseif ($mediaType === 'location' || isset($message['locationMessage'])) {
            $loc = $message['locationMessage'] ?? [];
            $summary = "[Lokasi] {$loc['degreesLatitude']},{$loc['degreesLongitude']}";
        } elseif ($mediaType === 'image' || $mediaType === 'photo') {
            $caption = $message['imageMessage']['caption'] ?? '';
            $summary = $caption ? "[Foto] $caption" : "[Foto]";
        }
        if ($summary === '') return;

        $this->addHistory($phone, 'user', $summary);
        $buffer[] = ['type' => $msgType === 'text' ? 'text' : ($mediaType ?: $msgType), 'summary' => $summary, 'raw' => $message, 'time' => $now];

        $bufferCreated = $userState['pending_buffer_created'] ?? $now;
        $processAt = min($now + self::DEBOUNCE_SEC, $bufferCreated + self::MAX_WAIT_SEC);

        $this->state->update($phone, [
            'pending_buffer' => $buffer,
            'pending_buffer_created' => $bufferCreated,
            'pending_process_at' => $processAt,
        ]);
    }

    /**
     * Proses semua buffer yang sudah expired
     */
    private function processExpiredBuffers(): void
    {
        $files = glob(CONVERSATIONS_DIR . '/*.json');
        if (!$files) return;
        $now = time();

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (!$data) continue;

            $processAt = $data['pending_process_at'] ?? null;
            if (!$processAt || $processAt > $now) continue;

            $phone = $data['phone'] ?? '';
            $buffer = $data['pending_buffer'] ?? [];
            if (empty($buffer)) continue;

            $this->state->update($phone, [
                'pending_buffer' => [], 'pending_buffer_created' => null, 'pending_process_at' => null,
            ]);

            // Cek lokasi
            foreach ($buffer as $b) {
                if (isset($b['raw']['locationMessage']['degreesLatitude'])) {
                    $this->handleLocation($phone, $b['raw']['locationMessage']);
                    return;
                }
            }

            // Cek foto tanpa caption
            $allPhotos = true;
            foreach ($buffer as $b) {
                if ($b['type'] !== 'image' && $b['type'] !== 'photo') { $allPhotos = false; break; }
            }
            if ($allPhotos && count($buffer) > 0) {
                $this->handleImage($phone);
                return;
            }

            // Gabung text
            $parts = array_map(fn($b) => $b['summary'], $buffer);
            $this->handleText($phone, implode("\n", $parts));
        }
    }

    /**
     * Proses alamat yang berhasil di-geocode
     */
    private function processGeocodedAddress(string $phone, string $text, array $coords): void
    {
        $msg = "📍 *{$text}* — sebentar dicek dulu ya kaa...";
        $this->sendText($phone, $msg);
        $this->addHistory($phone, 'bot', $msg);

        // Buat virtual location data seperti dari share location
        $locationData = [
            'degreesLatitude' => $coords['lat'],
            'degreesLongitude' => $coords['lng'],
        ];
        $this->handleLocation($phone, $locationData);
    }

    /**
     * Generate response via AI
     */
    private function generateWithAI(string $phone, string $text, array $userState): ?array
    {
        $history = $userState['history'] ?? [];

        $context = [
            'state' => $userState['state'],
            'name' => $userState['name'] ?? '',
            'location' => $userState['location'] ?? null,
            'covered' => $userState['covered'] ?? null,
            'registration' => $userState['registration'] ?? [],
            'message' => $text,
            'history' => $history,
        ];

        $result = $this->ai->generateBotResponse($context);

        if ($result['response'] === null) {
            return null;
        }

        return $result;
    }

    /**
     * Apply intent dari AI ke state machine
     */
    private function applyIntent(string $phone, ?string $intent, string $currentState): void
    {
        switch ($intent) {
            case 'cek_lokasi':
                $this->state->update($phone, ['state' => STATE_AWAITING_LOCATION]);
                break;

            case 'tertarik':
                if ($currentState === STATE_COVERED) {
                    $this->state->update($phone, ['state' => STATE_OFFERING]);
                } elseif ($currentState === STATE_OFFERING) {
                    $this->state->update($phone, ['state' => STATE_COLLECTING_NAME]);
                }
                break;

            case 'tidak_minat':
                $this->state->update($phone, ['state' => STATE_START]);
                break;

            case 'beri_nama':
                $this->state->update($phone, ['state' => STATE_CLOSING]);
                break;

            case 'tanya_paket':
                if ($currentState === STATE_COVERED) {
                    $this->state->update($phone, ['state' => STATE_OFFERING]);
                }
                break;

            case 'sapa':
                if ($currentState === STATE_START || $currentState === STATE_CLOSING) {
                    $this->state->update($phone, ['state' => STATE_GREETING]);
                }
                break;

            // 'lainnya' atau null → stay di state yang sama
        }
    }

    /**
     * Fallback rule-based (existing logic)
     */
    private function handleTextFallback(string $phone, string $text, array $userState): void
    {
        $currentState = $userState['state'];
        $textLower = trim(strtolower($text));

        switch ($currentState) {
            case 'start':
                $this->sendGreeting($phone);
                $this->state->update($phone, ['state' => STATE_GREETING]);
                break;

            case 'greeting':
                if ($this->isAffirmative($textLower)) {
                    $this->askLocation($phone);
                    $this->state->update($phone, ['state' => STATE_AWAITING_LOCATION]);
                } else {
                    $this->sendText($phone, "Baik, kalau butuh bantuan nanti hubungi lagi ya 😊");
                    $this->state->update($phone, ['state' => STATE_START]);
                }
                break;

            case 'awaiting_location':
                // Cek apakah user bilang tidak bisa shareloc
                if ($this->isCantShareLocation($textLower)) {
                    $this->sendText($phone, "Baik kaka, kalau gitu coba ketik nama perumahan atau alamat lengkapnya ya, nanti saya cek secara detail");
                    break;
                }
                // Cek apakah user nanya paket (lokasi belum dicek, ttp minta shareloc)
                if ($this->isAskingPackage($textLower)) {
                    $this->sendText($phone, "Boleh minta shareloc atau alamatnya dulu kaa, biar saya cek dulu apakah area kaka tercover. Setelah itu bisa kita bahas paketnya 😊");
                    break;
                }
                // Coba geocode jika user ketik alamat
                if ($this->geocoder) {
                    $coords = $this->geocoder->geocode($text);
                    if ($coords !== null) {
                        $locData = ['degreesLatitude' => $coords['lat'], 'degreesLongitude' => $coords['lng']];
                        $msg = "📍 *{$text}* — sebentar dicek dulu ya kaa...";
                        $this->sendText($phone, $msg);
                        $this->handleLocation($phone, $locData);
                        break;
                    }
                }
                $this->sendText($phone, "Boleh minta shareloc nya kak, biar saya cek secara detail. Atau ketik aja nama daerah/perumahan kaka.");
                break;

            case 'covered':
                if ($this->isInterested($textLower)) {
                    $this->offerPackages($phone);
                    $this->state->update($phone, ['state' => STATE_OFFERING]);
                } else {
                    $this->sendText($phone, "Baik, kalau butuh bantuan nanti hubungi lagi ya 😊");
                    $this->state->update($phone, ['state' => STATE_START]);
                }
                break;

            case 'not_covered':
                if ($this->isAskOtherArea($textLower)) {
                    $this->askLocation($phone);
                    $this->state->update($phone, ['state' => STATE_AWAITING_LOCATION]);
                } else {
                    $this->sendText($phone, "Baik, kalau ada lokasi lain yang mau dicek, bilang aja ya 😊");
                    $this->state->update($phone, ['state' => STATE_START]);
                }
                break;

            case 'offering':
                if ($this->isInterested($textLower)) {
                    $this->askName($phone);
                    $this->state->update($phone, ['state' => STATE_COLLECTING_NAME]);
                } else {
                    $this->sendText($phone, "Baik, kalau berminat nanti hubungi lagi ya. Terima kasih 😊");
                    $this->state->update($phone, ['state' => STATE_START]);
                }
                break;

            case 'collecting_name':
                // Jika user kirim data form, simpan dan akui
                $this->saveFormData($phone, $text);
                if ($this->isFormData($textLower)) {
                    $this->sendText($phone, "Baik kaka, data sudah saya catat. Kalau masih ada yang kurang silakan dilengkapi ya 😊");
                    break;
                }
                $this->state->update($phone, ['name' => trim($text)]);
                $this->sendText($phone, "Terima kasih kaka! Berikut form registrasi LIGAT WiFi:\n\n" . REG_FORM);
                $this->state->update($phone, ['state' => STATE_CLOSING]);
                break;

            case 'closing':
                // Cek apakah user kirim foto (KTP/Rumah)
                if ($this->isPhotoLabel($textLower)) {
                    $this->sendText($phone, "Baik kaka, foto {$text} sudah tercatat. Kalau ada data lain yang mau dilengkapi silakan 😊");
                    break;
                }
                // Cek apakah user kirim data form (mengandung Nama, Nik, dll)
                if ($this->isFormData($textLower)) {
                    $this->sendText($phone, "Baik kaka, data sudah saya catat. Jangan lupa kirimkan juga foto KTP dan foto rumah ya 😊");
                    break;
                }
                // Fallback: serahin ke AI
                if ($this->ai) {
                    $aiResult = $this->generateWithAI($phone, $text, $userState);
                    if ($aiResult !== null && $aiResult['response'] !== null) {
                        $this->addHistory($phone, 'bot', $aiResult['response']);
                        $this->sendText($phone, $aiResult['response']);
                        break;
                    }
                }
                $this->sendText($phone, "Baik kaka, data tambahan sudah dicatat. Silakan lengkapi foto KTP dan foto rumah ya 😊");
                break;

            default:
                $this->sendGreeting($phone);
                $this->state->update($phone, ['state' => STATE_GREETING]);
                break;
        }

        // Simpan response fallback ke history
        // (history bot sudah di-log oleh AI path, fallback perlu manual)
        // Tapi kita tidak tahu response exact yang dikirim, skip aja
    }

    private function handleImage(string $phone): void
    {
        $userState = $this->state->get($phone);
        if (!$userState || $userState['state'] !== STATE_CLOSING) {
            return;
        }
        $this->sendText($phone, "Baik kaka, foto sudah saya terima. Tapi jangan lupa dikasih caption ya, ini foto KTP atau foto Rumah? 😊");
        $this->addHistory($phone, 'bot', "Foto diterima, minta keterangan");
    }

    /**
     * Handle location share
     */
    private function handleLocation(string $phone, array $location): void
    {
        $lat = $location['degreesLatitude'] ?? 0;
        $lng = $location['degreesLongitude'] ?? 0;

        if (!$lat || !$lng) {
            $this->sendText($phone, "Maaf, lokasi tidak terbaca. Coba kirim ulang lokasi kaka.");
            return;
        }

        $this->state->update($phone, [
            'location' => ['lat' => $lat, 'lng' => $lng],
            'state' => STATE_CHECKING_COVERAGE,
        ]);

        $this->sendText($phone, "📍 *Lokasi:* {$lat}, {$lng} — sebentar dicek dulu ya kaa...");

        $result = $this->coverage->check($lat, $lng);

        if ($result['covered']) {
            $this->state->update($phone, [
                'covered' => true,
                'state' => STATE_COVERED,
            ]);

            $namaArea = $result['area_name'];
            $msg = "✅ *Alhamdulillah* lokasi kaka di *{$namaArea}* tercover LIGAT! 🎉\n\nRencana mau pasang kapan kak?";
            $this->sendText($phone, $msg);
            $this->addHistory($phone, 'bot', $msg);
        } else {
            $this->state->update($phone, [
                'covered' => false,
                'state' => STATE_NOT_COVERED,
            ]);

            $msg = "Mohon maaf kaka untuk saat ini lokasi ini belum tercover area LIGAT, tapi kalau kaka mau pasang untuk lokasi/rumah kaka yang lain juga bisa kok ka.";
            $this->sendText($phone, $msg);
            $this->addHistory($phone, 'bot', $msg);
        }
    }

    /**
     * Add message ke history percakapan
     */
    private function addHistory(string $phone, string $role, string $text): void
    {
        $userState = $this->state->get($phone);
        if (!$userState) return;

        $history = $userState['history'] ?? [];
        $history[] = ['role' => $role, 'text' => $text, 'time' => date('c')];

        // Batasi max history
        if (count($history) > self::MAX_HISTORY) {
            $history = array_slice($history, -self::MAX_HISTORY);
        }

        $this->state->update($phone, ['history' => $history]);
    }

    // ─── Template methods (fallback) ───

    private function sendGreeting(string $phone): void
    {
        $msg = "👋 *Halo! Selamat datang di LIGAT Internet!*\n\n"
            . "Saya dari LIGAT penyedia layanan internet cepat dan stabil. Ada yang bisa saya bantu?\n\n"
            . "Ketik: *Cek Lokasi* untuk cek ketersediaan LIGAT di daerah kaka.";
        $this->sendText($phone, $msg);
    }

    private function askLocation(string $phone): void
    {
        $msg = "📍 *Cek Lokasi*\n\n"
            . "Silakan *share lokasi* kaka dengan cara:\n"
            . "1. Klik icon *attach* (📎) di samping input chat\n"
            . "2. Pilih *Location*\n"
            . "3. Kirim lokasi kaka saat ini\n\n"
            . "Saya akan cek apakah daerah kaka sudah tercover LIGAT!";
        $this->sendText($phone, $msg);
    }

    private function offerPackages(string $phone): void
    {
        $paketList = "";
        foreach (PAKET as $speed => $price) {
            $paketList .= "• *{$speed}* — {$price}\n";
        }
        $msg = "📡 *Paket Internet LIGAT*\n\n"
            . "Berikut paket yang tersedia:\n\n"
            . $paketList . "\n"
            . "Semua paket sudah include:\n"
            . "✅ Instalasi gratis\n"
            . "✅ WiFi Router\n"
            . "✅ 24/7 Support\n\n"
            . "Apa kaka tertarik dengan salah satu paket di atas?";
        $this->sendText($phone, $msg);
    }

    private function askName(string $phone): void
    {
        $this->sendText($phone, "😊 *Bagus!*\n\nSilakan ketik *nama* kaka ya, nanti tim kami akan menghubungi kaka untuk proses pemasangan.");
    }

    private function closing(string $phone, string $name): void
    {
        $this->sendText($phone, "✨ *Terima kasih, {$name}!*\n\n"
            . "Data kaka sudah saya terima:\n"
            . "📞 Nomor: {$phone}\n"
            . "👤 Nama: {$name}\n\n"
            . "Tim LIGAT akan menghubungi kaka *dalam 1x24 jam* untuk proses pemasangan.\n\n"
            . "Ada lagi yang bisa saya bantu?");
    }

    /**
     * Forward data registrasi ke nomor sales
     */
    private function forwardRegistrasi(string $phone): void
    {
        $userState = $this->state->get($phone);
        if (!$userState) return;

        $loc = $userState['location'] ?? null;
        $reg = $userState['registration'] ?? [];

        $msg = "📋 *LEAD BARU - REGISTRASI LIGAT*\n"
             . "👤 Nama: " . ($reg['nama'] ?? '-') . "\n"
             . "🆔 Nik: " . ($reg['nik'] ?? '-') . "\n"
             . "📅 TTL: " . ($reg['ttl'] ?? '-') . "\n"
             . "📍 Alamat: " . ($reg['alamat'] ?? '-') . "\n"
             . "📧 Email: " . ($reg['email'] ?? '-') . "\n"
             . "📞 No. WA 1: " . ($reg['no_wa'] ?? $phone) . "\n"
             . "📞 No. WA 2: " . ($reg['no_wa2'] ?? '-') . "\n"
             . "📦 Paket: " . ($reg['paket'] ?? '-') . "\n"
             . "📅 Pasang: " . ($reg['tgl_pasang'] ?? '-') . "\n";

        if ($loc) {
            $msg .= "🗺️ Lokasi: {$loc['lat']}, {$loc['lng']}\n";
        }
        $msg .= "📸 Foto KTP: " . (!empty($reg['foto_ktp']) ? '✅' : '❌') . "\n"
              . "🏠 Foto Rumah: " . (!empty($reg['foto_rumah']) ? '✅' : '❌') . "\n"
              . "📍 Sharelok: " . ($loc ? '✅' : '❌') . "\n"
              . "📅 Waktu: " . date('d/m/Y H:i') . "\n";

        foreach (FORWARD_NUMBERS as $num) {
            $this->wuzapi->sendText($num, $msg);
        }
    }

    // ─── Helpers ───

    private function sendText(string $phone, string $text): void
    {
        $this->wuzapi->sendText($phone, $text);
    }

    private function extractPhone(string $jid): ?string
    {
        if (preg_match('/^(\d+)(:\d+)?@/', $jid, $m)) {
            return $m[1];
        }
        return null;
    }

    private function getText(array $message): ?string
    {
        $text = $message['conversation'] ?? $message['extendedTextMessage']['text']
              ?? $message['Conversation'] ?? $message['ExtendedTextMessage']['text'] ?? null;
        return $text !== null ? trim($text) : null;
    }

    // ─── Keyword detection (fallback) ───

    private function isAffirmative(string $text): bool
    {
        $words = ['ya', 'iya', 'y', 'yes', 'bisa', 'mau', 'cek', 'cek lokasi', 'lokasi',
                  'tersedia', 'iya mau', 'tentu'];
        foreach ($words as $w) {
            if (strpos($text, $w) !== false) return true;
        }
        return false;
    }

    private function isInterested(string $text): bool
    {
        $words = ['mau', 'tertarik', 'iya', 'ya', 'yes', 'bisa', 'ambil', 'daftar',
                  'pasang', 'berlangganan', 'saya mau', 'bagus', 'info', 'lanjut'];
        foreach ($words as $w) {
            if (strpos($text, $w) !== false) return true;
        }
        return false;
    }

    private function isAskOtherArea(string $text): bool
    {
        $words = ['cek lain', 'lokasi lain', 'area lain', 'cek lagi', 'coba lagi', 'lainnya'];
        foreach ($words as $w) {
            if (strpos($text, $w) !== false) return true;
        }
        return false;
    }

    private function isCantShareLocation(string $text): bool
    {
        $words = ['tidak di rumah', 'lagi tidak di rumah', 'gak di rumah', 'ga di rumah',
                  'lagi di luar', 'di luar kota', 'tidak bisa share', 'gak bisa share',
                  'tidak bisa shareloc', 'gak ada di rumah', 'lagi kerja', 'di kantor',
                  'tidak di tempat', 'belum di rumah', 'masih di luar'];
        foreach ($words as $w) {
            if (strpos($text, $w) !== false) return true;
        }
        return false;
    }

    private function isAskingPackage(string $text): bool
    {
        $words = ['paket', 'harga', 'berapa', 'biaya', 'mahal', 'murah', 'promo',
                  'speed', 'kencang', 'cepat', 'mbps', 'kecepatan'];
        foreach ($words as $w) {
            if (strpos($text, $w) !== false) return true;
        }
        return false;
    }

    private function isPhotoLabel(string $text): bool
    {
        $words = ['ktp', 'foto rumah', 'foto ktp', 'foto rumah', 'rumah', 'ktp'];
        foreach ($words as $w) {
            if (strpos($text, $w) !== false) return true;
        }
        return false;
    }

    private function isFormData(string $text): bool
    {
        $words = ['nama', 'nik', 'ttl', 'alamat', 'email', 'no. wa', 'paket'];
        $count = 0;
        foreach ($words as $w) {
            if (strpos($text, $w) !== false) $count++;
        }
        return $count >= 2;
    }

    private function isConfirmComplete(string $text): bool
    {
        $words = ['sudah lengkap', 'lengkap semua', 'sudah semua', 'sudah kak', 'selesai',
                  'udah lengkap', 'udah semua', 'lengkap kak', 'done'];
        foreach ($words as $w) {
            if (strpos($text, $w) !== false) return true;
        }
        return false;
    }

    /**
     * Parse dan simpan data form registrasi ke state
     */
    private function saveFormData(string $phone, string $text): void
    {
        $userState = $this->state->get($phone);
        if (!$userState) return;

        $reg = $userState['registration'] ?? [];
        $lower = strtolower($text);

        // Deteksi foto dari caption
        if (strpos($lower, 'ktp') !== false && (strpos($lower, 'foto') !== false || strpos($lower, 'ini') !== false)) {
            $reg['foto_ktp'] = true;
        }
        if (strpos($lower, 'foto rumah') !== false || (strpos($lower, 'rumah') !== false && strpos($lower, 'foto') !== false)) {
            $reg['foto_rumah'] = true;
        }

        // Parse data form dari teks
        $patterns = [
            'nama' => '/Nama\s*:\s*(.+)/im',
            'nik' => '/Nik\s*:\s*(.+)/im',
            'ttl' => '/TTL\s*:\s*(.+)/im',
            'alamat' => '/Alamat\s*:\s*(.+)/im',
            'paket' => '/Paket\s*:\s*(.+)/im',
            'tgl_pasang' => '/tanggal\s*Pasang\s*:\s*(.+)/im',
            'email' => '/Email\s*:\s*(.+)/im',
            'no_wa' => '/No\.?\s*wa\s*1?\s*:\s*(.+)/im',
            'no_wa2' => '/No\.?\s*wa\s*2\s*:\s*(.+)/im',
        ];

        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $reg[$key] = trim($m[1]);
            }
        }

        $this->state->update($phone, ['registration' => $reg]);
    }
}

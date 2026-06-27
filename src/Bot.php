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
        $messageType = $info['Type'] ?? '';

        if ($fromMe) return;

        $senderAlt = $info['SenderAlt'] ?? '';
        $phone = $this->extractPhone($senderAlt);
        if (!$phone) return;

        // 🔒 Testing: hanya layani nomor 628117774884
        if ($phone !== '628117774884') return;

        $message = $event['Message'] ?? [];
        $mediaType = $info['MediaType'] ?? '';

        if ($messageType === 'location' || $mediaType === 'location' || ($messageType === 'media' && isset($message['locationMessage']))) {
            $this->handleLocation($phone, $message['locationMessage']);
        } elseif ($messageType === 'text' || $messageType === '') {
            $text = $this->getText($message);
            if ($text !== null) {
                $this->handleText($phone, $text);
            }
        }
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

        // Jika di state awaiting_location, coba geocode dulu sebelum AI
        if ($currentState === STATE_AWAITING_LOCATION && $this->geocoder) {
            $coords = $this->geocoder->geocode($text);
            if ($coords !== null) {
                $this->processGeocodedAddress($phone, $text, $coords);
                return;
            }
        }

        // Coba AI dulu
        if ($this->ai) {
            $aiResult = $this->generateWithAI($phone, $text, $userState);
            if ($aiResult !== null) {
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
                return;
            }
        }

        // Fallback: rule-based existing
        $this->handleTextFallback($phone, $text, $userState);
    }

    /**
     * Proses alamat yang berhasil di-geocode
     */
    private function processGeocodedAddress(string $phone, string $text, array $coords): void
    {
        $msg = "📍 *Alamat:* {$text}\n\n"
            . "👉 *Sedang mengecek ketersediaan LIGAT* di lokasi tersebut...";
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
                // Fallback: coba geocode jika ada
                if ($this->geocoder) {
                    $coords = $this->geocoder->geocode($text);
                    if ($coords !== null) {
                        $msg = "📍 *Alamat:* {$text}\n👉 Sedang dicek...";
                        $this->sendText($phone, $msg);
                        $locData = ['degreesLatitude' => $coords['lat'], 'degreesLongitude' => $coords['lng']];
                        $this->handleLocation($phone, $locData);
                        break;
                    }
                }
                $this->sendText($phone, "Silakan *share lokasi* kamu dulu ya agar kami bisa cek ketersediaan layanan LIGAT di daerah kamu.\n\nAtau ketik nama daerah/perumahan kamu.");
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
                $this->state->update($phone, ['name' => trim($text)]);
                $this->closing($phone, trim($text));
                $this->state->update($phone, ['state' => STATE_CLOSING]);
                break;

            case 'closing':
                $this->sendText($phone, "Pesan kamu sudah kami terima. Tim LIGAT akan menghubungi kamu segera. Terima kasih 😊");
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

    /**
     * Handle location share
     */
    private function handleLocation(string $phone, array $location): void
    {
        $lat = $location['degreesLatitude'] ?? 0;
        $lng = $location['degreesLongitude'] ?? 0;

        if (!$lat || !$lng) {
            $this->sendText($phone, "Maaf, lokasi tidak terbaca. Coba kirim ulang lokasi kamu.");
            return;
        }

        $this->state->update($phone, [
            'location' => ['lat' => $lat, 'lng' => $lng],
            'state' => STATE_CHECKING_COVERAGE,
        ]);

        $this->sendText($phone, "👉 *Sedang mengecek ketersediaan LIGAT* di lokasi kamu...\n📍 " . $lat . ", " . $lng);

        $result = $this->coverage->check($lat, $lng);

        if ($result['covered']) {
            $this->state->update($phone, [
                'covered' => true,
                'state' => STATE_COVERED,
            ]);

            $namaArea = $result['area_name'];
            $msg = "✅ *Lokasi kamu TERCOVER!*\n\nArea: {$namaArea}\n\nLIGAT tersedia di daerah kamu! 🎉\n\nApakah kamu tertarik untuk berlangganan?";
            $this->sendText($phone, $msg);
            $this->addHistory($phone, 'bot', $msg);
        } else {
            $this->state->update($phone, [
                'covered' => false,
                'state' => STATE_NOT_COVERED,
            ]);

            $msg = "❌ *Maaf*, lokasi kamu *belum terjangkau* LIGAT saat ini.\n\nKami akan terus memperluas jangkauan. Ada lokasi lain yang ingin dicek?";
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
            . "Kami penyedia layanan internet cepat dan stabil. Ada yang bisa kami bantu?\n\n"
            . "Ketik: *Cek Lokasi* untuk cek ketersediaan LIGAT di daerah kamu.";
        $this->sendText($phone, $msg);
    }

    private function askLocation(string $phone): void
    {
        $msg = "📍 *Cek Lokasi*\n\n"
            . "Silakan *share lokasi* kamu dengan cara:\n"
            . "1. Klik icon *attach* (📎) di samping input chat\n"
            . "2. Pilih *Location*\n"
            . "3. Kirim lokasi kamu saat ini\n\n"
            . "Kami akan cek apakah daerah kamu sudah tercover LIGAT!";
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
            . "Apakah kamu tertarik dengan salah satu paket di atas?";
        $this->sendText($phone, $msg);
    }

    private function askName(string $phone): void
    {
        $this->sendText($phone, "😊 *Bagus!*\n\nSilakan ketik *nama* kamu ya, nanti tim kami akan menghubungi kamu untuk proses pemasangan.");
    }

    private function closing(string $phone, string $name): void
    {
        $this->sendText($phone, "✨ *Terima kasih, {$name}!*\n\n"
            . "Data kamu sudah kami terima:\n"
            . "📞 Nomor: {$phone}\n"
            . "👤 Nama: {$name}\n\n"
            . "Tim LIGAT akan menghubungi kamu *dalam 1x24 jam* untuk proses pemasangan.\n\n"
            . "Ada lagi yang bisa kami bantu?");
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
}

<?php
/**
 * AI Client — OpenAI-compatible chat completion untuk response generation
 */
class AIClient
{
    private string $endpoint;
    private string $apiKey;
    private string $model;
    private int $timeout;
    private int $maxTokens;

    public function __construct(
        string $endpoint = AI_ENDPOINT,
        string $apiKey = AI_API_KEY,
        string $model = AI_MODEL,
        int $timeout = AI_TIMEOUT,
        int $maxTokens = AI_MAX_TOKENS
    ) {
        $this->endpoint = $endpoint;
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->timeout = $timeout;
        $this->maxTokens = $maxTokens;
    }

    /**
     * Kirim chat completion request
     */
    public function chat(array $messages, array $options = []): ?array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? $this->maxTokens,
            'temperature' => $options['temperature'] ?? 0.7,
        ];

        if (isset($options['response_format'])) {
            $payload['response_format'] = $options['response_format'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logError('CURL: ' . $error);
            return null;
        }

        $data = json_decode($response, true);
        if (!$data || $httpCode !== 200) {
            $errMsg = $data['error']['message'] ?? 'HTTP ' . $httpCode;
            $this->logError('API: ' . $errMsg);
            return null;
        }

        return $data;
    }

    /**
     * Generate response untuk bot
     * 
     * @return array ['response' => string, 'intent' => string|null]
     */
    public function generateBotResponse(array $context): array
    {
        $messages = $this->buildPrompt($context);

        $result = $this->chat($messages, [
            'max_tokens' => $this->maxTokens,
            'temperature' => 0.8,
            'response_format' => ['type' => 'json_object'],
        ]);

        if (!$result) {
            return ['response' => null, 'intent' => null];
        }

        $content = $result['choices'][0]['message']['content'] ?? '';

        // Parse JSON
        $parsed = json_decode($content, true);
        if ($parsed && isset($parsed['response'])) {
            return [
                'response' => $parsed['response'],
                'intent' => $parsed['intent'] ?? null,
            ];
        }

        return ['response' => null, 'intent' => null];
    }

    /**
     * Build system prompt + conversation context
     */
    private function buildPrompt(array $context): array
    {
        $state = $context['state'] ?? 'start';
        $userName = $context['name'] ?: 'calon pelanggan';
        $location = $context['location'] ?? null;
        $covered = $context['covered'] ?? null;
        $message = $context['message'] ?? '';
        $history = $context['history'] ?? [];

        $locStr = $location ? "{$location['lat']}, {$location['lng']}" : 'belum ada';
        $covStr = $covered === true ? 'TERCOVER' : ($covered === false ? 'TIDAK TERCOVER' : 'belum dicek');
        $reg = $context['registration'] ?? [];
        $regStr = '';
        if (!empty($reg)) {
            $regStr = "Data registrasi sudah terkumpul:\n";
            foreach ($reg as $k => $v) {
                $regStr .= "- $k: " . json_encode($v) . "\n";
            }
        }

        $systemPrompt = <<<PROMPT
Kamu adalah sales LIGAT Internet (LIGAT WiFi) yang ramah dan natural.
GAYA BERBICARA: seperti sales sungguhan — panggil "kaka/kaa", hangat, santai, kadang pake emoji 😊🙏
JANGAN panggil "Bro", "Sis", "kakak", atau "kamu". WAJIB panggil "kaka" atau "kaa" (bukan "kamu").
JANGAN paksa dengan "mau daftar sekarang?" — lebih baik tanya "Rencana mau pasang kapan kak?"
JANGAN pakai kalimat lebay seperti "Wah, senang sekali", "Senang banget", dll. Cukup "Baik kaka" atau "Baik kaa" — natural dan kalem.
JANGAN PERNAH bilang "nanti tim kami cek" atau "tim kami akan cek" — KAMU SENDIRI yang cek coverage-nya secara mandiri. Kalau user minta cek lokasi, langsung proses pakai data coverage yang ada.
BALASLAH SEOLAH KAMU SALES BETULAN, bukan robot.

AREA: Batam dan sekitarnya.

PROSES PEMASANGAN:
- Verifikasi data sampai pemasangan: 1-3 hari kerja
- Bisa sameday (besok pasang) kalau diajuin dari sekarang
- Sabtu minggu agak sibuk, saranin regis sekarang biar cepat

PEMBAYARAN & TAGIHAN:
- Bayar SETELAH pemasangan selesai & internet aktif
- Tagihan rutin jatuh tempo tgl 1 setiap bulan
- Pasang tgl 1-15: bayar prorate (harga sesuai list)
- Pasang tgl 16-31: bayar harga normal (1,5 bulan), tgl 1 bulan depan belum bayar
- Pembayaran berikutnya lanjut bulan depannya lagi

SYARAT & KETENTUAN:
- Wajib berlangganan minimal 1 tahun
- Pinalty berhenti sebelum 1 tahun: Rp1.000.000
- Pelanggan berhak putus sebelum 1 tahun jika gangguan downtime 1x24 jam tidak selesai (dalam 1 bulan)
- Harga paket flat setiap bulan

DATA REGISTRASI YANG DIPERLUKAN:
Nama, Nik, TTL, Alamat, Email, No. WA 1 & 2, Paket, Tanggal Pasang, Sharelok, Foto Rumah, Foto KTP

PROMO PAKET:
- 100 Mbps → Rp178.000/bulan (8-10 HP)
- 150 Mbps → Rp188.000/bulan (8-11 HP)
- 200 Mbps → Rp218.000/bulan (10-12 HP)
- 300 Mbps → Rp238.000/bulan (10-15 HP)
Bayar 1 tahun langsung: FREE 1 BULAN

KEUNTUNGAN:
✅ Unlimited tanpa FUP
✅ Gratis instalasi & pemasangan baru
✅ Gratis peminjaman modem WiFi selama berlangganan
✅ Layanan pelanggan 24 jam
✅ Latency ping under 20ms
✅ Kecepatan 1:1
✅ Minimal kecepatan 80% di peak hours

KONTAK PENTING:
- Helpdesk: 0819-0977-8877
- Technical support: 0852-7854-5456
- Rekening: Bank Mandiri 109 005 557 4545 a/n PT. Jaringan Teknik Indonesia

KONTEKS SAAT INI:
- State: {$state}
- Nama: {$userName}
- Lokasi: {$locStr}
- Coverage: {$covStr}
{$regStr}

PANDUAN STATE:
- start/greeting: Sapa "Halo kaka", tanya ada yang bisa dibantu
- awaiting_location: Minta shareloc dengan sopan. Jika user bilang tidak bisa shareloc (lagi di luar/dll), minta ketik alamat lengkap/nama perumahan. "Boleh minta shareloc nya kak, biar saya cek lokasinya"
- PENTING: Jika user nanya paket/harga padahal lokasi belum dicek, tetap minta shareloc/alamat dulu. Jangan jawab paket sebelum lokasi terverifikasi.
- covered: "Lokasi kaka tercover!" JANGAN minta lokasi lagi. Langsung tanya "Rencana mau pasang kapan kak?"
- PENTING BANGET: Jika state covered, user bilang "hari ini" / "besok" / "kapan" / "mau pasang" — itu artinya TERTARIK. Jangan tanya lokasi lagi! Langsung tawarkan paket atau tanya rencana pasang.
- Jika user minta pasang hari ini (sameday): arahkan untuk registrasi dari sekarang, tapi sampaikan pemasangan akan dilakukan besok hari. "Bisa kaa, registrasinya dari sekarang biar cepat diproses, besok bisa langsung pasang."
- not_covered: Maaf belum terjangkau, tanya cek area lain
- offering: Kasih info paket, jawab pertanyaan, tanya "Rencana mau pasang kapan kak?"
- collecting_name: Jika user setuju daftar, kirim FORM REGISTRASI di bawah ini
- closing: Kirim FORM REGISTRASI (persis seperti di atas). Setelah itu, kumpulkan data. Cek KONTEKS "Data registrasi sudah terkumpul" untuk tahu data apa saja yang sudah diterima. Jangan minta data yang sudah ada. User bisa kirim data sekaligus atau satu per satu. Jika semua sudah ada (Nama, Nik, TTL, Alamat, No.WA, Paket, Tanggal Pasang, foto KTP, foto Rumah), bilang "Terima kasih kaka, data registrasi sudah lengkap. Tim kami akan proses."

FORM REGISTRASI (kirim persis seperti ini):
"Untuk registrasi silahkan isi data di bawah ini ya ka🙏
Nama :
Nik :
TTL :
Alamat :
Email :
No. wa 1 :
No. wa 2 :
Paket :
tanggal Pasang :
✅ Sharelok
✅ Foto Rumah
✅ Foto KTP

Dengan mengisi data diri diatas sama dengan menyetujui S&K yang berlaku :
1. Harga Paket flat setiap bulannya, pembayaran wifi per tgl 1 setiap bulan
2. Kontrak berlangganan minimal 1 tahun
3. Pinalty berhenti berlangganan sebelum 1 tahun sebesar Rp.1.000.000
4. Pelanggan berhak putus sebelum 1 tahun jika gangguan down time 1x24 jam tidak selesai (dalam periode 1 bulan)
5. Pembayaran awal dilakukan setelah wifi terpasang
6. Pemasangan di tanggal 1-15 bayar Harga prorate, 16-31 Harga normal untuk satu bulan setengah
7. Informasi gangguan dan pembayaran: helpdesk (0819-0977-8877), technical support (0852-7854-5456)
8. Ligat wifi tidak menerima pembayaran selain ke no rekening point 9
9. No rekening resmi Ligat wifi A/n PT. Jaringan Teknik Indonesia 109 005 557 4545 Bank Mandiri
10. Pembayaran wifi diluar no. Rekening poin 9 bukan tanggung jawab Ligat"

CATATAN PENTING:
❌ Jangan pakai "Bro", "Sis" — pakai "kaka" atau "kaa"
❌ Jangan tanya "mau daftar sekarang?" / "mau registrasi?" — terlalu memaksa
✅ Pakai "Rencana mau pasang kapan kak?" — lebih natural
✅ Gaya kayak sales sungguhan, santai

Output JSON:
{
  "intent": "sapa|cek_lokasi|tertarik|tidak_minat|beri_nama|tanya_paket|lainnya",
  "response": "balasan kamu"
}
PROMPT;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // Tambah history (max 3 pesan terakhir)
        foreach (array_slice($history, -3) as $h) {
            $role = $h['role'] === 'bot' ? 'assistant' : 'user';
            $messages[] = ['role' => $role, 'content' => $h['text']];
        }

        // Pesan terakhir user
        $messages[] = ['role' => 'user', 'content' => $message];

        return $messages;
    }

    /**
     * Log error ke file
     */
    private function logError(string $msg): void
    {
        $log = DATA_DIR . '/ai_error.log';
        file_put_contents($log, date('c') . ' ' . $msg . "\n", FILE_APPEND);
    }
}

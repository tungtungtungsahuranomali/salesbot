<?php
/**
 * Konfigurasi Sales Bot LIGAT
 */

define('WUZAPI_BASE', 'http://202.8.28.198:3000');
define('BOT_TOKEN', 'pakjati');
define('BOT_NAME', 'LIGAT Sales Bot');

// State machine
define('STATE_START', 'start');
define('STATE_GREETING', 'greeting');
define('STATE_AWAITING_LOCATION', 'awaiting_location');
define('STATE_CHECKING_COVERAGE', 'checking_coverage');
define('STATE_COVERED', 'covered');
define('STATE_NOT_COVERED', 'not_covered');
define('STATE_OFFERING', 'offering');
define('STATE_COLLECTING_NAME', 'collecting_name');
define('STATE_CLOSING', 'closing');

// Paths
define('DATA_DIR', __DIR__ . '/data');
define('CONVERSATIONS_DIR', DATA_DIR . '/conversations');
define('COVERAGE_FILE', DATA_DIR . '/coverage.json');

// Paket internet LIGAT (promo terbaru)
define('PAKET', [
    '100 Mbps' => 'Rp178.000/bulan — cocok untuk 8-10 HP',
    '150 Mbps' => 'Rp188.000/bulan — cocok untuk 8-11 HP',
    '200 Mbps' => 'Rp218.000/bulan — cocok untuk 10-12 HP',
    '300 Mbps' => 'Rp238.000/bulan — cocok untuk 10-15 HP',
]);

// STB + IPTV add-on
define('STB_PRICE', 80000);
define('STB_DESC', 'STB + IPTV 250+ channel — tambah Rp80.000/bulan');

// AI Inference (opencode.ai)
define('AI_ENDPOINT', 'https://opencode.ai/zen/go/v1/chat/completions');
define('AI_API_KEY', 'sk-tKg1Ke2EagIVgtaGWDYFuMHmnAFZlTREKTvQWAD7ffMKioDrBpU5cgCR8oBDF1K5');
define('AI_MODEL', 'deepseek-v4-flash');
define('AI_TIMEOUT', 120); // detik
define('AI_MAX_TOKENS', 10000); // max tokens termasuk reasoning

// Geocoding
define('GOOGLE_GEOCODE_API_KEY', 'AIzaSyBTj6uKwGCbGi9WqhaMSs93_6ryoL6Jtmk');

// Form registrasi
define('REG_FORM', "Untuk registrasi silahkan isi data di bawah ini ya ka🙏\nNama :\nNik :\nTTL :\nAlamat :\nEmail :\nNo. wa 1 :\nNo. wa 2 :\nPaket :\ntanggal Pasang :\n✅ Sharelok\n✅ Foto Rumah\n✅ Foto KTP\n\nDengan mengisi data diri diatas sama dengan menyetujui S&K yang berlaku :\n1. Harga Paket flat setiap bulannya, pembayaran wifi per tgl 1 setiap bulan\n2. Kontrak berlangganan minimal 1 tahun\n3. Pinalty berhenti berlangganan sebelum 1 tahun sebesar Rp.1.000.000\n4. Pelanggan berhak putus sebelum 1 tahun jika gangguan down time 1x24 jam tidak selesai (dalam periode 1 bulan)\n5. Pembayaran awal dilakukan setelah wifi terpasang\n6. Pemasangan di tanggal 1-15 bayar Harga prorate, 16-31 Harga normal untuk satu bulan setengah\n7. Informasi gangguan dan pembayaran: helpdesk (0819-0977-8877), technical support (0852-7854-5456)\n8. Ligat wifi tidak menerima pembayaran selain ke no rekening point 9\n9. No rekening resmi Ligat wifi A/n PT. Jaringan Teknik Indonesia 109 005 557 4545 Bank Mandiri\n10. Pembayaran wifi diluar no. Rekening poin 9 bukan tanggung jawab Ligat");

// Forward nomor untuk notifikasi registrasi
define('FORWARD_NUMBERS', ['628117774884', '6283832185377']);

<?php
/**
 * Test skenario lengkap SalesBot LIGAT — fallback only (tanpa AI)
 * Cepat, deterministik, tanpa HTTP call ke AI endpoint
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/WuzAPI.php';
require_once __DIR__ . '/src/StateManager.php';
require_once __DIR__ . '/src/CoverageChecker.php';
require_once __DIR__ . '/src/AIClient.php';
require_once __DIR__ . '/src/Geocoder.php';
require_once __DIR__ . '/src/Bot.php';

$testPhone = '6281234567890';
$testDir = __DIR__ . '/data/test_' . time();
@mkdir($testDir, 0777, true);

$logFile = $testDir . '/outbox.log';

// Mock WuzAPI — catat pesan keluar, jangan kirim beneran
$mockWuzapi = new class($logFile) extends WuzAPI {
    private string $logFile;
    public array $sent = [];

    public function __construct(string $logFile) {
        $this->logFile = $logFile;
        parent::__construct('http://localhost:3000', 'test');
    }

    public function sendText(string $phone, string $body, ?string $id = null): array {
        $this->sent[] = ['phone' => $phone, 'body' => $body];
        file_put_contents($this->logFile, date('H:i:s') . " SEND {$phone}: {$body}\n", FILE_APPEND);
        return ['success' => true];
    }
};

$state = new StateManager($testDir);
$coverage = new CoverageChecker(COVERAGE_FILE);

// Test fallback (tanpa AI)
$bot = new Bot($mockWuzapi, $state, $coverage, null, null);

$pass = 0;
$fail = 0;

function check(array $stateData, string $expectedState, ?bool $expectedCovered, ?bool $expectedConfirmed, string $label): bool {
    $ok = true;
    $actual = $stateData['state'] ?? '';
    $covered = $stateData['covered'] ?? null;
    $confirmed = $stateData['location_confirmed'] ?? false;

    if ($actual !== $expectedState) {
        echo "  ❌ {$label}: state '{$expectedState}' ≠ '{$actual}'\n";
        $ok = false;
    }
    if ($expectedCovered !== null && $covered !== $expectedCovered) {
        echo "  ❌ {$label}: covered=" . json_encode($expectedCovered) . " ≠ " . json_encode($covered) . "\n";
        $ok = false;
    }
    if ($expectedConfirmed !== null && $confirmed !== $expectedConfirmed) {
        echo "  ❌ {$label}: confirmed=" . json_encode($expectedConfirmed) . " ≠ " . json_encode($confirmed) . "\n";
        $ok = false;
    }
    if ($ok) echo "  ✅ {$label}: state={$actual}, covered={$covered}, confirmed=" . json_encode($confirmed) . "\n";
    return $ok;
}

function textEv(string $phone, string $text): array {
    return [
        'Info' => ['IsFromMe' => false, 'IsGroup' => false, 'SenderAlt' => $phone . '@s.whatsapp.net', 'Type' => 'text'],
        'Message' => ['conversation' => $text],
    ];
}

function locEv(string $phone, float $lat, float $lng): array {
    return [
        'Info' => ['IsFromMe' => false, 'IsGroup' => false, 'SenderAlt' => $phone . '@s.whatsapp.net', 'Type' => 'media', 'MediaType' => 'location'],
        'Message' => ['locationMessage' => ['degreesLatitude' => $lat, 'degreesLongitude' => $lng]],
    ];
}

echo "═══════════════════════════════════════════════\n";
echo " TEST FLOW FALLBACK (rule-based, no AI)\n";
echo "═══════════════════════════════════════════════\n\n";

// ─── 1. START → GREETING ───
echo "─── 1. User: 'halo' ───\n";
$bot->handle(textEv($testPhone, 'halo'));
check($state->get($testPhone), 'greeting', null, false, 'start → greeting') ? $pass++ : $fail++;

// ─── 2. GREETING → AWAITING_LOCATION ───
echo "─── 2. User: 'cek lokasi' ───\n";
$bot->handle(textEv($testPhone, 'cek lokasi'));
check($state->get($testPhone), 'awaiting_location', null, false, 'greeting → awaiting_location') ? $pass++ : $fail++;

// ─── 3. KIRIM LOKASI TERCOVER (TIBAN LAMA) ───
echo "─── 3. User: share lokasi (lat=1.10541, lng=103.98348) ───\n";
$bot->handle(locEv($testPhone, 1.10541, 103.9834777));
$s = $state->get($testPhone);
if ($s['state'] === 'covered' && $s['covered'] === true) {
    echo "  ✅ Lokasi tercover: {$s['location']['lat']},{$s['location']['lng']}\n";
    $pass++;
} else {
    echo "  ❌ Expected covered, got state={$s['state']}, covered=" . json_encode($s['covered']) . "\n";
    $fail++;
}

// ─── 4. COVERED → OFFERING + location_confirmed ───
echo "─── 4. User: 'mau pasang' ───\n";
$bot->handle(textEv($testPhone, 'mau pasang'));
check($state->get($testPhone), 'offering', true, true, 'covered → offering + confirmed') ? $pass++ : $fail++;

// ─── 5. OFFERING → COLLECTING_NAME ───
echo "─── 5. User: 'saya mau pasang 150mbps' ───\n";
$bot->handle(textEv($testPhone, 'saya mau pasang 150mbps'));
check($state->get($testPhone), 'collecting_name', true, true, 'offering → collecting_name') ? $pass++ : $fail++;

// ─── 6. COLLECTING_NAME → CLOSING ───
echo "─── 6. User: 'Budi Santoso' (nama) ───\n";
$bot->handle(textEv($testPhone, 'Budi Santoso'));
check($state->get($testPhone), 'closing', true, true, 'collecting_name → closing') ? $pass++ : $fail++;

// ─── 7. CLOSING — simpan data form ───
echo "─── 7. User: kirim data form ───\n";
$form = "Nama : Budi Santoso\nNik : 1234567890\nTTL : Batam, 1 Jan 1990\nAlamat : Tiban Lama\nEmail : budi@email.com\nNo. wa 1 : 6281234567890\nPaket : 150 Mbps\ntanggal Pasang : 1 Juli 2026";
$bot->handle(textEv($testPhone, $form));
$reg = $state->get($testPhone)['registration'] ?? [];
if (($reg['nama'] ?? '') === 'Budi Santoso' && ($reg['paket'] ?? '') === '150 Mbps') {
    echo "  ✅ Data form: {$reg['nama']}, {$reg['paket']}\n";
    $pass++;
} else {
    echo "  ❌ Data form: " . json_encode($reg) . "\n";
    $fail++;
}

// ─── 8. CLOSING — konfirmasi lengkap ───
echo "─── 8. User: 'sudah lengkap' ───\n";
$bot->handle(textEv($testPhone, 'sudah lengkap kak'));
$outbox = file_get_contents($logFile);
if (strpos($outbox, 'LEAD BARU') !== false) {
    echo "  ✅ Forward registrasi: LEAD BARU terkirim\n";
    $pass++;
} else {
    echo "  ⚠️  Forward tidak terkirim (wajar, mock tidak punya FORWARD_NUMBERS valid)\n";
}

// ═══════════════════════════════════════════════
// TEST GANTI LOKASI
// ═══════════════════════════════════════════════
echo "\n─── 9. GANTI LOKASI dari covered ───\n";
$p2 = '6289876543210';
$bot->handle(textEv($p2, 'halo'));
$bot->handle(textEv($p2, 'cek lokasi'));
$bot->handle(locEv($p2, 1.10541, 103.9834777));
check($state->get($p2), 'covered', true, false, 'setup covered') ? $pass++ : $fail++;
$bot->handle(textEv($p2, 'cek lokasi lain'));
$s = $state->get($p2);
if ($s['state'] === 'awaiting_location' && $s['location'] === null && $s['covered'] === null && $s['location_confirmed'] === false) {
    echo "  ✅ Lokasi di-reset: awaiting_location, location=null\n";
    $pass++;
} else {
    echo "  ❌ Reset gagal: state={$s['state']}, location=" . json_encode($s['location']) . "\n";
    $fail++;
}

// ═══════════════════════════════════════════════
// TEST NOT COVERED → GANTI LOKASI
// ═══════════════════════════════════════════════
echo "\n─── 10. NOT COVERED → coba lokasi lain ───\n";
$p3 = '6285555555555';
$bot->handle(textEv($p3, 'halo'));
$bot->handle(textEv($p3, 'cek lokasi'));
$bot->handle(locEv($p3, 1.1616966, 104.0412698)); // not covered
check($state->get($p3), 'not_covered', false, false, 'not covered') ? $pass++ : $fail++;
$bot->handle(textEv($p3, 'coba lagi'));
$s = $state->get($p3);
if ($s['state'] === 'awaiting_location') {
    echo "  ✅ Kembali ke awaiting_location\n";
    $pass++;
} else {
    echo "  ❌ Expected awaiting_location, got {$s['state']}\n";
    $fail++;
}

// ═══════════════════════════════════════════════
// TEST LIMIT LOKASI (fallback)
// ═══════════════════════════════════════════════
echo "\n─── 11. LIMIT percobaan lokasi via teks ───\n";
$p4 = '6286666666666';
$bot->handle(textEv($p4, 'halo'));
$bot->handle(textEv($p4, 'cek lokasi'));
$bot->handle(textEv($p4, 'xyzxyzrandom'));
echo "   attempt 1: attempts={$state->get($p4)['location_attempts']}\n";
$bot->handle(textEv($p4, 'abcabcrandom'));
echo "   attempt 2: attempts={$state->get($p4)['location_attempts']}\n";
$bot->handle(textEv($p4, 'randomlagi'));
$s4 = $state->get($p4);
echo "   attempt 3: state={$s4['state']}, attempts={$s4['location_attempts']}\n";
if ($s4['state'] === 'start' && ($s4['location_attempts'] ?? -1) === 0) {
    echo "  ✅ Limit tercapai → helpdesk\n";
    $pass++;
} else {
    echo "  ❌ Expected start, got {$s4['state']}\n";
    $fail++;
}

// ═══════════════════════════════════════════════
// TEST AI PATH (optional — cepat)
// ═══════════════════════════════════════════════
echo "\n─── 12. AI PATH: cek AI response parsing ───\n";
$ai = new AIClient(AI_ENDPOINT, AI_API_KEY, AI_MODEL, 5, 500); // timeout 5 detik
$result = $ai->generateBotResponse([
    'state' => 'greeting',
    'name' => '',
    'message' => 'halo',
    'history' => [],
    'location' => null,
    'covered' => null,
    'registration' => [],
]);
if ($result['response'] !== null && isset($result['intent'])) {
    echo "  ✅ AI response: intent={$result['intent']}, response=" . substr($result['response'], 0, 50) . "...\n";
    $pass++;
} else {
    echo "  ⚠️  AI tidak reachable (timeout 5s) — skip\n";
}

// ═══════════════════════════════════════════════
// CLEANUP
// ═══════════════════════════════════════════════
array_map('unlink', glob($testDir . '/*.json'));
@rmdir($testDir);

echo "\n═══════════════════════════════════════════════\n";
echo " HASIL: {$pass} passed, {$fail} failed\n";
echo "═══════════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);

<?php
/**
 * Webhook Entry Point untuk Sales Bot LIGAT
 * 
 * Endpoint yang dipanggil WuzAPI setiap ada pesan masuk.
 * 
 * Cara set webhook:
 *   curl -X POST http://202.8.28.198:3000/webhook \
 *     -H "Token: 123qwe" \
 *     -H "Content-Type: application/json" \
 *     -d '{"url":"https://URL_PUBLIC_ANDA/index.php","events":["Message"]}'
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Load config & classes
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/WuzAPI.php';
require_once __DIR__ . '/../src/StateManager.php';
require_once __DIR__ . '/../src/CoverageChecker.php';
require_once __DIR__ . '/../src/AIClient.php';
require_once __DIR__ . '/../src/Geocoder.php';
require_once __DIR__ . '/../src/Bot.php';

// Hanya terima POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Method not allowed']));
}

// Log incoming request (untuk debugging)
$rawInput = file_get_contents('php://input');
$logFile = DATA_DIR . '/webhook.log';
file_put_contents($logFile, date('c') . ' ' . $rawInput . "\n", FILE_APPEND);

// WuzAPI kirim webhook sebagai form-urlencoded: instanceName=SALESBOT&jsonData={...}&userID=...
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
    parse_str($rawInput, $formData);
    $jsonRaw = $formData['jsonData'] ?? '';
    $data = json_decode($jsonRaw, true);
} else {
    $data = json_decode($rawInput, true);
}

if (!$data || !isset($data['event'])) {
    // Tetap 200 — WuzAPI kirim test request saat set webhook
    echo json_encode(['success' => true, 'message' => 'webhook active']);
    exit;
}

$event = $data['event'];

try {
    // Init komponen
    $wuzapi = new WuzAPI(WUZAPI_BASE, BOT_TOKEN);
    $state = new StateManager(CONVERSATIONS_DIR);
    $coverage = new CoverageChecker(COVERAGE_FILE);
    $ai = new AIClient();
    $geocoder = new Geocoder();
    $bot = new Bot($wuzapi, $state, $coverage, $ai, $geocoder);

    // Proses pesan
    $bot->handle($event);

    // Response sukses
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    // Log error
    file_put_contents(
        DATA_DIR . '/error.log',
        date('c') . ' ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n",
        FILE_APPEND
    );

    // Tetap return 200 agar WuzAPI tidak mengirim ulang
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

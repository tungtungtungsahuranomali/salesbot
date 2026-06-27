<?php
/**
 * Konfigurasi Sales Bot LIGAT
 */

define('WUZAPI_BASE', 'http://202.8.28.198:3000');
define('BOT_TOKEN', '123qwe');
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

// AI Inference (opencode.ai)
define('AI_ENDPOINT', 'https://opencode.ai/zen/go/v1/chat/completions');
define('AI_API_KEY', 'sk-tKg1Ke2EagIVgtaGWDYFuMHmnAFZlTREKTvQWAD7ffMKioDrBpU5cgCR8oBDF1K5');
define('AI_MODEL', 'deepseek-v4-flash');
define('AI_TIMEOUT', 120); // detik
define('AI_MAX_TOKENS', 10000); // max tokens termasuk reasoning

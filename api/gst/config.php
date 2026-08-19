<?php
/**
 * GST Filing API bootstrap (mirrors e-way bill: connect.php + JSON headers).
 * Perione GST production keys live here — never returned to Android.
 */
if (defined('GST_API_CONFIG_LOADED')) {
    return;
}
define('GST_API_CONFIG_LOADED', true);

$gstJsonApi = !defined('GST_ADMIN_BOOTSTRAP');
if ($gstJsonApi) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

require_once dirname(__DIR__, 2) . '/admin/connect.php';
require_once dirname(__DIR__, 2) . '/controller/GstFilingController.php';

/** Perione GST API (production). Same pattern as cashfree_config.php / eway DB settings. */
if (!defined('GST_PERIONE_BASE_URL')) {
    define('GST_PERIONE_BASE_URL', 'https://api.perione.in');
}
if (!defined('GST_PERIONE_CLIENT_ID')) {
    define('GST_PERIONE_CLIENT_ID', 'PGSTP9b6556720857514b152fcc7e6c2f285e');
}
if (!defined('GST_PERIONE_CLIENT_SECRET')) {
    define('GST_PERIONE_CLIENT_SECRET', 'PGSTP34a71f3c165a281dcf33d7d31906cea7');
}

if (!isset($connect) || !($connect instanceof mysqli)) {
    if ($gstJsonApi) {
        echo json_encode(['status' => 'error', 'message' => 'Database unavailable.']);
        exit;
    }
}

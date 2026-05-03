<?php
/**
 * NIC e-Way master codes for mobile dropdowns (same source as dashboard).
 * Static reference data — no session or business_id required.
 */
header('Content-Type: application/json; charset=utf-8');

$masterFile = dirname(__DIR__, 2) . '/eway_bills_doc/eway_master_codes.php';
$master = (is_readable($masterFile) && is_array($m = include $masterFile)) ? $m : [];

echo json_encode([
    'status' => 'success',
    'master_codes' => $master,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

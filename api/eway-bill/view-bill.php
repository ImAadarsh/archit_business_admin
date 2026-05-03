<?php
include '../../admin/connect.php';
include '../../controller/EwayBillController.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($connect) || !($connect instanceof mysqli)) {
    echo json_encode(['status' => 'error', 'message' => 'Database unavailable.']);
    exit;
}

$raw = file_get_contents('php://input');
$in = json_decode($raw ?: '[]', true);
if (!is_array($in)) {
    $in = [];
}

$business_id = (int) ($in['business_id'] ?? $_GET['business_id'] ?? 0);
$ebn = (string) ($in['ebn'] ?? $_GET['ebn'] ?? '');

if ($business_id <= 0 || $ebn === '') {
    echo json_encode(['status' => 'error', 'message' => 'business_id and ebn are required.']);
    exit;
}

$ebnClean = preg_replace('/\D+/', '', $ebn);
$chk = $connect->prepare('SELECT id FROM eway_bills WHERE business_id = ? AND CAST(eway_bill_no AS CHAR) = ? LIMIT 1');
$chk->bind_param('is', $business_id, $ebnClean);
$chk->execute();
$ok = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$ok) {
    echo json_encode(['status' => 'error', 'message' => 'e-Way bill not found for this business.']);
    exit;
}

$controller = new EwayBillController($connect);
echo json_encode($controller->viewEwayBill($business_id, $ebnClean), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

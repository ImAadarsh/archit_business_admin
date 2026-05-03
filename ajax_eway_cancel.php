<?php
/**
 * AJAX: Cancel an existing e-Way Bill (Perione canewb).
 *
 * Request: POST /business/ajax_eway_cancel.php
 *   ebn=<EBN>&reason_code=<1|2|3|4>&remarks=<text>
 * Response: JSON {status:"success"|"error", message?, ewbNo?, cancelDate?, api_communication_log:[]}
 */
include 'admin/connect.php';
include 'admin/session.php';
include 'controller/EwayBillController.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($connect) || !($connect instanceof mysqli)) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection unavailable.']);
    exit;
}
$business_id = isset($_SESSION['business_id']) ? (int) $_SESSION['business_id'] : 0;
if ($business_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Session expired. Please sign in again.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'POST required.']);
    exit;
}

$ebn = (string) ($_POST['ebn'] ?? '');
$reason = (int) ($_POST['reason_code'] ?? 0);
$remarks = (string) ($_POST['remarks'] ?? '');

if ($ebn === '' || $reason <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'ebn and reason_code are required.']);
    exit;
}

$controller = new EwayBillController($connect);
$response = $controller->cancelEwayBill($business_id, $ebn, $reason, $remarks);
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

<?php
/**
 * AJAX: Update Transporter assigned to an existing e-Way Bill.
 *
 * POST fields: ebn, transporterId
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
$tid = (string) ($_POST['transporterId'] ?? '');
if ($ebn === '' || $tid === '') {
    echo json_encode(['status' => 'error', 'message' => 'ebn and transporterId are required.']);
    exit;
}

$controller = new EwayBillController($connect);
$response = $controller->updateEwayBillTransporter($business_id, $ebn, $tid);
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

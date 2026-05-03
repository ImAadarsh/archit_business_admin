<?php
/**
 * AJAX: Update PART-B / Vehicle Number on an existing e-Way Bill.
 *
 * POST fields: ebn, vehicleNo, fromPlace, fromState, reasonCode, reasonRem, transDocNo, transDocDate, transMode
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
if ($ebn === '') {
    echo json_encode(['status' => 'error', 'message' => 'ebn is required.']);
    exit;
}

$fields = [
    'vehicleNo' => $_POST['vehicleNo'] ?? '',
    'fromPlace' => $_POST['fromPlace'] ?? '',
    'fromState' => $_POST['fromState'] ?? '',
    'reasonCode' => $_POST['reasonCode'] ?? '',
    'reasonRem' => $_POST['reasonRem'] ?? '',
    'transDocNo' => $_POST['transDocNo'] ?? '',
    'transDocDate' => $_POST['transDocDate'] ?? '',
    'transMode' => $_POST['transMode'] ?? '',
];

$controller = new EwayBillController($connect);
$response = $controller->updateEwayBillPartB($business_id, $ebn, $fields);
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

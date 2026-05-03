<?php
/**
 * AJAX: View an existing e-Way Bill (Perione getewaybill).
 *
 * Request: GET /business/ajax_eway_view.php?ebn=<EBN>
 * Response: JSON {status:"success"|"error", data?, message?, api_communication_log:[]}
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

$ebn = isset($_GET['ebn']) ? (string) $_GET['ebn'] : '';
if ($ebn === '') {
    echo json_encode(['status' => 'error', 'message' => 'e-Way Bill Number (ebn) is required.']);
    exit;
}

$controller = new EwayBillController($connect);
$response = $controller->viewEwayBill($business_id, $ebn);
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

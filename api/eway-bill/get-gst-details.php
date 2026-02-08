<?php
// business/api/eway-bill/get-gst-details.php
include '../../admin/connect.php';
include '../../controller/EwayBillController.php';

header('Content-Type: application/json');

// For internal mobile app use
$business_id = $_SESSION['business_id'] ?? $_GET['business_id'] ?? 0;
$gstin = $_GET['gstin'] ?? '';

if (!$business_id) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized or missing business_id']);
    exit;
}

if (empty($gstin)) {
    echo json_encode(['status' => 'error', 'message' => 'GSTIN is required.']);
    exit;
}

$controller = new EwayBillController($connect);
$response = $controller->getGstDetails($business_id, $gstin);

echo json_encode($response);

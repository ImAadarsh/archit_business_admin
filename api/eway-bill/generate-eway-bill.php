<?php
// business/api/eway-bill/generate-eway-bill.php
include '../../admin/connect.php';
include '../../controller/EwayBillController.php';

header('Content-Type: application/json');

// Handle raw JSON input
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, TRUE);

// Fallback to $_POST if not raw JSON
if (!$input) {
    $input = $_POST;
}

$business_id = $_SESSION['business_id'] ?? $input['business_id'] ?? 0;
$invoice_id = $input['invoice_id'] ?? 0;

if (!$business_id) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized or missing business_id']);
    exit;
}

if (!$invoice_id) {
    echo json_encode(['status' => 'error', 'message' => 'invoice_id is required.']);
    exit;
}

// 1. Verify Invoice belongs to Business
$stmt = $connect->prepare("SELECT business_id FROM invoices WHERE id = ?");
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$res || (int) $res['business_id'] !== (int) $business_id) {
    echo json_encode(['status' => 'error', 'message' => 'Invoice not found or access denied for this business.']);
    exit;
}

$controller = new EwayBillController($connect);
$response = $controller->generateEwayBill($invoice_id, [], $input);

echo json_encode($response);

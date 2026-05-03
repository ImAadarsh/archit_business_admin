<?php
/**
 * Mobile / API: Generate e-Way Bill (full NIC JSON body).
 * POST JSON or form. Requires business_id + invoice_id + same payload fields as web generate.
 */
include '../../admin/connect.php';
include '../../controller/EwayBillController.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($connect) || !($connect instanceof mysqli)) {
    echo json_encode(['status' => 'error', 'message' => 'Database unavailable.']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '[]', true);
if (!is_array($input) || empty($input)) {
    $input = $_POST;
}

$business_id = (int) ($input['business_id'] ?? 0);
$invoice_id = (int) ($input['invoice_id'] ?? 0);

if ($business_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'business_id is required.']);
    exit;
}
if ($invoice_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'invoice_id is required.']);
    exit;
}

$stmt = $connect->prepare('SELECT business_id FROM invoices WHERE id = ?');
$stmt->bind_param('i', $invoice_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || (int) $row['business_id'] !== $business_id) {
    echo json_encode(['status' => 'error', 'message' => 'Invoice not found or access denied for this business.']);
    exit;
}

$item_list = [];
if (!empty($input['itemList']) && is_array($input['itemList'])) {
    $item_list = $input['itemList'];
} elseif (!empty($input['itemList_json'])) {
    $decoded = json_decode((string) $input['itemList_json'], true);
    $item_list = is_array($decoded) ? $decoded : [];
}

$payload = [
    'supplyType' => $input['supplyType'] ?? 'O',
    'subSupplyType' => (string) ($input['subSupplyType'] ?? '1'),
    'subSupplyDesc' => (string) ($input['subSupplyDesc'] ?? ''),
    'docType' => $input['docType'] ?? 'INV',
    'docNo' => $input['docNo'] ?? '',
    'docDate' => $input['docDate'] ?? '',
    'fromGstin' => $input['fromGstin'] ?? '',
    'fromTrdName' => $input['fromTrdName'] ?? '',
    'fromAddr1' => $input['fromAddr1'] ?? '',
    'fromAddr2' => $input['fromAddr2'] ?? '',
    'fromPlace' => $input['fromPlace'] ?? '',
    'fromPincode' => (int) ($input['fromPincode'] ?? 0),
    'fromStateCode' => (int) ($input['fromStateCode'] ?? 0),
    'actFromStateCode' => (int) ($input['actFromStateCode'] ?? 0),
    'toGstin' => $input['toGstin'] ?? 'URP',
    'toTrdName' => $input['toTrdName'] ?? '',
    'toAddr1' => $input['toAddr1'] ?? '',
    'toAddr2' => $input['toAddr2'] ?? '',
    'toPlace' => $input['toPlace'] ?? '',
    'toPincode' => (int) ($input['toPincode'] ?? 0),
    'toStateCode' => (int) ($input['toStateCode'] ?? 0),
    'actToStateCode' => (int) ($input['actToStateCode'] ?? 0),
    'transactionType' => (int) ($input['transactionType'] ?? 1),
    'otherValue' => (float) ($input['otherValue'] ?? 0),
    'totalValue' => (float) ($input['totalValue'] ?? 0),
    'cgstValue' => (float) ($input['cgstValue'] ?? 0),
    'sgstValue' => (float) ($input['sgstValue'] ?? 0),
    'igstValue' => (float) ($input['igstValue'] ?? 0),
    'cessValue' => (float) ($input['cessValue'] ?? 0),
    'cessNonAdvolValue' => (float) ($input['cessNonAdvolValue'] ?? 0),
    'totInvValue' => (float) ($input['totInvValue'] ?? 0),
    'transMode' => (string) ($input['transMode'] ?? ''),
    'transDistance' => (string) ($input['transDistance'] ?? '0'),
    'vehicleNo' => (string) ($input['vehicleNo'] ?? ''),
    'vehicleType' => $input['vehicleType'] ?? 'R',
    'transporterId' => trim((string) ($input['transporterId'] ?? ($input['fromGstin'] ?? ''))),
    'transporterName' => (string) ($input['transporterName'] ?? ''),
    'transDocNo' => (string) ($input['transDocNo'] ?? ''),
    'transDocDate' => (string) ($input['transDocDate'] ?? ''),
    'dispatchFromGSTIN' => (string) ($input['dispatchFromGSTIN'] ?? ''),
    'dispatchFromTradeName' => (string) ($input['dispatchFromTradeName'] ?? ''),
    'shipToGSTIN' => (string) ($input['shipToGSTIN'] ?? ''),
    'shipToTradeName' => (string) ($input['shipToTradeName'] ?? ''),
    'itemList' => $item_list,
];

$controller = new EwayBillController($connect);
$response = $controller->generateEwayBill($invoice_id, [], $payload, $business_id);

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

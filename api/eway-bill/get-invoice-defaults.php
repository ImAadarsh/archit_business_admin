<?php
// business/api/eway-bill/get-invoice-defaults.php
include '../../admin/connect.php';
include '../../controller/EwayBillController.php';

header('Content-Type: application/json');

$business_id = $_SESSION['business_id'] ?? $_GET['business_id'] ?? 0;
$invoice_id = $_GET['invoice_id'] ?? 0;

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

// 2. Prepare Invoice Data
$default_transport = [
    'transMode' => '1',
    'transDistance' => '0',
    'vehicleNo' => '',
    'transporterId' => '',
    'transporterName' => '',
    'transDocNo' => '',
    'transDocDate' => '',
];
$formData = $controller->prepareEwayBillData($invoice_id, $default_transport);

if (!$formData) {
    echo json_encode(['status' => 'error', 'message' => 'Invoice not found or access denied']);
    exit;
}

// 2. Load Business Defaults and Merge
$businessDefaults = $controller->getFormDefaults($business_id, 'eway_bill_form');
foreach ($businessDefaults as $key => $val) {
    // Only apply default if the field is empty or follows the standard default from prepareEwayBillData
    // We prioritize invoice data over defaults, but defaults over the static values in prepareEwayBillData
    if (empty($formData[$key]) || $formData[$key] === '1' || $formData[$key] === 'O' || $formData[$key] === 'INV') {
        $formData[$key] = $val;
    }
}

// 3. Load Master Codes
$master_file = dirname(__DIR__, 2) . '/eway_bills_doc/eway_master_codes.php';
$master = is_readable($master_file) ? include $master_file : [];
$master = is_array($master) ? $master : [];

// Fetch settings to return credentials needed for the frontend
$settings = $controller->getEwayBillSettings($business_id) ?? [];

echo json_encode([
    'status' => 'success',
    'invoice_data' => $formData,
    'business_defaults' => $businessDefaults, // still returning for reference
    'master_codes' => $master,
    'service' => 'ewaybill',
    'environment' => !empty($settings['environment']) ? $settings['environment'] : 'production',
    'client_key' => $settings['client_id'] ?? '',
    'client_secret' => $settings['client_secret'] ?? '',
    'gstin' => $settings['gstin'] ?? '',
    'api_username' => $settings['api_username'] ?? ''
]);

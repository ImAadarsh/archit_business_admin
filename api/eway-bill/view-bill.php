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
$result = $controller->viewEwayBill($business_id, $ebnClean);

if (is_array($result) && ($result['status'] ?? '') === 'success') {
    $masterFile = dirname(__DIR__, 2) . '/eway_bills_doc/eway_master_codes.php';
    $mc = (is_readable($masterFile) && is_array($m = include $masterFile)) ? $m : [];
    $result['master_codes'] = $mc;
    $result['display_code_labels'] = [
        'status' => [
            'ACT' => 'Active',
            'CNL' => 'Cancelled',
            'REJ' => 'Rejected by other party',
        ],
        'rejectStatus' => [
            'N' => 'Not rejected',
            'Y' => 'Rejected by other party',
        ],
        'genMode' => [
            'API' => 'API',
            'PORTAL' => 'NIC Portal',
            'MOBILE' => 'Mobile App',
            'SMS' => 'SMS',
            'BULK' => 'Bulk Upload',
            'TAX-PAYER' => 'Tax payer',
        ],
    ];
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

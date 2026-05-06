<?php
include '../../admin/connect.php';
include '../../controller/EwayBillController.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

if (!isset($connect) || !($connect instanceof mysqli)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Database unavailable.';
    exit;
}

$business_id = (int) ($_GET['business_id'] ?? 0);
$ebn = (string) ($_GET['ebn'] ?? '');
$ebnClean = preg_replace('/\D+/', '', $ebn);

if ($business_id <= 0 || $ebnClean === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'business_id and ebn are required.';
    exit;
}

$chk = $connect->prepare('SELECT id FROM eway_bills WHERE business_id = ? AND CAST(eway_bill_no AS CHAR) = ? LIMIT 1');
$chk->bind_param('is', $business_id, $ebnClean);
$chk->execute();
$ok = $chk->get_result()->fetch_assoc();
$chk->close();
if (!$ok) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'e-Way bill not found for this business.';
    exit;
}

$controller = new EwayBillController($connect);
$result = $controller->viewEwayBill($business_id, $ebnClean);
if (!is_array($result) || ($result['status'] ?? '') !== 'success' || !is_array($result['data'] ?? null)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo (string) ($result['message'] ?? 'Failed to fetch e-Way bill.');
    exit;
}

$data = $result['data'];

$businessName = 'InvoiceMate';
$logoRel = '';
$bq = $connect->prepare('SELECT business_name, logo FROM businessses WHERE id = ? LIMIT 1');
if ($bq) {
    $bq->bind_param('i', $business_id);
    $bq->execute();
    $brow = $bq->get_result()->fetch_assoc();
    $bq->close();
    if ($brow) {
        $businessName = trim((string) ($brow['business_name'] ?? $businessName));
        $logoRel = trim((string) ($brow['logo'] ?? ''));
    }
}

$storageBase = realpath(dirname(__DIR__, 3) . '/../api/storage/app');
$logoPath = '';
if ($storageBase && $logoRel !== '') {
    $cand = $storageBase . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $logoRel);
    if (is_file($cand)) {
        $logoPath = $cand;
    }
}

$pick = static function (array $arr, array $keys, $default = '') {
    foreach ($keys as $k) {
        if (array_key_exists($k, $arr) && $arr[$k] !== null && $arr[$k] !== '') {
            return $arr[$k];
        }
    }
    return $default;
};

$fmtDateTime = static function ($v) {
    $s = trim((string) $v);
    if ($s === '') return '';
    $ts = strtotime($s);
    if ($ts === false) return $s;
    return date('d/m/Y h:i A', $ts);
};

$fmtDate = static function ($v) {
    $s = trim((string) $v);
    if ($s === '') return '';
    $ts = strtotime($s);
    if ($ts === false) return $s;
    return date('d/m/Y', $ts);
};

$clean = static function ($v) {
    return htmlspecialchars(trim((string) $v), ENT_QUOTES, 'UTF-8');
};

$no = (string) $pick($data, ['ewbNo', 'ewayBillNo'], $ebnClean);
$enteredDate = (string) $pick($data, ['ewayBillDate', 'genDate', 'enteredDate'], '');
$validUpto = (string) $pick($data, ['validUpto', 'validUntil'], '');
$partBEntered = trim((string) $pick($data, ['vehNo', 'vehicleNo', 'transMode'], '')) !== '';
$validText = $partBEntered
    ? ('Valid up to: ' . ($validUpto !== '' ? $fmtDateTime($validUpto) : 'N/A'))
    : 'Not Valid for Movement as Part B is not entered';

$fromGstin = (string) $pick($data, ['fromGstin', 'fromGSTIN'], '');
$fromName = (string) $pick($data, ['fromTrdName', 'fromTradeName'], '');
$toGstin = (string) $pick($data, ['toGstin', 'toGSTIN'], '');
$toName = (string) $pick($data, ['toTrdName', 'toTradeName'], '');

$dispatch = trim((string) $pick($data, ['fromPlace'], ''));
$dispatchState = trim((string) $pick($data, ['fromStateName', 'fromStateCode'], ''));
$dispatchPin = trim((string) $pick($data, ['fromPincode'], ''));
if ($dispatchState !== '') $dispatch .= ($dispatch !== '' ? ', ' : '') . $dispatchState;
if ($dispatchPin !== '') $dispatch .= ($dispatch !== '' ? '-' : '') . $dispatchPin;

$delivery = trim((string) $pick($data, ['toPlace'], ''));
$deliveryState = trim((string) $pick($data, ['toStateName', 'toStateCode'], ''));
$deliveryPin = trim((string) $pick($data, ['toPincode'], ''));
if ($deliveryState !== '') $delivery .= ($delivery !== '' ? ', ' : '') . $deliveryState;
if ($deliveryPin !== '') $delivery .= ($delivery !== '' ? '-' : '') . $deliveryPin;

$docNo = (string) $pick($data, ['docNo'], '');
$docDate = (string) $pick($data, ['docDate'], '');
$transType = (string) $pick($data, ['transactionTypeDesc', 'transactionType'], '');
$valueGoods = (string) $pick($data, ['totalValue', 'totInvValue'], '');
$reason = (string) $pick($data, ['subSupplyTypeDesc', 'supplyTypeDesc', 'subSupplyDesc'], '');
$transporter = trim((string) $pick($data, ['transporterId'], ''));
$transporterName = trim((string) $pick($data, ['transporterName'], ''));
if ($transporterName !== '') {
    $transporter .= ($transporter !== '' ? ' ' : '') . $transporterName;
}
$portal = (string) $pick($data, ['genMode', 'source', 'portal'], 'API');

$hsn = '';
$itemList = $pick($data, ['itemList', 'ItemList'], []);
if (is_array($itemList) && !empty($itemList[0]) && is_array($itemList[0])) {
    $hsn = (string) $pick($itemList[0], ['hsnCode', 'hsn'], '');
}

$rows = [
    ['Unique No.', $no],
    ['Entered Date', $fmtDateTime($enteredDate)],
    ['Entered By', trim($fromGstin . ' - ' . $fromName)],
    ['Valid From', $validText],
    ['Portal', $portal],
    ['GSTIN of Supplier', trim($fromGstin . ' / ' . $fromName)],
    ['Place of Dispatch', $dispatch],
    ['GSTIN of Recipient', trim($toGstin . ' / ' . $toName)],
    ['Place of Delivery', $delivery],
    ['Document No.', $docNo],
    ['Document Date', $fmtDate($docDate)],
    ['Transaction Type', $transType],
    ['Value of Goods', $valueGoods],
    ['HSN Code', $hsn],
    ['Reason for Transportation', $reason],
    ['Transporter', $transporter],
];

$html = '<h2 style="text-align:center;font-size:16px;">Part - A Slip</h2>';
$html .= '<table border="1" cellpadding="5" cellspacing="0" width="100%">';
foreach ($rows as $r) {
    $html .= '<tr><td width="35%"><b>' . $clean($r[0]) . '</b></td><td width="65%">' . $clean($r[1]) . '</td></tr>';
}
$html .= '</table>';

$pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('InvoiceMate');
$pdf->SetAuthor($businessName);
$pdf->SetTitle('Eway Part-A Slip ' . $no);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(10, 12, 10);
$pdf->AddPage();

if ($logoPath !== '') {
    $pdf->Image($logoPath, 10, 8, 20, 20, '', '', '', false, 300, '', false, false, 0, false, false, false);
    $pdf->SetXY(34, 10);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(160, 6, $businessName, 0, 1, 'L');
    $pdf->Ln(6);
} else {
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, $businessName, 0, 1, 'L');
}

$pdf->SetFont('helvetica', '', 9);
$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Ln(3);

$style = [
    'position' => '',
    'align' => 'C',
    'stretch' => false,
    'fitwidth' => true,
    'cellfitalign' => '',
    'border' => false,
    'hpadding' => 0,
    'vpadding' => 0,
    'fgcolor' => [0, 0, 0],
    'bgcolor' => false,
    'text' => true,
    'font' => 'helvetica',
    'fontsize' => 8,
    'stretchtext' => 4
];
$pdf->write1DBarcode($no, 'C128', '', '', '', 18, 0.35, $style, 'N');
$pdf->Ln(2);
$pdf->SetFont('helvetica', '', 8);
$pdf->MultiCell(0, 5, "Note: If any discrepancy in information please try after sometimes.", 0, 'L', false, 1);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="eway-parta-' . preg_replace('/\D+/', '', $no) . '.pdf"');
$pdf->Output('eway-parta-' . $no . '.pdf', 'I');
exit;


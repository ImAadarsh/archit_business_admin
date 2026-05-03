<?php
/**
 * Mobile: latest e-Way row for an invoice (for enabling View / Part-B / etc. on invoice menu).
 *
 * GET: invoice_id, business_id
 */
include '../../admin/connect.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($connect) || !($connect instanceof mysqli)) {
    echo json_encode(['status' => 'error', 'message' => 'Database unavailable.']);
    exit;
}

$business_id = (int) ($_GET['business_id'] ?? 0);
$invoice_id = (int) ($_GET['invoice_id'] ?? 0);

if ($business_id <= 0 || $invoice_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'business_id and invoice_id are required.']);
    exit;
}

$chk = $connect->prepare('SELECT business_id FROM invoices WHERE id = ? LIMIT 1');
$chk->bind_param('i', $invoice_id);
$chk->execute();
$inv = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$inv || (int) $inv['business_id'] !== $business_id) {
    echo json_encode(['status' => 'error', 'message' => 'Invoice not found for this business.']);
    exit;
}

$sql = 'SELECT eway_bill_no, eway_bill_date, valid_until, status, transporter_id, id
        FROM eway_bills
        WHERE business_id = ? AND invoice_id = ?
        ORDER BY id DESC
        LIMIT 1';
$stmt = $connect->prepare($sql);
$stmt->bind_param('ii', $business_id, $invoice_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode([
        'status' => 'success',
        'has_eway' => false,
        'can_create' => true,
        'can_manage' => false,
        'eway' => null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$st = (string) ($row['status'] ?? '');
$ebn = trim((string) ($row['eway_bill_no'] ?? ''));
$hasNumber = $ebn !== '' && $ebn !== '0';
$isGenerated = $st === 'generated' && $hasNumber;

echo json_encode([
    'status' => 'success',
    'has_eway' => true,
    'can_create' => !$isGenerated,
    'can_manage' => $isGenerated,
    'eway' => [
        'eway_bill_no' => $ebn,
        'eway_bill_date' => $row['eway_bill_date'] ?? null,
        'valid_until' => $row['valid_until'] ?? null,
        'local_status' => $st,
        'transporter_id' => $row['transporter_id'] ?? '',
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

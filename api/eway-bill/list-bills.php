<?php
/**
 * POST JSON: list generated e-Way bills (same filters as web eway-bill.php).
 * Body: { "business_id": int, "q": "", "days": 0, "period": "invoice"|"recorded", "status": "", "nic": "" }
 */
include '../../admin/connect.php';

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
if ($business_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'business_id is required.']);
    exit;
}

$filter_q = trim((string) ($in['q'] ?? ''));
$filter_days = isset($in['days']) ? max(0, (int) $in['days']) : 0;
$filter_period = (string) ($in['period'] ?? 'invoice');
if ($filter_period !== 'recorded') {
    $filter_period = 'invoice';
}
$filter_status = (string) ($in['status'] ?? '');
if (!in_array($filter_status, ['', 'generated', 'cancelled', 'pending'], true)) {
    $filter_status = '';
}
$filter_nic = (string) ($in['nic'] ?? '');
if (!in_array($filter_nic, ['', 'ok', 'stale'], true)) {
    $filter_nic = '';
}

$where = 'ew.business_id = ?';
$params = [$business_id];
$types = 'i';

if ($filter_status !== '') {
    $where .= ' AND ew.status = ?';
    $params[] = $filter_status;
    $types .= 's';
}

if ($filter_q !== '') {
    $like = '%' . $filter_q . '%';
    $where .= ' AND (CAST(IFNULL(i.serial_no, \'\') AS CHAR) LIKE ? OR IFNULL(i.name, \'\') LIKE ? OR IFNULL(i.doc_no, \'\') LIKE ?';
    $where .= ' OR CAST(IFNULL(ew.eway_bill_no, \'\') AS CHAR) LIKE ? OR IFNULL(ew.transporter_id, \'\') LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
    $types .= 'sssss';
}

if ($filter_days > 0) {
    if ($filter_period === 'recorded') {
        $where .= ' AND ew.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
        $params[] = $filter_days;
        $types .= 'i';
    } else {
        $where .= ' AND (
            (i.id IS NOT NULL AND i.invoice_date >= DATE_SUB(NOW(), INTERVAL ? DAY))
            OR (i.id IS NULL AND ew.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY))
        )';
        $params[] = $filter_days;
        $params[] = $filter_days;
        $types .= 'ii';
    }
}

if ($filter_nic === 'ok') {
    $where .= ' AND (i.id IS NULL OR i.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 180 DAY))';
} elseif ($filter_nic === 'stale') {
    $where .= ' AND i.id IS NOT NULL AND i.invoice_date < DATE_SUB(CURDATE(), INTERVAL 180 DAY)';
}

$sql = "SELECT ew.id, ew.invoice_id, ew.eway_bill_no, ew.eway_bill_date, ew.valid_until, ew.status,
               ew.transporter_id, ew.created_at,
               IFNULL(i.serial_no, ew.invoice_id) AS invoice_no,
               IFNULL(i.name, '—') AS customer_name,
               IFNULL(i.total_amount, 0) AS total_amount,
               i.invoice_date
        FROM eway_bills ew
        LEFT JOIN invoices i ON ew.invoice_id = i.id
        WHERE $where
        ORDER BY ew.created_at DESC, ew.id DESC";

$stmt = $connect->prepare($sql);
if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => 'Query failed: ' . $connect->error]);
    exit;
}
$stmt->bind_param($types, ...$params);
if (!$stmt->execute()) {
    echo json_encode(['status' => 'error', 'message' => $stmt->error]);
    exit;
}
$res = $stmt->get_result();
$rows = [];
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
}
$stmt->close();

echo json_encode(['status' => 'success', 'bills' => $rows, 'count' => count($rows)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

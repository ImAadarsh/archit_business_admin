<?php
/**
 * POST JSON: invoices completed & without a generated e-Way bill (create flow).
 * Body: { "business_id", "q", "days", "period", "nic" } (same as eway-bill-create.php)
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

$search = trim((string) ($in['q'] ?? ''));
$days = isset($in['days']) ? max(0, (int) $in['days']) : 0;
$period = (string) ($in['period'] ?? 'invoice');
if ($period !== 'recorded') {
    $period = 'invoice';
}
$nic = (string) ($in['nic'] ?? '');
if (!in_array($nic, ['', 'ok', 'stale'], true)) {
    $nic = '';
}

$params = [$business_id];
$types = 'i';
$where = 'i.business_id = ? AND i.is_completed = 1 AND ew.id IS NULL';

if ($search !== '') {
    $like = '%' . $search . '%';
    $where .= ' AND (CAST(IFNULL(i.serial_no, \'\') AS CHAR) LIKE ? OR i.name LIKE ? OR IFNULL(i.doc_no, \'\') LIKE ?)';
    array_push($params, $like, $like, $like);
    $types .= 'sss';
}
if ($days > 0) {
    if ($period === 'recorded') {
        $where .= ' AND i.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
    } else {
        $where .= ' AND i.invoice_date >= DATE_SUB(NOW(), INTERVAL ? DAY)';
    }
    $params[] = $days;
    $types .= 'i';
}
if ($nic === 'ok') {
    $where .= ' AND i.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 180 DAY)';
} elseif ($nic === 'stale') {
    $where .= ' AND i.invoice_date < DATE_SUB(CURDATE(), INTERVAL 180 DAY)';
}

$sql = "SELECT i.id, i.serial_no, i.name, i.invoice_date, i.total_amount, i.doc_no
        FROM invoices i
        LEFT JOIN eway_bills ew ON i.id = ew.invoice_id AND ew.status = 'generated'
        WHERE $where
        ORDER BY i.invoice_date DESC, i.id DESC";

$stmt = $connect->prepare($sql);
if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => $connect->error]);
    exit;
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
}
$stmt->close();

echo json_encode(['status' => 'success', 'invoices' => $rows, 'count' => count($rows)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

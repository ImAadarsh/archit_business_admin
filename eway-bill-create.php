<?php
/**
 * Create New e-Way Bill — dedicated page listing eligible invoices and launching the
 * generation form. Kept separate from the management page for a cleaner UX.
 *
 * Flow: this list -> click "Generate" -> business/eway-bill-generate.php?invoice_id=...
 */
include 'admin/connect.php';
include 'admin/session.php';
include 'admin/header.php';
include 'controller/EwayBillController.php';

$business_id = $_SESSION['business_id'];
$controller = new EwayBillController($connect);
$db_connected = isset($connect) && $connect instanceof mysqli;

$success_message = isset($_GET['success']) ? (string) $_GET['success'] : null;
$error_message = null;

$settings = $db_connected ? $controller->getEwayBillSettings($business_id) : null;
$settings_missing = !$settings;

$search = trim((string) ($_GET['q'] ?? ''));
$days = isset($_GET['days']) ? max(0, (int) $_GET['days']) : 0; // 0 = no upper bound
$period = (string) ($_GET['period'] ?? 'invoice');
if ($period !== 'recorded') {
    $period = 'invoice';
}
$nic = (string) ($_GET['nic'] ?? '');
if (!in_array($nic, ['', 'ok', 'stale'], true)) {
    $nic = '';
}
$filters_active =
    $search !== '' || $days > 0 || $period === 'recorded' || $nic !== '';
$invoice_count = 0;

$invoices_result = null;
if ($db_connected) {
    $params = [$business_id];
    $types = 'i';
    $where = "i.business_id = ? AND i.is_completed = 1 AND ew.id IS NULL";

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where .= " AND (CAST(IFNULL(i.serial_no, '') AS CHAR) LIKE ? OR i.name LIKE ? OR IFNULL(i.gst_no, '') LIKE ?)";
        array_push($params, $like, $like, $like);
        $types .= 'sss';
    }
    if ($days > 0) {
        if ($period === 'recorded') {
            $where .= " AND i.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        } else {
            $where .= " AND i.invoice_date >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        }
        $params[] = $days;
        $types .= 'i';
    }
    if ($nic === 'ok') {
        $where .= " AND i.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 180 DAY)";
    } elseif ($nic === 'stale') {
        $where .= " AND i.invoice_date < DATE_SUB(CURDATE(), INTERVAL 180 DAY)";
    }

    $sql = "SELECT i.*
              FROM invoices i
              LEFT JOIN eway_bills ew ON i.id = ew.invoice_id AND ew.status = 'generated'
             WHERE $where
             ORDER BY i.invoice_date DESC, i.id DESC";

    $stmt = $connect->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $invoices_result = $stmt->get_result();
        $invoice_count = $invoices_result ? $invoices_result->num_rows : 0;
        // Note: $stmt is left open until the loop reads $invoices_result.
    } else {
        $error_message = 'Database error preparing query: ' . $connect->error;
    }
}

/** Reuse the NIC-aware date parser pattern from eway-bill.php. */
function ebc_parse_date($value)
{
    if ($value === null) {
        return false;
    }
    $s = trim((string) $value);
    if ($s === '' || $s === '0000-00-00 00:00:00' || $s === '0000-00-00') {
        return false;
    }
    $formats = ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y'];
    foreach ($formats as $fmt) {
        $dt = \DateTime::createFromFormat($fmt, $s);
        $errs = \DateTime::getLastErrors();
        if ($dt instanceof \DateTime && (!$errs || ($errs['warning_count'] === 0 && $errs['error_count'] === 0))) {
            return $dt;
        }
    }
    $ts = strtotime($s);
    if ($ts !== false && $ts > 0) {
        $dt = new \DateTime();
        $dt->setTimestamp($ts);
        return $dt;
    }
    return false;
}

function ebc_format_date($value, $placeholder = '<span class="text-muted">—</span>', $format = 'd-M Y')
{
    $dt = ebc_parse_date($value);
    return $dt ? htmlspecialchars($dt->format($format)) : $placeholder;
}

/** Days since invoice; helps surface NIC's 180-day docDate window before the user clicks. */
function ebc_days_since($value)
{
    $dt = ebc_parse_date($value);
    if (!$dt) {
        return null;
    }
    $diff = (new \DateTime('today', new \DateTimeZone('Asia/Kolkata')))->diff($dt);
    return (int) $diff->days * ($diff->invert ? 1 : -1) * -1; // positive when in the past
}
?>

<body class="vertical light">
    <div class="wrapper">
        <?php
        include 'admin/navbar.php';
        include 'admin/aside.php';
        ?>

        <main role="main" class="main-content">
            <div class="container-fluid">
                <div class="row align-items-center mb-4">
                    <div class="col">
                        <h2 class="h3 page-title mb-1">Create New e-Way Bill</h2>
                        <p class="small text-muted mb-0">Pick a completed invoice to start a new e-Way Bill. Bills already
                            generated are hidden.</p>
                    </div>
                    <div class="col-auto">
                        <a href="eway-bill.php" class="btn btn-outline-secondary">
                            <i class="fe fe-arrow-left"></i> Back to Management
                        </a>
                    </div>
                </div>

                <?php if (!$db_connected): ?>
                    <div class="alert alert-danger" role="alert">
                        Database connection failed. e-Way Bill data cannot be loaded until MySQL is reachable.
                    </div>
                <?php endif; ?>

                <?php if ($settings_missing && $db_connected): ?>
                    <div class="alert alert-warning" role="alert">
                        Perione e-Way Bill credentials are not configured for this business yet.
                        <a href="eway-bill.php" class="alert-link">Configure them on the Management page</a> before generating bills.
                    </div>
                <?php endif; ?>

                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>
                <?php if ($error_message): ?>
                    <div class="alert alert-danger" role="alert">
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <div class="card shadow mb-3">
                    <div class="card-body py-3">
                        <form method="get" action="eway-bill-create.php" class="d-flex flex-wrap align-items-end">
                            <div class="form-group mr-3 mb-2 mb-md-0">
                                <label for="q" class="small text-muted mb-1 d-block">Search</label>
                                <input id="q" type="text" name="q" class="form-control form-control-sm"
                                    style="min-width:220px;"
                                    placeholder="invoice no / customer / GSTIN"
                                    value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="form-group mr-3 mb-2 mb-md-0">
                                <label for="days" class="small text-muted mb-1 d-block">Within last</label>
                                <select id="days" name="days" class="form-control form-control-sm">
                                    <?php
                                    $opts = [0 => 'All time', 7 => '7 days', 30 => '30 days', 90 => '90 days', 180 => '180 days (NIC limit)'];
                                    foreach ($opts as $val => $label) {
                                        $sel = ((int) $days === (int) $val) ? ' selected' : '';
                                        echo '<option value="' . (int) $val . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="form-group mr-3 mb-2 mb-md-0">
                                <label for="period" class="small text-muted mb-1 d-block">Apply period to</label>
                                <select id="period" name="period" class="form-control form-control-sm"
                                    title="Invoice date = tax date on the bill. Invoice saved = when the invoice row was created in this app.">
                                    <option value="invoice" <?php echo $period === 'invoice' ? 'selected' : ''; ?>>Invoice date</option>
                                    <option value="recorded" <?php echo $period === 'recorded' ? 'selected' : ''; ?>>Invoice saved in app</option>
                                </select>
                            </div>
                            <div class="form-group mr-3 mb-2 mb-md-0">
                                <label for="nic" class="small text-muted mb-1 d-block">NIC doc date</label>
                                <select id="nic" name="nic" class="form-control form-control-sm"
                                    title="Based on invoice date (same 180-day docDate rule as e-way generation).">
                                    <option value="" <?php echo $nic === '' ? 'selected' : ''; ?>>Any</option>
                                    <option value="ok" <?php echo $nic === 'ok' ? 'selected' : ''; ?>>Within 180 days</option>
                                    <option value="stale" <?php echo $nic === 'stale' ? 'selected' : ''; ?>>Over 180 days</option>
                                </select>
                            </div>
                            <div class="form-group mr-3 mb-2 mb-md-0">
                                <label class="small text-muted mb-1 d-block">Status</label>
                                <select class="form-control form-control-sm text-muted" disabled
                                    title="This page only lists completed invoices that do not have a generated e-Way Bill yet.">
                                    <option>Eligible only</option>
                                </select>
                            </div>
                            <div class="form-group mb-2 mb-md-0 mr-auto">
                                <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                                <?php if ($filters_active): ?>
                                    <a href="eway-bill-create.php" class="btn btn-sm btn-link">Clear</a>
                                <?php endif; ?>
                            </div>
                            <div class="small text-muted mb-1">
                                <?php echo (int) $invoice_count; ?> eligible invoice<?php echo $invoice_count === 1 ? '' : 's'; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card shadow">
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Invoice Date</th>
                                    <th>Customer</th>
                                    <th>Customer GSTIN</th>
                                    <th class="text-right">Amount</th>
                                    <th>NIC Window</th>
                                    <th class="text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($invoices_result && $invoice_count > 0): ?>
                                    <?php while ($row = $invoices_result->fetch_assoc()):
                                        $age = ebc_days_since($row['invoice_date']);
                                        $stale = ($age !== null && $age > 180);
                                        ?>
                                        <tr<?php echo $stale ? ' class="table-warning"' : ''; ?>>
                                            <td><strong><?php echo htmlspecialchars((string) $row['serial_no']); ?></strong></td>
                                            <td><?php echo ebc_format_date($row['invoice_date']); ?></td>
                                            <td><?php echo htmlspecialchars((string) ($row['name'] ?? '')); ?></td>
                                            <td>
                                                <code class="small"><?php echo htmlspecialchars((string) ($row['gst_no'] ?? 'URP')); ?></code>
                                            </td>
                                            <td class="text-right">₹<?php echo number_format((float) ($row['total_amount'] ?? 0), 2); ?></td>
                                            <td>
                                                <?php if ($age === null): ?>
                                                    <span class="text-muted small">—</span>
                                                <?php elseif ($stale): ?>
                                                    <span class="badge badge-warning"
                                                        title="NIC blocks docDate older than 180 days (errors 207 / 820)">
                                                        <?php echo (int) $age; ?> days
                                                        old
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-success small"><?php echo (int) $age; ?> days
                                                        ago</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-right">
                                                <a href="eway-bill-generate.php?invoice_id=<?php echo (int) $row['id']; ?>"
                                                    class="btn btn-primary btn-sm <?php echo $settings_missing ? 'disabled' : ''; ?>"
                                                    <?php echo $settings_missing ? 'aria-disabled="true" tabindex="-1"' : ''; ?>>
                                                    <i class="fe fe-plus"></i> Generate
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">
                                            <?php if ($filters_active): ?>
                                                No eligible invoices match your filter.
                                            <?php else: ?>
                                                All completed invoices already have an e-Way Bill, or no completed invoices exist yet.
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>

        <?php include "admin/footer.php"; ?>
    </div>
</body>

</html>

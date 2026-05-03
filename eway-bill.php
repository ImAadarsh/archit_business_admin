<?php
include 'admin/connect.php';
include 'admin/session.php';
include 'admin/header.php';
include 'controller/EwayBillController.php';

$business_id = $_SESSION['business_id'];
$controller = new EwayBillController($connect);
$db_connected = isset($connect) && $connect instanceof mysqli;

if (isset($_GET['success'])) {
    $success_message = $_GET['success'];
}
if (!empty($_GET['db_error'])) {
    $error_message = 'Database connection failed. Cannot open the generate form until MySQL is reachable.';
}

// Handle Generation Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_eway'])) {
    $invoice_id = $_POST['invoice_id'];
    $transport_details = [
        'transMode' => $_POST['trans_mode'],
        'transDistance' => $_POST['trans_distance'],
        'vehicleNo' => $_POST['vehicle_no'],
        'transporterId' => $_POST['transporter_id'],
        'transporterName' => $_POST['transporter_name'] ?? '',
    ];

    $response = $controller->generateEwayBill($invoice_id, $transport_details);
    if ($response['status'] === 'success') {
        $success_message = "e-Way Bill generated successfully! Number: " . $response['ewayBillNo'];
    } else {
        $error_message = "Failed to generate e-Way Bill: " . $response['message'];
        if (isset($response['api_http_code'])) {
            $error_message .= " (HTTP " . (int) $response['api_http_code'] . ")";
        }
    }
}


// Handle Configuration Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    if (!$db_connected) {
        $error_message = "Cannot save configuration: database connection failed.";
    } else {
        $api_email = $_POST['api_email'];
        $api_username = $_POST['api_username'];
        $api_password = $_POST['api_password'];
        $gstin = $_POST['api_gstin'];
        $client_id = $_POST['api_client_id'];
        $client_secret = $_POST['api_client_secret'];
        $ip_address = $_POST['api_ip_address'] ?? '0.0.0.0';

        $check_sql = "SELECT id FROM eway_bill_settings WHERE business_id = ?";
        $stmt = $connect->prepare($check_sql);
        $stmt->bind_param("i", $business_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();

        if ($res->num_rows > 0) {
            $sql = "UPDATE eway_bill_settings SET api_email=?, api_username=?, api_password=?, gstin=?, client_id=?, client_secret=?, ip_address=? WHERE business_id=?";
            $stmt = $connect->prepare($sql);
            $stmt->bind_param("sssssssi", $api_email, $api_username, $api_password, $gstin, $client_id, $client_secret, $ip_address, $business_id);
        } else {
            $sql = "INSERT INTO eway_bill_settings (business_id, api_email, api_username, api_password, gstin, client_id, client_secret, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $connect->prepare($sql);
            $stmt->bind_param("isssssss", $business_id, $api_email, $api_username, $api_password, $gstin, $client_id, $client_secret, $ip_address);
        }

        if ($stmt->execute()) {
            $success_message = "Configuration saved successfully!";
        } else {
            $error_message = "Error saving configuration: " . $connect->error;
        }
        $stmt->close();
    }
}

// Fetch current configuration
$settings = $db_connected ? $controller->getEwayBillSettings($business_id) : null;

// —— List filters (GET): align with eway-bill-create.php where possible ——
$filter_q = trim((string) ($_GET['q'] ?? ''));
$filter_days = isset($_GET['days']) ? max(0, (int) $_GET['days']) : 0;
$filter_period = (string) ($_GET['period'] ?? 'invoice');
if ($filter_period !== 'recorded') {
    $filter_period = 'invoice';
}
$filter_status = (string) ($_GET['status'] ?? '');
$allowed_status_filters = ['', 'generated', 'cancelled', 'pending'];
if (!in_array($filter_status, $allowed_status_filters, true)) {
    $filter_status = '';
}
$filter_nic = (string) ($_GET['nic'] ?? '');
if (!in_array($filter_nic, ['', 'ok', 'stale'], true)) {
    $filter_nic = '';
}
$filters_active =
    $filter_q !== ''
    || $filter_days > 0
    || $filter_status !== ''
    || $filter_nic !== ''
    || $filter_period === 'recorded';

// Fetch generated e-Way bills
$eway_rows = [];
$eway_list_error = null;
$eligible_invoices = null;
if ($db_connected) {
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
        // Note: use doc_no (always on invoices) — gst_no is not present on all deployments.
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

    $eway_sql = "SELECT ew.*,
                        IFNULL(i.serial_no, ew.invoice_id) AS invoice_no,
                        IFNULL(i.name, '—') AS customer_name,
                        IFNULL(i.total_amount, 0) AS total_amount,
                        i.invoice_date
                 FROM eway_bills ew
                 LEFT JOIN invoices i ON ew.invoice_id = i.id
                 WHERE $where
                 ORDER BY ew.created_at DESC, ew.id DESC";
    $stmt = $connect->prepare($eway_sql);
    if (!$stmt) {
        $eway_list_error = 'Could not load e-Way bills: ' . $connect->error;
    } else {
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            $eway_list_error = 'Could not load e-Way bills: ' . $stmt->error;
        } else {
            $res = $stmt->get_result();
            if (!$res) {
                $eway_list_error = 'Could not read e-Way bill list (enable mysqlnd or check DB). ' . $stmt->error;
            } else {
                while ($row = $res->fetch_assoc()) {
                    $eway_rows[] = $row;
                }
            }
        }
        $stmt->close();
    }

    $eligible_sql = "SELECT i.* FROM invoices i
                     LEFT JOIN eway_bills ew ON i.id = ew.invoice_id AND ew.status = 'generated'
                     WHERE i.business_id = ? AND i.is_completed = 1 AND ew.id IS NULL
                     ORDER BY i.invoice_date DESC";
    $stmt = $connect->prepare($eligible_sql);
    if ($stmt) {
        $stmt->bind_param("i", $business_id);
        $stmt->execute();
        $eligible_invoices = $stmt->get_result();
        $stmt->close();
    }
}


// Handle Form Defaults Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_form_defaults'])) {
    $defaults_to_save = ['supplyType', 'subSupplyType', 'docType', 'transMode', 'vehicleType', 'transactionType'];
    $success_count = 0;
    foreach ($defaults_to_save as $fld) {
        if (isset($_POST[$fld])) {
            if ($controller->saveFormDefault($business_id, 'eway_bill_form', $fld, $_POST[$fld])) {
                $success_count++;
            }
        }
    }
    if ($success_count > 0) {
        $success_message = "Form defaults updated successfully!";
    }
}

// Fetch current form defaults
$form_defaults = $controller->getFormDefaults($business_id, 'eway_bill_form');

// Master codes for UI
$master = [];
$master_file = __DIR__ . '/eway_bills_doc/eway_master_codes.php';
if (is_readable($master_file)) {
    $master = include $master_file;
}
$master = is_array($master) ? $master : [];

function master_options_simple($list, $selected, $empty_label = '')
{
    if (!is_array($list))
        return '';
    $out = $empty_label ? '<option value="">' . htmlspecialchars($empty_label) . '</option>' : '';
    foreach ($list as $code => $label) {
        $s = ($selected !== '' && (string) $selected === (string) $code) ? ' selected' : '';
        $out .= '<option value="' . htmlspecialchars((string) $code) . '"' . $s . '>' . htmlspecialchars($label) . '</option>';
    }
    return $out;
}

/**
 * Parse a NIC e-Way Bill date such as "03/05/2026 04:35:00 PM" (DD/MM/YYYY HH:MM:SS AM/PM).
 * Also accepts "DD/MM/YYYY", "YYYY-MM-DD HH:MM:SS", and ISO timestamps.
 *
 * Returns false when the input is null/empty/unparseable so callers can render a placeholder
 * (avoids the bogus "30-Nov -0001" / "01-Jan 1970" output from strtotime on bad input).
 *
 * @param string|null $value Raw string from `eway_bills.eway_bill_date` / `valid_until`.
 * @return \DateTime|false
 */
function eway_parse_nic_datetime($value)
{
    if ($value === null) {
        return false;
    }
    $s = trim((string) $value);
    if ($s === '' || $s === '0000-00-00 00:00:00' || $s === '0000-00-00' || strcasecmp($s, 'null') === 0) {
        return false;
    }
    $formats = [
        'd/m/Y h:i:s A', // 03/05/2026 04:35:00 PM
        'd/m/Y h:i A',   // 03/05/2026 04:35 PM
        'd/m/Y H:i:s',   // 03/05/2026 16:35:00
        'd/m/Y H:i',     // 03/05/2026 16:35
        'd/m/Y',         // 03/05/2026
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y-m-d\TH:i:sP',
        'Y-m-d',
    ];
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

/** Render NIC datetime with consistent format; show placeholder when missing. */
function eway_format_nic_datetime($value, $placeholder = '<span class="text-muted">—</span>', $format = 'd-M Y H:i')
{
    $dt = eway_parse_nic_datetime($value);
    return $dt ? htmlspecialchars($dt->format($format)) : $placeholder;
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
                <div class="row justify-content-center">
                    <div class="col-12">
                        <div class="row align-items-center mb-4">
                            <div class="col">
                                <h2 class="h3 page-title">e-Way Bill Management</h2>
                                <p class="small text-muted mb-0">Generated bills only. Use the green button to start a
                                    new bill from a completed invoice.</p>
                            </div>
                            <div class="col-auto">
                                <a href="eway-bill-create.php" class="btn btn-success">
                                    <i class="fe fe-plus-circle"></i> Create New e-Way Bill
                                    <?php if ($eligible_invoices && $eligible_invoices->num_rows > 0): ?>
                                        <span class="badge badge-light ml-1"><?php echo (int) $eligible_invoices->num_rows; ?></span>
                                    <?php endif; ?>
                                </a>
                                <button class="btn btn-outline-secondary" type="button" data-toggle="collapse"
                                    data-target="#configSection">
                                    <i class="fe fe-settings"></i> API Configuration
                                </button>
                                <button class="btn btn-outline-info" type="button" data-toggle="collapse"
                                    data-target="#defaultsSection">
                                    <i class="fe fe-list"></i> Form Defaults
                                </button>
                            </div>
                        </div>

                        <!-- Configuration Section -->
                        <div class="collapse <?php echo !$settings ? 'show' : ''; ?> mb-4" id="configSection">
                            <div class="card shadow">
                                <div class="card-header">
                                    <strong class="card-title">API Credentials (Business-wise)</strong>
                                </div>
                                <div class="card-body">
                                    <form method="POST" class="row">
                                        <div class="form-group col-md-4">
                                            <label>API Email</label>
                                            <input type="email" class="form-control" name="api_email"
                                                value="<?php echo $settings['api_email'] ?? ''; ?>" required>
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label>API Username</label>
                                            <input type="text" class="form-control" name="api_username"
                                                value="<?php echo $settings['api_username'] ?? ''; ?>" required>
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label>API Password</label>
                                            <input type="password" class="form-control" name="api_password"
                                                value="<?php echo $settings['api_password'] ?? ''; ?>" required>
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label>GSTIN</label>
                                            <input type="text" class="form-control" name="api_gstin"
                                                value="<?php echo $settings['gstin'] ?? ''; ?>" required>
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label>Client ID</label>
                                            <input type="text" class="form-control" name="api_client_id"
                                                value="<?php echo $settings['client_id'] ?? ''; ?>" required>
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label>Client Secret</label>
                                            <input type="text" class="form-control" name="api_client_secret"
                                                value="<?php echo $settings['client_secret'] ?? ''; ?>" required>
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label>IP Address (Perione header)</label>
                                            <small class="form-text text-muted">Stored in <code>eway_bill_settings</code>.
                                                Use <code>0.0.0.0</code> or <code>auto</code> to send this server’s IP
                                                (detected: <strong><?php echo htmlspecialchars(EwayBillController::detectServerBindIp()); ?></strong>).</small>
                                            <input type="text" class="form-control" name="api_ip_address"
                                                value="<?php echo htmlspecialchars($settings['ip_address'] ?? '0.0.0.0'); ?>" required
                                                placeholder="0.0.0.0 = auto">
                                        </div>
                                        <div class="col-12 text-right">
                                            <button type="submit" name="save_config" class="btn btn-primary">Save
                                                Configuration</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Form Defaults Section -->
                        <div class="collapse mb-4" id="defaultsSection">
                            <div class="card shadow">
                                <div class="card-header">
                                    <strong class="card-title">Default Dropdown Values</strong>
                                </div>
                                <div class="card-body">
                                    <form method="POST" class="row">
                                        <div class="form-group col-md-4">
                                            <label>Default Supply Type</label>
                                            <select name="supplyType"
                                                class="form-control"><?php echo master_options_simple($master['supplyType'] ?? [], $form_defaults['supplyType'] ?? 'O'); ?></select>
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label>Default Sub Supply Type</label>
                                            <select name="subSupplyType"
                                                class="form-control"><?php echo master_options_simple($master['subSupplyType'] ?? [], $form_defaults['subSupplyType'] ?? '1'); ?></select>
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label>Default Document Type</label>
                                            <select name="docType"
                                                class="form-control"><?php echo master_options_simple($master['docType'] ?? [], $form_defaults['docType'] ?? 'INV'); ?></select>
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label>Default Transport Mode</label>
                                            <select name="transMode"
                                                class="form-control"><?php echo master_options_simple($master['transMode'] ?? [], $form_defaults['transMode'] ?? '1'); ?></select>
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label>Default Vehicle Type</label>
                                            <select name="vehicleType"
                                                class="form-control"><?php echo master_options_simple($master['vehicleType'] ?? [], $form_defaults['vehicleType'] ?? 'R'); ?></select>
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label>Default Transaction Type</label>
                                            <select name="transactionType"
                                                class="form-control"><?php echo master_options_simple($master['transactionType'] ?? [], $form_defaults['transactionType'] ?? '1'); ?></select>
                                        </div>
                                        <div class="col-12 text-right">
                                            <button type="submit" name="save_form_defaults" class="btn btn-primary">Save
                                                Defaults</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <?php if (!$db_connected): ?>
                            <div class="alert alert-danger" role="alert">
                                Database connection failed. e-Way Bill data cannot be loaded until MySQL is reachable.
                                Check <code>business/admin/connect.php</code> (host, user, database) and your network or XAMPP MySQL.
                            </div>
                        <?php endif; ?>

                        <?php if ($eway_list_error): ?>
                            <div class="alert alert-danger" role="alert">
                                <?php echo htmlspecialchars($eway_list_error); ?>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($success_message)): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <?php echo $success_message; ?>
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($error_message)): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <div><?php echo $error_message; ?></div>
                                <?php if (isset($response) && (!empty($response['api_response']) || !empty($response['api_decoded']))): ?>
                                    <details class="mt-2 mb-0">
                                        <summary class="small">API response</summary>
                                        <pre class="small bg-dark text-light p-2 rounded mt-1 mb-0"
                                            style="max-height:200px;overflow:auto;"><?php
                                            if (!empty($response['api_decoded'])) {
                                                echo htmlspecialchars(json_encode($response['api_decoded'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                                            } else {
                                                echo htmlspecialchars($response['api_response']);
                                            }
                                            ?></pre>
                                    </details>
                                <?php endif; ?>
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                        <?php endif; ?>

                        <div class="card shadow mb-3">
                            <div class="card-body py-3">
                                <form method="get" action="eway-bill.php" class="d-flex flex-wrap align-items-end">
                                    <div class="form-group mr-3 mb-2 mb-md-0">
                                        <label for="flt_q" class="small text-muted mb-1 d-block">Search</label>
                                        <input id="flt_q" type="text" name="q" class="form-control form-control-sm"
                                            style="min-width:220px;"
                                            placeholder="invoice / customer / GSTIN / e-way no / transporter ID"
                                            value="<?php echo htmlspecialchars($filter_q); ?>">
                                    </div>
                                    <div class="form-group mr-3 mb-2 mb-md-0">
                                        <label for="flt_days" class="small text-muted mb-1 d-block">Within last</label>
                                        <select id="flt_days" name="days" class="form-control form-control-sm">
                                            <?php
                                            $day_opts = [0 => 'All time', 7 => '7 days', 30 => '30 days', 90 => '90 days', 180 => '180 days (NIC limit)'];
                                            foreach ($day_opts as $val => $label) {
                                                $sel = ((int) $filter_days === (int) $val) ? ' selected' : '';
                                                echo '<option value="' . (int) $val . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <div class="form-group mr-3 mb-2 mb-md-0">
                                        <label for="flt_period" class="small text-muted mb-1 d-block">Apply period to</label>
                                        <select id="flt_period" name="period" class="form-control form-control-sm"
                                            title="Invoice date comes from the invoice. e-Way saved uses the local e-way_bills row timestamp.">
                                            <option value="invoice" <?php echo $filter_period === 'invoice' ? 'selected' : ''; ?>>Invoice date</option>
                                            <option value="recorded" <?php echo $filter_period === 'recorded' ? 'selected' : ''; ?>>e-Way saved in app</option>
                                        </select>
                                    </div>
                                    <div class="form-group mr-3 mb-2 mb-md-0">
                                        <label for="flt_status" class="small text-muted mb-1 d-block">Status</label>
                                        <select id="flt_status" name="status" class="form-control form-control-sm">
                                            <option value="" <?php echo $filter_status === '' ? 'selected' : ''; ?>>All</option>
                                            <option value="generated" <?php echo $filter_status === 'generated' ? 'selected' : ''; ?>>Generated</option>
                                            <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                            <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        </select>
                                    </div>
                                    <div class="form-group mr-3 mb-2 mb-md-0">
                                        <label for="flt_nic" class="small text-muted mb-1 d-block">NIC doc date</label>
                                        <select id="flt_nic" name="nic" class="form-control form-control-sm">
                                            <option value="" <?php echo $filter_nic === '' ? 'selected' : ''; ?>>Any</option>
                                            <option value="ok" <?php echo $filter_nic === 'ok' ? 'selected' : ''; ?>>Within 180 days</option>
                                            <option value="stale" <?php echo $filter_nic === 'stale' ? 'selected' : ''; ?>>Over 180 days</option>
                                        </select>
                                    </div>
                                    <div class="form-group mb-2 mb-md-0 mr-auto">
                                        <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                                        <?php if ($filters_active): ?>
                                            <a href="eway-bill.php" class="btn btn-sm btn-link">Clear</a>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small text-muted mb-1">
                                        <?php echo count($eway_rows); ?> bill<?php echo count($eway_rows) === 1 ? '' : 's'; ?>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="row">
                            <!-- Recently Generated List -->
                            <div class="col-md-12">
                                <div class="card shadow mb-4">
                                    <div class="card-header">
                                        <strong class="card-title">Generated e-Way Bills</strong>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-hover datatables">
                                            <thead>
                                                <tr>
                                                    <th>Invoice #</th>
                                                    <th>Customer</th>
                                                    <th>e-Way Bill No</th>
                                                    <th>Generated Date</th>
                                                    <th>Valid Until</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (!$eway_rows && $db_connected): ?>
                                                    <tr>
                                                        <td colspan="7" class="text-center text-muted py-4">
                                                            <?php echo $filters_active
                                                                ? 'No e-Way bills match your filters.'
                                                                : 'No e-Way bills yet. Use Create New to generate one.'; ?>
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                                <?php foreach ($eway_rows as $row): ?>
                                                    <tr>
                                                        <td>
                                                            <?php echo htmlspecialchars((string) $row['invoice_no']); ?>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars((string) $row['customer_name']); ?>
                                                        </td>
                                                        <td><strong>
                                                                <?php echo htmlspecialchars((string) $row['eway_bill_no']); ?>
                                                            </strong></td>
                                                        <td>
                                                            <?php echo eway_format_nic_datetime($row['eway_bill_date']); ?>
                                                        </td>
                                                        <td>
                                                            <?php echo eway_format_nic_datetime(
                                                                $row['valid_until'],
                                                                '<span class="text-muted" title="No vehicle / transport details — Part-A only">PART-A only</span>'
                                                            ); ?>
                                                        </td>
                                                        <td>
                                                            <span
                                                                class="badge badge-<?php echo $row['status'] == 'generated' ? 'success' : 'warning'; ?>">
                                                                <?php echo ucfirst($row['status']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php $disabled = empty($row['eway_bill_no']) || $row['status'] === 'cancelled'; ?>
                                                            <div class="btn-group btn-group-sm" role="group">
                                                                <button class="btn btn-outline-primary eway-view-btn"
                                                                    type="button"
                                                                    data-ebn="<?php echo htmlspecialchars((string) $row['eway_bill_no']); ?>"
                                                                    <?php echo empty($row['eway_bill_no']) ? 'disabled' : ''; ?>>
                                                                    <i class="fe fe-eye"></i> View
                                                                </button>
                                                                <button class="btn btn-outline-info eway-partb-btn"
                                                                    type="button"
                                                                    data-ebn="<?php echo htmlspecialchars((string) $row['eway_bill_no']); ?>"
                                                                    title="Update PART-B / Vehicle"
                                                                    <?php echo $disabled ? 'disabled' : ''; ?>>
                                                                    <i class="fe fe-truck"></i> Update Vehicle
                                                                </button>
                                                                <button class="btn btn-outline-warning eway-trans-btn"
                                                                    type="button"
                                                                    data-ebn="<?php echo htmlspecialchars((string) $row['eway_bill_no']); ?>"
                                                                    data-current-tid="<?php echo htmlspecialchars((string) ($row['transporter_id'] ?? '')); ?>"
                                                                    title="Update Transporter"
                                                                    <?php echo $disabled ? 'disabled' : ''; ?>>
                                                                    <i class="fe fe-user"></i> Update Transporter
                                                                </button>
                                                                <button
                                                                    class="btn <?php echo $row['status'] === 'cancelled' ? 'btn-secondary' : 'btn-danger'; ?> eway-cancel-btn text-white"
                                                                    type="button"
                                                                    data-ebn="<?php echo htmlspecialchars((string) $row['eway_bill_no']); ?>"
                                                                    data-status="<?php echo htmlspecialchars((string) $row['status']); ?>"
                                                                    <?php echo $disabled ? 'disabled' : ''; ?>>
                                                                    <i class="fe fe-x-circle"></i>
                                                                    <?php echo $row['status'] === 'cancelled' ? 'Cancelled' : 'Cancel'; ?>
                                                                </button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

    <?php include "admin/footer.php"; ?>
    </div>

    <!-- View e-Way Bill Modal -->
    <div class="modal fade" id="ewayViewModal" tabindex="-1" role="dialog" aria-labelledby="ewayViewModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="ewayViewModalLabel">e-Way Bill — <span id="ewayViewModalEbn"></span></h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="ewayViewLoading" class="text-center py-4">
                        <div class="spinner-border" role="status"></div>
                        <p class="text-muted mt-2 mb-0">Fetching from Perione...</p>
                    </div>
                    <div id="ewayViewError" class="alert alert-danger" style="display:none;"></div>
                    <div id="ewayViewBody" style="display:none;">

                        <!-- Header summary -->
                        <div class="row mb-3">
                            <div class="col-md-3"><small class="text-muted">EBN</small>
                                <div id="evw-ewbNo" class="font-weight-bold"></div>
                            </div>
                            <div class="col-md-3"><small class="text-muted">Status</small>
                                <div id="evw-status"></div>
                            </div>
                            <div class="col-md-3"><small class="text-muted">Generated</small>
                                <div id="evw-ewayBillDate"></div>
                            </div>
                            <div class="col-md-3"><small class="text-muted">Valid Upto</small>
                                <div id="evw-validUpto"></div>
                            </div>
                        </div>

                        <!-- Document classification (codes explained) -->
                        <div class="card mb-3">
                            <div class="card-header py-2"><strong class="small">Document Classification (NIC codes)</strong></div>
                            <div class="card-body py-2">
                                <div class="row small">
                                    <div class="col-md-3 mb-2"><span class="text-muted">Supply Type</span>
                                        <div id="evw-supplyType"></div>
                                    </div>
                                    <div class="col-md-3 mb-2"><span class="text-muted">Sub Supply Type</span>
                                        <div id="evw-subSupplyType"></div>
                                    </div>
                                    <div class="col-md-3 mb-2"><span class="text-muted">Document Type</span>
                                        <div id="evw-docType"></div>
                                    </div>
                                    <div class="col-md-3 mb-2"><span class="text-muted">Transaction Type</span>
                                        <div id="evw-transactionType"></div>
                                    </div>
                                    <div class="col-md-3 mb-2"><span class="text-muted">Doc No</span>
                                        <div id="evw-docNo"></div>
                                    </div>
                                    <div class="col-md-3 mb-2"><span class="text-muted">Doc Date</span>
                                        <div id="evw-docDate"></div>
                                    </div>
                                    <div class="col-md-3 mb-2"><span class="text-muted">Generation Mode</span>
                                        <div id="evw-genMode"></div>
                                    </div>
                                    <div class="col-md-3 mb-2"><span class="text-muted">Reject Status</span>
                                        <div id="evw-rejectStatus"></div>
                                    </div>
                                    <div class="col-md-3 mb-2"><span class="text-muted">User GSTIN</span>
                                        <div id="evw-userGstin"></div>
                                    </div>
                                    <div class="col-md-3 mb-2"><span class="text-muted">Validity (days)</span>
                                        <div id="evw-noValidDays"></div>
                                    </div>
                                    <div class="col-md-3 mb-2"><span class="text-muted">Extended Times</span>
                                        <div id="evw-extendedTimes"></div>
                                    </div>
                                    <div class="col-md-3 mb-2"><span class="text-muted">Distance (km)</span>
                                        <div id="evw-actualDist"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- From / To -->
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="card h-100">
                                    <div class="card-header py-2"><strong class="small">From (Consignor)</strong></div>
                                    <div class="card-body py-2 small">
                                        <div><strong id="evw-fromTrdName"></strong></div>
                                        <div class="text-muted" id="evw-fromGstin"></div>
                                        <div id="evw-fromAddr"></div>
                                        <div class="mt-2"><span class="text-muted">State:</span> <span
                                                id="evw-fromState"></span></div>
                                        <div><span class="text-muted">Actual From State:</span> <span
                                                id="evw-actFromState"></span></div>
                                        <div><span class="text-muted">Pincode:</span> <span
                                                id="evw-fromPincode"></span></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card h-100">
                                    <div class="card-header py-2"><strong class="small">To (Consignee)</strong></div>
                                    <div class="card-body py-2 small">
                                        <div><strong id="evw-toTrdName"></strong></div>
                                        <div class="text-muted" id="evw-toGstin"></div>
                                        <div id="evw-toAddr"></div>
                                        <div class="mt-2"><span class="text-muted">State:</span> <span
                                                id="evw-toState"></span></div>
                                        <div><span class="text-muted">Actual To State:</span> <span
                                                id="evw-actToState"></span></div>
                                        <div><span class="text-muted">Pincode:</span> <span
                                                id="evw-toPincode"></span></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Transport -->
                        <div class="card mb-3">
                            <div class="card-header py-2"><strong class="small">Transport</strong></div>
                            <div class="card-body py-2">
                                <div class="row small">
                                    <div class="col-md-4 mb-2"><span class="text-muted">Transporter ID</span>
                                        <div id="evw-transporterId"></div>
                                    </div>
                                    <div class="col-md-4 mb-2"><span class="text-muted">Transporter Name</span>
                                        <div id="evw-transporterName"></div>
                                    </div>
                                    <div class="col-md-4 mb-2"><span class="text-muted">Vehicle Type</span>
                                        <div id="evw-vehicleType"></div>
                                    </div>
                                </div>
                                <h6 class="small text-muted mt-2 mb-1">Vehicle / Part-B updates</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>#</th>
                                                <th>Updated</th>
                                                <th>Vehicle No</th>
                                                <th>From Place</th>
                                                <th>From State</th>
                                                <th>Trans Mode</th>
                                                <th>Trans Doc No / Date</th>
                                            </tr>
                                        </thead>
                                        <tbody id="evw-vehicleList">
                                            <tr>
                                                <td colspan="7" class="text-muted text-center small">No vehicle / Part-B
                                                    update yet (PART-A only).</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Tax / Totals -->
                        <div class="card mb-3">
                            <div class="card-header py-2"><strong class="small">Tax & Totals</strong></div>
                            <div class="card-body py-2">
                                <div class="row small">
                                    <div class="col-md-3 mb-2"><span class="text-muted">Total (Taxable)</span>
                                        <div id="evw-totalValue"></div>
                                    </div>
                                    <div class="col-md-3 mb-2"><span class="text-muted">CGST</span>
                                        <div id="evw-cgstValue"></div>
                                    </div>
                                    <div class="col-md-3 mb-2"><span class="text-muted">SGST</span>
                                        <div id="evw-sgstValue"></div>
                                    </div>
                                    <div class="col-md-3 mb-2"><span class="text-muted">IGST</span>
                                        <div id="evw-igstValue"></div>
                                    </div>
                                    <div class="col-md-3 mb-2"><span class="text-muted">Cess</span>
                                        <div id="evw-cessValue"></div>
                                    </div>
                                    <div class="col-md-3 mb-2"><span class="text-muted">Cess Non-Advol</span>
                                        <div id="evw-cessNonAdvolValue"></div>
                                    </div>
                                    <div class="col-md-3 mb-2"><span class="text-muted">Other Charges</span>
                                        <div id="evw-otherValue"></div>
                                    </div>
                                    <div class="col-md-3 mb-2"><strong class="text-muted">Total Invoice Value</strong>
                                        <div id="evw-totInvValue" class="font-weight-bold"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Items -->
                        <h6 class="mt-3">Items</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead class="thead-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Product</th>
                                        <th>HSN</th>
                                        <th class="text-right">Qty</th>
                                        <th>Unit (UQC)</th>
                                        <th class="text-right">Taxable</th>
                                        <th class="text-right">CGST%</th>
                                        <th class="text-right">SGST%</th>
                                        <th class="text-right">IGST%</th>
                                        <th class="text-right">Cess%</th>
                                    </tr>
                                </thead>
                                <tbody id="evw-itemList"></tbody>
                            </table>
                        </div>

                        <details class="mt-3">
                            <summary class="small">Full API response (raw)</summary>
                            <pre id="evw-raw"
                                class="small bg-dark text-light p-2 rounded mt-2 mb-0" style="max-height:300px;overflow:auto;"></pre>
                        </details>
                        <details class="mt-2">
                            <summary class="small">Perione API call log</summary>
                            <pre id="evw-log"
                                class="small bg-dark text-light p-2 rounded mt-2 mb-0" style="max-height:300px;overflow:auto;"></pre>
                        </details>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Update PART-B / Vehicle Modal -->
    <div class="modal fade" id="ewayPartBModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">Update PART-B / Vehicle — <span id="ewayPartBModalEbn"></span></h5>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info small mb-3">
                        Sets / updates the vehicle details (Part-B). NIC requires <strong>Vehicle No</strong> when
                        Transport Mode is Road, or <strong>Trans Doc No</strong> for Rail / Air / Ship. Updates a fresh
                        validity window from this point.
                    </div>
                    <div id="ewayPartBError" class="alert alert-danger" style="display:none;"></div>
                    <div id="ewayPartBSuccess" class="alert alert-success" style="display:none;"></div>
                    <div class="row">
                        <div class="form-group col-md-4">
                            <label>From Place <span class="text-danger">*</span></label>
                            <input type="text" id="partb_fromPlace" class="form-control" maxlength="50"
                                placeholder="e.g. KIRTI NAGAR" required>
                        </div>
                        <div class="form-group col-md-4">
                            <label>From State <span class="text-danger">*</span></label>
                            <select id="partb_fromState"
                                class="form-control"><?php echo master_options_simple($master['stateCode'] ?? [], '7'); ?></select>
                        </div>
                        <div class="form-group col-md-4">
                            <label>Transport Mode <span class="text-danger">*</span></label>
                            <select id="partb_transMode"
                                class="form-control"><?php echo master_options_simple($master['transMode'] ?? [], '1'); ?></select>
                        </div>
                        <div class="form-group col-md-4">
                            <label>Vehicle No <span class="text-danger" id="partb_vehicleNo_required">*</span></label>
                            <input type="text" id="partb_vehicleNo" class="form-control" maxlength="15"
                                placeholder="e.g. KA12AB1234">
                        </div>
                        <div class="form-group col-md-4">
                            <label>Trans Doc No <span class="text-muted small" id="partb_transDocNo_hint">(Rail / Air /
                                    Ship)</span></label>
                            <input type="text" id="partb_transDocNo" class="form-control" maxlength="15">
                        </div>
                        <div class="form-group col-md-4">
                            <label>Trans Doc Date (DD/MM/YYYY)</label>
                            <input type="text" id="partb_transDocDate" class="form-control" placeholder="03/05/2026">
                        </div>
                        <div class="form-group col-md-4">
                            <label>Reason <span class="text-danger">*</span></label>
                            <select id="partb_reasonCode" class="form-control">
                                <option value="1">1 — Due to Break Down</option>
                                <option value="2">2 — Due to Trans Shipment</option>
                                <option value="3">3 — Others</option>
                                <option value="4" selected>4 — First Time</option>
                            </select>
                        </div>
                        <div class="form-group col-md-8">
                            <label>Reason Remarks (max 50)</label>
                            <input type="text" id="partb_reasonRem" class="form-control" maxlength="50"
                                placeholder="e.g. First time vehicle assignment">
                        </div>
                    </div>
                    <details class="mt-2">
                        <summary class="small">Perione API call log</summary>
                        <pre id="partb_log" class="small bg-dark text-light p-2 rounded mt-2 mb-0"
                            style="max-height:240px;overflow:auto;"></pre>
                    </details>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-info text-white" id="ewayPartBSubmit">
                        <span class="spinner-border spinner-border-sm" id="ewayPartBSpinner" style="display:none;"></span>
                        Update PART-B
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Transporter Modal -->
    <div class="modal fade" id="ewayTransModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">Update Transporter — <span id="ewayTransModalEbn"></span></h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning small mb-3">
                        Reassigns this e-Way Bill to a different transporter (15-character GSTIN or TRANSIN). NIC
                        validates the ID against their registry; invalid IDs return an error.
                    </div>
                    <div id="ewayTransError" class="alert alert-danger" style="display:none;"></div>
                    <div id="ewayTransSuccess" class="alert alert-success" style="display:none;"></div>
                    <div class="form-group">
                        <label>Current Transporter ID</label>
                        <input type="text" id="trans_currentTid" class="form-control" readonly>
                    </div>
                    <div class="form-group">
                        <label>New Transporter ID (GSTIN / TRANSIN) <span class="text-danger">*</span></label>
                        <input type="text" id="trans_newTid" class="form-control" maxlength="15"
                            placeholder="15 characters, e.g. 05AAACG0904A1ZL" required>
                    </div>
                    <details class="mt-2">
                        <summary class="small">Perione API call log</summary>
                        <pre id="trans_log" class="small bg-dark text-light p-2 rounded mt-2 mb-0"
                            style="max-height:240px;overflow:auto;"></pre>
                    </details>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-warning" id="ewayTransSubmit">
                        <span class="spinner-border spinner-border-sm" id="ewayTransSpinner" style="display:none;"></span>
                        Update Transporter
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Cancel e-Way Bill Modal -->
    <div class="modal fade" id="ewayCancelModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Cancel e-Way Bill — <span id="ewayCancelModalEbn"></span></h5>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning small mb-3">
                        NIC allows cancellation only within <strong>24 hours</strong> of generation, and only if the bill
                        has not been verified by an officer in transit.
                    </div>
                    <div id="ewayCancelError" class="alert alert-danger" style="display:none;"></div>
                    <div id="ewayCancelSuccess" class="alert alert-success" style="display:none;"></div>
                    <div class="form-group">
                        <label>Reason <span class="text-danger">*</span></label>
                        <select id="ewayCancelReason" class="form-control" required>
                            <option value="1">1 — Duplicate</option>
                            <option value="2" selected>2 — Order Cancelled</option>
                            <option value="3">3 — Data Entry Mistake</option>
                            <option value="4">4 — Others</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Remarks (max 100 chars)</label>
                        <textarea id="ewayCancelRemarks" class="form-control" rows="2" maxlength="100"
                            placeholder="Optional reason notes"></textarea>
                    </div>
                    <details class="mt-2">
                        <summary class="small">Perione API call log</summary>
                        <pre id="ecn-log"
                            class="small bg-dark text-light p-2 rounded mt-2 mb-0" style="max-height:240px;overflow:auto;"></pre>
                    </details>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-danger" id="ewayCancelSubmit">
                        <span class="spinner-border spinner-border-sm" role="status" id="ewayCancelSpinner"
                            style="display:none;"></span>
                        Confirm Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // NIC master codes (from business/eway_bills_doc/eway_master_codes.php) for in-modal labels.
        window.EWAY_MASTER = <?php echo json_encode([
            'supplyType' => $master['supplyType'] ?? [],
            'subSupplyType' => $master['subSupplyType'] ?? [],
            'docType' => $master['docType'] ?? [],
            'transMode' => $master['transMode'] ?? [],
            'vehicleType' => $master['vehicleType'] ?? [],
            'transactionType' => $master['transactionType'] ?? [],
            'qtyUnit' => $master['qtyUnit'] ?? [],
            'stateCode' => $master['stateCode'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        // Field-only NIC codes that aren't part of the master file.
        window.EWAY_EXTRA_CODES = {
            status: { ACT: 'Active', CNL: 'Cancelled', REJ: 'Rejected by other party' },
            rejectStatus: { N: 'Not rejected', Y: 'Rejected by other party' },
            genMode: { API: 'API', PORTAL: 'NIC Portal', MOBILE: 'Mobile App', SMS: 'SMS', BULK: 'Bulk Upload', 'TAX-PAYER': 'Tax payer' }
        };

        (function () {
            function $(id) { return document.getElementById(id); }
            function escHtml(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }
            function fmtMoney(n) { var v = Number(n); return isFinite(v) ? v.toFixed(2) : (n == null ? '—' : String(n)); }
            function joinAddr() { return Array.prototype.slice.call(arguments).filter(function (x) { return x !== '' && x != null; }).join(', '); }

            /** Render "code — Label" if known, else just the code. Trims NIC's whitespace-padded codes (e.g. "1  "). */
            function codeLabel(group, value) {
                if (value == null || value === '') return '<span class="text-muted">—</span>';
                var key = String(value).trim();
                var map = (window.EWAY_MASTER || {})[group] || {};
                var label = map[key] != null ? map[key] : (map[Number(key)] != null ? map[Number(key)] : null);
                if (label) {
                    return '<span class="font-weight-bold">' + escHtml(key) + '</span> <span class="text-muted">— ' + escHtml(label) + '</span>';
                }
                return escHtml(key);
            }

            function extraCodeLabel(group, value) {
                if (value == null || value === '') return '<span class="text-muted">—</span>';
                var map = (window.EWAY_EXTRA_CODES || {})[group] || {};
                var key = String(value).trim();
                var label = map[key];
                if (label) {
                    return '<span class="font-weight-bold">' + escHtml(key) + '</span> <span class="text-muted">— ' + escHtml(label) + '</span>';
                }
                return escHtml(key);
            }

            function statusBadge(status) {
                var s = String(status || '').trim();
                var cls = s === 'ACT' ? 'success' : (s === 'CNL' ? 'danger' : (s === 'REJ' ? 'warning' : 'secondary'));
                var label = ((window.EWAY_EXTRA_CODES.status || {})[s]) || s || '—';
                return '<span class="badge badge-' + cls + '">' + escHtml(s || '—') + '</span> <span class="small text-muted ml-1">' + escHtml(label) + '</span>';
            }

            function showViewModal(d, raw, log) {
                // Header summary
                $('evw-ewbNo').textContent = d.ewbNo || d.ewayBillNo || '';
                $('evw-status').innerHTML = statusBadge(d.status);
                $('evw-ewayBillDate').textContent = d.ewayBillDate || '—';
                var validUpto = String(d.validUpto || '').trim();
                if (validUpto === '') {
                    var hint = (d.noValidDays != null) ? ('PART-A only — +' + d.noValidDays + ' days once Part-B is added') : 'PART-A only';
                    $('evw-validUpto').innerHTML = '<span class="text-muted">' + escHtml(hint) + '</span>';
                } else {
                    $('evw-validUpto').textContent = validUpto;
                }

                // Document Classification (codes explained)
                $('evw-supplyType').innerHTML = codeLabel('supplyType', d.supplyType);
                $('evw-subSupplyType').innerHTML = codeLabel('subSupplyType', d.subSupplyType);
                $('evw-docType').innerHTML = codeLabel('docType', d.docType);
                $('evw-transactionType').innerHTML = codeLabel('transactionType', d.transactionType);
                $('evw-docNo').textContent = d.docNo || '—';
                $('evw-docDate').textContent = d.docDate || '—';
                $('evw-genMode').innerHTML = extraCodeLabel('genMode', d.genMode);
                $('evw-rejectStatus').innerHTML = extraCodeLabel('rejectStatus', d.rejectStatus);
                $('evw-userGstin').textContent = d.userGstin || '—';
                $('evw-noValidDays').textContent = (d.noValidDays != null) ? d.noValidDays : '—';
                $('evw-extendedTimes').textContent = (d.extendedTimes != null) ? d.extendedTimes : '—';
                $('evw-actualDist').textContent = (d.actualDist != null) ? (d.actualDist + ' km') : '—';

                // From / To
                $('evw-fromTrdName').textContent = d.fromTrdName || '';
                $('evw-fromGstin').textContent = d.fromGstin || '';
                $('evw-fromAddr').textContent = joinAddr(d.fromAddr1, d.fromAddr2, d.fromPlace);
                $('evw-fromState').innerHTML = codeLabel('stateCode', d.fromStateCode);
                $('evw-actFromState').innerHTML = codeLabel('stateCode', d.actFromStateCode);
                $('evw-fromPincode').textContent = d.fromPincode != null ? d.fromPincode : '—';

                $('evw-toTrdName').textContent = d.toTrdName || '';
                $('evw-toGstin').textContent = d.toGstin || '';
                $('evw-toAddr').textContent = joinAddr(d.toAddr1, d.toAddr2, d.toPlace);
                $('evw-toState').innerHTML = codeLabel('stateCode', d.toStateCode);
                $('evw-actToState').innerHTML = codeLabel('stateCode', d.actToStateCode);
                $('evw-toPincode').textContent = d.toPincode != null ? d.toPincode : '—';

                // Transport
                $('evw-transporterId').textContent = d.transporterId || '—';
                $('evw-transporterName').textContent = (d.transporterName || '').trim() || '—';
                $('evw-vehicleType').innerHTML = codeLabel('vehicleType', d.vehicleType);

                // Vehicle / Part-B updates
                var vList = (d.VehiclListDetails || d.vehicleListDetails || []);
                var vBody = $('evw-vehicleList');
                vBody.innerHTML = '';
                if (!vList.length) {
                    vBody.innerHTML = '<tr><td colspan="7" class="text-muted text-center small">No vehicle / Part-B update yet (PART-A only).</td></tr>';
                } else {
                    vList.forEach(function (v, i) {
                        var tr = document.createElement('tr');
                        tr.innerHTML = '<td>' + (i + 1) + '</td>'
                            + '<td>' + escHtml(v.updMode || v.updatedDate || '—') + '</td>'
                            + '<td>' + escHtml(v.vehicleNo || '—') + '</td>'
                            + '<td>' + escHtml(v.fromPlace || '—') + '</td>'
                            + '<td>' + codeLabel('stateCode', v.fromState) + '</td>'
                            + '<td>' + codeLabel('transMode', v.transMode) + '</td>'
                            + '<td>' + escHtml(v.transDocNo || '—') + (v.transDocDate ? (' / ' + escHtml(v.transDocDate)) : '') + '</td>';
                        vBody.appendChild(tr);
                    });
                }

                // Tax / Totals
                $('evw-totalValue').textContent = fmtMoney(d.totalValue);
                $('evw-cgstValue').textContent = fmtMoney(d.cgstValue);
                $('evw-sgstValue').textContent = fmtMoney(d.sgstValue);
                $('evw-igstValue').textContent = fmtMoney(d.igstValue);
                $('evw-cessValue').textContent = fmtMoney(d.cessValue);
                $('evw-cessNonAdvolValue').textContent = fmtMoney(d.cessNonAdvolValue);
                $('evw-otherValue').textContent = fmtMoney(d.otherValue);
                $('evw-totInvValue').textContent = fmtMoney(d.totInvValue);

                // Items
                var tbody = $('evw-itemList'); tbody.innerHTML = '';
                (d.itemList || []).forEach(function (it, idx) {
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td>' + (it.itemNo || (idx + 1)) + '</td>'
                        + '<td>' + escHtml(it.productName || it.productDesc || '') + '</td>'
                        + '<td>' + escHtml(it.hsnCode || '') + '</td>'
                        + '<td class="text-right">' + escHtml(it.quantity != null ? it.quantity : '') + '</td>'
                        + '<td>' + codeLabel('qtyUnit', it.qtyUnit) + '</td>'
                        + '<td class="text-right">' + fmtMoney(it.taxableAmount) + '</td>'
                        + '<td class="text-right">' + escHtml(it.cgstRate != null ? it.cgstRate : 0) + '</td>'
                        + '<td class="text-right">' + escHtml(it.sgstRate != null ? it.sgstRate : 0) + '</td>'
                        + '<td class="text-right">' + escHtml(it.igstRate != null ? it.igstRate : 0) + '</td>'
                        + '<td class="text-right">' + escHtml(it.cessRate != null ? it.cessRate : 0) + '</td>';
                    tbody.appendChild(tr);
                });

                $('evw-raw').textContent = JSON.stringify(raw, null, 2);
                $('evw-log').textContent = JSON.stringify(log || [], null, 2);
                $('ewayViewLoading').style.display = 'none';
                $('ewayViewError').style.display = 'none';
                $('ewayViewBody').style.display = 'block';
            }

            document.addEventListener('click', function (ev) {
                var viewBtn = ev.target.closest('.eway-view-btn');
                if (viewBtn) {
                    var ebn = viewBtn.getAttribute('data-ebn') || '';
                    if (!ebn) return;
                    $('ewayViewModalEbn').textContent = ebn;
                    $('ewayViewLoading').style.display = 'block';
                    $('ewayViewError').style.display = 'none';
                    $('ewayViewBody').style.display = 'none';
                    if (window.jQuery && jQuery('#ewayViewModal').modal) jQuery('#ewayViewModal').modal('show');
                    fetch('ajax_eway_view.php?ebn=' + encodeURIComponent(ebn))
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (res.status === 'success' && res.data) {
                                showViewModal(res.data, res, res.api_communication_log || []);
                            } else {
                                $('ewayViewLoading').style.display = 'none';
                                $('ewayViewError').textContent = res.message || 'Failed to fetch e-Way Bill.';
                                $('ewayViewError').style.display = 'block';
                            }
                        })
                        .catch(function (err) {
                            $('ewayViewLoading').style.display = 'none';
                            $('ewayViewError').textContent = 'Network error: ' + err;
                            $('ewayViewError').style.display = 'block';
                        });
                    return;
                }

                var cancelBtn = ev.target.closest('.eway-cancel-btn');
                if (cancelBtn && !cancelBtn.disabled) {
                    var ebn = cancelBtn.getAttribute('data-ebn') || '';
                    if (!ebn) return;
                    $('ewayCancelModalEbn').textContent = ebn;
                    $('ewayCancelError').style.display = 'none';
                    $('ewayCancelSuccess').style.display = 'none';
                    $('ecn-log').textContent = '';
                    $('ewayCancelSubmit').setAttribute('data-ebn', ebn);
                    if (window.jQuery && jQuery('#ewayCancelModal').modal) jQuery('#ewayCancelModal').modal('show');
                    return;
                }

                var partbBtn = ev.target.closest('.eway-partb-btn');
                if (partbBtn && !partbBtn.disabled) {
                    var ebn = partbBtn.getAttribute('data-ebn') || '';
                    if (!ebn) return;
                    $('ewayPartBModalEbn').textContent = ebn;
                    $('ewayPartBError').style.display = 'none';
                    $('ewayPartBSuccess').style.display = 'none';
                    $('partb_log').textContent = '';
                    $('ewayPartBSubmit').setAttribute('data-ebn', ebn);
                    syncPartBModeRequirement();
                    if (window.jQuery && jQuery('#ewayPartBModal').modal) jQuery('#ewayPartBModal').modal('show');
                    return;
                }

                var transBtn = ev.target.closest('.eway-trans-btn');
                if (transBtn && !transBtn.disabled) {
                    var ebn = transBtn.getAttribute('data-ebn') || '';
                    if (!ebn) return;
                    $('ewayTransModalEbn').textContent = ebn;
                    $('trans_currentTid').value = transBtn.getAttribute('data-current-tid') || '';
                    $('trans_newTid').value = '';
                    $('ewayTransError').style.display = 'none';
                    $('ewayTransSuccess').style.display = 'none';
                    $('trans_log').textContent = '';
                    $('ewayTransSubmit').setAttribute('data-ebn', ebn);
                    if (window.jQuery && jQuery('#ewayTransModal').modal) jQuery('#ewayTransModal').modal('show');
                    return;
                }
            });

            /** Toggle "required" hints on the PART-B modal based on Transport Mode. */
            function syncPartBModeRequirement() {
                var mode = $('partb_transMode') ? $('partb_transMode').value : '';
                var vehReq = $('partb_vehicleNo_required');
                var docHint = $('partb_transDocNo_hint');
                if (!vehReq || !docHint) return;
                if (mode === '1') {
                    vehReq.style.display = '';
                    docHint.textContent = '(optional)';
                } else if (['2', '3', '4'].indexOf(mode) >= 0) {
                    vehReq.style.display = 'none';
                    docHint.textContent = '(required for Rail / Air / Ship)';
                } else {
                    vehReq.style.display = 'none';
                    docHint.textContent = '';
                }
            }
            if ($('partb_transMode')) {
                $('partb_transMode').addEventListener('change', syncPartBModeRequirement);
            }

            var partbSubmit = document.getElementById('ewayPartBSubmit');
            if (partbSubmit) {
                partbSubmit.addEventListener('click', function () {
                    var ebn = partbSubmit.getAttribute('data-ebn') || '';
                    if (!ebn) return;
                    var fd = new FormData();
                    fd.append('ebn', ebn);
                    fd.append('vehicleNo', $('partb_vehicleNo').value);
                    fd.append('fromPlace', $('partb_fromPlace').value);
                    fd.append('fromState', $('partb_fromState').value);
                    fd.append('reasonCode', $('partb_reasonCode').value);
                    fd.append('reasonRem', $('partb_reasonRem').value);
                    fd.append('transDocNo', $('partb_transDocNo').value);
                    fd.append('transDocDate', $('partb_transDocDate').value);
                    fd.append('transMode', $('partb_transMode').value);
                    partbSubmit.disabled = true;
                    $('ewayPartBSpinner').style.display = 'inline-block';
                    $('ewayPartBError').style.display = 'none';
                    $('ewayPartBSuccess').style.display = 'none';
                    fetch('ajax_eway_partb.php', { method: 'POST', body: fd })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            partbSubmit.disabled = false;
                            $('ewayPartBSpinner').style.display = 'none';
                            $('partb_log').textContent = JSON.stringify(res.api_communication_log || [], null, 2);
                            if (res.status === 'success') {
                                $('ewayPartBSuccess').textContent = 'PART-B updated. Valid Upto: ' + (res.validUpto || '—') + '. Refreshing...';
                                $('ewayPartBSuccess').style.display = 'block';
                                setTimeout(function () { window.location.reload(); }, 1300);
                            } else {
                                $('ewayPartBError').textContent = res.message || 'Failed to update PART-B.';
                                $('ewayPartBError').style.display = 'block';
                            }
                        })
                        .catch(function (err) {
                            partbSubmit.disabled = false;
                            $('ewayPartBSpinner').style.display = 'none';
                            $('ewayPartBError').textContent = 'Network error: ' + err;
                            $('ewayPartBError').style.display = 'block';
                        });
                });
            }

            var transSubmit = document.getElementById('ewayTransSubmit');
            if (transSubmit) {
                transSubmit.addEventListener('click', function () {
                    var ebn = transSubmit.getAttribute('data-ebn') || '';
                    if (!ebn) return;
                    var fd = new FormData();
                    fd.append('ebn', ebn);
                    fd.append('transporterId', ($('trans_newTid').value || '').toUpperCase().replace(/\s+/g, ''));
                    transSubmit.disabled = true;
                    $('ewayTransSpinner').style.display = 'inline-block';
                    $('ewayTransError').style.display = 'none';
                    $('ewayTransSuccess').style.display = 'none';
                    fetch('ajax_eway_transporter.php', { method: 'POST', body: fd })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            transSubmit.disabled = false;
                            $('ewayTransSpinner').style.display = 'none';
                            $('trans_log').textContent = JSON.stringify(res.api_communication_log || [], null, 2);
                            if (res.status === 'success') {
                                $('ewayTransSuccess').textContent = 'Transporter updated to ' + (res.transporterId || '—') + '. Refreshing...';
                                $('ewayTransSuccess').style.display = 'block';
                                setTimeout(function () { window.location.reload(); }, 1300);
                            } else {
                                $('ewayTransError').textContent = res.message || 'Failed to update transporter.';
                                $('ewayTransError').style.display = 'block';
                            }
                        })
                        .catch(function (err) {
                            transSubmit.disabled = false;
                            $('ewayTransSpinner').style.display = 'none';
                            $('ewayTransError').textContent = 'Network error: ' + err;
                            $('ewayTransError').style.display = 'block';
                        });
                });
            }

            var submit = document.getElementById('ewayCancelSubmit');
            if (submit) {
                submit.addEventListener('click', function () {
                    var ebn = submit.getAttribute('data-ebn') || '';
                    if (!ebn) return;
                    var fd = new FormData();
                    fd.append('ebn', ebn);
                    fd.append('reason_code', $('ewayCancelReason').value);
                    fd.append('remarks', $('ewayCancelRemarks').value);
                    submit.disabled = true;
                    $('ewayCancelSpinner').style.display = 'inline-block';
                    $('ewayCancelError').style.display = 'none';
                    $('ewayCancelSuccess').style.display = 'none';
                    fetch('ajax_eway_cancel.php', { method: 'POST', body: fd })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            submit.disabled = false;
                            $('ewayCancelSpinner').style.display = 'none';
                            $('ecn-log').textContent = JSON.stringify(res.api_communication_log || [], null, 2);
                            if (res.status === 'success') {
                                $('ewayCancelSuccess').textContent = 'Cancelled successfully' + (res.cancelDate ? (' on ' + res.cancelDate) : '') + '. Refreshing...';
                                $('ewayCancelSuccess').style.display = 'block';
                                setTimeout(function () { window.location.reload(); }, 1200);
                            } else {
                                $('ewayCancelError').textContent = res.message || 'Failed to cancel.';
                                $('ewayCancelError').style.display = 'block';
                            }
                        })
                        .catch(function (err) {
                            submit.disabled = false;
                            $('ewayCancelSpinner').style.display = 'none';
                            $('ewayCancelError').textContent = 'Network error: ' + err;
                            $('ewayCancelError').style.display = 'block';
                        });
                });
            }
        })();
    </script>
</body>

</html>
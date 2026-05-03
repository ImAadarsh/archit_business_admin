<?php
include 'admin/connect.php';
include 'admin/session.php';
include 'admin/header.php';
include 'controller/EwayBillController.php';

$business_id = $_SESSION['business_id'];
$controller = new EwayBillController($connect);
$db_connected = isset($connect) && $connect instanceof mysqli;

$invoice_id = isset($_GET['invoice_id']) ? (int) $_GET['invoice_id'] : (isset($_POST['invoice_id']) ? (int) $_POST['invoice_id'] : 0);
if (!$invoice_id) {
    header('Location: eway-bill.php');
    exit;
}

if (!$db_connected) {
    header('Location: eway-bill.php?db_error=1');
    exit;
}

// Verify invoice belongs to business
$chk = $connect->prepare("SELECT id, serial_no FROM invoices WHERE id = ? AND business_id = ?");
if (!$chk) {
    header('Location: eway-bill.php?db_error=1');
    exit;
}
$chk->bind_param("ii", $invoice_id, $business_id);
$chk->execute();
$inv_row = $chk->get_result()->fetch_assoc();
$chk->close();
if (!$inv_row) {
    header('Location: eway-bill.php');
    exit;
}

$success_message = null;
$error_message = null;
/** @var array|null Set when generate succeeds — stay on page to show full Perione API log */
$eway_generate_success = null;

// Build payload from form and generate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_eway_full'])) {
    $item_list = [];
    if (!empty($_POST['itemList_json'])) {
        $item_list = json_decode($_POST['itemList_json'], true);
        if (!is_array($item_list))
            $item_list = [];
    }

    $payload = [
        'supplyType' => $_POST['supplyType'] ?? 'O',
        'subSupplyType' => $_POST['subSupplyType'] ?? '1',
        'subSupplyDesc' => $_POST['subSupplyDesc'] ?? '',
        'docType' => $_POST['docType'] ?? 'INV',
        'docNo' => $_POST['docNo'] ?? '',
        'docDate' => $_POST['docDate'] ?? '',
        'fromGstin' => $_POST['fromGstin'] ?? '',
        'fromTrdName' => $_POST['fromTrdName'] ?? '',
        'fromAddr1' => $_POST['fromAddr1'] ?? '',
        'fromAddr2' => $_POST['fromAddr2'] ?? '',
        'fromPlace' => $_POST['fromPlace'] ?? '',
        'fromPincode' => (int) ($_POST['fromPincode'] ?? 0),
        'fromStateCode' => (int) ($_POST['fromStateCode'] ?? 7),
        'actFromStateCode' => (int) ($_POST['actFromStateCode'] ?? 7),
        'toGstin' => $_POST['toGstin'] ?? 'URP',
        'toTrdName' => $_POST['toTrdName'] ?? '',
        'toAddr1' => $_POST['toAddr1'] ?? '',
        'toAddr2' => $_POST['toAddr2'] ?? '',
        'toPlace' => $_POST['toPlace'] ?? '',
        'toPincode' => (int) ($_POST['toPincode'] ?? 0),
        'toStateCode' => (int) ($_POST['toStateCode'] ?? 7),
        'actToStateCode' => (int) ($_POST['actToStateCode'] ?? 7),
        'transactionType' => (int) ($_POST['transactionType'] ?? 1),
        'otherValue' => (float) ($_POST['otherValue'] ?? 0),
        'totalValue' => (float) ($_POST['totalValue'] ?? 0),
        'cgstValue' => (float) ($_POST['cgstValue'] ?? 0),
        'sgstValue' => (float) ($_POST['sgstValue'] ?? 0),
        'igstValue' => (float) ($_POST['igstValue'] ?? 0),
        'cessValue' => (float) ($_POST['cessValue'] ?? 0),
        'cessNonAdvolValue' => (float) ($_POST['cessNonAdvolValue'] ?? 0),
        'totInvValue' => (float) ($_POST['totInvValue'] ?? 0),
        'transMode' => $_POST['transMode'] ?? '',
        'transDistance' => (string) ($_POST['transDistance'] ?? '0'),
        'vehicleNo' => $_POST['vehicleNo'] ?? '',
        'vehicleType' => $_POST['vehicleType'] ?? 'R',
        'transporterId' => trim((string) ($_POST['transporterId'] ?? ($_POST['fromGstin'] ?? ''))),
        'transporterName' => $_POST['transporterName'] ?? '',
        'transDocNo' => $_POST['transDocNo'] ?? '',
        'transDocDate' => $_POST['transDocDate'] ?? '',
        'dispatchFromGSTIN' => $_POST['dispatchFromGSTIN'] ?? '',
        'dispatchFromTradeName' => $_POST['dispatchFromTradeName'] ?? '',
        'shipToGSTIN' => $_POST['shipToGSTIN'] ?? '',
        'shipToTradeName' => $_POST['shipToTradeName'] ?? '',
        'itemList' => $item_list,
    ];

    $response = $controller->generateEwayBill($invoice_id, [], $payload);
    if ($response['status'] === 'success') {
        $eway_generate_success = $response;
    } else {
        $error_message = $response['message'] ?? 'Generation failed';
        if (isset($response['api_http_code']))
            $error_message .= ' (HTTP ' . (int) $response['api_http_code'] . ')';
        $api_response = $response;
    }
}

// Load form data (auto-populate). On POST after error, re-use POST so user doesn't lose edits.
$default_transport = [
    'transMode' => '',
    'transDistance' => '0',
    'vehicleNo' => '',
    'transporterId' => '',
    'transporterName' => '',
    'transDocNo' => '',
    'transDocDate' => '',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_eway_full'])
    && (($error_message ?? '') !== '' || $eway_generate_success !== null)) {
    $form = [
        'supplyType' => $_POST['supplyType'] ?? 'O',
        'subSupplyType' => $_POST['subSupplyType'] ?? '1',
        'subSupplyDesc' => $_POST['subSupplyDesc'] ?? '',
        'docType' => $_POST['docType'] ?? 'INV',
        'docNo' => $_POST['docNo'] ?? '',
        'docDate' => $_POST['docDate'] ?? '',
        'fromGstin' => $_POST['fromGstin'] ?? '',
        'fromTrdName' => $_POST['fromTrdName'] ?? '',
        'fromAddr1' => $_POST['fromAddr1'] ?? '',
        'fromAddr2' => $_POST['fromAddr2'] ?? '',
        'fromPlace' => $_POST['fromPlace'] ?? '',
        'fromPincode' => $_POST['fromPincode'] ?? 110001,
        'fromStateCode' => $_POST['fromStateCode'] ?? 7,
        'actFromStateCode' => $_POST['actFromStateCode'] ?? 7,
        'toGstin' => $_POST['toGstin'] ?? 'URP',
        'toTrdName' => $_POST['toTrdName'] ?? '',
        'toAddr1' => $_POST['toAddr1'] ?? '',
        'toAddr2' => $_POST['toAddr2'] ?? '',
        'toPlace' => $_POST['toPlace'] ?? '',
        'toPincode' => $_POST['toPincode'] ?? 0,
        'toStateCode' => $_POST['toStateCode'] ?? 7,
        'actToStateCode' => $_POST['actToStateCode'] ?? 7,
        'transactionType' => $_POST['transactionType'] ?? 1,
        'otherValue' => $_POST['otherValue'] ?? 0,
        'totalValue' => $_POST['totalValue'] ?? 0,
        'cgstValue' => $_POST['cgstValue'] ?? 0,
        'sgstValue' => $_POST['sgstValue'] ?? 0,
        'igstValue' => $_POST['igstValue'] ?? 0,
        'cessValue' => $_POST['cessValue'] ?? 0,
        'cessNonAdvolValue' => $_POST['cessNonAdvolValue'] ?? 0,
        'totInvValue' => $_POST['totInvValue'] ?? 0,
        'transMode' => $_POST['transMode'] ?? '',
        'transDistance' => $_POST['transDistance'] ?? '0',
        'vehicleNo' => $_POST['vehicleNo'] ?? '',
        'vehicleType' => $_POST['vehicleType'] ?? 'R',
        'transporterId' => $_POST['transporterId'] ?? '',
        'transporterName' => $_POST['transporterName'] ?? '',
        'transDocNo' => $_POST['transDocNo'] ?? '',
        'transDocDate' => $_POST['transDocDate'] ?? '',
        'dispatchFromGSTIN' => $_POST['dispatchFromGSTIN'] ?? '',
        'dispatchFromTradeName' => $_POST['dispatchFromTradeName'] ?? '',
        'shipToGSTIN' => $_POST['shipToGSTIN'] ?? '',
        'shipToTradeName' => $_POST['shipToTradeName'] ?? '',
        'itemList' => !empty($_POST['itemList_json']) ? (json_decode($_POST['itemList_json'], true) ?: []) : [],
    ];
} else {
    $form = $controller->prepareEwayBillData($invoice_id, $default_transport);
    if (!$form) {
        $error_message = $error_message ?? 'Could not load invoice data for e-Way Bill.';
        $form = ['itemList' => []];
    }
}

// Load business defaults and merge into $form (invoice data wins; defaults fill missing/static values).
$defaults = $controller->getFormDefaults($business_id, 'eway_bill_form');
$applied_defaults = [];
foreach ($defaults as $key => $val) {
    if (empty($form[$key]) || $form[$key] === '1' || $form[$key] === 'O' || $form[$key] === 'INV') {
        $form[$key] = $val;
        $applied_defaults[$key] = $val;
    }
}

/**
 * NIC sub_supply 3 = Export and 2 = Import. If a saved business default seeds these values
 * for a domestic sale, the gateway returns 207 / 450. Strip them here and warn the user.
 */
$saved_default_warnings = [];
if (isset($applied_defaults['subSupplyType']) && in_array((string) $applied_defaults['subSupplyType'], ['2', '3'], true)) {
    $saved_default_warnings[] = 'A saved Form Default set Sub Supply Type to "'
        . htmlspecialchars((string) $applied_defaults['subSupplyType'])
        . '" (Import/Export). This forces NIC to expect URP/SEZ on To GSTIN and state 96. We have reset Sub Supply Type to "1" (Supply) for this bill. Save defaults again from this page to update.';
    $form['subSupplyType'] = '1';
}

// Master codes for dropdowns (from eway_bills_doc)
$master = [];
$master_file = __DIR__ . '/eway_bills_doc/eway_master_codes.php';
if (is_readable($master_file)) {
    $master = include $master_file;
}
$master = is_array($master) ? $master : [];

// Helper to escape form value
function fv($arr, $key, $default = '')
{
    if (!is_array($arr))
        return $default;
    return isset($arr[$key]) ? htmlspecialchars((string) $arr[$key]) : $default;
}

// Helper: render options from master array (code => description)
function master_options($list, $selected, $empty_label = '')
{
    if (!is_array($list))
        return '';
    $out = $empty_label ? '<option value="">' . htmlspecialchars($empty_label) . '</option>' : '';
    foreach ($list as $code => $label) {
        $s = ($selected !== '' && (string) $selected === (string) $code) ? ' selected' : '';
        $out .= '<option value="' . htmlspecialchars((string) $code) . '"' . $s . '>' . htmlspecialchars($label) . ' (' . htmlspecialchars((string) $code) . ')</option>';
    }
    return $out;
}

/** Render structured Perione API debug steps (from EwayBillController::api_communication_log). */
function render_eway_api_communication_log($log)
{
    if (!is_array($log) || count($log) === 0) {
        return '<p class="text-muted small mb-0">No API steps recorded for this attempt.</p>';
    }
    $out = '';
    $n = 0;
    foreach ($log as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $n++;
        $title = isset($entry['step']) ? (string) $entry['step'] : ('Step ' . $n);
        $json = json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = print_r($entry, true);
        }
        $out .= '<details class="mb-2 border rounded p-2 bg-light eway-api-debug-step"><summary class="small font-weight-bold cursor-pointer">' . htmlspecialchars($title) . '</summary>';
        $out .= '<pre class="small bg-dark text-light p-2 rounded mt-2 mb-0" style="max-height:480px; overflow:auto; white-space:pre-wrap; word-break:break-word;">' . htmlspecialchars($json) . '</pre></details>';
    }
    return $out !== '' ? $out : '<p class="text-muted small mb-0">No API steps recorded.</p>';
}
?>

<body class="vertical light">
    <div class="wrapper">
        <?php include 'admin/navbar.php';
        include 'admin/aside.php'; ?>
        <main role="main" class="main-content">
            <div class="container-fluid">
                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($_GET['success']); ?></div>
                <?php endif; ?>
                <?php if (!empty($saved_default_warnings)): ?>
                    <div class="alert alert-warning">
                        <strong>Form defaults adjusted</strong>
                        <ul class="mb-0">
                            <?php foreach ($saved_default_warnings as $msg): ?>
                                <li class="small"><?php echo $msg; /* already escaped above */ ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if (!empty($eway_generate_success) && is_array($eway_generate_success)): ?>
                    <div class="alert alert-success">
                        <strong>e-Way Bill generated.</strong>
                        Number: <?php echo htmlspecialchars((string) ($eway_generate_success['ewayBillNo'] ?? '')); ?>
                        <?php if (!empty($eway_generate_success['ewayBillDate'])): ?>
                            — Date: <?php echo htmlspecialchars((string) $eway_generate_success['ewayBillDate']); ?>
                        <?php endif; ?>
                        <?php if (!empty($eway_generate_success['validUpto'])): ?>
                            — Valid up to: <?php echo htmlspecialchars((string) $eway_generate_success['validUpto']); ?>
                        <?php endif; ?>
                    </div>
                    <div class="card shadow mb-4 border-success">
                        <div class="card-header bg-success text-white"><strong>Perione API — full log</strong> <span
                                class="small font-weight-normal">(exactly what was sent and received, in order)</span>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted">URLs and headers redact passwords and shorten secrets. <code>inner_eway_json_sent_to_gateway</code>
                                is the NIC-style JSON before base64 in <code>data</code>.</p>
                            <?php echo render_eway_api_communication_log($eway_generate_success['api_communication_log'] ?? []); ?>
                            <a href="eway-bill.php" class="btn btn-primary mt-3">Back to e-Way Bill list</a>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($error_message): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?>
                        <?php if (isset($api_response) && !empty($api_response['api_decoded'])): ?>
                            <details class="mt-2">
                                <summary class="small">Parsed API error / envelope (api_decoded)</summary>
                                <pre
                                    class="small bg-dark text-light p-2 rounded"><?php echo htmlspecialchars(json_encode($api_response['api_decoded'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
                            </details>
                        <?php endif; ?>
                    </div>
                    <?php if (isset($api_response) && !empty($api_response['api_communication_log'])): ?>
                        <div class="card shadow mb-4 border-danger">
                            <div class="card-header"><strong>Perione API — full request / response log</strong> <span
                                    class="small text-muted">(all steps)</span></div>
                            <div class="card-body">
                                <p class="small text-muted mb-2">Each block is one call (generate or authenticate). Compare
                                    <code>request</code> vs <code>response</code> for that step.</p>
                                <?php echo render_eway_api_communication_log($api_response['api_communication_log']); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="card shadow mb-3 border-info" id="ewayGstFetchDebug" style="display:none;">
                    <div class="card-header bg-light"><strong>Last GSTIN lookup (Perione)</strong> <span
                            class="small text-muted">— request / response log</span></div>
                    <div class="card-body py-2">
                        <pre id="ewayGstFetchDebugPre"
                            class="small bg-dark text-light p-2 rounded mb-0" style="max-height:400px; overflow:auto; white-space:pre-wrap;"></pre>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col">
                        <a href="eway-bill.php" class="btn btn-outline-secondary"><i class="fe fe-arrow-left"></i> Back
                            to e-Way Bill</a>
                    </div>
                    <div class="col">
                        <h2 class="h4 mb-0">Generate e-Way Bill — Invoice #<?php echo (int) $inv_row['serial_no']; ?>
                        </h2>
                    </div>
                </div>

                <form method="post" id="ewayFullForm">
                    <input type="hidden" name="invoice_id" value="<?php echo $invoice_id; ?>">
                    <input type="hidden" name="itemList_json" id="itemList_json"
                        value="<?php echo htmlspecialchars(json_encode($form['itemList'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>">

                    <div class="card shadow mb-3">
                        <div class="card-header"><strong>Document</strong> <span class="small text-muted">(Refer Master
                                Codes)</span></div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-2">
                                    <label>Supply Type <span class="text-danger">*</span></label>
                                    <select name="supplyType" class="form-control"
                                        required><?php echo master_options($master['supplyType'] ?? [], fv($form, 'supplyType', 'O')); ?></select>
                                </div>
                                <div class="col-md-2">
                                    <label>Sub Supply Type <span class="text-danger">*</span></label>
                                    <select name="subSupplyType" id="subSupplyType" class="form-control"
                                        required><?php echo master_options($master['subSupplyType'] ?? [], fv($form, 'subSupplyType', '1')); ?></select>
                                    <small class="form-text text-muted">Domestic sale = <strong>1 Supply</strong>. Use 3
                                        only for cross-border export (To GSTIN must be URP/SEZ + State 96 +
                                        Pincode 999999).</small>
                                </div>
                                <div class="col-md-2">
                                    <label>Document Type <span class="text-danger">*</span></label>
                                    <select name="docType" class="form-control"
                                        required><?php echo master_options($master['docType'] ?? [], fv($form, 'docType', 'INV')); ?></select>
                                </div>
                                <div class="col-md-2"><label>Doc No <span class="text-danger">*</span></label><input
                                        type="text" name="docNo" class="form-control"
                                        value="<?php echo fv($form, 'docNo'); ?>" maxlength="16"
                                        placeholder="Alphanumeric, / - ." required></div>
                                <div class="col-md-2"><label>Doc Date (DD/MM/YYYY) <span
                                            class="text-danger">*</span></label>
                                    <small class="form-text text-muted d-block mb-1">Must match the printed tax invoice;
                                        not in the future; on/after 01/07/2017; within the last <strong>180
                                            days</strong> (NIC).</small>
                                    <input type="text" name="docDate" class="form-control"
                                        value="<?php echo fv($form, 'docDate'); ?>" placeholder="03/05/2026" required>
                                </div>
                                <div class="col-md-2"><label>Sub Supply Desc</label><input type="text"
                                        name="subSupplyDesc" class="form-control"
                                        value="<?php echo fv($form, 'subSupplyDesc', ''); ?>" maxlength="20"
                                        placeholder="If Sub Supply = Others"></div>
                            </div>
                        </div>
                    </div>

                    <div class="card shadow mb-3">
                        <div class="card-header"><strong>From (Consignor / Supplier)</strong></div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4"><label>GSTIN <span class="text-danger">*</span></label><input
                                        type="text" name="fromGstin" class="form-control"
                                        value="<?php echo fv($form, 'fromGstin'); ?>" maxlength="15"
                                        placeholder="15 char or URP" required></div>
                                <div class="col-md-4"><label>Trade Name <span class="text-danger">*</span></label><input
                                        type="text" name="fromTrdName" class="form-control"
                                        value="<?php echo fv($form, 'fromTrdName'); ?>" maxlength="100" required></div>
                                <div class="col-md-4"><label>Place <span class="text-danger">*</span></label><input
                                        type="text" name="fromPlace" class="form-control"
                                        value="<?php echo fv($form, 'fromPlace'); ?>" maxlength="50" required></div>
                                <div class="col-md-6"><label>Address Line 1 <span
                                            class="text-danger">*</span></label><input type="text" name="fromAddr1"
                                        class="form-control" value="<?php echo fv($form, 'fromAddr1'); ?>"
                                        maxlength="120" required></div>
                                <div class="col-md-6"><label>Address Line 2</label><input type="text" name="fromAddr2"
                                        class="form-control" value="<?php echo fv($form, 'fromAddr2'); ?>"
                                        maxlength="120"></div>
                                <div class="col-md-2"><label>Pincode (6 digits) <span
                                            class="text-danger">*</span></label><input type="number" name="fromPincode"
                                        class="form-control" value="<?php echo fv($form, 'fromPincode'); ?>"
                                        min="100000" max="999999" required></div>
                                <div class="col-md-5">
                                    <label>State Code <span class="text-danger">*</span></label>
                                    <select name="fromStateCode" class="form-control"
                                        required><?php echo master_options($master['stateCode'] ?? [], fv($form, 'fromStateCode', '7')); ?></select>
                                </div>
                                <div class="col-md-5">
                                    <label>Actual From State Code <span class="text-danger">*</span></label>
                                    <select name="actFromStateCode" class="form-control"
                                        required><?php echo master_options($master['stateCode'] ?? [], fv($form, 'actFromStateCode', '7')); ?></select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card shadow mb-3">
                        <div class="card-header"><strong>To (Consignee / Recipient)</strong></div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <label>GSTIN (or URP) <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="text" name="toGstin" id="toGstin" class="form-control"
                                            value="<?php echo fv($form, 'toGstin'); ?>" maxlength="15" required>
                                        <div class="input-group-append">
                                            <button class="btn btn-outline-primary" type="button" id="fetchGstBtn">Fetch
                                                GST Details</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4"><label>Trade Name <span class="text-danger">*</span></label><input
                                        type="text" name="toTrdName" id="toTrdName" class="form-control"
                                        value="<?php echo fv($form, 'toTrdName'); ?>" maxlength="100" required></div>
                                <div class="col-md-4"><label>Place <span class="text-danger">*</span></label><input
                                        type="text" name="toPlace" id="toPlace" class="form-control"
                                        value="<?php echo fv($form, 'toPlace'); ?>" maxlength="50" required></div>
                                <div class="col-md-6"><label>Address Line 1 <span
                                            class="text-danger">*</span></label><input type="text" name="toAddr1"
                                        id="toAddr1" class="form-control" value="<?php echo fv($form, 'toAddr1'); ?>"
                                        maxlength="120" required></div>
                                <div class="col-md-6"><label>Address Line 2</label><input type="text" name="toAddr2"
                                        id="toAddr2" class="form-control" value="<?php echo fv($form, 'toAddr2'); ?>"
                                        maxlength="120"></div>
                                <div class="col-md-2"><label>Pincode (6 digits) <span
                                            class="text-danger">*</span></label><input type="number" name="toPincode"
                                        id="toPincode" class="form-control"
                                        value="<?php echo fv($form, 'toPincode'); ?>" min="100000" max="999999"
                                        required></div>
                                <div class="col-md-5">
                                    <label>State Code <span class="text-danger">*</span></label>
                                    <select name="toStateCode" id="toStateCode" class="form-control"
                                        required><?php echo master_options($master['stateCode'] ?? [], fv($form, 'toStateCode', '7')); ?></select>
                                </div>
                                <div class="col-md-5">
                                    <label>Actual To State Code <span class="text-danger">*</span></label>
                                    <select name="actToStateCode" id="actToStateCode" class="form-control"
                                        required><?php echo master_options($master['stateCode'] ?? [], fv($form, 'actToStateCode', '7')); ?></select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card shadow mb-3">
                        <div class="card-header"><strong>Values & Tax</strong></div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-2">
                                    <label>Transaction Type <span class="text-danger">*</span></label>
                                    <select name="transactionType" class="form-control"
                                        required><?php echo master_options($master['transactionType'] ?? [], fv($form, 'transactionType', '1')); ?></select>
                                </div>
                                <div class="col-md-2"><label>Total Value <span
                                            class="text-danger">*</span></label><input type="number" step="0.01"
                                        name="totalValue" class="form-control"
                                        value="<?php echo fv($form, 'totalValue'); ?>" required></div>
                                <div class="col-md-2"><label>CGST Value</label><input type="number" step="0.01"
                                        name="cgstValue" class="form-control"
                                        value="<?php echo fv($form, 'cgstValue'); ?>"></div>
                                <div class="col-md-2"><label>SGST Value</label><input type="number" step="0.01"
                                        name="sgstValue" class="form-control"
                                        value="<?php echo fv($form, 'sgstValue'); ?>"></div>
                                <div class="col-md-2"><label>IGST Value</label><input type="number" step="0.01"
                                        name="igstValue" class="form-control"
                                        value="<?php echo fv($form, 'igstValue'); ?>"></div>
                                <div class="col-md-2"><label>Tot Inv Value <span
                                            class="text-danger">*</span></label><input type="number" step="0.01"
                                        name="totInvValue" class="form-control"
                                        value="<?php echo fv($form, 'totInvValue'); ?>" required></div>
                                <div class="col-md-2"><label>Other charges</label><input type="number" step="0.01"
                                        name="otherValue" class="form-control"
                                        value="<?php echo fv($form, 'otherValue', '0'); ?>"></div>
                                <div class="col-md-2"><label>Cess Value</label><input type="number" step="0.01"
                                        name="cessValue" class="form-control"
                                        value="<?php echo fv($form, 'cessValue'); ?>"></div>
                                <div class="col-md-2"><label>Cess Non-Advol</label><input type="number" step="0.01"
                                        name="cessNonAdvolValue" class="form-control"
                                        value="<?php echo fv($form, 'cessNonAdvolValue'); ?>"></div>
                            </div>
                        </div>
                    </div>

                    <div class="card shadow mb-3">
                        <div class="card-header"><strong>Transport</strong> <span class="small text-muted">(Refer Master
                                Codes)</span></div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-2">
                                    <label>Transport Mode</label>
                                    <select name="transMode" class="form-control">
                                        <option value="">— Not selected —</option>
                                        <?php echo master_options($master['transMode'] ?? [], fv($form, 'transMode', '')); ?>
                                    </select>
                                    <small class="form-text text-muted">Optional. NIC error 303: if you pick a mode you
                                        must also enter Vehicle No or Trans Doc No.</small>
                                </div>
                                <div class="col-md-2"><label>Distance (km, max 4000)</label><input type="number"
                                        name="transDistance" class="form-control"
                                        value="<?php echo fv($form, 'transDistance'); ?>" min="0" max="4000"
                                        placeholder="0 = from system"></div>
                                <div class="col-md-2"><label>Vehicle No</label><input type="text" name="vehicleNo"
                                        class="form-control" value="<?php echo fv($form, 'vehicleNo'); ?>"
                                        maxlength="15" placeholder="e.g. KA12AB1234"></div>
                                <div class="col-md-2">
                                    <label>Vehicle Type</label>
                                    <select name="vehicleType"
                                        class="form-control"><?php echo master_options($master['vehicleType'] ?? [], fv($form, 'vehicleType', 'R')); ?></select>
                                </div>
                                <div class="col-md-2">
                                    <label>Transporter ID (GSTIN/TRANSIN) <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="text" name="transporterId" id="transporterId" class="form-control"
                                            value="<?php echo fv($form, 'transporterId', fv($form, 'fromGstin')); ?>"
                                            maxlength="15" required>
                                        <div class="input-group-append">
                                            <button class="btn btn-outline-primary" type="button" id="fetchTransBtn"
                                                title="Fetch trade name from GSTIN / TRANSIN (Perione)"><i
                                                    class="fe fe-search"></i></button>
                                        </div>
                                    </div>
                                    <small class="form-text text-muted">NIC error 619: required. Defaults to your own
                                        GSTIN if you are arranging transport yourself.</small>
                                </div>
                                <div class="col-md-2"><label>Transporter Name</label><input type="text"
                                        name="transporterName" id="transporterName" class="form-control"
                                        value="<?php echo fv($form, 'transporterName'); ?>" maxlength="100"></div>
                                <div class="col-md-2"><label>Trans Doc No</label><input type="text" name="transDocNo"
                                        class="form-control" value="<?php echo fv($form, 'transDocNo'); ?>"
                                        maxlength="15" placeholder="Mandatory if Rail/Air/Ship"></div>
                                <div class="col-md-2"><label>Trans Doc Date (DD/MM/YYYY)</label><input type="text"
                                        name="transDocDate" class="form-control"
                                        value="<?php echo fv($form, 'transDocDate'); ?>" placeholder="02/03/2018"></div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-md-6"><label>Dispatch From GSTIN</label><input type="text"
                                        name="dispatchFromGSTIN" class="form-control"
                                        value="<?php echo fv($form, 'dispatchFromGSTIN'); ?>" maxlength="15"></div>
                                <div class="col-md-6"><label>Dispatch From Trade Name</label><input type="text"
                                        name="dispatchFromTradeName" class="form-control"
                                        value="<?php echo fv($form, 'dispatchFromTradeName'); ?>" maxlength="100"></div>
                                <div class="col-md-6">
                                    <label>Ship To GSTIN</label>
                                    <div class="input-group">
                                        <input type="text" name="shipToGSTIN" id="shipToGSTIN" class="form-control"
                                            value="<?php echo fv($form, 'shipToGSTIN'); ?>" maxlength="15">
                                        <div class="input-group-append">
                                            <button class="btn btn-outline-primary" type="button" id="fetchShipBtn"
                                                title="Fetch ship-to trade name from GSTIN (Perione)"><i
                                                    class="fe fe-search"></i></button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6"><label>Ship To Trade Name</label><input type="text"
                                        name="shipToTradeName" id="shipToTradeName" class="form-control"
                                        value="<?php echo fv($form, 'shipToTradeName'); ?>" maxlength="100"></div>
                            </div>
                        </div>
                    </div>

                    <div class="card shadow mb-3">
                        <div class="card-header"><strong>Item List</strong> <span class="small text-muted">(from
                                invoice; Unit = Master UQC)</span></div>
                        <div class="card-body table-responsive">
                            <table class="table table-bordered table-sm" id="ewayItemTable">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>HSN</th>
                                        <th>Qty</th>
                                        <th>Unit (UQC)</th>
                                        <th>Taxable Amt</th>
                                        <th>SGST %</th>
                                        <th>CGST %</th>
                                        <th>IGST %</th>
                                        <th>Cess %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (($form['itemList'] ?? []) as $idx => $it):
                                        $cu = $it['qtyUnit'] ?? 'NOS';
                                        ?>
                                        <tr data-item-index="<?php echo (int) $idx; ?>">
                                            <td><?php echo htmlspecialchars($it['productName'] ?? ''); ?></td>
                                            <td><?php echo (int) ($it['hsnCode'] ?? 0); ?></td>
                                            <td><?php echo (float) ($it['quantity'] ?? 0); ?></td>
                                            <td>
                                                <select class="form-control form-control-sm item-qty-unit"
                                                    data-index="<?php echo (int) $idx; ?>" aria-label="Unit">
                                                    <?php
                                                    $units = $master['qtyUnit'] ?? ['NOS' => 'NUMBERS'];
                                                    foreach ($units as $code => $label) {
                                                        $sel = ((string) $cu === (string) $code) ? ' selected' : '';
                                                        echo '<option value="' . htmlspecialchars($code) . '"' . $sel . '>' . htmlspecialchars($code) . ' - ' . htmlspecialchars($label) . '</option>';
                                                    }
                                                    ?>
                                                </select>
                                            </td>
                                            <td><?php echo number_format((float) ($it['taxableAmount'] ?? 0), 2); ?></td>
                                            <td><?php echo (float) ($it['sgstRate'] ?? 0); ?></td>
                                            <td><?php echo (float) ($it['cgstRate'] ?? 0); ?></td>
                                            <td><?php echo (float) ($it['igstRate'] ?? 0); ?></td>
                                            <td><?php echo (float) ($it['cessRate'] ?? 0); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="form-group">
                        <button type="submit" name="generate_eway_full" class="btn btn-primary btn-lg">Generate e-Way
                            Bill</button>
                        <a href="eway-bill.php" class="btn btn-secondary btn-lg">Cancel</a>
                    </div>
                </form>
            </div>
        </main>
        <?php include 'admin/footer.php'; ?>
    </div>
    <script>
        (function () {
            var el = document.getElementById('itemList_json');
            var table = document.getElementById('ewayItemTable');
            function syncItemListJson() {
                if (!el || !table) return;
                try {
                    var list = JSON.parse(el.value || '[]');
                    var rows = table.querySelectorAll('tbody tr[data-item-index]');
                    rows.forEach(function (row) {
                        var idx = parseInt(row.getAttribute('data-item-index'), 10);
                        var sel = row.querySelector('.item-qty-unit');
                        if (sel && list[idx]) list[idx].qtyUnit = sel.value;
                    });
                    el.value = JSON.stringify(list);
                } catch (e) { }
            }
            if (table) {
                table.addEventListener('change', function (ev) {
                    if (ev.target.classList.contains('item-qty-unit')) syncItemListJson();
                });
            }

            /** Mirror server-side validateNicPayloadBusinessRules so the user sees errors before POST. */
            var ewayForm = document.getElementById('ewayFullForm');
            if (ewayForm) {
                ewayForm.addEventListener('submit', function (ev) {
                    function val(name) {
                        var n = ewayForm.querySelector('[name="' + name + '"]');
                        return n ? String(n.value || '').trim() : '';
                    }
                    var supplyType = val('supplyType').toUpperCase();
                    var subSupplyType = val('subSupplyType');
                    var toGstin = val('toGstin').toUpperCase().replace(/\s+/g, '');
                    var transporterId = val('transporterId').toUpperCase().replace(/\s+/g, '');
                    var transMode = val('transMode');
                    var vehicleNo = val('vehicleNo');
                    var transDocNo = val('transDocNo');
                    var msg = '';

                    if (transporterId === '') {
                        msg = 'Transporter ID (GSTIN/TRANSIN) is required by NIC (error 619). ' +
                            'Enter the transporter\'s GSTIN, or leave default to use your own GSTIN.';
                    }
                    if (!msg && transMode !== '' && vehicleNo === '' && transDocNo === '') {
                        msg = 'NIC error 303: when Transport Mode is selected, you must also fill Vehicle No or Trans Doc No. ' +
                            'Either fill those, or clear Transport Mode.';
                    }
                    if (!msg && supplyType === 'O' && subSupplyType === '1' && toGstin && toGstin !== 'URP'
                        && (toGstin.length !== 15 || !/^[0-9A-Z]{15}$/.test(toGstin))) {
                        msg = 'To GSTIN "' + toGstin + '" is not a valid 15-character GSTIN. Use URP for unregistered party.';
                    }

                    if (msg) {
                        ev.preventDefault();
                        alert(msg);
                    }
                });
            }

            function showGstApiCommunicationLog(res) {
                var box = document.getElementById('ewayGstFetchDebug');
                var pre = document.getElementById('ewayGstFetchDebugPre');
                if (!box || !pre) return;
                if (res.api_communication_log && res.api_communication_log.length) {
                    box.style.display = 'block';
                    pre.textContent = JSON.stringify(res.api_communication_log, null, 2);
                    try { box.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); } catch (e) { }
                }
            }

            /** Map Perione getgstindetails row to consignee address fields (handles pin merged into address2). */
            function gstDetailsToConsigneeFields(d) {
                var pin = ((d.pinCode || '') + '').replace(/\D/g, '').substring(0, 6);
                var a1 = (d.address1 || '').trim();
                var a2 = (d.address2 || '').trim();
                if (pin && a2.indexOf(pin) === 0) {
                    a2 = a2.substring(pin.length).trim();
                }
                var place = '';
                if (a2.length > 0 && a2.length <= 50) {
                    place = a2;
                } else {
                    var firstSeg = (a1.split(',')[0] || a1).trim();
                    place = firstSeg.substring(0, 50);
                }
                return {
                    toGstin: (d.gstin || '').trim().toUpperCase(),
                    toTrdName: (d.tradeName || d.legalName || '').trim().substring(0, 100),
                    toAddr1: a1.substring(0, 120),
                    toAddr2: a2.substring(0, 120),
                    toPlace: place.substring(0, 50),
                    toPincode: pin ? parseInt(pin, 10) : '',
                    stateCode: (d.stateCode != null && d.stateCode !== '') ? String(d.stateCode).trim() : ''
                };
            }

            function applyConsigneeFromGst(d) {
                var m = gstDetailsToConsigneeFields(d);
                if (document.getElementById('toGstin') && m.toGstin) document.getElementById('toGstin').value = m.toGstin;
                if (document.getElementById('toTrdName')) document.getElementById('toTrdName').value = m.toTrdName;
                if (document.getElementById('toAddr1')) document.getElementById('toAddr1').value = m.toAddr1;
                if (document.getElementById('toAddr2')) document.getElementById('toAddr2').value = m.toAddr2;
                if (document.getElementById('toPlace')) document.getElementById('toPlace').value = m.toPlace;
                if (document.getElementById('toPincode') && m.toPincode !== '') document.getElementById('toPincode').value = m.toPincode;
                if (m.stateCode) {
                    var sc = document.getElementById('toStateCode');
                    var ac = document.getElementById('actToStateCode');
                    if (sc) sc.value = m.stateCode;
                    if (ac) ac.value = m.stateCode;
                }
            }

            // Fetch GST Details Logic (consignee)
            var fetchBtn = document.getElementById('fetchGstBtn');
            if (fetchBtn) {
                fetchBtn.addEventListener('click', function () {
                    var gstinInput = document.getElementById('toGstin');
                    var gstin = gstinInput ? gstinInput.value.trim() : '';
                    if (!gstin) {
                        alert('Please enter a GSTIN first.');
                        return;
                    }

                    fetchBtn.disabled = true;
                    fetchBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Fetching...';

                    fetch('ajax_fetch_gst.php?gstin=' + encodeURIComponent(gstin))
                        .then(response => response.json())
                        .then(res => {
                            fetchBtn.disabled = false;
                            fetchBtn.innerHTML = 'Fetch GST Details';
                            showGstApiCommunicationLog(res);

                            if (res.status === 'success' && res.data) {
                                applyConsigneeFromGst(res.data);
                                alert('Consignee details fetched from GSTIN registry.');
                            } else {
                                alert('Error: ' + (res.message || 'Could not fetch GST details.'));
                            }
                        })
                        .catch(err => {
                            fetchBtn.disabled = false;
                            fetchBtn.innerHTML = 'Fetch GST Details';
                            alert('An error occurred during fetch. Check console.');
                            console.error(err);
                        });
                });
            }

            // Fetch Transporter Details Logic
            var fetchTransBtn = document.getElementById('fetchTransBtn');
            if (fetchTransBtn) {
                fetchTransBtn.addEventListener('click', function () {
                    var gstinInput = document.getElementById('transporterId');
                    var gstin = gstinInput ? gstinInput.value.trim() : '';
                    if (!gstin) {
                        alert('Please enter a Transporter ID (GSTIN/TRANSIN) first.');
                        return;
                    }

                    fetchTransBtn.disabled = true;
                    fetchTransBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

                    fetch('ajax_fetch_gst.php?gstin=' + encodeURIComponent(gstin))
                        .then(response => response.json())
                        .then(res => {
                            fetchTransBtn.disabled = false;
                            fetchTransBtn.innerHTML = '<i class="fe fe-search"></i>';
                            showGstApiCommunicationLog(res);

                            if (res.status === 'success' && res.data) {
                                var d = res.data;
                                var tid = document.getElementById('transporterId');
                                if (tid && d.gstin) tid.value = String(d.gstin).trim().toUpperCase();
                                if (document.getElementById('transporterName')) {
                                    document.getElementById('transporterName').value = (d.tradeName || d.legalName || '').trim().substring(0, 100);
                                }
                                alert('Transporter details fetched from GSTIN / TRANSIN registry.');
                            } else {
                                alert('Error: ' + (res.message || 'Could not fetch Transporter details.'));
                            }
                        })
                        .catch(err => {
                            fetchTransBtn.disabled = false;
                            fetchTransBtn.innerHTML = '<i class="fe fe-search"></i>';
                            alert('An error occurred during fetch.');
                            console.error(err);
                        });
                });
            }

            // Fetch Ship-To Details Logic
            var fetchShipBtn = document.getElementById('fetchShipBtn');
            if (fetchShipBtn) {
                fetchShipBtn.addEventListener('click', function () {
                    var gstinInput = document.getElementById('shipToGSTIN');
                    var gstin = gstinInput ? gstinInput.value.trim() : '';
                    if (!gstin) {
                        alert('Please enter a Ship-To GSTIN first.');
                        return;
                    }

                    fetchShipBtn.disabled = true;
                    fetchShipBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

                    fetch('ajax_fetch_gst.php?gstin=' + encodeURIComponent(gstin))
                        .then(response => response.json())
                        .then(res => {
                            fetchShipBtn.disabled = false;
                            fetchShipBtn.innerHTML = '<i class="fe fe-search"></i>';
                            showGstApiCommunicationLog(res);

                            if (res.status === 'success' && res.data) {
                                var d = res.data;
                                var sg = document.getElementById('shipToGSTIN');
                                if (sg && d.gstin) sg.value = String(d.gstin).trim().toUpperCase();
                                if (document.getElementById('shipToTradeName')) {
                                    document.getElementById('shipToTradeName').value = (d.tradeName || d.legalName || '').trim().substring(0, 100);
                                }
                                alert('Ship-to party details fetched from GSTIN registry.');
                            } else {
                                alert('Error: ' + (res.message || 'Could not fetch Ship-To details.'));
                            }
                        })
                        .catch(err => {
                            fetchShipBtn.disabled = false;
                            fetchShipBtn.innerHTML = '<i class="fe fe-search"></i>';
                            alert('An error occurred during fetch.');
                            console.error(err);
                        });
                });
            }
        })();
    </script>
</body>

</html>
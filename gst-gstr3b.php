<?php
/**
 * GSTR-3B computation, offset, and filing (admin). Parity with GstComputationActivity
 * plus 3.1 / 4 / 6.1 tables, editable offset, cash vs ITC explanation, 6-month compare.
 */
require_once __DIR__ . '/includes/gst-init.php';

if ($gst_business_id <= 0) {
    header('Location: index.php');
    exit;
}

$gstCtx = [
    'business_id' => $gst_business_id,
    'period' => $gst_period,
    'location_id' => $gst_location_id ?: null,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gst_ajax']) && $gst && $gst_db_ok) {
    $action = (string) $_POST['gst_ajax'];
    if ($action === 'auth_otp') {
        gst_json_exit($gst->authOtp($gstCtx));
    }
    if ($action === 'auth_verify') {
        $gstCtx['otp'] = trim((string) ($_POST['otp'] ?? ''));
        gst_json_exit($gst->authVerify($gstCtx));
    }
    if ($action === 'offset') {
        $gstCtx['use_itc'] = !empty($_POST['use_itc']);
        $gstCtx['local_only'] = !empty($_POST['local_only']);
        $gstCtx['net_tax_pay'] = $_POST['net_tax_pay'] ?? null;
        $gstCtx['pditc'] = $_POST['pditc'] ?? null;
        $gstCtx['pdcash'] = $_POST['pdcash'] ?? null;
        $gstCtx['eco_dtls'] = $_POST['eco_dtls'] ?? null;
        gst_json_exit($gst->offsetLiability($gstCtx));
    }
    if ($action === 'file_3b') {
        $gstCtx['otp'] = trim((string) ($_POST['otp'] ?? ''));
        gst_json_exit($gst->fileGstr3b($gstCtx));
    }
    gst_json_exit(['status' => 'error', 'message' => 'Unknown action.']);
}

function gst3b_money_input($name, $value, $formId = '')
{
    $v = number_format((float) $value, 2, '.', '');
    $form = $formId !== '' ? ' form="' . gst_h($formId) . '"' : '';
    return '<input type="number" step="0.01" min="0" class="form-control form-control-sm gst-head-input" name="'
        . gst_h($name) . '" value="' . gst_h($v) . '"' . $form . '>';
}

function gst3b_tax_table(array $rows, $withTxval = true)
{
    echo '<div class="table-responsive"><table class="table table-sm table-bordered mb-0">';
    echo '<thead class="thead-light"><tr><th>Code</th><th>Nature of supplies</th>';
    if ($withTxval) {
        echo '<th class="text-right">Taxable</th>';
    }
    echo '<th class="text-right">IGST</th><th class="text-right">CGST</th><th class="text-right">SGST</th><th class="text-right">Cess</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        echo '<td class="text-nowrap">' . gst_h($r['code'] ?? '') . '</td>';
        echo '<td>' . gst_h($r['nature'] ?? '');
        if (!empty($r['note'])) {
            echo '<div class="small text-muted">' . gst_h($r['note']) . '</div>';
        }
        echo '</td>';
        if ($withTxval) {
            echo '<td class="text-right">' . gst_h(gst_inr($r['txval'] ?? 0)) . '</td>';
        }
        echo '<td class="text-right">' . gst_h(gst_inr($r['iamt'] ?? 0)) . '</td>';
        echo '<td class="text-right">' . gst_h(gst_inr($r['camt'] ?? 0)) . '</td>';
        echo '<td class="text-right">' . gst_h(gst_inr($r['samt'] ?? 0)) . '</td>';
        echo '<td class="text-right">' . gst_h(gst_inr($r['csamt'] ?? 0)) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

$data = [];
$loadErr = '';
$compareRows = [];
$summary = [];
if ($gst && $gst_db_ok) {
    $res = $gst->getGstr3b($gstCtx);
    if (($res['status'] ?? '') === 'success' && is_array($res['data'] ?? null)) {
        $data = $res['data'];
    } else {
        $loadErr = (string) ($res['message'] ?? 'Could not compute GSTR-3B.');
    }
    $cmp = $gst->comparePeriods(array_merge($gstCtx, ['months' => 6]));
    if (($cmp['status'] ?? '') === 'success') {
        $compareRows = $cmp['data']['rows'] ?? [];
    }
    $sumRes = $gst->getPeriodSummary($gstCtx);
    if (($sumRes['status'] ?? '') === 'success') {
        $summary = $sumRes['data'] ?? [];
    }
} else {
    $loadErr = 'Database connection failed.';
}

$credRaw = ($gst && $gst_db_ok) ? $gst->getCredentials($gst_business_id) : null;
$portal = gst_portal_state(is_array($credRaw) ? $credRaw : []);
$preview = is_array($data['offset_preview'] ?? null) ? $data['offset_preview'] : [];
$netPay = $preview['Nettaxpay'] ?? ['iamt' => 0, 'camt' => 0, 'samt' => 0, 'csamt' => 0];
$pditc = $preview['pditc'] ?? ['iamt' => 0, 'camt' => 0, 'samt' => 0, 'csamt' => 0];
$pdcash = $preview['pdcash'] ?? ['iamt' => 0, 'camt' => 0, 'samt' => 0, 'csamt' => 0];
$eco = $preview['ecodtls'] ?? ($data['eco_dtls'] ?? ['txval' => 0, 'iamt' => 0, 'camt' => 0, 'samt' => 0, 'csamt' => 0]);

$outward = (float) ($data['gross_output'] ?? $data['output_gst'] ?? 0);
$itcElig = (float) ($data['itc_eligible'] ?? 0);
$itcPend = (float) ($data['itc_pending'] ?? 0);
$itcCarry = (float) ($data['itc_carry'] ?? 0);
$netTot = (float) ($data['net_total'] ?? 0);
$cashPay = (float) ($data['cash_to_pay'] ?? max(0, $netTot));
$status = (string) ($data['status'] ?? 'pending');
$filingUnlocked = !empty($summary['filing_unlocked']);
$currentPeriod = date('Y-m');
$lastPeriod = gst_shift_period($currentPeriod, -1);

$shareText = 'GSTR-3B ' . gst_period_label($gst_period) . "\n"
    . 'Gross Output Liability: ' . gst_inr($outward) . "\n"
    . 'Less: ITC Utilized: ' . gst_inr($itcElig) . "\n"
    . 'Pending handshake: ' . gst_inr($itcPend) . "\n"
    . 'Carry-forward: ' . gst_inr($itcCarry) . "\n"
    . ($netTot >= 0 ? 'Net Cash To Pay: ' : 'Net refundable / credit: ') . gst_inr(abs($netTot));

$gst_nav = 'gstr3b';
include __DIR__ . '/admin/header.php';
?>
<body class="vertical light">
    <div class="wrapper">
        <?php
        include __DIR__ . '/admin/navbar.php';
        include __DIR__ . '/admin/aside.php';
        ?>
        <main role="main" class="main-content">
            <div class="container-fluid pb-5">
                <div class="row justify-content-center">
                    <div class="col-12">
                        <div class="row align-items-center mb-3">
                            <div class="col">
                                <h2 class="h3 page-title mb-0">
                                    GSTR-3B Summary &amp; Payment
                                    <?php echo gst_status_badge($status); ?>
                                </h2>
                                <p class="small text-muted mb-0">
                                    Period <strong><?php echo gst_h(gst_period_label($gst_period)); ?></strong>
                                    (<?php echo gst_h($gst_period); ?>)
                                    · <a href="<?php echo gst_h(gst_url('gst-filing.php')); ?>">Back to Filing Center</a>
                                </p>
                            </div>
                            <div class="col-auto">
                                <a class="btn btn-outline-secondary btn-sm" href="<?php echo gst_h(gst_url('gst-gstr3b-excel.php')); ?>">
                                    <i class="fe fe-download"></i> Excel export
                                </a>
                                <button type="button" class="btn btn-outline-primary btn-sm" id="gstShareBtn">
                                    <i class="fe fe-share-2"></i> Share summary
                                </button>
                            </div>
                        </div>

                        <?php include __DIR__ . '/includes/gst-subnav.php'; ?>

                        <?php if ($loadErr !== ''): ?>
                            <div class="alert alert-danger"><?php echo gst_h($loadErr); ?></div>
                        <?php endif; ?>
                        <div id="gstFlash" class="d-none"></div>

                        <?php if (!$portal['connected']): ?>
                            <div class="alert alert-warning">
                                GST portal not connected.
                                <a class="alert-link" href="<?php echo gst_h(gst_url('gst-credentials.php')); ?>">Add GSTIN &amp; username</a>
                                then request OTP. Local computation still works.
                            </div>
                        <?php elseif ($portal['needs_otp']): ?>
                            <div class="alert alert-info">
                                GST session needs OTP for offset / file.
                                <button type="button" class="btn btn-sm btn-primary ml-2" id="gstAuthOtpBtn">Request OTP</button>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success">
                                GST portal connected<?php echo !empty($portal['token_expiry']) ? ' until ' . gst_h(date('j M Y, g:i A', strtotime($portal['token_expiry']))) : ''; ?>.
                            </div>
                        <?php endif; ?>

                        <div class="card shadow mb-4">
                            <div class="card-body">
                                <div class="row align-items-end">
                                    <div class="col-md-4 mb-2">
                                        <label class="small text-muted d-block">Return period</label>
                                        <div class="btn-group w-100">
                                            <a class="btn btn-outline-secondary" href="<?php echo gst_h(gst_url('gst-gstr3b.php', ['period' => gst_shift_period($gst_period, -1)])); ?>">&lsaquo;</a>
                                            <span class="btn btn-light disabled flex-fill"><?php echo gst_h(gst_period_label($gst_period)); ?></span>
                                            <a class="btn btn-outline-secondary" href="<?php echo gst_h(gst_url('gst-gstr3b.php', ['period' => gst_shift_period($gst_period, 1)])); ?>">&rsaquo;</a>
                                        </div>
                                    </div>
                                    <div class="col-md-5 mb-2">
                                        <a class="btn btn-sm <?php echo $gst_period === $currentPeriod ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo gst_h(gst_url('gst-gstr3b.php', ['period' => $currentPeriod])); ?>">This month</a>
                                        <a class="btn btn-sm <?php echo $gst_period === $lastPeriod ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo gst_h(gst_url('gst-gstr3b.php', ['period' => $lastPeriod])); ?>">Last month</a>
                                    </div>
                                    <?php if (count($gst_locations) > 0): ?>
                                        <div class="col-md-3 mb-2">
                                            <form method="GET" action="gst-gstr3b.php">
                                                <input type="hidden" name="period" value="<?php echo gst_h($gst_period); ?>">
                                                <label class="small text-muted d-block">Location</label>
                                                <select name="location_id" class="form-control" onchange="this.form.submit()">
                                                    <option value="">All locations</option>
                                                    <?php foreach ($gst_locations as $loc): ?>
                                                        <option value="<?php echo (int) $loc['id']; ?>" <?php echo $gst_location_id === (int) $loc['id'] ? 'selected' : ''; ?>>
                                                            <?php echo gst_h($loc['location_name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="small text-muted mt-1">
                                    <?php echo gst_h($data['invoice_count'] ?? 0); ?> sales invoices
                                    · Offset ref <?php echo gst_h($data['offset_ref_id'] ?: '—'); ?>
                                    · File ref <?php echo gst_h($data['file_ref_id'] ?: '—'); ?>
                                </div>
                            </div>
                        </div>

                        <div class="card shadow mb-4">
                            <div class="card-header">
                                <strong class="card-title mb-0">STEP 3: Liability breakdown</strong>
                            </div>
                            <div class="card-body">
                                <p class="mb-1">Gross Output Liability: <strong><?php echo gst_h(gst_inr($outward)); ?></strong></p>
                                <p class="mb-1">Less: ITC Utilized: <strong><?php echo gst_h(gst_inr($itcElig)); ?></strong></p>
                                <p class="mb-1 text-muted">Pending handshake: <?php echo gst_h(gst_inr($itcPend)); ?></p>
                                <p class="mb-2 text-muted">Carry-forward: <?php echo gst_h(gst_inr($itcCarry)); ?></p>
                                <hr>
                                <h5 class="mb-0">
                                    <?php echo $netTot >= 0 ? 'Net Cash To Pay: ' : 'Net refundable / credit: '; ?>
                                    <?php echo gst_h(gst_inr(abs($netTot))); ?>
                                </h5>
                                <p class="small text-muted mt-3 mb-0">
                                    Eligible ITC only includes purchases marked Approved (vendor handshake). Pending amounts stay in carry-forward until vendors file.
                                </p>
                            </div>
                        </div>

                        <div class="card shadow mb-4">
                            <div class="card-header">
                                <strong class="card-title mb-0">Table 3.1 — Outward supplies</strong>
                            </div>
                            <div class="card-body p-0">
                                <?php gst3b_tax_table($data['table_3_1'] ?? []); ?>
                            </div>
                            <div class="card-body border-top">
                                <p class="small text-muted mb-2">Break-up from books (B2B / B2CS / B2CL). Zero-rated, RCM, and non-GST rows are placeholders when not tracked.</p>
                                <?php gst3b_tax_table($data['table_3_1_by_supply'] ?? []); ?>
                            </div>
                        </div>

                        <div class="card shadow mb-4">
                            <div class="card-header">
                                <strong class="card-title mb-0">eco_dtls — e-commerce operator supplies</strong>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info mb-3">
                                    <?php echo gst_h($data['eco_dtls']['note'] ?? 'Perione requires eco_dtls on every GSTR-3B offset, even when all values are zero.'); ?>
                                </div>
                                <div class="form-row">
                                    <div class="form-group col-md-2">
                                        <label class="small">Taxable</label>
                                        <?php echo gst3b_money_input('eco_dtls[txval]', $eco['txval'] ?? 0, 'gstOffsetForm'); ?>
                                    </div>
                                    <div class="form-group col-md-2">
                                        <label class="small">IGST</label>
                                        <?php echo gst3b_money_input('eco_dtls[iamt]', $eco['iamt'] ?? 0, 'gstOffsetForm'); ?>
                                    </div>
                                    <div class="form-group col-md-2">
                                        <label class="small">CGST</label>
                                        <?php echo gst3b_money_input('eco_dtls[camt]', $eco['camt'] ?? 0, 'gstOffsetForm'); ?>
                                    </div>
                                    <div class="form-group col-md-2">
                                        <label class="small">SGST</label>
                                        <?php echo gst3b_money_input('eco_dtls[samt]', $eco['samt'] ?? 0, 'gstOffsetForm'); ?>
                                    </div>
                                    <div class="form-group col-md-2">
                                        <label class="small">Cess</label>
                                        <?php echo gst3b_money_input('eco_dtls[csamt]', $eco['csamt'] ?? 0, 'gstOffsetForm'); ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card shadow mb-4">
                            <div class="card-header">
                                <strong class="card-title mb-0">Table 4 — Eligible ITC</strong>
                            </div>
                            <div class="card-body p-0">
                                <?php gst3b_tax_table($data['table_4'] ?? []); ?>
                            </div>
                        </div>

                        <div class="card shadow mb-4">
                            <div class="card-header">
                                <strong class="card-title mb-0">Table 6.1 — Payment of tax</strong>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Description</th>
                                                <th class="text-right">Tax payable</th>
                                                <th class="text-right">Paid through ITC</th>
                                                <th class="text-right">Paid in cash</th>
                                                <th class="text-right">Interest</th>
                                                <th class="text-right">Late fee</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (($data['table_6_1'] ?? []) as $r): ?>
                                                <tr>
                                                    <td><?php echo gst_h($r['head'] ?? ''); ?></td>
                                                    <td class="text-right"><?php echo gst_h(gst_inr($r['tax_payable'] ?? 0)); ?></td>
                                                    <td class="text-right"><?php echo gst_h(gst_inr($r['paid_itc'] ?? 0)); ?></td>
                                                    <td class="text-right"><?php echo gst_h(gst_inr($r['paid_cash'] ?? 0)); ?></td>
                                                    <td class="text-right"><?php echo gst_h(gst_inr($r['interest'] ?? 0)); ?></td>
                                                    <td class="text-right"><?php echo gst_h(gst_inr($r['late_fee'] ?? 0)); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="card-body border-top small text-muted">
                                <?php echo gst_h($data['charges_note'] ?? 'Interest and late fee are placeholders until the portal returns them.'); ?>
                                Source: <?php echo gst_h($data['charges_source'] ?? 'placeholder'); ?>.
                            </div>
                        </div>

                        <div class="card shadow mb-4">
                            <div class="card-header">
                                <strong class="card-title mb-0">Cash ledger vs ITC ledger</strong>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>ITC ledger (pditc)</h6>
                                        <p class="small text-muted mb-0">
                                            Credit already on your electronic credit ledger from approved inward supplies.
                                            Using ITC here reduces cash outflow. Heads: IGST, CGST, SGST, Cess.
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Cash ledger (pdcash)</h6>
                                        <p class="small text-muted mb-0">
                                            Amount paid via GST challan (PMT-06) into the electronic cash ledger, then offset against remaining liability.
                                            Interest and late fee can only be paid in cash — not from ITC.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <form id="gstOffsetForm" class="card shadow mb-4">
                            <div class="card-header">
                                <strong class="card-title mb-0">Offset liability</strong>
                                <span class="small text-muted ml-2">net_tax_pay · pditc · pdcash — posted to offset-liability.php</span>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered">
                                        <thead class="thead-light">
                                            <tr>
                                                <th></th>
                                                <th class="text-right">IGST</th>
                                                <th class="text-right">CGST</th>
                                                <th class="text-right">SGST</th>
                                                <th class="text-right">Cess</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>Net tax payable (Nettaxpay)</td>
                                                <td><?php echo gst3b_money_input('net_tax_pay[iamt]', $netPay['iamt'] ?? 0); ?></td>
                                                <td><?php echo gst3b_money_input('net_tax_pay[camt]', $netPay['camt'] ?? 0); ?></td>
                                                <td><?php echo gst3b_money_input('net_tax_pay[samt]', $netPay['samt'] ?? 0); ?></td>
                                                <td><?php echo gst3b_money_input('net_tax_pay[csamt]', $netPay['csamt'] ?? 0); ?></td>
                                            </tr>
                                            <tr>
                                                <td>Paid through ITC (pditc)</td>
                                                <td><?php echo gst3b_money_input('pditc[iamt]', $pditc['iamt'] ?? 0); ?></td>
                                                <td><?php echo gst3b_money_input('pditc[camt]', $pditc['camt'] ?? 0); ?></td>
                                                <td><?php echo gst3b_money_input('pditc[samt]', $pditc['samt'] ?? 0); ?></td>
                                                <td><?php echo gst3b_money_input('pditc[csamt]', $pditc['csamt'] ?? 0); ?></td>
                                            </tr>
                                            <tr>
                                                <td>Paid in cash (pdcash)</td>
                                                <td><?php echo gst3b_money_input('pdcash[iamt]', $pdcash['iamt'] ?? 0); ?></td>
                                                <td><?php echo gst3b_money_input('pdcash[camt]', $pdcash['camt'] ?? 0); ?></td>
                                                <td><?php echo gst3b_money_input('pdcash[samt]', $pdcash['samt'] ?? 0); ?></td>
                                                <td><?php echo gst3b_money_input('pdcash[csamt]', $pdcash['csamt'] ?? 0); ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" id="use_itc" value="1" checked>
                                    <label class="form-check-label" for="use_itc">Use ITC (pditc)</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" id="local_only" value="1">
                                    <label class="form-check-label" for="local_only">Local preview only (skip portal)</label>
                                </div>
                                <p class="small text-muted mt-2 mb-0">
                                    Cash cells auto-fill as tax payable minus ITC. Edit any head, then Offset or Pay &amp; File.
                                </p>
                            </div>
                        </form>

                        <div class="mb-4">
                            <?php if (!$filingUnlocked): ?>
                                <div class="alert alert-warning">Step 3 is locked on the hub until sales are synced and pending ITC is cleared. You can still preview and offset locally.</div>
                            <?php endif; ?>
                            <button type="button" class="btn btn-primary btn-lg mr-2 mb-2" id="gstFileBtn">File GSTR-3B</button>
                            <button type="button" class="btn btn-success btn-lg mr-2 mb-2" id="gstPayFileBtn">Pay &amp; File GSTR-3B</button>
                            <button type="button" class="btn btn-outline-secondary mb-2" id="gstOffsetBtn">Offset liability only</button>
                        </div>

                        <div class="card shadow mb-4">
                            <div class="card-header">
                                <strong class="card-title mb-0">Last 6 months — output vs ITC vs net</strong>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Period</th>
                                                <th class="text-right">Invoices</th>
                                                <th class="text-right">Output GST</th>
                                                <th class="text-right">ITC utilized</th>
                                                <th class="text-right">Pending ITC</th>
                                                <th class="text-right">Net</th>
                                                <th class="text-right">Cash to pay</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!$compareRows): ?>
                                                <tr><td colspan="8" class="text-muted">No comparison data.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($compareRows as $row): ?>
                                                    <tr class="<?php echo ($row['period'] ?? '') === $gst_period ? 'table-primary' : ''; ?>">
                                                        <td>
                                                            <a href="<?php echo gst_h(gst_url('gst-gstr3b.php', ['period' => $row['period']])); ?>">
                                                                <?php echo gst_h($row['period_label'] ?? $row['period']); ?>
                                                            </a>
                                                        </td>
                                                        <td class="text-right"><?php echo (int) ($row['invoice_count'] ?? 0); ?></td>
                                                        <td class="text-right"><?php echo gst_h(gst_inr($row['output_gst'] ?? 0)); ?></td>
                                                        <td class="text-right"><?php echo gst_h(gst_inr($row['itc_eligible'] ?? 0)); ?></td>
                                                        <td class="text-right"><?php echo gst_h(gst_inr($row['itc_pending'] ?? 0)); ?></td>
                                                        <td class="text-right"><?php echo gst_h(gst_inr($row['net_total'] ?? 0)); ?></td>
                                                        <td class="text-right"><?php echo gst_h(gst_inr($row['cash_to_pay'] ?? 0)); ?></td>
                                                        <td><?php echo gst_status_badge($row['status'] ?? ''); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="gst-sticky-net border-top bg-white px-4 py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-muted">Net cash to pay</span>
                    <strong class="h5 mb-0" id="gstStickyNet"><?php echo gst_h(gst_inr(max(0, $cashPay))); ?></strong>
                </div>
            </div>

            <div class="modal fade" id="gstOtpModal" tabindex="-1" role="dialog">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="gstOtpTitle">GST OTP</h5>
                            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                        </div>
                        <div class="modal-body">
                            <p id="gstOtpHint">Enter the OTP sent to the GST-registered mobile / email.</p>
                            <input type="text" class="form-control" id="gstOtpInput" maxlength="10" inputmode="numeric" placeholder="6-digit OTP" autocomplete="one-time-code">
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" id="gstOtpSubmit">Submit</button>
                        </div>
                    </div>
                </div>
            </div>

            <style>
                .gst-sticky-net { position: sticky; bottom: 0; z-index: 20; box-shadow: 0 -2px 8px rgba(0,0,0,.06); }
                .nav-pills .nav-link { font-size: 13px; }
            </style>
            <?php include __DIR__ . '/admin/footer.php'; ?>
            <script>
                (function () {
                    var shareText = <?php echo json_encode($shareText, JSON_UNESCAPED_UNICODE); ?>;
                    var otpCallback = null;
                    var busy = false;

                    function flash(ok, msg) {
                        var el = document.getElementById('gstFlash');
                        el.className = 'alert ' + (ok ? 'alert-success' : 'alert-danger');
                        el.textContent = msg || (ok ? 'Done.' : 'Failed.');
                        el.classList.remove('d-none');
                        window.scrollTo(0, 0);
                    }

                    function formPayload(extra) {
                        var fd = new FormData(document.getElementById('gstOffsetForm'));
                        fd.append('period', <?php echo json_encode($gst_period); ?>);
                        fd.append('location_id', <?php echo json_encode((string) $gst_location_id); ?>);
                        fd.append('use_itc', document.getElementById('use_itc').checked ? '1' : '0');
                        fd.append('local_only', document.getElementById('local_only').checked ? '1' : '0');
                        extra = extra || {};
                        Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); });
                        return fd;
                    }

                    function post(action, extra, done) {
                        if (busy) return;
                        busy = true;
                        var fd = formPayload(extra);
                        fd.append('gst_ajax', action);
                        fetch('gst-gstr3b.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                            .then(function (r) { return r.json(); })
                            .then(function (res) { busy = false; done(res); })
                            .catch(function (err) { busy = false; flash(false, String(err)); });
                    }

                    function promptOtp(title, hint, cb) {
                        otpCallback = cb;
                        document.getElementById('gstOtpTitle').textContent = title;
                        document.getElementById('gstOtpHint').textContent = hint;
                        document.getElementById('gstOtpInput').value = '';
                        $('#gstOtpModal').modal('show');
                        setTimeout(function () { document.getElementById('gstOtpInput').focus(); }, 400);
                    }

                    document.getElementById('gstOtpSubmit').addEventListener('click', function () {
                        var otp = (document.getElementById('gstOtpInput').value || '').trim();
                        if (!otp) { alert('Enter OTP'); return; }
                        $('#gstOtpModal').modal('hide');
                        if (otpCallback) otpCallback(otp);
                    });

                    function authThen(next) {
                        post('auth_otp', {}, function (res) {
                            if (res.status === 'success' || (res.data && (res.data.needs_otp || res.data.otp_sent))) {
                                promptOtp('GST portal OTP', res.message || 'Enter the GST portal OTP.', function (otp) {
                                    post('auth_verify', { otp: otp }, function (v) {
                                        if (v.status === 'success') {
                                            flash(true, v.message || 'Authenticated.');
                                            if (next) next();
                                        } else {
                                            flash(false, v.message || 'OTP verification failed.');
                                        }
                                    });
                                });
                            } else {
                                flash(false, res.message || 'Could not request OTP.');
                            }
                        });
                    }

                    function needsAuth(res) {
                        var msg = ((res && res.message) || '').toLowerCase();
                        return !!(res && res.data && res.data.auth_required)
                            || msg.indexOf('authenticate') !== -1
                            || msg.indexOf('session expired') !== -1;
                    }

                    function needsOtp(res) {
                        var d = (res && res.data) || {};
                        return !!(d.needs_otp || d.otp_sent);
                    }

                    function file3b(otp) {
                        var extra = {};
                        if (otp) extra.otp = otp;
                        post('file_3b', extra, function (res) {
                            if (needsAuth(res)) {
                                authThen(function () { file3b(''); });
                                return;
                            }
                            if (needsOtp(res)) {
                                promptOtp('EVC OTP for GSTR-3B', res.message || 'Enter the EVC OTP to file GSTR-3B.', function (code) {
                                    file3b(code);
                                });
                                return;
                            }
                            if (res.status === 'success') {
                                flash(true, res.message || 'GSTR-3B filed.');
                                setTimeout(function () { window.location.reload(); }, 1200);
                            } else {
                                flash(false, res.message || 'File failed.');
                            }
                        });
                    }

                    function offsetThen(next) {
                        post('offset', {}, function (res) {
                            if (needsAuth(res)) {
                                authThen(function () { offsetThen(next); });
                                return;
                            }
                            if (res.status === 'success') {
                                if (next) next();
                                else {
                                    flash(true, res.message || 'Liability offset.');
                                    setTimeout(function () { window.location.reload(); }, 1000);
                                }
                            } else {
                                flash(false, res.message || 'Offset failed.');
                            }
                        });
                    }

                    function recalcCash() {
                        ['iamt', 'camt', 'samt', 'csamt'].forEach(function (k) {
                            var net = parseFloat(document.querySelector('[name="net_tax_pay[' + k + ']"]').value) || 0;
                            var itc = document.getElementById('use_itc').checked
                                ? (parseFloat(document.querySelector('[name="pditc[' + k + ']"]').value) || 0)
                                : 0;
                            if (!document.getElementById('use_itc').checked) {
                                document.querySelector('[name="pditc[' + k + ']"]').value = '0.00';
                            }
                            var cash = Math.max(0, Math.round((net - itc) * 100) / 100);
                            document.querySelector('[name="pdcash[' + k + ']"]').value = cash.toFixed(2);
                        });
                    }

                    document.getElementById('gstOffsetForm').addEventListener('input', function (e) {
                        if (e.target && e.target.classList.contains('gst-head-input')) {
                            if (e.target.name.indexOf('pdcash') === -1) recalcCash();
                        }
                    });
                    document.getElementById('use_itc').addEventListener('change', recalcCash);

                    var shareBtn = document.getElementById('gstShareBtn');
                    if (shareBtn) {
                        shareBtn.addEventListener('click', function () {
                            if (navigator.clipboard && navigator.clipboard.writeText) {
                                navigator.clipboard.writeText(shareText).then(function () {
                                    alert('GSTR-3B summary copied to clipboard.');
                                }).catch(function () { window.prompt('Copy summary', shareText); });
                            } else {
                                window.prompt('Copy summary', shareText);
                            }
                        });
                    }

                    document.getElementById('gstFileBtn').addEventListener('click', function () { file3b(''); });
                    document.getElementById('gstOffsetBtn').addEventListener('click', function () { offsetThen(null); });
                    document.getElementById('gstPayFileBtn').addEventListener('click', function () {
                        offsetThen(function () { file3b(''); });
                    });
                    var authBtn = document.getElementById('gstAuthOtpBtn');
                    if (authBtn) authBtn.addEventListener('click', function () { authThen(function () { window.location.reload(); }); });
                })();
            </script>

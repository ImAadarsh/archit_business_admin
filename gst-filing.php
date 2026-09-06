<?php
/**
 * GST Filing Center — admin hub (parity with Android GstFilingHome).
 * Calls GstFilingController directly; Perione keys stay in api/gst/config.php.
 */
require_once __DIR__ . '/includes/gst-init.php';

if ($gst_business_id <= 0) {
    header('Location: index.php');
    exit;
}

$gst_action = (string) ($_POST['gst_action'] ?? $_GET['gst_action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $gst && $gst_db_ok) {
    if ($gst_action === 'save_credentials') {
        $gstin = strtoupper(preg_replace('/\s+/', '', (string) ($_POST['gstin'] ?? '')));
        $username = trim((string) ($_POST['gst_username'] ?? ''));
        $email = trim((string) ($_POST['gst_email'] ?? ''));
        $password = trim((string) ($_POST['gst_password'] ?? ''));
        $ip = trim((string) ($_POST['ip_address'] ?? 'auto'));
        if ($ip === '') {
            $ip = 'auto';
        }
        if ($gstin === '' || $username === '') {
            gst_flash(false, 'GSTIN and portal username are required.');
        } else {
            $fields = [
                'gstin' => $gstin,
                'gst_username' => $username,
                'gst_email' => $email,
                'state_cd' => GstFilingController::stateFromGstin($gstin),
                'ip_address' => $ip,
            ];
            if ($password !== '') {
                $fields['gst_password'] = $password;
            }
            $ok = $gst->upsertCredentials($gst_business_id, $fields);
            gst_flash($ok, $ok ? 'GST details saved.' : 'Could not save GST details.');
        }
        gst_redirect_hub();
    }

    if ($gst_action === 'sync_gstr1') {
        $res = $gst->prepareGstr1([
            'business_id' => $gst_business_id,
            'period' => $gst_period,
            'location_id' => $gst_location_id ?: null,
            'push_portal' => false,
        ]);
        $ok = ($res['status'] ?? '') === 'success';
        $count = (int) ($res['data']['invoice_count'] ?? 0);
        gst_flash($ok, $ok
            ? ($count . ' invoices synced for ' . gst_period_label($gst_period) . '.')
            : (string) ($res['message'] ?? 'Sync failed.'));
        gst_redirect_hub();
    }

    if ($gst_action === 'itc_status') {
        $eid = (string) ($_POST['expense_id'] ?? '');
        $status = strtolower(trim((string) ($_POST['status'] ?? '')));
        if ($eid === '' || !in_array($status, ['matched', 'pending', 'carry'], true)) {
            gst_flash(false, 'Invalid ITC action.');
        } else {
            $res = $gst->reconcileItc([
                'business_id' => $gst_business_id,
                'period' => $gst_period,
                'location_id' => $gst_location_id ?: null,
                'expense_id' => $eid,
                'status' => $status,
            ]);
            $ok = ($res['status'] ?? '') === 'success';
            if ($ok && $status === 'matched') {
                gst_flash(true, 'Approved — this ITC now reduces net tax.');
            } elseif ($ok) {
                gst_flash(true, 'Kept as pending — not in eligible ITC.');
            } else {
                gst_flash(false, (string) ($res['message'] ?? 'Could not update ITC.'));
            }
        }
        gst_redirect_hub();
    }

    if ($gst_action === 'request_otp') {
        $res = $gst->authOtp(['business_id' => $gst_business_id]);
        $ok = ($res['status'] ?? '') === 'success';
        $already = !empty($res['data']['already_authenticated']) || !empty($res['data']['gst_auth_valid']);
        gst_flash($ok, $ok
            ? (string) ($res['message'] ?? ($already ? 'OTP already verified.' : 'OTP sent to GST-registered mobile/email.'))
            : (string) ($res['message'] ?? 'Could not request OTP.'));
        if ($ok && !$already) {
            $_SESSION['gst_otp_prompt'] = 1;
        }
        gst_redirect_hub();
    }

    if ($gst_action === 'verify_otp') {
        $otp = trim((string) ($_POST['otp'] ?? ''));
        $res = $gst->authVerify([
            'business_id' => $gst_business_id,
            'otp' => $otp,
        ]);
        $ok = ($res['status'] ?? '') === 'success';
        gst_flash($ok, $ok
            ? (string) ($res['message'] ?? 'GST session authenticated.')
            : (string) ($res['message'] ?? 'OTP verification failed.'));
        unset($_SESSION['gst_otp_prompt']);
        gst_redirect_hub();
    }
}

$summary = [];
$summaryErr = '';
if ($gst && $gst_db_ok) {
    $sumRes = $gst->getPeriodSummary([
        'business_id' => $gst_business_id,
        'period' => $gst_period,
        'location_id' => $gst_location_id ?: null,
    ]);
    if (($sumRes['status'] ?? '') === 'success' && is_array($sumRes['data'] ?? null)) {
        $summary = $sumRes['data'];
    } else {
        $summaryErr = (string) ($sumRes['message'] ?? 'Could not load period summary.');
    }
} else {
    $summaryErr = 'Database connection failed. Cannot load GST filing data.';
}

$invoiceCount = (int) ($summary['invoice_count'] ?? 0);
$taxable = (float) ($summary['taxable'] ?? 0);
$salesGst = (float) ($summary['sales_gst'] ?? 0);
$itcClaimed = (float) ($summary['itc_claimed'] ?? 0);
$itcEligible = (float) ($summary['itc_eligible'] ?? 0);
$itcPendingAmt = (float) ($summary['itc_pending'] ?? 0);
$itcCarry = (float) ($summary['itc_carry'] ?? ($summary['carry_forward'] ?? 0));
$netCash = (float) ($summary['net_cash'] ?? max(0, $salesGst - $itcEligible));
$countAll = (int) ($summary['counts']['all'] ?? 0);
$countPending = (int) ($summary['counts']['pending'] ?? 0);
$countApproved = (int) ($summary['counts']['approved'] ?? 0);
$countAutoApproved = (int) ($summary['counts']['auto_approved'] ?? $summary['auto_approved'] ?? 0);
$pendingMini = is_array($summary['pending_mini'] ?? null) ? $summary['pending_mini'] : [];
$lastSynced = $summary['last_synced_at'] ?? null;
$filingUnlocked = !empty($summary['filing_unlocked']) || ($invoiceCount > 0 && $countPending === 0);
$step1Ok = $invoiceCount > 0;
$step2Ok = $countPending === 0 && $countAll > 0;

$itcRows = [];
if ($gst && $gst_db_ok) {
    $filter = $gst_itc_filter === 'approved' ? 'matched' : ($gst_itc_filter === 'pending' ? 'pending' : 'all');
    $itcRes = $gst->getGstr2b([
        'business_id' => $gst_business_id,
        'period' => $gst_period,
        'location_id' => $gst_location_id ?: null,
        'status' => $filter,
    ]);
    if (($itcRes['status'] ?? '') === 'success') {
        $itcRows = $itcRes['data']['invoices'] ?? [];
    }
}

$vendorPreview = [];
if ($gst_itc_filter === 'pending') {
    $vendorPreview = $pendingMini;
} else {
    foreach ($itcRows as $row) {
        if (($row['status'] ?? '') === 'ghost') {
            continue;
        }
        $vendorPreview[] = $row;
        if (count($vendorPreview) >= 5) {
            break;
        }
    }
}

$credRaw = ($gst && $gst_db_ok) ? $gst->getCredentials($gst_business_id) : null;
$portal = gst_portal_state(is_array($credRaw) ? $credRaw : []);
$gst_cred_public = gst_public_credentials($credRaw) ?: [];
if (is_array($credRaw) && trim((string) ($credRaw['gst_password'] ?? '')) !== '') {
    $gst_cred_public['has_password'] = true;
}
$showOtpModal = !empty($_SESSION['gst_otp_prompt']);
if ($showOtpModal) {
    unset($_SESSION['gst_otp_prompt']);
}

$calendarPeriods = [];
for ($i = 0; $i < 6; $i++) {
    $calendarPeriods[] = gst_shift_period(date('Y-m'), -$i);
}
$returnMap = $gst_db_ok ? gst_load_return_map($connect, $gst_business_id, $calendarPeriods) : [];
$apiLogs = $gst_db_ok ? gst_load_api_logs($connect, $gst_business_id, 10) : [];

$shareText = gst_summary_share(
    gst_period_label($gst_period),
    $invoiceCount,
    $taxable,
    $salesGst,
    $itcEligible,
    $netCash,
    $itcCarry
);

if (isset($_GET['export']) && $_GET['export'] === '1') {
    $fname = 'gst-summary-' . $gst_period . '.txt';
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    echo $shareText;
    exit;
}

$gst_nav = 'hub';
$currentPeriod = date('Y-m');
$lastPeriod = gst_shift_period($currentPeriod, -1);
$quarterPeriod = gst_quarter_start($currentPeriod);

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
                                    GST Filing Center
                                    <?php if ($filingUnlocked): ?>
                                        <span class="badge badge-success align-middle ml-2">READY</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning align-middle ml-2">IN PROGRESS</span>
                                    <?php endif; ?>
                                </h2>
                                <p class="small text-muted mb-0">Prepare GSTR-1, reconcile ITC, then file GSTR-3B for this period.</p>
                            </div>
                            <div class="col-auto">
                                <a class="btn btn-outline-secondary btn-sm" href="<?php echo gst_h(gst_url('gst-filing.php', ['export' => 1])); ?>">
                                    <i class="fe fe-download"></i> Export summary
                                </a>
                                <button type="button" class="btn btn-outline-primary btn-sm" id="gstShareBtn">
                                    <i class="fe fe-share-2"></i> Share summary
                                </button>
                            </div>
                        </div>

                        <?php include __DIR__ . '/includes/gst-subnav.php'; ?>

                        <?php if (!empty($gst_flash['success'])): ?>
                            <div class="alert alert-success"><?php echo gst_h($gst_flash['success']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($gst_flash['error'])): ?>
                            <div class="alert alert-danger"><?php echo gst_h($gst_flash['error']); ?></div>
                        <?php endif; ?>
                        <?php if ($summaryErr !== ''): ?>
                            <div class="alert alert-danger"><?php echo gst_h($summaryErr); ?></div>
                        <?php endif; ?>

                        <?php echo gst_auth_banner_html($portal, ['form_action' => gst_url('gst-filing.php')]); ?>

                        <div class="card shadow mb-4">
                            <div class="card-body">
                                <div class="row align-items-end">
                                    <div class="col-md-4 mb-2">
                                        <label class="small text-muted d-block">Return period</label>
                                        <div class="btn-group w-100">
                                            <a class="btn btn-outline-secondary" href="<?php echo gst_h(gst_url('gst-filing.php', ['period' => gst_shift_period($gst_period, -1)])); ?>" title="Previous month">&lsaquo;</a>
                                            <span class="btn btn-light disabled flex-fill"><?php echo gst_h(gst_period_label($gst_period)); ?> <span class="text-muted">(<?php echo gst_h($gst_period); ?>)</span></span>
                                            <a class="btn btn-outline-secondary" href="<?php echo gst_h(gst_url('gst-filing.php', ['period' => gst_shift_period($gst_period, 1)])); ?>" title="Next month">&rsaquo;</a>
                                        </div>
                                    </div>
                                    <div class="col-md-5 mb-2">
                                        <label class="small text-muted d-block">Presets</label>
                                        <a class="btn btn-sm <?php echo $gst_period === $currentPeriod ? 'btn-primary' : 'btn-outline-secondary'; ?>"
                                           href="<?php echo gst_h(gst_url('gst-filing.php', ['period' => $currentPeriod])); ?>">This month</a>
                                        <a class="btn btn-sm <?php echo $gst_period === $lastPeriod ? 'btn-primary' : 'btn-outline-secondary'; ?>"
                                           href="<?php echo gst_h(gst_url('gst-filing.php', ['period' => $lastPeriod])); ?>">Last month</a>
                                        <a class="btn btn-sm <?php echo $gst_period === $quarterPeriod ? 'btn-primary' : 'btn-outline-secondary'; ?>"
                                           href="<?php echo gst_h(gst_url('gst-filing.php', ['period' => $quarterPeriod])); ?>">This quarter</a>
                                    </div>
                                    <?php if (count($gst_locations) > 0): ?>
                                        <div class="col-md-3 mb-2">
                                            <form method="GET" action="gst-filing.php">
                                                <input type="hidden" name="period" value="<?php echo gst_h($gst_period); ?>">
                                                <label class="small text-muted d-block" for="gst_location_id">Location</label>
                                                <select name="location_id" id="gst_location_id" class="form-control" onchange="this.form.submit()">
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
                                <div class="mt-2">
                                    <strong class="text-warning"><?php echo gst_h(gst_deadline_hint($gst_period)); ?></strong>
                                    <span class="text-muted ml-3"><?php echo gst_h(gst_format_synced($lastSynced)); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-lg-8">
                                <div class="card shadow mb-4">
                                    <div class="card-header">
                                        <strong class="card-title mb-0">Filing checklist</strong>
                                        <button type="button" class="btn btn-sm btn-outline-secondary float-right" data-toggle="modal" data-target="#gstTipChecklist">?</button>
                                    </div>
                                    <div class="card-body">
                                        <p class="mb-1 <?php echo $step1Ok ? 'text-success' : 'text-muted'; ?>">
                                            <?php echo $step1Ok ? '✓' : '○'; ?> Step 1 · <?php echo $step1Ok ? 'Sales synced (' . $invoiceCount . ' invoices)' : 'Sync sales (GSTR-1)'; ?>
                                        </p>
                                        <p class="mb-1 <?php echo $step2Ok ? 'text-success' : 'text-muted'; ?>">
                                            <?php echo $step2Ok ? '✓' : '○'; ?>
                                            Step 2 · Approve ITC (<?php echo (int) $countApproved; ?>/<?php echo max($countAll, 1); ?> approved<?php
                                            echo $countAutoApproved > 0 ? ', ' . (int) $countAutoApproved . ' auto' : '';
                                            ?>)
                                        </p>
                                        <p class="mb-0 <?php echo $filingUnlocked ? 'text-success' : 'text-muted'; ?>">
                                            <?php echo $filingUnlocked ? '✓ Step 3 · Compute &amp; file (unlocked)' : '🔒 Step 3 · Compute &amp; file (locked)'; ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="card shadow mb-4">
                                    <div class="card-header" data-toggle="collapse" data-target="#gstStep1Body" style="cursor:pointer;">
                                        <strong class="card-title mb-0">STEP 1: GSTR-1 (Sales Records)</strong>
                                        <span class="float-right text-muted"><?php echo $step1Ok ? 'Data ready' : 'Pending Sync'; ?></span>
                                    </div>
                                    <div class="collapse show" id="gstStep1Body">
                                        <div class="card-body">
                                            <form method="POST" class="mb-3" action="<?php echo gst_h(gst_url('gst-filing.php')); ?>">
                                                <input type="hidden" name="gst_action" value="sync_gstr1">
                                                <input type="hidden" name="period" value="<?php echo gst_h($gst_period); ?>">
                                                <?php if ($gst_location_id > 0): ?>
                                                    <input type="hidden" name="location_id" value="<?php echo (int) $gst_location_id; ?>">
                                                <?php endif; ?>
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="fe fe-refresh-cw"></i> Sync Invoice Data
                                                </button>
                                            </form>
                                            <table class="table table-sm mb-3">
                                                <tr>
                                                    <td class="text-muted">Total Sales Invoices</td>
                                                    <td class="text-right font-weight-bold"><?php echo (int) $invoiceCount; ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="text-muted">Total Taxable Value</td>
                                                    <td class="text-right font-weight-bold"><?php echo gst_h(gst_inr($taxable)); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="text-muted">Total Tax Collected</td>
                                                    <td class="text-right font-weight-bold"><?php echo gst_h(gst_inr($salesGst)); ?></td>
                                                </tr>
                                            </table>
                                            <a href="<?php echo gst_h(gst_url('gst-gstr1.php')); ?>">View all sales invoices →</a>
                                        </div>
                                    </div>
                                </div>

                                <div class="card shadow mb-4">
                                    <div class="card-header" data-toggle="collapse" data-target="#gstStep2Body" style="cursor:pointer;">
                                        <strong class="card-title mb-0">STEP 2: LMS &amp; ITC Reconciliation (GSTR-2B)</strong>
                                    </div>
                                    <div class="collapse show" id="gstStep2Body">
                                        <div class="card-body">
                                            <div class="mb-3">
                                                <a class="btn btn-sm <?php echo $gst_itc_filter === 'all' ? 'btn-primary' : 'btn-outline-secondary'; ?>"
                                                   href="<?php echo gst_h(gst_url('gst-filing.php', ['itc' => 'all'])); ?>">All (<?php echo (int) $countAll; ?>)</a>
                                                <a class="btn btn-sm <?php echo $gst_itc_filter === 'pending' ? 'btn-primary' : 'btn-outline-secondary'; ?>"
                                                   href="<?php echo gst_h(gst_url('gst-filing.php', ['itc' => 'pending'])); ?>">Pending (<?php echo (int) $countPending; ?>)</a>
                                                <a class="btn btn-sm <?php echo $gst_itc_filter === 'approved' ? 'btn-primary' : 'btn-outline-secondary'; ?>"
                                                   href="<?php echo gst_h(gst_url('gst-filing.php', ['itc' => 'approved'])); ?>">Approved (<?php echo (int) $countApproved; ?>)</a>
                                            </div>
                                            <?php if ($countAutoApproved > 0): ?>
                                                <p class="small text-success mb-3">
                                                    <?php echo (int) $countAutoApproved; ?> ITC row(s) already auto-approved from GSTR-2B match.
                                                    Only Pending needs manual review.
                                                </p>
                                            <?php endif; ?>

                                            <div class="border rounded p-3 mb-3 bg-light">
                                                <h6 class="mb-2">ITC &amp; Tax Ledger Balance</h6>
                                                <div class="d-flex justify-content-between small py-1">
                                                    <span class="text-muted">GST Collected (Output Tax)</span>
                                                    <strong><?php echo gst_h(gst_inr($salesGst)); ?></strong>
                                                </div>
                                                <div class="d-flex justify-content-between small py-1">
                                                    <span class="text-muted">GST Input (ITC Approved)</span>
                                                    <strong><?php echo gst_h(gst_inr($itcEligible)); ?></strong>
                                                </div>
                                                <hr class="my-2">
                                                <div class="d-flex justify-content-between">
                                                    <strong>Net Tax Payable</strong>
                                                    <strong><?php echo gst_h(gst_inr($netCash)); ?></strong>
                                                </div>
                                            </div>
                                            <p class="small text-muted">Carry-forward / pending: <?php echo gst_h(gst_inr($itcCarry)); ?>
                                                · Claimed: <?php echo gst_h(gst_inr($itcClaimed)); ?>
                                                · Pending amount: <?php echo gst_h(gst_inr($itcPendingAmt)); ?>
                                            </p>

                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <strong>Pending vendors</strong>
                                                <button type="button" class="btn btn-link btn-sm p-0" data-toggle="modal" data-target="#gstTipStatus">What do statuses mean?</button>
                                            </div>
                                            <?php if (!$vendorPreview): ?>
                                                <p class="text-muted small">No pending ITC — all clear for this filter</p>
                                            <?php else: ?>
                                                <?php foreach ($vendorPreview as $row):
                                                    $vname = $row['paid_to'] ?: ($row['name'] ?? ('Vendor #' . ($row['id'] ?? '')));
                                                    $vgstin = $row['gstin'] ?? '';
                                                    $claimed = (float) ($row['gstClaimed'] ?? $row['gst_claimed'] ?? 0);
                                                    $eid = (string) ($row['id'] ?? '');
                                                    $st = $row['status'] ?? 'pending';
                                                    ?>
                                                    <div class="border rounded p-3 mb-2">
                                                        <div class="font-weight-bold"><?php echo gst_h($vname); ?></div>
                                                        <div class="small text-muted mb-2">
                                                            <?php echo gst_h(gst_inr($claimed)); ?> ITC · <?php echo $vgstin !== '' ? gst_h($vgstin) : 'No GSTIN'; ?>
                                                            · <?php echo gst_itc_badge($st, $row['notes'] ?? ''); ?>
                                                        </div>
                                                        <?php if ($st === 'pending'): ?>
                                                            <form method="POST" class="d-inline" action="<?php echo gst_h(gst_url('gst-filing.php')); ?>">
                                                                <input type="hidden" name="gst_action" value="itc_status">
                                                                <input type="hidden" name="period" value="<?php echo gst_h($gst_period); ?>">
                                                                <input type="hidden" name="expense_id" value="<?php echo gst_h($eid); ?>">
                                                                <input type="hidden" name="status" value="matched">
                                                                <button type="submit" class="btn btn-sm btn-success">Approve</button>
                                                            </form>
                                                            <form method="POST" class="d-inline" action="<?php echo gst_h(gst_url('gst-filing.php')); ?>">
                                                                <input type="hidden" name="gst_action" value="itc_status">
                                                                <input type="hidden" name="period" value="<?php echo gst_h($gst_period); ?>">
                                                                <input type="hidden" name="expense_id" value="<?php echo gst_h($eid); ?>">
                                                                <input type="hidden" name="status" value="pending">
                                                                <button type="submit" class="btn btn-sm btn-outline-danger">Reject</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            <a class="btn btn-primary mt-2" href="<?php echo gst_h(gst_url('gst-gstr2b.php', ['itc' => $gst_itc_filter])); ?>">Open ITC Reconciliation</a>
                                        </div>
                                    </div>
                                </div>

                                <div class="card shadow mb-4">
                                    <div class="card-header" data-toggle="collapse" data-target="#gstStep3Body" style="cursor:pointer;">
                                        <strong class="card-title mb-0">
                                            <?php echo $filingUnlocked ? '' : '🔒 '; ?>STEP 3: GSTR-3B Summary &amp; Payment
                                        </strong>
                                    </div>
                                    <div class="collapse show" id="gstStep3Body">
                                        <div class="card-body">
                                            <table class="table table-sm">
                                                <tr>
                                                    <td class="text-muted">Gross Output Liability</td>
                                                    <td class="text-right font-weight-bold"><?php echo gst_h(gst_inr($salesGst)); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="text-muted">Less: ITC Utilized</td>
                                                    <td class="text-right font-weight-bold"><?php echo gst_h(gst_inr($itcEligible)); ?></td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Net Cash To Pay</strong></td>
                                                    <td class="text-right font-weight-bold"><?php echo gst_h(gst_inr($netCash)); ?></td>
                                                </tr>
                                            </table>
                                            <?php if ($filingUnlocked): ?>
                                                <a class="btn btn-primary btn-block" href="<?php echo gst_h(gst_url('gst-gstr3b.php')); ?>">Pay &amp; File GSTR-3B</a>
                                                <p class="small text-muted mt-2 mb-0">Ready — review liability and file when portal is connected.</p>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-secondary btn-block" disabled>Pay &amp; File GSTR-3B</button>
                                                <p class="small text-muted mt-2 mb-0">Complete Step 1 sync and clear pending ITC to unlock filing.</p>
                                            <?php endif; ?>
                                            <div class="mt-3">
                                                <a href="<?php echo gst_h(gst_url('gst-carry-forward.php')); ?>">View carry-forward ITC →</a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-4">
                                <div class="card shadow mb-4">
                                    <div class="card-header">
                                        <strong class="card-title mb-0">GSTIN &amp; portal login</strong>
                                        <a class="small float-right" href="<?php echo gst_h(gst_url('gst-credentials.php')); ?>">Full form</a>
                                    </div>
                                    <div class="card-body">
                                        <p class="small text-muted mb-2">
                                            GSTIN: <strong><?php echo gst_h($gst_cred_public['gstin'] ?? '—'); ?></strong><br>
                                            Username: <strong><?php echo gst_h($gst_cred_public['gst_username'] ?? '—'); ?></strong><br>
                                            Email: <strong><?php echo gst_h($gst_cred_public['gst_email'] ?? '—'); ?></strong>
                                        </p>
                                        <?php
                                        $gst_cred_action = gst_url('gst-filing.php');
                                        include __DIR__ . '/includes/gst-credentials-form.php';
                                        ?>
                                    </div>
                                </div>

                                <div class="card shadow mb-4">
                                    <div class="card-header">
                                        <strong class="card-title mb-0">Filing calendar</strong>
                                        <span class="small text-muted">GSTR-1 ~11th · 3B ~20th</span>
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-sm mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>Period</th>
                                                        <th>GSTR-1</th>
                                                        <th>3B</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($calendarPeriods as $p):
                                                        $row = $returnMap[$p] ?? [];
                                                        $hi = $p === $gst_period;
                                                        ?>
                                                        <tr class="<?php echo $hi ? 'table-primary' : ''; ?>">
                                                            <td>
                                                                <a href="<?php echo gst_h(gst_url('gst-filing.php', ['period' => $p])); ?>">
                                                                    <?php echo gst_h(gst_period_label($p)); ?>
                                                                </a>
                                                                <div class="small text-muted">1st due <?php echo gst_h(gst_due_date($p, 11)); ?> · 3B <?php echo gst_h(gst_due_date($p, 20)); ?></div>
                                                            </td>
                                                            <td><?php echo gst_status_badge($row['gstr1_status'] ?? 'pending'); ?></td>
                                                            <td><?php echo gst_status_badge($row['gstr3b_status'] ?? 'pending'); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="p-2 border-top small">
                                            <a href="<?php echo gst_h(gst_url('gst-return-status.php')); ?>">Open return status</a>
                                        </div>
                                    </div>
                                </div>

                                <div class="card shadow mb-4">
                                    <div class="card-header">
                                        <strong class="card-title mb-0">Recent API actions</strong>
                                        <a class="small float-right" href="<?php echo gst_h(gst_url('gst-api-logs.php')); ?>">All logs</a>
                                    </div>
                                    <div class="card-body p-0">
                                        <?php if (!$apiLogs): ?>
                                            <p class="text-muted small p-3 mb-0">No GST API calls logged yet.</p>
                                        <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th>When</th>
                                                            <th>Action</th>
                                                            <th>Status</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($apiLogs as $log): ?>
                                                            <tr>
                                                                <td class="small"><?php echo gst_h($log['created_at']); ?></td>
                                                                <td class="small"><?php echo gst_h($log['action']); ?>
                                                                    <?php if (!empty($log['period'])): ?>
                                                                        <span class="text-muted"><?php echo gst_h($log['period']); ?></span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td><?php echo gst_status_badge($log['status'] ?? ''); ?>
                                                                    <?php if (!empty($log['http_code'])): ?>
                                                                        <span class="small text-muted"><?php echo (int) $log['http_code']; ?></span>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$portal['authenticated']): ?>
                <p class="text-center text-muted small mb-2">Portal filing not connected — using sales invoices and expenses for preview.</p>
            <?php endif; ?>

            <div class="gst-sticky-net border-top bg-white px-4 py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-muted">Net cash to pay</span>
                    <strong class="h5 mb-0"><?php echo gst_h(gst_inr($netCash)); ?></strong>
                </div>
            </div>

            <div class="modal fade" id="gstTipChecklist" tabindex="-1" role="dialog">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Filing checklist</h5>
                            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                        </div>
                        <div class="modal-body">
                            Step 1: Load sales invoices for the period.<br>
                            Step 2: Approve vendor ITC (handshake).<br>
                            Step 3 unlocks when sales are synced and no pending ITC remains.
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal fade" id="gstTipStatus" tabindex="-1" role="dialog">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Approved (Matched)</h5>
                            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                        </div>
                        <div class="modal-body">
                            Vendor has (or you treat as having) reported this purchase in their GSTR-1 so it appears in your GSTR-2B handshake. Only Approved ITC reduces your net tax.
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal fade" id="gstOtpModal" tabindex="-1" role="dialog">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <form method="POST" action="<?php echo gst_h(gst_url('gst-filing.php')); ?>">
                            <div class="modal-header">
                                <h5 class="modal-title">GST portal OTP</h5>
                                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                            </div>
                            <div class="modal-body">
                                <p>Enter the OTP sent to the GST-registered mobile / email.</p>
                                <input type="hidden" name="gst_action" value="verify_otp">
                                <input type="hidden" name="period" value="<?php echo gst_h($gst_period); ?>">
                                <input type="text" class="form-control" name="otp" maxlength="10" inputmode="numeric" placeholder="6-digit OTP" required>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary">Submit</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <style>
                .gst-sticky-net {
                    position: sticky;
                    bottom: 0;
                    z-index: 20;
                    box-shadow: 0 -2px 8px rgba(0,0,0,.06);
                }
                .nav-pills .nav-link { font-size: 13px; }
            </style>
            <script>
                (function () {
                    var shareText = <?php echo json_encode($shareText, JSON_UNESCAPED_UNICODE); ?>;
                    var btn = document.getElementById('gstShareBtn');
                    if (btn) {
                        btn.addEventListener('click', function () {
                            if (navigator.clipboard && navigator.clipboard.writeText) {
                                navigator.clipboard.writeText(shareText).then(function () {
                                    alert('GST summary copied to clipboard.');
                                }).catch(function () {
                                    window.prompt('Copy GST summary', shareText);
                                });
                            } else {
                                window.prompt('Copy GST summary', shareText);
                            }
                        });
                    }
                })();
            </script>
            <?php include __DIR__ . '/admin/footer.php'; ?>
            <?php if ($showOtpModal): ?>
            <script>$(function () { $('#gstOtpModal').modal('show'); });</script>
            <?php endif; ?>

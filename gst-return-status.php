<?php
/**
 * GST return status: ARN / ref_id, poll get-return-status, R1 + R3B filing history.
 */
require_once __DIR__ . '/includes/gst-init.php';

if ($gst_business_id <= 0) {
    header('Location: index.php');
    exit;
}

$gst_action = (string) ($_POST['gst_action'] ?? '');
$pollRef = trim((string) ($_POST['ref_id'] ?? $_GET['ref_id'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $gst && $gst_db_ok) {
    if ($gst_action === 'request_otp') {
        $res = $gst->authOtp(['business_id' => $gst_business_id]);
        $ok = ($res['status'] ?? '') === 'success';
        gst_flash($ok, $ok
            ? (string) ($res['message'] ?? 'OTP sent.')
            : (string) ($res['message'] ?? 'Could not request OTP.'));
        if ($ok) {
            $_SESSION['gst_otp_prompt'] = 1;
        }
        header('Location: ' . gst_url('gst-return-status.php', $pollRef !== '' ? ['ref_id' => $pollRef] : []));
        exit;
    }
    if ($gst_action === 'verify_otp') {
        $res = $gst->authVerify([
            'business_id' => $gst_business_id,
            'otp' => trim((string) ($_POST['otp'] ?? '')),
        ]);
        $ok = ($res['status'] ?? '') === 'success';
        gst_flash($ok, $ok
            ? (string) ($res['message'] ?? 'GST session authenticated.')
            : (string) ($res['message'] ?? 'OTP verification failed.'));
        unset($_SESSION['gst_otp_prompt']);
        header('Location: ' . gst_url('gst-return-status.php', $pollRef !== '' ? ['ref_id' => $pollRef] : []));
        exit;
    }
}

$status = [];
$history = [];
$statusErr = '';
if ($gst && $gst_db_ok) {
    $in = [
        'business_id' => $gst_business_id,
        'period' => $gst_period,
        'include_history' => 1,
        'history_limit' => 24,
    ];
    if ($pollRef !== '') {
        $in['ref_id'] = $pollRef;
    }
    $res = $gst->getReturnStatus($in);
    if (($res['status'] ?? '') === 'success' && is_array($res['data'] ?? null)) {
        $status = $res['data'];
        $history = $status['history'] ?? [];
    } else {
        $statusErr = (string) ($res['message'] ?? 'Could not load return status.');
    }
    if (!$history) {
        $hist = $gst->listFilingHistory(['business_id' => $gst_business_id, 'limit' => 24]);
        if (($hist['status'] ?? '') === 'success') {
            $history = $hist['data']['rows'] ?? [];
        }
    }
} else {
    $statusErr = 'Database connection failed.';
}

$credRaw = ($gst && $gst_db_ok) ? $gst->getCredentials($gst_business_id) : null;
$portal = gst_portal_state(is_array($credRaw) ? $credRaw : []);
$showOtpModal = !empty($_SESSION['gst_otp_prompt']);
if ($showOtpModal) {
    unset($_SESSION['gst_otp_prompt']);
}

$local = is_array($status['local'] ?? null) ? $status['local'] : [];
$arn = (string) ($status['arn'] ?? '');
$refId = (string) ($status['ref_id'] ?? $pollRef);
$interest = $status['interest'] ?? ['iamt' => 0, 'camt' => 0, 'samt' => 0, 'csamt' => 0];
$lateFee = $status['late_fee'] ?? ['iamt' => 0, 'camt' => 0, 'samt' => 0, 'csamt' => 0];
$currentPeriod = date('Y-m');
$lastPeriod = gst_shift_period($currentPeriod, -1);

$gst_nav = 'status';
include __DIR__ . '/admin/header.php';
?>
<body class="vertical light">
    <div class="wrapper">
        <?php
        include __DIR__ . '/admin/navbar.php';
        include __DIR__ . '/admin/aside.php';
        ?>
        <main role="main" class="main-content">
            <div class="container-fluid">
                <div class="row justify-content-center">
                    <div class="col-12">
                        <div class="row align-items-center mb-3">
                            <div class="col">
                                <h2 class="h3 page-title">GST return status</h2>
                                <p class="small text-muted mb-0">
                                    ARN, reference id, and filing history for GSTR-1 (R1) and GSTR-3B (R3B).
                                    Polls <code>get-return-status.php</code> when the GST session is valid.
                                    <a href="<?php echo gst_h(gst_url('gst-filing.php')); ?>">Back to Filing Center</a>
                                </p>
                            </div>
                        </div>
                        <?php include __DIR__ . '/includes/gst-subnav.php'; ?>

                        <?php if (!empty($gst_flash['success'])): ?>
                            <div class="alert alert-success"><?php echo gst_h($gst_flash['success']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($gst_flash['error'])): ?>
                            <div class="alert alert-danger"><?php echo gst_h($gst_flash['error']); ?></div>
                        <?php endif; ?>
                        <?php if ($statusErr !== ''): ?>
                            <div class="alert alert-danger"><?php echo gst_h($statusErr); ?></div>
                        <?php endif; ?>

                        <?php if (!$portal['connected']): ?>
                            <div class="alert alert-warning">
                                GST portal not connected.
                                <a class="alert-link" href="<?php echo gst_h(gst_url('gst-credentials.php')); ?>">Open GST credentials</a>
                            </div>
                        <?php elseif (!empty($status['auth_required']) || $portal['needs_otp']): ?>
                            <div class="alert alert-info">
                                Authenticate to poll the GST portal.
                                <form method="POST" class="d-inline ml-2">
                                    <input type="hidden" name="gst_action" value="request_otp">
                                    <input type="hidden" name="period" value="<?php echo gst_h($gst_period); ?>">
                                    <button type="submit" class="btn btn-sm btn-primary">Request OTP</button>
                                </form>
                            </div>
                        <?php endif; ?>

                        <div class="card shadow mb-4">
                            <div class="card-body">
                                <form method="GET" action="gst-return-status.php" class="form-row align-items-end">
                                    <div class="form-group col-md-3 mb-2">
                                        <label class="small text-muted">Period</label>
                                        <div class="btn-group w-100">
                                            <a class="btn btn-outline-secondary" href="<?php echo gst_h(gst_url('gst-return-status.php', ['period' => gst_shift_period($gst_period, -1)])); ?>">&lsaquo;</a>
                                            <span class="btn btn-light disabled flex-fill"><?php echo gst_h(gst_period_label($gst_period)); ?></span>
                                            <a class="btn btn-outline-secondary" href="<?php echo gst_h(gst_url('gst-return-status.php', ['period' => gst_shift_period($gst_period, 1)])); ?>">&rsaquo;</a>
                                        </div>
                                    </div>
                                    <div class="form-group col-md-2 mb-2">
                                        <a class="btn btn-sm <?php echo $gst_period === $currentPeriod ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo gst_h(gst_url('gst-return-status.php', ['period' => $currentPeriod])); ?>">This month</a>
                                        <a class="btn btn-sm <?php echo $gst_period === $lastPeriod ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo gst_h(gst_url('gst-return-status.php', ['period' => $lastPeriod])); ?>">Last month</a>
                                    </div>
                                    <div class="form-group col-md-3 mb-2">
                                        <label class="small text-muted">ref_id (optional)</label>
                                        <input type="text" class="form-control" name="ref_id" value="<?php echo gst_h($pollRef); ?>" placeholder="Portal reference id">
                                        <input type="hidden" name="period" value="<?php echo gst_h($gst_period); ?>">
                                    </div>
                                    <div class="form-group col-md-2 mb-2">
                                        <button type="submit" class="btn btn-primary btn-block">Poll status</button>
                                    </div>
                                    <div class="form-group col-md-2 mb-2">
                                        <a class="btn btn-outline-secondary btn-block" href="<?php echo gst_h(gst_url('gst-return-status.php')); ?>">Refresh</a>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-lg-6">
                                <div class="card shadow mb-4">
                                    <div class="card-header"><strong class="card-title mb-0">This period</strong></div>
                                    <div class="card-body">
                                        <p class="mb-1">Period: <strong><?php echo gst_h($status['period_label'] ?? gst_period_label($gst_period)); ?></strong>
                                            <span class="text-muted">(<?php echo gst_h($status['ret_period'] ?? ''); ?>)</span></p>
                                        <p class="mb-1">ARN: <strong><?php echo gst_h($arn !== '' ? $arn : '—'); ?></strong></p>
                                        <p class="mb-1">ref_id: <strong><?php echo gst_h($refId !== '' ? $refId : '—'); ?></strong></p>
                                        <hr>
                                        <p class="mb-1">GSTR-1 (R1): <?php echo gst_status_badge($local['gstr1_status'] ?? 'pending'); ?>
                                            <span class="small text-muted"><?php echo gst_h($local['gstr1_ref_id'] ?? '—'); ?></span>
                                            <?php if (!empty($local['gstr1_filed_at'])): ?>
                                                <span class="small text-muted">filed <?php echo gst_h($local['gstr1_filed_at']); ?></span>
                                            <?php endif; ?>
                                        </p>
                                        <p class="mb-1">GSTR-3B (R3B): <?php echo gst_status_badge($local['gstr3b_status'] ?? 'pending'); ?>
                                            <span class="small text-muted"><?php echo gst_h($local['gstr3b_ref_id'] ?? '—'); ?></span>
                                            <?php if (!empty($local['gstr3b_filed_at'])): ?>
                                                <span class="small text-muted">filed <?php echo gst_h($local['gstr3b_filed_at']); ?></span>
                                            <?php endif; ?>
                                        </p>
                                        <p class="mb-0 small text-muted">Last synced: <?php echo gst_h(gst_format_synced($local['last_synced_at'] ?? null)); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="card shadow mb-4">
                                    <div class="card-header"><strong class="card-title mb-0">Interest &amp; late fee</strong></div>
                                    <div class="card-body p-0">
                                        <table class="table table-sm mb-0">
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
                                                    <td>Interest</td>
                                                    <td class="text-right"><?php echo gst_h(gst_inr($interest['iamt'] ?? 0)); ?></td>
                                                    <td class="text-right"><?php echo gst_h(gst_inr($interest['camt'] ?? 0)); ?></td>
                                                    <td class="text-right"><?php echo gst_h(gst_inr($interest['samt'] ?? 0)); ?></td>
                                                    <td class="text-right"><?php echo gst_h(gst_inr($interest['csamt'] ?? 0)); ?></td>
                                                </tr>
                                                <tr>
                                                    <td>Late fee</td>
                                                    <td class="text-right"><?php echo gst_h(gst_inr($lateFee['iamt'] ?? 0)); ?></td>
                                                    <td class="text-right"><?php echo gst_h(gst_inr($lateFee['camt'] ?? 0)); ?></td>
                                                    <td class="text-right"><?php echo gst_h(gst_inr($lateFee['samt'] ?? 0)); ?></td>
                                                    <td class="text-right"><?php echo gst_h(gst_inr($lateFee['csamt'] ?? 0)); ?></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="card-body small text-muted">
                                        <?php echo gst_h($status['charges_note'] ?? 'Placeholders until the portal returns interest / late fee.'); ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($status['portal'])): ?>
                            <div class="card shadow mb-4">
                                <div class="card-header" data-toggle="collapse" data-target="#gstPortalJson" style="cursor:pointer;">
                                    <strong class="card-title mb-0">Portal response JSON</strong>
                                </div>
                                <div class="collapse" id="gstPortalJson">
                                    <div class="card-body">
                                        <pre class="small mb-0" style="max-height:360px;overflow:auto;white-space:pre-wrap;"><?php echo gst_h(gst_pretty_json($status['portal'])); ?></pre>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="card shadow mb-4">
                            <div class="card-header">
                                <strong class="card-title mb-0">Filing history (R1 &amp; R3B)</strong>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Period</th>
                                                <th>GSTR-1</th>
                                                <th>R1 ref / ARN</th>
                                                <th>Filed</th>
                                                <th>GSTR-3B</th>
                                                <th>R3B ref / ARN</th>
                                                <th>Filed</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!$history): ?>
                                                <tr><td colspan="8" class="text-muted">No saved return periods yet. Compute or file a return to create history.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($history as $h): ?>
                                                    <tr class="<?php echo ($h['period'] ?? '') === $gst_period ? 'table-primary' : ''; ?>">
                                                        <td>
                                                            <a href="<?php echo gst_h(gst_url('gst-return-status.php', ['period' => $h['period']])); ?>">
                                                                <?php echo gst_h($h['period_label'] ?? $h['period']); ?>
                                                            </a>
                                                        </td>
                                                        <td><?php echo gst_status_badge($h['gstr1_status'] ?? ($h['r1']['status'] ?? '')); ?></td>
                                                        <td class="small"><?php echo gst_h($h['gstr1_ref_id'] ?? ($h['r1']['ref_id'] ?? '—')); ?></td>
                                                        <td class="small"><?php echo gst_h($h['gstr1_filed_at'] ?? ($h['r1']['filed_at'] ?? '—')); ?></td>
                                                        <td><?php echo gst_status_badge($h['gstr3b_status'] ?? ($h['r3b']['status'] ?? '')); ?></td>
                                                        <td class="small"><?php echo gst_h($h['gstr3b_ref_id'] ?? ($h['r3b']['ref_id'] ?? '—')); ?></td>
                                                        <td class="small"><?php echo gst_h($h['gstr3b_filed_at'] ?? ($h['r3b']['filed_at'] ?? '—')); ?></td>
                                                        <td>
                                                            <?php
                                                            $hrefRef = $h['gstr3b_ref_id'] ?? $h['gstr1_ref_id'] ?? '';
                                                            ?>
                                                            <a class="btn btn-sm btn-outline-primary" href="<?php echo gst_h(gst_url('gst-return-status.php', ['period' => $h['period'], 'ref_id' => $hrefRef])); ?>">Poll</a>
                                                        </td>
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

            <div class="modal fade" id="gstOtpModal" tabindex="-1" role="dialog">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <form method="POST">
                            <div class="modal-header">
                                <h5 class="modal-title">GST portal OTP</h5>
                                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                            </div>
                            <div class="modal-body">
                                <p>Enter the OTP sent to the GST-registered mobile / email.</p>
                                <input type="hidden" name="gst_action" value="verify_otp">
                                <input type="hidden" name="period" value="<?php echo gst_h($gst_period); ?>">
                                <input type="hidden" name="ref_id" value="<?php echo gst_h($pollRef); ?>">
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
            <?php include __DIR__ . '/admin/footer.php'; ?>
            <?php if ($showOtpModal): ?>
            <script>$(function () { $('#gstOtpModal').modal('show'); });</script>
            <?php endif; ?>

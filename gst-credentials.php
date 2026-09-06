<?php
/**
 * GST credentials: GSTIN, portal username, email, last auth. Never client secret.
 */
require_once __DIR__ . '/includes/gst-init.php';

if ($gst_business_id <= 0) {
    header('Location: index.php');
    exit;
}

$gst_action = (string) ($_POST['gst_action'] ?? '');

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
        header('Location: ' . gst_url('gst-credentials.php'));
        exit;
    }

    if ($gst_action === 'request_otp') {
        $res = $gst->authOtp(['business_id' => $gst_business_id]);
        $ok = ($res['status'] ?? '') === 'success';
        gst_flash($ok, $ok
            ? (string) ($res['message'] ?? 'OTP sent to GST-registered mobile/email.')
            : (string) ($res['message'] ?? 'Could not request OTP.'));
        if ($ok) {
            $_SESSION['gst_otp_prompt'] = 1;
        }
        header('Location: ' . gst_url('gst-credentials.php'));
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
        header('Location: ' . gst_url('gst-credentials.php'));
        exit;
    }
}

$safe = [];
if ($gst && $gst_db_ok) {
    $sr = $gst->getSafeCredentials(['business_id' => $gst_business_id]);
    if (($sr['status'] ?? '') === 'success' && is_array($sr['data'] ?? null)) {
        $safe = $sr['data'];
    }
}

$credRaw = ($gst && $gst_db_ok) ? $gst->getCredentials($gst_business_id) : null;
$gst_cred_public = gst_public_credentials($credRaw) ?: [];
if (is_array($credRaw) && trim((string) ($credRaw['gst_password'] ?? '')) !== '') {
    $gst_cred_public['has_password'] = true;
}
$portal = gst_portal_state(is_array($credRaw) ? $credRaw : []);
$showOtpModal = !empty($_SESSION['gst_otp_prompt']);
if ($showOtpModal) {
    unset($_SESSION['gst_otp_prompt']);
}

$gst_nav = 'credentials';
$gst_cred_action = gst_url('gst-credentials.php');
$lastAuth = $safe['last_auth_at'] ?? null;
$tokenExpiry = $safe['token_expiry'] ?? ($portal['token_expiry'] ?? null);

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
                                <h2 class="h3 page-title">GST credentials</h2>
                                <p class="small text-muted mb-0">
                                    GSTIN, portal username, email, and last authentication only.
                                    Perione client secret is never stored in this screen or returned to the browser.
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

                        <?php if (!$portal['connected']): ?>
                            <div class="alert alert-warning">GST portal not connected. Save GSTIN and username, then request OTP.</div>
                        <?php elseif ($portal['needs_otp']): ?>
                            <div class="alert alert-info">
                                OTP needed to file or sync from the GST portal. Local reads still work.
                                <form method="POST" class="d-inline ml-2">
                                    <input type="hidden" name="gst_action" value="request_otp">
                                    <input type="hidden" name="period" value="<?php echo gst_h($gst_period); ?>">
                                    <button type="submit" class="btn btn-sm btn-primary">Request OTP</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success">GST portal session is authenticated.</div>
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-lg-8">
                                <div class="card shadow mb-4">
                                    <div class="card-header">
                                        <strong class="card-title">Business GST details</strong>
                                    </div>
                                    <div class="card-body">
                                        <?php include __DIR__ . '/includes/gst-credentials-form.php'; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="card shadow mb-4">
                                    <div class="card-header">
                                        <strong class="card-title mb-0">Session</strong>
                                    </div>
                                    <div class="card-body">
                                        <p class="mb-1">GSTIN: <strong><?php echo gst_h($safe['gstin'] ?? $gst_cred_public['gstin'] ?? '—'); ?></strong></p>
                                        <p class="mb-1">Portal username: <strong><?php echo gst_h($safe['gst_username'] ?? $gst_cred_public['gst_username'] ?? '—'); ?></strong></p>
                                        <p class="mb-1">Email: <strong><?php echo gst_h($safe['gst_email'] ?? $gst_cred_public['gst_email'] ?? '—'); ?></strong></p>
                                        <p class="mb-1">State code: <strong><?php echo gst_h($safe['state_cd'] ?? '—'); ?></strong></p>
                                        <hr>
                                        <p class="mb-1">
                                            Last auth:
                                            <strong>
                                                <?php
                                                if ($lastAuth && strtotime($lastAuth)) {
                                                    echo gst_h(date('j M Y, g:i A', strtotime($lastAuth)));
                                                } else {
                                                    echo 'Never';
                                                }
                                                ?>
                                            </strong>
                                        </p>
                                        <p class="mb-1">
                                            Token expiry:
                                            <strong>
                                                <?php
                                                if ($tokenExpiry && strtotime((string) $tokenExpiry)) {
                                                    echo gst_h(date('j M Y, g:i A', strtotime((string) $tokenExpiry)));
                                                } else {
                                                    echo '—';
                                                }
                                                ?>
                                            </strong>
                                        </p>
                                        <p class="mb-3">
                                            Session:
                                            <?php echo !empty($safe['session_valid']) || !empty($portal['authenticated'])
                                                ? '<span class="badge badge-success">valid</span>'
                                                : '<span class="badge badge-warning">needs OTP</span>'; ?>
                                        </p>
                                        <?php if ($portal['connected'] && !$portal['authenticated']): ?>
                                            <form method="POST">
                                                <input type="hidden" name="gst_action" value="request_otp">
                                                <button type="submit" class="btn btn-primary btn-block">Request GST OTP</button>
                                            </form>
                                        <?php elseif ($portal['authenticated']): ?>
                                            <form method="POST">
                                                <input type="hidden" name="gst_action" value="request_otp">
                                                <button type="submit" class="btn btn-outline-secondary btn-block">Re-authenticate</button>
                                            </form>
                                        <?php endif; ?>
                                        <p class="small text-muted mt-3 mb-0">
                                            Client ID / client secret live only in server config and are never shown here.
                                            Auth tokens are not displayed.
                                        </p>
                                    </div>
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

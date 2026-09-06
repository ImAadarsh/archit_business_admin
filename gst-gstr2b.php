<?php
/**
 * GSTR-2B / ITC reconciliation — two-way books vs portal match.
 * Perione client secrets stay on the server (api/gst/config.php). Never rendered here.
 */
require_once __DIR__ . '/includes/gst-init.php';

if ($gst_business_id <= 0) {
    header('Location: index.php');
    exit;
}

$gst_2b_filter = strtolower(trim((string) ($_GET['filter'] ?? $_POST['filter'] ?? 'all')));
if (!in_array($gst_2b_filter, ['all', 'pending', 'matched', 'carry', 'ghost'], true)) {
    $gst_2b_filter = 'all';
}
$gst_2b_q = trim((string) ($_GET['q'] ?? $_POST['q'] ?? ''));

$gst_2b_extra = ['filter' => $gst_2b_filter];
if ($gst_2b_q !== '') {
    $gst_2b_extra['q'] = $gst_2b_q;
}

function gst_2b_go($extra = [])
{
    global $gst_2b_extra;
    gst_redirect('gst-gstr2b.php', array_merge($gst_2b_extra, $extra));
}

function gst_2b_eligible_now()
{
    global $gst, $gst_business_id, $gst_period, $gst_location_id;
    if (!$gst) {
        return 0.0;
    }
    $res = $gst->getGstr2b([
        'business_id' => $gst_business_id,
        'period' => $gst_period,
        'location_id' => $gst_location_id ?: null,
        'status' => 'all',
    ]);
    return (float) (($res['data']['totals']['eligible'] ?? 0));
}

$gst_action = (string) ($_POST['gst_action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $gst && $gst_db_ok && $gst_action !== '') {
    $ctx = [
        'business_id' => $gst_business_id,
        'period' => $gst_period,
        'location_id' => $gst_location_id ?: null,
    ];

    if ($gst_action === 'sync_gstr2b') {
        $res = $gst->syncGstr2b($ctx);
        $ok = ($res['status'] ?? '') === 'success';
        $data = is_array($res['data'] ?? null) ? $res['data'] : [];
        $msg = (string) ($res['message'] ?? 'GSTR-2B synced.');
        if (!empty($data['portal_warning'])) {
            $msg .= ' ' . $data['portal_warning'];
        }
        if (!empty($data['auto_approved'])) {
            $msg .= ' Auto-approved ' . (int) $data['auto_approved'] . ' matching ITC row(s).';
        } elseif (!empty($data['auto_matched'])) {
            $msg .= ' Paired ' . (int) $data['auto_matched'] . ' purchase(s) — review pending mismatches.';
        }
        gst_flash($ok, $msg);
        if ($ok && (!empty($data['auth_required']) || !empty($data['needs_otp']) || gst_needs_otp($res) || gst_needs_auth($res))) {
            $_SESSION['gst_otp_prompt'] = 1;
        }
        gst_2b_go();
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
        gst_2b_go();
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
        gst_2b_go();
    }

    if ($gst_action === 'itc_status') {
        $before = gst_2b_eligible_now();
        $eid = (string) ($_POST['expense_id'] ?? $_POST['id'] ?? '');
        $status = strtolower(trim((string) ($_POST['status'] ?? '')));
        $notes = trim((string) ($_POST['notes'] ?? $_POST['reason'] ?? ''));
        $res = $gst->reconcileItc(array_merge($ctx, [
            'expense_id' => $eid,
            'status' => $status,
            'notes' => $notes,
        ]));
        $ok = ($res['status'] ?? '') === 'success';
        $after = (float) (($res['data']['totals']['eligible'] ?? $before));
        if ($ok && $status === 'matched') {
            gst_flash(true, 'Approved · Eligible ITC ' . gst_inr($before) . ' → ' . gst_inr($after));
        } elseif ($ok && $status === 'pending') {
            gst_flash(true, 'Rejected / kept pending — not in eligible ITC.');
        } elseif ($ok && $status === 'carry') {
            gst_flash(true, 'Moved to carry-forward.');
        } elseif ($ok && $status === 'ghost') {
            gst_flash(true, 'Ghost credit dismissed.');
        } else {
            gst_flash(false, (string) ($res['message'] ?? 'Could not update ITC.'));
        }
        gst_2b_go();
    }

    if ($gst_action === 'approve_under') {
        $before = gst_2b_eligible_now();
        $res = $gst->reconcileItc(array_merge($ctx, [
            'bulk_action' => 'approve_under',
            'max_gst' => 5000,
        ]));
        $ok = ($res['status'] ?? '') === 'success';
        $n = (int) ($res['data']['updated'] ?? 0);
        $after = (float) (($res['data']['totals']['eligible'] ?? $before));
        gst_flash($ok, $ok
            ? ($n . ' approved · Eligible ITC ' . gst_inr($before) . ' → ' . gst_inr($after))
            : (string) ($res['message'] ?? 'Bulk approve failed.'));
        gst_2b_go();
    }

    if ($gst_action === 'carry_pending') {
        $res = $gst->reconcileItc(array_merge($ctx, ['bulk_action' => 'carry_pending']));
        $ok = ($res['status'] ?? '') === 'success';
        $n = (int) ($res['data']['updated'] ?? 0);
        gst_flash($ok, $ok
            ? ($n . ' pending row(s) moved to carry-forward.')
            : (string) ($res['message'] ?? 'Could not carry pending ITC.'));
        gst_2b_go();
    }

    if (in_array($gst_action, ['approve_selected', 'carry_selected', 'mark_ghost'], true)) {
        $before = gst_2b_eligible_now();
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : [];
        $notes = trim((string) ($_POST['notes'] ?? $_POST['reason'] ?? ''));
        if (!$ids) {
            gst_flash(false, 'Select at least one row.');
            gst_2b_go();
        }
        $res = $gst->reconcileItc(array_merge($ctx, [
            'bulk_action' => $gst_action,
            'ids' => $ids,
            'notes' => $notes,
        ]));
        $ok = ($res['status'] ?? '') === 'success';
        $n = (int) ($res['data']['updated'] ?? 0);
        $after = (float) (($res['data']['totals']['eligible'] ?? $before));
        if ($ok && $gst_action === 'approve_selected') {
            gst_flash(true, $n . ' approved · Eligible ITC ' . gst_inr($before) . ' → ' . gst_inr($after));
        } elseif ($ok && $gst_action === 'carry_selected') {
            gst_flash(true, $n . ' row(s) moved to carry-forward.');
        } elseif ($ok) {
            gst_flash(true, $n . ' marked as ghost / dismissed.');
        } else {
            gst_flash(false, (string) ($res['message'] ?? 'Bulk action failed.'));
        }
        gst_2b_go();
    }
}

$data = [];
$loadErr = '';
if ($gst && $gst_db_ok) {
    $res = $gst->getGstr2b([
        'business_id' => $gst_business_id,
        'period' => $gst_period,
        'location_id' => $gst_location_id ?: null,
        'status' => 'all',
    ]);
    if (($res['status'] ?? '') === 'success' && is_array($res['data'] ?? null)) {
        $data = $res['data'];
    } else {
        $loadErr = (string) ($res['message'] ?? 'Could not load GSTR-2B.');
    }
} else {
    $loadErr = 'Database connection failed. Cannot load GSTR-2B.';
}

$matches = is_array($data['matches'] ?? null) ? $data['matches'] : [];
$invoices = is_array($data['invoices'] ?? null) ? $data['invoices'] : [];
$vendors = is_array($data['vendor_summary'] ?? null) ? $data['vendor_summary'] : [];
$ghostQueue = is_array($data['ghost_queue'] ?? null) ? $data['ghost_queue'] : [];
$ghostDismissed = is_array($data['ghost_dismissed'] ?? null) ? $data['ghost_dismissed'] : [];
$eligibleNotes = is_array($data['eligible_notes'] ?? null) ? $data['eligible_notes'] : [];
$totals = is_array($data['totals'] ?? null) ? $data['totals'] : [];
$counts = is_array($data['counts'] ?? null) ? $data['counts'] : [
    'all' => 0, 'pending' => 0, 'matched' => 0, 'carry' => 0, 'ghost' => 0, 'auto_approved' => 0,
];
$autoApprovedCount = (int) ($counts['auto_approved'] ?? $data['auto_approved'] ?? 0);
$eligible = (float) ($totals['eligible'] ?? 0);
$lastSynced = $data['last_synced_at'] ?? null;
$gstr2bStatus = (string) ($data['gstr2b_status'] ?? 'pending');

$qLower = strtolower($gst_2b_q);
$shown = [];
foreach ($matches as $m) {
    $st = $m['status'] ?? 'pending';
    if ($gst_2b_filter !== 'all' && $st !== $gst_2b_filter) {
        continue;
    }
    if ($qLower !== '') {
        $hay = strtolower(
            ($m['vendor'] ?? '') . ' ' . ($m['gstin'] ?? '') . ' ' . ($m['invoice_no'] ?? '')
        );
        if (strpos($hay, $qLower) === false) {
            continue;
        }
    }
    $shown[] = $m;
}

if (isset($_GET['export']) && in_array($_GET['export'], ['csv', 'xls'], true)) {
    $ext = $_GET['export'] === 'xls' ? 'xls' : 'csv';
    $sep = $ext === 'xls' ? "\t" : ',';
    $fname = 'gstr2b-' . $gst_period . '.' . $ext;
    header('Content-Type: ' . ($ext === 'xls' ? 'application/vnd.ms-excel' : 'text/csv') . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    $headers = [
        'Vendor', 'GSTIN', 'Invoice no', 'Date', 'Taxable', 'IGST', 'CGST', 'SGST', 'Tax',
        'Status', 'Match', 'Mismatch reason', 'Books GSTIN', 'Portal GSTIN',
        'Books inv', 'Portal inv', 'Books date', 'Portal date', 'Books taxable', 'Portal taxable',
    ];
    if ($ext === 'csv') {
        fputcsv($out, $headers);
    } else {
        fwrite($out, implode($sep, $headers) . "\n");
    }
    foreach ($shown as $m) {
        $row = [
            $m['vendor'] ?? '',
            $m['gstin'] ?? '',
            $m['invoice_no'] ?? '',
            $m['date'] ?? '',
            $m['taxable'] ?? 0,
            $m['igst'] ?? 0,
            $m['cgst'] ?? 0,
            $m['sgst'] ?? 0,
            $m['tax'] ?? 0,
            gst_itc_label($m['status'] ?? 'pending'),
            $m['match_quality'] ?? '',
            $m['mismatch_reason'] ?? '',
            $m['books']['gstin'] ?? '',
            $m['portal']['gstin'] ?? '',
            $m['books']['invoice_no'] ?? '',
            $m['portal']['invoice_no'] ?? '',
            $m['books']['date'] ?? '',
            $m['portal']['date'] ?? '',
            $m['books']['taxable'] ?? '',
            $m['portal']['taxable'] ?? '',
        ];
        if ($ext === 'csv') {
            fputcsv($out, $row);
        } else {
            fwrite($out, implode($sep, $row) . "\n");
        }
    }
    fclose($out);
    exit;
}

$credRaw = ($gst && $gst_db_ok) ? $gst->getCredentials($gst_business_id) : null;
$portal = gst_portal_state(is_array($credRaw) ? $credRaw : []);
$showOtpModal = !empty($_SESSION['gst_otp_prompt']);
if ($showOtpModal) {
    unset($_SESSION['gst_otp_prompt']);
}

function gst_2b_mismatch_td($value, array $keys, $key)
{
    $hit = in_array($key, $keys, true) || in_array($key . '_typo', $keys, true);
    $cls = $hit ? ' table-warning' : '';
    if (in_array('ghost', $keys, true) && in_array($key, ['gstin', 'invoice_no'], true)) {
        $cls = ' table-danger';
    }
    return '<td class="small' . $cls . '">' . gst_h($value === '' || $value === null ? '—' : $value) . '</td>';
}

function gst_2b_quality_badge($q)
{
    $q = strtolower((string) $q);
    $map = [
        'exact' => ['badge-success', 'Exact'],
        'matched' => ['badge-info', 'Matched'],
        'mismatch' => ['badge-warning', 'Mismatch'],
        'typo' => ['badge-warning', 'Typo'],
        'books_only' => ['badge-secondary', 'Books only'],
        'ghost' => ['badge-danger', 'Ghost'],
    ];
    $pair = $map[$q] ?? ['badge-secondary', $q !== '' ? $q : '—'];
    return '<span class="badge ' . $pair[0] . '">' . gst_h($pair[1]) . '</span>';
}

$gst_nav = 'gstr2b';
$gst_page_title = 'GSTR-2B — ITC reconciliation';
$gst_page_lead = '';
$gst_page_full = true;

$gst_page_toolbar = function () use ($gst_2b_extra) {
    ?>
    <a class="btn btn-sm btn-outline-secondary" href="<?php echo gst_h(gst_url('gst-gstr2b.php', array_merge($gst_2b_extra, ['export' => 'csv']))); ?>">CSV</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?php echo gst_h(gst_url('gst-gstr2b.php', array_merge($gst_2b_extra, ['export' => 'xls']))); ?>">Excel</a>
    <a class="btn btn-sm btn-outline-primary" href="<?php echo gst_h(gst_url('gst-carry-forward.php')); ?>">Carry-forward</a>
    <?php
};

$gst_page_extra = function () use (
    $loadErr, $portal, $gstr2bStatus, $lastSynced, $eligible, $totals, $counts,
    $gst_2b_filter, $gst_2b_q, $gst_2b_extra, $shown, $matches, $invoices,
    $ghostQueue, $ghostDismissed, $vendors, $eligibleNotes, $gst_period, $gst_location_id, $gst_locations
) {
    $chip = function ($key, $label, $count) use ($gst_2b_filter, $gst_2b_q) {
        $cls = $gst_2b_filter === $key ? 'btn-primary' : 'btn-outline-secondary';
        $href = gst_url('gst-gstr2b.php', array_filter(['filter' => $key, 'q' => $gst_2b_q]));
        echo '<a class="btn btn-sm ' . $cls . ' mr-1 mb-1" href="' . gst_h($href) . '">'
            . gst_h($label) . ' (' . (int) $count . ')</a>';
    };
    ?>
    <div class="alert mb-3 d-flex justify-content-between align-items-center"
         style="position:sticky;top:0;z-index:1020;background:#e8f1fb;border-color:#7BA3D4;">
        <div>
            <span class="text-muted">Eligible ITC (Approved)</span>
            <strong class="h4 mb-0 ml-2" style="color:#1A3A5C;"><?php echo gst_h(gst_inr($eligible)); ?></strong>
        </div>
        <span class="small text-muted">Only Approved rows reduce GSTR-3B net tax</span>
    </div>

    <?php if ($loadErr !== ''): ?>
        <div class="alert alert-danger"><?php echo gst_h($loadErr); ?></div>
    <?php endif; ?>

    <?php if (!$portal['connected']): ?>
        <div class="alert alert-warning">
            GST portal not connected. Local expenses still reconcile here.
            <a class="alert-link" href="<?php echo gst_h(gst_url('gst-credentials.php')); ?>">Open GST credentials</a>
        </div>
    <?php elseif ($portal['needs_otp']): ?>
        <div class="alert alert-info">
            GST session needs OTP for portal GSTR-2B sync. Local books still load.
            <form method="POST" class="d-inline ml-2">
                <input type="hidden" name="gst_action" value="request_otp">
                <?php gst_ctx_fields(); ?>
                <button type="submit" class="btn btn-sm btn-primary">Request OTP</button>
            </form>
        </div>
    <?php endif; ?>

    <div class="card shadow mb-4">
        <div class="card-body">
            <div class="row align-items-end">
                <div class="col-md-4 mb-2">
                    <label class="small text-muted d-block">Return period</label>
                    <div class="btn-group w-100">
                        <a class="btn btn-outline-secondary" href="<?php echo gst_h(gst_url('gst-gstr2b.php', ['period' => gst_shift_period($gst_period, -1), 'filter' => $gst_2b_filter])); ?>">&lsaquo;</a>
                        <span class="btn btn-light disabled flex-fill"><?php echo gst_h(gst_period_label($gst_period)); ?></span>
                        <a class="btn btn-outline-secondary" href="<?php echo gst_h(gst_url('gst-gstr2b.php', ['period' => gst_shift_period($gst_period, 1), 'filter' => $gst_2b_filter])); ?>">&rsaquo;</a>
                    </div>
                </div>
                <?php if (count($gst_locations) > 0): ?>
                    <div class="col-md-3 mb-2">
                        <form method="GET" action="gst-gstr2b.php">
                            <input type="hidden" name="period" value="<?php echo gst_h($gst_period); ?>">
                            <input type="hidden" name="filter" value="<?php echo gst_h($gst_2b_filter); ?>">
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
                <div class="col-md-5 mb-2 text-md-right">
                    <span class="mr-2"><?php echo gst_status_badge($gstr2bStatus); ?></span>
                    <span class="small text-muted"><?php echo gst_h(gst_format_synced($lastSynced)); ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <?php
        $kpis = [
            ['Approved', $totals['matched'] ?? 0, 'success'],
            ['Pending', $totals['pending'] ?? 0, 'warning'],
            ['Carry', $totals['carry'] ?? 0, 'info'],
            ['Ghost', $totals['ghost'] ?? 0, 'danger'],
        ];
        foreach ($kpis as $k): ?>
            <div class="col-md-3 mb-3">
                <div class="card shadow h-100">
                    <div class="card-body py-3">
                        <div class="small text-muted"><?php echo gst_h($k[0]); ?></div>
                        <div class="h5 mb-0 text-<?php echo gst_h($k[2]); ?>"><?php echo gst_h(gst_inr($k[1])); ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong class="card-title mb-0">ITC statuses</strong>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-toggle="modal" data-target="#gstTipStatus">What do these mean?</button>
        </div>
        <div class="card-body">
            <?php foreach ($eligibleNotes as $note): ?>
                <p class="small mb-2">
                    <span class="badge <?php echo ($note['kind'] ?? '') === 'eligible' ? 'badge-success' : 'badge-secondary'; ?>">
                        <?php echo gst_h($note['title'] ?? ''); ?>
                    </span>
                    <?php echo gst_h($note['body'] ?? ''); ?>
                </p>
            <?php endforeach; ?>
            <?php if (!$eligibleNotes): ?>
                <p class="small text-muted mb-0">Approved ITC is eligible. Pending, carry, ghost, and missing GSTIN are not.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-body py-3">
            <div class="mb-2">
                <?php
                $chip('all', 'All', $counts['all'] ?? 0);
                $chip('pending', 'Pending', $counts['pending'] ?? 0);
                $chip('matched', 'Approved', $counts['matched'] ?? 0);
                $chip('carry', 'Carry', $counts['carry'] ?? 0);
                $chip('ghost', 'Ghost', $counts['ghost'] ?? 0);
                ?>
            </div>
            <?php if ($autoApprovedCount > 0): ?>
                <p class="small text-success mb-2 mb-md-0">
                    <?php echo (int) $autoApprovedCount; ?> row(s) auto-approved from GSTR-2B match
                    (invoice no + GSTIN + date + amounts). Review only Pending.
                </p>
            <?php endif; ?>
            <form method="GET" action="gst-gstr2b.php" class="form-inline">
                <input type="hidden" name="period" value="<?php echo gst_h($gst_period); ?>">
                <input type="hidden" name="filter" value="<?php echo gst_h($gst_2b_filter); ?>">
                <?php if ($gst_location_id > 0): ?>
                    <input type="hidden" name="location_id" value="<?php echo (int) $gst_location_id; ?>">
                <?php endif; ?>
                <input type="search" name="q" class="form-control form-control-sm mr-2 mb-1" style="min-width:240px;"
                       placeholder="Search vendor / GSTIN / invoice"
                       value="<?php echo gst_h($gst_2b_q); ?>">
                <button type="submit" class="btn btn-sm btn-outline-primary mb-1">Search</button>
                <?php if ($gst_2b_q !== ''): ?>
                    <a class="btn btn-sm btn-link mb-1" href="<?php echo gst_h(gst_url('gst-gstr2b.php', ['filter' => $gst_2b_filter])); ?>">Clear</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header">
            <strong class="card-title mb-0">Actions</strong>
        </div>
        <div class="card-body">
            <form method="POST" class="d-inline mr-1 mb-1">
                <input type="hidden" name="gst_action" value="sync_gstr2b">
                <?php gst_ctx_fields(); ?>
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fe fe-refresh-cw"></i> Sync GSTR-2B
                </button>
            </form>
            <form method="POST" class="d-inline mr-1 mb-1" onsubmit="return confirm('Approve all pending ITC under ₹5,000? Larger claims stay pending for manual review.');">
                <input type="hidden" name="gst_action" value="approve_under">
                <?php gst_ctx_fields(); ?>
                <button type="submit" class="btn btn-success btn-sm">Bulk approve under ₹5,000</button>
            </form>
            <form method="POST" class="d-inline mr-1 mb-1" onsubmit="return confirm('Move all pending ITC to carry-forward for a later period?');">
                <input type="hidden" name="gst_action" value="carry_pending">
                <?php gst_ctx_fields(); ?>
                <button type="submit" class="btn btn-outline-info btn-sm">Pending → Carry</button>
            </form>
        </div>
    </div>

    <form method="POST" id="gst2bBulkForm">
        <?php gst_ctx_fields(); ?>
        <input type="hidden" name="gst_action" id="gst2bBulkAction" value="approve_selected">
        <input type="hidden" name="notes" id="gst2bBulkNotes" value="">

        <div class="card shadow mb-4">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
                <strong class="card-title mb-0">Two-way match — Books (expenses) vs GSTR-2B</strong>
                <div class="btn-group btn-group-sm mt-1">
                    <button type="submit" class="btn btn-success" onclick="return gst2bBulk('approve_selected', 'Approve selected rows? Eligible ITC will increase.');">Approve selected</button>
                    <button type="submit" class="btn btn-info" onclick="return gst2bBulk('carry_selected', 'Carry selected rows to a later period?');">Carry selected</button>
                    <button type="submit" class="btn btn-outline-danger" onclick="return gst2bBulkGhost();">Mark ghost</button>
                </div>
            </div>
            <div class="card-body table-responsive">
                <p class="small text-muted">
                    Match keys: GSTIN, invoice number, date, taxable value, tax.
                    Yellow cells are mismatches / typos. Red is a portal credit with no books purchase (ghost).
                </p>
                <?php if (!$shown): ?>
                    <div class="text-center py-5" id="gst2bEmpty">
                        <p class="text-muted mb-3">No ITC rows for this filter.
                            <?php echo count($matches) === 0 ? 'Add GST purchases in expenses, then Sync GSTR-2B.' : 'Try another status chip or clear search.'; ?>
                        </p>
                        <a class="btn btn-outline-primary" href="expense.php">Open expenses</a>
                    </div>
                <?php else: ?>
                    <table class="table table-hover table-sm mb-0">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="gst2bCheckAll" title="Select all"></th>
                                <th>Vendor</th>
                                <th>GSTIN</th>
                                <th>Invoice no</th>
                                <th>Date</th>
                                <th class="text-right">Taxable</th>
                                <th class="text-right">IGST</th>
                                <th class="text-right">CGST</th>
                                <th class="text-right">SGST</th>
                                <th>Status</th>
                                <th>Mismatch</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($shown as $m):
                            $keys = is_array($m['mismatch_keys'] ?? null) ? $m['mismatch_keys'] : [];
                            $st = $m['status'] ?? 'pending';
                            $eid = (string) ($m['id'] ?? '');
                            $ghost = $st === 'ghost';
                            $title = $m['ineligible_note'] ?? ($m['mismatch_reason'] ?? '');
                            ?>
                            <tr title="<?php echo gst_h($title); ?>">
                                <td><input type="checkbox" class="gst2b-row" name="ids[]" value="<?php echo gst_h($eid); ?>"></td>
                                <td>
                                    <div class="font-weight-bold"><?php echo gst_h($m['vendor'] ?: '—'); ?></div>
                                    <div class="small text-muted">
                                        Books <?php echo gst_h($m['books']['invoice_no'] ?? '—'); ?>
                                        <?php if (!empty($m['portal'])): ?>
                                            · 2B <?php echo gst_h($m['portal']['invoice_no'] ?? '—'); ?>
                                        <?php else: ?>
                                            · no 2B row
                                        <?php endif; ?>
                                        · <?php echo gst_2b_quality_badge($m['match_quality'] ?? ''); ?>
                                    </div>
                                </td>
                                <?php echo gst_2b_mismatch_td($m['gstin'] ?? '', $keys, 'gstin'); ?>
                                <?php echo gst_2b_mismatch_td($m['invoice_no'] ?? '', $keys, 'invoice_no'); ?>
                                <?php echo gst_2b_mismatch_td($m['date'] ?? '', $keys, 'date'); ?>
                                <?php echo gst_2b_mismatch_td(isset($m['taxable']) ? gst_inr($m['taxable']) : '—', $keys, 'taxable'); ?>
                                <td class="text-right small"><?php echo gst_h(gst_inr($m['igst'] ?? 0)); ?></td>
                                <td class="text-right small"><?php echo gst_h(gst_inr($m['cgst'] ?? 0)); ?></td>
                                <td class="text-right small"><?php echo gst_h(gst_inr($m['sgst'] ?? 0)); ?></td>
                                <td><?php echo gst_itc_badge($st, $m['notes'] ?? ''); ?></td>
                                <td class="small" style="max-width:220px;">
                                    <?php echo gst_h($m['mismatch_reason'] ?: '—'); ?>
                                </td>
                                <td class="text-nowrap">
                                    <?php if ($ghost): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger gst-dismiss-ghost"
                                                data-id="<?php echo gst_h($eid); ?>">Dismiss</button>
                                    <?php elseif ($st === 'matched'): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                onclick="return gst2bRow(this, '<?php echo gst_h($eid); ?>', 'pending');">Reject</button>
                                        <button type="button" class="btn btn-sm btn-outline-info"
                                                onclick="return gst2bRow(this, '<?php echo gst_h($eid); ?>', 'carry');">Carry</button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-success"
                                                onclick="return gst2bRow(this, '<?php echo gst_h($eid); ?>', 'matched');">Approve</button>
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                onclick="return gst2bRow(this, '<?php echo gst_h($eid); ?>', 'pending');">Reject</button>
                                        <button type="button" class="btn btn-sm btn-outline-info"
                                                onclick="return gst2bRow(this, '<?php echo gst_h($eid); ?>', 'carry');">Carry</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="small text-muted mt-2 mb-0">
                        Row actions are the desktop equivalent of swipe: Approve (right) / Reject keep-pending (left) / Carry / Dismiss ghost.
                        Showing <?php echo count($shown); ?> of <?php echo count($matches); ?> matched rows
                        (<?php echo count($invoices); ?> handshake invoices).
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <div class="card shadow mb-4">
        <div class="card-header">
            <strong class="card-title mb-0">Ghost invoice review queue</strong>
        </div>
        <div class="card-body">
            <p class="small text-muted">Portal credits with no matching purchase. Dismiss with a reason so they never enter eligible ITC.</p>
            <?php if (!$ghostQueue): ?>
                <p class="text-muted mb-0">No unmatched portal credits in this period.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Vendor</th>
                                <th>GSTIN</th>
                                <th>Invoice</th>
                                <th>Date</th>
                                <th class="text-right">Taxable</th>
                                <th class="text-right">ITC</th>
                                <th>Reason</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($ghostQueue as $g): ?>
                            <tr class="table-danger">
                                <td><?php echo gst_h($g['vendor'] ?? ''); ?></td>
                                <td><?php echo gst_h($g['gstin'] ?? ''); ?></td>
                                <td><?php echo gst_h($g['invoice_no'] ?? ''); ?></td>
                                <td><?php echo gst_h($g['date'] ?? ''); ?></td>
                                <td class="text-right"><?php echo gst_h(gst_inr($g['taxable'] ?? 0)); ?></td>
                                <td class="text-right"><?php echo gst_h(gst_inr($g['tax'] ?? 0)); ?></td>
                                <td class="small"><?php echo gst_h($g['mismatch_reason'] ?? 'No books match'); ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-danger gst-dismiss-ghost"
                                            data-id="<?php echo gst_h($g['id'] ?? ''); ?>">Dismiss</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php if ($ghostDismissed): ?>
                <h6 class="mt-4">Dismissed this period</h6>
                <ul class="small mb-0">
                    <?php foreach ($ghostDismissed as $d): ?>
                        <li><?php echo gst_h(($d['vendor'] ?? '') . ' · ' . ($d['gstin'] ?? '') . ' · ' . ($d['reason'] ?? '')); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header">
            <strong class="card-title mb-0">Vendor-wise ITC summary</strong>
        </div>
        <div class="card-body table-responsive">
            <?php if (!$vendors): ?>
                <p class="text-muted mb-0">No vendor totals yet.</p>
            <?php else: ?>
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Vendor</th>
                            <th>GSTIN</th>
                            <th class="text-right">Rows</th>
                            <th class="text-right">Claimed</th>
                            <th class="text-right">Eligible</th>
                            <th class="text-right">Pending</th>
                            <th class="text-right">Carry</th>
                            <th class="text-right">Ghost</th>
                            <th class="text-right">Mismatches</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($vendors as $v): ?>
                        <tr>
                            <td><?php echo gst_h($v['vendor'] ?? ''); ?></td>
                            <td><?php echo gst_h($v['gstin'] ?: '—'); ?></td>
                            <td class="text-right"><?php echo (int) ($v['rows'] ?? 0); ?></td>
                            <td class="text-right"><?php echo gst_h(gst_inr($v['claimed'] ?? 0)); ?></td>
                            <td class="text-right text-success"><?php echo gst_h(gst_inr($v['eligible'] ?? 0)); ?></td>
                            <td class="text-right"><?php echo gst_h(gst_inr($v['pending'] ?? 0)); ?></td>
                            <td class="text-right"><?php echo gst_h(gst_inr($v['carry'] ?? 0)); ?></td>
                            <td class="text-right text-danger"><?php echo gst_h(gst_inr($v['ghost'] ?? 0)); ?></td>
                            <td class="text-right"><?php echo (int) ($v['mismatches'] ?? 0); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php
};

$gst_page_scripts = function () use ($showOtpModal, $gst_period, $gst_location_id) {
    ?>
    <form method="POST" id="gst2bRowForm" class="d-none">
        <input type="hidden" name="gst_action" value="itc_status">
        <?php gst_ctx_fields(); ?>
        <input type="hidden" name="expense_id" id="gst2bRowId" value="">
        <input type="hidden" name="status" id="gst2bRowStatus" value="">
        <input type="hidden" name="notes" id="gst2bRowNotes" value="">
    </form>

    <div class="modal fade" id="gstTipStatus" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">ITC statuses</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <p><strong>Approved (Matched)</strong> — Vendor handshake is treated as present in GSTR-2B. Only this ITC reduces net tax.</p>
                    <p><strong>Pending</strong> — Claimed from expenses, not confirmed yet. Stays out of eligible ITC.</p>
                    <p><strong>Carry-forward</strong> — Not approved this period; available later when the vendor files late.</p>
                    <p class="mb-0"><strong>Ghost</strong> — Credits under your GSTIN with no matching purchase (typo, wrong GSTIN, or fraud). Dismiss so they never enter eligible ITC.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="gstGhostModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Dismiss ghost credit</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <label class="small text-muted">Reason</label>
                    <textarea id="gstGhostReason" class="form-control" rows="3" placeholder="Wrong GSTIN / no purchase / duplicate / other"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="gstGhostConfirm">Dismiss</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="gstOtpModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <form method="POST" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">GST portal OTP</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="gst_action" value="verify_otp">
                    <?php gst_ctx_fields(); ?>
                    <p class="small text-muted">Enter the OTP sent to the GST-registered mobile / email. Portal API keys are never shown here.</p>
                    <input type="text" name="otp" class="form-control" inputmode="numeric" maxlength="10" required placeholder="6-digit OTP">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function gst2bBulk(action, msg) {
        var n = document.querySelectorAll('.gst2b-row:checked').length;
        if (!n) { alert('Select at least one row.'); return false; }
        if (!confirm(msg)) return false;
        document.getElementById('gst2bBulkAction').value = action;
        return true;
    }
    function gst2bBulkGhost() {
        var n = document.querySelectorAll('.gst2b-row:checked').length;
        if (!n) { alert('Select at least one row.'); return false; }
        var reason = prompt('Reason for marking selected as ghost / dismiss:', 'No matching purchase');
        if (reason === null) return false;
        document.getElementById('gst2bBulkNotes').value = reason;
        document.getElementById('gst2bBulkAction').value = 'mark_ghost';
        return true;
    }
    function gst2bRow(btn, id, status) {
        document.getElementById('gst2bRowId').value = id;
        document.getElementById('gst2bRowStatus').value = status;
        document.getElementById('gst2bRowForm').submit();
        return false;
    }
    (function () {
        var all = document.getElementById('gst2bCheckAll');
        if (all) {
            all.addEventListener('change', function () {
                document.querySelectorAll('.gst2b-row').forEach(function (c) { c.checked = all.checked; });
            });
        }
        var pendingId = '';
        document.querySelectorAll('.gst-dismiss-ghost').forEach(function (btn) {
            btn.addEventListener('click', function () {
                pendingId = btn.getAttribute('data-id') || '';
                document.getElementById('gstGhostReason').value = '';
                $('#gstGhostModal').modal('show');
            });
        });
        var ok = document.getElementById('gstGhostConfirm');
        if (ok) {
            ok.addEventListener('click', function () {
                var reason = (document.getElementById('gstGhostReason').value || '').trim();
                if (!reason) { alert('Please enter a dismiss reason.'); return; }
                document.getElementById('gst2bRowId').value = pendingId;
                document.getElementById('gst2bRowStatus').value = 'ghost';
                document.getElementById('gst2bRowNotes').value = reason;
                document.getElementById('gst2bRowForm').submit();
            });
        }
        <?php if ($showOtpModal): ?>
        $('#gstOtpModal').modal('show');
        <?php endif; ?>
    })();
    </script>
    <?php
};

require __DIR__ . '/includes/gst-page-shell.php';

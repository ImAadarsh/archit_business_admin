<?php
/**
 * ITC carry-forward — outstanding pending/carry for the period plus history.
 * Perione client secrets stay on the server. Never rendered here.
 */
require_once __DIR__ . '/includes/gst-init.php';

if ($gst_business_id <= 0) {
    header('Location: index.php');
    exit;
}

$gst_action = (string) ($_POST['gst_action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $gst && $gst_db_ok && $gst_action !== '') {
    $ctx = [
        'business_id' => $gst_business_id,
        'period' => $gst_period,
        'location_id' => $gst_location_id ?: null,
    ];

    if ($gst_action === 'itc_status') {
        $eid = (string) ($_POST['expense_id'] ?? '');
        $status = strtolower(trim((string) ($_POST['status'] ?? '')));
        $res = $gst->reconcileItc(array_merge($ctx, [
            'expense_id' => $eid,
            'status' => $status,
        ]));
        $ok = ($res['status'] ?? '') === 'success';
        if ($ok && $status === 'matched') {
            gst_flash(true, 'Approved — this ITC now counts as eligible.');
        } elseif ($ok && $status === 'carry') {
            gst_flash(true, 'Kept as carry-forward.');
        } else {
            gst_flash($ok, $ok ? 'ITC updated.' : (string) ($res['message'] ?? 'Could not update ITC.'));
        }
        gst_redirect('gst-carry-forward.php');
    }

    if ($gst_action === 'carry_pending') {
        $res = $gst->reconcileItc(array_merge($ctx, ['bulk_action' => 'carry_pending']));
        $ok = ($res['status'] ?? '') === 'success';
        $n = (int) ($res['data']['updated'] ?? 0);
        gst_flash($ok, $ok
            ? ($n . ' pending row(s) marked carry-forward.')
            : (string) ($res['message'] ?? 'Could not carry pending ITC.'));
        gst_redirect('gst-carry-forward.php');
    }

    if ($gst_action === 'approve_selected' || $gst_action === 'carry_selected') {
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : [];
        if (!$ids) {
            gst_flash(false, 'Select at least one row.');
            gst_redirect('gst-carry-forward.php');
        }
        $res = $gst->reconcileItc(array_merge($ctx, [
            'bulk_action' => $gst_action,
            'ids' => $ids,
        ]));
        $ok = ($res['status'] ?? '') === 'success';
        $n = (int) ($res['data']['updated'] ?? 0);
        gst_flash($ok, $ok
            ? ($n . ' row(s) updated.')
            : (string) ($res['message'] ?? 'Bulk action failed.'));
        gst_redirect('gst-carry-forward.php');
    }
}

$data = [];
$loadErr = '';
if ($gst && $gst_db_ok) {
    $res = $gst->getCarryForward([
        'business_id' => $gst_business_id,
        'period' => $gst_period,
        'location_id' => $gst_location_id ?: null,
    ]);
    if (($res['status'] ?? '') === 'success' && is_array($res['data'] ?? null)) {
        $data = $res['data'];
    } else {
        $loadErr = (string) ($res['message'] ?? 'Could not load carry-forward.');
    }
} else {
    $loadErr = 'Database connection failed. Cannot load carry-forward.';
}

$rows = is_array($data['invoices'] ?? null) ? $data['invoices'] : [];
$stored = is_array($data['stored'] ?? null) ? $data['stored'] : [];
$history = is_array($data['history'] ?? null) ? $data['history'] : [];
$totals = is_array($data['totals'] ?? null) ? $data['totals'] : [];
$outstanding = (float) ($data['total'] ?? ($totals['outstanding'] ?? 0));
$pendingAmt = (float) ($totals['pending'] ?? 0);
$carryAmt = (float) ($totals['carry'] ?? 0);
$count = (int) ($data['count'] ?? count($rows));
$q = trim((string) ($_GET['q'] ?? ''));
$qLower = strtolower($q);
if ($qLower !== '') {
    $rows = array_values(array_filter($rows, function ($r) use ($qLower) {
        $hay = strtolower(($r['paid_to'] ?? '') . ' ' . ($r['name'] ?? '') . ' ' . ($r['gstin'] ?? ''));
        return strpos($hay, $qLower) !== false;
    }));
}

if (isset($_GET['export']) && in_array($_GET['export'], ['csv', 'xls'], true)) {
    $ext = $_GET['export'] === 'xls' ? 'xls' : 'csv';
    $sep = $ext === 'xls' ? "\t" : ',';
    $fname = 'gst-carry-forward-' . $gst_period . '.' . $ext;
    header('Content-Type: ' . ($ext === 'xls' ? 'application/vnd.ms-excel' : 'text/csv') . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    $headers = ['Vendor', 'GSTIN', 'Date', 'Invoice / expense', 'Taxable', 'ITC', 'Status'];
    if ($ext === 'csv') {
        fputcsv($out, $headers);
    } else {
        fwrite($out, implode($sep, $headers) . "\n");
    }
    foreach ($rows as $r) {
        $line = [
            $r['paid_to'] ?: ($r['name'] ?? ''),
            $r['gstin'] ?? '',
            $r['date'] ?? '',
            $r['invoice_no'] ?? ($r['id'] ?? ''),
            $r['taxable'] ?? 0,
            $r['gstClaimed'] ?? $r['gst_claimed'] ?? 0,
            gst_itc_label($r['status'] ?? 'pending'),
        ];
        if ($ext === 'csv') {
            fputcsv($out, $line);
        } else {
            fwrite($out, implode($sep, $line) . "\n");
        }
    }
    fclose($out);
    exit;
}

$gst_nav = 'carry';
$gst_page_title = 'ITC carry-forward';
$gst_page_lead = '';
$gst_page_full = true;

$gst_page_toolbar = function () {
    ?>
    <a class="btn btn-sm btn-outline-secondary" href="<?php echo gst_h(gst_url('gst-carry-forward.php', ['export' => 'csv'])); ?>">CSV</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?php echo gst_h(gst_url('gst-carry-forward.php', ['export' => 'xls'])); ?>">Excel</a>
    <a class="btn btn-sm btn-outline-primary" href="<?php echo gst_h(gst_url('gst-gstr2b.php')); ?>">GSTR-2B</a>
    <?php
};

$gst_page_extra = function () use (
    $loadErr, $rows, $stored, $history, $outstanding, $pendingAmt, $carryAmt, $count,
    $q, $gst_period, $gst_location_id, $gst_locations, $data
) {
    ?>
    <?php if ($loadErr !== ''): ?>
        <div class="alert alert-danger"><?php echo gst_h($loadErr); ?></div>
    <?php endif; ?>

    <div class="card shadow mb-4">
        <div class="card-body">
            <p class="mb-3">
                Carry-forward for <strong><?php echo gst_h($data['period_label'] ?? gst_period_label($gst_period)); ?></strong>
                — pending vendor handshake or explicitly marked Carry.
            </p>
            <div class="row align-items-end">
                <div class="col-md-4 mb-2">
                    <label class="small text-muted d-block">Return period</label>
                    <div class="btn-group w-100">
                        <a class="btn btn-outline-secondary" href="<?php echo gst_h(gst_url('gst-carry-forward.php', ['period' => gst_shift_period($gst_period, -1)])); ?>">&lsaquo;</a>
                        <span class="btn btn-light disabled flex-fill"><?php echo gst_h(gst_period_label($gst_period)); ?></span>
                        <a class="btn btn-outline-secondary" href="<?php echo gst_h(gst_url('gst-carry-forward.php', ['period' => gst_shift_period($gst_period, 1)])); ?>">&rsaquo;</a>
                    </div>
                </div>
                <?php if (count($gst_locations) > 0): ?>
                    <div class="col-md-3 mb-2">
                        <form method="GET" action="gst-carry-forward.php">
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
                <div class="col-md-5 mb-2">
                    <form method="GET" action="gst-carry-forward.php" class="form-inline">
                        <input type="hidden" name="period" value="<?php echo gst_h($gst_period); ?>">
                        <?php if ($gst_location_id > 0): ?>
                            <input type="hidden" name="location_id" value="<?php echo (int) $gst_location_id; ?>">
                        <?php endif; ?>
                        <input type="search" name="q" class="form-control mr-2" placeholder="Vendor / GSTIN"
                               value="<?php echo gst_h($q); ?>">
                        <button class="btn btn-outline-primary" type="submit">Search</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="alert mb-4 d-flex justify-content-between align-items-center"
         style="position:sticky;top:0;z-index:1020;background:#e8f1fb;border-color:#7BA3D4;">
        <div>
            <span class="text-muted">Outstanding carry / pending</span>
            <strong class="h4 mb-0 ml-2" style="color:#1A3A5C;"><?php echo gst_h(gst_inr($outstanding)); ?></strong>
            <span class="small text-muted ml-2"><?php echo (int) $count; ?> row<?php echo $count === 1 ? '' : 's'; ?></span>
        </div>
        <form method="POST" class="mb-0" onsubmit="return confirm('Mark all pending ITC as carry-forward?');">
            <input type="hidden" name="gst_action" value="carry_pending">
            <?php gst_ctx_fields(); ?>
            <button type="submit" class="btn btn-sm btn-outline-info">Pending → Carry</button>
        </form>
    </div>

    <div class="row">
        <div class="col-md-4 mb-3">
            <div class="card shadow h-100">
                <div class="card-body">
                    <div class="small text-muted">Pending</div>
                    <div class="h5 mb-0 text-warning"><?php echo gst_h(gst_inr($pendingAmt)); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card shadow h-100">
                <div class="card-body">
                    <div class="small text-muted">Marked carry</div>
                    <div class="h5 mb-0 text-info"><?php echo gst_h(gst_inr($carryAmt)); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card shadow h-100">
                <div class="card-body">
                    <div class="small text-muted">Ledger rows stored</div>
                    <div class="h5 mb-0"><?php echo count($stored); ?></div>
                </div>
            </div>
        </div>
    </div>

    <form method="POST">
        <?php gst_ctx_fields(); ?>
        <div class="card shadow mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong class="card-title mb-0">Carry / pending list</strong>
                <div>
                    <button type="submit" name="gst_action" value="approve_selected" class="btn btn-sm btn-success"
                            onclick="return confirm('Approve selected into eligible ITC?');">Approve selected</button>
                    <button type="submit" name="gst_action" value="carry_selected" class="btn btn-sm btn-info"
                            onclick="return confirm('Mark selected as carry-forward?');">Carry selected</button>
                </div>
            </div>
            <div class="card-body table-responsive">
                <?php if (!$rows): ?>
                    <div class="text-center py-5">
                        <p class="text-muted mb-3">No pending or carried ITC for this period.</p>
                        <a class="btn btn-outline-primary" href="<?php echo gst_h(gst_url('gst-gstr2b.php')); ?>">Open GSTR-2B</a>
                        <a class="btn btn-outline-secondary" href="expense.php">Open expenses</a>
                    </div>
                <?php else: ?>
                    <table class="table table-hover table-sm mb-0">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="gstCarryCheckAll"></th>
                                <th>Vendor</th>
                                <th>GSTIN</th>
                                <th>Date</th>
                                <th>Invoice</th>
                                <th class="text-right">Taxable</th>
                                <th class="text-right">ITC</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $r):
                            $eid = (string) ($r['id'] ?? '');
                            $name = $r['paid_to'] ?: ($r['name'] ?? ('Expense #' . $eid));
                            $claimed = (float) ($r['gstClaimed'] ?? $r['gst_claimed'] ?? 0);
                            ?>
                            <tr>
                                <td><input type="checkbox" class="gst-carry-row" name="ids[]" value="<?php echo gst_h($eid); ?>"></td>
                                <td class="font-weight-bold"><?php echo gst_h($name); ?></td>
                                <td><?php echo gst_h($r['gstin'] ?: '—'); ?></td>
                                <td><?php echo gst_h($r['date'] ?? ''); ?></td>
                                <td><?php echo gst_h($r['invoice_no'] ?? $eid); ?></td>
                                <td class="text-right"><?php echo gst_h(gst_inr($r['taxable'] ?? 0)); ?></td>
                                <td class="text-right"><?php echo gst_h(gst_inr($claimed)); ?></td>
                                <td><?php echo gst_itc_badge($r['status'] ?? 'pending'); ?></td>
                                <td class="text-nowrap">
                                    <button type="submit" class="btn btn-sm btn-success" name="gst_action" value="itc_status"
                                            formaction="" onclick="this.form.expense_id.value='<?php echo gst_h($eid); ?>'; this.form.status.value='matched';">Approve</button>
                                    <button type="submit" class="btn btn-sm btn-outline-info" name="gst_action" value="itc_status"
                                            onclick="this.form.expense_id.value='<?php echo gst_h($eid); ?>'; this.form.status.value='carry';">Carry</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <input type="hidden" name="expense_id" value="">
                    <input type="hidden" name="status" value="">
                <?php endif; ?>
            </div>
        </div>
    </form>

    <div class="card shadow mb-4">
        <div class="card-header">
            <strong class="card-title mb-0">Carry-forward history by period</strong>
        </div>
        <div class="card-body table-responsive">
            <p class="small text-muted">Claimed = books ITC (non-ghost). Approved = eligible this period. Remainder = pending + carry still outstanding.</p>
            <?php if (!$history): ?>
                <p class="text-muted mb-0">No handshake history yet. Reconcile ITC on GSTR-2B first.</p>
            <?php else: ?>
                <table class="table table-hover table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Period</th>
                            <th class="text-right">Claimed</th>
                            <th class="text-right">Approved</th>
                            <th class="text-right">Pending</th>
                            <th class="text-right">Carry</th>
                            <th class="text-right">Remainder</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($history as $h): ?>
                        <tr<?php echo ($h['period'] ?? '') === $gst_period ? ' class="table-active"' : ''; ?>>
                            <td><?php echo gst_h($h['period_label'] ?? $h['period']); ?></td>
                            <td class="text-right"><?php echo gst_h(gst_inr($h['claimed'] ?? 0)); ?></td>
                            <td class="text-right text-success"><?php echo gst_h(gst_inr($h['approved'] ?? 0)); ?></td>
                            <td class="text-right"><?php echo gst_h(gst_inr($h['pending'] ?? 0)); ?></td>
                            <td class="text-right"><?php echo gst_h(gst_inr($h['carry'] ?? 0)); ?></td>
                            <td class="text-right font-weight-bold"><?php echo gst_h(gst_inr($h['remainder'] ?? 0)); ?></td>
                            <td>
                                <a href="<?php echo gst_h(gst_url('gst-carry-forward.php', ['period' => $h['period']])); ?>">Open</a>
                                ·
                                <a href="<?php echo gst_h(gst_url('gst-gstr2b.php', ['period' => $h['period']])); ?>">2B</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($stored): ?>
        <div class="card shadow mb-4">
            <div class="card-header">
                <strong class="card-title mb-0">Stored carry ledger (open)</strong>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>From</th>
                            <th>To</th>
                            <th>Vendor</th>
                            <th>GSTIN</th>
                            <th class="text-right">ITC</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($stored as $s): ?>
                        <tr>
                            <td><?php echo gst_h($s['from_period'] ?? ''); ?></td>
                            <td><?php echo gst_h($s['to_period'] ?: '—'); ?></td>
                            <td><?php echo gst_h($s['vendor_name'] ?? ''); ?></td>
                            <td><?php echo gst_h($s['vendor_gstin'] ?? ''); ?></td>
                            <td class="text-right"><?php echo gst_h(gst_inr($s['itc_amount'] ?? 0)); ?></td>
                            <td><?php echo gst_status_badge($s['status'] ?? 'open'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
    <?php
};

$gst_page_scripts = function () {
    ?>
    <script>
    (function () {
        var all = document.getElementById('gstCarryCheckAll');
        if (all) {
            all.addEventListener('change', function () {
                document.querySelectorAll('.gst-carry-row').forEach(function (c) { c.checked = all.checked; });
            });
        }
    })();
    </script>
    <?php
};

require __DIR__ . '/includes/gst-page-shell.php';

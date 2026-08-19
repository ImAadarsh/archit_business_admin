<?php
/**
 * GSTR-1 (sales) admin — detailed outward-supplies UI.
 * Placeholder replacement; reuses GST hub chrome (gst-init / gst-subnav / gst-page-shell).
 */
require_once __DIR__ . '/includes/gst-init.php';

if ($gst_business_id <= 0) {
    header('Location: index.php');
    exit;
}

if (!function_exists('gst_gstr1_type_badge')) {
    function gst_gstr1_type_badge($type)
    {
        $t = strtoupper(trim((string) $type));
        $map = [
            'B2B' => 'badge-primary',
            'B2CL' => 'badge-info',
            'B2CS' => 'badge-secondary',
            'EXP' => 'badge-dark',
            'CDNR' => 'badge-warning',
            'CDNUR' => 'badge-warning',
        ];
        $cls = $map[$t] ?? 'badge-light';
        return '<span class="badge ' . $cls . '">' . gst_h($t !== '' ? $t : '—') . '</span>';
    }

    function gst_gstr1_money($n)
    {
        return number_format((float) $n, 2);
    }
}

if (isset($_GET['export']) && $_GET['export'] === 'csv' && $gst) {
    $res = $gst->exportGstr1([
        'business_id' => $gst_business_id,
        'period' => $gst_period,
        'location_id' => $gst_location_id ?: null,
    ]);
    if (($res['status'] ?? '') !== 'success') {
        gst_flash(false, (string) ($res['message'] ?? 'Export failed.'));
        header('Location: ' . gst_url('gst-gstr1.php'));
        exit;
    }
    $fname = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($res['data']['filename'] ?? ('GSTR1_' . $gst_period . '.csv')));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    foreach ($res['data']['csv_rows'] ?? [] as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

$gstr1 = [];
$loadErr = '';
if ($gst && $gst_db_ok) {
    $res = $gst->getGstr1([
        'business_id' => $gst_business_id,
        'period' => $gst_period,
        'location_id' => $gst_location_id ?: null,
    ]);
    if (($res['status'] ?? '') === 'success' && is_array($res['data'] ?? null)) {
        $gstr1 = $res['data'];
    } else {
        $loadErr = (string) ($res['message'] ?? 'Could not load GSTR-1 invoices.');
    }
} else {
    $loadErr = 'Database connection failed. Cannot load GSTR-1 data.';
}

$invoices = is_array($gstr1['invoices'] ?? null) ? $gstr1['invoices'] : [];
$totals = is_array($gstr1['totals'] ?? null) ? $gstr1['totals'] : [];
$sections = is_array($gstr1['sections'] ?? null) ? $gstr1['sections'] : [];
$hsnRows = is_array($gstr1['hsn'] ?? null) ? $gstr1['hsn'] : [];
$docsIssued = is_array($gstr1['docs_issued'] ?? null) ? $gstr1['docs_issued'] : [];
$payload = is_array($gstr1['payload'] ?? null) ? $gstr1['payload'] : new stdClass();
$gstr1Status = (string) ($gstr1['gstr1_status'] ?? 'pending');
$gstr1Ref = (string) ($gstr1['gstr1_ref_id'] ?? '');
$gstr1FiledAt = (string) ($gstr1['gstr1_filed_at'] ?? '');
$lastSynced = $gstr1['last_synced_at'] ?? null;
$authRequired = !empty($gstr1['auth_required']);
$excludedCount = (int) ($gstr1['excluded_count'] ?? 0);

$credRaw = ($gst && $gst_db_ok) ? $gst->getCredentials($gst_business_id) : null;
$portal = gst_portal_state(is_array($credRaw) ? $credRaw : []);
$gst_cred_public = gst_public_credentials($credRaw) ?: [];

$currentPeriod = date('Y-m');
$lastPeriod = gst_shift_period($currentPeriod, -1);
$shareText = 'GSTR-1 ' . gst_period_label($gst_period)
    . "\nInvoices: " . (int) ($totals['count'] ?? count($invoices))
    . "\nTaxable: " . gst_inr($totals['taxable'] ?? 0)
    . "\nGST: " . gst_inr($totals['gst'] ?? 0)
    . "\nTotal: " . gst_inr($totals['total'] ?? 0);

$gst_nav = 'gstr1';
$gst_page_title = 'GSTR-1 · Sales Records';
$gst_page_lead = '';
$gst_page_actions = '<span class="badge badge-info mr-2">STEP 1</span>'
    . gst_status_badge($gstr1Status)
    . ' <button type="button" class="btn btn-outline-secondary btn-sm ml-2" id="gstr1RefreshBtn"><i class="fe fe-refresh-cw"></i> Refresh</button>'
    . ' <a class="btn btn-outline-secondary btn-sm" href="' . gst_h(gst_url('gst-gstr1.php', ['export' => 'csv'])) . '"><i class="fe fe-download"></i> Excel / CSV</a>'
    . ' <button type="button" class="btn btn-outline-primary btn-sm" id="gstr1ShareBtn"><i class="fe fe-share-2"></i> Share</button>';

$gstJs = [
    'businessId' => $gst_business_id,
    'period' => $gst_period,
    'locationId' => $gst_location_id,
    'apiBase' => 'api/gst/',
    'invoicesUrl' => 'invoices.php',
    'shareText' => $shareText,
    'data' => [
        'invoices' => $invoices,
        'totals' => $totals,
        'sections' => $sections,
        'hsn' => $hsnRows,
        'docs_issued' => $docsIssued,
        'payload' => $payload,
        'gstr1_status' => $gstr1Status,
        'gstr1_ref_id' => $gstr1Ref,
        'gstr1_filed_at' => $gstr1FiledAt,
        'last_synced_at' => $lastSynced,
        'auth_required' => $authRequired,
        'excluded_count' => $excludedCount,
        'period_label' => gst_period_label($gst_period),
        'gstin' => $gst_cred_public['gstin'] ?? ($gstr1['gstin'] ?? ''),
        'source' => $gstr1['source'] ?? 'local',
    ],
];

$gst_page_body = function () use (
    $gst_period,
    $gst_location_id,
    $gst_locations,
    $currentPeriod,
    $lastPeriod,
    $invoices,
    $totals,
    $sections,
    $hsnRows,
    $docsIssued,
    $payload,
    $gstr1Status,
    $gstr1Ref,
    $lastSynced,
    $excludedCount,
    $loadErr,
    $portal,
    $gst_cred_public
) {
    $sectionCount = function ($key) use ($sections, $invoices) {
        if (isset($sections[$key]['count'])) {
            return (int) $sections[$key]['count'];
        }
        $n = 0;
        foreach ($invoices as $r) {
            $t = $r['invoice_type'] ?? $r['supply_type'] ?? '';
            if ($t === $key) {
                $n++;
            }
        }
        return $n;
    };
    ?>
                        <?php if ($loadErr !== ''): ?>
                            <div class="alert alert-danger"><?php echo gst_h($loadErr); ?> Local invoice data is used when the portal is unavailable.</div>
                        <?php endif; ?>

                        <?php if (!$portal['connected']): ?>
                            <div class="alert alert-warning">
                                <strong>GST portal not connected.</strong>
                                Local sales still appear here. Connect GSTIN to push or file.
                                <a class="alert-link" href="<?php echo gst_h(gst_url('gst-credentials.php')); ?>">Open GST credentials</a>
                            </div>
                        <?php elseif ($portal['needs_otp'] || $portal['authenticated'] === false): ?>
                            <div class="alert alert-info">
                                <strong>OTP needed for portal actions.</strong>
                                Prepare (local snapshot) works without OTP. Push to portal and File need a GST session.
                                <button type="button" class="btn btn-sm btn-primary ml-2" id="gstr1RequestOtpBtn">Request GST OTP</button>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success">
                                GST portal connected<?php echo !empty($portal['token_expiry']) ? ' until ' . gst_h(date('j M Y, g:i A', strtotime($portal['token_expiry']))) : ''; ?>.
                                GSTIN <strong><?php echo gst_h($gst_cred_public['gstin'] ?? ''); ?></strong>
                            </div>
                        <?php endif; ?>

                        <div id="gstr1Flash" class="d-none"></div>
                        <div id="gstr1Errors" class="d-none alert alert-danger">
                            <strong>GSTN validation errors</strong>
                            <ul class="mb-0 mt-2" id="gstr1ErrorsList"></ul>
                        </div>

                        <div class="card shadow mb-3">
                            <div class="card-body">
                                <div class="row align-items-end">
                                    <div class="col-md-4 mb-2">
                                        <label class="small text-muted d-block">Return period</label>
                                        <div class="btn-group w-100">
                                            <a class="btn btn-outline-secondary" href="<?php echo gst_h(gst_url('gst-gstr1.php', ['period' => gst_shift_period($gst_period, -1)])); ?>">&lsaquo;</a>
                                            <span class="btn btn-light disabled flex-fill"><?php echo gst_h(gst_period_label($gst_period)); ?> <span class="text-muted">(<?php echo gst_h($gst_period); ?>)</span></span>
                                            <a class="btn btn-outline-secondary" href="<?php echo gst_h(gst_url('gst-gstr1.php', ['period' => gst_shift_period($gst_period, 1)])); ?>">&rsaquo;</a>
                                        </div>
                                    </div>
                                    <div class="col-md-5 mb-2">
                                        <label class="small text-muted d-block">Presets</label>
                                        <a class="btn btn-sm <?php echo $gst_period === $currentPeriod ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo gst_h(gst_url('gst-gstr1.php', ['period' => $currentPeriod])); ?>">This month</a>
                                        <a class="btn btn-sm <?php echo $gst_period === $lastPeriod ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo gst_h(gst_url('gst-gstr1.php', ['period' => $lastPeriod])); ?>">Last month</a>
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <form method="GET" action="gst-gstr1.php">
                                            <input type="hidden" name="period" value="<?php echo gst_h($gst_period); ?>">
                                            <label class="small text-muted d-block" for="gstr1_location_id">Location</label>
                                            <select name="location_id" id="gstr1_location_id" class="form-control form-control-sm" onchange="this.form.submit()">
                                                <option value="">All locations</option>
                                                <?php foreach ($gst_locations as $loc): ?>
                                                    <option value="<?php echo (int) $loc['id']; ?>" <?php echo $gst_location_id === (int) $loc['id'] ? 'selected' : ''; ?>>
                                                        <?php echo gst_h($loc['location_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    </div>
                                </div>
                                <div class="mt-2 small">
                                    <strong class="text-warning"><?php echo gst_h(gst_deadline_hint($gst_period)); ?></strong>
                                    <span class="text-muted ml-3" id="gstr1SyncedLabel"><?php echo gst_h(gst_format_synced($lastSynced)); ?></span>
                                    <span class="text-muted ml-3">Status <?php echo gst_status_badge($gstr1Status); ?></span>
                                    <?php if ($gstr1Ref !== ''): ?>
                                        <span class="text-muted ml-2">ARN / Ref <code id="gstr1RefLabel"><?php echo gst_h($gstr1Ref); ?></code></span>
                                    <?php else: ?>
                                        <span class="text-muted ml-2">ARN / Ref <code id="gstr1RefLabel">—</code></span>
                                    <?php endif; ?>
                                    <?php if ($excludedCount > 0): ?>
                                        <span class="badge badge-secondary ml-2"><?php echo (int) $excludedCount; ?> excluded</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="card shadow mb-3">
                            <div class="card-body py-3">
                                <div class="d-flex flex-wrap align-items-end">
                                    <div class="form-group mr-3 mb-2 mb-md-0">
                                        <label class="small text-muted mb-1 d-block" for="gstr1Search">Search</label>
                                        <input id="gstr1Search" type="text" class="form-control form-control-sm" style="min-width:220px;" placeholder="customer / invoice / GSTIN">
                                    </div>
                                    <div class="form-group mr-3 mb-2 mb-md-0">
                                        <label class="small text-muted mb-1 d-block" for="gstr1Gstin">GSTIN</label>
                                        <select id="gstr1Gstin" class="form-control form-control-sm">
                                            <option value="">Any</option>
                                            <option value="yes">Has GSTIN (B2B)</option>
                                            <option value="no">No GSTIN (B2C)</option>
                                        </select>
                                    </div>
                                    <div class="form-group mr-3 mb-2 mb-md-0">
                                        <label class="small text-muted mb-1 d-block" for="gstr1Type">Invoice type</label>
                                        <select id="gstr1Type" class="form-control form-control-sm">
                                            <option value="">All</option>
                                            <option value="B2B">B2B</option>
                                            <option value="B2CL">B2C large</option>
                                            <option value="B2CS">B2CS</option>
                                            <option value="EXP">Export</option>
                                            <option value="CDNR">CDNR</option>
                                            <option value="CDNUR">CDNUR</option>
                                        </select>
                                    </div>
                                    <div class="form-group mr-3 mb-2 mb-md-0">
                                        <label class="small text-muted mb-1 d-block" for="gstr1AmtMin">Amount min</label>
                                        <input id="gstr1AmtMin" type="number" class="form-control form-control-sm" style="width:110px;" min="0" step="0.01">
                                    </div>
                                    <div class="form-group mr-3 mb-2 mb-md-0">
                                        <label class="small text-muted mb-1 d-block" for="gstr1AmtMax">Amount max</label>
                                        <input id="gstr1AmtMax" type="number" class="form-control form-control-sm" style="width:110px;" min="0" step="0.01">
                                    </div>
                                    <div class="form-group mr-3 mb-2 mb-md-0">
                                        <label class="small text-muted mb-1 d-block" for="gstr1DateFrom">Date from</label>
                                        <input id="gstr1DateFrom" type="date" class="form-control form-control-sm">
                                    </div>
                                    <div class="form-group mr-3 mb-2 mb-md-0">
                                        <label class="small text-muted mb-1 d-block" for="gstr1DateTo">Date to</label>
                                        <input id="gstr1DateTo" type="date" class="form-control form-control-sm">
                                    </div>
                                    <div class="form-group mb-2 mb-md-0">
                                        <label class="small text-muted mb-1 d-block">Sort</label>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-secondary active" id="gstr1SortDate">Date</button>
                                            <button type="button" class="btn btn-outline-secondary" id="gstr1SortGst">GST</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card shadow mb-3">
                            <div class="card-body py-3">
                                <div class="row text-center" id="gstr1TotalsBar">
                                    <div class="col-6 col-md-3">
                                        <div class="small text-muted">Invoices</div>
                                        <strong id="totCount"><?php echo (int) ($totals['count'] ?? 0); ?></strong>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <div class="small text-muted">Taxable</div>
                                        <strong id="totTaxable"><?php echo gst_h(gst_inr($totals['taxable'] ?? 0)); ?></strong>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <div class="small text-muted">Tax (GST)</div>
                                        <strong id="totGst"><?php echo gst_h(gst_inr($totals['gst'] ?? 0)); ?></strong>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <div class="small text-muted">Total</div>
                                        <strong id="totTotal"><?php echo gst_h(gst_inr($totals['total'] ?? 0)); ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card shadow mb-3">
                            <div class="card-body py-3">
                                <button type="button" class="btn btn-primary btn-sm mr-1 mb-1" id="gstr1PrepareBtn"><i class="fe fe-check-circle"></i> Prepare (local snapshot)</button>
                                <button type="button" class="btn btn-outline-primary btn-sm mr-1 mb-1" id="gstr1PushBtn"><i class="fe fe-upload"></i> Push to portal</button>
                                <button type="button" class="btn btn-success btn-sm mr-1 mb-1" id="gstr1FileBtn"><i class="fe fe-send"></i> File with EVC OTP</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm mr-1 mb-1" id="gstr1StatusBtn"><i class="fe fe-activity"></i> Poll status / ARN</button>
                                <button type="button" class="btn btn-outline-info btn-sm mb-1" id="gstr1JsonBtn"><i class="fe fe-code"></i> GSTN JSON preview</button>
                                <span class="small text-muted ml-2" id="gstr1Busy"></span>
                            </div>
                        </div>

                        <ul class="nav nav-tabs mb-0" id="gstr1Tabs" role="tablist">
                            <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tabAll" data-section="">All <span class="badge badge-light"><?php echo count($invoices); ?></span></a></li>
                            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tabAll" data-section="B2B">B2B <span class="badge badge-light"><?php echo $sectionCount('B2B'); ?></span></a></li>
                            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tabAll" data-section="B2CL">B2C large <span class="badge badge-light"><?php echo $sectionCount('B2CL'); ?></span></a></li>
                            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tabAll" data-section="B2CS">B2CS <span class="badge badge-light"><?php echo $sectionCount('B2CS'); ?></span></a></li>
                            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tabAll" data-section="CDNR">CDNR <span class="badge badge-light"><?php echo $sectionCount('CDNR'); ?></span></a></li>
                            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tabAll" data-section="CDNUR">CDNUR <span class="badge badge-light"><?php echo $sectionCount('CDNUR'); ?></span></a></li>
                            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tabAll" data-section="EXP">Export <span class="badge badge-light"><?php echo $sectionCount('EXP'); ?></span></a></li>
                            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tabHsn">HSN summary</a></li>
                            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tabDocs">Documents issued</a></li>
                            <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tabJson">GSTN JSON</a></li>
                        </ul>

                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="tabAll">
                                <div class="card shadow mb-4" style="border-top-left-radius:0;">
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-hover table-sm mb-0" id="gstr1Table">
                                                <thead>
                                                    <tr>
                                                        <th></th>
                                                        <th>In</th>
                                                        <th>Invoice #</th>
                                                        <th>Date</th>
                                                        <th>Customer</th>
                                                        <th>GSTIN</th>
                                                        <th>Place of supply</th>
                                                        <th class="text-right">Taxable</th>
                                                        <th class="text-right">CGST</th>
                                                        <th class="text-right">SGST</th>
                                                        <th class="text-right">IGST</th>
                                                        <th class="text-right">CESS</th>
                                                        <th class="text-right">Total</th>
                                                        <th>Type</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="gstr1Tbody">
                                                <?php if (!$invoices): ?>
                                                    <tr id="gstr1EmptyRow">
                                                        <td colspan="14" class="text-center text-muted py-5">
                                                            <p class="mb-2">No sales invoices for this period</p>
                                                            <a href="invoices.php" class="btn btn-sm btn-outline-primary">Open Invoice list →</a>
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                                <?php foreach ($invoices as $row):
                                                    $iid = (int) ($row['invoice_id'] ?? $row['id'] ?? 0);
                                                    $itype = (string) ($row['invoice_type'] ?? $row['supply_type'] ?? '');
                                                    $incl = !empty($row['included']);
                                                    $items = is_array($row['items'] ?? null) ? $row['items'] : [];
                                                    ?>
                                                    <tr class="gstr1-row<?php echo $incl ? '' : ' table-secondary'; ?>"
                                                        data-id="<?php echo $iid; ?>"
                                                        data-type="<?php echo gst_h($itype); ?>"
                                                        data-gstin="<?php echo gst_h($row['customer_gstin'] ?? ''); ?>"
                                                        data-date="<?php echo gst_h($row['invoice_date'] ?? ''); ?>"
                                                        data-total="<?php echo gst_h((string) ($row['total'] ?? 0)); ?>"
                                                        data-taxable="<?php echo gst_h((string) ($row['taxable'] ?? 0)); ?>"
                                                        data-gst="<?php echo gst_h((string) ($row['gst'] ?? 0)); ?>"
                                                        data-name="<?php echo gst_h($row['name'] ?? ''); ?>"
                                                        data-inv="<?php echo gst_h($row['invoice_no'] ?? ''); ?>"
                                                        data-included="<?php echo $incl ? '1' : '0'; ?>"
                                                        data-location="<?php echo gst_h((string) ($row['location_id'] ?? '')); ?>">
                                                        <td>
                                                            <button type="button" class="btn btn-link btn-sm p-0 gstr1-expand" title="Line items"><?php echo $items ? '▸' : '·'; ?></button>
                                                        </td>
                                                        <td>
                                                            <input type="checkbox" class="gstr1-include" <?php echo $incl ? 'checked' : ''; ?> title="Include in this GSTR-1">
                                                        </td>
                                                        <td><?php echo gst_h($row['invoice_no'] ?? $iid); ?></td>
                                                        <td><?php echo gst_h($row['invoice_date'] ?? ''); ?></td>
                                                        <td><?php echo gst_h($row['name'] ?? ''); ?></td>
                                                        <td><code><?php echo gst_h(($row['customer_gstin'] ?? '') !== '' ? $row['customer_gstin'] : '—'); ?></code></td>
                                                        <td><?php echo gst_h($row['place_of_supply'] ?? ''); ?>
                                                            <span class="text-muted small"><?php echo gst_h($row['place_of_supply_name'] ?? ''); ?></span>
                                                        </td>
                                                        <td class="text-right"><?php echo gst_gstr1_money($row['taxable'] ?? 0); ?></td>
                                                        <td class="text-right"><?php echo gst_gstr1_money($row['cgst'] ?? 0); ?></td>
                                                        <td class="text-right"><?php echo gst_gstr1_money($row['sgst'] ?? 0); ?></td>
                                                        <td class="text-right"><?php echo gst_gstr1_money($row['igst'] ?? 0); ?></td>
                                                        <td class="text-right"><?php echo gst_gstr1_money($row['cess'] ?? 0); ?></td>
                                                        <td class="text-right font-weight-bold"><?php echo gst_gstr1_money($row['total'] ?? 0); ?></td>
                                                        <td><?php echo gst_gstr1_type_badge($itype); ?></td>
                                                    </tr>
                                                    <tr class="gstr1-items-row d-none" data-for="<?php echo $iid; ?>">
                                                        <td colspan="14" class="bg-light">
                                                            <?php if (!$items): ?>
                                                                <p class="small text-muted mb-0 p-2">No line items on this invoice.</p>
                                                            <?php else: ?>
                                                                <table class="table table-sm mb-0">
                                                                    <thead>
                                                                        <tr>
                                                                            <th>Item</th>
                                                                            <th>HSN</th>
                                                                            <th class="text-right">Qty</th>
                                                                            <th class="text-right">Taxable</th>
                                                                            <th class="text-right">Rate</th>
                                                                            <th class="text-right">CGST</th>
                                                                            <th class="text-right">SGST</th>
                                                                            <th class="text-right">IGST</th>
                                                                            <th class="text-right">Total</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <?php foreach ($items as $it): ?>
                                                                            <tr>
                                                                                <td><?php echo gst_h($it['product_name'] ?? ''); ?></td>
                                                                                <td><?php echo gst_h($it['hsn_code'] ?? ''); ?></td>
                                                                                <td class="text-right"><?php echo gst_gstr1_money($it['quantity'] ?? 0); ?></td>
                                                                                <td class="text-right"><?php echo gst_gstr1_money($it['taxable'] ?? 0); ?></td>
                                                                                <td class="text-right"><?php echo gst_gstr1_money($it['gst_rate'] ?? 0); ?>%</td>
                                                                                <td class="text-right"><?php echo gst_gstr1_money($it['cgst'] ?? 0); ?></td>
                                                                                <td class="text-right"><?php echo gst_gstr1_money($it['sgst'] ?? 0); ?></td>
                                                                                <td class="text-right"><?php echo gst_gstr1_money($it['igst'] ?? 0); ?></td>
                                                                                <td class="text-right"><?php echo gst_gstr1_money($it['total'] ?? 0); ?></td>
                                                                            </tr>
                                                                        <?php endforeach; ?>
                                                                    </tbody>
                                                                </table>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div id="gstr1EmptyJs" class="text-center text-muted py-5 d-none">
                                            <p class="mb-2">No sales invoices match these filters</p>
                                            <a href="invoices.php" class="btn btn-sm btn-outline-primary">Open Invoice list →</a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="tabHsn">
                                <div class="card shadow mb-4" style="border-top-left-radius:0;">
                                    <div class="card-header"><strong class="card-title mb-0">HSN summary</strong></div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-sm mb-0" id="gstr1HsnTable">
                                                <thead>
                                                    <tr>
                                                        <th>#</th>
                                                        <th>HSN</th>
                                                        <th>Description</th>
                                                        <th>UQC</th>
                                                        <th class="text-right">Qty</th>
                                                        <th class="text-right">Rate</th>
                                                        <th class="text-right">Taxable</th>
                                                        <th class="text-right">IGST</th>
                                                        <th class="text-right">CGST</th>
                                                        <th class="text-right">SGST</th>
                                                        <th class="text-right">CESS</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (!$hsnRows): ?>
                                                        <tr><td colspan="11" class="text-center text-muted py-4">No HSN rows for included invoices.</td></tr>
                                                    <?php endif; ?>
                                                    <?php foreach ($hsnRows as $h): ?>
                                                        <tr>
                                                            <td><?php echo (int) ($h['num'] ?? 0); ?></td>
                                                            <td><?php echo gst_h($h['hsn_sc'] ?? ''); ?></td>
                                                            <td><?php echo gst_h($h['desc'] ?? ''); ?></td>
                                                            <td><?php echo gst_h($h['uqc'] ?? 'NOS'); ?></td>
                                                            <td class="text-right"><?php echo gst_gstr1_money($h['qty'] ?? 0); ?></td>
                                                            <td class="text-right"><?php echo gst_gstr1_money($h['rt'] ?? 0); ?></td>
                                                            <td class="text-right"><?php echo gst_gstr1_money($h['txval'] ?? 0); ?></td>
                                                            <td class="text-right"><?php echo gst_gstr1_money($h['iamt'] ?? 0); ?></td>
                                                            <td class="text-right"><?php echo gst_gstr1_money($h['camt'] ?? 0); ?></td>
                                                            <td class="text-right"><?php echo gst_gstr1_money($h['samt'] ?? 0); ?></td>
                                                            <td class="text-right"><?php echo gst_gstr1_money($h['csamt'] ?? 0); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="tabDocs">
                                <div class="card shadow mb-4" style="border-top-left-radius:0;">
                                    <div class="card-header"><strong class="card-title mb-0">Documents issued</strong></div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-sm mb-0" id="gstr1DocsTable">
                                                <thead>
                                                    <tr>
                                                        <th>Doc #</th>
                                                        <th>Type</th>
                                                        <th>From</th>
                                                        <th>To</th>
                                                        <th class="text-right">Total</th>
                                                        <th class="text-right">Cancelled</th>
                                                        <th class="text-right">Net issued</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (!$docsIssued): ?>
                                                        <tr><td colspan="7" class="text-center text-muted py-4">No documents issued for included invoices.</td></tr>
                                                    <?php endif; ?>
                                                    <?php foreach ($docsIssued as $d):
                                                        $doc = $d['docs'][0] ?? [];
                                                        ?>
                                                        <tr>
                                                            <td><?php echo (int) ($d['doc_num'] ?? 0); ?></td>
                                                            <td><?php echo gst_h($d['doc_typ'] ?? ''); ?></td>
                                                            <td><?php echo gst_h($doc['from'] ?? ''); ?></td>
                                                            <td><?php echo gst_h($doc['to'] ?? ''); ?></td>
                                                            <td class="text-right"><?php echo (int) ($doc['totnum'] ?? 0); ?></td>
                                                            <td class="text-right"><?php echo (int) ($doc['cancel'] ?? 0); ?></td>
                                                            <td class="text-right"><?php echo (int) ($doc['net_issue'] ?? 0); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="tabJson">
                                <div class="card shadow mb-4" style="border-top-left-radius:0;">
                                    <div class="card-header">
                                        <strong class="card-title mb-0">GSTN save payload</strong>
                                        <button type="button" class="btn btn-sm btn-outline-secondary float-right" id="gstr1CopyJson">Copy JSON</button>
                                    </div>
                                    <div class="card-body">
                                        <p class="small text-muted">This is the JSON that Prepare / Push sends to GSTN (excluded invoices omitted). Portal keys are never shown here.</p>
                                        <pre class="small bg-dark text-light p-3 rounded mb-0" id="gstr1JsonPre" style="max-height:480px;overflow:auto;"><?php
                                            echo gst_h(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                                        ?></pre>
                                    </div>
                                </div>
                            </div>
                        </div>
    <?php
};

$gst_page_after = function () {
    ?>
            <div class="modal fade" id="gstr1SessionOtpModal" tabindex="-1" role="dialog">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">GST portal OTP</h5>
                            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-muted">Authenticate the GST session, then we retry the portal action.</p>
                            <div class="form-group">
                                <label>OTP</label>
                                <input type="text" class="form-control" id="gstr1SessionOtp" maxlength="10" inputmode="numeric" placeholder="6-digit OTP" autocomplete="one-time-code">
                            </div>
                            <div class="small text-muted" id="gstr1SessionOtpHint"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" id="gstr1SessionOtpSend">Send OTP</button>
                            <button type="button" class="btn btn-primary" id="gstr1SessionOtpVerify">Verify &amp; retry</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal fade" id="gstr1EvcOtpModal" tabindex="-1" role="dialog">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">EVC OTP — File GSTR-1</h5>
                            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                        </div>
                        <div class="modal-body">
                            <p>Enter the EVC OTP sent to the GST-registered mobile / email to file GSTR-1.</p>
                            <input type="text" class="form-control" id="gstr1EvcOtp" maxlength="10" inputmode="numeric" placeholder="6-digit OTP" autocomplete="one-time-code">
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-success" id="gstr1EvcOtpSubmit">File GSTR-1</button>
                        </div>
                    </div>
                </div>
            </div>
            <style>
                .nav-pills .nav-link { font-size: 13px; }
                #gstr1Table th, #gstr1Table td { white-space: nowrap; vertical-align: middle; }
                #gstr1Table td:nth-child(5) { white-space: normal; min-width: 140px; }
                .gstr1-row.excluded td { opacity: .55; }
                #gstr1TotalsBar strong { font-size: 1.05rem; }
            </style>
    <?php
};

$gst_page_footer_scripts = function () use ($gstJs) {
    ?>
            <script>
            (function () {
                var CFG = <?php echo json_encode($gstJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                var pendingRetry = null;
                var sortMode = 'date';
                var sectionFilter = '';
                var pollTimer = null;

                function inr(n) {
                    n = Number(n || 0);
                    return '₹' + n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
                function flash(ok, msg) {
                    var el = document.getElementById('gstr1Flash');
                    if (!el) return;
                    el.className = 'alert alert-' + (ok ? 'success' : 'danger');
                    el.textContent = msg || '';
                    el.classList.remove('d-none');
                }
                function busy(msg) {
                    var el = document.getElementById('gstr1Busy');
                    if (el) el.textContent = msg || '';
                }
                function showErrors(list) {
                    var box = document.getElementById('gstr1Errors');
                    var ul = document.getElementById('gstr1ErrorsList');
                    if (!box || !ul) return;
                    ul.innerHTML = '';
                    if (!list || !list.length) {
                        box.classList.add('d-none');
                        return;
                    }
                    list.forEach(function (e) {
                        var li = document.createElement('li');
                        var code = (e && e.code) ? ('[' + e.code + '] ') : '';
                        li.textContent = code + ((e && e.message) ? e.message : String(e));
                        ul.appendChild(li);
                    });
                    box.classList.remove('d-none');
                }
                function dataOf(res) {
                    return (res && res.data) ? res.data : {};
                }
                function needsAuth(res) {
                    var d = dataOf(res);
                    if (d.auth_required || d.needs_auth) return true;
                    var m = String((res && res.message) || '').toLowerCase();
                    return m.indexOf('session expired') !== -1 || m.indexOf('authenticate') !== -1 || m.indexOf('auth-otp') !== -1;
                }
                function needsEvc(res) {
                    var d = dataOf(res);
                    return !!(d.needs_otp && (res.status === 'success' || d.form === 'GSTR1'));
                }
                function api(path, body) {
                    var payload = Object.assign({
                        business_id: CFG.businessId,
                        period: CFG.period
                    }, body || {});
                    if (CFG.locationId) payload.location_id = CFG.locationId;
                    return fetch(CFG.apiBase + path, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    }).then(function (r) { return r.json(); });
                }
                function handle(res, onOk, retryFn) {
                    var d = dataOf(res);
                    showErrors(d.errors || []);
                    if (needsAuth(res)) {
                        pendingRetry = retryFn || null;
                        if (window.jQuery) jQuery('#gstr1SessionOtpModal').modal('show');
                        flash(false, res.message || 'GST session required. Request OTP.');
                        return;
                    }
                    if (res.status === 'success' && needsEvc(res)) {
                        if (window.jQuery) jQuery('#gstr1EvcOtpModal').modal('show');
                        flash(true, res.message || 'EVC OTP sent.');
                        return;
                    }
                    if (res.status !== 'success') {
                        flash(false, res.message || 'Request failed.');
                        return;
                    }
                    if (typeof onOk === 'function') onOk(res);
                }
                function visibleRows() {
                    return Array.prototype.slice.call(document.querySelectorAll('#gstr1Tbody tr.gstr1-row'));
                }
                function applyFilter() {
                    var q = (document.getElementById('gstr1Search').value || '').toLowerCase().trim();
                    var gstin = document.getElementById('gstr1Gstin').value;
                    var type = document.getElementById('gstr1Type').value || sectionFilter;
                    var amin = parseFloat(document.getElementById('gstr1AmtMin').value);
                    var amax = parseFloat(document.getElementById('gstr1AmtMax').value);
                    var dfrom = document.getElementById('gstr1DateFrom').value;
                    var dto = document.getElementById('gstr1DateTo').value;
                    var rows = visibleRows();
                    var shown = [];
                    rows.forEach(function (tr) {
                        var hay = ((tr.getAttribute('data-name') || '') + ' ' + (tr.getAttribute('data-inv') || '') + ' ' + (tr.getAttribute('data-gstin') || '') + ' ' + (tr.getAttribute('data-id') || '')).toLowerCase();
                        var g = tr.getAttribute('data-gstin') || '';
                        var t = tr.getAttribute('data-type') || '';
                        var tot = parseFloat(tr.getAttribute('data-total') || '0');
                        var dt = tr.getAttribute('data-date') || '';
                        var ok = true;
                        if (q && hay.indexOf(q) === -1) ok = false;
                        if (gstin === 'yes' && !g) ok = false;
                        if (gstin === 'no' && g) ok = false;
                        if (type && t !== type) ok = false;
                        if (!isNaN(amin) && document.getElementById('gstr1AmtMin').value !== '' && tot < amin) ok = false;
                        if (!isNaN(amax) && document.getElementById('gstr1AmtMax').value !== '' && tot > amax) ok = false;
                        if (dfrom && dt < dfrom) ok = false;
                        if (dto && dt > dto) ok = false;
                        tr.style.display = ok ? '' : 'none';
                        var items = document.querySelector('tr.gstr1-items-row[data-for="' + tr.getAttribute('data-id') + '"]');
                        if (items && items.classList.contains('d-none') === false && !ok) items.style.display = 'none';
                        else if (items && ok) items.style.display = '';
                        if (ok) shown.push(tr);
                    });
                    shown.sort(function (a, b) {
                        if (sortMode === 'gst') {
                            return parseFloat(b.getAttribute('data-gst') || '0') - parseFloat(a.getAttribute('data-gst') || '0');
                        }
                        return String(b.getAttribute('data-date') || '').localeCompare(String(a.getAttribute('data-date') || ''));
                    });
                    var tb = document.getElementById('gstr1Tbody');
                    shown.forEach(function (tr) {
                        tb.appendChild(tr);
                        var items = document.querySelector('tr.gstr1-items-row[data-for="' + tr.getAttribute('data-id') + '"]');
                        if (items) tb.appendChild(items);
                    });
                    var sumT = 0, sumG = 0, sumTot = 0, n = 0;
                    shown.forEach(function (tr) {
                        if (tr.getAttribute('data-included') !== '1') return;
                        n++;
                        sumT += parseFloat(tr.getAttribute('data-taxable') || '0');
                        sumG += parseFloat(tr.getAttribute('data-gst') || '0');
                        sumTot += parseFloat(tr.getAttribute('data-total') || '0');
                    });
                    document.getElementById('totCount').textContent = String(n);
                    document.getElementById('totTaxable').textContent = inr(sumT);
                    document.getElementById('totGst').textContent = inr(sumG);
                    document.getElementById('totTotal').textContent = inr(sumTot);
                    var empty = document.getElementById('gstr1EmptyJs');
                    var emptyRow = document.getElementById('gstr1EmptyRow');
                    var any = shown.length > 0;
                    if (empty) empty.classList.toggle('d-none', any);
                    if (emptyRow) emptyRow.style.display = any ? 'none' : '';
                }
                function reload() {
                    busy('Refreshing…');
                    api('get-gstr1.php', {}).then(function (res) {
                        busy('');
                        if (res.status !== 'success') {
                            flash(false, (res.message || 'Refresh failed') + ' — showing last loaded invoices.');
                            return;
                        }
                        window.location.reload();
                    }).catch(function () {
                        busy('');
                        flash(false, 'Could not refresh. Showing local invoices.');
                    });
                }
                function prepare(push) {
                    busy(push ? 'Pushing to GST portal…' : 'Preparing GSTR-1…');
                    var body = { push_portal: !!push };
                    var run = function () { return api('prepare-gstr1.php', body); };
                    run().then(function (res) {
                        busy('');
                        handle(res, function (ok) {
                            var d = dataOf(ok);
                            if (d.payload) {
                                document.getElementById('gstr1JsonPre').textContent = JSON.stringify(d.payload, null, 2);
                            }
                            flash(true, ok.message || (push ? 'Pushed to portal.' : 'GSTR-1 prepared.'));
                            if (push) startPoll();
                        }, function () { return run().then(function (r) { handle(r, function (ok) { flash(true, ok.message || 'Done.'); }); }); });
                    }).catch(function () { busy(''); flash(false, 'Network error.'); });
                }
                function fileGstr1(otp) {
                    busy('Filing GSTR-1…');
                    var body = {};
                    if (otp) body.otp = otp;
                    var run = function () { return api('file-gstr1.php', body); };
                    run().then(function (res) {
                        busy('');
                        handle(res, function (ok) {
                            var d = dataOf(ok);
                            flash(true, ok.message || 'GSTR-1 filed.');
                            if (d.arn || d.ref_id) {
                                document.getElementById('gstr1RefLabel').textContent = d.arn || d.ref_id;
                            }
                            if (window.jQuery) jQuery('#gstr1EvcOtpModal').modal('hide');
                            startPoll();
                        }, function () { return run().then(function (r) { handle(r); }); });
                    }).catch(function () { busy(''); flash(false, 'Network error.'); });
                }
                function pollStatus() {
                    busy('Checking return status…');
                    api('get-return-status.php', { ref_id: (CFG.data && CFG.data.gstr1_ref_id) || '' }).then(function (res) {
                        busy('');
                        handle(res, function (ok) {
                            var d = dataOf(ok);
                            var local = d.local || {};
                            var ref = local.gstr1_ref_id || '';
                            document.getElementById('gstr1RefLabel').textContent = ref || '—';
                            var msg = 'Local GSTR-1: ' + (local.gstr1_status || 'pending');
                            if (d.portal) msg += ' · Portal response received.';
                            if (d.auth_required) msg += ' (authenticate to poll GSTN)';
                            flash(true, msg);
                            if (d.errors) showErrors(d.errors);
                        }, pollStatus);
                    }).catch(function () { busy(''); flash(false, 'Could not poll status.'); });
                }
                function startPoll() {
                    var n = 0;
                    if (pollTimer) clearInterval(pollTimer);
                    pollTimer = setInterval(function () {
                        n++;
                        if (n > 8) { clearInterval(pollTimer); return; }
                        pollStatus();
                    }, 4000);
                }

                document.getElementById('gstr1Search').addEventListener('input', applyFilter);
                ['gstr1Gstin', 'gstr1Type', 'gstr1AmtMin', 'gstr1AmtMax', 'gstr1DateFrom', 'gstr1DateTo'].forEach(function (id) {
                    document.getElementById(id).addEventListener('change', applyFilter);
                    document.getElementById(id).addEventListener('input', applyFilter);
                });
                document.getElementById('gstr1SortDate').addEventListener('click', function () {
                    sortMode = 'date';
                    this.classList.add('active');
                    document.getElementById('gstr1SortGst').classList.remove('active');
                    applyFilter();
                });
                document.getElementById('gstr1SortGst').addEventListener('click', function () {
                    sortMode = 'gst';
                    this.classList.add('active');
                    document.getElementById('gstr1SortDate').classList.remove('active');
                    applyFilter();
                });
                document.querySelectorAll('#gstr1Tabs a[data-section]').forEach(function (a) {
                    a.addEventListener('click', function () {
                        sectionFilter = this.getAttribute('data-section') || '';
                        if (sectionFilter) document.getElementById('gstr1Type').value = sectionFilter;
                        else document.getElementById('gstr1Type').value = '';
                        applyFilter();
                    });
                });
                document.getElementById('gstr1Tbody').addEventListener('click', function (ev) {
                    var btn = ev.target.closest('.gstr1-expand');
                    if (!btn) return;
                    var tr = btn.closest('tr.gstr1-row');
                    if (!tr) return;
                    var items = document.querySelector('tr.gstr1-items-row[data-for="' + tr.getAttribute('data-id') + '"]');
                    if (!items) return;
                    items.classList.toggle('d-none');
                    btn.textContent = items.classList.contains('d-none') ? '▸' : '▾';
                });
                document.getElementById('gstr1Tbody').addEventListener('change', function (ev) {
                    var cb = ev.target.closest('.gstr1-include');
                    if (!cb) return;
                    var tr = cb.closest('tr.gstr1-row');
                    var id = parseInt(tr.getAttribute('data-id'), 10);
                    var included = cb.checked;
                    api('toggle-gstr1-invoice.php', { invoice_id: id, included: included }).then(function (res) {
                        if (res.status !== 'success') {
                            cb.checked = !included;
                            flash(false, res.message || 'Could not update include flag.');
                            return;
                        }
                        tr.setAttribute('data-included', included ? '1' : '0');
                        tr.classList.toggle('table-secondary', !included);
                        tr.classList.toggle('excluded', !included);
                        applyFilter();
                        flash(true, res.message || (included ? 'Included.' : 'Excluded.'));
                    }).catch(function () {
                        cb.checked = !included;
                        flash(false, 'Could not save include/exclude.');
                    });
                });
                document.getElementById('gstr1PrepareBtn').addEventListener('click', function () { prepare(false); });
                document.getElementById('gstr1PushBtn').addEventListener('click', function () { prepare(true); });
                document.getElementById('gstr1FileBtn').addEventListener('click', function () { fileGstr1(''); });
                document.getElementById('gstr1StatusBtn').addEventListener('click', pollStatus);
                document.getElementById('gstr1RefreshBtn').addEventListener('click', reload);
                document.getElementById('gstr1JsonBtn').addEventListener('click', function () {
                    if (window.jQuery) jQuery('#gstr1Tabs a[href="#tabJson"]').tab('show');
                    document.getElementById('gstr1JsonPre').scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
                document.getElementById('gstr1CopyJson').addEventListener('click', function () {
                    var txt = document.getElementById('gstr1JsonPre').textContent || '';
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(txt).then(function () { flash(true, 'JSON copied.'); });
                    } else {
                        window.prompt('Copy JSON', txt);
                    }
                });
                document.getElementById('gstr1ShareBtn').addEventListener('click', function () {
                    var txt = CFG.shareText || '';
                    if (navigator.share) {
                        navigator.share({ title: 'GSTR-1', text: txt }).catch(function () {});
                        return;
                    }
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(txt).then(function () { flash(true, 'GSTR-1 summary copied.'); });
                    } else {
                        window.prompt('Copy GSTR-1 summary', txt);
                    }
                });
                function sendSessionOtp() {
                    busy('Requesting GST OTP…');
                    api('auth-otp.php', {}).then(function (res) {
                        busy('');
                        var d = dataOf(res);
                        document.getElementById('gstr1SessionOtpHint').textContent = res.message || '';
                        if (res.status === 'success' || d.needs_otp || d.otp_sent) {
                            flash(true, res.message || 'OTP sent.');
                            if (window.jQuery) jQuery('#gstr1SessionOtpModal').modal('show');
                        } else {
                            flash(false, res.message || 'Could not send OTP.');
                        }
                    }).catch(function () { busy(''); flash(false, 'Could not send OTP.'); });
                }
                document.getElementById('gstr1RequestOtpBtn') && document.getElementById('gstr1RequestOtpBtn').addEventListener('click', sendSessionOtp);
                document.getElementById('gstr1SessionOtpSend').addEventListener('click', sendSessionOtp);
                document.getElementById('gstr1SessionOtpVerify').addEventListener('click', function () {
                    var otp = (document.getElementById('gstr1SessionOtp').value || '').trim();
                    if (!otp) { flash(false, 'Enter the OTP.'); return; }
                    busy('Verifying OTP…');
                    api('auth-verify.php', { otp: otp }).then(function (res) {
                        busy('');
                        if (res.status !== 'success') {
                            flash(false, res.message || 'OTP verification failed.');
                            return;
                        }
                        flash(true, res.message || 'GST session authenticated.');
                        if (window.jQuery) jQuery('#gstr1SessionOtpModal').modal('hide');
                        if (typeof pendingRetry === 'function') {
                            var fn = pendingRetry;
                            pendingRetry = null;
                            fn();
                        }
                    }).catch(function () { busy(''); flash(false, 'Could not verify OTP.'); });
                });
                document.getElementById('gstr1EvcOtpSubmit').addEventListener('click', function () {
                    var otp = (document.getElementById('gstr1EvcOtp').value || '').trim();
                    if (!otp) { flash(false, 'Enter the EVC OTP.'); return; }
                    fileGstr1(otp);
                });
                applyFilter();
            })();
            </script>
    <?php
};

require __DIR__ . '/includes/gst-page-shell.php';

<?php
/**
 * Full GST API audit (gst_api_logs): filter by period / endpoint / success-fail,
 * expand request/response JSON. Secrets are already redacted when logged.
 */
require_once __DIR__ . '/includes/gst-init.php';

if ($gst_business_id <= 0) {
    header('Location: index.php');
    exit;
}

$filterPeriod = trim((string) ($_GET['log_period'] ?? 'all'));
$filterEndpoint = trim((string) ($_GET['endpoint'] ?? ''));
$filterAction = trim((string) ($_GET['action'] ?? ''));
$filterOutcome = strtolower(trim((string) ($_GET['outcome'] ?? '')));
if (!in_array($filterOutcome, ['', 'success', 'fail'], true)) {
    $filterOutcome = '';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$logIn = [
    'business_id' => $gst_business_id,
    'endpoint' => $filterEndpoint,
    'action' => $filterAction,
    'outcome' => $filterOutcome,
    'limit' => $limit,
    'offset' => $offset,
];
if (preg_match('/^\d{4}-\d{2}$/', $filterPeriod)) {
    $logIn['period'] = $filterPeriod;
}

$pack = ['rows' => [], 'total' => 0, 'actions' => []];
if ($gst && $gst_db_ok) {
    $res = $gst->listApiLogs($logIn);
    if (($res['status'] ?? '') === 'success' && is_array($res['data'] ?? null)) {
        $pack = $res['data'];
    }
}
$rows = $pack['rows'] ?? [];
$total = (int) ($pack['total'] ?? 0);
$actions = $pack['actions'] ?? [];
$pages = max(1, (int) ceil($total / $limit));

$gst_nav = 'logs';
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
                                <h2 class="h3 page-title">GST API logs</h2>
                                <p class="small text-muted mb-0">
                                    Full audit of <code>gst_api_logs</code> for this business.
                                    Request/response JSON is redacted (no client secret, OTP, or auth token).
                                    <a href="<?php echo gst_h(gst_url('gst-filing.php')); ?>">Back to Filing Center</a>
                                </p>
                            </div>
                        </div>
                        <?php include __DIR__ . '/includes/gst-subnav.php'; ?>

                        <div class="card shadow mb-4">
                            <div class="card-body">
                                <form method="GET" action="gst-api-logs.php" class="form-row align-items-end">
                                    <input type="hidden" name="period" value="<?php echo gst_h($gst_period); ?>">
                                    <div class="form-group col-md-2 mb-2">
                                        <label class="small text-muted">Period</label>
                                        <select name="log_period" class="form-control">
                                            <option value="all" <?php echo $filterPeriod === 'all' || $filterPeriod === '' ? 'selected' : ''; ?>>All periods</option>
                                            <?php for ($i = 0; $i < 12; $i++):
                                                $p = gst_shift_period(date('Y-m'), -$i); ?>
                                                <option value="<?php echo gst_h($p); ?>" <?php echo $filterPeriod === $p ? 'selected' : ''; ?>>
                                                    <?php echo gst_h(gst_period_label($p)); ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="form-group col-md-3 mb-2">
                                        <label class="small text-muted">Action</label>
                                        <select name="action" class="form-control">
                                            <option value="">All actions</option>
                                            <?php foreach ($actions as $a): ?>
                                                <option value="<?php echo gst_h($a); ?>" <?php echo $filterAction === $a ? 'selected' : ''; ?>>
                                                    <?php echo gst_h($a); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group col-md-3 mb-2">
                                        <label class="small text-muted">Endpoint contains</label>
                                        <input type="text" class="form-control" name="endpoint" value="<?php echo gst_h($filterEndpoint); ?>" placeholder="gstr3b / auth / offset">
                                    </div>
                                    <div class="form-group col-md-2 mb-2">
                                        <label class="small text-muted">Result</label>
                                        <select name="outcome" class="form-control">
                                            <option value="" <?php echo $filterOutcome === '' ? 'selected' : ''; ?>>All</option>
                                            <option value="success" <?php echo $filterOutcome === 'success' ? 'selected' : ''; ?>>Success</option>
                                            <option value="fail" <?php echo $filterOutcome === 'fail' ? 'selected' : ''; ?>>Fail</option>
                                        </select>
                                    </div>
                                    <div class="form-group col-md-2 mb-2">
                                        <button type="submit" class="btn btn-primary btn-block">Filter</button>
                                    </div>
                                </form>
                                <p class="small text-muted mb-0"><?php echo (int) $total; ?> log<?php echo $total === 1 ? '' : 's'; ?></p>
                            </div>
                        </div>

                        <div class="card shadow mb-4">
                            <div class="card-body p-0">
                                <?php if (!$rows): ?>
                                    <p class="text-muted p-3 mb-0">No GST API calls match these filters.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover table-sm mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th></th>
                                                    <th>When</th>
                                                    <th>Period</th>
                                                    <th>Action</th>
                                                    <th>Method</th>
                                                    <th>HTTP</th>
                                                    <th>Status</th>
                                                    <th>Endpoint</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($rows as $log):
                                                    $ok = !empty($log['ok']);
                                                    $rid = 'gstlog-' . (int) $log['id'];
                                                    $req = gst_pretty_json($log['request_json'] ?? '');
                                                    $resp = gst_pretty_json($log['response_json'] ?? '');
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <button class="btn btn-sm btn-outline-secondary" type="button" data-toggle="collapse" data-target="#<?php echo gst_h($rid); ?>">JSON</button>
                                                        </td>
                                                        <td class="text-nowrap"><?php echo gst_h($log['created_at'] ?? ''); ?></td>
                                                        <td><?php echo gst_h($log['period'] ?? ''); ?></td>
                                                        <td><?php echo gst_h($log['action'] ?? ''); ?></td>
                                                        <td><?php echo gst_h($log['method'] ?? ''); ?></td>
                                                        <td><?php echo (int) ($log['http_code'] ?? 0); ?></td>
                                                        <td>
                                                            <?php echo gst_status_badge($ok ? 'ok' : ($log['status'] ?? 'error')); ?>
                                                            <?php if ($ok): ?>
                                                                <span class="badge badge-success">success</span>
                                                            <?php else: ?>
                                                                <span class="badge badge-danger">fail</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="small text-muted"><?php echo gst_h($log['endpoint'] ?? ''); ?></td>
                                                    </tr>
                                                    <tr class="collapse" id="<?php echo gst_h($rid); ?>">
                                                        <td colspan="8" class="bg-light">
                                                            <div class="row">
                                                                <div class="col-md-6">
                                                                    <strong class="small">Request</strong>
                                                                    <pre class="small mb-0" style="max-height:320px;overflow:auto;white-space:pre-wrap;"><?php echo gst_h($req !== '' ? $req : '—'); ?></pre>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <strong class="small">Response</strong>
                                                                    <pre class="small mb-0" style="max-height:320px;overflow:auto;white-space:pre-wrap;"><?php echo gst_h($resp !== '' ? $resp : '—'); ?></pre>
                                                                </div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if ($pages > 1): ?>
                                <div class="card-footer">
                                    <?php
                                    $q = $_GET;
                                    for ($p = 1; $p <= $pages; $p++):
                                        $q['page'] = $p;
                                        $href = 'gst-api-logs.php?' . http_build_query($q);
                                        ?>
                                        <a class="btn btn-sm <?php echo $p === $page ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo gst_h($href); ?>"><?php echo $p; ?></a>
                                    <?php endfor; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php include __DIR__ . '/admin/footer.php'; ?>

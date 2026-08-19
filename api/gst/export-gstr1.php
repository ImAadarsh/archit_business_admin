<?php
/**
 * GET/POST: GSTR-1 period export. format=csv downloads a spreadsheet; otherwise JSON.
 * Params: business_id, period, optional location_id, format=csv|json
 */
require __DIR__ . '/config.php';
$gst = new GstFilingController($connect);
$in = $gst->readInput();
$format = strtolower(trim((string) ($in['format'] ?? $_GET['format'] ?? 'json')));
$res = $gst->exportGstr1($in);
if ($format === 'csv' && ($res['status'] ?? '') === 'success') {
    $fname = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($res['data']['filename'] ?? 'GSTR1.csv'));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    foreach ($res['data']['csv_rows'] ?? [] as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}
$gst->jsonOut($res);

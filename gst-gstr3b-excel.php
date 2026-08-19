<?php
/**
 * Excel/CSV export of GSTR-3B summary, 3.1 / 4 / 6.1 tables, and 6-month comparison.
 */
require_once __DIR__ . '/includes/gst-init.php';

if ($gst_business_id <= 0 || !$gst || !$gst_db_ok) {
    header('HTTP/1.1 403 Forbidden');
    exit('Unauthorized');
}

$ctx = [
    'business_id' => $gst_business_id,
    'period' => $gst_period,
    'location_id' => $gst_location_id ?: null,
];
$res = $gst->getGstr3b($ctx);
$data = (($res['status'] ?? '') === 'success') ? ($res['data'] ?? []) : [];
$cmp = $gst->comparePeriods(array_merge($ctx, ['months' => 6]));
$compare = (($cmp['status'] ?? '') === 'success') ? ($cmp['data']['rows'] ?? []) : [];

$fname = 'gstr3b-' . $gst_period . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['GSTR-3B summary', gst_period_label($gst_period), $gst_period]);
fputcsv($out, ['Status', $data['status'] ?? '']);
fputcsv($out, ['Invoices', $data['invoice_count'] ?? 0]);
fputcsv($out, ['Gross output GST', $data['gross_output'] ?? $data['output_gst'] ?? 0]);
fputcsv($out, ['ITC utilized', $data['itc_eligible'] ?? 0]);
fputcsv($out, ['Pending handshake', $data['itc_pending'] ?? 0]);
fputcsv($out, ['Carry-forward', $data['itc_carry'] ?? 0]);
fputcsv($out, ['Net total', $data['net_total'] ?? 0]);
fputcsv($out, ['Cash to pay', $data['cash_to_pay'] ?? 0]);
fputcsv($out, ['Offset ref', $data['offset_ref_id'] ?? '']);
fputcsv($out, ['File ref', $data['file_ref_id'] ?? '']);
fputcsv($out, []);

fputcsv($out, ['Table 3.1 Outward supplies']);
fputcsv($out, ['Code', 'Nature', 'Taxable', 'IGST', 'CGST', 'SGST', 'Cess', 'Note']);
foreach (($data['table_3_1'] ?? []) as $r) {
    fputcsv($out, [
        $r['code'] ?? '', $r['nature'] ?? '', $r['txval'] ?? 0,
        $r['iamt'] ?? 0, $r['camt'] ?? 0, $r['samt'] ?? 0, $r['csamt'] ?? 0, $r['note'] ?? '',
    ]);
}
fputcsv($out, []);
fputcsv($out, ['eco_dtls (always sent)']);
$eco = $data['eco_dtls'] ?? [];
fputcsv($out, ['Taxable', 'IGST', 'CGST', 'SGST', 'Cess', 'Note']);
fputcsv($out, [
    $eco['txval'] ?? 0, $eco['iamt'] ?? 0, $eco['camt'] ?? 0, $eco['samt'] ?? 0, $eco['csamt'] ?? 0,
    $eco['note'] ?? '',
]);
fputcsv($out, []);

fputcsv($out, ['Table 4 Eligible ITC']);
fputcsv($out, ['Code', 'Nature', 'Taxable', 'IGST', 'CGST', 'SGST', 'Cess', 'Note']);
foreach (($data['table_4'] ?? []) as $r) {
    fputcsv($out, [
        $r['code'] ?? '', $r['nature'] ?? '', $r['txval'] ?? 0,
        $r['iamt'] ?? 0, $r['camt'] ?? 0, $r['samt'] ?? 0, $r['csamt'] ?? 0, $r['note'] ?? '',
    ]);
}
fputcsv($out, []);

fputcsv($out, ['Table 6.1 Payment of tax']);
fputcsv($out, ['Head', 'Tax payable', 'Paid ITC', 'Paid cash', 'Interest', 'Late fee']);
foreach (($data['table_6_1'] ?? []) as $r) {
    fputcsv($out, [
        $r['head'] ?? '', $r['tax_payable'] ?? 0, $r['paid_itc'] ?? 0,
        $r['paid_cash'] ?? 0, $r['interest'] ?? 0, $r['late_fee'] ?? 0,
    ]);
}
fputcsv($out, []);
fputcsv($out, ['Charges note', $data['charges_note'] ?? '']);
fputcsv($out, []);

fputcsv($out, ['Last 6 months comparison']);
fputcsv($out, ['Period', 'Label', 'Invoices', 'Output GST', 'ITC', 'Pending', 'Net', 'Cash', 'Status']);
foreach ($compare as $r) {
    fputcsv($out, [
        $r['period'] ?? '', $r['period_label'] ?? '', $r['invoice_count'] ?? 0,
        $r['output_gst'] ?? 0, $r['itc_eligible'] ?? 0, $r['itc_pending'] ?? 0,
        $r['net_total'] ?? 0, $r['cash_to_pay'] ?? 0, $r['status'] ?? '',
    ]);
}

fclose($out);
exit;

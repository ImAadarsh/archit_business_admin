<?php
/**
 * POST JSON: include or exclude an invoice from this period's GSTR-1.
 * Body: business_id, period, invoice_id, included (bool). Persists in gst_gstr1_exclusions.
 */
require __DIR__ . '/config.php';
$gst = new GstFilingController($connect);
$gst->jsonOut($gst->toggleGstr1Invoice($gst->readInput()));

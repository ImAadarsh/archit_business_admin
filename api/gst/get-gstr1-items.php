<?php
/**
 * GET/POST: line items for a sales invoice (GSTR-1 drilldown).
 * Params: business_id, invoice_id
 */
require __DIR__ . '/config.php';
$gst = new GstFilingController($connect);
$gst->jsonOut($gst->getGstr1Items($gst->readInput()));

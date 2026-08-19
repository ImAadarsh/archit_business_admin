<?php
/**
 * GET/POST: outward supplies for GSTR-1 from invoices.
 * Params: business_id, period (YYYY-MM), optional location_id
 */
require __DIR__ . '/config.php';
$gst = new GstFilingController($connect);
$gst->jsonOut($gst->getGstr1($gst->readInput()));

<?php
/**
 * POST JSON: pull GSTR-2B from Perione and match local expenses.
 * Body: business_id, period, optional location_id
 */
require __DIR__ . '/config.php';
$gst = new GstFilingController($connect);
$gst->jsonOut($gst->syncGstr2b($gst->readInput()));

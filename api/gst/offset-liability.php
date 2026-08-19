<?php
/**
 * POST JSON: offset GSTR-3B liability (Perione /gstr3b/offset).
 * Body: business_id, period, optional local_only, use_itc
 */
require __DIR__ . '/config.php';
$gst = new GstFilingController($connect);
$gst->jsonOut($gst->offsetLiability($gst->readInput()));

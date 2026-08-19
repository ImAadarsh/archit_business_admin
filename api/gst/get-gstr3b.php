<?php
/**
 * GET/POST: GSTR-3B computation from output GST minus approved ITC.
 * Params: business_id, period, optional location_id
 */
require __DIR__ . '/config.php';
$gst = new GstFilingController($connect);
$gst->jsonOut($gst->getGstr3b($gst->readInput()));

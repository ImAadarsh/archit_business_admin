<?php
/**
 * GET/POST: local purchases + ITC handshake rows (pending/matched/carry/ghost).
 * Params: business_id, period, optional location_id, status filter
 */
require __DIR__ . '/config.php';
$gst = new GstFilingController($connect);
$gst->jsonOut($gst->getGstr2b($gst->readInput()));

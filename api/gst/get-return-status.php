<?php
/**
 * GET/POST: local + portal return filing status.
 * Params: business_id, period, optional ref_id
 */
require __DIR__ . '/config.php';
$gst = new GstFilingController($connect);
$gst->jsonOut($gst->getReturnStatus($gst->readInput()));

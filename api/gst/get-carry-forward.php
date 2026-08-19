<?php
/**
 * GET/POST: carry-forward / pending ITC for the period.
 * Params: business_id, period, optional location_id
 */
require __DIR__ . '/config.php';
$gst = new GstFilingController($connect);
$gst->jsonOut($gst->getCarryForward($gst->readInput()));

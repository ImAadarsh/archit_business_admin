<?php
/**
 * GET/POST: period dashboard KPIs (sales GST, eligible ITC, net cash).
 * Params: business_id, period (YYYY-MM), optional location_id
 */
require __DIR__ . '/config.php';
$gst = new GstFilingController($connect);
$gst->jsonOut($gst->getPeriodSummary($gst->readInput()));

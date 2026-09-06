<?php
/**
 * GET/POST: safe GST credentials + session validity (never returns auth_token).
 * Params: business_id
 */
require __DIR__ . '/config.php';
$gst = new GstFilingController($connect);
$gst->jsonOut($gst->getSafeCredentials($gst->readInput()));

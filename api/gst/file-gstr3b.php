<?php
/**
 * POST JSON: file GSTR-3B via Perione EVC.
 * Body: business_id, period, optional otp (omit first call to request EVC OTP)
 */
require __DIR__ . '/config.php';
$gst = new GstFilingController($connect);
$gst->jsonOut($gst->fileGstr3b($gst->readInput()));

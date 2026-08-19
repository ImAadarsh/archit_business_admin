<?php
/**
 * POST JSON: verify GST OTP and store auth token (Perione /authentication/authtoken).
 * Body: business_id, otp
 */
require __DIR__ . '/config.php';
$gst = new GstFilingController($connect);
$gst->jsonOut($gst->authVerify($gst->readInput()));

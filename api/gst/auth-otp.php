<?php
/**
 * POST JSON: request GST portal OTP (Perione /authentication/otprequest).
 * Body: business_id, optional gstin, gst_username, gst_email/email, gst_password/password
 */
require __DIR__ . '/config.php';
$gst = new GstFilingController($connect);
$gst->jsonOut($gst->authOtp($gst->readInput()));

<?php
/**
 * POST JSON: set ITC status (handshake).
 * Body: business_id, period, expense_id, status
 *    or items: [{id, status}]
 *    or bulk_action: approve_under | carry_pending (optional max_gst)
 */
require __DIR__ . '/config.php';
$gst = new GstFilingController($connect);
$gst->jsonOut($gst->reconcileItc($gst->readInput()));

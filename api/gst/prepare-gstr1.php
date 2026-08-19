<?php
/**
 * POST JSON: snapshot sales invoices into gst_gstr1_invoices; optional Perione gstr1/save.
 * Body: business_id, period, optional location_id, push_portal (bool)
 */
require __DIR__ . '/config.php';
$gst = new GstFilingController($connect);
$gst->jsonOut($gst->prepareGstr1($gst->readInput()));

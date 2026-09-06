-- GST Filing tables for InvoiceMate / Archit
-- Safe to re-run: CREATE TABLE IF NOT EXISTS
-- Apply on the business DB (same as invoices / expenses / eway_bill_settings).

SET NAMES utf8mb4;

-- Per-business GSTIN + Perione GST mapping (portal username, tokens).
-- App-level Perione client_id / client_secret live in api/gst/config.php
-- and are used as defaults when these columns are empty.
CREATE TABLE IF NOT EXISTS `gst_credentials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `business_id` int(11) NOT NULL,
  `gstin` varchar(15) NOT NULL DEFAULT '',
  `gst_username` varchar(64) NOT NULL DEFAULT '' COMMENT 'GSTN portal username',
  `gst_password` varchar(128) NOT NULL DEFAULT '' COMMENT 'GSTN portal password (server-side only)',
  `gst_email` varchar(191) NOT NULL DEFAULT '' COMMENT 'Perione registered email',
  `state_cd` varchar(2) NOT NULL DEFAULT '',
  `ip_address` varchar(64) NOT NULL DEFAULT 'auto',
  `client_id` varchar(128) DEFAULT NULL COMMENT 'Optional override; else config.php',
  `client_secret` varchar(128) DEFAULT NULL COMMENT 'Optional override; else config.php',
  `auth_token` varchar(512) DEFAULT NULL,
  `token_expiry` datetime DEFAULT NULL,
  `txn` varchar(128) DEFAULT NULL,
  `evc_txn` varchar(128) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gst_credentials_business` (`business_id`),
  KEY `idx_gst_credentials_gstin` (`gstin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per business + return month (YYYY-MM).
CREATE TABLE IF NOT EXISTS `gst_return_periods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `business_id` int(11) NOT NULL,
  `period` char(7) NOT NULL COMMENT 'YYYY-MM',
  `ret_period` char(6) NOT NULL COMMENT 'MMYYYY GSTN format',
  `gstr1_status` varchar(24) NOT NULL DEFAULT 'pending' COMMENT 'pending|ready|prepared|filed|error',
  `gstr2b_status` varchar(24) NOT NULL DEFAULT 'pending' COMMENT 'pending|synced|error',
  `gstr3b_status` varchar(24) NOT NULL DEFAULT 'pending' COMMENT 'pending|computed|offset|filed|error',
  `gstr1_ref_id` varchar(64) DEFAULT NULL,
  `gstr2b_ref_id` varchar(64) DEFAULT NULL,
  `gstr3b_ref_id` varchar(64) DEFAULT NULL,
  `gstr1_filed_at` datetime DEFAULT NULL,
  `gstr3b_filed_at` datetime DEFAULT NULL,
  `last_synced_at` datetime DEFAULT NULL,
  `notes` varchar(512) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gst_return_period` (`business_id`, `period`),
  KEY `idx_gst_return_ret_period` (`business_id`, `ret_period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Snapshot of outward supplies used for GSTR-1.
CREATE TABLE IF NOT EXISTS `gst_gstr1_invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `business_id` int(11) NOT NULL,
  `period` char(7) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `invoice_no` varchar(32) NOT NULL DEFAULT '',
  `invoice_date` date DEFAULT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `customer_gstin` varchar(15) DEFAULT NULL,
  `supply_type` varchar(8) NOT NULL DEFAULT 'B2CS' COMMENT 'B2B|B2CS|B2CL|EXP',
  `place_of_supply` varchar(2) DEFAULT NULL,
  `taxable` decimal(14,2) NOT NULL DEFAULT 0.00,
  `cgst` decimal(14,2) NOT NULL DEFAULT 0.00,
  `sgst` decimal(14,2) NOT NULL DEFAULT 0.00,
  `igst` decimal(14,2) NOT NULL DEFAULT 0.00,
  `cess` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `gst_rate` decimal(6,2) NOT NULL DEFAULT 0.00,
  `included` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=in this GSTR-1; 0=excluded (see gst_gstr1_exclusions)',
  `hsn_code` varchar(16) DEFAULT NULL,
  `snapshot_json` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gst_gstr1_inv` (`business_id`, `period`, `invoice_id`),
  KEY `idx_gst_gstr1_period` (`business_id`, `period`),
  KEY `idx_gst_gstr1_supply` (`supply_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invoices excluded from a GSTR-1 period. Survives prepare() snapshot wipe.
CREATE TABLE IF NOT EXISTS `gst_gstr1_exclusions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `business_id` int(11) NOT NULL,
  `period` char(7) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gstr1_excl` (`business_id`, `period`, `invoice_id`),
  KEY `idx_gstr1_excl_period` (`business_id`, `period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Portal GSTR-2B invoices vs local purchase/expense rows.
CREATE TABLE IF NOT EXISTS `gst_gstr2b_invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `business_id` int(11) NOT NULL,
  `period` char(7) NOT NULL,
  `source` varchar(16) NOT NULL DEFAULT 'portal' COMMENT 'portal|local',
  `expense_id` int(11) DEFAULT NULL,
  `invoice_no` varchar(64) DEFAULT NULL,
  `invoice_date` date DEFAULT NULL,
  `vendor_name` varchar(255) DEFAULT NULL,
  `vendor_gstin` varchar(15) DEFAULT NULL,
  `taxable` decimal(14,2) NOT NULL DEFAULT 0.00,
  `cgst` decimal(14,2) NOT NULL DEFAULT 0.00,
  `sgst` decimal(14,2) NOT NULL DEFAULT 0.00,
  `igst` decimal(14,2) NOT NULL DEFAULT 0.00,
  `cess` decimal(14,2) NOT NULL DEFAULT 0.00,
  `itc_eligible` decimal(14,2) NOT NULL DEFAULT 0.00,
  `portal_json` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gst_gstr2b_period` (`business_id`, `period`),
  KEY `idx_gst_gstr2b_expense` (`expense_id`),
  KEY `idx_gst_gstr2b_gstin` (`vendor_gstin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Handshake: matched / pending / carry / ghost.
CREATE TABLE IF NOT EXISTS `gst_itc_reconcile` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `business_id` int(11) NOT NULL,
  `period` char(7) NOT NULL,
  `source` varchar(16) NOT NULL DEFAULT 'expense' COMMENT 'expense|portal|ghost',
  `source_ref` varchar(64) NOT NULL COMMENT 'expense id or portal/ghost key',
  `expense_id` int(11) DEFAULT NULL,
  `gstr2b_id` int(11) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending' COMMENT 'pending|matched|carry|ghost',
  `gst_claimed` decimal(14,2) NOT NULL DEFAULT 0.00,
  `gst_portal` decimal(14,2) NOT NULL DEFAULT 0.00,
  `taxable` decimal(14,2) NOT NULL DEFAULT 0.00,
  `vendor_name` varchar(255) DEFAULT NULL,
  `vendor_gstin` varchar(15) DEFAULT NULL,
  `doc_date` date DEFAULT NULL,
  `notes` varchar(512) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gst_itc_row` (`business_id`, `period`, `source`, `source_ref`),
  KEY `idx_gst_itc_status` (`business_id`, `period`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GSTR-3B computation + payment / offset refs.
CREATE TABLE IF NOT EXISTS `gst_gstr3b_summary` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `business_id` int(11) NOT NULL,
  `period` char(7) NOT NULL,
  `output_taxable` decimal(14,2) NOT NULL DEFAULT 0.00,
  `output_cgst` decimal(14,2) NOT NULL DEFAULT 0.00,
  `output_sgst` decimal(14,2) NOT NULL DEFAULT 0.00,
  `output_igst` decimal(14,2) NOT NULL DEFAULT 0.00,
  `output_cess` decimal(14,2) NOT NULL DEFAULT 0.00,
  `itc_cgst` decimal(14,2) NOT NULL DEFAULT 0.00,
  `itc_sgst` decimal(14,2) NOT NULL DEFAULT 0.00,
  `itc_igst` decimal(14,2) NOT NULL DEFAULT 0.00,
  `itc_cess` decimal(14,2) NOT NULL DEFAULT 0.00,
  `net_cgst` decimal(14,2) NOT NULL DEFAULT 0.00,
  `net_sgst` decimal(14,2) NOT NULL DEFAULT 0.00,
  `net_igst` decimal(14,2) NOT NULL DEFAULT 0.00,
  `net_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `cash_to_pay` decimal(14,2) NOT NULL DEFAULT 0.00,
  `itc_pending` decimal(14,2) NOT NULL DEFAULT 0.00,
  `itc_carry` decimal(14,2) NOT NULL DEFAULT 0.00,
  `payment_ref` varchar(64) DEFAULT NULL,
  `offset_ref_id` varchar(64) DEFAULT NULL,
  `file_ref_id` varchar(64) DEFAULT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'draft' COMMENT 'draft|computed|offset|filed|error',
  `payload_json` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gst_gstr3b` (`business_id`, `period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ITC marked carry-forward to a later period.
CREATE TABLE IF NOT EXISTS `gst_carry_forward` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `business_id` int(11) NOT NULL,
  `from_period` char(7) NOT NULL,
  `to_period` char(7) DEFAULT NULL,
  `expense_id` int(11) DEFAULT NULL,
  `itc_reconcile_id` int(11) DEFAULT NULL,
  `vendor_name` varchar(255) DEFAULT NULL,
  `vendor_gstin` varchar(15) DEFAULT NULL,
  `itc_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` varchar(16) NOT NULL DEFAULT 'open' COMMENT 'open|applied|dismissed',
  `notes` varchar(512) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gst_carry_period` (`business_id`, `from_period`),
  KEY `idx_gst_carry_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Request / response audit for Perione + local GST actions.
CREATE TABLE IF NOT EXISTS `gst_api_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `business_id` int(11) DEFAULT NULL,
  `period` char(7) DEFAULT NULL,
  `action` varchar(64) NOT NULL DEFAULT '',
  `endpoint` varchar(191) NOT NULL DEFAULT '',
  `method` varchar(8) NOT NULL DEFAULT 'GET',
  `http_code` int(11) DEFAULT NULL,
  `status` varchar(24) DEFAULT NULL,
  `request_json` longtext DEFAULT NULL,
  `response_json` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gst_logs_biz` (`business_id`, `created_at`),
  KEY `idx_gst_logs_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Safe Database Updates for Cashfree Subscription System
-- These changes will NOT affect existing data or functionality

-- 1. Add Cashfree-specific fields to subscriptions table (if they don't exist)
ALTER TABLE `subscriptions` 
ADD COLUMN IF NOT EXISTS `cashfree_subscription_id` VARCHAR(64) NULL AFTER `razorpay_subscription_id`,
ADD COLUMN IF NOT EXISTS `cashfree_customer_id` VARCHAR(64) NULL AFTER `cashfree_subscription_id`,
ADD COLUMN IF NOT EXISTS `next_charge_at` DATETIME NULL AFTER `current_period_end`,
ADD COLUMN IF NOT EXISTS `authorization_status` ENUM('pending', 'active', 'failed', 'expired') NULL DEFAULT 'pending' AFTER `next_charge_at`,
ADD COLUMN IF NOT EXISTS `authorization_reference` VARCHAR(255) NULL AFTER `authorization_status`,
ADD COLUMN IF NOT EXISTS `authorization_time` DATETIME NULL AFTER `authorization_reference`;

-- 2. Add Cashfree-specific fields to subscription_payments table (if they don't exist)
ALTER TABLE `subscription_payments` 
ADD COLUMN IF NOT EXISTS `cashfree_payment_id` VARCHAR(64) NULL AFTER `razorpay_payment_id`,
ADD COLUMN IF NOT EXISTS `cashfree_txn_id` VARCHAR(64) NULL AFTER `cashfree_payment_id`,
ADD COLUMN IF NOT EXISTS `payment_group` VARCHAR(50) NULL AFTER `payment_method_type`,
ADD COLUMN IF NOT EXISTS `retry_attempts` INT(11) DEFAULT 0 AFTER `payment_group`,
ADD COLUMN IF NOT EXISTS `failure_reason` TEXT NULL AFTER `retry_attempts`;

-- 3. Add indexes for better performance (safe to add)
CREATE INDEX IF NOT EXISTS `idx_subscriptions_cashfree` ON `subscriptions` (`cashfree_subscription_id`);
CREATE INDEX IF NOT EXISTS `idx_subscriptions_auth_status` ON `subscriptions` (`authorization_status`);
CREATE INDEX IF NOT EXISTS `idx_payments_cashfree` ON `subscription_payments` (`cashfree_payment_id`);
CREATE INDEX IF NOT EXISTS `idx_payments_status` ON `subscription_payments` (`status`, `created_at`);

-- 4. Add webhook tracking table (completely new table)
CREATE TABLE IF NOT EXISTS `subscription_webhooks` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `webhook_id` VARCHAR(100) NOT NULL,
  `event_type` VARCHAR(100) NOT NULL,
  `subscription_id` VARCHAR(100) NULL,
  `cf_subscription_id` VARCHAR(100) NULL,
  `payment_id` VARCHAR(100) NULL,
  `event_data` LONGTEXT NULL,
  `processed` TINYINT(1) NOT NULL DEFAULT 0,
  `processed_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_webhook_id` (`webhook_id`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_subscription` (`cf_subscription_id`),
  KEY `idx_processed` (`processed`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Add subscription status history table (completely new table)
CREATE TABLE IF NOT EXISTS `subscription_status_history` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `subscription_id` BIGINT(20) UNSIGNED NOT NULL,
  `old_status` VARCHAR(50) NULL,
  `new_status` VARCHAR(50) NOT NULL,
  `changed_by` ENUM('system', 'user', 'webhook') NOT NULL DEFAULT 'system',
  `reason` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_subscription` (`subscription_id`),
  KEY `idx_status_change` (`old_status`, `new_status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_ssh_subscription` FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Update subscription_plans table to ensure Cashfree compatibility
ALTER TABLE `subscription_plans` 
ADD COLUMN IF NOT EXISTS `cashfree_plan_id` VARCHAR(255) NULL AFTER `razorpay_plan_id`,
ADD COLUMN IF NOT EXISTS `payment_gateway` VARCHAR(50) DEFAULT 'cashfree' AFTER `cashfree_plan_id`,
ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `payment_gateway`;

-- 7. Add indexes for subscription_plans
CREATE INDEX IF NOT EXISTS `idx_plans_cashfree` ON `subscription_plans` (`cashfree_plan_id`);
CREATE INDEX IF NOT EXISTS `idx_plans_gateway` ON `subscription_plans` (`payment_gateway`);
CREATE INDEX IF NOT EXISTS `idx_plans_active` ON `subscription_plans` (`is_active`, `is_public`);

-- 8. Add business subscription tracking
ALTER TABLE `businessses` 
ADD COLUMN IF NOT EXISTS `cashfree_customer_id` VARCHAR(64) NULL AFTER `razorpay_customer_id`,
ADD COLUMN IF NOT EXISTS `subscription_trial_ends_at` DATETIME NULL AFTER `trial_ends_at`;

-- 9. Add indexes for businessses
CREATE INDEX IF NOT EXISTS `idx_business_cashfree` ON `businessses` (`cashfree_customer_id`);
CREATE INDEX IF NOT EXISTS `idx_business_subscription` ON `businessses` (`subscription_status`, `subscription_trial_ends_at`);

-- 10. Create a view for easy subscription monitoring (completely new)
CREATE OR REPLACE VIEW `subscription_overview` AS
SELECT 
    s.id,
    s.business_id,
    b.business_name,
    b.email as business_email,
    sp.name as plan_name,
    sp.code as plan_code,
    s.status,
    s.authorization_status,
    s.trial_ends_at,
    s.current_period_start,
    s.current_period_end,
    s.next_charge_at,
    s.razorpay_subscription_id as cf_subscription_id,
    s.cashfree_subscription_id,
    s.created_at,
    s.updated_at
FROM subscriptions s
JOIN businessses b ON s.business_id = b.id
JOIN subscription_plans sp ON s.plan_id = sp.id
ORDER BY s.created_at DESC;

-- 11. Create a view for payment monitoring (completely new)
CREATE OR REPLACE VIEW `payment_overview` AS
SELECT 
    sp.id as payment_id,
    sp.subscription_id,
    s.business_id,
    b.business_name,
    sp.amount,
    sp.currency,
    sp.status,
    sp.payment_method_type,
    sp.razorpay_payment_id as cf_payment_id,
    sp.cashfree_payment_id,
    sp.paid_at,
    sp.created_at
FROM subscription_payments sp
JOIN subscriptions s ON sp.subscription_id = s.id
JOIN businessses b ON s.business_id = b.id
ORDER BY sp.created_at DESC;

-- 12. Add a function to safely update subscription status
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS `UpdateSubscriptionStatus`(
    IN p_subscription_id BIGINT,
    IN p_new_status VARCHAR(50),
    IN p_authorization_status VARCHAR(50),
    IN p_authorization_reference VARCHAR(255),
    IN p_changed_by VARCHAR(20)
)
BEGIN
    DECLARE v_old_status VARCHAR(50);
    DECLARE v_business_id INT;
    
    -- Get current status and business ID
    SELECT status, business_id INTO v_old_status, v_business_id
    FROM subscriptions 
    WHERE id = p_subscription_id;
    
    -- Update subscription status
    UPDATE subscriptions 
    SET 
        status = p_new_status,
        authorization_status = p_authorization_status,
        authorization_reference = p_authorization_reference,
        updated_at = NOW()
    WHERE id = p_subscription_id;
    
    -- Update business subscription status
    UPDATE businessses 
    SET subscription_status = p_new_status
    WHERE id = v_business_id;
    
    -- Record status change
    INSERT INTO subscription_status_history 
    (subscription_id, old_status, new_status, changed_by, reason)
    VALUES 
    (p_subscription_id, v_old_status, p_new_status, p_changed_by, 
     CONCAT('Status changed from ', IFNULL(v_old_status, 'NULL'), ' to ', p_new_status));
    
END//
DELIMITER ;

-- 13. Add a function to record webhook events
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS `RecordWebhookEvent`(
    IN p_webhook_id VARCHAR(100),
    IN p_event_type VARCHAR(100),
    IN p_subscription_id VARCHAR(100),
    IN p_cf_subscription_id VARCHAR(100),
    IN p_payment_id VARCHAR(100),
    IN p_event_data LONGTEXT
)
BEGIN
    INSERT INTO subscription_webhooks 
    (webhook_id, event_type, subscription_id, cf_subscription_id, payment_id, event_data)
    VALUES 
    (p_webhook_id, p_event_type, p_subscription_id, p_cf_subscription_id, p_payment_id, p_event_data)
    ON DUPLICATE KEY UPDATE
    event_data = p_event_data,
    processed = 0,
    processed_at = NULL;
END//
DELIMITER ;

-- 14. Create a safe migration log table
CREATE TABLE IF NOT EXISTS `database_migrations` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `migration_name` VARCHAR(255) NOT NULL,
  `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM('success', 'failed', 'skipped') NOT NULL DEFAULT 'success',
  `notes` TEXT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_migration_name` (`migration_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Log this migration
INSERT INTO database_migrations (migration_name, status, notes) 
VALUES ('cashfree_subscription_system_v1', 'success', 'Added Cashfree subscription support without affecting existing data')
ON DUPLICATE KEY UPDATE status = 'success', notes = 'Updated Cashfree subscription support';

-- 16. Add comments to tables for documentation
ALTER TABLE `subscriptions` COMMENT = 'Subscription management with Cashfree and Razorpay support';
ALTER TABLE `subscription_payments` COMMENT = 'Payment tracking for subscriptions with multiple gateway support';
ALTER TABLE `subscription_plans` COMMENT = 'Subscription plans with multi-gateway support';
ALTER TABLE `businessses` COMMENT = 'Business accounts with subscription tracking';

-- 17. Create a backup of critical data before changes (optional - uncomment if needed)
-- CREATE TABLE subscriptions_backup AS SELECT * FROM subscriptions;
-- CREATE TABLE subscription_payments_backup AS SELECT * FROM subscription_payments;
-- CREATE TABLE subscription_plans_backup AS SELECT * FROM subscription_plans;

-- 18. Add constraints to ensure data integrity
ALTER TABLE `subscriptions` 
ADD CONSTRAINT IF NOT EXISTS `chk_subscription_status` 
CHECK (status IN ('trialing', 'active', 'paused', 'canceled', 'completed', 'unpaid', 'past_due'));

ALTER TABLE `subscriptions` 
ADD CONSTRAINT IF NOT EXISTS `chk_auth_status` 
CHECK (authorization_status IN ('pending', 'active', 'failed', 'expired') OR authorization_status IS NULL);

-- 19. Add triggers for automatic status tracking (optional)
DELIMITER //
CREATE TRIGGER IF NOT EXISTS `subscription_status_change_log` 
AFTER UPDATE ON `subscriptions`
FOR EACH ROW
BEGIN
    IF OLD.status != NEW.status THEN
        INSERT INTO subscription_status_history 
        (subscription_id, old_status, new_status, changed_by, reason)
        VALUES 
        (NEW.id, OLD.status, NEW.status, 'system', 'Automatic status change');
    END IF;
END//
DELIMITER ;

-- 20. Final verification queries (safe to run)
SELECT 'Database updates completed successfully' as status;
SELECT COUNT(*) as total_subscriptions FROM subscriptions;
SELECT COUNT(*) as total_plans FROM subscription_plans;
SELECT COUNT(*) as total_businesses FROM businessses;

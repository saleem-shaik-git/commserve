-- Phase 5 hardening: safe scheduler claiming and payment metadata (idempotent).

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scheduled_payments' AND COLUMN_NAME = 'last_run_at') = 0,
  'ALTER TABLE scheduled_payments ADD COLUMN last_run_at DATETIME NULL AFTER next_run_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scheduled_payments' AND COLUMN_NAME = 'last_error') = 0,
  'ALTER TABLE scheduled_payments ADD COLUMN last_error VARCHAR(500) NULL AFTER last_run_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE scheduled_payments
  MODIFY status ENUM('active','paused','cancelled','processing','completed') NOT NULL DEFAULT 'active';

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bill_payments' AND COLUMN_NAME = 'provider_reference') = 0,
  'ALTER TABLE bill_payments ADD COLUMN provider_reference VARCHAR(120) NULL AFTER customer_reference',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bill_payments' AND COLUMN_NAME = 'failure_reason') = 0,
  'ALTER TABLE bill_payments ADD COLUMN failure_reason VARCHAR(255) NULL AFTER status',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scheduled_payments' AND INDEX_NAME = 'idx_scheduled_claim') = 0,
  'CREATE INDEX idx_scheduled_claim ON scheduled_payments(status,next_run_at,id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

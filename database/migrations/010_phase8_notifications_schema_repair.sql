-- Phase 8 schema repair.
-- 009_phase8_notifications.sql used CREATE TABLE IF NOT EXISTS, which does not
-- upgrade an older notifications table that already existed. This migration
-- adds the columns required by NotificationService without dropping data.
-- Statements are guarded with information_schema checks so the file is portable
-- across MySQL 8 and MariaDB (no ADD COLUMN IF NOT EXISTS / CREATE INDEX IF NOT EXISTS).

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'channel') = 0,
  'ALTER TABLE notifications ADD COLUMN channel ENUM(''in_app'',''email'',''sms'') NOT NULL DEFAULT ''in_app'' AFTER user_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'event_type') = 0,
  'ALTER TABLE notifications ADD COLUMN event_type VARCHAR(80) NOT NULL DEFAULT ''general'' AFTER channel',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'title') = 0,
  'ALTER TABLE notifications ADD COLUMN title VARCHAR(180) NOT NULL DEFAULT ''Notification'' AFTER event_type',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'message') = 0,
  'ALTER TABLE notifications ADD COLUMN message TEXT NOT NULL AFTER title',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'entity_type') = 0,
  'ALTER TABLE notifications ADD COLUMN entity_type VARCHAR(50) NULL AFTER message',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'entity_id') = 0,
  'ALTER TABLE notifications ADD COLUMN entity_id BIGINT UNSIGNED NULL AFTER entity_type',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'status') = 0,
  'ALTER TABLE notifications ADD COLUMN status ENUM(''queued'',''sent'',''failed'',''read'') NOT NULL DEFAULT ''queued'' AFTER entity_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'attempts') = 0,
  'ALTER TABLE notifications ADD COLUMN attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER status',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'available_at') = 0,
  'ALTER TABLE notifications ADD COLUMN available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER attempts',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'sent_at') = 0,
  'ALTER TABLE notifications ADD COLUMN sent_at TIMESTAMP NULL AFTER available_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'read_at') = 0,
  'ALTER TABLE notifications ADD COLUMN read_at TIMESTAMP NULL AFTER sent_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'last_error') = 0,
  'ALTER TABLE notifications ADD COLUMN last_error VARCHAR(500) NULL AFTER read_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'created_at') = 0,
  'ALTER TABLE notifications ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER last_error',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'idx_notification_user_status') = 0,
  'CREATE INDEX idx_notification_user_status ON notifications(user_id,status,created_at)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'idx_notification_queue') = 0,
  'CREATE INDEX idx_notification_queue ON notifications(status,available_at)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO notification_templates(event_type,channel,title_template,body_template) VALUES
('card_issued','in_app','Card issued','Your {{card_type}} {{network}} card has been issued. Reference: {{reference}}.'),
('card_issued','email','Card issued','Your {{card_type}} {{network}} card has been issued. Reference: {{reference}}.'),
('risk_alert','email','Security alert','A simulated compliance risk alert requires your attention. Reference: {{reference}}.'),
('kyc_status_changed','email','KYC status updated','Your KYC status is now {{status}}.'),
('atm_withdrawal_completed','email','ATM withdrawal completed','Your ATM withdrawal of {{amount}} was completed. Reference: {{reference}}.'),
('transfer_failed','email','Transfer failed','Your transfer of {{amount}} could not be completed. Reference: {{reference}}.');

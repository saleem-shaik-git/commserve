-- Phase 8 schema repair.
-- 009_phase8_notifications.sql used CREATE TABLE IF NOT EXISTS, which does not
-- upgrade an older notifications table that already existed. This migration
-- adds the columns required by NotificationService without dropping data.

ALTER TABLE notifications ADD COLUMN IF NOT EXISTS channel ENUM('in_app','email','sms') NOT NULL DEFAULT 'in_app' AFTER user_id;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS event_type VARCHAR(80) NOT NULL DEFAULT 'general' AFTER channel;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS title VARCHAR(180) NOT NULL DEFAULT 'Notification' AFTER event_type;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS message TEXT NOT NULL AFTER title;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS entity_type VARCHAR(50) NULL AFTER message;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS entity_id BIGINT UNSIGNED NULL AFTER entity_type;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS status ENUM('queued','sent','failed','read') NOT NULL DEFAULT 'queued' AFTER entity_id;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER status;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER attempts;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS sent_at TIMESTAMP NULL AFTER available_at;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS read_at TIMESTAMP NULL AFTER sent_at;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS last_error VARCHAR(500) NULL AFTER read_at;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER last_error;

CREATE INDEX IF NOT EXISTS idx_notification_user_status ON notifications(user_id,status,created_at);
CREATE INDEX IF NOT EXISTS idx_notification_queue ON notifications(status,available_at);

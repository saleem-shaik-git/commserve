CREATE TABLE IF NOT EXISTS notification_preferences (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  in_app_enabled TINYINT(1) NOT NULL DEFAULT 1,
  email_enabled TINYINT(1) NOT NULL DEFAULT 1,
  sms_enabled TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_notification_preference(user_id,event_type),
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  channel ENUM('in_app','email','sms') NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  title VARCHAR(180) NOT NULL,
  message TEXT NOT NULL,
  entity_type VARCHAR(50) NULL,
  entity_id BIGINT UNSIGNED NULL,
  status ENUM('queued','sent','failed','read') NOT NULL DEFAULT 'queued',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  available_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  sent_at TIMESTAMP NULL,
  read_at TIMESTAMP NULL,
  last_error VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notification_user_status(user_id,status,created_at),
  INDEX idx_notification_queue(status,available_at),
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS notification_templates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(80) NOT NULL,
  channel ENUM('in_app','email','sms') NOT NULL,
  title_template VARCHAR(180) NOT NULL,
  body_template TEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_notification_template(event_type,channel)
);

CREATE TABLE IF NOT EXISTS alert_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(80) NOT NULL,
  severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_alert_rule(event_type)
);

INSERT IGNORE INTO notification_templates(event_type,channel,title_template,body_template) VALUES
('transfer_completed','in_app','Transfer completed','Your transfer of {{amount}} to {{recipient}} was completed. Reference: {{reference}}.'),
('transfer_failed','in_app','Transfer failed','Your transfer of {{amount}} could not be completed. Reference: {{reference}}.'),
('bill_payment_completed','in_app','Bill payment completed','Your {{biller}} payment of {{amount}} was completed. Reference: {{reference}}.'),
('card_payment_completed','in_app','Card payment completed','Your card payment of {{amount}} at {{merchant}} was completed. Reference: {{reference}}.'),
('atm_withdrawal_completed','in_app','ATM withdrawal completed','Your ATM withdrawal of {{amount}} was completed. Reference: {{reference}}.'),
('risk_alert','in_app','Security alert','A security/risk event requires your attention. Reference: {{reference}}.'),
('kyc_status_changed','in_app','KYC status updated','Your KYC status is now {{status}}.');

INSERT IGNORE INTO notification_templates(event_type,channel,title_template,body_template) VALUES
('transfer_completed','email','Transfer completed','Your transfer of {{amount}} to {{recipient}} was completed. Reference: {{reference}}.'),
('bill_payment_completed','email','Bill payment completed','Your {{biller}} payment of {{amount}} was completed. Reference: {{reference}}.'),
('card_payment_completed','email','Card payment completed','Your card payment of {{amount}} at {{merchant}} was completed. Reference: {{reference}}.');

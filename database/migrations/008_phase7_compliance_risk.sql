-- Phase 7: simulated compliance, KYC and transaction risk controls.
CREATE TABLE IF NOT EXISTS customer_kyc (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','verified','rejected','requires_review') NOT NULL DEFAULT 'pending',
  document_type VARCHAR(50) NULL,
  document_reference VARCHAR(100) NULL,
  full_name VARCHAR(180) NULL,
  date_of_birth DATE NULL,
  phone VARCHAR(40) NULL,
  address VARCHAR(255) NULL,
  country CHAR(2) NULL,
  submitted_at TIMESTAMP NULL,
  reviewed_at TIMESTAMP NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  review_notes VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_customer_kyc_user(user_id),
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_kyc_status(status)
);

CREATE TABLE IF NOT EXISTS risk_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rule_code VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NOT NULL,
  threshold_amount DECIMAL(19,4) NULL,
  risk_level ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS risk_alerts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  transaction_id BIGINT UNSIGNED NULL,
  rule_code VARCHAR(50) NOT NULL,
  risk_level ENUM('low','medium','high','critical') NOT NULL,
  status ENUM('open','reviewing','cleared','blocked') NOT NULL DEFAULT 'open',
  score INT UNSIGNED NOT NULL DEFAULT 0,
  reason VARCHAR(500) NOT NULL,
  metadata JSON NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
  FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_risk_alert_status(status,created_at),
  INDEX idx_risk_alert_user(user_id,created_at),
  INDEX idx_risk_alert_tx(transaction_id)
);

CREATE TABLE IF NOT EXISTS compliance_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(60) NOT NULL,
  severity ENUM('info','warning','high','critical') NOT NULL DEFAULT 'info',
  description VARCHAR(500) NOT NULL,
  metadata JSON NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_compliance_user_created(user_id,created_at),
  INDEX idx_compliance_type_created(event_type,created_at)
);

INSERT IGNORE INTO risk_rules(rule_code,name,description,threshold_amount,risk_level,enabled) VALUES
('HIGH_VALUE_TRANSFER','High value transfer','Transfer exceeds configured high-value threshold',1000000,'high',1),
('VERY_HIGH_VALUE','Very high value transaction','Transaction exceeds critical threshold',5000000,'critical',1),
('VELOCITY_10_TX_24H','Transaction velocity','More than 10 outgoing transactions in 24 hours',NULL,'medium',1),
('VELOCITY_20_TX_24H','High transaction velocity','More than 20 outgoing transactions in 24 hours',NULL,'high',1),
('NEW_ACCOUNT_ACTIVITY','New account activity','High-value activity from an account created recently',500000,'medium',1),
('KYC_REQUIRED','KYC required','Customer has not completed simulated KYC verification',NULL,'high',1);

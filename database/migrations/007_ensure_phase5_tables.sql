-- Safety net: create Phase 5 payment tables if an earlier migration was skipped or rolled back.

ALTER TABLE transactions MODIFY type ENUM('deposit','withdrawal','transfer','fee','refund','reversal','bill_payment') NOT NULL;

CREATE TABLE IF NOT EXISTS billers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(150) NOT NULL,
  category ENUM('electricity','internet','cable','airtime','data','water','other') NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_biller_code(code)
);

CREATE TABLE IF NOT EXISTS bill_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  biller_id BIGINT UNSIGNED NOT NULL,
  transaction_id BIGINT UNSIGNED NULL,
  customer_reference VARCHAR(120) NOT NULL,
  provider_reference VARCHAR(120) NULL,
  amount DECIMAL(19,4) NOT NULL,
  currency CHAR(3) NOT NULL,
  status ENUM('pending','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
  failure_reason VARCHAR(255) NULL,
  idempotency_key VARCHAR(100) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
  FOREIGN KEY (biller_id) REFERENCES billers(id) ON DELETE RESTRICT,
  FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
  UNIQUE KEY uq_bill_payment_idempotency (user_id,idempotency_key),
  INDEX idx_bill_user_created (user_id,created_at),
  INDEX idx_bill_status (status)
);

CREATE TABLE IF NOT EXISTS scheduled_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  from_account_id BIGINT UNSIGNED NOT NULL,
  beneficiary_id BIGINT UNSIGNED NULL,
  biller_id BIGINT UNSIGNED NULL,
  amount DECIMAL(19,4) NOT NULL,
  currency CHAR(3) NOT NULL,
  frequency ENUM('once','daily','weekly','monthly') NOT NULL,
  next_run_at DATETIME NOT NULL,
  last_run_at DATETIME NULL,
  last_error VARCHAR(500) NULL,
  status ENUM('active','paused','cancelled','processing','completed') NOT NULL DEFAULT 'active',
  description VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (from_account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
  FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(id) ON DELETE SET NULL,
  FOREIGN KEY (biller_id) REFERENCES billers(id) ON DELETE SET NULL,
  INDEX idx_scheduled_due (status,next_run_at),
  INDEX idx_scheduled_user (user_id,status)
);

INSERT IGNORE INTO billers(code,name,category,status) VALUES
('COMM_ELECTRICITY','CommServe Electricity','electricity','active'),
('COMM_INTERNET','CommServe Internet','internet','active'),
('COMM_CABLE','CommServe Cable','cable','active'),
('COMM_AIRTIME','CommServe Airtime','airtime','active'),
('COMM_DATA','CommServe Data','data','active'),
('COMM_WATER','CommServe Water','water','active');

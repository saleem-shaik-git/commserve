-- Phase 6: simulated virtual/debit cards and ATM operations.
ALTER TABLE transactions MODIFY type ENUM('deposit','withdrawal','transfer','fee','refund','reversal','bill_payment','card_payment','atm_withdrawal') NOT NULL;

CREATE TABLE IF NOT EXISTS cards (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  card_type ENUM('virtual','debit') NOT NULL DEFAULT 'virtual',
  network ENUM('demo_visa','demo_mastercard') NOT NULL DEFAULT 'demo_visa',
  pan_hash CHAR(64) NOT NULL,
  last4 CHAR(4) NOT NULL,
  expiry_month TINYINT UNSIGNED NOT NULL,
  expiry_year SMALLINT UNSIGNED NOT NULL,
  cvv_hash CHAR(64) NOT NULL,
  pin_hash CHAR(64) NULL,
  status ENUM('active','frozen','blocked','expired') NOT NULL DEFAULT 'active',
  daily_limit DECIMAL(19,4) NOT NULL DEFAULT 500000,
  per_transaction_limit DECIMAL(19,4) NOT NULL DEFAULT 200000,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_card_pan_hash(pan_hash),
  INDEX idx_cards_user_status(user_id,status),
  INDEX idx_cards_account(account_id)
);

CREATE TABLE IF NOT EXISTS card_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  card_id BIGINT UNSIGNED NOT NULL,
  transaction_id BIGINT UNSIGNED NOT NULL,
  merchant_name VARCHAR(160) NULL,
  channel ENUM('online','pos','atm') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(card_id) REFERENCES cards(id) ON DELETE RESTRICT,
  FOREIGN KEY(transaction_id) REFERENCES transactions(id) ON DELETE RESTRICT,
  INDEX idx_card_transactions_card_created(card_id,created_at)
);

CREATE TABLE IF NOT EXISTS card_payment_idempotency (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  card_id BIGINT UNSIGNED NOT NULL,
  idempotency_key VARCHAR(100) NOT NULL,
  transaction_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(card_id) REFERENCES cards(id) ON DELETE CASCADE,
  FOREIGN KEY(transaction_id) REFERENCES transactions(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_card_idempotency(card_id,idempotency_key)
);

CREATE TABLE IF NOT EXISTS atm_terminals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  terminal_code VARCHAR(30) NOT NULL UNIQUE,
  location VARCHAR(160) NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO atm_terminals(terminal_code,location,status) VALUES
('COMM-ATM-001','CommServe Demo Branch - Lagos','active'),
('COMM-ATM-002','CommServe Demo Branch - Ogun','active');

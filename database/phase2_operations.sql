USE commserve;

ALTER TABLE transactions
  ADD COLUMN reversal_of_transaction_id BIGINT UNSIGNED NULL AFTER completed_at,
  ADD COLUMN refund_of_transaction_id BIGINT UNSIGNED NULL AFTER reversal_of_transaction_id,
  ADD COLUMN idempotency_key VARCHAR(100) NULL AFTER refund_of_transaction_id,
  ADD COLUMN failure_reason VARCHAR(255) NULL AFTER idempotency_key,
  ADD COLUMN initiated_by BIGINT UNSIGNED NULL AFTER failure_reason,
  ADD UNIQUE KEY uq_transactions_idempotency (idempotency_key),
  ADD KEY idx_transactions_status_created (status, created_at),
  ADD KEY idx_transactions_initiated_by (initiated_by),
  ADD CONSTRAINT fk_txn_reversal FOREIGN KEY (reversal_of_transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_txn_refund FOREIGN KEY (refund_of_transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_txn_initiated_by FOREIGN KEY (initiated_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE beneficiaries
  ADD COLUMN status ENUM('active','disabled') NOT NULL DEFAULT 'active' AFTER bank_name,
  ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD KEY idx_beneficiaries_user_status (user_id,status);

CREATE TABLE IF NOT EXISTS transaction_otp_challenges (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  otp_hash CHAR(64) NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at TIMESTAMP NOT NULL,
  verified_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX(transaction_id), INDEX(user_id,created_at)
);

CREATE TABLE IF NOT EXISTS transaction_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(50) NOT NULL,
  old_status VARCHAR(30) NULL,
  new_status VARCHAR(30) NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  metadata JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
  FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX(transaction_id,created_at)
);

CREATE TABLE IF NOT EXISTS transfer_limits (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  daily_limit DECIMAL(19,4) NOT NULL DEFAULT 5000000,
  per_transaction_limit DECIMAL(19,4) NOT NULL DEFAULT 1000000,
  currency CHAR(3) NOT NULL DEFAULT 'NGN',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_transfer_limit_user_currency (user_id,currency),
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS account_balance_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id BIGINT UNSIGNED NOT NULL,
  balance DECIMAL(19,4) NOT NULL,
  snapshot_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  INDEX(account_id,snapshot_at)
);

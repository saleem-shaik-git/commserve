-- Customer Internet Banking - pending transfers and statement support
CREATE TABLE IF NOT EXISTS pending_transfers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id BIGINT UNSIGNED NOT NULL,
  from_account_id BIGINT UNSIGNED NOT NULL,
  to_account_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
  FOREIGN KEY (from_account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
  FOREIGN KEY (to_account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_pending_transfer_txn (transaction_id),
  INDEX idx_pending_from (from_account_id),
  INDEX idx_pending_to (to_account_id)
);

CREATE TABLE IF NOT EXISTS statement_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  type ENUM('pdf','csv') NOT NULL,
  from_date DATE NULL,
  to_date DATE NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  INDEX idx_stmt_user (user_id, created_at)
);

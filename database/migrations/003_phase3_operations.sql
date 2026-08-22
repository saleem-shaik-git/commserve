CREATE TABLE IF NOT EXISTS admin_action_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action_type VARCHAR(50) NOT NULL,
  entity_type VARCHAR(50) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  requested_by BIGINT UNSIGNED NOT NULL,
  approved_by BIGINT UNSIGNED NULL,
  status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  reason VARCHAR(500) NOT NULL,
  decision_reason VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  decided_at TIMESTAMP NULL,
  FOREIGN KEY(requested_by) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY(approved_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX(status,created_at), INDEX(entity_type,entity_id)
);

CREATE TABLE IF NOT EXISTS admin_transfer_limits (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  currency CHAR(3) NOT NULL,
  daily_limit DECIMAL(19,4) NOT NULL,
  per_transaction_limit DECIMAL(19,4) NOT NULL,
  updated_by BIGINT UNSIGNED NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admin_transfer_limit_currency(currency),
  FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE RESTRICT
);

INSERT IGNORE INTO admin_transfer_limits(currency,daily_limit,per_transaction_limit,updated_by)
SELECT 'NGN',5000000,1000000,id FROM users WHERE role_id=(SELECT id FROM roles WHERE name='admin') ORDER BY id LIMIT 1;

CREATE TABLE IF NOT EXISTS security_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NULL,
 event_type VARCHAR(80) NOT NULL,
 severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
 ip_address VARCHAR(45) NULL,
 user_agent VARCHAR(500) NULL,
 details JSON NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_security_events_user(user_id,created_at),
 INDEX idx_security_events_type(event_type,created_at),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS user_security_settings (
 user_id BIGINT UNSIGNED PRIMARY KEY,
 login_alerts TINYINT(1) NOT NULL DEFAULT 1,
 transaction_alerts TINYINT(1) NOT NULL DEFAULT 1,
 require_reauth_for_security_changes TINYINT(1) NOT NULL DEFAULT 1,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
ALTER TABLE users ADD COLUMN locked_until TIMESTAMP NULL;
ALTER TABLE users ADD COLUMN password_changed_at TIMESTAMP NULL;
CREATE INDEX idx_login_attempts_ip_created ON login_attempts(ip_address,created_at);
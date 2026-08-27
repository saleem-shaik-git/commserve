CREATE TABLE IF NOT EXISTS crypto_assets (
 id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 symbol VARCHAR(12) NOT NULL UNIQUE,
 name VARCHAR(80) NOT NULL,
 decimals TINYINT UNSIGNED NOT NULL DEFAULT 8,
 usd_rate DECIMAL(24,8) NOT NULL DEFAULT 0,
 active BOOLEAN NOT NULL DEFAULT TRUE,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS crypto_wallets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 asset_id TINYINT UNSIGNED NOT NULL,
 address VARCHAR(128) NOT NULL UNIQUE,
 balance DECIMAL(36,18) NOT NULL DEFAULT 0,
 status ENUM('active','frozen') NOT NULL DEFAULT 'active',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_crypto_user_asset(user_id,asset_id),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY(asset_id) REFERENCES crypto_assets(id)
);

CREATE TABLE IF NOT EXISTS crypto_transactions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 reference VARCHAR(48) NOT NULL UNIQUE,
 user_id BIGINT UNSIGNED NOT NULL,
 asset_id TINYINT UNSIGNED NOT NULL,
 type ENUM('receive','send','convert') NOT NULL,
 status ENUM('pending','completed','failed','cancelled') NOT NULL DEFAULT 'completed',
 amount DECIMAL(36,18) NOT NULL,
 fee DECIMAL(36,18) NOT NULL DEFAULT 0,
 counter_asset_id TINYINT UNSIGNED NULL,
 counter_amount DECIMAL(36,18) NULL,
 from_address VARCHAR(128) NULL,
 to_address VARCHAR(128) NULL,
 description VARCHAR(255) NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 completed_at TIMESTAMP NULL,
 INDEX idx_crypto_user_date(user_id,created_at),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY(asset_id) REFERENCES crypto_assets(id),
 FOREIGN KEY(counter_asset_id) REFERENCES crypto_assets(id)
);

INSERT INTO crypto_assets(symbol,name,decimals,usd_rate) VALUES
('BTC','Bitcoin',8,65000.00000000),
('ETH','Ethereum',8,3200.00000000),
('USDT','Tether USD',6,1.00000000),
('USDC','USD Coin',6,1.00000000),
('XRP','XRP',6,0.55000000)
ON DUPLICATE KEY UPDATE name=VALUES(name),decimals=VALUES(decimals),usd_rate=VALUES(usd_rate),active=TRUE;

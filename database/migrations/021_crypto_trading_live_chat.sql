-- Phase 14: live-rate crypto trading (buy/sell against fiat balance) and
-- customer <-> admin live chat.
--
-- crypto_assets gains a CoinGecko id plus cached live rates (falls back to the
-- bank reference rate when no outbound internet is available).
-- crypto_transactions type extended with 'buy' and 'sell'.

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crypto_assets' AND COLUMN_NAME = 'coingecko_id') = 0,
  'ALTER TABLE crypto_assets ADD COLUMN coingecko_id VARCHAR(50) NULL AFTER name',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crypto_assets' AND COLUMN_NAME = 'live_usd_rate') = 0,
  'ALTER TABLE crypto_assets ADD COLUMN live_usd_rate DECIMAL(24,8) NULL AFTER usd_rate',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crypto_assets' AND COLUMN_NAME = 'live_rate_at') = 0,
  'ALTER TABLE crypto_assets ADD COLUMN live_rate_at TIMESTAMP NULL DEFAULT NULL AFTER live_usd_rate',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE crypto_assets SET coingecko_id='bitcoin'  WHERE symbol='BTC' AND coingecko_id IS NULL;
UPDATE crypto_assets SET coingecko_id='ethereum' WHERE symbol='ETH' AND coingecko_id IS NULL;
UPDATE crypto_assets SET coingecko_id='tether'   WHERE symbol='USDT' AND coingecko_id IS NULL;
UPDATE crypto_assets SET coingecko_id='usd-coin' WHERE symbol='USDC' AND coingecko_id IS NULL;
UPDATE crypto_assets SET coingecko_id='ripple'   WHERE symbol='XRP' AND coingecko_id IS NULL;

ALTER TABLE crypto_transactions
  MODIFY type ENUM('receive','send','convert','buy','sell') NOT NULL;

-- ---------------------------------------------------------------------------
-- Live chat between customers and administrators
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS chat_threads (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  subject VARCHAR(200) NOT NULL DEFAULT '',
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  last_message_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_chat_thread_user(user_id,last_message_at),
  INDEX idx_chat_thread_status(status,last_message_at)
);

CREATE TABLE IF NOT EXISTS chat_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  thread_id BIGINT UNSIGNED NOT NULL,
  sender_user_id BIGINT UNSIGNED NOT NULL,
  sender_role ENUM('customer','admin') NOT NULL,
  body TEXT NOT NULL,
  read_by_customer TINYINT(1) NOT NULL DEFAULT 0,
  read_by_admin TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(thread_id) REFERENCES chat_threads(id) ON DELETE CASCADE,
  FOREIGN KEY(sender_user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_chat_message_thread(thread_id,id),
  INDEX idx_chat_message_unread_admin(sender_role,read_by_admin),
  INDEX idx_chat_message_unread_customer(sender_role,read_by_customer)
);

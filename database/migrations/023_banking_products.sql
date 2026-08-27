-- Phase 16: retail banking products - savings products, interest engine,
-- fixed deposits, loans/credit scoring, customer lifecycle.
--
-- Extends transactions.type with the crypto trading types (021) and the new
-- banking-product types.

ALTER TABLE transactions
  MODIFY type ENUM('deposit','withdrawal','transfer','fee','refund','reversal','bill_payment','card_payment','atm_withdrawal','crypto_purchase','crypto_sale','interest_credit','account_opening','fixed_deposit','fixed_deposit_payout','loan_disbursement','loan_repayment') NOT NULL;

-- ---------------------------------------------------------------------------
-- Savings products
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS savings_products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  interest_rate DECIMAL(6,3) NOT NULL DEFAULT 0 COMMENT 'Annual %, simulated',
  min_opening_balance DECIMAL(19,4) NOT NULL DEFAULT 0,
  min_daily_balance DECIMAL(19,4) NOT NULL DEFAULT 0,
  calc_frequency ENUM('daily','monthly') NOT NULL DEFAULT 'monthly',
  withdrawal_restriction ENUM('none','limited','restricted','locked') NOT NULL DEFAULT 'none',
  max_withdrawals_per_month INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = unlimited (applies to limited)',
  is_term BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Fixed/term deposit product',
  default_term_days INT UNSIGNED NULL,
  early_penalty_pct DECIMAL(5,2) NOT NULL DEFAULT 30.00 COMMENT '% of accrued interest forfeited on early withdrawal',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  sort INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO savings_products(code,name,interest_rate,min_opening_balance,min_daily_balance,calc_frequency,withdrawal_restriction,max_withdrawals_per_month,is_term,default_term_days,early_penalty_pct,sort) VALUES
('REGULAR',  'Regular Savings',   4.000,   5000,     0, 'monthly','none',      0, FALSE, NULL, 0.00, 1),
('PREMIUM',  'Premium Savings',   6.000, 100000, 50000, 'monthly','limited',   4, FALSE, NULL, 0.00, 2),
('TARGET',   'Target Savings',    7.000,  10000,  5000, 'monthly','restricted',2, FALSE,  365, 50.00, 3),
('FIXED12',  'Fixed Deposit 12M',10.000, 100000,     0, 'monthly','locked',    0, TRUE,   365, 30.00, 4),
('FIXED90',  'Fixed Deposit 90D', 8.000,    500,     0, 'monthly','locked',    0, TRUE,    90, 30.00, 5),
('STUDENT',  'Student Savings',   3.000,    100,     0, 'daily',  'none',      0, FALSE, NULL, 0.00, 6),
('BUSINESS', 'Business Savings',  5.000,  50000, 10000, 'monthly','limited',   6, FALSE, NULL, 0.00, 7)
ON DUPLICATE KEY UPDATE name=VALUES(name);

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts' AND COLUMN_NAME = 'savings_product_id') = 0,
  'ALTER TABLE accounts ADD COLUMN savings_product_id INT UNSIGNED NULL DEFAULT NULL AFTER account_type_id, ADD CONSTRAINT fk_account_savings_product FOREIGN KEY (savings_product_id) REFERENCES savings_products(id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS interest_postings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id BIGINT UNSIGNED NOT NULL,
  savings_product_id INT UNSIGNED NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  days INT UNSIGNED NOT NULL,
  avg_daily_balance DECIMAL(19,4) NOT NULL,
  annual_rate DECIMAL(6,3) NOT NULL,
  amount DECIMAL(19,4) NOT NULL,
  reference VARCHAR(48) NOT NULL UNIQUE,
  transaction_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_interest_period(account_id,period_end),
  FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  FOREIGN KEY(savings_product_id) REFERENCES savings_products(id) ON DELETE SET NULL,
  FOREIGN KEY(transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
);

-- ---------------------------------------------------------------------------
-- Fixed deposits
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS fixed_deposits (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL COMMENT 'Funding/payout account',
  savings_product_id INT UNSIGNED NOT NULL,
  reference VARCHAR(48) NOT NULL UNIQUE,
  principal DECIMAL(19,4) NOT NULL,
  annual_rate DECIMAL(6,3) NOT NULL,
  term_days INT UNSIGNED NOT NULL,
  penalty_pct DECIMAL(5,2) NOT NULL DEFAULT 30.00,
  interest_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
  maturity_value DECIMAL(19,4) NOT NULL DEFAULT 0,
  start_date DATE NOT NULL,
  maturity_date DATE NOT NULL,
  status ENUM('active','matured','closed','withdrawn_early') NOT NULL DEFAULT 'active',
  penalty_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
  payout_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
  payout_reference VARCHAR(48) NULL,
  closed_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(account_id) REFERENCES accounts(id),
  FOREIGN KEY(savings_product_id) REFERENCES savings_products(id)
);

-- ---------------------------------------------------------------------------
-- Lending
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS loan_products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  annual_rate DECIMAL(6,3) NOT NULL,
  min_amount DECIMAL(19,4) NOT NULL,
  max_amount DECIMAL(19,4) NOT NULL,
  min_tenor_months INT UNSIGNED NOT NULL DEFAULT 1,
  max_tenor_months INT UNSIGNED NOT NULL DEFAULT 60,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active'
);

INSERT INTO loan_products(code,name,annual_rate,min_amount,max_amount,min_tenor_months,max_tenor_months) VALUES
('PERSONAL','Personal Loan',12.000,  1000, 5000000, 3, 60),
('BUSINESS','Business Loan',15.000, 10000,20000000, 6, 60),
('STUDENT', 'Student Loan',  6.000,   500,  200000, 6, 84)
ON DUPLICATE KEY UPDATE name=VALUES(name);

CREATE TABLE IF NOT EXISTS loan_applications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  loan_product_id INT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NULL COMMENT 'Disbursement / repayment account chosen by the customer',
  amount DECIMAL(19,4) NOT NULL,
  tenor_months INT UNSIGNED NOT NULL,
  purpose VARCHAR(255) NULL,
  status ENUM('pending','under_review','approved','rejected','disbursed') NOT NULL DEFAULT 'pending',
  credit_score INT UNSIGNED NULL,
  decision_reason VARCHAR(255) NULL,
  decided_by BIGINT UNSIGNED NULL,
  decided_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(loan_product_id) REFERENCES loan_products(id),
  FOREIGN KEY(account_id) REFERENCES accounts(id),
  FOREIGN KEY(decided_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS loans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(48) NOT NULL UNIQUE,
  application_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL COMMENT 'Disbursement / repayment account',
  loan_product_id INT UNSIGNED NOT NULL,
  principal DECIMAL(19,4) NOT NULL,
  annual_rate DECIMAL(6,3) NOT NULL,
  tenor_months INT UNSIGNED NOT NULL,
  monthly_payment DECIMAL(19,4) NOT NULL,
  total_interest DECIMAL(19,4) NOT NULL,
  outstanding_principal DECIMAL(19,4) NOT NULL,
  status ENUM('active','completed','defaulted','written_off') NOT NULL DEFAULT 'active',
  next_due_date DATE NULL,
  late_count INT UNSIGNED NOT NULL DEFAULT 0,
  start_date DATE NOT NULL,
  completed_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(application_id) REFERENCES loan_applications(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(account_id) REFERENCES accounts(id),
  FOREIGN KEY(loan_product_id) REFERENCES loan_products(id)
);

CREATE TABLE IF NOT EXISTS loan_schedule (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  loan_id BIGINT UNSIGNED NOT NULL,
  installment_no INT UNSIGNED NOT NULL,
  due_date DATE NOT NULL,
  principal_due DECIMAL(19,4) NOT NULL,
  interest_due DECIMAL(19,4) NOT NULL,
  total_due DECIMAL(19,4) NOT NULL,
  paid_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
  status ENUM('pending','paid','partial','late','defaulted') NOT NULL DEFAULT 'pending',
  paid_at TIMESTAMP NULL,
  UNIQUE KEY uq_loan_installment(loan_id,installment_no),
  FOREIGN KEY(loan_id) REFERENCES loans(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS loan_repayments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(48) NOT NULL UNIQUE,
  loan_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(19,4) NOT NULL,
  principal_part DECIMAL(19,4) NOT NULL,
  interest_part DECIMAL(19,4) NOT NULL,
  transaction_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(loan_id) REFERENCES loans(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
);

-- ---------------------------------------------------------------------------
-- Credit scoring cache + customer lifecycle override
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS credit_scores (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  score INT UNSIGNED NOT NULL,
  band VARCHAR(20) NOT NULL,
  factors JSON NULL,
  computed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_credit_user(user_id),
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'lifecycle_override') = 0,
  'ALTER TABLE users ADD COLUMN lifecycle_override ENUM('''',''restricted'',''closed'') NOT NULL DEFAULT '''' AFTER status',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

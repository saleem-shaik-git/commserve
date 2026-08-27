USE commserve;
INSERT INTO roles (name) VALUES ('customer'),('admin') ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO account_types (name,currency,minimum_balance) VALUES ('Savings','USD',0),('Current','USD',0) ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Demo password for all seeded users: password
SET @pw = '$2y$12$e168JUMgLVhnQ6L7JGrdu.KvDbGx0c/SFSI9caGTzsMYyemiKc7BC';
INSERT INTO users (role_id,email,password_hash,first_name,last_name,phone) VALUES
((SELECT id FROM roles WHERE name='admin'),'admin@commserve.test',@pw,'System','Administrator','08000000000'),
((SELECT id FROM roles WHERE name='customer'),'john@commserve.test',@pw,'John','Doe','08000000001'),
((SELECT id FROM roles WHERE name='customer'),'jane@commserve.test',@pw,'Jane','Doe','08000000002');
INSERT INTO accounts (user_id,account_type_id,account_number,available_balance) VALUES
((SELECT id FROM users WHERE email='john@commserve.test'),(SELECT id FROM account_types WHERE name='Savings'),'0100000001',2500000),
((SELECT id FROM users WHERE email='john@commserve.test'),(SELECT id FROM account_types WHERE name='Current'),'0100000002',500000),
((SELECT id FROM users WHERE email='jane@commserve.test'),(SELECT id FROM account_types WHERE name='Savings'),'0100000003',1250000);

-- Opening simulated balances are represented in the ledger so the ledger remains the source of truth.
INSERT INTO transactions (reference,type,status,amount,currency,description,completed_at)
SELECT CONCAT('OPEN-',a.account_number),'deposit','completed',a.available_balance,'USD','Opening simulated balance',CURRENT_TIMESTAMP
FROM accounts a
WHERE a.account_number IN ('0100000001','0100000002','0100000003')
  AND NOT EXISTS (SELECT 1 FROM transactions t WHERE t.reference=CONCAT('OPEN-',a.account_number));

INSERT INTO ledger_entries (transaction_id,account_id,entry_type,amount)
SELECT t.id,a.id,'credit',a.available_balance
FROM accounts a JOIN transactions t ON t.reference=CONCAT('OPEN-',a.account_number)
WHERE a.account_number IN ('0100000001','0100000002','0100000003')
  AND NOT EXISTS (SELECT 1 FROM ledger_entries le WHERE le.transaction_id=t.id AND le.account_id=a.id);

USE commserve;
INSERT INTO roles (name) VALUES ('customer'),('admin') ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO account_types (name,currency,minimum_balance) VALUES ('Savings','NGN',0),('Current','NGN',0) ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Demo password for all seeded users: password
SET @pw = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4K3ZJ1NqJ3R1Z9F3J6rJ5Q6rGQf5a';
INSERT INTO users (role_id,email,password_hash,first_name,last_name,phone) VALUES
((SELECT id FROM roles WHERE name='admin'),'admin@commserve.test',@pw,'System','Administrator','08000000000'),
((SELECT id FROM roles WHERE name='customer'),'john@commserve.test',@pw,'John','Doe','08000000001'),
((SELECT id FROM roles WHERE name='customer'),'jane@commserve.test',@pw,'Jane','Doe','08000000002');
INSERT INTO accounts (user_id,account_type_id,account_number,available_balance) VALUES
((SELECT id FROM users WHERE email='john@commserve.test'),(SELECT id FROM account_types WHERE name='Savings'),'0100000001',2500000),
((SELECT id FROM users WHERE email='john@commserve.test'),(SELECT id FROM account_types WHERE name='Current'),'0100000002',500000),
((SELECT id FROM users WHERE email='jane@commserve.test'),(SELECT id FROM account_types WHERE name='Savings'),'0100000003',1250000);

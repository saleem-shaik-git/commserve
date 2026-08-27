-- Phase 11.7: normalize admin-configured transfer limits to USD.
-- 015/016 normalized account_types, transactions and transfer_limits but left
-- admin_transfer_limits on the legacy NGN seed row, so the admin Limits page
-- edited a currency that live limit checks never read.

DELETE FROM admin_transfer_limits WHERE currency IS NULL OR currency <> 'USD';

INSERT IGNORE INTO admin_transfer_limits(currency,daily_limit,per_transaction_limit,updated_by)
SELECT 'USD',5000000,1000000,id FROM users WHERE role_id=(SELECT id FROM roles WHERE name='admin') ORDER BY id LIMIT 1;

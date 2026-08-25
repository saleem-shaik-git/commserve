-- Phase 11.5: global default currency and internationalization
ALTER TABLE users ADD COLUMN locale CHAR(5) NOT NULL DEFAULT 'en' AFTER status;
ALTER TABLE account_types MODIFY currency CHAR(3) NOT NULL DEFAULT 'USD';
ALTER TABLE transactions MODIFY currency CHAR(3) NOT NULL DEFAULT 'USD';
ALTER TABLE transfer_limits MODIFY currency CHAR(3) NOT NULL DEFAULT 'USD';

-- Existing demo data is simulated. Normalize existing records to USD.
UPDATE account_types SET currency='USD' WHERE currency='NGN' OR currency IS NULL OR currency='';
UPDATE transactions SET currency='USD' WHERE currency='NGN' OR currency IS NULL OR currency='';
UPDATE transfer_limits SET currency='USD' WHERE currency='NGN' OR currency IS NULL OR currency='';

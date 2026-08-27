-- Phase 18: beneficiaries carry a routing number (informational identifier
-- shown on the transfer screen alongside the account number).

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'beneficiaries' AND COLUMN_NAME = 'routing_number') = 0,
  'ALTER TABLE beneficiaries ADD COLUMN routing_number VARCHAR(20) NULL AFTER account_number',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

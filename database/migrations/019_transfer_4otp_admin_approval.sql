-- Phase 12: four-stage OTP verification for transfers + admin release approval.
--
-- Stage 1: identity verification
-- Stage 2: amount verification
-- Stage 3: beneficiary verification
-- Stage 4: final authorization
--
-- After all four OTPs are verified the transaction moves to 'awaiting_approval'
-- and an admin_action_requests row (action_type='transfer_approval') is created;
-- an admin releases or rejects it from the Approvals screen.

ALTER TABLE transactions
  MODIFY status ENUM('pending','processing','completed','failed','cancelled','reversed','refunded','awaiting_approval') NOT NULL DEFAULT 'pending';

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction_otp_challenges' AND COLUMN_NAME = 'stage') = 0,
  'ALTER TABLE transaction_otp_challenges ADD COLUMN stage TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER user_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction_otp_challenges' AND INDEX_NAME = 'idx_otp_stage') = 0,
  'CREATE INDEX idx_otp_stage ON transaction_otp_challenges(transaction_id,stage,id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO notification_templates(event_type,channel,title_template,body_template) VALUES
('transfer_pending_approval','in_app','Transfer pending approval','Your transfer of {{amount}} to {{recipient}} passed all 4 verification stages and is awaiting admin approval. Reference: {{reference}}.'),
('transfer_pending_approval','email','Transfer pending approval','Your transfer of {{amount}} to {{recipient}} passed all 4 verification stages and is awaiting admin approval. Reference: {{reference}}.'),
('transfer_rejected','in_app','Transfer rejected','Your transfer of {{amount}} was rejected by an administrator. Reference: {{reference}}.'),
('transfer_rejected','email','Transfer rejected','Your transfer of {{amount}} was rejected by an administrator. Reference: {{reference}}.');

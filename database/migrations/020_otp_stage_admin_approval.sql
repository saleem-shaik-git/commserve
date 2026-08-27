-- Phase 13: admin approval for every submitted OTP stage (stages 1-4).
--
-- When a customer submits the OTP for a stage, the challenge is marked
-- verified and queued for administrator approval (admin_action_requests row
-- with action_type='otp_stage_approval'). The transfer only advances:
--   * stages 1-3: admin approval issues the OTP for the next stage;
--   * stage 4:    admin approval releases the transfer (posts the ledger).
--
-- otp_code stores the display copy of the one-time password because the
-- application has no SMS/voice gateway; it is shown on the confirmation page.

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction_otp_challenges' AND COLUMN_NAME = 'admin_status') = 0,
  'ALTER TABLE transaction_otp_challenges ADD COLUMN admin_status ENUM(''pending'',''approved'',''rejected'') NULL DEFAULT NULL AFTER verified_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction_otp_challenges' AND COLUMN_NAME = 'otp_code') = 0,
  'ALTER TABLE transaction_otp_challenges ADD COLUMN otp_code VARCHAR(12) NULL AFTER otp_hash',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction_otp_challenges' AND INDEX_NAME = 'idx_otp_admin') = 0,
  'CREATE INDEX idx_otp_admin ON transaction_otp_challenges(transaction_id,admin_status,id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO notification_templates(event_type,channel,title_template,body_template) VALUES
('otp_stage_approved','in_app','Verification stage approved','Your stage {{stage}} verification ({{label}}) for transfer {{reference}} was approved. The next one-time password is ready on the confirmation page.'),
('otp_stage_approved','email','Verification stage approved','Your stage {{stage}} verification ({{label}}) for transfer {{reference}} was approved. The next one-time password is ready on the confirmation page.'),
('otp_stage_rejected','in_app','Verification stage rejected','Your transfer {{reference}} was rejected at stage {{stage}} ({{label}}). Reason: {{reason}}.'),
('otp_stage_rejected','email','Verification stage rejected','Your transfer {{reference}} was rejected at stage {{stage}} ({{label}}). Reason: {{reason}}.');

-- Reword persisted seeded/administrative text that referenced the demo label.
UPDATE notification_templates
   SET body_template = 'A compliance risk alert requires your attention. Reference: {{reference}}.'
 WHERE event_type = 'risk_alert' AND channel = 'email'
   AND body_template LIKE 'A simulated compliance risk alert%';

UPDATE risk_rules
   SET description = 'Customer has not completed KYC verification'
 WHERE rule_code = 'KYC_REQUIRED' AND description LIKE '%simulated KYC%';

UPDATE atm_terminals
   SET location = REPLACE(location, 'CommServe Demo Branch', 'CommServe Branch')
 WHERE location LIKE '%CommServe Demo Branch%';

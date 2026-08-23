INSERT IGNORE INTO notification_templates(event_type,channel,title_template,body_template) VALUES
('card_issued','in_app','Card issued','Your {{card_type}} {{network}} card has been issued. Reference: {{reference}}.'),
('card_issued','email','Card issued','Your {{card_type}} {{network}} card has been issued. Reference: {{reference}}.'),
('risk_alert','email','Security alert','A simulated compliance risk alert requires your attention. Reference: {{reference}}.'),
('kyc_status_changed','email','KYC status updated','Your KYC status is now {{status}}.'),
('atm_withdrawal_completed','email','ATM withdrawal completed','Your ATM withdrawal of {{amount}} was completed. Reference: {{reference}}.'),
('transfer_failed','email','Transfer failed','Your transfer of {{amount}} could not be completed. Reference: {{reference}}.');

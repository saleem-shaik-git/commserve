-- Phase 15: remove the ATM feature.
-- The customer ATM page, dashboard links, card-service withdrawal path and
-- notification preference entry are gone; this migration drops the terminal
-- catalogue and deactivates the ATM notification templates.

DROP TABLE IF EXISTS atm_terminals;

UPDATE notification_templates SET active=0 WHERE event_type='atm_withdrawal_completed';

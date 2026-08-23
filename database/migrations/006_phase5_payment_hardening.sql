-- Phase 5 hardening: safe scheduler claiming and payment metadata
ALTER TABLE scheduled_payments
  MODIFY status ENUM('active','paused','cancelled','processing','completed') NOT NULL DEFAULT 'active',
  ADD COLUMN last_run_at DATETIME NULL AFTER next_run_at,
  ADD COLUMN last_error VARCHAR(500) NULL AFTER last_run_at;

ALTER TABLE bill_payments
  ADD COLUMN provider_reference VARCHAR(120) NULL AFTER customer_reference,
  ADD COLUMN failure_reason VARCHAR(255) NULL AFTER status;

CREATE INDEX idx_scheduled_claim ON scheduled_payments(status,next_run_at,id);

-- Phase 17: OTP delivery is email-only.
-- The confirmation page no longer displays codes, so the stage-approval
-- notification wording changes from "ready on the confirmation page" to
-- "sent to your email".

UPDATE notification_templates
   SET body_template = REPLACE(body_template, 'The next one-time password is ready on the confirmation page.', 'The next one-time password has been sent to your email.')
 WHERE event_type = 'otp_stage_approved'
   AND body_template LIKE '%ready on the confirmation page%';

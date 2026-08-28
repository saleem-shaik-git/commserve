-- Phase 19: every customer has exactly ONE conversation with the admin team.
-- Consolidates any existing per-customer duplicate threads into their newest
-- thread, then enforces uniqueness on chat_threads.user_id.

CREATE TEMPORARY TABLE IF NOT EXISTS tmp_chat_keep AS
  SELECT user_id, MAX(id) AS keep_id FROM chat_threads GROUP BY user_id;

UPDATE chat_messages m
  JOIN chat_threads t ON t.id = m.thread_id
  JOIN tmp_chat_keep k ON k.user_id = t.user_id
   SET m.thread_id = k.keep_id
 WHERE t.id <> k.keep_id;

DELETE t FROM chat_threads t
  JOIN tmp_chat_keep k ON k.user_id = t.user_id
 WHERE t.id <> k.keep_id;

DROP TEMPORARY TABLE IF EXISTS tmp_chat_keep;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_threads' AND INDEX_NAME = 'uq_chat_thread_user') = 0,
  'ALTER TABLE chat_threads ADD UNIQUE KEY uq_chat_thread_user (user_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

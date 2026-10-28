USE internpilih;

SET @has_telegram_sent_at := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'announcements'
      AND COLUMN_NAME = 'telegram_sent_at'
);
SET @sql := IF(@has_telegram_sent_at = 0, 'ALTER TABLE announcements ADD COLUMN telegram_sent_at TIMESTAMP NULL AFTER email_failed_count', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_telegram_message_id := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'announcements'
      AND COLUMN_NAME = 'telegram_message_id'
);
SET @sql := IF(@has_telegram_message_id = 0, 'ALTER TABLE announcements ADD COLUMN telegram_message_id VARCHAR(80) NULL AFTER telegram_sent_at', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_telegram_error := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'announcements'
      AND COLUMN_NAME = 'telegram_error'
);
SET @sql := IF(@has_telegram_error = 0, 'ALTER TABLE announcements ADD COLUMN telegram_error VARCHAR(255) NULL AFTER telegram_message_id', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

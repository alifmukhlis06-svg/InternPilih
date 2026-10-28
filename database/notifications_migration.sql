USE internpilih;

CREATE TABLE IF NOT EXISTS notifications (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_user_id INT UNSIGNED NULL,
    created_by        INT UNSIGNED NULL,
    type              ENUM('system','general','reminder','urgent') NOT NULL DEFAULT 'system',
    title             VARCHAR(180) NOT NULL,
    body              TEXT NOT NULL,
    link_url          VARCHAR(255) NULL,
    recipient_group   VARCHAR(40) NULL,
    email_to          VARCHAR(150) NULL,
    email_sent_at     TIMESTAMP NULL,
    email_error       VARCHAR(255) NULL,
    is_read           TINYINT(1) NOT NULL DEFAULT 0,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by)        REFERENCES users(id) ON DELETE SET NULL
);

SET @has_email_to := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'notifications'
      AND COLUMN_NAME = 'email_to'
);
SET @sql := IF(@has_email_to = 0, 'ALTER TABLE notifications ADD COLUMN email_to VARCHAR(150) NULL AFTER recipient_group', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_email_sent_at := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'notifications'
      AND COLUMN_NAME = 'email_sent_at'
);
SET @sql := IF(@has_email_sent_at = 0, 'ALTER TABLE notifications ADD COLUMN email_sent_at TIMESTAMP NULL AFTER email_to', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_email_error := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'notifications'
      AND COLUMN_NAME = 'email_error'
);
SET @sql := IF(@has_email_error = 0, 'ALTER TABLE notifications ADD COLUMN email_error VARCHAR(255) NULL AFTER email_sent_at', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO notifications (recipient_user_id, created_by, type, title, body, link_url, recipient_group, is_read)
SELECT 1, 4, 'urgent', 'Document submission reminder', 'Please upload your internship agreement and remaining placement documents for coordinator verification.', 'documents.html', 'all', 0
WHERE NOT EXISTS (SELECT 1 FROM notifications);

INSERT INTO notifications (recipient_user_id, created_by, type, title, body, link_url, recipient_group, is_read)
SELECT 1, NULL, 'system', 'Placement verified', 'Your accepted placement at Petronas ICT Sdn Bhd has been recorded. Please complete all required documents.', 'documents.html', 'student', 0
WHERE (SELECT COUNT(*) FROM notifications) = 1;

INSERT INTO notifications (recipient_user_id, created_by, type, title, body, link_url, recipient_group, is_read)
SELECT 2, 4, 'reminder', 'Respond to pending offers', 'You still have pending internship offers. Please accept or formally reject each offer in InternPilih.', 'offers.html', 'pending', 0
WHERE (SELECT COUNT(*) FROM notifications) = 2;

INSERT INTO notifications (recipient_user_id, created_by, type, title, body, link_url, recipient_group, is_read)
SELECT 3, 4, 'general', 'Internship monitoring update', 'The coordinator is reviewing current offer responses and placement progress for this semester.', 'dashboard.html', 'all', 1
WHERE (SELECT COUNT(*) FROM notifications) = 3;

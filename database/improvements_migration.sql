USE internpilih;

CREATE TABLE IF NOT EXISTS announcements (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_by      INT UNSIGNED NOT NULL,
    type            ENUM('general','reminder','urgent') NOT NULL DEFAULT 'general',
    recipient_group VARCHAR(40) NOT NULL DEFAULT 'all',
    title           VARCHAR(180) NOT NULL,
    body            TEXT NOT NULL,
    link_url        VARCHAR(255) NULL,
    recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
    email_sent_count INT UNSIGNED NOT NULL DEFAULT 0,
    email_failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS activity_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id   INT UNSIGNED NULL,
    metadata    JSON NULL,
    ip_address  VARCHAR(45) NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_user_created (user_id, created_at),
    INDEX idx_activity_entity (entity_type, entity_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS internship_applications (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id     INT UNSIGNED NOT NULL,
    company_id     INT UNSIGNED NOT NULL,
    position       VARCHAR(150) NOT NULL,
    source         ENUM('student','employer','coordinator') NOT NULL DEFAULT 'student',
    status         ENUM('draft','submitted','shortlisted','interview','offered','rejected','withdrawn') NOT NULL DEFAULT 'submitted',
    applied_at     DATE NOT NULL,
    interview_at   DATETIME NULL,
    notes          TEXT NULL,
    offer_id       INT UNSIGNED NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (offer_id) REFERENCES offers(id) ON DELETE SET NULL
);

SET @has_offer_application_id := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'offers'
      AND COLUMN_NAME = 'application_id'
);
SET @sql := IF(@has_offer_application_id = 0, 'ALTER TABLE offers ADD COLUMN application_id INT UNSIGNED NULL AFTER reject_reason', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_notification_announcement_id := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'notifications'
      AND COLUMN_NAME = 'announcement_id'
);
SET @sql := IF(@has_notification_announcement_id = 0, 'ALTER TABLE notifications ADD COLUMN announcement_id INT UNSIGNED NULL AFTER id', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO internship_applications (student_id, company_id, position, source, status, applied_at, offer_id, notes)
SELECT o.student_id, o.company_id, o.position, 'student', 'offered', o.offer_date, o.id, 'Backfilled from existing offer.'
FROM offers o
LEFT JOIN internship_applications ia ON ia.offer_id = o.id
WHERE ia.id IS NULL;

UPDATE offers o
JOIN internship_applications ia ON ia.offer_id = o.id
SET o.application_id = ia.id
WHERE o.application_id IS NULL;

INSERT INTO announcements (created_by, type, recipient_group, title, body, link_url, recipient_count, email_sent_count, email_failed_count, created_at)
SELECT
    n.created_by,
    CASE WHEN n.type IN ('general','reminder','urgent') THEN n.type ELSE 'general' END,
    COALESCE(n.recipient_group, 'all'),
    n.title,
    n.body,
    n.link_url,
    COUNT(*) AS recipient_count,
    SUM(n.email_sent_at IS NOT NULL) AS email_sent_count,
    SUM(n.email_error IS NOT NULL) AS email_failed_count,
    MIN(n.created_at)
FROM notifications n
LEFT JOIN announcements a
    ON a.created_by = n.created_by
    AND a.title = n.title
    AND a.body = n.body
    AND a.created_at = n.created_at
WHERE n.created_by IS NOT NULL
  AND n.announcement_id IS NULL
  AND n.type IN ('general','reminder','urgent')
  AND a.id IS NULL
GROUP BY n.created_by, n.type, n.recipient_group, n.title, n.body, n.link_url, n.created_at;

UPDATE notifications n
JOIN announcements a
    ON a.created_by = n.created_by
    AND a.title = n.title
    AND a.body = n.body
SET n.announcement_id = a.id
WHERE n.announcement_id IS NULL;

INSERT INTO activity_logs (user_id, action, entity_type, entity_id, metadata)
SELECT 4, 'migration.applied', 'database', NULL, JSON_OBJECT('name', 'improvements_migration')
WHERE NOT EXISTS (
    SELECT 1 FROM activity_logs
    WHERE action = 'migration.applied'
      AND entity_type = 'database'
      AND JSON_EXTRACT(metadata, '$.name') = 'improvements_migration'
);

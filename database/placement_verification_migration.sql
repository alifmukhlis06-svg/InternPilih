USE internpilih;

SET @has_placement_verification_status := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'offers'
      AND COLUMN_NAME = 'placement_verification_status'
);
SET @sql := IF(
    @has_placement_verification_status = 0,
    "ALTER TABLE offers ADD COLUMN placement_verification_status ENUM('not_submitted','pending','verified','rejected') NOT NULL DEFAULT 'not_submitted' AFTER application_id",
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_placement_verification_updated_at := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'offers'
      AND COLUMN_NAME = 'placement_verification_updated_at'
);
SET @sql := IF(
    @has_placement_verification_updated_at = 0,
    'ALTER TABLE offers ADD COLUMN placement_verification_updated_at DATETIME NULL AFTER placement_verification_status',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_placement_verification_updated_by := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'offers'
      AND COLUMN_NAME = 'placement_verification_updated_by'
);
SET @sql := IF(
    @has_placement_verification_updated_by = 0,
    'ALTER TABLE offers ADD COLUMN placement_verification_updated_by INT UNSIGNED NULL AFTER placement_verification_updated_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_document_reviewed_by := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'documents'
      AND COLUMN_NAME = 'reviewed_by'
);
SET @sql := IF(
    @has_document_reviewed_by = 0,
    'ALTER TABLE documents ADD COLUMN reviewed_by INT UNSIGNED NULL AFTER reject_reason',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_document_reviewed_at := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'documents'
      AND COLUMN_NAME = 'reviewed_at'
);
SET @sql := IF(
    @has_document_reviewed_at = 0,
    'ALTER TABLE documents ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE offers o
LEFT JOIN (
    SELECT
        latest.offer_id,
        COUNT(*) AS submitted_count,
        SUM(d.status = 'approved') AS approved_count,
        SUM(d.status = 'rejected') AS rejected_count
    FROM (
        SELECT offer_id, doc_type, MAX(id) AS latest_id
        FROM documents
        WHERE offer_id IS NOT NULL
          AND doc_type IN ('offer_letter', 'student_id', 'agreement')
        GROUP BY offer_id, doc_type
    ) latest
    JOIN documents d ON d.id = latest.latest_id
    GROUP BY latest.offer_id
) document_state ON document_state.offer_id = o.id
SET
    o.placement_verification_status = CASE
        WHEN o.status <> 'accepted' THEN 'not_submitted'
        WHEN document_state.submitted_count IS NULL THEN 'not_submitted'
        WHEN document_state.rejected_count > 0 THEN 'rejected'
        WHEN document_state.approved_count = 3 THEN 'verified'
        ELSE 'pending'
    END,
    o.placement_verification_updated_at = CASE
        WHEN document_state.submitted_count IS NULL THEN NULL
        ELSE NOW()
    END;

INSERT INTO activity_logs (user_id, action, entity_type, entity_id, metadata)
SELECT NULL, 'migration.applied', 'database', NULL, JSON_OBJECT('name', 'placement_verification_migration')
WHERE NOT EXISTS (
    SELECT 1 FROM activity_logs
    WHERE action = 'migration.applied'
      AND entity_type = 'database'
      AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.name')) = 'placement_verification_migration'
);

USE internpilih;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'students' AND column_name = 'campus') = 0,
    'ALTER TABLE students ADD COLUMN campus VARCHAR(100) NULL', 'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'admins' AND column_name = 'campus') = 0,
    'ALTER TABLE admins ADD COLUMN campus VARCHAR(100) NULL', 'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'offers' AND column_name = 'internship_start_date') = 0,
    'ALTER TABLE offers ADD COLUMN internship_start_date DATE NULL', 'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'password_reset_tokens' AND column_name = 'purpose') = 0,
    'ALTER TABLE password_reset_tokens ADD COLUMN purpose ENUM(''password_reset'',''password_change'') NOT NULL DEFAULT ''password_reset''', 'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql; EXECUTE migration_statement; DEALLOCATE PREPARE migration_statement;

UPDATE students s
JOIN users u ON u.id = s.user_id
SET s.campus = 'Arau'
WHERE (s.matric_id = '2025161705' OR u.name LIKE 'Alif Mukhlis%');

UPDATE admins a
JOIN students s ON s.coordinator_id = a.user_id
JOIN users u ON u.id = s.user_id
SET a.campus = 'Arau'
WHERE s.matric_id = '2025161705' OR u.name LIKE 'Alif Mukhlis%';

UPDATE offers SET internship_start_date = '2027-03-01'
WHERE internship_start_date IS NULL;

INSERT INTO system_settings (setting_key, setting_value) VALUES
('internship_application_open', '2026-11-02'),
('main_offer_deadline', '2026-12-15'),
('placement_confirmation_deadline', '2027-01-15'),
('document_completion_deadline', '2027-01-31'),
('internship_start_date', '2027-03-01')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

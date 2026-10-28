-- Run on an existing legacy schema before role_tables_finalize.sql.
-- Existing role rows and user records are not overwritten. Do not rerun after
-- finalization, because the old role columns have been removed from users.

CREATE TABLE IF NOT EXISTS students (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    matric_id VARCHAR(20) NULL UNIQUE,
    faculty VARCHAR(100) NULL,
    programme VARCHAR(150) NULL,
    cgpa DECIMAL(3,2) NULL,
    CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS employers (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    company_id INT UNSIGNED NULL,
    CONSTRAINT fk_employers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_employers_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS admins (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    faculty VARCHAR(100) NULL,
    CONSTRAINT fk_admins_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

INSERT INTO students (user_id, matric_id, faculty, programme, cgpa)
SELECT id, matric_id, faculty, programme, cgpa FROM users WHERE role = 'student'
ON DUPLICATE KEY UPDATE user_id = VALUES(user_id);

INSERT INTO employers (user_id, company_id)
SELECT id, company_id FROM users WHERE role = 'employer'
ON DUPLICATE KEY UPDATE user_id = VALUES(user_id);

INSERT INTO admins (user_id)
SELECT id FROM users WHERE role = 'admin'
ON DUPLICATE KEY UPDATE user_id = VALUES(user_id);

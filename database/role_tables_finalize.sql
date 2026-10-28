-- Run once after role_tables_migration.sql and after application code has
-- switched to students/employers/admins. Existing role values were checked
-- against their copied rows before this migration was applied.

ALTER TABLE offers
    DROP FOREIGN KEY offers_ibfk_1,
    ADD CONSTRAINT fk_offers_student FOREIGN KEY (student_id) REFERENCES students(user_id) ON DELETE CASCADE;

ALTER TABLE documents
    DROP FOREIGN KEY documents_ibfk_1,
    ADD CONSTRAINT fk_documents_student FOREIGN KEY (student_id) REFERENCES students(user_id) ON DELETE CASCADE;

ALTER TABLE internship_applications
    DROP FOREIGN KEY internship_applications_ibfk_1,
    ADD CONSTRAINT fk_applications_student FOREIGN KEY (student_id) REFERENCES students(user_id) ON DELETE CASCADE;

ALTER TABLE placement_change_requests
    DROP FOREIGN KEY placement_change_requests_ibfk_1,
    ADD CONSTRAINT fk_change_requests_student FOREIGN KEY (student_id) REFERENCES students(user_id) ON DELETE CASCADE;

ALTER TABLE users
    DROP COLUMN matric_id,
    DROP COLUMN faculty,
    DROP COLUMN programme,
    DROP COLUMN cgpa,
    DROP COLUMN company_id;

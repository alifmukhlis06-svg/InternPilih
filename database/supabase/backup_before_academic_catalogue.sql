-- Private backup before adding catalogue references to production student records.
BEGIN;
CREATE SCHEMA IF NOT EXISTS internpilih_backup_20261127_academic;
REVOKE ALL ON SCHEMA internpilih_backup_20261127_academic FROM PUBLIC, anon, authenticated;
CREATE TABLE IF NOT EXISTS internpilih_backup_20261127_academic.students AS TABLE public.students;
CREATE TABLE IF NOT EXISTS internpilih_backup_20261127_academic.student_registration_requests AS TABLE public.student_registration_requests;
REVOKE ALL ON ALL TABLES IN SCHEMA internpilih_backup_20261127_academic FROM PUBLIC, anon, authenticated;
COMMIT;

-- Private production snapshot immediately before optional-document self registration.
BEGIN;
CREATE SCHEMA internpilih_backup_20261127_self_service;
REVOKE ALL ON SCHEMA internpilih_backup_20261127_self_service FROM PUBLIC, anon, authenticated;
CREATE TABLE internpilih_backup_20261127_self_service.users AS SELECT * FROM public.users;
CREATE TABLE internpilih_backup_20261127_self_service.students AS SELECT * FROM public.students;
CREATE TABLE internpilih_backup_20261127_self_service.student_registration_requests AS SELECT * FROM public.student_registration_requests;
REVOKE ALL ON ALL TABLES IN SCHEMA internpilih_backup_20261127_self_service FROM PUBLIC, anon, authenticated;
COMMIT;

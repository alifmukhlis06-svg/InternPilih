-- Private row snapshot of production tables affected by the two additive features.
-- Apply once, before either feature migration, to the production project only.
BEGIN;
CREATE SCHEMA internpilih_backup_20261127;
REVOKE ALL ON SCHEMA internpilih_backup_20261127 FROM PUBLIC, anon, authenticated;
CREATE TABLE internpilih_backup_20261127.users AS SELECT * FROM public.users;
CREATE TABLE internpilih_backup_20261127.students AS SELECT * FROM public.students;
CREATE TABLE internpilih_backup_20261127.admins AS SELECT * FROM public.admins;
CREATE TABLE internpilih_backup_20261127.notifications AS SELECT * FROM public.notifications;
REVOKE ALL ON ALL TABLES IN SCHEMA internpilih_backup_20261127 FROM PUBLIC, anon, authenticated;
COMMIT;

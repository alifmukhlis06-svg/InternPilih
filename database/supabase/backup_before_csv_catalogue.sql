-- Production snapshot before the additive CSV catalogue import.
BEGIN;
CREATE SCHEMA IF NOT EXISTS internpilih_backup_20261127_csv;
REVOKE ALL ON SCHEMA internpilih_backup_20261127_csv FROM PUBLIC, anon, authenticated;
CREATE TABLE IF NOT EXISTS internpilih_backup_20261127_csv.academic_campuses AS TABLE public.academic_campuses;
CREATE TABLE IF NOT EXISTS internpilih_backup_20261127_csv.academic_units AS TABLE public.academic_units;
CREATE TABLE IF NOT EXISTS internpilih_backup_20261127_csv.academic_programmes AS TABLE public.academic_programmes;
CREATE TABLE IF NOT EXISTS internpilih_backup_20261127_csv.academic_offerings AS TABLE public.academic_offerings;
CREATE TABLE IF NOT EXISTS internpilih_backup_20261127_csv.academic_coordinator_mappings AS TABLE public.academic_coordinator_mappings;
CREATE TABLE IF NOT EXISTS internpilih_backup_20261127_csv.students AS TABLE public.students;
CREATE TABLE IF NOT EXISTS internpilih_backup_20261127_csv.student_registration_requests AS TABLE public.student_registration_requests;
COMMIT;

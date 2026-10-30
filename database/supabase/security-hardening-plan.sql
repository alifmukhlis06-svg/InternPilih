-- InternPilih production security hardening proposal.
-- REVIEW ONLY: do not execute without an approved maintenance window.
-- This file intentionally contains no password or project credential.

BEGIN;

-- Resolve the mutable-search-path warning without changing trigger behavior.
CREATE OR REPLACE FUNCTION public.set_updated_at()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    NEW.updated_at = pg_catalog.now();
    RETURN NEW;
END;
$$;

-- InternPilih does not currently use PostgREST for its application data.
-- Remove table access from public API roles as defense in depth. RLS remains
-- enabled on every table; do not add broad USING (true) policies.
REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM anon, authenticated;
REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public FROM anon, authenticated;

-- A dedicated backend login must be created separately with a password supplied
-- privately by the operator. Do not put the password in this file.
-- Near-term, the custom PHP-session architecture still requires a trusted backend
-- role that can bypass RLS. Give that role no DDL, schema-ownership, database,
-- replication, or role-management privileges. Grant only the operations below.
--
-- Example role creation (operator supplies the password securely):
-- CREATE ROLE internpilih_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE
--     NOINHERIT NOREPLICATION BYPASSRLS PASSWORD '<set privately>';
-- GRANT CONNECT ON DATABASE postgres TO internpilih_app;
-- GRANT USAGE ON SCHEMA public TO internpilih_app;
-- GRANT SELECT ON ALL TABLES IN SCHEMA public TO internpilih_app;
-- GRANT INSERT ON public.companies, public.offers, public.documents,
--     public.internship_applications, public.announcements,
--     public.notifications, public.activity_logs,
--     public.placement_change_requests, public.password_reset_tokens,
--     public.system_settings, public.app_sessions,
--     public.login_rate_limits TO internpilih_app;
-- GRANT UPDATE ON public.users, public.companies, public.admins,
--     public.students, public.offers, public.documents,
--     public.internship_applications, public.announcements,
--     public.notifications, public.placement_change_requests,
--     public.password_reset_tokens, public.system_settings,
--     public.app_sessions, public.login_rate_limits TO internpilih_app;
-- GRANT DELETE ON public.offers, public.password_reset_tokens,
--     public.app_sessions, public.login_rate_limits TO internpilih_app;
-- GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO internpilih_app;
--
-- Preferred future state: migrate application identity to Supabase Auth or expose
-- narrowly scoped SECURITY DEFINER functions, then change this role to NOBYPASSRLS
-- and add actor-specific policies tested with pgTAP.

-- This plan is deliberately non-executing even if pasted accidentally.
ROLLBACK;

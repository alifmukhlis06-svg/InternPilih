-- InternPilih-Staging only.
-- Apply after 002_serverless_storage.sql and only after explicit approval.
-- The backend role is deliberately created NOLOGIN; its password is enabled
-- separately from a private local environment variable.

BEGIN;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM public.system_settings
        WHERE setting_key = 'environment' AND setting_value = 'staging'
    ) THEN
        RAISE EXCEPTION 'Staging environment marker is missing';
    END IF;
END;
$$;

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

REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM anon, authenticated;
REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public FROM anon, authenticated;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    REVOKE ALL PRIVILEGES ON TABLES FROM anon, authenticated;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    REVOKE ALL PRIVILEGES ON SEQUENCES FROM anon, authenticated;
REVOKE EXECUTE ON FUNCTION public.set_updated_at() FROM PUBLIC, anon, authenticated;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'internpilih_staging_app') THEN
        CREATE ROLE internpilih_staging_app
            NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE
            NOINHERIT NOREPLICATION BYPASSRLS;
    ELSE
        ALTER ROLE internpilih_staging_app
            NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE
            NOINHERIT NOREPLICATION BYPASSRLS;
    END IF;
END;
$$;

REVOKE ALL PRIVILEGES ON DATABASE postgres FROM internpilih_staging_app;
GRANT CONNECT ON DATABASE postgres TO internpilih_staging_app;
REVOKE ALL PRIVILEGES ON SCHEMA public FROM internpilih_staging_app;
GRANT USAGE ON SCHEMA public TO internpilih_staging_app;

GRANT SELECT ON ALL TABLES IN SCHEMA public TO internpilih_staging_app;
GRANT INSERT ON public.companies, public.offers, public.documents,
    public.internship_applications, public.announcements,
    public.notifications, public.activity_logs,
    public.placement_change_requests, public.password_reset_tokens,
    public.system_settings, public.app_sessions, public.login_rate_limits
    TO internpilih_staging_app;
GRANT UPDATE ON public.users, public.companies, public.admins,
    public.students, public.offers, public.documents,
    public.internship_applications, public.announcements,
    public.notifications, public.placement_change_requests,
    public.password_reset_tokens, public.system_settings,
    public.app_sessions, public.login_rate_limits
    TO internpilih_staging_app;
GRANT DELETE ON public.offers, public.password_reset_tokens,
    public.app_sessions, public.login_rate_limits
    TO internpilih_staging_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public
    TO internpilih_staging_app;
GRANT EXECUTE ON FUNCTION public.set_updated_at()
    TO internpilih_staging_app;

ALTER ROLE internpilih_staging_app SET statement_timeout = '15s';
ALTER ROLE internpilih_staging_app SET idle_in_transaction_session_timeout = '15s';

COMMIT;

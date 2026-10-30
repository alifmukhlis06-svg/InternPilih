-- InternPilih-Staging only.
-- Apply after database/supabase/schema.sql and only after explicit approval.
-- This migration creates shared serverless state and private Storage metadata.

BEGIN;

DO $$
BEGIN
    IF to_regclass('public.users') IS NULL
       OR to_regclass('public.documents') IS NULL
       OR to_regclass('public.system_settings') IS NULL THEN
        RAISE EXCEPTION 'InternPilih base schema is not installed';
    END IF;
END;
$$;

CREATE TABLE public.app_sessions (
    session_key char(64) PRIMARY KEY,
    session_data text NOT NULL,
    expires_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_app_sessions_expires_at
    ON public.app_sessions(expires_at);

CREATE TABLE public.login_rate_limits (
    key_hash char(64) PRIMARY KEY,
    failure_count integer NOT NULL DEFAULT 0 CHECK (failure_count >= 0),
    window_started_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until timestamptz,
    updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_login_rate_limits_updated_at
    ON public.login_rate_limits(updated_at);

ALTER TABLE public.app_sessions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.login_rate_limits ENABLE ROW LEVEL SECURITY;
REVOKE ALL PRIVILEGES ON public.app_sessions, public.login_rate_limits
    FROM PUBLIC, anon, authenticated;

ALTER TABLE public.documents
    ADD COLUMN storage_bucket varchar(100),
    ADD COLUMN storage_object_path varchar(500),
    ADD COLUMN content_type varchar(100),
    ADD COLUMN byte_size bigint CHECK (byte_size IS NULL OR byte_size >= 0),
    ADD COLUMN sha256 char(64),
    ADD COLUMN storage_migrated_at timestamptz;

CREATE UNIQUE INDEX uq_documents_storage_object
    ON public.documents(storage_bucket, storage_object_path)
    WHERE storage_object_path IS NOT NULL;

INSERT INTO storage.buckets (
    id, name, public, file_size_limit, allowed_mime_types
) VALUES (
    'documents',
    'documents',
    false,
    5242880,
    ARRAY['application/pdf', 'image/jpeg', 'image/png']::text[]
);

-- This marker is checked by every staging-only helper before it can write.
INSERT INTO public.system_settings (setting_key, setting_value)
VALUES
    ('environment', 'staging'),
    ('offer_response_period_days', '14')
ON CONFLICT (setting_key) DO NOTHING;

COMMIT;

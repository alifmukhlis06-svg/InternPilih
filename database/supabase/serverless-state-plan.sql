-- InternPilih shared serverless state proposal.
-- REVIEW ONLY: this file ends in ROLLBACK and must not be executed as-is.

BEGIN;

CREATE TABLE IF NOT EXISTS public.app_sessions (
    session_key char(64) PRIMARY KEY,
    session_data text NOT NULL,
    expires_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_app_sessions_expires_at
    ON public.app_sessions(expires_at);

CREATE TABLE IF NOT EXISTS public.login_rate_limits (
    key_hash char(64) PRIMARY KEY,
    failure_count integer NOT NULL DEFAULT 0 CHECK (failure_count >= 0),
    window_started_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until timestamptz NULL,
    updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_login_rate_limits_updated_at
    ON public.login_rate_limits(updated_at);

ALTER TABLE public.app_sessions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.login_rate_limits ENABLE ROW LEVEL SECURITY;
REVOKE ALL PRIVILEGES ON public.app_sessions, public.login_rate_limits FROM anon, authenticated;

-- Apply only after the dedicated backend role is approved and created:
-- GRANT SELECT, INSERT, UPDATE, DELETE
--     ON public.app_sessions, public.login_rate_limits
--     TO internpilih_app;

-- Scheduled or request-driven cleanup can safely delete expired rows:
-- DELETE FROM public.app_sessions WHERE expires_at <= CURRENT_TIMESTAMP;
-- DELETE FROM public.login_rate_limits
-- WHERE updated_at < CURRENT_TIMESTAMP - INTERVAL '2 days';

ROLLBACK;

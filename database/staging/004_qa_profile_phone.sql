-- Safe additive profile migration for InternPilih staging/production schemas.
-- The nullable column preserves every existing account and can be rolled out
-- before the matching application code.

BEGIN;

ALTER TABLE public.users
    ADD COLUMN IF NOT EXISTS phone varchar(30);

COMMIT;

-- InternPilih private Storage schema proposal.
-- REVIEW ONLY: this file ends in ROLLBACK and uploads no objects.

BEGIN;

ALTER TABLE public.documents
    ADD COLUMN IF NOT EXISTS storage_bucket varchar(100),
    ADD COLUMN IF NOT EXISTS storage_object_path varchar(500),
    ADD COLUMN IF NOT EXISTS content_type varchar(100),
    ADD COLUMN IF NOT EXISTS byte_size bigint CHECK (byte_size IS NULL OR byte_size >= 0),
    ADD COLUMN IF NOT EXISTS sha256 char(64),
    ADD COLUMN IF NOT EXISTS storage_migrated_at timestamptz;

CREATE UNIQUE INDEX IF NOT EXISTS uq_documents_storage_object
    ON public.documents(storage_bucket, storage_object_path)
    WHERE storage_object_path IS NOT NULL;

INSERT INTO storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
VALUES (
    'documents',
    'documents',
    false,
    5242880,
    ARRAY['application/pdf', 'image/jpeg', 'image/png']::text[]
)
ON CONFLICT (id) DO NOTHING;

-- The bucket stays private. No anon/authenticated storage.objects policies are
-- created. PHP authorizes each request before using a server-only Storage secret
-- to upload or create a 60-second signed download URL.

ROLLBACK;

-- Permit verified Google students to register without a review or document.
-- Existing review statuses, columns, indexes and RLS remain available.
BEGIN;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '20s';

ALTER TABLE public.student_registration_requests
    ALTER COLUMN document_path DROP NOT NULL,
    ALTER COLUMN document_name DROP NOT NULL,
    ALTER COLUMN document_content_type DROP NOT NULL,
    ALTER COLUMN document_bytes DROP NOT NULL,
    ALTER COLUMN document_sha256 DROP NOT NULL;

ALTER TABLE public.student_registration_requests
    ADD CONSTRAINT registration_document_fields_complete CHECK (
        (document_path IS NULL AND document_name IS NULL AND document_content_type IS NULL
            AND document_bytes IS NULL AND document_sha256 IS NULL)
        OR
        (document_path IS NOT NULL AND document_name IS NOT NULL AND document_content_type IS NOT NULL
            AND document_bytes IS NOT NULL AND document_sha256 IS NOT NULL)
    );

ALTER TABLE public.student_registration_requests
    DROP CONSTRAINT student_registration_requests_status_check,
    ADD CONSTRAINT student_registration_requests_status_check CHECK (
        status IN ('pending','pending_assignment','correction_required','approved','rejected','registered')
    );

COMMIT;

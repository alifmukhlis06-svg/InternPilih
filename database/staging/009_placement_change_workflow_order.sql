ALTER TABLE public.placement_change_requests
    DROP CONSTRAINT IF EXISTS placement_change_requests_status_check;

DROP INDEX IF EXISTS public.uq_placement_change_active_student;

ALTER TABLE public.placement_change_requests
    ADD COLUMN IF NOT EXISTS document_deadline timestamptz;

UPDATE public.placement_change_requests
SET status = CASE status
        WHEN 'preparing_request' THEN 'coordinator_review'
        WHEN 'documents_complete' THEN 'coordinator_review'
        WHEN 'request_submitted' THEN 'documents_submitted'
        WHEN 'coordinator_review' THEN 'documents_submitted'
        WHEN 'approved' THEN 'completed'
        ELSE status
    END,
    document_deadline = CASE
        WHEN status IN ('preparing_request', 'documents_complete') THEN NULL
        ELSE document_deadline
    END;

ALTER TABLE public.placement_change_requests
    ALTER COLUMN status SET DEFAULT 'coordinator_review',
    ADD CONSTRAINT placement_change_requests_status_check
    CHECK (status IN (
        'coordinator_review', 'approved_documents_required', 'documents_submitted',
        'document_verification', 'revision_required', 'completed', 'rejected'
    ));

CREATE UNIQUE INDEX uq_placement_change_active_student
    ON public.placement_change_requests(student_id)
    WHERE status IN (
        'coordinator_review', 'approved_documents_required', 'documents_submitted',
        'document_verification', 'revision_required'
    );

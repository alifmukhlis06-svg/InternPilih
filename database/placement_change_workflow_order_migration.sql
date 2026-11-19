ALTER TABLE placement_change_requests
    ADD COLUMN document_deadline DATETIME NULL AFTER submitted_at;

ALTER TABLE placement_change_requests
    MODIFY status ENUM(
        'preparing_request','documents_complete','request_submitted','coordinator_review',
        'approved_documents_required','documents_submitted','document_verification',
        'revision_required','approved','completed','rejected'
    ) NOT NULL DEFAULT 'coordinator_review';

UPDATE placement_change_requests
SET status = CASE status
    WHEN 'preparing_request' THEN 'coordinator_review'
    WHEN 'documents_complete' THEN 'coordinator_review'
    WHEN 'request_submitted' THEN 'documents_submitted'
    WHEN 'coordinator_review' THEN 'documents_submitted'
    WHEN 'approved' THEN 'completed'
    ELSE status
END,
document_deadline = CASE
    WHEN status IN ('preparing_request','documents_complete') THEN NULL
    ELSE document_deadline
END;

ALTER TABLE placement_change_requests
    MODIFY status ENUM(
        'coordinator_review','approved_documents_required','documents_submitted',
        'document_verification','revision_required','completed','rejected'
    ) NOT NULL DEFAULT 'coordinator_review';

USE internpilih;

ALTER TABLE placement_change_requests
    MODIFY status ENUM(
        'pending','preparing_request','documents_complete','request_submitted',
        'coordinator_review','revision_required','approved','rejected'
    ) NOT NULL DEFAULT 'preparing_request',
    ADD COLUMN submitted_at DATETIME NULL,
    ADD COLUMN revision_requested_at DATETIME NULL,
    ADD COLUMN revision_deadline DATETIME NULL,
    ADD COLUMN final_decision_at DATETIME NULL;

UPDATE placement_change_requests
SET status = 'preparing_request'
WHERE status = 'pending';

ALTER TABLE placement_change_requests
    MODIFY status ENUM(
        'preparing_request','documents_complete','request_submitted',
        'coordinator_review','revision_required','approved','rejected'
    ) NOT NULL DEFAULT 'preparing_request';

CREATE TABLE IF NOT EXISTS placement_change_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id BIGINT UNSIGNED NOT NULL,
    document_type ENUM('proof_of_sending','placement_change_letter','company_response') NOT NULL,
    status ENUM('missing','uploaded','verified','rejected') NOT NULL DEFAULT 'missing',
    file_name VARCHAR(255) NULL,
    storage_bucket VARCHAR(100) NULL,
    storage_object_path VARCHAR(500) NULL,
    content_type VARCHAR(100) NULL,
    byte_size BIGINT UNSIGNED NULL,
    sha256 CHAR(64) NULL,
    uploaded_at DATETIME NULL,
    replaced_at DATETIME NULL,
    last_submitted_at DATETIME NULL,
    verified_at DATETIME NULL,
    rejected_at DATETIME NULL,
    reviewed_by INT UNSIGNED NULL,
    rejection_reason VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_change_document_type (request_id, document_type),
    INDEX idx_change_document_request (request_id),
    FOREIGN KEY (request_id) REFERENCES placement_change_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
);

INSERT IGNORE INTO placement_change_documents (request_id, document_type)
SELECT id, 'proof_of_sending' FROM placement_change_requests;
INSERT IGNORE INTO placement_change_documents (request_id, document_type)
SELECT id, 'placement_change_letter' FROM placement_change_requests;
INSERT IGNORE INTO placement_change_documents (request_id, document_type)
SELECT id, 'company_response' FROM placement_change_requests;

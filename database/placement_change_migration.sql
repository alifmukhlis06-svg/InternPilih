USE internpilih;

ALTER TABLE offers
    MODIFY status ENUM('pending','accepted','rejected','withdrawn') NOT NULL DEFAULT 'pending';

CREATE TABLE IF NOT EXISTS placement_change_requests (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id         INT UNSIGNED NOT NULL,
    current_offer_id   INT UNSIGNED NOT NULL,
    requested_offer_id INT UNSIGNED NOT NULL,
    reason             TEXT NOT NULL,
    status             ENUM('coordinator_review','approved_documents_required','documents_submitted','document_verification','revision_required','completed','rejected') NOT NULL DEFAULT 'coordinator_review',
    reviewed_by        INT UNSIGNED NULL,
    review_note        VARCHAR(500) NULL,
    reviewed_at        DATETIME NULL,
    submitted_at       DATETIME NULL,
    document_deadline  DATETIME NULL,
    revision_requested_at DATETIME NULL,
    revision_deadline  DATETIME NULL,
    final_decision_at  DATETIME NULL,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pcr_student_status (student_id, status),
    INDEX idx_pcr_status_created (status, created_at),
    FOREIGN KEY (student_id)         REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (current_offer_id)   REFERENCES offers(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_offer_id) REFERENCES offers(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by)        REFERENCES users(id)  ON DELETE SET NULL
);

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
    FOREIGN KEY (request_id) REFERENCES placement_change_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
);

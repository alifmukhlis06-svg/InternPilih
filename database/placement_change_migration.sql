USE internpilih;

ALTER TABLE offers
    MODIFY status ENUM('pending','accepted','rejected','withdrawn') NOT NULL DEFAULT 'pending';

CREATE TABLE IF NOT EXISTS placement_change_requests (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id         INT UNSIGNED NOT NULL,
    current_offer_id   INT UNSIGNED NOT NULL,
    requested_offer_id INT UNSIGNED NOT NULL,
    reason             TEXT NOT NULL,
    status             ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by        INT UNSIGNED NULL,
    review_note        VARCHAR(500) NULL,
    reviewed_at        DATETIME NULL,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pcr_student_status (student_id, status),
    INDEX idx_pcr_status_created (status, created_at),
    FOREIGN KEY (student_id)         REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (current_offer_id)   REFERENCES offers(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_offer_id) REFERENCES offers(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by)        REFERENCES users(id)  ON DELETE SET NULL
);

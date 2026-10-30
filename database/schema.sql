-- ============================================================
-- InternPilih Database Schema
-- Shared users plus students, employers and admins role tables.
-- ============================================================

CREATE DATABASE IF NOT EXISTS internpilih CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE internpilih;

-- ============================================================
-- TABLE 1: users  (students, admin, employers)
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150)  NOT NULL,
    email       VARCHAR(150)  NOT NULL UNIQUE,
    phone       VARCHAR(30)   NULL,
    password    VARCHAR(255)  NOT NULL,
    role        ENUM('student','admin','employer') NOT NULL DEFAULT 'student',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- TABLE 2: companies
-- ============================================================
CREATE TABLE IF NOT EXISTS companies (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150)  NOT NULL,
    industry    VARCHAR(100)  NOT NULL,
    location    VARCHAR(150)  NOT NULL,
    allowance   DECIMAL(8,2)  NULL,
    work_mode   ENUM('On-site','Hybrid','Remote') DEFAULT 'On-site',
    hr_email    VARCHAR(150)  NULL,
    status      ENUM('active','inactive') DEFAULT 'active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- One-to-one role details. The user ID remains the foreign key used by
-- offers, documents, notifications and activity logs.
CREATE TABLE IF NOT EXISTS admins (
    user_id     INT UNSIGNED PRIMARY KEY,
    faculty     VARCHAR(100) NULL,
    programme   VARCHAR(150) NULL UNIQUE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS students (
    user_id     INT UNSIGNED PRIMARY KEY,
    matric_id   VARCHAR(20) NULL UNIQUE,
    faculty     VARCHAR(100) NULL,
    programme   VARCHAR(150) NULL,
    coordinator_id INT UNSIGNED NULL,
    cgpa        DECIMAL(3,2) NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (coordinator_id) REFERENCES admins(user_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS employers (
    user_id     INT UNSIGNED PRIMARY KEY,
    company_id  INT UNSIGNED NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
);

-- ============================================================
-- TABLE 3: offers
-- ============================================================
CREATE TABLE IF NOT EXISTS offers (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id    INT UNSIGNED NOT NULL,
    company_id    INT UNSIGNED NOT NULL,
    position      VARCHAR(150) NOT NULL,
    offer_date    DATE         NOT NULL,
    deadline      DATE         NULL,
    status        ENUM('pending','accepted','rejected','withdrawn') NOT NULL DEFAULT 'pending',
    response_date DATE         NULL,
    reject_reason TEXT         NULL,
    application_id INT UNSIGNED NULL,
    placement_verification_status ENUM('not_submitted','pending','verified','rejected') NOT NULL DEFAULT 'not_submitted',
    placement_verification_updated_at DATETIME NULL,
    placement_verification_updated_by INT UNSIGNED NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id)  REFERENCES students(user_id) ON DELETE CASCADE,
    FOREIGN KEY (company_id)  REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (placement_verification_updated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- TABLE 4: documents
-- ============================================================
CREATE TABLE IF NOT EXISTS documents (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id    INT UNSIGNED NOT NULL,
    offer_id      INT UNSIGNED NULL,
    doc_type      ENUM('offer_letter','agreement','student_id','supervisor_letter','other') NOT NULL,
    file_name     VARCHAR(255) NOT NULL,
    status        ENUM('pending','approved','rejected') DEFAULT 'pending',
    reject_reason VARCHAR(255) NULL,
    reviewed_by   INT UNSIGNED NULL,
    reviewed_at   DATETIME NULL,
    uploaded_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(user_id) ON DELETE CASCADE,
    FOREIGN KEY (offer_id)   REFERENCES offers(id)  ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- TABLE 5: announcements
-- One row per coordinator announcement batch.
-- Notifications hold the per-recipient inbox/read/email state.
-- ============================================================
CREATE TABLE IF NOT EXISTS announcements (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_by      INT UNSIGNED NOT NULL,
    type            ENUM('general','reminder','urgent') NOT NULL DEFAULT 'general',
    recipient_group VARCHAR(40) NOT NULL DEFAULT 'all',
    title           VARCHAR(180) NOT NULL,
    body            TEXT NOT NULL,
    link_url        VARCHAR(255) NULL,
    recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
    email_sent_count INT UNSIGNED NOT NULL DEFAULT 0,
    email_failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    telegram_sent_at TIMESTAMP NULL,
    telegram_message_id VARCHAR(80) NULL,
    telegram_error VARCHAR(255) NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================================
-- TABLE 6: notifications
-- Stores admin announcements, reminders, and system notices.
-- Each recipient gets a row so read/unread state is personal.
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    announcement_id   INT UNSIGNED NULL,
    recipient_user_id INT UNSIGNED NULL,
    created_by        INT UNSIGNED NULL,
    type              ENUM('system','general','reminder','urgent') NOT NULL DEFAULT 'system',
    title             VARCHAR(180) NOT NULL,
    body              TEXT NOT NULL,
    link_url          VARCHAR(255) NULL,
    recipient_group   VARCHAR(40) NULL,
    email_to          VARCHAR(150) NULL,
    email_sent_at     TIMESTAMP NULL,
    email_error       VARCHAR(255) NULL,
    is_read           TINYINT(1) NOT NULL DEFAULT 0,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE SET NULL,
    FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by)        REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- TABLE 7: activity_logs
-- Audit trail for important user/system actions.
-- ============================================================
CREATE TABLE IF NOT EXISTS activity_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id   INT UNSIGNED NULL,
    metadata    JSON NULL,
    ip_address  VARCHAR(45) NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_user_created (user_id, created_at),
    INDEX idx_activity_entity (entity_type, entity_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- TABLE 8: internship_applications
-- Tracks applications before they become formal offers.
-- ============================================================
CREATE TABLE IF NOT EXISTS internship_applications (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id     INT UNSIGNED NOT NULL,
    company_id     INT UNSIGNED NOT NULL,
    position       VARCHAR(150) NOT NULL,
    source         ENUM('student','employer','coordinator') NOT NULL DEFAULT 'student',
    status         ENUM('draft','submitted','shortlisted','interview','offered','rejected','withdrawn') NOT NULL DEFAULT 'submitted',
    applied_at     DATE NOT NULL,
    interview_at   DATETIME NULL,
    notes          TEXT NULL,
    offer_id       INT UNSIGNED NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(user_id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (offer_id) REFERENCES offers(id) ON DELETE SET NULL
);

-- ============================================================
-- TABLE 9: password_reset_tokens
-- Stores hashed, single-use password recovery tokens.
-- ============================================================
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    token_hash    CHAR(64) NOT NULL UNIQUE,
    expires_at    DATETIME NOT NULL,
    used_at       DATETIME NULL,
    requested_ip  VARCHAR(45) NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_password_reset_user (user_id, created_at),
    INDEX idx_password_reset_expiry (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================================
-- TABLE 10: placement_change_requests
-- Lets a student ask the coordinator to switch an accepted
-- placement to a better pending offer.
-- ============================================================
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
    FOREIGN KEY (student_id)         REFERENCES students(user_id) ON DELETE CASCADE,
    FOREIGN KEY (current_offer_id)   REFERENCES offers(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_offer_id) REFERENCES offers(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by)        REFERENCES users(id)  ON DELETE SET NULL
);

-- ============================================================
-- TABLE 11: system_settings
-- Coordinator-managed values used by automatic system rules.
-- ============================================================
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key   VARCHAR(80) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_by    INT UNSIGNED NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('offer_response_period_days', '14')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

-- ============================================================
-- SEED DATA
-- All passwords = "password123"
-- ============================================================

INSERT INTO companies (name, industry, location, allowance, work_mode, hr_email) VALUES
('Petronas ICT Sdn Bhd',   'GLC / Oil & Gas',    'KLCC, Kuala Lumpur',     1800.00, 'Hybrid',   'hr@petronas.com.my'),
('Tech Solutions Sdn Bhd', 'IT Services',         'Mid Valley, KL',         1200.00, 'Hybrid',   'hr@techsolutions.com.my'),
('Axiata Digital Sdn Bhd', 'Telecommunications',  'Cyberjaya, Selangor',    1500.00, 'On-site',  'recruitment@axiata.com'),
('Maybank IT Division',    'Finance & Banking',   'Bangsar South, KL',      1000.00, 'On-site',  'intern@maybank.com'),
('Microsoft Malaysia',     'IT / Cloud',          'KLCC, Kuala Lumpur',     2500.00, 'Hybrid',   'intern@microsoft.com.my'),
('KPJ Healthcare IT',      'Healthcare',          'Damansara, KL',          1300.00, 'On-site',  'it@kpjhealth.com.my');

INSERT INTO users (name, email, password, role) VALUES
('Alif Mukhlis bin Ahmad',      '2025161705@student.uitm.edu.my', '$2y$10$AjfPLRSCzdaJBIfWxn52POHM4KNEG9WmjtpgtgMsfaTmp/Kvrba2a', 'student'),
('Nurul Aisyah binti Hassan',   '2025161708@student.uitm.edu.my', '$2y$10$AjfPLRSCzdaJBIfWxn52POHM4KNEG9WmjtpgtgMsfaTmp/Kvrba2a', 'student'),
('Muhammad Faris bin Abdullah','2025161712@student.uitm.edu.my', '$2y$10$AjfPLRSCzdaJBIfWxn52POHM4KNEG9WmjtpgtgMsfaTmp/Kvrba2a', 'student');

INSERT INTO users (name, email, password, role) VALUES
('Dr. Siti Norzahra binti Mahmud', 'admin@uitm.edu.my', '$2y$10$AjfPLRSCzdaJBIfWxn52POHM4KNEG9WmjtpgtgMsfaTmp/Kvrba2a', 'admin'),
('Nadia Farzana binti Rahim', 'hr@techsolutions.com.my', '$2y$10$AjfPLRSCzdaJBIfWxn52POHM4KNEG9WmjtpgtgMsfaTmp/Kvrba2a', 'employer'),
('Dr. Hazim Farhan bin Zainal', 'coordinator02@demo.internpilih.test', '$2y$10$AjfPLRSCzdaJBIfWxn52POHM4KNEG9WmjtpgtgMsfaTmp/Kvrba2a', 'admin');

INSERT INTO admins (user_id, faculty, programme) VALUES
(4, 'Faculty of Computer and Mathematical Sciences', 'Bachelor of Computer Science with Honours (Netcentric Computing)'),
(6, 'Faculty of Computer and Mathematical Sciences', 'Bachelor of Computer Science with Honours');

INSERT INTO students (user_id, matric_id, faculty, programme, coordinator_id, cgpa) VALUES
(1, '2025161705', 'Faculty of Computer and Mathematical Sciences', 'Bachelor of Computer Science with Honours (Netcentric Computing)', 4, 3.67),
(2, '2025161708', 'Faculty of Computer and Mathematical Sciences', 'Bachelor of Computer Science with Honours', 6, 3.55),
(3, '2025161712', 'Faculty of Computer and Mathematical Sciences', 'Bachelor of Computer Science with Honours', 6, 3.40);
INSERT INTO employers (user_id, company_id) VALUES (5, 2);

INSERT INTO offers (student_id, company_id, position, offer_date, deadline, status, response_date) VALUES
(1, 1, 'Data Analytics Intern',        '2026-01-05', '2026-01-20', 'accepted', '2026-01-15'),
(1, 2, 'Cybersecurity Intern',         '2026-01-10', '2026-02-20', 'pending',  NULL),
(1, 3, 'Network Engineering Intern',   '2026-01-12', '2026-03-01', 'pending',  NULL),
(2, 2, 'Software Development Intern',  '2026-01-12', '2026-02-15', 'accepted', '2026-01-18'),
(2, 3, 'Mobile Development Intern',    '2026-01-15', '2026-02-28', 'pending',  NULL),
(3, 2, 'Cloud Operations Intern',      '2026-01-06', '2026-01-20', 'rejected', '2026-01-08');

INSERT INTO documents (student_id, offer_id, doc_type, file_name, status) VALUES
(1, 1, 'offer_letter', 'offer_letter_petronas.pdf', 'approved'),
(1, 1, 'student_id',   'student_id_alif.pdf',       'approved'),
(1, 1, 'agreement',    'agreement_alif.pdf',         'pending');

INSERT INTO internship_applications (student_id, company_id, position, source, status, applied_at, offer_id, notes) VALUES
(1, 1, 'Data Analytics Intern', 'student', 'offered', '2025-12-20', 1, 'Converted into accepted offer.'),
(1, 2, 'Cybersecurity Intern', 'student', 'offered', '2026-01-02', 2, 'Offer received and awaiting student response.'),
(2, 3, 'Network Engineering Intern', 'student', 'submitted', '2026-01-08', NULL, 'Awaiting employer response.');

INSERT INTO announcements (created_by, type, recipient_group, title, body, link_url, recipient_count, email_sent_count, email_failed_count) VALUES
(4, 'urgent', 'all', 'Document submission reminder', 'Please upload your internship agreement and remaining placement documents for coordinator verification.', 'documents.html', 1, 0, 0),
(4, 'reminder', 'pending', 'Respond to pending offers', 'You still have pending internship offers. Please accept or formally reject each offer in InternPilih.', 'offers.html', 1, 0, 0),
(4, 'general', 'all', 'Internship monitoring update', 'The coordinator is reviewing current offer responses and placement progress for this semester.', 'dashboard.html', 1, 0, 0);

INSERT INTO notifications (recipient_user_id, created_by, type, title, body, link_url, recipient_group, is_read) VALUES
(1, 4, 'urgent', 'Document submission reminder', 'Please upload your internship agreement and remaining placement documents for coordinator verification.', 'documents.html', 'all', 0),
(1, NULL, 'system', 'Placement verified', 'Your accepted placement at Petronas ICT Sdn Bhd has been recorded. Please complete all required documents.', 'documents.html', 'student', 0),
(2, 4, 'reminder', 'Respond to pending offers', 'You still have pending internship offers. Please accept or formally reject each offer in InternPilih.', 'offers.html', 'pending', 0),
(3, 4, 'general', 'Internship monitoring update', 'The coordinator is reviewing current offer responses and placement progress for this semester.', 'dashboard.html', 'all', 1);

UPDATE notifications SET announcement_id = 1 WHERE title = 'Document submission reminder';
UPDATE notifications SET announcement_id = 2 WHERE title = 'Respond to pending offers';
UPDATE notifications SET announcement_id = 3 WHERE title = 'Internship monitoring update';

INSERT INTO activity_logs (user_id, action, entity_type, entity_id, metadata) VALUES
(4, 'announcement.seeded', 'announcement', 1, JSON_OBJECT('recipient_group', 'all')),
(1, 'offer.seeded', 'offer', 1, JSON_OBJECT('status', 'accepted')),
(1, 'application.seeded', 'internship_application', 1, JSON_OBJECT('status', 'offered'));

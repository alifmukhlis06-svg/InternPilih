ALTER TABLE users
    ADD COLUMN IF NOT EXISTS google_auth_user_id VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL;

CREATE UNIQUE INDEX uq_users_google_auth_user_id ON users (google_auth_user_id);

CREATE TABLE IF NOT EXISTS authentication_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action ENUM('google_login_success','password_login_success','login_failure','logout','unauthorized_access_attempt') NOT NULL,
    authentication_method ENUM('google','password','session') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_authentication_audit_user_created (user_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

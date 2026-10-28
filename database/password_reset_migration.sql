USE internpilih;

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

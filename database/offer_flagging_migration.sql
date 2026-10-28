-- Automatic offer-risk flagging configuration.
-- Run against the internpilih database for an existing installation.

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


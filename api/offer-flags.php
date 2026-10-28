<?php

// Shared rules for automatically flagging students with unresolved offers.

const DEFAULT_OFFER_RESPONSE_PERIOD_DAYS = 14;

function ensureOfferFlagSettings(): bool {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $ready = (bool)db()->query("
            CREATE TABLE IF NOT EXISTS system_settings (
                setting_key VARCHAR(80) PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL,
                updated_by INT UNSIGNED NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");
        if ($ready) {
            $ready = (bool)db()->query("
                INSERT IGNORE INTO system_settings (setting_key, setting_value)
                VALUES ('offer_response_period_days', '" . DEFAULT_OFFER_RESPONSE_PERIOD_DAYS . "')
            ");
        }
    } catch (Throwable $error) {
        $ready = false;
    }

    if (!$ready) {
        return false;
    }
    return $ready;
}

function offerResponsePeriodDays(): int {
    try {
        $result = db()->query("
            SELECT setting_value
            FROM system_settings
            WHERE setting_key = 'offer_response_period_days'
            LIMIT 1
        ");
    } catch (Throwable $error) {
        $result = false;
    }

    if (!$result || !$result->num_rows) {
        if (!ensureOfferFlagSettings()) {
            return DEFAULT_OFFER_RESPONSE_PERIOD_DAYS;
        }
        try {
            $result = db()->query("
                SELECT setting_value
                FROM system_settings
                WHERE setting_key = 'offer_response_period_days'
                LIMIT 1
            ");
        } catch (Throwable $error) {
            $result = false;
        }
        if (!$result) {
            return DEFAULT_OFFER_RESPONSE_PERIOD_DAYS;
        }
    }

    $row = $result->fetch_assoc();
    $days = (int)($row['setting_value'] ?? DEFAULT_OFFER_RESPONSE_PERIOD_DAYS);
    return max(1, min(90, $days));
}

function offerFlagReasons(int $pendingCount, int $overduePendingCount): array {
    $reasons = [];
    if ($pendingCount > 1) {
        $reasons[] = 'multiple_pending_offers';
    }
    if ($overduePendingCount > 0) {
        $reasons[] = 'response_period_exceeded';
    }
    return $reasons;
}

<?php
// api/settings.php - Coordinator-managed system settings
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/offer-flags.php';

$user = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    respond([
        'offer_response_period_days' => offerResponsePeriodDays(),
    ]);
}

if ($method === 'PUT') {
    if ($user['role'] !== 'admin') {
        respond(['error' => 'Admins only'], 403);
    }

    $data = body();
    $rawDays = $data['offer_response_period_days'] ?? null;
    if (filter_var($rawDays, FILTER_VALIDATE_INT) === false) {
        respond(['error' => 'Response period must be a whole number of days.'], 422);
    }

    $days = (int)$rawDays;
    if ($days < 1 || $days > 90) {
        respond(['error' => 'Response period must be between 1 and 90 days.'], 422);
    }
    if (!ensureOfferFlagSettings()) {
        respond(['error' => 'The system settings table could not be initialized. Run the offer flagging database migration.'], 500);
    }

    $adminId = (int)$user['id'];
    $value = (string)$days;
    $stmt = db()->prepare("
        INSERT INTO system_settings (setting_key, setting_value, updated_by)
        VALUES ('offer_response_period_days', ?, ?)
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_by = VALUES(updated_by),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->bind_param('si', $value, $adminId);
    $stmt->execute();

    logActivity($adminId, 'settings.offer_response_period_updated', 'system_setting', null, [
        'offer_response_period_days' => $days,
    ]);

    respond([
        'success' => true,
        'offer_response_period_days' => $days,
    ]);
}

respond(['error' => 'Method not allowed'], 405);

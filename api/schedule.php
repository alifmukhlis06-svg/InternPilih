<?php
// Canonical internship-cycle dates stored in the database.
require_once __DIR__ . '/config.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['error' => 'Method not allowed'], 405);
}

$keys = [
    'internship_application_open',
    'main_offer_deadline',
    'placement_confirmation_deadline',
    'document_completion_deadline',
    'internship_start_date',
];
$quoted = implode(', ', array_map(static fn(string $key): string => "'{$key}'", $keys));
$result = db()->query("
    SELECT setting_key, setting_value
    FROM system_settings
    WHERE setting_key IN ({$quoted})
");
$schedule = [];
foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
    $schedule[(string)$row['setting_key']] = (string)$row['setting_value'];
}

if (count($schedule) !== count($keys)) {
    respondError('The internship schedule migration has not been applied.', 503, 'schedule_migration_required');
}

respond(['schedule' => $schedule, 'semester' => 'March–August 2027']);

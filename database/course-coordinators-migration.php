<?php
/** Add course-level coordinator columns to an existing database. Run before the demo seeder. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once __DIR__ . '/../config/db.php';
$db = db();
function hasColumn(mysqli $db, string $table, string $column): bool {
    $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}
if (!hasColumn($db, 'admins', 'programme')) {
    $db->query('ALTER TABLE admins ADD COLUMN programme VARCHAR(150) NULL AFTER faculty, ADD UNIQUE KEY unique_admin_programme (programme)');
}
if (!hasColumn($db, 'students', 'coordinator_id')) {
    $db->query('ALTER TABLE students ADD COLUMN coordinator_id INT UNSIGNED NULL AFTER programme, ADD INDEX idx_students_coordinator (coordinator_id), ADD CONSTRAINT fk_students_coordinator FOREIGN KEY (coordinator_id) REFERENCES admins(user_id) ON DELETE SET NULL');
}
echo "Course coordinator columns are ready. Run database/seed-realistic-demo.php to create and assign coordinators.\n";

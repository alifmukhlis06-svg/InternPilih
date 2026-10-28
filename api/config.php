<?php
require_once __DIR__ . '/../config/db.php';

// JSON response helper
function respond(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    echo json_encode($data);
    exit;
}

// CORS pre-flight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    exit;
}

// Get JSON request body
function body(): array {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

// Simple session-based auth check.
// Laragon's default session path can be locked down when PHP is run from CLI,
// so keep this app's sessions in a local writable folder.
$sessionPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'internpilih_sessions';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0775, true);
}
session_save_path($sessionPath);
session_start();
function currentUser(): ?array {
    $sessionUser = $_SESSION['user'] ?? null;
    if (!$sessionUser) return null;
    $id = (int)$sessionUser['id'];
    $stmt = db()->prepare("
        SELECT u.id, u.name, u.email, u.role, s.matric_id,
               COALESCE(s.faculty, a.faculty) AS faculty,
               s.programme, s.cgpa, s.coordinator_id,
               coordinator.name AS coordinator_name,
               a.programme AS coordinator_programme, e.company_id
        FROM users u
        LEFT JOIN students s ON s.user_id = u.id
        LEFT JOIN employers e ON e.user_id = u.id
        LEFT JOIN admins a ON a.user_id = u.id
        LEFT JOIN users coordinator ON coordinator.id = s.coordinator_id
        WHERE u.id = ? LIMIT 1
    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $fresh = $stmt->get_result()->fetch_assoc() ?: null;
    $_SESSION['user'] = $fresh;
    return $fresh;
}
function requireAuth(): array {
    $u = currentUser();
    if (!$u) respond(['error' => 'Unauthenticated'], 401);
    return $u;
}

function requireCoordinatorStudent(int $coordinatorId, int $studentId): void {
    $stmt = db()->prepare('SELECT 1 FROM students WHERE user_id = ? AND coordinator_id = ? LIMIT 1');
    $stmt->bind_param('ii', $studentId, $coordinatorId);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_row()) {
        respond(['error' => 'Student is not assigned to this coordinator.'], 403);
    }
}

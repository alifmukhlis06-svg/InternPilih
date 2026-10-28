<?php
// api/profile.php - Current user profile read/update
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity.php';

$user = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

function profileForUser(int $id): ?array {
    $stmt = db()->prepare("
        SELECT u.id, u.name, u.email, u.role, s.matric_id,
               COALESCE(s.faculty, a.faculty) AS faculty,
               s.programme, s.cgpa, s.coordinator_id, coordinator.name AS coordinator_name,
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
    return $stmt->get_result()->fetch_assoc() ?: null;
}

if ($method === 'GET') {
    respond(['user' => profileForUser((int)$user['id'])]);
}

if ($method === 'PUT') {
    $data = body();
    $current = profileForUser((int)$user['id']);
    if (!$current) respond(['error' => 'User not found.'], 404);
    $name = trim($data['name'] ?? $current['name']);
    $email = trim($data['email'] ?? $current['email']);
    $faculty = trim($data['faculty'] ?? ($current['faculty'] ?? ''));
    $programme = trim($data['programme'] ?? ($current['programme'] ?? ''));
    $cgpa = array_key_exists('cgpa', $data)
        ? ($data['cgpa'] === '' ? null : (float)$data['cgpa'])
        : ($current['cgpa'] ?? null);

    if (!$name || !$email) {
        respond(['error' => 'Name and email are required.'], 422);
    }

    if ($current['role'] === 'student') {
        $expectedEmail = ($current['matric_id'] ?? '') . '@student.uitm.edu.my';
        if ($email !== $expectedEmail) {
            respond(['error' => 'Student email must be your matric number followed by @student.uitm.edu.my.'], 422);
        }
        if ($programme !== $current['programme'] || $faculty !== $current['faculty']) {
            respond(['error' => 'Your faculty and programme are managed by the coordinator.'], 422);
        }
    }

    if ($current['role'] === 'student' && $cgpa !== null && ($cgpa < 0 || $cgpa > 4)) {
        respond(['error' => 'CGPA must be between 0 and 4.'], 422);
    }

    $conn = db();
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?');
        $stmt->bind_param('ssi', $name, $email, $user['id']);
        $stmt->execute();
        if ($current['role'] === 'student') {
            $student = $conn->prepare('UPDATE students SET faculty = ?, programme = ?, cgpa = ? WHERE user_id = ?');
            $student->bind_param('ssdi', $faculty, $programme, $cgpa, $user['id']);
            $student->execute();
        } elseif ($current['role'] === 'admin' && array_key_exists('faculty', $data)) {
            $admin = $conn->prepare('UPDATE admins SET faculty = ? WHERE user_id = ?');
            $admin->bind_param('si', $faculty, $user['id']);
            $admin->execute();
        }
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    $fresh = profileForUser((int)$user['id']);
    $_SESSION['user'] = $fresh;

    logActivity((int)$user['id'], 'profile.updated', 'user', (int)$user['id']);
    respond(['success' => true, 'user' => $fresh]);
}

respond(['error' => 'Method not allowed'], 405);

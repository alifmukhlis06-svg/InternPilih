<?php
// Current user profile. Identity and academic details are intentionally read-only.
require_once __DIR__ . '/config.php';

$user = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

function profileForUser(int $id): ?array {
    $stmt = db()->prepare("
        SELECT u.id, u.name, u.email, to_jsonb(u)->>'phone' AS phone, u.role, s.matric_id,
               COALESCE(s.faculty, a.faculty) AS faculty,
               COALESCE(s.campus, a.campus) AS campus,
               s.programme, s.cgpa, s.coordinator_id, coordinator.name AS coordinator_name,
               a.programme AS coordinator_programme, a.staff_id, e.company_id
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

function acceptedPlacementForStudent(int $studentId): ?array {
    $stmt = db()->prepare("
        SELECT o.id, o.status, o.position, o.response_date, o.internship_start_date,
               o.placement_verification_status,
               c.id AS company_id, c.name AS company_name
        FROM offers o
        JOIN companies c ON c.id = o.company_id
        WHERE o.student_id = ? AND o.status = 'accepted'
        ORDER BY o.response_date DESC NULLS LAST, o.id DESC
        LIMIT 1
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

if ($method === 'GET') {
    $profile = profileForUser((int)$user['id']);
    respond([
        'user' => $profile,
        'placement' => $profile && $profile['role'] === 'student'
            ? acceptedPlacementForStudent((int)$user['id'])
            : null,
    ]);
}

if (in_array($method, ['PUT', 'PATCH', 'POST'], true)) {
    respondError(
        'Profile information is read-only. Contact your coordinator if a record is incorrect.',
        403,
        'profile_read_only'
    );
}

respond(['error' => 'Method not allowed'], 405);

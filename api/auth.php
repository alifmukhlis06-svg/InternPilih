<?php
// api/auth.php  — Login & Logout
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

// POST /api/auth.php  → login
if ($method === 'POST') {
    $data  = body();
    $email = trim($data['email'] ?? '');
    $pass  = $data['password'] ?? '';

    if (!$email || !$pass) {
        respond(['error' => 'Email and password are required.'], 422);
    }

    $stmt = db()->prepare("
        SELECT u.id, u.name, u.email, u.password, u.role,
               s.matric_id, COALESCE(s.faculty, a.faculty) AS faculty,
               s.programme, s.cgpa, s.coordinator_id, coordinator.name AS coordinator_name,
               a.programme AS coordinator_programme, e.company_id
        FROM users u
        LEFT JOIN students s ON s.user_id = u.id
        LEFT JOIN employers e ON e.user_id = u.id
        LEFT JOIN admins a ON a.user_id = u.id
        LEFT JOIN users coordinator ON coordinator.id = s.coordinator_id
        WHERE u.email = ? LIMIT 1
    ");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user || !password_verify($pass, $user['password'])) {
        respond(['error' => 'Invalid email or password.'], 401);
    }

    unset($user['password']);
    $_SESSION['user'] = $user;

    respond(['success' => true, 'user' => $user]);
}

// DELETE /api/auth.php  → logout
if ($method === 'DELETE') {
    session_destroy();
    respond(['success' => true]);
}

// GET /api/auth.php  → get current session user
if ($method === 'GET') {
    $user = currentUser();
    if (!$user) respond(['user' => null]);
    respond(['user' => $user]);
}

respond(['error' => 'Method not allowed'], 405);

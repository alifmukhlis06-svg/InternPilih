<?php
// api/profile.php - Current user profile read/update
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity.php';

$user = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

function profileForUser(int $id): ?array {
    $stmt = db()->prepare("
        SELECT u.id, u.name, u.email, to_jsonb(u)->>'phone' AS phone, u.role, s.matric_id,
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

function acceptedPlacementForStudent(int $studentId): ?array {
    $stmt = db()->prepare("
        SELECT o.id, o.status, o.position, o.response_date,
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

if ($method === 'PUT') {
    $data = body();
    $current = profileForUser((int)$user['id']);
    if (!$current) respond(['error' => 'User not found.'], 404);
    $name = array_key_exists('name', $data) ? trim((string)$data['name']) : (string)$current['name'];
    $email = array_key_exists('email', $data) ? trim((string)$data['email']) : (string)$current['email'];
    $phone = array_key_exists('phone', $data) ? trim((string)$data['phone']) : (string)($current['phone'] ?? '');
    $faculty = array_key_exists('faculty', $data) ? trim((string)$data['faculty']) : (string)($current['faculty'] ?? '');
    $programme = array_key_exists('programme', $data) ? trim((string)$data['programme']) : (string)($current['programme'] ?? '');
    $cgpa = array_key_exists('cgpa', $data)
        ? ($data['cgpa'] === '' ? null : (float)$data['cgpa'])
        : ($current['cgpa'] ?? null);

    if (!$name || !$email) {
        respond(['error' => 'Name and email are required.'], 422);
    }
    if (array_key_exists('email', $data) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respondError('Enter a valid email address.', 422, 'invalid_email');
    }
    if (strlen($phone) > 30 || ($phone !== '' && !preg_match('/^[0-9+() .-]{7,30}$/', $phone))) {
        respondError('Enter a valid phone number using digits and standard phone punctuation.', 422, 'invalid_phone');
    }

    if ($current['role'] === 'student') {
        $expectedEmail = ($current['matric_id'] ?? '') . '@student.uitm.edu.my';
        $emailChanged = array_key_exists('email', $data) && $email !== $current['email'];
        $stagingEmailAllowed = strtolower((string)(getenv('INTERNPILIH_ENVIRONMENT') ?: '')) === 'staging'
            && str_ends_with(strtolower($email), '@example.test');
        if ($emailChanged && $email !== $expectedEmail && !$stagingEmailAllowed) {
            respondError(
                'Student email must be your matric number followed by @student.uitm.edu.my.',
                422,
                'invalid_student_email'
            );
        }
        if ((array_key_exists('programme', $data) && $programme !== $current['programme'])
            || (array_key_exists('faculty', $data) && $faculty !== $current['faculty'])) {
            respond(['error' => 'Your faculty and programme are managed by the coordinator.'], 422);
        }
    }

    if ($current['role'] === 'student' && $cgpa !== null && ($cgpa < 0 || $cgpa > 4)) {
        respond(['error' => 'CGPA must be between 0 and 4.'], 422);
    }

    $conn = db();
    $conn->begin_transaction();
    try {
        $userChanges = [];
        $userValues = [];
        foreach (['name' => $name, 'email' => $email, 'phone' => $phone] as $field => $value) {
            if (array_key_exists($field, $data) && (string)$value !== (string)($current[$field] ?? '')) {
                $userChanges[] = $field . ' = ?';
                $userValues[] = $value;
            }
        }
        if ($userChanges !== []) {
            $userValues[] = (int)$user['id'];
            $stmt = $conn->prepare('UPDATE users SET ' . implode(', ', $userChanges) . ' WHERE id = ?');
            $stmt->execute($userValues);
        }
        if ($current['role'] === 'student' && array_key_exists('cgpa', $data)
            && (string)$cgpa !== (string)($current['cgpa'] ?? '')) {
            $student = $conn->prepare('UPDATE students SET cgpa = ? WHERE user_id = ?');
            $student->execute([$cgpa, (int)$user['id']]);
        } elseif ($current['role'] === 'admin' && array_key_exists('faculty', $data)) {
            $admin = $conn->prepare('UPDATE admins SET faculty = ? WHERE user_id = ?');
            $admin->bind_param('si', $faculty, $user['id']);
            $admin->execute();
        }
        $conn->commit();
    } catch (Throwable $error) {
        if ($conn->inTransaction()) $conn->rollback();
        error_log('InternPilih profile update failed; request=' . INTERNPILIH_REQUEST_ID
            . '; user=' . (int)$user['id'] . '; error=' . $error->getMessage());
        if (str_contains(strtolower($error->getMessage()), 'phone')) {
            respondError(
                'Phone saving is not available until the profile migration is installed.',
                503,
                'profile_phone_migration_required'
            );
        }
        if ((string)$error->getCode() === '23505') {
            respondError('That email address is already in use.', 409, 'email_already_used');
        }
        respondError('Unable to save the profile.', 500, 'profile_update_failed');
    }

    $fresh = profileForUser((int)$user['id']);
    $_SESSION['user'] = $fresh;

    logActivity((int)$user['id'], 'profile.updated', 'user', (int)$user['id']);
    respond([
        'success' => true,
        'user' => $fresh,
        'placement' => $fresh['role'] === 'student'
            ? acceptedPlacementForStudent((int)$user['id'])
            : null,
    ]);
}

respond(['error' => 'Method not allowed'], 405);

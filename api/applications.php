<?php
// api/applications.php - Internship applications before formal offers
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity.php';

$user = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$id = (int)($_GET['id'] ?? 0);

function applicationSelectSql(string $where = ''): string {
    return "
        SELECT
            ia.*,
            u.name AS student_name,
            u.email AS student_email,
            s.matric_id,
            c.name AS company_name,
            c.industry,
            c.location,
            c.hr_email
        FROM internship_applications ia
        JOIN users u ON u.id = ia.student_id
        JOIN students s ON s.user_id = u.id
        JOIN companies c ON c.id = ia.company_id
        $where
        ORDER BY ia.created_at DESC
    ";
}

if ($method === 'GET') {
    if ($user['role'] === 'student') {
        $stmt = db()->prepare(applicationSelectSql("WHERE ia.student_id = ?"));
        $stmt->bind_param('i', $user['id']);
    } elseif ($user['role'] === 'employer') {
        $stmt = db()->prepare(applicationSelectSql("WHERE ia.company_id = ?"));
        $stmt->bind_param('i', $user['company_id']);
    } else {
        $stmt = db()->prepare(applicationSelectSql('WHERE s.coordinator_id = ?'));
        $stmt->bind_param('i', $user['id']);
    }
    $stmt->execute();
    respond(['applications' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
}

if ($method === 'POST') {
    if ($user['role'] !== 'student') respond(['error' => 'Students only'], 403);
    $data = body();
    $companyId = (int)($data['company_id'] ?? 0);
    $position = trim((string)($data['position'] ?? ''));
    $appliedAt = $data['applied_at'] ?? date('Y-m-d');
    $notes = trim((string)($data['notes'] ?? ''));

    if (!$companyId || $position === '') {
        respond(['error' => 'Company and position are required.'], 422);
    }

    $stmt = db()->prepare("
        INSERT INTO internship_applications (student_id, company_id, position, source, status, applied_at, notes)
        VALUES (?, ?, ?, 'student', 'submitted', ?, ?)
    ");
    $stmt->bind_param('iisss', $user['id'], $companyId, $position, $appliedAt, $notes);
    $stmt->execute();
    $applicationId = (int)db()->insert_id;
    logActivity((int)$user['id'], 'application.submitted', 'internship_application', $applicationId, [
        'company_id' => $companyId,
        'position' => $position,
    ]);
    respond(['success' => true, 'id' => $applicationId], 201);
}

if ($method === 'PUT' && $id) {
    if (!in_array($user['role'], ['admin', 'employer', 'student'], true)) respond(['error' => 'Not allowed'], 403);
    $data = body();
    $status = $data['status'] ?? '';
    $allowedStatuses = $user['role'] === 'student'
        ? ['withdrawn']
        : ['submitted', 'shortlisted', 'interview', 'offered', 'rejected'];
    if (!in_array($status, $allowedStatuses, true)) {
        respond(['error' => 'Invalid status'], 422);
    }
    $notes = array_key_exists('notes', $data) ? trim((string)$data['notes']) : null;
    $interviewAt = $data['interview_at'] ?? null;

    if ($user['role'] === 'student') {
        $check = db()->prepare("
            SELECT id
            FROM internship_applications
            WHERE id = ? AND student_id = ? AND status IN ('submitted', 'shortlisted', 'interview')
            LIMIT 1
        ");
        $check->bind_param('ii', $id, $user['id']);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) respond(['error' => 'Application not found or cannot be withdrawn'], 404);
    } elseif ($user['role'] === 'employer') {
        $check = db()->prepare("SELECT id FROM internship_applications WHERE id = ? AND company_id = ? LIMIT 1");
        $check->bind_param('ii', $id, $user['company_id']);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) respond(['error' => 'Application not found'], 404);
    }
    if ($user['role'] === 'admin') {
        $check = db()->prepare('SELECT ia.student_id FROM internship_applications ia WHERE ia.id = ? LIMIT 1');
        $check->bind_param('i', $id);
        $check->execute();
        $target = $check->get_result()->fetch_assoc();
        if (!$target) respond(['error' => 'Application not found'], 404);
        requireCoordinatorStudent((int)$user['id'], (int)$target['student_id']);
    }

    $stmt = db()->prepare("
        UPDATE internship_applications
        SET status = ?, notes = COALESCE(?, notes), interview_at = ?
        WHERE id = ?
    ");
    $stmt->bind_param('sssi', $status, $notes, $interviewAt, $id);
    $stmt->execute();
    logActivity((int)$user['id'], 'application.' . $status, 'internship_application', $id);
    respond(['success' => true]);
}

respond(['error' => 'Method not allowed'], 405);

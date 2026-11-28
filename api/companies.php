<?php
// api/companies.php  — Company CRUD
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity.php';

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$id     = (int)($_GET['id'] ?? 0);

function validatedCompanyInput(array $input): array {
    foreach (['name' => 150, 'industry' => 100, 'location' => 150, 'hr_email' => 150] as $field => $limit) {
        $value = $input[$field] ?? '';
        if (!is_string($value)) respondError('Company fields must be text values.', 422, 'invalid_company_input');
        $input[$field] = trim($value);
        if (($field !== 'hr_email' && $input[$field] === '') || mb_strlen($input[$field], 'UTF-8') > $limit) {
            respondError(ucfirst(str_replace('_', ' ', $field)) . ' is required or exceeds its maximum length.', 422, 'invalid_company_input');
        }
    }
    $allowance = $input['allowance'] ?? 0;
    if ((!is_int($allowance) && !is_float($allowance) && !is_string($allowance))
        || !is_numeric($allowance) || !is_finite((float)$allowance)
        || (float)$allowance < 0 || (float)$allowance > 999999.99
        || abs((float)$allowance * 100 - round((float)$allowance * 100)) > 0.00001) {
        respondError('Allowance must be between RM 0.00 and RM 999999.99, with at most two decimal places.', 422, 'invalid_allowance');
    }
    $input['allowance'] = (float)$allowance;
    $input['work_mode'] = $input['work_mode'] ?? 'On-site';
    if (!in_array($input['work_mode'], ['On-site', 'Hybrid', 'Remote'], true)) {
        respondError('Choose a valid work mode.', 422, 'invalid_work_mode');
    }
    if ($input['hr_email'] !== '' && !filter_var($input['hr_email'], FILTER_VALIDATE_EMAIL)) {
        respondError('Enter a valid HR email or leave it blank.', 422, 'invalid_hr_email');
    }
    return $input;
}

// ── GET — list all companies ──────────────────────────────────
if ($method === 'GET') {
    $search = trim((string)($_GET['search'] ?? ''));

    $sql = "SELECT c.id, c.name, c.industry, c.location, c.allowance, c.work_mode, c.hr_email, c.status, c.created_at,
               (SELECT COUNT(*) FROM offers o WHERE o.company_id = c.id AND o.status = 'accepted') AS confirmed_placements,
               (SELECT string_agg(p.position, '||' ORDER BY p.position)
                FROM (
                    SELECT DISTINCT NULLIF(BTRIM(o.position), '') AS position
                    FROM offers o
                    WHERE o.company_id = c.id
                ) p
                WHERE p.position IS NOT NULL) AS positions
            FROM companies c
            WHERE c.status = 'active'";

    if ($search) {
        $sql .= " AND (c.name ILIKE ? OR c.industry ILIKE ? OR c.location ILIKE ?)";
    }
    $sql .= " ORDER BY c.name";

    $stmt = db()->prepare($sql);
    if ($search) {
        $pattern = '%' . $search . '%';
        $stmt->bind_param('sss', $pattern, $pattern, $pattern);
    }
    $stmt->execute();
    respond($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
}

// ── POST — add company (admin only) ──────────────────────────
if ($method === 'POST') {
    if ($user['role'] !== 'admin') respond(['error' => 'Admins only'], 403);
    $d = validatedCompanyInput(body());
    $allowance = $d['allowance'];
    $workMode = $d['work_mode'];
    $hrEmail = $d['hr_email'];

    $stmt = db()->prepare("
        INSERT INTO companies (name, industry, location, allowance, work_mode, hr_email)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('sssdss',
        $d['name'], $d['industry'], $d['location'],
        $allowance, $workMode, $hrEmail
    );
    $stmt->execute();
    $companyId = (int)db()->insert_id;
    logActivity((int)$user['id'], 'company.created', 'company', $companyId, ['name' => $d['name']]);
    respond(['success' => true, 'id' => $companyId], 201);
}

// ── PUT — update company (admin only) ────────────────────────
if ($method === 'PUT' && $id) {
    if ($user['role'] !== 'admin') respond(['error' => 'Admins only'], 403);
    $d = validatedCompanyInput(body());

    $stmt = db()->prepare("
        UPDATE companies SET name=?, industry=?, location=?, allowance=?, work_mode=?, hr_email=?
        WHERE id=?
    ");
    $stmt->bind_param('sssdssi',
        $d['name'], $d['industry'], $d['location'],
        $d['allowance'], $d['work_mode'], $d['hr_email'], $id
    );
    $stmt->execute();
    logActivity((int)$user['id'], 'company.updated', 'company', $id, ['name' => $d['name'] ?? null]);
    respond(['success' => true]);
}

// ── DELETE — remove company (admin only) ──────────────────────
if ($method === 'DELETE' && $id) {
    if ($user['role'] !== 'admin') respond(['error' => 'Admins only'], 403);
    $stmt = db()->prepare("UPDATE companies SET status = 'inactive' WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    logActivity((int)$user['id'], 'company.inactivated', 'company', $id);
    respond(['success' => true]);
}

respond(['error' => 'Bad request'], 400);

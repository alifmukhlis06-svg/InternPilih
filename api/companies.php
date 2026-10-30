<?php
// api/companies.php  — Company CRUD
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity.php';

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$id     = (int)($_GET['id'] ?? 0);

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
    $d = body();

    foreach (['name', 'industry', 'location'] as $field) {
        if (empty(trim((string)($d[$field] ?? '')))) {
            respond(['error' => ucfirst($field) . ' is required'], 422);
        }
    }

    $allowance = (float)($d['allowance'] ?? 0);
    $workMode = $d['work_mode'] ?? 'On-site';
    if (!in_array($workMode, ['On-site', 'Hybrid', 'Remote'], true)) {
        $workMode = 'On-site';
    }
    $hrEmail = $d['hr_email'] ?? '';

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
    $d = body();

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

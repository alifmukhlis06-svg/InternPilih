<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity.php';

$user = requireAuth();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$id = (int)($_GET['id'] ?? 0);

function normaliseProgrammeValue(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';
    return trim((string)(preg_replace('/\s+/u', ' ', $value) ?? $value));
}

function programmeMatchKeys(string $programme): array {
    $keys = [];
    $exact = normaliseProgrammeValue($programme);
    if ($exact !== '') $keys[] = $exact;
    if (preg_match_all('/\(([^()]*)\)/u', $programme, $matches)) {
        foreach ($matches[1] as $specialisation) {
            $key = normaliseProgrammeValue((string)$specialisation);
            if ($key !== '') $keys[] = $key;
        }
    }
    return array_values(array_unique($keys));
}

function studentProgrammeMatchKeys(array $user): array {
    $keys = programmeMatchKeys((string)($user['programme'] ?? ''));
    $stmt = db()->prepare('SELECT s.programme_code,p.name,p.display_name FROM students s LEFT JOIN academic_programmes p ON p.code=s.programme_code WHERE s.user_id=?');
    $stmt->execute([(int)$user['id']]);
    $student = $stmt->get_result()->fetch_assoc();
    if ($student && $student['programme_code']) {
        $keys[] = normaliseProgrammeValue($student['programme_code']);
        foreach ([$student['name'],$student['display_name']] as $name) if ($name) $keys = array_merge($keys,programmeMatchKeys($name));
        $aliases = db()->prepare('SELECT alias FROM academic_programme_aliases WHERE programme_code=?');
        $aliases->execute([$student['programme_code']]);
        foreach ($aliases->get_result()->fetch_all() as $alias) $keys = array_merge($keys,programmeMatchKeys($alias['alias']));
    }
    return array_values(array_unique($keys));
}

function validatedOpportunityInput(array $input): array {
    $companyId = (int)($input['company_id'] ?? 0);
    $position = trim((string)($input['position_title'] ?? ''));
    $department = trim((string)($input['department'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $location = trim((string)($input['location'] ?? ''));
    $status = strtolower(trim((string)($input['status'] ?? 'open')));
    $intake = trim((string)($input['intake'] ?? ''));
    $deadline = trim((string)($input['application_deadline'] ?? ''));
    $programmes = $input['eligible_programmes'] ?? [];

    if ($companyId <= 0 || $position === '') {
        respondError('Company and position title are required.', 422, 'validation_failed');
    }
    if (!in_array($status, ['open', 'closed', 'inactive'], true)) {
        respondError('Opportunity status is invalid.', 422, 'validation_failed');
    }
    if ($deadline !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $deadline);
        if (!$date || $date->format('Y-m-d') !== $deadline) {
            respondError('Application deadline must be a valid date.', 422, 'validation_failed');
        }
    }
    if (!is_array($programmes)) {
        respondError('Eligible programmes must be a list.', 422, 'validation_failed');
    }

    $eligible = [];
    foreach ($programmes as $programme) {
        $label = trim((string)$programme);
        $matchKeys = programmeMatchKeys($label);
        $normalised = preg_match('/^([A-Z]{1,4}[0-9]{2,3})\s+[—–-]/u', $label, $codeMatch)
            ? normaliseProgrammeValue($codeMatch[1]) : ($matchKeys ? (string)end($matchKeys) : '');
        if ($label === '' || $normalised === '') continue;
        if (strlen($label) > 180) respondError('An eligible programme is too long.', 422, 'validation_failed');
        $eligible[$normalised] = $label;
    }
    if (count($eligible) > 50) {
        respondError('No more than 50 eligible programmes may be assigned.', 422, 'validation_failed');
    }

    return [
        'company_id' => $companyId,
        'position_title' => substr($position, 0, 150),
        'department' => $department === '' ? null : substr($department, 0, 150),
        'description' => $description === '' ? null : $description,
        'location' => $location === '' ? null : substr($location, 0, 150),
        'status' => $status,
        'intake' => $intake === '' ? null : substr($intake, 0, 100),
        'application_deadline' => $deadline === '' ? null : $deadline,
        'eligible_programmes' => $eligible,
    ];
}

function ensureCompanyExists(int $companyId): void {
    $statement = db()->prepare("SELECT 1 FROM companies WHERE id = ? AND status = 'active' LIMIT 1");
    $statement->bind_param('i', $companyId);
    $statement->execute();
    if (!$statement->get_result()->fetch_row()) {
        respondError('The selected company is unavailable.', 422, 'company_unavailable');
    }
}

function replaceEligibleProgrammes(int $opportunityId, array $programmes): void {
    $delete = db()->prepare('DELETE FROM opportunity_programmes WHERE opportunity_id = ?');
    $delete->bind_param('i', $opportunityId);
    $delete->execute();
    if (!$programmes) return;

    $insert = db()->prepare('INSERT INTO opportunity_programmes (opportunity_id, programme, programme_normalized) VALUES (?, ?, ?)');
    foreach ($programmes as $normalised => $label) {
        $insert->bind_param('iss', $opportunityId, $label, $normalised);
        $insert->execute();
    }
}

if ($method === 'GET') {
    $companyId = (int)($_GET['company_id'] ?? 0);
    $sql = "SELECT io.id, io.company_id, io.position_title, io.department, io.description,
                   io.location, io.status, io.intake, io.application_deadline,
                   io.created_at, io.updated_at, c.name AS company_name,
                   op.programme, op.programme_normalized
            FROM internship_opportunities io
            JOIN companies c ON c.id = io.company_id
            LEFT JOIN opportunity_programmes op ON op.opportunity_id = io.id
            WHERE c.status = 'active'";
    if ($companyId > 0) $sql .= ' AND io.company_id = ?';
    $sql .= ' ORDER BY io.created_at DESC, io.id DESC, op.programme';

    $statement = db()->prepare($sql);
    if ($companyId > 0) $statement->bind_param('i', $companyId);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $programmeKeys = $user['role'] === 'student'
        ? studentProgrammeMatchKeys($user)
        : [];
    $today = new DateTimeImmutable('today');
    $opportunities = [];

    foreach ($rows as $row) {
        $opportunityId = (int)$row['id'];
        if (!isset($opportunities[$opportunityId])) {
            $deadline = trim((string)($row['application_deadline'] ?? ''));
            $deadlineCurrent = $deadline === '' || new DateTimeImmutable($deadline) >= $today;
            $opportunities[$opportunityId] = [
                'id' => $opportunityId,
                'company_id' => (int)$row['company_id'],
                'company_name' => $row['company_name'],
                'position_title' => $row['position_title'],
                'department' => $row['department'],
                'description' => $row['description'],
                'is_demo' => str_starts_with(trim((string)($row['description'] ?? '')), '[DEMO]'),
                'location' => $row['location'],
                'status' => $row['status'],
                'intake' => $row['intake'],
                'application_deadline' => $row['application_deadline'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'eligible_programmes' => [],
                'programme_match' => false,
                'is_current' => $row['status'] === 'open' && $deadlineCurrent,
            ];
        }
        $programme = trim((string)($row['programme'] ?? ''));
        if ($programme !== '') {
            $opportunities[$opportunityId]['eligible_programmes'][] = $programme;
            if ($programmeKeys && in_array((string)$row['programme_normalized'], $programmeKeys, true)) {
                $opportunities[$opportunityId]['programme_match'] = true;
            }
        }
    }
    respond(array_values($opportunities));
}

if ($user['role'] !== 'admin') {
    respondError('Coordinator access is required.', 403, 'forbidden');
}

if ($method === 'POST') {
    $data = validatedOpportunityInput(body());
    ensureCompanyExists($data['company_id']);
    $database = db();
    try {
        $database->begin_transaction();
        $statement = $database->prepare("INSERT INTO internship_opportunities
            (company_id, position_title, department, description, location, status, intake, application_deadline)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $statement->bind_param('isssssss', $data['company_id'], $data['position_title'], $data['department'],
            $data['description'], $data['location'], $data['status'], $data['intake'], $data['application_deadline']);
        $statement->execute();
        $opportunityId = (int)$database->insert_id;
        replaceEligibleProgrammes($opportunityId, $data['eligible_programmes']);
        $database->commit();
    } catch (Throwable $error) {
        if ($database->inTransaction()) $database->rollback();
        error_log('InternPilih opportunity creation failed.');
        respondError('The opportunity could not be saved.', 500, 'opportunity_save_failed');
    }
    logActivity((int)$user['id'], 'opportunity.created', 'internship_opportunity', $opportunityId,
        ['company_id' => $data['company_id'], 'position_title' => $data['position_title']]);
    respond(['success' => true, 'id' => $opportunityId], 201);
}

if ($method === 'PUT' && $id > 0) {
    $data = validatedOpportunityInput(body());
    ensureCompanyExists($data['company_id']);
    $database = db();
    try {
        $database->begin_transaction();
        $statement = $database->prepare("UPDATE internship_opportunities
            SET company_id = ?, position_title = ?, department = ?, description = ?, location = ?,
                status = ?, intake = ?, application_deadline = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?");
        $statement->bind_param('isssssssi', $data['company_id'], $data['position_title'], $data['department'],
            $data['description'], $data['location'], $data['status'], $data['intake'], $data['application_deadline'], $id);
        $statement->execute();
        if ($statement->affected_rows === 0) {
            $exists = $database->prepare('SELECT 1 FROM internship_opportunities WHERE id = ?');
            $exists->bind_param('i', $id);
            $exists->execute();
            if (!$exists->get_result()->fetch_row()) {
                $database->rollback();
                respondError('Opportunity not found.', 404, 'opportunity_not_found');
            }
        }
        replaceEligibleProgrammes($id, $data['eligible_programmes']);
        $database->commit();
    } catch (Throwable $error) {
        if ($database->inTransaction()) $database->rollback();
        error_log('InternPilih opportunity update failed.');
        respondError('The opportunity could not be updated.', 500, 'opportunity_update_failed');
    }
    logActivity((int)$user['id'], 'opportunity.updated', 'internship_opportunity', $id,
        ['company_id' => $data['company_id'], 'position_title' => $data['position_title']]);
    respond(['success' => true]);
}

if ($method === 'DELETE' && $id > 0) {
    $statement = db()->prepare("UPDATE internship_opportunities SET status = 'inactive', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $statement->bind_param('i', $id);
    $statement->execute();
    if ($statement->affected_rows === 0) respondError('Opportunity not found.', 404, 'opportunity_not_found');
    logActivity((int)$user['id'], 'opportunity.inactivated', 'internship_opportunity', $id);
    respond(['success' => true]);
}

respondError('Method not allowed.', 405, 'method_not_allowed');

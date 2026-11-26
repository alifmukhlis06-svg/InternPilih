<?php
// Coordinator-only supervisor directory and auditable student assignment.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';

function supervisorCoordinator(): array {
    $user = requireAuth();
    if ($user['role'] !== 'admin') respondError('Coordinator access required.', 403, 'coordinator_required');
    $stmt = db()->prepare('SELECT campus, faculty, programme FROM admins WHERE user_id=?');
    $stmt->execute([(int)$user['id']]);
    $scope = $stmt->get_result()->fetch_assoc();
    if (!$scope || trim((string)$scope['campus']) === '' || trim((string)$scope['faculty']) === '' || trim((string)$scope['programme']) === '') {
        respondError('Your coordinator academic scope is incomplete.', 403, 'incomplete_scope');
    }
    return ['id' => (int)$user['id'], 'scope' => $scope];
}

function supervisorScopeParams(array $coordinator): array {
    return [$coordinator['scope']['campus'], $coordinator['scope']['faculty'], $coordinator['scope']['programme']];
}

function supervisorNotice(int $studentId, int $coordinatorId, ?string $name): void {
    $title = $name === null ? 'Academic Supervisor assignment removed' : 'Academic Supervisor assignment updated';
    $message = $name === null
        ? 'Your Academic Supervisor is currently unassigned. Your Coordinator will update this when a supervisor is available.'
        : 'Your Academic Supervisor is now ' . $name . '. See your profile for contact details.';
    $stmt = db()->prepare("INSERT INTO notifications (recipient_user_id,created_by,type,title,body,link_url,recipient_group)
        VALUES (?,?,'system',?,?,'student/profile.html','student')");
    $stmt->execute([$studentId,$coordinatorId,$title,$message]);
}

$coordinator = supervisorCoordinator();
$method = $_SERVER['REQUEST_METHOD'];
$action = strtolower(trim((string)($_GET['action'] ?? 'list')));
$scope = supervisorScopeParams($coordinator);

if ($method === 'GET' && $action === 'list') {
    $stmt = db()->prepare("SELECT v.id,v.name,v.staff_id,v.email,v.phone,v.campus,v.faculty,v.programme,
        v.email_approved_for_contact,v.created_at,v.updated_at,COUNT(s.user_id) AS student_count
        FROM academic_supervisors v LEFT JOIN students s ON s.academic_supervisor_id=v.id
        WHERE v.campus=? AND v.faculty=? AND v.programme=?
        GROUP BY v.id ORDER BY v.name");
    $stmt->execute($scope);
    $supervisors = $stmt->get_result()->fetch_all();
    $students = db()->prepare("SELECT s.user_id,u.name,u.email,s.matric_id,s.academic_supervisor_id,
        s.academic_supervisor_assigned_at,v.name AS supervisor_name
        FROM students s JOIN users u ON u.id=s.user_id
        LEFT JOIN academic_supervisors v ON v.id=s.academic_supervisor_id
        WHERE s.coordinator_id=? AND s.campus=? AND s.faculty=? AND s.programme=? AND u.role='student'
        ORDER BY u.name");
    $students->execute(array_merge([$coordinator['id']],$scope));
    respond(['scope'=>$coordinator['scope'],'supervisors'=>$supervisors,'students'=>$students->get_result()->fetch_all()]);
}

if ($method === 'GET' && $action === 'history') {
    $stmt = db()->prepare("SELECT h.id,h.student_id,u.name AS student_name,h.previous_supervisor_id,
        previous.name AS previous_name,h.new_supervisor_id,replacement.name AS new_name,h.changed_at,h.internship_intake
        FROM academic_supervisor_assignment_history h
        JOIN students s ON s.user_id=h.student_id JOIN users u ON u.id=s.user_id
        LEFT JOIN academic_supervisors previous ON previous.id=h.previous_supervisor_id
        LEFT JOIN academic_supervisors replacement ON replacement.id=h.new_supervisor_id
        WHERE h.changed_by=? AND s.coordinator_id=? AND s.campus=? AND s.faculty=? AND s.programme=?
        ORDER BY h.changed_at DESC,h.id DESC LIMIT 100");
    $stmt->execute(array_merge([$coordinator['id'],$coordinator['id']],$scope));
    respond(['history'=>$stmt->get_result()->fetch_all()]);
}

if ($method === 'POST' && $action === 'save') {
    $data = body();
    $id = (int)($data['id'] ?? 0);
    $name = trim((string)($data['name'] ?? ''));
    $staffId = trim((string)($data['staff_id'] ?? ''));
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $phone = trim((string)($data['phone'] ?? ''));
    $approved = ($data['email_approved_for_contact'] ?? false) === true;
    if (mb_strlen($name) < 2 || mb_strlen($name) > 150 || $staffId === '' || mb_strlen($staffId) > 50
        || !filter_var($email,FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150
        || ($phone !== '' && (!preg_match('/^\+?[0-9 ()-]{7,30}$/',$phone) || mb_strlen($phone) > 30))) {
        respondError('Check the supervisor name, Staff ID, email and phone.',422,'invalid_supervisor');
    }
    try {
        if ($id > 0) {
            $stmt = db()->prepare('UPDATE academic_supervisors SET name=?,staff_id=?,email=?,phone=?,email_approved_for_contact=?,updated_at=now() WHERE id=? AND campus=? AND faculty=? AND programme=?');
            $stmt->execute(array_merge([$name,$staffId,$email,$phone ?: null,$approved,$id],$scope));
            if ($stmt->affected_rows !== 1) respondError('Supervisor is outside your academic scope.',404,'supervisor_not_found');
        } else {
            $stmt = db()->prepare('INSERT INTO academic_supervisors (name,staff_id,email,phone,campus,faculty,programme,email_approved_for_contact,created_by) VALUES (?,?,?,?,?,?,?,?,?) RETURNING id');
            $stmt->execute(array_merge([$name,$staffId,$email,$phone ?: null],$scope,[$approved,$coordinator['id']]));
            $id = (int)$stmt->get_result()->fetch_assoc()['id'];
        }
        respond(['success'=>true,'id'=>$id]);
    } catch (Throwable $error) {
        error_log('InternPilih supervisor save failed: '.$error->getMessage());
        respondError('Supervisor could not be saved. Check for duplicate Staff ID or email.',409,'supervisor_save_failed');
    }
}

if ($method === 'POST' && $action === 'assign') {
    $data = body();
    $studentIds = $data['student_ids'] ?? [];
    $supervisorId = isset($data['supervisor_id']) && $data['supervisor_id'] !== '' ? (int)$data['supervisor_id'] : null;
    $intake = trim((string)($data['internship_intake'] ?? ''));
    if (!is_array($studentIds) || count($studentIds) < 1 || count($studentIds) > 100
        || count($studentIds) !== count(array_unique($studentIds))
        || array_filter($studentIds,fn($id)=>!filter_var($id,FILTER_VALIDATE_INT) || (int)$id < 1)
        || ($supervisorId !== null && $supervisorId < 1) || mb_strlen($intake) > 100) {
        respondError('Choose up to 100 unique students and a valid supervisor.',422,'invalid_assignment');
    }
    $studentIds = array_map('intval',$studentIds);
    sort($studentIds,SORT_NUMERIC);
    $database = db();
    try {
        $database->begin_transaction();
        $supervisor = null;
        if ($supervisorId !== null) {
            $stmt = $database->prepare('SELECT id,name,email,email_approved_for_contact FROM academic_supervisors WHERE id=? AND campus=? AND faculty=? AND programme=? FOR UPDATE');
            $stmt->execute(array_merge([$supervisorId],$scope));
            $supervisor = $stmt->get_result()->fetch_assoc();
            if (!$supervisor) {
                $database->rollback();
                respondError('Supervisor is outside your academic scope.',403,'supervisor_scope');
            }
        }
        $records = [];
        foreach ($studentIds as $studentId) {
            $studentId = (int)$studentId;
            $stmt = $database->prepare("SELECT s.user_id,s.academic_supervisor_id,u.name FROM students s JOIN users u ON u.id=s.user_id
                WHERE s.user_id=? AND s.coordinator_id=? AND s.campus=? AND s.faculty=? AND s.programme=? AND u.role='student' FOR UPDATE OF s");
            $stmt->execute(array_merge([$studentId,$coordinator['id']],$scope));
            $student = $stmt->get_result()->fetch_assoc();
            if (!$student) {
                $database->rollback();
                respondError('A selected student is outside your academic scope.',403,'student_scope');
            }
            $records[] = $student;
        }
        $changed = [];
        foreach ($records as $student) {
            $studentId = (int)$student['user_id'];
            $old = $student['academic_supervisor_id'] === null ? null : (int)$student['academic_supervisor_id'];
            if ($old === $supervisorId) continue;
            $update = $database->prepare('UPDATE students SET academic_supervisor_id=?,academic_supervisor_assigned_at=CASE WHEN ? IS NULL THEN NULL ELSE now() END WHERE user_id=?');
            $update->execute([$supervisorId,$supervisorId,$studentId]);
            $history = $database->prepare('INSERT INTO academic_supervisor_assignment_history (student_id,previous_supervisor_id,new_supervisor_id,changed_by,internship_intake) VALUES (?,?,?,?,?)');
            $history->execute([$studentId,$old,$supervisorId,$coordinator['id'],$intake ?: null]);
            supervisorNotice($studentId,$coordinator['id'],$supervisor['name'] ?? null);
            $changed[] = $student;
        }
        $database->commit();
        if ($changed && $supervisor && $supervisor['email_approved_for_contact']) {
            try {
                $names = implode(', ',array_map(fn($student)=>$student['name'],$changed));
                sendNotificationEmail(['email'=>$supervisor['email'],'name'=>$supervisor['name']],
                    'Academic Supervisor assignment',
                    'You have been assigned as Academic Supervisor for: '.$names.'.',null);
            } catch (Throwable $error) { error_log('InternPilih supervisor email failed.'); }
        }
        respond(['success'=>true,'changed'=>count($changed)]);
    } catch (Throwable $error) {
        if ($database->inTransaction()) $database->rollback();
        error_log('InternPilih supervisor assignment failed: '.$error->getMessage());
        respondError('Assignment could not be saved.',503,'assignment_failed');
    }
}

respondError('Not found.',404,'not_found');

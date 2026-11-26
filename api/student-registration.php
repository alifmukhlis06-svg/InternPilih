<?php
// First-time student requests and coordinator review. All access is server-side.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/storage-client.php';
require_once __DIR__ . '/mailer.php';

function registrationIdentity(): array {
    $identity = $_SESSION['registration_identity'] ?? null;
    if (!is_array($identity) || (int)($identity['expires_at'] ?? 0) <= time()
        || !preg_match('/^[0-9]{10}@student\.uitm\.edu\.my$/', (string)($identity['email'] ?? ''))
        || empty($identity['google_auth_user_id']) || empty($identity['google_subject'])) {
        unset($_SESSION['registration_identity']);
        respondError('Sign in with your UiTM Google account to continue.', 401, 'registration_session_required');
    }
    return $identity;
}

function registrationRequestFor(array $identity, bool $lock = false): ?array {
    $sql = 'SELECT * FROM student_registration_requests WHERE google_auth_user_id = ? AND google_subject = ? AND lower(email) = ? LIMIT 1'
        . ($lock ? ' FOR UPDATE' : '');
    $stmt = db()->prepare($sql);
    $stmt->execute([$identity['google_auth_user_id'], $identity['google_subject'], $identity['email']]);
    return $stmt->get_result()->fetch_assoc();
}

function registrationOptions(): array {
    $rows = db()->query("SELECT DISTINCT campus, faculty, programme FROM (
        SELECT campus, faculty, programme FROM students
        UNION ALL SELECT campus, faculty, programme FROM admins
    ) academic WHERE nullif(btrim(campus), '') IS NOT NULL
      AND nullif(btrim(faculty), '') IS NOT NULL
      AND nullif(btrim(programme), '') IS NOT NULL
    ORDER BY campus, faculty, programme");
    return $rows instanceof DatabaseResult ? $rows->fetch_all() : [];
}

function coordinatorForProgramme(string $campus, string $faculty, string $programme): ?int {
    $stmt = db()->prepare("SELECT a.user_id FROM admins a JOIN users u ON u.id = a.user_id
        WHERE u.role = 'admin' AND lower(btrim(a.campus)) = lower(?)
          AND lower(btrim(a.faculty)) = lower(?) AND lower(btrim(a.programme)) = lower(?)
        ORDER BY a.user_id LIMIT 2");
    $stmt->execute([$campus, $faculty, $programme]);
    $rows = $stmt->get_result()->fetch_all();
    return count($rows) === 1 ? (int)$rows[0]['user_id'] : null;
}

function registrationFile(): array {
    $file = $_FILES['document'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        respondError('Upload a verification document.', 422, 'document_required');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > 4194304) respondError('The document must be 4 MB or smaller.', 422, 'document_size');
    $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($types[$extension]) || $mime !== $types[$extension]) {
        respondError('Upload a valid PDF, JPG, or PNG document.', 422, 'document_type');
    }
    if ($mime === 'application/pdf') {
        $bytes = file_get_contents($file['tmp_name']);
        if (!is_string($bytes) || !str_starts_with($bytes, '%PDF-')
            || !str_contains(substr($bytes, -2048), '%%EOF')
            || preg_match('#/(?:JavaScript|JS|OpenAction|Launch)\b#i', $bytes)) {
            respondError('The PDF could not be accepted.', 422, 'document_content');
        }
    } elseif (getimagesize($file['tmp_name']) === false) {
        respondError('The image could not be accepted.', 422, 'document_content');
    }
    return [$file, $mime, $size, $extension];
}

function registrationCoordinator(): array {
    $user = requireAuth();
    if ($user['role'] !== 'admin') respondError('Coordinator access required.', 403, 'coordinator_required');
    return $user;
}

function registrationNotice(int $userId, int $reviewerId, string $email, string $name): void {
    $title = 'Student registration approved';
    $message = 'Your InternPilih student registration has been approved. Sign in with UiTM Google. You can also set a password using Forgot Password.';
    try {
        $stmt = db()->prepare("INSERT INTO notifications
            (recipient_user_id, created_by, type, title, body, link_url, recipient_group)
            VALUES (?, ?, 'system', ?, ?, 'student/dashboard.html', 'student') RETURNING id");
        $stmt->execute([$userId, $reviewerId, $title, $message]);
        $noticeId = (int)$stmt->get_result()->fetch_assoc()['id'];
        $delivery = sendNotificationEmail(['email' => $email, 'name' => $name], $title, $message, 'student/dashboard.html');
        updateNotificationEmailStatus($noticeId, $delivery);
    } catch (Throwable $error) {
        error_log('InternPilih registration approval notification failed.');
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$action = strtolower(trim((string)($_GET['action'] ?? '')));

if ($method === 'GET' && $action === 'options') {
    registrationIdentity();
    respond(['academic_options' => registrationOptions()]);
}
if ($method === 'GET' && $action === 'status') {
    $identity = registrationIdentity();
    $request = registrationRequestFor($identity);
    if ($request) {
        unset($request['google_auth_user_id'], $request['google_subject'], $request['document_path'], $request['document_bucket'], $request['document_sha256']);
    }
    respond(['identity' => [
        'name' => $identity['name'], 'email' => $identity['email'],
        'matric_id' => $identity['matric_id'], 'picture' => $identity['picture'],
    ], 'request' => $request]);
}
if ($method === 'POST' && $action === 'submit') {
    $identity = registrationIdentity();
    $name = trim((string)($_POST['legal_name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $campus = trim((string)($_POST['campus'] ?? ''));
    $faculty = trim((string)($_POST['faculty'] ?? ''));
    $programme = trim((string)($_POST['programme'] ?? ''));
    $semester = filter_var($_POST['semester'] ?? null, FILTER_VALIDATE_INT);
    $cgpaInput = trim((string)($_POST['cgpa'] ?? ''));
    $cgpa = $cgpaInput === '' ? null : filter_var($cgpaInput, FILTER_VALIDATE_FLOAT);
    if ($name === '' || mb_strlen($name) > 150 || !preg_match('/^\+?[0-9 ()-]{7,30}$/', $phone)
        || !$semester || $semester < 1 || $semester > 12
        || ($cgpaInput !== '' && ($cgpa === false || $cgpa < 0 || $cgpa > 4))) {
        respondError('Check your name, phone number, semester, and CGPA.', 422, 'invalid_profile');
    }
    foreach ([[$campus,100],[$faculty,100],[$programme,150]] as [$value,$limit]) {
        if (mb_strlen($value) < 2 || mb_strlen($value) > $limit
            || !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} .,&()\/-]*$/u',$value)) {
            respondError('Enter a valid campus, faculty, and programme.',422,'invalid_academic_selection');
        }
    }
    [$file, $mime, $size, $extension] = registrationFile();
    $database = db();
    $uploadedPath = null;
    $oldPath = null;
    try {
        $database->begin_transaction();
        $existingUser = $database->prepare('SELECT id FROM users WHERE lower(btrim(email)) = ? OR google_auth_user_id = ? OR google_subject = ? LIMIT 1 FOR UPDATE');
        $existingUser->execute([$identity['email'], $identity['google_auth_user_id'], $identity['google_subject']]);
        if ($existingUser->get_result()->fetch_assoc()) {
            $database->rollback();
            respondError('An InternPilih account is already linked to this identity. Sign in again.', 409, 'existing_account');
        }
        $existingMatric = $database->prepare('SELECT user_id FROM students WHERE matric_id=? LIMIT 1');
        $existingMatric->execute([$identity['matric_id']]);
        if ($existingMatric->get_result()->fetch_assoc()) {
            $database->rollback();
            respondError('This Student ID is already registered. Contact your coordinator.', 409, 'student_id_already_registered');
        }
        $existing = registrationRequestFor($identity, true);
        if ($existing && $existing['status'] !== 'correction_required') {
            $database->rollback();
            respondError('This registration request cannot be submitted again.', 409, 'duplicate_request');
        }
        $coordinatorId = coordinatorForProgramme($campus, $faculty, $programme);
        if ($coordinatorId !== null) {
            $canonical = $database->prepare('SELECT campus,faculty,programme FROM admins WHERE user_id=?');
            $canonical->execute([$coordinatorId]);
            $academicScope = $canonical->get_result()->fetch_assoc();
            if ($academicScope) {
                $campus = $academicScope['campus'];
                $faculty = $academicScope['faculty'];
                $programme = $academicScope['programme'];
            }
        }
        $status = $coordinatorId === null ? 'pending_assignment' : 'pending';
        $sha = hash_file('sha256', $file['tmp_name']);
        if ($sha === false) throw new RuntimeException('Document checksum failed.');
        $uploadedPath = 'registration/' . bin2hex(random_bytes(16)) . '/' . bin2hex(random_bytes(12)) . '.' . $extension;
        if (storageConfiguration()['driver'] !== 'supabase') throw new RuntimeException('Private Supabase Storage is required.');
        uploadPrivateDocument($uploadedPath, $file['tmp_name'], $mime);
        $documentName = mb_substr(basename((string)$file['name']), 0, 150);
        if ($existing) {
            $oldPath = $existing['document_path'];
            $stmt = $database->prepare("UPDATE student_registration_requests SET legal_name=?, phone=?, campus=?, faculty=?, programme=?,
                semester=?, cgpa=?, coordinator_id=?, document_path=?, document_name=?, document_content_type=?, document_bytes=?,
                document_sha256=?, status=?, review_reason=NULL, reviewed_at=NULL, reviewed_by=NULL, submitted_at=now(), updated_at=now()
                WHERE id=? AND status='correction_required'");
            $stmt->execute([$name, $phone, $campus, $faculty, $programme, $semester, $cgpa, $coordinatorId,
                $uploadedPath, $documentName, $mime, $size, $sha, $status, $existing['id']]);
        } else {
            $stmt = $database->prepare("INSERT INTO student_registration_requests
                (google_auth_user_id, google_subject, google_profile_name, google_picture_url, email, matric_id, legal_name, phone,
                 campus, faculty, programme, semester, cgpa, coordinator_id, document_path, document_name,
                 document_content_type, document_bytes, document_sha256, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$identity['google_auth_user_id'], $identity['google_subject'], $identity['name'], $identity['picture'], $identity['email'],
                $identity['matric_id'], $name, $phone, $campus, $faculty, $programme, $semester, $cgpa,
                $coordinatorId, $uploadedPath, $documentName, $mime, $size, $sha, $status]);
        }
        $database->commit();
        if ($oldPath) { try { deletePrivateDocument($oldPath); } catch (Throwable $ignored) {} }
        respond(['success' => true, 'status' => $status]);
    } catch (Throwable $error) {
        if ($database->inTransaction()) $database->rollback();
        if ($uploadedPath) { try { deletePrivateDocument($uploadedPath); } catch (Throwable $ignored) {} }
        error_log('InternPilih student registration submission failed: ' . $error->getMessage());
        respondError('Registration could not be submitted. Please try again.', 503, 'registration_unavailable');
    }
}
if ($method === 'GET' && $action === 'requests') {
    $coordinator = registrationCoordinator();
    $stmt = db()->prepare("SELECT r.id, r.legal_name, r.matric_id, r.email, r.campus, r.faculty, r.programme,
        r.semester, r.cgpa, r.phone, r.document_name, r.status, r.review_reason, r.submitted_at, r.reviewed_at
        FROM student_registration_requests r JOIN admins a ON a.user_id = ?
        WHERE (r.coordinator_id = a.user_id OR (r.coordinator_id IS NULL AND r.status = 'pending_assignment'))
          AND lower(btrim(a.campus)) = lower(btrim(r.campus))
          AND lower(btrim(a.faculty)) = lower(btrim(r.faculty))
          AND lower(btrim(a.programme)) = lower(btrim(r.programme))
        ORDER BY r.submitted_at DESC");
    $stmt->execute([(int)$coordinator['id']]);
    respond(['requests' => $stmt->get_result()->fetch_all()]);
}
if ($method === 'POST' && $action === 'claim') {
    $coordinator = registrationCoordinator();
    $id = (int)(body()['id'] ?? 0);
    if ($id < 1) respondError('Invalid request.', 422, 'invalid_request');
    $database = db();
    try {
        $database->begin_transaction();
        $stmt = $database->prepare("SELECT * FROM student_registration_requests WHERE id=? AND status='pending_assignment' AND coordinator_id IS NULL FOR UPDATE");
        $stmt->execute([$id]);
        $request = $stmt->get_result()->fetch_assoc();
        if (!$request || coordinatorForProgramme($request['campus'], $request['faculty'], $request['programme']) !== (int)$coordinator['id']) {
            $database->rollback();
            respondError('This request is outside your academic scope.', 403, 'unassigned_request');
        }
        $scope = $database->prepare('SELECT campus,faculty,programme FROM admins WHERE user_id=?');
        $scope->execute([(int)$coordinator['id']]);
        $academicScope = $scope->get_result()->fetch_assoc();
        $update = $database->prepare("UPDATE student_registration_requests SET coordinator_id=?,campus=?,faculty=?,programme=?,status='pending',updated_at=now() WHERE id=?");
        $update->execute([(int)$coordinator['id'],$academicScope['campus'],$academicScope['faculty'],$academicScope['programme'],$id]);
        $database->commit();
        respond(['success' => true, 'status' => 'pending']);
    } catch (Throwable $error) {
        if ($database->inTransaction()) $database->rollback();
        respondError('Assignment could not be saved.', 503, 'assignment_unavailable');
    }
}
if ($method === 'GET' && $action === 'students') {
    $coordinator = registrationCoordinator();
    $stmt = db()->prepare("SELECT u.id, u.name, u.email, s.matric_id, s.campus, s.faculty, s.programme, s.cgpa,
        r.semester FROM students s JOIN users u ON u.id=s.user_id
        LEFT JOIN student_registration_requests r ON r.approved_user_id=u.id
        JOIN admins a ON a.user_id=?
        WHERE s.coordinator_id=a.user_id AND u.role='student'
          AND lower(btrim(s.campus))=lower(btrim(a.campus))
          AND lower(btrim(s.faculty))=lower(btrim(a.faculty))
          AND lower(btrim(s.programme))=lower(btrim(a.programme))
        ORDER BY u.name");
    $stmt->execute([(int)$coordinator['id']]);
    respond(['students' => $stmt->get_result()->fetch_all()]);
}
if ($method === 'GET' && $action === 'document') {
    $id = (int)($_GET['id'] ?? 0);
    $user = currentUser();
    $identity = $user ? null : registrationIdentity();
    $stmt = db()->prepare('SELECT * FROM student_registration_requests WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $request = $stmt->get_result()->fetch_assoc();
    $allowed = $request && (($identity && hash_equals($request['google_auth_user_id'], $identity['google_auth_user_id'])
        && hash_equals($request['google_subject'], $identity['google_subject'])
        && hash_equals(strtolower($request['email']), $identity['email']))
        || ($user && $user['role'] === 'admin' && (int)$request['coordinator_id'] === (int)$user['id']
            && coordinatorForProgramme($request['campus'], $request['faculty'], $request['programme']) === (int)$user['id']));
    if (!$allowed) respondError('Document not found.', 404, 'document_not_found');
    try {
        $bytes = downloadPrivateDocumentBytes($request['document_path']);
        if (strlen($bytes) !== (int)$request['document_bytes'] || hash('sha256', $bytes) !== $request['document_sha256']) {
            throw new RuntimeException('Document integrity mismatch.');
        }
        header('Content-Type: ' . $request['document_content_type']);
        header('Content-Disposition: attachment; filename="verification-document"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: private, no-store');
        echo $bytes;
        exit;
    } catch (Throwable $error) {
        respondError('Document is temporarily unavailable.', 503, 'document_unavailable');
    }
}
if ($method === 'POST' && $action === 'review') {
    $coordinator = registrationCoordinator();
    $data = body();
    $id = (int)($data['id'] ?? 0);
    $decision = (string)($data['decision'] ?? '');
    $reason = trim((string)($data['reason'] ?? ''));
    if ($id < 1 || !in_array($decision, ['approve', 'reject', 'correction_required'], true)
        || ($decision !== 'approve' && ($reason === '' || mb_strlen($reason) > 1000))) {
        respondError('Choose a decision and provide a reason when required.', 422, 'invalid_review');
    }
    $database = db();
    try {
        $database->begin_transaction();
        $stmt = $database->prepare("SELECT * FROM student_registration_requests WHERE id=? AND coordinator_id=? AND status='pending' FOR UPDATE");
        $stmt->execute([$id, (int)$coordinator['id']]);
        $request = $stmt->get_result()->fetch_assoc();
        if (!$request || coordinatorForProgramme($request['campus'], $request['faculty'], $request['programme']) !== (int)$coordinator['id']) {
            $database->rollback();
            respondError('This request is unavailable for your review.', 403, 'unassigned_request');
        }
        $approvedUserId = null;
        if ($decision === 'approve') {
            $duplicate = $database->prepare('SELECT id FROM users WHERE lower(btrim(email))=? OR google_auth_user_id=? OR google_subject=? LIMIT 1 FOR UPDATE');
            $duplicate->execute([$request['email'], $request['google_auth_user_id'], $request['google_subject']]);
            $studentDuplicate = $database->prepare('SELECT user_id FROM students WHERE matric_id=? LIMIT 1 FOR UPDATE');
            $studentDuplicate->execute([$request['matric_id']]);
            if ($duplicate->get_result()->fetch_assoc() || $studentDuplicate->get_result()->fetch_assoc()) {
                $database->rollback();
                respondError('A student account with this identity or Student ID already exists.', 409, 'duplicate_student');
            }
            // No known password exists until the student uses verified email recovery.
            $unusablePassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $insertUser = $database->prepare("INSERT INTO users (name,email,phone,password,role,google_auth_user_id,google_subject)
                VALUES (?,?,?,?,'student',?,?) RETURNING id");
            $insertUser->execute([$request['legal_name'], $request['email'], $request['phone'], $unusablePassword, $request['google_auth_user_id'], $request['google_subject']]);
            $approvedUserId = (int)$insertUser->get_result()->fetch_assoc()['id'];
            $insertStudent = $database->prepare('INSERT INTO students (user_id,matric_id,campus,faculty,programme,coordinator_id,cgpa) VALUES (?,?,?,?,?,?,?)');
            $insertStudent->execute([$approvedUserId, $request['matric_id'], $request['campus'], $request['faculty'],
                $request['programme'], (int)$coordinator['id'], $request['cgpa']]);
        }
        $status = $decision === 'approve' ? 'approved' : ($decision === 'reject' ? 'rejected' : 'correction_required');
        $update = $database->prepare('UPDATE student_registration_requests SET status=?, review_reason=?, reviewed_at=now(), reviewed_by=?, approved_user_id=?, updated_at=now() WHERE id=?');
        $update->execute([$status, $decision === 'approve' ? null : $reason, (int)$coordinator['id'], $approvedUserId, $id]);
        $database->commit();
        if ($approvedUserId) registrationNotice($approvedUserId, (int)$coordinator['id'], $request['email'], $request['legal_name']);
        respond(['success' => true, 'status' => $status]);
    } catch (Throwable $error) {
        if ($database->inTransaction()) $database->rollback();
        error_log('InternPilih student registration review failed: ' . $error->getMessage());
        respondError('Review could not be saved.', 503, 'review_unavailable');
    }
}

respondError('Not found.', 404, 'not_found');

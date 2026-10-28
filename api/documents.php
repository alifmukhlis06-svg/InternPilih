<?php
// api/documents.php  — Document upload & verification
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/placement-verification.php';

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$id     = (int)($_GET['id'] ?? 0);

$uploadDir = __DIR__ . '/../uploads/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// ── GET — list documents ──────────────────────────────────────
if ($method === 'GET') {
    if ($user['role'] === 'student') {
        $stmt = db()->prepare("
            SELECT d.*, c.name AS company_name, o.placement_verification_status
            FROM documents d
            LEFT JOIN offers o ON o.id = d.offer_id
            LEFT JOIN companies c ON c.id = o.company_id
            WHERE d.student_id = ?
            ORDER BY d.uploaded_at DESC
        ");
        $stmt->bind_param('i', $user['id']);
    } elseif ($user['role'] === 'admin') {
        // Coordinator sees documents for their assigned students.
        $stmt = db()->prepare("
            SELECT d.*, u.name AS student_name, s.matric_id, c.name AS company_name,
                   o.position, o.status AS offer_status, o.placement_verification_status
            FROM documents d
            JOIN users u ON u.id = d.student_id
            JOIN students s ON s.user_id = u.id
            LEFT JOIN offers o ON o.id = d.offer_id
            LEFT JOIN companies c ON c.id = o.company_id
            WHERE s.coordinator_id = ?
            ORDER BY d.uploaded_at DESC
        ");
        $stmt->bind_param('i', $user['id']);
    } else {
        respond(['error' => 'Forbidden'], 403);
    }
    $stmt->execute();
    respond($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
}

// ── POST — upload document ────────────────────────────────────
if ($method === 'POST') {
    if ($user['role'] !== 'student') respond(['error' => 'Students only'], 403);

    if (empty($_FILES['file'])) respond(['error' => 'No file uploaded'], 422);

    $allowed = ['pdf','jpg','jpeg','png'];
    $file    = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) respond(['error' => 'File upload failed'], 422);
    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) respond(['error' => 'File type not allowed'], 422);
    if ($file['size'] > 5 * 1024 * 1024) respond(['error' => 'File too large (max 5MB)'], 422);

    $offerId  = (int)($_POST['offer_id'] ?? 0) ?: null;
    $docType  = $_POST['doc_type'] ?? 'other';
    $allowedDocTypes = ['offer_letter', 'agreement', 'student_id', 'supervisor_letter', 'other'];
    if (!in_array($docType, $allowedDocTypes, true)) respond(['error' => 'Invalid document type'], 422);

    if ($offerId !== null) {
        $offerStmt = db()->prepare("SELECT id FROM offers WHERE id = ? AND student_id = ? AND status = 'accepted' LIMIT 1");
        $offerStmt->bind_param('ii', $offerId, $user['id']);
        $offerStmt->execute();
        if (!$offerStmt->get_result()->fetch_assoc()) {
            respond(['error' => 'Documents can only be linked to your accepted placement.'], 422);
        }
    } elseif (in_array($docType, REQUIRED_PLACEMENT_DOCUMENT_TYPES, true)) {
        respond(['error' => 'Select an accepted placement for this required document.'], 422);
    }

    $fileName = uniqid('doc_') . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
        respond(['error' => 'Unable to store uploaded file'], 500);
    }

    $stmt = db()->prepare("
        INSERT INTO documents (student_id, offer_id, doc_type, file_name)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param('iiss', $user['id'], $offerId, $docType, $fileName);
    $stmt->execute();
    $docId = (int)db()->insert_id;
    $placement = $offerId !== null
        ? recalculatePlacementVerification($offerId, (int)$user['id'])
        : ['changed' => false, 'status' => null];
    logActivity((int)$user['id'], 'document.uploaded', 'document', $docId, [
        'doc_type' => $docType,
        'offer_id' => $offerId,
        'placement_verification_status' => $placement['status'],
    ]);
    if ($offerId !== null && $placement['changed']) {
        logActivity((int)$user['id'], 'placement.verification_status_changed', 'offer', $offerId, [
            'from' => $placement['previous_status'],
            'to' => $placement['status'],
            'trigger' => 'document_uploaded',
            'document_id' => $docId,
        ]);
    }
    respond([
        'success' => true,
        'file' => $fileName,
        'placement_verification_status' => $placement['status'],
    ], 201);
}

// ── PUT — admin approve/reject ────────────────────────────────
if ($method === 'PUT' && $id) {
    if ($user['role'] !== 'admin') respond(['error' => 'Admins only'], 403);
    $d      = body();
    $status = $d['status'] ?? '';
    if (!in_array($status, ['approved', 'rejected'])) respond(['error' => 'Invalid status'], 422);
    $reason = $status === 'rejected' ? trim((string)($d['reject_reason'] ?? '')) : null;

    $docStmt = db()->prepare("
        SELECT d.student_id, d.offer_id, d.doc_type, u.name, u.email,
               c.name AS company_name, o.position
        FROM documents d
        JOIN users u ON u.id = d.student_id
        LEFT JOIN offers o ON o.id = d.offer_id
        LEFT JOIN companies c ON c.id = o.company_id
        WHERE d.id = ?
        LIMIT 1
    ");
    $docStmt->bind_param('i', $id);
    $docStmt->execute();
    $doc = $docStmt->get_result()->fetch_assoc();
    if (!$doc) respond(['error' => 'Document not found'], 404);
    requireCoordinatorStudent((int)$user['id'], (int)$doc['student_id']);

    $placement = ['changed' => false, 'status' => null, 'previous_status' => null];
    db()->begin_transaction();
    try {
        $reviewedBy = (int)$user['id'];
        $stmt = db()->prepare("
            UPDATE documents
            SET status = ?, reject_reason = ?, reviewed_by = ?, reviewed_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param('ssii', $status, $reason, $reviewedBy, $id);
        $stmt->execute();

        if (!empty($doc['offer_id'])) {
            $placement = recalculatePlacementVerification((int)$doc['offer_id'], $reviewedBy);
        }

        logActivity($reviewedBy, 'document.' . $status, 'document', $id, [
            'reject_reason' => $reason,
            'offer_id' => $doc['offer_id'] ? (int)$doc['offer_id'] : null,
            'placement_verification_status' => $placement['status'],
        ]);
        if (!empty($doc['offer_id']) && $placement['changed']) {
            logActivity($reviewedBy, 'placement.verification_status_changed', 'offer', (int)$doc['offer_id'], [
                'from' => $placement['previous_status'],
                'to' => $placement['status'],
                'trigger' => 'document_' . $status,
                'document_id' => $id,
            ]);
        }
        db()->commit();
    } catch (Throwable $error) {
        db()->rollback();
        respond(['error' => 'Unable to update document verification.'], 500);
    }

    if ($doc) {
        $placementStatus = $placement['status'];
        $title = $placementStatus === 'verified'
            ? 'Placement verified'
            : ($status === 'approved' ? 'Document approved' : 'Document rejected');
        $docName = ucwords(str_replace('_', ' ', $doc['doc_type']));
        if ($placementStatus === 'verified') {
            $body = "All required documents for your placement at {$doc['company_name']} have been approved. Your placement is now verified.";
        } else {
            $body = $status === 'approved'
                ? "$docName has been approved by the coordinator."
                    . ($placementStatus !== null ? ' Your placement verification status is ' . str_replace('_', ' ', $placementStatus) . '.' : '')
                : "$docName was rejected. " . ($reason ? "Reason: $reason" : 'Please review and upload the correct file.');
        }
        $type = $status === 'approved' ? 'system' : 'urgent';
        $link = 'documents.html';
        $group = 'student';
        $createdBy = (int)$user['id'];
        $recipient = (int)$doc['student_id'];
        $note = db()->prepare("
            INSERT INTO notifications (recipient_user_id, created_by, type, title, body, link_url, recipient_group)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $note->bind_param('iisssss', $recipient, $createdBy, $type, $title, $body, $link, $group);
        $note->execute();
        $notificationId = (int)db()->insert_id;
        $emailResult = sendNotificationEmail($doc, $title, $body, $link);
        updateNotificationEmailStatus($notificationId, $emailResult);
    }

    respond([
        'success' => true,
        'placement_verification_status' => $placement['status'],
        'placement_verification_changed' => $placement['changed'],
    ]);
}

respond(['error' => 'Bad request'], 400);

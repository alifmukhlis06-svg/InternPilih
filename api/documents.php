<?php
// api/documents.php  — Document upload & verification
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/placement-verification.php';
require_once __DIR__ . '/storage-client.php';

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$id     = (int)($_GET['id'] ?? 0);

$uploadDir = __DIR__ . '/../uploads/';

/**
 * Return the student's one active accepted placement.
 *
 * @return array<string,mixed>|null
 */
function currentAcceptedPlacement(int $studentId): ?array {
    $stmt = db()->prepare("
        SELECT o.id, o.company_id, o.position, o.placement_verification_status,
               c.name AS company_name
        FROM offers o
        JOIN companies c ON c.id = o.company_id
        WHERE o.student_id = ? AND o.status = 'accepted'
        ORDER BY COALESCE(o.response_date, o.offer_date) DESC, o.id DESC
        LIMIT 2
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $placements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if (count($placements) > 1) {
        respondError(
            'Multiple active placements were found. Please contact your internship coordinator.',
            409,
            'multiple_active_placements'
        );
    }
    return $placements[0] ?? null;
}

// ── GET — list documents ──────────────────────────────────────
if ($method === 'GET') {
    if ($user['role'] === 'student') {
        $placement = currentAcceptedPlacement((int)$user['id']);
        $placementId = (int)($placement['id'] ?? 0);
        header('X-InternPilih-Placement-Offer-Id: ' . $placementId);
        $scope = strtolower(trim((string)($_GET['scope'] ?? 'current')));
        if (!in_array($scope, ['current', 'history'], true)) {
            respondError('Invalid document scope.', 422, 'invalid_document_scope');
        }

        if ($scope === 'history') {
            $stmt = db()->prepare("
                SELECT d.*, c.name AS company_name, o.position,
                       o.status AS offer_status, o.placement_verification_status
                FROM documents d
                LEFT JOIN offers o ON o.id = d.offer_id
                LEFT JOIN companies c ON c.id = o.company_id
                WHERE d.student_id = ?
                  AND (d.offer_id IS NULL OR d.offer_id <> ?)
                ORDER BY d.uploaded_at DESC, d.id DESC
            ");
            $stmt->bind_param('ii', $user['id'], $placementId);
        } elseif ($placement === null) {
            respond([]);
        } else {
            // Upload Docs is placement-scoped: never mix documents from an old
            // withdrawn placement into the current accepted placement.
            $stmt = db()->prepare("
                SELECT d.*, c.name AS company_name, o.position,
                       o.status AS offer_status, o.placement_verification_status
                FROM documents d
                JOIN offers o ON o.id = d.offer_id
                JOIN companies c ON c.id = o.company_id
                WHERE d.student_id = ? AND d.offer_id = ?
                  AND o.student_id = ? AND o.status = 'accepted'
                ORDER BY d.uploaded_at DESC, d.id DESC
            ");
            $studentId = (int)$user['id'];
            $stmt->bind_param('iii', $studentId, $placementId, $studentId);
        }
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
    if ($user['role'] !== 'student') respondError('Students only', 403, 'student_role_required');

    if (empty($_FILES['file'])) {
        respondError('No file reached the upload endpoint. Select a file and try again.', 422, 'file_missing');
    }

    $allowed = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];
    $file    = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        error_log('InternPilih document upload rejected before processing; request=' . INTERNPILIH_REQUEST_ID
            . '; php_upload_error=' . $uploadError);
        respondError('PHP could not receive the complete file. Check the file size and try again.', 422, 'php_upload_failed');
    }
    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) respondError('Only PDF, JPG, and PNG files are allowed.', 422, 'file_type_not_allowed');
    $storage = storageConfiguration();
    $maximumBytes = $storage['driver'] === 'supabase' ? 4 * 1024 * 1024 : 5 * 1024 * 1024;
    if ($file['size'] > $maximumBytes) {
        respondError('File too large (max ' . ($maximumBytes / 1024 / 1024) . 'MB)', 422, 'file_too_large');
    }
    if (!is_uploaded_file($file['tmp_name'])) respondError('Invalid uploaded file', 422, 'invalid_upload');
    $detectedMime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if ($detectedMime === false || !in_array($detectedMime, $allowed[$ext], true)) {
        respondError('The uploaded file content does not match its extension.', 422, 'file_content_mismatch');
    }

    $requestedOfferId = (int)($_POST['offer_id'] ?? 0) ?: null;
    $offerId = null;
    $docType  = $_POST['doc_type'] ?? 'other';
    $allowedDocTypes = ['offer_letter', 'agreement', 'student_id', 'supervisor_letter', 'other'];
    if (!in_array($docType, $allowedDocTypes, true)) respondError('Invalid document type', 422, 'invalid_document_type');

    $placementDocumentTypes = ['offer_letter', 'agreement', 'student_id', 'supervisor_letter'];
    if (in_array($docType, $placementDocumentTypes, true)) {
        $placement = currentAcceptedPlacement((int)$user['id']);
        if ($placement === null) {
            respondError('Accept a placement before uploading placement documents.', 409, 'accepted_placement_required');
        }
        $offerId = (int)$placement['id'];
        if ($requestedOfferId !== null && $requestedOfferId !== $offerId) {
            respondError(
                'Your placement changed before this upload completed. Refresh the page and try again.',
                409,
                'placement_changed',
                ['current_offer_id' => $offerId]
            );
        }
    } elseif ($requestedOfferId !== null) {
        $placement = currentAcceptedPlacement((int)$user['id']);
        if ($placement === null || (int)$placement['id'] !== $requestedOfferId) {
            respondError('Documents can only be linked to your accepted placement.', 409, 'accepted_placement_required');
        }
        $offerId = $requestedOfferId;
    }

    error_log('InternPilih document upload started; request=' . INTERNPILIH_REQUEST_ID
        . '; student=' . (int)$user['id'] . '; offer=' . (int)($offerId ?? 0)
        . '; type=' . $docType . '; bytes=' . (int)$file['size']);

    $fileName = 'doc_' . bin2hex(random_bytes(16)) . '.' . $ext;
    $database = db();
    $uploadedObjectPath = null;
    $database->begin_transaction();
    try {
        if ($storage['driver'] === 'supabase') {
            $sha256 = hash_file('sha256', $file['tmp_name']);
            if ($sha256 === false) throw new RuntimeException('Could not checksum the uploaded file.');
            $objectPrefix = $offerId !== null
                ? sprintf('students/%d/offers/%d', (int)$user['id'], (int)$offerId)
                : sprintf('students/%d/unassigned', (int)$user['id']);
            $objectPath = sprintf(
                '%s/%s-%s.%s',
                $objectPrefix,
                bin2hex(random_bytes(12)),
                substr($sha256, 0, 16),
                $ext
            );
            uploadPrivateDocument($objectPath, $file['tmp_name'], $detectedMime);
            $uploadedObjectPath = $objectPath;
            $stmt = $database->prepare("
                INSERT INTO documents
                    (student_id, offer_id, doc_type, file_name, storage_bucket,
                     storage_object_path, content_type, byte_size, sha256, storage_migrated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $bucket = $storage['bucket'];
            $byteSize = (int)$file['size'];
            $stmt->bind_param(
                'iisssssis',
                $user['id'], $offerId, $docType, $fileName, $bucket,
                $objectPath, $detectedMime, $byteSize, $sha256
            );
        } elseif ($storage['driver'] === 'local') {
            if (!is_dir($uploadDir)
                && !mkdir($uploadDir, 0755, true)
                && !is_dir($uploadDir)) {
                throw new RuntimeException('Unable to initialise local document storage');
            }
            if (!move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
                throw new RuntimeException('Unable to store uploaded file');
            }
            $stmt = $database->prepare("
                INSERT INTO documents (student_id, offer_id, doc_type, file_name)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->bind_param('iiss', $user['id'], $offerId, $docType, $fileName);
        } else {
            throw new RuntimeException('Document storage is not configured.');
        }
        $stmt->execute();
        $docId = (int)$database->insert_id;
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
        $database->commit();
    } catch (Throwable $error) {
        if ($database->inTransaction()) $database->rollback();
        if ($uploadedObjectPath !== null) {
            try {
                deletePrivateDocument($uploadedObjectPath);
            } catch (Throwable $cleanupError) {
                error_log('InternPilih failed to clean up an uncommitted Storage object.');
            }
        }
        error_log('InternPilih document upload failed; request=' . INTERNPILIH_REQUEST_ID
            . '; student=' . (int)$user['id'] . '; error=' . $error->getMessage());
        $errorMessage = $error->getMessage();
        if (str_contains($errorMessage, 'not configured')) {
            respondError('Document storage is not configured for this environment.', 503, 'storage_not_configured', ['retryable' => false]);
        }
        if (str_contains($errorMessage, 'Storage request failed')) {
            respondError('Document storage could not be reached. Try again shortly.', 503, 'storage_unavailable', ['retryable' => true]);
        }
        if (str_contains($errorMessage, 'rejected by Storage')) {
            respondError('Document storage rejected the upload. Ask the staging administrator to verify the private bucket credentials.', 502, 'storage_rejected', ['retryable' => false]);
        }
        respondError('Unable to store the uploaded file.', 500, 'document_store_failed', ['retryable' => true]);
    }
    error_log('InternPilih document upload completed; request=' . INTERNPILIH_REQUEST_ID
        . '; document=' . $docId . '; student=' . (int)$user['id']);
    respond([
        'success' => true,
        'document_id' => $docId,
        'offer_id' => $offerId,
        'file' => $fileName,
        'status' => 'pending',
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

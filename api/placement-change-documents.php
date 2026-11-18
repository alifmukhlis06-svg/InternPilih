<?php
// Upload, replace, verify, and reject placement-change evidence.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/storage-client.php';

$user = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$id = (int)($_GET['id'] ?? 0);
$uploadDir = __DIR__ . '/../uploads/';
$documentTypes = ['proof_of_sending', 'placement_change_letter', 'company_response'];

function placementChangeDocumentRecord(int $id): ?array {
    $stmt = db()->prepare("
        SELECT pcd.*, pcr.student_id, pcr.status AS request_status,
               pcr.revision_deadline, s.coordinator_id
        FROM placement_change_documents pcd
        JOIN placement_change_requests pcr ON pcr.id = pcd.request_id
        JOIN students s ON s.user_id = pcr.student_id
        WHERE pcd.id = ? LIMIT 1
    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

if ($method === 'POST') {
    if ($user['role'] !== 'student') respondError('Students only', 403, 'student_role_required');
    $requestId = (int)($_POST['request_id'] ?? 0);
    $documentType = (string)($_POST['document_type'] ?? '');
    if ($requestId <= 0 || !in_array($documentType, $documentTypes, true)) {
        respondError('Choose a valid placement-change document.', 422, 'invalid_document_type');
    }
    if (empty($_FILES['file'])) respondError('Select a file to upload.', 422, 'file_missing');

    $lookup = db()->prepare("
        SELECT pcd.*, pcr.student_id, pcr.status AS request_status
        FROM placement_change_documents pcd
        JOIN placement_change_requests pcr ON pcr.id = pcd.request_id
        WHERE pcd.request_id = ? AND pcd.document_type = ? AND pcr.student_id = ? LIMIT 1
    ");
    $lookup->bind_param('isi', $requestId, $documentType, $user['id']);
    $lookup->execute();
    $document = $lookup->get_result()->fetch_assoc();
    if (!$document) respondError('Placement-change document not found.', 404, 'document_not_found');
    $requestStatus = (string)$document['request_status'];
    $canUpload = in_array($requestStatus, ['preparing_request', 'documents_complete'], true)
        || ($requestStatus === 'revision_required' && $document['status'] === 'rejected');
    if (!$canUpload) {
        respondError('Documents are locked unless this document requires revision.', 409, 'document_locked');
    }

    $allowed = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];
    $file = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        respondError('PHP could not receive the complete file.', 422, 'php_upload_failed');
    }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) respondError('Only PDF, JPG, and PNG files are allowed.', 422, 'file_type_not_allowed');
    $storage = storageConfiguration();
    $maximumBytes = $storage['driver'] === 'supabase' ? 4 * 1024 * 1024 : 5 * 1024 * 1024;
    if ((int)$file['size'] > $maximumBytes) respondError('File is too large.', 422, 'file_too_large');
    if (!is_uploaded_file($file['tmp_name'])) respondError('Invalid uploaded file.', 422, 'invalid_upload');
    $detectedMime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if ($detectedMime === false || !in_array($detectedMime, $allowed[$ext], true)) {
        respondError('The uploaded content does not match its extension.', 422, 'file_content_mismatch');
    }

    $fileName = 'change_' . bin2hex(random_bytes(16)) . '.' . $ext;
    $newObjectPath = null;
    $newLocalPath = null;
    $sha256 = hash_file('sha256', $file['tmp_name']);
    if ($sha256 === false) respondError('Could not checksum the uploaded file.', 500, 'checksum_failed');

    try {
        if ($storage['driver'] === 'supabase') {
            $newObjectPath = sprintf(
                'students/%d/placement-changes/%d/%s-%s.%s',
                (int)$user['id'], $requestId, $documentType, bin2hex(random_bytes(12)), $ext
            );
            uploadPrivateDocument($newObjectPath, $file['tmp_name'], $detectedMime);
        } elseif ($storage['driver'] === 'local') {
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                throw new RuntimeException('Unable to initialise local document storage.');
            }
            $newLocalPath = $uploadDir . $fileName;
            if (!move_uploaded_file($file['tmp_name'], $newLocalPath)) {
                throw new RuntimeException('Unable to store uploaded file.');
            }
        } else {
            throw new RuntimeException('Document storage is not configured.');
        }

        $conn = db();
        $conn->begin_transaction();
        $lock = $conn->prepare("
            SELECT pcd.*, pcr.student_id, pcr.status AS request_status, pcr.revision_deadline
            FROM placement_change_documents pcd
            JOIN placement_change_requests pcr ON pcr.id = pcd.request_id
            WHERE pcd.id = ? FOR UPDATE
        ");
        $documentId = (int)$document['id'];
        $lock->bind_param('i', $documentId);
        $lock->execute();
        $locked = $lock->get_result()->fetch_assoc();
        $stillAllowed = $locked && (int)$locked['student_id'] === (int)$user['id']
            && (in_array($locked['request_status'], ['preparing_request', 'documents_complete'], true)
                || ($locked['request_status'] === 'revision_required' && $locked['status'] === 'rejected'));
        if (!$stillAllowed) throw new RuntimeException('Document state changed while uploading.');

        $now = date('Y-m-d H:i:s');
        $wasReplacement = !empty($locked['file_name']);
        $bucket = $storage['driver'] === 'supabase' ? $storage['bucket'] : null;
        $byteSize = (int)$file['size'];
        $update = $conn->prepare("
            UPDATE placement_change_documents
            SET status = 'uploaded', file_name = ?, storage_bucket = ?, storage_object_path = ?,
                content_type = ?, byte_size = ?, sha256 = ?,
                uploaded_at = COALESCE(uploaded_at, ?), replaced_at = ?,
                last_submitted_at = NULL,
                verified_at = NULL, rejected_at = NULL, reviewed_by = NULL,
                rejection_reason = NULL, updated_at = ?
            WHERE id = ?
        ");
        $replacedAt = $wasReplacement ? $now : null;
        $update->bind_param(
            'ssssissssi',
            $fileName, $bucket, $newObjectPath, $detectedMime, $byteSize, $sha256,
            $now, $replacedAt, $now, $documentId
        );
        $update->execute();

        $progress = $conn->prepare("
            SELECT COUNT(*) AS complete_count FROM placement_change_documents
            WHERE request_id = ? AND status IN ('uploaded','verified')
        ");
        $progress->bind_param('i', $requestId);
        $progress->execute();
        $completeCount = (int)($progress->get_result()->fetch_assoc()['complete_count'] ?? 0);
        if (in_array($locked['request_status'], ['preparing_request', 'documents_complete'], true)) {
            $nextStatus = $completeCount === 3 ? 'documents_complete' : 'preparing_request';
            $requestUpdate = $conn->prepare("UPDATE placement_change_requests SET status = ? WHERE id = ?");
            $requestUpdate->bind_param('si', $nextStatus, $requestId);
            $requestUpdate->execute();
        } else {
            $nextStatus = 'revision_required';
        }
        logActivity((int)$user['id'], $wasReplacement ? 'placement_change_document.replaced' : 'placement_change_document.uploaded', 'placement_change_document', $documentId, [
            'request_id' => $requestId,
            'document_type' => $documentType,
            'request_status' => $nextStatus,
        ]);
        $conn->commit();

        if (!empty($locked['storage_object_path']) && $storage['driver'] === 'supabase') {
            try { deletePrivateDocument((string)$locked['storage_object_path']); } catch (Throwable $cleanupError) {}
        } elseif (!empty($locked['file_name']) && $storage['driver'] === 'local') {
            $oldPath = $uploadDir . basename((string)$locked['file_name']);
            if (is_file($oldPath)) @unlink($oldPath);
        }
        respond([
            'success' => true,
            'document_id' => $documentId,
            'document_status' => 'uploaded',
            'request_status' => $nextStatus,
            'document_progress' => $completeCount,
        ], 201);
    } catch (Throwable $error) {
        if (isset($conn) && $conn->inTransaction()) $conn->rollback();
        if ($newObjectPath !== null) {
            try { deletePrivateDocument($newObjectPath); } catch (Throwable $cleanupError) {}
        }
        if ($newLocalPath !== null && is_file($newLocalPath)) @unlink($newLocalPath);
        error_log('InternPilih placement-change upload failed: ' . $error->getMessage());
        respondError('Unable to store the placement-change document.', 500, 'document_store_failed');
    }
}

if ($method === 'PUT' && $id) {
    if ($user['role'] !== 'admin') respond(['error' => 'Coordinators only'], 403);
    $d = body();
    $status = (string)($d['status'] ?? '');
    $reason = trim((string)($d['reason'] ?? ''));
    if (!in_array($status, ['verified', 'rejected'], true)) respond(['error' => 'Invalid document review status.'], 422);
    if ($status === 'rejected' && $reason === '') respond(['error' => 'Provide a short rejection reason.'], 422);
    if (strlen($reason) > 500) respond(['error' => 'Rejection reason is too long.'], 422);

    $conn = db();
    $conn->begin_transaction();
    try {
        $lock = $conn->prepare("
            SELECT pcd.*, pcr.student_id, pcr.status AS request_status, pcr.revision_deadline
            FROM placement_change_documents pcd
            JOIN placement_change_requests pcr ON pcr.id = pcd.request_id
            WHERE pcd.id = ? FOR UPDATE
        ");
        $lock->bind_param('i', $id);
        $lock->execute();
        $document = $lock->get_result()->fetch_assoc();
        if (!$document) {
            $conn->rollback();
            respond(['error' => 'Document not found.'], 404);
        }
        requireCoordinatorStudent((int)$user['id'], (int)$document['student_id']);
        if (!in_array($document['request_status'], ['request_submitted', 'coordinator_review', 'revision_required'], true)
            || $document['status'] !== 'uploaded' || empty($document['last_submitted_at'])) {
            $conn->rollback();
            respond(['error' => 'This document is not available for review.'], 409);
        }

        $now = date('Y-m-d H:i:s');
        $verifiedAt = $status === 'verified' ? $now : null;
        $rejectedAt = $status === 'rejected' ? $now : null;
        $reviewReason = $status === 'rejected' ? $reason : null;
        $review = $conn->prepare("
            UPDATE placement_change_documents
            SET status = ?, verified_at = ?, rejected_at = ?, reviewed_by = ?,
                rejection_reason = ?, updated_at = ? WHERE id = ?
        ");
        $review->bind_param('sssissi', $status, $verifiedAt, $rejectedAt, $user['id'], $reviewReason, $now, $id);
        $review->execute();

        if ($status === 'rejected') {
            if ($document['request_status'] === 'revision_required') {
                $deadline = (string)$document['revision_deadline'];
                $requestUpdate = $conn->prepare("
                    UPDATE placement_change_requests SET reviewed_by = ?, review_note = ? WHERE id = ?
                ");
                $requestUpdate->bind_param('isi', $user['id'], $reason, $document['request_id']);
            } else {
                $deadline = date('Y-m-d H:i:s', time() + 7 * 86400);
                $requestUpdate = $conn->prepare("
                    UPDATE placement_change_requests
                    SET status = 'revision_required', revision_requested_at = ?, revision_deadline = ?,
                        reviewed_by = ?, review_note = ? WHERE id = ?
                ");
                $requestUpdate->bind_param('ssisi', $now, $deadline, $user['id'], $reason, $document['request_id']);
            }
            $requestUpdate->execute();
            $requestStatus = 'revision_required';
        } else {
            if ($document['request_status'] === 'revision_required') {
                $requestStatus = 'revision_required';
            } else {
                $requestUpdate = $conn->prepare("
                    UPDATE placement_change_requests SET status = 'coordinator_review', reviewed_by = ?
                    WHERE id = ? AND status = 'request_submitted'
                ");
                $requestUpdate->bind_param('ii', $user['id'], $document['request_id']);
                $requestUpdate->execute();
                $requestStatus = 'coordinator_review';
            }
            $deadline = null;
        }
        logActivity((int)$user['id'], 'placement_change_document.' . $status, 'placement_change_document', $id, [
            'request_id' => (int)$document['request_id'],
            'document_type' => $document['document_type'],
            'reason' => $reviewReason,
            'revision_deadline' => $deadline,
        ]);
        $conn->commit();
        respond(['success' => true, 'document_status' => $status, 'request_status' => $requestStatus, 'revision_deadline' => $deadline]);
    } catch (Throwable $error) {
        if ($conn->inTransaction()) $conn->rollback();
        error_log('InternPilih placement-change document review failed: ' . $error->getMessage());
        respond(['error' => 'Unable to review the document.'], 500);
    }
}

respond(['error' => 'Bad request'], 400);

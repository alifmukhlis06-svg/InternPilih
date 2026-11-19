<?php
// Placement-change request lifecycle and final placement decision.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/placement-verification.php';

$user = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$id = (int)($_GET['id'] ?? 0);

const PLACEMENT_CHANGE_ACTIVE_STATUSES = [
    'preparing_request', 'documents_complete', 'request_submitted',
    'coordinator_review', 'revision_required',
];
const PLACEMENT_CHANGE_DOCUMENT_TYPES = [
    'proof_of_sending', 'placement_change_letter', 'company_response',
];

function placementChangeSelectSql(): string {
    return "
        SELECT pcr.*, u.name AS student_name, s.matric_id, u.email AS student_email,
            current_offer.position AS current_position,
            current_offer.status AS current_offer_status,
            current_company.name AS current_company_name,
            requested_offer.position AS requested_position,
            requested_offer.status AS requested_offer_status,
            requested_company.name AS requested_company_name,
            reviewer.name AS reviewer_name
        FROM placement_change_requests pcr
        JOIN users u ON u.id = pcr.student_id
        JOIN students s ON s.user_id = u.id
        JOIN offers current_offer ON current_offer.id = pcr.current_offer_id
        JOIN companies current_company ON current_company.id = current_offer.company_id
        JOIN offers requested_offer ON requested_offer.id = pcr.requested_offer_id
        JOIN companies requested_company ON requested_company.id = requested_offer.company_id
        LEFT JOIN users reviewer ON reviewer.id = pcr.reviewed_by
    ";
}

function placementChangeDocuments(int $requestId): array {
    $stmt = db()->prepare("
        SELECT pcd.*, reviewer.name AS reviewer_name
        FROM placement_change_documents pcd
        LEFT JOIN users reviewer ON reviewer.id = pcd.reviewed_by
        WHERE pcd.request_id = ?
        ORDER BY CASE pcd.document_type
            WHEN 'proof_of_sending' THEN 1
            WHEN 'placement_change_letter' THEN 2
            WHEN 'company_response' THEN 3 ELSE 4 END
    ");
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function enrichPlacementChange(array $request): array {
    $documents = placementChangeDocuments((int)$request['id']);
    $uploaded = 0;
    $verified = 0;
    foreach ($documents as $document) {
        if (in_array($document['status'], ['uploaded', 'verified'], true)) $uploaded++;
        if ($document['status'] === 'verified') $verified++;
    }
    $request['documents'] = $documents;
    $request['document_progress'] = $uploaded;
    $request['verified_count'] = $verified;
    $request['is_revision_overdue'] = $request['status'] === 'revision_required'
        && !empty($request['revision_deadline'])
        && strtotime((string)$request['revision_deadline']) < time();
    $request['is_active'] = in_array($request['status'], PLACEMENT_CHANGE_ACTIVE_STATUSES, true)
        && $request['current_offer_status'] === 'accepted'
        && $request['requested_offer_status'] === 'pending';
    return $request;
}

function createPlacementChangeDocumentRows(int $requestId): void {
    $stmt = db()->prepare("
        INSERT INTO placement_change_documents (request_id, document_type)
        VALUES (?, ?)
        ON CONFLICT (request_id, document_type) DO NOTHING
    ");
    foreach (PLACEMENT_CHANGE_DOCUMENT_TYPES as $type) {
        $stmt->bind_param('is', $requestId, $type);
        $stmt->execute();
    }
}

if ($method === 'GET') {
    if ($user['role'] === 'student') {
        $stmt = db()->prepare(placementChangeSelectSql() . " WHERE pcr.student_id = ? ORDER BY pcr.created_at DESC");
        $stmt->bind_param('i', $user['id']);
    } elseif ($user['role'] === 'admin') {
        $stmt = db()->prepare(placementChangeSelectSql() . " WHERE s.coordinator_id = ? ORDER BY CASE pcr.status
            WHEN 'request_submitted' THEN 1 WHEN 'coordinator_review' THEN 2 WHEN 'revision_required' THEN 3
            WHEN 'documents_complete' THEN 4 WHEN 'preparing_request' THEN 5 ELSE 6 END, pcr.created_at DESC");
        $stmt->bind_param('i', $user['id']);
    } else {
        respond(['error' => 'Forbidden'], 403);
    }
    $stmt->execute();
    respond(array_map('enrichPlacementChange', $stmt->get_result()->fetch_all(MYSQLI_ASSOC)));
}

if ($method === 'POST') {
    if ($user['role'] !== 'student') respond(['error' => 'Students only'], 403);
    $d = body();
    $requestedOfferId = (int)($d['requested_offer_id'] ?? 0);
    $reason = trim((string)($d['reason'] ?? ''));
    if ($requestedOfferId <= 0) respond(['error' => 'Choose the offer you want to change to.'], 422);
    if ($reason === '') respond(['error' => 'Please write a short reason for the coordinator.'], 422);
    if (strlen($reason) > 1000) respond(['error' => 'Reason is too long. Keep it under 1000 characters.'], 422);

    $conn = db();
    $conn->begin_transaction();
    try {
        $studentLock = $conn->prepare("SELECT user_id FROM students WHERE user_id = ? FOR UPDATE");
        $studentLock->bind_param('i', $user['id']);
        $studentLock->execute();
        if (!$studentLock->get_result()->fetch_assoc()) {
            $conn->rollback();
            respond(['error' => 'Student profile not found.'], 404);
        }
        $active = $conn->prepare("
            SELECT pcr.id, current_offer.status AS current_offer_status,
                requested_offer.status AS requested_offer_status
            FROM placement_change_requests pcr
            JOIN offers current_offer ON current_offer.id = pcr.current_offer_id
            JOIN offers requested_offer ON requested_offer.id = pcr.requested_offer_id
            WHERE pcr.student_id = ?
              AND pcr.status IN ('preparing_request','documents_complete','request_submitted','coordinator_review','revision_required')
            FOR UPDATE
        ");
        $active->bind_param('i', $user['id']);
        $active->execute();
        foreach ($active->get_result()->fetch_all(MYSQLI_ASSOC) as $activeRequest) {
            if ($activeRequest['current_offer_status'] === 'accepted' && $activeRequest['requested_offer_status'] === 'pending') {
                $conn->rollback();
                respond(['error' => 'You already have an active placement change request. Finish it before starting another one.'], 409);
            }

            $closedAt = date('Y-m-d H:i:s');
            $staleId = (int)$activeRequest['id'];
            $staleNote = 'Automatically closed because one of the linked offers is no longer eligible.';
            $closeStale = $conn->prepare("
                UPDATE placement_change_requests
                SET status = 'rejected', review_note = ?, reviewed_at = ?, final_decision_at = ?
                WHERE id = ?
            ");
            $closeStale->bind_param('sssi', $staleNote, $closedAt, $closedAt, $staleId);
            $closeStale->execute();
            logActivity((int)$user['id'], 'placement_change.stale_closed', 'placement_change_request', $staleId);
        }
        $current = $conn->prepare("
            SELECT id FROM offers WHERE student_id = ? AND status = 'accepted'
            ORDER BY response_date DESC, created_at DESC LIMIT 1 FOR UPDATE
        ");
        $current->bind_param('i', $user['id']);
        $current->execute();
        $currentOffer = $current->get_result()->fetch_assoc();
        if (!$currentOffer) {
            $conn->rollback();
            respond(['error' => 'You need an accepted placement before requesting a change.'], 409);
        }
        $requested = $conn->prepare("
            SELECT id FROM offers WHERE id = ? AND student_id = ? AND status = 'pending' LIMIT 1 FOR UPDATE
        ");
        $requested->bind_param('ii', $requestedOfferId, $user['id']);
        $requested->execute();
        if (!$requested->get_result()->fetch_assoc()) {
            $conn->rollback();
            respond(['error' => 'The new offer must still be pending and belong to your account.'], 422);
        }
        $stmt = $conn->prepare("
            INSERT INTO placement_change_requests
                (student_id, current_offer_id, requested_offer_id, reason, status)
            VALUES (?, ?, ?, ?, 'preparing_request')
        ");
        $currentOfferId = (int)$currentOffer['id'];
        $stmt->bind_param('iiis', $user['id'], $currentOfferId, $requestedOfferId, $reason);
        $stmt->execute();
        $requestId = (int)$conn->insert_id;
        createPlacementChangeDocumentRows($requestId);
        logActivity((int)$user['id'], 'placement_change.preparing', 'placement_change_request', $requestId, [
            'current_offer_id' => $currentOfferId,
            'requested_offer_id' => $requestedOfferId,
        ]);
        $conn->commit();
        respond(['success' => true, 'id' => $requestId, 'status' => 'preparing_request'], 201);
    } catch (Throwable $error) {
        if ($conn->inTransaction()) $conn->rollback();
        error_log('InternPilih placement change creation failed: ' . $error->getMessage());
        respond(['error' => 'Could not start the placement change request.'], 500);
    }
}

if ($method === 'PUT' && $id) {
    $d = body();
    $action = (string)($d['action'] ?? '');

    if ($user['role'] === 'student' && $action === 'submit') {
        $conn = db();
        $conn->begin_transaction();
        try {
            $requestStmt = $conn->prepare("SELECT * FROM placement_change_requests WHERE id = ? AND student_id = ? FOR UPDATE");
            $requestStmt->bind_param('ii', $id, $user['id']);
            $requestStmt->execute();
            $request = $requestStmt->get_result()->fetch_assoc();
            if (!$request) {
                $conn->rollback();
                respond(['error' => 'Placement change request not found.'], 404);
            }
            if (!in_array($request['status'], ['preparing_request', 'documents_complete', 'revision_required'], true)) {
                $conn->rollback();
                respond(['error' => 'This request is not open for submission.'], 409);
            }
            $countStmt = $conn->prepare("
                SELECT COUNT(*) AS complete_count FROM placement_change_documents
                WHERE request_id = ? AND status IN ('uploaded','verified')
            ");
            $countStmt->bind_param('i', $id);
            $countStmt->execute();
            if ((int)($countStmt->get_result()->fetch_assoc()['complete_count'] ?? 0) !== 3) {
                $conn->rollback();
                respond(['error' => 'Upload all 3 required documents before submitting.'], 409);
            }
            $submittedAt = date('Y-m-d H:i:s');
            $update = $conn->prepare("
                UPDATE placement_change_requests
                SET status = 'request_submitted', submitted_at = COALESCE(submitted_at, ?), review_note = NULL
                WHERE id = ?
            ");
            $update->bind_param('si', $submittedAt, $id);
            $update->execute();
            $docUpdate = $conn->prepare("
                UPDATE placement_change_documents SET last_submitted_at = ?, updated_at = ?
                WHERE request_id = ? AND status = 'uploaded'
            ");
            $docUpdate->bind_param('ssi', $submittedAt, $submittedAt, $id);
            $docUpdate->execute();
            logActivity((int)$user['id'], 'placement_change.submitted', 'placement_change_request', $id, [
                'resubmission' => $request['status'] === 'revision_required',
                'revision_deadline' => $request['revision_deadline'] ?? null,
            ]);
            $conn->commit();
            respond(['success' => true, 'status' => 'request_submitted']);
        } catch (Throwable $error) {
            if ($conn->inTransaction()) $conn->rollback();
            respond(['error' => 'Could not submit the placement change request.'], 500);
        }
    }

    if ($user['role'] !== 'admin') respond(['error' => 'Forbidden'], 403);
    $status = (string)($d['status'] ?? '');
    $reviewNote = trim((string)($d['review_note'] ?? ''));
    if ($action !== 'final_decision' || !in_array($status, ['approved', 'rejected'], true)) {
        respond(['error' => 'Invalid review action.'], 422);
    }
    if ($status === 'rejected' && $reviewNote === '') {
        respond(['error' => 'Provide a short reason for rejecting this request.'], 422);
    }

    $conn = db();
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM placement_change_requests WHERE id = ? FOR UPDATE");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();
        if (!$request) {
            $conn->rollback();
            respond(['error' => 'Placement change request not found.'], 404);
        }
        requireCoordinatorStudent((int)$user['id'], (int)$request['student_id']);
        if (!in_array($request['status'], ['request_submitted', 'coordinator_review'], true)) {
            $conn->rollback();
            respond(['error' => 'This request is not ready for a final decision.'], 409);
        }

        if ($status === 'approved') {
            $verifiedStmt = $conn->prepare("
                SELECT COUNT(*) AS verified_count FROM placement_change_documents
                WHERE request_id = ? AND status = 'verified'
            ");
            $verifiedStmt->bind_param('i', $id);
            $verifiedStmt->execute();
            if ((int)($verifiedStmt->get_result()->fetch_assoc()['verified_count'] ?? 0) !== 3) {
                $conn->rollback();
                respond(['error' => 'All 3 documents must be verified before final approval.'], 409);
            }
            $offerLock = $conn->prepare("SELECT id, status FROM offers WHERE id IN (?, ?) ORDER BY id FOR UPDATE");
            $currentOfferId = (int)$request['current_offer_id'];
            $requestedOfferId = (int)$request['requested_offer_id'];
            $offerLock->bind_param('ii', $currentOfferId, $requestedOfferId);
            $offerLock->execute();
            $statuses = [];
            foreach ($offerLock->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $statuses[(int)$row['id']] = $row['status'];
            if (($statuses[$currentOfferId] ?? null) !== 'accepted' || ($statuses[$requestedOfferId] ?? null) !== 'pending') {
                $conn->rollback();
                respond(['error' => 'The placement offers are no longer eligible for this change.'], 409);
            }
            $today = date('Y-m-d');
            $withdrawReason = 'Placement changed after coordinator approval.';
            $withdraw = $conn->prepare("UPDATE offers SET status = 'withdrawn', response_date = ?, reject_reason = ? WHERE id = ?");
            $withdraw->bind_param('ssi', $today, $withdrawReason, $currentOfferId);
            $withdraw->execute();
            $accept = $conn->prepare("UPDATE offers SET status = 'accepted', response_date = ?, reject_reason = NULL WHERE id = ?");
            $accept->bind_param('si', $today, $requestedOfferId);
            $accept->execute();
            recalculatePlacementVerification($currentOfferId, (int)$user['id']);
            $placementVerification = recalculatePlacementVerification($requestedOfferId, (int)$user['id']);
        }

        $decidedAt = date('Y-m-d H:i:s');
        $review = $conn->prepare("
            UPDATE placement_change_requests
            SET status = ?, reviewed_by = ?, review_note = ?, reviewed_at = ?, final_decision_at = ?
            WHERE id = ?
        ");
        $review->bind_param('sisssi', $status, $user['id'], $reviewNote, $decidedAt, $decidedAt, $id);
        $review->execute();
        logActivity((int)$user['id'], 'placement_change.' . $status, 'placement_change_request', $id, [
            'review_note' => $reviewNote,
            'placement_verification_status' => $placementVerification['status'] ?? null,
        ]);
        $conn->commit();
        respond(['success' => true, 'status' => $status]);
    } catch (Throwable $error) {
        if ($conn->inTransaction()) $conn->rollback();
        error_log('InternPilih placement change review failed: ' . $error->getMessage());
        respond(['error' => 'Could not review the placement change request.'], 500);
    }
}

respond(['error' => 'Bad request'], 400);

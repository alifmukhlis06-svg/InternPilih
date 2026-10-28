<?php
// api/placement-changes.php - Student placement change requests.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/placement-verification.php';

$user = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$id = (int)($_GET['id'] ?? 0);

function placementChangeSelectSql(): string {
    return "
        SELECT
            pcr.*,
            u.name AS student_name,
            s.matric_id,
            u.email AS student_email,
            current_offer.position AS current_position,
            current_company.name AS current_company_name,
            requested_offer.position AS requested_position,
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

if ($method === 'GET') {
    if ($user['role'] === 'student') {
        $stmt = db()->prepare(placementChangeSelectSql() . " WHERE pcr.student_id = ? ORDER BY pcr.created_at DESC");
        $stmt->bind_param('i', $user['id']);
    } elseif ($user['role'] === 'admin') {
        $stmt = db()->prepare(placementChangeSelectSql() . " WHERE s.coordinator_id = ? ORDER BY FIELD(pcr.status, 'pending', 'approved', 'rejected'), pcr.created_at DESC");
        $stmt->bind_param('i', $user['id']);
    } else {
        respond(['error' => 'Forbidden'], 403);
    }
    $stmt->execute();
    respond($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
}

if ($method === 'POST') {
    if ($user['role'] !== 'student') respond(['error' => 'Students only'], 403);

    $d = body();
    $requestedOfferId = (int)($d['requested_offer_id'] ?? 0);
    $reason = trim((string)($d['reason'] ?? ''));

    if ($requestedOfferId <= 0) respond(['error' => 'Choose the offer you want to change to.'], 422);
    if ($reason === '') respond(['error' => 'Please write a short reason for the coordinator.'], 422);
    if (strlen($reason) > 1000) respond(['error' => 'Reason is too long. Keep it under 1000 characters.'], 422);

    $current = db()->prepare("
        SELECT id
        FROM offers
        WHERE student_id = ? AND status = 'accepted'
        ORDER BY response_date DESC, created_at DESC
        LIMIT 1
    ");
    $current->bind_param('i', $user['id']);
    $current->execute();
    $currentOffer = $current->get_result()->fetch_assoc();
    if (!$currentOffer) respond(['error' => 'You need an accepted placement before requesting a change.'], 409);

    $requested = db()->prepare("
        SELECT id
        FROM offers
        WHERE id = ? AND student_id = ? AND status = 'pending'
        LIMIT 1
    ");
    $requested->bind_param('ii', $requestedOfferId, $user['id']);
    $requested->execute();
    if (!$requested->get_result()->fetch_assoc()) {
        respond(['error' => 'The new offer must still be pending and belong to your account.'], 422);
    }

    $pending = db()->prepare("
        SELECT id
        FROM placement_change_requests
        WHERE student_id = ? AND status = 'pending'
        LIMIT 1
    ");
    $pending->bind_param('i', $user['id']);
    $pending->execute();
    if ($pending->get_result()->fetch_assoc()) {
        respond(['error' => 'You already have a pending placement change request.'], 409);
    }

    $stmt = db()->prepare("
        INSERT INTO placement_change_requests (student_id, current_offer_id, requested_offer_id, reason)
        VALUES (?, ?, ?, ?)
    ");
    $currentOfferId = (int)$currentOffer['id'];
    $stmt->bind_param('iiis', $user['id'], $currentOfferId, $requestedOfferId, $reason);
    $stmt->execute();
    $requestId = (int)db()->insert_id;

    logActivity((int)$user['id'], 'placement_change.requested', 'placement_change_request', $requestId, [
        'current_offer_id' => $currentOfferId,
        'requested_offer_id' => $requestedOfferId,
    ]);

    respond(['success' => true, 'id' => $requestId], 201);
}

if ($method === 'PUT' && $id) {
    if ($user['role'] !== 'admin') respond(['error' => 'Admins only'], 403);

    $d = body();
    $status = $d['status'] ?? '';
    $reviewNote = trim((string)($d['review_note'] ?? ''));
    if (!in_array($status, ['approved', 'rejected'], true)) {
        respond(['error' => 'Invalid review status'], 422);
    }

    $conn = db();
    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("
            SELECT *
            FROM placement_change_requests
            WHERE id = ?
            FOR UPDATE
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();

        if (!$request) {
            $conn->rollback();
            respond(['error' => 'Placement change request not found'], 404);
        }
        requireCoordinatorStudent((int)$user['id'], (int)$request['student_id']);
        if ($request['status'] !== 'pending') {
            $conn->rollback();
            respond(['error' => 'This request has already been reviewed.'], 409);
        }

        if ($status === 'approved') {
            $offerLock = $conn->prepare("
                SELECT id, status
                FROM offers
                WHERE id IN (?, ?)
                ORDER BY id
                FOR UPDATE
            ");
            $currentOfferId = (int)$request['current_offer_id'];
            $requestedOfferId = (int)$request['requested_offer_id'];
            $offerLock->bind_param('ii', $currentOfferId, $requestedOfferId);
            $offerLock->execute();
            $locked = $offerLock->get_result()->fetch_all(MYSQLI_ASSOC);
            $statuses = [];
            foreach ($locked as $row) {
                $statuses[(int)$row['id']] = $row['status'];
            }

            if (($statuses[$currentOfferId] ?? null) !== 'accepted') {
                $conn->rollback();
                respond(['error' => 'The current placement is no longer accepted.'], 409);
            }
            if (($statuses[$requestedOfferId] ?? null) !== 'pending') {
                $conn->rollback();
                respond(['error' => 'The requested offer is no longer pending.'], 409);
            }

            $today = date('Y-m-d');
            $withdrawReason = 'Placement changed after coordinator approval.';

            $withdraw = $conn->prepare("
                UPDATE offers
                SET status = 'withdrawn', response_date = ?, reject_reason = ?
                WHERE id = ?
            ");
            $withdraw->bind_param('ssi', $today, $withdrawReason, $currentOfferId);
            $withdraw->execute();

            $accept = $conn->prepare("
                UPDATE offers
                SET status = 'accepted', response_date = ?, reject_reason = NULL
                WHERE id = ?
            ");
            $accept->bind_param('si', $today, $requestedOfferId);
            $accept->execute();

            recalculatePlacementVerification($currentOfferId, (int)$user['id']);
            $placementVerification = recalculatePlacementVerification($requestedOfferId, (int)$user['id']);
        }

        $reviewedAt = date('Y-m-d H:i:s');
        $review = $conn->prepare("
            UPDATE placement_change_requests
            SET status = ?, reviewed_by = ?, review_note = ?, reviewed_at = ?
            WHERE id = ?
        ");
        $review->bind_param('sissi', $status, $user['id'], $reviewNote, $reviewedAt, $id);
        $review->execute();

        $conn->commit();

        logActivity((int)$user['id'], 'placement_change.' . $status, 'placement_change_request', $id, [
            'current_offer_id' => (int)$request['current_offer_id'],
            'requested_offer_id' => (int)$request['requested_offer_id'],
            'review_note' => $reviewNote,
            'placement_verification_status' => $placementVerification['status'] ?? null,
        ]);

        respond(['success' => true]);
    } catch (Throwable $e) {
        $conn->rollback();
        respond(['error' => 'Could not review request: ' . $e->getMessage()], 500);
    }
}

respond(['error' => 'Bad request'], 400);

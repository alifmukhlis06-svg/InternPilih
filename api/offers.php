<?php
// api/offers.php  — CRUD for internship offers
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/offer-flags.php';
require_once __DIR__ . '/placement-verification.php';

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$id     = (int)($_GET['id'] ?? 0);
$responsePeriodDays = offerResponsePeriodDays();

// ── GET — list offers ─────────────────────────────────────────
if ($method === 'GET') {
    if ($user['role'] === 'student') {
        // Student sees only their own offers
        $stmt = db()->prepare("
            SELECT o.*,
                DATE_ADD(o.offer_date, INTERVAL {$responsePeriodDays} DAY) AS configured_response_deadline,
                (o.status = 'pending' AND DATEDIFF(CURDATE(), o.offer_date) > {$responsePeriodDays}) AS response_period_exceeded,
                c.name AS company_name, c.industry, c.location, c.allowance, c.work_mode, c.hr_email
            FROM offers o
            JOIN companies c ON c.id = o.company_id
            WHERE o.student_id = ?
            ORDER BY o.created_at DESC
        ");
        $stmt->bind_param('i', $user['id']);
    } elseif ($user['role'] === 'employer') {
        // Employer sees offers for their company
        $stmt = db()->prepare("
            SELECT o.*,
                DATE_ADD(o.offer_date, INTERVAL {$responsePeriodDays} DAY) AS configured_response_deadline,
                (o.status = 'pending' AND DATEDIFF(CURDATE(), o.offer_date) > {$responsePeriodDays}) AS response_period_exceeded,
                c.name AS company_name, c.industry, c.location, c.allowance, c.work_mode, c.hr_email, u.name AS student_name, s.matric_id, u.email AS student_email, s.faculty, s.programme, s.cgpa
            FROM offers o
            JOIN companies c ON c.id = o.company_id
            JOIN users u ON u.id = o.student_id
            JOIN students s ON s.user_id = u.id
            WHERE c.id = ?
            ORDER BY o.created_at DESC
        ");
        $stmt->bind_param('i', $user['company_id']);
    } else {
        // Coordinators see offers for students in their programme.
        $stmt = db()->prepare("
            SELECT o.*,
                DATE_ADD(o.offer_date, INTERVAL {$responsePeriodDays} DAY) AS configured_response_deadline,
                (o.status = 'pending' AND DATEDIFF(CURDATE(), o.offer_date) > {$responsePeriodDays}) AS response_period_exceeded,
                c.name AS company_name, c.industry, c.location, c.allowance, c.work_mode, c.hr_email, u.name AS student_name, s.matric_id, u.email AS student_email, s.faculty, s.programme, s.cgpa
            FROM offers o
            JOIN companies c ON c.id = o.company_id
            JOIN users u ON u.id = o.student_id
            JOIN students s ON s.user_id = u.id
            WHERE s.coordinator_id = ?
            ORDER BY o.created_at DESC
        ");
        $stmt->bind_param('i', $user['id']);
    }

    $stmt->execute();
    respond($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
}

// ── POST — create offer ───────────────────────────────────────
if ($method === 'POST') {
    if ($user['role'] !== 'student') respond(['error' => 'Students only'], 403);
    $d = body();

    $companyId = (int)($d['company_id'] ?? 0);
    $position = trim((string)($d['position'] ?? ''));
    $offerDate = trim((string)($d['offer_date'] ?? ''));
    $deadline = trim((string)($d['deadline'] ?? ''));
    if ($companyId < 1 || $position === '' || $offerDate === '' || $deadline === '') {
        respond(['error' => 'Company, internship position, offer date, and response deadline are required.'], 422);
    }
    if (strlen($position) > 150) {
        respond(['error' => 'Internship position must be 150 characters or fewer.'], 422);
    }

    $company = db()->prepare("SELECT id FROM companies WHERE id = ? AND status = 'active' LIMIT 1");
    $company->bind_param('i', $companyId);
    $company->execute();
    if (!$company->get_result()->fetch_assoc()) {
        respond(['error' => 'Select an active company.'], 422);
    }

    $stmt = db()->prepare("
        INSERT INTO offers (student_id, company_id, position, offer_date, deadline, status)
        VALUES (?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->bind_param('iisss',
        $user['id'],
        $companyId,
        $position,
        $offerDate,
        $deadline
    );
    $stmt->execute();
    $offerId = (int)db()->insert_id;
    logActivity((int)$user['id'], 'offer.created', 'offer', $offerId, [
        'company_id' => $companyId,
        'position' => $position,
    ]);
    respond(['success' => true, 'id' => $offerId], 201);
}

// ── PUT — update offer status (accept/reject) ─────────────────
if ($method === 'PUT' && $id) {
    if ($user['role'] !== 'student') respond(['error' => 'Students only'], 403);
    $d = body();
    $status = $d['status'] ?? '';
    if (!in_array($status, ['accepted', 'rejected'])) respond(['error' => 'Invalid status'], 422);

    $target = db()->prepare("
        SELECT
            o.id,
            o.status,
            o.position,
            c.name AS company_name,
            c.hr_email,
            u.name AS student_name,
            u.email AS student_email,
            s.matric_id
        FROM offers o
        JOIN companies c ON c.id = o.company_id
        JOIN users u ON u.id = o.student_id
        JOIN students s ON s.user_id = u.id
        WHERE o.id = ? AND o.student_id = ?
        LIMIT 1
    ");
    $target->bind_param('ii', $id, $user['id']);
    $target->execute();
    $offer = $target->get_result()->fetch_assoc();
    if (!$offer) {
        respond(['error' => 'Offer not found'], 404);
    }

    if ($status === 'accepted') {
        $check = db()->prepare("
            SELECT c.name AS company_name
            FROM offers o
            JOIN companies c ON c.id = o.company_id
            WHERE o.student_id = ? AND o.status = 'accepted' AND o.id <> ?
            LIMIT 1
        ");
        $check->bind_param('ii', $user['id'], $id);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        if ($existing) {
            respond([
                'error' => 'You already accepted an offer from ' . $existing['company_name'] . '. Reject or contact coordinator before accepting another offer.'
            ], 409);
        }
    }

    $today = date('Y-m-d');
    $reason = $status === 'rejected' ? trim((string)($d['reject_reason'] ?? '')) : null;
    $emailDelivery = null;

    if ($status === 'rejected') {
        if ($offer['status'] === 'rejected') {
            respond(['error' => 'This offer has already been rejected.'], 409);
        }
        if ($reason === '') {
            respond(['error' => 'Write a rejection message before sending.'], 422);
        }
        $registeredEmail = trim((string)$offer['hr_email']);
        if (!filter_var($registeredEmail, FILTER_VALIDATE_EMAIL)) {
            respond(['error' => 'This company does not have a valid HR email address.'], 422);
        }

        $recipientEmail = trim((string)($d['recipient_email'] ?? $registeredEmail));
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            respond(['error' => 'Enter a valid receiver email address.'], 422);
        }

        $recipientOverridden = strcasecmp($recipientEmail, $registeredEmail) !== 0;
        if ($recipientOverridden) {
            $confirmedRecipient = trim((string)($d['confirmed_recipient_email'] ?? ''));
            if (strcasecmp($confirmedRecipient, $recipientEmail) !== 0) {
                respond(['error' => 'Confirm the edited receiver email before sending.'], 422);
            }
        }
        $offer['hr_email'] = $recipientEmail;

        $subject = trim((string)($d['email_subject'] ?? ''));
        if ($subject === '') {
            $subject = 'Internship Offer Response - ' . $offer['position'];
        }
        $subject = substr($subject, 0, 180);
        $emailDelivery = sendRejectionNoticeEmail($offer, $subject, $reason);

        if (!$emailDelivery['sent']) {
            logActivity((int)$user['id'], 'offer.rejection_email_failed', 'offer', $id, [
                'email_to' => $offer['hr_email'],
                'recipient_overridden' => $recipientOverridden,
                'error' => substr((string)($emailDelivery['error'] ?? 'Unknown email error'), 0, 255),
            ]);
            respond([
                'error' => 'The rejection email could not be sent. The offer is still pending. Please try again.',
            ], 502);
        }
    }

    $stmt = db()->prepare("
        UPDATE offers SET status = ?, response_date = ?, reject_reason = ?
        WHERE id = ? AND student_id = ?
    ");
    $stmt->bind_param('sssii', $status, $today, $reason, $id, $user['id']);
    $stmt->execute();
    $placement = recalculatePlacementVerification($id, (int)$user['id']);
    logActivity((int)$user['id'], 'offer.' . $status, 'offer', $id, [
        'reject_reason' => $reason,
        'email_sent' => (bool)($emailDelivery['sent'] ?? false),
        'email_to' => $emailDelivery['email'] ?? null,
        'recipient_overridden' => $recipientOverridden ?? false,
        'placement_verification_status' => $placement['status'],
    ]);
    respond([
        'success' => true,
        'email_sent' => (bool)($emailDelivery['sent'] ?? false),
        'email_to' => $emailDelivery['email'] ?? null,
        'recipient_overridden' => $recipientOverridden ?? false,
        'placement_verification_status' => $placement['status'],
    ]);
}

// ── DELETE — remove offer (student or admin) ──────────────────
if ($method === 'DELETE' && $id) {
    if ($user['role'] === 'student') {
        $stmt = db()->prepare("DELETE FROM offers WHERE id = ? AND student_id = ?");
        $stmt->bind_param('ii', $id, $user['id']);
    } else {
        $stmt = db()->prepare("DELETE FROM offers WHERE id = ?");
        $stmt->bind_param('i', $id);
    }
    $stmt->execute();
    logActivity((int)$user['id'], 'offer.deleted', 'offer', $id);
    respond(['success' => true]);
}

respond(['error' => 'Bad request'], 400);

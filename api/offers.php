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
                o.offer_date + {$responsePeriodDays} AS configured_response_deadline,
                (o.status = 'pending' AND CURRENT_DATE - o.offer_date > {$responsePeriodDays}) AS response_period_exceeded,
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
                o.offer_date + {$responsePeriodDays} AS configured_response_deadline,
                (o.status = 'pending' AND CURRENT_DATE - o.offer_date > {$responsePeriodDays}) AS response_period_exceeded,
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
                o.offer_date + {$responsePeriodDays} AS configured_response_deadline,
                (o.status = 'pending' AND CURRENT_DATE - o.offer_date > {$responsePeriodDays}) AS response_period_exceeded,
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
    $internshipStartDate = trim((string)($d['internship_start_date'] ?? ''));
    if ($companyId < 1 || $position === '' || $offerDate === '' || $deadline === '') {
        respond(['error' => 'Company, internship position, offer date, and response deadline are required.'], 422);
    }
    if (strlen($position) > 150) {
        respond(['error' => 'Internship position must be 150 characters or fewer.'], 422);
    }
    $scheduleRows = db()->query("
        SELECT setting_key, setting_value FROM system_settings
        WHERE setting_key IN ('internship_application_open', 'main_offer_deadline',
            'placement_confirmation_deadline', 'internship_start_date')
    ")->fetch_all(MYSQLI_ASSOC);
    $cycle = array_column($scheduleRows, 'setting_value', 'setting_key');
    if ($internshipStartDate === '') {
        $internshipStartDate = (string)($cycle['internship_start_date'] ?? '');
    }
    foreach ([$offerDate, $deadline, $internshipStartDate] as $dateValue) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
        if (!$date || $date->format('Y-m-d') !== $dateValue) {
            respond(['error' => 'Offer, response, and internship start dates must be valid dates.'], 422);
        }
    }
    if ($offerDate < ($cycle['internship_application_open'] ?? '')
        || $offerDate > ($cycle['main_offer_deadline'] ?? '9999-12-31')) {
        respond(['error' => 'Offer dates must fall within the configured preparation period.'], 422);
    }
    if ($deadline < $offerDate || $deadline > ($cycle['placement_confirmation_deadline'] ?? '9999-12-31')) {
        respond(['error' => 'The response deadline must be on or after the offer date and no later than final placement confirmation.'], 422);
    }
    if ($internshipStartDate !== ($cycle['internship_start_date'] ?? '')) {
        respond(['error' => 'The internship start date must match the configured March cycle.'], 422);
    }

    $company = db()->prepare("SELECT id FROM companies WHERE id = ? AND status = 'active' LIMIT 1");
    $company->bind_param('i', $companyId);
    $company->execute();
    if (!$company->get_result()->fetch_assoc()) {
        respond(['error' => 'Select an active company.'], 422);
    }

    $stmt = db()->prepare("
        INSERT INTO offers (student_id, company_id, position, offer_date, deadline, internship_start_date, status)
        VALUES (?, ?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->bind_param('iissss',
        $user['id'],
        $companyId,
        $position,
        $offerDate,
        $deadline,
        $internshipStartDate
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
        respondError('Offer not found', 404, 'offer_not_found', ['offer_id' => $id]);
    }

    if ($offer['status'] !== 'pending') {
        respondError('Only a pending offer can be accepted or rejected.', 409, 'offer_not_pending', [
            'offer_id' => $id,
            'current_status' => $offer['status'],
        ]);
    }

    if ($status === 'accepted') {
        $conn = db();
        $conn->begin_transaction();
        try {
            // Serialize acceptance for this student so two concurrent requests
            // cannot both pass the one-accepted-placement rule.
            $lock = $conn->prepare("
                SELECT o.id, o.status, c.name AS company_name
                FROM offers o
                JOIN companies c ON c.id = o.company_id
                WHERE o.student_id = ?
                ORDER BY o.id
                FOR UPDATE
            ");
            $lock->bind_param('i', $user['id']);
            $lock->execute();
            $studentOffers = $lock->get_result()->fetch_all(MYSQLI_ASSOC);
            $targetIsPending = false;
            $existingCompany = null;
            foreach ($studentOffers as $studentOffer) {
                if ((int)$studentOffer['id'] === $id && $studentOffer['status'] === 'pending') {
                    $targetIsPending = true;
                }
                if ((int)$studentOffer['id'] !== $id && $studentOffer['status'] === 'accepted') {
                    $existingCompany = (string)$studentOffer['company_name'];
                }
            }

            if (!$targetIsPending) {
                $currentStatus = 'not_found';
                foreach ($studentOffers as $studentOffer) {
                    if ((int)$studentOffer['id'] === $id) {
                        $currentStatus = (string)$studentOffer['status'];
                        break;
                    }
                }
                $conn->rollback();
                respondError('This offer is no longer pending.', 409, 'offer_not_pending', [
                    'offer_id' => $id,
                    'current_status' => $currentStatus,
                ]);
            }
            if ($existingCompany !== null) {
                $conn->rollback();
                respondError(
                    'You already accepted an offer from ' . $existingCompany . '. Contact your coordinator before changing placement.',
                    409,
                    'placement_already_accepted',
                    ['offer_id' => $id, 'accepted_company' => $existingCompany]
                );
            }

            $today = date('Y-m-d');
            $accept = $conn->prepare("
                UPDATE offers
                SET status = 'accepted', response_date = ?, reject_reason = NULL
                WHERE id = ? AND student_id = ? AND status = 'pending'
            ");
            $accept->bind_param('sii', $today, $id, $user['id']);
            $accept->execute();
            if ($accept->affected_rows !== 1) {
                $conn->rollback();
                respondError('This offer is no longer pending.', 409, 'offer_not_pending', [
                    'offer_id' => $id,
                    'current_status' => 'changed_concurrently',
                ]);
            }

            $placement = recalculatePlacementVerification($id, (int)$user['id']);
            logActivity((int)$user['id'], 'offer.accepted', 'offer', $id, [
                'placement_verification_status' => $placement['status'],
            ]);
            $conn->commit();
        } catch (Throwable $error) {
            if ($conn->inTransaction()) $conn->rollback();
            error_log('InternPilih offer acceptance failed; request=' . INTERNPILIH_REQUEST_ID
                . '; student=' . (int)$user['id'] . '; offer=' . $id . '; error=' . $error->getMessage());
            respondError('Unable to accept the offer.', 500, 'offer_acceptance_failed', [
                'offer_id' => $id,
                'retryable' => true,
            ]);
        }

        $acceptedOfferStatement = db()->prepare("
            SELECT o.*, c.name AS company_name, c.industry, c.location,
                   c.allowance, c.work_mode, c.hr_email
            FROM offers o
            JOIN companies c ON c.id = o.company_id
            WHERE o.id = ? AND o.student_id = ?
            LIMIT 1
        ");
        $acceptedOfferStatement->bind_param('ii', $id, $user['id']);
        $acceptedOfferStatement->execute();
        $acceptedOffer = $acceptedOfferStatement->get_result()->fetch_assoc();

        respond([
            'success' => true,
            'offer' => $acceptedOffer,
            'email_sent' => false,
            'email_to' => null,
            'recipient_overridden' => false,
            'placement_verification_status' => $placement['status'],
        ]);
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
        'email_simulated' => (bool)($emailDelivery['simulated'] ?? false),
        'email_to' => $emailDelivery['email'] ?? null,
        'recipient_overridden' => $recipientOverridden ?? false,
        'placement_verification_status' => $placement['status'],
    ]);
    respond([
        'success' => true,
        'email_sent' => (bool)($emailDelivery['sent'] ?? false),
        'email_simulated' => (bool)($emailDelivery['simulated'] ?? false),
        'email_to' => $emailDelivery['email'] ?? null,
        'recipient_overridden' => $recipientOverridden ?? false,
        'placement_verification_status' => $placement['status'],
    ]);
}

// ── DELETE — remove offer (student or admin) ──────────────────
if ($method === 'DELETE' && $id) {
    if ($user['role'] === 'student') {
        $stmt = db()->prepare("
            DELETE FROM offers
            WHERE id = ? AND student_id = ? AND status IN ('pending', 'rejected')
        ");
        $stmt->bind_param('ii', $id, $user['id']);
    } elseif ($user['role'] === 'admin') {
        $stmt = db()->prepare("
            DELETE FROM offers o
            USING students s
            WHERE o.id = ?
              AND o.student_id = s.user_id
              AND s.coordinator_id = ?
              AND o.status <> 'accepted'
        ");
        $stmt->bind_param('ii', $id, $user['id']);
    } else {
        respond(['error' => 'Not allowed'], 403);
    }
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        respond(['error' => 'Offer not found or cannot be deleted in its current state.'], 404);
    }
    logActivity((int)$user['id'], 'offer.deleted', 'offer', $id);
    respond(['success' => true]);
}

respond(['error' => 'Bad request'], 400);

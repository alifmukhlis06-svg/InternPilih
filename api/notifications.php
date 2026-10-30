<?php
// api/notifications.php - Notifications and announcements
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/offer-flags.php';

$user = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$id = (int)($_GET['id'] ?? 0);

function notificationRowsForGroup(string $group, int $coordinatorId, int $recipientUserId = 0): array {
    $db = db();
    if ($group === 'individual') {
        if ($recipientUserId <= 0) {
            respond(['error' => 'Select a student recipient.'], 422);
        }
        $stmt = $db->prepare("
            SELECT u.id, u.name, u.email, s.matric_id
            FROM users u
            JOIN students s ON s.user_id = u.id
            WHERE u.id = ? AND u.role = 'student' AND s.coordinator_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('ii', $recipientUserId, $coordinatorId);
        $stmt->execute();
        $recipient = $stmt->get_result()->fetch_assoc();
        if (!$recipient) {
            respond(['error' => 'The selected student is not assigned to this coordinator.'], 403);
        }
        return [$recipient];
    } elseif ($group === 'pending') {
        $sql = "
            SELECT DISTINCT u.id, u.name, u.email
            FROM users u
            JOIN students s ON s.user_id = u.id
            JOIN offers o ON o.student_id = u.id
            WHERE u.role = 'student' AND s.coordinator_id = {$coordinatorId} AND o.status = 'pending'
            ORDER BY u.id
        ";
    } elseif ($group === 'risk') {
        $responsePeriodDays = offerResponsePeriodDays();
        $sql = "
            SELECT u.id, u.name, u.email
            FROM users u
            JOIN students s ON s.user_id = u.id
            JOIN offers o ON o.student_id = u.id
            WHERE u.role = 'student' AND s.coordinator_id = {$coordinatorId}
            GROUP BY u.id
            HAVING COUNT(*) FILTER (WHERE o.status = 'pending') > 1
                OR COUNT(*) FILTER (WHERE o.status = 'pending' AND CURRENT_DATE - o.offer_date > {$responsePeriodDays}) > 0
            ORDER BY u.id
        ";
    } else {
        $sql = "SELECT u.id, u.name, u.email FROM users u JOIN students s ON s.user_id = u.id WHERE u.role = 'student' AND s.coordinator_id = {$coordinatorId} ORDER BY u.id";
    }

    $result = $db->query($sql);
    if (!$result) respond(['error' => $db->error], 500);
    return $result->fetch_all(MYSQLI_ASSOC);
}

if ($method === 'GET') {
    if ($user['role'] === 'admin') {
        $result = db()->query("
            SELECT
                a.id,
                a.title,
                a.body,
                a.type,
                a.recipient_group,
                a.created_at,
                a.recipient_count,
                COUNT(n.id) FILTER (WHERE n.is_read = FALSE) AS unread_count,
                a.email_sent_count,
                a.email_failed_count,
                a.telegram_sent_at,
                a.telegram_message_id,
                a.telegram_error,
                MAX(recipient.name) AS recipient_name,
                MAX(recipient_student.matric_id) AS recipient_matric_id
            FROM announcements a
            LEFT JOIN notifications n ON n.announcement_id = a.id
            LEFT JOIN users recipient ON recipient.id = n.recipient_user_id
            LEFT JOIN students recipient_student ON recipient_student.user_id = recipient.id
            WHERE a.created_by = " . (int)$user['id'] . "
            GROUP BY a.id
            ORDER BY a.created_at DESC
            LIMIT 50
        ");
        if (!$result) respond(['error' => db()->error], 500);
        respond(['notifications' => $result->fetch_all(MYSQLI_ASSOC)]);
    }

    $stmt = db()->prepare("
        SELECT n.*, u.name AS sender_name
        FROM notifications n
        LEFT JOIN users u ON u.id = n.created_by
        WHERE n.recipient_user_id = ?
        ORDER BY n.created_at DESC
        LIMIT 100
    ");
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    respond(['notifications' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
}

if ($method === 'POST') {
    if ($user['role'] !== 'admin') respond(['error' => 'Admins only'], 403);

    $data = body();
    $type = $data['type'] ?? 'general';
    if (!in_array($type, ['general', 'reminder', 'urgent'], true)) {
        $type = 'general';
    }

    $group = $data['recipient_group'] ?? 'all';
    if (!in_array($group, ['all', 'pending', 'risk', 'individual'], true)) {
        $group = 'all';
    }
    $recipientUserId = (int)($data['recipient_user_id'] ?? 0);

    $title = trim((string)($data['title'] ?? ''));
    $body = trim((string)($data['body'] ?? ''));
    $link = trim((string)($data['link_url'] ?? 'notifications.html'));
    $linkParts = parse_url($link);
    if ($link === '' || $linkParts === false
        || isset($linkParts['scheme']) || isset($linkParts['host'])
        || str_starts_with($link, '//')
        || preg_match('#(^|/)\.\.(/|$)#', $link)) {
        $link = 'notifications.html';
    }

    if ($title === '' || $body === '') {
        respond(['error' => 'Subject and message are required.'], 422);
    }

    // Resolve and authorize recipients before creating any announcement row.
    $recipients = notificationRowsForGroup($group, (int)$user['id'], $recipientUserId);

    $announcement = db()->prepare("
        INSERT INTO announcements (created_by, type, recipient_group, title, body, link_url)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $createdBy = (int)$user['id'];
    $announcement->bind_param('isssss', $createdBy, $type, $group, $title, $body, $link);
    $announcement->execute();
    $announcementId = (int)db()->insert_id;

    $stmt = db()->prepare("
        INSERT INTO notifications (announcement_id, recipient_user_id, created_by, type, title, body, link_url, recipient_group)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $emailSent = 0;
    $emailFailed = 0;
    foreach ($recipients as $recipient) {
        $recipientId = (int)$recipient['id'];
        $stmt->bind_param('iiisssss', $announcementId, $recipientId, $createdBy, $type, $title, $body, $link, $group);
        $stmt->execute();
        $notificationId = (int)db()->insert_id;
        $emailResult = sendNotificationEmail($recipient, $title, $body, $link);
        updateNotificationEmailStatus($notificationId, $emailResult);
        $emailResult['sent'] ? $emailSent++ : $emailFailed++;
    }

    $updateAnnouncement = db()->prepare("
        UPDATE announcements
        SET recipient_count = ?, email_sent_count = ?, email_failed_count = ?
        WHERE id = ?
    ");
    $recipientCount = count($recipients);
    $updateAnnouncement->bind_param('iiii', $recipientCount, $emailSent, $emailFailed, $announcementId);
    $updateAnnouncement->execute();
    $telegramResult = $group === 'individual'
        ? ['sent' => false, 'message_id' => null, 'error' => null, 'skipped' => true]
        : sendTelegramAnnouncement(
            $title,
            $body,
            $link,
            (string)($user['coordinator_programme'] ?? '')
        );
    updateAnnouncementTelegramStatus($announcementId, $telegramResult);
    logActivity($createdBy, 'announcement.sent', 'announcement', $announcementId, [
        'recipient_group' => $group,
        'recipient_count' => $recipientCount,
        'email_sent_count' => $emailSent,
        'email_failed_count' => $emailFailed,
        'telegram_sent' => $telegramResult['sent'],
        'telegram_skipped' => !empty($telegramResult['skipped']),
        'telegram_error' => $telegramResult['error'],
    ]);

    respond([
        'success' => true,
        'announcement_id' => $announcementId,
        'recipient_count' => count($recipients),
        'email_sent_count' => $emailSent,
        'email_failed_count' => $emailFailed,
        'telegram_sent' => $telegramResult['sent'],
        'telegram_skipped' => !empty($telegramResult['skipped']),
        'telegram_error' => $telegramResult['error'],
    ], 201);
}

if ($method === 'PUT') {
    if ($id) {
        $stmt = db()->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ? AND recipient_user_id = ?");
        $stmt->bind_param('ii', $id, $user['id']);
        $stmt->execute();
        logActivity((int)$user['id'], 'notification.read', 'notification', $id);
        respond(['success' => true]);
    }

    $stmt = db()->prepare("UPDATE notifications SET is_read = TRUE WHERE recipient_user_id = ?");
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    logActivity((int)$user['id'], 'notification.read_all', 'notification', null);
    respond(['success' => true]);
}

respond(['error' => 'Method not allowed'], 405);

<?php
// api/mail-status.php - Admin-only email transport diagnostic
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';

$user = requireAuth();
if ($user['role'] !== 'admin') {
    respond(['error' => 'Admins only'], 403);
}

$status = laragonSendmailStatus();
$config = notificationEmailConfig();
$replyTo = $config['reply_to'] ?? null;
$replyWarning = null;
if ($replyTo && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
    $replyWarning = 'reply_to must be a valid email address';
    $replyTo = null;
}

respond([
    'configured' => $status['ok'],
    'mode' => $status['mode'] ?? null,
    'warning' => $status['warning'] ?? null,
    'config_warning' => $replyWarning,
    'error' => $status['error'],
    'mailpit_url' => $status['mailpit_url'] ?? null,
    'from_email' => $config['from_email'] ?? null,
    'reply_to' => $replyTo,
    'sendmail_path' => ini_get('sendmail_path'),
]);

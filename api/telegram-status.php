<?php
// api/telegram-status.php - Admin-only Telegram diagnostic
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/telegram.php';

$user = requireAuth();
if ($user['role'] !== 'admin') {
    respond(['error' => 'Admins only'], 403);
}

$config = telegramConfig();
$enabled = !empty($config['enabled']);
$hasToken = trim((string)($config['bot_token'] ?? '')) !== '';
$programme = trim((string)($user['coordinator_programme'] ?? ''));
$courseChatIds = is_array($config['course_chat_ids'] ?? null) ? $config['course_chat_ids'] : [];
$courseChatId = trim((string)($courseChatIds[$programme] ?? ''));
$hasChat = $courseChatId !== '';

respond([
    'enabled' => $enabled,
    'configured' => $enabled && $hasToken && $hasChat,
    'has_bot_token' => $hasToken,
    'has_chat_id' => $hasChat,
    'programme' => $programme !== '' ? $programme : null,
]);

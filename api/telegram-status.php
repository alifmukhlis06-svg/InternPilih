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
$hasChat = trim((string)($config['chat_id'] ?? '')) !== '';

respond([
    'enabled' => $enabled,
    'configured' => $enabled && $hasToken && $hasChat,
    'has_bot_token' => $hasToken,
    'has_chat_id' => $hasChat,
    'chat_id' => $hasChat ? (string)$config['chat_id'] : null,
]);

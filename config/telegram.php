<?php

$read = static function (string $preferred, string $legacy = ''): string {
    $value = getenv($preferred);
    if ($value !== false && trim($value) !== '') return trim($value);
    if ($legacy !== '') {
        $value = getenv($legacy);
        if ($value !== false && trim($value) !== '') return trim($value);
    }
    return '';
};

$botToken = $read('INTERNPILIH_TELEGRAM_BOT_TOKEN', 'TELEGRAM_BOT_TOKEN');
$defaultChatId = $read('INTERNPILIH_TELEGRAM_CHAT_ID', 'TELEGRAM_CHAT_ID');
$courseChatIds = [];
$courseMapJson = $read('INTERNPILIH_TELEGRAM_COURSE_CHAT_IDS', 'TELEGRAM_COURSE_CHAT_IDS');
if ($courseMapJson !== '') {
    $decoded = json_decode($courseMapJson, true);
    if (is_array($decoded)) {
        foreach ($decoded as $programme => $chatId) {
            $programme = trim((string)$programme);
            $chatId = trim((string)$chatId);
            if ($programme !== '' && $chatId !== '') $courseChatIds[$programme] = $chatId;
        }
    }
}

// A single test chat may be explicitly assigned to multiple synthetic courses.
// Real course groups should use INTERNPILIH_TELEGRAM_COURSE_CHAT_IDS instead.
$programmeJson = $read('INTERNPILIH_TELEGRAM_PROGRAMMES', 'TELEGRAM_PROGRAMMES');
if ($defaultChatId !== '' && $programmeJson !== '') {
    $programmes = json_decode($programmeJson, true);
    if (is_array($programmes)) {
        foreach ($programmes as $programme) {
            $programme = trim((string)$programme);
            if ($programme !== '') $courseChatIds[$programme] ??= $defaultChatId;
        }
    }
}

return [
    'enabled' => filter_var($read('INTERNPILIH_TELEGRAM_ENABLED', 'TELEGRAM_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL),
    'bot_token' => $botToken,
    'chat_id' => $defaultChatId,
    'course_chat_ids' => $courseChatIds,
];

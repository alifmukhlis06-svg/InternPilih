<?php

function telegramConfig(): array {
    $path = __DIR__ . '/../config/telegram.php';
    $config = file_exists($path) ? require $path : [];
    $localPath = __DIR__ . '/../config/telegram.local.php';
    if (file_exists($localPath)) {
        $config = array_merge($config, require $localPath);
    }
    return $config;
}

function sendTelegramAnnouncement(string $title, string $body, ?string $link = null): array {
    $config = telegramConfig();
    if (empty($config['enabled'])) {
        return ['sent' => false, 'message_id' => null, 'error' => 'Telegram is disabled'];
    }

    $token = trim((string)($config['bot_token'] ?? ''));
    $chatId = trim((string)($config['chat_id'] ?? ''));
    if ($token === '' || $chatId === '') {
        return ['sent' => false, 'message_id' => null, 'error' => 'Telegram bot token or chat ID is missing'];
    }

    $text = "[InternPilih] " . $title . "\n\n" . $body;
    if ($link) {
        $text .= "\n\nOpen: " . $link;
    }

    $payload = http_build_query([
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => 'true',
    ]);

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);

    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage?' . $payload;
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        $response = telegramPostViaSocket($token, $payload);
    }
    if ($response === false) {
        $error = error_get_last();
        return [
            'sent' => false,
            'message_id' => null,
            'error' => telegramRedactError($error['message'] ?? 'Telegram request failed', $token),
        ];
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['ok'])) {
        $description = is_array($data) ? ($data['description'] ?? 'Telegram API error') : 'Invalid Telegram response';
        return ['sent' => false, 'message_id' => null, 'error' => telegramRedactError($description, $token)];
    }

    return [
        'sent' => true,
        'message_id' => $data['result']['message_id'] ?? null,
        'error' => null,
    ];
}

function telegramRedactError(string $message, string $token): string {
    return substr(str_replace($token, '[hidden-token]', $message), 0, 255);
}

function telegramPostViaSocket(string $token, string $payload): string|false {
    $ips = gethostbynamel('api.telegram.org') ?: [];
    foreach ($ips as $ip) {
        $socket = @stream_socket_client(
            'ssl://' . $ip . ':443',
            $errno,
            $errstr,
            20,
            STREAM_CLIENT_CONNECT,
            stream_context_create([
                'ssl' => [
                    'peer_name' => 'api.telegram.org',
                    'SNI_enabled' => true,
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ])
        );
        if (!$socket) {
            continue;
        }

        $path = '/bot' . $token . '/sendMessage?' . $payload;
        $request = "GET $path HTTP/1.1\r\n"
            . "Host: api.telegram.org\r\n"
            . "Connection: close\r\n\r\n";
        fwrite($socket, $request);
        stream_set_timeout($socket, 20);
        $response = stream_get_contents($socket);
        fclose($socket);

        if ($response) {
            $parts = explode("\r\n\r\n", $response, 2);
            return $parts[1] ?? $response;
        }
    }

    return false;
}

function updateAnnouncementTelegramStatus(int $announcementId, array $result): void {
    $sentAt = $result['sent'] ? date('Y-m-d H:i:s') : null;
    $messageId = isset($result['message_id']) ? (string)$result['message_id'] : null;
    $error = $result['error'] ? substr((string)$result['error'], 0, 255) : null;

    $stmt = db()->prepare("
        UPDATE announcements
        SET telegram_sent_at = ?, telegram_message_id = ?, telegram_error = ?
        WHERE id = ?
    ");
    $stmt->bind_param('sssi', $sentAt, $messageId, $error, $announcementId);
    $stmt->execute();
}

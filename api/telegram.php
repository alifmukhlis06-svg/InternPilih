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

function sendTelegramAnnouncement(
    string $title,
    string $body,
    ?string $link = null,
    ?string $programme = null
): array {
    $config = telegramConfig();
    if (empty($config['enabled'])) {
        return ['sent' => false, 'message_id' => null, 'error' => 'Telegram is disabled'];
    }

    $token = telegramNormalizeBotToken((string)($config['bot_token'] ?? ''));
    $chatId = trim((string)($config['chat_id'] ?? ''));
    if ($programme !== null) {
        $programme = trim($programme);
        $courseChatIds = is_array($config['course_chat_ids'] ?? null)
            ? $config['course_chat_ids']
            : [];
        $chatId = trim((string)($courseChatIds[$programme] ?? ''));
        if ($chatId === '') {
            return ['sent' => false, 'message_id' => null, 'error' => 'Course-specific Telegram delivery is not configured'];
        }
    }
    if ($token === '' || $chatId === '') {
        return ['sent' => false, 'message_id' => null, 'error' => 'Telegram bot token or chat ID is missing'];
    }
    if (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', $token)) {
        return ['sent' => false, 'message_id' => null, 'error' => 'Telegram bot token format is invalid'];
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
    $response = telegramRequestViaCurl($url);
    if ($response === false) {
        $response = @file_get_contents($url, false, $context);
    }
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

    $response = telegramNormalizeResponse($response);
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

function telegramNormalizeBotToken(string $token): string {
    $token = trim($token);
    $token = preg_replace(
        '/^(?:export\s+)?(?:INTERNPILIH_TELEGRAM_BOT_TOKEN|TELEGRAM_BOT_TOKEN)\s*=\s*/i',
        '',
        $token
    ) ?? $token;
    $token = trim($token, " \t\n\r\0\x0B\"'");

    if (preg_match('#api\.telegram\.org/bot([^/?\s]+)#i', $token, $match)) {
        $token = $match[1];
    }
    if (preg_match('/^bot(\d+:[A-Za-z0-9_-]+)$/i', $token, $match)) {
        $token = $match[1];
    }

    return trim($token, " \t\n\r\0\x0B\"'");
}

function telegramRequestViaCurl(string $url): string|false {
    if (!function_exists('curl_init')) {
        return false;
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_ENCODING => '',
    ]);
    $response = curl_exec($curl);
    curl_close($curl);

    return is_string($response) ? $response : false;
}

function telegramNormalizeResponse(string $response): string {
    $response = trim($response);

    if (str_starts_with($response, "\x1f\x8b") && function_exists('gzdecode')) {
        $decoded = @gzdecode($response);
        if (is_string($decoded)) {
            $response = trim($decoded);
        }
    }

    // Some serverless network fallbacks can retain HTTP or chunk framing.
    if (str_starts_with($response, 'HTTP/')) {
        $parts = preg_split("/\r?\n\r?\n/", $response);
        $response = trim((string)end($parts));
    }
    if (preg_match('/^[0-9a-f]+\r\n/i', $response)) {
        $decoded = telegramDecodeChunkedBody($response);
        if (is_string($decoded)) {
            $response = trim($decoded);
        }
    }

    if (!str_starts_with($response, '{')) {
        $start = strpos($response, '{');
        $end = strrpos($response, '}');
        if ($start !== false && $end !== false && $end >= $start) {
            $response = substr($response, $start, $end - $start + 1);
        }
    }

    return $response;
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
            if (count($parts) !== 2) {
                continue;
            }

            [$headers, $body] = $parts;
            if (stripos($headers, "transfer-encoding: chunked") !== false) {
                $body = telegramDecodeChunkedBody($body);
            }
            if ($body !== false) {
                return $body;
            }
        }
    }

    return false;
}

function telegramDecodeChunkedBody(string $body): string|false {
    $decoded = '';
    while ($body !== '') {
        $lineEnd = strpos($body, "\r\n");
        if ($lineEnd === false) {
            return false;
        }

        $sizeLine = trim(substr($body, 0, $lineEnd));
        $extension = strpos($sizeLine, ';');
        if ($extension !== false) {
            $sizeLine = substr($sizeLine, 0, $extension);
        }
        if ($sizeLine === '' || !ctype_xdigit($sizeLine)) {
            return false;
        }

        $size = hexdec($sizeLine);
        $body = substr($body, $lineEnd + 2);
        if ($size === 0) {
            return $decoded;
        }
        if (strlen($body) < $size + 2 || substr($body, $size, 2) !== "\r\n") {
            return false;
        }

        $decoded .= substr($body, 0, $size);
        $body = substr($body, $size + 2);
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

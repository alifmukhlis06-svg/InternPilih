<?php

function notificationEmailConfig(): array {
    $path = __DIR__ . '/../config/mail.php';
    $config = file_exists($path) ? require $path : [];
    $localPath = __DIR__ . '/../config/mail.local.php';
    if (file_exists($localPath)) {
        $config = array_merge($config, require $localPath);
    }
    $environmentOverrides = [
        'INTERNPILIH_MAIL_DRIVER' => 'driver',
        'INTERNPILIH_MAIL_FROM_EMAIL' => 'from_email',
        'INTERNPILIH_MAIL_FROM_NAME' => 'from_name',
        'INTERNPILIH_MAIL_REPLY_TO' => 'reply_to',
        'INTERNPILIH_APP_URL' => 'app_url',
        'INTERNPILIH_SMTP_HOST' => 'smtp_host',
        'INTERNPILIH_SMTP_PORT' => 'smtp_port',
        'INTERNPILIH_SMTP_ENCRYPTION' => 'smtp_encryption',
        'INTERNPILIH_SMTP_USERNAME' => 'smtp_username',
        'INTERNPILIH_SMTP_PASSWORD' => 'smtp_password',
    ];
    foreach ($environmentOverrides as $environmentName => $configKey) {
        $value = getenv($environmentName);
        if ($value !== false && trim($value) !== '') {
            $config[$configKey] = $configKey === 'smtp_port' ? (int)$value : $value;
        }
    }
    return $config;
}

function notificationUrl(?string $link): string {
    $config = notificationEmailConfig();
    $base = rtrim((string)($config['app_url'] ?? ''), '/');
    if (!$link) return $base;
    if (preg_match('/^https?:\/\//i', $link)) return $link;
    return $base . '/' . ltrim($link, '/');
}

function laragonSendmailStatus(): array {
    $config = notificationEmailConfig();
    if (in_array(($config['driver'] ?? ''), ['disabled', 'null'], true)) {
        return ['ok' => true, 'mode' => 'disabled', 'error' => null];
    }
    if (($config['driver'] ?? 'local_capture') === 'smtp') {
        if (empty($config['smtp_username']) || empty($config['smtp_password'])) {
            return ['ok' => false, 'mode' => 'smtp', 'error' => 'SMTP username/app password is missing in config/mail.php'];
        }
        return ['ok' => true, 'mode' => 'smtp', 'error' => null];
    }

    $path = (string)ini_get('sendmail_path');
    if (stripos($path, 'mailpit') !== false) {
        return [
            'ok' => true,
            'mode' => 'local_capture',
            'mailpit_url' => 'http://127.0.0.1:8025',
            'warning' => 'Laragon Mailpit captures emails locally and does not deliver to real inboxes',
            'error' => null,
        ];
    }
    if ($path === '' || stripos($path, 'sendmail') === false) {
        return ['ok' => true, 'mode' => 'php_mail', 'error' => null];
    }

    $sendmailExe = trim(str_replace('"', '', preg_split('/\s+/', $path)[0] ?? ''));
    $candidates = [];
    if ($sendmailExe !== '') {
        $candidates[] = dirname($sendmailExe) . DIRECTORY_SEPARATOR . 'sendmail.ini';
        if (preg_match('/^[A-Z]:/i', $sendmailExe)) {
            foreach (range('C', 'Z') as $drive) {
                $candidates[] = preg_replace('/^[A-Z]:/i', $drive . ':', dirname($sendmailExe)) . DIRECTORY_SEPARATOR . 'sendmail.ini';
            }
        }
    }

    $iniFile = null;
    foreach (array_unique($candidates) as $candidate) {
        if (is_file($candidate)) {
            $iniFile = $candidate;
            break;
        }
    }
    if (!$iniFile) {
        return ['ok' => false, 'mode' => 'sendmail', 'error' => 'Laragon sendmail.ini was not found'];
    }

    $settings = parse_ini_file($iniFile) ?: [];
    $server = trim((string)($settings['smtp_server'] ?? ''));
    $username = trim((string)($settings['auth_username'] ?? ''));
    $password = trim((string)($settings['auth_password'] ?? ''));
    $sender = trim((string)($settings['force_sender'] ?? ''));

    if ($server === '') {
        return ['ok' => false, 'mode' => 'sendmail', 'error' => 'SMTP server is not configured in Laragon sendmail.ini'];
    }
    if ($username === '' || $password === '') {
        return ['ok' => false, 'mode' => 'sendmail', 'error' => 'SMTP username/app password is missing in Laragon sendmail.ini'];
    }
    if ($sender === '') {
        return ['ok' => false, 'mode' => 'sendmail', 'error' => 'force_sender is missing in Laragon sendmail.ini'];
    }

    return ['ok' => true, 'mode' => 'smtp_sendmail', 'error' => null];
}

function smtpRead($socket): string {
    $data = '';
    while (($line = fgets($socket, 515)) !== false) {
        $data .= $line;
        if (preg_match('/^\d{3}\s/', $line)) break;
    }
    return $data;
}

function smtpCommand($socket, string $command, array $okCodes): string {
    fwrite($socket, $command . "\r\n");
    $response = smtpRead($socket);
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $okCodes, true)) {
        throw new RuntimeException(trim($response) ?: 'SMTP command failed');
    }
    return $response;
}

function sendSmtpEmail(string $to, string $subject, string $message, array $headers): void {
    $config = notificationEmailConfig();
    $host = (string)($config['smtp_host'] ?? 'smtp.gmail.com');
    $port = (int)($config['smtp_port'] ?? 587);
    $username = (string)($config['smtp_username'] ?? '');
    $password = preg_replace('/\s+/', '', (string)($config['smtp_password'] ?? ''));
    $from = (string)($config['from_email'] ?? $username);

    $socket = stream_socket_client("tcp://$host:$port", $errno, $errstr, 20);
    if (!$socket) {
        throw new RuntimeException("SMTP connection failed: $errstr");
    }

    try {
        $greeting = smtpRead($socket);
        if ((int)substr($greeting, 0, 3) !== 220) {
            throw new RuntimeException(trim($greeting) ?: 'SMTP greeting failed');
        }
        smtpCommand($socket, 'EHLO localhost', [250]);
        smtpCommand($socket, 'STARTTLS', [220]);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('SMTP TLS negotiation failed');
        }
        smtpCommand($socket, 'EHLO localhost', [250]);
        smtpCommand($socket, 'AUTH LOGIN', [334]);
        smtpCommand($socket, base64_encode($username), [334]);
        smtpCommand($socket, base64_encode($password), [235]);
        smtpCommand($socket, 'MAIL FROM:<' . $from . '>', [250]);
        smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        smtpCommand($socket, 'DATA', [354]);

        $content = implode("\r\n", $headers)
            . "\r\nSubject: " . $subject
            . "\r\nTo: " . $to
            . "\r\n\r\n"
            . str_replace("\n.", "\n..", str_replace("\r\n", "\n", $message));
        smtpCommand($socket, $content . "\r\n.", [250]);
        smtpCommand($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function sendPlainTextEmail(string $email, string $subject, string $message, ?string $replyToOverride = null): array {
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'email' => $email, 'error' => 'Invalid recipient email'];
    }

    $transport = laragonSendmailStatus();
    if (!$transport['ok']) {
        return ['sent' => false, 'email' => $email, 'error' => $transport['error']];
    }
    if (($transport['mode'] ?? '') === 'disabled') {
        $isStaging = strtolower(trim((string)(getenv('INTERNPILIH_ENVIRONMENT') ?: ''))) === 'staging';
        $normalizedEmail = strtolower($email);
        $isSyntheticStagingRecipient = str_ends_with($normalizedEmail, '@example.test')
            || preg_match('/^(?:stg[0-9]+@student\.uitm\.edu\.my|synthetic[a-z0-9]+@uitm\.edu\.my)$/', $normalizedEmail) === 1;
        if ($isStaging && $isSyntheticStagingRecipient) {
            // Staging records the workflow result but deliberately performs no
            // network delivery for these isolated synthetic accounts.
            return [
                'sent' => true,
                'simulated' => true,
                'mode' => 'staging_simulation',
                'email' => $email,
                'error' => null,
            ];
        }
        return [
            'sent' => false,
            'simulated' => false,
            'mode' => 'disabled',
            'email' => $email,
            'error' => 'Email delivery is disabled in this environment',
        ];
    }

    $config = notificationEmailConfig();
    $fromEmail = (string)($config['from_email'] ?? 'noreply@internpilih.local');
    $fromName = (string)($config['from_name'] ?? 'InternPilih');
    $replyTo = trim((string)($replyToOverride ?: ($config['reply_to'] ?? $fromEmail)));
    if (!filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $replyTo = $fromEmail;
    }
    $subject = preg_replace('/[\r\n]+/', ' ', trim($subject));

    $safeFromName = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($fromName)
        : preg_replace('/[\r\n]+/', ' ', $fromName);

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $safeFromName . ' <' . $fromEmail . '>',
        'Reply-To: ' . $replyTo,
        'X-Mailer: PHP/' . phpversion(),
    ];

    if (($config['driver'] ?? 'local_capture') === 'smtp') {
        try {
            sendSmtpEmail($email, $subject, $message, $headers);
            return ['sent' => true, 'email' => $email, 'error' => null];
        } catch (Throwable $e) {
            return ['sent' => false, 'email' => $email, 'error' => 'SMTP failed: ' . $e->getMessage()];
        }
    }

    $sent = @mail($email, $subject, $message, implode("\r\n", $headers));
    return [
        'sent' => $sent,
        'email' => $email,
        'error' => $sent ? null : 'PHP mail transport failed or is not configured',
    ];
}

function sendNotificationEmail(array $recipient, string $title, string $body, ?string $link = null): array {
    $url = notificationUrl($link);
    $message = $body . "\n\n";
    if ($url) {
        $message .= "Open in InternPilih: " . $url . "\n\n";
    }
    $message .= "This email was sent automatically by InternPilih.";

    return sendPlainTextEmail(
        (string)($recipient['email'] ?? ''),
        '[InternPilih] ' . $title,
        $message
    );
}

function sendPasswordResetEmail(array $recipient, string $resetUrl): array {
    $name = trim((string)($recipient['name'] ?? '')) ?: 'there';
    $message = "Hello {$name},\n\n"
        . "We received a request to reset your InternPilih password.\n\n"
        . "Reset your password: {$resetUrl}\n\n"
        . "This link expires in 60 minutes and can only be used once. If you did not request this change, you can ignore this email.\n\n"
        . "InternPilih";

    return sendPlainTextEmail(
        (string)($recipient['email'] ?? ''),
        '[InternPilih] Reset your password',
        $message
    );
}

function sendPasswordChangeVerificationEmail(array $recipient, string $verificationUrl): array {
    $name = trim((string)($recipient['name'] ?? '')) ?: 'there';
    $message = "Hello {$name},\n\n"
        . "Use the secure link below to verify your email address before changing your InternPilih password.\n\n"
        . "Verify password change: {$verificationUrl}\n\n"
        . "This link expires in 15 minutes and can only be used once. If you did not request this change, ignore this email and keep your current password.\n\n"
        . "InternPilih";

    return sendPlainTextEmail(
        (string)($recipient['email'] ?? ''),
        '[InternPilih] Verify your password change',
        $message
    );
}

function sendRejectionNoticeEmail(array $offer, string $subject, string $body): array {
    $studentName = trim((string)($offer['student_name'] ?? ''));
    $studentId = trim((string)($offer['matric_id'] ?? ''));
    $replyTo = trim((string)($offer['student_email'] ?? ''));
    $footer = "\n\n---\nSent through InternPilih";
    if ($studentName !== '') {
        $footer .= " on behalf of {$studentName}";
    }
    if ($studentId !== '') {
        $footer .= " ({$studentId})";
    }
    $footer .= '.';

    return sendPlainTextEmail(
        (string)($offer['hr_email'] ?? ''),
        $subject,
        $body . $footer,
        $replyTo
    );
}

function updateNotificationEmailStatus(int $notificationId, array $result): void {
    $sentAt = $result['sent'] ? date('Y-m-d H:i:s') : null;
    $error = $result['error'] ? substr((string)$result['error'], 0, 255) : null;
    $email = substr((string)($result['email'] ?? ''), 0, 150);

    $stmt = db()->prepare("
        UPDATE notifications
        SET email_to = ?, email_sent_at = ?, email_error = ?
        WHERE id = ?
    ");
    $stmt->bind_param('sssi', $email, $sentAt, $error, $notificationId);
    $stmt->execute();
}

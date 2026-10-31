<?php
// Public password recovery endpoint.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

function ensurePasswordResetTable(): void {
    $result = db()->query("SELECT to_regclass('public.password_reset_tokens') AS table_name");
    $row = $result instanceof DatabaseResult ? $result->fetch_assoc() : null;
    if (empty($row['table_name'])) {
        throw new RuntimeException('Password recovery storage could not be initialised.');
    }
}

function resetTokenIsValid(string $token): bool {
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) return false;

    $hash = hash('sha256', $token);
    $stmt = db()->prepare("
        SELECT id
        FROM password_reset_tokens
        WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
        LIMIT 1
    ");
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}

try {
    ensurePasswordResetTable();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $token = trim((string)($_GET['token'] ?? ''));
        respond(['valid' => resetTokenIsValid($token)]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['error' => 'Method not allowed'], 405);
    }

    $data = body();
    $action = (string)($data['action'] ?? 'request');

    if (in_array($action, ['request', 'request_change'], true)) {
        $isAuthenticatedChange = $action === 'request_change';
        $purpose = $isAuthenticatedChange ? 'password_change' : 'password_reset';
        $genericMessage = $isAuthenticatedChange
            ? 'A verification link has been sent to your registered email address.'
            : 'If that email belongs to an InternPilih account, a reset link has been sent.';

        if ($isAuthenticatedChange) {
            $sessionUser = requireAuth();
            $userId = (int)$sessionUser['id'];
            $stmt = db()->prepare('SELECT id, name, email FROM users WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $userId);
        } else {
            $email = strtolower(trim((string)($data['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                respond(['error' => 'Enter a valid email address.'], 422);
            }
            $stmt = db()->prepare('SELECT id, name, email FROM users WHERE email = ? LIMIT 1');
            $stmt->bind_param('s', $email);
        }
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user) {
            usleep(250000);
            respond(['success' => true, 'message' => $genericMessage]);
        }

        $userId = (int)$user['id'];
        $recent = db()->prepare("
            SELECT id FROM password_reset_tokens
            WHERE user_id = ? AND purpose = ? AND used_at IS NULL
              AND created_at > NOW() - INTERVAL '60 seconds'
            LIMIT 1
        ");
        $recent->bind_param('is', $userId, $purpose);
        $recent->execute();
        if ($recent->get_result()->fetch_assoc()) {
            respond(['success' => true, 'message' => $genericMessage]);
        }

        db()->query("DELETE FROM password_reset_tokens WHERE expires_at < NOW() - INTERVAL '1 day'");
        $invalidate = db()->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
        $invalidate->bind_param('i', $userId);
        $invalidate->execute();

        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

        $expiry = $isAuthenticatedChange ? '15 minutes' : '1 hour';
        $insert = db()->prepare("
            INSERT INTO password_reset_tokens (user_id, token_hash, purpose, expires_at, requested_ip)
            VALUES (?, ?, ?, NOW() + INTERVAL '{$expiry}', ?)
        ");
        $insert->bind_param('isss', $userId, $hash, $purpose, $ip);
        $insert->execute();
        $tokenId = (int)db()->insert_id;

        $resetUrl = notificationUrl('reset-password.html?token=' . rawurlencode($token));
        $delivery = $isAuthenticatedChange
            ? sendPasswordChangeVerificationEmail($user, $resetUrl)
            : sendPasswordResetEmail($user, $resetUrl);
        if (!$delivery['sent']) {
            $delete = db()->prepare('DELETE FROM password_reset_tokens WHERE id = ?');
            $delete->bind_param('i', $tokenId);
            $delete->execute();
            error_log('InternPilih password reset email failed: ' . ($delivery['error'] ?? 'Unknown mail error'));
            respond(['error' => 'The reset email could not be sent. Please contact your coordinator or try again later.'], 502);
        }

        respond(['success' => true, 'message' => $genericMessage]);
    }

    if ($action === 'reset') {
        $token = trim((string)($data['token'] ?? ''));
        $password = (string)($data['password'] ?? '');

        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            respond(['error' => 'This reset link is invalid or has expired.'], 422);
        }
        if (strlen($password) < 8 || strlen($password) > 128) {
            respond(['error' => 'Use a password between 8 and 128 characters.'], 422);
        }

        $hash = hash('sha256', $token);
        $database = db();
        $database->begin_transaction();

        $lookup = $database->prepare("
            SELECT id, user_id
            FROM password_reset_tokens
            WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
            LIMIT 1
            FOR UPDATE
        ");
        $lookup->bind_param('s', $hash);
        $lookup->execute();
        $reset = $lookup->get_result()->fetch_assoc();

        if (!$reset) {
            $database->rollback();
            respond(['error' => 'This reset link is invalid or has expired.'], 422);
        }

        $userId = (int)$reset['user_id'];
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $updateUser = $database->prepare('UPDATE users SET password = ? WHERE id = ?');
        $updateUser->bind_param('si', $passwordHash, $userId);
        $updateUser->execute();

        $useTokens = $database->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
        $useTokens->bind_param('i', $userId);
        $useTokens->execute();
        $database->commit();

        $_SESSION = [];
        session_regenerate_id(true);
        respond(['success' => true, 'message' => 'Your password has been updated. You can now sign in.']);
    }

    respond(['error' => 'Invalid password reset action.'], 422);
} catch (Throwable $e) {
    if (isset($database) && $database instanceof DatabaseConnection && $database->inTransaction()) {
        try { $database->rollback(); } catch (Throwable $ignored) {}
    }
    error_log('InternPilih password reset error: ' . $e->getMessage());
    respond(['error' => 'Password recovery is temporarily unavailable. Please try again later.'], 500);
}

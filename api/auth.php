<?php
// api/auth.php  — Login & Logout
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

const LOGIN_RATE_LIMIT_MAX_FAILURES = 5;
const LOGIN_RATE_LIMIT_WINDOW_SECONDS = 900;
const INTERNPILIH_STAGING_PROJECT_REF = 'zohcslnofeuisywqnjts';

/**
 * Return fictional login identities only for the approved staging project.
 * Passwords and password hashes are never returned to the browser.
 *
 * @return array<int, array{identifier:string,role:string}>
 */
function stagingDemoAccounts(): array {
    $environment = strtolower(trim((string)(getenv('INTERNPILIH_ENVIRONMENT') ?: '')));
    $vercelEnvironment = strtolower(trim((string)(getenv('VERCEL_ENV') ?: '')));
    $projectUrl = rtrim(trim((string)(getenv('INTERNPILIH_SUPABASE_URL') ?: '')), '/');
    $expectedUrl = 'https://' . INTERNPILIH_STAGING_PROJECT_REF . '.supabase.co';
    if ($environment !== 'staging'
        || ($vercelEnvironment !== '' && $vercelEnvironment !== 'preview')
        || !hash_equals($expectedUrl, $projectUrl)) {
        return [];
    }

    try {
        $result = db()->query("
            SELECT CASE
                       WHEN u.role = 'student' THEN s.matric_id
                       WHEN u.role = 'admin' THEN a.staff_id
                       ELSE u.email
                   END AS identifier,
                   u.role
            FROM users u
            LEFT JOIN students s ON s.user_id = u.id
            LEFT JOIN admins a ON a.user_id = u.id
            WHERE (u.role = 'student' AND s.matric_id LIKE 'STG%')
               OR (u.role = 'admin' AND u.name LIKE 'Synthetic Coordinator %')
               OR (u.role = 'employer' AND LOWER(u.email) LIKE '%@example.test')
            ORDER BY
                CASE u.role WHEN 'student' THEN 1 WHEN 'employer' THEN 2 ELSE 3 END,
                identifier
        ");
        if (!$result) return [];
        return array_map(
            static fn(array $row): array => [
                'identifier' => (string)$row['identifier'],
                'role' => (string)$row['role'],
            ],
            $result->fetch_all(MYSQLI_ASSOC)
        );
    } catch (Throwable $error) {
        error_log('InternPilih staging demo-account lookup failed.');
        return [];
    }
}

/**
 * Update the local failed-login counter without storing an email address or password.
 *
 * @return array{failures:int,retry_after:int}
 */
function loginRateLimit(string $email, bool $recordFailure = false, bool $clear = false): array {
    $isServerless = getenv('VERCEL') !== false;
    $driver = strtolower(trim((string)(getenv('INTERNPILIH_RATE_LIMIT_DRIVER') ?: ($isServerless ? 'database' : 'files'))));
    if ($driver === 'database') {
        return databaseLoginRateLimit($email, $recordFailure, $clear);
    }
    if ($driver !== 'files' || $isServerless) {
        throw new RuntimeException('A shared database login limiter is required on Vercel.');
    }

    $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'internpilih_login_limits';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        // Authentication must remain available if the optional local limiter cannot
        // be initialised. Production deployments should use a shared limiter.
        return ['failures' => 0, 'retry_after' => 0];
    }

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $key = hash('sha256', $ip . "\n" . strtolower(trim($email)));
    $path = $directory . DIRECTORY_SEPARATOR . $key . '.json';
    $handle = fopen($path, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        return ['failures' => 0, 'retry_after' => 0];
    }

    $contents = stream_get_contents($handle);
    $decoded = json_decode($contents ?: '[]', true);
    $attempts = is_array($decoded) ? $decoded : [];
    $cutoff = time() - LOGIN_RATE_LIMIT_WINDOW_SECONDS;
    $attempts = array_values(array_filter(
        $attempts,
        static fn(mixed $timestamp): bool => is_int($timestamp) && $timestamp > $cutoff
    ));

    if ($clear) {
        $attempts = [];
    } elseif ($recordFailure) {
        $attempts[] = time();
    }

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($attempts));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    $oldest = $attempts[0] ?? time();
    return [
        'failures' => count($attempts),
        'retry_after' => max(0, LOGIN_RATE_LIMIT_WINDOW_SECONDS - (time() - $oldest)),
    ];
}

/** @return array{failures:int,retry_after:int} */
function databaseLoginRateLimit(string $email, bool $recordFailure, bool $clear): array {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $key = hash('sha256', $ip . "\n" . strtolower(trim($email)));
    $database = db();
    $database->begin_transaction();
    try {
        $lookup = $database->prepare("
            SELECT failure_count,
                   EXTRACT(EPOCH FROM window_started_at)::bigint AS window_started_epoch,
                   EXTRACT(EPOCH FROM locked_until)::bigint AS locked_until_epoch
            FROM login_rate_limits
            WHERE key_hash = ?
            FOR UPDATE
        ");
        $lookup->bind_param('s', $key);
        $lookup->execute();
        $row = $lookup->get_result()->fetch_assoc();
        $now = time();

        if ($clear) {
            if ($row) {
                $delete = $database->prepare('DELETE FROM login_rate_limits WHERE key_hash = ?');
                $delete->bind_param('s', $key);
                $delete->execute();
            }
            $database->commit();
            return ['failures' => 0, 'retry_after' => 0];
        }

        $failureCount = (int)($row['failure_count'] ?? 0);
        $windowStarted = (int)($row['window_started_epoch'] ?? $now);
        $lockedUntil = isset($row['locked_until_epoch']) ? (int)$row['locked_until_epoch'] : 0;
        if ($lockedUntil > $now) {
            $database->commit();
            return [
                'failures' => max(LOGIN_RATE_LIMIT_MAX_FAILURES, $failureCount),
                'retry_after' => $lockedUntil - $now,
            ];
        }
        if (($now - $windowStarted) >= LOGIN_RATE_LIMIT_WINDOW_SECONDS) {
            $failureCount = 0;
            $windowStarted = $now;
            $lockedUntil = 0;
        }

        if (!$recordFailure) {
            $database->commit();
            return ['failures' => $failureCount, 'retry_after' => 0];
        }

        $failureCount++;
        if ($failureCount >= LOGIN_RATE_LIMIT_MAX_FAILURES) {
            $lockedUntil = $now + LOGIN_RATE_LIMIT_WINDOW_SECONDS;
        }
        $windowValue = gmdate('Y-m-d H:i:sP', $windowStarted);
        $lockedValue = $lockedUntil > 0 ? gmdate('Y-m-d H:i:sP', $lockedUntil) : null;
        $upsert = $database->prepare("
            INSERT INTO login_rate_limits
                (key_hash, failure_count, window_started_at, locked_until, updated_at)
            VALUES (?, ?, CAST(? AS timestamptz), CAST(? AS timestamptz), CURRENT_TIMESTAMP)
            ON CONFLICT (key_hash) DO UPDATE SET
                failure_count = EXCLUDED.failure_count,
                window_started_at = EXCLUDED.window_started_at,
                locked_until = EXCLUDED.locked_until,
                updated_at = CURRENT_TIMESTAMP
        ");
        $upsert->bind_param('siss', $key, $failureCount, $windowValue, $lockedValue);
        $upsert->execute();
        $database->commit();
        return [
            'failures' => $failureCount,
            'retry_after' => $lockedUntil > $now ? $lockedUntil - $now : 0,
        ];
    } catch (Throwable $error) {
        if ($database->inTransaction()) $database->rollback();
        throw $error;
    }
}

/** @return array{failures:int,retry_after:int} */
function checkedLoginRateLimit(string $email, bool $recordFailure = false, bool $clear = false): array {
    try {
        return loginRateLimit($email, $recordFailure, $clear);
    } catch (Throwable $error) {
        error_log('InternPilih login limiter failed: ' . $error->getMessage());
        respond(['error' => 'Authentication is temporarily unavailable.'], 503);
    }
}

// POST /api/auth.php  → login
function normalizedEmail(string $email): string {
    return strtolower(trim($email));
}

function isAllowedUitmEmail(string $email): bool {
    $normalized = normalizedEmail($email);
    $separator = strrpos($normalized, '@');
    if ($separator === false || $separator === 0) return false;
    return in_array(substr($normalized, $separator + 1), ['student.uitm.edu.my', 'uitm.edu.my'], true);
}

function oauthConfiguration(): array {
    $localFile = __DIR__ . '/../config/supabase.local.php';
    $local = is_file($localFile) ? require $localFile : [];
    $url = rtrim(trim((string)(getenv('INTERNPILIH_SUPABASE_URL') ?: ($local['project_url'] ?? ''))), '/');
    $publishableKey = trim((string)(getenv('INTERNPILIH_SUPABASE_PUBLISHABLE_KEY') ?: ($local['publishable_key'] ?? '')));
    return [
        'enabled' => $url !== '' && $publishableKey !== '',
        'project_url' => $url,
        'publishable_key' => $publishableKey,
    ];
}

function fetchSupabaseGoogleIdentity(string $accessToken): ?array {
    $configuration = oauthConfiguration();
    if (!$configuration['enabled'] || $accessToken === '' || strlen($accessToken) > 8192) return null;

    $url = $configuration['project_url'] . '/auth/v1/user';
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $accessToken,
        'apikey: ' . $configuration['publishable_key'],
    ];
    $responseBody = false;
    $status = 0;

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $responseBody = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        $responseBody = @file_get_contents($url, false, $context);
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                $status = (int)$matches[1];
                break;
            }
        }
    }

    if ($status !== 200 || !is_string($responseBody)) return null;
    $identity = json_decode($responseBody, true);
    if (!is_array($identity)) return null;
    // A Supabase user may have several linked providers. Check the actual
    // Google identity and its verified address, not editable user metadata.
    $email = normalizedEmail((string)($identity['email'] ?? ''));
    $googleSubject = '';
    $googleProfile = [];
    foreach (($identity['identities'] ?? []) as $linked) {
        if (!is_array($linked) || ($linked['provider'] ?? '') !== 'google') continue;
        $data = $linked['identity_data'] ?? [];
        if (is_array($data)
            && normalizedEmail((string)($data['email'] ?? '')) === $email
            && ($data['email_verified'] ?? false) === true) {
            $googleSubject = trim((string)($linked['provider_id'] ?? $data['sub'] ?? ''));
            $googleProfile = $data;
            break;
        }
    }
    if ($googleSubject === '') return null;
    $identity['_verified_google_subject'] = $googleSubject;
    $identity['_verified_google_profile'] = $googleProfile;
    return $identity;
}

function registrationSessionIdentity(): ?array {
    $registration = $_SESSION['registration_identity'] ?? null;
    if (!is_array($registration) || (int)($registration['expires_at'] ?? 0) <= time()) {
        unset($_SESSION['registration_identity']);
        return null;
    }
    return $registration;
}

function studentIdFromEmail(string $email): ?string {
    return preg_match('/^([0-9]{10})@student\.uitm\.edu\.my$/', $email, $match) ? $match[1] : null;
}

function loadInternPilihUserByEmail(string $email, bool $forUpdate = false): ?array {
    $lockingClause = $forUpdate ? ' FOR UPDATE OF u' : '';
    $stmt = db()->prepare("
        SELECT u.id, u.name, u.email, to_jsonb(u)->>'phone' AS phone, u.role,
               to_jsonb(u)->>'google_auth_user_id' AS google_auth_user_id,
               s.matric_id, COALESCE(s.faculty, a.faculty) AS faculty,
               COALESCE(s.campus, a.campus) AS campus,
               s.programme, s.cgpa, s.coordinator_id, coordinator.name AS coordinator_name,
               a.programme AS coordinator_programme, a.staff_id, e.company_id
        FROM users u
        LEFT JOIN students s ON s.user_id = u.id
        LEFT JOIN employers e ON e.user_id = u.id
        LEFT JOIN admins a ON a.user_id = u.id
        LEFT JOIN users coordinator ON coordinator.id = s.coordinator_id
        WHERE LOWER(TRIM(u.email)) = ?
        LIMIT 1{$lockingClause}
    ");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function dashboardForRole(string $role): ?string {
    return match ($role) {
        'student' => '/student/dashboard.html',
        'admin' => '/admin/dashboard.html',
        default => null,
    };
}

$action = strtolower(trim((string)($_GET['action'] ?? '')));

if ($method === 'GET' && $action === 'oauth-config') {
    $configuration = oauthConfiguration();
    if (!$configuration['enabled']) respond(['error' => 'Google sign-in is temporarily unavailable.'], 503);
    respond($configuration);
}

if ($method === 'POST' && $action === 'google') {
    resetApplicationSessionForAuthentication();
    $data = body();
    $identity = fetchSupabaseGoogleIdentity(trim((string)($data['access_token'] ?? '')));
    $email = normalizedEmail((string)($identity['email'] ?? ''));
    $googleUserId = trim((string)($identity['id'] ?? ''));
    $googleSubject = trim((string)($identity['_verified_google_subject'] ?? ''));
    $emailVerified = $googleSubject !== '';

    if (!$identity || $email === '' || $googleUserId === '' || $googleSubject === '' || !$emailVerified) {
        recordAuthenticationAudit(null, 'login_failure', 'google');
        respondError('Google sign-in could not be completed. Please try again.', 401, 'invalid_oauth_response');
    }
    if (!isAllowedUitmEmail($email)) {
        recordAuthenticationAudit(null, 'login_failure', 'google');
        respondError('Please use an approved UiTM email account.', 403, 'unsupported_email_domain');
    }

    $database = db();
    $database->begin_transaction();
    try {
        $user = loadInternPilihUserByEmail($email, true);
        if (!$user) {
            if (studentIdFromEmail($email) === null) {
                $database->rollback();
                respondError('Student registration requires a verified ten-digit UiTM student email.', 403, 'student_email_required');
            }
            $existingIdentity = $database->prepare('SELECT id FROM users WHERE google_auth_user_id = ? OR google_subject = ? LIMIT 1');
            $existingIdentity->execute([$googleUserId, $googleSubject]);
            if ($existingIdentity->get_result()->fetch_assoc()) {
                $database->rollback();
                respondError('This Google identity is already linked to an InternPilih account.', 409, 'identity_already_linked');
            }
            $database->rollback();
            $googleProfile = $identity['_verified_google_profile'] ?? [];
            $picture = trim((string)($googleProfile['avatar_url'] ?? $googleProfile['picture'] ?? ''));
            $pictureHost = strtolower((string)(parse_url($picture, PHP_URL_HOST) ?: ''));
            if (!str_starts_with($picture, 'https://')
                || !($pictureHost === 'googleusercontent.com' || str_ends_with($pictureHost, '.googleusercontent.com'))) {
                $picture = '';
            }
            session_regenerate_id(true);
            $_SESSION['registration_identity'] = [
                'google_auth_user_id' => $googleUserId,
                'google_subject' => $googleSubject,
                'email' => $email,
                'matric_id' => studentIdFromEmail($email),
                'name' => trim((string)($googleProfile['full_name'] ?? $googleProfile['name'] ?? '')),
                'picture' => $picture,
                'expires_at' => time() + authenticationSessionLifetime(),
            ];
            respond(['success' => true, 'registration' => true, 'redirect' => '/complete-student-profile.html']);
        }

        $redirect = dashboardForRole((string)$user['role']);
        if ($redirect === null) {
            $database->rollback();
            recordAuthenticationAudit((int)$user['id'], 'login_failure', 'google');
            respondError('This account cannot use UiTM Google sign-in.', 403, 'invalid_account');
        }

        $linkedGoogleUserId = trim((string)($user['google_auth_user_id'] ?? ''));
        if ($linkedGoogleUserId !== '' && !hash_equals($linkedGoogleUserId, $googleUserId)) {
            $database->rollback();
            recordAuthenticationAudit((int)$user['id'], 'login_failure', 'google');
            respondError('Google sign-in could not be completed. Please contact your internship coordinator.', 403, 'account_link_mismatch');
        }

        $subjectConflict = $database->prepare('SELECT id FROM users WHERE google_subject = ? AND id <> ? LIMIT 1');
        $subjectConflict->execute([$googleSubject, (int)$user['id']]);
        if ($subjectConflict->get_result()->fetch_assoc()) {
            $database->rollback();
            respondError('This Google identity is already linked to another account.', 409, 'identity_already_linked');
        }

        $userId = (int)$user['id'];
        $update = $database->prepare('UPDATE users SET google_auth_user_id = ?, google_subject = ?, last_login_at = CURRENT_TIMESTAMP WHERE id = ? AND (google_subject IS NULL OR google_subject = ?)');
        $update->bind_param('ssis', $googleUserId, $googleSubject, $userId, $googleSubject);
        $update->execute();
        if ($update->affected_rows !== 1) {
            $database->rollback();
            respondError('Google identity does not match this account.', 403, 'account_link_mismatch');
        }
        $database->commit();

        unset($user['google_auth_user_id']);
        establishAuthenticatedSession($user, 'google');
        recordAuthenticationAudit($userId, 'google_login_success', 'google');
        respond(['success' => true, 'user' => $user, 'redirect' => $redirect]);
    } catch (Throwable $error) {
        if ($database->inTransaction()) $database->rollback();
        recordAuthenticationAudit(null, 'login_failure', 'google');
        error_log('InternPilih Google account linking failed.');
        respondError('Google sign-in is temporarily unavailable. Please try again.', 503, 'google_login_unavailable');
    }
}

if ($method === 'POST') {
    resetApplicationSessionForAuthentication();
    $data  = body();
    $role = strtolower(trim((string)($data['role'] ?? 'student')));
    $identifier = trim((string)($data['identifier'] ?? ''));
    $pass  = $data['password'] ?? '';

    if (!in_array($role, ['student', 'admin', 'employer'], true)) {
        recordAuthenticationAudit(null, 'login_failure', 'password');
        respond(['error' => 'Select a valid account role.'], 422);
    }
    $identifierLabel = $role === 'student' ? 'Student ID' : ($role === 'admin' ? 'Staff ID' : 'Email');
    if ($identifier === '' || $pass === '') {
        recordAuthenticationAudit(null, 'login_failure', 'password');
        respond(['error' => $identifierLabel . ' and password are required.'], 422);
    }

    $rateLimitIdentity = $role . ':' . strtolower($identifier);
    $limit = checkedLoginRateLimit($rateLimitIdentity);
    if ($limit['failures'] >= LOGIN_RATE_LIMIT_MAX_FAILURES) {
        recordAuthenticationAudit(null, 'login_failure', 'password');
        header('Retry-After: ' . max(1, $limit['retry_after']));
        respond(['error' => 'Too many login attempts. Please wait and try again.'], 429);
    }

    $identityClause = match ($role) {
        'student' => 's.matric_id = ?',
        'admin' => 'a.staff_id = ?',
        default => 'LOWER(u.email) = LOWER(?)',
    };
    $stmt = db()->prepare("
        SELECT u.id, u.name, u.email, to_jsonb(u)->>'phone' AS phone, u.password, u.role,
               s.matric_id, COALESCE(s.faculty, a.faculty) AS faculty,
               COALESCE(s.campus, a.campus) AS campus,
               s.programme, s.cgpa, s.coordinator_id, coordinator.name AS coordinator_name,
               a.programme AS coordinator_programme, a.staff_id, e.company_id
        FROM users u
        LEFT JOIN students s ON s.user_id = u.id
        LEFT JOIN employers e ON e.user_id = u.id
        LEFT JOIN admins a ON a.user_id = u.id
        LEFT JOIN users coordinator ON coordinator.id = s.coordinator_id
        WHERE u.role = ? AND {$identityClause}
        LIMIT 1
    ");
    $stmt->bind_param('ss', $role, $identifier);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user || !password_verify($pass, $user['password'])) {
        checkedLoginRateLimit($rateLimitIdentity, true);
        recordAuthenticationAudit($user ? (int)$user['id'] : null, 'login_failure', 'password');
        usleep(random_int(150000, 350000));
        respond(['error' => 'Invalid ' . $identifierLabel . ' or password.'], 401);
    }

    unset($user['password']);
    checkedLoginRateLimit($rateLimitIdentity, false, true);
    $userId = (int)$user['id'];
    $updateLogin = db()->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?');
    $updateLogin->bind_param('i', $userId);
    $updateLogin->execute();
    establishAuthenticatedSession($user, 'password');
    recordAuthenticationAudit($userId, 'password_login_success', 'password');

    respond(['success' => true, 'user' => $user]);
}

// DELETE /api/auth.php  → logout
if ($method === 'DELETE') {
    $sessionUser = currentUser();
    if ($sessionUser) {
        recordAuthenticationAudit((int)$sessionUser['id'], 'logout', (string)($_SESSION['auth_method'] ?? 'session'));
    }
    clearApplicationSession();
    respond(['success' => true]);
}

// GET /api/auth.php  → get current session user
if ($method === 'GET') {
    $user = currentUser();
    if (!$user) {
        $response = ['user' => null];
        $demoAccounts = stagingDemoAccounts();
        if ($demoAccounts !== []) {
            $response['demo_accounts'] = $demoAccounts;
        }
        respond($response);
    }
    respond(['user' => $user]);
}

respond(['error' => 'Method not allowed'], 405);

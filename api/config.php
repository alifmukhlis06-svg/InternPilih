<?php
require_once __DIR__ . '/../config/db.php';

$requestId = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
if ($requestId === '' || !preg_match('/^[a-zA-Z0-9._-]{6,80}$/', $requestId)) {
    $requestId = bin2hex(random_bytes(8));
}
define('INTERNPILIH_REQUEST_ID', $requestId);

// JSON response helper
function respond(mixed $data, int $code = 200): never {
    if (is_array($data) && isset($data['error'])) {
        $data['code'] ??= 'request_failed';
        $data['request_id'] ??= INTERNPILIH_REQUEST_ID;
    }
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data);
    exit;
}

function respondError(string $message, int $httpStatus, string $code, array $details = []): never {
    respond(array_merge([
        'error' => $message,
        'code' => $code,
        'request_id' => INTERNPILIH_REQUEST_ID,
    ], $details), $httpStatus);
}

// InternPilih is a same-origin application. Do not advertise cross-origin API access.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Reject modern browser cross-site state-changing requests. SameSite cookies below
// provide the primary CSRF boundary; this is an additional defense in depth.
$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
$isServerless = getenv('VERCEL') !== false;
if (in_array($requestMethod, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    $configuredOrigin = rtrim((string)(getenv('INTERNPILIH_APP_URL') ?: ''), '/');
    $requestOrigin = rtrim((string)($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
    $allowedOrigins = [];
    if ($configuredOrigin !== '') {
        $allowedOrigins[] = $configuredOrigin;
    }
    if ($isServerless) {
        foreach (['VERCEL_URL', 'VERCEL_BRANCH_URL'] as $vercelHostName) {
            $vercelHost = trim((string)(getenv($vercelHostName) ?: ''));
            if ($vercelHost !== '' && preg_match('/^[a-z0-9.-]+$/i', $vercelHost)) {
                $allowedOrigins[] = 'https://' . rtrim($vercelHost, '/');
            }
        }
    }
    $originAllowed = false;
    if ($requestOrigin !== '') {
        foreach (array_unique($allowedOrigins) as $allowedOrigin) {
            if (hash_equals($allowedOrigin, $requestOrigin)) {
                $originAllowed = true;
                break;
            }
        }
    }
    $originCheckRequired = $isServerless || $allowedOrigins !== [];
    if ($fetchSite === 'cross-site'
        || ($originCheckRequired && !$originAllowed)) {
        respond(['error' => 'Cross-site request blocked.'], 403);
    }
}

// Get JSON request body
function body(): array {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

// Simple session-based auth check.
// Laragon's default session path can be locked down when PHP is run from CLI,
// so keep this app's sessions in a local writable folder.
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
session_name('internpilih_session');
$sessionDriver = strtolower(trim((string)(getenv('INTERNPILIH_SESSION_DRIVER') ?: ($isServerless ? 'database' : 'files'))));
if ($sessionDriver === 'database') {
    require_once __DIR__ . '/../config/DatabaseSessionHandler.php';
    $lifetime = (int)(getenv('INTERNPILIH_SESSION_LIFETIME_SECONDS') ?: 7200);
    session_set_save_handler(new DatabaseSessionHandler(db(), $lifetime), true);
} elseif ($sessionDriver === 'files' && !$isServerless) {
    $sessionPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'internpilih_sessions';
    if (!is_dir($sessionPath)) {
        mkdir($sessionPath, 0775, true);
    }
    session_save_path($sessionPath);
} else {
    throw new RuntimeException('A shared database session driver is required on Vercel.');
}
$forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $forwardedProto === 'https'
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
function currentUser(): ?array {
    $sessionUser = $_SESSION['user'] ?? null;
    if (!$sessionUser) return null;
    $id = (int)$sessionUser['id'];
    $stmt = db()->prepare("
        SELECT u.id, u.name, u.email, to_jsonb(u)->>'phone' AS phone, u.role, s.matric_id,
               COALESCE(s.faculty, a.faculty) AS faculty,
               COALESCE(s.campus, a.campus) AS campus,
               s.programme, s.cgpa, s.coordinator_id,
               coordinator.name AS coordinator_name,
               a.programme AS coordinator_programme, a.staff_id, e.company_id
        FROM users u
        LEFT JOIN students s ON s.user_id = u.id
        LEFT JOIN employers e ON e.user_id = u.id
        LEFT JOIN admins a ON a.user_id = u.id
        LEFT JOIN users coordinator ON coordinator.id = s.coordinator_id
        WHERE u.id = ? LIMIT 1
    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $fresh = $stmt->get_result()->fetch_assoc() ?: null;
    $_SESSION['user'] = $fresh;
    return $fresh;
}
function requireAuth(): array {
    $u = currentUser();
    if (!$u) respond(['error' => 'Unauthenticated'], 401);
    return $u;
}

function requireCoordinatorStudent(int $coordinatorId, int $studentId): void {
    $stmt = db()->prepare('SELECT 1 FROM students WHERE user_id = ? AND coordinator_id = ? LIMIT 1');
    $stmt->bind_param('ii', $studentId, $coordinatorId);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_row()) {
        respond(['error' => 'Student is not assigned to this coordinator.'], 403);
    }
}

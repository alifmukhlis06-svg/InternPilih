<?php

declare(strict_types=1);

$requestId = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
if (!preg_match('/^[a-zA-Z0-9._-]{6,80}$/', $requestId)) {
    $requestId = bin2hex(random_bytes(8));
}
define('INTERNPILIH_REQUEST_ID', $requestId);

/** Classify connection/resource outages, never syntax or constraint errors. */
function isTemporaryDatabaseFailure(Throwable $error): bool {
    if (!$error instanceof PDOException) return false;
    $state = (string)($error->errorInfo[0] ?? $error->getCode());
    return str_starts_with($state, '08')
        || str_starts_with($state, '53')
        || in_array($state, ['57P01', '57P02', '57P03'], true);
}

// Installed before session/database initialization, including direct local PHP
// routes. Endpoint transaction catches still perform their existing rollbacks.
set_exception_handler(static function (Throwable $error): void {
    $unavailable = isTemporaryDatabaseFailure($error);
    $state = $error instanceof PDOException
        ? (string)($error->errorInfo[0] ?? $error->getCode()) : 'application';
    // Never log exception messages, DSNs, query parameters or credentials here.
    error_log('InternPilih request failure; request=' . INTERNPILIH_REQUEST_ID
        . '; type=' . get_class($error) . '; state=' . preg_replace('/[^A-Z0-9]/i', '', $state)
        . '; location=' . basename($error->getFile()) . ':' . $error->getLine());
    http_response_code($unavailable ? 503 : 500);
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    if ($unavailable) header('Retry-After: 10');
    echo json_encode([
        'error' => $unavailable
            ? 'The database service is temporarily unavailable. Please try again shortly.'
            : 'The request could not be completed. Please try again or contact your coordinator.',
        'code' => $unavailable ? 'database_unavailable' : 'internal_error',
        'request_id' => INTERNPILIH_REQUEST_ID,
        'retryable' => $unavailable,
    ]);
});

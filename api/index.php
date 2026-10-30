<?php

declare(strict_types=1);

// Single Vercel Hobby function entrypoint. Every public API route is explicitly
// allowlisted so helper/configuration PHP files can never be invoked directly.
$endpoint = strtolower(trim((string)($_GET['endpoint'] ?? '')));
$handlers = [
    'auth' => 'auth.php',
    'applications' => 'applications.php',
    'companies' => 'companies.php',
    'document-file' => 'document-file.php',
    'documents' => 'documents.php',
    'gemini' => 'gemini.php',
    'mail-status' => 'mail-status.php',
    'notifications' => 'notifications.php',
    'offer-risk-ai' => 'offer-risk-ai.php',
    'offers' => 'offers.php',
    'password-reset' => 'password-reset.php',
    'placement-changes' => 'placement-changes.php',
    'profile' => 'profile.php',
    'reports' => 'reports.php',
    'settings' => 'settings.php',
    'summary' => 'summary.php',
    'telegram-status' => 'telegram-status.php',
];

if (!isset($handlers[$endpoint])) {
    http_response_code(404);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['error' => 'Not found']);
    exit;
}

require __DIR__ . '/' . $handlers[$endpoint];

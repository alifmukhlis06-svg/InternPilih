<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/StagingConnection.php';

/** @param array<string, string> $variables */
function withStagingEnvironment(array $variables, callable $test): void
{
    $names = array_keys($variables);
    $previous = [];
    foreach ($variables as $name => $value) {
        $previous[$name] = getenv($name);
        putenv($name . '=' . $value);
    }

    try {
        $test();
    } finally {
        foreach ($names as $name) {
            if ($previous[$name] === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $previous[$name]);
            }
        }
    }
}

$base = [
    'INTERNPILIH_ENVIRONMENT' => 'staging',
    'INTERNPILIH_STAGING_ADMIN_DSN' => 'pgsql:host=aws-0-test.pooler.supabase.com;port=5432;dbname=postgres;sslmode=require',
    'INTERNPILIH_STAGING_ADMIN_PASSWORD' => 'offline-placeholder-not-used',
];

$tests = [
    'mismatched project reference' => $base + [
        'INTERNPILIH_STAGING_PROJECT_REF' => 'aaaaaaaaaaaaaaaaaaaa',
        'INTERNPILIH_STAGING_ADMIN_USER' => 'postgres.aaaaaaaaaaaaaaaaaaaa',
    ],
    'mismatched project username' => $base + [
        'INTERNPILIH_STAGING_PROJECT_REF' => INTERNPILIH_EXPECTED_STAGING_PROJECT_REF,
        'INTERNPILIH_STAGING_ADMIN_USER' => 'postgres.aaaaaaaaaaaaaaaaaaaa',
    ],
];

foreach ($tests as $label => $variables) {
    withStagingEnvironment($variables, static function () use ($label): void {
        try {
            stagingPdo('ADMIN');
        } catch (RuntimeException $error) {
            echo 'PASS: ' . $label . " rejected before connection.\n";
            return;
        }
        throw new RuntimeException('Isolation guard unexpectedly allowed: ' . $label);
    });
}

echo "Offline staging isolation checks passed; no network connection was attempted.\n";

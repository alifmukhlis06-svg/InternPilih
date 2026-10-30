<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

try {
    $localPath = __DIR__ . '/../config/supabase.local.php';
    if (!is_file($localPath)) {
        throw new RuntimeException('Local database configuration is unavailable.');
    }
    $local = require $localPath;
    $dsn = (string)($local['database_dsn'] ?? '');
    $dsn = preg_replace('/(?:^|;)port=5432(?=;|$)/i', ';port=6543', $dsn) ?? '';
    if (!preg_match('/(?:^|;)port=6543(?:;|$)/i', $dsn)
        || !preg_match('/(?:^|;)sslmode=require(?:;|$)/i', $dsn)) {
        throw new RuntimeException('Transaction pooler DSN preparation failed.');
    }

    putenv('INTERNPILIH_DATABASE_DSN=' . $dsn);
    putenv('INTERNPILIH_DATABASE_USER=' . (string)($local['database_user'] ?? ''));
    putenv('INTERNPILIH_DATABASE_PASSWORD=' . (string)($local['database_password'] ?? ''));
    putenv('INTERNPILIH_DATABASE_POOL_MODE=transaction');

    require_once __DIR__ . '/../config/db.php';
    $statement = db()->prepare('SELECT ?::integer AS prepared_value');
    $expected = 1;
    $statement->bind_param('i', $expected);
    $statement->execute();
    if ((int)($statement->get_result()->fetch_assoc()['prepared_value'] ?? 0) !== 1) {
        throw new RuntimeException('Emulated prepared statement failed.');
    }

    db()->begin_transaction();
    $result = db()->query('SELECT 1 AS transaction_ok');
    db()->rollback();
    if ((int)($result->fetch_assoc()['transaction_ok'] ?? 0) !== 1) {
        throw new RuntimeException('Read-only transaction probe failed.');
    }

    echo "Supabase Transaction pooler connection successful.\n";
    echo "Client TLS: required by sslmode=require.\n";
    echo "PDO emulated prepare: successful.\n";
    echo "Read-only transaction rollback: successful.\n";
} catch (Throwable $error) {
    $message = strtolower($error->getMessage());
    $safe = match (true) {
        str_contains($message, 'authentication') => 'authentication failed',
        str_contains($message, 'could not translate'),
        str_contains($message, 'getaddrinfo') => 'pooler hostname could not be resolved',
        str_contains($message, 'connection refused') => 'pooler refused the connection',
        str_contains($message, 'timeout') => 'pooler connection timed out',
        str_contains($message, 'ssl') => 'TLS negotiation failed',
        default => 'transaction pooler compatibility failed',
    };
    fwrite(STDERR, 'Connection test failed: ' . $safe . PHP_EOL);
    exit(1);
}

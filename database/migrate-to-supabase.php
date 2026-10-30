<?php
/**
 * Copy the local InternPilih MySQL database into Supabase PostgreSQL.
 *
 * Run with PHP extensions mysqli and pdo_pgsql enabled. The script is a dry
 * plan unless --execute is supplied. The target must be empty unless both
 * --execute and --replace are supplied explicitly.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$execute = in_array('--execute', $argv, true);
$replace = in_array('--replace', $argv, true);
if (!$execute) {
    echo "PLAN ONLY - no Supabase connection or database write was attempted.\n";
    echo "Schema: database/supabase/schema.sql\n";
    echo "Post-import checks: database/supabase/post-import.sql\n";
    echo "Run with --execute only after reviewing and approving both SQL files.\n";
    exit(0);
}

require_once __DIR__ . '/../config/mysql-source.php';

$configFile = __DIR__ . '/../config/supabase.local.php';
if (!is_file($configFile)) {
    throw new RuntimeException('Copy config/supabase.local.example.php to config/supabase.local.php first.');
}
$config = require $configFile;
$password = trim((string)($config['database_password'] ?? ''));
if ($password === '') {
    throw new RuntimeException('Set database_password in config/supabase.local.php before migrating.');
}
if (!extension_loaded('pdo_pgsql')) {
    throw new RuntimeException('The pdo_pgsql PHP extension is required.');
}

$source = mysqlSource();
$source->query("SET time_zone = '+08:00'");
$source->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$source->begin_transaction(
    MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT
);
$sourceTransactionOpen = true;
$target = new PDO(
    (string)$config['database_dsn'],
    (string)($config['database_user'] ?? 'postgres'),
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
// The audited MySQL source runs in Malaysia time (UTC+08). Use the matching
// import-session timezone so MySQL TIMESTAMP and DATETIME strings become the
// same instants when inserted into PostgreSQL timestamptz columns. Normal
// application connections continue to use UTC after migration.
$target->exec("SET TIME ZONE 'Asia/Kuala_Lumpur'");

$schema = file_get_contents(__DIR__ . '/supabase/schema.sql');
if ($schema === false) throw new RuntimeException('Could not read the Supabase schema.');
$schema = preg_replace('/\ABEGIN;\s*/i', '', $schema);
$schema = preg_replace('/\s*COMMIT;\s*\z/i', '', (string)$schema);
if ($schema === '') throw new RuntimeException('The Supabase schema is empty.');

$tables = [
    'users',
    'companies',
    'admins',
    'students',
    'employers',
    'offers',
    'documents',
    'internship_applications',
    'announcements',
    'notifications',
    'activity_logs',
    'placement_change_requests',
    'password_reset_tokens',
    'system_settings',
];
$identityTables = [
    'users', 'companies', 'offers', 'documents', 'internship_applications',
    'announcements', 'notifications', 'activity_logs',
    'placement_change_requests', 'password_reset_tokens',
];
$booleanColumns = ['notifications' => ['is_read']];

$target->beginTransaction();
try {
    // PostgreSQL DDL is transactional. Running schema creation, import,
    // verification, and post-import checks together guarantees that a failure
    // leaves neither partial data nor an incomplete public schema behind.
    $target->exec($schema);

    $existingRows = 0;
    foreach ($tables as $table) {
        $existingRows += (int)$target->query('SELECT COUNT(*) FROM public."' . $table . '"')->fetchColumn();
    }
    if ($existingRows > 0 && !$replace) {
        throw new RuntimeException("Supabase already contains $existingRows InternPilih rows. Rerun with --replace only if those rows may be replaced.");
    }

    if ($replace) {
        $quoted = implode(', ', array_map(static fn(string $table): string => 'public."' . $table . '"', array_reverse($tables)));
        $target->exec('TRUNCATE TABLE ' . $quoted . ' RESTART IDENTITY CASCADE');
    }

    $migrated = [];
    foreach ($tables as $table) {
        $result = $source->query('SELECT * FROM `' . $table . '`');
        if (!$result) throw new RuntimeException("Could not read local table $table: {$source->error}");

        $fields = $result->fetch_fields();
        $columns = array_map(static fn(object $field): string => $field->name, $fields);
        $quotedColumns = implode(', ', array_map(static fn(string $column): string => '"' . $column . '"', $columns));
        $placeholders = implode(', ', array_map(static fn(string $column): string => ':' . $column, $columns));
        $insert = $target->prepare('INSERT INTO public."' . $table . '" (' . $quotedColumns . ') VALUES (' . $placeholders . ')');

        $count = 0;
        while ($row = $result->fetch_assoc()) {
            foreach ($columns as $column) {
                $value = $row[$column];
                if ($value === null) {
                    $insert->bindValue(':' . $column, null, PDO::PARAM_NULL);
                } elseif (in_array($column, $booleanColumns[$table] ?? [], true)) {
                    $insert->bindValue(':' . $column, (bool)$value, PDO::PARAM_BOOL);
                } else {
                    $insert->bindValue(':' . $column, (string)$value, PDO::PARAM_STR);
                }
            }
            $insert->execute();
            $count++;
        }
        $migrated[$table] = $count;
        echo str_pad($table, 30) . $count . " rows\n";
    }

    foreach ($identityTables as $table) {
        $target->exec(
            "SELECT setval(pg_get_serial_sequence('public.$table', 'id'), "
            . "COALESCE((SELECT MAX(id) FROM public.\"$table\"), 1), "
            . "EXISTS (SELECT 1 FROM public.\"$table\"))"
        );
    }

    foreach ($tables as $table) {
        $targetCount = (int)$target->query('SELECT COUNT(*) FROM public."' . $table . '"')->fetchColumn();
        if ($targetCount !== $migrated[$table]) {
            throw new RuntimeException("Verification failed for $table: local {$migrated[$table]}, Supabase $targetCount.");
        }
    }

    $postImport = file_get_contents(__DIR__ . '/supabase/post-import.sql');
    if ($postImport === false) {
        throw new RuntimeException('Could not read the post-import SQL migration.');
    }
    $target->exec($postImport);

    // Close the read-only source snapshot only after every source row has
    // passed target-side verification, then atomically publish the target.
    $source->commit();
    $sourceTransactionOpen = false;
    $target->commit();
    echo "Database migration complete and all table counts match.\n";
} catch (Throwable $error) {
    if ($target->inTransaction()) $target->rollBack();
    if ($sourceTransactionOpen ?? false) $source->rollback();
    throw $error;
}

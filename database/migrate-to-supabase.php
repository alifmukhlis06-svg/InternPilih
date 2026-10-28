<?php
/**
 * Copy the local InternPilih MySQL database into Supabase PostgreSQL.
 *
 * Run with PHP extensions mysqli and pdo_pgsql enabled. The target must be
 * empty unless --replace is supplied explicitly.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/db.php';

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

$replace = in_array('--replace', $argv, true);
$source = db();
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
$target->exec("SET TIME ZONE 'UTC'");

$schema = file_get_contents(__DIR__ . '/supabase/schema.sql');
if ($schema === false) throw new RuntimeException('Could not read the Supabase schema.');
$target->exec($schema);

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

$existingRows = 0;
foreach ($tables as $table) {
    $existingRows += (int)$target->query('SELECT COUNT(*) FROM public."' . $table . '"')->fetchColumn();
}
if ($existingRows > 0 && !$replace) {
    throw new RuntimeException("Supabase already contains $existingRows InternPilih rows. Rerun with --replace only if those rows may be replaced.");
}

$target->beginTransaction();
try {
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

    $target->commit();
    echo "Database migration complete and all table counts match.\n";
} catch (Throwable $error) {
    if ($target->inTransaction()) $target->rollBack();
    throw $error;
}

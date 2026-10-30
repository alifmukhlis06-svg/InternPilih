<?php

declare(strict_types=1);

if (!defined('MYSQLI_ASSOC')) {
    // Transitional compatibility for existing fetch_all(MYSQLI_ASSOC) calls.
    define('MYSQLI_ASSOC', 1);
}

final class DatabaseResult
{
    /** @var array<int, array<string, mixed>> */
    private array $rows;
    private int $position = 0;
    public int $num_rows;

    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(array $rows)
    {
        foreach ($rows as &$row) {
            foreach ($row as &$value) {
                // PDO_PGSQL can expose booleans as PostgreSQL's t/f strings.
                if ($value === 't') {
                    $value = true;
                } elseif ($value === 'f') {
                    $value = false;
                }
            }
            unset($value);
        }
        unset($row);
        $this->rows = $rows;
        $this->num_rows = count($rows);
    }

    /** @return array<string, mixed>|null */
    public function fetch_assoc(): ?array
    {
        return $this->rows[$this->position++] ?? null;
    }

    /** @return array<int, mixed>|null */
    public function fetch_row(): ?array
    {
        $row = $this->fetch_assoc();
        return $row === null ? null : array_values($row);
    }

    /** @return array<int, array<string, mixed>> */
    public function fetch_all(int $mode = MYSQLI_ASSOC): array
    {
        return $this->rows;
    }
}

final class DatabaseStatement
{
    private PDOStatement $statement;
    private DatabaseConnection $connection;
    /** @var array<int, mixed> */
    private array $parameters = [];
    private ?DatabaseResult $result = null;
    private bool $returnsIdentity;
    public int $affected_rows = 0;

    public function __construct(
        DatabaseConnection $connection,
        PDOStatement $statement,
        bool $returnsIdentity
    ) {
        $this->connection = $connection;
        $this->statement = $statement;
        $this->returnsIdentity = $returnsIdentity;
    }

    public function bind_param(string $types, mixed &...$values): bool
    {
        // mysqli binds by reference, but this PDO adapter executes from a value
        // array. Keep value copies so the PDO driver cannot coerce the caller's
        // variables (for example, turning an integer ID into a numeric string).
        $this->parameters = array_map(
            static fn(mixed $value): mixed => $value,
            $values
        );
        return true;
    }

    public function execute(?array $parameters = null): bool
    {
        $values = $parameters ?? $this->parameters;
        $ok = $this->statement->execute(array_values($values));
        $this->affected_rows = $this->statement->rowCount();

        $rows = $this->statement->columnCount() > 0
            ? $this->statement->fetchAll(PDO::FETCH_ASSOC)
            : [];
        $this->result = new DatabaseResult($rows);

        if ($this->returnsIdentity && isset($rows[0]['id'])) {
            $this->connection->insert_id = (int)$rows[0]['id'];
        }

        return $ok;
    }

    public function get_result(): DatabaseResult
    {
        return $this->result ?? new DatabaseResult([]);
    }
}

final class DatabaseConnection
{
    private PDO $pdo;
    public int $insert_id = 0;
    public int $affected_rows = 0;
    public string $error = '';

    private const IDENTITY_TABLES = [
        'users',
        'companies',
        'offers',
        'documents',
        'internship_applications',
        'announcements',
        'notifications',
        'activity_logs',
        'placement_change_requests',
        'password_reset_tokens',
    ];

    public function __construct(array $config)
    {
        if (!extension_loaded('pdo_pgsql')) {
            throw new RuntimeException('The pdo_pgsql PHP extension is required.');
        }

        $transactionPool = ($config['pool_mode'] ?? 'session') === 'transaction';
        $this->pdo = new PDO(
            $config['dsn'],
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Supabase Transaction pooler does not support native prepared
                // statements. Session mode keeps native prepares for Laragon.
                PDO::ATTR_EMULATE_PREPARES => $transactionPool,
            ]
        );
        if (!$transactionPool) {
            $this->pdo->exec("SET TIME ZONE 'UTC'");
        }
    }

    public function prepare(string $sql): DatabaseStatement
    {
        [$sql, $returnsIdentity] = $this->withIdentityReturn($sql);
        return new DatabaseStatement($this, $this->pdo->prepare($sql), $returnsIdentity);
    }

    public function query(string $sql): DatabaseResult|bool
    {
        $statement = $this->pdo->query($sql);
        if ($statement->columnCount() === 0) {
            $this->affected_rows = $statement->rowCount();
            return true;
        }

        return new DatabaseResult($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function exec(string $sql): int|false
    {
        return $this->pdo->exec($sql);
    }

    public function begin_transaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollback(): bool
    {
        return $this->pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function real_escape_string(string $value): string
    {
        return substr($this->pdo->quote($value), 1, -1);
    }

    /** @return array{0:string,1:bool} */
    private function withIdentityReturn(string $sql): array
    {
        if (stripos($sql, 'RETURNING ') !== false) {
            return [$sql, true];
        }

        if (!preg_match('/^\s*INSERT\s+INTO\s+(?:public\.)?([a-z_][a-z0-9_]*)\b/i', $sql, $match)) {
            return [$sql, false];
        }

        if (!in_array(strtolower($match[1]), self::IDENTITY_TABLES, true)) {
            return [$sql, false];
        }

        return [rtrim($sql, " \t\r\n;") . ' RETURNING id', true];
    }
}

function db(): DatabaseConnection
{
    static $connection = null;

    if (!$connection instanceof DatabaseConnection) {
        $connection = new DatabaseConnection(require __DIR__ . '/database.php');
    }

    return $connection;
}

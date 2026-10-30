<?php

declare(strict_types=1);

final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public function __construct(private DatabaseConnection $database, private int $lifetimeSeconds)
    {
        $this->lifetimeSeconds = max(300, $this->lifetimeSeconds);
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $statement = $this->database->prepare("
            SELECT session_data
            FROM app_sessions
            WHERE session_key = ? AND expires_at > CURRENT_TIMESTAMP
            LIMIT 1
        ");
        $key = $this->key($id);
        $statement->bind_param('s', $key);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        return $row ? (string)$row['session_data'] : '';
    }

    public function write(string $id, string $data): bool
    {
        $statement = $this->database->prepare("
            INSERT INTO app_sessions (session_key, session_data, expires_at)
            VALUES (?, ?, CURRENT_TIMESTAMP + (? * INTERVAL '1 second'))
            ON CONFLICT (session_key) DO UPDATE SET
                session_data = EXCLUDED.session_data,
                expires_at = EXCLUDED.expires_at,
                updated_at = CURRENT_TIMESTAMP
        ");
        $key = $this->key($id);
        $lifetime = $this->lifetimeSeconds;
        $statement->bind_param('ssi', $key, $data, $lifetime);
        return $statement->execute();
    }

    public function destroy(string $id): bool
    {
        $statement = $this->database->prepare('DELETE FROM app_sessions WHERE session_key = ?');
        $key = $this->key($id);
        $statement->bind_param('s', $key);
        return $statement->execute();
    }

    public function gc(int $max_lifetime): int|false
    {
        $statement = $this->database->prepare('DELETE FROM app_sessions WHERE expires_at <= CURRENT_TIMESTAMP');
        $statement->execute();
        return $statement->affected_rows;
    }

    public function validateId(string $id): bool
    {
        $statement = $this->database->prepare("
            SELECT 1
            FROM app_sessions
            WHERE session_key = ? AND expires_at > CURRENT_TIMESTAMP
            LIMIT 1
        ");
        $key = $this->key($id);
        $statement->bind_param('s', $key);
        $statement->execute();
        return (bool)$statement->get_result()->fetch_row();
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $statement = $this->database->prepare("
            UPDATE app_sessions
            SET expires_at = CURRENT_TIMESTAMP + (? * INTERVAL '1 second'),
                updated_at = CURRENT_TIMESTAMP
            WHERE session_key = ?
        ");
        $lifetime = $this->lifetimeSeconds;
        $key = $this->key($id);
        $statement->bind_param('is', $lifetime, $key);
        $statement->execute();
        return $statement->affected_rows === 1;
    }

    private function key(string $id): string
    {
        return hash('sha256', $id);
    }
}

<?php

class DatabaseConnection {
    private $host;
    private $database;
    private $username;
    private $password;
    private $conn;

    public function __construct() {
        $config = require __DIR__ . '/../config/database.php';
        $this->host = $config['host'];
        $this->database = $config['database'];
        $this->username = $config['username'];
        $this->password = $config['password'];
        $this->connect();
    }

    public function connect() {
        try {
            $this->conn = new mysqli(
                $this->host,
                $this->username,
                $this->password,
                $this->database
            );

            if ($this->conn->connect_error) {
                throw new Exception("Connection Error: " . $this->conn->connect_error);
            }

            $this->conn->set_charset("utf8mb4");
        } catch (Exception $e) {
            die("Database Connection Failed: " . $e->getMessage());
        }
    }

    public function getConnection() {
        return $this->conn;
    }

    public function query($sql) {
        $result = $this->conn->query($sql);
        if (!$result) {
            throw new Exception("Query Error: " . $this->conn->error);
        }
        return $result;
    }

    public function execute($sql) {
        if (!$this->conn->query($sql)) {
            throw new Exception("Execute Error: " . $this->conn->error);
        }
        return true;
    }

    public function close() {
        $this->conn->close();
    }
}
?>

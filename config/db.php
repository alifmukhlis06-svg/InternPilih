<?php

function db(): mysqli {
    static $conn = null;

    if ($conn === null) {
        $config = require __DIR__ . '/database.php';

        $conn = new mysqli(
            $config['host'],
            $config['username'],
            $config['password'],
            $config['database'],
            $config['port'] ?? 3306
        );

        if ($conn->connect_error) {
            http_response_code(500);
            header('Content-Type: application/json');
            die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
        }

        $conn->set_charset($config['charset'] ?? 'utf8mb4');
    }

    return $conn;
}

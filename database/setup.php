<?php
/**
 * Database Setup Script
 * Run this once to create the database and all tables
 */

$host = 'localhost';
$username = 'root';
$password = '';

try {
    // Connect to MySQL without specifying a database
    $conn = new mysqli($host, $username, $password);

    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Create database if it doesn't exist
    $createDB = "CREATE DATABASE IF NOT EXISTS internpilih DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    if (!$conn->query($createDB)) {
        throw new Exception("Create database error: " . $conn->error);
    }
    echo "✓ Database 'internpilih' created/checked successfully\n";

    // Select the database
    $conn->select_db('internpilih');

    // Read and execute the schema file
    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new Exception("Schema file not found: " . $schemaFile);
    }

    $sql = file_get_contents($schemaFile);
    
    // Split and execute multiple queries
    $queries = array_filter(array_map('trim', preg_split('/;[\r\n]/', $sql)));
    
    foreach ($queries as $query) {
        if (!empty($query)) {
            if (!$conn->query($query)) {
                throw new Exception("Query error: " . $conn->error . "\n" . substr($query, 0, 100));
            }
        }
    }

    echo "✓ All tables created successfully!\n";
    echo "\nCreated tables:\n";
    echo "  - users\n";
    echo "  - students\n";
    echo "  - employers\n";
    echo "  - admins\n";
    echo "  - companies\n";
    echo "  - offers\n";
    echo "  - internship_applications\n";
    echo "  - documents\n";
    echo "  - notifications\n";
    echo "  - announcements\n";
    echo "  - activity_logs\n";
    echo "  - password_reset_tokens\n";
    echo "  - placement_change_requests\n";
    echo "  - system_settings\n";

    $conn->close();

} catch (Exception $e) {
    die("ERROR: " . $e->getMessage() . "\n");
}
?>

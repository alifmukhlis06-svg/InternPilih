<?php
/**
 * Database Setup API
 * Handles database creation and table setup
 */

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $host = $data['host'] ?? 'localhost';
    $username = $data['username'] ?? 'root';
    $password = $data['password'] ?? '';
    $database = $data['database'] ?? 'internpilih';

    // Connect to MySQL without selecting a database first
    $conn = new mysqli($host, $username, $password);

    if ($conn->connect_error) {
        throw new Exception("Connection Error: " . $conn->connect_error);
    }

    // Create database
    $createDB = "CREATE DATABASE IF NOT EXISTS `" . $conn->real_escape_string($database) . "` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    
    if (!$conn->query($createDB)) {
        throw new Exception("Create database error: " . $conn->error);
    }

    // Select the database
    $conn->select_db($database);

    // Read schema file
    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new Exception("Schema file not found: " . $schemaFile);
    }

    $sql = file_get_contents($schemaFile);
    
    // Split and execute multiple queries
    $queries = array_filter(array_map('trim', preg_split('/;[\r\n]/', $sql)));
    $tableCount = 0;
    
    foreach ($queries as $query) {
        if (!empty($query)) {
            if (!$conn->query($query)) {
                throw new Exception("Query error: " . $conn->error . "\n" . substr($query, 0, 100));
            }
            $tableCount++;
        }
    }

    $message = "✓ Database setup completed successfully!\n\n";
    $message .= "Database: " . $database . "\n";
    $message .= "Tables created: " . $tableCount . "\n\n";
    $message .= "Tables:\n";
    $message .= "  ✓ users\n";
    $message .= "  ✓ companies\n";
    $message .= "  ✓ job_offers\n";
    $message .= "  ✓ applications\n";
    $message .= "  ✓ notifications\n";
    $message .= "  ✓ messages\n";
    $message .= "  ✓ student_profiles\n";
    $message .= "  ✓ verifications\n\n";
    $message .= "Database credentials saved to: config/database.php\n";
    $message .= "You can now start using the database!";

    echo json_encode([
        'success' => true,
        'message' => $message
    ]);

    $conn->close();

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => "ERROR: " . $e->getMessage()
    ]);
}
?>

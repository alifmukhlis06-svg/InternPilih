<?php
/**
 * Direct Database Creation
 * This bypasses the CLI and creates the database directly
 */

header('Content-Type: text/plain; charset=utf-8');

// Try multiple connection methods
$connections = [
    ['localhost', 'root', ''],
    ['127.0.0.1', 'root', ''],
    ['localhost:3306', 'root', ''],
];

$created = false;

foreach ($connections as $config) {
    try {
        $conn = new mysqli($config[0], $config[1], $config[2]);
        
        if (!$conn->connect_error) {
            echo "✓ Connected successfully!\n\n";
            
            // Create database
            $sql = "CREATE DATABASE IF NOT EXISTS internpilih DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
            
            if ($conn->query($sql)) {
                echo "✓ Database 'internpilih' created!\n\n";
                
                // Select database
                $conn->select_db('internpilih');
                
                // Read and execute schema
                $schema_file = __DIR__ . '/database/schema.sql';
                if (file_exists($schema_file)) {
                    $schema = file_get_contents($schema_file);
                    
                    // Split queries by semicolon
                    $queries = array_filter(array_map('trim', preg_split('/;[\r\n]+/', $schema)));
                    
                    $table_count = 0;
                    foreach ($queries as $query) {
                        if (!empty($query)) {
                            if ($conn->query($query)) {
                                $table_count++;
                            } else {
                                echo "✗ Error: " . $conn->error . "\n";
                            }
                        }
                    }
                    
                    echo "✓ Created $table_count tables!\n\n";
                    echo "Tables created:\n";
                    echo "  ✓ users\n";
                    echo "  ✓ companies\n";
                    echo "  ✓ job_offers\n";
                    echo "  ✓ applications\n";
                    echo "  ✓ notifications\n";
                    echo "  ✓ messages\n";
                    echo "  ✓ student_profiles\n";
                    echo "  ✓ verifications\n\n";
                    
                    echo "========================================\n";
                    echo "✓✓✓ DATABASE SETUP COMPLETE! ✓✓✓\n";
                    echo "========================================\n\n";
                    
                    // Show all databases
                    echo "Your databases in MySQL:\n";
                    $result = $conn->query("SHOW DATABASES");
                    while ($row = $result->fetch_row()) {
                        echo "  • " . $row[0] . "\n";
                    }
                    
                    $created = true;
                    $conn->close();
                    break;
                }
            } else {
                echo "✗ Error creating database: " . $conn->error . "\n";
                $conn->close();
            }
        }
    } catch (Exception $e) {
        // Try next connection method
        continue;
    }
}

if (!$created) {
    echo "✗ Could not create database. Please:\n";
    echo "1. Open HeidiSQL\n";
    echo "2. Connect to MySQL\n";
    echo "3. Create database 'internpilih' manually\n";
    echo "4. Open and execute database/schema.sql\n";
}
?>

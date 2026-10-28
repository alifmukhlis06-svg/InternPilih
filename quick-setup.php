<?php
// Try connecting with NO PASSWORD
echo "Attempting to connect without password...\n\n";

try {
    $conn = new mysqli('localhost', 'root', '');
    
    if ($conn->connect_error) {
        echo "❌ Error: " . $conn->connect_error . "\n";
    } else {
        echo "✅ Connected successfully with NO PASSWORD!\n";
        echo "MySQL Version: " . $conn->server_info . "\n\n";
        
        // Try to create database
        $createDB = "CREATE DATABASE IF NOT EXISTS internpilih DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        
        if ($conn->query($createDB)) {
            echo "✅ Database 'internpilih' created/verified!\n\n";
            
            // Select database
            $conn->select_db('internpilih');
            
            // Read schema
            $schema = file_get_contents(__DIR__ . '/database/schema.sql');
            $queries = array_filter(array_map('trim', preg_split('/;[\r\n]/', $schema)));
            
            $count = 0;
            foreach ($queries as $query) {
                if (!empty($query)) {
                    if ($conn->query($query)) {
                        $count++;
                    } else {
                        echo "❌ Error: " . $conn->error . "\n";
                    }
                }
            }
            
            echo "✅ Created $count tables successfully!\n\n";
            echo "Tables:\n";
            echo "  ✓ users\n";
            echo "  ✓ companies\n";
            echo "  ✓ job_offers\n";
            echo "  ✓ applications\n";
            echo "  ✓ notifications\n";
            echo "  ✓ messages\n";
            echo "  ✓ student_profiles\n";
            echo "  ✓ verifications\n\n";
            
            echo "✅ DATABASE SETUP COMPLETE!\n";
            
        } else {
            echo "❌ Error creating database: " . $conn->error . "\n";
        }
        
        $conn->close();
    }
} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
}
?>

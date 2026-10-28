<?php
// Try different connection methods
echo "Testing different MySQL connection methods:\n\n";

// Method 1: Try with empty password
echo "1. Testing with empty password:\n";
try {
    $conn = new mysqli('localhost', 'root', '');
    if ($conn->connect_error) {
        echo "   ✗ Error: " . $conn->connect_error . "\n";
    } else {
        echo "   ✓ Connected successfully with empty password!\n";
        echo "   Server: " . $conn->server_info . "\n";
        $conn->close();
    }
} catch (Exception $e) {
    echo "   ✗ Exception: " . $e->getMessage() . "\n";
}

// Method 2: Try with port 3306
echo "\n2. Testing with port 3306 and empty password:\n";
try {
    $conn = new mysqli('localhost:3306', 'root', '');
    if ($conn->connect_error) {
        echo "   ✗ Error: " . $conn->connect_error . "\n";
    } else {
        echo "   ✓ Connected successfully!\n";
        $conn->close();
    }
} catch (Exception $e) {
    echo "   ✗ Exception: " . $e->getMessage() . "\n";
}

// Method 3: Try with 127.0.0.1
echo "\n3. Testing with 127.0.0.1 and empty password:\n";
try {
    $conn = new mysqli('127.0.0.1', 'root', '');
    if ($conn->connect_error) {
        echo "   ✗ Error: " . $conn->connect_error . "\n";
    } else {
        echo "   ✓ Connected successfully!\n";
        $conn->close();
    }
} catch (Exception $e) {
    echo "   ✗ Exception: " . $e->getMessage() . "\n";
}

// Method 4: Check if user provided password might be for a different reason
echo "\n4. Checking MySQL info via command line:\n";
$output = shell_exec('cmd /c "C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysql.exe" --version 2>&1');
echo "   " . trim($output) . "\n";

// Method 5: Try via PDO
echo "\n5. Testing with PDO (empty password):\n";
try {
    $pdo = new PDO('mysql:host=localhost', 'root', '');
    echo "   ✓ PDO Connected successfully!\n";
    
    // Try to create database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS internpilih DEFAULT CHARACTER SET utf8mb4");
    echo "   ✓ Database 'internpilih' created/verified!\n";
    
    // List tables
    $stmt = $pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='internpilih'");
    $tables = $stmt->fetchAll();
    echo "   Current tables in database: " . count($tables) . "\n";
    
} catch (PDOException $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
}
?>

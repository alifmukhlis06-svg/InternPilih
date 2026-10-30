<?php
// Test MySQLi
echo "PHP Version: " . phpversion() . "\n\n";

echo "MySQLi Extension: ";
if (extension_loaded('mysqli')) {
    echo "✓ Loaded\n";
} else {
    echo "✗ NOT LOADED\n";
}

echo "MySQL Extension: ";
if (extension_loaded('mysql')) {
    echo "✓ Loaded\n";
} else {
    echo "✗ NOT LOADED\n";
}

echo "PDO Extension: ";
if (extension_loaded('pdo')) {
    echo "✓ Loaded\n";
} else {
    echo "✗ NOT LOADED\n";
}

echo "\n--- Testing MySQL Connection ---\n";

try {
    $legacyPassword = getenv('INTERNPILIH_MYSQL_PASSWORD') ?: '';
    $conn = new mysqli('localhost', 'root', $legacyPassword);
    if ($conn->connect_error) {
        echo "❌ Connection Error: " . $conn->connect_error . "\n";
    } else {
        echo "✓ Connected successfully!\n";
        echo "Server Info: " . $conn->server_info . "\n";
        $conn->close();
    }
} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
}
?>

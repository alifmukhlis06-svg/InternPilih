<?php
/**
 * Example: How to use the database connection
 * Include this at the top of your files that need database access
 */

require_once __DIR__ . '/config/DatabaseConnection.php';

// Create a new database connection
$db = new DatabaseConnection();
$conn = $db->getConnection();

// Example 1: Simple query to fetch data
$result = $conn->query("SELECT * FROM users WHERE role = 'student'");
while ($row = $result->fetch_assoc()) {
    echo "Student: " . $row['name'] . "\n";
}

// Example 2: Prepared statements (safer for preventing SQL injection)
$email = "student@example.com";
$role = "student";

$stmt = $conn->prepare("SELECT * FROM users WHERE email = ? AND role = ?");
$stmt->bind_param("ss", $email, $role);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Example 3: Insert data
$stmt = $conn->prepare("INSERT INTO users (name, email, password, role, phone) VALUES (?, ?, ?, ?, ?)");
$name = "John Doe";
$password = password_hash("password123", PASSWORD_BCRYPT);
$phone = "081234567890";
$stmt->bind_param("sssss", $name, $email, $password, $role, $phone);
if ($stmt->execute()) {
    echo "User created successfully with ID: " . $conn->insert_id . "\n";
}

// Close the connection when done
$db->close();
?>

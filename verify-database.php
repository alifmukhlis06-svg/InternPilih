<?php
/**
 * Database Verification Script
 * Check if database and tables are properly set up
 */

$config = require __DIR__ . '/config/database.php';

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Database Verification</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        .status { padding: 15px; margin: 10px 0; border-radius: 5px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #667eea; color: white; }
        tr:hover { background: #f9f9f9; }
        .check { font-weight: bold; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🔍 Database Verification</h1>";

try {
    // Test connection
    $conn = new mysqli(
        $config['host'],
        $config['username'],
        $config['password']
    );

    if ($conn->connect_error) {
        echo "<div class='status error'>";
        echo "❌ <strong>Connection Failed</strong><br>";
        echo "Error: " . $conn->connect_error;
        echo "</div>";
        die("</div></body></html>");
    }

    echo "<div class='status success'>✓ Connected to MySQL successfully</div>";

    // Check if database exists
    $result = $conn->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '" . $config['database'] . "'");
    
    if ($result->num_rows == 0) {
        echo "<div class='status error'>❌ Database '" . $config['database'] . "' does not exist</div>";
        echo "<p><strong>To create it, visit:</strong> <a href='setup-database.html'>setup-database.html</a></p>";
    } else {
        echo "<div class='status success'>✓ Database '" . $config['database'] . "' exists</div>";
        
        // Select database
        $conn->select_db($config['database']);
        
        // Get all tables
        $result = $conn->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '" . $config['database'] . "'");
        
        $tables = [];
        $expected_tables = ['users', 'companies', 'job_offers', 'applications', 'notifications', 'messages', 'student_profiles', 'verifications'];
        
        while ($row = $result->fetch_assoc()) {
            $tables[] = $row['TABLE_NAME'];
        }
        
        echo "<h2>📊 Tables Status</h2>";
        echo "<table>";
        echo "<tr><th>Table Name</th><th>Status</th><th>Row Count</th></tr>";
        
        $all_found = true;
        foreach ($expected_tables as $table) {
            $status = in_array($table, $tables) ? '✓' : '✗';
            $color = in_array($table, $tables) ? '#28a745' : '#dc3545';
            
            if (!in_array($table, $tables)) {
                $all_found = false;
                echo "<tr><td><strong>$table</strong></td><td style='color: $color;'>$status Missing</td><td>-</td></tr>";
            } else {
                $count_result = $conn->query("SELECT COUNT(*) as cnt FROM `$table`");
                $count_row = $count_result->fetch_assoc();
                $count = $count_row['cnt'];
                echo "<tr><td><strong>$table</strong></td><td style='color: $color;'>$status Created</td><td>$count rows</td></tr>";
            }
        }
        echo "</table>";
        
        if ($all_found) {
            echo "<div class='status success' style='margin-top: 20px;'>";
            echo "<strong>✅ All tables created successfully!</strong><br>";
            echo "Your database is ready to use.";
            echo "</div>";
        } else {
            echo "<div class='status error' style='margin-top: 20px;'>";
            echo "<strong>❌ Some tables are missing</strong><br>";
            echo "Please run the setup: <a href='setup-database.html'>setup-database.html</a>";
            echo "</div>";
        }
    }
    
    echo "<h2 style='margin-top: 30px;'>📋 Configuration</h2>";
    echo "<div class='info'>";
    echo "<strong>Host:</strong> " . $config['host'] . "<br>";
    echo "<strong>Database:</strong> " . $config['database'] . "<br>";
    echo "<strong>Username:</strong> " . $config['username'] . "<br>";
    echo "<strong>Charset:</strong> " . $config['charset'] . "<br>";
    echo "</div>";
    
    $conn->close();

} catch (Exception $e) {
    echo "<div class='status error'>";
    echo "❌ <strong>Error:</strong> " . $e->getMessage();
    echo "</div>";
}

echo "    </div>
</body>
</html>";
?>

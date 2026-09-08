<?php
// ═══════════════════════════════════════════════════════════════════
// DATABASE CONNECTION TEST
// ═══════════════════════════════════════════════════════════════════

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Database Connection Test</h1>";
echo "<hr>";

// Test 1: Check if config file exists
echo "<h2>1. Config File Check</h2>";
if (file_exists(__DIR__ . '/includes/config.php')) {
    echo "✅ Config file exists<br>";
    require_once __DIR__ . '/includes/config.php';
    echo "✅ Config file loaded<br>";
} else {
    echo "❌ Config file NOT found<br>";
    die();
}

// Test 2: Check database constants
echo "<hr><h2>2. Database Configuration</h2>";
echo "DB_HOST: " . DB_HOST . "<br>";
echo "DB_NAME: " . DB_NAME . "<br>";
echo "DB_USER: " . DB_USER . "<br>";
echo "DB_PASS: " . (empty(DB_PASS) ? '(empty)' : '(set)') . "<br>";

// Test 3: Try to connect to MySQL
echo "<hr><h2>3. MySQL Connection Test</h2>";
try {
    $dsn = "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    echo "✅ Connected to MySQL server<br>";
    
    // Test 4: Check if database exists
    echo "<hr><h2>4. Database Existence Check</h2>";
    $stmt = $pdo->query("SHOW DATABASES LIKE '" . DB_NAME . "'");
    $dbExists = $stmt->fetch();
    
    if ($dbExists) {
        echo "✅ Database '" . DB_NAME . "' exists<br>";
        
        // Test 5: Connect to specific database
        echo "<hr><h2>5. Connect to Database</h2>";
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        echo "✅ Connected to database '" . DB_NAME . "'<br>";
        
        // Test 6: Check tables
        echo "<hr><h2>6. Tables Check</h2>";
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (count($tables) > 0) {
            echo "✅ Found " . count($tables) . " tables:<br>";
            echo "<ul>";
            foreach ($tables as $table) {
                echo "<li>" . $table . "</li>";
            }
            echo "</ul>";
            
            // Test 7: Check admin_users table
            echo "<hr><h2>7. Admin Users Check</h2>";
            if (in_array('admin_users', $tables)) {
                $stmt = $pdo->query("SELECT username FROM admin_users");
                $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
                echo "✅ Admin users table exists<br>";
                echo "Found " . count($admins) . " admin(s): " . implode(', ', $admins) . "<br>";
            } else {
                echo "❌ admin_users table NOT found<br>";
            }
            
            // Test 8: Check controllers table
            echo "<hr><h2>8. Controllers Check</h2>";
            if (in_array('controllers', $tables)) {
                $stmt = $pdo->query("SELECT username FROM controllers");
                $controllers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                echo "✅ Controllers table exists<br>";
                echo "Found " . count($controllers) . " controller(s): " . implode(', ', $controllers) . "<br>";
            } else {
                echo "❌ controllers table NOT found<br>";
            }
            
        } else {
            echo "❌ No tables found in database<br>";
            echo "<strong>ACTION REQUIRED:</strong> Import database.sql file<br>";
        }
        
    } else {
        echo "❌ Database '" . DB_NAME . "' does NOT exist<br>";
        echo "<hr><h2>Available Databases:</h2>";
        $stmt = $pdo->query("SHOW DATABASES");
        $databases = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "<ul>";
        foreach ($databases as $db) {
            echo "<li>" . $db . "</li>";
        }
        echo "</ul>";
        echo "<strong>ACTION REQUIRED:</strong> Create database and import database.sql<br>";
    }
    
} catch (PDOException $e) {
    echo "❌ Connection failed: " . $e->getMessage() . "<br>";
    echo "<hr><h2>Possible Solutions:</h2>";
    echo "<ul>";
    echo "<li>Check if MySQL is running</li>";
    echo "<li>Verify DB_USER and DB_PASS in config.php</li>";
    echo "<li>Check if user has proper permissions</li>";
    echo "</ul>";
}

echo "<hr>";
echo "<h2>Summary</h2>";
echo "<p>If you see ✅ for all tests, your database is ready!</p>";
echo "<p>If you see ❌, follow the ACTION REQUIRED steps above.</p>";
echo "<hr>";
echo "<p><strong>IMPORTANT:</strong> Delete this file after testing for security!</p>";
?>

<?php
// ═══════════════════════════════════════════════════════════════════
// PASSWORD RESET SCRIPT
// ═══════════════════════════════════════════════════════════════════
// This script will reset the default passwords for admin and controller
// IMPORTANT: Delete this file after use!
// ═══════════════════════════════════════════════════════════════════

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Password Reset Script</h1>";
echo "<hr>";

// Load config
require_once __DIR__ . '/includes/config.php';

echo "<h2>1. Database Connection</h2>";
try {
    $db = db();
    echo "✅ Connected to database<br>";
} catch (Exception $e) {
    echo "❌ Connection failed: " . $e->getMessage() . "<br>";
    die();
}

echo "<hr><h2>2. Resetting Passwords</h2>";

// Reset admin password
$adminPassword = 'CHANGE_ME';
$adminHash = password_hash($adminPassword, PASSWORD_DEFAULT);

echo "<strong>Admin User:</strong><br>";
echo "Username: admin<br>";
echo "New Password: $adminPassword<br>";
echo "Hash: $adminHash<br>";

try {
    $db->update('admin_users', ['password' => $adminHash], "username = 'admin'");
    echo "✅ Admin password updated<br><br>";
} catch (Exception $e) {
    echo "❌ Failed to update admin password: " . $e->getMessage() . "<br><br>";
}

// Reset controller password
$controllerPassword = 'CHANGE_ME';
$controllerHash = password_hash($controllerPassword, PASSWORD_DEFAULT);

echo "<strong>Controller User:</strong><br>";
echo "Username: madeya<br>";
echo "New Password: $controllerPassword<br>";
echo "Hash: $controllerHash<br>";

try {
    $db->update('controllers', ['password' => $controllerHash], "username = 'madeya'");
    echo "✅ Controller password updated<br>";
} catch (Exception $e) {
    echo "❌ Failed to update controller password: " . $e->getMessage() . "<br>";
}

echo "<hr><h2>3. Verification</h2>";

// Verify admin
$admin = db()->fetchOne("SELECT username, password FROM admin_users WHERE username = 'admin'");
if ($admin) {
    echo "<strong>Admin:</strong><br>";
    echo "Username: " . $admin['username'] . "<br>";
    echo "Password Hash: " . substr($admin['password'], 0, 30) . "...<br>";
    
    if (password_verify('CHANGE_ME', $admin['password'])) {
        echo "✅ Password verification: SUCCESS<br><br>";
    } else {
        echo "❌ Password verification: FAILED<br><br>";
    }
}

// Verify controller
$controller = db()->fetchOne("SELECT username, password FROM controllers WHERE username = 'madeya'");
if ($controller) {
    echo "<strong>Controller:</strong><br>";
    echo "Username: " . $controller['username'] . "<br>";
    echo "Password Hash: " . substr($controller['password'], 0, 30) . "...<br>";
    
    if (password_verify('CHANGE_ME', $controller['password'])) {
        echo "✅ Password verification: SUCCESS<br>";
    } else {
        echo "❌ Password verification: FAILED<br>";
    }
}

echo "<hr><h2>4. Login Credentials</h2>";
echo "<div style='background: #f0f0f0; padding: 20px; border-radius: 5px;'>";
echo "<h3>Controller Panel</h3>";
echo "<strong>URL:</strong> <a href='controller.php'>controller.php</a><br>";
echo "<strong>Username:</strong> madeya<br>";
echo "<strong>Password:</strong> CHANGE_ME<br><br>";

echo "<h3>Admin Panel</h3>";
echo "<strong>URL:</strong> <a href='admin/'>admin/</a><br>";
echo "<strong>Username:</strong> admin<br>";
echo "<strong>Password:</strong> CHANGE_ME<br>";
echo "</div>";

echo "<hr><h2>5. Next Steps</h2>";
echo "<ol>";
echo "<li>Try logging in with the credentials above</li>";
echo "<li>If login works, <strong>DELETE THIS FILE IMMEDIATELY!</strong></li>";
echo "<li>Change the default passwords after first login</li>";
echo "<li>Delete test-db-connection.php if you haven't already</li>";
echo "</ol>";

echo "<hr>";
echo "<div style='background: #ffebee; padding: 15px; border-left: 4px solid #f44336;'>";
echo "<strong>⚠️ SECURITY WARNING:</strong><br>";
echo "This file contains sensitive information and can reset passwords!<br>";
echo "<strong>DELETE THIS FILE (reset-passwords.php) IMMEDIATELY AFTER USE!</strong>";
echo "</div>";

?>

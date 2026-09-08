<?php
// ═══════════════════════════════════════════════════════════════════
// ETEL MADEYA - SECURITY FUNCTIONS
// ═══════════════════════════════════════════════════════════════════

/**
 * Rate limiting for login attempts
 */
function checkRateLimit($identifier, $maxAttempts = 5, $timeWindow = 900) {
    $key = 'rate_limit_' . md5($identifier);
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'first_attempt' => time()];
    }
    
    $data = $_SESSION[$key];
    
    // Reset if time window has passed
    if (time() - $data['first_attempt'] > $timeWindow) {
        $_SESSION[$key] = ['count' => 1, 'first_attempt' => time()];
        return true;
    }
    
    // Check if limit exceeded
    if ($data['count'] >= $maxAttempts) {
        return false;
    }
    
    // Increment counter
    $_SESSION[$key]['count']++;
    return true;
}

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Sanitize input for SQL (additional layer, PDO handles this)
 */
function sanitizeSQL($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate plate number format
 */
function validatePlateNumber($plate) {
    // Ethiopian plate format: AA-3-12345
    return preg_match('/^[A-Z]{2,3}-\d{1}-\d{5}$/', $plate);
}

/**
 * Validate phone number
 */
function validatePhone($phone) {
    // Ethiopian phone format: +251-XXX-XXX-XXX or 09XX-XXX-XXX
    return preg_match('/^(\+251|0)[79]\d{8}$/', str_replace(['-', ' '], '', $phone));
}

/**
 * Validate email
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Hash password securely
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Verify password
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Log security event
 */
function logSecurityEvent($event, $details = '') {
    $logFile = __DIR__ . '/../logs/security.log';
    $logDir = dirname($logFile);
    
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $ip = getClientIP();
    $message = "[$timestamp] [$ip] $event";
    
    if ($details) {
        $message .= " - $details";
    }
    
    $message .= PHP_EOL;
    
    file_put_contents($logFile, $message, FILE_APPEND);
}

/**
 * Check if user is authenticated (controller)
 */
function isControllerAuthenticated() {
    return isset($_SESSION['controller_id']);
}

/**
 * Check if admin is authenticated
 */
function isAdminAuthenticated() {
    return isset($_SESSION['admin_id']);
}

/**
 * Require controller authentication
 */
function requireControllerAuth() {
    if (!isControllerAuthenticated()) {
        jsonResponse(['success' => false, 'message' => 'Authentication required'], 401);
    }
}

/**
 * Require admin authentication
 */
function requireAdminAuth() {
    if (!isAdminAuthenticated()) {
        jsonResponse(['success' => false, 'message' => 'Admin authentication required'], 401);
    }
}

/**
 * Sanitize filename for uploads
 */
function sanitizeFilename($filename) {
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
    return substr($filename, 0, 255);
}

/**
 * Generate random token
 */
function generateToken($length = 8) {
    return strtoupper(substr(bin2hex(random_bytes($length)), 0, $length));
}

/**
 * XSS protection
 */
function xssClean($data) {
    if (is_array($data)) {
        return array_map('xssClean', $data);
    }
    
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

/**
 * Check for SQL injection patterns
 */
function detectSQLInjection($input) {
    $patterns = [
        '/(\bUNION\b.*\bSELECT\b)/i',
        '/(\bSELECT\b.*\bFROM\b)/i',
        '/(\bINSERT\b.*\bINTO\b)/i',
        '/(\bDELETE\b.*\bFROM\b)/i',
        '/(\bDROP\b.*\bTABLE\b)/i',
        '/(\bUPDATE\b.*\bSET\b)/i',
        '/(--|#|\/\*|\*\/)/i'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $input)) {
            logSecurityEvent('SQL_INJECTION_ATTEMPT', $input);
            return true;
        }
    }
    
    return false;
}

/**
 * Prevent directory traversal
 */
function preventDirectoryTraversal($path) {
    $realPath = realpath($path);
    $basePath = realpath(__DIR__ . '/..');
    
    if ($realPath === false || strpos($realPath, $basePath) !== 0) {
        logSecurityEvent('DIRECTORY_TRAVERSAL_ATTEMPT', $path);
        return false;
    }
    
    return true;
}

/**
 * Session security
 */
function secureSession() {
    // Regenerate session ID periodically
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
    
    // Check session timeout
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
        session_unset();
        session_destroy();
        return false;
    }
    
    $_SESSION['last_activity'] = time();
    return true;
}

// Initialize session security
secureSession();

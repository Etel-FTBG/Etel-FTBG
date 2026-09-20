<?php
// ============================================================
// ETEL TAXI - API entrypoint  v1.0
// Same single-dispatcher pattern as Delivery's api/boot.php:
// ?action=X routes to the right controller, ok()/fail() are the only
// two response shapes, every controller shares this one PDO connection.
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Etel-Service-Key');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';

define('APP_VER', '1.0.0');

function ok($data = null) {
    echo json_encode(array('success' => true, 'data' => $data, 'v' => APP_VER));
    exit;
}
function fail($msg, $code = 400, $extra = array()) {
    http_response_code($code);
    echo json_encode(array_merge(array('success' => false, 'message' => $msg, 'v' => APP_VER), $extra));
    exit;
}
function fail_ex($e, $publicMsg, $code = 500) {
    error_log('[EtelTaxi] ' . $publicMsg . ': ' . $e->getMessage());
    fail($publicMsg, $code);
}

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
        );
    } catch (Exception $e) {
        fail_ex($e, 'Database connection failed', 500);
    }
    return $pdo;
}

function tbl($t) { return DB_PREFIX . $t; }

function gen_session_token() {
    return bin2hex(random_bytes(32));
}

// ---- Driver auth: EtelID SSO session first (a driver already logged
// into any other Etel app is recognized here automatically), falling
// back to this app's own phone+PIN session. ----
function require_driver_auth($pdo) {
    $token = '';
    $hdrs = function_exists('getallheaders') ? getallheaders() : array();
    foreach ($hdrs as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $token = trim(str_ireplace('Bearer', '', $v));
    if ($token === '' && isset($_GET['token'])) $token = $_GET['token'];

    if ($token === '') fail('Sign in required.', 401);

    // Try this app's own driver session first.
    $s = $pdo->prepare('SELECT d.* FROM ' . tbl('taxi_sessions') . ' s
                        JOIN ' . tbl('taxi_drivers') . ' d ON d.id = s.driver_id
                        WHERE s.token = ? AND s.expires_at > NOW() LIMIT 1');
    $s->execute(array($token));
    $driver = $s->fetch();
    if ($driver) return $driver;

    // Fall back to a shared EtelID SSO token - look up the member, then
    // find a driver row already linked to that etel_id.
    try {
        // Real table names are "{WP prefix}etelid_sessions"/"etelid_members"
        // (confirmed against the live schema via debug-db.php) - same fix
        // as DriverController.php's driver_complete_etelid_registration.
        // EtelID_Auth::create_session() stores sha256($token), never the
        // raw token (class-etelid-auth.php) - member_from_token() on
        // EtelID's own side re-hashes before comparing, so this lookup
        // must too, or it can never match (confirmed: this was the exact
        // cause of every "Session expired or invalid" after EtelID login).
        $es = $pdo->prepare('SELECT m.etel_id, m.full_name, m.phone
                             FROM ' . ETELID_DB_NAME . '.' . ETELID_DB_PREFIX . 'etelid_sessions es
                             JOIN ' . ETELID_DB_NAME . '.' . ETELID_DB_PREFIX . 'etelid_members m ON m.id = es.member_id
                             WHERE es.token = ? AND es.expires_at > NOW() LIMIT 1');
        $es->execute(array(hash('sha256', $token)));
        $member = $es->fetch();
        if ($member) {
            $ds = $pdo->prepare('SELECT * FROM ' . tbl('taxi_drivers') . ' WHERE etel_id = ? LIMIT 1');
            $ds->execute(array($member['etel_id']));
            $driver = $ds->fetch();
            if ($driver) return $driver;
            fail('This EtelID is not yet registered with any taxi association. Ask your association to register your vehicle.', 403);
        }
    } catch (Exception $e) {
        // Cross-database lookup failing (e.g. DB user lacks grant on
        // EtelID's database) should degrade to "not signed in", not a
        // 500 - this is a real fallback path, not an error state.
    }

    fail('Session expired or invalid. Please sign in again.', 401);
}

function require_admin_auth($pdo, $capability = null) {
    $token = '';
    $hdrs = function_exists('getallheaders') ? getallheaders() : array();
    foreach ($hdrs as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $token = trim(str_ireplace('Bearer', '', $v));
    // CSV export is a plain <a href> download link, which can't set a
    // custom Authorization header - accept the token as a query param
    // for that one case.
    if ($token === '' && !empty($_GET['token'])) $token = (string)$_GET['token'];
    if ($token === '') fail('Admin sign in required.', 401);

    $s = $pdo->prepare('SELECT a.* FROM ' . tbl('taxi_admin_sessions') . ' s
                        JOIN ' . tbl('taxi_admins') . ' a ON a.id = s.admin_id
                        WHERE s.token = ? AND s.expires_at > NOW() LIMIT 1');
    $s->execute(array($token));
    $admin = $s->fetch();
    if (!$admin) fail('Admin session expired or invalid.', 401);

    if ($capability !== null && !taxi_admin_has_capability($pdo, $admin, $capability)) {
        fail("You don't have permission to do this (requires: $capability)", 403);
    }
    return $admin;
}

// Loads the capability list for an admin.
// - Not EtelGroup-managed (is_etelgroup_managed=0 - the real signal,
//   set explicitly by admin_etelid_login, never inferred from staff_role
//   happening to be empty) -> unrestricted, exactly like every admin
//   account before this system existed.
// - EtelGroup-managed with a current staff_role -> look up that role's
//   permissions (or a safe read-only baseline if the role name has no
//   configured row yet).
// - EtelGroup-managed with NO current staff_role (role was removed) ->
//   zero capabilities.
function taxi_admin_capabilities($pdo, $admin) {
    $managed = !empty($admin['is_etelgroup_managed']);
    if (!$managed) return array('*');
    if (empty($admin['staff_role'])) return array();
    // "Owner", "Admin", and "Manager" default to unrestricted access -
    // documented in admin.html's Roles panel and admin-roles.js, but
    // never actually implemented here: any managed admin with one of
    // these role names fell through to the safe read-only baseline
    // below instead, blocking normal admin actions like verifying a
    // payment with "requires: payments.manage".
    if (in_array(strtolower($admin['staff_role']), array('owner', 'admin', 'manager'), true)) {
        return array('*');
    }
    try {
        $s = $pdo->prepare('SELECT capabilities FROM ' . tbl('taxi_role_permissions') . ' WHERE LOWER(role_name) = LOWER(?) LIMIT 1');
        $s->execute(array($admin['staff_role']));
        $row = $s->fetch();
    } catch (Exception $e) { $row = null; }
    if (!$row) return array('drivers.view', 'associations.view');
    $caps = json_decode($row['capabilities'], true);
    return is_array($caps) ? $caps : array();
}
function taxi_admin_has_capability($pdo, $admin, $capability) {
    $caps = taxi_admin_capabilities($pdo, $admin);
    return in_array('*', $caps, true) || in_array($capability, $caps, true);
}

// Traffic officer auth - EtelID-only sign-in (see OfficerController's
// officer_etelid_login), same session-token pattern as admin/driver.
function require_officer_auth($pdo) {
    $token = '';
    $hdrs = function_exists('getallheaders') ? getallheaders() : array();
    foreach ($hdrs as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $token = trim(str_ireplace('Bearer', '', $v));
    if ($token === '') fail('Officer sign in required.', 401);

    $s = $pdo->prepare('SELECT o.* FROM ' . tbl('taxi_officer_sessions') . ' s
                        JOIN ' . tbl('taxi_officers') . ' o ON o.id = s.officer_id
                        WHERE s.token = ? AND s.expires_at > NOW() LIMIT 1');
    $s->execute(array($token));
    $officer = $s->fetch();
    if (!$officer) fail('Officer session expired or invalid.', 401);
    return $officer;
}

/* Generic call into EtelID's service API (same pattern as
   driver_etelid_login's inline call in DriverController.php, factored
   out so the license/identity cross-check can reuse it too). Returns
   the decoded JSON body, or null if unreachable/not configured. */
function taxi_etelid_service_call($path, $payload) {
    if (!defined('ETELID_SERVICE_KEY') || ETELID_SERVICE_KEY === '') return null;
    $url = 'https://' . ETELID_HOST . '/EtelId/wp-json/etelid/v1/service/' . $path;
    $ctx = stream_context_create(array('http' => array(
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nX-Etel-Service-Key: " . ETELID_SERVICE_KEY . "\r\n",
        'content' => json_encode($payload),
        'timeout' => 8,
        'ignore_errors' => true,
    )));
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return null;
    $decoded = json_decode($resp, true);
    return is_array($decoded) ? $decoded : null;
}

/* Loose match: case-insensitive, ignores extra whitespace and Ethiopian
   naming order variance (people commonly type "First Father" vs EtelID's
   full "First Father Grandfather"), so a mismatch only fires on genuinely
   different names, not formatting differences. Mirrors the same helper
   in ETEL Delivery/Ride's RideController.php. */
function taxi_names_roughly_match($a, $b) {
    $norm = function ($s) {
        $s = mb_strtolower(trim($s), 'UTF-8');
        return preg_replace('/\s+/', ' ', $s);
    };
    $a = $norm($a); $b = $norm($b);
    if ($a === '' || $b === '') return false;
    if ($a === $b) return true;
    $aParts = explode(' ', $a); $bParts = explode(' ', $b);
    return $aParts[0] === $bParts[0] && (count($aParts) < 2 || count($bParts) < 2 || $aParts[1] === $bParts[1]);
}

/* Cross-checks a driver-entered license name/DOB against their EtelID
   record. $phone is used to look the member up via service/kyc.
   Returns 'matched', 'mismatch', or 'unverified' (EtelID unreachable -
   not blocking, mirrors Delivery/Ride's fail-open behavior). */
function taxi_check_license_identity($phone, $licenseName, $licenseDob) {
    $kyc = taxi_etelid_service_call('kyc', array('identifier' => $phone, 'type' => 'phone'));
    if (!is_array($kyc) || empty($kyc['member'])) {
        error_log('[EtelTaxi] KYC cross-check unreachable for phone=' . $phone);
        return 'unverified';
    }
    $m = $kyc['member'];
    $nameMatch = taxi_names_roughly_match($licenseName, isset($m['full_name']) ? $m['full_name'] : '');
    $dobMatch  = !empty($m['dob']) && $m['dob'] === $licenseDob;
    return ($nameMatch && $dobMatch) ? 'matched' : 'mismatch';
}

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody, true);
if (!is_array($body)) $body = $_POST;

if ($action === '') fail('No action specified.', 400);

switch ($action) {
    case 'driver_register': case 'driver_login': case 'driver_get_status':
    case 'driver_submit_payment': case 'driver_get_payment_history':
    case 'driver_get_bank_accounts': case 'public_verify_permit':
    case 'driver_list_associations': case 'driver_register_push_token':
    case 'driver_etelid_login': case 'driver_complete_etelid_registration':
    case 'get_vapid_public_key':
        require __DIR__ . '/controllers/DriverController.php';
        break;

    case 'admin_login': case 'admin_etelid_login': case 'admin_list_drivers': case 'admin_list_associations':
    case 'admin_create_association': case 'admin_update_association':
    case 'admin_register_driver': case 'admin_list_pending_payments':
    case 'admin_verify_payment': case 'admin_reject_payment':
    case 'admin_get_bank_accounts': case 'admin_update_bank_account':
    case 'admin_get_dashboard_stats': case 'admin_list_overdue':
    case 'admin_register_officer': case 'admin_list_officers':
    case 'admin_get_revenue_trend': case 'admin_export_drivers_csv':
    case 'admin_list_role_permissions': case 'admin_update_role_permissions':
    case 'admin_delete_role_permissions': case 'admin_get_my_capabilities':
        require __DIR__ . '/controllers/AdminController.php';
        break;

    case 'officer_etelid_login': case 'officer_verify_by_plate': case 'officer_logout':
        require __DIR__ . '/controllers/OfficerController.php';
        break;

    case 'service_check_association_payment':
        require __DIR__ . '/controllers/ServiceController.php';
        break;

    default:
        fail('Unknown action: ' . $action, 404);
}

<?php
/**
 * ETEL Delivery - One-time backfill for open-trip rides stored with a
 * ETB 0 fare because of the rides_calc_fare() array-binding bug.
 *
 * Recomputes fare_paid/fare_final for affected COMPLETED open-trip rides
 * using the same flat formula customers were actually shown on screen
 * (ETB 120 base + ETB 30/km - no surge/vehicle multiplier, since we
 * cannot reliably know the historical surge state and the UI never
 * promised one for these rides), and credits the difference to the
 * assigned driver's total_earnings.
 *
 * SAFE BY DEFAULT: runs as a dry run (shows what would change, touches
 * nothing) unless called with ?apply=1. Requires a valid admin session
 * token, same as the rest of the admin API.
 *
 * Place at: delivery/api/backfill_open_trip_fares.php
 * Use:      https://ethelmarket.com/delivery/api/backfill_open_trip_fares.php
 *           (dry run - review the list)
 *           https://ethelmarket.com/delivery/api/backfill_open_trip_fares.php?apply=1
 *           (after reviewing, actually applies the fix)
 * Delete this file once you've run it and confirmed the results.
 */

if (ob_get_level()) ob_end_clean();
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/env_loader.php';

function bf_env($key, $cfgConst, $default = '') {
    if (defined($cfgConst)) return constant($cfgConst);
    static $env = null;
    if ($env === null) {
        // Was its own inline line-splitter here (no quote-stripping, no
        // inline-comment stripping, so e.g. TOKEN_SECRET="abc # note"
        // would have kept the trailing comment as part of the value).
        // Switched to the same shared parser boot.php/diagnose.php/
        // etel-os.php use, per the "unify .env parsing" recommendation.
        $env = etel_parse_env_file(__DIR__ . '/.env');
    }
    if (isset($env[$key]) && $env[$key] !== '') return $env[$key];
    $fromEnv = getenv($key);
    return ($fromEnv !== false && $fromEnv !== '') ? $fromEnv : $default;
}

$DB_HOST = bf_env('DB_HOST', 'CFG_DB_HOST', 'localhost');
$DB_NAME = bf_env('DB_NAME', 'CFG_DB_NAME', 'etel_delivery');
$DB_USER = bf_env('DB_USER', 'CFG_DB_USER', '');
$DB_PASS = bf_env('DB_PASS', 'CFG_DB_PASS', '');
$TOKEN_SECRET = bf_env('TOKEN_SECRET', 'CFG_TOKEN_SECRET', '');

function bf_fail($msg, $code = 400) {
    while (ob_get_level()) { @ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERROR: $msg\n";
    exit;
}

/* -- Auth: same bearer-token pattern as the rest of the admin API -- */
function bf_get_bearer() {
    $h = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (!$h && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $h = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    if (!$h && function_exists('getallheaders')) {
        $hdrs = getallheaders();
        foreach ($hdrs as $k => $v) { if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; } }
    }
    if (!$h && isset($_GET['token'])) return trim($_GET['token']);
    return (strpos($h, 'Bearer ') === 0) ? substr($h, 7) : '';
}
/* Same token format as boot.php's gen_token()/verify_token() - see
   migration 006 / boot.php's etel_bump_token_version() for why
   token_version exists and why it has to be checked here too. */
function bf_verify_token($token, $secret) {
    $d = @base64_decode($token);
    if (!$d) return null;
    $p = explode(':', $d);
    if (count($p) < 4) return null; // old 3-part tokens fail closed
    $uid = $p[0]; $ts = $p[1]; $version = $p[2]; $sig = $p[3];
    if ((time() - intval($ts)) > 86400 * 7) return null;
    $expected = hash_hmac('sha256', $uid . ':' . $ts . ':' . $version, $secret);
    $match = function_exists('hash_equals') ? hash_equals($expected, $sig) : ($sig === $expected);
    return $match ? array('uid' => intval($uid), 'version' => intval($version)) : null;
}

if (!$TOKEN_SECRET) bf_fail('TOKEN_SECRET not configured - cannot verify admin session.', 500);
$token = bf_get_bearer();
$tok = $token ? bf_verify_token($token, $TOKEN_SECRET) : null;
if (!$tok) bf_fail('Not authorized. Open this URL with your admin session token, e.g. add ?token=YOUR_ADMIN_TOKEN to the URL (copy it from localStorage.etel_token in the admin dashboard), or call it from a tool that sends your existing Authorization header.', 401);

try {
    $pdo = new PDO(
        'mysql:host=' . $DB_HOST . ';dbname=' . $DB_NAME . ';charset=utf8mb4',
        $DB_USER, $DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
} catch (Exception $e) {
    bf_fail('Database connection failed: ' . $e->getMessage(), 503);
}

$bf_has_ver = false;
try {
    $colCheck = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'token_version'");
    $colCheck->execute();
    $bf_has_ver = (bool) $colCheck->fetch();
} catch (Exception $e) { $bf_has_ver = false; }

$uid = $tok['uid'];
$role = $pdo->prepare('SELECT role' . ($bf_has_ver ? ', token_version' : '') . ' FROM users WHERE id=?');
$role->execute(array($uid));
$roleRow = $role->fetch();
if (!$roleRow || $roleRow['role'] !== 'admin') bf_fail('Admin role required.', 403);
if ($bf_has_ver && intval($roleRow['token_version']) !== $tok['version']) bf_fail('Session revoked, please log in again.', 401);

/* -- Find affected rides: completed, open-trip, real distance, but fare stored as 0 -- */
$rows = $pdo->query(
    "SELECT r.id, r.driver_id, r.passenger_id, r.distance_km, r.fare_paid, r.fare_final, r.completed_at,
            u.name AS passenger_name
     FROM rides r
     LEFT JOIN users u ON u.id = r.passenger_id
     WHERE r.status = 'completed'
       AND r.open_trip = 1
       AND r.distance_km > 0
       AND (r.fare_paid IS NULL OR r.fare_paid <= 0)
     ORDER BY r.id ASC"
)->fetchAll();

$apply = isset($_GET['apply']) && $_GET['apply'] == '1';
$changes = array();
foreach ($rows as $r) {
    $km = round(floatval($r['distance_km']), 2);
    $correctFare = round(120 + 30 * $km, 2);
    $changes[] = array(
        'id' => $r['id'], 'passenger' => $r['passenger_name'], 'driver_id' => $r['driver_id'],
        'km' => $km, 'old_fare' => floatval($r['fare_paid']), 'new_fare' => $correctFare,
        'completed_at' => $r['completed_at'],
    );
}

if ($apply && $changes) {
    $pdo->beginTransaction();
    try {
        $updRide = $pdo->prepare('UPDATE rides SET fare_paid = ?, fare_final = ? WHERE id = ?');
        $updDriver = $pdo->prepare('UPDATE ride_drivers SET total_earnings = total_earnings + ? WHERE user_id = ?');
        foreach ($changes as $c) {
            $updRide->execute(array($c['new_fare'], $c['new_fare'], $c['id']));
            if ($c['driver_id']) {
                $diff = $c['new_fare'] - $c['old_fare'];
                $updDriver->execute(array($diff, $c['driver_id']));
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        bf_fail('Backfill failed partway, rolled back: ' . $e->getMessage(), 500);
    }
}

while (ob_get_level()) { @ob_end_clean(); }
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Open-Trip Fare Backfill</title>
<style>
body{background:#0d0d0d;color:#e8e2d4;font-family:monospace;padding:20px;}
h1{color:#D4AF37;font-size:18px;}
table{border-collapse:collapse;width:100%;margin-top:14px;}
th,td{border:1px solid rgba(212,175,55,.25);padding:8px 10px;text-align:left;font-size:13px;}
th{background:rgba(212,175,55,.1);color:#D4AF37;}
.banner{padding:12px 16px;border-radius:8px;margin-bottom:14px;font-weight:bold;}
.dry{background:rgba(212,175,55,.12);border:1px solid rgba(212,175,55,.4);color:#F2CC6B;}
.applied{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.4);color:#6ee7a0;}
.none{color:#888;}
a.btn{display:inline-block;margin-top:14px;padding:10px 16px;background:linear-gradient(135deg,#C8960C,#D4AF37);color:#0d0d0d;font-weight:bold;text-decoration:none;border-radius:8px;}
</style></head><body>
<h1>Open-Trip Fare Backfill</h1>
<?php if ($apply): ?>
  <div class="banner applied">&#9989; Applied - <?php echo count($changes); ?> ride(s) corrected and driver earnings updated.</div>
<?php else: ?>
  <div class="banner dry">&#9888;&#65039; DRY RUN - nothing has been changed. Review the list below, then re-open this URL with <code>?apply=1</code> appended (keep the same token) to actually apply it.</div>
<?php endif; ?>

<?php if (!$changes): ?>
  <p class="none">No affected rides found - either already fixed, or none exist.</p>
<?php else: ?>
<table>
<tr><th>Ride ID</th><th>Passenger</th><th>Distance</th><th>Old Fare</th><th>Corrected Fare</th><th>Completed</th></tr>
<?php foreach ($changes as $c): ?>
<tr>
  <td>#<?php echo (int)$c['id']; ?></td>
  <td><?php echo htmlspecialchars($c['passenger'] ?: '-'); ?></td>
  <td><?php echo $c['km']; ?> km</td>
  <td>ETB <?php echo number_format($c['old_fare'], 2); ?></td>
  <td>ETB <?php echo number_format($c['new_fare'], 2); ?></td>
  <td><?php echo htmlspecialchars($c['completed_at']); ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<?php if (!$apply && $changes): ?>
<a class="btn" href="?apply=1&token=<?php echo urlencode($token); ?>">Apply this fix now</a>
<?php endif; ?>

</body></html>

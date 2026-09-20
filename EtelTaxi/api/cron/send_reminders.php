<?php
// ============================================================
// ETEL TAXI - Daily reminder job  v1.0
//
// Schedule this to run once a day (cPanel -> Cron Jobs -> add a job
// that runs, e.g., `php /home/USER/public_html/EtelTaxi/api/cron/send_reminders.php`
// once daily). Can also be hit as a URL if your host doesn't support
// real cron jobs - in that case it requires ?key=YOUR_TAXI_ADMIN_KEY,
// same as migrate.php/seed-admin.php.
//
// For each active driver with a registered push token, works out
// whether today calls for a "payment due soon", "you're in the grace
// period", or "penalty has applied" notification - and sends each one
// AT MOST ONCE per period, using taxi_notifications_log (both an
// in-code check and a DB unique-key constraint, so a job that runs
// twice in one day by accident still can't double-notify anyone).
//
// "Due soon" fires starting 3 days before the period's window opens -
// adjust $DUE_SOON_LEAD_DAYS below if you want more or less notice.
// ============================================================

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    header('Content-Type: application/json');
    require_once __DIR__ . '/../config.php';
    $key = isset($_GET['key']) ? $_GET['key'] : '';
    if (!defined('TAXI_ADMIN_KEY') || TAXI_ADMIN_KEY === '' || !hash_equals(TAXI_ADMIN_KEY, $key)) {
        http_response_code(401);
        echo json_encode(array('ok' => false, 'error' => 'Unauthorized.'));
        exit;
    }
} else {
    require_once __DIR__ . '/../config.php';
}

require_once __DIR__ . '/../lib/taxi_payments.php';
require_once __DIR__ . '/../lib/taxi_push.php';

const DUE_SOON_LEAD_DAYS = 3;

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
} catch (Exception $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    if (!$isCli) { echo json_encode(array('ok' => false, 'error' => 'DB connection failed')); }
    exit(1);
}

function tbl($t) { return DB_PREFIX . $t; }

$drivers = $pdo->query('SELECT id, association_id, full_name, push_subscription FROM ' . tbl('taxi_drivers') . '
                        WHERE status = "active" AND push_subscription IS NOT NULL AND push_subscription != ""')->fetchAll();

$sent = array('due_soon' => 0, 'grace_period' => 0, 'penalty_applied' => 0);
$today = new DateTime();

foreach ($drivers as $driver) {
    $status = taxi_compute_driver_status($pdo, $driver['id'], $driver['association_id']);
    if ($status['status'] === 'active') continue; // already paid - nothing to remind them about

    $period = $status['current_period'];
    $windowStart = new DateTime($status['window_start']);
    $dueSoonStart = (clone $windowStart)->modify('-' . DUE_SOON_LEAD_DAYS . ' days');

    $notifType = null;
    if ($status['status'] === 'overdue') {
        $notifType = 'penalty_applied';
    } elseif ($today >= $windowStart) {
        $notifType = 'grace_period';
    } elseif ($today >= $dueSoonStart) {
        $notifType = 'due_soon';
    }
    if (!$notifType) continue;

    // Already sent this exact notification for this driver+period? Skip.
    $chk = $pdo->prepare('SELECT id FROM ' . tbl('taxi_notifications_log') . '
                          WHERE driver_id = ? AND period_month = ? AND notif_type = ?');
    $chk->execute(array($driver['id'], $period, $notifType));
    if ($chk->fetch()) continue;

    $messages = array(
        'due_soon'        => array('Payment due soon', 'Your Etel Taxi due for ' . $period . ' is coming up. Pay before the penalty window starts.'),
        'grace_period'     => array('Payment due now', 'Your Etel Taxi due for ' . $period . ' is payable now through ' . $status['window_end'] . ' - pay soon to avoid a penalty.'),
        'penalty_applied' => array('Penalty applied', 'Your Etel Taxi due for ' . $period . ' is overdue. A penalty of ETB ' . number_format($status['penalty_owed'], 0) . ' now applies.'),
    );
    list($title, $body) = $messages[$notifType];

    $ok = taxi_send_push($driver['push_subscription'], $title, $body, array('type' => $notifType, 'period' => $period), $pdo, $driver['id']);

    // Log the attempt either way - a failed send (bad/expired token)
    // shouldn't be retried every single day forever; the driver will
    // get a fresh chance next period.
    try {
        $pdo->prepare('INSERT INTO ' . tbl('taxi_notifications_log') . ' (driver_id, period_month, notif_type, sent_at)
                       VALUES (?, ?, ?, NOW())')->execute(array($driver['id'], $period, $notifType));
    } catch (Exception $e) { /* unique key already prevents true duplicates */ }

    if ($ok) $sent[$notifType]++;
}

$summary = "Reminders sent: due_soon={$sent['due_soon']}, grace_period={$sent['grace_period']}, penalty_applied={$sent['penalty_applied']}\n";
if ($isCli) { echo $summary; }
else { echo json_encode(array('ok' => true, 'sent' => $sent)); }

<?php
/**
 * ኢE Pay — One-time PIN reset
 * Upload to: /Etel.Pay/wallet/reset.php  (wallet ROOT, not api/)
 * Visit once, it deletes itself.
 */

// ── Edit these two lines ──────────────────────────────────────
$TARGET_PHONE = '0924445206';
$NEW_PIN      = '1234';        // change to your new PIN
// ─────────────────────────────────────────────────────────────

// Load config from api/config.php
$_cfg = __DIR__ . '/api/config.php';
if (file_exists($_cfg)) require_once $_cfg;

if (!defined('EP_DB_HOST'))    define('EP_DB_HOST',    'localhost');
if (!defined('EP_DB_NAME'))    define('EP_DB_NAME',    'etelpay');
if (!defined('EP_DB_USER'))    define('EP_DB_USER',    'root');
if (!defined('EP_DB_PASS'))    define('EP_DB_PASS',    '');
if (!defined('EP_TBL_PREFIX')) define('EP_TBL_PREFIX', 'Etelpay_');

// Load .env override if present
$_env = __DIR__ . '/api/.env';
if (file_exists($_env)) {
    $ev = parse_ini_file($_env);
    if ($ev) foreach ($ev as $k => $v) { if (!getenv($k)) putenv("$k=$v"); }
    if (getenv('DB_HOST')) define('_EP_DB_HOST', getenv('DB_HOST'));
    if (getenv('DB_NAME')) define('_EP_DB_NAME', getenv('DB_NAME'));
    if (getenv('DB_USER')) define('_EP_DB_USER', getenv('DB_USER'));
    if (getenv('DB_PASS')) define('_EP_DB_PASS', getenv('DB_PASS'));
}

$host = defined('_EP_DB_HOST') ? _EP_DB_HOST : EP_DB_HOST;
$name = defined('_EP_DB_NAME') ? _EP_DB_NAME : EP_DB_NAME;
$user = defined('_EP_DB_USER') ? _EP_DB_USER : EP_DB_USER;
$pass = defined('_EP_DB_PASS') ? _EP_DB_PASS : EP_DB_PASS;

$USERS   = EP_TBL_PREFIX . 'ep_users';
$SESSIONS= EP_TBL_PREFIX . 'ep_sessions';
$OPTIONS = EP_TBL_PREFIX . 'options';

$msg = ''; $ok = false; $deleted = false;

try {
    $pdo = new PDO(
        'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4',
        $user, $pass,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );

    // Find user
    $s = $pdo->prepare('SELECT id, phone, status FROM ' . $USERS . ' WHERE phone = ? LIMIT 1');
    $s->execute(array($TARGET_PHONE));
    $u = $s->fetch(PDO::FETCH_ASSOC);

    if (!$u) {
        $msg = 'Phone not found: ' . htmlspecialchars($TARGET_PHONE);
    } else {
        $hash = password_hash($NEW_PIN, PASSWORD_BCRYPT);

        // Reset PIN + verify + activate
        $pdo->prepare('UPDATE ' . $USERS . ' SET pin_hash=?, kyc_status="verified", status="active" WHERE id=?')
            ->execute(array($hash, (int)$u['id']));

        // Set as admin phone in WP options
        try {
            $ex = $pdo->prepare('SELECT option_id FROM ' . $OPTIONS . ' WHERE option_name="etel_pay_admin_phones" LIMIT 1');
            $ex->execute();
            if ($ex->fetch()) {
                $pdo->prepare('UPDATE ' . $OPTIONS . ' SET option_value=? WHERE option_name="etel_pay_admin_phones"')
                    ->execute(array($TARGET_PHONE));
            } else {
                $pdo->prepare('INSERT INTO ' . $OPTIONS . ' (option_name, option_value) VALUES ("etel_pay_admin_phones",?)')
                    ->execute(array($TARGET_PHONE));
            }
        } catch (Exception $e) {}

        // Clear all sessions
        $pdo->prepare('DELETE FROM ' . $SESSIONS . ' WHERE ep_user_id=?')->execute(array((int)$u['id']));

        $ok  = true;
        $msg = 'Done! PIN reset · Admin granted · Sessions cleared.';

        // Self-delete
        $deleted = @unlink(__FILE__);
    }
} catch (Exception $e) {
    $msg = 'DB error: ' . htmlspecialchars($e->getMessage());
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ኢE Pay — Reset</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#030205;color:#F5F3F0;font-family:'Segoe UI',sans-serif;
  display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
.card{background:rgba(14,12,22,.97);border:1px solid rgba(212,168,67,.3);
  border-radius:20px;padding:36px 28px;width:100%;max-width:360px;text-align:center}
.mark{font-family:serif;font-size:48px;margin-bottom:6px;
  background:linear-gradient(135deg,#F2CC6B,#D4A843);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
h1{font-size:16px;font-weight:600;color:rgba(245,243,240,.5);letter-spacing:2px;
  text-transform:uppercase;margin-bottom:24px}
.box{padding:16px 18px;border-radius:12px;font-size:14px;line-height:1.7;text-align:left;margin-bottom:16px}
.box.ok {background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.22);color:#22C55E}
.box.err{background:rgba(239,68,68,.10);border:1px solid rgba(239,68,68,.22);color:#EF4444}
.row{display:flex;justify-content:space-between;padding:8px 0;
  border-bottom:1px solid rgba(212,168,67,.1);font-size:13px}
.row:last-child{border-bottom:none}
.row span:first-child{color:rgba(245,243,240,.4)}
.row span:last-child{font-weight:600;color:#F2CC6B;font-family:monospace}
.note{font-size:12px;color:rgba(245,243,240,.3);margin-top:14px;line-height:1.6}
a.btn{display:block;margin-top:20px;padding:14px;
  background:linear-gradient(135deg,#F2CC6B,#D4A843,#A07820);
  color:#1a0e00;border-radius:12px;text-decoration:none;font-weight:700;font-size:14px}
</style>
</head>
<body>
<div class="card">
  <div class="mark">ኢE</div>
  <h1>PIN Reset</h1>

  <div class="box <?php echo $ok ? 'ok' : 'err'; ?>">
    <?php echo $msg; ?>
  </div>

  <?php if ($ok): ?>
  <div>
    <div class="row"><span>Phone</span>  <span><?php echo htmlspecialchars($TARGET_PHONE); ?></span></div>
    <div class="row"><span>New PIN</span><span><?php echo htmlspecialchars($NEW_PIN); ?></span></div>
    <div class="row"><span>KYC</span>    <span>verified ✓</span></div>
    <div class="row"><span>Admin</span>  <span>granted ✓</span></div>
    <div class="row"><span>Sessions</span><span>cleared ✓</span></div>
    <div class="row"><span>This file</span><span><?php echo $deleted ? 'deleted ✓' : '⚠ delete manually'; ?></span></div>
  </div>
  <a class="btn" href="login.html">Go to login →</a>
  <?php if (!$deleted): ?>
  <div class="note">⚠️ Auto-delete failed. Please delete <code>reset.php</code> from cPanel File Manager manually.</div>
  <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>

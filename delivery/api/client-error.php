<?php
/**
 * ETEL - browser error beacon.
 *
 * js/etel-beacon.js (loaded by the rider and driver apps) posts a small JSON note here when a page throws an uncaught
 * error, a promise is rejected without a handler, or an API call answers with a server error. Public on purpose (the
 * app may be signed out or half-broken when it fails) so it is kept harmless: tiny bodies only, nothing is echoed back,
 * each distinct error is stored once with a counter and a last-seen time, at most 500 distinct errors are kept (rows
 * older than 30 days are dropped first), and every field is cut short. No tokens, no query strings, no IP addresses.
 * Admins read the list in the System health panel (admin_client_errors).
 *
 * GET answers 204 and writes nothing (the post-deploy check uses it to see the file is deployed).
 */
if (ob_get_level()) ob_end_clean();
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

function ce_done() { while (ob_get_level()) { @ob_end_clean(); } http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ce_done();
$raw = file_get_contents('php://input', false, null, 0, 4097);
if ($raw === false || $raw === '' || strlen($raw) > 4096) ce_done();
$j = json_decode($raw, true);
if (!is_array($j)) ce_done();
$pick = function ($k, $max) use ($j) {
    if (!isset($j[$k]) || !(is_string($j[$k]) || is_numeric($j[$k]))) return '';
    return substr(preg_replace('/[\x00-\x1F]/', ' ', (string) $j[$k]), 0, $max);
};
$strip = function ($u) { return preg_replace('/[?#].*$/', '', $u); };   // never keep query strings or fragments (they can carry tokens)
$app = preg_replace('/[^a-z0-9_-]/', '', strtolower($pick('app', 20)));
$kind = $pick('kind', 12);
if (!in_array($kind, array('js', 'promise', 'api'), true)) ce_done();
$message = $pick('message', 300);
if ($message === '' || $app === '') ce_done();
$page = $strip($pick('page', 120));
$source = $strip($pick('source', 160));
$line = (int) $pick('line', 8);
$stack = $strip($pick('stack', 1200));
$rid = preg_replace('/[^a-f0-9]/', '', strtolower($pick('rid', 16)));
$uid = (int) $pick('uid', 10);
$ua = $pick('ua', 200);

require_once __DIR__ . '/env_loader.php';
$env = etel_parse_env_file(__DIR__ . '/.env');
$e = function ($k, $d = '') use ($env) { return (isset($env[$k]) && $env[$k] !== '') ? $env[$k] : $d; };
try {
    $pdo = new PDO('mysql:host=' . $e('DB_HOST', 'localhost') . ';dbname=' . $e('DB_NAME', 'etel_delivery') . ';charset=utf8mb4', $e('DB_USER'), $e('DB_PASS'),
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3));
    $pdo->exec("CREATE TABLE IF NOT EXISTS client_errors (
        id CHAR(32) NOT NULL, app VARCHAR(20) NOT NULL DEFAULT '', kind VARCHAR(12) NOT NULL DEFAULT '', page VARCHAR(120) NOT NULL DEFAULT '',
        message VARCHAR(300) NOT NULL DEFAULT '', source VARCHAR(160) NOT NULL DEFAULT '', line_no INT NOT NULL DEFAULT 0, stack VARCHAR(1200) NOT NULL DEFAULT '',
        rid VARCHAR(16) NOT NULL DEFAULT '', ua VARCHAR(200) NOT NULL DEFAULT '', user_id INT NOT NULL DEFAULT 0,
        hits INT NOT NULL DEFAULT 1, first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY idx_client_errors_seen (last_seen)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // the same error is one row: page, kind and message (numbers and long ids blanked) make the fingerprint
    $fp = md5($app . '|' . $kind . '|' . $page . '|' . preg_replace('/[0-9a-f]{8,}|\d+/i', '#', $message) . '|' . $source . '|' . $line);
    $up = $pdo->prepare('UPDATE client_errors SET hits = hits + 1, last_seen = NOW(), rid = IF(? <> \'\', ?, rid), user_id = IF(? > 0, ?, user_id) WHERE id = ?');
    $up->execute(array($rid, $rid, $uid, $uid, $fp));
    if ($up->rowCount() === 0) {
        $pdo->exec('DELETE FROM client_errors WHERE last_seen < DATE_SUB(NOW(), INTERVAL 30 DAY)');
        if ((int) $pdo->query('SELECT COUNT(*) FROM client_errors')->fetchColumn() < 500) {
            $pdo->prepare('INSERT IGNORE INTO client_errors (id, app, kind, page, message, source, line_no, stack, rid, ua, user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute(array($fp, $app, $kind, $page, $message, $source, $line, $stack, $rid, $ua, $uid));
        }
    }
} catch (Exception $ex) { /* a failed report must never matter */ }
ce_done();

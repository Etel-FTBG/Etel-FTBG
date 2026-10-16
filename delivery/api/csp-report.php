<?php
/**
 * ETEL Delivery - Content-Security-Policy violation reports.
 *
 * The Customer page (customer.html) is served with a STRICT policy in "report only" mode (see .htaccess): the browser still runs
 * everything, but tells this endpoint whenever something would have been blocked by a policy without 'unsafe-inline'. We use the
 * reports to find any inline script left in the page before that policy is enforced.
 *
 * Public on purpose (browsers send it without a login) so it is kept harmless: tiny bodies only, nothing is echoed back, and the
 * same violation is stored once with a counter (no row per report). At most 300 different violations are kept; after that new
 * kinds are dropped. Only the fields below are stored, each cut short.
 */
if (ob_get_level()) ob_end_clean();
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

function csp_done() { while (ob_get_level()) { @ob_end_clean(); } http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') csp_done();
$raw = file_get_contents('php://input', false, null, 0, 8193);
if ($raw === false || $raw === '' || strlen($raw) > 8192) csp_done();
$j = json_decode($raw, true);
if (!is_array($j)) csp_done();
// Two formats: {"csp-report": {...}} (report-uri) and [{"type":"csp-violation","body":{...}}] (Reporting API).
$r = isset($j['csp-report']) && is_array($j['csp-report']) ? $j['csp-report'] : (isset($j[0]['body']) && is_array($j[0]['body']) ? $j[0]['body'] : array());
if (!$r) csp_done();
$pick = function ($keys, $max) use ($r) {
    foreach ($keys as $k) { if (isset($r[$k]) && (is_string($r[$k]) || is_numeric($r[$k]))) return substr(preg_replace('/[\x00-\x1F]/', ' ', (string) $r[$k]), 0, $max); }
    return '';
};
$page = $pick(array('document-uri', 'documentURL', 'documentURI'), 200);
$page = preg_replace('/[?#].*$/', '', $page);   // never store query strings or fragments (they can carry tokens)
$directive = $pick(array('effective-directive', 'effectiveDirective', 'violated-directive'), 60);
$blocked = preg_replace('/[?#].*$/', '', $pick(array('blocked-uri', 'blockedURL', 'blockedURI'), 200));
$source = preg_replace('/[?#].*$/', '', $pick(array('source-file', 'sourceFile'), 200));
$line = (int) $pick(array('line-number', 'lineNumber'), 10);
$sample = $pick(array('script-sample', 'sample'), 120);
if ($directive === '') csp_done();

require_once __DIR__ . '/env_loader.php';
$env = etel_parse_env_file(__DIR__ . '/.env');
$e = function ($k, $d = '') use ($env) { return (isset($env[$k]) && $env[$k] !== '') ? $env[$k] : $d; };
try {
    $pdo = new PDO('mysql:host=' . $e('DB_HOST', 'localhost') . ';dbname=' . $e('DB_NAME', 'etel_delivery') . ';charset=utf8mb4', $e('DB_USER'), $e('DB_PASS'),
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3));
    $pdo->exec("CREATE TABLE IF NOT EXISTS csp_reports (
        id CHAR(32) NOT NULL, page VARCHAR(200) NOT NULL DEFAULT '', directive VARCHAR(60) NOT NULL DEFAULT '', blocked VARCHAR(200) NOT NULL DEFAULT '',
        source VARCHAR(200) NOT NULL DEFAULT '', line_no INT NOT NULL DEFAULT 0, sample VARCHAR(120) NOT NULL DEFAULT '',
        hits INT NOT NULL DEFAULT 1, first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $id = md5($page . '|' . $directive . '|' . $blocked . '|' . $source . '|' . $line . '|' . $sample);
    $up = $pdo->prepare('UPDATE csp_reports SET hits = hits + 1, last_seen = NOW() WHERE id = ?');
    $up->execute(array($id));
    if ($up->rowCount() === 0) {
        if ((int) $pdo->query('SELECT COUNT(*) FROM csp_reports')->fetchColumn() < 300) {
            $pdo->prepare('INSERT IGNORE INTO csp_reports (id, page, directive, blocked, source, line_no, sample) VALUES (?,?,?,?,?,?,?)')
                ->execute(array($id, $page, $directive, $blocked, $source, $line, $sample));
        }
    }
} catch (Exception $ex) { /* a failed report must never matter */ }
csp_done();

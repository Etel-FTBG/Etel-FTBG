<?php
// The one-click Code Audit (api/audit_agent.php): secrets never leave the server, and the files that matter most are read first.
// Pure functions plus a temporary folder; no database. Exits 0 on pass, 1 on failure.
error_reporting(E_ALL); ini_set('display_errors', '1');
require_once __DIR__ . '/../api/audit_agent.php';
$fails = 0;
function check($name, $ok, $extra = '') { global $fails; if ($ok) echo "  PASS  $name\n"; else { echo "  FAIL  $name" . ($extra !== '' ? "  -> $extra" : '') . "\n"; $fails++; } }

echo "secret files\n";
foreach (array('.env', '.env.local', 'prod.env', '.htpasswd', 'server.pem', 'private.key', 'id_rsa', 'cert.crt', 'data.sqlite') as $f) check("$f is never sent", etel_audit_is_secret_file($f));
foreach (array('boot.php', 'env_loader.php', 'environment.js', 'keyboard.js', 'index.html') as $f) check("$f is a normal file", !etel_audit_is_secret_file($f));

echo "hard-coded secrets inside code\n";
$code = "define('TOKEN_SECRET', 'abcdef1234567890abcdef');\n\$cfg = array('api_key' => 'sk-live-0123456789', 'name' => 'ETEL');\n\$password = \"hunter2hunter2\";\n\$ok = 'short';";
$r = etel_audit_redact($code);
check('the token secret value is blanked', strpos($r, 'abcdef1234567890abcdef') === false && strpos($r, '[redacted]') !== false, $r);
check('the api key value is blanked', strpos($r, 'sk-live-0123456789') === false);
check('a double-quoted password is blanked', strpos($r, 'hunter2hunter2') === false);
check('ordinary code is left alone', strpos($r, "'name' => 'ETEL'") !== false && strpos($r, "\$ok = 'short'") !== false);

echo "which files are read first\n";
check('the router and sign-in come first', etel_audit_risk_rank('api/boot.php') === 0 && etel_audit_risk_rank('api/controllers/AuthController.php') === 0 && etel_audit_risk_rank('api/upload.php') === 0);
check('money files come right after', etel_audit_risk_rank('api/lib/wallet_ops.php') === 1 && etel_audit_risk_rank('api/controllers/CashController.php') === 1);
check('a style sheet comes last', etel_audit_risk_rank('css/etel-readable.css') === 3);

echo "collecting a project folder\n";
$tmp = sys_get_temp_dir() . '/etel_audit_' . getmypid(); mkdir($tmp . '/api/controllers', 0777, true); mkdir($tmp . '/css', 0777, true);
file_put_contents($tmp . '/api/.env', "DB_PASS=supersecretpassword\nTOKEN_SECRET=zzzzzzzzzzzzzzzz\n");
file_put_contents($tmp . '/api/controllers/AuthController.php', "<?php\n\$secret = 'verysecretvalue123';\n");
file_put_contents($tmp . '/css/a.css', 'body{color:#000}');
$files = etel_audit_collect_files($tmp);
check('the .env file is not collected', !isset($files['api/.env']) && count($files) === 2, implode(',', array_keys($files)));
check('a secret typed into code is redacted', isset($files['api/controllers/AuthController.php']) && strpos($files['api/controllers/AuthController.php'], 'verysecretvalue123') === false);
$msg = etel_audit_build_content($files);
check('nothing secret reaches the message', strpos($msg, 'supersecretpassword') === false && strpos($msg, 'zzzzzzzzzzzzzzzz') === false && strpos($msg, 'verysecretvalue123') === false);
check('the sign-in file is listed before the style sheet', strpos($msg, '=== FILE: api/controllers/AuthController.php') < strpos($msg, '=== FILE: css/a.css'));
check('the read budget is large enough to cover real code', ETEL_AUDIT_MAX_CONTENT >= 200000 && ETEL_AUDIT_MAX_PER_FILE >= 10000);
@unlink($tmp . '/api/.env'); @unlink($tmp . '/api/controllers/AuthController.php'); @unlink($tmp . '/css/a.css'); @rmdir($tmp . '/api/controllers'); @rmdir($tmp . '/api'); @rmdir($tmp . '/css'); @rmdir($tmp);

echo $fails ? "FAILED: $fails\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);

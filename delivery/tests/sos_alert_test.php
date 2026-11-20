<?php
// Run: php delivery/tests/sos_alert_test.php   (needs MariaDB database fa_test, sql_mode ANSI_QUOTES; it creates the small tables it needs)
// SOS alerts to staff: the REAL lib/sos_alert.php. Only the actual push (a network call) and the capability lookup are replaced by recorders.
error_reporting(E_ALL); ini_set('display_errors', '1');
define('APP_VER', 'test');
$PUSHES = array(); $THROW = array();
function etel_push_send_async($pdo, $uid, $title, $body, $data = array()) { global $PUSHES, $THROW; if (in_array($uid, $THROW, true)) throw new Exception('boom'); $PUSHES[] = array('uid' => $uid, 'title' => $title, 'body' => $body, 'data' => $data); return true; }
// the real rule in boot.php: a legacy (not managed) admin has every capability; a managed one only what its staff role has. Here only the staff role "safety" may view SOS.
function user_has_capability($pdo, $u, $cap) { if (empty($u['is_etelgroup_managed'])) return true; return isset($u['staff_role']) && $u['staff_role'] === 'safety' && $cap === 'sos.view'; }
$PDO = new PDO('mysql:host=localhost;dbname=fa_test;charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false));
function build($withSos = true) {
    global $PDO;
    foreach (array('users', 'sos_alerts') as $t) $PDO->exec('DROP TABLE IF EXISTS `' . $t . '`');
    $PDO->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL DEFAULT '', phone VARCHAR(25) NOT NULL DEFAULT '', role VARCHAR(20) NOT NULL DEFAULT 'customer', active TINYINT(1) NOT NULL DEFAULT 1, staff_role VARCHAR(40) DEFAULT NULL, is_etelgroup_managed TINYINT(1) NOT NULL DEFAULT 0)");
    if ($withSos) $PDO->exec("CREATE TABLE sos_alerts (id INT(11) NOT NULL AUTO_INCREMENT, ride_id INT(11) DEFAULT NULL, reporter_id INT(11) NOT NULL, role VARCHAR(20) NOT NULL DEFAULT 'rider', lat DECIMAL(10,7) DEFAULT NULL, lng DECIMAL(10,7) DEFAULT NULL, message VARCHAR(500) DEFAULT NULL, status VARCHAR(20) NOT NULL DEFAULT 'open', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, resolved_at DATETIME DEFAULT NULL, resolved_by INT(11) DEFAULT NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $PDO->exec("INSERT INTO users (id,name,phone,role,active,staff_role,is_etelgroup_managed) VALUES
      (1,'Owner','0900','admin',1,NULL,0),(2,'Safety desk','0901','admin',1,'safety',1),(3,'Viewer','0902','admin',1,'viewer',1),(4,'Gone admin','0903','admin',0,NULL,0),
      (50,'Abel','0911000050','driver',1,NULL,0),(51,'Sara <b>Rider</b>','0911000051','customer',1,NULL,0)");
}
$PASS = 0; $FAILS = array();
function t($n, $c, $i = '') { global $PASS, $FAILS; if ($c) $PASS++; else $FAILS[] = $n . ($i !== '' ? ' -> ' . $i : ''); }
function pushedIds() { global $PUSHES; $o = array(); foreach ($PUSHES as $p) $o[] = $p['uid']; sort($o); return $o; }
require_once __DIR__ . '/../api/lib/sos_alert.php';

build();
$PDO->exec("INSERT INTO sos_alerts (id, ride_id, reporter_id, role, lat, lng, message, status, created_at) VALUES (1, 77, 50, 'driver', 7.0621000, 38.4761000, 'Passenger is threatening me <script>x</script>', 'open', NOW())");
$n = sos_notify($PDO, 1, false);
t('staff who may view SOS were asked: the legacy admin and the safety desk, not the viewer, the disabled admin, or a driver', $n === 2 && pushedIds() === array(1, 2), json_encode(pushedIds()));
$p = $PUSHES[0];
t('title says who and what role', $p['title'] === 'SOS: Abel (driver)', $p['title']);
t('text has the phone, the trip, the location and the message without tags', strpos($p['body'], 'phone 0911000050') !== false && strpos($p['body'], 'trip #77') !== false && strpos($p['body'], 'location 7.0621,38.4761') !== false && strpos($p['body'], 'threatening me') !== false && strpos($p['body'], '<') === false, $p['body']);
t('the push opens the admin page and carries the SOS id', $p['data']['type'] === 'sos' && $p['data']['sos_id'] === 1 && $p['data']['url'] === '/delivery/admin.html');
$row = $PDO->query('SELECT * FROM sos_alerts WHERE id=1')->fetch();
t('the counters were added to the table by themselves and written: 1 notice, 2 staff, time set', intval($row['notify_count']) === 1 && intval($row['staff_count']) === 2 && $row['notified_at'] !== null, json_encode($row));

// a rider without a ride or location, name with tags
$PDO->exec("INSERT INTO sos_alerts (id, reporter_id, role, message, status, created_at) VALUES (2, 51, 'rider', '', 'open', NOW())");
$PUSHES = array(); sos_notify($PDO, 2, false);
t('a rider with no trip and no location: short text, name without tags', $PUSHES[0]['title'] === 'SOS: Sara Rider (rider)' && strpos($PUSHES[0]['body'], 'trip') === false && strpos($PUSHES[0]['body'], 'location') === false, json_encode($PUSHES[0]));
$long = str_repeat('x', 300); $PDO->exec("INSERT INTO sos_alerts (id, reporter_id, role, message, status, created_at) VALUES (3, 50, 'driver', '$long', 'open', NOW())");
$PUSHES = array(); sos_notify($PDO, 3, false);
t('a long message is cut so the push stays short', strlen($PUSHES[0]['body']) < 200, (string) strlen($PUSHES[0]['body']));
// a push that throws must not break anything
$THROW = array(1); $PUSHES = array(); $PDO->exec("INSERT INTO sos_alerts (id, reporter_id, role, message, status, created_at) VALUES (4, 50, 'driver', 'x', 'open', NOW())");
$n = sos_notify($PDO, 4, false);
t('a failing push does not stop the others and does not throw', $n === 2 && pushedIds() === array(2), json_encode(pushedIds())); $THROW = array();

// escalation
$PDO->exec("DELETE FROM sos_alerts");
$PDO->exec("INSERT INTO sos_alerts (id, reporter_id, role, message, status, created_at, notify_count) VALUES
  (10, 50, 'driver', 'young', 'open', DATE_SUB(NOW(), INTERVAL 1 MINUTE), 1),
  (11, 50, 'driver', 'old and quiet', 'open', DATE_SUB(NOW(), INTERVAL 3 MINUTE), 0),
  (12, 50, 'driver', 'resolved', 'resolved', DATE_SUB(NOW(), INTERVAL 30 MINUTE), 1),
  (13, 51, 'rider', 'announced recently', 'open', DATE_SUB(NOW(), INTERVAL 10 MINUTE), 1),
  (14, 51, 'rider', 'announced long ago', 'open', DATE_SUB(NOW(), INTERVAL 20 MINUTE), 2),
  (15, 51, 'rider', 'announced 5 times already', 'open', DATE_SUB(NOW(), INTERVAL 60 MINUTE), 5)");
$PDO->exec("UPDATE sos_alerts SET notified_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = 13");
$PDO->exec("UPDATE sos_alerts SET notified_at = DATE_SUB(NOW(), INTERVAL 6 MINUTE) WHERE id = 14");
$PDO->exec("UPDATE sos_alerts SET notified_at = DATE_SUB(NOW(), INTERVAL 30 MINUTE) WHERE id = 15");
$PUSHES = array(); $k = sos_escalate($PDO);
$titles = array(); foreach ($PUSHES as $x) $titles[] = $x['title'];
t('escalation announces only the open alert that is 2+ minutes old with no notice yet (11) and the one not announced for 4+ minutes (14)', $k === 2, json_encode(array($k, $titles)));
t('each goes to the 2 staff and says STILL OPEN with the minutes', count($PUSHES) === 4 && strpos($PUSHES[0]['title'], 'SOS STILL OPEN: ') === 0 && preg_match('/open for \d+ min/', $PUSHES[0]['body']) === 1, json_encode($PUSHES[0]));
t('not announced: too young (10), resolved (12), announced a minute ago (13), already announced 5 times (15)', $PDO->query('SELECT notify_count FROM sos_alerts WHERE id=10')->fetchColumn() == 1 && $PDO->query('SELECT notify_count FROM sos_alerts WHERE id=13')->fetchColumn() == 1 && $PDO->query('SELECT notify_count FROM sos_alerts WHERE id=15')->fetchColumn() == 5 && $PDO->query('SELECT notify_count FROM sos_alerts WHERE id=12')->fetchColumn() == 1);
t('the notice counters went up for 11 and 14', $PDO->query('SELECT notify_count FROM sos_alerts WHERE id=11')->fetchColumn() == 1 && $PDO->query('SELECT notify_count FROM sos_alerts WHERE id=14')->fetchColumn() == 3);
$PUSHES = array(); $k = sos_escalate($PDO);
t('running it again right away announces nothing (they were just announced)', $k === 0 && count($PUSHES) === 0, (string) $k);
$PDO->exec("UPDATE sos_alerts SET status='resolved' WHERE id=11");
$PDO->exec("UPDATE sos_alerts SET notified_at = DATE_SUB(NOW(), INTERVAL 9 MINUTE) WHERE id IN (11, 14)");
$PUSHES = array(); $k = sos_escalate($PDO);
t('a resolved alert is never announced again, an open one is', $k === 1 && $PDO->query('SELECT notify_count FROM sos_alerts WHERE id=14')->fetchColumn() == 4);
$PDO->exec("UPDATE sos_alerts SET notified_at = DATE_SUB(NOW(), INTERVAL 9 MINUTE) WHERE id = 14");
sos_escalate($PDO); $PDO->exec("UPDATE sos_alerts SET notified_at = DATE_SUB(NOW(), INTERVAL 9 MINUTE) WHERE id = 14");
$PUSHES = array(); $k = sos_escalate($PDO);
t('it stops after 5 notices in all', $k === 0 && $PDO->query('SELECT notify_count FROM sos_alerts WHERE id=14')->fetchColumn() == 5);

// nobody to tell
$PDO->exec("UPDATE users SET role = 'customer' WHERE role = 'admin'");
$PDO->exec("INSERT INTO sos_alerts (id, reporter_id, role, message, status, created_at) VALUES (20, 50, 'driver', 'x', 'open', NOW())");
$PUSHES = array(); $n = sos_notify($PDO, 20, false);
t('no staff at all: returns 0, no error, and 0 staff is written down', $n === 0 && count($PUSHES) === 0 && $PDO->query('SELECT staff_count FROM sos_alerts WHERE id=20')->fetchColumn() == 0);
// a missing alert or table
t('an alert that does not exist: 0, no error', sos_notify($PDO, 9999, false) === 0);
$PDO->exec('DROP TABLE sos_alerts');
t('no sos_alerts table: 0, no error (both functions)', sos_notify($PDO, 1, false) === 0 && sos_escalate($PDO) === 0);
echo 'PASSED: ' . $PASS . ' FAILED: ' . count($FAILS) . "\n"; foreach ($FAILS as $f) echo ' - ' . $f . "\n"; exit(count($FAILS) ? 1 : 0);

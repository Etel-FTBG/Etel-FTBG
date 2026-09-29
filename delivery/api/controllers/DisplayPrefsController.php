<?php
/**
 * ETEL - Display Size Preferences Controller
 * Actions: get_display_prefs, save_display_prefs
 *
 * Stores each signed-in user's display size choice (text / icon / button /
 * logo size + boldness, see js/etel-display-settings.js) so it follows the
 * person across all of their devices. The user id ALWAYS comes from the
 * auth token, never from the request body, so one account can never read
 * or overwrite another account's setting.
 *
 * The table is created on first use (CREATE TABLE IF NOT EXISTS, outside any
 * transaction) as well as by migration 060, so the code and the migration
 * can be deployed in either order.
 */
if (!defined('APP_VER')) { http_response_code(403); exit('Direct access forbidden'); }

$pdo = db();
$u   = require_auth($pdo);
$uid = (int)$u['id'];

if (!function_exists('display_prefs_ensure_table')) {
    function display_prefs_ensure_table($pdo) {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS user_display_prefs (
                    user_id    INT(11)      NOT NULL,
                    prefs_json VARCHAR(255) NOT NULL,
                    version    INT(11)      NOT NULL DEFAULT 1,
                    updated_at DATETIME     NOT NULL,
                    PRIMARY KEY (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (Exception $e) {
            error_log('[ETEL][display_prefs] create table: ' . $e->getMessage());
        }
    }
}

/* Clamp to the same ranges the client uses (70-200 %, boldness 0-2). */
if (!function_exists('display_prefs_clean')) {
    function display_prefs_clean($p) {
        if (!is_array($p)) return null;
        $out = array();
        foreach (array('ts', 'is', 'bs', 'ls') as $k) {
            $v = isset($p[$k]) ? (int)$p[$k] : 100;
            $out[$k] = max(70, min(200, $v));
        }
        $fw = isset($p['fw']) ? (int)$p['fw'] : 0;
        $out['fw'] = max(0, min(2, $fw));
        return $out;
    }
}

display_prefs_ensure_table($pdo);

switch ($action) {

case 'get_display_prefs': {
    try {
        $s = $pdo->prepare('SELECT prefs_json, version FROM user_display_prefs WHERE user_id = ? LIMIT 1');
        $s->execute(array($uid));
        $row = $s->fetch();
    } catch (Exception $e) {
        fail_ex($e, 'Could not load display settings');
    }
    if (!$row) { ok(array('prefs' => null, 'v' => 0)); }
    $prefs = display_prefs_clean(json_decode($row['prefs_json'], true));
    ok(array('prefs' => $prefs, 'v' => (int)$row['version']));
    break;
}

case 'save_display_prefs': {
    $prefs = display_prefs_clean(isset($body['prefs']) ? $body['prefs'] : null);
    if ($prefs === null) fail('Invalid display settings', 400);
    $json = json_encode($prefs);
    $now  = date('Y-m-d H:i:s');
    try {
        /* UPDATE first (bumps the version); INSERT only when the user has no
           row yet. A concurrent first save from two devices can make the
           INSERT hit the primary key - then just UPDATE again. */
        $up = $pdo->prepare('UPDATE user_display_prefs SET prefs_json = ?, version = version + 1, updated_at = ? WHERE user_id = ?');
        $up->execute(array($json, $now, $uid));
        if ($up->rowCount() === 0) {
            try {
                $in = $pdo->prepare('INSERT INTO user_display_prefs (user_id, prefs_json, version, updated_at) VALUES (?, ?, 1, ?)');
                $in->execute(array($uid, $json, $now));
            } catch (Exception $e2) {
                $up->execute(array($json, $now, $uid));
            }
        }
        $s = $pdo->prepare('SELECT version FROM user_display_prefs WHERE user_id = ? LIMIT 1');
        $s->execute(array($uid));
        $v = (int)$s->fetchColumn();
    } catch (Exception $e) {
        fail_ex($e, 'Could not save display settings');
    }
    ok(array('prefs' => $prefs, 'v' => $v));
    break;
}

default:
    fail('Unknown action: ' . $action, 404);
}

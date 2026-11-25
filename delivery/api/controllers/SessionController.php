<?php
// ============================================================
// ETEL Delivery - automatic sign-out when the app is not used (see api/lib/idle_session.php).
//   stream_ticket     { for: 'sse_...', id? }  -> { ticket, expires_in }   a 2-minute sealed ticket for one live stream (any signed-in person)
//   session_ping      { active: true|false }   -> { idle_minutes, exempt, seconds_left }                 (any signed-in person)
//        active:true  = the person used the app just now (a tap, a key); active:false = only asks for the numbers (does not count as use)
//   admin_idle_get    {}                         -> { settings: {role: minutes}, defaults, env_off }          (settings.view)
//   admin_idle_save   { settings: {role: minutes} }  -> the same, read back                                  (settings.manage)
// ============================================================
require_once __DIR__ . '/../lib/idle_session.php';

switch ($action) {

case 'session_ping': {
    $pdo = db();
    $u = require_auth($pdo);
    $token = get_bearer();
    $active = !empty($body['active']);
    ok(etel_idle_ping($pdo, intval($u['id']), (string) $u['role'], $token, $active));
    break;
}

case 'stream_ticket': {
    /* A 2-minute sealed ticket for one live stream, so the login token is not put in the web address (see lib/stream_ticket.php). */
    $pdo = db();
    $u = require_auth($pdo);
    require_once __DIR__ . '/../lib/stream_ticket.php';
    $for = isset($body['for']) ? (string) $body['for'] : '';
    $beacon = (bool) preg_match('/^[a-z_]{3,40}_beacon_ping$/', $for);   /* a beacon is sent when the tab is hidden, so its ticket is made ahead: 10 minutes */
    if (!$beacon && !preg_match('/^sse_[a-z_]{3,60}$/', $for)) fail('Unknown stream', 400);
    $ttl = $beacon ? 600 : 120;
    $tk = etel_ticket_make(get_bearer(), $for, isset($body['id']) ? (string) $body['id'] : '', $ttl);
    if ($tk === '') fail('Stream tickets are not available on this server', 501);
    ok(array('ticket' => $tk, 'expires_in' => $ttl));
    break;
}

case 'admin_idle_get': {
    $pdo = db(); require_admin($pdo, 'settings.view');
    ok(array('settings' => etel_idle_settings($pdo), 'defaults' => etel_idle_defaults(), 'env_off' => etel_idle_env_off()));
    break;
}

case 'admin_idle_save': {
    $pdo = db(); require_admin($pdo, 'settings.manage');
    $in = isset($body['settings']) && is_array($body['settings']) ? $body['settings'] : array();
    $roles = etel_idle_roles();
    foreach ($in as $role => $m) {
        if (!in_array($role, $roles, true)) continue;
        if (!is_numeric($m)) fail('Minutes must be a number (0 = never sign out)', 400);
        $m = intval($m);
        if ($m < 0 || $m > 1440) fail('Minutes must be between 0 and 1440', 400);
        if ($m > 0 && $m < 5) fail('At least 5 minutes (or 0 for never)', 400);
        $pdo->prepare("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE rule_value = VALUES(rule_value)")
            ->execute(array('idle_minutes_' . $role, (string) $m, 'Sign out after this many minutes without using the app: ' . $role . ' (0 = never)'));
    }
    ok(array('settings' => etel_idle_settings($pdo), 'defaults' => etel_idle_defaults(), 'env_off' => etel_idle_env_off()));
    break;
}
}

<?php
// ============================================================
// ETEL Delivery - Etel Shield, the owner's side (see api/lib/shield.php). All actions need an admin login.
//   admin_shield_get     {}                                  -> { config, defaults, stats, me, env_off }                    (settings.view)
//   admin_shield_save    { config: {...} }                   -> the same, read back                                         (settings.manage)
//   admin_shield_events  { limit?, rule?, ip? }              -> { events: [...] }                                           (settings.view)
//   admin_shield_bans    {}                                  -> { bans: [...] }                                             (settings.view)
//   admin_shield_ban     { ip, minutes, reason? }            -> { bans }                                                    (settings.manage)
//   admin_shield_unban   { ip }                              -> { bans }                                                    (settings.manage)
// ============================================================
require_once __DIR__ . '/../lib/shield.php';

function etel_shield_stats($pdo) {
    $out = array('events_24h' => 0, 'by_rule' => array(), 'top_ips' => array(), 'bans_active' => 0, 'would_block_24h' => 0, 'blocked_24h' => 0);
    try {
        etel_shield_ensure_tables($pdo);
        $out['events_24h'] = intval($pdo->query("SELECT COUNT(*) FROM shield_events WHERE at > DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn());
        foreach ($pdo->query("SELECT rule, mode, COUNT(*) AS n FROM shield_events WHERE at > DATE_SUB(NOW(), INTERVAL 1 DAY) GROUP BY rule, mode ORDER BY n DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out['by_rule'][] = array('rule' => $r['rule'], 'mode' => $r['mode'], 'n' => intval($r['n']));
            if ($r['mode'] === 'monitor') $out['would_block_24h'] += intval($r['n']);
            if ($r['mode'] === 'block') $out['blocked_24h'] += intval($r['n']);
        }
        foreach ($pdo->query("SELECT ip, COUNT(*) AS n, MAX(at) AS last_at FROM shield_events WHERE at > DATE_SUB(NOW(), INTERVAL 1 DAY) GROUP BY ip ORDER BY n DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out['top_ips'][] = array('ip' => $r['ip'], 'n' => intval($r['n']), 'last_at' => $r['last_at']);
        }
        $out['bans_active'] = intval($pdo->query("SELECT COUNT(*) FROM shield_bans WHERE until_at > NOW()")->fetchColumn());
    } catch (Exception $e) { /* empty stats */ }
    return $out;
}

function etel_shield_bans_list($pdo) {
    etel_shield_ensure_tables($pdo);
    $q = $pdo->query("SELECT ip, reason, until_at, created_at, created_by, GREATEST(0, TIMESTAMPDIFF(MINUTE, NOW(), until_at)) AS minutes_left FROM shield_bans WHERE until_at > NOW() ORDER BY until_at DESC LIMIT 300");
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function etel_shield_view($pdo) {
    $cfg = etel_shield_cfg($pdo);
    $c = etel_shield_client($cfg);
    return array(
        'config' => $cfg, 'defaults' => etel_shield_defaults(), 'stats' => etel_shield_stats($pdo), 'env_off' => etel_shield_env_off(),
        'me' => array('ip' => $c[0], 'country' => $c[1], 'remote_addr' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '',
                      'allowed' => etel_shield_in_list($c[0], $cfg['allow_ips'])),
    );
}

switch ($action) {

case 'admin_shield_get': {
    $pdo = db(); require_admin($pdo, 'settings.view');
    ok(etel_shield_view($pdo));
    break;
}

case 'admin_shield_save': {
    $pdo = db(); require_admin($pdo, 'settings.manage');
    $in = isset($body['config']) && is_array($body['config']) ? $body['config'] : array();
    $cur = etel_shield_cfg($pdo);
    $new = array_merge($cur, $in);        /* keys that are not sent keep their value */
    foreach (array('allow_ips', 'deny_ips') as $k) {
        if (isset($in[$k]) && is_array($in[$k])) {
            foreach ($in[$k] as $ip) { $ip = trim((string) $ip); if ($ip !== '' && !etel_shield_valid_ip_or_cidr($ip)) fail('Not a valid IP address: ' . substr($ip, 0, 45), 400); }
        }
    }
    /* never let the owner block their own connection by mistake */
    $me = etel_shield_client(etel_shield_clean($new));
    if (!empty($new['deny_ips']) && is_array($new['deny_ips']) && etel_shield_in_list($me[0], $new['deny_ips']) && !etel_shield_in_list($me[0], isset($new['allow_ips']) ? $new['allow_ips'] : array())) {
        fail('That list would block your own connection (' . $me[0] . '). Add it to the allowed addresses first.', 400);
    }
    etel_shield_cfg_save($pdo, $new);
    ok(etel_shield_view($pdo));
    break;
}

case 'admin_shield_events': {
    $pdo = db(); require_admin($pdo, 'settings.view');
    etel_shield_ensure_tables($pdo);
    $limit = isset($body['limit']) ? max(1, min(500, intval($body['limit']))) : 100;
    $w = array('1=1'); $p = array();
    if (!empty($body['rule'])) { $w[] = 'rule LIKE ?'; $p[] = substr((string) $body['rule'], 0, 40) . '%'; }
    if (!empty($body['ip'])) { $w[] = 'ip = ?'; $p[] = substr((string) $body['ip'], 0, 45); }
    $q = $pdo->prepare("SELECT id, at, ip, rule, mode, action, detail, ua, country FROM shield_events WHERE " . implode(' AND ', $w) . " ORDER BY id DESC LIMIT $limit");
    $q->execute($p);
    ok(array('events' => $q->fetchAll(PDO::FETCH_ASSOC)));
    break;
}

case 'admin_shield_bans': {
    $pdo = db(); require_admin($pdo, 'settings.view');
    ok(array('bans' => etel_shield_bans_list($pdo)));
    break;
}

case 'admin_shield_ban': {
    $pdo = db(); $adm = require_admin($pdo, 'settings.manage');
    $ip = isset($body['ip']) ? trim((string) $body['ip']) : '';
    if ($ip === '' || @inet_pton($ip) === false) fail('Write a valid IP address (one address, not a range)', 400);
    $cfg = etel_shield_cfg($pdo); $me = etel_shield_client($cfg);
    if ($ip === $me[0]) fail('That is your own connection: you would block yourself.', 400);
    if (etel_shield_is_internal($ip) || etel_shield_in_list($ip, $cfg['allow_ips'])) fail('That address is on the allowed list (or is this server itself).', 400);
    $minutes = isset($body['minutes']) ? intval($body['minutes']) : 60;
    if ($minutes < 1 || $minutes > 525600) fail('Minutes must be between 1 and 525600 (one year)', 400);
    etel_shield_ban($pdo, $ip, $minutes, isset($body['reason']) ? trim((string) $body['reason']) : 'blocked by an admin', 'admin:' . intval($adm['id']));
    ok(array('bans' => etel_shield_bans_list($pdo)));
    break;
}

case 'admin_shield_unban': {
    $pdo = db(); require_admin($pdo, 'settings.manage');
    $ip = isset($body['ip']) ? trim((string) $body['ip']) : '';
    if ($ip === '') fail('ip is required', 400);
    etel_shield_unban($pdo, $ip);
    ok(array('bans' => etel_shield_bans_list($pdo)));
    break;
}
}

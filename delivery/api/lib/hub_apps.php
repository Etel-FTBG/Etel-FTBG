<?php
// ============================================================
// ETEL app (hub) - which apps are visible. Table app_hub_apps (migration 111); no row = the built-in default.
//   mode  on     visible to everyone who may open the app (the app still checks role and programme membership)
//         admins visible to admin accounts only (test an app before launch)
//         off    hidden for everyone
// The built-in list below must match Etel-Driver-App/src/hub/apps.ts (same keys). An unknown key is refused.
// Works when the table is not migrated yet: every app keeps its default.
// ============================================================
if (!function_exists('hub_apps_catalogue')) {

/* key => array(title, group, default mode, default address path ('' = none yet, the owner must type one), who, kind)
   kind 'app' = a tile in the hub; kind 'feature' = a switch for a feature of the phone app (no address). */
function hub_apps_catalogue() {
    return array(
        'id'       => array('EtelID', 'Identity', 'on', '/EtelId/', 'Everyone', 'app'),
        'ride'     => array('ETEL Ride', 'Mobility', 'on', '/delivery/ride/', 'Everyone', 'app'),
        'driver'   => array('ETEL Driver', 'Mobility', 'on', '/delivery/driver/', 'Driver and courier accounts', 'app'),
        'courier'  => array('ETEL Courier', 'Delivery and services', 'on', '/delivery/courier/', 'Courier accounts', 'app'),
        'customer' => array('ETEL Delivery', 'Delivery and services', 'on', '/delivery/customer.html', 'Everyone', 'app'),
        'service'  => array('ETEL Services', 'Delivery and services', 'on', '/delivery/servicegiver.html', 'Service giver accounts', 'app'),
        'investor' => array('ETEL Investor', 'My programmes', 'on', '/delivery/investor/', 'Investors only', 'app'),
        'equb'     => array('ETEL Equb', 'My programmes', 'on', '/delivery/equb/', 'Equb members only', 'app'),
        'agent'    => array('ETEL Agent', 'My programmes', 'on', '/delivery/agent/', 'Approved agents only', 'app'),
        'admin'    => array('ETEL Admin', 'Staff', 'off', '/delivery/admin.html', 'Admin accounts only', 'app'),
        'pay'      => array('EtelPay', 'Finance and trade', 'off', '', 'Everyone', 'app'),
        'madeya'   => array('EtelMadeya', 'Finance and trade', 'off', '', 'Everyone', 'app'),
        'gebeya'   => array('EtelGebeya', 'Finance and trade', 'off', '', 'Everyone', 'app'),
        'light'       => array('Floating golden light', 'Features', 'on', '', 'Everyone (the light that shows over other apps)', 'feature'),
        'light_alert' => array('Sound and vibration for a new request or order', 'Features', 'on', '', 'Everyone (needs the light)', 'feature'),
    );
}

function hub_apps_modes() { return array('on', 'admins', 'off'); }

/* key => array(mode, url) for every app the owner changed. */
function hub_apps_overrides($pdo) {
    $out = array();
    try {
        foreach ($pdo->query('SELECT app_key, mode, url FROM app_hub_apps')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['app_key']] = array('mode' => $r['mode'], 'url' => $r['url']);
        }
    } catch (Exception $e) { /* not migrated: defaults */ }
    return $out;
}

/* '' when the address is fine, else a plain error. Only https on the site's own host, so the app never opens a stranger's page inside itself. */
function hub_apps_url_error($url, $host) {
    $url = trim((string) $url);
    if ($url === '') return '';
    if (strlen($url) > 255) return 'The address is too long.';
    if (preg_match('/[\s"\'<>\\\\]/', $url)) return 'The address has a character that is not allowed.';
    $p = @parse_url($url);
    if (!$p || empty($p['scheme']) || strtolower($p['scheme']) !== 'https' || empty($p['host'])) return 'The address must start with https://';
    $h = strtolower(preg_replace('/^www\./', '', $p['host'])); $mine = strtolower(preg_replace('/^www\./', '', preg_replace('/:\d+$/', '', (string) $host)));
    if ($mine === '' || $h !== $mine) return 'The address must be on this website (' . $mine . ').';
    if (isset($p['user']) || isset($p['pass'])) return 'The address must not contain a user name.';
    if (empty($p['path']) || $p['path'][0] !== '/') return 'The address needs a path, for example https://' . $mine . '/Etel.Pay/wallet/';
    return '';
}

/* What the owner sees: every known app with its default and its current setting. */
function hub_apps_admin_view($pdo) {
    $ov = hub_apps_overrides($pdo); $out = array();
    foreach (hub_apps_catalogue() as $key => $c) {
        $o = isset($ov[$key]) ? $ov[$key] : null;
        $out[] = array('key' => $key, 'title' => $c[0], 'group' => $c[1], 'default_mode' => $c[2], 'default_path' => $c[3], 'who' => $c[4],
                       'kind' => $c[5], 'needs_url' => ($c[5] === 'app' && $c[3] === '') ? 1 : 0,
                       'mode' => $o ? $o['mode'] : $c[2], 'url' => $o ? $o['url'] : '', 'changed' => $o ? 1 : 0);
    }
    return $out;
}

/* Saves one app. Returns '' or an error text. */
function hub_apps_save($pdo, $adminId, $key, $mode, $url, $host) {
    $cat = hub_apps_catalogue();
    if (!isset($cat[$key])) return 'Unknown app.';
    if (!in_array($mode, hub_apps_modes(), true)) return 'Choose Visible, Admins only or Hidden.';
    $url = trim((string) $url);
    if ($cat[$key][5] === 'feature') $url = '';                                    // a feature switch has no address
    $err = hub_apps_url_error($url, $host);
    if ($err !== '') return $err;
    if ($mode !== 'off' && $cat[$key][5] === 'app' && $cat[$key][3] === '' && $url === '') return 'Type the address of this app first. It has no built-in address yet.';
    $pdo->prepare('INSERT INTO app_hub_apps (app_key, mode, url, updated_by, updated_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE mode = VALUES(mode), url = VALUES(url), updated_by = VALUES(updated_by), updated_at = NOW()')
        ->execute(array($key, $mode, $url, intval($adminId)));
    return '';
}

/* What the phone app asks for: only the apps the owner changed. */
function hub_apps_public($pdo) {
    $cat = hub_apps_catalogue(); $out = array();
    foreach (hub_apps_overrides($pdo) as $key => $o) {
        if (!isset($cat[$key])) continue;
        $out[$key] = array('mode' => $o['mode'], 'url' => $o['url']);
    }
    return $out;
}

}

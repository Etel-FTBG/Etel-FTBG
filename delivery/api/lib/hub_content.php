<?php
// ============================================================
// ETEL app (hub) - what the owner can change about how things look (tables app_hub_content and app_hub_settings, migration 112).
//   per app : names and short descriptions (English and Amharic), a small label (New, Beta, Soon), the order, an icon picture
//   settings: welcome title and tagline (English and Amharic), support phone / Telegram / email, and one home banner
// An app or setting with no row keeps its built-in value. Works when the tables are not migrated yet (nothing changes).
// Every text is stored as plain text (tags removed): the phone app and the admin page both show it as text, never as markup.
// ============================================================
if (!function_exists('hub_content_clean_text')) {

function hub_content_clean_text($v, $max) {
    $v = strip_tags((string) $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v);
    $v = trim(preg_replace('/\s+/', ' ', $v));
    return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}

/* '' when fine, else the reason. Only a picture the admin uploaded here (https, this website, /uploads/hub/). */
function hub_content_icon_error($url, $host) {
    $url = trim((string) $url);
    if ($url === '') return '';
    if (strlen($url) > 255) return 'The picture address is too long.';
    $p = @parse_url($url);
    if (!$p || empty($p['scheme']) || empty($p['host']) || empty($p['path'])) return 'The picture must be uploaded here (https address on this website).';
    $h = strtolower(preg_replace('/^www\./', '', $p['host'])); $mine = strtolower(preg_replace('/^www\./', '', preg_replace('/:\d+$/', '', (string) $host)));
    $local = in_array($mine, array('127.0.0.1', 'localhost'), true);          // a developer's own computer has no https
    if (strtolower($p['scheme']) !== 'https' && !($local && strtolower($p['scheme']) === 'http')) return 'The picture must be uploaded here (https address on this website).';
    if ($mine === '' || $h !== $mine) return 'The picture must be on this website (' . $mine . ').';
    if (strpos($p['path'], '/uploads/hub/') === false || strpos($p['path'], '..') !== false || !empty($p['query'])) return 'The picture must come from the upload button on this page.';
    if (!preg_match('/\.(png|jpe?g|webp|gif)$/i', $p['path'])) return 'The picture must be a PNG, JPG, WEBP or GIF file.';
    return '';
}

/* Cleans what the owner typed for one app. Returns array(clean, error). Only the fields that were sent are returned. */
function hub_content_clean($in, $host) {
    $lim = array('title_en' => 40, 'title_am' => 40, 'desc_en' => 80, 'desc_am' => 80, 'badge' => 14);
    $c = array();
    foreach ($lim as $k => $max) { if (array_key_exists($k, $in)) $c[$k] = hub_content_clean_text($in[$k], $max); }
    if (array_key_exists('sort_order', $in)) {
        if ($in['sort_order'] === '' || $in['sort_order'] === null) $in['sort_order'] = 0;
        if (!is_numeric($in['sort_order']) || intval($in['sort_order']) < 0 || intval($in['sort_order']) > 99) return array(null, 'The order must be a number from 0 to 99 (0 = built-in order).');
        $c['sort_order'] = intval($in['sort_order']);
    }
    if (array_key_exists('icon_url', $in)) {
        $err = hub_content_icon_error($in['icon_url'], $host);
        if ($err !== '') return array(null, $err);
        $c['icon_url'] = trim((string) $in['icon_url']);
    }
    return array($c, '');
}

function hub_content_all($pdo) {
    $out = array();
    try {
        foreach ($pdo->query('SELECT app_key, title_en, title_am, desc_en, desc_am, badge, sort_order, icon_url FROM app_hub_content')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = $r['app_key']; unset($r['app_key']); $r['sort_order'] = intval($r['sort_order']); $out[$k] = $r;
        }
    } catch (Exception $e) { /* not migrated: built-in values */ }
    return $out;
}

/* Saves the fields of one app (only those sent). Returns '' or an error text. */
function hub_content_save($pdo, $adminId, $key, $fields, $host) {
    require_once __DIR__ . '/hub_apps.php';
    $cat = hub_apps_catalogue();
    if (!isset($cat[$key]) || $cat[$key][5] !== 'app') return 'Unknown app.';
    list($c, $err) = hub_content_clean($fields, $host);
    if ($err !== '') return $err;
    if (!$c) return 'Nothing to change.';
    $cols = array('title_en' => '', 'title_am' => '', 'desc_en' => '', 'desc_am' => '', 'badge' => '', 'sort_order' => 0, 'icon_url' => '');
    $cur = hub_content_all($pdo); $base = isset($cur[$key]) ? $cur[$key] : $cols;
    $new = array_merge($cols, $base, $c);
    $pdo->prepare('INSERT INTO app_hub_content (app_key, title_en, title_am, desc_en, desc_am, badge, sort_order, icon_url, updated_by, updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())
                   ON DUPLICATE KEY UPDATE title_en = VALUES(title_en), title_am = VALUES(title_am), desc_en = VALUES(desc_en), desc_am = VALUES(desc_am), badge = VALUES(badge),
                   sort_order = VALUES(sort_order), icon_url = VALUES(icon_url), updated_by = VALUES(updated_by), updated_at = NOW()')
        ->execute(array($key, $new['title_en'], $new['title_am'], $new['desc_en'], $new['desc_am'], $new['badge'], $new['sort_order'], $new['icon_url'], intval($adminId)));
    return '';
}

/* The settings the owner may set, with the rule for each. */
function hub_settings_rules() {
    return array(
        'welcome_title_en' => 'text60', 'welcome_title_am' => 'text60', 'tagline_en' => 'text80', 'tagline_am' => 'text80',
        'support_phone' => 'phone', 'support_telegram' => 'telegram', 'support_email' => 'email',
        'banner_enabled' => 'flag', 'banner_title_en' => 'text60', 'banner_title_am' => 'text60', 'banner_text_en' => 'text160', 'banner_text_am' => 'text160',
        'banner_image_url' => 'image', 'banner_link_app' => 'appkey', 'banner_audience' => 'audience', 'banner_starts_at' => 'date', 'banner_ends_at' => 'date',
        'maint_enabled' => 'flag', 'maint_text_en' => 'text160', 'maint_text_am' => 'text160',
        'min_version_android' => 'version', 'update_text_en' => 'text160', 'update_text_am' => 'text160', 'update_url_android' => 'storeurl',
        'trust_block_expired_docs' => 'flag', 'trust_block_fake_gps' => 'flag',
    );
}

/* Returns array(clean value, error). */
function hub_settings_clean_one($rule, $v, $host) {
    $v = is_scalar($v) ? trim((string) $v) : '';
    if ($v === '') return array('', '');
    switch ($rule) {
        case 'text60': return array(hub_content_clean_text($v, 60), '');
        case 'text80': return array(hub_content_clean_text($v, 80), '');
        case 'text160': return array(hub_content_clean_text($v, 160), '');
        case 'phone': return preg_match('/^\+?[0-9][0-9 ()\-]{4,19}$/', $v) ? array($v, '') : array('', 'The phone number looks wrong (use digits, for example +251911234567).');
        case 'telegram': {
            if (preg_match('~^https://t\.me/[A-Za-z0-9_]{4,32}$~', $v)) return array($v, '');
            if (preg_match('/^@?[A-Za-z0-9_]{4,32}$/', $v)) return array('https://t.me/' . ltrim($v, '@'), '');
            return array('', 'Telegram must be a name like @etelsupport or a https://t.me/... link.');
        }
        case 'email': return (filter_var($v, FILTER_VALIDATE_EMAIL) && strlen($v) <= 120) ? array($v, '') : array('', 'The email address looks wrong.');
        case 'flag': return array(($v === '1' || $v === 'true' || $v === 'on') ? '1' : '0', '');
        case 'image': { $e = hub_content_icon_error($v, $host); return $e === '' ? array($v, '') : array('', $e); }
        case 'appkey': { require_once __DIR__ . '/hub_apps.php'; $cat = hub_apps_catalogue(); return (isset($cat[$v]) && $cat[$v][5] === 'app') ? array($v, '') : array('', 'The banner link must be one of the apps.'); }
        case 'audience': return in_array($v, array('all', 'driver', 'courier', 'service_giver', 'customer'), true) ? array($v, '') : array('', 'Choose who sees the banner.');
        case 'version': return preg_match('/^[0-9]{1,3}(\.[0-9]{1,3}){1,2}$/', $v) ? array($v, '') : array('', 'The version must look like 1.7.0 (numbers and dots).');
        case 'storeurl': { $e = hub_status_url_error($v, $host); return $e === '' ? array($v, '') : array('', $e); }
        case 'date': { $t = strtotime($v); return $t ? array(date('Y-m-d H:i', $t), '') : array('', 'The date looks wrong (for example 2026-12-01 08:00).'); }
    }
    return array('', 'Unknown setting.');
}

/* '' when fine. The update button opens this address on the phone: https only, and only the Play Store, this website or an Expo download page. */
function hub_status_url_error($url, $host) {
    $url = trim((string) $url);
    if ($url === '') return '';
    if (strlen($url) > 255) return 'The address is too long.';
    if (preg_match('/[\s"\'<>\\\\]/', $url)) return 'The address has a character that is not allowed.';
    $p = @parse_url($url);
    if (!$p || empty($p['scheme']) || strtolower($p['scheme']) !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) return 'The update address must start with https://';
    $h = strtolower($p['host']); $mine = strtolower(preg_replace('/^www\./', '', preg_replace('/:\d+$/', '', (string) $host)));
    $okHost = ($h === 'play.google.com') || ($h === 'expo.dev') || (substr($h, -9) === '.expo.dev') || ($mine !== '' && preg_replace('/^www\./', '', $h) === $mine);
    return $okHost ? '' : 'The update address must be a Google Play link (play.google.com), an Expo download link (expo.dev) or on this website (' . $mine . ').';
}

/* What a phone needs to know before it shows anything: is the app in maintenance, and is this version too old. $version is the phone's own version. */
function hub_status_public($pdo, $version) {
    $set = hub_settings_all($pdo);
    $g = function ($k) use ($set) { return isset($set[$k]) ? (string) $set[$k] : ''; };
    $min = $g('min_version_android');
    $version = trim((string) $version);
    $required = ($min !== '' && preg_match('/^[0-9]{1,3}(\.[0-9]{1,3}){0,2}$/', $version) && version_compare($version, $min, '<'));
    return array(
        'maintenance' => array('on' => $g('maint_enabled') === '1', 'text_en' => $g('maint_text_en'), 'text_am' => $g('maint_text_am')),
        'update' => array('required' => $required, 'min' => $min, 'text_en' => $g('update_text_en'), 'text_am' => $g('update_text_am'), 'url' => $g('update_url_android')),
    );
}

function hub_settings_all($pdo) {
    $out = array();
    try { foreach ($pdo->query('SELECT setting_key, setting_value FROM app_hub_settings')->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['setting_key']] = $r['setting_value']; }
    catch (Exception $e) { /* not migrated */ }
    return $out;
}

/* Saves several settings at once (all or nothing for the checks). An empty value removes the setting. Returns '' or an error text. */
function hub_settings_save($pdo, $adminId, $map, $host) {
    $rules = hub_settings_rules(); $clean = array();
    foreach ($map as $k => $v) {
        if (!isset($rules[$k])) return 'Unknown setting: ' . substr(preg_replace('/[^A-Za-z0-9_]/', '', (string) $k), 0, 40);
        list($val, $err) = hub_settings_clean_one($rules[$k], $v, $host);
        if ($err !== '') return $err;
        $clean[$k] = $val;
    }
    if (!$clean) return 'Nothing to change.';
    $up = $pdo->prepare('INSERT INTO app_hub_settings (setting_key, setting_value, updated_by, updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()');
    $del = $pdo->prepare('DELETE FROM app_hub_settings WHERE setting_key = ?');
    foreach ($clean as $k => $val) { if ($val === '') $del->execute(array($k)); else $up->execute(array($k, $val, intval($adminId))); }
    return '';
}

/* The banner if it is on, inside its dates and meant for this role; else null. $now = a timestamp. */
function hub_banner_active($settings, $role, $now) {
    if (empty($settings['banner_enabled']) || $settings['banner_enabled'] !== '1') return null;
    $title = isset($settings['banner_title_en']) ? $settings['banner_title_en'] : ''; $titleAm = isset($settings['banner_title_am']) ? $settings['banner_title_am'] : '';
    $text = isset($settings['banner_text_en']) ? $settings['banner_text_en'] : ''; $textAm = isset($settings['banner_text_am']) ? $settings['banner_text_am'] : '';
    $img = isset($settings['banner_image_url']) ? $settings['banner_image_url'] : '';
    if ($title === '' && $titleAm === '' && $text === '' && $textAm === '' && $img === '') return null;
    if (!empty($settings['banner_starts_at']) && $now < strtotime($settings['banner_starts_at'])) return null;
    if (!empty($settings['banner_ends_at']) && $now > strtotime($settings['banner_ends_at'])) return null;
    $aud = isset($settings['banner_audience']) && $settings['banner_audience'] !== '' ? $settings['banner_audience'] : 'all';
    if ($aud !== 'all' && $aud !== $role) return null;
    return array('title_en' => $title, 'title_am' => $titleAm, 'text_en' => $text, 'text_am' => $textAm, 'image_url' => $img,
                 'link_app' => isset($settings['banner_link_app']) ? $settings['banner_link_app'] : '',
                 'id' => substr(md5($title . '|' . $titleAm . '|' . $text . '|' . $textAm . '|' . $img . '|' . (isset($settings['banner_ends_at']) ? $settings['banner_ends_at'] : '')), 0, 12));
}

/* What the phone asks for (in addition to the app switches). */
function hub_content_public($pdo, $role) {
    $s = hub_settings_all($pdo);
    $texts = array();
    foreach (array('welcome_title_en', 'welcome_title_am', 'tagline_en', 'tagline_am', 'support_phone', 'support_telegram', 'support_email') as $k) { if (isset($s[$k]) && $s[$k] !== '') $texts[$k] = $s[$k]; }
    return array('content' => hub_content_all($pdo), 'texts' => $texts, 'banner' => hub_banner_active($s, (string) $role, time()));
}

}

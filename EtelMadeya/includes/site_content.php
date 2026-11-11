<?php
// ═══════════════════════════════════════════════════════════════════
// ETEL MADEYA - SITE CONTENT (header / footer / driver access) managed from Admin > Settings
// Everything falls back to the old hard-coded values, so nothing breaks before an admin edits it.
// ═══════════════════════════════════════════════════════════════════

/** "English | አማርኛ" -> ['en' => ..., 'am' => ...] */
function madeyaPair($line) {
    $p = array_map('trim', explode('|', (string)$line, 2));
    return ['en' => $p[0], 'am' => $p[1] ?? ''];
}

function madeyaSite() {
    static $c = null;
    if ($c !== null) return $c;
    $s = [];
    try {
        // db() die()s when MySQL is unreachable; probe first so the public page still renders with defaults.
        new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
        require_once __DIR__ . '/database-new.php';
        foreach (db()->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'site\\_%' OR setting_key LIKE 'footer\\_%' OR setting_key = 'require_etelid_drivers'") as $r) {
            $s[$r['setting_key']] = (string)$r['setting_value'];
        }
    } catch (Exception $e) { /* settings table unavailable: use defaults */ }
    $get = function ($k, $d) use ($s) { return (isset($s[$k]) && trim($s[$k]) !== '') ? trim($s[$k]) : $d; };

    $infoDefault = "Real-time queue updates | የቅጽበታዊ ወረፋ ዝመናዎች\nFair queue management | ፍትሃዊ ወረፋ አስተዳደር\nMultiple vehicle types | በርካታ የተሽከርካሪ ዓይነቶች";
    $linksDefault = "Check Position | #check\nLive Stations | #stations\nAbout | #about";

    $links = [];
    foreach (preg_split('/\r?\n/', $get('footer_links', $linksDefault)) as $ln) {
        $ln = trim($ln); if ($ln === '') continue;
        $p = array_map('trim', explode('|', $ln, 2));
        if (count($p) < 2 || $p[0] === '' || $p[1] === '') continue;
        $url = $p[1];
        if (!preg_match('~^(#[a-z]+|https?://|/|mailto:|tel:)~i', $url)) continue; // no javascript: etc.
        $links[] = ['label' => $p[0], 'url' => $url];
    }
    $info = [];
    foreach (preg_split('/\r?\n/', $get('footer_info', $infoDefault)) as $ln) {
        if (trim($ln) !== '') $info[] = madeyaPair($ln);
    }

    $c = [
        'phone'     => $get('site_phone', SITE_PHONE),
        'email'     => $get('site_email', SITE_EMAIL),
        'address'   => $get('site_address', SITE_ADDRESS),
        'hours'     => $get('site_hours', SITE_HOURS),
        'tagline'   => madeyaPair($get('site_tagline', 'Modern gas station queue management for Ethiopia. | ለኢትዮጵያ ዘመናዊ የነዳጅ ወረፋ አስተዳደር።')),
        'copyright' => $get('site_copyright', 'Etel Madeya. All rights reserved.'),
        'powered'   => madeyaPair($get('site_powered', 'Powered by ETEL | በኢትል የተጎለበተ')),
        'links'     => $links,
        'info'      => $info,
        'require_login' => (($s['require_etelid_drivers'] ?? '0') === '1'),
    ];
    return $c;
}

/** The EtelID member signed in on the public driver pages, or null. */
function madeyaDriverMember() {
    return (isset($_SESSION['driver_member']) && is_array($_SESSION['driver_member'])) ? $_SESSION['driver_member'] : null;
}

/** May this visitor use the driver tools (Check Position / Live Stations)? */
function madeyaDriverAuthorized() {
    $c = madeyaSite();
    if (!$c['require_login']) return true;
    return madeyaDriverMember() !== null || isset($_SESSION['controller_id']) || isset($_SESSION['admin_id']);
}

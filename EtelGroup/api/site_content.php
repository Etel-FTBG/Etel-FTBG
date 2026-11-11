<?php
// ============================================================
// Etel Group master page - editable page content (hero, contact, socials, footer, leadership).
// The admin edits these from "Site content"; the public page fetches them (get_site_content) and replaces only the
// texts that have a saved value - nothing saved = the built-in text stays. Self-healing table, no manual migration.
// ============================================================
declare(strict_types=1);

function eg_ensure_site_content_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS site_content (
            k VARCHAR(60) NOT NULL,
            en TEXT NULL,
            am TEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (k)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/** The editable fields. type: text | area | url. bi = has an Amharic version too. */
function eg_site_content_schema(): array {
    $g = array();
    $g[] = array('id' => 'hero', 'title' => 'Hero (top of the page)', 'fields' => array(
        array('k' => 'hero_eyebrow', 'label' => 'Small line above the headline', 'type' => 'text', 'bi' => true),
        array('k' => 'hero_h1_a',    'label' => 'Headline - first line',  'type' => 'text', 'bi' => true),
        array('k' => 'hero_h1_b',    'label' => 'Headline - second line (gold)', 'type' => 'text', 'bi' => true),
        array('k' => 'hero_eth',     'label' => 'Line under the headline', 'type' => 'text', 'bi' => true),
        array('k' => 'hero_sub',     'label' => 'Intro paragraph', 'type' => 'area', 'bi' => true),
        array('k' => 'hero_location','label' => 'Location badge (top right)', 'type' => 'text', 'bi' => false),
    ));
    $g[] = array('id' => 'contact', 'title' => 'Contact details', 'fields' => array(
        array('k' => 'contact_address', 'label' => 'Headquarters address', 'type' => 'text', 'bi' => false),
        array('k' => 'contact_email',   'label' => 'Email', 'type' => 'text', 'bi' => false),
        array('k' => 'contact_website', 'label' => 'Website text', 'type' => 'text', 'bi' => false),
    ));
    $g[] = array('id' => 'social', 'title' => 'Social links (leave empty to keep the button inactive)', 'fields' => array(
        array('k' => 'social_telegram',  'label' => 'Telegram link',  'type' => 'url', 'bi' => false),
        array('k' => 'social_tiktok',    'label' => 'TikTok link',    'type' => 'url', 'bi' => false),
        array('k' => 'social_instagram', 'label' => 'Instagram link', 'type' => 'url', 'bi' => false),
        array('k' => 'social_linkedin',  'label' => 'LinkedIn link',  'type' => 'url', 'bi' => false),
        array('k' => 'social_youtube',   'label' => 'YouTube link',   'type' => 'url', 'bi' => false),
    ));
    $g[] = array('id' => 'footer', 'title' => 'Footer', 'fields' => array(
        array('k' => 'footer_tagline',  'label' => 'Footer tagline', 'type' => 'area', 'bi' => true),
        array('k' => 'footer_location', 'label' => 'Footer location line', 'type' => 'text', 'bi' => false),
    ));
    $stat = array();
    $statDefaults = array(1 => 'Subsidiaries', 2 => 'Live Services', 3 => 'Member Tiers', 4 => 'Ambition');
    foreach ($statDefaults as $i => $d) {
        $stat[] = array('k' => 'stat' . $i . '_value', 'label' => 'Number ' . $i . ' (digits, or a short text such as the infinity sign)', 'type' => 'text', 'bi' => false);
        $stat[] = array('k' => 'stat' . $i . '_label', 'label' => 'Number ' . $i . ' label (built-in: ' . $d . ')', 'type' => 'text', 'bi' => true);
    }
    $stat[] = array('k' => 'member_label', 'label' => 'Registered members label', 'type' => 'text', 'bi' => true);
    $g[] = array('id' => 'stats', 'title' => 'Numbers (the four big counters)', 'fields' => $stat);
    $bars = array();
    $barDefaults = array(1 => 'Etel Delivery', 2 => 'Etel Madeya', 3 => 'Etel Market', 4 => 'Etel Pay', 5 => 'Etel Transport', 6 => 'Etel LuxLine');
    foreach ($barDefaults as $i => $d) {
        $bars[] = array('k' => 'bar' . $i . '_name', 'label' => 'Bar ' . $i . ' name (built-in: ' . $d . ')', 'type' => 'text', 'bi' => false);
        $bars[] = array('k' => 'bar' . $i . '_pct', 'label' => 'Bar ' . $i . ' progress, 0 to 100', 'type' => 'pct', 'bi' => false);
        $bars[] = array('k' => 'bar' . $i . '_text', 'label' => 'Bar ' . $i . ' text shown instead of the percent (e.g. Live)', 'type' => 'text', 'bi' => false);
        $bars[] = array('k' => 'bar' . $i . '_sub', 'label' => 'Bar ' . $i . ' description', 'type' => 'text', 'bi' => false);
    }
    $g[] = array('id' => 'bars', 'title' => 'Progress bars', 'fields' => $bars);
    $g[] = array('id' => 'regions', 'title' => 'Regions (one name per line)', 'fields' => array(
        array('k' => 'regions_active', 'label' => 'Where we operate now (lit up)', 'type' => 'area', 'bi' => false),
        array('k' => 'regions_soon', 'label' => 'Where we are going next (dim)', 'type' => 'area', 'bi' => false),
    ));
    $gov = array();
    for ($i = 1; $i <= 4; $i++) $gov[] = array('k' => 'gov' . $i, 'label' => 'Governance principle ' . $i, 'type' => 'text', 'bi' => true);
    $gov[] = array('k' => 'press_body', 'label' => 'Press and media line', 'type' => 'area', 'bi' => false);
    $g[] = array('id' => 'governance', 'title' => 'Governance principles and press line', 'fields' => $gov);
    $names = array(1 => 'Leader 1 (Founder & Group CEO card)', 2 => 'Leader 2 (COO card)', 3 => 'Leader 3 (CTO card)');
    foreach ($names as $i => $title) {
        $g[] = array('id' => 'leader' . $i, 'title' => $title . ' - fill in the name to replace the "Profile coming soon" card', 'fields' => array(
            array('k' => 'lead' . $i . '_name',  'label' => 'Name', 'type' => 'text', 'bi' => false),
            array('k' => 'lead' . $i . '_title', 'label' => 'Job title', 'type' => 'text', 'bi' => false),
            array('k' => 'lead' . $i . '_bio',   'label' => 'Short bio', 'type' => 'area', 'bi' => true),
            array('k' => 'lead' . $i . '_photo', 'label' => 'Photo link (optional, a picture URL)', 'type' => 'url', 'bi' => false),
        ));
    }
    return $g;
}

/** key => field definition */
function eg_site_content_fields(): array {
    $out = array();
    foreach (eg_site_content_schema() as $grp) foreach ($grp['fields'] as $f) $out[$f['k']] = $f;
    return $out;
}

function eg_site_content_clean(string $v, string $type): string {
    $v = trim(strip_tags(str_replace(array("\r\n", "\r"), "\n", $v)));
    if ($type === 'area') return mb_substr($v, 0, 1000);
    $v = preg_replace('/\s+/u', ' ', $v);
    if ($type === 'pct') {
        $v = str_replace('%', '', $v);
        return is_numeric($v) ? (string)max(0, min(100, (int)round((float)$v))) : '';
    }
    if ($type === 'url') {
        $v = mb_substr($v, 0, 400);
        if ($v === '') return '';
        return preg_match('~^(https?://|mailto:|tel:|/|#)~i', $v) ? $v : '';
    }
    return mb_substr($v, 0, 250);
}

function eg_site_content_load(PDO $pdo): array {
    eg_ensure_site_content_table($pdo);
    $fields = eg_site_content_fields();
    $out = array();
    foreach ($pdo->query('SELECT k, en, am FROM site_content')->fetchAll() as $r) {
        if (!isset($fields[$r['k']])) continue;
        $out[$r['k']] = array('en' => (string)$r['en'], 'am' => (string)$r['am']);
    }
    return $out;
}

// ============================================================
// Coming-soon companies: launch text + "notify me" sign-ups. Kept in their own tables (keyed by company slug) so the
// existing companies table and its admin form logic stay exactly as they were.
// ============================================================
function eg_ensure_launch_tables(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS company_launch (
            slug VARCHAR(60) NOT NULL,
            launch_text VARCHAR(80) NOT NULL DEFAULT '',
            notify_enabled TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS launch_notify (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(60) NOT NULL,
            contact VARCHAR(160) NOT NULL,
            ip_hash CHAR(16) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_slug_contact (slug, contact),
            KEY idx_ip_time (ip_hash, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/** slug => [launch_text, notify_enabled, notify_count?] */
function eg_launch_map(PDO $pdo, bool $withCounts = false): array {
    eg_ensure_launch_tables($pdo);
    $map = array();
    foreach ($pdo->query('SELECT slug, launch_text, notify_enabled FROM company_launch')->fetchAll() as $r) {
        $map[$r['slug']] = array('launch_text' => (string)$r['launch_text'], 'notify_enabled' => (int)$r['notify_enabled'], 'notify_count' => 0);
    }
    if ($withCounts) {
        foreach ($pdo->query('SELECT slug, COUNT(*) n FROM launch_notify GROUP BY slug')->fetchAll() as $r) {
            if (!isset($map[$r['slug']])) $map[$r['slug']] = array('launch_text' => '', 'notify_enabled' => 0, 'notify_count' => 0);
            $map[$r['slug']]['notify_count'] = (int)$r['n'];
        }
    }
    return $map;
}

/** "name@example.com" or a phone number (digits, 9-15) -> cleaned string, '' when it is neither. */
function eg_clean_contact(string $v): string {
    $v = trim($v);
    if (filter_var($v, FILTER_VALIDATE_EMAIL) && mb_strlen($v) <= 160) return mb_strtolower($v);
    $digits = preg_replace('/[^\d+]/', '', $v);
    if (preg_match('/^\+?\d{9,15}$/', $digits)) return $digits;
    return '';
}

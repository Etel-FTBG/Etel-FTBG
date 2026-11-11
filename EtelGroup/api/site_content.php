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

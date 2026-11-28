<?php
// ============================================================
// ETEL - language control (the owner decides which languages the apps offer)
//   get_language_config    {}                       -> { default, langs: [{code, status}] }   (public: the language picker reads it)
//   admin_get_languages    {}                       -> { config, stats }
//   admin_save_languages   { default, langs: {om: 'beta', ...} } -> { config }
// status: live (no tag) | beta (BETA tag) | soon (listed, cannot be chosen) | off (hidden). English is always live.
// Stored as one JSON row in pricing_rules (rule_key languages_config). Nothing here changes a user's own saved choice.
// ============================================================
require_once __DIR__ . '/../lib/lang_config.php';

switch ($action) {

case 'get_language_config': {
    $pdo = db();
    header('Cache-Control: public, max-age=120');
    ok(etel_lang_public(etel_lang_config($pdo)));
    break;
}

case 'admin_get_languages': {
    $pdo = db();
    require_admin($pdo, 'settings.manage');
    $stats = array();
    try {
        if (in_array('lang', etel_table_columns($pdo, 'users'), true)) {
            foreach ($pdo->query("SELECT lang, COUNT(*) AS n FROM users WHERE lang IS NOT NULL AND lang <> '' GROUP BY lang")->fetchAll(PDO::FETCH_ASSOC) as $r) $stats[$r['lang']] = intval($r['n']);
        }
    } catch (Exception $e) { /* counts are optional */ }
    ok(array('config' => etel_lang_config($pdo), 'stats' => $stats));
    break;
}

case 'admin_save_languages': {
    $pdo = db();
    require_admin($pdo, 'settings.manage');
    try { ok(array('config' => etel_lang_save($pdo, $body))); }
    catch (Exception $e) { fail_ex($e, 'Could not save the language settings', 500); }
    break;
}

}

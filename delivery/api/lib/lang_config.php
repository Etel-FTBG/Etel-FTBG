<?php
// ETEL language control: which languages the apps offer and in what state. See controllers/LangController.php.

function etel_lang_codes() { return array('en', 'am', 'om', 'ti', 'so', 'aa'); }
function etel_lang_states() { return array('live', 'beta', 'soon', 'off'); }
function etel_lang_defaults() {
    return array('default' => 'en', 'langs' => array('en' => 'live', 'am' => 'live', 'om' => 'beta', 'ti' => 'beta', 'so' => 'beta', 'aa' => 'soon'));
}

/* Clean any input into a safe config: only known codes and states; English always live; the default language must be live or beta. */
function etel_lang_clean($in) {
    $d = etel_lang_defaults();
    $langs = $d['langs'];
    if (is_array($in) && isset($in['langs']) && is_array($in['langs'])) {
        foreach (etel_lang_codes() as $c) {
            if (isset($in['langs'][$c]) && in_array($in['langs'][$c], etel_lang_states(), true)) $langs[$c] = $in['langs'][$c];
        }
    }
    $langs['en'] = 'live';
    $def = (is_array($in) && isset($in['default']) && is_string($in['default'])) ? $in['default'] : 'en';
    if (!in_array($def, etel_lang_codes(), true) || !in_array($langs[$def], array('live', 'beta'), true)) $def = 'en';
    return array('default' => $def, 'langs' => $langs);
}

/* pricing_rules holds numbers only, so each language is one row: lang_state_<code> = 0 off, 1 soon, 2 beta, 3 live; lang_default = position of the default code. */
function etel_lang_num() { return array('off' => 0, 'soon' => 1, 'beta' => 2, 'live' => 3); }

function etel_lang_config($pdo) {
    $in = array('langs' => array());
    try {
        $rows = $pdo->query("SELECT rule_key, rule_value FROM pricing_rules WHERE rule_key LIKE 'lang\\_%'")->fetchAll(PDO::FETCH_ASSOC);
        $by = array();
        foreach ($rows as $r) $by[$r['rule_key']] = intval($r['rule_value']);
        $names = array_flip(etel_lang_num());
        foreach (etel_lang_codes() as $i => $c) {
            if (isset($by['lang_state_' . $c], $names[$by['lang_state_' . $c]])) $in['langs'][$c] = $names[$by['lang_state_' . $c]];
        }
        $codes = etel_lang_codes();
        if (isset($by['lang_default'], $codes[$by['lang_default']])) $in['default'] = $codes[$by['lang_default']];
    } catch (Exception $e) { /* table missing: defaults */ }
    return etel_lang_clean($in);
}

function etel_lang_save($pdo, $in) {
    $cfg = etel_lang_clean($in);
    $st = $pdo->prepare("INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE rule_value = VALUES(rule_value)");
    $num = etel_lang_num();
    foreach (etel_lang_codes() as $c) $st->execute(array('lang_state_' . $c, $num[$cfg['langs'][$c]], 'Language ' . $c . ': 0 hidden, 1 coming soon, 2 beta, 3 live'));
    $st->execute(array('lang_default', array_search($cfg['default'], etel_lang_codes()), 'Language new visitors start in (position: en, am, om, ti, so, aa)'));
    return $cfg;
}

function etel_lang_public($cfg) {
    $out = array();
    foreach (etel_lang_codes() as $c) $out[] = array('code' => $c, 'status' => $cfg['langs'][$c]);
    return array('default' => $cfg['default'], 'langs' => $out);
}

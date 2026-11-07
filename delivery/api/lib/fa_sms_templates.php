<?php
// ============================================================
// ETEL Ride - Field Agents: the text messages (SMS) a customer an agent booked for can get, in English, Amharic or both.
//   * Four kinds: welcome (sent automatically to a NEW customer), reset ("set your password"), verify ("complete your profile"), test (admin test).
//   * Three variants of each: en, am, both. Admin picks which variant customers get (setting agent_booking_sms_lang: 0 English, 1 Amharic, 2 both)
//     and may edit the wording of any variant (table fa_sms_templates; no row = the built-in default below).
//   * {link} in a text becomes the sign-in address.
//   * fa_sms_cost() estimates how many SMS credits a text uses: 160 characters per credit in plain English text, 70 per credit as soon as the text
//     has Amharic (or any non-ASCII) letters, because it is then sent as Unicode; a text longer than one credit uses 153 / 67 per part.
// The Amharic and both defaults are stored as \u escapes (inside JSON strings) so this file stays pure ASCII. ASCII only.
// ============================================================
require_once __DIR__ . '/sms_send.php';

if (!function_exists('fa_sms_link')) {

function fa_sms_link() { return 'ethelmarket.com/delivery/ride/'; }

/* kind => array(title, when-it-is-sent, mode) for the admin screen. */
function fa_sms_kinds() {
    return array(
        'welcome' => array('New customer text', 'Sent automatically when an agent books a ride for a customer who has no Etel account yet.', 'automatic'),
        'reset'   => array('Reset message', 'Sent when you press "Send reset message" on a booking in an agent\'s details.', 'manual'),
        'verify'  => array('Verify reminder', 'Sent when you press "Remind to verify profile" on a booking in an agent\'s details.', 'manual'),
        'test'    => array('Test text', 'Sent when you press "Send test" in the test box. Nothing else uses it.', 'test'),
    );
}
function fa_sms_langs() { return array('en', 'am', 'both'); }

/* Built-in wording: [kind][lang] => text with {link}. */
function fa_sms_defaults() {
    static $d = null;
    if ($d !== null) return $d;
    $j = array(
        'welcome' => array(
            'en' => '"ETEL Ride: an agent booked a ride for you. The driver will call; pay in cash. To follow it open {link} and tap Forgot password."',
            'am' => '"\\u12a2\\u1275\\u12a4\\u120d \\u122b\\u12ed\\u12f5\\u1366 \\u12c8\\u12aa\\u120d \\u1309\\u12de \\u12a0\\u12d8\\u12d8\\u120d\\u12ce\\u1362 \\u1239\\u134c\\u1229 \\u12ed\\u12f0\\u12cd\\u120b\\u120d\\u1363 \\u1260\\u1325\\u122c \\u12ed\\u12ad\\u1348\\u1209\\u1362 \\u1208\\u1218\\u12a8\\u1273\\u1270\\u120d {link} \\u1308\\u1265\\u1270\\u12cd \\"\\u12e8\\u12ed\\u1208\\u134d \\u1243\\u120d \\u1228\\u1231?\\" \\u12ed\\u132b\\u1291\\u1362"',
            'both' => '"\\u12a2\\u1275\\u12a4\\u120d \\u122b\\u12ed\\u12f5\\u1366 \\u1309\\u12de \\u1270\\u12ed\\u12de\\u120d\\u12ce\\u1273\\u120d\\u1362 \\u1239\\u134c\\u1229 \\u12ed\\u12f0\\u12cd\\u120b\\u120d\\u1363 \\u1260\\u1325\\u122c \\u12ed\\u12ad\\u1348\\u1209\\u1362\\nEtel Ride: ride booked. Driver will call, pay cash.\\n{link}"',
        ),
        'reset' => array(
            'en' => '"ETEL Ride: to follow your rides or pay with EtelPay open {link} and tap Forgot password."',
            'am' => '"\\u12a2\\u1275\\u12a4\\u120d \\u122b\\u12ed\\u12f5\\u1366 \\u1309\\u12de\\u12ce\\u1295 \\u1208\\u1218\\u12a8\\u1273\\u1270\\u120d \\u12c8\\u12ed\\u121d \\u1260\\u12a2\\u1275\\u12a4\\u120d \\u1354\\u12ed \\u1208\\u1218\\u12ad\\u1348\\u120d {link} \\u1308\\u1265\\u1270\\u12cd \\"\\u12e8\\u12ed\\u1208\\u134d \\u1243\\u120d \\u1228\\u1231?\\" \\u12ed\\u132b\\u1291\\u1362"',
            'both' => '"\\u12a2\\u1275\\u12a4\\u120d \\u122b\\u12ed\\u12f5\\u1366 \\"\\u12e8\\u12ed\\u1208\\u134d \\u1243\\u120d \\u1228\\u1231?\\" \\u12ed\\u132b\\u1291\\u1362\\nEtel Ride: tap Forgot password.\\n{link}"',
        ),
        'verify' => array(
            'en' => '"ETEL Ride: sign in at {link} and complete your profile to fully verify your account."',
            'am' => '"\\u12a2\\u1275\\u12a4\\u120d \\u122b\\u12ed\\u12f5\\u1366 {link} \\u1308\\u1265\\u1270\\u12cd \\u1218\\u1308\\u1208\\u132b\\u12ce\\u1295 \\u12eb\\u121f\\u1209\\u1364 \\u12a0\\u12ab\\u12cd\\u1295\\u1275\\u12ce \\u1219\\u1209 \\u1260\\u1219\\u1209 \\u12ed\\u1228\\u130b\\u1308\\u1323\\u120d\\u1362"',
            'both' => '"\\u12a2\\u1275\\u12a4\\u120d \\u122b\\u12ed\\u12f5\\u1366 \\u1308\\u1265\\u1270\\u12cd \\u1218\\u1308\\u1208\\u132b\\u12ce\\u1295 \\u12eb\\u121f\\u1209\\u1362\\nEtel Ride: sign in, complete your profile.\\n{link}"',
        ),
        'test' => array(
            'en' => '"ETEL: test text from Etel Admin. SMS is working."',
            'am' => '"\\u12a2\\u1275\\u12a4\\u120d\\u1366 \\u12e8\\u1219\\u12a8\\u122b \\u1218\\u120d\\u12a5\\u12ad\\u1275 \\u12a8\\u12a2\\u1275\\u12a4\\u120d \\u12a0\\u12f5\\u121a\\u1295\\u1362 \\u12a4\\u1235\\u12a4\\u121d\\u12a4\\u1235 \\u12a5\\u12e8\\u1230\\u122b \\u1290\\u12cd\\u1362"',
            'both' => '"\\u12a2\\u1275\\u12a4\\u120d\\u1366 \\u12e8\\u1219\\u12a8\\u122b \\u1218\\u120d\\u12a5\\u12ad\\u1275\\u1362\\nEtel: test text, SMS works."',
        ),
    );
    $d = array();
    foreach ($j as $k => $langs) { foreach ($langs as $l => $v) $d[$k][$l] = json_decode($v); }
    return $d;
}

function fa_sms_ensure_table($pdo) {
    static $done = false;
    if ($done) return true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `fa_sms_templates` (
            `id` INT(11) NOT NULL AUTO_INCREMENT, `kind` VARCHAR(20) NOT NULL, `lang` VARCHAR(8) NOT NULL, `body` VARCHAR(1000) NOT NULL,
            `updated_by` INT(11) NOT NULL DEFAULT 0, `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_fa_sms_tpl` (`kind`, `lang`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    } catch (Exception $e) { return false; }
    return true;
}

/* Edited wording: [kind][lang] => body. Empty when nothing was edited or the table cannot be read. */
function fa_sms_overrides($pdo) {
    $o = array();
    if (!$pdo || !fa_sms_ensure_table($pdo)) return $o;
    try {
        foreach ($pdo->query('SELECT kind, lang, body FROM fa_sms_templates')->fetchAll(PDO::FETCH_ASSOC) as $r) $o[$r['kind']][$r['lang']] = $r['body'];
    } catch (Exception $e) { /* defaults apply */ }
    return $o;
}

/* The text to send: edited wording if there is one, else the default; {link} replaced. */
function fa_sms_resolve($pdo, $kind, $lang) {
    $d = fa_sms_defaults();
    if (!isset($d[$kind][$lang])) return '';
    $o = fa_sms_overrides($pdo);
    $body = (isset($o[$kind][$lang]) && trim($o[$kind][$lang]) !== '') ? $o[$kind][$lang] : $d[$kind][$lang];
    return str_replace('{link}', fa_sms_link(), $body);
}

/* 'en' | 'am' | 'both' from the settings (0, 1, 2). The older on/off setting "Amharic texts" still counts as Amharic when the new one was never set. */
function fa_sms_mode($S) {
    $m = isset($S['agent_booking_sms_lang']) ? intval($S['agent_booking_sms_lang']) : 0;
    if ($m === 0 && !empty($S['agent_booking_sms_am'])) $m = 1;
    return $m === 2 ? 'both' : ($m === 1 ? 'am' : 'en');
}

/* Length in UTF-16 code units (what a phone counts). */
function fa_sms_len($s) {
    $n = 0;
    $len = strlen($s);
    for ($i = 0; $i < $len; ) {
        $c = ord($s[$i]);
        if ($c < 0x80) { $n += 1; $i += 1; }
        elseif ($c < 0xE0) { $n += 1; $i += 2; }
        elseif ($c < 0xF0) { $n += 1; $i += 3; }
        else { $n += 2; $i += 4; }
    }
    return $n;
}
/* Estimated SMS credits. Plain ASCII text (without the backtick) is GSM-7: 160 / 153 per part, and ^ { } \ [ ] ~ | count twice. Anything else is Unicode: 70 / 67. */
function fa_sms_cost($text) {
    $text = (string)$text;
    $unicode = (preg_match('/[^\x00-\x7F]/', $text) === 1) || (strpos($text, '`') !== false);
    if ($unicode) {
        $chars = fa_sms_len($text);
        $segs = $chars === 0 ? 0 : ($chars <= 70 ? 1 : (int)ceil($chars / 67));
        return array('chars' => $chars, 'encoding' => 'Unicode', 'per_part' => $chars <= 70 ? 70 : 67, 'segments' => $segs);
    }
    $chars = strlen($text) + preg_match_all('/[\^{}\\\\\[\]~|]/', $text, $m);
    $segs = $chars === 0 ? 0 : ($chars <= 160 ? 1 : (int)ceil($chars / 153));
    return array('chars' => $chars, 'encoding' => 'GSM-7', 'per_part' => $chars <= 160 ? 160 : 153, 'segments' => $segs);
}

/* null when the wording is acceptable, else a plain sentence saying what to fix. */
function fa_sms_validate($kind, $body) {
    $body = (string)$body;
    if (trim($body) === '') return 'The text cannot be empty.';
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $body)) return 'The text has a character that cannot be sent.';
    if (fa_sms_len($body) > 480) return 'The text is too long (most 480 characters, which is already several credits).';
    if ($kind !== 'test' && strpos($body, '{link}') === false) return 'Keep {link} in the text so the customer gets the sign-in address.';
    return null;
}

/* Everything the admin screen needs. */
function fa_sms_overview($pdo, $S) {
    $kinds = fa_sms_kinds(); $d = fa_sms_defaults(); $o = fa_sms_overrides($pdo); $out = array();
    foreach ($kinds as $k => $meta) {
        $v = array();
        foreach (fa_sms_langs() as $l) {
            $custom = isset($o[$k][$l]) && trim($o[$k][$l]) !== '';
            $body = $custom ? $o[$k][$l] : $d[$k][$l];
            $v[$l] = array('body' => $body, 'default' => $d[$k][$l], 'custom' => $custom, 'cost' => fa_sms_cost(str_replace('{link}', fa_sms_link(), $body)));
        }
        $out[] = array('kind' => $k, 'title' => $meta[0], 'when' => $meta[1], 'how' => $meta[2], 'variants' => $v);
    }
    return array('mode' => fa_sms_mode($S), 'auto_on' => !empty($S['agent_booking_sms']), 'link' => fa_sms_link(), 'provider' => etel_sms_provider(), 'kinds' => $out);
}

}

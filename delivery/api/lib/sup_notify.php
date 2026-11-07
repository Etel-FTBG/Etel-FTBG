<?php
// ============================================================
// ETEL Support desk - Telegram notices. OFF by default (sup_settings tg_enabled = 0): nothing is ever sent until the owner turns it on.
// Uses the same bot token and the same chat-id lookup as the password-reset code in boot.php (TG_BOT_TOKEN; EtelID member table or tg_chat_ids).
// What leaves the server is only a short notice with the request number and a link: never the message text, never a phone number. ASCII only.
// ============================================================
if (!defined('APP_VER') && PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/sup_core.php';

if (!function_exists('sup_tg_send')) {

function sup_u($s) { return json_decode('"' . $s . '"'); }

/* Sends one Telegram text. Tests can replace the sender with $GLOBALS['SUP_TG_SENDER'] (a callable). Returns true when Telegram accepted it. */
function sup_tg_send($chatId, $text) {
    if (!empty($GLOBALS['SUP_TG_SENDER']) && is_callable($GLOBALS['SUP_TG_SENDER'])) return (bool) call_user_func($GLOBALS['SUP_TG_SENDER'], $chatId, $text);
    $tok = function_exists('etel_env') ? etel_env('TG_BOT_TOKEN', 'CFG_TG_BOT_TOKEN', '') : '';
    if ($tok === '' || $chatId === '' || !function_exists('curl_init')) return false;
    $ch = curl_init('https://api.telegram.org/bot' . $tok . '/sendMessage');
    curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(array('chat_id' => $chatId, 'text' => $text)), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_HTTPHEADER => array('Content-Type: application/json')));
    $resp = curl_exec($ch); curl_close($ch);
    $j = $resp ? json_decode($resp, true) : null;
    return is_array($j) && !empty($j['ok']);
}

/* The customer's Telegram chat id, found by phone the same way the password-reset code does. Empty when unknown. */
function sup_tg_chat_for($pdo, $user) {
    $phone = isset($user['phone']) ? (string) $user['phone'] : '';
    if ($phone === '') return '';
    if (!empty($GLOBALS['SUP_TG_CHAT_LOOKUP']) && is_callable($GLOBALS['SUP_TG_CHAT_LOOKUP'])) return (string) call_user_func($GLOBALS['SUP_TG_CHAT_LOOKUP'], $phone);
    try {
        if (!function_exists('etelid_bridge_db') && file_exists(__DIR__ . '/../etelid_bridge.php')) @require_once __DIR__ . '/../etelid_bridge.php';
        if (function_exists('etelid_bridge_db')) {
            $eid = etelid_bridge_db();
            if ($eid) {
                $q = $eid->prepare("SELECT tg_chat_id FROM Etelid_etelid_members WHERE REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-','') = REPLACE(REPLACE(REPLACE(?,'+',''),' ',''),'-','') LIMIT 1");
                $q->execute(array($phone)); $c = $q->fetchColumn();
                if ($c) return (string) $c;
            }
        }
    } catch (Exception $e) { /* EtelID master not reachable: fall through */ }
    try { $q = $pdo->prepare('SELECT chat_id FROM tg_chat_ids WHERE phone = ? LIMIT 1'); $q->execute(array($phone)); $c = $q->fetchColumn(); if ($c) return (string) $c; } catch (Exception $e) { /* table may not exist */ }
    return '';
}

function sup_notify_staff_new($pdo, $t) {
    $S = sup_settings($pdo);
    if (empty($S['tg_enabled']) || $S['tg_staff_chat_id'] === '') return false;
    $apps = sup_apps();
    $txt = 'New support request ' . $t['ref'] . ' (' . (isset($apps[$t['app']]) ? $apps[$t['app']] : $t['app']) . ', ' . $t['category'] . ', ' . $t['priority'] . '). Open Admin > Support desk to answer.';
    return sup_tg_send($S['tg_staff_chat_id'], $txt);
}

function sup_notify_user_reply($pdo, $t) {
    $S = sup_settings($pdo);
    if (empty($S['tg_enabled']) || empty($S['tg_notify_user'])) return false;
    $q = $pdo->prepare('SELECT id, phone FROM users WHERE id = ?'); $q->execute(array(intval($t['user_id']))); $u = $q->fetch(PDO::FETCH_ASSOC);
    if (!$u) return false;
    $chat = sup_tg_chat_for($pdo, $u);
    if ($chat === '') return false;
    $link = 'https://ethelmarket.com/delivery/help/';
    $txt = ($t['lang'] === 'en') ? ('You have a reply from Etel Support (' . $t['ref'] . '). Open Help to read it: ' . $link)
                                 : (sup_u('\u12a8\u12a2\u1275\u12a4\u120d \u12f5\u130b\u134d \u1218\u120d\u1235 \u12a0\u1208\u12ce\u1275\u1362 \u1208\u121b\u1295\u1260\u1265 \u12a5\u1308\u12db \u1308\u1339\u1295 \u12ed\u12ad\u1348\u1271\u1366 ') . $link . ' (' . $t['ref'] . ')');
    return sup_tg_send($chat, $txt);
}

}

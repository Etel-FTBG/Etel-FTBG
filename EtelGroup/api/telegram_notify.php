<?php
// ============================================================
// Telegram webhook for the "Notify me" bot. Telegram calls this when someone presses Start on the bot link shown
// in a coming-soon company's pop-up (https://t.me/<bot>?start=notify_<slug>). Their chat id is added to that company's list.
// It is connected from Admin > Companies > "Telegram notify bot" (setWebhook with a secret that is checked here).
// ============================================================
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../php/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/site_content.php';

try {
    $pdo = eg_db();
    eg_ensure_companies_table($pdo); eg_ensure_launch_tables($pdo);
    $secret = eg_nget($pdo, 'tg_secret');
    $hdr = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if ($secret === '' || !hash_equals($secret, (string)$hdr)) { http_response_code(403); echo '{"ok":false}'; exit; }
    $u = json_decode((string)file_get_contents('php://input'), true);
    $m = is_array($u) ? ($u['message'] ?? null) : null;
    if (!$m || empty($m['chat']['id']) || ($m['chat']['type'] ?? '') !== 'private') { echo '{"ok":true}'; exit; }
    $chat = (string)$m['chat']['id'];
    $token = eg_nget($pdo, 'tg_token');
    $text = trim((string)($m['text'] ?? ''));
    if (preg_match('/^\/start\s+notify_([a-z0-9-]{1,60})$/i', $text, $mm)) {
        $slug = strtolower($mm[1]);
        $c = $pdo->prepare("SELECT c.name FROM companies c JOIN company_launch l ON l.slug = c.slug WHERE c.slug = ? AND c.status = 'coming_soon' AND l.notify_enabled = 1");
        $c->execute(array($slug));
        $co = $c->fetch();
        if ($co) {
            $pdo->prepare('INSERT IGNORE INTO launch_notify (slug, contact, ip_hash) VALUES (?,?,?)')->execute(array($slug, 'tg:' . $chat, ''));
            eg_tg_send($token, $chat, 'You are on the list. We will message you here when ' . $co['name'] . ' launches.');
        } else {
            eg_tg_send($token, $chat, 'Sign-ups for this company are not open right now.');
        }
    } else {
        eg_tg_send($token, $chat, 'Hello! Open a "Notify me" button on ethelmarket.com to get a message here when a new Etel company launches.');
    }
} catch (Throwable $e) {
    error_log('[EtelGroup][telegram_notify] ' . $e->getMessage());
}
echo '{"ok":true}';

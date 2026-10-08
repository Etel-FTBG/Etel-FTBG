<?php
// Needed for rides_default_peak_windows() used by admin_get_ride_peak_windows
// below - AdminController.php previously never needed anything from
// ride_fare.php, unlike RideController.php/AgentController.php which
// already require it.
require_once __DIR__ . '/../lib/ride_fare.php';
// Needed for rides_add_missing_cols() used by admin_topup_etel_money and
// admin_get_pending_etel_topups below - same situation as ride_fare.php
// above (see lib/schema_helpers.php header for the fuller story).
require_once __DIR__ . '/../lib/schema_helpers.php';
require_once __DIR__ . '/../push.php';
switch ($action) {
case 'get_feature_flags': {
    // Public/read-only - any page (Delivery, Ride, etc.) checks this to
    // decide whether to show Etel Pay as usable or "Coming Soon". No auth
    // needed to READ; only admin_toggle_feature (below) can change it.
    $path = __DIR__ . '/../etel-features.json';
    $flags = @json_decode(@file_get_contents($path), true);
    if (!is_array($flags)) $flags = array('etelpay_live' => false);

    // Per-user override: lets an admin grant (or explicitly deny) Etel
    // Pay access for a specific member ahead of (or instead of) the
    // global flag above. Auth is optional here - an anonymous/public
    // request just gets the global flag as before; a logged-in member
    // gets their own override applied on top, exposed as a separate
    // `etelpay_live_effective` key so nothing that already reads
    // `etelpay_live` (the global value) breaks.
    $flags['etelpay_live_effective'] = !empty($flags['etelpay_live']);
    try {
        $pdo = db();
        $u = get_auth_user($pdo);
        if ($u) {
            $ov = $pdo->prepare('SELECT allowed FROM etelpay_user_overrides WHERE user_id=?');
            $ov->execute(array($u['id']));
            $row = $ov->fetch();
            if ($row !== false) $flags['etelpay_live_effective'] = !empty($row['allowed']);
        }
    } catch (Exception $e) { /* overrides table may not be migrated yet - fall back to global flag */ }
    ok($flags);
    break;
}
case 'admin_list_etelpay_overrides': {
    // GET: list every user with an explicit Etel Pay override (on or off).
    $pdo = db();
    require_admin($pdo, 'owner.only');
    try {
        $s = $pdo->query("SELECT o.user_id, o.allowed, o.note, o.updated_by, o.updated_at, u.name, u.phone
                           FROM etelpay_user_overrides o JOIN users u ON u.id = o.user_id
                           ORDER BY o.updated_at DESC");
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $rows = array(); }
    ok(array('overrides' => $rows));
    break;
}
case 'admin_set_etelpay_override': {
    // POST: user_id (or phone), allowed (bool), note?
    // Grants or denies one member Etel Pay access regardless of the
    // global etelpay_live flag. Same capability as the global toggle,
    // since this affects real payment behavior for that member.
    $pdo = db();
    $admin = require_admin($pdo, 'owner.only');
    $user_id = isset($body['user_id']) ? intval($body['user_id']) : 0;
    if (!$user_id && !empty($body['phone'])) {
        $phone = etel_validate_phone($body['phone']);
        if (!$phone) fail('Enter a valid Ethiopian mobile number, e.g. 0911234567');
        $us = $pdo->prepare('SELECT id FROM users WHERE phone=?');
        $us->execute(array($phone));
        $user_id = intval($us->fetchColumn());
    }
    if (!$user_id) fail('user_id or phone is required');
    $allowed = !empty($body['value']);
    $note = isset($body['note']) ? trim($body['note']) : null;
    $by = isset($admin['name']) ? $admin['name'] : ('admin#' . $admin['id']);
    try {
        $chk = $pdo->prepare('SELECT id FROM users WHERE id=?');
        $chk->execute(array($user_id));
        if (!$chk->fetch()) fail('User not found', 404);
        $pdo->prepare('INSERT INTO etelpay_user_overrides (user_id, allowed, note, updated_by, updated_at) VALUES (?,?,?,?,NOW())
                        ON DUPLICATE KEY UPDATE allowed=VALUES(allowed), note=VALUES(note), updated_by=VALUES(updated_by), updated_at=NOW()')
            ->execute(array($user_id, $allowed ? 1 : 0, $note, $by));
        ok(array('message' => 'Override saved', 'user_id' => $user_id, 'allowed' => $allowed));
    } catch (Exception $e) { fail_ex($e, 'Could not save override', 500); }
    break;
}
case 'admin_remove_etelpay_override': {
    // POST: user_id  - removes the override so that user reverts to
    // whatever the global etelpay_live flag says.
    $pdo = db();
    require_admin($pdo, 'owner.only');
    $user_id = isset($body['user_id']) ? intval($body['user_id']) : 0;
    if (!$user_id) fail('user_id is required');
    try {
        $pdo->prepare('DELETE FROM etelpay_user_overrides WHERE user_id=?')->execute(array($user_id));
        ok(array('message' => 'Override removed'));
    } catch (Exception $e) { fail_ex($e, 'Could not remove override', 500); }
    break;
}

// ── SUPPORT INBOX ────────────────────────────────────────────────
// Answers the gap flagged after the customer_send_message feature was
// built: messages landed in customer_messages with nowhere for an
// admin to read or reply to them. These three actions are the missing
// inbox - list threads (one per user who has ever messaged support),
// read a single thread, and reply.

case 'admin_list_support_threads': {
    // GET: optional unread_only=1
    $pdo = db();
    require_admin($pdo, 'orders.view');
    $unread_only = !empty($_GET['unread_only']) || !empty($body['unread_only']);
    try {
        $sql = "SELECT m.user_id, u.name, u.phone,
                       MAX(m.created_at) AS last_message_at,
                       SUM(CASE WHEN m.sender='customer' AND m.is_read=0 THEN 1 ELSE 0 END) AS unread_count,
                       (SELECT body FROM customer_messages WHERE user_id=m.user_id ORDER BY created_at DESC LIMIT 1) AS last_body,
                       (SELECT sender FROM customer_messages WHERE user_id=m.user_id ORDER BY created_at DESC LIMIT 1) AS last_sender
                FROM customer_messages m
                JOIN users u ON u.id = m.user_id
                GROUP BY m.user_id, u.name, u.phone";
        if ($unread_only) $sql .= " HAVING unread_count > 0";
        $sql .= " ORDER BY last_message_at DESC LIMIT 200";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $rows = array(); }
    ok(array('threads' => $rows));
    break;
}

case 'admin_get_support_thread': {
    // GET: user_id  - full message history for one person, and marks
    // their customer-sent messages as read (so the unread badge clears
    // once an admin actually opens the thread, not just lists it).
    $pdo = db();
    require_admin($pdo, 'orders.view');
    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : (isset($body['user_id']) ? intval($body['user_id']) : 0);
    if (!$user_id) fail('user_id is required');
    try {
        $s = $pdo->prepare('SELECT id, sender, subject, body, is_read, created_at FROM customer_messages WHERE user_id=? ORDER BY created_at ASC');
        $s->execute(array($user_id));
        $messages = $s->fetchAll(PDO::FETCH_ASSOC);
        $pdo->prepare("UPDATE customer_messages SET is_read=1 WHERE user_id=? AND sender='customer' AND is_read=0")->execute(array($user_id));
        $u = $pdo->prepare('SELECT name, phone FROM users WHERE id=?');
        $u->execute(array($user_id));
        $user = $u->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { fail_ex($e, 'Could not load thread', 500); }
    ok(array('messages' => $messages, 'user' => $user));
    break;
}

case 'admin_reply_support_message': {
    // POST: user_id, body, subject?
    $pdo = db();
    $admin = require_admin($pdo, 'orders.view');
    $user_id = isset($body['user_id']) ? intval($body['user_id']) : 0;
    $text = isset($body['body']) ? trim($body['body']) : '';
    $subject = isset($body['subject']) ? trim($body['subject']) : 'Support';
    if (!$user_id || !$text) fail('user_id and body are required');
    try {
        $chk = $pdo->prepare('SELECT id FROM users WHERE id=?');
        $chk->execute(array($user_id));
        if (!$chk->fetch()) fail('User not found', 404);
        $pdo->prepare("INSERT INTO customer_messages (user_id, sender, subject, body, is_read, created_at) VALUES (?, 'support', ?, ?, 1, NOW())")
            ->execute(array($user_id, $subject, $text));
        ok(array('message' => 'Reply sent'));
    } catch (Exception $e) { fail_ex($e, 'Could not send reply', 500); }
    break;
}
case 'admin_toggle_feature': {
    // Lets an admin flip a feature flag (currently just etelpay_live) once
    // e.g. government approval comes through, without needing a code
    // deploy. Same 'owner.only' capability as the code-audit action above,
    // since this affects real payment behavior system-wide.
    $pdo = db();
    $admin = require_admin($pdo, 'owner.only');
    $flag = isset($body['flag']) ? trim($body['flag']) : '';
    $value = !empty($body['value']);
    $allowed = array('etelpay_live');
    if (!in_array($flag, $allowed, true)) fail('Unknown or non-toggleable flag: ' . $flag);

    $path = __DIR__ . '/../etel-features.json';
    $flags = @json_decode(@file_get_contents($path), true);
    if (!is_array($flags)) $flags = array();
    $flags[$flag] = $value;
    $flags[$flag . '_updated_at'] = gmdate('Y-m-d H:i:s');
    $flags[$flag . '_updated_by'] = isset($admin['name']) ? $admin['name'] : (isset($admin['id']) ? 'admin#' . $admin['id'] : 'admin');
    @file_put_contents($path, json_encode($flags, JSON_PRETTY_PRINT));
    ok($flags);
    break;
}

/* ── Canary release system ──────────────────────────────────────────
   Lets an admin ship a new frontend bundle to a small, specific set of
   test users first, confirm it's clean, then promote it to everyone -
   and even after promotion, users already mid-session only get an
   opt-in "update available" prompt (see js/js/release-loader.js +
   core.js's silentPatchCheck) rather than being silently switched
   under them. See lib/app_releases.php for the schema and per-user
   version resolution this all sits on top of. Gated the same as
   etelpay_live above ('owner.only') since this controls what code
   every single user's device actually runs. */

case 'admin_list_release_channels': {
    require_once __DIR__ . '/../lib/app_releases.php';
    $pdo = db();
    require_admin($pdo, 'owner.only');
    $out = array();
    foreach (app_releases_valid_app_names() as $app) {
        $channel = app_releases_get_channel($pdo, $app);
        $ct = $pdo->prepare("SELECT ct.user_id, u.name, u.phone, ct.added_at
            FROM app_canary_testers ct JOIN users u ON u.id = ct.user_id
            WHERE ct.app_name=? ORDER BY ct.added_at DESC");
        $ct->execute(array($app));
        $channel['canary_testers'] = $ct->fetchAll();
        $out[] = $channel;
    }
    ok(array('channels' => $out));
    break;
}

case 'admin_set_release_channel': {
    // Sets the STABLE or CANARY version label for one app. This is a
    // label only - it must match a version folder you've already
    // uploaded (js/releases/{app}/{version}/...) via the Updater, same
    // Upload-tab workflow as before, just to a new folder each release
    // instead of overwriting the live path in place. Setting a channel
    // here does NOT upload any files itself.
    require_once __DIR__ . '/../lib/app_releases.php';
    $pdo = db();
    $admin = require_admin($pdo, 'owner.only');
    $app = isset($body['app']) ? trim($body['app']) : '';
    $channelType = isset($body['channel']) ? trim($body['channel']) : ''; // 'stable' or 'canary'
    // Version labels are lowercased here - version folders are always
    // created lowercase (js/releases/{app}/v3/, never .../V3/), and the
    // live server filesystem is case-sensitive Linux, so a canary set to
    // "V3" by a stray capital letter 404s against the real "v3" folder.
    // The loader's fallback-to-stable safety net masks that completely
    // (no error, no blank screen - it just silently serves the OLD
    // version), which is exactly what happened the first time this ran:
    // looked like canary did nothing, was actually 404-ing on every
    // fetch. Normalizing here removes an entire class of "why isn't my
    // canary build showing up" reports before they can happen again.
    $version = isset($body['version']) ? strtolower(trim($body['version'])) : '';
    if (!in_array($app, app_releases_valid_app_names(), true)) fail('Unknown app: ' . $app, 400);
    if (!in_array($channelType, array('stable', 'canary'), true)) fail("channel must be 'stable' or 'canary'", 400);
    if ($channelType === 'canary' && $version === '') {
        // Explicit clear: an empty version turns canary OFF for this app
        // (every canary tester falls back to stable) without deleting
        // the tester list itself, so it's easy to re-target the same
        // testers on the next release.
        $pdo->prepare("UPDATE app_release_channels SET canary_version=NULL, updated_at=NOW() WHERE app_name=?")
            ->execute(array($app));
        ok(array('app' => $app, 'canary_version' => null));
        break;
    }
    if ($version === '') fail('Version is required', 400);
    app_releases_get_channel($pdo, $app); // ensure the row exists first
    if ($channelType === 'stable') {
        $cur = app_releases_get_channel($pdo, $app);
        $pdo->prepare("UPDATE app_release_channels SET previous_stable_version=?, stable_version=?, updated_at=NOW() WHERE app_name=?")
            ->execute(array($cur['stable_version'], $version, $app));
    } else {
        $pdo->prepare("UPDATE app_release_channels SET canary_version=?, updated_at=NOW() WHERE app_name=?")
            ->execute(array($version, $app));
    }
    ok(array('app' => $app, 'channel' => $channelType, 'version' => $version,
             'set_by' => isset($admin['name']) ? $admin['name'] : 'admin'));
    break;
}

case 'admin_promote_canary_to_stable': {
    // The "release to everyone" button, once the admin has confirmed the
    // canary build is clean. Moves the current canary_version into
    // stable_version and clears canary - remembering the prior stable as
    // previous_stable_version so admin_rollback_stable can undo it.
    require_once __DIR__ . '/../lib/app_releases.php';
    $pdo = db();
    require_admin($pdo, 'owner.only');
    $app = isset($body['app']) ? trim($body['app']) : '';
    if (!in_array($app, app_releases_valid_app_names(), true)) fail('Unknown app: ' . $app, 400);
    $channel = app_releases_get_channel($pdo, $app);
    if (!$channel['canary_version']) fail('No canary version is currently set for this app', 400);
    $pdo->prepare("UPDATE app_release_channels
        SET previous_stable_version=?, stable_version=?, canary_version=NULL, updated_at=NOW()
        WHERE app_name=?")->execute(array($channel['stable_version'], $channel['canary_version'], $app));
    ok(array('app' => $app, 'stable_version' => $channel['canary_version']));
    break;
}

case 'admin_rollback_stable': {
    // Safety net: instantly revert stable back to whatever it was before
    // the last promotion, in case something slipped past canary testing
    // and only showed up once it hit everyone. Only one step of history
    // is kept (previous_stable_version), not a full log.
    require_once __DIR__ . '/../lib/app_releases.php';
    $pdo = db();
    require_admin($pdo, 'owner.only');
    $app = isset($body['app']) ? trim($body['app']) : '';
    if (!in_array($app, app_releases_valid_app_names(), true)) fail('Unknown app: ' . $app, 400);
    $channel = app_releases_get_channel($pdo, $app);
    if (!$channel['previous_stable_version']) fail('No previous version recorded to roll back to', 400);
    $pdo->prepare("UPDATE app_release_channels
        SET stable_version=?, previous_stable_version=NULL, updated_at=NOW()
        WHERE app_name=?")->execute(array($channel['previous_stable_version'], $app));
    ok(array('app' => $app, 'stable_version' => $channel['previous_stable_version']));
    break;
}

case 'admin_add_canary_tester': {
    require_once __DIR__ . '/../lib/app_releases.php';
    $pdo = db();
    require_admin($pdo, 'owner.only');
    $app = isset($body['app']) ? trim($body['app']) : '';
    $userId = isset($body['user_id']) ? intval($body['user_id']) : 0;
    if (!in_array($app, app_releases_valid_app_names(), true)) fail('Unknown app: ' . $app, 400);
    if (!$userId) fail('user_id is required', 400);
    $chk = $pdo->prepare('SELECT id, name, phone FROM users WHERE id=?');
    $chk->execute(array($userId));
    $user = $chk->fetch();
    if (!$user) fail('No such user', 404);
    $pdo->prepare("INSERT IGNORE INTO app_canary_testers (app_name, user_id, added_at) VALUES (?, ?, NOW())")
        ->execute(array($app, $userId));
    ok(array('app' => $app, 'user' => $user));
    break;
}

case 'admin_remove_canary_tester': {
    require_once __DIR__ . '/../lib/app_releases.php';
    $pdo = db();
    require_admin($pdo, 'owner.only');
    $app = isset($body['app']) ? trim($body['app']) : '';
    $userId = isset($body['user_id']) ? intval($body['user_id']) : 0;
    if (!in_array($app, app_releases_valid_app_names(), true)) fail('Unknown app: ' . $app, 400);
    if (!$userId) fail('user_id is required', 400);
    $pdo->prepare("DELETE FROM app_canary_testers WHERE app_name=? AND user_id=?")->execute(array($app, $userId));
    ok(array('app' => $app, 'user_id' => $userId, 'removed' => true));
    break;
}

case 'admin_search_canary_candidates': {
    // Search box for "who should test this" - by name or phone, any
    // role (a canary tester for the driver app should obviously be a
    // driver, but nothing stops testing e.g. a rider-facing change on a
    // staff member's own rider account, so this deliberately searches
    // all users rather than filtering by role).
    $pdo = db();
    require_admin($pdo, 'owner.only');
    $q = isset($body['q']) ? trim($body['q']) : '';
    if (strlen($q) < 2) fail('Search query too short', 400);
    $s = $pdo->prepare("SELECT id, name, phone, role FROM users WHERE name LIKE ? OR phone LIKE ? LIMIT 15");
    $like = '%' . $q . '%';
    $s->execute(array($like, $like));
    ok(array('users' => $s->fetchAll()));
    break;
}

case 'admin_run_code_audit': {
    // One-click replacement for the manual "zip the project, upload it to
    // EtelGebeya/audit, download the JSON, paste it into Claude" loop -
    // scans this project's own files server-side and calls the same
    // shared AI-audit backend the manual tool uses. See api/audit_agent.php
    // for the actual scan/build/call logic (kept in its own file so it's
    // a clean copy-paste template for other projects).
    $pdo = db();
    require_admin($pdo, 'owner.only');
    require_once __DIR__ . '/../audit_agent.php';
    $provider = isset($body['provider']) ? trim($body['provider']) : 'gemini';
    $root = dirname(dirname(__DIR__)); // controllers/ -> api/ -> project root
    $report = etel_run_self_audit($root, 'ETEL Delivery & Ride', $provider, $pdo);
    if (isset($report['error'])) fail($report['error'], 502);
    ok($report);
    break;
}

// ── Audit API settings ────────────────────────────────────────────
// Which Gemini/OpenRouter key (and OpenRouter model) "Run Audit Now"
// actually calls, direct to each provider's real API - previously
// hardcoded/routed through a shared cross-project proxy, meaning
// switching keys or backends needed a code deploy (or, as it turned
// out, depended on a shared secret staying in sync across two separate
// projects' deploys - see audit_agent.php's header for why that was
// dropped). Now editable from the panel; see etel_audit_get_config()
// in audit_agent.php for the fallback-to-hardcoded-constants behavior
// if this table is empty/missing.
case 'admin_get_audit_settings': {
    $pdo = db();
    require_admin($pdo, 'owner.only');
    require_once __DIR__ . '/../audit_agent.php';
    $cfg = etel_audit_get_config($pdo);
    ok($cfg);
    break;
}

case 'admin_save_audit_settings': {
    $pdo = db();
    require_admin($pdo, 'owner.only');
    require_once __DIR__ . '/../audit_agent.php';
    etel_audit_ensure_settings_table($pdo);
    $geminiKey       = isset($body['gemini_key'])       ? trim($body['gemini_key'])       : '';
    $openrouterKey   = isset($body['openrouter_key'])   ? trim($body['openrouter_key'])   : '';
    $openrouterModel = isset($body['openrouter_model']) ? trim($body['openrouter_model']) : '';
    $provider        = isset($body['default_provider']) ? trim($body['default_provider']) : 'gemini';
    if (!in_array($provider, array('gemini', 'openrouter'), true)) $provider = 'gemini';
    if ($provider === 'gemini' && !$geminiKey) fail('gemini_key is required when Gemini is the default provider', 400);
    if ($provider === 'openrouter' && !$openrouterKey) fail('openrouter_key is required when OpenRouter is the default provider', 400);
    if (!$openrouterModel) $openrouterModel = 'anthropic/claude-3.5-sonnet';
    try {
        $pdo->prepare("INSERT INTO audit_settings (id, gemini_key, openrouter_key, openrouter_model, default_provider) VALUES (1, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE gemini_key=VALUES(gemini_key), openrouter_key=VALUES(openrouter_key), openrouter_model=VALUES(openrouter_model), default_provider=VALUES(default_provider)")
            ->execute(array($geminiKey, $openrouterKey, $openrouterModel, $provider));
        ok(array('message' => 'Audit settings saved'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not save audit settings', 500);
    }
    break;
}
case 'admin_create_user': {
    $pdo = db();
    $admin = require_admin($pdo, 'users.manage');
    
    $phone_raw = isset($body['phone']) ? trim($body['phone']) : '';
    $pw    = isset($body['password']) ? $body['password'] : '';
    $role  = isset($body['role']) ? trim($body['role']) : 'customer';
    // Only known roles. Creating an admin hands out an unrestricted account (a new admin is not
    // EtelGroup-managed, so it holds '*'), so it needs '*' itself; Ride-only staff may only add riders/drivers.
    if (!in_array($role, array('customer','driver','courier','service_giver','admin'), true)) fail('Unknown role', 400);
    if ($role === 'admin' && !user_has_capability($pdo, $admin, '*')) fail('Only an owner can create admin accounts', 403);
    if (etel_is_ride_only($pdo, $admin) && !in_array($role, array('customer','driver'), true)) fail('You can only create rider or driver accounts', 403);

    if (!$phone_raw) fail('Phone is required');
    // New account - safe to enforce the strict Ethiopian mobile format here.
    $phone = etel_validate_phone($phone_raw);
    if (!$phone) fail('Enter a valid Ethiopian mobile number, e.g. 0911234567');
    $name  = isset($body['name']) ? trim($body['name']) : $phone;
    if (!$pw || strlen($pw) < 8) fail('Password must be at least 8 characters');
    
    // Check duplicate
    $chk = $pdo->prepare('SELECT id FROM users WHERE phone=?');
    $chk->execute(array($phone));
    if ($chk->fetch()) fail('Phone already registered');
    
    $hash = password_hash($pw, PASSWORD_DEFAULT);
    // Build one INSERT from the actual schema instead of blindly trying
    // several variants and swallowing whichever ones fail - that used to
    // mask real errors (e.g. a duplicate key would look the same as a
    // missing column and just get silently retried).
    $userCols = etel_table_columns($pdo, 'users');
    $fields = array('name', 'phone', 'password_hash', 'role');
    $marks  = array('?', '?', '?', '?');
    $values = array($name, $phone, $hash, $role);
    if (in_array('active', $userCols, true)) { $fields[] = 'active'; $marks[] = '1'; }
    $fields[] = 'created_at'; $marks[] = 'NOW()';
    $sql = 'INSERT INTO users (' . implode(',', $fields) . ') VALUES (' . implode(',', $marks) . ')';
    $uid = 0;
    try {
        $s = $pdo->prepare($sql);
        $s->execute($values);
        $uid = intval($pdo->lastInsertId());
    } catch (Exception $e) {
        fail_ex($e, 'Could not create user', 500);
    }
    if (!$uid) fail('Could not create user');
    
    ok(array('user_id' => $uid, 'phone' => $phone, 'role' => $role));
    break;
}
case 'admin_assign_service_giver': {
    $pdo = db();
    $admin = require_admin($pdo, 'service_givers.manage');
    
    $order_id = isset($body['order_id']) ? intval($body['order_id']) : 0;
    $sg_id    = isset($body['service_giver_id']) ? intval($body['service_giver_id']) : 0;
    
    if (!$order_id || !$sg_id) fail('order_id and service_giver_id required', 400);
    
    // Verify order exists
    $ord_chk = $pdo->prepare('SELECT id FROM orders WHERE id=?');
    $ord_chk->execute(array($order_id));
    if (!$ord_chk->fetch()) fail('Order not found', 404);
    
    // Verify service giver exists
    $sg_chk = $pdo->prepare('SELECT id FROM service_givers WHERE id=?');
    $sg_chk->execute(array($sg_id));
    if (!$sg_chk->fetch()) fail('Service giver not found', 404);
    
    // Check for existing assignment
    $exist_chk = $pdo->prepare('SELECT id FROM order_assignments WHERE order_id=? AND service_giver_id=?');
    $exist_chk->execute(array($order_id, $sg_id));
    if ($exist_chk->fetch()) fail('Assignment already exists', 409);
    
    try {
        $pdo->beginTransaction();
        
        $ins = $pdo->prepare('INSERT INTO order_assignments (order_id, service_giver_id, status, assigned_at) VALUES (?, ?, ?, NOW())');
        $ins->execute(array($order_id, $sg_id, 'pending'));
        $assignment_id = $pdo->lastInsertId();
        
        $upd = $pdo->prepare('UPDATE orders SET status="assigned" WHERE id=?');
        $upd->execute(array($order_id));
        
        $pdo->commit();
        
        ok(array('message' => 'Order assigned to service giver', 'assignment_id' => $assignment_id));
    } catch (Exception $e) {
        $pdo->rollBack();
        fail_ex($e, 'Assignment failed', 500);
    }
    break;
}
case 'admin_add_item': {
    // POST: (requires admin auth)  service_giver_id, name, description, category, price, etc.
    // Admin adds item for a service giver
    $pdo = db();
    $admin = require_admin($pdo, 'items.manage');
    
    $sg_id = isset($body['service_giver_id']) ? intval($body['service_giver_id']) : 0;
    if (!$sg_id) fail('service_giver_id is required', 400);
    
    $name = isset($body['name']) ? trim($body['name']) : '';
    $description = isset($body['description']) ? trim($body['description']) : '';
    $category = isset($body['category']) ? trim($body['category']) : '';
    $stock_quantity = isset($body['stock_quantity']) ? intval($body['stock_quantity']) : 0;
    $preparation_time = isset($body['preparation_time']) ? intval($body['preparation_time']) : 30;
    $image_url = isset($body['image_url']) ? trim($body['image_url']) : '';

    if (!$name) fail('Item name is required', 400);
    if (!$category || !in_array($category, array('food','grocery','package','pharmacy','flower'))) {
        fail('Valid category is required', 400);
    }
    $price = etel_validate_amount(isset($body['price']) ? $body['price'] : null);
    if ($price === false) fail('Price must be a valid amount greater than 0', 400);
    $original_price = isset($body['original_price']) && $body['original_price'] !== ''
        ? etel_validate_amount($body['original_price'])
        : $price;
    if ($original_price === false) fail('Original price must be a valid amount greater than 0', 400);

    try {
        $item_code = strtoupper(substr($category, 0, 4)) . rand(1000, 9999);

        $ins = $pdo->prepare("
            INSERT INTO items (
                service_giver_id, item_code, name, description, category,
                price, original_price, stock_quantity, preparation_time, image_url, is_available
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $ins->execute(array(
            $sg_id, $item_code, $name, $description, $category,
            $price, $original_price, $stock_quantity, $preparation_time, $image_url
        ));
        
        $item_id = $pdo->lastInsertId();
        
        ok(array(
            'item_id' => $item_id,
            'item_code' => $item_code,
            'message' => 'Item added successfully'
        ));
    } catch (Exception $e) {
        fail_ex($e, 'Failed to add item', 500);
    }
    break;
}
case 'admin_get_all_items': {
    // GET: (requires admin auth)  ?category=food&service_giver_id=X
    // Admin views all items
    $pdo = db();
    $admin = require_admin($pdo, 'items.view');
    
    $category = isset($_GET['category']) ? trim($_GET['category']) : '';
    $sg_id = isset($_GET['service_giver_id']) ? intval($_GET['service_giver_id']) : 0;
    
    $where = 'WHERE 1=1';
    $params = array();
    
    if ($category && in_array($category, array('food','grocery','package','pharmacy','flower'))) {
        $where .= ' AND i.category = ?';
        $params[] = $category;
    }
    
    if ($sg_id > 0) {
        $where .= ' AND i.service_giver_id = ?';
        $params[] = $sg_id;
    }
    
    try {
        $s = $pdo->prepare("
            SELECT 
                i.*,
                sg.business_name,
                sg.zone,
                sg.service_type
            FROM items i
            JOIN service_givers sg ON i.service_giver_id = sg.id
            $where
            ORDER BY i.created_at DESC
        ");
        $s->execute($params);
        $items = $s->fetchAll();
    } catch (Exception $e) {
        fail_ex($e, 'Query failed', 500);
    }
    
    ok(array('items' => $items, 'count' => count($items)));
    break;
}
case 'admin_update_item': {
    $pdo = db();
    require_admin($pdo, 'items.manage');
    $item_id = isset($body['item_id']) ? intval($body['item_id']) : 0;
    if (!$item_id) fail('item_id is required', 400);
    $allowed = array('name','description','category','price','original_price','stock_quantity','preparation_time','image_url','is_available');
    $updates = array(); $params = array();
    foreach ($allowed as $f) {
        if (isset($body[$f])) { $updates[] = "$f=?"; $params[] = $body[$f]; }
    }
    if (empty($updates)) fail('No fields to update', 400);
    $params[] = $item_id;
    try {
        $pdo->prepare('UPDATE items SET '.implode(',',$updates).' WHERE id=?')->execute($params);
        ok(array('updated'=>true));
    } catch (Exception $e) { fail_ex($e, 'Update failed', 500); }
    break;
}
case 'admin_delete_item': {
    $pdo = db();
    require_admin($pdo, 'items.manage');
    $item_id = isset($body['item_id']) ? intval($body['item_id']) : 0;
    if (!$item_id) fail('item_id is required', 400);
    try {
        $pdo->prepare('DELETE FROM items WHERE id=?')->execute(array($item_id));
        ok(array('deleted'=>true));
    } catch (Exception $e) { fail_ex($e, 'Delete failed', 500); }
    break;
}
case 'admin_toggle_item': {
    $pdo = db();
    require_admin($pdo, 'items.manage');
    $item_id      = isset($body['item_id'])      ? intval($body['item_id'])      : 0;
    $is_available = isset($body['is_available']) ? intval($body['is_available']) : 0;
    if (!$item_id) fail('item_id is required', 400);
    try {
        $pdo->prepare('UPDATE items SET is_available=? WHERE id=?')->execute(array($is_available,$item_id));
        ok(array('toggled'=>true,'is_available'=>$is_available));
    } catch (Exception $e) { fail_ex($e, 'Toggle failed', 500); }
    break;
}
// NOTE: admin_get_service_givers_list had its own standalone dead copy here,
// and the block below used to also carry a bare 'admin_get_service_givers'
// case label. boot.php's own inline case ('admin_get_service_givers':
// case 'admin_get_service_givers_list':) always resolves both of those
// action names before $action reaches this file - only 'get_service_givers'
// (a different action name) is actually delegated here. Removed the dead
// standalone block and the dead label 2026-07-12, same cross-file
// shadowing pattern as admin_create_service_giver above.
case 'get_service_givers': {
    // GET: (requires admin auth)  ?status=pending|verified|suspended&limit=100
    // List all service givers with optional filtering
    $pdo = db();
    $admin = require_admin($pdo, 'service_givers.view');
    
    // Check if service_givers table exists
    try {
        $result = $pdo->query("SHOW TABLES LIKE 'service_givers'");
        if ($result->rowCount() == 0) {
            fail('Service Givers table not initialized. Please import delivery_servicegiver_schema.sql', 503);
        }
    } catch (Exception $e) {
        fail_ex($e, 'Database check failed', 500);
    }
    
    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
    
    $where = 'WHERE 1=1';
    $params = array();
    
    if ($status && in_array($status, array('pending','verified','suspended','inactive'))) {
        $where .= ' AND sg.status = ?';
        $params[] = $status;
    }
    
    try {
        $s = $pdo->prepare("
            SELECT 
                sg.id,
                sg.user_id,
                sg.service_giver_code,
                sg.business_name,
                sg.service_type,
                sg.vehicle_type,
                sg.vehicle_plate,
                sg.zone,
                sg.address,
                sg.city,
                sg.identification_number,
                sg.status,
                sg.is_online,
                sg.average_rating,
                sg.total_deliveries,
                sg.completed_deliveries,
                sg.failed_deliveries,
                sg.total_earnings,
                sg.rating_count,
                sg.created_at,
                u.name,
                u.phone
            FROM service_givers sg
            JOIN users u ON sg.user_id = u.id
            $where
            ORDER BY sg.created_at DESC
            LIMIT $limit
        ");
        $s->execute($params);
        $service_givers = $s->fetchAll();
    } catch (Exception $e) {
        fail_ex($e, 'Query failed', 500);
    }
    
    ok(array('service_givers' => $service_givers, 'count' => count($service_givers)));
    break;
}
case 'admin_get_service_giver': {
    // GET: (requires admin auth)  ?id=N
    // Get single service giver details
    $pdo = db();
    $admin = require_admin($pdo, 'service_givers.view');
    
    $sg_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if (!$sg_id) fail('Service giver ID required', 400);
    
    try {
        $s = $pdo->prepare("
            SELECT 
                sg.*,
                u.name,
                u.phone,
                u.role
            FROM service_givers sg
            JOIN users u ON sg.user_id = u.id
            WHERE sg.id = ?
        ");
        $s->execute(array($sg_id));
        $sg = $s->fetch();
    } catch (Exception $e) {
        fail_ex($e, 'Query failed', 500);
    }
    
    if (!$sg) fail('Service giver not found', 404);
    
    ok(array('service_giver' => $sg));
    break;
}

// NOTE: admin_get_users, admin_get_couriers, and the admin_get_orders/
// get_orders pair each had a standalone dead copy here too - all three
// action names are resolved by their own inline cases in boot.php before
// $action ever reaches this file. Removed 2026-07-12, same shadowing
// pattern as above.

case 'admin_update_order': {
    $pdo = db();
    require_admin($pdo, 'orders.manage');
    $order_id = isset($body['order_id']) ? intval($body['order_id']) : 0;
    $status   = isset($body['status'])   ? trim($body['status'])   : '';
    if (!$order_id) fail('order_id required', 400);
    if (!in_array($status, array('pending','assigned','picked_up','in_transit','delivered','cancelled'))) fail('Invalid status', 400);
    try {
        $extra = ($status === 'delivered') ? ', delivered_at=NOW()' : '';
        $pdo->prepare("UPDATE orders SET status=? $extra WHERE id=?")->execute(array($status, $order_id));
        if (function_exists('etel_notify_order_status')) etel_notify_order_status($pdo, $order_id);
        ok(array('message' => 'Order updated', 'order_id' => $order_id, 'status' => $status));
    } catch (Exception $e) { fail_ex($e, 'Update failed', 500); }
    break;
}

case 'admin_get_order_receipt': {
    // Full order + line items + the fulfilling business's name, for the
    // admin "Print Receipt" button - any order, not just ones a service
    // giver is handling right now (that's the servicegiver.html flow,
    // which only knows about its own assignments).
    $pdo = db();
    require_admin($pdo, 'orders.view');
    $order_id = isset($body['order_id']) ? intval($body['order_id']) : (isset($_GET['order_id']) ? intval($_GET['order_id']) : 0);
    if (!$order_id) fail('order_id is required', 400);

    $oq = $pdo->prepare('SELECT id, order_code, from_zone, to_zone, package_type, recipient_name, recipient_phone, fare_total, status, placed_at, notes FROM orders WHERE id=?');
    $oq->execute(array($order_id));
    $order = $oq->fetch();
    if (!$order) fail('Order not found', 404);

    $iq = $pdo->prepare('SELECT item_name, quantity, unit_price, subtotal FROM order_items WHERE order_id=? ORDER BY id ASC');
    $iq->execute(array($order_id));
    $items = $iq->fetchAll();

    $business_name = '';
    try {
        $bq = $pdo->prepare('SELECT sg.business_name FROM order_assignments oa JOIN service_givers sg ON oa.service_giver_id = sg.id WHERE oa.order_id=? ORDER BY oa.id DESC LIMIT 1');
        $bq->execute(array($order_id));
        $brow = $bq->fetch();
        if ($brow) $business_name = $brow['business_name'];
    } catch (Exception $e) { /* no assignment yet - fine, print without a business name */ }

    ok(array('order' => $order, 'items' => $items, 'business_name' => $business_name));
    break;
}

// NOTE: admin_verify_service_giver and admin_suspend_service_giver had
// standalone dead copies here as well - neither action name is even in
// boot.php's delegate list for this file; both are fully handled by their
// own inline cases in boot.php. Removed 2026-07-12.
// admin_create_service_giver used to have a second, unreachable copy
// here (with different defaults: service_type 'delivery', vehicle_type
// 'motorcycle', zone 'Bole' - vs. the live one's 'retail'/'on_foot'/'Mehal
// Ketema'). boot.php resolves this action with its own inline case before
// $action ever reaches this required file, so this copy could never
// execute and its different defaults were pure dead weight. Removed
// 2026-07-12 after tracing the call path to confirm boot.php's case
// always wins - same pattern as the admin_get_confirmations/
// admin_force_confirm/admin_analytics misrouting fixed earlier this
// session, just dead rather than live-wrong this time.
case 'admin_get_service_giver_statistics': {
    // GET: (requires admin auth)
    // Get overall service giver statistics for admin dashboard
    $pdo = db();
    $admin = require_admin($pdo, 'service_givers.view');
    
    $stats = array(
        'total' => 0,
        'verified' => 0,
        'pending' => 0,
        'suspended' => 0,
        'online' => 0,
        'total_deliveries' => 0,
        'total_earnings' => 0
    );
    
    try {
        // Check if service_givers table exists
        $result = $pdo->query("SHOW TABLES LIKE 'service_givers'");
        if ($result->rowCount() == 0) {
            ok($stats);  // Return empty stats if table doesn't exist yet
            break;
        }
        
        // Total counts by status
        $s = $pdo->query('SELECT status, COUNT(*) as c FROM service_givers GROUP BY status');
        if ($s) {
            $rows = $s->fetchAll();
            foreach ($rows as $r) {
                $stats['total'] += intval($r['c']);
                if ($r['status'] === 'verified') $stats['verified'] = intval($r['c']);
                if ($r['status'] === 'pending') $stats['pending'] = intval($r['c']);
                if ($r['status'] === 'suspended') $stats['suspended'] = intval($r['c']);
            }
        }
        
        // Online count
        $s = $pdo->query('SELECT COUNT(*) as c FROM service_givers WHERE is_online=1');
        if ($s) {
            $r = $s->fetch();
            $stats['online'] = intval($r['c']);
        }
        
        // Total deliveries and earnings
        $s = $pdo->query('SELECT SUM(total_deliveries) as td, SUM(total_earnings) as te FROM service_givers');
        if ($s) {
            $r = $s->fetch();
            $stats['total_deliveries'] = intval($r['td']) ?: 0;
            $stats['total_earnings'] = floatval($r['te']) ?: 0.00;
        }
    } catch (Exception $e) {
        // Silently return empty stats on error
        ok($stats);
        break;
    }

    ok($stats);
    break;
}
case 'admin_get_confirmations': {
    $pdo = db();
    require_admin($pdo, 'orders.view');

    try {
        $confs = $pdo->query("
            SELECT
                oc.id, oc.order_id, oc.type, oc.status,
                oc.requested_at, oc.responded_at, oc.payment_confirmed,
                o.order_code, o.from_zone, o.to_zone, o.fare_total, o.status AS order_status,
                o.payment_method,
                u_req.name  AS courier_name,
                u_req.phone AS courier_phone,
                u_res.name  AS responder_name
            FROM   order_confirmations oc
            JOIN   orders o      ON o.id      = oc.order_id
            JOIN   users  u_req  ON u_req.id  = oc.requested_by
            LEFT JOIN users u_res ON u_res.id = oc.responded_by
            ORDER  BY oc.requested_at DESC
            LIMIT  200
        ")->fetchAll();
    } catch (Exception $e) {
        fail_ex($e, 'Query failed', 500);
    }

    $pending_pickup   = intval($pdo->query("SELECT COUNT(*) FROM order_confirmations WHERE type='pickup'   AND status='pending'")->fetchColumn());
    $pending_delivery = intval($pdo->query("SELECT COUNT(*) FROM order_confirmations WHERE type='delivery' AND status='pending'")->fetchColumn());

    ok(array(
        'confirmations'    => $confs,
        'pending_pickup'   => $pending_pickup,
        'pending_delivery' => $pending_delivery,
    ));
    break;
}
case 'admin_backfill_order_service_giver_ids': {
    // One-time data repair: orders.service_giver_id is the direct,
    // authoritative link used by sg_pending_confirmations and
    // sg_confirm_pickup (OrderController.php) - but some historical
    // orders (created before this column was reliably set, or via an
    // older/test code path) may have it NULL or 0 even though a real
    // order_assignments row exists recording which shop it belongs
    // to. This backfills those from order_assignments, which is why
    // "Force Confirm" here in admin always worked regardless (it
    // bypasses SG-matching entirely) while the natural SG-side flow
    // silently failed to find these specific orders.
    $pdo = db();
    require_admin($pdo, 'orders.manage');
    try {
        $before = $pdo->query("SELECT COUNT(*) FROM orders WHERE service_giver_id IS NULL OR service_giver_id = 0")->fetchColumn();
        $pdo->exec("
            UPDATE orders o
            JOIN order_assignments oa ON oa.order_id = o.id
            SET o.service_giver_id = oa.service_giver_id
            WHERE (o.service_giver_id IS NULL OR o.service_giver_id = 0)
        ");
        $after = $pdo->query("SELECT COUNT(*) FROM orders WHERE service_giver_id IS NULL OR service_giver_id = 0")->fetchColumn();
        ok(array(
            'message' => 'Backfill complete',
            'orders_missing_before' => intval($before),
            'orders_missing_after' => intval($after),
            'orders_repaired' => intval($before) - intval($after),
        ));
    } catch (Exception $e) {
        fail_ex($e, 'Backfill failed', 500);
    }
    break;
}
case 'admin_force_confirm': {
    $pdo   = db();
    $admin = require_admin($pdo, 'orders.manage');

    $conf_id = isset($body['conf_id']) ? intval($body['conf_id']) : 0;
    if (!$conf_id) fail('conf_id required', 400);

    $s = $pdo->prepare('SELECT * FROM order_confirmations WHERE id=?');
    $s->execute(array($conf_id));
    $conf = $s->fetch();
    if (!$conf) fail('Confirmation not found', 404);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE order_confirmations SET status='confirmed', responded_by=?, responded_at=NOW() WHERE id=?")
            ->execute(array($admin['id'], $conf_id));

        if ($conf['type'] === 'pickup') {
            $pdo->prepare("UPDATE orders SET status='picked_up' WHERE id=? AND status='assigned'")->execute(array($conf['order_id']));
            $pdo->prepare("UPDATE order_assignments SET status='picked_up' WHERE order_id=?")->execute(array($conf['order_id']));
        if (function_exists('etel_notify_order_status')) etel_notify_order_status($pdo, $conf['order_id']);
        } else {
            $pdo->prepare("UPDATE orders SET status='delivered', delivered_at=NOW() WHERE id=?")->execute(array($conf['order_id']));
            $pdo->prepare("UPDATE order_assignments SET status='delivered' WHERE order_id=?")->execute(array($conf['order_id']));
        if (function_exists('etel_notify_order_status')) etel_notify_order_status($pdo, $conf['order_id']);
        }
        $pdo->commit();
        ok(array('message' => 'Confirmation forced by admin'));
    } catch (Exception $e) {
        $pdo->rollBack();
        fail_ex($e, 'Failed', 500);
    }
    break;
}
case 'admin_analytics': {
    $pdo = db();
    require_admin($pdo, 'analytics.view');

    $period = isset($_GET['period']) ? trim($_GET['period']) : 'week'; // today | week | month
    if ($period === 'today') {
        $dateFilter = "DATE(placed_at) = CURDATE()";
    } elseif ($period === 'month') {
        $dateFilter = "placed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    } else {
        $dateFilter = "placed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    }

    $result = array('period' => $period);

    try {
        // Revenue & order counts
        $r = $pdo->query("SELECT
                COUNT(*) as total_orders,
                COALESCE(SUM(fare_total),0) as total_revenue,
                COALESCE(AVG(fare_total),0) as avg_order_value,
                SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status='pending'   THEN 1 ELSE 0 END) as pending
            FROM orders WHERE $dateFilter")->fetch();
        $result['orders'] = $r;

        // Revenue by zone
        $s = $pdo->query("SELECT to_zone as zone, COUNT(*) as orders, COALESCE(SUM(fare_total),0) as revenue
            FROM orders WHERE $dateFilter GROUP BY to_zone ORDER BY orders DESC LIMIT 12");
        $result['by_zone'] = $s->fetchAll();

        // Daily revenue trend (last 7 days regardless of period)
        $s = $pdo->query("SELECT DATE(placed_at) as day,
                COUNT(*) as orders, COALESCE(SUM(fare_total),0) as revenue
            FROM orders WHERE placed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY day ORDER BY day ASC");
        $result['daily_trend'] = $s->fetchAll();

        // Top service givers
        $s = $pdo->query("SELECT sg.business_name, COUNT(oa.order_id) as orders,
                COALESCE(SUM(o.fare_total),0) as revenue
            FROM order_assignments oa
            JOIN service_givers sg ON oa.service_giver_id = sg.id
            JOIN orders o ON oa.order_id = o.id
            WHERE $dateFilter
            GROUP BY oa.service_giver_id ORDER BY orders DESC LIMIT 5");
        $result['top_service_givers'] = $s->fetchAll();

        // Payment method breakdown
        try {
            $s = $pdo->query("SELECT payment_method, COUNT(*) as count FROM orders
                WHERE $dateFilter GROUP BY payment_method");
            $result['payment_methods'] = $s->fetchAll();
        } catch (Exception $e2) { $result['payment_methods'] = array(); }

        // Average rating
        try {
            $r2 = $pdo->query("SELECT AVG(stars) as avg_rating, COUNT(*) as rating_count FROM order_ratings")->fetch();
            $result['ratings'] = array('average' => round(floatval($r2['avg_rating']), 2), 'count' => intval($r2['rating_count']));
        } catch (Exception $e2) { $result['ratings'] = array('average' => 0, 'count' => 0); }

    } catch (Exception $e) {
        fail_ex($e, 'Analytics query failed', 500);
    }

    ok($result);
    break;
}

case 'admin_backfill_cash_commission': {
    /* One-time, admin-triggered backfill for the newly-activated 5%
       platform commission on CASH rides only - wallet rides are
       deliberately excluded and left untouched (grandfathered at their
       original 100% credit), since retroactively reducing
       total_earnings could push a driver's available_balance
       (total_earnings - total_paid_out) negative if they've already
       withdrawn that money. Cash carries no such risk: commission_owed
       is a debt the driver owes the platform, not money already paid
       out to them, so it's safe to (re)compute from scratch.
       SETS commission_owed (not increment) from the actual completed
       cash-ride history in `rides`, making this safe to re-run more
       than once without double-counting. */
    $pdo = db();
    require_admin($pdo, 'finance.manage');
    $rate = 0.05;
    $result = array('updated_drivers' => 0, 'total_commission_backfilled' => 0, 'rate_used' => $rate);
    try {
        $pdo->beginTransaction();
        $s = $pdo->query("SELECT driver_id, SUM(fare_paid) AS total_cash
                           FROM rides
                           WHERE status='completed' AND payment_method='cash' AND driver_id IS NOT NULL
                           GROUP BY driver_id");
        $rows = $s->fetchAll();
        $upd = $pdo->prepare("UPDATE ride_drivers SET commission_owed=? WHERE user_id=?");
        $totalBackfilled = 0;
        foreach ($rows as $row) {
            $commission = round(floatval($row['total_cash']) * $rate, 2);
            $upd->execute(array($commission, $row['driver_id']));
            $totalBackfilled += $commission;
        }
        $pdo->commit();
        $result['updated_drivers'] = count($rows);
        $result['total_commission_backfilled'] = round($totalBackfilled, 2);
    } catch (Exception $e) {
        $pdo->rollBack();
        fail_ex($e, 'Backfill failed', 500);
    }
    ok($result);
    break;
}

case 'admin_topup_etel_money': {
    /* Manual admin-recorded top-up, for drivers who pay cash or a bank
       transfer directly at the office rather than through Etel Pay.
       Logged in the same driver_etel_money_topups table as the
       automatic Etel Pay path (source='admin_manual' instead of
       'etelpay'), so both routes show up together in one place if a
       top-up history view gets built later. */
    $pdo = db();
    $admin = etel_require_ride_pay($pdo, 'finance.manage', 'rides.manage');
    $driverId = isset($body['driver_id']) ? intval($body['driver_id']) : 0;
    if (!$driverId) fail('driver_id required');
    $amount = etel_validate_amount(isset($body['amount']) ? $body['amount'] : null);
    if ($amount === false) fail('amount must be a valid amount greater than 0');
    $note = isset($body['note']) ? mb_substr(trim($body['note']), 0, 255) : '';

    $chk = $pdo->prepare("SELECT user_id FROM ride_drivers WHERE user_id=?");
    $chk->execute(array($driverId));
    if (!$chk->fetch()) fail('Driver not found', 404);

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS driver_etel_money_topups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            tx_ref VARCHAR(40) NOT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'etelpay',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            note VARCHAR(255) NULL,
            approved_by INT UNSIGNED NULL,
            approved_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_tx_ref (tx_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    rides_add_missing_cols($pdo, 'driver_etel_money_topups', array(
        'status'      => "status VARCHAR(20) NOT NULL DEFAULT 'pending'",
        'approved_by' => "approved_by INT UNSIGNED NULL",
        'approved_at' => "approved_at DATETIME NULL"
    ));

    $manualRef = 'admin-manual-' . $driverId . '-' . time() . '-' . bin2hex(random_bytes(4));
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE ride_drivers SET etel_money_balance = etel_money_balance + ? WHERE user_id=?")
            ->execute(array($amount, $driverId));
        $pdo->prepare("INSERT INTO driver_etel_money_topups (user_id, amount, tx_ref, source, status, note, approved_by, approved_at) VALUES (?, ?, ?, 'admin_manual', 'approved', ?, ?, NOW())")
            ->execute(array($driverId, $amount, $manualRef, $note, $admin['id']));
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        fail_ex($e, 'Top-up failed', 500);
    }

    $bal = $pdo->prepare("SELECT etel_money_balance FROM ride_drivers WHERE user_id=?");
    $bal->execute(array($driverId));
    ok(array('driver_id' => $driverId, 'amount' => $amount, 'etel_money_balance' => round(floatval($bal->fetchColumn()), 2)));
    break;
}

case 'admin_ride_payments': {
    /* Ride Payments console: fare revenue by payment method, driver balances,
       recent driver payouts and pending driver Etel Money top-ups. Ride data only. */
    $pdo = db();
    etel_require_ride_pay($pdo, 'finance.view', 'rides.view');
    $out = array('by_method' => array(), 'totals' => array(), 'payouts' => array(), 'topups' => array());
    try {
        $out['by_method'] = $pdo->query("SELECT COALESCE(NULLIF(payment_method,''),'cash') AS method, COUNT(*) AS trips,
                COALESCE(SUM(fare_paid),0) AS amount
            FROM rides WHERE status='completed' GROUP BY 1 ORDER BY amount DESC")->fetchAll(PDO::FETCH_ASSOC);
        $t = $pdo->query("SELECT
                COALESCE(SUM(CASE WHEN DATE(completed_at)=CURDATE() THEN fare_paid END),0) AS today,
                COALESCE(SUM(CASE WHEN completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN fare_paid END),0) AS week,
                COALESCE(SUM(CASE WHEN completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN fare_paid END),0) AS month,
                COALESCE(SUM(fare_paid),0) AS total, COUNT(*) AS trips
            FROM rides WHERE status='completed'")->fetch(PDO::FETCH_ASSOC);
        $d = $pdo->query("SELECT COALESCE(SUM(total_earnings),0) AS earnings, COALESCE(SUM(total_paid_out),0) AS paid_out,
                COALESCE(SUM(commission_owed),0) AS commission_owed, COALESCE(SUM(etel_money_balance),0) AS etel_money
            FROM ride_drivers")->fetch(PDO::FETCH_ASSOC);
        $out['totals'] = array_merge($t ?: array(), $d ?: array());
    } catch (Throwable $e) { error_log('[ETEL] admin_ride_payments totals: ' . $e->getMessage()); }
    try {
        $out['payouts'] = $pdo->query("SELECT p.id, p.amount, p.status, p.tx_ref, p.created_at, u.name AS driver_name, u.phone AS driver_phone
            FROM driver_payouts p LEFT JOIN users u ON u.id = p.user_id ORDER BY p.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { /* table is created on the first cash-out */ }
    try {
        $out['topups'] = $pdo->query("SELECT t.id, t.amount, t.tx_ref, t.source, t.created_at, u.name AS driver_name, u.phone AS driver_phone
            FROM driver_etel_money_topups t JOIN users u ON u.id = t.user_id
            WHERE t.status='pending' ORDER BY t.created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { /* table is created on the first top-up */ }
    ok($out);
    break;
}

case 'admin_get_pending_etel_topups': {
    /* Driver-initiated top-ups waiting on an admin's explicit approval
       before they touch etel_money_balance - see driver_topup_etel_money
       for why that gate exists (Etel Pay is shared across all of Etel
       Group, not scoped to this specific driver ledger). Covers both
       source='etelpay' (auto-verified against Etel Pay, tx_ref-based)
       and source='manual_receipt' (bank transfer / cash deposit,
       photo-based - see driver_submit_manual_topup) so admins review
       both kinds from one queue. receipt_image_url is included so the
       approval screen can show the driver's uploaded receipt photo. */
    $pdo = db();
    etel_require_ride_pay($pdo, 'finance.view', 'rides.view');
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS driver_etel_money_topups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            tx_ref VARCHAR(40) NOT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'etelpay',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            note VARCHAR(255) NULL,
            receipt_image_url VARCHAR(500) NULL,
            approved_by INT UNSIGNED NULL,
            approved_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_tx_ref (tx_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    rides_add_missing_cols($pdo, 'driver_etel_money_topups', array(
        'status'             => "status VARCHAR(20) NOT NULL DEFAULT 'pending'",
        'approved_by'        => "approved_by INT UNSIGNED NULL",
        'approved_at'        => "approved_at DATETIME NULL",
        'receipt_image_url'  => "receipt_image_url VARCHAR(500) NULL"
    ));
    $s = $pdo->query("SELECT t.id, t.user_id, t.amount, t.tx_ref, t.source, t.note, t.receipt_image_url, t.created_at,
                              u.name AS driver_name, u.phone AS driver_phone
                       FROM driver_etel_money_topups t
                       JOIN users u ON u.id = t.user_id
                       WHERE t.status='pending' AND t.source IN ('etelpay','manual_receipt')
                       ORDER BY t.created_at ASC");
    ok(array('pending' => $s->fetchAll()));
    break;
}

case 'admin_approve_etel_topup': {
    /* Credits etel_money_balance only now, at approval time - not when
       the driver originally submitted the (already Etel-Pay-verified)
       request. Row-locked so two admins clicking Approve on the same
       request at the same moment can't double-credit it. */
    $pdo = db();
    $admin = etel_require_ride_pay($pdo, 'finance.manage', 'rides.manage');
    $topupId = isset($body['id']) ? intval($body['id']) : 0;
    if (!$topupId) fail('id required');

    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare("SELECT * FROM driver_etel_money_topups WHERE id=? FOR UPDATE");
        $s->execute(array($topupId));
        $row = $s->fetch();
        if (!$row) { $pdo->rollBack(); fail('Top-up request not found', 404); }
        if ($row['status'] !== 'pending') { $pdo->rollBack(); fail('This request is not pending (already ' . $row['status'] . ')', 409); }

        $pdo->prepare("UPDATE ride_drivers SET etel_money_balance = etel_money_balance + ? WHERE user_id=?")
            ->execute(array($row['amount'], $row['user_id']));
        $pdo->prepare("UPDATE driver_etel_money_topups SET status='approved', approved_by=?, approved_at=NOW() WHERE id=?")
            ->execute(array($admin['id'], $topupId));
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        fail_ex($e, 'Approval failed', 500);
    }

    $bal = $pdo->prepare("SELECT etel_money_balance FROM ride_drivers WHERE user_id=?");
    $bal->execute(array($row['user_id']));
    ok(array('id' => $topupId, 'driver_id' => $row['user_id'], 'amount' => $row['amount'], 'etel_money_balance' => round(floatval($bal->fetchColumn()), 2)));
    break;
}

case 'admin_reject_etel_topup': {
    $pdo = db();
    $admin = etel_require_ride_pay($pdo, 'finance.manage', 'rides.manage');
    $topupId = isset($body['id']) ? intval($body['id']) : 0;
    if (!$topupId) fail('id required');
    $reason = isset($body['reason']) ? mb_substr(trim($body['reason']), 0, 255) : '';

    $s = $pdo->prepare("SELECT status FROM driver_etel_money_topups WHERE id=?");
    $s->execute(array($topupId));
    $row = $s->fetch();
    if (!$row) fail('Top-up request not found', 404);
    if ($row['status'] !== 'pending') fail('This request is not pending (already ' . $row['status'] . ')', 409);

    $pdo->prepare("UPDATE driver_etel_money_topups SET status='rejected', approved_by=?, approved_at=NOW(), note=? WHERE id=?")
        ->execute(array($admin['id'], $reason, $topupId));
    ok(array('id' => $topupId, 'status' => 'rejected'));
    break;
}

case 'admin_ride_growth': {
    /* Growth over time for Ride trips and driver signups, at four
       granularities (daily/weekly/monthly/yearly), for the admin dashboard's
       growth chart and for pulling into investor materials as the
       company scales. Aggregated server-side (GROUP BY) rather than
       shipping raw rows to the client, same reasoning as
       admin_analytics above - keeps the payload small regardless of
       how many rides/drivers exist by the time this actually gets used
       at scale.
       Trips = completed rides only (a real, paid trip - not every
       'searching'/'cancelled' row). Drivers = distinct users with a
       ride_drivers row, bucketed by their account's own created_at
       (ride_drivers has no signup timestamp of its own, so this joins
       to users.created_at, which every account has). */
    $pdo = db();
    etel_require_ride_pay($pdo, 'analytics.view', 'rides.view');

    // Optional custom date range (📅 date picker on the dashboard) - when
    // both dates are given, also return a daily breakdown bounded to that
    // exact range, in addition to the fixed daily/weekly/monthly/yearly
    // windows above. Validated and capped at 2 years to keep the query
    // and payload bounded regardless of what a user types in.
    $dateFrom = isset($body['date_from']) ? trim($body['date_from']) : '';
    $dateTo   = isset($body['date_to'])   ? trim($body['date_to'])   : '';
    $customRange = null;
    if ($dateFrom !== '' && $dateTo !== ''
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $fromTs = strtotime($dateFrom);
        $toTs   = strtotime($dateTo);
        if ($fromTs !== false && $toTs !== false && $fromTs <= $toTs
            && ($toTs - $fromTs) <= (2 * 365 * 86400)) {
            $customRange = array('from' => $dateFrom, 'to' => $dateTo);
        }
    }

    $result = array();
    try {
        // --- Trips (completed rides), daily (last 30 days) ---
        $s = $pdo->query("SELECT DATE_FORMAT(completed_at, '%Y-%m-%d') AS period, COUNT(*) AS count
            FROM rides
            WHERE status='completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY period ORDER BY period ASC");
        $result['trips_daily'] = $s->fetchAll();

        // --- Drivers onboarded, daily (last 30 days) ---
        $s = $pdo->query("SELECT DATE_FORMAT(u.created_at, '%Y-%m-%d') AS period, COUNT(*) AS count
            FROM ride_drivers rd JOIN users u ON u.id = rd.user_id
            WHERE u.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY period ORDER BY period ASC");
        $result['drivers_daily'] = $s->fetchAll();

        // --- Trips (completed rides), weekly (last 12 ISO weeks) ---
        $s = $pdo->query("SELECT YEARWEEK(completed_at, 3) AS period_key,
                CONCAT(YEAR(completed_at), '-W', LPAD(WEEK(completed_at, 3), 2, '0')) AS period,
                COUNT(*) AS count
            FROM rides
            WHERE status='completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 12 WEEK)
            GROUP BY period_key, period ORDER BY period_key ASC");
        $result['trips_weekly'] = $s->fetchAll();

        // --- Trips, monthly (last 12 months) ---
        $s = $pdo->query("SELECT DATE_FORMAT(completed_at, '%Y-%m') AS period, COUNT(*) AS count
            FROM rides
            WHERE status='completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY period ORDER BY period ASC");
        $result['trips_monthly'] = $s->fetchAll();

        // --- Trips, yearly (all years with data) ---
        $s = $pdo->query("SELECT YEAR(completed_at) AS period, COUNT(*) AS count
            FROM rides
            WHERE status='completed' AND completed_at IS NOT NULL
            GROUP BY period ORDER BY period ASC");
        $result['trips_yearly'] = $s->fetchAll();

        // --- Drivers onboarded, weekly (last 12 ISO weeks) ---
        $s = $pdo->query("SELECT YEARWEEK(u.created_at, 3) AS period_key,
                CONCAT(YEAR(u.created_at), '-W', LPAD(WEEK(u.created_at, 3), 2, '0')) AS period,
                COUNT(*) AS count
            FROM ride_drivers rd JOIN users u ON u.id = rd.user_id
            WHERE u.created_at >= DATE_SUB(NOW(), INTERVAL 12 WEEK)
            GROUP BY period_key, period ORDER BY period_key ASC");
        $result['drivers_weekly'] = $s->fetchAll();

        // --- Drivers, monthly (last 12 months) ---
        $s = $pdo->query("SELECT DATE_FORMAT(u.created_at, '%Y-%m') AS period, COUNT(*) AS count
            FROM ride_drivers rd JOIN users u ON u.id = rd.user_id
            WHERE u.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY period ORDER BY period ASC");
        $result['drivers_monthly'] = $s->fetchAll();

        // --- Drivers, yearly ---
        $s = $pdo->query("SELECT YEAR(u.created_at) AS period, COUNT(*) AS count
            FROM ride_drivers rd JOIN users u ON u.id = rd.user_id
            GROUP BY period ORDER BY period ASC");
        $result['drivers_yearly'] = $s->fetchAll();

        // --- Custom date range (📅 picker), daily breakdown, if provided ---
        if ($customRange !== null) {
            $s = $pdo->prepare("SELECT DATE_FORMAT(completed_at, '%Y-%m-%d') AS period, COUNT(*) AS count
                FROM rides
                WHERE status='completed'
                  AND completed_at >= ? AND completed_at < DATE_ADD(?, INTERVAL 1 DAY)
                GROUP BY period ORDER BY period ASC");
            $s->execute(array($customRange['from'], $customRange['to']));
            $result['trips_custom'] = $s->fetchAll();

            $s = $pdo->prepare("SELECT DATE_FORMAT(u.created_at, '%Y-%m-%d') AS period, COUNT(*) AS count
                FROM ride_drivers rd JOIN users u ON u.id = rd.user_id
                WHERE u.created_at >= ? AND u.created_at < DATE_ADD(?, INTERVAL 1 DAY)
                GROUP BY period ORDER BY period ASC");
            $s->execute(array($customRange['from'], $customRange['to']));
            $result['drivers_custom'] = $s->fetchAll();

            $result['custom_range'] = $customRange;
        }

        // --- Running totals, for the stat-card headline numbers ---
        // Note on revenue: the 5% platform commission is NOT currently
        // enforced anywhere in the live booking/payment flow - the
        // commission_owed/cash_fares_total columns exist for this purpose
        // but the actual rate is still unset (accrues at ETB 0 until
        // activated - see ride_drivers commission handling). So this is
        // reported as a clearly-labeled PROJECTION (gross fares x 5%),
        // not as revenue actually collected, to avoid the dashboard
        // implying money that hasn't actually changed hands yet.
        $grossFares = floatval($pdo->query("SELECT COALESCE(SUM(fare_final),0) FROM rides WHERE status='completed'")->fetchColumn());
        $result['totals'] = array(
            'trips_all_time'             => intval($pdo->query("SELECT COUNT(*) FROM rides WHERE status='completed'")->fetchColumn()),
            'drivers_all_time'           => intval($pdo->query("SELECT COUNT(*) FROM ride_drivers")->fetchColumn()),
            'gross_fares_all_time'       => round($grossFares, 2),
            'projected_commission_5pct'  => round($grossFares * 0.05, 2),
        );
    } catch (Exception $e) {
        fail_ex($e, 'Growth stats query failed', 500);
    }

    ok($result);
    break;
}

/* ==========================================================
   RIDE PRICING (per vehicle type) - migration 007
   Lets an admin change base fare / per-km rate / wait rate /
   minimum fares per vehicle type, and the peak-hour surge
   multiplier, without a code deploy. See RideController.php's
   rides_fare_rule()/rides_surge_multiplier() for how these are
   read at fare-calculation time (with hardcoded fallbacks if
   this migration hasn't run yet).
   ========================================================== */
case 'admin_get_ride_fare_rules': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    $rules = array();
    try {
        // Double-quoted string literals here previously broke under a MySQL
        // session with ANSI_QUOTES in sql_mode (seen on this cPanel host),
        // which treats "bajaj" etc. as column identifiers instead of
        // strings and throws "Unknown column 'bajaj'". Single quotes are
        // unambiguous string literals regardless of sql_mode.
        $s = $pdo->query("SELECT vehicle_type, base_fare, per_km_rate, per_km_rate_open, wait_rate_per_min, min_fare, min_fare_short_trip, active, updated_at FROM ride_fare_rules ORDER BY FIELD(vehicle_type,'bajaj','moto','car','minibus'), vehicle_type");
        $rules = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        fail_ex($e, 'Could not load ride fare rules - has migration 006_token_revocation.sql / 007_ride_fare_rules.sql been applied? (api/migrate.php --apply)', 500);
    }
    $surge = 1.3;
    try {
        $sv = $pdo->query("SELECT rule_value FROM pricing_rules WHERE rule_key='ride_surge_multiplier' LIMIT 1")->fetchColumn();
        if ($sv !== false && $sv !== null && $sv !== '') $surge = floatval($sv);
    } catch (Exception $e) { /* table may not exist yet - default stands */ }
    ok(array('rules' => $rules, 'ride_surge_multiplier' => $surge));
    break;
}

case 'admin_update_ride_fare_rule': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    $vt = isset($body['vehicle_type']) ? strtolower(trim($body['vehicle_type'])) : '';
    // Was a fixed whitelist of 4 hardcoded names (bajaj/moto/car/minibus),
    // which blocked adding any new vehicle type - relaxed to a format
    // check (lowercase snake_case, 2-20 chars) matching how vehicle_type
    // is already read elsewhere (RideController.php's rides_fare_rule()
    // works for any string, only bajaj/moto/car/minibus were ever special
    // as fallback defaults, not as an enforced enum).
    if (!preg_match('/^[a-z][a-z0-9_]{1,19}$/', $vt)) {
        fail('vehicle_type must be lowercase letters/numbers/underscore, 2-20 characters (e.g. tricycle, mini_truck)', 400);
    }
    $fields = array('base_fare','per_km_rate','per_km_rate_open','wait_rate_per_min','min_fare','min_fare_short_trip');
    $vals = array();
    foreach ($fields as $f) {
        $amt = isset($body[$f]) ? etel_validate_amount($body[$f], true) : false;
        if ($amt === false) fail("$f must be a valid non-negative amount", 400);
        $vals[$f] = $amt;
    }
    $active = isset($body['active']) ? (intval($body['active']) ? 1 : 0) : 1;
    try {
        $s = $pdo->prepare(
            'INSERT INTO ride_fare_rules (vehicle_type, base_fare, per_km_rate, per_km_rate_open, wait_rate_per_min, min_fare, min_fare_short_trip, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                base_fare=VALUES(base_fare), per_km_rate=VALUES(per_km_rate),
                per_km_rate_open=VALUES(per_km_rate_open), wait_rate_per_min=VALUES(wait_rate_per_min),
                min_fare=VALUES(min_fare), min_fare_short_trip=VALUES(min_fare_short_trip),
                active=VALUES(active)'
        );
        $s->execute(array($vt, $vals['base_fare'], $vals['per_km_rate'], $vals['per_km_rate_open'],
                          $vals['wait_rate_per_min'], $vals['min_fare'], $vals['min_fare_short_trip'], $active));
        ok(array('message' => 'Fare rule updated', 'vehicle_type' => $vt));
    } catch (Exception $e) {
        fail_ex($e, 'Update failed - has migration 007_ride_fare_rules.sql been applied?', 500);
    }
    break;
}

case 'admin_add_vehicle_type': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    $vt = isset($body['vehicle_type']) ? strtolower(trim($body['vehicle_type'])) : '';
    if (!preg_match('/^[a-z][a-z0-9_]{1,19}$/', $vt)) {
        fail('Vehicle type name must be lowercase letters/numbers/underscore, 2-20 characters (e.g. tricycle, mini_truck)', 400);
    }
    $base = isset($body['base_fare']) ? etel_validate_amount($body['base_fare'], true) : false;
    if ($base === false) fail('base_fare must be a valid non-negative amount', 400);
    $perKm = isset($body['per_km_rate']) ? etel_validate_amount($body['per_km_rate'], true) : false;
    if ($perKm === false) fail('per_km_rate must be a valid non-negative amount', 400);
    // Open-trip rate, wait rate, and minimum fares aren't asked for on the
    // "add" mini-form (keeps it to two required numbers) - seeded from the
    // base/per-km the admin just entered so the row is immediately usable,
    // and fully editable afterward via the normal per-row Save button.
    $perKmOpen = isset($body['per_km_rate_open']) ? etel_validate_amount($body['per_km_rate_open'], true) : $perKm;
    if ($perKmOpen === false) $perKmOpen = $perKm;
    $waitRate = isset($body['wait_rate_per_min']) ? etel_validate_amount($body['wait_rate_per_min'], true) : 3.00;
    if ($waitRate === false) $waitRate = 3.00;
    $minFare = isset($body['min_fare']) ? etel_validate_amount($body['min_fare'], true) : $base;
    if ($minFare === false) $minFare = $base;
    $minFareShort = isset($body['min_fare_short_trip']) ? etel_validate_amount($body['min_fare_short_trip'], true) : $base;
    if ($minFareShort === false) $minFareShort = $base;
    try {
        $exists = $pdo->prepare('SELECT id FROM ride_fare_rules WHERE vehicle_type=? LIMIT 1');
        $exists->execute(array($vt));
        if ($exists->fetch()) fail("Vehicle type '$vt' already exists - edit it in the table above instead", 400);
        $s = $pdo->prepare(
            'INSERT INTO ride_fare_rules (vehicle_type, base_fare, per_km_rate, per_km_rate_open, wait_rate_per_min, min_fare, min_fare_short_trip, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $s->execute(array($vt, $base, $perKm, $perKmOpen, $waitRate, $minFare, $minFareShort));
        ok(array('message' => 'Vehicle type added', 'vehicle_type' => $vt));
    } catch (Exception $e) {
        fail_ex($e, 'Could not add vehicle type - has migration 007_ride_fare_rules.sql been applied?', 500);
    }
    break;
}

case 'admin_delete_vehicle_type': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    $vt = isset($body['vehicle_type']) ? strtolower(trim($body['vehicle_type'])) : '';
    if ($vt === '') fail('vehicle_type is required', 400);
    // Soft-delete only (active=0), same convention as admin_delete_zone -
    // drivers already registered under this vehicle type, and past rides
    // priced with it, keep their history; it just stops being offered.
    try {
        $pdo->prepare('UPDATE ride_fare_rules SET active=0 WHERE vehicle_type=?')->execute(array($vt));
        ok(array('message' => 'Vehicle type deactivated'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not deactivate vehicle type - has migration 007_ride_fare_rules.sql been applied?', 500);
    }
    break;
}

case 'admin_update_ride_surge_multiplier': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    $v = isset($body['value']) ? etel_validate_amount($body['value'], false) : false;
    if ($v === false || $v <= 0 || $v > 5) fail('value must be a number between 0 and 5 (e.g. 1.3 for +30%)', 400);
    try {
        $pdo->prepare(
            "INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_surge_multiplier', ?, 'Ride fare multiplier during peak hours (weekday rush + weekend evenings)')
             ON DUPLICATE KEY UPDATE rule_value = VALUES(rule_value)"
        )->execute(array($v));
        ok(array('message' => 'Surge multiplier updated', 'ride_surge_multiplier' => $v));
    } catch (Exception $e) {
        fail_ex($e, 'Update failed - has migration 007_ride_fare_rules.sql been applied?', 500);
    }
    break;
}

// ── DRIVER DAILY-GOAL TIERS ──────────────────────────────────────────
// Previously six values ("2 trips -> +ETB 250" etc.) hardcoded directly
// into driver/index.html - an admin had no way to change the trip
// counts or bonus amounts without editing code. Same self-healing/seed
// pattern as ride_fare_rules: rides_ensure_goal_tiers_table() creates
// the table on first use, seeded with the exact amounts that were
// previously hardcoded, so nothing changes until an admin edits a tier.
case 'admin_get_goal_tiers': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    rides_ensure_goal_tiers_table($pdo);
    try {
        $rows = $pdo->query("SELECT id, trips_required, bonus_etb, active, sort_order FROM driver_goal_tiers ORDER BY sort_order, trips_required")->fetchAll(PDO::FETCH_ASSOC);
        ok(array('tiers' => $rows));
    } catch (Exception $e) {
        fail_ex($e, 'Could not load goal tiers', 500);
    }
    break;
}

case 'admin_save_goal_tier': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    rides_ensure_goal_tiers_table($pdo);
    $id = isset($body['id']) ? intval($body['id']) : 0;
    $trips = isset($body['trips_required']) ? intval($body['trips_required']) : 0;
    if ($trips < 1 || $trips > 200) fail('trips_required must be between 1 and 200', 400);
    // bonus_etb is legacy/unused now - the amount shown to a driver is
    // always computed live from THEIR OWN real average fare (see
    // driver_get_goal_tiers in RideController.php), not this column.
    // Kept in the schema in case a fixed-bonus mode is reintroduced
    // later, but the admin UI no longer exposes it, so default to 0.
    $bonus = isset($body['bonus_etb']) ? etel_validate_amount($body['bonus_etb'], true) : 0;
    if ($bonus === false) $bonus = 0;
    $active = isset($body['active']) ? (intval($body['active']) ? 1 : 0) : 1;
    $sortOrder = isset($body['sort_order']) ? intval($body['sort_order']) : $trips;
    try {
        if ($id > 0) {
            $pdo->prepare('UPDATE driver_goal_tiers SET trips_required=?, bonus_etb=?, active=?, sort_order=? WHERE id=?')
                ->execute(array($trips, $bonus, $active, $sortOrder, $id));
        } else {
            $existing = $pdo->prepare('SELECT id FROM driver_goal_tiers WHERE trips_required=?');
            $existing->execute(array($trips));
            if ($existing->fetch()) fail("A tier for $trips trips already exists - edit it instead", 400);
            $pdo->prepare('INSERT INTO driver_goal_tiers (trips_required, bonus_etb, active, sort_order) VALUES (?, ?, ?, ?)')
                ->execute(array($trips, $bonus, $active, $sortOrder));
        }
        ok(array('message' => 'Goal tier saved'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not save goal tier', 500);
    }
    break;
}

case 'admin_delete_goal_tier': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if (!$id) fail('id is required', 400);
    try {
        $pdo->prepare('DELETE FROM driver_goal_tiers WHERE id=?')->execute(array($id));
        ok(array('message' => 'Goal tier deleted'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not delete goal tier', 500);
    }
    break;
}

// ── SYSTEM TEST ───────────────────────────────────────────────────
// Runs a complete, real ride lifecycle end to end - booking, driver
// accept, trip start, simulated GPS movement, a simulated (fast-
// forwarded, not real-time) waiting period, arrival billing, and
// payment confirmation - using two dedicated demo accounts, then
// deletes the test ride so it never shows up in real dashboard stats
// or revenue. Exists so "is the system actually working" can be
// answered in one tap instead of a multi-hour manual chase with real
// phones - this is exactly the kind of pipeline (routing whitelist,
// wait-fee math, fare breakdown) that had silent bugs earlier that
// only real end-to-end testing caught.
case 'admin_run_system_test': {
    $pdo = db();
    require_admin($pdo, 'rides.manage');
    rides_ensure_wait_columns($pdo);
    rides_ensure_goal_tiers_table($pdo);
    $steps = array();
    $pass = function($label, $detail = null) use (&$steps) { $steps[] = array('step' => $label, 'ok' => true, 'detail' => $detail); };
    $fail_step = function($label, $detail) use (&$steps) { $steps[] = array('step' => $label, 'ok' => false, 'detail' => $detail); };

    // 1. Demo accounts - self-healing, created once and reused every run
    $custId = null; $drvId = null;
    try {
        $s = $pdo->prepare('SELECT id FROM users WHERE phone=?');
        $s->execute(array('0900000001'));
        $row = $s->fetch();
        if ($row) { $custId = $row['id']; }
        else {
            $pdo->prepare("INSERT INTO users (name, phone, role, password_hash, created_at) VALUES ('Demo Customer (System Test)', '0900000001', 'customer', ?, NOW())")
                ->execute(array(password_hash('demotest1234', PASSWORD_DEFAULT)));
            $custId = $pdo->lastInsertId();
        }
        $s->execute(array('0900000002'));
        $row = $s->fetch();
        if ($row) { $drvId = $row['id']; }
        else {
            $pdo->prepare("INSERT INTO users (name, phone, role, password_hash, created_at) VALUES ('Demo Driver (System Test)', '0900000002', 'driver', ?, NOW())")
                ->execute(array(password_hash('demotest1234', PASSWORD_DEFAULT)));
            $drvId = $pdo->lastInsertId();
        }
        $pdo->prepare("INSERT INTO ride_drivers (user_id, online, vehicle_type, vehicle_plate, vehicle_make, vehicle_color, last_seen)
                       VALUES (?, 1, 'bajaj', 'DEMO-001', 'Demo Bajaj', 'Gold', NOW())
                       ON DUPLICATE KEY UPDATE online=1, last_seen=NOW()")
            ->execute(array($drvId));
        $pass('Demo accounts ready', "Customer #$custId · Driver #$drvId (phone 0900000001 / 0900000002, password: demotest1234)");
    } catch (Exception $e) {
        $fail_step('Demo accounts ready', $e->getMessage());
        ok(array('steps' => $steps, 'passed' => false));
        break;
    }

    // 2. Book a test ride - open trip, Bajaj, a real ~3km route inside Hawassa
    $rideId = null;
    $plat = 7.0500; $plng = 38.4700; $dlat = 7.0650; $dlng = 38.4850;
    try {
        $pdo->prepare("INSERT INTO rides
                (passenger_id, pickup_lat, pickup_lng, dropoff_lat, dropoff_lng,
                 pickup_address, dropoff_address, distance_km, fare_estimate, fare_final,
                 payment_method, status, open_trip, vehicle_type, expires_at, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?, 'searching', 1, 'bajaj', DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())")
            ->execute(array($custId, $plat, $plng, $dlat, $dlng,
                '[SYSTEM TEST] Pickup', '[SYSTEM TEST] Dropoff', 3.0, 150, 150, 'cash'));
        $rideId = $pdo->lastInsertId();
        $pass('Booking created', "Ride #$rideId · open trip · Bajaj");
    } catch (Exception $e) {
        $fail_step('Booking created', $e->getMessage());
        ok(array('steps' => $steps, 'passed' => false));
        break;
    }

    try {
        $pdo->prepare("UPDATE rides SET status='accepted', driver_id=?, assigned_at=NOW() WHERE id=?")->execute(array($drvId, $rideId));
        $pass('Driver accepted', "Demo Driver #$drvId assigned to ride #$rideId");
    } catch (Exception $e) { $fail_step('Driver accepted', $e->getMessage()); }

    try {
        $pdo->prepare("UPDATE rides SET status='in_progress', started_at=NOW(), traveled_km=0, last_lat=?, last_lng=? WHERE id=?")
            ->execute(array($plat, $plng, $rideId));
        $pass('Trip started', 'GPS meter reset to 0.00 km');
    } catch (Exception $e) { $fail_step('Trip started', $e->getMessage()); }

    try {
        // Simulated GPS movement (a real trip would send many small ticks
        // via driver_update_location - this jumps straight to the end
        // distance since we're not actually driving around Hawassa).
        $pdo->prepare("UPDATE rides SET traveled_km=3.2, last_lat=?, last_lng=? WHERE id=?")->execute(array($dlat, $dlng, $rideId));
        $pass('GPS movement simulated', '3.20 km traveled (simulated, not real driving)');
    } catch (Exception $e) { $fail_step('GPS movement simulated', $e->getMessage()); }

    try {
        // Fast-forwarded wait: stamp wait_started_at 5 minutes in the
        // past, then immediately fold it into wait_seconds_total, the
        // same net effect as a driver waiting 5 real minutes and tapping
        // Stop - without this test actually taking 5 minutes to run.
        $pdo->prepare("UPDATE rides SET wait_active=1, wait_started_at=DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE id=?")->execute(array($rideId));
        $pdo->prepare("UPDATE rides SET wait_active=0, wait_started_at=NULL, wait_seconds_total=300 WHERE id=?")->execute(array($rideId));
        $pass('Waiting fee simulated', '5 minutes (300s) recorded, same as a real Start/Stop Waiting cycle');
    } catch (Exception $e) { $fail_step('Waiting fee simulated', $e->getMessage()); }

    $fareCalc = null; $breakdown = null;
    try {
        // Exactly the same calculation driver_arrived uses for a real ride.
        $fareCalc = rides_calc_fare($pdo, 3.2, 'bajaj', true, 5.0);
        $breakdown = rides_fare_breakdown_for_ui($fareCalc);
        $pdo->prepare("UPDATE rides SET status='arrived', arrived_at=NOW(), fare_final=? WHERE id=?")
            ->execute(array($fareCalc['fare'], $rideId));
        $pass('Arrival + fare calculated',
            'Base ETB ' . $breakdown['fare_base'] . ' + Distance ETB ' . $breakdown['fare_distance'] .
            ' + Wait ETB ' . $breakdown['fare_wait'] .
            ($breakdown['fare_surge_etb'] > 0 ? ' + Surge ETB ' . $breakdown['fare_surge_etb'] : '') .
            ' = ETB ' . $breakdown['fare_total']);
    } catch (Exception $e) { $fail_step('Arrival + fare calculated', $e->getMessage()); }

    try {
        $pdo->prepare("UPDATE rides SET status='completed', completed_at=NOW(), fare_paid=fare_final WHERE id=?")->execute(array($rideId));
        $pass('Payment confirmed', 'Ride marked completed, same as the customer tapping confirm');
    } catch (Exception $e) { $fail_step('Payment confirmed', $e->getMessage()); }

    try {
        // Delete the test ride so it never counts toward real revenue,
        // trip totals, or the admin dashboard - this run should be
        // invisible everywhere except this report.
        $pdo->prepare("DELETE FROM rides WHERE id=?")->execute(array($rideId));
        $pass('Test data cleaned up', "Ride #$rideId removed - won't appear in real stats or revenue");
    } catch (Exception $e) { $fail_step('Test data cleaned up', $e->getMessage()); }

    $allPassed = true;
    foreach ($steps as $st) { if (!$st['ok']) { $allPassed = false; break; } }
    ok(array('steps' => $steps, 'passed' => $allPassed, 'fare_breakdown' => $breakdown));
    break;
}

// ── PEAK-HOUR WINDOWS (when surge applies) ──────────────────────────
// Previously the actual peak-hour schedule (6-9am, 4-8pm daily, Fri/Sat
// 7pm+) was fixed in RideController.php with no way to change it short of
// a code deploy - only the multiplier itself was admin-editable. This
// pair of actions makes the schedule itself editable too, stored as JSON
// under pricing_rules('ride_peak_windows') and read by
// rides_peak_windows()/rides_active_peak_multiplier() in api/lib/ride_fare.php. An
// install that has never saved this falls back to the exact old
// hardcoded schedule, so behavior is unchanged until an admin actually
// changes it.
case 'admin_get_ride_peak_windows': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    $windows = rides_default_peak_windows();
    $isDefault = true;
    try {
        $v = $pdo->query("SELECT rule_value FROM pricing_rules WHERE rule_key='ride_peak_windows' LIMIT 1")->fetchColumn();
        if ($v !== false && $v !== null && $v !== '') {
            $decoded = json_decode($v, true);
            if (is_array($decoded) && count($decoded) > 0) { $windows = $decoded; $isDefault = false; }
        }
    } catch (Exception $e) { /* table may not exist yet - default stands */ }
    ok(array('windows' => $windows, 'is_default' => $isDefault));
    break;
}
case 'admin_update_ride_peak_windows': {
    $pdo = db();
    require_admin($pdo, 'pricing.manage');
    $windows = isset($body['windows']) ? $body['windows'] : null;
    if (!is_array($windows)) fail('windows must be an array', 400);
    if (count($windows) > 20) fail('Too many windows (max 20)', 400);
    $timeRe = '/^([01]\d|2[0-4]):([0-5]\d)$/';
    $defaultMult = rides_surge_multiplier($pdo);
    $clean = array();
    foreach ($windows as $i => $w) {
        if (!is_array($w)) fail("Window #" . ($i + 1) . " is malformed", 400);
        $label = isset($w['label']) ? trim(substr((string)$w['label'], 0, 60)) : ('Window ' . ($i + 1));
        $days = isset($w['days']) && is_array($w['days']) ? array_values(array_unique(array_map('intval', $w['days']))) : array();
        $days = array_values(array_filter($days, function ($d) { return $d >= 1 && $d <= 7; }));
        if (!$days) fail("Window \"$label\" needs at least one day selected", 400);
        $start = isset($w['start']) ? trim($w['start']) : '';
        $end   = isset($w['end'])   ? trim($w['end'])   : '';
        if (!preg_match($timeRe, $start)) fail("Window \"$label\" has an invalid start time (use HH:MM)", 400);
        if (!preg_match($timeRe, $end))   fail("Window \"$label\" has an invalid end time (use HH:MM, 24:00 allowed for midnight)", 400);
        // end <= start is NOT an error - it's a legitimate overnight window
        // that wraps past midnight (e.g. 22:00 to 02:00 for a late-night
        // surge). rides_active_peak_multiplier() in ride_fare.php knows how
        // to match those correctly. Only reject the genuinely ambiguous
        // case where start and end are identical (zero-length or "always on"
        // - either way, not a real window).
        if ($end === $start) fail("Window \"$label\": start and end time can't be the same", 400);
        // Per-window multiplier - each window can now surge by a
        // different amount (e.g. weekend nights at 1.5x, weekday rush at
        // 1.2x) instead of one multiplier applying everywhere. Falls back
        // to the existing global "Peak-hour surge multiplier" field if a
        // window doesn't specify its own.
        $mult = isset($w['multiplier']) && $w['multiplier'] !== '' ? floatval($w['multiplier']) : $defaultMult;
        if ($mult <= 0 || $mult > 5) fail("Window \"$label\": multiplier must be between 0 and 5 (e.g. 1.3 for +30%)", 400);
        $clean[] = array('label' => $label, 'days' => $days, 'start' => $start, 'end' => $end, 'multiplier' => round($mult, 2));
    }
    try {
        $json = json_encode($clean);
        $pdo->prepare(
            "INSERT INTO pricing_rules (rule_key, rule_value, description) VALUES ('ride_peak_windows', ?, 'JSON schedule of when ride peak-hour surge pricing applies, and how much, per window')
             ON DUPLICATE KEY UPDATE rule_value = VALUES(rule_value)"
        )->execute(array($json));
        ok(array('message' => 'Peak-hour schedule updated', 'windows' => $clean));
    } catch (Exception $e) {
        fail_ex($e, 'Update failed - has migration 027_pricing_rules_widen_value.sql been applied?', 500);
    }
    break;
}

/* ==========================================================
   DELIVERY ZONES - migration 007
   Lets an admin add/edit/deactivate the zones customers pick
   for delivery orders (and the flat fee each one charges)
   without a code deploy. See OrderController.php's
   customer_create_order for how the fee is read (falls back
   to the old hardcoded config-etb.php table if a zone isn't
   found here), and boot.php's get_zones for the public list.
   ========================================================== */
case 'admin_get_zones': {
    $pdo = db();
    require_admin($pdo, 'settings.view');
    try {
        $s = $pdo->query('SELECT id, name, base_fare, est_minutes, surge_active, surge_mult, sort_order, active FROM delivery_zones ORDER BY sort_order ASC, name ASC');
        ok(array('zones' => $s->fetchAll(PDO::FETCH_ASSOC)));
    } catch (Exception $e) {
        fail_ex($e, 'Could not load zones - has migration 007_ride_fare_rules.sql been applied?', 500);
    }
    break;
}

case 'admin_update_zone': {
    $pdo = db();
    require_admin($pdo, 'settings.manage');
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if (!$id) fail('id is required', 400);
    $name = isset($body['name']) ? trim($body['name']) : '';
    if (!$name) fail('name is required', 400);
    $fee = isset($body['base_fare']) ? etel_validate_amount($body['base_fare'], true) : false;
    if ($fee === false) fail('base_fare must be a valid non-negative amount', 400);
    $est_minutes = isset($body['est_minutes']) ? max(0, intval($body['est_minutes'])) : 20;
    $sort_order  = isset($body['sort_order']) ? intval($body['sort_order']) : 0;
    $active      = isset($body['active']) ? (intval($body['active']) ? 1 : 0) : 1;
    try {
        $s = $pdo->prepare('UPDATE delivery_zones SET name=?, base_fare=?, est_minutes=?, sort_order=?, active=? WHERE id=?');
        $s->execute(array($name, $fee, $est_minutes, $sort_order, $active, $id));
        if ($s->rowCount() === 0) fail('Zone not found', 404);
        ok(array('message' => 'Zone updated', 'id' => $id));
    } catch (Exception $e) {
        fail_ex($e, 'Update failed', 500);
    }
    break;
}

case 'admin_add_zone': {
    $pdo = db();
    require_admin($pdo, 'settings.manage');
    $name = isset($body['name']) ? trim($body['name']) : '';
    if (!$name) fail('name is required', 400);
    $fee = isset($body['base_fare']) ? etel_validate_amount($body['base_fare'], true) : false;
    if ($fee === false) fail('base_fare must be a valid non-negative amount', 400);
    $est_minutes = isset($body['est_minutes']) ? max(0, intval($body['est_minutes'])) : 20;
    $sort_order  = isset($body['sort_order']) ? intval($body['sort_order']) : 99;
    try {
        $s = $pdo->prepare('INSERT INTO delivery_zones (name, base_fare, est_minutes, sort_order, active) VALUES (?, ?, ?, ?, 1)');
        $s->execute(array($name, $fee, $est_minutes, $sort_order));
        ok(array('message' => 'Zone added', 'id' => intval($pdo->lastInsertId())));
    } catch (Exception $e) {
        fail_ex($e, 'Add failed (zone name may already exist)', 500);
    }
    break;
}

/* ==========================================================
   Delivery tiers (moto / car / express cargo) - mirrors the
   delivery_zones admin pattern above. OrderController.php
   reads this table first (falls back to the old hardcoded
   $DELIVERY_TIERS array in config-etb.php if a tier isn't
   found here), and customer.js's get_delivery_tiers for the
   public tier picker.
   ========================================================== */
case 'admin_get_delivery_tiers': {
    $pdo = db();
    require_admin($pdo, 'settings.view');
    try {
        $s = $pdo->query('SELECT id, tier_key, label, icon, multiplier, eta_delta_min, sort_order, active FROM delivery_tiers ORDER BY sort_order ASC, label ASC');
        ok(array('tiers' => $s->fetchAll(PDO::FETCH_ASSOC)));
    } catch (Exception $e) {
        fail_ex($e, 'Could not load delivery tiers - has migration 012_delivery_tiers.sql been applied?', 500);
    }
    break;
}

case 'admin_update_delivery_tier': {
    $pdo = db();
    require_admin($pdo, 'settings.manage');
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if (!$id) fail('id is required', 400);
    $label = isset($body['label']) ? trim($body['label']) : '';
    if (!$label) fail('label is required', 400);
    $icon = isset($body['icon']) ? trim($body['icon']) : '';
    $multiplier = isset($body['multiplier']) ? etel_validate_amount($body['multiplier'], true) : false;
    if ($multiplier === false || $multiplier <= 0) fail('multiplier must be a valid positive number', 400);
    $eta_delta_min = isset($body['eta_delta_min']) ? intval($body['eta_delta_min']) : 0;
    $sort_order    = isset($body['sort_order']) ? intval($body['sort_order']) : 0;
    $active        = isset($body['active']) ? (intval($body['active']) ? 1 : 0) : 1;
    try {
        $s = $pdo->prepare('UPDATE delivery_tiers SET label=?, icon=?, multiplier=?, eta_delta_min=?, sort_order=?, active=? WHERE id=?');
        $s->execute(array($label, $icon, $multiplier, $eta_delta_min, $sort_order, $active, $id));
        if ($s->rowCount() === 0) fail('Tier not found', 404);
        ok(array('message' => 'Tier updated', 'id' => $id));
    } catch (Exception $e) {
        fail_ex($e, 'Update failed', 500);
    }
    break;
}

/* ==========================================================
   Bank transfer accounts (Abysinia / CBE) - these used to be
   hardcoded directly in customer.js ("Account No: 1234567890"),
   meaning a wrong/placeholder number could only ever be fixed by
   a code deploy, and a customer choosing bank transfer had no
   guarantee the account shown was real. Same DB-first pattern as
   delivery_zones/delivery_tiers/ride_fare_rules above, except
   there is deliberately NO hardcoded fallback for these - see
   boot.php's get_bank_accounts.
   ========================================================== */
case 'admin_get_bank_accounts': {
    $pdo = db();
    require_admin($pdo, 'finance.view');
    try {
        $s = $pdo->query('SELECT id, bank_key, display_name, account_name, account_number, active FROM bank_accounts ORDER BY display_name ASC');
        ok(array('accounts' => $s->fetchAll(PDO::FETCH_ASSOC)));
    } catch (Exception $e) {
        fail_ex($e, 'Could not load bank accounts - has migration 014_bank_accounts.sql been applied?', 500);
    }
    break;
}

case 'admin_update_bank_account': {
    $pdo = db();
    require_admin($pdo, 'finance.manage');
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if (!$id) fail('id is required', 400);
    $display_name   = isset($body['display_name'])   ? trim($body['display_name'])   : '';
    $account_name   = isset($body['account_name'])   ? trim($body['account_name'])   : '';
    $account_number = isset($body['account_number']) ? trim($body['account_number']) : '';
    $active         = isset($body['active']) ? (intval($body['active']) ? 1 : 0) : 1;
    if (!$display_name) fail('display_name is required', 400);
    if (!$account_number) fail('account_number is required', 400);
    try {
        $s = $pdo->prepare('UPDATE bank_accounts SET display_name=?, account_name=?, account_number=?, active=? WHERE id=?');
        $s->execute(array($display_name, $account_name, $account_number, $active, $id));
        if ($s->rowCount() === 0) fail('Bank account not found (or nothing changed)', 404);
        ok(array('message' => 'Bank account saved', 'id' => $id));
    } catch (Exception $e) {
        fail_ex($e, 'Save failed', 500);
    }
    break;
}

case 'admin_create_announcement': {
    $pdo = db();
    $admin = etel_require_ride_pay($pdo, 'settings.manage', 'rides.manage');
    $title = isset($body['title']) ? trim($body['title']) : '';
    $bodytxt = isset($body['body']) ? trim($body['body']) : '';
    $category = isset($body['category']) ? trim($body['category']) : 'news';
    if (!in_array($category, array('news', 'notification', 'partner'), true)) $category = 'news';
    $audience = isset($body['audience']) ? trim($body['audience']) : 'drivers';
    if (!in_array($audience, array('drivers', 'riders', 'all'), true)) $audience = 'drivers';
    if (!$title || !$bodytxt) fail('title and body are required', 400);
    try {
        rides_add_missing_cols($pdo, 'driver_announcements', array(
            'audience' => "audience VARCHAR(10) NOT NULL DEFAULT 'drivers'"
        ));
        $s = $pdo->prepare('INSERT INTO driver_announcements (category, title, body, created_by, active, audience) VALUES (?, ?, ?, ?, 1, ?)');
        $s->execute(array($category, $title, $bodytxt, $admin['id'], $audience));
        $newAnnouncementId = intval($pdo->lastInsertId());

        /* Push the announcement (best-effort: a push failure never blocks posting). */
        try {
            if ($audience === 'drivers' || $audience === 'all') {
                $driverIds = $pdo->query("SELECT DISTINCT user_id FROM ride_drivers")->fetchAll(PDO::FETCH_COLUMN);
                if ($driverIds) {
                    etel_push_send_multi($pdo, $driverIds, $title, mb_substr($bodytxt, 0, 150),
                        array('type' => 'announcement', 'announcement_id' => $newAnnouncementId, 'url' => '/delivery/driver/'));
                }
            }
            if ($audience === 'riders' || $audience === 'all') {
                $riderIds = $pdo->query("SELECT DISTINCT passenger_id FROM rides")->fetchAll(PDO::FETCH_COLUMN);
                if ($riderIds) {
                    etel_push_send_multi($pdo, $riderIds, $title, mb_substr($bodytxt, 0, 150),
                        array('type' => 'announcement', 'announcement_id' => $newAnnouncementId, 'url' => '/delivery/ride/'));
                }
            }
        } catch (Exception $pushEx) {
            error_log('[ETEL][push] announcement push failed: ' . $pushEx->getMessage());
        }

        ok(array('message' => 'Announcement posted', 'id' => $newAnnouncementId, 'audience' => $audience));
    } catch (Exception $e) {
        fail_ex($e, 'Post failed', 500);
    }
    break;
}

case 'admin_get_announcements': {
    $pdo = db();
    etel_require_ride_pay($pdo, 'settings.view', 'rides.view');
    try {
        $rows = $pdo->query('SELECT id, category, title, body, active, created_at, audience FROM driver_announcements ORDER BY created_at DESC LIMIT 100');
    } catch (Exception $e) {
        $rows = $pdo->query('SELECT id, category, title, body, active, created_at FROM driver_announcements ORDER BY created_at DESC LIMIT 100');
    }
    ok(array('announcements' => $rows->fetchAll(PDO::FETCH_ASSOC)));
    break;
}

case 'admin_delete_announcement': {
    $pdo = db();
    etel_require_ride_pay($pdo, 'settings.manage', 'rides.manage');
    $aid = isset($body['id']) ? intval($body['id']) : 0;
    if (!$aid) fail('id required', 400);
    $pdo->prepare('UPDATE driver_announcements SET active=0 WHERE id=?')->execute(array($aid));
    ok(array('message' => 'Announcement removed'));
    break;
}

case 'admin_get_launch_checklist_state': {
    /* Server-side persistence for the Launch Readiness tab's checkboxes
       so state is consistent across devices (phone, desktop) rather
       than per-browser localStorage. The checklist ITEMS themselves are
       static content defined in js/admin-launch-checklist.js, not in
       the DB - this table only tracks which item_keys are checked. */
    $pdo = db();
    require_admin($pdo, 'settings.view');
    try {
        $rows = $pdo->query(
            "SELECT s.item_key, s.checked, s.checked_at, u.name AS checked_by_name
             FROM launch_checklist_state s
             LEFT JOIN users u ON u.id = s.checked_by"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $rows = array(); // table not migrated yet - treat as nothing checked
    }
    $state = array();
    foreach ($rows as $r) {
        $state[$r['item_key']] = array(
            'checked'         => (bool) intval($r['checked']),
            'checked_at'      => $r['checked_at'],
            'checked_by_name' => $r['checked_by_name']
        );
    }
    ok(array('state' => $state));
    break;
}

case 'admin_toggle_launch_checklist_item': {
    $pdo = db();
    $admin = require_admin($pdo, 'settings.manage');
    $key = isset($body['item_key']) ? trim(substr($body['item_key'], 0, 100)) : '';
    $checked = !empty($body['checked']) ? 1 : 0;
    if ($key === '') fail('item_key required', 400);
    try {
        $pdo->prepare(
            "INSERT INTO launch_checklist_state (item_key, checked, checked_at, checked_by)
             VALUES (?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE checked=VALUES(checked), checked_at=VALUES(checked_at), checked_by=VALUES(checked_by)"
        )->execute(array($key, $checked, $admin['id']));
        ok(array('saved' => true));
    } catch (Exception $e) {
        fail_ex($e, 'Could not save checklist state', 500);
    }
    break;
}

case 'admin_get_ride_issues': {
    $pdo = db();
    require_admin($pdo, 'rides.view');
    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    if ($status) {
        $q = $pdo->prepare(
            'SELECT ri.*, u.name AS reporter_name, u.phone AS reporter_phone
             FROM ride_issues ri LEFT JOIN users u ON u.id = ri.reporter_id
             WHERE ri.status = ? ORDER BY ri.created_at DESC LIMIT 200'
        );
        $q->execute(array($status));
    } else {
        $q = $pdo->query(
            'SELECT ri.*, u.name AS reporter_name, u.phone AS reporter_phone
             FROM ride_issues ri LEFT JOIN users u ON u.id = ri.reporter_id
             ORDER BY ri.created_at DESC LIMIT 200'
        );
    }
    ok(array('issues' => $q->fetchAll(PDO::FETCH_ASSOC)));
    break;
}

case 'admin_resolve_ride_issue': {
    $pdo = db();
    require_admin($pdo, 'rides.manage');
    $iid = isset($body['id']) ? intval($body['id']) : 0;
    if (!$iid) fail('id required', 400);
    $pdo->prepare("UPDATE ride_issues SET status='resolved', resolved_at=NOW() WHERE id=?")->execute(array($iid));
    ok(array('message' => 'Marked resolved'));
    break;
}

case 'admin_get_order_issues': {
    // Order's counterpart to admin_get_ride_issues above - same shape,
    // same orders.view permission every other order-admin action here uses.
    $pdo = db();
    require_admin($pdo, 'orders.view');
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS order_issues (
            id INT(11) NOT NULL AUTO_INCREMENT,
            order_id INT(11) DEFAULT NULL,
            reporter_id INT(11) NOT NULL,
            category VARCHAR(30) NOT NULL,
            details VARCHAR(2000) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_order (order_id),
            KEY idx_reporter (reporter_id),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    if ($status) {
        $q = $pdo->prepare(
            'SELECT oi.*, u.name AS reporter_name, u.phone AS reporter_phone,
                    o.order_code
             FROM order_issues oi
             LEFT JOIN users u ON u.id = oi.reporter_id
             LEFT JOIN orders o ON o.id = oi.order_id
             WHERE oi.status = ? ORDER BY oi.created_at DESC LIMIT 200'
        );
        $q->execute(array($status));
    } else {
        $q = $pdo->query(
            'SELECT oi.*, u.name AS reporter_name, u.phone AS reporter_phone,
                    o.order_code
             FROM order_issues oi
             LEFT JOIN users u ON u.id = oi.reporter_id
             LEFT JOIN orders o ON o.id = oi.order_id
             ORDER BY oi.created_at DESC LIMIT 200'
        );
    }
    ok(array('issues' => $q->fetchAll(PDO::FETCH_ASSOC)));
    break;
}

case 'admin_resolve_order_issue': {
    $pdo = db();
    require_admin($pdo, 'orders.manage');
    $iid = isset($body['id']) ? intval($body['id']) : 0;
    if (!$iid) fail('id required', 400);
    $pdo->prepare("UPDATE order_issues SET status='resolved', resolved_at=NOW() WHERE id=?")->execute(array($iid));
    ok(array('message' => 'Marked resolved'));
    break;
}

case 'admin_get_sos_alerts': {
    $pdo = db();
    require_admin($pdo, 'sos.view');
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS sos_alerts (
            id INT(11) NOT NULL AUTO_INCREMENT,
            ride_id INT(11) DEFAULT NULL,
            reporter_id INT(11) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'rider',
            lat DECIMAL(10,7) DEFAULT NULL,
            lng DECIMAL(10,7) DEFAULT NULL,
            message VARCHAR(500) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME DEFAULT NULL,
            resolved_by INT(11) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_ride (ride_id),
            KEY idx_reporter (reporter_id),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $status = isset($_GET['status']) ? trim($_GET['status']) : 'open';
    if ($status && $status !== 'all') {
        $q = $pdo->prepare(
            'SELECT sa.*, u.name AS reporter_name, u.phone AS reporter_phone
             FROM sos_alerts sa LEFT JOIN users u ON u.id = sa.reporter_id
             WHERE sa.status = ? ORDER BY sa.created_at DESC LIMIT 200'
        );
        $q->execute(array($status));
    } else {
        $q = $pdo->query(
            'SELECT sa.*, u.name AS reporter_name, u.phone AS reporter_phone
             FROM sos_alerts sa LEFT JOIN users u ON u.id = sa.reporter_id
             ORDER BY sa.created_at DESC LIMIT 200'
        );
    }
    ok(array('alerts' => $q->fetchAll(PDO::FETCH_ASSOC)));
    break;
}

case 'admin_resolve_sos': {
    $pdo = db();
    $admin = require_admin($pdo, 'sos.manage');
    $aid = isset($body['id']) ? intval($body['id']) : 0;
    if (!$aid) fail('id required', 400);
    $pdo->prepare("UPDATE sos_alerts SET status='resolved', resolved_at=NOW(), resolved_by=? WHERE id=?")
        ->execute(array($admin['id'], $aid));
    ok(array('message' => 'SOS alert resolved'));
    break;
}

case 'admin_delete_zone': {
    $pdo = db();
    require_admin($pdo, 'settings.manage');
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if (!$id) fail('id is required', 400);
    try {
        // Soft delete (deactivate) rather than a hard DELETE, consistent
        // with how service givers are suspended rather than removed -
        // existing orders/history referencing this zone name stay intact.
        $s = $pdo->prepare('UPDATE delivery_zones SET active=0 WHERE id=?');
        $s->execute(array($id));
        if ($s->rowCount() === 0) fail('Zone not found', 404);
        ok(array('message' => 'Zone deactivated', 'id' => $id));
    } catch (Exception $e) {
        fail_ex($e, 'Delete failed', 500);
    }
    break;
}

case 'admin_list_role_permissions': {
    $pdo = db();
    require_admin($pdo, 'owner.only');
    try {
        $s = $pdo->query('SELECT id, role_name, capabilities, updated_at FROM role_permissions ORDER BY role_name ASC');
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) $r['capabilities'] = json_decode($r['capabilities'], true) ?: array();
        ok(array('roles' => $rows, 'all_capabilities' => array(
            'orders.view', 'orders.manage', 'rides.view', 'rides.manage',
            'users.view', 'users.manage', 'service_givers.view', 'service_givers.manage',
            'items.view', 'items.manage', 'settings.view', 'settings.manage',
            'finance.view', 'finance.manage', 'sos.view', 'sos.manage',
            'analytics.view', 'owner.only',
        )));
    } catch (Exception $e) {
        fail_ex($e, 'Could not load roles - has the permissions migration run yet?', 500);
    }
    break;
}

case 'admin_update_role_permissions': {
    $pdo = db();
    require_admin($pdo, 'owner.only');
    $role_name = isset($body['role_name']) ? trim($body['role_name']) : '';
    $caps = isset($body['capabilities']) && is_array($body['capabilities']) ? $body['capabilities'] : array();
    if ($role_name === '') fail('role_name is required', 400);
    $caps = array_values(array_unique(array_map('strval', $caps)));
    try {
        $pdo->prepare(
            'INSERT INTO role_permissions (role_name, capabilities) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE capabilities = VALUES(capabilities)'
        )->execute(array($role_name, json_encode($caps)));
        ok(array('message' => "Permissions saved for \"$role_name\""));
    } catch (Exception $e) {
        fail_ex($e, 'Could not save - has the permissions migration run yet?', 500);
    }
    break;
}

case 'admin_delete_role_permissions': {
    $pdo = db();
    require_admin($pdo, 'owner.only');
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if (!$id) fail('id is required', 400);
    $pdo->prepare('DELETE FROM role_permissions WHERE id = ?')->execute(array($id));
    ok(array('message' => 'Role deleted'));
    break;
}

case 'admin_get_my_capabilities': {
    $pdo = db();
    $u = require_admin($pdo);
    ok(array('capabilities' => user_capabilities($pdo, $u), 'staff_role' => $u['staff_role'] ?? null));
    break;
}
}

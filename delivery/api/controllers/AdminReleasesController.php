<?php
// ============================================================
// ETEL - Admin: app release channels and canary testers (split out of AdminController.php to keep that file small).
// Loaded just before the main switch, in the same scope, so $pdo, $u, $body and $action are the ones it set up.
// An action that is not listed here falls through to the main switch, which answers "Unknown action" as before.
// ============================================================
if (!defined('APP_VER')) { http_response_code(403); exit; } // direct access guard

switch ($action) {


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

}

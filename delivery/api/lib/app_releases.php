<?php
// ============================================================
// ETEL Delivery/Ride - canary release system  v1.0
// Deploy as: api/lib/app_releases.php
//
// Lets an admin ship a new frontend bundle to a small set of specific
// test users first (canary), confirm it's clean, then promote it to
// everyone - and even after promotion, users already mid-session are
// only offered an update banner rather than being silently switched
// under them. Works within the existing no-SSH, cPanel-Upload-tab
// deploy flow: a new version means uploading to a NEW versioned
// folder (js/releases/{app}/{version}/...), never overwriting a path
// in place. The live bundle path referenced by each page's <script
// src> becomes a tiny, effectively-permanent "loader stub" (see
// js/release-loader.js) that fetches the version pointer below and
// injects the real, versioned bundle - so that script tag itself
// never needs to change again after the one-time migration.
// ============================================================

if (!function_exists('app_releases_ensure_schema')) {
function app_releases_ensure_schema($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_release_channels (
            app_name VARCHAR(40) PRIMARY KEY,
            stable_version VARCHAR(40) NOT NULL DEFAULT 'v1',
            canary_version VARCHAR(40) DEFAULT NULL,
            previous_stable_version VARCHAR(40) DEFAULT NULL,
            updated_at DATETIME DEFAULT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_canary_testers (
            app_name VARCHAR(40) NOT NULL,
            user_id INT NOT NULL,
            added_at DATETIME DEFAULT NULL,
            PRIMARY KEY (app_name, user_id)
        )");
    } catch (Exception $e) {
        error_log('[ETEL][releases] schema create failed: ' . $e->getMessage());
    }
}
}

/* The six apps sharing this one PHP/MySQL codebase (see build-bundles.js
   header) - kept as a single source of truth so a typo in an admin
   request can't silently create a stray new "app" row. */
if (!function_exists('app_releases_valid_app_names')) {
function app_releases_valid_app_names() {
    return array('driver', 'ride', 'customer', 'courier', 'servicegiver', 'admin');
}
}

if (!function_exists('app_releases_get_channel')) {
function app_releases_get_channel($pdo, $appName) {
    app_releases_ensure_schema($pdo);
    $s = $pdo->prepare("SELECT * FROM app_release_channels WHERE app_name=?");
    $s->execute(array($appName));
    $row = $s->fetch();
    if (!$row) {
        // First time this app's channel is looked up - seed it at v1
        // rather than erroring, so a fresh app doesn't need a manual
        // insert before it can be used.
        $pdo->prepare("INSERT IGNORE INTO app_release_channels (app_name, stable_version, updated_at) VALUES (?, 'v1', NOW())")
            ->execute(array($appName));
        return array('app_name' => $appName, 'stable_version' => 'v1', 'canary_version' => null, 'previous_stable_version' => null);
    }
    return $row;
}
}

if (!function_exists('app_releases_resolve_version_for_user')) {
/* What version THIS user's device should load for THIS app right now.
   Canary testers get the canary version (if one is set) - everyone
   else gets stable, full stop. A user who is a canary tester but no
   canary version is currently set just gets stable like anyone else. */
function app_releases_resolve_version_for_user($pdo, $appName, $userId) {
    $channel = app_releases_get_channel($pdo, $appName);
    $isCanaryTester = false;
    if ($channel['canary_version']) {
        $s = $pdo->prepare("SELECT 1 FROM app_canary_testers WHERE app_name=? AND user_id=?");
        $s->execute(array($appName, $userId));
        $isCanaryTester = (bool) $s->fetchColumn();
    }
    $version = ($isCanaryTester && $channel['canary_version']) ? $channel['canary_version'] : $channel['stable_version'];
    return array(
        'version' => $version,
        'stable_version' => $channel['stable_version'],
        'is_canary_tester' => $isCanaryTester,
        'on_canary_build' => $isCanaryTester && $channel['canary_version'] && $version === $channel['canary_version'],
    );
}
}

/* ════════════════════════════════════════════════════════════════
   ETEL — canary release loader  v1.0
   ════════════════════════════════════════════════════════════════
   This file replaces the direct <script src="js/driver-bundle.js">
   (etc) tag on each app page, and is meant to almost NEVER change
   again after the one-time migration - a new release means uploading
   a new versioned folder and flipping a channel in the admin panel,
   not touching this file or the <script> tag that loads it.

   Each page includes this with a small inline config right before it:
       <script>window.ETEL_APP = 'driver';</script>
       <script src="../js/release-loader.js"></script>

   It resolves which version THIS logged-in user should get for this
   app (canary testers get the canary build if one is set, everyone
   else gets stable - see api/lib/app_releases.php), injects that
   versioned bundle, and falls back to a known-good baseline if
   anything about the version check fails so a network hiccup can
   never leave the page blank.
   ════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';
    var APP = window.ETEL_APP;
    var FALLBACK_VERSION = 'v1'; // must exist at js/releases/{app}/v1/ - the one-time migration baseline
    var LOCAL_KEY = 'etel_loaded_version_' + APP;
    // document.currentScript is only valid synchronously during this
    // script's own initial execution - it would be null (or worse,
    // point at some unrelated later script) by the time an async
    // fetch().then() callback runs, so it must be captured right now.
    var THIS_SCRIPT = document.currentScript;

    if (!APP) {
        console.error('[release-loader] window.ETEL_APP must be set before this script loads');
        return;
    }

    function deliveryRoot() {
        // Same anchoring logic as apiBase() - needed because customer.html
        // lives at the delivery/ root while driver/ride/courier/etc live one
        // level deeper (delivery/driver/, delivery/ride/...), so a
        // hardcoded '../js/releases/...' relative path is wrong on some
        // pages and right on others. Always resolve to an absolute,
        // unambiguous path instead.
        var loc = window.location.pathname;
        var marker = '/delivery/';
        var idx = loc.indexOf(marker);
        var dir = idx >= 0 ? loc.substring(0, idx + marker.length) : loc.substring(0, loc.lastIndexOf('/') + 1);
        return window.location.protocol + '//' + window.location.host + dir;
    }

    function apiBase() {
        return deliveryRoot() + 'api/boot.php';
    }

    function bundleFiles(app) {
        // Only the actual build-bundles.js output files are versioned -
        // these are the ones that change together on every release. The
        // handful of standalone scripts each page also loads (sos-button.js,
        // apk-download-badge.js, driver-rate-rider.js, etc) aren't part of
        // the bundler and keep deploying the old direct-overwrite way; they
        // change far less often and aren't what canary testing is for here.
        var manifests = {
            // driver-rate-rider.js/driver-announcements-v2.js/sos-button.js
            // used to be separate deferred <script> tags, relying on the
            // browser guarantee that a plain synchronous <script src> (the
            // old driver-bundle.js tag) always finishes before any
            // deferred script runs. Now that the bundle loads
            // asynchronously (fetch the version, then inject), that
            // ordering guarantee is gone - these three reference DRV/
            // drvApi at file scope in places, not just inside later
            // function calls, so they're folded into this same
            // sequential chain rather than left as independent tags that
            // could race ahead of the bundle they depend on.
            driver: ['driver-bundle.js', 'driver-bundle-extra.js', 'driver-rate-rider.js', 'driver-announcements-v2.js', 'sos-button.js'],
            ride: ['ride-bundle.js', 'ride-bundle-extra.js'],
            customer: ['customer-bundle.js', 'customer-bundle-extra.js'],
            courier: ['courier-bundle.js'],
            // servicegiver.html does NOT actually load servicegiver-bundle.js
            // at all - checked the real page and it loads these three
            // source files separately, unbundled (the bundle exists as a
            // build-bundles.js output but is unused/unwired by this page).
            // jsQR.js is a vendored third-party QR library, independent of
            // ETEL's own code and unlikely to change with releases, so it
            // stays a separate unversioned tag like apk-download-badge.js.
            servicegiver: ['core.js', 'servicegiver.js', 'servicegiver-premium.js'],
            admin: ['admin.js'] // no bundle step for admin - see build-bundles.js's source list
        };
        return manifests[app] || [app + '-bundle.js'];
    }

    function injectSequentially(files, idx, version, appNote) {
        if (idx >= files.length) {
            // All files for this app finished loading and executing, in
            // order. Pages that used to rely on "the bundle's <script>
            // tag is synchronous, so anything below it in the HTML runs
            // after" (e.g. the loadGoogleMaps(...,'initMap') call right
            // after the old bundle tag - core.js, which DEFINES
            // loadGoogleMaps, is itself one of the injected files, so
            // calling it before this event would throw
            // "loadGoogleMaps is not defined") must switch to listening
            // for this event instead of calling anything bundle-defined
            // immediately inline.
            try { window.dispatchEvent(new CustomEvent('etel:bundle-ready', { detail: { app: APP, version: version } })); } catch (e) {}
            return;
        }
        var s = document.createElement('script');
        // Dynamically created <script> elements default to async=true,
        // which means by spec they do NOT block the window 'load' event
        // unless told otherwise. core.js (concatenated into every one of
        // these bundles) has its own window.addEventListener('load', ...)
        // driving silentPatchCheck/pwaInit/PWAInstall.init/DarkMode -
        // without this, 'load' could fire (and permanently skip that
        // whole block, same failure mode as the DOMContentLoaded bug
        // already found in driver.js/servicegiver.js) before this script
        // even starts executing, especially on a slow connection or a
        // multi-file manifest. async=false is the standard, spec-defined
        // way to make a dynamically inserted script both execute in
        // guaranteed order AND count toward what 'load' waits for.
        s.async = false;
        s.src = deliveryRoot() + 'js/releases/' + APP + '/' + version + '/' + files[idx];
        s.onload = function () { injectSequentially(files, idx + 1, version, appNote); };
        s.onerror = function () {
            console.error('[release-loader] failed to load ' + s.src + (appNote ? ' (' + appNote + ')' : ''));
            if (version !== FALLBACK_VERSION) {
                console.warn('[release-loader] falling back to ' + FALLBACK_VERSION);
                inject(FALLBACK_VERSION, 'fallback after load failure');
            }
        };
        THIS_SCRIPT.parentNode.insertBefore(s, THIS_SCRIPT.nextSibling);
    }

    function inject(version, appNote) {
        window.__ETEL_LOADED_VERSION = version;
        try { localStorage.setItem(LOCAL_KEY, version); } catch (e) { /* private browsing, ignore */ }
        injectSequentially(bundleFiles(APP), 0, version, appNote);
    }

    // Auth token isn't available this early on every page (e.g. before
    // login), so an unauthenticated/failed version check just means
    // "load the known baseline" - never a broken page.
    var token = null;
    try { token = (typeof Auth !== 'undefined' && Auth.getToken) ? Auth.getToken() : localStorage.getItem('etel_token'); } catch (e) {}
    if (!token) { inject(FALLBACK_VERSION, 'no auth token yet'); return; }

    var currentVersion = null;
    try { currentVersion = localStorage.getItem(LOCAL_KEY); } catch (e) {}

    var url = apiBase() + '?action=get_patch_info&app=' + encodeURIComponent(APP) +
        '&current_version=' + encodeURIComponent(currentVersion || '') + '&t=' + Date.now();

    fetch(url, { headers: { 'Authorization': 'Bearer ' + token } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.success || !d.data || !d.data.version) { inject(FALLBACK_VERSION, 'bad response shape'); return; }
            inject(d.data.version);
            window.__ETEL_ON_CANARY_BUILD = !!d.data.on_canary_build;
        })
        .catch(function (err) {
            console.warn('[release-loader] version check failed, using last-known or fallback', err);
            inject(currentVersion || FALLBACK_VERSION, 'version check failed');
        });
})();

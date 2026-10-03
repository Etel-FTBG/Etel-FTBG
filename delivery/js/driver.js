/* ════════════════════════════════════════════════════════════════
   ETEL DRIVER — Dashboard JS
   ════════════════════════════════════════════════════════════════ */
'use strict';

var DRV = {
    map: null,
    currentPage: 'requests',
    currentReqTab: 'requests',
    goalTier: { trips: 2, bonus: 250 },
    arrivedPoll: null,
    sseSource: null,
    selfMarker: null,
    rideMarkers: [],
    directionsService: null,
    directionsRenderer: null,
    isOnline: false,
    currentLat: null,
    currentLng: null,
    watchId: null,
    locationInterval: null,
    pollInterval: null,
    activeRide: null,
    driverProfile: null,
};

/* ── Map sizing ──
   The sheet's vertical offset comes from a single static spacer div in the
   markup (#page-requests, height:50vh) that sits behind the fixed map --
   that's the only source of truth for it now (a duplicate margin-top:50vh
   that used to also live on .req-sheet was removed; it was stacking with
   this spacer and pushing content ~100vh down, which is why the sheet used
   to be invisible until an unrelated scroll happened to reveal it).
   The px-based map-height override below exists ONLY for iOS Safari, where
   vh inside a position:fixed element is genuinely buggy. It must NOT also
   touch the sheet's margin -- the static spacer already handles that. */
var _IS_IOS_SAFARI = /iP(hone|od|ad)/.test(navigator.userAgent) &&
    /WebKit/.test(navigator.userAgent) && !/CriOS|FxiOS/.test(navigator.userAgent);

function _syncDriverMapSizing() {
    if (!_IS_IOS_SAFARI) return;
    var _dm = document.getElementById('driver-map');
    if (!_dm) return;
    var mapH = Math.round(window.innerHeight * 0.52);
    _dm.style.height = Math.max(220, mapH) + 'px';
    try { if (DRV.map) google.maps.event.trigger(DRV.map, 'resize'); } catch (e) {}
}

/* ── Init Maps ── */
function initDriverMap() {
    var _dm = document.getElementById('driver-map');
    if (_IS_IOS_SAFARI) {
        /* Force explicit sizing for iOS Safari — vh units inside fixed
           containers are unreliable in Safari specifically. */
        var mapH = Math.round(window.innerHeight * 0.52);
        _dm.style.cssText = 'position:fixed;top:0;left:0;right:0;' +
            'height:' + Math.max(220, mapH) + 'px;' +
            'width:100%;z-index:1;background:#10101c;';
        window.addEventListener('resize', _syncDriverMapSizing);
        setTimeout(_syncDriverMapSizing, 400);
        setTimeout(_syncDriverMapSizing, 1200);
    }
    /* Everywhere else (including this Windows desktop app): leave the
       declarative CSS (52vh) alone -- it already works. */
    DRV.map = new google.maps.Map(_dm, {
        center: { lat: 7.0621, lng: 38.4761 },
        zoom: 14,
        /* No mapId: a Cloud mapId makes Google ignore `styles`. Markers here are
           classic google.maps.Marker and do not need one. Style follows the
           app's light/dark mode (see toggleDriverTheme). */
        styles: window.EtelUI ? EtelUI.mapStyles(document.body.classList.contains('light-mode')) : undefined,
        backgroundColor: '#10101c',
        mapTypeId: google.maps.MapTypeId.ROADMAP,
        disableDefaultUI: true,
        mapTypeControl: true,
        mapTypeControlOptions: {
            style: google.maps.MapTypeControlStyle.HORIZONTAL_BAR,
            position: google.maps.ControlPosition.TOP_RIGHT,
            mapTypeIds: [google.maps.MapTypeId.ROADMAP, google.maps.MapTypeId.HYBRID]
        },
        zoomControl: true
    });
    DRV.directionsService  = new google.maps.DirectionsService();
    DRV.directionsRenderer = new google.maps.DirectionsRenderer({ suppressMarkers: true, polylineOptions: { strokeColor: '#E5C76B', strokeWeight: 5, strokeOpacity: .95 } });
    DRV.directionsRenderer.setMap(DRV.map);

    // Load profile
    loadDriverProfile();
}


/* ── Light / Dark mode toggle — for outdoor use ──
   One source of truth: localStorage 'etel_driver_theme' ('dark' default).
   Light mode drops body.dark-page (its !important dark surfaces) and adds
   body.light-mode (the light tokens); dark mode is the reverse. The button
   always shows the mode you would SWITCH TO (sun while dark, moon while
   light), same as the other Etel apps. core.js' floating toggle is turned
   off on this page (ETEL_NO_THEME_FAB) so there is never a second, fighting
   switch. */
function applyDriverTheme(isLight) {
    var b = document.body;
    b.classList.toggle('light-mode', isLight);
    b.classList.toggle('dark-page', !isLight);
    b.classList.remove('dark-mode');
    b.style.background = isLight ? '' : '#08080f';
    var root = document.documentElement;
    root.style.background = isLight ? '#F0EDE6' : '';
    root.style.colorScheme = isLight ? 'light' : 'dark';
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', isLight ? '#F0EDE6' : '#08080f');
    var btn = document.getElementById('theme-toggle-btn');
    if (btn) {
        btn.innerHTML = window.EtelUI ? EtelUI.icon(isLight ? 'moon' : 'sun', 20) : (isLight ? '\u263E' : '\u2600');
        btn.title = isLight ? 'Switch to dark mode' : 'Switch to light mode';
        btn.setAttribute('aria-label', btn.title);
    }
}
function toggleDriverTheme() {
    var isLight = !document.body.classList.contains('light-mode');
    applyDriverTheme(isLight);
    try { localStorage.setItem('etel_driver_theme', isLight ? 'light' : 'dark'); } catch(e) {}
    /* Tell Google Maps to repaint after theme change */
    setTimeout(function() {
        try {
            if (DRV.map) {
                if (window.EtelUI) DRV.map.setOptions({ styles: EtelUI.mapStyles(isLight) });
                google.maps.event.trigger(DRV.map, 'resize');
            }
        } catch(e) {}
    }, 100);
}

/* Apply saved theme on load */
(function() {
    var saved = null;
    try { saved = localStorage.getItem('etel_driver_theme'); } catch(e) {}
    applyDriverTheme(saved === 'light');
    window.addEventListener('storage', function(ev) {
        if (ev.key === 'etel_driver_theme') applyDriverTheme(ev.newValue === 'light');
    });
})();

/* ── Screen Wake Lock ──
   Keeps the screen from sleeping while the driver is online/on a trip -
   this is the one part of "phone screen sleeps and tracking stops" that
   can actually be PREVENTED rather than just recovered from afterward.
   Doesn't help if the driver manually switches to another app (no web
   API can prevent that - the OS backgrounding/eviction recovery above
   handles that case instead), and isn't supported on every browser, so
   this fails silently and tracking still self-heals via the resume
   logic either way. */
DRV.wakeLock = null;
async function _acquireWakeLock() {
    if (!('wakeLock' in navigator)) return;
    try {
        DRV.wakeLock = await navigator.wakeLock.request('screen');
        DRV.wakeLock.addEventListener('release', function () { DRV.wakeLock = null; });
    } catch (e) { /* best-effort - e.g. denied, low battery mode, unsupported */ }
}
function _releaseWakeLock() {
    if (DRV.wakeLock) { try { DRV.wakeLock.release(); } catch (e) {} DRV.wakeLock = null; }
}

/* ── GPS ── */
function startGps() {
    if (!navigator.geolocation) { showDriverToast('Geolocation not supported', 'err'); return; }
    _acquireWakeLock();
    /* Restore the last known position immediately on (re)start, in case
       the page reloaded mid-ride (e.g. browser killed the tab during a
       long dead zone) - this is just a local display fallback; the
       server's own last_lat/last_lng (used to compute traveled_km) is
       untouched by any of this and remains the source of truth. */
    try {
        var saved = JSON.parse(localStorage.getItem('etel_driver_last_pos') || 'null');
        if (saved && saved.lat) { DRV.currentLat = saved.lat; DRV.currentLng = saved.lng; }
    } catch (e) {}

    DRV._lastFixAt = Date.now(); // seeded now, not left undefined, so the staleness watchdog below has a real baseline from the moment tracking starts
    DRV.watchId = navigator.geolocation.watchPosition(function (pos) {
        DRV.currentLat = pos.coords.latitude;
        DRV.currentLng = pos.coords.longitude;
        // Was never captured here despite being read and sent on every
        // ping in broadcastLocation() - accuracy was always undefined,
        // JSON.stringify silently drops undefined fields, so the server
        // never actually received it and its low-accuracy noise
        // protection (skip fixes reported as >30m accuracy) could never
        // engage no matter how poor the GPS signal genuinely was.
        DRV.currentAccuracy = pos.coords.accuracy;
        DRV._lastFixAt = Date.now();
        DRV._geoError = null;
        _updateGpsErrorBadge(null);
        _updateGpsQualityBadge();
        updateSelfMarker();
        /* watchPosition keeps firing regardless of network state, so this
           keeps accumulating the driver's real path even while offline;
           persisted so a reload doesn't lose "where was I last" between
           the moment connectivity drops and the moment it returns. */
        try { localStorage.setItem('etel_driver_last_pos', JSON.stringify({ lat: DRV.currentLat, lng: DRV.currentLng, t: Date.now() })); } catch (e) {}
        _updateGpsStatusBadge();
    }, function (err) {
        // This callback was `null` - any geolocation failure (permission
        // revoked mid-session, GPS/location services turned off, the OS
        // killing location access in the background, a provider timeout)
        // failed completely silently: no retry, no badge, nothing. The
        // meter looked "stuck" with zero indication of why, because
        // nothing was ever actually wrong with the meter's own math -
        // watchPosition had simply stopped delivering positions at all,
        // so DRV.currentLat never moved and there was nothing new for
        // broadcastLocation to even send.
        DRV._geoError = err;
        _updateGpsErrorBadge(err);
        _updateGpsQualityBadge();
        // PERMISSION_DENIED (code 1) can't be recovered by retrying - only
        // the driver re-granting the permission fixes it, so don't loop
        // pointlessly. POSITION_UNAVAILABLE (2) and TIMEOUT (3) are
        // usually transient (momentary GPS dropout, cold start under
        // cover) - one delayed retry via getCurrentPosition often
        // recovers a fix without waiting for watchPosition's own next
        // internal attempt, which can be much slower to retry on some
        // Android WebViews.
        if (err && err.code !== 1) {
            setTimeout(function () {
                if (!DRV.isOnline) return;
                navigator.geolocation.getCurrentPosition(function (pos) {
                    DRV.currentLat = pos.coords.latitude;
                    DRV.currentLng = pos.coords.longitude;
                    DRV.currentAccuracy = pos.coords.accuracy;
                    DRV._lastFixAt = Date.now();
                    DRV._geoError = null;
                    _updateGpsErrorBadge(null);
                    _updateGpsQualityBadge();
                    updateSelfMarker();
                }, function () { /* still failing - the staleness watchdog below will keep the badge visible */ }, { enableHighAccuracy: true, maximumAge: 0, timeout: 15000 });
            }, 4000);
        }
    }, { enableHighAccuracy: true, maximumAge: 5000 });

    DRV.locationInterval = setInterval(broadcastLocation, 5000);
    // Staleness watchdog: watchPosition can also just go quiet without
    // ever firing its error callback on some devices/browsers (observed
    // behavior, not just a theoretical gap) - if no fix has landed in
    // 5x the normal ping interval while online, something is wrong even
    // though no explicit error was ever reported, so say so instead of
    // broadcastLocation silently re-sending the same aging coordinates
    // forever.
    DRV.staleWatchInterval = setInterval(function () {
        if (!DRV.isOnline) return;
        if (DRV._geoError) return; // already showing the more specific error badge
        var age = Date.now() - (DRV._lastFixAt || 0);
        if (age > 25000) {
            _updateGpsErrorBadge({ code: 0, message: 'No GPS update for ' + Math.round(age / 1000) + 's' });
        }
        _updateGpsQualityBadge();
    }, 5000);

    /* The instant connectivity returns, sync right away instead of
       waiting for the next scheduled 5s tick - broadcastLocation sends
       whatever DRV.currentLat/lng is *right now*, and since watchPosition
       never stopped tracking while offline, this single call captures
       the driver's current real position and the server computes the
       full distance delta from wherever it last successfully heard from
       us, so no distance from the offline stretch is lost. */
    if (!DRV._onlineListenerAttached) {
        DRV._onlineListenerAttached = true;
        window.addEventListener('online', function () {
            _updateGpsStatusBadge();
            broadcastLocation();
        });
        window.addEventListener('offline', _updateGpsStatusBadge);
    }
}

function stopGps() {
    if (DRV.watchId !== null) { navigator.geolocation.clearWatch(DRV.watchId); DRV.watchId = null; }
    clearInterval(DRV.locationInterval);
    clearInterval(DRV.staleWatchInterval);
    _updateGpsErrorBadge(null);
    _updateGpsQualityBadge();
    _releaseWakeLock();
}

/* Distinct from _updateGpsStatusBadge (network connectivity) and
   _updateLowAccuracyBadge (a fix landed, but its accuracy is too poor
   to trust) - this one is for "no fix is landing at all", which used to
   fail completely silently (see startGps()'s watchPosition error
   callback, previously `null`). err.code follows the standard
   GeolocationPositionError values (1=PERMISSION_DENIED,
   2=POSITION_UNAVAILABLE, 3=TIMEOUT); code 0 is this file's own
   synthetic "watchdog noticed positions stopped arriving, with no
   explicit error ever reported" case. */
function _updateGpsErrorBadge(err) {
    var box = document.getElementById('gps-error-badge');
    if (!box) {
        var meterBox = document.getElementById('meter-km');
        var anchor = (meterBox && meterBox.parentElement) || document.getElementById('active-ride-container');
        if (!anchor) return;
        box = document.createElement('div');
        box.id = 'gps-error-badge';
        box.style.cssText = 'display:none;margin-top:6px;padding:8px 10px;border-radius:8px;background:rgba(211,47,47,.14);border:1px solid rgba(211,47,47,.4);color:#FF6B6B;font-size:11.5px;font-weight:700;text-align:center;cursor:pointer';
        anchor.appendChild(box);
    }
    if (!err) { box.style.display = 'none'; return; }
    box.style.display = 'block';
    var short, detail;
    if (err.code === 1) {
        short = '⚠ Location permission needed';
        detail = 'Allow location access for this app in your phone Settings, then reload — the meter cannot run without it.';
    } else if (err.code === 2) {
        short = '⚠ GPS unavailable';
        detail = 'Check that Location is turned on in your phone Settings.';
    } else if (err.code === 3) {
        short = '⚠ GPS signal weak — retrying…';
        detail = 'Still trying to get a GPS fix. This can take a few seconds outdoors or near a window.';
    } else {
        short = '⚠ ' + (err.message || 'GPS not responding');
        detail = 'Try toggling Online off and back on if this continues.';
    }
    box.textContent = short + '  ›';
    box.onclick = function () { showDriverToast(detail, 'err'); };
}

/* Shows a small persistent badge near the meter/active-ride UI so the
   driver knows tracking is still running locally even without a signal,
   rather than wondering why the numbers look frozen. Driven by both the
   browser's online/offline events and consecutive broadcastLocation
   failures (some networks report "online" on a captive portal with no
   real connectivity). */
DRV._consecutiveSyncFailures = 0;
function _updateGpsStatusBadge() {
    var badge = document.getElementById('gps-offline-badge');
    if (!badge) return;
    var isOffline = !navigator.onLine || DRV._consecutiveSyncFailures >= 2;
    badge.style.display = isOffline ? 'flex' : 'none';
}

/* Small always-visible GPS quality indicator in the header (Good/Weak/
   Location off), separate from the more detailed error/offline badges
   above - those only render once something's near the meter, so they're
   invisible while just Online waiting for a request. Reuses the exact
   same state (DRV._geoError, DRV._lastFixAt) those badges already
   track; no new GPS watcher or polling added. */
function _updateGpsQualityBadge() {
    var wrap = document.getElementById('drv-gps-quality');
    var dot = document.getElementById('drv-gps-dot');
    var label = document.getElementById('drv-gps-label');
    if (!wrap || !dot || !label) return;
    if (!DRV.isOnline) { wrap.style.display = 'none'; return; }
    var state, text;
    if (DRV._geoError && DRV._geoError.code === 1) {
        state = 'bad'; text = 'Location off';
    } else if (!DRV._lastFixAt) {
        state = 'weak'; text = 'GPS…';
    } else if (DRV._geoError || (Date.now() - DRV._lastFixAt) > 25000) {
        state = 'weak'; text = 'GPS weak';
    } else {
        state = 'good'; text = 'GPS good';
    }
    wrap.style.display = 'inline-flex';
    dot.className = 'drv-gps-dot ' + state;
    label.textContent = text;
}

function broadcastLocation(){if(!DRV.isOnline||!DRV.currentLat)return;if(DRV._locSyncInFlight)return;DRV._locSyncInFlight=true;var _params={lat:DRV.currentLat,lng:DRV.currentLng,accuracy:DRV.currentAccuracy};if(DRV.activeRide&&DRV.activeRide.id)_params.ride_id=DRV.activeRide.id;drvApi("driver_update_location",_params,function(e){DRV._locSyncInFlight=false;DRV._consecutiveSyncFailures=0;_updateGpsStatusBadge();if(e&&e.meter&&DRV.activeRide){var t=document.getElementById("meter-km"),n=document.getElementById("meter-fare");if(t)t.textContent=parseFloat(e.meter.traveled_km||0).toFixed(2)+" km";if(n)n.textContent="ETB "+parseFloat(e.meter.meter_fare||0).toFixed(0);var r=document.getElementById("meter-surge");if(r)r.style.display=e.meter.surge?"inline-flex":"none";_updateLowAccuracyBadge(!!e.meter.low_accuracy,DRV.currentAccuracy);DRV.activeRide.distance_km=e.meter.traveled_km;DRV.activeRide.fare=e.meter.meter_fare;DRV.activeRide.traveled_km=e.meter.traveled_km;DRV.activeRide.meter_fare=e.meter.meter_fare;DRV.activeRide.surge=!!e.meter.surge;['fare_total','fare_base','fare_distance','fare_wait','fare_subtotal','fare_surge_mult','fare_surge_etb','fare_floor_topup'].forEach(function(_k){if(e.meter[_k]!=null)DRV.activeRide[_k]=e.meter[_k]});if(e.meter.wait_active!=null){DRV.activeRide.wait_active=e.meter.wait_active;DRV.activeRide.wait_minutes=e.meter.wait_minutes;DRV.activeRide.wait_etb=e.meter.wait_etb;_syncWaitState(DRV.activeRide)}if(e.meter.wait_auto_stopped){showDriverToast("Waiting stopped automatically \u2014 you\u2019re moving","info")}}},function(){DRV._locSyncInFlight=false;DRV._consecutiveSyncFailures++;_updateGpsStatusBadge()},false,10000)}
// Makes a normally-invisible server behavior visible: the meter/wait-
// auto-stop logic silently skips any GPS fix reported as low-accuracy
// (>30m), on the theory that a single noisy fix can register 100m+ of
// phantom movement. That protection is correct, but with no visibility
// into it, a driver whose device is consistently reporting poor accuracy
// (weak signal, indoors, cheap GPS chipset) sees exactly what looks like
// two unrelated bugs - km never counting up, and the waiting-fee auto-
// stop never triggering - when actually every single ping is being
// silently discarded by the same one condition. This surfaces that
// directly instead of leaving it to guesswork.
function _updateLowAccuracyBadge(isLow,accuracyMeters){
  var box=document.getElementById("gps-accuracy-badge");
  if(!box){
    var meterBox=document.getElementById("meter-km");
    if(!meterBox||!meterBox.parentElement)return;
    box=document.createElement("div");
    box.id="gps-accuracy-badge";
    box.style.cssText="display:none;margin-top:6px;padding:6px 10px;border-radius:8px;background:rgba(255,87,34,.12);border:1px solid rgba(255,87,34,.35);color:#FF7043;font-size:11.5px;font-weight:700;text-align:center;cursor:pointer";
    meterBox.parentElement.appendChild(box);
  }
  if(isLow){
    box.style.display="block";
    // >500m is not "noisy GPS" any more - it's the phone handing the
    // browser a WiFi/cell-tower position with no real satellite fix at
    // all (a plain distance-threshold bump can't fix that, only a real
    // fix can), so say so and name the actual setting to check instead
    // of leaving the driver waiting on a signal that was never coming.
    var severe=accuracyMeters&&accuracyMeters>500;
    box.textContent="\u26A0 GPS signal weak  \u203A";
    box.onclick=function(){
      showDriverToast(severe
        ? "No real GPS fix ("+Math.round(accuracyMeters)+"m, network-only) \u2014 check phone Settings > Location is set to \"High accuracy\"/\"Precise\", then step outside or near a window"
        : "Weak GPS signal ("+(accuracyMeters?Math.round(accuracyMeters)+"m":"?")+") \u2014 distance paused until signal improves; waiting-fee still auto-stops once you're clearly moving",
        "err");
    };
  } else {
    box.style.display="none";
  }
}

/* The drawn carbon/gold badge (the vehicle photos under img/vehicles are not
   shipped with the app, so a photo marker can silently be invisible). */
function _selfMarkerIcon() {
    if (window.EtelUI) return EtelUI.vehicleMarker((DRV.driverProfile && DRV.driverProfile.vehicle_type) || 'bajaj', { size: 48 });
    return { url: _driverVtypeImage(), scaledSize: new google.maps.Size(52, 52), anchor: new google.maps.Point(26, 26) };
}

function updateSelfMarker() {
    if (!DRV.currentLat || !DRV.map) return;
    var pos = { lat: DRV.currentLat, lng: DRV.currentLng };
    if (!DRV.selfMarker) {
        // Was hardcoded to a generic gold-circle-with-emoji icon
        // regardless of vehicle type - refreshSelfMarkerIcon() (which
        // sets the real vehicle-type image) only ever ran afterward, so
        // if this marker got created before that call ever landed (e.g.
        // the very first GPS fix arriving before the profile finished
        // loading), the generic icon just stuck permanently until
        // something else happened to call refreshSelfMarkerIcon() again.
        // Using the real vehicle image from creation removes that gap
        // entirely - the marker is correct from the first frame, not
        // dependent on a follow-up call landing at the right time.
        DRV.selfMarker = new google.maps.Marker({
            position: pos, map: DRV.map,
            icon: _selfMarkerIcon(),
            title: 'You'
        });
        DRV.map.setCenter(pos);
    } else if (window.EtelUI && EtelUI.glideMarker) {
        // Ease between GPS fixes and turn the badge to the heading.
        EtelUI.glideMarker(DRV.selfMarker, pos, { type: (DRV.driverProfile && DRV.driverProfile.vehicle_type) || 'bajaj', size: 48, duration: 1200 });
    } else {
        DRV.selfMarker.setPosition(pos);
    }
}

/* ── Online / Offline Toggle ── */
/* == "You're Online" reminder (persistent notification + app-icon badge) ==
   The point: while the driver is online and off looking at some other app,
   give them a tappable reminder that brings this app straight back to the
   front - the closest a website can get to a native app's persistent
   "you're online" status bar notification. Two layers, both best-effort
   and silently skipped wherever the platform doesn't support them:
     1. A tagged, persistent Notification (works once permission is granted;
        tapping it uses the existing notificationclick handler in sw.js to
        focus/open this app).
     2. The Badging API, which puts a small dot on the installed PWA's home
        screen icon (Android/desktop Chrome/Edge, iOS 16.4+ only if the
        driver has actually added this to their home screen - regular
        Safari tabs cannot show an icon badge at all, that's an iOS
        restriction, not something fixable here). */
/* Real Web Push subscription - this is what lets a new-ride-request push
   actually wake a driver up even when the app is backgrounded or the
   phone is asleep, unlike the in-app ringtone which only works while the
   app is open and in the foreground. Safe to call repeatedly (e.g. every
   time the driver goes online) - does nothing extra if already
   subscribed correctly. */
function urlBase64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - base64String.length % 4) % 4);
    var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    var rawData = window.atob(base64);
    var outputArray = new Uint8Array(rawData.length);
    for (var i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
    return outputArray;
}
function subscribeToPushNotifications() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
    navigator.serviceWorker.ready.then(function (reg) {
        return reg.pushManager.getSubscription().then(function (existing) {
            if (existing) return existing;
            return fetch(ETEL.api.replace('api/boot.php', '') + 'api/boot.php?action=get_vapid_public_key')
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.success || !res.data || !res.data.publicKey) {
                        throw new Error('VAPID key unavailable');
                    }
                    return reg.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: urlBase64ToUint8Array(res.data.publicKey)
                    });
                });
        });
    }).then(function (subscription) {
        if (!subscription) return;
        return drvApi('save_push_subscription', { subscription: subscription.toJSON() }, function () {}, function () {});
    }).catch(function (e) {
        console.error('[push] subscription setup failed', e);
    });
}

function _showOnlineReminder() {
    try { if (navigator.setAppBadge) navigator.setAppBadge(); } catch (e) {}
    if (!('Notification' in window) || !navigator.serviceWorker) return;
    if (Notification.permission !== 'granted') return; // permission is requested earlier, at the actual click - see goOnline()
    navigator.serviceWorker.ready.then(function (reg) {
        reg.showNotification('ETEL Driver \u2014 You\u2019re Online', {
            body: 'Ready for rides. Tap to return to the driver app.',
            icon: '../icons/icon-192.png',
            badge: '../icons/icon-72.png',
            tag: 'etel-online-status',
            renotify: false,
            requireInteraction: true,
            silent: true,
            data: './'
        }).catch(function () {});
    });
}

/* == Battery-optimization tip (Tecno/Infinix/itel/Xiaomi/Oppo/Vivo, etc.) ==
   Many Android OEM skins common in this market (HiOS, XOS, MIUI, ColorOS,
   FuntouchOS) freeze background apps - including the browser - more
   aggressively than stock Android, to save battery. That can silently stop
   the online reminder (and the location heartbeat) from working even after
   notification permission is granted. Nothing in web code can override this;
   the driver has to allow it themselves in their phone's settings. This
   shows a one-time (per device) walkthrough with the right menu names for
   their specific brand where known, so they are not left guessing. */
function _detectBatteryAggressiveOEM() {
    var ua = (navigator.userAgent || '').toLowerCase();
    if (/android/.test(ua) === false) return null; // iOS/desktop: different mechanism, not this issue
    if (/tecno|infinix|itel|camon|spark/.test(ua)) {
        return {
            brand: 'Tecno / Infinix / itel',
            steps: [
                'Settings \u2192 Battery Lab (or Battery Saving Settings) \u2192 disable "App Power Saving Management" for this browser app.',
                'Phone Master \u2192 Toolbox \u2192 Auto-start Management \u2192 allow this browser to run in the background.',
                'Open your recent-apps switcher, find this browser, and tap the lock icon on its card so the system does not close it.',
                'If you see "Power Boost" or "Sleep Mode Optimization", turn those off too.'
            ]
        };
    }
    if (/xiaomi|redmi|miui/.test(ua)) {
        return {
            brand: 'Xiaomi / Redmi',
            steps: [
                'Settings \u2192 Battery & performance \u2192 App battery saver \u2192 find this browser \u2192 set to "No restrictions".',
                'Settings \u2192 Apps \u2192 Manage apps \u2192 this browser \u2192 Autostart \u2192 enable it.',
                'Open recent apps, find this browser, and lock it so it is not cleared automatically.'
            ]
        };
    }
    if (/oppo|coloros|realme/.test(ua)) {
        return {
            brand: 'Oppo / Realme',
            steps: [
                'Settings \u2192 Battery \u2192 App Battery Management \u2192 find this browser \u2192 allow "Allow background running".',
                'Open recent apps, find this browser, and lock it from the app card menu.'
            ]
        };
    }
    if (/vivo|funtouch/.test(ua)) {
        return {
            brand: 'Vivo',
            steps: [
                'Settings \u2192 Battery \u2192 Background power consumption management \u2192 allow this browser.',
                'Open recent apps, find this browser, and lock it from the app card menu.'
            ]
        };
    }
    return null; // stock/near-stock Android - usually fine without extra steps
}

function _maybeShowBatteryTip() {
    try { if (localStorage.getItem('etelBatteryTipSeen') === '1') return; } catch (e) { return; }
    var info = _detectBatteryAggressiveOEM();
    if (!info) return;
    _showBatteryTipModal(info);
}

function _showBatteryTipModal(info) {
    var wrap = document.createElement('div');
    wrap.className = 'modal-overlay';
    wrap.style.cssText = 'position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px';
    var stepsHtml = info.steps.map(function (s, i) {
        return '<li style="margin-bottom:10px;line-height:1.5">' + s + '</li>';
    }).join('');
    wrap.innerHTML =
        '<div class="modal-content" style="max-width:420px;width:100%;padding:26px 24px;border-radius:20px;max-height:80vh;overflow:auto">' +
        '  <div style="font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;opacity:.7;margin-bottom:8px">One more step \u2014 ' + info.brand + '</div>' +
        '  <div style="font-size:15px;line-height:1.6;margin-bottom:16px">Your phone\u2019s battery saver can pause the online reminder in the background. Allow this browser to keep running so you never miss a ride:</div>' +
        '  <ol style="padding-left:20px;font-size:13.5px;margin-bottom:20px">' + stepsHtml + '</ol>' +
        '  <button id="battery-tip-ok" style="width:100%;padding:13px;border:none;border-radius:12px;font-weight:700;font-size:14px;cursor:pointer;background:linear-gradient(135deg,#FFD700,#D4AF37);color:#1a1206">Got it</button>' +
        '</div>';
    document.body.appendChild(wrap);
    document.getElementById('battery-tip-ok').addEventListener('click', function () {
        try { localStorage.setItem('etelBatteryTipSeen', '1'); } catch (e) {}
        wrap.remove();
    });
}

function _clearOnlineReminder() {
    try { if (navigator.clearAppBadge) navigator.clearAppBadge(); } catch (e) {}
    if (!navigator.serviceWorker) return;
    navigator.serviceWorker.ready.then(function (reg) {
        reg.getNotifications({ tag: 'etel-online-status' }).then(function (ns) {
            ns.forEach(function (n) { n.close(); });
        });
    }).catch(function () {});
}

function toggleOnline() {
    if (DRV.isOnline) {
        goOffline();
    } else {
        goOnline();
    }
}

function goOnline() {
    var btn = document.getElementById('online-btn');
    if (btn) btn.disabled = true;
    /* Request notification permission RIGHT HERE, synchronously on the click.
       If this waits until after the async getCurrentPosition() callback below,
       Safari/iOS in particular may treat "user activation" as already expired
       and silently refuse to show the permission prompt at all - which looks
       exactly like "the reminder just doesn't show up" with no error anywhere. */
    if (!('Notification' in window)) {
        showDriverToast('Reminder notifications need this app added to your Home Screen first', 'info');
    } else if (Notification.permission === 'default') {
        Notification.requestPermission().then(function (p) {
            if (p === 'granted') { _showOnlineReminder(); subscribeToPushNotifications(); }
            else showDriverToast('Notifications blocked - enable them in Settings to get the online reminder', 'info');
        });
    } else if (Notification.permission === 'denied') {
        showDriverToast('Notifications are blocked for this app - enable them in Settings to get the online reminder', 'info');
    } else if (Notification.permission === 'granted') {
        subscribeToPushNotifications();
    }
    if (!DRV.currentLat) {
        navigator.geolocation.getCurrentPosition(function (pos) {
            DRV.currentLat = pos.coords.latitude;
            DRV.currentLng = pos.coords.longitude;
            DRV.currentAccuracy = pos.coords.accuracy;
            _doGoOnline(btn);
        }, function () {
            if (btn) btn.disabled = false;
            _resetSlideOnline();
            showDriverToast('Enable location to go online', 'err');
        });
    } else {
        _doGoOnline(btn);
    }
}

function _resetSlideOnline() {
    var thumb = document.getElementById('slide-online-thumb');
    var fill  = document.getElementById('slide-online-fill');
    if (thumb) { thumb.style.transition = 'transform .3s'; thumb.style.transform = 'translateX(0)'; setTimeout(function(){ thumb.style.transition = ''; }, 320); }
    if (fill)  { fill.style.transform = 'scaleX(0)'; }
}

function _doGoOnline(btn) {
    drvApi('driver_go_online', { lat: DRV.currentLat, lng: DRV.currentLng }, function () {
        if (btn) btn.disabled = false;
        DRV.isOnline = true;
        setOnlineUI(true);
        startGps();
        _updateGpsQualityBadge();
        startPolling();
        showDriverToast('You are now online', 'ok');
        setTimeout(function () {
            showDriverToast('✓ Safe to use other apps now — we\u2019ll notify you here when a ride comes in', 'info');
        }, 1600);
        _showOnlineReminder();
        _maybeShowBatteryTip();
        setTimeout(function () {
            try {
                if (DRV.map) { google.maps.event.trigger(DRV.map, 'resize'); }
            } catch (e) {}
        }, 300);
    }, function (msg) {
        if (btn) btn.disabled = false;
        _resetSlideOnline();
        showDriverToast(msg, 'err');
    });
}

var _powerHoldTimer = null;
function startPowerHold(e) {
    e.preventDefault();
    var btn = document.getElementById('poweroff-btn');
    if (btn) btn.style.transform = 'scale(0.88)';
    _powerHoldTimer = setTimeout(function () {
        _powerHoldTimer = null;
        if (btn) btn.style.transform = '';
        goOffline();
    }, 800);
}
function cancelPowerHold() {
    if (_powerHoldTimer) { clearTimeout(_powerHoldTimer); _powerHoldTimer = null; }
    var btn = document.getElementById('poweroff-btn');
    if (btn) btn.style.transform = '';
}

function confirmGoOffline() {
    if (confirm('Go offline? You will stop receiving ride requests until you go online again.')) {
        goOffline();
    }
}

function goOffline() {
    drvApi('driver_go_offline', {}, function () {
        DRV.isOnline = false;
        DRV._nearbyCount = 0;
        setOnlineUI(false);
        stopGps();
        stopPolling();
        clearRideMarkers();
        var rl2 = document.getElementById('requests-list');
        if (rl2) rl2.innerHTML = _offlineEmptyStateHtml();
        showDriverToast('You are now offline', 'info');
        _clearOnlineReminder();
    }, function (msg) { showDriverToast(msg, 'err'); });
}

function setOnlineUI(on) {
    var statusPill = document.getElementById('online-status-pill');
    var powerBtn   = document.getElementById('poweroff-btn');
    var slideWrap  = document.getElementById('slide-online-wrap');
    var helpBtn    = document.getElementById('battery-tip-help-btn');
    if (statusPill) statusPill.style.display = on ? 'flex' : 'none';
    if (powerBtn)   powerBtn.style.display   = on ? 'flex' : 'none';
    if (helpBtn)    helpBtn.style.display    = on ? 'block' : 'none';
    if (slideWrap)  slideWrap.style.display  = on ? 'none' : 'block';
    _updateTopbarStatus();
    var banner = document.getElementById('unavail-banner');
    if (banner) banner.style.display = 'none';
    _updateSheetFocus();
}

/* ── Sheet focus: while a request is waiting for a decision or a ride is
   active, that's the only thing that matters on this screen - collapse
   the goal/Street-Hail section instead of making the driver scroll past
   it to reach the thing that actually needs their attention. Restored
   automatically the moment neither is true. */
function _updateSheetFocus() {
    var sheet = document.getElementById('req-sheet');
    if (!sheet) return;
    var focused = !!DRV.activeRide || !!(DRV._nearbyCount > 0);
    sheet.classList.toggle('sheet-focused', focused);
}

/* ── Topbar status: reflects the actual ride lifecycle, not just
   online/offline, so the driver can read their current stage at a
   glance without opening the ride card. Reads existing state only
   (DRV.isOnline, DRV.activeRide.status) - no new state is introduced. */
function _updateTopbarStatus() {
    var st  = document.getElementById('status-text');
    var dot = document.getElementById('drv-status-dot');
    if (!st) return;
    var ride = DRV.activeRide;
    var label, on;
    if (ride && (ride.status === 'driver_assigned' || ride.status === 'accepted')) {
        label = 'Going to pickup'; on = true;
    } else if (ride && ride.status === 'arrived') {
        /* 'arrived' means arrived at the DESTINATION awaiting payment
           (see arrivedRide()/driver-rate-rider.js's header comment) -
           NOT arrived at pickup. Was mislabeled 'At pickup' before. */
        label = 'Awaiting payment'; on = true;
    } else if (ride && ride.status === 'in_progress') {
        label = 'On trip'; on = true;
    } else if (DRV.isOnline) {
        label = 'Ready for rides'; on = true;
    } else {
        label = 'Offline'; on = false;
    }
    st.textContent = label;
    if (dot) dot.classList.toggle('online', on);
}

/* ── SSE stream — replaces 8s setInterval polling ── */
function startPolling() {
    stopPolling();
    checkActiveRide();
    _startSSE();
}

function stopPolling() {
    if (DRV.sseSource) { DRV.sseSource.close(); DRV.sseSource = null; }
    clearInterval(DRV.pollInterval);
    DRV.pollInterval = null;
}

function _startSSE() {
    if (!DRV.isOnline) return;
    if (DRV.sseSource) { DRV.sseSource.close(); DRV.sseSource = null; }
    var token = (window.ETEL && ETEL.auth && ETEL.auth.token) ? ETEL.auth.token : (localStorage.getItem('etel_token') || '');
    var url = (window.ETEL && ETEL.api ? ETEL.api : '/delivery/api/boot.php') + '?action=sse_driver_stream&radius_km=8&token=' + encodeURIComponent(token);
    try {
        var es = new EventSource(url);
        DRV.sseSource = es;
        es.onmessage = function(e) {
            try {
                var d = JSON.parse(e.data);
                if (!d || d.error) return;
                renderNearbyRequests(d.requests || []);
                if (d.active_ride && !DRV.activeRide) {
                    _applyActiveRideUpdate(d.active_ride);
                    switchReqTab('active');
                } else if (d.active_ride && DRV.activeRide) {
                    _applyActiveRideUpdate(d.active_ride);
                } else if (!d.active_ride && DRV.activeRide) {
                    /* Ride disappeared server-side (e.g. customer cancelled mid-trip) --
                       stop waiting on it and free the driver up instead of leaving the
                       stale card on screen forever. */
                    if (DRV.arrivedPoll) { clearInterval(DRV.arrivedPoll); DRV.arrivedPoll = null; }
                    DRV.activeRide = null;
                    renderActiveRide(null);
                    showDriverToast('Trip ended by rider — you are available again', 'warn');
                    loadDriverProfile();
                }
            } catch(ex) {}
        };
        es.addEventListener('close', function() {
            es.close(); DRV.sseSource = null;
            if (DRV.isOnline) setTimeout(_startSSE, 4000);
        });
        es.onerror = function() {
            es.close(); DRV.sseSource = null;
            /* SSE failed — fall back to 10s polling */
            if (DRV.isOnline && !DRV.pollInterval) {
                DRV.pollInterval = setInterval(_pollFallback, 10000);
                _pollFallback();
            }
        };
    } catch(ex) {
        DRV.pollInterval = setInterval(_pollFallback, 10000);
        _pollFallback();
    }
}

function _pollFallback() {
    if (!DRV.isOnline) return;
    drvApi('driver_get_nearby_requests', { radius_km: 8 }, function(data) {
        renderNearbyRequests(data.requests || []);
    }, null);
}

function pollNearby() { _pollFallback(); }

/* == Background/foreground resilience ==
   Mobile OS/browsers throttle or fully suspend JS timers, GPS watches, and
   SSE connections once this tab is backgrounded - that is a platform-level
   restriction (most aggressive on iOS Safari), not something any web code
   can override. What we CAN do: send one best-effort "still here" ping the
   moment backgrounding starts (via sendBeacon, which can fire even as the
   page suspends, unlike a normal fetch), and the instant the driver returns,
   immediately resume full heartbeat + reconnect the live feed rather than
   waiting for the driver to notice and re-toggle online. */
function _beaconPing() {
    if (!DRV.isOnline) return;
    var token = '';
    try { token = localStorage.getItem('etel_token') || ''; } catch (e) {}
    if (!token || !navigator.sendBeacon) return;
    var url = (window.ETEL && ETEL.api ? ETEL.api : '/delivery/api/boot.php') +
        '?action=driver_beacon_ping&token=' + encodeURIComponent(token);
    var payload = { action: 'driver_beacon_ping' };
    if (DRV.currentLat) { payload.lat = DRV.currentLat; payload.lng = DRV.currentLng; }
    try {
        navigator.sendBeacon(url, new Blob([JSON.stringify(payload)], { type: 'application/json' }));
    } catch (e) {}
}

document.addEventListener('visibilitychange', function () {
    if (!DRV.isOnline) return;
    if (document.visibilityState === 'hidden') {
        _beaconPing();
        _showOnlineReminder();
    } else {
        /* Back in the foreground - resume immediately instead of waiting for
           the next throttled timer tick or SSE reconnect attempt. */
        if (DRV.isOnline) _acquireWakeLock();
        // While the screen is off, watchPosition gets suspended on most
        // Android browsers (not just throttled - it stops delivering fixes
        // entirely) and the underlying GPS provider often doesn't resume
        // cleanly on its own once the tab wakes back up. DRV.currentLat/lng
        // is therefore whatever position was current the instant the screen
        // went to sleep - broadcasting that immediately used to send a
        // stale ping (near-zero movement for the whole sleep duration), and
        // the GPS chip's first fix after a cold resume is often a poor
        // accuracy reading anyway, which the server's accuracy gate then
        // correctly excludes from distance credit - together this is what
        // made trips continued after a screen sleep look like tracking had
        // silently stopped. Force a fresh fix and restart the watch instead
        // of trusting whatever was last known, so the very first thing that
        // happens on resume is real, current, high-accuracy position data.
        if (DRV.isOnline && navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function (pos) {
                DRV.currentLat = pos.coords.latitude;
                DRV.currentLng = pos.coords.longitude;
                DRV.currentAccuracy = pos.coords.accuracy;
                DRV._lastFixAt = Date.now();
                DRV._geoError = null;
                _updateGpsErrorBadge(null);
                _updateGpsQualityBadge();
                updateSelfMarker();
                broadcastLocation();
            }, function () {
                broadcastLocation(); // fall back to whatever's known rather than blocking resume entirely
            }, { enableHighAccuracy: true, maximumAge: 0, timeout: 15000 });
            // Full stop+restart rather than trusting the existing watchId to
            // keep delivering fixes post-resume - some Android WebViews
            // leave a pre-sleep watch "registered" but silently dead after
            // the OS suspends and resumes the tab. stopGps() also clears
            // the location/staleness intervals so startGps() doesn't stack
            // a second set of them on top of the still-running originals.
            stopGps();
            startGps();
        } else {
            broadcastLocation();
        }
        if (!DRV.sseSource || DRV.sseSource.readyState === EventSource.CLOSED) {
            _startSSE();
        }
        checkActiveRide();
    }
});

function checkActiveRide() {
    drvApi('driver_get_profile', {}, function (data) {
        DRV.driverProfile = data.driver;
        updateEarningsUI(data.driver);
        if (data.active_ride) {
            _applyActiveRideUpdate(data.active_ride);
            /* Auto-switch to active tab if there is a live ride */
            switchReqTab('active');
        }
    }, null);
}

/* ── Render nearby requests ── */
function renderNearbyRequests(requests) {
    var el = document.getElementById('requests-list');
    if (!el) return;
    clearRideMarkers();

    /* Requests here are broadcast to every online nearby driver (first to
       accept wins) - there's no per-driver "offer" to decline server-side.
       Declining is therefore a local dismissal only: stop ringing and hide
       this one card until it naturally drops out of the next poll/SSE
       tick, which is exactly what already happens once it expires or
       another driver takes it. */
    if (!DRV._dismissedRequestIds) DRV._dismissedRequestIds = {};
    requests = requests.filter(function (r) { return !DRV._dismissedRequestIds[r.id]; });

    DRV._nearbyCount = requests.length;
    _updateSheetFocus();

    if (!requests.length) {
        stopAllRinging();
        DRV._seenRequestIds = {};
        DRV._dismissedRequestIds = {};
        el.innerHTML = _waitingForRequestsHtml();
        return;
    }
    /* New requests arrived — auto-show the requests sub-tab */
    if (DRV.currentReqTab !== 'requests') switchReqTab('requests');

    /* Ring (sound + vibration) per-request, continuously, until accepted
       or muted - not just once per poll. Also stop ringing for any
       request that's no longer in this list (taken by someone else, or
       expired) so it doesn't ring forever for an offer that's gone. */
    if (!DRV._seenRequestIds) DRV._seenRequestIds = {};
    var currentIds = {};
    requests.forEach(function (r) { currentIds[r.id] = true; });
    Object.keys(DRV._seenRequestIds).forEach(function (id) {
        if (!currentIds[id]) { stopRingingFor(id); delete DRV._seenRequestIds[id]; }
    });
    requests.forEach(function (r) {
        if (!DRV._seenRequestIds[r.id]) {
            DRV._seenRequestIds[r.id] = true;
            startRingingFor(r.id);
        }
    });

    el.innerHTML = requests.map(function (r) {
        // Add markers to map
        var marker = new google.maps.Marker({
            position: { lat: parseFloat(r.pickup_lat), lng: parseFloat(r.pickup_lng) },
            map: DRV.map,
            icon: { path: google.maps.SymbolPath.CIRCLE, fillColor: '#E5C76B', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2, scale: 8 },
            title: r.pickup_address
        });
        DRV.rideMarkers.push(marker);

        var expiryLeft = r.expires_at ? Math.max(0, Math.round((new Date(r.expires_at) - new Date()) / 1000)) : 0;
        return '<div class="req-card" id="req-card-' + r.id + '" data-expires-at="' + esc(r.expires_at || '') + '">' +
            '<div class="req-top">' +
                '<div class="req-fare">ETB ' + parseFloat(r.fare_final).toFixed(0) + '</div>' +
                '<span class="req-dist-badge">📍 ' + parseFloat(r.distance_from_driver_km).toFixed(1) + ' km away</span>' +
                '<button onclick="declineRequest(\'' + r.id + '\')" style="margin-left:auto;padding:5px 10px;border:1px solid rgba(255,255,255,.15);background:transparent;color:var(--dim,#9A9AAE);border-radius:8px;font-size:11px;cursor:pointer">🚫 Decline</button>' +
            '</div>' +
            '<div style="font-size:12px;color:var(--dim);margin-bottom:8px">👤 ' + esc(r.passenger_name || 'Passenger') + '</div>' +
            '<div class="req-route">' +
                '<div class="req-stop"><div class="req-stop-dot pickup"></div><div class="req-addr"><strong>' + esc(r.pickup_address || 'Pickup') + '</strong></div></div>' +
                '<div class="req-connector"></div>' +
                '<div class="req-stop"><div class="req-stop-dot dropoff"></div><div class="req-addr">' + esc(r.dropoff_address || 'Drop-off') + ' <small>· ' + parseFloat(r.distance_km).toFixed(1) + ' km</small></div></div>' +
            '</div>' +
            (expiryLeft ? '<div class="req-expiry-text" style="font-size:11px;color:var(--dim);margin-bottom:10px">⏱ Expires in ' + expiryLeft + 's</div>' : '') +
            '<div class="slide-accept-wrap">' +
              '<div class="slide-accept-track" data-ride-id="' + r.id + '">' +
                '<div class="slide-accept-fill"></div>' +
                '<div class="slide-accept-label"><span class="slide-accept-arrow">‹</span><span>Slide to Accept</span></div>' +
                '<div class="slide-accept-thumb" data-ride-id="' + r.id + '">◀</div>' +
              '</div>' +
            '</div>' +
            '</div>';
    }).join('');
}

/* Audible + haptic alert for a new incoming request - rings continuously
   (not a fixed number of times) until the driver either accepts this
   specific ride or taps Mute on this specific card, tracked per-request-id
   so multiple simultaneous offers don't interfere with each other.
   Uses the Web Audio API rather than shipping an audio file - works
   offline, no asset to fetch, can't 404.
   NOTE ON BACKGROUNDING: this only plays while the app is actually open
   and in the foreground. No web page can keep JS/audio running reliably
   once the browser puts the tab/PWA in the background - that's an
   intentional battery-saving restriction on every mobile browser, not a
   bug here. Real "ring even while the phone is in another app" would
   need Web Push notifications waking the service worker, which is a much
   bigger feature (server-side push infrastructure) than this update. */
if (!window.DRV_ringingTimers) window.DRV_ringingTimers = {};

function _playRingBurst() {
    try {
        var Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return;
        if (!DRV._audioCtx) DRV._audioCtx = new Ctx();
        var ctx = DRV._audioCtx;
        if (ctx.state === 'suspended') ctx.resume().catch(function () {});
        // Two frequencies x two waveform layers (sine + triangle stacked)
        // reads noticeably louder/fuller than a single sine tone at the
        // same gain, without distorting like a square wave would.
        [880, 1175].forEach(function (freq, i) {
            [{ type: 'sine', gain: 0.9 }, { type: 'triangle', gain: 0.5 }].forEach(function (layer) {
                var osc = ctx.createOscillator();
                var gain = ctx.createGain();
                osc.type = layer.type;
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.0001, ctx.currentTime);
                var start = ctx.currentTime + i * 0.16;
                gain.gain.exponentialRampToValueAtTime(layer.gain, start + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.26);
                osc.connect(gain); gain.connect(ctx.destination);
                osc.start(start); osc.stop(start + 0.28);
            });
        });
    } catch (e) { /* audio not available -- non-fatal */ }
}

function startRingingFor(requestId) {
    if (window.DRV_ringingTimers[requestId]) return; // already ringing for this one
    if (navigator.vibrate) navigator.vibrate([200, 100, 200, 100, 200]);
    _playRingBurst();
    window.DRV_ringingTimers[requestId] = setInterval(function () {
        if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
        _playRingBurst();
    }, 1400);
}

function stopRingingFor(requestId) {
    if (window.DRV_ringingTimers[requestId]) {
        clearInterval(window.DRV_ringingTimers[requestId]);
        delete window.DRV_ringingTimers[requestId];
    }
}

/* Shared empty-state markup for the requests list, with a big
   state-specific header (Online/Offline) matching the design's
   per-state headers. Centralized so the call sites that show these
   can't drift out of sync with each other. */
function _waitingForRequestsHtml() {
    return '<div class="empty-state state-empty"><div class="state-empty-header">Online</div><div class="ei">🔍</div><p>Waiting for nearby requests…</p></div>';
}
function _offlineEmptyStateHtml() {
    return '<div class="empty-state state-empty"><div class="state-empty-header state-empty-header--offline">Offline</div><div class="ei">📴</div><p>Go online to see nearby ride requests</p></div>';
}

function stopAllRinging() {
    Object.keys(window.DRV_ringingTimers).forEach(stopRingingFor);
}

/* Local-only dismissal (see the comment in renderNearbyRequests): stop
   ringing, hide this one card, and remember it for the rest of this
   session so a poll/SSE tick landing before it naturally expires
   doesn't bring it straight back. */
function declineRequest(requestId) {
    stopRingingFor(requestId);
    if (!DRV._dismissedRequestIds) DRV._dismissedRequestIds = {};
    DRV._dismissedRequestIds[requestId] = true;
    var card = document.getElementById('req-card-' + requestId);
    if (card) card.remove();
    if (!document.querySelector('#requests-list .req-card')) {
        var el = document.getElementById('requests-list');
        DRV._nearbyCount = 0;
        _updateSheetFocus();
        if (el) el.innerHTML = _waitingForRequestsHtml();
    }
}
/* Kept as an alias in case any external caller still references the old
   name - functionally identical now that decline also mutes. */
function muteRequestRing(requestId) { declineRequest(requestId); }

/* A request card's countdown used to be computed once at render time and
   never updated again until the next poll/SSE tick happened to redraw
   the whole list - so it both froze between refreshes and, worse, could
   sit on screen reading "Expires in 0s" (or a stale positive number)
   indefinitely if the next refresh was delayed. This ticks every second
   off the real expires_at timestamp and removes a card itself the
   moment it's actually expired, rather than waiting on the network. */
function _tickRequestExpiries() {
    var cards = document.querySelectorAll('.req-card[data-expires-at]');
    cards.forEach(function (card) {
        var expiresAt = card.getAttribute('data-expires-at');
        if (!expiresAt) return;
        var secondsLeft = Math.round((new Date(expiresAt) - Date.now()) / 1000);
        if (secondsLeft <= 0) {
            var id = card.id.replace('req-card-', '');
            stopRingingFor(id);
            if (DRV._seenRequestIds) delete DRV._seenRequestIds[id];
            card.remove();
            if (!document.querySelector('#requests-list .req-card')) {
                DRV._nearbyCount = 0;
                _updateSheetFocus();
                var el = document.getElementById('requests-list');
                if (el) el.innerHTML = _waitingForRequestsHtml();
            }
            return;
        }
        var label = card.querySelector('.req-expiry-text');
        if (label) label.textContent = '⏱ Expires in ' + secondsLeft + 's';
    });
}
/* Started exactly once when this script first loads, guarded so a
   duplicate <script> load or a hot-reload during development can never
   stack a second ticking interval on top of this one. */
if (!window._etelReqExpiryTickStarted) {
    window._etelReqExpiryTickStarted = true;
    setInterval(_tickRequestExpiries, 1000);
}

function clearRideMarkers() {
    DRV.rideMarkers.forEach(function (m) { m.setMap(null); });
    DRV.rideMarkers = [];
}

/* ── Accept ride ── */
function acceptRide(requestId) {
    var btns = document.querySelectorAll('.req-accept-btn');
    btns.forEach(function (b) { b.disabled = true; });
    stopAllRinging();
    drvApi('driver_accept_ride', { request_id: requestId }, function (data) {
        DRV.activeRide = data;
        renderActiveRide(data);
        stopPolling();
        clearRideMarkers();
        var rl = document.getElementById('requests-list');
        if (rl) rl.innerHTML = '<div class="empty-state"><div class="ei">🚗</div><p>You are on a ride!</p></div>';
        showDriverToast('Ride accepted! Navigate to pickup', 'ok');
        if (data.pickup_lat && !isNaN(parseFloat(data.pickup_lat))) drawRoute({ lat: parseFloat(data.pickup_lat), lng: parseFloat(data.pickup_lng) });
        switchReqTab('active');
    }, function (msg) {
        btns.forEach(function (b) { b.disabled = false; });
        showDriverToast(msg, 'err');
    });
}

/* ── Status-rank guard against stale/out-of-order active-ride reads ──
   Multiple independent code paths (visibilitychange -> checkActiveRide(),
   loadDriverProfile(), the online-auto-resume block, SSE pushes) all fetch
   driver_get_profile / receive active_ride pushes and used to blindly
   overwrite the rendered card. If one of those reads lands late and still
   shows an older status (e.g. 'in_progress' arriving after the driver has
   already tapped Arrived and the card shows 'arrived'), it would silently
   resurrect the "Arrived — Bill Customer" button, letting the driver tap
   it a second time (server correctly rejects the 2nd driver_arrived call,
   but the stale button reappearing is confusing/looks broken). Route every
   *background* active-ride update through this guard instead of setting
   DRV.activeRide + renderActiveRide directly. */
var DRV_STATUS_RANK = { driver_assigned: 0, accepted: 0, in_progress: 1, arrived: 2, completed: 3 };
function _applyActiveRideUpdate(newRide) {
    if (!newRide) { DRV.activeRide = null; renderActiveRide(null); return; }
    var cur = DRV.activeRide;
    if (cur && cur.id === newRide.id) {
        var curRank = DRV_STATUS_RANK.hasOwnProperty(cur.status) ? DRV_STATUS_RANK[cur.status] : 0;
        var newRank = DRV_STATUS_RANK.hasOwnProperty(newRide.status) ? DRV_STATUS_RANK[newRide.status] : 0;
        if (newRank < curRank) return; /* stale/out-of-order read - ignore */
        // Same guard, for the live GPS-meter/fare-breakdown fields within
        // one in_progress ride: this driver_get_profile snapshot and the
        // GPS ping in broadcastLocation() race independently over the
        // network, so a profile request sent BEFORE the latest location
        // update can still arrive AFTER it - its fields were read from the
        // DB/computed before that update landed, so they're real but older
        // (often still 0/missing right at trip start, or simply absent if
        // the server's fare-breakdown computation didn't run for this
        // particular response). Two failure modes seen in practice:
        // 1) the field is present but numerically smaller (a genuinely
        //    older reading) - these fields only ever increase over one
        //    in_progress ride, so never let them go backwards.
        // 2) the field is missing/undefined entirely - renderActiveRide's
        //    Fare Breakdown block is gated on fare_total!=null, so a
        //    snapshot missing it makes the WHOLE breakdown section vanish
        //    (not just show 0) even though a moment ago it was showing
        //    correct numbers. Carry the last known value forward instead.
        if (newRank === curRank) {
            var MONOTONIC_FARE_FIELDS = ['traveled_km', 'meter_fare', 'fare_total', 'fare_base',
                'fare_distance', 'fare_wait', 'fare_subtotal', 'fare_surge_etb', 'fare_floor_topup', 'wait_minutes'];
            var FILL_ONLY_FIELDS = ['fare_surge_mult', 'meter_surge', 'wait_active', 'distance_km'];
            MONOTONIC_FARE_FIELDS.concat(FILL_ONLY_FIELDS).forEach(function (k) {
                if (newRide[k] === undefined || newRide[k] === null) {
                    if (cur[k] !== undefined && cur[k] !== null) newRide[k] = cur[k];
                    return;
                }
                if (MONOTONIC_FARE_FIELDS.indexOf(k) === -1) return;
                var cn = parseFloat(cur[k]), nn = parseFloat(newRide[k]);
                if (!isNaN(cn) && !isNaN(nn) && nn < cn) newRide[k] = cn;
            });
        }
    }
    DRV.activeRide = newRide;
    renderActiveRide(newRide);
}

/* ── Render active ride ── */
function renderActiveRide(ride) {
    var container = document.getElementById('active-ride-container');
    _updateSheetFocus();
    _updateTopbarStatus();
    if (!ride) {
        /* No separate "No active ride" card - the Offline/Ready-for-rides
           state right below already says everything there is to say
           when there's nothing active, and showing both was redundant
           clutter (two empty-state messages stacked on top of each
           other). */
        container.innerHTML = '';
        DRV._routeEtaKey = null;
        return;
    }

    var statusPill = {
        driver_assigned: '<span class="active-status-pill pill-assigned">🚗 Go to Pickup</span>',
        accepted:        '<span class="active-status-pill pill-assigned">🤝 Linked — ready</span>',
        in_progress:     '<span class="active-status-pill pill-inprog">📟 Meter Running</span>',
        arrived:         '<span class="active-status-pill pill-inprog">🏁 Awaiting Payment</span>'
    }[ride.status] || '';

    /* Large state-specific title, matching the target design's per-state
       headers - purely a bigger label over the same statusPill/state,
       not a new state. 'arrived' says "Awaiting Payment" here too (not
       "Arrived"), since that status means arrived at the DESTINATION
       waiting for the customer to pay, not arrived at pickup. */
    var stateHeader = {
        driver_assigned: 'Going to Pickup',
        accepted:        'Going to Pickup',
        in_progress:     'Trip in Progress',
        arrived:         'Awaiting Payment'
    }[ride.status] || '';

    /* One primary action per state — everything else (nav, route history,
       chat, cancel) lives behind the "⋯ More" panel below so the driver
       isn't shown five buttons at once mid-trip. */
    var primaryBtn;
    if (ride.status === 'driver_assigned' || ride.status === 'accepted') {
        primaryBtn = '<button class="action-btn primary" onclick="startRide(' + ride.id + ')">🚀 Passenger Picked Up</button>';
    } else if (ride.status === 'arrived') {
        primaryBtn = '';
    } else {
        primaryBtn = '<button class="action-btn primary" onclick="arrivedRide(' + ride.id + ')">🏁 Arrived — Bill Customer</button>';
    }

    var extra = '';
    var isSurge = ride.meter_surge != null ? !!ride.meter_surge : (ride.surge_applied == 1 || ride.surge === true);
    var surgeMult = ride.fare_surge_mult != null ? parseFloat(ride.fare_surge_mult).toFixed(1) : '1.3';
    var surgeTag = isSurge
        ? '<span id="meter-surge" style="display:inline-flex;align-items:center;gap:4px;background:rgba(255,152,0,.15);border:1px solid rgba(255,152,0,.4);color:#FFB74D;font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:999px;margin-top:6px">⚡ Peak-hour pricing (' + surgeMult + '×)</span>'
        : '<span id="meter-surge" style="display:none"></span>';

    if (ride.status === 'in_progress') {
        extra = '<div style="display:flex;gap:8px;margin-bottom:12px;background:rgba(212,175,55,.07);border:1px dashed rgba(212,175,55,.4);border-radius:12px;padding:12px;align-items:center">'
            + '<div style="font-size:22px">📟</div>'
            + '<div style="flex:1"><div class="ride-info-k">GPS meter</div><div id="meter-km" style="font-size:17px;font-weight:800;color:var(--gold-lt)">' + parseFloat(ride.traveled_km != null ? ride.traveled_km : (ride.distance_km || 0)).toFixed(2) + ' km</div></div>'
            + '<div style="text-align:right"><div class="ride-info-k">Current fare</div><div id="meter-fare" style="font-size:17px;font-weight:800;color:var(--teal)">ETB ' + parseFloat(ride.meter_fare != null ? ride.meter_fare : (ride.fare_final || ride.fare_estimate || ride.fare || 0)).toFixed(0) + '</div>' + surgeTag + '</div>'
            + '</div>'
            + '<div id="wait-toggle-box" style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px;background:rgba(255,255,255,.03);border:1px solid rgba(212,175,55,.15);border-radius:12px;padding:12px 14px;transition:all .25s">'
            +   '<div><div class="ride-info-k">Waiting for customer</div><div id="wait-status-text" style="font-size:13px;font-weight:700;color:var(--text-dim);margin-top:2px">Not waiting</div></div>'
            +   '<button id="wait-toggle-btn" onclick="toggleWaitFee()" style="border:none;border-radius:10px;padding:10px 16px;font-weight:700;font-size:12.5px;cursor:pointer;background:linear-gradient(135deg,#FFD700,#D4AF37);color:#1a1206;white-space:nowrap">⏱ Start Waiting</button>'
            + '</div>';
        /* The itemized Fare Breakdown table (base/distance/subtotal/total)
           used to also render here, live, throughout the entire trip -
           stacked directly below the running meter box, in the same
           card, it read as a second "billing" screen sitting on top of
           the "Trip in Progress" screen (reported as the trip card
           appearing to render two different interfaces for the same
           ride). The running "Current fare" figure in the meter box
           above already gives the driver a live total; the itemized
           breakdown now only appears once the trip actually reaches
           'arrived' (awaiting payment) below, which is the one moment
           it's actually meant to be read as a bill. */
    }
    if (ride.status === 'arrived') {
        extra = '<div style="margin-bottom:12px;background:rgba(46,204,113,.08);border:1px solid rgba(46,204,113,.35);border-radius:12px;padding:14px;text-align:center">'
            + '<div style="font-size:13px;color:var(--text-dim);margin-bottom:4px">Trip finished — ' + parseFloat(ride.distance_km || 0).toFixed(2) + ' km</div>'
            + '<div style="font-size:24px;font-weight:800;color:#2ECC71">ETB ' + parseFloat(ride.fare_final || ride.fare_estimate || ride.fare || 0).toFixed(0) + '</div>'
            + (isSurge ? '<div style="display:inline-flex;align-items:center;gap:4px;background:rgba(255,152,0,.15);border:1px solid rgba(255,152,0,.4);color:#FFB74D;font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:999px;margin-top:6px">⚡ Peak-hour pricing was applied (' + surgeMult + '×)</div>' : '')
            + '<div style="font-size:12px;color:var(--text-dim);margin-top:6px">Waiting for the customer to confirm &amp; pay… collect cash if cash.</div>'
            + '</div>'
            + (ride.fare_total != null ? _fareBreakdownHtml(ride) : '');
    }

    var phone = ride.passenger_phone || (ride.passenger && ride.passenger.phone) || '';
    var morePanel = '<div class="ride-more-panel" id="ride-more-panel">'
        + '<div style="display:flex;gap:10px;margin-bottom:8px">'
        +   '<button class="action-btn" style="flex:1;background:rgba(78,205,196,.12);border:1px solid rgba(78,205,196,.3);color:var(--teal)" onclick="navigateTo(' + parseFloat(ride.pickup_lat || 0) + ',' + parseFloat(ride.pickup_lng || 0) + ')">🗺️ Pickup Nav</button>'
        +   '<button class="action-btn" style="flex:1;background:rgba(96,180,255,.10);border:1px solid rgba(96,180,255,.2);color:#60B4FF" onclick="navigateTo(' + parseFloat(ride.dropoff_lat || 0) + ',' + parseFloat(ride.dropoff_lng || 0) + ')">📍 Dropoff Nav</button>'
        + '</div>'
        + '<button class="action-btn" style="width:100%;margin-bottom:8px;background:rgba(212,175,55,.1);border:1px solid rgba(212,175,55,.3);color:var(--gold-lt);font-size:13px" onclick="RideChat.open(' + (ride.id || 0) + ",'driver')\">💬 Chat with Passenger</button>"
        + '<button class="action-btn" style="width:100%;margin-bottom:8px;background:rgba(212,175,55,.08);border:1px solid rgba(212,175,55,.25);color:var(--gold-lt)" onclick="showRoutePath(' + (ride.id || 0) + ')">🧭 View Route So Far</button>'
        + '<button class="action-btn danger" style="width:100%" onclick="cancelRide(' + (ride.id || 0) + ')">Cancel Ride</button>'
        + '</div>';

    container.innerHTML = '<div class="active-ride-card">'
        + (stateHeader ? '<div class="ride-state-header">' + stateHeader + '</div>' : '')
        + '<div class="active-ride-header"><span class="active-ride-code">🔑 ' + esc(ride.ride_code || '') + '</span>' + statusPill + '</div>'
        + '<div class="passenger-info">'
        +   '<div class="passenger-avatar">🧑</div>'
        +   '<div><div class="passenger-name">' + esc(ride.passenger_name || (ride.passenger && ride.passenger.name) || '—') + '</div><div class="passenger-phone">' + esc(phone) + '</div></div>'
        +   '<a href="tel:' + esc(phone) + '" class="call-btn">📞 Call</a>'
        + '</div>'
        + '<div style="display:flex;gap:10px;margin-bottom:14px">'
        +   '<div class="ride-info-box" style="background:rgba(229,199,107,.08);border:1px solid var(--gbrd);border-radius:14px;padding:12px;flex:1"><div class="ride-info-k">Pickup</div><div style="font-size:12px;margin-top:4px;color:var(--text)">' + esc(ride.pickup_address || '—') + '</div></div>'
        +   '<div class="ride-info-box" style="background:rgba(78,205,196,.08);border:1px solid rgba(78,205,196,.2);border-radius:14px;padding:12px;flex:1"><div class="ride-info-k">Drop-off</div><div style="font-size:12px;margin-top:4px;color:var(--text)">' + esc(ride.dropoff_address || '—') + '</div></div>'
        + '</div>'
        + '<div class="route-eta-badge" id="route-eta-badge" style="display:none"></div>'
        + extra
        + '<div style="display:flex;gap:8px;margin-bottom:12px">'
        +   '<div class="ride-info-box" style="flex:1;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:10px;text-align:center"><div class="ride-info-k">Fare</div><div style="font-size:18px;font-weight:800;color:var(--gold-lt)">ETB ' + parseFloat(ride.status === 'in_progress' && ride.meter_fare != null ? ride.meter_fare : (ride.fare_final || ride.fare_estimate || ride.fare || 0)).toFixed(0) + '</div></div>'
        +   '<div class="ride-info-box" style="flex:1;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:10px;text-align:center"><div class="ride-info-k">Distance</div><div style="font-size:18px;font-weight:800;color:var(--teal)">' + parseFloat(ride.status === 'in_progress' && ride.traveled_km != null ? ride.traveled_km : (ride.distance_km || 0)).toFixed(1) + ' km</div></div>'
        + '</div>'
        + '<div class="ride-actions">' + primaryBtn + '<button class="action-btn more-toggle" onclick="document.getElementById(\'ride-more-panel\').classList.toggle(\'open\')">⋯ More</button></div>'
        + morePanel
        + '</div>';
    _syncWaitState(ride);
    _maybeRefreshRouteEta(ride);
}

/* ── Ride actions ── */
function arrivedRide(rideId) {
    if (!confirm('Arrived at destination? The customer will be billed the metered fare.')) return;
    drvApi('driver_arrived', { ride_id: rideId, lat: DRV.currentLat, lng: DRV.currentLng }, function (data) {
        /* driver_arrived's own response only returns arrival/fare fields -
           it never re-queries passenger_name/passenger_phone/ride_code
           etc. Replacing DRV.activeRide outright with just this response
           wiped those out of the rendered card (name shows "—", Call
           button goes dead) for one render, until the next background
           poll's full SQL join restores them. Merge onto the existing
           object instead so nothing the driver was already looking at
           disappears. */
        DRV.activeRide = (DRV.activeRide && DRV.activeRide.id === (data.id || rideId))
            ? Object.assign({}, DRV.activeRide, data, { status: 'arrived' })
            : Object.assign({ id: rideId }, data, { status: 'arrived' });
        renderActiveRide(DRV.activeRide);
        showDriverToast('🏁 Trip billed: ETB ' + parseFloat(data.fare_final || data.fare || 0).toFixed(0) + ' — waiting for customer confirmation', 'ok');
        if (DRV.arrivedPoll) clearInterval(DRV.arrivedPoll);
        var arrivedPollAttempts = 0;
        var ARRIVED_POLL_MAX = 36; /* 5s * 36 = 3 minutes before we give up waiting */
        DRV.arrivedPoll = setInterval(function () {
            arrivedPollAttempts++;
            drvApi('driver_get_profile', {}, function (p) {
                if (!p.active_ride) {
                    /* Either the customer paid, or the ride was cancelled out from
                       under us (e.g. a cancel that raced the arrival). Either way,
                       stop waiting and free the driver up. */
                    clearInterval(DRV.arrivedPoll); DRV.arrivedPoll = null;
                    DRV.activeRide = null;
                    renderActiveRide(null);
                    showDriverToast('💰 Trip resolved — you are available again', 'ok');
                    loadDriverProfile();
                } else if (p.active_ride.status !== 'arrived') {
                    _applyActiveRideUpdate(p.active_ride);
                } else if (arrivedPollAttempts >= ARRIVED_POLL_MAX) {
                    /* Customer never confirmed payment after 3 minutes -- don't
                       leave the driver stranded indefinitely. */
                    clearInterval(DRV.arrivedPoll); DRV.arrivedPoll = null;
                    DRV.activeRide = null;
                    renderActiveRide(null);
                    showDriverToast('No response from customer — trip closed, contact support if unpaid', 'warn');
                    loadDriverProfile();
                }
            }, null);
        }, 5000);
    }, function (msg) {
        showDriverToast(msg, 'err');
        /* driver_arrived failing usually means the ride is no longer in_progress
           (e.g. the customer cancelled it moments before we tapped Arrived).
           Don't leave the stale card on screen -- re-check and reset. */
        drvApi('driver_get_profile', {}, function (p) {
            if (!p.active_ride) {
                DRV.activeRide = null;
                renderActiveRide(null);
            } else {
                DRV.activeRide = p.active_ride;
                renderActiveRide(p.active_ride);
            }
        }, null);
    }, true);
}
function startRide(rideId) {
    drvApi('driver_start_ride', { ride_id: rideId }, function () {
        if (DRV.activeRide) {
            DRV.activeRide.status = 'in_progress';
            renderActiveRide(DRV.activeRide);
        }
        showDriverToast('Ride started! Drive safely 🚀', 'ok');
        if (DRV.activeRide && DRV.activeRide.dropoff_lat && !isNaN(parseFloat(DRV.activeRide.dropoff_lat))) {
            drawRoute({ lat: parseFloat(DRV.activeRide.dropoff_lat), lng: parseFloat(DRV.activeRide.dropoff_lng) });
        } else {
            showDriverToast('By-distance ride — passenger will direct you 🧭', 'info');
        }
    }, function (msg) { showDriverToast(msg, 'err'); });
}

// Legacy entry point - the "Complete Ride" button no longer calls this
// directly (see renderActiveRide), but kept as a safe alias in case any
// stale cached bundle still references it: driver_complete_ride itself
// now requires passenger confirmation too, so this just funnels into the
// same arrived/waiting-for-confirmation UI as arrivedRide().
function completeRide(rideId) {
    arrivedRide(rideId);
}

/* Cash out available earnings straight into the driver's own Etel Pay
   wallet. Unlike paying a fare, this doesn't need the driver's Etel Pay
   PIN -- delivery's backend calls Etel Pay directly with a shared secret
   and an idempotent reference, so a retry can never double-pay. */
function openCashoutModal() {
    var available = DRV.availableBalance || 0;
    if (available <= 0) { showDriverToast('No available balance to cash out', 'err'); return; }
    var input = prompt('Cash out how much? (Available: ETB ' + available.toFixed(0) + ')', available.toFixed(0));
    if (!input) return;
    var amount = parseFloat(input);
    if (!amount || amount <= 0) { showDriverToast('Enter a valid amount', 'err'); return; }
    if (amount > available) { showDriverToast('Amount exceeds your available balance', 'err'); return; }

    var btn = document.getElementById('money-cashout-btn');
    var st  = document.getElementById('cashout-status');
    if (btn) btn.disabled = true;
    if (st) st.textContent = 'Sending to ኢE Pay…';

    drvApi('driver_cashout', { amount: amount }, function (data) {
        if (st) st.textContent = '';
        showDriverToast('ETB ' + amount.toFixed(0) + ' sent to your ኢE Pay wallet 💰', 'ok');
        loadDriverProfile();
    }, function (msg) {
        if (btn) btn.disabled = false;
        if (st) st.textContent = '';
        showDriverToast(msg || 'Cashout failed', 'err');
    });
}

function cancelRide(rideId) {
    if (!confirm('Cancel this ride?')) return;
    drvApi('driver_cancel_ride', { ride_id: rideId }, function () {
        DRV.activeRide = null;
        renderActiveRide(null);
        DRV.directionsRenderer.setDirections({ routes: [] });
        showDriverToast('Ride cancelled', 'info');
        startPolling();
    }, function (msg) { showDriverToast(msg, 'err'); });
}

function navigateTo(lat, lng) {
    if (!lat || !lng) { showDriverToast('No coordinates', 'err'); return; }
    drawRoute({ lat: lat, lng: lng });
    DRV.map.panTo({ lat: lat, lng: lng });
}

function drawRoute(dest, opts) {
    var silent = opts && opts.silent;
    if (!DRV.currentLat) { if (!silent) showDriverToast('Location not available', 'err'); return; }
    DRV.directionsService.route({
        origin: { lat: DRV.currentLat, lng: DRV.currentLng },
        destination: dest,
        travelMode: google.maps.TravelMode.DRIVING,
    }, function (result, status) {
        if (status === 'OK') {
            DRV.directionsRenderer.setDirections(result);
            var leg = result.routes[0].legs[0];
            if (!silent) showDriverToast('Route: ' + leg.distance.text + ' · ' + leg.duration.text, 'info');
            var badge = document.getElementById('route-eta-badge');
            if (badge) { badge.textContent = '🗺️ ' + leg.distance.text + ' · ' + leg.duration.text; badge.style.display = 'block'; }
        }
    });
}

/* Auto-computes and shows real distance/ETA to the driver's current leg
   (pickup while going to pickup, destination while on trip) using the
   same Google Directions call navigateTo() already makes on a manual
   tap - just triggered once per leg instead of requiring the driver to
   tap Nav first. Guarded by DRV._routeEtaKey so a re-render from a
   background poll/SSE tick never re-requests the same route twice. */
function _maybeRefreshRouteEta(ride) {
    var badge = document.getElementById('route-eta-badge');
    if (!ride) { if (badge) badge.style.display = 'none'; return; }
    var dest, key;
    if (ride.status === 'driver_assigned' || ride.status === 'accepted') {
        dest = { lat: parseFloat(ride.pickup_lat || 0), lng: parseFloat(ride.pickup_lng || 0) };
        key = ride.id + ':pickup:' + dest.lat + ',' + dest.lng;
    } else if (ride.status === 'in_progress') {
        dest = { lat: parseFloat(ride.dropoff_lat || 0), lng: parseFloat(ride.dropoff_lng || 0) };
        key = ride.id + ':dropoff:' + dest.lat + ',' + dest.lng;
    } else {
        if (badge) badge.style.display = 'none';
        return;
    }
    if (!dest.lat || !dest.lng) { if (badge) badge.style.display = 'none'; return; }
    if (DRV._routeEtaKey === key) return;
    DRV._routeEtaKey = key;
    drawRoute(dest, { silent: true });
}

/* ── Profile & Earnings ── */
function loadDriverProfile() {
    drvApi('driver_get_profile', {}, function (data) {
        DRV.driverProfile = data.driver;
        updateEarningsUI(data.driver);
        if (data.active_ride) {
            _applyActiveRideUpdate(data.active_ride);
        }
        /* Show registration wizard for new drivers on first load */
        if (data.driver && !DRV._regChecked) {
            DRV._regChecked = true;
            checkOnboarding(data.driver);
        }
    }, null);
}

function updateEarningsUI(d) {
    if (!d) return;
    DRV.driverProfile = d;
    // Keeps the driver's own marker on the map showing the correct
    // vehicle icon if their registered vehicle type changed (e.g. right
    // after saving an update in the Update Vehicle Info panel) - present
    // in the deployed bundle's updateEarningsUI but missing here.
    refreshSelfMarkerIcon();
    var earn  = parseFloat(d.total_earnings || 0);
    var rides = parseInt(d.total_rides || 0);
    var rating= parseFloat(d.rating || 5).toFixed(1);
    /* trips_today/earnings_today are computed server-side in
       driver_get_profile (CURDATE()-scoped), but nothing here ever read
       them - every "today" label on this page (goal strip AND the Money
       page hero card) was actually showing lifetime totals the whole
       time, which is why it never changed day to day and looked
       unresponsive to the date strip below it. */
    var earnToday = d.earnings_today !== undefined ? parseFloat(d.earnings_today) : earn;
    var ridesToday = d.trips_today !== undefined ? parseInt(d.trips_today) : rides;
    DRV._earningsToday = { trips: ridesToday, earnings: earnToday };

    /* Requests page strip */
    var sp = document.getElementById('stat-priority');
    var st = document.getElementById('stat-trips-today');
    var se = document.getElementById('stat-etb-today');
    if (sp) sp.textContent = '+' + (d.priority_score || 100);
    if (st) st.textContent = ridesToday + ' trips';
    if (se) se.textContent = 'ETB ' + earnToday.toFixed(0);

    /* Goal bar (progress vs selected tier) - trips_today, not lifetime */
    var tier = DRV.goalTier;
    var pct = tier.trips ? Math.min(100, Math.round(ridesToday / tier.trips * 100)) : 0;
    var bar = document.getElementById('goal-bar-fill');
    if (bar) bar.style.width = pct + '%';

    /* Compact goal block: "X/Y trips" + earned-so-far + trips remaining,
       replacing the old always-on horizontal tier scroller. The tier
       list itself still lives in #goal-chips, just tucked behind the
       "Goals ›" toggle now. */
    var gfNum = document.getElementById('goal-fraction-num');
    var gfDen = document.getElementById('goal-fraction-den');
    var gSub  = document.getElementById('goal-subtext');
    if (gfNum) gfNum.textContent = ridesToday;
    if (gfDen) gfDen.textContent = tier.trips || 0;
    if (gSub) {
        var remaining = Math.max(0, (tier.trips || 0) - ridesToday);
        gSub.textContent = 'ETB ' + earnToday.toFixed(0) + ' earned' +
            (remaining > 0 ? ' · ' + remaining + ' trip' + (remaining === 1 ? '' : 's') + ' remaining' : ' · goal reached 🎉');
    }

    /* Money page hero card - shows whichever date is currently selected
       in the date strip (today by default). Only re-render from this
       fresh profile data if today is still the selected date; if the
       driver has tapped a past day, selectEarningsDate() owns ma/ml
       until they tap back to today, so a background poll landing mid-
       browse doesn't yank the card back to today under them. */
    var ma = document.getElementById('money-amount');
    var ml = document.getElementById('money-trips-lbl');
    var mb = document.getElementById('money-balance');
    var mt = document.getElementById('money-total');
    var available = d.available_balance !== undefined ? parseFloat(d.available_balance) : earn;
    DRV.availableBalance = available;
    /* "Total earned — all trips" must be wallet + cash combined - the
       sub-label right under the Payout card says exactly that ("cash
       trips are already in your hand, so they're counted in your total
       but not added here"), but `earn` (d.total_earnings) is wallet-paid
       rides ONLY by design (see customer_confirm_arrival's payout
       comment) - cash rides never get added to it. Every trip in this
       driver's history is a cash "Open trip" ride, so the lifetime total
       shown was missing almost everything, while today's figure above
       was already correct since driver_get_profile's earnings_today
       query sums fare_paid across BOTH payment methods with no filter.
       True total = total_earnings + cash_fares_total. */
    var cashFaresTotal = parseFloat(d.cash_fares_total || 0);
    var trueTotal = earn + cashFaresTotal;
    if (!DRV.selectedEarningsDate || DRV.selectedEarningsDate === _todayIso()) {
        if (ma) ma.textContent = 'ETB ' + earnToday.toFixed(0);
        if (ml) ml.textContent = ridesToday + ' trip' + (ridesToday === 1 ? '' : 's') + ' completed';
    }
    if (mb) mb.textContent = 'ETB ' + available.toFixed(0);
    if (mt) mt.textContent = 'ETB ' + trueTotal.toFixed(0);
    var cb = document.getElementById('money-cashout-btn');
    if (cb) cb.disabled = available <= 0;

    /* Quick-stat tiles at the top of the Money page (qstat-available /
       qstat-alltime) are separate DOM elements from money-balance /
       money-total right below them - same numbers, just a second visual
       presentation. These two ids were never referenced anywhere in this
       file, so they sat frozen at their static "ETB 0" HTML default
       forever regardless of real earnings - same "getElementById vs
       HTML id=" mismatch class of bug documented elsewhere. */
    var qa = document.getElementById('qstat-available');
    var qt = document.getElementById('qstat-alltime');
    if (qa) qa.textContent = 'ETB ' + available.toFixed(0);
    if (qt) qt.textContent = 'ETB ' + trueTotal.toFixed(0);

    /* This one legitimately stays wallet-only - it's specifically
       labeled "From ኢE Pay trips (cashout-eligible)", i.e. the wallet
       subset of the true total, not the true total itself. */
    var mw = document.getElementById('money-wallet-earned');
    if (mw) mw.textContent = 'ETB ' + earn.toFixed(0);

    /* ኢE Money balance (the driver's prepaid commission balance, 5%
       deducted per completed ride regardless of cash/wallet - see
       customer_confirm_arrival). Same missing-DOM-write bug as the two
       qstat tiles above: etel-money-bal was never written to, even
       though driver_get_profile has always returned d.etel_money_balance.
       Allowed to go negative by design (a running tab) - .neg class
       already existed in CSS for this but nothing ever applied it. */
    var emb = document.getElementById('etel-money-bal');
    if (emb) {
        var etelBal = parseFloat(d.etel_money_balance || 0);
        emb.textContent = 'ETB ' + etelBal.toFixed(0);
        emb.classList.toggle('neg', etelBal < 0);
    }

    /* Profile page */
    var pn  = document.getElementById('prof-name');
    var ph  = document.getElementById('prof-phone');
    var pa  = document.getElementById('prof-avatar');
    var pr  = document.getElementById('prof-rating');
    var pri = document.getElementById('prof-priority');
    var prd = document.getElementById('prof-rides');
    var pp  = document.getElementById('prof-plate');
    var pvn = document.getElementById('prof-vehicle-name');
    var pps = document.getElementById('prof-payment-sub');
    var psv = document.getElementById('prof-service');
    if (pn)  pn.textContent  = d.name || '';
    if (ph)  ph.textContent  = d.phone || '';
    if (pa) {
        if (d.selfie_url) {
            pa.innerHTML = '<img src="' + escUrl(d.selfie_url) + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit">';
        } else {
            var _parts = String(d.name || '').trim().split(/\s+/).filter(Boolean);
            pa.textContent = _parts.length ? (_parts[0][0] + (_parts[1] ? _parts[1][0] : '')).toUpperCase() : '?';
        }
    }
    if (pr)  pr.textContent  = rating;
    if (pri) pri.textContent = '+' + (d.priority_score || 100);
    if (prd) prd.textContent = rides;
    if (pp)  pp.textContent  = d.vehicle_plate || '——';
    if (pvn) pvn.textContent = (d.vehicle_type || 'Bajaj') + ' · 1 vehicle';
    if (psv) psv.textContent = (d.vehicle_type || 'Bajaj') + ' · registered';
    if (pps) pps.textContent = 'ETB ' + available.toFixed(0) + ' available · tap to cash out';

    /* Legacy display name */
    var dn = document.getElementById('driver-display-name');
    if (dn) dn.textContent = d.name || '';
    var un = document.getElementById('driver-user-name');
    if (un) un.textContent = d.phone || '';
}

function _todayIso() {
    var n = new Date();
    return n.getFullYear() + '-' + String(n.getMonth() + 1).padStart(2, '0') + '-' + String(n.getDate()).padStart(2, '0');
}

function renderMoneyPage() {
    /* Build date strip for last 7 days. Was pure decoration before - no
       data-date attribute, no click handler, nothing - so tapping a pip
       did nothing at all and the hero card only ever showed lifetime
       totals mislabeled "Today's Earnings" regardless of which pip
       looked selected. Now each pip carries its real date and calls
       selectEarningsDate() on tap. */
    var strip = document.getElementById('date-strip');
    if (!strip || strip.dataset.built) return;
    strip.dataset.built = '1';
    var now = new Date();
    var html = '';
    for (var i = 6; i >= 0; i--) {
        var d2 = new Date(now); d2.setDate(now.getDate() - i);
        var iso = d2.getFullYear() + '-' + String(d2.getMonth() + 1).padStart(2, '0') + '-' + String(d2.getDate()).padStart(2, '0');
        var isToday = (i === 0);
        html += '<div class="date-pip' + (isToday ? ' today' : '') + '" data-date="' + iso + '" onclick="selectEarningsDate(\'' + iso + '\', this)">' + d2.getDate() + '</div>';
    }
    strip.innerHTML = html;
    DRV.selectedEarningsDate = _todayIso();
    loadDriverKpiSummary('today');
    loadFuelLogs();
}

function loadDriverKpiSummary(period, btnEl) {
    var toggle = document.getElementById('kpi-period-toggle');
    if (toggle) {
        var btns = toggle.querySelectorAll('.kpi-period-btn');
        for (var i = 0; i < btns.length; i++) btns[i].classList.remove('active');
        if (btnEl) btnEl.classList.add('active');
        else {
            var match = toggle.querySelector('[data-period="' + period + '"]');
            if (match) match.classList.add('active');
        }
    }
    DRV._kpiPeriod = period;
    var elNetHr = document.getElementById('kpi-net-per-hour');
    var elNote = document.getElementById('kpi-net-per-hour-note');
    var elRides = document.getElementById('kpi-rides');
    var elGross = document.getElementById('kpi-gross');
    var elFee = document.getElementById('kpi-fee');
    var elNet = document.getElementById('kpi-net');
    var elKm = document.getElementById('kpi-km');
    var elHours = document.getElementById('kpi-hours');
    var elFuelCost = document.getElementById('kpi-fuel-cost');
    var elFuelTag = document.getElementById('kpi-fuel-rate-tag');
    var elNetAfterFuel = document.getElementById('kpi-net-after-fuel');
    if (elNetHr) elNetHr.textContent = '…';
    drvApi('driver_get_kpi_summary', { period: period }, function (d) {
        if (DRV._kpiPeriod !== period) return; // driver switched tabs while this was in flight
        if (elNetHr) elNetHr.textContent = d.net_etb_per_hour !== null && d.net_etb_per_hour !== undefined ? 'ETB ' + d.net_etb_per_hour.toFixed(0) : '—';
        if (elNote) elNote.textContent = d.note || '';
        if (elRides) elRides.textContent = d.completed_rides;
        if (elGross) elGross.textContent = 'ETB ' + d.gross_fare.toFixed(0);
        if (elFee) elFee.textContent = 'ETB ' + d.platform_fee.toFixed(0);
        if (elNet) elNet.textContent = 'ETB ' + d.net_earnings.toFixed(0);
        if (elKm) elKm.textContent = d.distance_km.toFixed(1) + ' km';
        if (elHours) elHours.textContent = d.online_hours.toFixed(1) + ' hrs';
        if (elFuelCost) elFuelCost.textContent = 'ETB ' + (d.estimated_fuel_cost || 0).toFixed(0);
        if (elFuelTag) elFuelTag.textContent = d.using_real_fuel_rate ? '(your real rate)' : '(~8/km estimate)';
        if (elNetAfterFuel) elNetAfterFuel.textContent = 'ETB ' + (d.net_after_fuel != null ? d.net_after_fuel.toFixed(0) : '0');
    }, function (err) {
        if (DRV._kpiPeriod !== period) return;
        if (elNetHr) elNetHr.textContent = '—';
        if (elNote) elNote.textContent = 'Could not load';
        showDriverToast(err || 'Could not load performance data', 'err');
    });
}

/* ══════════ Fuel Management ══════════ */

function openFuelLogForm() {
    openDriverModal('⛽ Log a Fill-up',
        '<p style="margin-bottom:14px;font-size:12.5px;color:rgba(245,237,216,.6)">Enter what you actually paid. Adding your current odometer reading lets us calculate your real cost per km once you have 2+ logs — otherwise we keep using the ~8 ETB/km estimate.</p>' +
        '<label style="display:block;font-size:11px;color:rgba(245,237,216,.5);margin-bottom:5px;text-transform:uppercase;letter-spacing:.06em">Liters</label>' +
        '<input id="fuel-log-liters" type="number" min="0" step="0.1" placeholder="e.g. 9" style="width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:#191926;color:#EDEDF5;font-size:14px;margin-bottom:12px;box-sizing:border-box">' +
        '<label style="display:block;font-size:11px;color:rgba(245,237,216,.5);margin-bottom:5px;text-transform:uppercase;letter-spacing:.06em">Amount paid (ETB)</label>' +
        '<input id="fuel-log-cost" type="number" min="0" step="0.01" placeholder="e.g. 1710" style="width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:#191926;color:#EDEDF5;font-size:14px;margin-bottom:12px;box-sizing:border-box">' +
        '<label style="display:block;font-size:11px;color:rgba(245,237,216,.5);margin-bottom:5px;text-transform:uppercase;letter-spacing:.06em">Odometer reading (km) — optional but recommended</label>' +
        '<input id="fuel-log-odo" type="number" min="0" step="0.1" placeholder="e.g. 18240" style="width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:#191926;color:#EDEDF5;font-size:14px;margin-bottom:12px;box-sizing:border-box">' +
        '<div id="fuel-log-msg" style="display:none;font-size:12.5px;font-weight:600;line-height:1.5;padding:10px 12px;border-radius:10px;margin:10px 0"></div>' +
        '<button id="fuel-log-submit" onclick="saveFuelLog()" style="width:100%;padding:13px;border:none;border-radius:12px;background:linear-gradient(135deg,#FFE9A8,#D4AF37);color:#1a1a2e;font-weight:800;font-size:14px;cursor:pointer;margin-top:8px">Save Fill-up</button>');
}

function saveFuelLog() {
    var litersEl = document.getElementById('fuel-log-liters');
    var costEl = document.getElementById('fuel-log-cost');
    var odoEl = document.getElementById('fuel-log-odo');
    var msgEl = document.getElementById('fuel-log-msg');
    var btnEl = document.getElementById('fuel-log-submit');
    var liters = litersEl ? parseFloat(litersEl.value) : NaN;
    var cost = costEl ? parseFloat(costEl.value) : NaN;
    var odo = odoEl && odoEl.value !== '' ? parseFloat(odoEl.value) : null;
    function showMsg(text, ok) {
        if (!msgEl) return;
        msgEl.style.display = 'block';
        msgEl.style.color = ok ? '#2ECC71' : '#ff6b6b';
        msgEl.style.background = ok ? 'rgba(46,204,113,.1)' : 'rgba(255,107,107,.1)';
        msgEl.style.border = '1px solid ' + (ok ? 'rgba(46,204,113,.3)' : 'rgba(255,107,107,.3)');
        msgEl.textContent = text;
    }
    if (!(liters > 0)) { showMsg('Enter how many liters you bought.', false); return; }
    if (!(cost > 0)) { showMsg('Enter how much you paid.', false); return; }
    if (btnEl) { btnEl.disabled = true; btnEl.textContent = 'Saving…'; }
    drvApi('driver_add_fuel_log', { liters: liters, cost_etb: cost, odometer_km: odo }, function () {
        showMsg('✓ Fill-up saved', true);
        loadFuelLogs();
        loadDriverKpiSummary(DRV._kpiPeriod || 'today');
        setTimeout(closeDriverModal, 700);
    }, function (err) {
        if (btnEl) { btnEl.disabled = false; btnEl.textContent = 'Save Fill-up'; }
        showMsg(err || 'Could not save', false);
    });
}

function loadFuelLogs() {
    var el = document.getElementById('fuel-log-list');
    if (!el) return;
    el.textContent = 'Loading…';
    drvApi('driver_list_fuel_logs', {}, function (d) {
        var logs = d.logs || [];
        if (!logs.length) { el.innerHTML = '<div style="text-align:center;padding:14px 0;color:rgba(245,237,216,.4)">No fill-ups logged yet.</div>'; return; }
        el.innerHTML = logs.map(function (l) {
            var date = (l.logged_at || '').slice(0, 10);
            var odoTxt = l.odometer_km != null ? ' · odo ' + parseFloat(l.odometer_km).toFixed(0) + ' km' : '';
            return '<div style="display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.05)">' +
                '<div><div style="color:#EDEDF5;font-weight:600">' + esc(date) + '</div>' +
                '<div style="font-size:11px;color:rgba(245,237,216,.45)">' + parseFloat(l.liters).toFixed(1) + ' L · ETB ' + parseFloat(l.cost_etb).toFixed(0) + odoTxt + '</div></div>' +
                '<button onclick="deleteFuelLog(' + l.id + ')" style="background:none;border:none;color:#ff6b6b;font-size:16px;cursor:pointer;padding:4px 8px">🗑</button></div>';
        }).join('');
    }, function (err) {
        el.innerHTML = '<div style="color:#ff6b6b">' + esc(err || 'Could not load') + '</div>';
    });
}

function deleteFuelLog(id) {
    if (!confirm('Remove this fuel log?')) return;
    drvApi('driver_delete_fuel_log', { id: id }, function () {
        loadFuelLogs();
        loadDriverKpiSummary(DRV._kpiPeriod || 'today');
    }, function (err) {
        showDriverToast(err || 'Could not delete', 'err');
    });
}

function selectEarningsDate(dateStr, el) {
    var strip = document.getElementById('date-strip');
    if (strip) {
        var pips = strip.querySelectorAll('.date-pip');
        for (var i = 0; i < pips.length; i++) pips[i].classList.remove('selected');
    }
    if (el) el.classList.add('selected');
    DRV.selectedEarningsDate = dateStr;

    var ma = document.getElementById('money-amount');
    var ml = document.getElementById('money-trips-lbl');
    var lbl = document.querySelector('#page-money .money-label');

    if (dateStr === _todayIso()) {
        if (lbl) lbl.textContent = "Today's Earnings";
        var cached = DRV._earningsToday;
        if (cached) {
            if (ma) ma.textContent = 'ETB ' + cached.earnings.toFixed(0);
            if (ml) ml.textContent = cached.trips + ' trip' + (cached.trips === 1 ? '' : 's') + ' completed';
        }
        return;
    }

    if (lbl) lbl.textContent = 'Earnings — ' + dateStr;
    if (ma) ma.textContent = 'ETB …';
    if (ml) ml.textContent = 'Loading…';
    drvApi('driver_get_earnings_for_date', { date: dateStr }, function (data) {
        if (DRV.selectedEarningsDate !== dateStr) return; // driver tapped elsewhere while this was in flight
        if (ma) ma.textContent = 'ETB ' + parseFloat(data.earnings || 0).toFixed(0);
        if (ml) ml.textContent = data.trips + ' trip' + (data.trips === 1 ? '' : 's') + ' completed';
    }, function (err) {
        if (DRV.selectedEarningsDate !== dateStr) return;
        if (ma) ma.textContent = 'ETB —';
        if (ml) ml.textContent = 'Could not load';
        showDriverToast(err || 'Could not load earnings for that date', 'err');
    });
}

/* ── ኢE Money top-up: info card, Etel Pay top-up (with optional receipt
   photo), manual bank/cash top-up (receipt required), and top-up history.
   Round-tripped here from driver-bundle.js, where the original Etel Pay
   flow had been hand-written directly into the built bundle in an earlier
   session and never brought back into this source file - see
   build-bundles.js's own header comment: edit sources, not the bundle,
   since a rebuild would otherwise silently wipe this out. ── */

function driverUploadReceiptPhoto(file, uploadType) {
    var uploadBase = (typeof ETEL !== 'undefined' && ETEL.api) ? ETEL.api.replace('boot.php', 'upload.php') : 'api/upload.php';
    var fd = new FormData();
    fd.append('image', file);
    fd.append('type', uploadType);
    return fetch(uploadBase, { method: 'POST', headers: { 'Authorization': 'Bearer ' + (typeof Auth !== 'undefined' ? Auth.getToken() : '') }, body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.success) return data.data.url;
            throw new Error(data && data.message ? data.message : 'Photo upload failed');
        });
}

function openEtelMoneyInfo() {
    ensureDriverModalCss();
    openDriverModal('🅴 About ኢE Money', '<p>Your ኢE Money balance is a prepaid amount you keep with ETEL — separate from ኢE Pay, which is the shared payment system used across every Etel Group product (Delivery, Ride, Taxi, Gebeya).</p>' +
        '<h4>How it\'s used</h4><ul><li>5% of every completed trip — cash or wallet — is deducted from this balance automatically.</li>' +
        '<li>It\'s allowed to go negative. Nothing is blocked if it runs low, but keeping it topped up keeps your account in good standing.</li></ul>' +
        '<h4>Topping up</h4><ul><li>Pay via ኢE Pay and submit the transaction reference, or pay by bank transfer / cash deposit and submit a photo of your receipt.</li>' +
        '<li>An admin reviews and approves every top-up before it\'s added to your balance — this is a manual check, not instant.</li>' +
        '<li>Approval is usually quick, but isn\'t guaranteed to be immediate.</li></ul>');
}

var _etelTopupState = { photoFile: null };

function openEtelMoneyTopup() {
    ensureDriverModalCss();
    openDriverModal('➕ Top Up ኢE Money', '<div style="text-align:center;padding:20px;color:rgba(245,237,216,.5)">Loading…</div>');
    _etelTopupState.photoFile = null;
    drvApi('get_etelpay_merchant', {}, function (e) {
        var merchantPhone = (e && e.phone) || '—';
        document.getElementById('drv-modal-body').innerHTML =
            '<p style="margin-bottom:14px">Send your top-up amount via ኢE Pay to:</p>' +
            '<div style="font-family:monospace;font-size:20px;font-weight:800;letter-spacing:.05em;color:#D4AF37;text-align:center;padding:14px;background:rgba(212,175,55,.08);border:1px dashed rgba(212,175,55,.4);border-radius:12px;margin-bottom:16px">' + esc(merchantPhone) + '</div>' +
            '<p style="font-size:12px;color:rgba(245,237,216,.55);margin-bottom:16px">After sending, enter the amount and the ኢE Pay transaction reference you received. Attaching a screenshot of the receipt is optional but helps the admin review it faster. An admin will review and approve it before it\'s added to your balance.</p>' +
            '<label style="display:block;font-size:11px;color:rgba(245,237,216,.5);margin-bottom:5px;text-transform:uppercase;letter-spacing:.06em">Amount (ETB)</label>' +
            '<input id="etel-topup-amount" type="number" min="1" step="0.01" placeholder="e.g. 200" style="width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:#191926;color:#EDEDF5;font-size:14px;margin-bottom:12px;box-sizing:border-box">' +
            '<label style="display:block;font-size:11px;color:rgba(245,237,216,.5);margin-bottom:5px;text-transform:uppercase;letter-spacing:.06em">Etel Pay Transaction Reference</label>' +
            '<input id="etel-topup-txref" type="text" placeholder="e.g. TX-XXXXXXXX" style="width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:#191926;color:#EDEDF5;font-size:14px;margin-bottom:12px;box-sizing:border-box">' +
            '<label style="display:block;font-size:11px;color:rgba(245,237,216,.5);margin-bottom:5px;text-transform:uppercase;letter-spacing:.06em">Receipt Photo (optional)</label>' +
            '<input type="file" id="etel-topup-photo-input" accept="image/*" capture="environment" style="display:none" onchange="_etelTopupPhotoChosen(this)">' +
            '<div id="etel-topup-photo-box" onclick="document.getElementById(\'etel-topup-photo-input\').click()" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:14px;border-radius:10px;border:1.5px dashed rgba(212,175,55,.35);background:rgba(212,175,55,.05);color:#E5C76B;font-size:12.5px;font-weight:600;cursor:pointer;margin-bottom:6px">📷 Add receipt photo</div>' +
            '<img id="etel-topup-photo-preview" style="display:none;width:100%;max-height:180px;object-fit:cover;border-radius:10px;margin-bottom:6px" alt="Receipt preview">' +
            '<div id="etel-topup-msg" style="display:none;font-size:12.5px;font-weight:600;line-height:1.5;padding:10px 12px;border-radius:10px;margin:10px 0"></div>' +
            '<button id="etel-topup-submit" onclick="submitEtelMoneyTopup()" style="width:100%;padding:13px;border:none;border-radius:12px;background:linear-gradient(135deg,#7FE8DC,#4ECDC4);color:#0a1a17;font-weight:800;font-size:14px;cursor:pointer;margin-top:8px">Submit for Approval</button>';
    }, function (err) {
        document.getElementById('drv-modal-body').innerHTML = '<div style="text-align:center;padding:20px;color:#e88">Could not load payment details: ' + esc(err) + '</div>';
    });
}

function _etelTopupPhotoChosen(input) {
    if (!input.files || !input.files[0]) return;
    _etelTopupState.photoFile = input.files[0];
    var reader = new FileReader();
    reader.onload = function (e) {
        var img = document.getElementById('etel-topup-photo-preview');
        var box = document.getElementById('etel-topup-photo-box');
        if (img) { img.src = e.target.result; img.style.display = 'block'; }
        if (box) box.textContent = '📷 Photo selected — tap to change';
    };
    reader.readAsDataURL(input.files[0]);
}

function submitEtelMoneyTopup() {
    var amtEl = document.getElementById('etel-topup-amount');
    var refEl = document.getElementById('etel-topup-txref');
    var msgEl = document.getElementById('etel-topup-msg');
    var btnEl = document.getElementById('etel-topup-submit');
    var amount = amtEl ? parseFloat(amtEl.value) : NaN;
    var txRef = refEl ? refEl.value.trim() : '';
    function showMsg(text, color, bg, border) {
        if (!msgEl) return;
        msgEl.style.display = 'block'; msgEl.style.color = color; msgEl.style.background = bg;
        msgEl.style.borderColor = border; msgEl.style.border = '1px solid ' + border; msgEl.textContent = text;
    }
    if (!amount || amount <= 0) { showMsg('Enter a valid amount', '#FF8A80', 'rgba(192,57,43,.15)', 'rgba(192,57,43,.4)'); return; }
    if (!txRef) { showMsg('Enter the Etel Pay transaction reference', '#FF8A80', 'rgba(192,57,43,.15)', 'rgba(192,57,43,.4)'); return; }
    if (btnEl) { btnEl.disabled = true; btnEl.textContent = 'Verifying with Etel Pay…'; }
    showMsg('Verifying your payment…', '#E5C76B', 'rgba(212,175,55,.1)', 'rgba(212,175,55,.35)');

    var photoUpload = _etelTopupState.photoFile
        ? driverUploadReceiptPhoto(_etelTopupState.photoFile, 'topup_receipt')
        : Promise.resolve('');

    photoUpload.then(function (receiptUrl) {
        drvApi('driver_topup_etel_money', { amount: amount, tx_ref: txRef, receipt_image_url: receiptUrl || '' }, function (res) {
            showMsg('✅ ' + (res.message || 'Submitted — pending admin approval.'), '#7FE8DC', 'rgba(78,205,196,.12)', 'rgba(78,205,196,.4)');
            if (btnEl) { btnEl.disabled = true; btnEl.textContent = 'Submitted ✓'; }
            var pendingBox = document.getElementById('etel-money-pending');
            if (pendingBox) { pendingBox.style.display = 'block'; pendingBox.textContent = '⏳ ETB ' + amount.toFixed(2) + ' top-up pending admin approval (ref: ' + txRef + ')'; }
            showDriverToast('Top-up submitted — pending admin approval', 'ok');
            setTimeout(closeDriverModal, 1800);
        }, function (err) {
            if (btnEl) { btnEl.disabled = false; btnEl.textContent = 'Submit for Approval'; }
            showMsg('⚠ ' + err, '#FF8A80', 'rgba(192,57,43,.15)', 'rgba(192,57,43,.4)');
        });
    }).catch(function (err) {
        if (btnEl) { btnEl.disabled = false; btnEl.textContent = 'Submit for Approval'; }
        showMsg('⚠ Receipt photo failed to upload: ' + (err && err.message ? err.message : 'unknown error'), '#FF8A80', 'rgba(192,57,43,.15)', 'rgba(192,57,43,.4)');
    });
}

var _manualTopupState = { photoFile: null };

function openManualTopupModal() {
    ensureDriverModalCss();
    _manualTopupState.photoFile = null;
    openDriverModal('🧾 Top Up by Bank / Cash Deposit',
        '<p style="margin-bottom:14px">Paid a different way — bank transfer or cash deposit instead of ኢE Pay? Submit the amount and a photo of your receipt or deposit slip for admin review.</p>' +
        '<label style="display:block;font-size:11px;color:rgba(245,237,216,.5);margin-bottom:5px;text-transform:uppercase;letter-spacing:.06em">Amount (ETB)</label>' +
        '<input id="manual-topup-amount" type="number" min="1" step="0.01" placeholder="e.g. 200" style="width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:#191926;color:#EDEDF5;font-size:14px;margin-bottom:12px;box-sizing:border-box">' +
        '<label style="display:block;font-size:11px;color:rgba(245,237,216,.5);margin-bottom:5px;text-transform:uppercase;letter-spacing:.06em">Note (optional)</label>' +
        '<input id="manual-topup-note" type="text" placeholder="e.g. Bank of Abyssinia deposit" style="width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:#191926;color:#EDEDF5;font-size:14px;margin-bottom:12px;box-sizing:border-box">' +
        '<label style="display:block;font-size:11px;color:rgba(245,237,216,.5);margin-bottom:5px;text-transform:uppercase;letter-spacing:.06em">Receipt Photo (required)</label>' +
        '<input type="file" id="manual-topup-photo-input" accept="image/*" capture="environment" style="display:none" onchange="_manualTopupPhotoChosen(this)">' +
        '<div id="manual-topup-photo-box" onclick="document.getElementById(\'manual-topup-photo-input\').click()" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:14px;border-radius:10px;border:1.5px dashed rgba(212,175,55,.35);background:rgba(212,175,55,.05);color:#E5C76B;font-size:12.5px;font-weight:600;cursor:pointer;margin-bottom:6px">📷 Add receipt photo</div>' +
        '<img id="manual-topup-photo-preview" style="display:none;width:100%;max-height:180px;object-fit:cover;border-radius:10px;margin-bottom:6px" alt="Receipt preview">' +
        '<div id="manual-topup-msg" style="display:none;font-size:12.5px;font-weight:600;line-height:1.5;padding:10px 12px;border-radius:10px;margin:10px 0"></div>' +
        '<button id="manual-topup-submit" onclick="submitManualTopup()" style="width:100%;padding:13px;border:none;border-radius:12px;background:linear-gradient(135deg,#FFE9A8,#D4AF37);color:#1a1a2e;font-weight:800;font-size:14px;cursor:pointer;margin-top:8px">Submit for Approval</button>');
}

function _manualTopupPhotoChosen(input) {
    if (!input.files || !input.files[0]) return;
    _manualTopupState.photoFile = input.files[0];
    var reader = new FileReader();
    reader.onload = function (e) {
        var img = document.getElementById('manual-topup-photo-preview');
        var box = document.getElementById('manual-topup-photo-box');
        if (img) { img.src = e.target.result; img.style.display = 'block'; }
        if (box) box.textContent = '📷 Photo selected — tap to change';
    };
    reader.readAsDataURL(input.files[0]);
}

function submitManualTopup() {
    var amtEl = document.getElementById('manual-topup-amount');
    var noteEl = document.getElementById('manual-topup-note');
    var msgEl = document.getElementById('manual-topup-msg');
    var btnEl = document.getElementById('manual-topup-submit');
    var amount = amtEl ? parseFloat(amtEl.value) : NaN;
    function showMsg(text, color, bg, border) {
        if (!msgEl) return;
        msgEl.style.display = 'block'; msgEl.style.color = color; msgEl.style.background = bg;
        msgEl.style.borderColor = border; msgEl.style.border = '1px solid ' + border; msgEl.textContent = text;
    }
    if (!amount || amount <= 0) { showMsg('Enter a valid amount', '#FF8A80', 'rgba(192,57,43,.15)', 'rgba(192,57,43,.4)'); return; }
    if (!_manualTopupState.photoFile) { showMsg('A receipt photo is required for a bank/cash top-up', '#FF8A80', 'rgba(192,57,43,.15)', 'rgba(192,57,43,.4)'); return; }
    if (btnEl) { btnEl.disabled = true; btnEl.textContent = 'Uploading receipt…'; }
    showMsg('Uploading your receipt…', '#E5C76B', 'rgba(212,175,55,.1)', 'rgba(212,175,55,.35)');

    driverUploadReceiptPhoto(_manualTopupState.photoFile, 'topup_receipt').then(function (receiptUrl) {
        showMsg('Submitting…', '#E5C76B', 'rgba(212,175,55,.1)', 'rgba(212,175,55,.35)');
        drvApi('driver_submit_manual_topup', { amount: amount, receipt_image_url: receiptUrl, note: noteEl ? noteEl.value : '' }, function (res) {
            showMsg('✅ ' + (res.message || 'Submitted — pending admin approval.'), '#7FE8DC', 'rgba(78,205,196,.12)', 'rgba(78,205,196,.4)');
            if (btnEl) { btnEl.disabled = true; btnEl.textContent = 'Submitted ✓'; }
            var pendingBox = document.getElementById('etel-money-pending');
            if (pendingBox) { pendingBox.style.display = 'block'; pendingBox.textContent = '⏳ ETB ' + amount.toFixed(2) + ' bank/cash top-up pending admin approval'; }
            showDriverToast('Top-up submitted — pending admin approval', 'ok');
            setTimeout(closeDriverModal, 1800);
        }, function (err) {
            if (btnEl) { btnEl.disabled = false; btnEl.textContent = 'Submit for Approval'; }
            showMsg('⚠ ' + err, '#FF8A80', 'rgba(192,57,43,.15)', 'rgba(192,57,43,.4)');
        });
    }).catch(function (err) {
        if (btnEl) { btnEl.disabled = false; btnEl.textContent = 'Submit for Approval'; }
        showMsg('⚠ Receipt photo failed to upload: ' + (err && err.message ? err.message : 'unknown error'), '#FF8A80', 'rgba(192,57,43,.15)', 'rgba(192,57,43,.4)');
    });
}

function openTopupHistoryModal() {
    ensureDriverModalCss();
    openDriverModal('🕘 ኢE Money Top-Up History', '<div style="text-align:center;padding:20px;color:rgba(245,237,216,.5)">Loading…</div>');
    drvApi('driver_get_topup_history', {}, function (data) {
        var topups = (data && data.topups) || [];
        if (!topups.length) {
            document.getElementById('drv-modal-body').innerHTML = '<div style="text-align:center;padding:20px;color:rgba(245,237,216,.5)">No top-up requests yet.</div>';
            return;
        }
        var statusColors = {
            pending: ['#E5C76B', 'rgba(212,175,55,.1)', 'rgba(212,175,55,.35)'],
            approved: ['#7FE8DC', 'rgba(78,205,196,.12)', 'rgba(78,205,196,.4)'],
            rejected: ['#FF8A80', 'rgba(192,57,43,.15)', 'rgba(192,57,43,.4)']
        };
        var sourceLabels = { etelpay: '🅴 ኢE Pay', manual_receipt: '🧾 Bank / Cash', admin_manual: '👤 Admin credit' };
        document.getElementById('drv-modal-body').innerHTML = topups.map(function (t) {
            var sc = statusColors[t.status] || statusColors.pending;
            var when = (t.created_at || '').substring(0, 16).replace('T', ' ');
            return '<div style="padding:12px 0;border-bottom:1px solid rgba(212,175,55,.12)">' +
                '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">' +
                '<div style="font-weight:800;color:#F5EDD8;font-size:14px">ETB ' + parseFloat(t.amount).toFixed(2) + '</div>' +
                '<div style="font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:999px;color:' + sc[0] + ';background:' + sc[1] + ';border:1px solid ' + sc[2] + ';text-transform:uppercase">' + esc(t.status) + '</div>' +
                '</div>' +
                '<div style="font-size:11.5px;color:rgba(245,237,216,.55);margin-top:4px">' + (sourceLabels[t.source] || t.source) + ' · ' + esc(when) + '</div>' +
                (t.note ? '<div style="font-size:12px;color:rgba(245,237,216,.7);margin-top:4px">' + esc(t.note) + '</div>' : '') +
                (t.receipt_image_url ? '<a href="' + esc(t.receipt_image_url) + '" target="_blank" style="display:inline-block;margin-top:6px;font-size:11.5px;color:#D4AF37;text-decoration:underline">📷 View receipt</a>' : '') +
                '</div>';
        }).join('');
    }, function (err) {
        document.getElementById('drv-modal-body').innerHTML = '<div style="text-align:center;padding:20px;color:#e88">Could not load history: ' + esc(err) + '</div>';
    });
}

function renderProfilePage() {
    var lbl = document.getElementById('app-version-lbl');
    if (lbl) lbl.textContent = 'App version 3.7.1 (ETEL Delivery)';
    /* Populate immediately from whatever we already have (instant paint,
       no flash of placeholder text), then always refresh from the server
       so the page is never silently stuck waiting on an earlier, unrelated
       call to have completed first. */
    if (DRV.driverProfile) updateEarningsUI(DRV.driverProfile);
    loadDriverProfile();
    /* Show/hide re-register button based on onboard status */
    var regRow = document.getElementById('prof-reg-row');
    if (regRow) {
        var onboarded = DRV.driverProfile && (DRV.driverProfile.onboarded == 1);
        regRow.style.display = onboarded ? 'none' : 'flex';
    }
}

window.selectGoalTier = function (el, trips, bonus) {
    DRV.goalTier = { trips: trips, bonus: bonus };
    document.querySelectorAll('.goal-chip').forEach(function (c) { c.classList.remove('active'); });
    el.classList.add('active');
    var gt = document.getElementById('goal-title');
    var gb = document.getElementById('goal-bonus');
    if (gt) gt.textContent = trips + ' trips goal';
    if (gb) gb.textContent = '+ETB ' + bonus.toLocaleString();
    if (DRV.driverProfile) updateEarningsUI(DRV.driverProfile);
};

function loadRideHistory() {
    var el = document.getElementById('driver-history-list');
    if (!el) return;
    el.innerHTML = '<div style="text-align:center;padding:24px;color:var(--dim)">Loading…</div>';
    // Use a simple approach: get rides from profile endpoint (can add a dedicated one later)
    drvApi('passenger_get_ride_history', {}, function (data) {
        if (!data.rides || !data.rides.length) {
            el.innerHTML = '<div class="empty-state"><div class="ei">📋</div><p>No completed rides yet</p></div>';
            return;
        }
        window._driverHistoryCache = data.rides;
        el.innerHTML = data.rides.map(function (r, idx) {
            return '<div class="history-row" onclick="showDriverRideDetail(' + idx + ')" style="cursor:pointer">' +
                '<div class="history-icon">🚗</div>' +
                '<div class="history-meta"><strong>' + esc(r.dropoff_address || '—') + '</strong><small>' + (r.assigned_at || '').substring(0,10) + (r.as_driver===false ? ' · as passenger' : '') + '</small></div>' +
                '<div class="history-fare">ETB ' + parseFloat(r.fare_final||r.fare_paid||r.fare||0).toFixed(0) + '</div>' +
                '<div style="margin-left:8px;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:rgba(212,175,55,.12);border:1px solid rgba(212,175,55,.3);color:var(--gold-lt,#D4AF37);font-size:15px;flex-shrink:0">›</div>' +
                '</div>';
        }).join('');
    }, function () { el.innerHTML = '<div style="text-align:center;padding:24px;color:var(--dim)">Could not load history</div>'; });
}

function showDriverRideDetail(idx) {
    var r = (window._driverHistoryCache || [])[idx];
    if (!r) return;
    var asDriver = r.as_driver !== false;
    var fareRows = [['Final', r.fare_final], ['Paid', r.fare_paid]].filter(function (x) { return x[1] > 0; })
        .map(function (x) { return '<div style="display:flex;justify-content:space-between;padding:4px 0;"><span style="color:#9A9AAE">' + x[0] + '</span><span style="color:#E5C76B;font-weight:700">ETB ' + parseFloat(x[1]).toFixed(2) + '</span></div>'; })
        .join('') || '<div style="color:#B8B0A0">No fare recorded</div>';
    var html =
        '<div id="ride-detail-overlay" onclick="if(event.target===this)closeDriverRideDetail()" style="position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.75);display:flex;align-items:center;justify-content:center;padding:16px;">' +
        '<div style="background:#16121a;border:1px solid rgba(212,175,55,.35);border-radius:14px;width:min(96vw,440px);max-height:85vh;overflow-y:auto;padding:20px;">' +
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">' +
        '<div style="font-size:15px;font-weight:800;color:#E5C76B">Ride #' + (r.id||'') + ' &middot; ' + (r.status||'') + '</div>' +
        '<button onclick="closeDriverRideDetail()" style="background:none;border:1px solid #333;color:#B8B0A0;border-radius:6px;padding:4px 10px;cursor:pointer;">✕</button>' +
        '</div>' +
        (asDriver && r.passenger_name ? '<div style="margin-bottom:12px;"><div style="font-size:10px;color:#B8B0A0;text-transform:uppercase;">Passenger</div><div style="color:#EDEDF5;font-weight:700">' + esc(r.passenger_name) + '</div><div style="color:#9A9AAE;font-size:12px">' + esc(r.passenger_phone||'') + '</div></div>' : '') +
        '<div style="margin-bottom:12px;"><div style="font-size:10px;color:#B8B0A0;text-transform:uppercase;margin-bottom:4px;">Route</div>' +
        '<div style="color:#EDEDF5;font-size:13px;margin-bottom:3px;">📍 ' + esc(r.pickup_address||'—') + '</div>' +
        '<div style="color:#EDEDF5;font-size:13px;">🏁 ' + esc(r.dropoff_address||'—') + '</div></div>' +
        '<div style="display:flex;gap:20px;margin-bottom:12px;">' +
        '<div><div style="font-size:10px;color:#B8B0A0;text-transform:uppercase;">Distance</div><div style="color:#EDEDF5;">' + parseFloat(r.distance_km||r.traveled_km||0).toFixed(2) + ' km</div></div>' +
        '<div><div style="font-size:10px;color:#B8B0A0;text-transform:uppercase;">Payment</div><div style="color:#EDEDF5;">' + (r.payment_method||'cash') + '</div></div>' +
        (r.rating ? '<div><div style="font-size:10px;color:#B8B0A0;text-transform:uppercase;">Rating</div><div style="color:#EDEDF5;">★ ' + r.rating + '</div></div>' : '') +
        '</div>' +
        '<div style="border-top:1px solid rgba(212,175,55,.15);padding-top:10px;margin-bottom:14px;">' + fareRows + '</div>' +
        (r.fare_total != null ? '<div style="margin-bottom:14px;background:rgba(255,255,255,.02);border:1px solid rgba(212,175,55,.12);border-radius:12px;padding:14px 16px">' + _fareBreakdownHtml(r) + '</div>' : '') +
        '<div style="display:flex;gap:10px;margin-bottom:10px">' +
            (r.pickup_lat ? '<button class="action-btn" style="flex:1;background:rgba(78,205,196,.12);border:1px solid rgba(78,205,196,.3);color:var(--teal)" onclick="navigateTo(' + parseFloat(r.pickup_lat) + ',' + parseFloat(r.pickup_lng) + ')">🗺️ Pickup Nav</button>' : '') +
            (r.dropoff_lat ? '<button class="action-btn" style="flex:1;background:rgba(96,180,255,.10);border:1px solid rgba(96,180,255,.2);color:#60B4FF" onclick="navigateTo(' + parseFloat(r.dropoff_lat) + ',' + parseFloat(r.dropoff_lng) + ')">📍 Dropoff Nav</button>' : '') +
        '</div>' +
        '<button class="action-btn" style="width:100%;background:rgba(212,175,55,.1);border:1px solid rgba(212,175,55,.3);color:var(--gold-lt)" onclick="showRoutePath(' + (r.id || 0) + ')">🧭 View Route Driven</button>' +
        '</div></div>';
    var wrap = document.createElement('div');
    wrap.id = 'ride-detail-wrap';
    wrap.innerHTML = html;
    document.body.appendChild(wrap);
}
function closeDriverRideDetail() {
    var el = document.getElementById('ride-detail-wrap');
    if (el) el.remove();
}
window.showDriverRideDetail = showDriverRideDetail;
window.closeDriverRideDetail = closeDriverRideDetail;

// Shows the actual GPS breadcrumb trail recorded during a trip - fed by
// ride_path_points, which only starts recording for rides created after
// this feature shipped, so older completed rides simply have no points
// and the map falls back to pickup/dropoff markers only. Works for both
// a completed ride (from Ride History) and a live one (path so far,
// called from the active-ride screen).
function showRoutePath(rideId) {
    if (!rideId) return;
    var overlay = document.createElement('div');
    overlay.id = 'route-path-overlay';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.85);display:flex;flex-direction:column';
    overlay.innerHTML =
        '<div style="display:flex;justify-content:space-between;align-items:center;padding:14px 16px;background:#16121a;border-bottom:1px solid rgba(212,175,55,.25)">' +
            '<div style="color:var(--gold-lt);font-weight:800;font-size:15px">Route driven</div>' +
            '<button onclick="document.getElementById(\'route-path-overlay\').remove()" style="background:none;border:1px solid #333;color:#B8B0A0;border-radius:6px;padding:4px 10px;cursor:pointer">✕</button>' +
        '</div>' +
        '<div id="route-path-map" style="flex:1"></div>' +
        '<div id="route-path-status" style="padding:10px 16px;color:#9A9AAE;font-size:12px;text-align:center;background:#16121a">Loading route…</div>';
    document.body.appendChild(overlay);

    drvApi('get_ride_path', { ride_id: rideId }, function (data) {
        var statusEl = document.getElementById('route-path-status');
        var mapDiv = document.getElementById('route-path-map');
        if (!mapDiv) return; // overlay already closed
        var points = (data.points || []).map(function (p) { return { lat: p.lat, lng: p.lng }; });
        var center = points.length ? points[Math.floor(points.length / 2)]
            : (data.pickup_lat ? { lat: data.pickup_lat, lng: data.pickup_lng } : { lat: 7.0504, lng: 38.4955 });
        var map = new google.maps.Map(mapDiv, { center: center, zoom: 14, disableDefaultUI: true, zoomControl: true });
        if (points.length > 1) {
            new google.maps.Polyline({ path: points, map: map, strokeColor: '#D4AF37', strokeWeight: 4, strokeOpacity: .9 });
            var bounds = new google.maps.LatLngBounds();
            points.forEach(function (p) { bounds.extend(p); });
            map.fitBounds(bounds, 40);
        }
        if (data.pickup_lat && data.pickup_lng) {
            new google.maps.Marker({ position: { lat: data.pickup_lat, lng: data.pickup_lng }, map: map, label: 'A', title: 'Pickup' });
        }
        if (data.dropoff_lat && data.dropoff_lng) {
            new google.maps.Marker({ position: { lat: data.dropoff_lat, lng: data.dropoff_lng }, map: map, label: 'B', title: 'Dropoff' });
        }
        if (statusEl) statusEl.textContent = points.length ? (points.length + ' recorded points') : 'No route recorded for this trip (started before route tracking was added, or driver never moved).';
    }, function (err) {
        var statusEl = document.getElementById('route-path-status');
        if (statusEl) statusEl.textContent = 'Could not load route: ' + (err || 'error');
    });
}
window.showRoutePath = showRoutePath;

/* ── Page switching (4 bottom nav tabs) ── */
function switchPage(page) {
    DRV.currentPage = page;
    document.querySelectorAll('.drv-page').forEach(function (p) { p.classList.remove('active'); });
    document.querySelectorAll('.nav-tab').forEach(function (b) { b.classList.remove('active'); });
    var pg = document.getElementById('page-' + page);
    if (pg) pg.classList.add('active');
    document.querySelectorAll('.nav-tab[data-page="' + page + '"]').forEach(function (b) { b.classList.add('active'); });
    /* #driver-map is position:fixed with z-index:1, and .drv-main (which
       holds every page) is ALSO position:fixed -- which means .drv-main
       establishes its own stacking context. Nothing inside it (no matter
       what z-index a page div sets on itself) can ever paint above a
       SIBLING of .drv-main like #driver-map; that inner z-index only
       orders .drv-main's own children against each other. So on every
       page except Requests (which needs the map visible behind its own
       placeholder gap), hide the map outright instead of trying to out-
       stack it -- that's the only way Money/Chats/Profile's content
       stops rendering underneath it. */
    var driverMapEl = document.getElementById('driver-map');
    if (driverMapEl) driverMapEl.style.display = (page === 'requests') ? '' : 'none';
    if (page === 'money') { renderMoneyPage(); loadRideHistory(); }
    if (page === 'profile') renderProfilePage();
    if (page === 'chats') renderChatsPage();
    /* Reset scroll -- otherwise a page opened while scrolled down on the
       previous tab starts mid-scroll too, cutting off its own top content
       (e.g. Profile's avatar/name/stats hidden above the fold). Reset both
       immediately AND after the next paint: the immediate reset alone can
       lose the race against the page's own entrance animation/async data
       load, which can re-settle scroll position after layout changes. */
    var main = document.getElementById('drv-main');
    if (main) main.scrollTop = 0;
    if (pg) pg.scrollTop = 0;
    requestAnimationFrame(function () {
        if (main) main.scrollTop = 0;
        if (pg) pg.scrollTop = 0;
    });
}
window.switchPage = switchPage;

function renderChatsPage() {
    var el = document.getElementById('chats-active-ride');
    if (!el) return;
    var ride = DRV.activeRide;
    if (!ride || !ride.id) {
        el.innerHTML = '<div style="background:var(--sheet2);border:1px solid var(--line);border-radius:16px;padding:20px;text-align:center;color:var(--dim);font-size:13px">' +
            '💬 No active ride — chat opens automatically once you accept a request.</div>';
        return;
    }
    var name = ride.passenger_name || (ride.passenger && ride.passenger.name) || 'Passenger';
    el.innerHTML =
        '<div class="chat-row" onclick="RideChat.open(' + ride.id + ',\'driver\')" style="background:var(--sheet2);border:1px solid rgba(212,175,55,.3);border-radius:16px;cursor:pointer">' +
        '<div class="chat-icon" style="background:rgba(212,175,55,.15)">💬</div>' +
        '<div><div class="chat-title">' + name + '</div><div class="chat-sub">Ride #' + ride.id + ' · tap to chat</div></div>' +
        '<div class="chat-arrow">›</div></div>';
}

/* ── Map / sheet toggle ── */
window._drvSheetCollapsed = false;
window.toggleDriverSheet = function() {
    _drvSheetCollapsed = !_drvSheetCollapsed;
    var driverMap  = document.getElementById('driver-map');
    var drvMain    = document.getElementById('drv-main');
    var topbar     = document.querySelector('.drv-topbar');
    var expandBtn  = document.getElementById('drv-map-expand-btn');

    if (_drvSheetCollapsed) {
        /* Expand map to full screen */
        if (driverMap) { driverMap.style.transition='height .3s ease'; driverMap.style.height='100vh'; }
        /* Fade out sheet content */
        if (drvMain)  { drvMain.style.opacity='0'; drvMain.style.pointerEvents='none'; }
        if (topbar)   { topbar.style.opacity='0'; topbar.style.pointerEvents='none'; }
        if (expandBtn){ expandBtn.innerHTML='✕'; expandBtn.title='Show panel'; expandBtn.style.bottom='20px'; }
        /* Show floating restore pill */
        var fab = document.getElementById('drv-map-fab');
        if (!fab) {
            fab = document.createElement('button');
            fab.id = 'drv-map-fab';
            fab.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);z-index:9;'+
                'background:rgba(212,175,55,.95);color:#0d0d0d;padding:10px 22px;border-radius:999px;'+
                'font-family:Outfit,sans-serif;font-size:13px;font-weight:800;cursor:pointer;border:none;'+
                'box-shadow:0 6px 20px rgba(0,0,0,.4);display:none;white-space:nowrap;';
            fab.textContent = '🗺 Full Map — tap to restore panel';
            fab.onclick = function(){ window.toggleDriverSheet(); };
            document.body.appendChild(fab);
        }
        fab.style.display='block';
    } else {
        /* Restore */
        if (driverMap) { driverMap.style.height='52vh'; }
        if (drvMain)  { drvMain.style.opacity='1'; drvMain.style.pointerEvents=''; }
        if (topbar)   { topbar.style.opacity='1'; topbar.style.pointerEvents=''; }
        if (expandBtn){ expandBtn.innerHTML='⤢'; expandBtn.title='Full map'; expandBtn.style.bottom='calc(50vh + 14px)'; }
        var fab2 = document.getElementById('drv-map-fab');
        if (fab2) fab2.style.display='none';
    }
};

/* Tabs removed — switchReqTab kept as no-op for any legacy callers */
function switchReqTab(tab) {
    DRV.currentReqTab = tab;
    if (tab === 'history') loadRideHistory();
}
window.switchReqTab = switchReqTab;

/* Legacy alias so old driver.js calls still work */
function switchDriverTab(tab) {
    if (tab === 'requests' || tab === 'active' || tab === 'history') {
        switchPage('requests');
        switchReqTab(tab);
    } else {
        switchPage(tab);
    }
}

/* ── API helper ── */
var OFFLINE_QUEUE_KEY="etel_driver_offline_queue";function _offlineQueueGet(){try{return JSON.parse(localStorage.getItem(OFFLINE_QUEUE_KEY)||"[]")}catch(e){return[]}}function _offlineQueueSave(q){try{localStorage.setItem(OFFLINE_QUEUE_KEY,JSON.stringify(q))}catch(e){}}function _offlineQueueCount(){return _offlineQueueGet().length}function _offlineQueuePush(action,params){var q=_offlineQueueGet();q.push({id:"oq"+Date.now()+Math.random().toString(36).slice(2,8),action:action,params:params,ts:Date.now()});_offlineQueueSave(q);showDriverToast("No connection - saved on this device, will send automatically once you're back online ("+q.length+" pending)","info")}var _offlineQueueFlushing=false;function _offlineQueueFlush(){if(_offlineQueueFlushing)return;var q=_offlineQueueGet();if(!q.length)return;_offlineQueueFlushing=true;var synced=0;function next(){var remaining=_offlineQueueGet();if(!remaining.length){_offlineQueueFlushing=false;if(synced>0)showDriverToast("Synced "+synced+" offline action"+(synced===1?"":"s"),"ok");return}var item=remaining[0];drvApi(item.action,item.params,function(){var q2=_offlineQueueGet();q2.shift();_offlineQueueSave(q2);synced++;next()},function(err){var isNetworkErr=(err||"").toLowerCase().indexOf("network")!==-1||(err||"").toLowerCase().indexOf("cannot reach")!==-1;if(isNetworkErr){_offlineQueueFlushing=false;return}var q2=_offlineQueueGet();q2.shift();_offlineQueueSave(q2);showDriverToast("An offline action couldn't be applied and was dropped: "+(err||"unknown error"),"err");next()})}next()}window.addEventListener("online",_offlineQueueFlush);setInterval(_offlineQueueFlush,2e4);setTimeout(_offlineQueueFlush,3e3);
/* Optional 6th arg: timeoutMs. Without it a stalled mobile connection leaves
   fetch pending for minutes, and callers that guard on an "in flight" flag
   (broadcastLocation's _locSyncInFlight) stay blocked the whole time - the
   meter silently stops sending positions. On timeout the request is aborted
   and the normal error callback runs, which clears that flag. */
function drvApi(e,t,n,r,queueOffline,timeoutMs){var i="";try{i=localStorage.getItem("etel_token")||""}catch(e){}t.action=e;var _ac=null,_tm=null;if(timeoutMs>0&&typeof AbortController!=="undefined"){_ac=new AbortController();_tm=setTimeout(function(){_ac.abort()},timeoutMs)}fetch(ETEL.api,{method:"POST",headers:{"Content-Type":"application/json",Authorization:"Bearer "+i},body:JSON.stringify(t),signal:_ac?_ac.signal:undefined}).then(function(x){if(_tm)clearTimeout(_tm);return x},function(x){if(_tm)clearTimeout(_tm);throw x}).then(function(e){var t=e.status;return e.text().then(function(e){try{return JSON.parse(e)}catch(n){throw new Error("Server "+t+": "+e.substring(0,200))}})}).then(function(e){if(e.success){if(n)n(e.data||e)}else{if(r)r(e.message||"Error")}}).catch(function(err){if(queueOffline&&!navigator.onLine){_offlineQueuePush(e,t);return}if(r)r(err&&err.message||"Network error")})}

/* ── Utils ── */
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function showDriverToast(msg, type) {
    if (!document.getElementById('driver-toast-css')) {
        var st = document.createElement('style');
        st.id = 'driver-toast-css';
        st.textContent =
            '#driver-toasts{position:fixed;top:calc(env(safe-area-inset-top,0px) + 64px);left:50%;' +
            'transform:translateX(-50%);z-index:9999;display:flex;flex-direction:column;gap:8px;' +
            'align-items:center;width:max-content;max-width:92vw;pointer-events:none}' +
            '.driver-toast{padding:11px 18px;border-radius:12px;font-size:13px;font-weight:700;' +
            'color:#fff;box-shadow:0 8px 24px rgba(0,0,0,.45);max-width:92vw;text-align:center;' +
            'animation:dtIn .25s ease}' +
            '.driver-toast.ok{background:#1f7a45}.driver-toast.err{background:#C0392B}' +
            '.driver-toast.info{background:#2D2D45;border:1px solid rgba(212,175,55,.35)}' +
            '@keyframes dtIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:none}}';
        document.head.appendChild(st);
    }
    var c = document.getElementById('driver-toasts');
    if (!c) { c = document.createElement('div'); c.id = 'driver-toasts'; document.body.appendChild(c); }
    var t = document.createElement('div');
    t.className = 'driver-toast ' + (type || 'info');
    t.textContent = msg;
    c.appendChild(t);
    setTimeout(function () { t.remove(); }, 5000);
}

/* ══════════ Content modal (Learning Hub / Announcements / Referral) ══════════
   driver.html is built as a tab system (.drv-page), not a modal-based app,
   so there was no existing overlay component for these "drill down for
   detail" screens. This is a single reusable one, styled to match. */
function ensureDriverModalCss() {
    if (document.getElementById('drv-modal-css')) return;
    var st = document.createElement('style');
    st.id = 'drv-modal-css';
    st.textContent =
        '#drv-modal-bg{position:fixed;inset:0;z-index:9998;background:rgba(5,5,10,.6);backdrop-filter:blur(3px);display:none;align-items:flex-end;justify-content:center}' +
        '#drv-modal-bg.open{display:flex}' +
        '#drv-modal-box{width:100%;max-width:520px;max-height:86vh;overflow-y:auto;background:linear-gradient(180deg,#171006,#0a0703);' +
        'border:1px solid rgba(212,175,55,.25);border-radius:20px 20px 0 0;padding:22px 22px max(22px,env(safe-area-inset-bottom,22px));' +
        'font-family:inherit;color:#F5EDD8;animation:drvModalUp .25s ease}' +
        '@keyframes drvModalUp{from{transform:translateY(24px);opacity:0}to{transform:translateY(0);opacity:1}}' +
        '#drv-modal-hdr{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;gap:12px}' +
        '#drv-modal-title{font-size:17px;font-weight:800;color:#D4AF37;line-height:1.3}' +
        '#drv-modal-close{background:rgba(245,237,216,.08);border:none;color:#F5EDD8;width:30px;height:30px;border-radius:50%;font-size:16px;cursor:pointer;flex-shrink:0}' +
        '#drv-modal-body{font-size:13.5px;line-height:1.85;color:rgba(245,237,216,.88)}' +
        '#drv-modal-body h4{color:#D4AF37;font-size:13px;margin:16px 0 6px}' +
        '#drv-modal-body ul{padding-left:20px;margin:8px 0}' +
        '.drv-ann-item{padding:12px 0;border-bottom:1px solid rgba(212,175,55,.12)}' +
        '.drv-ann-item:last-child{border-bottom:none}' +
        '.drv-ann-title{font-weight:700;font-size:13.5px;color:#F5EDD8;display:flex;align-items:center;gap:8px}' +
        '.drv-ann-unread{width:8px;height:8px;border-radius:50%;background:#D4AF37;flex-shrink:0}' +
        '.drv-ann-body{font-size:12.5px;color:rgba(245,237,216,.7);margin-top:4px;line-height:1.7}' +
        '.drv-ann-date{font-size:10px;color:rgba(245,237,216,.4);margin-top:5px}' +
        '.drv-referral-code{font-family:monospace;font-size:28px;font-weight:800;letter-spacing:.15em;color:#D4AF37;' +
        'text-align:center;padding:20px;background:rgba(212,175,55,.08);border:1px dashed rgba(212,175,55,.4);border-radius:14px;margin:14px 0}' +
        '.drv-share-btn{display:block;width:100%;padding:13px;border:none;border-radius:12px;background:linear-gradient(135deg,#FFD700,#D4AF37);' +
        'color:#1a1206;font-weight:700;font-size:13px;cursor:pointer;margin-top:10px}';
    document.head.appendChild(st);
    if (!document.getElementById('drv-modal-bg')) {
        var bg = document.createElement('div');
        bg.id = 'drv-modal-bg';
        bg.innerHTML = '<div id="drv-modal-box"><div id="drv-modal-hdr"><div id="drv-modal-title"></div>' +
            '<button id="drv-modal-close" onclick="closeDriverModal()">\u2715</button></div><div id="drv-modal-body"></div></div>';
        bg.addEventListener('click', function (e) { if (e.target === bg) closeDriverModal(); });
        document.body.appendChild(bg);
    }
}
function openDriverModal(title, bodyHtml) {
    ensureDriverModalCss();
    document.getElementById('drv-modal-title').textContent = title;
    document.getElementById('drv-modal-body').innerHTML = bodyHtml;
    document.getElementById('drv-modal-bg').classList.add('open');
}
function closeDriverModal() {
    var bg = document.getElementById('drv-modal-bg');
    if (bg) bg.classList.remove('open');
}

/* ══════════ Learning Hub - real content, written for Ethiopian drivers ══════════ */
var DRIVER_LEARNING_CONTENT = {
    safety: {
        title: '\uD83D\uDEE1\uFE0F Safety Guide',
        html: '<h4>Before you go online</h4><ul>' +
            '<li>Check your mirrors, brakes, and lights before every shift, not just once a week.</li>' +
            '<li>Keep your phone mounted where you can see it without looking away from the road.</li>' +
            '<li>Make sure your ETEL Ride profile photo is current so passengers can recognize you.</li></ul>' +
            '<h4>During a trip</h4><ul>' +
            '<li>Confirm the passenger\u2019s name before starting, especially for Street Hail pickups.</li>' +
            '<li>If a route looks unsafe (poor lighting, isolated area) at night, you can always use the Issues button to flag it \u2014 don\u2019t take a risk to avoid a bad rating.</li>' +
            '<li>Never accept a trip request while riding \u2014 pull over first.</li></ul>' +
            '<h4>If something goes wrong</h4><ul>' +
            '<li>Use the Issues button on an active trip to reach support immediately.</li>' +
            '<li>For anything urgent involving your safety, contact local authorities first, then let ETEL support know afterward.</li></ul>'
    },
    service: {
        title: '\uD83D\uDCF1 Service Tips',
        html: '<h4>What riders rate you on</h4><ul>' +
            '<li>Arriving close to the estimated pickup time.</li>' +
            '<li>A clean vehicle and a polite greeting.</li>' +
            '<li>Confirming the destination out loud before you start driving.</li></ul>' +
            '<h4>Handling cash payments</h4><ul>' +
            '<li>Try to carry small change for common fare amounts \u2014 it\u2019s the single biggest source of pickup delays.</li>' +
            '<li>Wallet payments settle instantly, so encourage riders to use \u12a2E Pay when they can.</li></ul>' +
            '<h4>Keeping your rating healthy</h4><ul>' +
            '<li>A few honest 4-star ratings won\u2019t hurt you \u2014 consistency matters more than chasing perfect scores.</li>' +
            '<li>If a rider seems unhappy mid-trip, ask directly rather than guessing \u2014 most issues are simple mix-ups about the route or fare.</li></ul>'
    },
    earnings: {
        title: '\uD83D\uDCB0 Earnings Guide',
        html: '<h4>When demand is highest</h4><ul>' +
            '<li>Early morning (6\u20138 AM) and evening (5\u20137 PM) commute windows are consistently the busiest in Hawassa.</li>' +
            '<li>Weekend evenings around Referral and University areas tend to pick up later than weekdays.</li></ul>' +
            '<h4>Bajaj vs Moto vs Car</h4><ul>' +
            '<li>Bajaj and Moto get more short-trip requests and turn over faster per hour in dense zones.</li>' +
            '<li>Car requests are less frequent but pay more per trip \u2014 better suited to longer, cross-town rides.</li></ul>' +
            '<h4>Cashing out</h4><ul>' +
            '<li>Your available balance and cash-out option are on the Money tab \u2014 payouts go straight to your \u12a2E Pay wallet.</li>' +
            '<li>There\u2019s no minimum wait \u2014 cash out as often as makes sense for you.</li></ul>'
    },
    tax: {
        title: '\uD83D\uDCCB Tax Basics for Gig Drivers',
        html: '<p style="color:rgba(245,237,216,.6);font-size:11.5px;margin-bottom:10px">This is general information, not tax advice \u2014 Ethiopian tax rules can change, and your specific situation may differ. Confirm anything important with the Ethiopian Revenue and Customs Authority (ERCA) or a local accountant.</p>' +
            '<h4>The basics</h4><ul>' +
            '<li>Income earned through ride-hailing and delivery work is generally treated as business/self-employment income in Ethiopia, not employment income \u2014 ETEL does not withhold tax on your behalf.</li>' +
            '<li>Keeping a simple running record of your earnings (the Money tab\u2019s history helps) makes filing much easier later.</li></ul>' +
            '<h4>Good practice</h4><ul>' +
            '<li>Set aside a portion of each payout rather than spending 100% of it, so tax time isn\u2019t a surprise.</li>' +
            '<li>If driving is your main income, registering as a sole proprietor with ERCA is usually the correct path \u2014 ask a local accountant what applies to your specific case.</li></ul>'
    }
};
function openLearningCard(key) {
    var c = DRIVER_LEARNING_CONTENT[key];
    if (!c) return;
    openDriverModal(c.title, c.html);
}

/* ══════════ Announcements (unifies Pro news / Notifications / Partner messages) ══════════ */
function openAnnouncements() {
    ensureDriverModalCss();
    openDriverModal('\uD83D\uDD14 Notifications & Updates', '<div style="text-align:center;padding:20px;color:rgba(245,237,216,.5)">Loading\u2026</div>');
    drvApi('driver_get_announcements', {}, function (data) {
        var list = (data && data.announcements) || [];
        if (!list.length) {
            document.getElementById('drv-modal-body').innerHTML = '<div style="text-align:center;padding:20px;color:rgba(245,237,216,.5)">No updates yet.</div>';
            return;
        }
        var html = list.map(function (a) {
            var date = new Date(a.created_at.replace(' ', 'T'));
            var dateStr = isNaN(date) ? '' : date.toLocaleDateString();
            return '<div class="drv-ann-item">' +
                '<div class="drv-ann-title">' + (a.is_read ? '' : '<span class="drv-ann-unread"></span>') + esc(a.title) + '</div>' +
                '<div class="drv-ann-body">' + esc(a.body) + '</div>' +
                '<div class="drv-ann-date">' + dateStr + '</div></div>';
        }).join('');
        document.getElementById('drv-modal-body').innerHTML = html;
        // Mark unread ones as read now that they've actually been shown
        list.forEach(function (a) {
            if (!a.is_read) drvApi('driver_mark_announcement_read', { announcement_id: a.id }, function () {}, function () {});
        });
    }, function (err) {
        document.getElementById('drv-modal-body').innerHTML = '<div style="text-align:center;padding:20px;color:#e88">Could not load updates: ' + esc(err) + '</div>';
    });
}

/* ══════════ Referral / Invite ══════════ */
function openReferral() {
    ensureDriverModalCss();
    openDriverModal('\uD83D\uDC65 Invite Friends', '<div style="text-align:center;padding:20px;color:rgba(245,237,216,.5)">Loading\u2026</div>');
    drvApi('driver_get_referral_info', {}, function (data) {
        var code = (data && data.referral_code) || '\u2014';
        var count = (data && data.referred_count) || 0;
        var shareText = 'Join me driving for ETEL Ride/Delivery in Hawassa! Use my code ' + code + ' when you sign up: https://ethelmarket.com/delivery/login?role=driver&mode=register';
        document.getElementById('drv-modal-body').innerHTML =
            '<p>Share your code with other drivers. When someone signs up with it, it\u2019ll count toward your referrals below.</p>' +
            '<div class="drv-referral-code">' + esc(code) + '</div>' +
            '<p style="text-align:center;color:rgba(245,237,216,.6);font-size:12px">' + count + ' driver' + (count === 1 ? '' : 's') + ' referred so far</p>' +
            '<button class="drv-share-btn" onclick="etelShareReferral(' + JSON.stringify(shareText) + ')">Share Code</button>';
    }, function (err) {
        document.getElementById('drv-modal-body').innerHTML = '<div style="text-align:center;padding:20px;color:#e88">Could not load your code: ' + esc(err) + '</div>';
    });
}
function etelShareReferral(text) {
    if (navigator.share) { navigator.share({ text: text }).catch(function () {}); return; }
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { showDriverToast('Copied to clipboard', 'ok'); });
    } else { showDriverToast('Share not supported on this browser', 'err'); }
}

/* ══════════ Contact Support ══════════ */
function openSupportContact() {
    ensureDriverModalCss();
    // NOTE: placeholder contact details - replace with your real support
    // line/Telegram before relying on this in production.
    openDriverModal('\uD83E\uDD1D Contact Support', '<p>Reach the ETEL support team directly:</p>' +
        '<a href="tel:+251000000000" style="display:block;padding:13px;background:rgba(46,204,113,.1);border:1px solid rgba(46,204,113,.3);border-radius:12px;color:#2ecc71;text-decoration:none;font-weight:700;margin:10px 0;text-align:center">\uD83D\uDCDE Call Support</a>' +
        '<a href="https://t.me/etelsupport" target="_blank" style="display:block;padding:13px;background:rgba(51,149,255,.1);border:1px solid rgba(51,149,255,.3);border-radius:12px;color:#3395ff;text-decoration:none;font-weight:700;margin:10px 0;text-align:center">\u2708\uFE0F Message on Telegram</a>' +
        '<p style="font-size:11px;color:rgba(245,237,216,.4);margin-top:14px">For an issue with an active trip, use the Issues button on that trip instead \u2014 it gets flagged faster.</p>');
}

/* ══════════ Language toggle (English \u21c4 Amharic) ══════════
   Real, working i18n framework - a translation dictionary plus a toggle
   that swaps every [data-i18n] tagged element's text and persists the
   choice. Only the highest-traffic strings are tagged with data-i18n so
   far (nav tabs, key headers/buttons) as a genuine working example -
   tagging every remaining string in this file is a larger follow-up,
   not done here. */
var DRIVER_I18N = {
    en: {
        nav_requests: 'Requests', nav_money: 'Earnings', nav_chats: 'Chats', nav_profile: 'Profile',
        money_label: 'Messages', profile_language: 'Language', profile_language_sub: 'English selected'
    },
    am: {
        nav_requests: '\u1325\u12eb\u1244\u12ce\u127d', nav_money: '\u1308\u1262', nav_chats: '\u1218\u120d\u12d5\u12ad\u1276\u127d', nav_profile: '\u1218\u1308\u1208\u132b',
        money_label: '\u1218\u120d\u12d5\u12ad\u1276\u127d', profile_language: '\u124b\u1295\u124b', profile_language_sub: '\u12a0\u121b\u122d\u129b \u1270\u1218\u122d\u1327\u120d'
    }
};
function applyDriverLanguage(lang) {
    var dict = DRIVER_I18N[lang] || DRIVER_I18N.en;
    document.querySelectorAll('[data-i18n]').forEach(function (el) {
        var key = el.getAttribute('data-i18n');
        if (dict[key]) el.textContent = dict[key];
    });
    try { localStorage.setItem('etel_driver_lang', lang); } catch (e) {}
}
function toggleDriverLanguage() {
    var current = 'en';
    try { current = localStorage.getItem('etel_driver_lang') || 'en'; } catch (e) {}
    var next = current === 'en' ? 'am' : 'en';
    applyDriverLanguage(next);
    showDriverToast(next === 'am' ? 'Language: \u12a0\u121b\u122d\u129b (partial - more coming)' : 'Language: English', 'info');
}
(function () {
    var saved = 'en';
    try { saved = localStorage.getItem('etel_driver_lang') || 'en'; } catch (e) {}
    if (saved !== 'en') {
        if (document.readyState !== 'loading') applyDriverLanguage(saved);
        else document.addEventListener('DOMContentLoaded', function () { applyDriverLanguage(saved); });
    }
})();



/* ── Auth on load ──
   IMPORTANT: driver.js is no longer loaded via a plain synchronous
   <script src>```> tag - the canary release-loader fetches and injects
   it asynchronously (see release-loader.js). That means by the time
   this file's own top-level code runs, the page's real
   'DOMContentLoaded' event has almost certainly ALREADY fired (it's a
   one-time event) - a plain addEventListener('DOMContentLoaded', ...)
   here would silently never run, with no error at all, since nothing
   throws - the callback just never gets invoked. This is the exact
   bug already flagged in release-loader.js's comments as needing a
   fix in this file. Same defensive pattern as the language-init IIFE
   a few lines above: run immediately if the document has already
   finished loading, otherwise fall back to the event for the rare
   case this ever runs from a genuine synchronous <script> tag again. */
function _driverStartup() {
    if (!Auth.isLoggedIn()) { window.location.href = ETEL.api.replace('api/boot.php', '') + 'login.html?redirect=driver/&role=driver'; return; }
    var u = Auth.getUser();
    if (u && u.role !== 'driver' && u.role !== 'courier' && u.role !== 'admin') {
        showDriverToast('Driver account required', 'err');
    }
    switchPage('requests');
    switchReqTab('requests');
    var banner = document.getElementById('unavail-banner');
    if (banner) banner.style.display = 'none';

    /* ── Slide-to-go-online ── */
    initSlideOnline();
    // Loads the driver's daily-goal tiers (Admin -> Pricing configured
    // trip-count tiers, real trips-today progress) - present in the
    // deployed bundle's init sequence but missing from this source file,
    // so the goal card never actually populated here even though the
    // function itself was correctly reconciled.
    loadGoalTiers();

    /* ── Slide-to-accept: delegate on requests-list ── */
    document.addEventListener('mousedown',  startSlideAccept, true);
    document.addEventListener('touchstart', startSlideAccept, { passive: false, capture: true });

    /* Auto-resume if the server still has this driver marked online -
       this page previously always booted into the offline state and
       required the manual slide gesture, no matter what the server
       thought. That meant any reload during a trip (a backgrounded/
       screen-off tab getting evicted from memory by the OS is the most
       common real-world cause) silently killed GPS tracking and the
       fare meter until the driver happened to notice their trip screen
       was gone and manually went through "Go Online" again - the fare
       itself was never actually lost server-side (see driver_update_
       location's delta-from-last-known-position logic), but tracking
       stayed frozen indefinitely instead of resuming on its own.

       That fix had its own gap though: with NO active ride (a stale
       online=1 left over from a previous session/shift that was never
       explicitly ended - killing the app, a session token expiring,
       logging back in on a fresh login screen), this same code marked
       the UI "Online" and called startGps() directly, bypassing
       goOnline()'s getCurrentPosition() call entirely. startGps()'s own
       fallback only seeds a position from localStorage's last cached
       fix (etel_driver_last_pos) if one exists on THIS device/browser -
       on a genuinely fresh login there often isn't one yet, and
       startGps()'s watchPosition() has no error callback at all, so a
       denied/unavailable permission fails completely silently. The
       driver saw "Online" and the map showed nothing for them - not
       because GPS was slow, but because nothing had ever actually
       requested a fix yet - and the only way out was toggling off then
       on again, which does go through goOnline()'s proper
       getCurrentPosition()-first path.

       Mid-trip (active_ride present), silent auto-resume stays exactly
       as before - interrupting an active, paying trip with a
       confirmation prompt would be actively harmful, and this is the
       one case the original fix above was built for. With no active
       ride, ask first instead of silently claiming to be online: only
       goOnline() (which gets a real, fresh fix with proper permission/
       error handling before ever marking online) is allowed to flip
       DRV.isOnline - declining, or a failed location grab, explicitly
       syncs the server back down to offline instead of leaving it
       stranded as online=1 with no driver actually broadcasting. */
    drvApi('driver_get_profile', {}, function (data) {
        DRV.driverProfile = data.driver;
        updateEarningsUI(data.driver);
        if (data.driver && parseInt(data.driver.online, 10) === 1) {
            if (data.active_ride) {
                DRV.isOnline = true;
                setOnlineUI(true);
                startGps();
                startPolling();
                _applyActiveRideUpdate(data.active_ride);
                switchReqTab('active');
                if (!DRV.sseSource || DRV.sseSource.readyState === EventSource.CLOSED) {
                    _startSSE();
                }
            } else if (confirm('You were left online from a previous session. Go back online now?')) {
                goOnline();
            } else {
                drvApi('driver_go_offline', {}, function () {
                    DRV.isOnline = false;
                    setOnlineUI(false);
                }, function () { setOnlineUI(false); });
            }
        }
    }, null);
}
if (document.readyState !== 'loading') {
    _driverStartup();
} else {
    document.addEventListener('DOMContentLoaded', _driverStartup);
}

function initSlideOnline() {
    var track = document.getElementById('slide-online-track');
    var thumb = document.getElementById('slide-online-thumb');
    var fill  = document.getElementById('slide-online-fill');
    if (!track || !thumb) return;
    var dragging = false, startX = 0, progress = 0;

    function getClientX(e) { return e.touches ? e.touches[0].clientX : e.clientX; }

    function onStart(e) {
        if (DRV.isOnline) return;
        dragging = true;
        startX = getClientX(e);
        progress = 0;
        e.preventDefault();
    }
    function onMove(e) {
        if (!dragging) return;
        var maxX = track.offsetWidth - thumb.offsetWidth - 12;
        if (maxX <= 0) return;
        var dx = startX - getClientX(e); // positive when sliding LEFT (RTL)
        dx = Math.max(0, Math.min(dx, maxX));
        progress = dx / maxX;
        thumb.style.transform = 'translateX(' + (-dx) + 'px)';
        if (fill) fill.style.transform = 'scaleX(' + progress + ')';
        e.preventDefault();
    }
    function onEnd() {
        if (!dragging) return;
        dragging = false;
        if (progress >= 0.75) {
            var maxX = track.offsetWidth - thumb.offsetWidth - 12;
            thumb.style.transform = 'translateX(' + (-maxX) + 'px)';
            if (fill) fill.style.transform = 'scaleX(1)';
            setTimeout(function () {
                thumb.style.transform = '';
                if (fill) fill.style.transform = '';
                goOnline();
            }, 180);
        } else {
            thumb.style.transition = 'transform .25s';
            thumb.style.transform = 'translateX(0)';
            if (fill) fill.style.transform = 'scaleX(0)';
            progress = 0;
            setTimeout(function () { thumb.style.transition = ''; }, 260);
        }
    }

    /* Bind to the whole track so user doesn't have to hit the small thumb */
    track.addEventListener('mousedown', onStart);
    track.addEventListener('touchstart', onStart, { passive: false });
    document.addEventListener('mousemove', onMove);
    document.addEventListener('touchmove', onMove, { passive: false });
    document.addEventListener('mouseup', onEnd);
    document.addEventListener('touchend', onEnd);
}

/* Slide-to-accept: handles dynamically-rendered request cards */
var _slideAcceptState = null;
function startSlideAccept(e) {
    var thumb = e.target && e.target.closest ? e.target.closest('.slide-accept-thumb') : null;
    if (!thumb) return;
    var track = thumb.closest('.slide-accept-track');
    if (!track) return;
    var rideId = parseInt(thumb.getAttribute('data-ride-id') || track.getAttribute('data-ride-id'), 10);
    if (!rideId) return;
    var fill = track.querySelector('.slide-accept-fill');
    var maxX = track.offsetWidth - thumb.offsetWidth - 10;
    var startX = (e.touches ? e.touches[0].clientX : e.clientX);
    var currentX = 0;
    e.preventDefault();

    function onMove(ev) {
        var clientX = (ev.touches ? ev.touches[0].clientX : ev.clientX);
        var x = startX - clientX; // Invert drag math for RTL
        x = Math.max(0, Math.min(x, maxX));
        currentX = x;
        thumb.style.transform = 'translateX(' + (-x) + 'px)'; // Translate left
        if (fill) fill.style.transform = 'scaleX(' + (x / maxX) + ')'; // Scale left (origin is right)
        ev.preventDefault();
    }
    function onEnd() {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('touchmove', onMove);
        document.removeEventListener('mouseup', onEnd);
        document.removeEventListener('touchend', onEnd);
        if (currentX >= maxX * 0.75) {
            thumb.style.transform = 'translateX(' + (-maxX) + 'px)';
            if (fill) fill.style.transform = 'scaleX(1)';
            track.style.opacity = '.5';
            track.style.pointerEvents = 'none';
            acceptRide(rideId);
        } else {
            thumb.style.transform = 'translateX(0)';
            if (fill) fill.style.transform = 'scaleX(0)';
            currentX = 0;
        }
    }
    document.addEventListener('mousemove', onMove);
    document.addEventListener('touchmove', onMove, { passive: false });
    document.addEventListener('mouseup', onEnd);
    document.addEventListener('touchend', onEnd);
}

function doLogout() { Auth.logout(); } // Auth.logout() already redirects correctly - see core.js

function toggleSidebar() {
    var collapsed = document.body.classList.toggle('sidebar-collapsed');
    try { localStorage.setItem('etel_driver_sidebar_collapsed', collapsed ? '1' : '0'); } catch (e) {}
}
window.toggleSidebar = toggleSidebar;

/* -- MAP WATCHDOG: detect + recover blank map (see ride-fix.js) -- */
setTimeout(function () {
    var el = document.getElementById('driver-map');
    if (!el) return;
    var w = el.offsetWidth, h = el.offsetHeight;
    console.log('[ETEL][map] driver-map size: ' + w + 'x' + h + ', map=' + (DRV.map ? 'created' : 'MISSING'));
    if (!DRV.map) return;
    // .gm-style exists as soon as Google has built the map; raster tiles (no mapId) can still be
    // downloading at the 3 s check on a slow connection, which used to trigger a false "blank map" banner.
    var hasTiles = el.querySelector('img[src*="googleapis"], img[src*="gstatic"], canvas, .gm-style');
    if (w === 0 || h === 0 || !hasTiles) {
        console.warn('[ETEL][map] blank map detected - forcing resize + recenter');
        try {
            var b = document.createElement('div');
            b.style.cssText = 'position:fixed;bottom:8px;left:8px;z-index:9999;background:rgba(0,0,0,.85);color:#E5C76B;font:11px monospace;padding:6px 10px;border-radius:8px;pointer-events:none';
            b.textContent = '[map] ' + w + 'x' + h + (hasTiles ? ' tiles:yes' : ' tiles:NO') + ' recovering...';
            document.body.appendChild(b);
            setTimeout(function(){ b.remove(); }, 12000);
        } catch (e) {}
        if (w === 0 || h === 0) {
            el.style.cssText += ';position:fixed!important;top:0;left:0;right:0;bottom:0;width:100vw;height:100vh;z-index:0;';
        }
        try {
            var c = DRV.map.getCenter();
            google.maps.event.trigger(DRV.map, 'resize');
            DRV.map.setCenter(c || new google.maps.LatLng(7.0621, 38.4761));
            DRV.map.setZoom(DRV.map.getZoom() || 14);
        } catch (e) { console.warn('[ETEL][map] recover failed:', e); }
    }
}, 3000);

/* ════════════ STREET HAIL — link a flagged-down customer ════════════ */
var HAIL = { km: 0, scanStream: null, scanTimer: null };

window.openHailModal = function () {
    if (document.getElementById('hail-modal')) { document.getElementById('hail-modal').style.display = 'flex'; return; }
    var m = document.createElement('div');
    m.id = 'hail-modal';
    m.style.cssText = 'position:fixed;inset:0;top:0;left:0;right:0;bottom:0;z-index:500;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.7);padding:18px';
    m.innerHTML =
      '<div style="background:#12121d;border:1px solid rgba(212,175,55,.25);border-radius:18px;padding:20px;width:100%;max-width:380px">' +
        '<div style="font-size:16px;font-weight:800;color:#E5C76B;margin-bottom:4px">🚖 Street Hail</div>' +
        '<div style="font-size:12px;color:#9A9AAE;margin-bottom:14px">Customer flagged you down? Link their ETEL account, agree the km, then Start.</div>' +
        '<input id="hail-phone" type="tel" placeholder="Phone, EtelID (EG-...), FIN or card no." style="width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#191926;color:#EDEDF5;font-size:14px;margin-bottom:8px;box-sizing:border-box"/>' +
        '<button onclick="hailScanQR()" style="width:100%;padding:10px;background:#191926;border:1px dashed rgba(212,175,55,.4);color:#E5C76B;border-radius:10px;font-size:13px;cursor:pointer;margin-bottom:10px">📷 Scan EtelID QR instead</button>' +
        '<div id="hail-scan-box" style="display:none;margin-bottom:10px"><video id="hail-video" playsinline style="width:100%;border-radius:10px"></video></div>' +
        '<div style="font-size:12px;color:#9A9AAE;margin-bottom:6px">Agreed distance <span style="opacity:.7">(ETB 120 + 30/km)</span></div>' +
        '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:6px">' +
          '<button class="hail-km" onclick="hailSetKm(2,this)" style="padding:8px 14px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#191926;color:#EDEDF5;cursor:pointer">2 km</button>' +
          '<button class="hail-km" onclick="hailSetKm(5,this)" style="padding:8px 14px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#191926;color:#EDEDF5;cursor:pointer">5 km</button>' +
          '<button class="hail-km" onclick="hailSetKm(10,this)" style="padding:8px 14px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#191926;color:#EDEDF5;cursor:pointer">10 km</button>' +
          '<input id="hail-km-input" type="number" min="1" max="100" step="0.5" placeholder="km" oninput="hailSetKm(this.value,null)" style="width:70px;padding:8px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#191926;color:#EDEDF5"/>' +
          '<button class="hail-km" id="hail-open-btn" onclick="hailSetOpen(this)" style="padding:8px 14px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#191926;color:#EDEDF5;cursor:pointer;white-space:nowrap">&#x1F4DF; Open</button>' +
        '</div>' +
        '<div id="hail-fare" style="font-size:14px;font-weight:800;color:#E5C76B;margin-bottom:8px">&nbsp;</div>' +
        '<div id="hail-msg" style="display:none;font-size:13px;font-weight:700;line-height:1.5;color:#FF8A80;background:rgba(192,57,43,.15);border:1px solid rgba(192,57,43,.4);border-radius:10px;padding:10px 12px;margin-bottom:12px"></div>' +
        '<div style="display:flex;gap:10px">' +
          '<button onclick="closeHailModal()" style="flex:1;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.15);background:transparent;color:#9A9AAE;cursor:pointer">Cancel</button>' +
          '<button id="hail-go" onclick="hailConfirm()" style="flex:2;padding:12px;border-radius:10px;border:none;background:linear-gradient(135deg,#FFE9A8,#D4AF37);color:#1a1a2e;font-weight:800;cursor:pointer">✅ Link Customer</button>' +
        '</div>' +
      '</div>';
    m.addEventListener('click', function (e) { if (e.target === m) closeHailModal(); });
    document.body.appendChild(m);
};

window.hailSetKm = function (km, btn) {
    km = parseFloat(km);
    HAIL.km = (km && km > 0) ? Math.min(100, km) : 0;
    HAIL.open = false;
    var ob = document.getElementById('hail-open-btn');
    if (ob) { ob.style.borderColor = 'rgba(255,255,255,.12)'; ob.style.color = '#EDEDF5'; }
    document.querySelectorAll('.hail-km').forEach(function (b) { b.style.borderColor = 'rgba(255,255,255,.12)'; b.style.color = '#EDEDF5'; });
    if (btn) { btn.style.borderColor = '#D4AF37'; btn.style.color = '#E5C76B'; var ki = document.getElementById('hail-km-input'); if (ki) ki.value = ''; }
    var f = document.getElementById('hail-fare');
    if (f) f.textContent = HAIL.km ? ('Fare: ETB ' + (120 + 30 * HAIL.km).toFixed(2) + ' — ' + HAIL.km + ' km') : '\u00a0';
};

window.hailSetOpen = function (btn) {
    HAIL.km = 0; HAIL.open = true;
    document.querySelectorAll('.hail-km').forEach(function (b) { b.style.borderColor = 'rgba(255,255,255,.12)'; b.style.color = '#EDEDF5'; });
    btn.style.borderColor = '#D4AF37'; btn.style.color = '#E5C76B';
    var ki = document.getElementById('hail-km-input'); if (ki) ki.value = '';
    var f = document.getElementById('hail-fare');
    if (f) f.textContent = 'Open trip: GPS meter (ETB 120 + 30/km as you drive)';
};

window.hailScanQR = function () {
    if (!('BarcodeDetector' in window)) { showDriverToast('QR scan not supported here — type the phone number', 'err'); return; }
    var box = document.getElementById('hail-scan-box'), video = document.getElementById('hail-video');
    box.style.display = 'block';
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (stream) {
        HAIL.scanStream = stream;
        video.srcObject = stream;
        video.play();
        var det = new BarcodeDetector({ formats: ['qr_code'] });
        HAIL.scanTimer = setInterval(function () {
            det.detect(video).then(function (codes) {
                if (codes.length) {
                    var txt = codes[0].rawValue || '';
                    /* Pass raw QR text - server resolves verify URLs,
                       EG-... ids, FIN, card numbers, and phones */
                    document.getElementById('hail-phone').value = txt;
                    hailStopScan();
                    showDriverToast('QR read ✅', 'ok');
                }
            }).catch(function () {});
        }, 400);
    }).catch(function (e) { showDriverToast(cameraAccessMessage(e), 'err'); box.style.display = 'none'; });
};

function hailStopScan() {
    if (HAIL.scanTimer) { clearInterval(HAIL.scanTimer); HAIL.scanTimer = null; }
    if (HAIL.scanStream) { HAIL.scanStream.getTracks().forEach(function (t) { t.stop(); }); HAIL.scanStream = null; }
    var box = document.getElementById('hail-scan-box'); if (box) box.style.display = 'none';
}

window.closeHailModal = function () {
    hailStopScan();
    var m = document.getElementById('hail-modal'); if (m) m.style.display = 'none';
};

window.hailConfirm = function () {
  try {
    var phone = (document.getElementById('hail-phone').value || '').trim();
    var msgEl = document.getElementById('hail-msg');
    function say(txt, color, bg, border) {
        if (!msgEl) return;
        msgEl.style.display = 'block';
        msgEl.style.color = color; msgEl.style.background = bg; msgEl.style.borderColor = border;
        msgEl.textContent = txt;
    }
    if (!phone) { say('Enter the customer phone or scan their QR', '#FF8A80', 'rgba(192,57,43,.15)', 'rgba(192,57,43,.4)'); return; }
    if (!HAIL.km && !HAIL.open) { say('Set the agreed km or tap Open for a GPS meter trip', '#FF8A80', 'rgba(192,57,43,.15)', 'rgba(192,57,43,.4)'); return; }
    // Without a GPS fix at the moment of linking, pickup_lat/pickup_lng
    // save as 0/0, and Pickup Nav later fails with "No coordinates" - the
    // fix is catching this here, not hiding the button afterward, since
    // the coordinate is genuinely useful (where the driver actually
    // linked the customer) whenever a fix is actually available, which is
    // nearly always the case except right at driver app startup.
    if (!DRV.currentLat || !DRV.currentLng) {
        say('Waiting for GPS location - try again in a moment', '#FF8A80', 'rgba(192,57,43,.15)', 'rgba(192,57,43,.4)');
        return;
    }
    var btn = document.getElementById('hail-go');
    say('Linking\u2026 please wait', '#E5C76B', 'rgba(212,175,55,.1)', 'rgba(212,175,55,.35)');
    btn.disabled = true; btn.textContent = 'Linking\u2026';
    var hailPayload = { phone: phone, km: HAIL.km, lat: DRV.currentLat, lng: DRV.currentLng };
    if (HAIL.open) hailPayload.open = 1;
    var hailAttempt = 0;
    function doHail() {
        hailAttempt++;
        drvApi('driver_hail_customer', hailPayload, function (data) {
            btn.disabled = false; btn.textContent = '✅ Link Customer';
            say('Linked: ' + (data.passenger_name || 'customer') + ' ✓', '#2ECC71', 'rgba(46,204,113,.12)', 'rgba(46,204,113,.4)');
            setTimeout(function () {
                closeHailModal();
                DRV.activeRide = data;
                renderActiveRide(data);
                switchDriverTab('active');
                showDriverToast('Customer linked: ' + (data.passenger_name || '') + ' — press Start when moving 🚦', 'ok');
            }, 700);
        }, function (msg) {
            if (msg === 'Network error' && hailAttempt < 3) {
                say('Weak signal — retrying (' + (hailAttempt + 1) + '/3)…', '#E5C76B', 'rgba(212,175,55,.1)', 'rgba(212,175,55,.35)');
                setTimeout(doHail, 2000);
                return;
            }
            btn.disabled = false; btn.textContent = '✅ Link Customer';
            var friendly = msg === 'Network error' ? 'No connection — check mobile data and try again' : msg;
            say('⚠ ' + friendly, '#FF8A80', 'rgba(192,57,43,.15)', 'rgba(192,57,43,.4)');
            showDriverToast(friendly, 'err');
        });
    }
    doHail();
  } catch (e) {
    alert('Street Hail error: ' + e.message);
  }
};

/* ════════════════════════════════════════════════════════════════
   PHASE 3 — Driver Registration Wizard
   Steps: 1=vehicle type, 2=vehicle details, 3=license, 4=selfie, 5=done
   ════════════════════════════════════════════════════════════════ */
var REG = {
    step: 1,
    totalSteps: 5,
    data: {
        vtype: 'bajaj',
        make: '',
        color: 'Black',
        year: '',
        plate: '',
        licenseName: '',
        licenseNum: '',
        licenseDob: '',
        selfieFile: null,
        licensePhotoFile: null,
    },
    vtypeIcons: { bajaj: '🛺', moto: '🏍️', car: '🚗', minibus: '🚐' },
    vtypeTitles: { bajaj: 'Bajaj / Rickshaw', moto: 'Motorcycle', car: 'Car', minibus: 'Minibus' },
    // Missing here (only in the bundle) until this fix - _renderViVtypeGrid
    // (Update Vehicle Info panel) reads REG.vtypeImages[e] directly, and an
    // undefined object there is exactly what threw "Cannot read properties
    // of undefined (reading 'Bajaj')" the moment that panel tried to render.
    vtypeImages: {
        Bajaj: '../img/vehicles/bajaj.png', bajaj: '../img/vehicles/bajaj.png',
        Motorcycle: '../img/vehicles/moto.png', moto: '../img/vehicles/moto.png',
        Car: '../img/vehicles/car.png', car: '../img/vehicles/car.png',
        Minibus: '../img/vehicles/minibus.png', minibus: '../img/vehicles/minibus.png',
    },
};

// Best-guess icon/display-title for a vehicle type slug we don't already
// have a hardcoded label for (e.g. a new type an admin just added in
// Pricing, like "qute" or "three_wheeler"). Same substring-matching
// approach as driverIconForVehicleType() in js/ride.js, kept independent
// since this file doesn't share scope with that one.
function regGuessIcon(slug) {
    var s = (slug || '').toLowerCase();
    if (s.indexOf('moto') !== -1 || s.indexOf('bike') !== -1 || s.indexOf('scoot') !== -1) return '🛵';
    if (s.indexOf('mini') !== -1 || s.indexOf('van') !== -1 || s.indexOf('bus') !== -1) return '🚐';
    if (s.indexOf('car') !== -1 || s.indexOf('sedan') !== -1) return '🚗';
    if (s.indexOf('bajaj') !== -1 || s.indexOf('rickshaw') !== -1 || s.indexOf('tuk') !== -1 || s.indexOf('three') !== -1) return '🛺';
    return '🚙';
}
function regGuessTitle(slug) {
    return (slug || '').split('_').map(function (w) { return w.charAt(0).toUpperCase() + w.slice(1); }).join(' ');
}

// Fetches the live list of active vehicle types (Admin -> Pricing ->
// fare rules table) and builds both the "Choose a service" icon grid and
// the step-2 dropdown from it, instead of the old hardcoded 4 options -
// a type added in the admin panel (like "Qute") now actually shows up
// here for drivers to pick, without needing another app update.
function loadVehicleTypeOptions(cb) {
    var grid = document.getElementById('reg-option-grid');
    var sel = document.getElementById('reg-vtype-select');
    drvApi('ride_get_vehicle_types', {}, function (data) {
        var types = (data && data.vehicle_types && data.vehicle_types.length) ? data.vehicle_types : ['bajaj', 'moto', 'car', 'minibus'];
        types.forEach(function (slug) {
            if (!REG.vtypeIcons[slug])  REG.vtypeIcons[slug]  = regGuessIcon(slug);
            if (!REG.vtypeTitles[slug]) REG.vtypeTitles[slug] = regGuessTitle(slug);
        });
        var currentVtype = REG.data.vtype || types[0];
        if (grid) {
            grid.innerHTML = types.map(function (slug) {
                var selected = slug === currentVtype ? ' selected' : '';
                return '<div class="reg-option' + selected + '" data-vtype="' + slug + '" onclick="regSelectVtype(this)">' +
                    '<div class="ro-icon">' + REG.vtypeIcons[slug] + '</div>' +
                    '<div class="ro-label">' + REG.vtypeTitles[slug] + '</div>' +
                    '</div>';
            }).join('');
        }
        if (sel) {
            sel.innerHTML = types.map(function (slug) {
                return '<option value="' + slug + '">' + REG.vtypeTitles[slug] + '</option>';
            }).join('');
            sel.value = currentVtype;
        }
        regUpdateHero(currentVtype);
        if (cb) cb();
    }, function () {
        // Network/auth error - fall back to the original 4 so onboarding
        // never gets fully stuck even if this call fails.
        var fallback = ['bajaj', 'moto', 'car', 'minibus'];
        if (grid) {
            grid.innerHTML = fallback.map(function (slug, i) {
                return '<div class="reg-option' + (i === 0 ? ' selected' : '') + '" data-vtype="' + slug + '" onclick="regSelectVtype(this)">' +
                    '<div class="ro-icon">' + REG.vtypeIcons[slug] + '</div>' +
                    '<div class="ro-label">' + REG.vtypeTitles[slug] + '</div>' +
                    '</div>';
            }).join('');
        }
        if (sel) {
            sel.innerHTML = fallback.map(function (slug) { return '<option value="' + slug + '">' + REG.vtypeTitles[slug] + '</option>'; }).join('');
        }
        if (cb) cb();
    });
}

/* ── Check if driver needs onboarding, show wizard if so ── */
function checkOnboarding(driverData) {
    if (!driverData) return;
    /* If not onboarded (or first ever load) open wizard */
    if (!driverData.onboarded || driverData.onboarded === '0' || driverData.onboarded === 0) {
        /* Pre-fill existing data */
        if (driverData.vehicle_type)  REG.data.vtype       = driverData.vehicle_type;
        if (driverData.vehicle_make)  REG.data.make        = driverData.vehicle_make;
        if (driverData.vehicle_color) REG.data.color       = driverData.vehicle_color;
        if (driverData.vehicle_year)  REG.data.year        = driverData.vehicle_year;
        if (driverData.vehicle_plate) REG.data.plate       = driverData.vehicle_plate;
        if (driverData.license_name)  REG.data.licenseName = driverData.license_name;
        if (driverData.license_number)REG.data.licenseNum  = driverData.license_num;
        if (driverData.license_dob)   REG.data.licenseDob  = driverData.license_dob;
        openReg();
    }
}

function openReg() {
    regBuildProgress();
    regGoStep(1);
    var overlay = document.getElementById('reg-overlay');
    if (overlay) overlay.classList.add('open');
    loadVehicleTypeOptions(function () {
        regUpdateHero(REG.data.vtype);
    });
}

function closeReg() {
    var overlay = document.getElementById('reg-overlay');
    if (overlay) overlay.classList.remove('open');
}

function regBuildProgress() {
    var el = document.getElementById('reg-progress');
    if (!el) return;
    var html = '';
    for (var i = 1; i <= REG.totalSteps; i++) html += '<div class="reg-dot" id="reg-dot-' + i + '"></div>';
    el.innerHTML = html;
    regUpdateProgress();
}

function regUpdateProgress() {
    for (var i = 1; i <= REG.totalSteps; i++) {
        var dot = document.getElementById('reg-dot-' + i);
        if (!dot) continue;
        dot.className = 'reg-dot' + (i < REG.step ? ' done' : i === REG.step ? ' active' : '');
    }
    var titles = {
        1: ['Choose service',   'What will you drive?'],
        2: ['Vehicle details',  'Tell us about your vehicle'],
        3: ['Driver license',   'Verification information'],
        4: ['Take a selfie',    'Identity verification'],
        5: ['Almost ready!',    'Registration complete'],
    };
    var t = titles[REG.step] || ['Registration', ''];
    var tt = document.getElementById('reg-topbar-title');
    var ts = document.getElementById('reg-topbar-sub');
    if (tt) tt.textContent = t[0];
    if (ts) ts.textContent = t[1];
    /* Back button: hidden on step 1 and 5 */
    var back = document.getElementById('reg-back-btn');
    if (back) back.style.visibility = (REG.step === 1 || REG.step === 5) ? 'hidden' : 'visible';
    /* Next button label */
    var btn = document.getElementById('reg-next-btn');
    if (!btn) return;
    var labels = { 1:'Continue', 2:'Continue', 3:'Continue', 4:'Next', 5:'Start Driving' };
    btn.textContent = labels[REG.step] || 'Continue';
    btn.className = 'reg-btn' + (REG.step === 5 ? '' : '');
}

function regGoStep(n) {
    document.querySelectorAll('.reg-step').forEach(function (s) { s.classList.remove('active'); });
    var el = document.getElementById('reg-step-' + n);
    if (el) el.classList.add('active');
    REG.step = n;
    regUpdateProgress();
    /* Pre-fill fields when entering step */
    if (n === 2) regPrefillVehicle();
    if (n === 5) regShowSummary();
    /* Scroll to top */
    var body = document.querySelector('.reg-body');
    if (body) body.scrollTop = 0;
}

window.regNext = function () {
    if (REG.step === 1) {
        regGoStep(2);
    } else if (REG.step === 2) {
        if (!regValidateVehicle()) return;
        regGoStep(3);
    } else if (REG.step === 3) {
        if (!regValidateLicense()) return;
        regGoStep(4);
    } else if (REG.step === 4) {
        if (!REG.data.selfieFile) { showDriverToast('Take a selfie to verify your identity', 'err'); return; }
        regSaveAndFinish();
    } else if (REG.step === 5) {
        closeReg();
    }
};

window.regBack = function () {
    if (REG.step > 1 && REG.step < 5) regGoStep(REG.step - 1);
};

/* ── Step 1: vehicle type ── */
window.regSelectVtype = function (el) {
    document.querySelectorAll('.reg-option').forEach(function (o) { o.classList.remove('selected'); });
    el.classList.add('selected');
    var vtype = el.getAttribute('data-vtype');
    REG.data.vtype = vtype;
    regUpdateHero(vtype);
    /* Sync select on step 2 */
    var sel = document.getElementById('reg-vtype-select');
    if (sel) sel.value = vtype;
};

function regUpdateHero(vtype) {
    var hero = document.getElementById('reg-hero');
    if (!hero) return;
    if (REG.vtypeImages[vtype]) {
        hero.innerHTML = '<img class="reg-hero-img" src="' + REG.vtypeImages[vtype] + '" alt="">';
    } else {
        hero.textContent = REG.vtypeIcons[vtype] || '🚗';
    }
}

/* ── Step 2: vehicle details ── */
function regPrefillVehicle() {
    var sel = document.getElementById('reg-vtype-select'); if (sel) sel.value = REG.data.vtype;
    var make = document.getElementById('reg-make'); if (make && REG.data.make) make.value = REG.data.make;
    var year = document.getElementById('reg-year'); if (year && REG.data.year) year.value = REG.data.year;
    var plate = document.getElementById('reg-plate'); if (plate && REG.data.plate) plate.value = REG.data.plate;
    var ct = document.getElementById('reg-color-text'); if (ct && REG.data.color) ct.value = REG.data.color;
    /* Select matching swatch */
    document.querySelectorAll('.reg-color').forEach(function (c) {
        c.classList.toggle('selected', c.getAttribute('data-color') === REG.data.color);
    });
    regCheckField('make'); regCheckField('year'); regCheckField('plate');
}

window.regVtypeChanged = function (val) {
    REG.data.vtype = val;
    regUpdateHero(val);
    /* Sync step-1 options */
    document.querySelectorAll('.reg-option').forEach(function (o) {
        o.classList.toggle('selected', o.getAttribute('data-vtype') === val);
    });
};

window.regSelectColor = function (el) {
    document.querySelectorAll('.reg-color').forEach(function (c) { c.classList.remove('selected'); });
    el.classList.add('selected');
    REG.data.color = el.getAttribute('data-color');
    var ct = document.getElementById('reg-color-text'); if (ct) ct.value = REG.data.color;
    regCheckField('color');
};

window.regColorText = function (val) {
    REG.data.color = val;
    /* Deselect swatches if typing custom */
    document.querySelectorAll('.reg-color').forEach(function (c) { c.classList.remove('selected'); });
    regCheckField('color');
};

window.regCheckField = function (field) {
    var labelMap = { make:'lbl-make', year:'lbl-year', plate:'lbl-plate', color:'lbl-color', lname:'lbl-lname', lnum:'lbl-lnum', ldob:'lbl-ldob' };
    var inputMap = { make:'reg-make', year:'reg-year', plate:'reg-plate', lname:'reg-license-name', lnum:'reg-license-num', ldob:'reg-license-dob' };
    var lbl = document.getElementById(labelMap[field]);
    var inp = document.getElementById(inputMap[field]);
    if (!lbl) return;
    var val = inp ? inp.value.trim() : REG.data[field];
    if (val) {
        lbl.className = 'reg-field-label done';
        if (field === 'make')  REG.data.make  = val;
        if (field === 'year')  REG.data.year  = val;
        if (field === 'plate') REG.data.plate = val.toUpperCase();
        if (field === 'lname') REG.data.licenseName = val;
        if (field === 'lnum')  REG.data.licenseNum  = val;
        if (field === 'ldob')  REG.data.licenseDob  = val;
    } else {
        lbl.className = 'reg-field-label';
    }
};

function regValidateVehicle() {
    REG.data.make  = (document.getElementById('reg-make')  || {value:''}).value.trim();
    REG.data.year  = (document.getElementById('reg-year')  || {value:''}).value.trim();
    REG.data.plate = ((document.getElementById('reg-plate') || {value:''}).value.trim()).toUpperCase();
    REG.data.vtype = (document.getElementById('reg-vtype-select') || {value:'bajaj'}).value;
    var ct = (document.getElementById('reg-color-text') || {value:''}).value.trim();
    if (ct) REG.data.color = ct;
    if (!REG.data.plate) { showDriverToast('Enter the vehicle plate number', 'err'); return false; }
    return true;
}

/* ── Step 3: license ── */
function regValidateLicense() {
    REG.data.licenseName = (document.getElementById('reg-license-name') || {value:''}).value.trim();
    REG.data.licenseNum  = (document.getElementById('reg-license-num')  || {value:''}).value.trim();
    REG.data.licenseDob  = (document.getElementById('reg-license-dob')  || {value:''}).value.trim();
    if (!REG.data.licenseName) { showDriverToast('Enter the name on your license', 'err'); return false; }
    if (!REG.data.licenseNum)  { showDriverToast('Enter your license number', 'err'); return false; }
    if (!REG.data.licenseDob)  { showDriverToast('Enter your date of birth as shown on your license', 'err'); return false; }
    if (!REG.data.licensePhotoFile) { showDriverToast('Take a photo of your driving license', 'err'); return false; }
    return true;
}

/* ── License photo capture (required) ── */
window.regTakeLicensePhoto = function () {
    var inp = document.getElementById('reg-license-photo-input');
    if (inp) inp.click();
};

window.regLicensePhotoChosen = function (input) {
    if (!input.files || !input.files[0]) return;
    REG.data.licensePhotoFile = input.files[0];
    var reader = new FileReader();
    reader.onload = function (e) {
        var preview = document.getElementById('reg-license-photo-preview');
        var box = document.getElementById('reg-license-photo-box');
        if (preview) { preview.src = e.target.result; preview.style.display = 'block'; }
        if (box) box.style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
    showDriverToast('License photo selected', 'ok');
};

/* ── Step 4: selfie ── */
window.regTakePhoto = function () {
    var inp = document.getElementById('reg-selfie-input');
    if (inp) inp.click();
};

window.regSelfieChosen = function (input) {
    if (!input.files || !input.files[0]) return;
    REG.data.selfieFile = input.files[0];
    var reader = new FileReader();
    reader.onload = function (e) {
        var preview = document.getElementById('reg-selfie-preview');
        var box = document.getElementById('reg-photo-box');
        if (preview) { preview.src = e.target.result; preview.style.display = 'block'; }
        if (box) box.style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
    showDriverToast('Photo selected', 'ok');
};

/* ── Step 5: save + summary ── */
function regShowSummary() {
    var icon = document.getElementById('reg-summary-icon');
    var type = document.getElementById('reg-summary-type');
    var detail = document.getElementById('reg-summary-detail');
    var plate = document.getElementById('reg-summary-plate');
    if (icon) icon.textContent = REG.vtypeIcons[REG.data.vtype] || '🚗';
    if (type) type.textContent = REG.vtypeTitles[REG.data.vtype] || REG.data.vtype;
    if (detail) detail.textContent = [REG.data.color, REG.data.make, REG.data.year].filter(Boolean).join(' · ');
    if (plate) plate.textContent = REG.data.plate || '——';
}

function regUploadPhoto(file, uploadType) {
    var uploadBase = (typeof ETEL !== 'undefined' && ETEL.api) ? ETEL.api.replace('boot.php', 'upload.php') : 'api/upload.php';
    var fd = new FormData();
    fd.append('image', file);
    fd.append('type', uploadType);
    return fetch(uploadBase, { method: 'POST', headers: { 'Authorization': 'Bearer ' + (typeof Auth !== 'undefined' ? Auth.getToken() : '') }, body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.success) return data.data.url;
            throw new Error(data && data.message ? data.message : 'Photo upload failed');
        });
}

function regSaveAndFinish() {
    var btn = document.getElementById('reg-next-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }

    /* License photo is required (enforced in regValidateLicense), selfie
       is optional - upload whichever files were actually chosen, then
       proceed to the profile save once both uploads (if any) resolve. */
    var uploads = [];
    uploads.push(REG.data.licensePhotoFile ? regUploadPhoto(REG.data.licensePhotoFile, 'driver_license') : Promise.resolve(null));
    uploads.push(REG.data.selfieFile ? regUploadPhoto(REG.data.selfieFile, 'driver_selfie') : Promise.resolve(null));

    Promise.all(uploads).then(function (urls) {
        var licensePhotoUrl = urls[0];
        var selfieUrl = urls[1];
        var payload = {
            vehicle_type:   REG.data.vtype,
            vehicle_make:   REG.data.make,
            vehicle_color:  REG.data.color,
            vehicle_year:   REG.data.year || '0',
            vehicle_plate:  REG.data.plate,
            license_name:   REG.data.licenseName,
            license_number: REG.data.licenseNum,
            license_dob:    REG.data.licenseDob,
        };
        if (licensePhotoUrl) payload.license_photo_url = licensePhotoUrl;
        if (selfieUrl) payload.selfie_url = selfieUrl;

        drvApi('driver_update_profile', payload, function (data) {
            if (btn) { btn.disabled = false; btn.textContent = 'Next'; }
            regGoStep(5);
            /* identity_match/message come from the EtelID cross-check run
               server-side in driver_update_profile - surface a mismatch or
               unreachable result so the driver knows their account is
               pending admin review rather than assuming they're onboarded. */
            if (data && data.identity_match === 'mismatch') {
                showDriverToast(data.message || 'License details do not match your EtelID - flagged for admin review', 'err');
            } else if (data && data.identity_match === 'unverified') {
                showDriverToast(data.message || 'Could not verify against EtelID right now - will retry automatically', 'info');
            }
            /* Refresh profile data in the UI */
            loadDriverProfile();
        }, function (msg) {
            if (btn) { btn.disabled = false; btn.textContent = 'Next'; }
            showDriverToast('Could not save: ' + msg, 'err');
        });
    }).catch(function (err) {
        if (btn) { btn.disabled = false; btn.textContent = 'Next'; }
        showDriverToast('Photo upload failed: ' + (err && err.message ? err.message : 'unknown error'), 'err');
    });
}
/* ── End Phase 3 ── */

/* ---- Reconciled from driver-bundle.js (built directly into the bundle
   in an earlier session, never round-tripped into this source file until
   now) - see build-bundles.js's own header for why this file, not the
   bundle, is what should be hand-edited going forward. ---- */
function _driverVtypeImage(){var e=(DRV.driverProfile&&DRV.driverProfile.vehicle_type||"").toLowerCase();if(e.indexOf("moto")!==-1||e.indexOf("bike")!==-1||e.indexOf("scoot")!==-1)return"../img/vehicles/moto.png";if(e.indexOf("mini")!==-1||e.indexOf("van")!==-1||e.indexOf("bus")!==-1)return"../img/vehicles/minibus.png";if(e.indexOf("car")!==-1||e.indexOf("sedan")!==-1)return"../img/vehicles/car.png";if(e.indexOf("qute")!==-1)return"../img/vehicles/qute.png";return"../img/vehicles/bajaj.png"}

function _renderViVtypeGrid(gridId,list,selected,onSelect){
  var grid=document.getElementById(gridId);
  if(!grid)return;
  var selLower=(selected||"").toLowerCase();
  grid.innerHTML=list.map(function(e){
    var isSel=e.toLowerCase()===selLower||e===selected;
    var iconHtml=REG.vtypeImages[e]?'<img class="ro-icon-img" src="'+REG.vtypeImages[e]+'" alt="" loading="lazy">':(REG.vtypeIcons[e]||"🚙");
    return '<div class="reg-option'+(isSel?" selected":"")+'" data-vtype="'+e+'" onclick="_viSelectVtype(this,\''+gridId+"')\">"+
      '<div class="ro-icon">'+iconHtml+"</div>"+
      '<div class="ro-label">'+(REG.vtypeTitles[e]||e)+"</div>"+
    "</div>"
  }).join("");
  grid._onSelect=onSelect;
}

function _syncWaitState(ride){
  if(!ride||!document.getElementById("wait-toggle-box")){
    if(DRV.waitTickInterval){clearInterval(DRV.waitTickInterval);DRV.waitTickInterval=null}
    return
  }
  DRV.waitBaselineMinutes=ride.wait_minutes||0;
  DRV.waitBaselineMs=Date.now();
  var active=!!ride.wait_active;
  if(active&&!DRV.waitTickInterval){DRV.waitTickInterval=setInterval(_updateWaitBox,1000)}
  else if(!active&&DRV.waitTickInterval){clearInterval(DRV.waitTickInterval);DRV.waitTickInterval=null}
  _updateWaitBox()
}

function _updateWaitBox(){
  var ride=DRV.activeRide;
  var box=document.getElementById("wait-toggle-box");
  var statusEl=document.getElementById("wait-status-text");
  var btn=document.getElementById("wait-toggle-btn");
  if(!ride||!box||!statusEl||!btn)return;
  var active=!!ride.wait_active;
  var elapsedSec=(DRV.waitBaselineMinutes||0)*60;
  if(active&&DRV.waitBaselineMs){elapsedSec+=(Date.now()-DRV.waitBaselineMs)/1000}
  box.style.background=active?"rgba(255,152,0,.12)":"rgba(255,255,255,.03)";
  box.style.borderColor=active?"rgba(255,152,0,.4)":"rgba(212,175,55,.15)";
  statusEl.style.color=active?"#FFB74D":"var(--text-dim)";
  var etb=parseFloat(ride.wait_etb||0).toFixed(0);
  statusEl.textContent=active
    ? ("⏱ "+_fmtWaitClock(elapsedSec)+" · ETB "+etb+" so far")
    : ((ride.wait_minutes>0)?("Stopped · ETB "+etb+" total"):"Not waiting");
  btn.textContent=active?"⏹ Stop Waiting":"⏱ Start Waiting";
  btn.style.background=active?"linear-gradient(135deg,#E74C3C,#C0392B)":"linear-gradient(135deg,#FFD700,#D4AF37)";
  btn.style.color=active?"#fff":"#1a1206";
}

function loadGoalTiers(){
  var grid=document.getElementById("goal-chips");
  if(!grid)return;
  drvApi("driver_get_goal_tiers",{},function(r){
    var tiers=r&&r.tiers&&r.tiers.length?r.tiers:[{trips_required:2,projected_etb:0}];
    DRV.avgFare=parseFloat(r&&r.avg_fare||0);
    grid.innerHTML=tiers.map(function(t,i){
      var trips=parseInt(t.trips_required),proj=parseFloat(t.projected_etb);
      return '<div class="goal-chip'+(i===0?" active":"")+'" onclick="selectGoalTier(this,'+trips+","+proj+')">'+
        '<div class="gc-trips">'+trips+" trip"+(trips===1?"":"s")+"</div>"+
        '<div class="gc-price">'+(DRV.avgFare>0?"~ETB "+proj.toLocaleString():"—")+"</div>"+
      "</div>"
    }).join("");
    if(tiers.length){
      DRV.goalTier={trips:parseInt(tiers[0].trips_required),bonus:parseFloat(tiers[0].projected_etb)};
      var gt=document.getElementById("goal-title"),gb=document.getElementById("goal-bonus");
      if(gt)gt.textContent=DRV.goalTier.trips+" trips goal";
      if(gb)gb.textContent=DRV.avgFare>0?"~ETB "+DRV.goalTier.bonus.toLocaleString():"—";
      if(DRV.driverProfile)updateEarningsUI(DRV.driverProfile)
    }
  },function(){
    grid.innerHTML='<div style="width:100%;text-align:center;padding:16px;color:var(--dim);font-size:12px">Could not load goals</div>'
  })
}

function loadVehicleTypeOptionsFor(gridId,selected,onSelect){
  drvApi("ride_get_vehicle_types",{},function(r){
    var list=r&&r.vehicle_types&&r.vehicle_types.length?r.vehicle_types:["bajaj","moto","car","minibus"];
    list.forEach(function(e){if(!REG.vtypeIcons[e])REG.vtypeIcons[e]=regGuessIcon(e);if(!REG.vtypeTitles[e])REG.vtypeTitles[e]=regGuessTitle(e)});
    _renderViVtypeGrid(gridId,list,selected,onSelect)
  },function(){
    _renderViVtypeGrid(gridId,["bajaj","moto","car","minibus"],selected,onSelect)
  })
}

// Shared state for the vehicle-info edit panel (openVehicleInfoEdit,
// saveVehicleInfoEdit, and the grid selection handler inside
// _renderViVtypeGrid all read/write VI.vtype) - this was a loose
// module-level variable in the deployed bundle that my earlier
// function-by-function reconciliation missed (it only captured named
// `function NAME(){}` declarations, not plain `var` statements sitting
// between them), causing "Uncaught ReferenceError: VI is not defined"
// once the bundle was rebuilt from source without it.
var VI = { vtype: null };

function openVehicleInfoEdit(){
  var p=DRV.driverProfile||{};
  VI.vtype=p.vehicle_type||"bajaj";
  var html=
    '<div style="margin-bottom:6px"><label style="display:block;font-size:11px;color:rgba(245,237,216,.6);margin-bottom:8px">Vehicle type</label>'+
      '<div class="reg-option-grid" id="vi-vtype-grid"><div style="grid-column:1/-1;text-align:center;padding:16px;color:rgba(245,237,216,.5);font-size:12px">Loading vehicle types…</div></div>'+
    "</div>"+
    '<div style="display:flex;gap:10px;margin-bottom:14px;margin-top:14px">'+
      '<div style="flex:1"><label style="display:block;font-size:11px;color:rgba(245,237,216,.6);margin-bottom:5px">Make/model</label>'+
        '<input id="vi-make" value="'+esc(p.vehicle_make||"")+'" style="width:100%;padding:11px;border-radius:10px;border:1px solid rgba(212,175,55,.25);background:#191926;color:#EDEDF5;font-size:14px;box-sizing:border-box"></div>'+
      '<div style="flex:1"><label style="display:block;font-size:11px;color:rgba(245,237,216,.6);margin-bottom:5px">Color</label>'+
        '<input id="vi-color" value="'+esc(p.vehicle_color||"")+'" style="width:100%;padding:11px;border-radius:10px;border:1px solid rgba(212,175,55,.25);background:#191926;color:#EDEDF5;font-size:14px;box-sizing:border-box"></div>'+
    "</div>"+
    '<div style="display:flex;gap:10px;margin-bottom:18px">'+
      '<div style="flex:1"><label style="display:block;font-size:11px;color:rgba(245,237,216,.6);margin-bottom:5px">Year</label>'+
        '<input id="vi-year" value="'+esc(p.vehicle_year||"")+'" style="width:100%;padding:11px;border-radius:10px;border:1px solid rgba(212,175,55,.25);background:#191926;color:#EDEDF5;font-size:14px;box-sizing:border-box"></div>'+
      '<div style="flex:1"><label style="display:block;font-size:11px;color:rgba(245,237,216,.6);margin-bottom:5px">Plate number</label>'+
        '<input id="vi-plate" value="'+esc(p.vehicle_plate||"")+'" style="width:100%;padding:11px;border-radius:10px;border:1px solid rgba(212,175,55,.25);background:#191926;color:#EDEDF5;font-size:14px;box-sizing:border-box;text-transform:uppercase"></div>'+
    "</div>"+
    '<button id="vi-save-btn" onclick="saveVehicleInfoEdit()" style="width:100%;padding:13px;border:none;border-radius:12px;background:linear-gradient(135deg,#FFD700,#D4AF37);color:#1a1206;font-weight:700;font-size:14px;cursor:pointer">Save Changes</button>'+
    '<div style="font-size:11px;color:rgba(245,237,216,.4);margin-top:10px;text-align:center">Your license and selfie are already verified - only vehicle details change here.</div>';
  openDriverModal("🔧 Update Vehicle Info",html);
  loadVehicleTypeOptionsFor("vi-vtype-grid",VI.vtype,function(vt){VI.vtype=vt});
}

function refreshSelfMarkerIcon(){if(DRV.selfMarker){if(DRV.selfMarker.__eg){DRV.selfMarker.__eg.bucket=null;DRV.selfMarker.__eg.type=null}DRV.selfMarker.setIcon(_selfMarkerIcon())}}

function saveVehicleInfoEdit(){
  var btn=document.getElementById("vi-save-btn");
  var plate=(document.getElementById("vi-plate").value||"").trim();
  if(!plate){showDriverToast("Enter the vehicle plate number","err");return}
  var payload={
    vehicle_type:VI.vtype,
    vehicle_make:(document.getElementById("vi-make").value||"").trim(),
    vehicle_color:(document.getElementById("vi-color").value||"").trim(),
    vehicle_year:(document.getElementById("vi-year").value||"").trim(),
    vehicle_plate:plate.toUpperCase()
  };
  if(btn){btn.disabled=true;btn.textContent="Saving…"}
  drvApi("driver_update_profile",payload,function(){
    showDriverToast("Vehicle info updated ✓","ok");
    closeDriverModal();
    // Refresh AFTER the reload resolves, not before - refreshSelfMarkerIcon()
    // reads DRV.driverProfile.vehicle_type, which is still the OLD value
    // until loadDriverProfile()'s callback actually lands.
    drvApi("driver_get_profile",{},function(e){
      DRV.driverProfile=e.driver;
      updateEarningsUI(e.driver);
    },null)
  },function(e){
    if(btn){btn.disabled=false;btn.textContent="Save Changes"}
    showDriverToast(e||"Could not save","err")
  });
}

/* ── Compliance Documents (insurance / technical inspection / Road Fund) ──
   A driver submits their own expiry date + a photo of the sticker for
   each document; an admin reviews and approves it before it becomes
   what a Traffic Officer sees on an NFC card check - see
   driver_submit_compliance_document / admin_approve_compliance_document
   in RideController.php. */
var CD_DOCS=[
  {key:'insurance',label:'Insurance',icon:'🛡️'},
  {key:'technical_inspection',label:'Technical Inspection',icon:'🔧'},
  {key:'road_fund',label:'Road Fund',icon:'🛣️'}
];
function openComplianceDocuments(){
  openDriverModal('📄 Compliance Documents','<div id="cd-body" style="text-align:center;padding:24px;color:rgba(245,237,216,.5)">Loading…</div>');
  drvApi('driver_get_compliance_status',{},function(data){
    renderComplianceDocuments(data.documents||{});
  },function(e){
    var el=document.getElementById('cd-body');
    if(el)el.innerHTML='<div style="color:#ff8a8a;text-align:center;padding:20px">Could not load: '+esc(e||'error')+'</div>';
  });
}
function renderComplianceDocuments(docs){
  var el=document.getElementById('cd-body');
  if(!el)return;
  el.innerHTML=CD_DOCS.map(function(doc){
    var d=docs[doc.key]||{};
    var statusHtml;
    if(d.pending){
      statusHtml='<span style="color:#F2CC6B">Submitted, awaiting review (expiry '+esc(d.pending.requested_expiry_date)+')</span>';
    }else if(d.status==='valid'){
      statusHtml='<span style="color:#9e9">Valid until '+esc(d.expiry_date)+'</span>';
    }else if(d.status==='expired'){
      statusHtml='<span style="color:#e88">EXPIRED ('+esc(d.expiry_date)+')</span>';
    }else{
      statusHtml='<span style="color:rgba(245,237,216,.5)">Not on file</span>';
    }
    return '<div style="padding:14px 0;border-bottom:1px solid rgba(212,175,55,.12)">'+
      '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px">'+
        '<div style="font-weight:700;font-size:13.5px">'+doc.icon+' '+esc(doc.label)+'</div>'+
        (d.pending?'':'<button style="padding:7px 12px;border-radius:8px;border:1px solid rgba(212,175,55,.35);background:rgba(212,175,55,.08);color:#D4AF37;font-size:12px;cursor:pointer" onclick="openComplianceDocSubmitForm(\''+doc.key+'\')">Submit</button>')+
      '</div>'+
      '<div style="font-size:12.5px;margin-top:4px">'+statusHtml+'</div>'+
    '</div>';
  }).join('')+
  '<div style="font-size:11px;color:rgba(245,237,216,.4);margin-top:12px;text-align:center">Submissions are reviewed by an admin before they show on a traffic check.</div>';
}
var CD_STATE={docKey:null,photoFile:null};
function openComplianceDocSubmitForm(docKey){
  CD_STATE.docKey=docKey;
  CD_STATE.photoFile=null;
  var doc=CD_DOCS.filter(function(d){return d.key===docKey})[0]||{label:docKey};
  var el=document.getElementById('cd-body');
  if(!el)return;
  el.innerHTML=
    '<div style="margin-bottom:14px"><label style="display:block;font-size:11px;color:rgba(245,237,216,.6);margin-bottom:6px">'+esc(doc.label)+' expiry date</label>'+
      '<input type="date" id="cd-expiry" style="width:100%;padding:11px;border-radius:10px;border:1px solid rgba(212,175,55,.25);background:#191926;color:#EDEDF5;font-size:14px;box-sizing:border-box"></div>'+
    '<div style="margin-bottom:14px"><label style="display:block;font-size:11px;color:rgba(245,237,216,.6);margin-bottom:6px">Photo of the document</label>'+
      '<input type="file" id="cd-photo-input" accept="image/*" style="width:100%;color:rgba(245,237,216,.8);font-size:12px">'+
      '<div id="cd-photo-status" style="font-size:11px;color:rgba(245,237,216,.4);margin-top:6px"></div></div>'+
    '<button id="cd-submit-btn" style="width:100%;padding:13px;border:none;border-radius:12px;background:linear-gradient(135deg,#FFD700,#D4AF37);color:#1a1206;font-weight:700;font-size:14px;cursor:pointer" onclick="submitComplianceDocument()">Submit for Review</button>'+
    '<button style="width:100%;padding:11px;margin-top:8px;border:1px solid rgba(212,175,55,.25);border-radius:12px;background:transparent;color:rgba(245,237,216,.7);font-size:13px;cursor:pointer" onclick="openComplianceDocuments()">Back</button>';
  document.getElementById('cd-photo-input').addEventListener('change',function(){
    CD_STATE.photoFile=this.files&&this.files[0]||null;
    document.getElementById('cd-photo-status').textContent=CD_STATE.photoFile?CD_STATE.photoFile.name:'';
  });
}
function submitComplianceDocument(){
  var docKey=CD_STATE.docKey;
  var expiry=(document.getElementById('cd-expiry').value||'').trim();
  if(!expiry){showDriverToast('Pick the expiry date printed on the document','err');return}
  if(!CD_STATE.photoFile){showDriverToast('Attach a photo of the document','err');return}
  var btn=document.getElementById('cd-submit-btn');
  if(btn){btn.disabled=true;btn.textContent='Uploading…'}
  driverUploadReceiptPhoto(CD_STATE.photoFile,'driver_'+docKey+'_photo').then(function(url){
    drvApi('driver_submit_compliance_document',{doc_type:docKey,expiry_date:expiry,receipt_url:url},function(){
      showDriverToast('Submitted for admin review ✓','ok');
      openComplianceDocuments();
    },function(e){
      if(btn){btn.disabled=false;btn.textContent='Submit for Review'}
      showDriverToast(e||'Could not submit','err');
    });
  }).catch(function(e){
    if(btn){btn.disabled=false;btn.textContent='Submit for Review'}
    showDriverToast((e&&e.message)||'Photo upload failed','err');
  });
}
window.openComplianceDocuments=openComplianceDocuments;
window.openComplianceDocSubmitForm=openComplianceDocSubmitForm;
window.submitComplianceDocument=submitComplianceDocument;

function toggleWaitFee(){
  var ride=DRV.activeRide;
  if(!ride||!ride.id){showDriverToast("No active ride","err");return}
  var startingNow=!ride.wait_active;
  if(startingNow&&!confirm("Start charging the waiting fee? The customer will be billed extra for every minute until you tap Stop Waiting."))return;
  var btn=document.getElementById("wait-toggle-btn");
  if(btn)btn.disabled=true;
  drvApi("driver_toggle_wait",{ride_id:ride.id},function(r){
    ride.wait_active=r.wait_active;ride.wait_minutes=r.wait_minutes;ride.wait_etb=r.wait_etb;
    if(r.meter_fare!=null){
      ride.meter_fare=r.meter_fare;
      var mf=document.getElementById("meter-fare");
      if(mf)mf.textContent="ETB "+parseFloat(r.meter_fare).toFixed(0)
    }
    if(btn)btn.disabled=false;
    _syncWaitState(ride);
    showDriverToast(r.wait_active?"⏱ Waiting fee started":"⏹ Waiting fee stopped","ok")
  },function(e){
    if(btn)btn.disabled=false;
    showDriverToast(e||"Could not toggle waiting","err")
  },true)
}

function _fareBreakdownHtml(e){
  var base=parseFloat(e.fare_base||0),dist=parseFloat(e.fare_distance||0),wait=parseFloat(e.fare_wait||0);
  var subtotal=parseFloat(e.fare_subtotal!=null?e.fare_subtotal:(base+dist+wait));
  var surgeMult=parseFloat(e.fare_surge_mult||1),surgeEtb=parseFloat(e.fare_surge_etb||0);
  var floorTopup=parseFloat(e.fare_floor_topup||0);
  var total=parseFloat(e.fare_total!=null?e.fare_total:(e.meter_fare!=null?e.meter_fare:e.fare));
  var km=parseFloat(e.distance_km!=null&&e.status==="arrived"?e.distance_km:(e.traveled_km||0));
  var waitMin=parseFloat(e.wait_minutes||0);
  var row=function(label,sub,val,color){return '<div style="display:flex;justify-content:space-between;align-items:baseline;font-size:12.5px;padding:3px 0;color:'+(color||"var(--text-dim)")+'"><span>'+label+(sub?' <span style="opacity:.7;font-size:11px">'+sub+"</span>":"")+'</span><span style="color:var(--text)">ETB '+val.toFixed(0)+"</span></div>"};
  var html='<div id="fare-breakdown-box" style="margin-bottom:12px;background:rgba(255,255,255,.02);border:1px solid rgba(212,175,55,.12);border-radius:12px;padding:12px 14px">'+
    '<div style="font-size:10.5px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px">🧾 Fare Breakdown</div>'+
    row("Base fare",null,base)+
    row("Distance",km.toFixed(2)+" km",dist);
  if(wait>0)html+=row("Waiting",waitMin.toFixed(0)+" min",wait,"#FFB74D");
  html+='<div style="border-top:1px solid rgba(255,255,255,.08);margin-top:4px;padding-top:6px">'+row("Subtotal",null,subtotal)+"</div>";
  if(surgeMult>1&&surgeEtb>0)html+=row("Surge ("+surgeMult.toFixed(1)+"×)",null,surgeEtb,"#FFB74D");
  if(floorTopup>0)html+=row("Minimum fare applied",null,floorTopup,"#4ECDC4");
  html+='<div style="display:flex;justify-content:space-between;font-size:14px;font-weight:800;padding-top:6px;margin-top:4px;border-top:1px solid rgba(212,175,55,.22)"><span>Total</span><span style="color:var(--teal)">ETB '+total.toFixed(0)+"</span></div>"+
  "</div>";
  return html
}

function _fmtWaitClock(totalSeconds){
  totalSeconds=Math.max(0,Math.floor(totalSeconds));
  var m=Math.floor(totalSeconds/60),s=totalSeconds%60;
  return (m<10?"0":"")+m+":"+(s<10?"0":"")+s;
}


/* ---- Additional window.* exports found missing during a follow-up
   reconciliation pass (onclick handlers need these; see the codebase's
   known IIFE-export gotcha) - the first reconciliation pass only
   captured named 'function NAME(){}' declarations and missed these
   window.X = ... assignment statements sitting between them. ---- */
window.loadGoalTiers=loadGoalTiers;
window.selectGoalTier=function(e,t,n){DRV.goalTier={trips:t,bonus:n};document.querySelectorAll(".goal-chip").forEach(function(e){e.classList.remove("active")});e.classList.add("active");var r=document.getElementById("goal-title");var i=document.getElementById("goal-bonus");if(r)r.textContent=t+" trips goal";if(i)i.textContent=DRV.avgFare>0?"~ETB "+n.toLocaleString():"—";if(DRV.driverProfile)updateEarningsUI(DRV.driverProfile)};
window._viSelectVtype=function(el,gridId){
  var grid=document.getElementById(gridId);
  if(!grid)return;
  grid.querySelectorAll(".reg-option").forEach(function(o){o.classList.remove("selected")});
  el.classList.add("selected");
  var vt=el.getAttribute("data-vtype");
  VI.vtype=vt;
  if(grid._onSelect)grid._onSelect(vt)
};
window.openVehicleInfoEdit=openVehicleInfoEdit;
window.saveVehicleInfoEdit=saveVehicleInfoEdit;
window.toggleWaitFee=toggleWaitFee;

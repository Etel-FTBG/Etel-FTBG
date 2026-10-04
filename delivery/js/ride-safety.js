/* ETEL Ride - rider safety (additive script, same idea as sos-button.js and
   ride-extra-features.js: it only reads globals the ride app already exposes
   (RIDE, Auth) and builds its own DOM, so it can be rolled back on its own).

   1. Trusted contacts (up to 3) + "Safety check-ins" switch, in a panel opened by
      any element with  data-etel-safety-open  (a row in the rider's Profile).
   2. One-tap "Send live trip link" to each contact through the phone's own SMS or
      WhatsApp (nothing is sent by our server).
   3. After the SOS button succeeds, offers the same buttons with the live link and
      the rider's location, so contacts can be told in one tap too.
   4. Safety check-in: during an in-progress trip, if it runs much longer than the
      distance suggests, or the car stands still for a long time, the rider is asked
      "Are you OK?". No answer in 60 s -> ETEL support is alerted (ride_safety_checkin_missed).
      "I need help" sends the SOS straight away. Not asked while the app is in the background. */
(function () {
    'use strict';
    if (window.EtelSafety) return;
    var doc = document;
    var settings = { contacts: [], checkins: true, loaded: false };
    var host = null, shadow = null, body = null;

    /* ---------- plumbing ---------- */
    function apiUrl() {
        var path = window.location.pathname, mk = '/delivery/', i = path.indexOf(mk);
        var base = i >= 0 ? path.substring(0, i + mk.length) : path.substring(0, path.lastIndexOf('/') + 1);
        return window.location.protocol + '//' + window.location.host + base + 'api/boot.php';
    }
    function token() { try { return (window.Auth && Auth.getToken) ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); } catch (e) { return ''; } }
    function call(action, extra) {
        var p = { action: action }; for (var k in (extra || {})) p[k] = extra[k];
        return fetch(apiUrl(), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() }, body: JSON.stringify(p) })
            .then(function (r) { return r.text(); })
            .then(function (t) { try { return JSON.parse(t); } catch (e) { return { success: false, message: 'Server error. Try again.' }; } })
            .catch(function () { return { success: false, message: 'Cannot reach the server. Check your connection.' }; });
    }
    function esc(t) { return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function rideId() { return (window.RIDE && (RIDE.currentRideId || RIDE.currentRequestId)) || null; }
    function rideData() { return (window.RIDE && RIDE.activeRideData) || null; }

    function loadSettings() {
        if (!token()) return Promise.resolve();
        return call('ride_safety_get').then(function (r) {
            if (r && r.success && r.data) { settings.contacts = r.data.contacts || []; settings.checkins = r.data.checkins !== false; settings.loaded = true; }
        });
    }

    /* ---------- messages to contacts ---------- */
    function waNumber(phone) {
        var d = String(phone).replace(/[^0-9+]/g, '');
        if (d.charAt(0) === '+') return d.substring(1);
        if (d.charAt(0) === '0') return '251' + d.substring(1);
        return d;
    }
    function contactLinks(c, text) {
        var t = encodeURIComponent(text);
        return { sms: 'sms:' + String(c.phone).replace(/[^0-9+]/g, '') + '?&body=' + t, wa: 'https://wa.me/' + waNumber(c.phone) + '?text=' + t };
    }
    function shareUrl() {
        var id = rideId();
        if (!id) return Promise.resolve(null);
        return call('ride_get_share_link', { ride_id: id }).then(function (r) { return (r && r.success && r.data) ? r.data.share_url : null; });
    }
    function myPlace() {
        return new Promise(function (resolve) {
            if (!navigator.geolocation) return resolve(null);
            navigator.geolocation.getCurrentPosition(function (p) { resolve({ lat: p.coords.latitude, lng: p.coords.longitude }); }, function () { resolve(null); }, { enableHighAccuracy: true, timeout: 4000 });
        });
    }
    function contactButtons(text) {
        if (!settings.contacts.length) return '<p class="dim">You have not added a trusted contact yet. Add one in Safety contacts.</p>';
        return settings.contacts.map(function (c) {
            var l = contactLinks(c, text);
            return '<div class="crow"><b>' + esc(c.name) + '</b><span><a class="b sm" href="' + esc(l.sms) + '">SMS</a> <a class="b sm p" target="_blank" rel="noopener" href="' + esc(l.wa) + '">WhatsApp</a></span></div>';
        }).join('');
    }

    /* ---------- shadow-DOM modal ---------- */
    var CSS = ':host{all:initial}*{box-sizing:border-box}' +
        '.bg{position:fixed;inset:0;z-index:2147483000;background:rgba(0,0,0,.74);display:flex;align-items:flex-end;justify-content:center;font-family:Outfit,system-ui,-apple-system,sans-serif}' +
        '@media(min-width:560px){.bg{align-items:center}}' +
        '.card{width:100%;max-width:440px;max-height:92vh;overflow:auto;background:#11131C;color:#F7F5EE;border:1px solid rgba(212,175,55,.35);border-radius:22px 22px 0 0;padding:20px 18px calc(18px + env(safe-area-inset-bottom,0px));box-shadow:0 20px 60px rgba(0,0,0,.6);line-height:1.5}' +
        '@media(min-width:560px){.card{border-radius:22px}}' +
        '.card.alert{border-color:rgba(255,119,115,.6)}' +
        '.top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:6px}h2{margin:0;font-size:19px;font-weight:800}' +
        '.x{width:40px;height:40px;border-radius:50%;border:1px solid rgba(255,255,255,.18);background:transparent;color:#C4C6D0;font-size:18px;cursor:pointer}' +
        '.sub{color:#C4C6D0;font-size:14px;margin:0 0 14px}.dim{color:#9A9AAE;font-size:13px}' +
        '.row{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:12px}' +
        '.crow{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 0;border-top:1px solid rgba(255,255,255,.08)}' +
        '.b{min-height:46px;padding:0 18px;border-radius:14px;border:1px solid rgba(212,175,55,.45);background:transparent;color:#F7F5EE;font:600 15px Outfit,system-ui,sans-serif;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}' +
        '.b.sm{min-height:40px;padding:0 14px;font-size:14px}.b.p{border:0;background:linear-gradient(135deg,#F4D56A,#C89A24);color:#16120A;font-weight:800}' +
        '.b.d{background:linear-gradient(135deg,#FF5A5F,#C62828);border:0;color:#fff;font-weight:800}' +
        'button:disabled{opacity:.5}' +
        'input[type=text],input[type=tel]{width:100%;min-height:46px;border-radius:12px;border:1px solid rgba(212,175,55,.45);background:#0B0C12;color:#F7F5EE;font-size:16px;padding:0 12px;margin-bottom:8px}' +
        '.pair{display:grid;grid-template-columns:1fr 1.2fr;gap:8px}' +
        'label.sw{display:flex;align-items:flex-start;gap:10px;margin:14px 0 4px;font-size:14px}label.sw input{width:20px;height:20px;margin-top:2px}' +
        '.msg{margin:10px 0 0;font-size:14px;min-height:20px}.err{color:#FF7773}.ok{color:#35D17C}' +
        '.big{font-size:44px;text-align:center;margin:2px 0}.count{font-size:30px;font-weight:800;text-align:center;color:#FF9C98;margin:4px 0 12px}';

    function build() {
        if (host) return;
        host = doc.createElement('div');
        host.setAttribute('data-etel-safety-host', '');
        host.style.cssText = 'all:initial;position:fixed;inset:0;z-index:2147483000;display:none';
        shadow = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
        shadow.innerHTML = '<style>' + CSS + '</style><div class="bg"><div class="card" id="card"><div id="body"></div></div></div>';
        doc.body.appendChild(host);
        body = shadow.getElementById('body');
        shadow.querySelector('.bg').addEventListener('click', function (e) { if (e.target === e.currentTarget && !host.__locked) close(); });
    }
    function $(id) { return shadow.getElementById(id); }
    function show(html, alertStyle, locked) {
        build();
        shadow.getElementById('card').className = 'card' + (alertStyle ? ' alert' : '');
        host.__locked = !!locked;
        body.innerHTML = html;
        host.style.display = 'block';
    }
    function close() { if (host) { host.style.display = 'none'; host.__locked = false; } }
    function head(title) { return '<div class="top"><h2>' + title + '</h2><button class="x" id="xclose" aria-label="Close">&#10005;</button></div>'; }
    function wireClose() { var x = $('xclose'); if (x) x.onclick = close; }
    function msg(t, k) { var m = $('msg'); if (m) { m.textContent = t || ''; m.className = 'msg ' + (k || 'err'); } }

    /* ---------- 1. settings panel ---------- */
    function openPanel() {
        show(head('Safety contacts') + '<p class="sub">Loading&hellip;</p>'); wireClose();
        loadSettings().then(renderPanel);
    }
    function renderPanel() {
        var rows = '';
        for (var i = 0; i < 3; i++) {
            var c = settings.contacts[i] || { name: '', phone: '' };
            rows += '<div class="pair"><input type="text" id="n' + i + '" placeholder="Name" maxlength="40" value="' + esc(c.name) + '"><input type="tel" id="p' + i + '" placeholder="Phone, e.g. 0911..." value="' + esc(c.phone) + '"></div>';
        }
        var live = rideId() ? '<div id="livebox" style="margin-top:16px"><b>Share this trip now</b><div id="livelist" class="dim">Getting your link&hellip;</div></div>' : '';
        show(head('Safety contacts') +
            '<p class="sub">People you trust. Share your live trip with them in one tap, and tell them quickly if you press SOS.</p>' + rows +
            '<label class="sw"><input type="checkbox" id="chk"' + (settings.checkins ? ' checked' : '') + '><span>Safety check-ins during trips<br><span class="dim">If a trip runs much longer than expected, or the car stops for a long time, we ask &ldquo;Are you OK?&rdquo;. No answer and ETEL support is alerted.</span></span></label>' +
            '<div class="row"><button class="b" id="cancel">Close</button><button class="b p" id="save">Save</button></div><div id="msg" class="msg"></div>' + live);
        wireClose();
        $('cancel').onclick = close;
        $('save').onclick = save;
        if (rideId()) {
            shareUrl().then(function (url) {
                var box = $('livelist'); if (!box) return;
                if (!url) { box.textContent = 'Trip sharing is available once your ride is active.'; return; }
                box.innerHTML = contactButtons('I am on an ETEL Ride trip. Follow it live: ' + url);
            });
        }
    }
    function save() {
        var contacts = [];
        for (var i = 0; i < 3; i++) contacts.push({ name: $('n' + i).value.trim(), phone: $('p' + i).value.trim() });
        $('save').disabled = true;
        call('ride_safety_save', { contacts: contacts, checkins: $('chk').checked ? 1 : 0 }).then(function (r) {
            $('save').disabled = false;
            if (r && r.success) { settings.contacts = r.data.contacts || []; settings.checkins = r.data.checkins !== false; msg('Saved.', 'ok'); }
            else msg((r && r.message) || 'Could not save. Try again.');
        });
    }

    /* ---------- 3. after SOS ---------- */
    function offerAfterSos() {
        if (!settings.contacts.length) return;
        Promise.all([shareUrl(), myPlace()]).then(function (res) {
            var url = res[0], pos = res[1];
            var text = 'EMERGENCY - I need help. I am on an ETEL Ride trip.' + (url ? ' Live trip: ' + url : '') + (pos ? ' My location: https://maps.google.com/?q=' + pos.lat + ',' + pos.lng : '');
            show(head('Tell your contacts') + '<p class="sub">ETEL support has your alert. Send this to the people you trust too:</p>' + contactButtons(text) +
                '<div class="row"><span></span><button class="b" id="cancel">Done</button></div>');
            wireClose(); $('cancel').onclick = close;
        });
    }
    function watchSos() {
        var badge = doc.getElementById('sos-sent-badge');
        if (!badge) { setTimeout(watchSos, 2500); return; }
        new MutationObserver(function () { if (badge.style.display === 'block') offerAfterSos(); }).observe(badge, { attributes: true, attributeFilter: ['style'] });
    }

    /* ---------- 4. check-in ---------- */
    var track = { id: null, lat: null, lng: null, since: 0, firstSeen: 0 };
    var asking = false;
    function dist(a, b, c, d) {
        var R = 6371000, r = Math.PI / 180, dLat = (c - a) * r, dLng = (d - b) * r;
        var x = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(a * r) * Math.cos(c * r) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return 2 * R * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
    }
    function snoozeKey(id) { return 'etel_safety_snooze_' + id; }
    function snoozed(id) { try { var t = parseInt(sessionStorage.getItem(snoozeKey(id)) || '0', 10); return t && Date.now() - t < 20 * 60000; } catch (e) { return false; } }
    function snooze(id) { try { sessionStorage.setItem(snoozeKey(id), String(Date.now())); } catch (e) {} }

    function why(rd, now) {
        var id = rd.id || rideId();
        if (track.id !== id) { track = { id: id, lat: null, lng: null, since: now, firstSeen: now }; }
        var started = rd.started_at ? Date.parse(String(rd.started_at).replace(' ', 'T') + '+03:00') : NaN;
        if (isNaN(started)) started = track.firstSeen;
        var elapsedMin = (now - started) / 60000;
        var km = parseFloat(rd.distance_km) || 0;
        var expected = km > 0 ? (km / 20) * 60 : 0; // about 20 km/h door to door in town
        var limit = expected > 0 ? Math.max(25, expected * 1.7 + 5) : 45;
        if (elapsedMin >= limit) return 'long';
        var la = parseFloat(rd.driver_lat), ln = parseFloat(rd.driver_lng);
        if (!isNaN(la) && !isNaN(ln)) {
            if (track.lat === null || dist(track.lat, track.lng, la, ln) > 40) { track.lat = la; track.lng = ln; track.since = now; }
            else if (now - track.since >= 8 * 60000 && elapsedMin >= 3) return 'stopped';
        }
        return null;
    }
    function tick() {
        if (!settings.loaded || !settings.checkins || asking || doc.hidden) return;
        var rd = rideData();
        if (!rd || rd.status !== 'in_progress') return;
        var id = rd.id || rideId();
        if (!id || snoozed(id)) return;
        var reason = why(rd, Date.now());
        if (reason) askAreYouOk(id, reason);
    }
    function askAreYouOk(id, reason) {
        asking = true; snooze(id);
        var left = 60, done = false;
        try { if (navigator.vibrate) navigator.vibrate([300, 150, 300, 150, 300]); } catch (e) {}
        show('<div class="big">&#128737;&#65039;</div><h2 style="text-align:center">Are you OK?</h2>' +
            '<p class="sub" style="text-align:center;margin-top:6px">' + (reason === 'stopped' ? 'Your ride has been stopped for a while.' : 'Your trip is taking longer than expected.') + ' Please let us know you are fine.</p>' +
            '<div class="count" id="count">60</div>' +
            '<div class="row"><button class="b d" id="help" style="flex:1">I need help</button><button class="b p" id="fine" style="flex:1">I am OK</button></div>' +
            '<div id="msg" class="msg"></div>', true, true);
        var timer = setInterval(function () {
            left--; var c = $('count'); if (c) c.textContent = String(Math.max(left, 0));
            if (left <= 0 && !done) { done = true; clearInterval(timer); missed(id); }
        }, 1000);
        $('fine').onclick = function () { if (done) return; done = true; clearInterval(timer); asking = false; close(); };
        $('help').onclick = function () {
            if (done) return; done = true; clearInterval(timer);
            $('help').disabled = true;
            myPlace().then(function (pos) {
                call('sos_trigger', { ride_id: id, lat: pos ? pos.lat : '', lng: pos ? pos.lng : '', message: 'Rider pressed "I need help" on the safety check-in' }).then(function () {
                    asking = false; offerAfterSos(); if (!settings.contacts.length) { show(head('Help is on the way') + '<p class="sub">ETEL support has your alert and your location.</p><div class="row"><span></span><button class="b" id="cancel">Close</button></div>'); wireClose(); $('cancel').onclick = close; }
                });
            });
        };
    }
    function missed(id) {
        call('ride_safety_checkin_missed', { ride_id: id }).then(function () {
            asking = false;
            show(head('We told ETEL support') + '<p class="sub">You did not answer, so we let ETEL support know to check on you. If you are fine, you can close this.</p><div class="row"><span></span><button class="b p" id="cancel">I am OK</button></div>');
            wireClose(); $('cancel').onclick = close;
        });
    }

    /* ---------- boot ---------- */
    window.EtelSafety = { open: openPanel, close: close, _tick: tick, _settings: settings };
    doc.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('[data-etel-safety-open]') : null;
        if (t) { e.preventDefault(); openPanel(); }
    });
    function start() { loadSettings(); watchSos(); setInterval(tick, 20000); setInterval(function () { if (!settings.loaded) loadSettings(); }, 120000); }
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', start); else start();
})();

/* ETEL Courier - package delivery (courier app release v7)
 *
 * A customer can send a package with no shop involved (api/lib/package_orders.php). This adds the courier side:
 *   - the job cards and the job offer say "package" and show the pickup ADDRESS (there is no shop to go to),
 *   - an accepted package has its own card: a progress strip, a trip clock (running timer for the leg in progress, clock time and duration
 *     of each finished leg, total), where to go, the contact, what it is, and the PIN step: the SENDER's pickup PIN picks it up, the
 *     RECIPIENT's delivery PIN delivers it (api actions courier_package_pickup / courier_package_deliver; 5 wrong PINs lock the step 15 minutes),
 *   - "Navigate" works INSIDE the app: it scrolls to the map and draws the route with distance and arrival time (the app's own
 *     drawRouteOnMap), and the route for the current leg is drawn automatically when a package becomes active. It no longer opens Google Maps,
 *   - when the package is delivered a short summary shows what was earned and how long each part took,
 *   - the app tells the server it can do all this (pkg=1 on its polls). The server never offers a package to an app that did not.
 * Shop orders are untouched: they still go through the original functions. This file is appended to the v5 bundle to make v7
 * (js/releases/courier/v7/courier-bundle.js, built by tools/build_courier_release.py) and is released to canary testers first.
 * It only wraps globals the bundle defines: apiGet, renderJobs, renderActiveOrders, etelOfferCardHtml, drawRouteOnMap, navigateToCustomer.
 * Times come from the server (order.pkg_times, epoch seconds on the database clock), so the phone's clock never matters.
 */
(function () {
    'use strict';
    if (window.__etelCourierPackages) return;
    window.__etelCourierPackages = 'v7';

    var SIZE = { document: 'Document or envelope', small: 'Small (shoebox)', medium: 'Medium (backpack)', large: 'Large (box, suitcase)' };

    function esc(s) {
        s = s == null ? '' : String(s);
        return typeof escapeHtml === 'function' ? escapeHtml(s) : s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function isPkg(o) { return !!o && o.order_kind === 'package'; }
    function money(n) { return 'ETB ' + (parseFloat(n) || 0).toFixed(2); }
    function toast(msg, kind) { if (typeof showToast === 'function') showToast(msg, kind); }

    // ---- styles (one block; light theme is body.etel-light) ----
    var CSS =
        '.pk7{--pk-gold:#D4AF37;--pk-text:#F5F5F7;--pk-dim:#A8A8B8;--pk-line:rgba(255,255,255,.12);--pk-panel:rgba(255,255,255,.05);--pk-ok:#2ECC71}' +
        'body.etel-light .pk7{--pk-gold:#8a6d00;--pk-text:#1A1A2E;--pk-dim:#5c5a70;--pk-line:rgba(0,0,0,.12);--pk-panel:rgba(0,0,0,.04);--pk-ok:#1b7f46}' +
        '.pk7 .pk7-top{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px}' +
        '.pk7 .pk7-code{font-weight:800;letter-spacing:.04em;color:var(--pk-text)}' +
        '.pk7 .pk7-pill{margin-left:auto;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;background:rgba(212,175,55,.16);color:var(--pk-gold);border:1px solid rgba(212,175,55,.4)}' +
        '.pk7 .pk7-steps{display:flex;align-items:center;gap:0;margin:2px 0 12px}' +
        '.pk7 .pk7-step{flex:1;text-align:center;font-size:11px;font-weight:700;color:var(--pk-dim)}' +
        '.pk7 .pk7-step i{display:block;width:22px;height:22px;margin:0 auto 4px;border-radius:50%;border:2px solid var(--pk-line);font-style:normal;font-size:12px;line-height:18px;color:#1a1200;box-sizing:border-box}' +
        '.pk7 .pk7-step.on{color:var(--pk-text)}.pk7 .pk7-step.on i{border-color:var(--pk-gold);box-shadow:0 0 0 0 rgba(212,175,55,.5);animation:pk7pulse 1.8s ease-out infinite}' +
        '.pk7 .pk7-step.done i{background:var(--pk-gold);border-color:var(--pk-gold)}' +
        '.pk7 .pk7-bar{flex:0 0 18%;height:2px;background:var(--pk-line);margin-bottom:18px}.pk7 .pk7-bar.done{background:var(--pk-gold)}' +
        '@keyframes pk7pulse{0%{box-shadow:0 0 0 0 rgba(212,175,55,.55)}70%,100%{box-shadow:0 0 0 9px rgba(212,175,55,0)}}' +
        '@media (prefers-reduced-motion:reduce){.pk7 .pk7-step.on i{animation:none}}' +
        '.pk7 .pk7-clock{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 14px;border-radius:14px;background:linear-gradient(135deg,rgba(212,175,55,.2),rgba(212,175,55,.05));border:1px solid rgba(212,175,55,.35);margin-bottom:12px}' +
        '.pk7 .pk7-clock-k{display:block;font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--pk-gold)}' +
        '.pk7 .pk7-clock-v{display:block;font-size:30px;font-weight:800;line-height:1.1;font-variant-numeric:tabular-nums;color:var(--pk-text)}' +
        '.pk7 .pk7-clock-side{text-align:right;font-size:12px;line-height:1.55;color:var(--pk-dim)}.pk7 .pk7-clock-side b{color:var(--pk-text);font-variant-numeric:tabular-nums}' +
        '.pk7 .pk7-earn{display:flex;align-items:baseline;justify-content:space-between;gap:8px;margin:2px 0 10px;padding:10px 14px;border-radius:12px;background:rgba(46,204,113,.12);border:1px solid rgba(46,204,113,.35)}' +
        '.pk7 .pk7-earn span{font-size:12px;font-weight:700;color:var(--pk-dim)}.pk7 .pk7-earn b{font-size:22px;color:var(--pk-ok);font-variant-numeric:tabular-nums}' +
        '.pk7 .pk7-place{display:flex;gap:11px;align-items:flex-start;margin:10px 0}.pk7 .pk7-place .ic{flex:0 0 34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:var(--pk-panel);border:1px solid var(--pk-line);font-size:17px}' +
        '.pk7 .pk7-place .k{font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--pk-dim)}.pk7 .pk7-place .v{font-size:15.5px;font-weight:700;line-height:1.3;color:var(--pk-text);overflow-wrap:anywhere}' +
        '.pk7 .pk7-place .w{font-size:12.5px;color:var(--pk-dim);margin-top:2px}' +
        '.pk7 .pk7-meta{display:flex;flex-wrap:wrap;gap:6px;margin:6px 0 10px}.pk7 .pk7-chip{padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600;background:var(--pk-panel);border:1px solid var(--pk-line);color:var(--pk-text)}' +
        '.pk7 .pk7-cash{margin:8px 0;padding:10px 12px;border-radius:10px;background:rgba(230,126,34,.14);border:1px solid rgba(230,126,34,.45);color:#F0A560;font-size:12.5px;font-weight:700}' +
        'body.etel-light .pk7 .pk7-cash{color:#8a4b00}' +
        '.pk7 .pk7-actions{display:flex;gap:8px;margin:10px 0 8px}.pk7 .pk7-nav{flex:1;display:flex;align-items:center;justify-content:center;gap:8px;padding:13px 14px;border:0;border-radius:12px;font-size:14.5px;font-weight:800;cursor:pointer;color:#1a1a2e;background:linear-gradient(135deg,#E5C76B,#D4AF37 55%,#b8922e)}' +
        '.pk7 .pk7-nav.drop{background:linear-gradient(135deg,#59e096,#2ECC71 55%,#1fa85a);color:#06220f}' +
        '.pk7 .pk7-call{display:flex;align-items:center;justify-content:center;gap:6px;padding:13px 14px;border-radius:12px;border:1px solid rgba(46,204,113,.5);color:var(--pk-ok);font-size:14px;font-weight:800;text-decoration:none;background:transparent}' +
        '.pk7 .pk7-pin{width:100%;padding:14px;border-radius:12px;border:1.5px solid var(--pk-gold);background:rgba(212,175,55,.1);color:var(--pk-text);font-size:14.5px;font-weight:800;cursor:pointer}' +
        '.pk7 .pk7-hint{font-size:11.5px;color:var(--pk-dim);margin-top:6px;text-align:center}' +
        '.pk7 .pk7-route{position:relative;margin:6px 0 10px}.pk7 .pk7-route .pk7-place{margin:0 0 12px}.pk7 .pk7-route .pk7-place:last-child{margin-bottom:0}' +
        '#pk7-eta{margin-top:4px;font-size:12px;font-weight:700;color:#4ECDC4}' +
        '#pkg-pin-overlay .pk7-pinbox{position:relative;margin:6px 0 4px}#pkg-pin-overlay .pk7-digits{display:flex;justify-content:center;gap:10px}' +
        '#pkg-pin-overlay .pk7-digit{width:54px;height:66px;border-radius:14px;border:1.5px solid rgba(212,175,55,.35);background:rgba(255,255,255,.06);display:flex;align-items:center;justify-content:center;font-size:30px;font-weight:800;color:#fff;font-variant-numeric:tabular-nums}' +
        '#pkg-pin-overlay .pk7-digit.cur{border-color:#D4AF37;box-shadow:0 0 0 3px rgba(212,175,55,.25)}' +
        '#pkg-pin-input{position:absolute;inset:0;width:100%;height:100%;opacity:0;font-size:16px;border:0;background:transparent;color:transparent;caret-color:transparent}';
    function injectCss() {
        if (document.getElementById('pk7-css') || !document.head) return;
        var s = document.createElement('style'); s.id = 'pk7-css'; s.textContent = CSS; document.head.appendChild(s);
    }
    injectCss();
    document.addEventListener('DOMContentLoaded', injectCss);

    // ---- 1. tell the server this app can run packages ----
    var origGet = window.apiGet;
    if (typeof origGet === 'function') {
        window.apiGet = function (action, params) {
            if (action === 'courier_get_my_orders' || action === 'courier_get_available_orders') params = Object.assign({ pkg: 1 }, params || {});
            return origGet.call(this, action, params);
        };
    }

    // ---- small pieces ----
    function callLink(phone, label) {
        if (!phone) return '';
        var p = String(phone).replace(/[^\d+]/g, '');
        return '<a class="pk7-call" href="tel:' + esc(p) + '" aria-label="' + esc(label) + '" title="' + esc(label) + '">📞 Call</a>';
    }
    function details(o) {
        var bits = [];
        if (o.pkg_size) bits.push(SIZE[o.pkg_size] || o.pkg_size);
        if (parseFloat(o.pkg_weight_kg) > 0) bits.push(parseFloat(o.pkg_weight_kg) + ' kg');
        if (parseFloat(o.distance_km) > 0) bits.push(parseFloat(o.distance_km) + ' km trip');
        return bits.join(' · ');
    }
    function chips(o) {
        var c = details(o).split(' · ').filter(Boolean);
        if (o.pkg_desc) c.push(o.pkg_desc);
        return c.length ? '<div class="pk7-meta">' + c.map(function (x) { return '<span class="pk7-chip">' + esc(x) + '</span>'; }).join('') + '</div>' : '';
    }
    function place(icon, kind, addr, who) {
        return '<div class="pk7-place"><span class="ic" aria-hidden="true">' + icon + '</span><div><div class="k">' + esc(kind) + '</div><div class="v">' + esc(addr) + '</div>' + (who ? '<div class="w">' + esc(who) + '</div>' : '') + '</div></div>';
    }

    // ---- times: server epoch seconds, so the phone's clock never matters ----
    var TM = { offset: 0, byId: {}, timer: null };
    function nowSec() { return Math.floor(Date.now() / 1000) - TM.offset; }
    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function clockText(sec) { sec = Math.max(0, sec); var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), x = sec % 60; return (h ? h + ':' + pad2(m) : pad2(m)) + ':' + pad2(x); }
    function durText(sec) {
        sec = Math.max(0, Math.round(sec));
        if (sec < 60) return sec + ' s';
        var m = Math.floor(sec / 60);
        if (m < 60) return m + ' min' + (m < 10 ? ' ' + pad2(sec % 60) + ' s' : '');
        return Math.floor(m / 60) + ' h ' + pad2(m % 60) + ' min';
    }
    function wallClock(sec) { try { return new Date(sec * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); } catch (e) { return ''; } }
    function learnTimes(orders) {
        (orders || []).forEach(function (o) {
            if (o && o.pkg_times) { TM.byId[o.id] = o.pkg_times; if (o.pkg_times.now) TM.offset = Math.floor(Date.now() / 1000) - o.pkg_times.now; }
        });
    }
    function tick() {
        var els = document.querySelectorAll('[data-pt-from]');
        if (!els.length) { clearInterval(TM.timer); TM.timer = null; return; }
        Array.prototype.forEach.call(els, function (el) { el.textContent = clockText(nowSec() - parseInt(el.getAttribute('data-pt-from'), 10)); });
    }
    function startTick() { if (!TM.timer) TM.timer = setInterval(tick, 1000); }

    // ---- in-app navigation ----
    // The app already draws a route (with distance and time) on its own map: use it. No external map is opened.
    function mapReady() { return typeof google !== 'undefined' && google.maps && google.maps.DirectionsService; }
    function navTo(lat, lng, addr, label, scroll) {
        if (scroll !== false) {
            var c = document.getElementById('courier-map-container') || document.getElementById('map');
            try { if (c && c.scrollIntoView) c.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
        }
        if (typeof navigateToCustomer === 'function') navigateToCustomer(lat, lng, label || addr);
        else toast('Map not ready. Try again in a moment.', 'err');
    }
    var routed = {};   // order id + leg, so the route is drawn once per leg and a courier who clears it is not nagged
    function autoRoute(o) {
        var picked = o.status === 'picked_up' || o.status === 'in_transit';
        var key = o.id + ':' + (picked ? 'drop' : 'pick');
        if (routed[key] || !mapReady()) return;
        routed[key] = true;
        var t = leg(o, picked);
        navTo(t.lat, t.lng, t.addr, t.label, false);
    }
    function leg(o, picked) {
        return picked
            ? { lat: o.delivery_lat, lng: o.delivery_lng, addr: o.recipient_address || o.to_zone, label: 'Drop-off · ' + (o.recipient_address || o.to_zone || '') }
            : { lat: o.pickup_lat, lng: o.pickup_lng, addr: o.pickup_address || o.from_zone, label: 'Pickup · ' + (o.pickup_address || o.from_zone || '') };
    }
    // arrival time next to the route's distance and duration
    var etaPatched = false;
    function patchEta() {
        if (etaPatched || typeof dirRenderer === 'undefined' || !dirRenderer || typeof dirRenderer.setDirections !== 'function') return;
        etaPatched = true;
        var orig = dirRenderer.setDirections;
        dirRenderer.setDirections = function (res) {
            var r = orig.apply(this, arguments);
            try {
                var secs = res && res.routes && res.routes[0] && res.routes[0].legs[0].duration.value;
                var panel = document.getElementById('map-route-panel');
                if (panel && secs) {
                    var el = document.getElementById('pk7-eta');
                    if (!el) { el = document.createElement('div'); el.id = 'pk7-eta'; panel.insertBefore(el, panel.firstChild); }
                    el.textContent = 'Arrive about ' + wallClock(Math.floor(Date.now() / 1000) + secs);
                } else { var e2 = document.getElementById('pk7-eta'); if (e2) e2.textContent = ''; }
            } catch (e) { /* the route itself is already drawn */ }
            return r;
        };
    }
    var origDraw = window.drawRouteOnMap;
    if (typeof origDraw === 'function') {
        window.drawRouteOnMap = function () { var r = origDraw.apply(this, arguments); patchEta(); return r; };
    }

    // ---- 2. available jobs ----
    function jobCard(o) {
        var d = typeof jobDistanceKm === 'function' ? jobDistanceKm(o) : null;
        var dist = d !== null && d !== undefined ? d.toFixed(1) + ' km away' : '';
        return '<div class="job-card pk7" data-pkg-card="' + o.id + '">' +
            '<div class="pk7-top"><span class="pk7-code">' + esc(o.order_code) + '</span>' +
            '<span class="job-wait" data-placed="' + esc(o.placed_at || '') + '"></span>' +
            '<span class="pk7-pill">📦 Package</span>' +
            '<button type="button" data-pkg-act="mute" data-id="' + o.id + '" style="padding:4px 9px;border:1px solid rgba(255,255,255,.2);background:transparent;color:#A8A8B8;border-radius:8px;font-size:11px;cursor:pointer" aria-label="Mute the job ring">🔕</button></div>' +
            '<div class="pk7-route">' + place('📍', 'Pickup' + (dist ? ' · ' + dist : ''), o.pickup_address || o.from_zone || '—') +
            place('🏁', 'Drop-off', o.recipient_address || o.to_zone || '—') + '</div>' + chips(o) +
            '<div class="pk7-earn"><span>You earn</span><b>' + money(o.delivery_fee) + '</b></div>' +
            (o.payment_method && typeof paymentBadgeHtml === 'function' ? '<div style="margin-bottom:8px">' + paymentBadgeHtml(o.payment_method) + '</div>' : '') +
            '<div class="job-actions"><button type="button" class="btn btn-primary" data-pkg-act="accept" data-id="' + o.id + '" data-code="' + esc(o.order_code) + '">Accept Job</button>' +
            '<button type="button" class="btn btn-secondary" data-pkg-act="skip" data-id="' + o.id + '">Skip</button></div></div>';
    }

    var origJobs = window.renderJobs;
    if (typeof origJobs === 'function') {
        window.renderJobs = function (list) {
            list = list || [];
            var pk = list.filter(isPkg), rest = list.filter(function (o) { return !isPkg(o); });
            origJobs(rest);
            if (!pk.length) return;
            var box = document.getElementById('jobs-content');
            if (!box) return;
            if (!rest.length) box.innerHTML = '';
            box.insertAdjacentHTML('afterbegin', pk.map(jobCard).join(''));
        };
    }

    // ---- 3. the timed job offer ----
    var origOffer = window.etelOfferCardHtml;
    if (typeof origOffer === 'function') {
        window.etelOfferCardHtml = function (o) {
            var h = origOffer(o);
            if (!isPkg(o)) return h;
            return h.replace('NEW JOB OFFER', 'NEW PACKAGE OFFER')
                .replace('<span class="job-address-icon">🏪</span>', '<span class="job-address-icon">📦</span>')
                .replace(/<span>Pickup: <strong>[^<]*<\/strong><\/span>/, '<span>Pickup: <strong>' + esc(o.pickup_address || o.from_zone || 'Sender') + '</strong></span>');
        };
    }

    // ---- 4. an accepted package ----
    function clockBlock(o, picked) {
        var t = o.pkg_times;
        if (!t || !t.accepted) return '';
        var legFrom = picked ? (t.picked_up || t.accepted) : t.accepted;
        var side = '<div>Accepted <b>' + esc(wallClock(t.accepted)) + '</b></div>';
        if (picked && t.picked_up) side += '<div>Collected <b>' + esc(wallClock(t.picked_up)) + '</b> (' + esc(durText(t.picked_up - t.accepted)) + ')</div>';
        side += '<div>Total <b data-pt-from="' + t.accepted + '">' + clockText(nowSec() - t.accepted) + '</b></div>';
        return '<div class="pk7-clock"><div><span class="pk7-clock-k">' + (picked ? 'To the recipient' : 'To the pickup') + '</span>' +
            '<span class="pk7-clock-v" data-pt-from="' + legFrom + '">' + clockText(nowSec() - legFrom) + '</span></div><div class="pk7-clock-side">' + side + '</div></div>';
    }
    function stepStrip(picked) {
        return '<div class="pk7-steps" aria-label="Progress">' +
            '<div class="pk7-step ' + (picked ? 'done' : 'on') + '"><i>' + (picked ? '✓' : '') + '</i>Pickup</div><div class="pk7-bar ' + (picked ? 'done' : '') + '"></div>' +
            '<div class="pk7-step ' + (picked ? 'on' : '') + '"><i></i>Drop-off</div><div class="pk7-bar"></div>' +
            '<div class="pk7-step"><i></i>Done</div></div>';
    }
    function activeCard(o) {
        var picked = o.status === 'picked_up' || o.status === 'in_transit';
        var t = leg(o, picked);
        var cash = (!picked && o.payment_method === 'cash')
            ? '<div class="pk7-cash">💵 Cash package: collect ' + money(o.fare_total) + ' from the sender when you pick it up.</div>' : '';
        var who = picked ? place('🏁', 'Deliver to', o.recipient_address || o.to_zone || '—', o.recipient_name || 'Recipient')
                         : place('📍', 'Collect from', o.pickup_address || o.from_zone || '—', o.sender_name ? 'Sender: ' + o.sender_name : '');
        var phone = picked ? o.recipient_phone : o.sender_phone;
        return '<div class="job-card pk7" data-pkg-card="' + o.id + '">' +
            '<div class="pk7-top"><span class="pk7-code">' + esc(o.order_code) + '</span><span class="pk7-pill">' + (picked ? '🚚 Take it to the recipient' : '📦 Collect from the sender') + '</span></div>' +
            stepStrip(picked) + clockBlock(o, picked) +
            '<div class="pk7-earn"><span>You earn</span><b>' + money(o.delivery_fee) + '</b></div>' + who + chips(o) + cash +
            '<div class="pk7-actions"><button type="button" class="pk7-nav' + (picked ? ' drop' : '') + '" data-pkg-act="nav" data-lat="' + esc(t.lat || '') + '" data-lng="' + esc(t.lng || '') + '" data-addr="' + esc(t.addr || '') + '" data-label="' + esc(t.label) + '">🧭 Navigate ' + (picked ? 'to drop-off' : 'to pickup') + '</button>' +
            callLink(phone, picked ? 'Call recipient' : 'Call sender') + '</div>' +
            '<button type="button" class="pk7-pin" data-pkg-act="' + (picked ? 'deliver' : 'pickup') + '" data-id="' + o.id + '" data-code="' + esc(o.order_code) + '">🔢 ' +
            (picked ? 'Enter the recipient’s delivery PIN' : 'Enter the sender’s pickup PIN') + '</button>' +
            '<div class="pk7-hint">' + (picked ? 'The recipient reads the 4-digit PIN to you. It confirms they received it.' : 'The sender shows the 4-digit PIN in their app. It confirms you have the right package.') + '</div></div>';
    }

    var origActive = window.renderActiveOrders;
    if (typeof origActive === 'function') {
        window.renderActiveOrders = function (orders) {
            orders = orders || [];
            learnTimes(orders);
            var mine = orders.filter(function (o) { return isPkg(o) && !o.offer_pending; });
            var rest = orders.filter(function (o) { return !(isPkg(o) && !o.offer_pending); });
            origActive(rest);
            window._activeOrders = orders;
            if (!mine.length) return;
            var box = document.getElementById('active-content');
            if (!box) return;
            if (!rest.length) box.innerHTML = '';
            box.insertAdjacentHTML('afterbegin', mine.map(activeCard).join(''));
            startTick();
            autoRoute(mine[0]);   // the first package's current leg is drawn on the map without a tap
        };
    }

    // ---- 5. the PIN dialog: four boxes over one real input (paste, autofill and the numeric keypad all work) ----
    function closePin() { var el = document.getElementById('pkg-pin-overlay'); if (el) el.remove(); }

    function openPin(kind, id, code) {
        closePin();
        var pickup = kind === 'pickup';
        var ov = document.createElement('div');
        ov.id = 'pkg-pin-overlay';
        ov.setAttribute('role', 'dialog');
        ov.setAttribute('aria-modal', 'true');
        ov.setAttribute('aria-label', pickup ? 'Pickup PIN' : 'Delivery PIN');
        ov.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.72);display:flex;align-items:center;justify-content:center;padding:18px';
        ov.innerHTML = '<div style="width:100%;max-width:340px;background:#1b1b2b;border:1px solid rgba(212,175,55,.4);border-radius:18px;padding:20px;color:#fff;font-family:inherit">' +
            '<div style="font-size:16px;font-weight:800;margin-bottom:4px">' + (pickup ? 'Sender’s pickup PIN' : 'Recipient’s delivery PIN') + '</div>' +
            '<div style="font-size:12.5px;color:#A8A8B8;margin-bottom:14px">' + esc(code || '') + ' · ' + (pickup ? 'Ask the sender to open this package in their app and read you the 4 digits.' : 'Ask the recipient for the 4-digit PIN they were given.') + '</div>' +
            '<div class="pk7-pinbox"><div class="pk7-digits" aria-hidden="true"><div class="pk7-digit"></div><div class="pk7-digit"></div><div class="pk7-digit"></div><div class="pk7-digit"></div></div>' +
            '<input id="pkg-pin-input" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="4" autocomplete="one-time-code" aria-label="4-digit PIN"></div>' +
            '<div id="pkg-pin-err" role="alert" style="min-height:18px;margin:10px 0 8px;font-size:12.5px;font-weight:700;color:#ff8a8a !important;text-align:center"></div>' +
            '<div style="display:flex;gap:10px"><button type="button" id="pkg-pin-cancel" style="flex:1;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.2);background:transparent;color:#ccc;font-size:14px;cursor:pointer">Cancel</button>' +
            '<button type="button" id="pkg-pin-ok" style="flex:2;padding:12px;border-radius:10px;border:0;background:linear-gradient(135deg,#D4AF37,#b8922e);color:#1a1a2e;font-size:14px;font-weight:800;cursor:pointer">' + (pickup ? 'Confirm pickup' : 'Confirm delivery') + '</button></div></div>';
        document.body.appendChild(ov);
        var input = document.getElementById('pkg-pin-input'), err = document.getElementById('pkg-pin-err'), ok = document.getElementById('pkg-pin-ok');
        var boxes = ov.querySelectorAll('.pk7-digit');
        function paint() {
            var v = input.value;
            Array.prototype.forEach.call(boxes, function (b, i) { b.textContent = v.charAt(i); b.className = 'pk7-digit' + (i === Math.min(v.length, 3) ? ' cur' : ''); });
        }
        input.addEventListener('input', function () { input.value = input.value.replace(/\D/g, '').slice(0, 4); paint(); });
        paint();
        document.getElementById('pkg-pin-cancel').addEventListener('click', closePin);
        ov.addEventListener('click', function (e) { if (e.target === ov) closePin(); });
        ov.addEventListener('keydown', function (e) { if (e.key === 'Escape') closePin(); if (e.key === 'Enter') submit(); });
        ok.addEventListener('click', submit);
        setTimeout(function () { try { input.focus(); } catch (e) {} }, 50);

        function submit() {
            var pin = input.value.trim();
            if (!/^\d{4}$/.test(pin)) { err.textContent = 'Enter the 4-digit PIN.'; return; }
            ok.disabled = true; err.textContent = '';
            api(pickup ? 'courier_package_pickup' : 'courier_package_deliver', { order_id: id, pin: pin }).then(function (res) {
                if (res && res.success) {
                    closePin();
                    if (pickup) toast((res.data && res.data.message) || 'Picked up');
                    else showSummary(id, (res.data && res.data.message) || 'Delivered');
                    if (typeof loadActiveOrders === 'function') loadActiveOrders();
                } else {
                    ok.disabled = false;
                    err.textContent = (res && res.message) || 'Could not check the PIN. Try again.';
                    input.value = ''; paint(); input.focus();
                }
            }).catch(function () { ok.disabled = false; err.textContent = 'No connection. Try again.'; });
        }
    }

    // ---- 5b. the delivered summary: what was earned and how long each part took ----
    function showSummary(id, message) {
        var t = TM.byId[id];
        var earn = '';
        try { var o = (window._activeOrders || []).filter(function (x) { return x.id == id; })[0]; if (o) earn = money(o.delivery_fee); } catch (e) {}
        var lines = '';
        if (t && t.accepted) {
            var end = nowSec();
            lines = '<div style="display:flex;justify-content:space-between;padding:7px 0;border-top:1px solid rgba(255,255,255,.1)"><span>To the pickup</span><b>' + esc(durText((t.picked_up || end) - t.accepted)) + '</b></div>' +
                (t.picked_up ? '<div style="display:flex;justify-content:space-between;padding:7px 0;border-top:1px solid rgba(255,255,255,.1)"><span>To the recipient</span><b>' + esc(durText(end - t.picked_up)) + '</b></div>' : '') +
                '<div style="display:flex;justify-content:space-between;padding:9px 0 2px;border-top:1px solid rgba(255,255,255,.18);font-size:15px"><span>Total</span><b style="color:#D4AF37">' + esc(durText(end - t.accepted)) + '</b></div>';
        }
        var ov = document.createElement('div');
        ov.id = 'pkg-done-overlay';
        ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true'); ov.setAttribute('aria-label', 'Delivered');
        ov.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.72);display:flex;align-items:center;justify-content:center;padding:18px';
        ov.innerHTML = '<div style="width:100%;max-width:340px;background:#1b1b2b;border:1px solid rgba(46,204,113,.5);border-radius:18px;padding:22px;color:#fff;text-align:center;font-family:inherit">' +
            '<div style="font-size:40px">✅</div><div style="font-size:19px;font-weight:800;margin:4px 0">Delivered</div>' +
            '<div style="font-size:13px;color:#A8A8B8;margin-bottom:10px">' + esc(message) + '</div>' +
            (earn ? '<div style="font-size:26px;font-weight:800;color:#2ECC71;margin:6px 0 12px">' + esc(earn) + '</div>' : '') +
            '<div style="text-align:left;font-size:13px;color:#d6d6e0">' + lines + '</div>' +
            '<button type="button" id="pkg-done-ok" style="width:100%;margin-top:16px;padding:13px;border-radius:12px;border:0;background:linear-gradient(135deg,#D4AF37,#b8922e);color:#1a1a2e;font-size:14.5px;font-weight:800;cursor:pointer">Done</button></div>';
        document.body.appendChild(ov);
        var ok = document.getElementById('pkg-done-ok');
        ok.addEventListener('click', function () { ov.remove(); });
        ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
        try { ok.focus(); } catch (e) {}
    }

    // ---- 6. the buttons on the cards (one listener; the cards carry data-pkg-act) ----
    document.addEventListener('click', function (e) {
        var b = e.target && e.target.closest ? e.target.closest('[data-pkg-act]') : null;
        if (!b) return;
        var id = parseInt(b.getAttribute('data-id'), 10), code = b.getAttribute('data-code') || '', act = b.getAttribute('data-pkg-act');
        if (act === 'accept' && typeof acceptJob === 'function') acceptJob(id, code);
        else if (act === 'skip' && typeof skipJob === 'function') skipJob(id);
        else if (act === 'mute' && typeof muteJobRing === 'function') muteJobRing(id, b);
        else if (act === 'nav') navTo(b.getAttribute('data-lat'), b.getAttribute('data-lng'), b.getAttribute('data-addr'), b.getAttribute('data-label'), true);
        else if (act === 'pickup' || act === 'deliver') openPin(act, id, code);
    });
})();

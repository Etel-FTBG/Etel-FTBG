/* ETEL Courier - package delivery (courier app release v6)
 *
 * A customer can send a package with no shop involved (api/lib/package_orders.php). This adds the courier side:
 *   - the job cards and the job offer say "package" and show the pickup ADDRESS (there is no shop to go to),
 *   - an accepted package has its own card: where to collect it, the sender, what it is, where it goes, the recipient,
 *     a Maps link for each leg, and the PIN step: the SENDER's pickup PIN picks it up, the RECIPIENT's delivery PIN delivers it
 *     (api actions courier_package_pickup / courier_package_deliver; 5 wrong PINs lock the step for 15 minutes),
 *   - the app tells the server it can do all this (pkg=1 on its polls). The server never offers a package to an app that did not.
 * Shop orders are untouched: they still go through the original functions. This file is appended to the v5 bundle to make v6
 * (js/releases/courier/v6/courier-bundle.js, built by tools/build_courier_release.py) and is released to canary testers first.
 * It only wraps globals the bundle defines: apiGet, renderJobs, renderActiveOrders, etelOfferCardHtml.
 */
(function () {
    'use strict';
    if (window.__etelCourierPackages) return;
    window.__etelCourierPackages = true;

    var SIZE = { document: 'Document or envelope', small: 'Small (shoebox)', medium: 'Medium (backpack)', large: 'Large (box, suitcase)' };

    function esc(s) {
        s = s == null ? '' : String(s);
        return typeof escapeHtml === 'function' ? escapeHtml(s) : s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function isPkg(o) { return !!o && o.order_kind === 'package'; }
    function money(n) { return 'ETB ' + (parseFloat(n) || 0).toFixed(2); }
    function toast(msg, kind) { if (typeof showToast === 'function') showToast(msg, kind); }

    // ---- 1. tell the server this app can run packages ----
    var origGet = window.apiGet;
    if (typeof origGet === 'function') {
        window.apiGet = function (action, params) {
            if (action === 'courier_get_my_orders' || action === 'courier_get_available_orders') params = Object.assign({ pkg: 1 }, params || {});
            return origGet.call(this, action, params);
        };
    }

    // ---- small pieces ----
    function mapsLink(lat, lng, address) {
        var la = parseFloat(lat), ln = parseFloat(lng);
        var dest = (la && ln) ? la + ',' + ln : encodeURIComponent((address || '') + ', Hawassa');
        return 'https://www.google.com/maps/dir/?api=1&destination=' + dest + '&travelmode=driving';
    }
    function callLink(phone, label) {
        if (!phone) return '';
        var p = String(phone).replace(/[^\d+]/g, '');
        return '<a href="tel:' + esc(p) + '" style="display:inline-block;padding:7px 12px;border-radius:8px;border:1px solid rgba(46,204,113,.45);color:#2ECC71;font-size:12.5px;font-weight:700;text-decoration:none">📞 ' + esc(label) + '</a>';
    }
    function details(o) {
        var bits = [];
        if (o.pkg_size) bits.push(SIZE[o.pkg_size] || o.pkg_size);
        if (parseFloat(o.pkg_weight_kg) > 0) bits.push(parseFloat(o.pkg_weight_kg) + ' kg');
        if (parseFloat(o.distance_km) > 0) bits.push(parseFloat(o.distance_km) + ' km trip');
        return bits.join(' · ');
    }
    function row(icon, html) {
        return '<div class="job-address" style="margin-top:6px"><span class="job-address-icon">' + icon + '</span><span>' + html + '</span></div>';
    }

    // ---- 2. available jobs ----
    function jobCard(o) {
        var d = typeof jobDistanceKm === 'function' ? jobDistanceKm(o) : null;
        var dist = d !== null && d !== undefined ? '<span class="dist-badge">' + d.toFixed(1) + ' km away</span>' : '';
        return '<div class="job-card" data-pkg-card="' + o.id + '">' +
            '<div class="job-header"><span class="job-id">' + esc(o.order_code) + '</span>' +
            '<span class="job-wait" data-placed="' + esc(o.placed_at || '') + '"></span>' +
            '<span class="job-status">📦 PACKAGE</span>' +
            '<button type="button" data-pkg-act="mute" data-id="' + o.id + '" style="margin-left:8px;padding:4px 9px;border:1px solid rgba(255,255,255,.2);background:transparent;color:#A8A8B8;border-radius:8px;font-size:11px;cursor:pointer">🔇 Mute</button></div>' +
            '<div class="job-info">' +
            row('📦', 'Pickup: <strong>' + esc(o.pickup_address || o.from_zone || '—') + '</strong> ' + dist) +
            row('📍', 'Dropoff: ' + esc(o.recipient_address || o.to_zone || '—')) +
            '<div class="job-meta"><div class="job-meta-item">📦 ' + esc(details(o) || 'package') + '</div>' +
            (o.pkg_desc ? '<div class="job-meta-item">' + esc(o.pkg_desc) + '</div>' : '') +
            '<div class="job-meta-item">🕐 ' + (typeof fmtDate === 'function' ? esc(fmtDate(o.placed_at)) : '') + '</div>' +
            (o.payment_method && typeof paymentBadgeHtml === 'function' ? '<div class="job-meta-item">' + paymentBadgeHtml(o.payment_method) + '</div>' : '') + '</div>' +
            '<div class="job-fee">You earn: ' + money(o.delivery_fee) + '</div>' +
            '<div class="job-actions"><button type="button" class="btn btn-primary" data-pkg-act="accept" data-id="' + o.id + '" data-code="' + esc(o.order_code) + '">Accept Job</button>' +
            '<button type="button" class="btn btn-secondary" data-pkg-act="skip" data-id="' + o.id + '">Skip</button></div></div></div>';
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
    function activeCard(o) {
        var picked = o.status === 'picked_up' || o.status === 'in_transit';
        var title = picked ? '🚚 Take it to the recipient' : '📦 Collect it from the sender';
        var cash = (!picked && o.payment_method === 'cash')
            ? '<div style="margin:8px 0;padding:9px 11px;border-radius:9px;background:rgba(230,126,34,.14);border:1px solid rgba(230,126,34,.45);color:#F0A560;font-size:12.5px;font-weight:700">💵 Cash package: collect ' + money(o.fare_total) + ' from the sender when you pick it up.</div>' : '';
        var leg;
        if (!picked) {
            leg = row('📍', 'Collect from: <strong>' + esc(o.pickup_address || o.from_zone || '—') + '</strong>') +
                (o.sender_name ? row('👤', 'Sender: ' + esc(o.sender_name)) : '') +
                '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0">' +
                '<a href="' + esc(mapsLink(o.pickup_lat, o.pickup_lng, o.pickup_address || o.from_zone)) + '" target="_blank" rel="noopener" style="display:inline-block;padding:9px 14px;border-radius:9px;background:linear-gradient(135deg,#D4AF37,#b8922e);color:#1a1a2e;font-size:13px;font-weight:800;text-decoration:none">🗺️ Navigate to pickup</a>' +
                callLink(o.sender_phone, 'Call sender') + '</div>';
        } else {
            leg = row('📍', 'Deliver to: <strong>' + esc(o.recipient_address || o.to_zone || '—') + '</strong>') +
                row('👤', 'Recipient: ' + esc(o.recipient_name || 'Recipient')) +
                '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0">' +
                '<a href="' + esc(mapsLink(o.delivery_lat, o.delivery_lng, o.recipient_address || o.to_zone)) + '" target="_blank" rel="noopener" style="display:inline-block;padding:9px 14px;border-radius:9px;background:linear-gradient(135deg,#2ECC71,#27ae60);color:#fff;font-size:13px;font-weight:800;text-decoration:none">🗺️ Navigate to drop-off</a>' +
                callLink(o.recipient_phone, 'Call recipient') + '</div>';
        }
        return '<div class="job-card" data-pkg-card="' + o.id + '">' +
            '<div class="job-header"><span class="job-id">' + esc(o.order_code) + '</span><span class="job-status">' + title + '</span></div>' +
            '<div class="job-info">' + leg +
            row('📦', esc(details(o) || 'Package') + (o.pkg_desc ? ' · ' + esc(o.pkg_desc) : '')) + cash +
            '<div class="job-fee">You earn: ' + money(o.delivery_fee) + '</div>' +
            '<button type="button" data-pkg-act="' + (picked ? 'deliver' : 'pickup') + '" data-id="' + o.id + '" data-code="' + esc(o.order_code) + '" style="width:100%;margin-top:8px;padding:13px;font-size:14px;font-weight:800;background:rgba(212,175,55,.16);color:#D4AF37;border:1px solid rgba(212,175,55,.5);border-radius:10px;cursor:pointer">' +
            (picked ? '🔢 Enter the recipient’s delivery PIN' : '🔢 Enter the sender’s pickup PIN') + '</button>' +
            '<div style="font-size:11.5px;color:#A8A8B8;margin-top:6px;text-align:center">' +
            (picked ? 'The recipient reads the 4-digit PIN to you. It confirms they received it.' : 'The sender shows the 4-digit PIN in their app. It confirms you have the right package.') + '</div>' +
            '</div></div>';
    }

    var origActive = window.renderActiveOrders;
    if (typeof origActive === 'function') {
        window.renderActiveOrders = function (orders) {
            orders = orders || [];
            var mine = orders.filter(function (o) { return isPkg(o) && !o.offer_pending; });
            var rest = orders.filter(function (o) { return !(isPkg(o) && !o.offer_pending); });
            origActive(rest);
            window._activeOrders = orders;
            if (!mine.length) return;
            var box = document.getElementById('active-content');
            if (!box) return;
            if (!rest.length) box.innerHTML = '';
            box.insertAdjacentHTML('afterbegin', mine.map(activeCard).join(''));
        };
    }

    // ---- 5. the PIN dialog ----
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
        ov.innerHTML = '<div style="width:100%;max-width:340px;background:#1b1b2b;border:1px solid rgba(212,175,55,.4);border-radius:16px;padding:20px;color:#fff;font-family:inherit">' +
            '<div style="font-size:16px;font-weight:800;margin-bottom:4px">' + (pickup ? 'Sender’s pickup PIN' : 'Recipient’s delivery PIN') + '</div>' +
            '<div style="font-size:12.5px;color:#A8A8B8;margin-bottom:12px">' + esc(code || '') + ' · ' + (pickup ? 'Ask the sender to open this package in their app and read you the 4 digits.' : 'Ask the recipient for the 4-digit PIN. Do not hand the package over without it.') + '</div>' +
            '<input id="pkg-pin-input" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="4" autocomplete="one-time-code" aria-label="4-digit PIN" placeholder="••••" style="width:100%;box-sizing:border-box;padding:14px;font-size:28px;letter-spacing:.5em;text-align:center;border-radius:10px;border:1.5px solid rgba(212,175,55,.5);background:#0f0f1a !important;color:#fff !important">' +
            '<div id="pkg-pin-err" role="alert" style="min-height:18px;margin:8px 0;font-size:12.5px;font-weight:700;color:#ff8a8a !important"></div>' +
            '<div style="display:flex;gap:10px"><button type="button" id="pkg-pin-cancel" style="flex:1;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.2);background:transparent;color:#ccc;font-size:14px;cursor:pointer">Cancel</button>' +
            '<button type="button" id="pkg-pin-ok" style="flex:2;padding:12px;border-radius:10px;border:0;background:linear-gradient(135deg,#D4AF37,#b8922e);color:#1a1a2e;font-size:14px;font-weight:800;cursor:pointer">' + (pickup ? 'Confirm pickup' : 'Confirm delivery') + '</button></div></div>';
        document.body.appendChild(ov);
        var input = document.getElementById('pkg-pin-input'), err = document.getElementById('pkg-pin-err'), ok = document.getElementById('pkg-pin-ok');
        input.addEventListener('input', function () { input.value = input.value.replace(/\D/g, '').slice(0, 4); });
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
                    toast((res.data && res.data.message) || (pickup ? 'Picked up' : 'Delivered'));
                    if (typeof loadActiveOrders === 'function') loadActiveOrders();
                } else {
                    ok.disabled = false;
                    err.textContent = (res && res.message) || 'Could not check the PIN. Try again.';
                    input.select();
                }
            }).catch(function () { ok.disabled = false; err.textContent = 'No connection. Try again.'; });
        }
    }

    // ---- 6. the buttons on the cards (one listener; the cards carry data-pkg-act) ----
    document.addEventListener('click', function (e) {
        var b = e.target && e.target.closest ? e.target.closest('[data-pkg-act]') : null;
        if (!b) return;
        var id = parseInt(b.getAttribute('data-id'), 10), code = b.getAttribute('data-code') || '', act = b.getAttribute('data-pkg-act');
        if (act === 'accept' && typeof acceptJob === 'function') acceptJob(id, code);
        else if (act === 'skip' && typeof skipJob === 'function') skipJob(id);
        else if (act === 'mute' && typeof muteJobRing === 'function') muteJobRing(id, b);
        else if (act === 'pickup' || act === 'deliver') openPin(act, id, code);
    });
})();

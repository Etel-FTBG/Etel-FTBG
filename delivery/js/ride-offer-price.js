/* ETEL Ride - offer your own price (additive script, no bundle release).

   1. Book screen: "Offer your own price" under the fare card. The rider picks a price inside the allowed band (asked from
      ride_offer_bounds) and it is sent with the booking (book_ride gets offer_fare).
   2. Searching screen: "Offers from drivers". Drivers can accept the offered price or counter it once; the rider sees the
      counters (ride_counter_list), picks one (ride_counter_accept), declines one, or raises their own offer
      (ride_raise_offer). When a driver is chosen the price is fixed: the trip is billed at exactly that price.
   Backend: api/lib/ride_price_offer.php. Text is translated by ride-i18n.js (Amharic). Fails quietly. */
(function () {
    'use strict';
    if (window.__etelRideOfferPrice) return;
    window.__etelRideOfferPrice = true;

    var on = false, offer = 0, bounds = null, lastKey = '', pollTimer = null, step = 10;

    function token() { try { return (window.Auth && Auth.getToken) ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); } catch (e) { return ''; } }
    function apiUrl() { return (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php'; }
    var realFetch = window.fetch ? window.fetch.bind(window) : null;
    function api(action, body) {
        return realFetch(apiUrl(), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() },
            body: JSON.stringify(Object.assign({ action: action }, body || {})) }).then(function (r) { return r.json(); });
    }
    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function fareShown() {
        var el = document.getElementById('fare-amount');
        var m = el ? String(el.textContent || '').replace(/,/g, '').match(/(\d+(?:\.\d+)?)/) : null;
        return m ? parseFloat(m[1]) : 0;
    }
    function rideId() { try { return (window.RIDE && (RIDE.currentRideId || RIDE.currentRequestId)) || 0; } catch (e) { return 0; } }

    /* ---------- send the offer with the booking ---------- */
    if (realFetch) {
        window.fetch = function (input, init) {
            try {
                if (on && offer > 0 && init && typeof init.body === 'string' && init.body.indexOf('"book_ride"') !== -1) {
                    var b = JSON.parse(init.body);
                    if (b && b.action === 'book_ride' && !b.offer_fare) {
                        b.offer_fare = offer;
                        init = Object.assign({}, init, { body: JSON.stringify(b) });
                        var p = realFetch(input, init);
                        p.then(function (r) { return r.clone().json(); }).then(function (j) { if (j && j.success) { on = false; renderBox(); } }).catch(function () {});
                        return p;
                    }
                }
            } catch (e) { /* send the booking untouched */ }
            return realFetch(input, init);
        };
    }

    /* ---------- Book screen ---------- */
    function box() {
        var b = document.getElementById('rop-box');
        if (b) return b;
        var anchor = document.getElementById('fare-card');
        if (!anchor || !anchor.parentNode) return null;
        b = document.createElement('div'); b.id = 'rop-box';
        anchor.parentNode.insertBefore(b, anchor.nextSibling);
        return b;
    }
    function clamp(v) {
        if (!bounds) return v;
        return Math.max(bounds.min, Math.min(bounds.max, v));
    }
    function renderBox() {
        var b = box(); if (!b) return;
        var fare = fareShown();
        var card = document.getElementById('fare-card');
        var visible = card && card.style.display !== 'none' && fare > 0;
        if (!visible || (bounds && bounds.enabled === false)) { b.style.display = 'none'; return; }
        b.style.display = '';
        if (!on) {
            b.innerHTML = '<button type="button" class="rop-link" id="rop-open"><span class="rop-ico" aria-hidden="true">&#128176;</span><span>Offer your own price</span></button>';
            document.getElementById('rop-open').onclick = openOffer;
            return;
        }
        var lo = bounds ? bounds.min : '', hi = bounds ? bounds.max : '';
        b.innerHTML = '<div class="rop-panel"><div class="rop-top"><b>Your offer</b><button type="button" class="rop-x" id="rop-off" aria-label="Remove">&times;</button></div>' +
            '<div class="rop-step"><button type="button" id="rop-minus" aria-label="Less">&minus;</button>' +
            '<div class="rop-val"><span>ETB</span><input id="rop-input" type="number" inputmode="numeric" value="' + esc(offer) + '" aria-label="Your offer in ETB"></div>' +
            '<button type="button" id="rop-plus" aria-label="More">+</button></div>' +
            '<div class="rop-hint"><span>Allowed range</span> <b data-no-i18n>ETB ' + esc(lo) + ' - ' + esc(hi) + '</b></div>' +
            '<div class="rop-hint">Drivers can accept your price or suggest another.</div></div>';
        var inp = document.getElementById('rop-input');
        inp.onchange = function () { offer = clamp(Math.round(parseFloat(inp.value) || offer)); inp.value = offer; };
        document.getElementById('rop-minus').onclick = function () { offer = clamp(offer - step); inp.value = offer; };
        document.getElementById('rop-plus').onclick = function () { offer = clamp(offer + step); inp.value = offer; };
        document.getElementById('rop-off').onclick = function () { on = false; renderBox(); };
    }
    function openOffer() {
        var fare = fareShown(); if (fare <= 0) return;
        api('ride_offer_bounds', { fare: fare }).then(function (r) {
            if (!r || !r.success || !r.data || r.data.enabled === false) { bounds = { enabled: false }; renderBox(); return; }
            bounds = { enabled: true, min: r.data.min, max: r.data.max, fare: fare };
            offer = clamp(Math.round(fare / 5) * 5);
            on = true; renderBox();
        }).catch(function () {});
    }
    function watchFare() {
        var fa = document.getElementById('fare-amount');
        if (!fa) return false;
        var last = fareShown();
        new MutationObserver(function () {
            var f = fareShown();
            if (f !== last) { last = f; if (on) { on = false; bounds = null; } renderBox(); }
        }).observe(fa, { childList: true, characterData: true, subtree: true });
        var card = document.getElementById('fare-card');
        if (card) new MutationObserver(renderBox).observe(card, { attributes: true, attributeFilter: ['style'] });
        renderBox();
        return true;
    }

    /* ---------- Searching screen: offers from drivers ---------- */
    function panel() {
        var p = document.getElementById('rop-offers');
        if (p) return p;
        var view = document.getElementById('view-searching');
        var label = view && view.querySelector('.searching-label');
        if (!label || !label.parentNode) return null;
        p = document.createElement('div'); p.id = 'rop-offers'; p.style.display = 'none';
        label.parentNode.insertBefore(p, label.nextSibling);
        p.addEventListener('click', onClick);
        return p;
    }
    function drawOffers(d) {
        var p = panel(); if (!p) return;
        if (d.rider_offer == null || d.status !== 'searching') { p.style.display = 'none'; p.innerHTML = ''; lastKey = ''; return; }
        var key = JSON.stringify([d.rider_offer, d.max, (d.counters || []).map(function (c) { return [c.id, c.price]; })]);
        p.style.display = '';
        if (key === lastKey) return;
        lastKey = key;
        var html = '<div class="rop-head"><div><div class="rop-sub">Your offer</div><div class="rop-price" data-no-i18n>ETB ' + esc(d.rider_offer) + '</div></div>' +
            (d.rider_offer < d.max ? '<button type="button" class="rop-raise" data-act="raise">Raise offer</button>' : '') + '</div>';
        var list = d.counters || [];
        if (!list.length) {
            html += '<div class="rop-wait">Waiting for drivers to answer…</div>';
        } else {
            html += '<div class="rop-title">Offers from drivers</div>';
            for (var i = 0; i < list.length; i++) {
                var c = list[i];
                html += '<div class="rop-offer"><div class="rop-who"><b data-no-i18n>' + esc(c.driver_name || '') + '</b>' +
                    '<div class="rop-sub" data-no-i18n>' + (c.driver_rating > 0 ? '&#9733; ' + esc(c.driver_rating) + ' &middot; ' : '') + esc(c.plate || c.vehicle || '') + '</div>' +
                    (c.km_away != null ? '<div class="rop-sub"><span data-no-i18n>' + esc(c.km_away) + '</span> km away</div>' : '') + '</div>' +
                    '<div class="rop-act"><div class="rop-price" data-no-i18n>ETB ' + esc(c.price) + '</div>' +
                    '<div><button type="button" class="rop-ok" data-act="accept" data-id="' + c.id + '">Accept</button>' +
                    '<button type="button" class="rop-no" data-act="decline" data-id="' + c.id + '" aria-label="Decline">&times;</button></div></div></div>';
            }
            html += '<div class="rop-sub rop-fixed">The price is fixed once you choose a driver.</div>';
        }
        p.innerHTML = html;
        p.setAttribute('data-max', d.max); p.setAttribute('data-offer', d.rider_offer);
    }
    function onClick(e) {
        var b = e.target.closest ? e.target.closest('[data-act]') : null; if (!b || b.disabled) return;
        var act = b.getAttribute('data-act'), id = rideId(), p = document.getElementById('rop-offers');
        if (!id) return;
        b.disabled = true;
        var call;
        if (act === 'accept') call = api('ride_counter_accept', { ride_id: id, counter_id: parseInt(b.getAttribute('data-id'), 10) });
        else if (act === 'decline') call = api('ride_counter_decline', { ride_id: id, counter_id: parseInt(b.getAttribute('data-id'), 10) });
        else {
            var cur = parseFloat(p.getAttribute('data-offer')) || 0, mx = parseFloat(p.getAttribute('data-max')) || 0;
            call = api('ride_raise_offer', { ride_id: id, offer: Math.min(mx, cur + step) });
        }
        call.then(function (r) {
            lastKey = '';
            if (r && r.success) { poll(); }
            else { b.disabled = false; try { window.alert((r && r.message) || 'Could not do that'); } catch (x) {} poll(); }
        }).catch(function () { b.disabled = false; });
    }
    function searchingNow() { var v = document.getElementById('view-searching'); return v && v.classList.contains('active'); }
    function poll() {
        if (document.hidden || !searchingNow()) return;
        var id = rideId(); if (!id || !token()) return;
        api('ride_counter_list', { ride_id: id }).then(function (r) { if (r && r.success) drawOffers(r.data || {}); }).catch(function () {});
    }
    function start() {
        watchFare();
        if (!document.getElementById('fare-amount')) {
            var t = setInterval(function () { if (watchFare()) clearInterval(t); }, 1000);
        }
        pollTimer = setInterval(poll, 3000);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();

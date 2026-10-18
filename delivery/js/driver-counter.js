/* ETEL Driver - counter-offers on ride requests (additive script, no bundle release).
   A rider can offer their own price. The request card already shows that price as the fare (and you can slide to accept
   it); this script adds a "Rider's offer" tag and a "Counter-offer" button that lets you ask for a higher price, once per
   trip, up to the limit the server allows. The rider then chooses a driver; the price is fixed once they do.
   Request details come from the same live updates the app already receives (js/etel-sse-poll.js sends them on as the
   etel:stream event). Backend: action driver_counter_offer. Text is translated by driver-i18n.js (Amharic). */
(function () {
    'use strict';
    if (window.__etelDriverCounter) return;
    window.__etelDriverCounter = true;

    var info = {};   // request id -> { offer, max, mine }
    var openId = 0;

    function token() { try { return (window.Auth && Auth.getToken) ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); } catch (e) { return ''; } }
    function apiUrl() { return (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php'; }
    function api(action, body) {
        return fetch(apiUrl(), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() },
            body: JSON.stringify(Object.assign({ action: action }, body || {})) }).then(function (r) { return r.json(); });
    }
    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

    document.addEventListener('etel:stream', function (ev) {
        var d = ev && ev.detail;
        if (!d || d.kind !== 'driver' || !d.data || !d.data.requests) return;
        var seen = {};
        d.data.requests.forEach(function (r) {
            seen[r.id] = true;
            var off = parseFloat(r.rider_offer) || 0;
            info[r.id] = off > 0 ? { offer: off, max: parseFloat(r.offer_max) || 0, mine: parseFloat(r.my_counter) || 0 } : null;
        });
        Object.keys(info).forEach(function (k) { if (!seen[k]) delete info[k]; });
        decorate();
    });

    // the request card's trip id: from the slide-to-accept track, the card id, or a button's onclick (the card markup differs between app releases)
    function cardId(card) {
        var t = card.querySelector('[data-ride-id]');
        if (t) return parseInt(t.getAttribute('data-ride-id'), 10);
        var m = String(card.id || '').match(/(\d+)$/);
        if (m) return parseInt(m[1], 10);
        var b = card.querySelectorAll('[onclick]');
        for (var i = 0; i < b.length; i++) {
            var q = String(b[i].getAttribute('onclick')).match(/\(\s*['"]?(\d+)['"]?/);
            if (q) return parseInt(q[1], 10);
        }
        return 0;
    }

    function decorate() {
        var cards = document.querySelectorAll('#requests-list .req-card');
        for (var i = 0; i < cards.length; i++) {
            var card = cards[i], id = cardId(card), x = info[id];
            var have = card.querySelector('.drc-bar');
            if (!x) { if (have) have.parentNode.removeChild(have); continue; }
            var sig = x.offer + '|' + x.mine;
            if (have && have.getAttribute('data-sig') === sig) continue;
            if (have) have.parentNode.removeChild(have);
            var bar = document.createElement('div');
            bar.className = 'drc-bar'; bar.setAttribute('data-sig', sig);
            bar.innerHTML = '<span class="drc-tag">Rider\'s offer</span>' +
                (x.mine > 0 ? '<span class="drc-mine">Your counter-offer <b data-no-i18n>ETB ' + esc(x.mine) + '</b> &middot; waiting for the rider</span>' : '') +
                '<button type="button" class="drc-btn" data-id="' + id + '">' + (x.mine > 0 ? 'Change counter-offer' : 'Counter-offer') + '</button>';
            var wrap = card.querySelector('.slide-accept-wrap');
            if (wrap) wrap.parentNode.insertBefore(bar, wrap); else card.appendChild(bar);
        }
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.drc-btn') : null;
        if (b) { e.preventDefault(); e.stopPropagation(); openDlg(parseInt(b.getAttribute('data-id'), 10)); }
    }, true);
    try {
        var list = document.getElementById('requests-list');
        var mo = new MutationObserver(function () { decorate(); });
        var attach = function () { list = document.getElementById('requests-list'); if (list) { mo.observe(list, { childList: true }); return true; } return false; };
        if (!attach()) { var t = setInterval(function () { if (attach()) clearInterval(t); }, 1000); }
    } catch (e) {}

    function closeDlg() { var o = document.getElementById('drc-dlg'); if (o) o.parentNode.removeChild(o); openId = 0; }
    function openDlg(id) {
        var x = info[id]; if (!x) return;
        openId = id;
        var price = x.mine > 0 ? x.mine : x.offer + 10;
        if (x.max && price > x.max) price = x.max;
        var o = document.getElementById('drc-dlg');
        if (o) o.parentNode.removeChild(o);
        o = document.createElement('div'); o.id = 'drc-dlg'; o.className = 'rp-dlg';
        o.setAttribute('role', 'dialog'); o.setAttribute('aria-modal', 'true'); o.setAttribute('aria-label', 'Counter-offer');
        o.onclick = function (e) { if (e.target === o) closeDlg(); };
        o.innerHTML = '<div class="rp-card"><button type="button" class="rp-close" id="drc-close" aria-label="Close">&times;</button>' +
            '<h3 class="rp-h">Counter-offer</h3>' +
            '<p class="rp-p"><span>The rider offered</span> <b data-no-i18n>ETB ' + esc(x.offer) + '</b></p>' +
            (x.max ? '<p class="rp-p"><span>Highest price you can ask</span> <b data-no-i18n>ETB ' + esc(x.max) + '</b></p>' : '') +
            '<p class="rp-p">Or slide the request to accept the rider\'s price.</p>' +
            '<div class="rop-step drc-step"><button type="button" id="drc-minus" aria-label="Less">&minus;</button>' +
            '<div class="rop-val"><span>ETB</span><input id="drc-input" type="number" inputmode="numeric" value="' + esc(price) + '" aria-label="Your price in ETB"></div>' +
            '<button type="button" id="drc-plus" aria-label="More">+</button></div>' +
            '<div class="rp-note" id="drc-note" role="status"></div>' +
            '<button type="button" class="rp-send" id="drc-send">Send counter-offer</button></div>';
        document.body.appendChild(o);
        var inp = document.getElementById('drc-input');
        function bump(d) { var v = Math.round(parseFloat(inp.value) || price) + d; if (v <= x.offer) v = x.offer + 1; if (x.max && v > x.max) v = x.max; inp.value = v; }
        document.getElementById('drc-minus').onclick = function () { bump(-5); };
        document.getElementById('drc-plus').onclick = function () { bump(5); };
        document.getElementById('drc-close').onclick = closeDlg;
        document.getElementById('drc-send').onclick = function () {
            var btn = this, note = document.getElementById('drc-note'), v = Math.round(parseFloat(inp.value) || 0);
            btn.disabled = true; note.textContent = ''; note.classList.remove('rp-bad');
            api('driver_counter_offer', { request_id: id, price: v }).then(function (r) {
                if (r && r.success) {
                    if (info[id]) info[id].mine = v;
                    decorate(); closeDlg();
                } else { btn.disabled = false; note.textContent = (r && r.message) || 'Could not send'; note.classList.add('rp-bad'); }
            }).catch(function () { btn.disabled = false; note.textContent = 'Network error'; note.classList.add('rp-bad'); });
        };
    }
})();

/* ETEL Ride - promo codes (additive script, no bundle release).

   1. Book screen: "Have a promo code?" under the payment options. The code is checked with ride_promo_check and, if it
      is good, is sent along with the booking (book_ride gets promo_code). The real discount is worked out on the FINAL
      fare when the trip ends, so the amount shown here is only an estimate. Etel pays for the discount, not the driver.
   2. Profile > "Promo codes": anyone can propose a code (percent or ETB off, how many uses, expiry). It starts as
      "waiting for approval" and works only after an admin approves it; this list shows each code's status.
   All text here is translated by ride-i18n.js (Amharic) like the rest of the app. Fails quietly. */
(function () {
    'use strict';
    if (window.__etelRidePromo) return;
    window.__etelRidePromo = true;

    var applied = null;   // { code, discount, text }
    var ptype = 'percent';

    function token() { try { return (window.Auth && Auth.getToken) ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); } catch (e) { return ''; } }
    function apiUrl() { return (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php'; }
    var realFetch = window.fetch ? window.fetch.bind(window) : null;
    function api(action, body) {
        return realFetch(apiUrl(), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() },
            body: JSON.stringify(Object.assign({ action: action }, body || {})) }).then(function (r) { return r.json(); });
    }
    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function num(n) { return String(parseFloat(n)); }
    function fareShown() {
        var el = document.getElementById('fare-amount');
        var m = el ? String(el.textContent || '').replace(/,/g, '').match(/(\d+(?:\.\d+)?)/) : null;
        return m ? parseFloat(m[1]) : 0;
    }

    /* ---------- send the code with the booking ---------- */
    if (realFetch) {
        window.fetch = function (input, init) {
            try {
                if (applied && init && typeof init.body === 'string' && init.body.indexOf('"book_ride"') !== -1) {
                    var b = JSON.parse(init.body);
                    if (b && b.action === 'book_ride' && !b.promo_code) {
                        b.promo_code = applied.code;
                        init = Object.assign({}, init, { body: JSON.stringify(b) });
                        var p = realFetch(input, init);
                        // a booking that went through used the code for this ride: clear the box for next time
                        p.then(function (r) { return r.clone().json(); }).then(function (j) { if (j && j.success) clear(); }).catch(function () {});
                        return p;
                    }
                }
            } catch (e) { /* send the booking untouched */ }
            return realFetch(input, init);
        };
    }

    /* ---------- the box on the Book screen ---------- */
    var TAG = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V4h9l8.6 8.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="8.5" r="1.2"/></svg>';
    function box() {
        var b = document.getElementById('rp-box');
        if (b) return b;
        var row = document.querySelector('#view-book .pay-row');
        if (!row || !row.parentNode) return null;
        b = document.createElement('div'); b.id = 'rp-box';
        row.parentNode.insertBefore(b, row.nextSibling);
        return b;
    }
    function render(state, msg, bad) {
        var b = box(); if (!b) return;
        if (applied) {
            b.innerHTML = '<div class="rp-chip" role="status"><span class="rp-chip-ico">' + TAG + '</span><span class="rp-chip-txt"><b>' + esc(applied.code) + '</b> <span>' + esc(applied.text) + '</span></span>' +
                '<button type="button" class="rp-x" id="rp-remove" aria-label="Remove">&times;</button></div>';
            document.getElementById('rp-remove').onclick = function () { clear(); };
            return;
        }
        if (state !== 'open') {
            b.innerHTML = '<button type="button" class="rp-link" id="rp-open"><span class="rp-chip-ico">' + TAG + '</span><span>Have a promo code?</span></button>';
            document.getElementById('rp-open').onclick = function () { render('open'); var i = document.getElementById('rp-input'); if (i) i.focus(); };
            return;
        }
        b.innerHTML = '<div class="rp-form"><input id="rp-input" class="rp-input" type="text" maxlength="40" autocapitalize="characters" autocomplete="off" spellcheck="false" placeholder="Promo code" aria-label="Promo code">' +
            '<button type="button" class="rp-apply" id="rp-apply">Apply</button></div>' +
            '<div class="rp-msg' + (bad ? ' rp-bad' : '') + '" id="rp-msg" role="status">' + esc(msg || '') + '</div>';
        var inp = document.getElementById('rp-input');
        document.getElementById('rp-apply').onclick = apply;
        inp.onkeydown = function (e) { if (e.key === 'Enter') { e.preventDefault(); apply(); } };
    }
    function clear() { applied = null; render('closed'); }
    function apply() {
        var inp = document.getElementById('rp-input'); if (!inp) return;
        var code = inp.value.replace(/[^A-Za-z0-9_-]/g, '').toUpperCase();
        if (!code) { render('open', 'Enter a promo code', true); return; }
        var btn = document.getElementById('rp-apply'); if (btn) btn.disabled = true;
        api('ride_promo_check', { code: code, fare: fareShown() }).then(function (r) {
            if (r && r.success) {
                var d = r.data || {};
                var est = parseFloat(d.discount) > 0 ? 'about ETB ' + Math.round(parseFloat(d.discount)) + ' off' : (d.type === 'percent' ? num(d.value) + '% off your fare' : 'ETB ' + num(d.value) + ' off your fare');
                applied = { code: d.code || code, discount: parseFloat(d.discount) || 0, text: est };
                render('closed');
            } else {
                render('open', (r && r.message) || 'That promo code is not valid', true);
                var i2 = document.getElementById('rp-input'); if (i2) i2.value = code;
            }
        }).catch(function () { render('open', 'Could not check the code. Try again.', true); });
    }

    /* ---------- Profile > Promo codes (propose a code, see its status) ---------- */
    var ST = { pending: 'Waiting for approval', approved: 'Approved', rejected: 'Rejected', disabled: 'Switched off' };
    function rule(p) {
        var d = p.discount_type === 'percent' ? num(p.value) + '% off' : 'ETB ' + num(p.value) + ' off';
        if (p.max_discount && parseFloat(p.max_discount) > 0) d += ' (most ETB ' + num(p.max_discount) + ')';
        return d;
    }
    function dlg() {
        var o = document.getElementById('rp-dlg');
        if (o) return o;
        o = document.createElement('div'); o.id = 'rp-dlg'; o.className = 'rp-dlg';
        o.setAttribute('role', 'dialog'); o.setAttribute('aria-modal', 'true'); o.setAttribute('aria-label', 'Promo codes');
        o.onclick = function (e) { if (e.target === o) closeDlg(); };
        document.body.appendChild(o);
        return o;
    }
    function closeDlg() { var o = document.getElementById('rp-dlg'); if (o) o.parentNode.removeChild(o); }
    function openDlg(note, noteBad) {
        var o = dlg();
        o.innerHTML = '<div class="rp-card"><button type="button" class="rp-close" id="rp-close" aria-label="Close">&times;</button>' +
            '<h3 class="rp-h">Promo codes</h3>' +
            '<p class="rp-p">Make a code for riders to use. It works after an admin approves it. Etel pays for the discount.</p>' +
            '<div class="rp-grid"><label>Code<input id="rpf-code" maxlength="20" autocapitalize="characters" placeholder="WELCOME20"></label>' +
            '<div class="rp-typewrap"><span class="rp-lbl">Type</span><div class="rp-seg" role="radiogroup" aria-label="Type"><button type="button" role="radio" aria-checked="true" data-v="percent" class="on">% off the fare</button><button type="button" role="radio" aria-checked="false" data-v="fixed">ETB off the fare</button></div></div>' +
            '<label>Amount<input id="rpf-value" type="number" min="1" step="any" placeholder="20"></label>' +
            '<label>Number of uses<input id="rpf-uses" type="number" min="1" max="1000" value="50"></label></div>' +
            '<div class="rp-note' + (noteBad ? ' rp-bad' : '') + '" id="rpf-note" role="status">' + esc(note || '') + '</div>' +
            '<button type="button" class="rp-send" id="rpf-send">Send for approval</button>' +
            '<h4 class="rp-h2">My codes</h4><div id="rpf-list" class="rp-list">Loading...</div></div>';
        document.getElementById('rp-close').onclick = closeDlg;
        document.getElementById('rpf-send').onclick = propose;
        ptype = 'percent';
        var segs = o.querySelectorAll('.rp-seg button');
        for (var si = 0; si < segs.length; si++) segs[si].onclick = function () {
            ptype = this.getAttribute('data-v');
            for (var sj = 0; sj < segs.length; sj++) { var on = segs[sj] === this; segs[sj].className = on ? 'on' : ''; segs[sj].setAttribute('aria-checked', on ? 'true' : 'false'); }
        };
        document.addEventListener('keydown', escKey);
        api('ride_promo_mine', {}).then(function (r) {
            var l = document.getElementById('rpf-list'); if (!l) return;
            var rows = (r && r.data && r.data.promos) || [];
            if (!rows.length) { l.textContent = 'No codes yet.'; return; }
            l.innerHTML = rows.map(function (p) {
                return '<div class="rp-row"><div><b>' + esc(p.code) + '</b> <span class="rp-sub">' + esc(rule(p)) + '</span>' +
                    (p.review_note ? '<div class="rp-sub">' + esc(p.review_note) + '</div>' : '') + '</div>' +
                    '<div class="rp-st rp-st-' + esc(p.status) + '">' + esc(ST[p.status] || p.status) + (p.status === 'approved' ? ' · ' + (p.used_count || 0) + (p.max_uses ? '/' + p.max_uses : '') : '') + '</div></div>';
            }).join('');
        }).catch(function () { var l = document.getElementById('rpf-list'); if (l) l.textContent = ''; });
    }
    function escKey(e) { if (e.key === 'Escape') { closeDlg(); document.removeEventListener('keydown', escKey); } }
    function propose() {
        var g = function (id) { var e = document.getElementById(id); return e ? e.value : ''; };
        var btn = document.getElementById('rpf-send'); if (btn) btn.disabled = true;
        api('ride_promo_propose', { code: g('rpf-code'), discount_type: ptype, value: g('rpf-value'), max_uses: g('rpf-uses') }).then(function (r) {
            if (r && r.success) openDlg('Sent for approval. It works once an admin approves it.', false);
            else { var n = document.getElementById('rpf-note'); if (n) { n.textContent = (r && r.message) || 'Could not send the code'; n.className = 'rp-note rp-bad'; } if (btn) btn.disabled = false; }
        }).catch(function () { var n = document.getElementById('rpf-note'); if (n) { n.textContent = 'Could not send the code. Try again.'; n.className = 'rp-note rp-bad'; } if (btn) btn.disabled = false; });
    }
    function addProfileRow() {
        if (document.getElementById('rp-profile-row')) return;
        var rows = document.querySelectorAll('#my-profile-modal .mp-row');
        var anchor = null;
        for (var i = 0; i < rows.length; i++) { if (/Settings\s*&\s*Privacy/i.test(rows[i].textContent || '')) { anchor = rows[i]; break; } }
        if (!anchor || !anchor.parentNode) return;
        var row = document.createElement('div'); row.className = 'mp-row'; row.id = 'rp-profile-row';
        row.innerHTML = '<div data-no-emoji-icons class="mp-row-icon">' + TAG + '</div><div><div class="mp-row-label">Promo codes</div><div class="mp-row-sub">Make a code for riders</div></div><div class="mp-row-arrow">&rsaquo;</div>';
        row.onclick = function () { try { if (window.closeMyProfile) window.closeMyProfile(); } catch (e) {} openDlg(); };
        anchor.parentNode.insertBefore(row, anchor);
    }

    function init() {
        if (token()) render('closed');
        addProfileRow();
    }
    // the Book screen and the profile sheet are built by the app after sign-in: keep watching for them
    var mo = new MutationObserver(function () {
        if (token() && !document.getElementById('rp-box')) render(applied ? 'closed' : 'closed');
        addProfileRow();
    });
    function start() { init(); try { mo.observe(document.body, { childList: true, subtree: true }); } catch (e) {} }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();

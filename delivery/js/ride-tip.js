/* ETEL Ride - tip your driver (additive script, no bundle release).

   After a trip the rider can leave a tip: pick an amount, send it to one of Etel's bank accounts (copy the number
   here), enter the transfer reference. The tip waits until an admin confirms the money arrived; then the driver gets all
   of it. One tip per trip, within 72 hours. Entry points: a button on the "Rate your driver" screen and Profile >
   "Tip your driver" (for the last trip). Text is translated by ride-i18n.js. Fails quietly. */
(function () {
    'use strict';
    if (window.__etelRideTip) return;
    window.__etelRideTip = true;

    function token() { try { return (window.Auth && Auth.getToken) ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); } catch (e) { return ''; } }
    function apiUrl() { return (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php'; }
    function api(action, body) {
        return fetch(apiUrl(), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() },
            body: JSON.stringify(Object.assign({ action: action }, body || {})) }).then(function (r) { return r.json(); });
    }
    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

    var HEART = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z"/></svg>';
    var ST = { pending: 'Waiting for Etel to confirm your transfer', approved: 'Sent to your driver. Thank you!', rejected: 'Etel could not find the transfer' };

    function dlg() {
        var o = document.getElementById('tp-dlg');
        if (o) return o;
        o = document.createElement('div'); o.id = 'tp-dlg'; o.className = 'rp-dlg';
        o.setAttribute('role', 'dialog'); o.setAttribute('aria-modal', 'true'); o.setAttribute('aria-label', 'Tip your driver');
        o.onclick = function (e) { if (e.target === o) closeDlg(); };
        document.body.appendChild(o);
        document.addEventListener('keydown', escKey);
        return o;
    }
    function closeDlg() { var o = document.getElementById('tp-dlg'); if (o) o.parentNode.removeChild(o); document.removeEventListener('keydown', escKey); }
    function escKey(e) { if (e.key === 'Escape') closeDlg(); }
    function shell(inner) {
        dlg().innerHTML = '<div class="rp-card"><button type="button" class="rp-close" id="tp-close" aria-label="Close">&times;</button>' + inner + '</div>';
        document.getElementById('tp-close').onclick = closeDlg;
    }
    function note(msg, bad) { var n = document.getElementById('tp-note'); if (n) { n.textContent = msg || ''; n.className = 'rp-note' + (bad ? ' rp-bad' : ''); } }

    function copy(text, btn) {
        var done = function () { if (btn) { var t = btn.textContent; btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = t; }, 1500); } };
        try { if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, done); return; } } catch (e) {}
        try { var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta); } catch (e) {}
        done();
    }

    function open(rideId) {
        if (!token()) return;
        if (!rideId) { shell('<h3 class="rp-h">Tip your driver</h3><p class="rp-p">Tip after a completed trip.</p>'); return; }
        shell('<h3 class="rp-h">Tip your driver</h3><p class="rp-p">Loading...</p>');
        api('ride_tip_info', { ride_id: rideId }).then(function (r) {
            var d = (r && r.data) || {};
            if (!r || !r.success) { shell('<h3 class="rp-h">Tip your driver</h3><p class="rp-p">' + esc((r && r.message) || 'Could not load') + '</p>'); return; }
            if (d.existing) {
                shell('<h3 class="rp-h">Tip your driver</h3><p class="rp-p"><b>ETB ' + esc(parseFloat(d.existing.amount)) + '</b></p><p class="rp-p">' + esc(ST[d.existing.status] || d.existing.status) + '</p>');
                return;
            }
            if (!d.eligible) { shell('<h3 class="rp-h">Tip your driver</h3><p class="rp-p">' + esc(d.reason || 'You cannot tip for this trip') + '</p>'); return; }
            form(rideId, d);
        }).catch(function () { shell('<h3 class="rp-h">Tip your driver</h3><p class="rp-p">Could not load. Try again.</p>'); });
    }

    function form(rideId, d) {
        var amount = 0, bank = '';
        var chips = (d.presets || [10, 20, 50, 100]).map(function (p) { return '<button type="button" class="tp-chip" data-a="' + p + '">ETB ' + p + '</button>'; }).join('');
        var accs = (d.accounts || []).map(function (a) {
            return '<div class="tp-acc"><div class="tp-acc-main"><b>' + esc(a.display_name) + '</b><div class="rp-sub">' + esc(a.account_name) + '</div><div class="tp-num">' + esc(a.account_number) + '</div></div>' +
                '<button type="button" class="tp-copy" data-n="' + esc(a.account_number) + '" data-k="' + esc(a.bank_key) + '">Copy</button></div>';
        }).join('') || '<div class="rp-sub">Etel has not set up a bank account for tips yet.</div>';
        shell('<h3 class="rp-h">Tip your driver</h3>' +
            (d.driver_name ? '<div class="tp-who">' + esc(d.driver_name) + '</div>' : '') +
            '<p class="rp-p">Your driver gets all of it, after Etel confirms your transfer.</p>' +
            '<div class="tp-chips" role="radiogroup" aria-label="Tip amount">' + chips + '</div>' +
            '<label class="tp-lbl">Or another amount (ETB)<input id="tp-amount" class="tp-input" type="number" min="5" max="' + esc(d.max) + '" step="any" placeholder="25"></label>' +
            (d.chapa ? '<button type="button" class="rp-send" id="tp-chapa">Pay with Chapa</button><div class="tp-lbl">Or send it by bank transfer</div>' : '') +
            '<div class="tp-lbl">1. Send it to one of these accounts</div>' + accs +
            '<label class="tp-lbl">2. Transfer reference<input id="tp-ref" class="tp-input" type="text" maxlength="60" autocomplete="off" placeholder="From your bank message"></label>' +
            '<div class="rp-note" id="tp-note" role="status"></div>' +
            '<button type="button" class="rp-send" id="tp-send">Send tip</button>');
        var o = document.getElementById('tp-dlg'), i;
        var cs = o.querySelectorAll('.tp-chip');
        for (i = 0; i < cs.length; i++) cs[i].onclick = function () {
            amount = parseFloat(this.getAttribute('data-a'));
            document.getElementById('tp-amount').value = '';
            for (var j = 0; j < cs.length; j++) { cs[j].className = 'tp-chip' + (cs[j] === this ? ' on' : ''); cs[j].setAttribute('aria-checked', cs[j] === this ? 'true' : 'false'); }
        };
        document.getElementById('tp-amount').oninput = function () { amount = parseFloat(this.value) || 0; for (var j = 0; j < cs.length; j++) cs[j].className = 'tp-chip'; };
        var cp = o.querySelectorAll('.tp-copy');
        for (i = 0; i < cp.length; i++) cp[i].onclick = function () { bank = this.getAttribute('data-k'); copy(this.getAttribute('data-n'), this); };
        var cb = document.getElementById('tp-chapa');
        if (cb) cb.onclick = function () {
            if (!(amount >= 5)) { note('Choose an amount of at least ETB 5', true); return; }
            var b = this; b.disabled = true; note('Opening Chapa...');
            api('ride_tip_chapa_init', { ride_id: rideId, amount: amount }).then(function (r) {
                if (r && r.success && r.data && r.data.checkout_url) { window.location.href = r.data.checkout_url; return; }
                note((r && r.message) || 'Could not open Chapa', true); b.disabled = false;
            }).catch(function () { note('Could not open Chapa. Try again.', true); b.disabled = false; });
        };
        document.getElementById('tp-send').onclick = function () {
            var ref = document.getElementById('tp-ref').value.trim();
            if (!(amount >= 5)) { note('Choose an amount of at least ETB 5', true); return; }
            if (ref.length < 4) { note('Enter the transfer reference from your bank', true); return; }
            this.disabled = true;
            var btn = this;
            api('ride_tip_give', { ride_id: rideId, amount: amount, bank_key: bank, payment_ref: ref }).then(function (r) {
                if (r && r.success) shell('<h3 class="rp-h">Tip your driver</h3><p class="rp-p">' + esc((r.data && r.data.message) || r.message || 'Thank you!') + '</p>');
                else { note((r && r.message) || 'Could not send the tip', true); btn.disabled = false; }
            }).catch(function () { note('Could not send the tip. Try again.', true); btn.disabled = false; });
        };
    }

    /* ---------- entry points ---------- */
    function addRatingButton() {
        var v = document.getElementById('view-rating');
        if (!v || document.getElementById('tp-open')) return;
        var skip = v.querySelector('.btn-outline');
        var b = document.createElement('button');
        b.type = 'button'; b.id = 'tp-open'; b.className = 'tp-open';
        b.innerHTML = HEART + '<span>Tip your driver</span>';
        b.onclick = function () { open(window.RIDE && RIDE.currentRideId); };
        if (skip && skip.parentNode) skip.parentNode.insertBefore(b, skip); else v.appendChild(b);
    }
    function addProfileRow() {
        if (document.getElementById('tp-profile-row')) return;
        var rows = document.querySelectorAll('#my-profile-modal .mp-row');
        var anchor = null;
        for (var i = 0; i < rows.length; i++) { if (/Settings\s*&\s*Privacy/i.test(rows[i].textContent || '')) { anchor = rows[i]; break; } }
        if (!anchor || !anchor.parentNode) return;
        var row = document.createElement('div'); row.className = 'mp-row'; row.id = 'tp-profile-row';
        row.innerHTML = '<div data-no-emoji-icons class="mp-row-icon">' + HEART + '</div><div><div class="mp-row-label">Tip your driver</div><div class="mp-row-sub">For your last trip</div></div><div class="mp-row-arrow">&rsaquo;</div>';
        row.onclick = function () {
            try { if (window.closeMyProfile) window.closeMyProfile(); } catch (e) {}
            shell('<h3 class="rp-h">Tip your driver</h3><p class="rp-p">Loading...</p>');
            api('ride_history', {}).then(function (r) {
                var rides = (r && r.data && (r.data.rides || r.data)) || [];
                var last = null;
                for (var k = 0; k < rides.length; k++) { if (rides[k] && rides[k].status === 'completed') { last = rides[k]; break; } }
                if (!last) { shell('<h3 class="rp-h">Tip your driver</h3><p class="rp-p">No completed trip to tip for yet.</p>'); return; }
                open(last.id);
            }).catch(function () { shell('<h3 class="rp-h">Tip your driver</h3><p class="rp-p">Could not load. Try again.</p>'); });
        };
        anchor.parentNode.insertBefore(row, anchor);
    }
    function init() { addRatingButton(); addProfileRow(); }
    var mo = new MutationObserver(function () { addRatingButton(); addProfileRow(); });
    function start() { init(); try { mo.observe(document.body, { childList: true, subtree: true }); } catch (e) {} }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();

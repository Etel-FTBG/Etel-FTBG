/* ETEL Driver - tips card on the Money tab (additive script, no bundle release).
   Shows the tips riders have left (api action driver_get_tips): each tip is added to the cash-out balance in full, with no
   commission, once Etel has confirmed the rider's transfer. Also says in one line that promo discounts are paid by Etel.
   Text is translated by driver-i18n.js (Amharic). Fails quietly. */
(function () {
    'use strict';
    if (window.__etelDriverTips) return;
    window.__etelDriverTips = true;

    function token() { try { return (window.Auth && Auth.getToken) ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); } catch (e) { return ''; } }
    function apiUrl() { return (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php'; }
    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function money(n) { return 'ETB ' + (Math.round((parseFloat(n) || 0) * 100) / 100).toLocaleString(); }

    function card() {
        var c = document.getElementById('drv-tips-card');
        if (c) return c;
        var anchor = document.getElementById('money-balance');
        var payout = anchor && anchor.closest ? anchor.closest('.money-card') : null;
        if (!payout || !payout.parentNode) return null;
        c = document.createElement('div');
        c.id = 'drv-tips-card'; c.className = 'money-card';
        payout.parentNode.insertBefore(c, payout.nextSibling);
        return c;
    }
    function render(d) {
        var c = card(); if (!c) return;
        var rows = (d && d.tips) || [];
        var list = rows.length ? rows.slice(0, 5).map(function (t) {
            return '<div class="money-card-row"><div class="money-card-lbl">Trip #' + esc(t.ride_id) + ' <span style="opacity:.6">' + esc(String(t.reviewed_at || t.created_at || '').slice(0, 10)) + '</span></div><div class="money-card-val good">' + esc(money(t.amount)) + '</div></div>';
        }).join('') : '<div style="font-size:12px;opacity:.7;padding:4px 0">No tips yet.</div>';
        c.innerHTML = '<div class="money-card-hdr">Tips from riders</div>' +
            '<div class="money-card-row"><div class="money-card-lbl">Tips received</div><div class="money-card-val good">' + esc(money(d && d.total)) + '</div></div>' + list +
            '<div style="font-size:11px;color:var(--text-dim);opacity:.7;margin-top:6px;line-height:1.4">Each tip is added to your cash-out balance in full, with no commission, once Etel confirms the rider\'s transfer.</div>' +
            '<div style="font-size:11px;color:var(--text-dim);opacity:.7;margin-top:4px;line-height:1.4">Promo discounts are paid by Etel: when a rider uses a promo code, the discount is added to your ኢE Money balance.</div>';
    }
    function load() {
        if (!token() || document.hidden || !card()) return;
        fetch(apiUrl(), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() }, body: JSON.stringify({ action: 'driver_get_tips' }) })
            .then(function (r) { return r.json(); })
            .then(function (r) { if (r && r.success) render(r.data || {}); })
            .catch(function () {});
    }
    var loaded = false;
    var mo = new MutationObserver(function () { if (!loaded && document.getElementById('money-balance')) { loaded = true; load(); } });
    function start() { try { mo.observe(document.body, { childList: true, subtree: true }); } catch (e) {} if (document.getElementById('money-balance')) { loaded = true; load(); } setInterval(load, 60000); document.addEventListener('visibilitychange', function () { if (!document.hidden) load(); }); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();

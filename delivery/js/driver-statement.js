/* ETEL Driver - weekly statement on the Money tab (additive script, no bundle release).
   Shows one week (Monday to Sunday) of the driver's trips from api action driver_get_statement: what riders paid, the promo
   Etel paid, Etel's 5% commission on each trip, what the driver keeps, tips and hours online. Tap a trip for its own
   breakdown; the Share button sends the week as text. Text is translated by driver-i18n.js (Amharic). Fails quietly. */
(function () {
    'use strict';
    if (window.__etelDriverStatement) return;
    window.__etelDriverStatement = true;

    var WEEK = null, DATA = null, OPEN = null;

    // the app's glossy-sheen rule matches every class ending in "-card" (money-card-row, -lbl, -val): off for these cards' rows
    try {
        var st = document.createElement('style');
        st.textContent = '#drv-stmt-card [class*="-card"]::before,#drv-tips-card [class*="-card"]::before{display:none!important}';
        document.head.appendChild(st);
    } catch (e) {}

    function token() { try { return (window.Auth && Auth.getToken) ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); } catch (e) { return ''; } }
    function apiUrl() { return (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php'; }
    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function money(n) { return 'ETB ' + (Math.round((parseFloat(n) || 0) * 100) / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    function day(ymd) { var p = String(ymd).split('-'); return p.length === 3 ? (parseInt(p[2], 10) + ' ' + MON[parseInt(p[1], 10) - 1]) : ''; }

    function card() {
        var c = document.getElementById('drv-stmt-card');
        if (c) return c;
        var anchor = document.getElementById('drv-tips-card') || (document.getElementById('money-balance') && document.getElementById('money-balance').closest('.money-card'));
        if (!anchor || !anchor.parentNode) return null;
        c = document.createElement('div');
        c.id = 'drv-stmt-card'; c.className = 'money-card';
        anchor.parentNode.insertBefore(c, anchor.nextSibling);
        c.addEventListener('click', onClick);
        return c;
    }
    function row(label, val, cls) {
        return '<div class="money-card-row"><div class="money-card-lbl">' + label + '</div><div class="money-card-val ' + (cls || '') + '">' + esc(val) + '</div></div>';
    }
    function bars(days) {
        var max = 0, i;
        for (i = 0; i < days.length; i++) if (days[i].you_keep > max) max = days[i].you_keep;
        var names = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
        var out = '<div style="display:flex;gap:6px;align-items:flex-end;height:64px;margin:10px 0 2px">';
        for (i = 0; i < days.length; i++) {
            var h = max > 0 ? Math.max(3, Math.round(days[i].you_keep / max * 52)) : 3;
            out += '<div style="flex:1;text-align:center" title="' + esc(day(days[i].date) + ': ' + money(days[i].you_keep)) + '">' +
                '<div style="height:' + h + 'px;background:' + (days[i].trips ? 'var(--gold,#D4AF37)' : 'rgba(128,128,128,.25)') + ';border-radius:4px"></div>' +
                '<div style="font-size:10px;opacity:.7;margin-top:3px" data-no-i18n>' + names[i] + '</div></div>';
        }
        return out + '</div>';
    }
    function tripDetail(t) {
        return '<div style="font-size:12px;padding:6px 0 2px;line-height:1.7">' +
            row('Rider paid', money(t.rider_paid)) +
            (t.promo > 0 ? row('Promo paid by Etel', '+ ' + money(t.promo), 'good') : '') +
            row('Etel commission (5%)', '- ' + money(t.commission)) +
            row('You keep', money(t.you_keep), 'good') +
            '<div style="font-size:11px;opacity:.7">' + (t.collected_by === 'etel' ? 'Etel collected this fare for you.' : 'You collected this fare in cash.') + '</div></div>';
    }
    function render() {
        var c = card(); if (!c || !DATA) return;
        var d = DATA, t = d.totals || {};
        var nav = '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">' +
            '<button type="button" data-stmt="prev" aria-label="Previous week" style="background:none;border:0;color:inherit;font-size:20px;padding:4px 10px;opacity:' + (d.prev_week ? 1 : .3) + '" ' + (d.prev_week ? '' : 'disabled') + '>&#9664;</button>' +
            '<div style="font-weight:700" data-no-i18n>' + esc(day(d.week_start) + ' - ' + day(d.week_end)) + '</div>' +
            '<button type="button" data-stmt="next" aria-label="Next week" style="background:none;border:0;color:inherit;font-size:20px;padding:4px 10px;opacity:' + (d.next_week ? 1 : .3) + '" ' + (d.next_week ? '' : 'disabled') + '>&#9654;</button></div>';
        var html = '<div class="money-card-hdr">Weekly statement</div>' + nav +
            row('You keep this week', money(d.total_with_tips), 'good') +
            row('Trips', String(t.trips || 0)) +
            row('Online hours', String(d.online_hours || 0)) +
            bars(d.days || []) +
            '<div style="border-top:1px solid rgba(128,128,128,.25);margin:8px 0 4px"></div>' +
            row('Paid by riders', money(t.rider_paid)) +
            (t.promo > 0 ? row('Promo paid by Etel', '+ ' + money(t.promo), 'good') : '') +
            row('Etel commission (5%)', '- ' + money(t.commission)) +
            row('Kept from trips', money(t.you_keep)) +
            (d.tips > 0 ? row('Tips from riders', '+ ' + money(d.tips), 'good') : '') +
            row('Collected in cash', money(t.cash_collected)) +
            row('Collected by Etel', money(t.etel_collected));
        var trips = d.trips || [];
        if (trips.length) {
            html += '<div style="font-weight:700;margin:12px 0 2px">Trips this week</div>';
            for (var i = 0; i < Math.min(trips.length, 40); i++) {
                var x = trips[i], open = OPEN === x.id;
                html += '<div data-trip="' + x.id + '" style="padding:8px 0;border-top:1px solid rgba(128,128,128,.18);cursor:pointer">' +
                    '<div style="display:flex;justify-content:space-between;gap:8px"><div style="min-width:0"><div style="font-size:12px;opacity:.7" data-no-i18n>#' + esc(x.id) + ' &middot; ' + esc(day(String(x.completed_at).slice(0, 10)) + ' ' + String(x.completed_at).slice(11, 16)) + '</div>' +
                    '<div style="font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" data-no-i18n>' + esc(x.pickup || '') + ' &rarr; ' + esc(x.dropoff || '') + '</div></div>' +
                    '<div style="white-space:nowrap;font-weight:700" class="money-card-val good">' + esc(money(x.you_keep)) + '</div></div>' + (open ? tripDetail(x) : '') + '</div>';
            }
        } else {
            html += '<div style="font-size:12px;opacity:.7;padding:8px 0">No trips this week.</div>';
        }
        html += '<button type="button" data-stmt="share" style="margin-top:10px;width:100%;padding:10px;border-radius:10px;border:1px solid rgba(212,175,55,.5);background:none;color:inherit;font-weight:700">Share statement</button>' +
            '<div style="font-size:11px;opacity:.7;margin-top:6px;line-height:1.4">Etel takes 5% of each fare, including the part a promo code paid. Tips are yours in full.</div>';
        c.innerHTML = html;
    }
    function text() {
        var d = DATA, t = d.totals || {};
        var l = ['ETEL Ride - weekly statement', day(d.week_start) + ' - ' + day(d.week_end), '',
            'Trips: ' + t.trips, 'Paid by riders: ' + money(t.rider_paid)];
        if (t.promo > 0) l.push('Promo paid by Etel: ' + money(t.promo));
        l.push('Etel commission (5%): ' + money(t.commission), 'Kept from trips: ' + money(t.you_keep));
        if (d.tips > 0) l.push('Tips: ' + money(d.tips));
        l.push('Total you keep: ' + money(d.total_with_tips), 'Online hours: ' + d.online_hours);
        return l.join('\n');
    }
    function share() {
        if (!DATA) return;
        var s = text();
        if (navigator.share) { navigator.share({ text: s }).catch(function () {}); return; }
        try { navigator.clipboard.writeText(s); alert('Statement copied'); } catch (e) { window.prompt('Copy your statement', s); }
    }
    function onClick(e) {
        var b = e.target.closest ? e.target.closest('[data-stmt]') : null;
        if (b && !b.disabled) {
            var k = b.getAttribute('data-stmt');
            if (k === 'prev' && DATA.prev_week) { WEEK = DATA.prev_week; OPEN = null; load(); }
            else if (k === 'next' && DATA.next_week) { WEEK = DATA.next_week; OPEN = null; load(); }
            else if (k === 'share') share();
            return;
        }
        var t = e.target.closest ? e.target.closest('[data-trip]') : null;
        if (t) { var id = parseInt(t.getAttribute('data-trip'), 10); OPEN = OPEN === id ? null : id; render(); }
    }
    function load() {
        if (!token() || !card()) return;
        fetch(apiUrl(), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() }, body: JSON.stringify(WEEK ? { action: 'driver_get_statement', week_start: WEEK } : { action: 'driver_get_statement' }) })
            .then(function (r) { return r.json(); })
            .then(function (r) { if (r && r.success) { DATA = r.data || {}; render(); } })
            .catch(function () {});
    }
    var loaded = false;
    var mo = new MutationObserver(function () { if (!loaded && document.getElementById('money-balance')) { loaded = true; setTimeout(load, 400); } });
    function start() {
        try { mo.observe(document.body, { childList: true, subtree: true }); } catch (e) {}
        if (document.getElementById('money-balance')) { loaded = true; setTimeout(load, 400); }
        document.addEventListener('visibilitychange', function () { if (!document.hidden && !WEEK) load(); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();

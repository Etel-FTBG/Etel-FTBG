/* ETEL - Send a package: "Send again" and "My packages". Additive script, loaded before js/customer-package.js.
     PkgHistory.load(done)   fetches the customer's recent packages (api: customer_package_recent), then calls done()
     PkgHistory.strip()      the "Send again" card for the top of step 1 ('' when there is nothing to show)
   Buttons (exposed in customer-actions.js): pkgAgain(i) starts the wizard with that package's details (window.pkgApply, in customer-package.js),
   pkgMine opens the full list with filters (All / Active / Delivered / Cancelled), pkgMineFilter(g), pkgMineClose, pkgMineAgain(i), pkgMineTrack(id). */
(function () {
    'use strict';
    if (window.PkgHistory) return;
    ETEL_I18N.register('customer-package-hist', {
        en: { pkgh_again_t: 'Send again', pkgh_again_s: 'Start from a package you sent before', pkgh_all: 'My packages', pkgh_title: 'My packages', pkgh_close: 'Close',
            pkgh_f_all: 'All', pkgh_f_active: 'On the way', pkgh_f_delivered: 'Delivered', pkgh_f_cancelled: 'Cancelled', pkgh_track: 'Track', pkgh_resend: 'Send again',
            pkgh_empty: 'No packages here yet.', pkgh_loading: 'Loading…', pkgh_fail: 'Could not load your packages.', pkgh_to: 'To',
            pkgh_st_pending: 'Finding a courier', pkgh_st_assigned: 'Courier on the way', pkgh_st_picked_up: 'Picked up', pkgh_st_in_transit: 'On the way', pkgh_st_delivered: 'Delivered', pkgh_st_cancelled: 'Cancelled', pkgh_express: 'Express' },
        am: { pkgh_again_t: 'እንደገና ላክ', pkgh_again_s: 'ከዚህ በፊት ከላኩት ጥቅል ይጀምሩ', pkgh_all: 'የእኔ ጥቅሎች', pkgh_title: 'የእኔ ጥቅሎች', pkgh_close: 'ዝጋ',
            pkgh_f_all: 'ሁሉም', pkgh_f_active: 'በመንገድ ላይ', pkgh_f_delivered: 'የደረሱ', pkgh_f_cancelled: 'የተሰረዙ', pkgh_track: 'ተከታተል', pkgh_resend: 'እንደገና ላክ',
            pkgh_empty: 'እዚህ ገና ምንም ጥቅል የለም።', pkgh_loading: 'በመጫን ላይ…', pkgh_fail: 'ጥቅሎችዎን መጫን አልተቻለም።', pkgh_to: 'ወደ',
            pkgh_st_pending: 'መልእክተኛ በመፈለግ ላይ', pkgh_st_assigned: 'መልእክተኛው በመንገድ ላይ ነው', pkgh_st_picked_up: 'ተወስዷል', pkgh_st_in_transit: 'በመንገድ ላይ', pkgh_st_delivered: 'ደርሷል', pkgh_st_cancelled: 'ተሰርዟል', pkgh_express: 'ፈጣን' }
    });
    function T(k) { return ETEL_I18N.t(k); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function A(name, args) { return EtelActions.attrs(name, args); }
    var LIST = null, FILTER = 'all', FAILED = false;

    function load(done) {
        api('customer_package_recent', {}).then(function (r) {
            if (r && r.success && r.data && r.data.packages) { LIST = r.data.packages; FAILED = false; } else { LIST = LIST || []; FAILED = true; }
            if (done) done();
        }).catch(function () { LIST = LIST || []; FAILED = true; if (done) done(); });
    }
    // up to three different recent packages (same recipient and same drop-off count once)
    function recent3() {
        var seen = {}, out = [];
        (LIST || []).forEach(function (p, i) {
            var k = (p.recipient_phone || '') + '|' + (p.drop.address || '');
            if (seen[k] || out.length >= 3) return;
            seen[k] = 1; out.push({ p: p, i: i });
        });
        return out;
    }
    function strip() {
        if (!LIST || !LIST.length) return '';
        var chips = recent3().map(function (e) {
            return '<button type="button" class="pkgh-chip"' + A('pkgAgain', [e.i]) + '><span class="pkgh-chip-n">' + esc(e.p.recipient_name || '-') + '</span><span class="pkgh-chip-s">' + esc(e.p.desc || e.p.drop.address) + '</span></button>';
        }).join('');
        return '<section class="pkg-card pkgh-strip"><div class="pkgh-head"><div><h3 class="pkg-h pkgh-h">' + esc(T('pkgh_again_t')) + '</h3><div class="pkgh-sub">' + esc(T('pkgh_again_s')) + '</div></div>' +
            '<button type="button" class="pkgh-all"' + A('pkgMine') + '>' + esc(T('pkgh_all')) + '</button></div><div class="pkgh-chips">' + chips + '</div></section>';
    }

    // ---------- the full list ----------
    function view() { return document.getElementById('pkgh-view'); }
    function close() { var v = view(); if (v && v.parentNode) v.parentNode.removeChild(v); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') close(); }
    function when(s) { return String(s || '').slice(0, 16); }
    function item(p, i) {
        var st = T('pkgh_st_' + p.status); if (st === 'pkgh_st_' + p.status) st = p.status;
        return '<li class="pkgh-item"><div class="pkgh-row1"><b class="pkgh-code">' + esc(p.order_code) + '</b><span class="pkgh-st g-' + esc(p.group) + '">' + esc(st) + '</span></div>' +
            '<div class="pkgh-route">' + esc(p.pickup.address) + ' → ' + esc(p.drop.address) + '</div>' +
            '<div class="pkgh-meta">' + esc(T('pkgh_to')) + ' ' + esc(p.recipient_name) + ' · ' + esc(when(p.placed_at)) + (p.speed === 'express' ? ' · ' + esc(T('pkgh_express')) : '') + (p.fare_total !== null ? ' · ETB ' + esc(p.fare_total) : '') + '</div>' +
            '<div class="pkgh-btns"><button type="button" class="pkgh-b"' + A('pkgMineTrack', [p.order_id]) + '>' + esc(T('pkgh_track')) + '</button>' +
            '<button type="button" class="pkgh-b pkgh-go"' + A('pkgMineAgain', [i]) + '>' + esc(T('pkgh_resend')) + '</button></div></li>';
    }
    function draw() {
        var v = view(); if (!v) return;
        var all = LIST || [], counts = { all: all.length, active: 0, delivered: 0, cancelled: 0 };
        all.forEach(function (p) { counts[p.group] = (counts[p.group] || 0) + 1; });
        var chips = ['all', 'active', 'delivered', 'cancelled'].map(function (g) {
            return '<button type="button" class="pkgh-f' + (FILTER === g ? ' on' : '') + '" aria-pressed="' + (FILTER === g ? 'true' : 'false') + '"' + A('pkgMineFilter', [g]) + '>' + esc(T('pkgh_f_' + g)) + ' (' + counts[g] + ')</button>';
        }).join('');
        var rows = '';
        all.forEach(function (p, i) { if (FILTER === 'all' || p.group === FILTER) rows += item(p, i); });
        var body = FAILED ? '<p class="pkgh-empty">' + esc(T('pkgh_fail')) + '</p>' : (rows ? '<ul class="pkgh-list">' + rows + '</ul>' : '<p class="pkgh-empty">' + esc(T('pkgh_empty')) + '</p>');
        v.innerHTML = '<div class="pkgh-in"><div class="pkgh-top"><h2 class="pkgh-t">' + esc(T('pkgh_title')) + '</h2><button type="button" class="pkgh-x" aria-label="' + esc(T('pkgh_close')) + '"' + A('pkgMineClose') + '>✕</button></div>' +
            '<div class="pkgh-filters" role="group">' + chips + '</div>' + body + '</div>';
    }
    window.pkgMine = function () {
        close(); FILTER = 'all';
        var v = document.createElement('div');
        v.id = 'pkgh-view'; v.className = 'pkgh-view'; v.setAttribute('role', 'dialog'); v.setAttribute('aria-modal', 'true'); v.setAttribute('aria-label', T('pkgh_title'));
        v.innerHTML = '<div class="pkgh-in"><p class="pkgh-empty">' + esc(T('pkgh_loading')) + '</p></div>';
        document.body.appendChild(v);
        document.addEventListener('keydown', onKey);
        load(draw);
    };
    window.pkgMineFilter = function (g) { FILTER = g; draw(); };
    window.pkgMineClose = close;
    window.pkgMineTrack = function (id) { close(); try { window.pkgTrack(id); } catch (e) {} };
    window.pkgMineAgain = function (i) { var p = LIST && LIST[i]; close(); if (p && window.pkgApply) window.pkgApply(p); };
    window.pkgAgain = function (i) { var p = LIST && LIST[i]; if (p && window.pkgApply) window.pkgApply(p); };
    window.PkgHistory = { load: load, strip: strip };
})();

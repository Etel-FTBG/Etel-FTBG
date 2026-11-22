/* ETEL Driver - the itemized fare (base, distance, waiting, surge, total) on the "Trip in Progress" card (additive script, no bundle release).

   The server already sends the live breakdown with every active-ride read during a trip, and the "Awaiting payment" card shows it, but the running card
   showed only the km and the total. The driver asked to see the details while on the trip. This draws the same table under the waiting box and keeps it
   up to date (the numbers can only grow during a trip, so the page's own guard keeps them from jumping back). Only for an in-progress trip: other
   states already have their own cards. The rider's screen is different on purpose (see ride-trip-simple.js). */
(function () {
    'use strict';
    if (window.__etelDriverTripBreakdown) return;
    window.__etelDriverTripBreakdown = true;

    var ID = 'etel-live-breakdown', last = '';
    function n(v) { v = parseFloat(v); return isFinite(v) ? v : 0; }
    function etb(v) { return 'ETB ' + n(v).toFixed(0); }
    function lang() { try { return localStorage.getItem('etel_lang') === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
    var AM = { 'Fare Breakdown': 'የክፍያ ዝርዝር', 'Base fare': 'መነሻ ዋጋ', 'Distance': 'ርቀት', 'Waiting': 'መጠበቅ', 'Subtotal': 'ንዑስ ድምር', 'Surge': 'የትራፊክ ጭማሪ', 'Minimum fare applied': 'ዝቅተኛ ዋጋ ተተግብሯል', 'Total': 'ጠቅላላ', 'min': 'ደቂቃ' };
    function T(s) { return lang() === 'am' && AM[s] ? AM[s] : s; }
    function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
    function row(label, sub, val, color) {
        return '<div style="display:flex;justify-content:space-between;align-items:baseline;font-size:12.5px;padding:3px 0;color:' + (color || 'var(--text-dim)') + '"><span>' + esc(label) +
            (sub ? ' <span style="opacity:.7;font-size:11px">' + esc(sub) + '</span>' : '') + '</span><span>' + etb(val) + '</span></div>';
    }
    function html(r) {
        var base = n(r.fare_base), dist = n(r.fare_distance), wait = n(r.fare_wait), sub = r.fare_subtotal != null ? n(r.fare_subtotal) : base + dist + wait;
        var mult = n(r.fare_surge_mult) || 1, surge = n(r.fare_surge_etb), floor = n(r.fare_floor_topup), total = n(r.fare_total);
        var km = n(r.traveled_km != null ? r.traveled_km : r.distance_km), wm = n(r.wait_minutes);
        var h = '<div style="font-size:10.5px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px">🧾 ' + esc(T('Fare Breakdown')) + '</div>' +
            row(T('Base fare'), null, base) + row(T('Distance'), km.toFixed(2) + ' km', dist);
        if (wait > 0) h += row(T('Waiting'), wm.toFixed(0) + ' ' + T('min'), wait, '#FFB74D');
        h += '<div style="border-top:1px solid rgba(255,255,255,.08);margin-top:4px;padding-top:6px">' + row(T('Subtotal'), null, sub) + '</div>';
        if (mult > 1 && surge > 0) h += row(T('Surge') + ' (' + mult.toFixed(1) + '×)', null, surge, '#FFB74D');
        if (floor > 0) h += row(T('Minimum fare applied'), null, floor, '#4ECDC4');
        h += '<div style="display:flex;justify-content:space-between;font-size:14px;font-weight:800;padding-top:6px;margin-top:4px;border-top:1px solid rgba(212,175,55,.22)"><span>' + esc(T('Total')) +
            '</span><span style="color:var(--teal)">' + etb(total) + '</span></div>';
        return h;
    }
    function tick() {
        var r = window.DRV && window.DRV.activeRide, box = document.getElementById(ID), host = document.getElementById('wait-toggle-box');
        if (!r || r.status !== 'in_progress' || r.fare_total == null || !host) { if (box && box.parentNode) box.parentNode.removeChild(box); last = ''; return; }
        var sig = [r.fare_base, r.fare_distance, r.fare_wait, r.fare_subtotal, r.fare_surge_mult, r.fare_surge_etb, r.fare_floor_topup, r.fare_total, r.traveled_km, r.wait_minutes, lang()].join('|');
        if (box && box.previousElementSibling === host && sig === last) return;
        if (!box) {
            box = document.createElement('div'); box.id = ID;
            box.style.cssText = 'margin-bottom:12px;background:rgba(255,255,255,.02);border:1px solid rgba(212,175,55,.12);border-radius:12px;padding:12px 14px';
        }
        if (box.previousElementSibling !== host) host.parentNode.insertBefore(box, host.nextSibling);
        if (sig !== last) { box.innerHTML = html(r); last = sig; }
    }
    setInterval(tick, 700);
})();

/* ETEL Ride - shows announcements an admin addressed to riders.
   Standalone (not part of ride-bundle.js): on load it asks for unread rider announcements
   and shows them one at a time; "Got it" marks one as read. Fails silently when offline or signed out. */
(function () {
    'use strict';
    function apiUrl() {
        var path = window.location.pathname, mk = '/delivery/', i = path.indexOf(mk);
        var base = i >= 0 ? path.substring(0, i + mk.length) : path.substring(0, path.lastIndexOf('/') + 1);
        return window.location.protocol + '//' + window.location.host + base + 'api/boot.php';
    }
    function token() { try { return (window.Auth && Auth.getToken) ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); } catch (e) { return ''; } }
    function call(action, body) {
        return fetch(apiUrl(), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() },
            body: JSON.stringify(Object.assign({ action: action }, body || {})) }).then(function (r) { return r.json(); });
    }
    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    var queue = [], host = null;
    function close() { if (host && host.parentNode) host.parentNode.removeChild(host); host = null; }
    function next() {
        close();
        var a = queue.shift();
        if (!a) return;
        host = document.createElement('div');
        host.style.cssText = 'position:fixed;inset:0;z-index:2147482000;background:rgba(0,0,0,.72);display:flex;align-items:center;justify-content:center;padding:18px;font-family:Outfit,system-ui,-apple-system,sans-serif';
        host.innerHTML = '<div style="max-width:380px;width:100%;background:#16130c;color:#f2e8c9;border:1px solid rgba(212,175,55,.45);border-radius:16px;padding:20px;box-shadow:0 20px 60px rgba(0,0,0,.6)">' +
            '<div style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#d4af37;margin-bottom:6px">Message from Etel Ride</div>' +
            '<div style="font-size:18px;font-weight:800;margin-bottom:8px">' + esc(a.title) + '</div>' +
            '<div style="font-size:14px;line-height:1.5;white-space:pre-wrap;margin-bottom:16px">' + esc(a.body) + '</div>' +
            '<button type="button" style="width:100%;padding:12px;border:0;border-radius:12px;background:#d4af37;color:#16130c;font-weight:800;font-size:15px;cursor:pointer">Got it</button></div>';
        host.querySelector('button').onclick = function () {
            call('rider_mark_announcement_read', { announcement_id: a.id }).catch(function () {});
            next();
        };
        document.body.appendChild(host);
    }
    function load() {
        if (!token()) return;
        call('rider_get_announcements').then(function (d) {
            if (!d || !d.success) return;
            queue = ((d.data && d.data.announcements) || []).filter(function (x) { return !x.is_read; }).slice(0, 3);
            next();
        }).catch(function () {});
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(load, 2500); });
    else setTimeout(load, 2500);
})();

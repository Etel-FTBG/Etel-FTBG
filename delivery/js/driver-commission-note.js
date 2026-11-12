/* ETEL Driver - tells the driver what Etel takes from each trip while a launch commission plan applies (additive script,
   no bundle release). Reads driver_get_commission (api): rate, phase (holiday, step or standard), ends_on and the standard rate.
   Shown only while the driver pays less than the standard rate, once per day, and can be closed. Fails quietly. */
(function () {
    'use strict';
    if (window.__etelDriverCommissionNote) return;
    window.__etelDriverCommissionNote = true;
    var KEY = 'etel_commission_note_day';
    function apiUrl() {
        var path = window.location.pathname, mk = '/delivery/', i = path.indexOf(mk);
        var base = i >= 0 ? path.substring(0, i + mk.length) : path.substring(0, path.lastIndexOf('/') + 1);
        return window.location.protocol + '//' + window.location.host + base + 'api/boot.php';
    }
    function token() { try { return (window.Auth && Auth.getToken) ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); } catch (e) { return ''; } }
    function today() { return new Date().toISOString().slice(0, 10); }
    function seen() { try { return localStorage.getItem(KEY) === today(); } catch (e) { return false; } }
    function mark() { try { localStorage.setItem(KEY, today()); } catch (e) { /* not saved */ } }
    function pct(r) { var n = Math.round(parseFloat(r) * 10000) / 100; return String(n); }
    function text(d, am) {
        var rate = pct(d.rate), std = pct(d.standard_rate);
        if (am) return 'የማስጀመሪያ ቅናሽ፦ ኢትኤል ከጉዞዎችዎ ' + rate + '% ብቻ ይወስዳል' + (d.ends_on ? ' እስከ ' + d.ends_on : '') + '። ከዚያ በኋላ ' + std + '% ወይም ዝቅተኛ ደረጃ።';
        return 'Launch offer: Etel takes ' + rate + '% from your trips' + (d.ends_on ? ' until ' + d.ends_on : '') + '. After that the rate goes up to ' + std + '% or a reduced step.';
    }
    function show(d) {
        var am = (document.documentElement.lang || '').toLowerCase().indexOf('am') === 0;
        var el = document.createElement('div');
        el.setAttribute('role', 'status');
        el.style.cssText = 'position:fixed;left:10px;right:10px;top:10px;z-index:99998;background:#0b1a3d;color:#fff;border:1px solid #f6c22b;border-radius:14px;padding:10px 38px 10px 14px;font:600 13px/1.4 system-ui,sans-serif;box-shadow:0 10px 26px rgba(0,0,0,.35)';
        el.textContent = text(d, am);
        var x = document.createElement('button');
        x.type = 'button'; x.setAttribute('aria-label', 'Close'); x.textContent = '×';
        x.style.cssText = 'position:absolute;right:6px;top:4px;background:none;border:0;color:#f6c22b;font-size:22px;line-height:1;cursor:pointer';
        x.onclick = function () { el.remove(); };
        el.appendChild(x);
        document.body.appendChild(el);
        mark();
        setTimeout(function () { if (el.parentNode) el.remove(); }, 20000);
    }
    function run() {
        if (seen() || !token()) return;
        fetch(apiUrl(), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() }, body: JSON.stringify({ action: 'driver_get_commission' }) })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                var d = j && j.success && j.data;
                if (d && d.phase && d.phase !== 'standard' && parseFloat(d.rate) < parseFloat(d.standard_rate)) show(d);
            }).catch(function () { /* quiet */ });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(run, 2500); });
    else setTimeout(run, 2500);
})();

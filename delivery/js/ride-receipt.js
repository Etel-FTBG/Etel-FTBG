/* ETEL Ride - trip receipt (additive script for ride/index.html).

   Adds a "Receipt" button to the ride detail card (opened from Ride History) for
   completed trips. It asks the server for the receipt (api: ride_receipt - the rider's own
   ride only, no driver phone, no coordinates), shows it, and lets the rider Share it as text
   (WhatsApp, Telegram, SMS...), copy it, or print / save as PDF. */
(function () {
    'use strict';
    if (window.EtelReceipt) return;
    var doc = document;
    var host = null, shadow = null, current = null;

    function esc(t) { return String(t == null ? '' : t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function apiUrl() {
        var path = window.location.pathname, mk = '/delivery/', i = path.indexOf(mk);
        var base = i >= 0 ? path.substring(0, i + mk.length) : path.substring(0, path.lastIndexOf('/') + 1);
        return window.location.protocol + '//' + window.location.host + base + 'api/boot.php';
    }
    function token() { try { return (window.Auth && Auth.getToken) ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); } catch (e) { return ''; } }
    function call(action, extra) {
        var p = { action: action }; for (var k in (extra || {})) p[k] = extra[k];
        return fetch(apiUrl(), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() }, body: JSON.stringify(p) })
            .then(function (r) { return r.text(); })
            .then(function (t) { try { return JSON.parse(t); } catch (e) { return { success: false, message: 'Server error. Try again.' }; } })
            .catch(function () { return { success: false, message: 'Cannot reach the server. Check your connection.' }; });
    }
    function money(n) { return 'ETB ' + (parseFloat(n) || 0).toFixed(2); }
    function when(s) { return s ? String(s).replace('T', ' ').substring(0, 16) : ''; }

    function asText(r) {
        var L = ['ETEL Ride receipt', r.receipt_no, when(r.completed_at), '',
            'From: ' + (r.pickup || '-'), 'To: ' + (r.dropoff || '-'), r.distance_km + ' km', ''];
        if (r.driver) L.push('Driver: ' + r.driver + (r.plate ? ' (' + r.plate + ')' : ''));
        (r.lines || []).forEach(function (l) { L.push(l.label + ': ' + money(l.etb)); });
        L.push('Total: ' + money(r.total) + ' (' + r.payment + ')', '', 'Thank you for riding with ETEL.');
        return L.join('\n');
    }

    var CSS = ':host{all:initial}*{box-sizing:border-box}' +
        '.bg{position:fixed;inset:0;z-index:2147483000;background:rgba(0,0,0,.72);display:flex;align-items:flex-end;justify-content:center;font-family:Outfit,system-ui,-apple-system,sans-serif}' +
        '@media(min-width:560px){.bg{align-items:center}}' +
        '.card{width:100%;max-width:420px;max-height:90vh;overflow:auto;background:#11131C;color:#F7F5EE;border:1px solid rgba(212,175,55,.35);border-radius:22px 22px 0 0;padding:18px 18px calc(16px + env(safe-area-inset-bottom,0px));line-height:1.5}' +
        '@media(min-width:560px){.card{border-radius:22px}}' +
        '.top{display:flex;align-items:center;justify-content:space-between;margin-bottom:2px}h2{margin:0;font-size:19px;font-weight:800}' +
        '.x{width:40px;height:40px;border-radius:50%;border:1px solid rgba(255,255,255,.18);background:transparent;color:#C4C6D0;font-size:18px;cursor:pointer}' +
        '.no{color:#F4D56A;font-weight:700;font-size:13px;letter-spacing:.06em}.dim{color:#9A9AAE;font-size:13px}' +
        '.sec{padding:10px 0;border-top:1px solid rgba(255,255,255,.08);font-size:14px}.k{color:#9A9AAE;font-size:11px;text-transform:uppercase;letter-spacing:.06em}' +
        '.ln{display:flex;justify-content:space-between;padding:3px 0}.tot{display:flex;justify-content:space-between;font-size:20px;font-weight:800;color:#F4D56A;padding-top:8px;border-top:1px solid rgba(212,175,55,.35);margin-top:6px}' +
        '.row{display:flex;gap:8px;margin-top:14px}' +
        '.b{flex:1;min-height:46px;padding:0 10px;border-radius:12px;border:1px solid rgba(212,175,55,.45);background:transparent;color:#F7F5EE;font:700 14px Outfit,system-ui,sans-serif;cursor:pointer}' +
        '.b.p{border:0;background:linear-gradient(135deg,#F4D56A,#C89A24);color:#16120A;font-weight:800}';
    function build() {
        if (host) return;
        host = doc.createElement('div');
        host.style.cssText = 'all:initial;position:fixed;inset:0;z-index:2147483000;display:none';
        shadow = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
        shadow.innerHTML = '<style>' + CSS + '</style><div class="bg"><div class="card" id="body"></div></div>';
        doc.body.appendChild(host);
        shadow.querySelector('.bg').addEventListener('click', function (e) { if (e.target === e.currentTarget) close(); });
    }
    function close() { if (host) host.style.display = 'none'; }
    function view(inner) { build(); host.style.display = 'block'; shadow.getElementById('body').innerHTML = inner; var x = shadow.getElementById('x'); if (x) x.onclick = close; }
    var HEAD = '<div class="top"><h2>Receipt</h2><button class="x" id="x" aria-label="Close">&#10005;</button></div>';

    function render(r) {
        current = r;
        var lines = (r.lines || []).map(function (l) { return '<div class="ln"><span>' + esc(l.label) + '</span><span>' + money(l.etb) + '</span></div>'; }).join('');
        view(HEAD + '<div class="no">' + esc(r.receipt_no) + '</div><div class="dim">' + esc(when(r.completed_at)) + '</div>' +
            '<div class="sec"><div class="k">From</div>' + esc(r.pickup || '-') + '<div class="k" style="margin-top:6px">To</div>' + esc(r.dropoff || '-') + '<div class="dim" style="margin-top:4px">' + esc(r.distance_km) + ' km</div></div>' +
            (r.driver ? '<div class="sec"><div class="k">Driver</div>' + esc(r.driver) + (r.plate ? ' &middot; ' + esc(r.plate) : '') + (r.vehicle ? '<div class="dim">' + esc(r.vehicle) + '</div>' : '') + '</div>' : '') +
            '<div class="sec">' + lines + '<div class="tot"><span>Total</span><span>' + money(r.total) + '</span></div><div class="dim" style="text-align:right">Paid by ' + esc(r.payment) + '</div></div>' +
            '<div class="row"><button class="b" id="copy">Copy</button><button class="b" id="print">Print / PDF</button><button class="b p" id="share">Share</button></div><div class="dim" id="msg" style="min-height:18px;margin-top:8px;text-align:center"></div>');
        var msg = shadow.getElementById('msg');
        shadow.getElementById('share').onclick = function () {
            var t = asText(r);
            if (navigator.share) { navigator.share({ title: 'ETEL Ride receipt ' + r.receipt_no, text: t }).catch(function () {}); }
            else copy(t, msg);
        };
        shadow.getElementById('copy').onclick = function () { copy(asText(r), msg); };
        shadow.getElementById('print').onclick = function () { printIt(r); };
    }
    function copy(t, msg) {
        function done(ok) { msg.textContent = ok ? 'Copied.' : 'Could not copy. Use Share or Print.'; }
        try { navigator.clipboard.writeText(t).then(function () { done(true); }, function () { done(false); }); } catch (e) { done(false); }
    }
    function printIt(r) {
        var w = window.open('', '_blank');
        if (!w) return;
        w.document.write('<!doctype html><meta charset="utf-8"><title>' + esc(r.receipt_no) + '</title><body style="font-family:Arial,sans-serif;max-width:420px;margin:24px auto;color:#111"><pre style="white-space:pre-wrap;font:15px/1.6 Arial,sans-serif">' + esc(asText(r)) + '</pre></body>');
        w.document.close(); try { w.focus(); w.print(); } catch (e) {}
    }

    function open(id) {
        view(HEAD + '<p class="dim" style="padding:14px 0">Loading your receipt&hellip;</p>');
        call('ride_receipt', { id: id }).then(function (d) {
            if (d && d.success && d.data) { render(d.data); return; }
            view(HEAD + '<p class="dim" style="padding:14px 0">' + esc((d && d.message) || 'Could not load the receipt.') + '</p>');
        });
    }

    /* Add the button to the ride detail card whenever it opens. */
    function enhance(overlay) {
        var card = overlay.firstElementChild;
        if (!card || card.getAttribute('data-etel-receipt')) return;
        var head = card.firstElementChild, m = head && /Ride #(\d+)\s*[··&]?.*?completed/i.exec(head.textContent || '');
        if (!m) return;
        card.setAttribute('data-etel-receipt', '1');
        var b = doc.createElement('button');
        b.type = 'button'; b.textContent = 'Receipt';
        b.style.cssText = 'width:100%;min-height:46px;margin-top:12px;border-radius:12px;border:1px solid rgba(212,175,55,.5);background:rgba(212,175,55,.12);color:#F4D56A;font:700 14px Outfit,system-ui,sans-serif;cursor:pointer';
        b.addEventListener('click', function () { open(parseInt(m[1], 10)); });
        card.appendChild(b);
    }
    function start() {
        new MutationObserver(function (ms) {
            ms.forEach(function (mu) { [].forEach.call(mu.addedNodes, function (n) {
                if (n.nodeType === 1 && n.id === 'ride-detail-overlay') enhance(n);
            }); });
        }).observe(doc.body, { childList: true });
    }
    window.EtelReceipt = { open: open, _enhance: enhance };
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', start); else start();
})();

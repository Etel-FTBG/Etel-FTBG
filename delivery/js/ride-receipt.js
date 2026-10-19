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
    function money(n) { var v = parseFloat(n) || 0; return (v < 0 ? '- ' : '') + 'ETB ' + Math.abs(v).toFixed(2); }
    function when(s) { return s ? String(s).replace('T', ' ').substring(0, 16) : ''; }

    function asText(r) {
        var L = ['ETEL Ride receipt', r.receipt_no, when(r.completed_at), '',
            'From: ' + (r.pickup || '-'), 'To: ' + (r.dropoff || '-'), r.distance_km + ' km', ''];
        if (r.rider) L.push('Rider: ' + r.rider);
        if (r.driver) L.push('Driver: ' + r.driver + (r.plate ? ' (' + r.plate + ')' : ''));
        (r.lines || []).forEach(function (l) { L.push(l.label + ': ' + money(l.etb)); });
        L.push('Total: ' + money(r.total) + ' (' + r.payment + ')');
        if (r.note) L.push(r.note);
        L.push('', 'Thank you for riding with ETEL.');
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
    var HEAD = '<div class="top"><h2 style="display:flex;align-items:center;gap:10px"><img src="' + logoUrl() + '" alt="" style="width:34px;height:34px;border-radius:50%" onerror="this.style.display=\'none\'">Receipt</h2><button class="x" id="x" aria-label="Close">&#10005;</button></div>';

    function render(r) {
        current = r;
        var lines = (r.lines || []).map(function (l) { return '<div class="ln"><span>' + esc(l.label) + '</span><span>' + money(l.etb) + '</span></div>'; }).join('');
        view(HEAD + '<div class="no">' + esc(r.receipt_no) + '</div><div class="dim">' + esc(when(r.completed_at)) + '</div>' +
            '<div class="sec"><div class="k">From</div>' + esc(r.pickup || '-') + '<div class="k" style="margin-top:6px">To</div>' + esc(r.dropoff || '-') + '<div class="dim" style="margin-top:4px">' + esc(r.distance_km) + ' km</div></div>' +
            (r.rider ? '<div class="sec"><div class="k">Rider</div>' + esc(r.rider) + '</div>' : '') + (r.driver ? '<div class="sec"><div class="k">Driver</div>' + esc(r.driver) + (r.plate ? ' &middot; ' + esc(r.plate) : '') + (r.vehicle ? '<div class="dim">' + esc(r.vehicle) + '</div>' : '') + '</div>' : '') +
            '<div class="sec">' + lines + '<div class="tot"><span>Total</span><span>' + money(r.total) + '</span></div><div class="dim" style="text-align:right">Paid by ' + esc(r.payment) + '</div>' + (r.note ? '<div class="dim" style="margin-top:6px">' + esc(r.note) + '</div>' : '') + '</div>' +
            '<div class="row"><button class="b" id="copy">Copy</button><button class="b" id="print">Print / PDF</button><button class="b p" id="share">Share</button></div>' +
            (window.EtelRidePrinter && window.EtelRidePrinter.isSupported() ? '<div class="row"><button class="b" id="thermal">Print on receipt printer</button></div>' : '') + '<div class="dim" id="msg" style="min-height:18px;margin-top:8px;text-align:center"></div>');
        var msg = shadow.getElementById('msg');
        shadow.getElementById('share').onclick = function () {
            var t = asText(r);
            if (navigator.share) { navigator.share({ title: 'ETEL Ride receipt ' + r.receipt_no, text: t }).catch(function () {}); }
            else copy(t, msg);
        };
        shadow.getElementById('copy').onclick = function () { copy(asText(r), msg); };
        shadow.getElementById('print').onclick = function () { printIt(r); };
        var th = shadow.getElementById('thermal');
        if (th) th.onclick = function () {
            msg.textContent = 'Connecting to the printer...';
            window.EtelRidePrinter.printReceipt(r).then(function () { msg.textContent = 'Sent to the printer.'; },
                function (e) { msg.textContent = (e && e.message) || 'Could not reach the printer.'; });
        };
    }
    function copy(t, msg) {
        function done(ok) { msg.textContent = ok ? 'Copied.' : 'Could not copy. Use Share or Print.'; }
        try { navigator.clipboard.writeText(t).then(function () { done(true); }, function () { done(false); }); } catch (e) { done(false); }
    }
    function logoUrl() { return apiUrl().replace('api/boot.php', 'icons/etel-ride-coin.png'); }

    /* The designed, printable receipt (A4, colour). Opens in its own window and prints / saves as PDF. */
    function receiptHtml(r) {
        var rows = (r.lines || []).map(function (l) {
            return '<tr><td>' + esc(l.label) + '</td><td class="n">' + money(l.etb) + '</td></tr>';
        }).join('') || '<tr><td>Trip fare</td><td class="n">' + money(r.total) + '</td></tr>';
        var veh = [r.vehicle, r.plate].filter(Boolean).join('  ·  ');
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
        '<title>' + esc(r.receipt_no) + '</title><style>' +
        '@page{size:A4;margin:0}*{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}' +
        'html,body{margin:0;background:#EDE7D6}body{font-family:Helvetica,Arial,sans-serif;color:#1A1712}' +
        '.page{width:210mm;min-height:297mm;margin:0 auto;background:#fff;position:relative;overflow:hidden}' +
        '.top{background:linear-gradient(135deg,#14110A 0%,#2A2210 100%);color:#F7F1DE;padding:14mm 16mm 12mm;position:relative}' +
        '.top:after{content:"";position:absolute;left:0;right:0;bottom:0;height:2.2mm;background:linear-gradient(90deg,#8A6A12,#F4D56A 45%,#B8891B 70%,#8A6A12)}' +
        '.brand{display:flex;align-items:center;gap:6mm}.brand img{width:26mm;height:26mm;border-radius:50%;box-shadow:0 0 0 .8mm rgba(244,213,106,.55)}' +
        '.brand h1{margin:0;font-size:22pt;letter-spacing:.04em;font-weight:800;color:#F4D56A}.brand .sub{font-size:9.5pt;letter-spacing:.32em;text-transform:uppercase;color:#D9CFAE;margin-top:1.5mm}' +
        '.meta{position:absolute;right:16mm;top:15mm;text-align:right}.meta .k{font-size:8pt;letter-spacing:.22em;text-transform:uppercase;color:#BFB58F}.meta .v{font-size:12pt;font-weight:700;color:#F4D56A;margin:.5mm 0 3mm}' +
        '.body{padding:11mm 16mm 0}' +
        '.paid{display:inline-block;border:.6mm solid #2E8B57;color:#2E8B57;font-weight:800;letter-spacing:.2em;font-size:9pt;padding:1.2mm 4mm;border-radius:2mm}' +
        '.hd{display:flex;justify-content:space-between;align-items:center;margin-bottom:7mm}.hd h2{margin:0;font-size:15pt;letter-spacing:.02em}' +
        '.route{position:relative;padding-left:9mm;margin-bottom:8mm}.route:before{content:"";position:absolute;left:2.2mm;top:3mm;bottom:3mm;border-left:.5mm dashed #C9A94A}' +
        '.stop{position:relative;margin-bottom:5mm}.stop:last-child{margin-bottom:0}.dot{position:absolute;left:-9mm;top:.6mm;width:5mm;height:5mm;border-radius:50%;background:#fff;border:1.2mm solid #B8891B}.stop.end .dot{background:#B8891B}' +
        '.lab{font-size:7.5pt;letter-spacing:.2em;text-transform:uppercase;color:#8C8466}.val{font-size:12pt;font-weight:700;margin-top:.5mm}' +
        '.grid{display:flex;gap:6mm;margin-bottom:8mm}.card{flex:1;border:.3mm solid #E4D9B6;border-radius:3mm;padding:4mm 5mm;background:#FBF8EE}.card .val{font-size:11.5pt}.card .s{font-size:9.5pt;color:#6F6850;margin-top:1mm}' +
        'table{width:100%;border-collapse:collapse;margin-bottom:2mm}td{padding:3mm 0;border-bottom:.25mm solid #ECE4CA;font-size:11pt}td.n{text-align:right;font-variant-numeric:tabular-nums}' +
        '.total{display:flex;justify-content:space-between;align-items:center;background:linear-gradient(135deg,#14110A,#2A2210);color:#F4D56A;border-radius:3mm;padding:5mm 6mm;margin-top:4mm}.total .l{font-size:10pt;letter-spacing:.22em;text-transform:uppercase;color:#D9CFAE}.total .a{font-size:21pt;font-weight:800}' +
        '.pay{text-align:right;font-size:9.5pt;color:#6F6850;margin-top:2.5mm}' +
        '.thanks{text-align:center;margin:14mm 0 0}.thanks b{font-size:13pt;color:#8A6A12}.thanks div{font-size:9.5pt;color:#6F6850;margin-top:1.5mm}' +
        '.foot{position:absolute;left:0;right:0;bottom:0;padding:5mm 16mm;background:#14110A;color:#BFB58F;font-size:8pt;display:flex;justify-content:space-between;letter-spacing:.04em}.foot b{color:#F4D56A}' +
        '@media screen{.page{margin:0 auto;box-shadow:0 4px 24px rgba(0,0,0,.25)}}' +
        '@media print{html,body{background:#fff}.page{margin:0;box-shadow:none}}' +
        '</style></head><body><div class="page">' +
        '<div class="top"><div class="brand"><img id="lg" src="' + esc(logoUrl()) + '" alt="ETEL Ride"><div><h1>ETEL RIDE</h1><div class="sub">Trip receipt</div></div></div>' +
        '<div class="meta"><div class="k">Receipt no.</div><div class="v">' + esc(r.receipt_no) + '</div><div class="k">Date</div><div class="v" style="margin-bottom:0">' + esc(when(r.completed_at)) + '</div></div></div>' +
        '<div class="body"><div class="hd"><h2>Your trip</h2><span class="paid">PAID</span></div>' +
        '<div class="route"><div class="stop"><span class="dot"></span><div class="lab">Pick-up</div><div class="val">' + esc(r.pickup || '-') + '</div></div>' +
        '<div class="stop end"><span class="dot"></span><div class="lab">Drop-off</div><div class="val">' + esc(r.dropoff || '-') + '</div></div></div>' +
        '<div class="grid"><div class="card"><div class="lab">Driver</div><div class="val">' + esc(r.driver || '-') + '</div><div class="s">' + esc(veh || ' ') + '</div></div>' +
        '<div class="card"><div class="lab">Distance</div><div class="val">' + esc(r.distance_km) + ' km</div><div class="s">Paid by ' + esc(r.payment) + (r.rider ? ' &middot; Rider: ' + esc(r.rider) : '') + '</div></div></div>' +
        '<div class="lab" style="margin-bottom:1mm">Fare details</div><table>' + rows + '</table>' +
        '<div class="total"><span class="l">Total paid</span><span class="a">' + money(r.total) + '</span></div>' + (r.note ? '<div class="lab" style="margin-top:2mm">' + esc(r.note) + '</div>' : '') +
        '<div class="thanks"><b>Thank you for riding with ETEL</b><div>Safe rides across Hawassa, fast and fair.</div></div></div>' +
        '<div class="foot"><span><b>ETEL Group</b> &middot; Hawassa, Ethiopia</span><span>ethelmarket.com</span></div>' +
        '</div></body></html>';
    }
    function printIt(r) {
        var w = window.open('', '_blank');
        if (!w) return;
        w.document.open(); w.document.write(receiptHtml(r)); w.document.close();
        var go = function () { try { w.focus(); w.print(); } catch (e) {} };
        var img = w.document.getElementById('lg');
        var fired = false; function once() { if (!fired) { fired = true; setTimeout(go, 150); } }
        if (img && !img.complete) { img.onload = once; img.onerror = once; setTimeout(once, 2500); } else once();
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
                if (n.nodeType !== 1) return;
                // The app wraps the card in a plain <div> before adding it to the page, so look inside too.
                var o = n.id === 'ride-detail-overlay' ? n : (n.querySelector ? n.querySelector('#ride-detail-overlay') : null);
                if (o) enhance(o);
            }); });
        }).observe(doc.body, { childList: true, subtree: true });
    }
    window.EtelReceipt = { open: open, _enhance: enhance, _html: receiptHtml };
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', start); else start();
})();

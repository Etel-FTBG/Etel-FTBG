/* ETEL Ride - Bluetooth thermal receipt printer (additive script for ride/index.html and driver/index.html).

   Same approach as js/admin/admin-printer.js (a paired 58 mm ESC/POS printer such as the P58E,
   over Web Bluetooth - no bridge app). Adds:
   - a "Receipt printer" row in the rider and driver profile (opens a small panel: Connect / Print test / Forget),
   - window.EtelRidePrinter.printReceipt(receipt) - used by the Receipt panel's "Printer" button.

   The browser forgets a Bluetooth connection when you leave a page, so every page has to find the
   printer again. This script remembers the printer in localStorage and looks it up with
   navigator.bluetooth.getDevices(); it also reuses the printer chosen on the Admin page (same
   website, same saved id), so a printer connected there is found here without picking it again.
   Needs Chrome on Android (or desktop Chrome/Edge) over https. */
(function () {
    'use strict';
    if (window.EtelRidePrinter) return;
    var doc = document;
    var GATT = ['000018f0-0000-1000-8000-00805f9b34fb', '49535343-fe7d-4ae5-8fa9-9fafd205e455', 'e7810a71-73ae-499d-8c15-faa9aef0c3f2'];
    var KEY = 'etel_ride_printer_device_id', ADMIN_KEY = 'etel_admin_printer_device_id';
    var dev = null, chr = null;

    function ls(k, v) { try { if (v === undefined) return localStorage.getItem(k); if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) {} return null; }
    function supported() { return !!navigator.bluetooth; }
    function esc(t) { return String(t == null ? '' : t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function logoUrl() {
        var path = window.location.pathname, mk = '/delivery/', i = path.indexOf(mk);
        var base = i >= 0 ? path.substring(0, i + mk.length) : path.substring(0, path.lastIndexOf('/') + 1);
        return window.location.protocol + '//' + window.location.host + base + 'icons/etel-ride-coin.png';
    }

    /* ---------- Bluetooth ---------- */
    function pick(allowPicker) {
        var known = (navigator.bluetooth.getDevices ? navigator.bluetooth.getDevices() : Promise.resolve([])).catch(function () { return []; });
        return known.then(function (list) {
            var ids = [ls(KEY), ls(ADMIN_KEY)];
            var m = null;
            ids.forEach(function (id) { if (!m && id) m = list.filter(function (d) { return d.id === id; })[0] || null; });
            if (!m) m = list.filter(function (d) { return (d.name || '').toUpperCase().indexOf('P58E') !== -1; })[0] || null;
            if (m) return m;
            if (!allowPicker) throw new Error('No printer yet. Tap Connect printer first.');
            return navigator.bluetooth.requestDevice({ acceptAllDevices: true, optionalServices: GATT }).then(function (d) { ls(KEY, d.id); return d; });
        });
    }
    function writable(server) {
        return server.getPrimaryServices().then(function (svcs) {
            var i = 0;
            function next() {
                if (i >= svcs.length) return null;
                return svcs[i++].getCharacteristics().then(function (cs) {
                    for (var k = 0; k < cs.length; k++) if (cs[k].properties && (cs[k].properties.write || cs[k].properties.writeWithoutResponse)) return cs[k];
                    return next();
                });
            }
            return next();
        });
    }
    function connect(allowPicker) {
        if (!supported()) return Promise.reject(new Error('This browser cannot talk to a Bluetooth printer. Use Chrome on Android.'));
        if (chr && dev && dev.gatt && dev.gatt.connected) return Promise.resolve(chr);
        return (dev ? Promise.resolve(dev) : pick(allowPicker)).then(function (d) {
            return d.gatt.connect().then(function (server) {
                return writable(server).then(function (c) {
                    if (!c) throw new Error('The printer has no writable Bluetooth channel.');
                    dev = d; chr = c; ls(KEY, d.id); return c;
                });
            });
        });
    }
    function forget() { try { if (dev && dev.gatt && dev.gatt.connected) dev.gatt.disconnect(); } catch (e) {} dev = null; chr = null; ls(KEY, null); }
    function send(bytes, allowPicker) {
        return connect(allowPicker).then(function (c) {
            var i = 0;
            function step() {
                if (i >= bytes.length) return null;
                var chunk = bytes.slice(i, i + 20); i += 20;
                var w = c.properties.writeWithoutResponse ? c.writeValueWithoutResponse(chunk) : c.writeValue(chunk);
                return w.then(function () { return new Promise(function (r) { setTimeout(r, 8); }); }).then(step);
            }
            return step();
        });
    }
    function status() {
        if (!supported()) return Promise.resolve({ ok: false, text: 'Not supported on this browser' });
        if (dev && dev.gatt && dev.gatt.connected) return Promise.resolve({ ok: true, text: 'Connected: ' + (dev.name || 'printer') });
        var known = navigator.bluetooth.getDevices ? navigator.bluetooth.getDevices().catch(function () { return []; }) : Promise.resolve([]);
        return known.then(function (list) {
            var id = ls(KEY) || ls(ADMIN_KEY);
            var m = list.filter(function (d) { return d.id === id; })[0] || list.filter(function (d) { return (d.name || '').toUpperCase().indexOf('P58E') !== -1; })[0];
            if (m) return { ok: true, text: 'Saved: ' + (m.name || 'printer') + ' (connects when you print)' };
            return { ok: false, text: id ? 'Saved printer not visible - tap Connect' : 'Not connected' };
        });
    }

    /* ---------- ESC/POS bytes ---------- */
    function ascii(s) { s = String(s == null ? '' : s); if (s.normalize) s = s.normalize('NFKD'); return s.replace(/[^\x20-\x7E]/g, '?'); }
    function T(b, s) { var a = ascii(s); for (var i = 0; i < a.length; i++) b.push(a.charCodeAt(i)); }
    function E(b) { for (var i = 1; i < arguments.length; i++) b.push(arguments[i]); }
    var DASH = '--------------------------------\n', W = 32;
    function pair(l, r) { l = ascii(l); r = ascii(r); var gap = W - l.length - r.length; if (gap < 1) { l = l.substring(0, Math.max(1, W - r.length - 1)); gap = W - l.length - r.length; } return l + new Array(gap + 1).join(' ') + r + '\n'; }
    function wrap(b, prefix, s) { var a = prefix + ascii(s), out = ''; while (a.length > W) { out += a.substring(0, W) + '\n'; a = '  ' + a.substring(W); } T(b, out + a + '\n'); }
    function etb(n) { return 'ETB ' + (parseFloat(n) || 0).toFixed(2); }

    /* Dithered 1-bit image, the same method the admin printer uses (a flat threshold turns the gold coin into a black blob). */
    function logoBytes(url, maxW) {
        return new Promise(function (resolve, reject) {
            var img = new Image(); img.crossOrigin = 'anonymous';
            img.onload = function () {
                try {
                    var scale = Math.min(1, maxW / img.naturalWidth), w = Math.ceil(Math.max(8, Math.round(img.naturalWidth * scale)) / 8) * 8, h = Math.max(1, Math.round(img.naturalHeight * scale));
                    var cv = doc.createElement('canvas'); cv.width = w; cv.height = h; var cx = cv.getContext('2d');
                    cx.fillStyle = '#fff'; cx.fillRect(0, 0, w, h); cx.drawImage(img, 0, 0, w, h);
                    var px = cx.getImageData(0, 0, w, h).data, g = new Float32Array(w * h), x, y, i;
                    for (y = 0; y < h; y++) for (x = 0; x < w; x++) { i = (y * w + x) * 4; var a = px[i + 3] / 255; g[y * w + x] = (0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2]) * a + 255 * (1 - a); }
                    var wb = w / 8, bm = new Uint8Array(wb * h);
                    for (y = 0; y < h; y++) for (x = 0; x < w; x++) {
                        var idx = y * w + x, old = g[idx], blk = old < 128;
                        if (blk) bm[y * wb + (x >> 3)] |= (0x80 >> (x & 7));
                        var er = old - (blk ? 0 : 255);
                        if (x + 1 < w) g[idx + 1] += er * 7 / 16;
                        if (y + 1 < h) { if (x > 0) g[idx - 1 + w] += er * 3 / 16; g[idx + w] += er * 5 / 16; if (x + 1 < w) g[idx + 1 + w] += er / 16; }
                    }
                    var hdr = [0x1D, 0x76, 0x30, 0x00, wb & 0xFF, (wb >> 8) & 0xFF, h & 0xFF, (h >> 8) & 0xFF];
                    var out = new Uint8Array(hdr.length + bm.length); out.set(hdr, 0); out.set(bm, hdr.length); resolve(out);
                } catch (e) { reject(e); }
            };
            img.onerror = function () { reject(new Error('logo')); };
            img.src = url;
        });
    }
    function header(b, title) {
        return logoBytes(logoUrl(), 150).then(function (lg) { for (var i = 0; i < lg.length; i++) b.push(lg[i]); T(b, '\n'); }, function () {}).then(function () {
            E(b, 0x1B, 0x61, 0x01, 0x1B, 0x45, 0x01); T(b, 'ETEL RIDE\n'); E(b, 0x1B, 0x45, 0x00); T(b, title + '\n'); T(b, DASH);
        });
    }
    function footer(b) { E(b, 0x1B, 0x61, 0x01); T(b, 'Thank you for riding with ETEL\n'); T(b, 'ethelmarket.com\n'); T(b, '\n\n\n'); E(b, 0x1D, 0x56, 0x00); }

    function receiptBytes(r) {
        var b = []; E(b, 0x1B, 0x40);
        return header(b, 'TRIP RECEIPT').then(function () {
            E(b, 0x1B, 0x61, 0x00);
            T(b, (r.receipt_no || '') + '\n'); if (r.completed_at) T(b, String(r.completed_at).replace('T', ' ').substring(0, 16) + '\n');
            T(b, DASH);
            wrap(b, 'From: ', r.pickup || '-'); wrap(b, 'To:   ', r.dropoff || '-');
            T(b, pair('Distance', (r.distance_km || 0) + ' km'));
            if (r.rider) wrap(b, 'Rider: ', r.rider);
            if (r.driver) { wrap(b, 'Driver: ', r.driver); if (r.plate || r.vehicle) T(b, '        ' + ascii([r.vehicle, r.plate].filter(Boolean).join(' ')) + '\n'); }
            T(b, DASH);
            (r.lines || []).forEach(function (l) { T(b, pair(l.label, etb(l.etb))); });
            T(b, DASH);
            E(b, 0x1D, 0x21, 0x01, 0x1B, 0x45, 0x01); T(b, pair('TOTAL', etb(r.total).replace('ETB ', ''))); E(b, 0x1D, 0x21, 0x00, 0x1B, 0x45, 0x00);
            T(b, pair('Paid by', r.payment || 'cash')); T(b, DASH);
            footer(b); return new Uint8Array(b);
        });
    }
    function testBytes() {
        var b = []; E(b, 0x1B, 0x40);
        return header(b, 'PRINTER TEST').then(function () {
            E(b, 0x1B, 0x61, 0x01); T(b, 'Your printer is connected.\n'); T(b, new Date().toLocaleString() + '\n'); footer(b); return new Uint8Array(b);
        });
    }

    /* ---------- panel (Shadow DOM) ---------- */
    var host = null, shadow = null;
    var CSS = ':host{all:initial}*{box-sizing:border-box}' +
        '.bg{position:fixed;inset:0;z-index:2147483000;background:rgba(0,0,0,.72);display:flex;align-items:flex-end;justify-content:center;font-family:Outfit,system-ui,-apple-system,sans-serif}' +
        '@media(min-width:560px){.bg{align-items:center}}' +
        '.card{width:100%;max-width:420px;background:#11131C;color:#F7F5EE;border:1px solid rgba(212,175,55,.35);border-radius:22px 22px 0 0;padding:18px 18px calc(16px + env(safe-area-inset-bottom,0px));line-height:1.5}' +
        '@media(min-width:560px){.card{border-radius:22px}}' +
        '.top{display:flex;align-items:center;justify-content:space-between}h2{margin:0;font-size:19px;font-weight:800}' +
        '.x{width:40px;height:40px;border-radius:50%;border:1px solid rgba(255,255,255,.18);background:transparent;color:#C4C6D0;font-size:18px;cursor:pointer}' +
        'p{margin:6px 0 10px;color:#C4C6D0;font-size:14px}.st{display:flex;align-items:center;gap:8px;padding:10px 12px;border-radius:12px;background:#0B0C12;border:1px solid rgba(255,255,255,.1);font-size:14px}' +
        '.dot{width:10px;height:10px;border-radius:50%;background:#8A8F9C}.dot.on{background:#2ECC71}.msg{min-height:20px;margin-top:8px;font-size:13px;color:#F4D56A}.msg.err{color:#FF9C98}' +
        '.row{display:flex;gap:8px;margin-top:12px}.b{flex:1;min-height:48px;border-radius:12px;border:1px solid rgba(212,175,55,.45);background:transparent;color:#F7F5EE;font:700 14px Outfit,system-ui,sans-serif;cursor:pointer}' +
        '.b.p{border:0;background:linear-gradient(135deg,#F4D56A,#C89A24);color:#16120A;font-weight:800}.b:disabled{opacity:.5}.hint{font-size:12px;color:#9A9AAE;margin-top:12px}';
    function build() {
        if (host) return;
        host = doc.createElement('div'); host.style.cssText = 'all:initial;position:fixed;inset:0;z-index:2147483000;display:none';
        shadow = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
        shadow.innerHTML = '<style>' + CSS + '</style><div class="bg"><div class="card"><div class="top"><h2>Receipt printer</h2><button class="x" id="x" aria-label="Close">&#10005;</button></div>' +
            '<p>Connect a Bluetooth thermal printer (for example the P58E) to print trip receipts with the ETEL logo.</p>' +
            '<div class="st"><span class="dot" id="dot"></span><span id="stt">Checking&hellip;</span></div><div class="msg" id="msg"></div>' +
            '<div class="row"><button class="b p" id="conn">Connect printer</button><button class="b" id="test">Print test</button></div>' +
            '<div class="row"><button class="b" id="forget">Forget printer</button></div>' +
            '<div class="hint">If your phone asks you to pick the printer every time, switch on chrome://flags &rarr; "Web Bluetooth new permissions backend", then restart Chrome.</div></div></div>';
        doc.body.appendChild(host);
        shadow.getElementById('x').onclick = close;
        shadow.querySelector('.bg').addEventListener('click', function (e) { if (e.target === e.currentTarget) close(); });
        shadow.getElementById('conn').onclick = function () { run(shadow.getElementById('conn'), 'Connecting...', function () { return connect(true).then(function () { return 'Connected.'; }); }); };
        shadow.getElementById('test').onclick = function () { run(shadow.getElementById('test'), 'Printing...', function () { return connect(true).then(testBytes).then(function (by) { return send(by, true); }).then(function () { return 'Sent to the printer.'; }); }); };
        shadow.getElementById('forget').onclick = function () { forget(); refresh(); say('Printer forgotten.'); };
    }
    function say(t, err) { var m = shadow.getElementById('msg'); m.textContent = t || ''; m.className = 'msg' + (err ? ' err' : ''); }
    function run(btn, working, fn) {
        say(working); btn.disabled = true;
        Promise.resolve().then(fn).then(function (t) { say(t); }, function (e) { say((e && e.message) || 'Could not reach the printer.', true); })
            .then(function () { btn.disabled = false; refresh(); });
    }
    function refresh() {
        status().then(function (s) {
            if (host) { shadow.getElementById('dot').className = 'dot' + (s.ok ? ' on' : ''); shadow.getElementById('stt').textContent = s.text; }
            [].forEach.call(doc.querySelectorAll('[data-etel-printer-sub]'), function (el) { el.textContent = s.text; });
        });
    }
    function open() { build(); host.style.display = 'block'; say(''); refresh(); }
    function close() { if (host) host.style.display = 'none'; }

    /* Print a receipt object (as returned by api ride_receipt). Called from a tap, so the picker may open. */
    function printReceipt(r) {
        return connect(true).then(function () { return receiptBytes(r); }).then(function (by) { return send(by, true); });
    }

    window.EtelRidePrinter = { isSupported: supported, connect: connect, forget: forget, printReceipt: printReceipt, open: open, status: status };
    doc.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('[data-etel-printer-open]') : null;
        if (t) { e.preventDefault(); open(); }
    });
    function start() { refresh(); }
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', start); else start();
})();

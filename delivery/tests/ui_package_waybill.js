// Send a package: the booking confirmation is a premium Etel waybill (tracking number, QR code, route, vehicle, total, 5-step timeline) with Share, Copy and Receipt buttons.
// Real page and files (customer.html, js/customer-package.js, js/customer-package-waybill.js), a fake API.   node delivery/tests/ui_package_waybill.js
const { chromium } = require('playwright'); const path = require('path'); const http = require('http'); const fs = require('fs');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.woff2': 'font/woff2' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });
const OPTIONS = [
  { vehicle_class: 'moto', label: 'Motorbike', icon: '\u{1F3CD}', price: '120.00', max_weight_kg: 20, rank: 1 },
  { vehicle_class: 'car', label: 'Car', icon: '\u{1F697}', price: '220.00', max_weight_kg: 120, rank: 2 }];
const OFF = [
  { vehicle_class: 'bike', label: 'Bicycle', icon: '\u{1F6B2}', reason: 'weight', max_weight_kg: 5, rank: 0 },
  { vehicle_class: 'van', label: 'Van', icon: '\u{1F690}', reason: 'size', max_weight_kg: 500, rank: 3 }];
const BANKS = [{ bank_key: 'abysinia', display_name: 'Bank of Abyssinia', account_name: 'Etel Group', account_number: '1234567890' }];
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const PAGE = 'http://127.0.0.1:' + srv.address().port + '/customer.html';
  const b = await chromium.launch();
  for (const theme of ['light', 'dark']) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    await ctx.addInitScript(th => { localStorage.setItem('theme', th); localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 5, name: 'Cust', phone: '0911000001', role: 'customer' })); }, theme);
    const issues = []; const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message + ' @ ' + (e.stack || '').split('\n')[1]));
    await p.route(/maps\.googleapis|gstatic|fonts\./, r => r.abort());
    await p.route(/boot\.php/, r => {
      const u = r.request().url(); let body = {}; try { body = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
      const act = (u.match(/action=([a-z_]+)/) || [])[1] || body.action; let data = {};
      if (act === 'customer_get_items' || act === 'get_items') data = { items: [], count: 0 };
      else if (act === 'customer_package_options') data = { sizes: [{ size: 'document' }, { size: 'small' }, { size: 'medium' }], vehicles: [], zones: [] };
      else if (act === 'customer_package_quote') data = { distance_km: 4.2, eta_min: 18, options: [{ vehicle_class: 'moto', label: 'Motorbike', icon: '\u{1F3CD}', price: '120.00', max_weight_kg: 20, rank: 1 }], unavailable: [], pickup_zone: 'A', drop_zone: 'B' };
      else if (act === 'customer_package_create') data = { order_id: 77, order_code: 'PKG-20261126-0077', total: '120.00', track_token: 'abc123abc123' };
      else if (act === 'submit_order_issue') { issues.push(body); data = { issue_id: 5 }; }
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
    });
    await p.goto(PAGE); await p.waitForTimeout(2000);
    await p.evaluate(() => { [].slice.call(document.querySelectorAll('button')).forEach(x => { if (/^\s*Not Now\s*$/.test(x.textContent)) x.click(); }); pkgOpen(); }); await p.waitForTimeout(600);
    const setf = async (f, v) => { await p.evaluate(([f, v]) => { const el = document.querySelector('[data-f="' + f + '"]'); el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }, [f, v]); };
    const zone = await p.evaluate(() => { const s = document.querySelector('select[data-f="pickup.zone"]'); return s && s.options[1] ? s.options[1].value : ''; });
    await setf('pickup.address', 'Piassa, 2FQM+RHF, Hawassa, Ethiopia'); await setf('drop.address', 'Tabor home');
    if (zone) { await setf('pickup.zone', zone); await setf('drop.zone', zone); }
    await setf('sender_name', 'Sender'); await setf('sender_phone', '0911000001'); await setf('recipient_name', 'Rita'); await setf('recipient_phone', '0911223344');
    await p.evaluate(() => pkgNext()); await p.evaluate(() => pkgSize('small')); await setf('weight', '8'); await setf('desc', 'Books');
    await p.evaluate(() => { const c = document.getElementById('pkg-f-ack'); c.checked = true; c.dispatchEvent(new Event('change', { bubbles: true })); });
    await p.evaluate(() => pkgNext()); await p.waitForTimeout(700); await p.evaluate(() => pkgVehicle('moto')); await p.evaluate(() => pkgNext()); await p.waitForTimeout(400);
    await p.evaluate(() => pkgSubmit()); await p.waitForTimeout(900);
    const n = theme + ': ';
    const txt = await p.evaluate(() => document.getElementById('pkg-scroll').innerText.replace(/\s+/g, ' '));
    t(n + 'the waybill shows the tracking number', /PKG-20261126-0077/.test(txt), txt.slice(0, 200));
    t(n + 'it shows route, vehicle, recipient, distance, payment and total', /Piassa, 2FQM\+RHF/.test(txt) && /Tabor home/.test(txt) && /Motorbike/.test(txt) && /Rita/.test(txt) && /4\.2 km/.test(txt) && /Cash/.test(txt) && /ETB 120\.00/.test(txt), txt);
    t(n + 'the QR code is drawn', await p.evaluate(() => { const q = document.getElementById('pkgw-qr'); return !!(q && q.querySelector('canvas, img')); }));
    t(n + 'the timeline has 5 steps, only the first is done', await p.evaluate(() => document.querySelectorAll('.pkgw-st').length === 5 && document.querySelectorAll('.pkgw-st.done').length === 1));
    t(n + 'the vehicle shows its real picture', await p.evaluate(() => { const i = document.querySelector('.pkgw-vp img'); return !!i && /moto\.png/.test(i.getAttribute('src')); }));
    t(n + 'the old buttons (Track package, Done) are still there', await p.evaluate(() => /Track package/.test(document.querySelector('.pkg-foot').innerText)));
    // actions
    const shared = await p.evaluate(async () => { let got = null; navigator.share = function (o) { got = o; return Promise.resolve(); }; [].slice.call(document.querySelectorAll('.pkgw-b'))[0].click(); return got; });
    t(n + 'Share gives the phone share sheet a text with the code, from, to and the tracking link', shared && /PKG-20261126-0077/.test(shared.text) && /Tabor home/.test(shared.text) && /track\.html\?c=PKG-20261126-0077&t=abc123abc123/.test(shared.text), JSON.stringify(shared));
    const copied = await p.evaluate(async () => { let got = ''; Object.defineProperty(navigator, 'clipboard', { value: { writeText: v => { got = v; return Promise.resolve(); } }, configurable: true }); [].slice.call(document.querySelectorAll('.pkgw-b'))[1].click(); return got; });
    t(n + 'Copy puts the tracking number on the clipboard', copied === 'PKG-20261126-0077', copied);
    const rec = await p.evaluate(() => { let html = ''; window.open = function () { return { document: { open() {}, write(h) { html = h; }, close() {} } }; }; [].slice.call(document.querySelectorAll('.pkgw-b'))[2].click(); return html; });
    t(n + 'Receipt writes a printable page with the code, total and a QR image', /PKG-20261126-0077/.test(rec) && /ETB 120\.00/.test(rec) && /<img src="data:image\/png/.test(rec) && /window\.print/.test(rec), rec.slice(0, 120));
    t(n + 'the receipt shows the tracking link', /track\.html\?c=PKG-20261126-0077&amp;t=abc123abc123|track\.html\?c=PKG-20261126-0077&t=abc123abc123/.test(rec));
    // report a problem
    await p.evaluate(() => { [].slice.call(document.querySelectorAll('.pkgw-prob'))[0].click(); });
    t(n + 'Report a problem opens a sheet', await p.evaluate(() => !!document.getElementById('pkgw-sheet') && document.querySelectorAll('#pkgw-cat option').length === 6));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/pkg_problem_' + theme + '.png' });
    await p.evaluate(() => { document.getElementById('pkgw-cancel').click(); });
    t(n + 'Cancel closes the sheet and sends nothing', await p.evaluate(() => !document.getElementById('pkgw-sheet')) && issues.length === 0);
    await p.evaluate(() => { [].slice.call(document.querySelectorAll('.pkgw-prob'))[0].click(); document.getElementById('pkgw-cat').value = 'item_damaged'; document.getElementById('pkgw-det').value = 'Box is crushed'; document.getElementById('pkgw-send').click(); });
    await p.waitForTimeout(500);
    t(n + 'Send files the report for this order with the chosen reason', issues.length === 1 && issues[0].order_id === 77 && issues[0].category === 'item_damaged' && /Box is crushed/.test(issues[0].details) && /PKG-20261126-0077/.test(issues[0].details), JSON.stringify(issues));
    t(n + 'the sheet closes after sending', await p.evaluate(() => !document.getElementById('pkgw-sheet')));
    // readable: every text in the card has contrast >= 4.5 on its own background
    const bad = await p.evaluate(() => {
      const parse = s => { const m = s.match(/[\d.]+/g) || []; return { r: +m[0], g: +m[1], b: +m[2], a: m.length > 3 ? +m[3] : 1 }; };
      const lum = c => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
      const out = [];
      document.querySelectorAll('.pkgw *').forEach(el => {
        if (!el.childNodes.length || ![].slice.call(el.childNodes).some(n => n.nodeType === 3 && n.textContent.trim())) return;
        let bg = { r: 255, g: 255, b: 255 }; for (let e = el; e; e = e.parentElement) { const c = parse(getComputedStyle(e).backgroundColor); if (c.a > 0.5) { bg = c; break; } }
        const fg = parse(getComputedStyle(el).color); const L1 = lum(fg), L2 = lum(bg); const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
        if (ratio < 4.5) out.push(el.className + ':' + el.textContent.trim().slice(0, 20) + ':' + ratio.toFixed(1));
      });
      return out;
    });
    t(n + 'all waybill text is readable (contrast 4.5+)', bad.length === 0, bad.join(' | '));
    await p.evaluate(() => { document.getElementById('pkg-scroll').scrollTop = 0; });
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/pkg_waybill_' + theme + '.png' });
    await p.evaluate(() => { document.getElementById('pkg-scroll').scrollTop = 600; }); await p.waitForTimeout(150);
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/pkg_waybill_' + theme + '_2.png' });
    t(n + 'no page errors', errs.length === 0, errs.join('|'));
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

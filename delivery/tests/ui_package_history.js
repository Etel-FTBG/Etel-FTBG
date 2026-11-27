// Send a package: "Send again" strip on step 1 (up to 3 different recent packages), "My packages" list with All / On the way / Delivered / Cancelled filters,
// Track, and Send again filling the form from an earlier package. Real page and files, a fake API.   node delivery/tests/ui_package_history.js
const { chromium } = require('playwright'); const path = require('path'); const http = require('http'); const fs = require('fs');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.woff2': 'font/woff2' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });
const P = (id, code, status, group, rn, rp, drop, desc, extra) => Object.assign({ order_id: id, order_code: code, status, group, placed_at: '2026-11-2' + (id % 9) + ' 10:15:00', delivered_at: '', fare_total: 120, speed: 'standard',
  pickup: { address: 'Piassa shop', lat: 7.05, lng: 38.49, zone: 'Z1' }, drop: { address: drop, lat: 7.06, lng: 38.5, zone: 'Z2' }, sender_name: 'Sender', sender_phone: '0911000001',
  recipient_name: rn, recipient_phone: rp, size: 'small', weight_kg: 2.5, desc, vehicle_class: 'moto' }, extra || {});
const LIST = [P(9, 'ETP-9', 'pending', 'active', 'Hana', '0911556677', 'Tabor home', 'Books', { speed: 'express' }), P(8, 'ETP-8', 'delivered', 'delivered', 'Hana', '0911556677', 'Tabor home', 'Shoes'),
  P(7, 'ETP-7', 'cancelled', 'cancelled', 'Dawit', '0922334455', 'Lake road', 'Documents', { size: 'document', weight_kg: 0.5 }), P(6, 'ETP-6', 'in_transit', 'active', 'Mimi', '0933445566', 'Hospital gate', 'Gift'),
  P(5, 'ETP-5', 'delivered', 'delivered', 'Abel', '0944556677', 'Airport', 'Laptop')];
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const PAGE = 'http://127.0.0.1:' + srv.address().port + '/customer.html';
  const b = await chromium.launch();
  for (const mode of [{ n: 'with packages', list: LIST }, { n: 'no packages yet', list: [] }, { n: 'server error', list: null }]) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    await ctx.addInitScript(() => { localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 5, name: 'Cust', phone: '0911000001', role: 'customer' })); });
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message + ' @ ' + (e.stack || '').split('\n')[1]));
    await p.route(/maps\.googleapis|gstatic|fonts\./, r => r.abort());
    await p.route(/boot\.php/, r => {
      const u = r.request().url(); let body = {}; try { body = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
      const act = (u.match(/action=([a-z_]+)/) || [])[1] || body.action; let data = {};
      if (act === 'customer_get_items' || act === 'get_items') data = { items: [], count: 0 };
      else if (act === 'customer_package_options') data = { sizes: [{ size: 'document' }, { size: 'small' }, { size: 'medium' }], vehicles: [], zones: [] };
      else if (act === 'customer_package_recent') { if (mode.list === null) { r.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'x' }) }); return; } data = { packages: mode.list }; }
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
    });
    await p.goto(PAGE); await p.waitForTimeout(2000);
    await p.evaluate(() => { [].slice.call(document.querySelectorAll('button')).forEach(x => { if (/^\s*Not Now\s*$/.test(x.textContent)) x.click(); }); window.__tracked = null; window.openTrackOrder = id => { window.__tracked = id; }; pkgOpen(); }); await p.waitForTimeout(900);
    const n = mode.n + ': ';
    const chips = await p.evaluate(() => [].slice.call(document.querySelectorAll('.pkgh-chip')).map(c => c.innerText.replace(/\s+/g, ' ')));
    if (!mode.list || !mode.list.length) {
      t(n + 'no Send again strip, and the form is normal', chips.length === 0 && !(await p.evaluate(() => !!document.querySelector('.pkgh-strip'))) && (await p.evaluate(() => !!document.querySelector('[data-f="pickup.address"]'))));
      t(n + 'no page errors', errs.length === 0, errs.join('|')); await ctx.close(); continue;
    }
    t(n + 'the strip shows 3 different recent recipients (Hana once)', chips.length === 3 && /Hana/.test(chips[0]) && /Books/.test(chips[0]) && /Dawit/.test(chips[1]) && /Mimi/.test(chips[2]), JSON.stringify(chips));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/pkg_again_strip.png' });
    // Send again from a chip
    await p.evaluate(() => { document.querySelectorAll('.pkgh-chip')[0].click(); }); await p.waitForTimeout(300);
    const step = await p.evaluate(() => document.querySelector('.pkg-step.now .pkg-step-n').textContent);
    t(n + 'tapping a chip goes to the Package step', step === '2', step);
    const f2 = await p.evaluate(() => ({ w: document.getElementById('pkg-f-weight').value, d: document.getElementById('pkg-f-desc').value, size: (document.querySelector('.pkg-size.on') || {}).innerText, ack: document.getElementById('pkg-f-ack').checked }));
    t(n + 'the package details are filled, the prohibited-items box is NOT ticked', f2.w === '2.5' && f2.d === 'Books' && /Small/.test(f2.size) && f2.ack === false, JSON.stringify(f2));
    await p.evaluate(() => pkgBack()); await p.waitForTimeout(250);
    const f1 = await p.evaluate(() => ['pickup.address', 'drop.address', 'recipient_name', 'recipient_phone', 'sender_name'].map(k => document.querySelector('[data-f="' + k + '"]').value));
    t(n + 'step 1 is filled: both addresses, recipient and sender', JSON.stringify(f1) === JSON.stringify(['Piassa shop', 'Tabor home', 'Hana', '0911556677', 'Sender']), JSON.stringify(f1));
    t(n + 'the strip is hidden once the form is filled', await p.evaluate(() => !document.querySelector('.pkgh-strip')));
    // My packages
    await p.evaluate(() => pkgOpen()); await p.waitForTimeout(700);
    await p.evaluate(() => document.querySelector('.pkgh-all').click()); await p.waitForTimeout(500);
    const cnt = await p.evaluate(() => [].slice.call(document.querySelectorAll('.pkgh-f')).map(x => x.innerText.replace(/\s+/g, ' ')));
    t(n + 'the list has four filters with counts', JSON.stringify(cnt) === JSON.stringify(['All (5)', 'On the way (2)', 'Delivered (2)', 'Cancelled (1)']), JSON.stringify(cnt));
    t(n + 'all 5 packages are listed, newest first', await p.evaluate(() => [].slice.call(document.querySelectorAll('.pkgh-code')).map(x => x.textContent).join(',') === 'ETP-9,ETP-8,ETP-7,ETP-6,ETP-5'));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/pkg_mine.png' });
    await p.evaluate(() => { document.querySelectorAll('.pkgh-f')[2].click(); }); await p.waitForTimeout(150);
    t(n + 'the Delivered filter shows only delivered packages', await p.evaluate(() => [].slice.call(document.querySelectorAll('.pkgh-code')).map(x => x.textContent).join(',') === 'ETP-8,ETP-5'));
    await p.evaluate(() => { document.querySelectorAll('.pkgh-f')[3].click(); }); await p.waitForTimeout(150);
    t(n + 'the Cancelled filter shows only the cancelled one', await p.evaluate(() => [].slice.call(document.querySelectorAll('.pkgh-code')).map(x => x.textContent).join(',') === 'ETP-7'));
    await p.evaluate(() => { document.querySelectorAll('.pkgh-f')[1].click(); }); await p.waitForTimeout(150);
    t(n + 'the express package says Express', await p.evaluate(() => /Express/.test(document.querySelector('.pkgh-item').innerText)));
    await p.evaluate(() => { [].slice.call(document.querySelectorAll('.pkgh-item')).filter(i => /ETP-6/.test(i.innerText))[0].querySelectorAll('.pkgh-b')[0].click(); }); await p.waitForTimeout(300);
    t(n + 'Track opens the tracking screen for that order and closes the list', await p.evaluate(() => window.__tracked === 6 && !document.getElementById('pkgh-view')), String(await p.evaluate(() => window.__tracked)));
    await p.evaluate(() => pkgOpen()); await p.waitForTimeout(500);
    await p.evaluate(() => document.querySelector('.pkgh-all').click()); await p.waitForTimeout(400);
    await p.evaluate(() => { [].slice.call(document.querySelectorAll('.pkgh-item')).filter(i => /ETP-7/.test(i.innerText))[0].querySelectorAll('.pkgh-b')[1].click(); }); await p.waitForTimeout(300);
    const f3 = await p.evaluate(() => ({ closed: !document.getElementById('pkgh-view'), size: (document.querySelector('.pkg-size.on') || {}).innerText, w: document.getElementById('pkg-f-weight').value, d: document.getElementById('pkg-f-desc').value }));
    t(n + 'Send again from the list fills the form (a document, 0.5 kg) and closes the list', f3.closed && /Document/i.test(f3.size) && f3.w === '0.5' && f3.d === 'Documents', JSON.stringify(f3));
    await p.evaluate(() => pkgOpen()); await p.waitForTimeout(500);
    await p.evaluate(() => document.querySelector('.pkgh-all').click()); await p.waitForTimeout(300);
    await p.keyboard.press('Escape');
    t(n + 'Escape closes the list', await p.evaluate(() => !document.getElementById('pkgh-view')));
    t(n + 'no page errors', errs.length === 0, errs.join('|'));
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

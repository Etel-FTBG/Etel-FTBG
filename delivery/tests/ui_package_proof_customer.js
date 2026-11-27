// Send a package: the sender's tracking screen shows "Photo from the courier" for a delivered package that has one (not before, not for other orders),
// the photo is fetched only on "View photo", and it stays after the screen is redrawn. Real page and files, a fake API.   node delivery/tests/ui_package_proof_customer.js
const { chromium } = require('playwright'); const path = require('path'); const http = require('http'); const fs = require('fs');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.woff2': 'font/woff2' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });
const PIC = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
const ORDER = { id: 77, order_code: 'ETP-77', order_kind: 'package', status: 'delivered', has_proof: true, pickup_address: 'Piassa', recipient_address: 'Tabor', recipient_name: 'Hana', pkg_size: 'small', pkg_weight_kg: 2, pkg_desc: 'Books', vehicle_class: 'moto', pkg_times: null };
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const PAGE = 'http://127.0.0.1:' + srv.address().port + '/customer.html';
  const b = await chromium.launch();
  for (const mode of [{ n: 'english', lang: 'en', fail: false }, { n: 'amharic', lang: 'am', fail: false }, { n: 'photo fails to load', lang: 'en', fail: true }]) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    await ctx.addInitScript(l => { localStorage.setItem('etel_lang', l); localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 5, name: 'Cust', phone: '0911000001', role: 'customer' })); }, mode.lang);
    const p = await ctx.newPage(); const errs = []; const asked = []; p.on('pageerror', e => errs.push(e.message + ' @ ' + (e.stack || '').split('\n')[1]));
    await p.route(/maps\.googleapis|gstatic|fonts\./, r => r.abort());
    await p.route(/boot\.php/, r => {
      const u = r.request().url(); let body = {}; try { body = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
      const act = (u.match(/action=([a-z_]+)/) || [])[1] || body.action; let data = {};
      if (act === 'customer_get_items' || act === 'get_items') data = { items: [], count: 0 };
      else if (act === 'customer_package_proof') { asked.push(body); if (mode.fail) { r.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'x' }) }); return; } data = { photo: PIC, taken_at: '2026-11-27 10:00:00' }; }
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
    });
    await p.goto(PAGE); await p.waitForTimeout(2000);
    await p.evaluate(() => { [].slice.call(document.querySelectorAll('button')).forEach(x => { if (/^\s*Not Now\s*$/.test(x.textContent)) x.click(); }); });
    const n = mode.n + ': ';
    const draw = o => p.evaluate(o => { document.getElementById('view-track-order').style.display = 'block'; window.renderTrackPackage(o); return document.getElementById('track-package-card').innerText.replace(/\s+/g, ' '); }, o);
    const txt1 = await draw(ORDER);
    t(n + 'a delivered package with a photo shows the photo card and a View photo button', await p.evaluate(() => !!document.querySelector('.pkgp .pkgp-b')) && (mode.lang === 'am' ? /ከመልእክተኛው የተላከ ፎቶ/ : /Photo from the courier/).test(txt1), txt1.slice(-160));
    t(n + 'the photo is not loaded before it is asked for', asked.length === 0 && !(await p.evaluate(() => !!document.querySelector('.pkgp-img'))));
    for (const [label, o] of [['no photo', Object.assign({}, ORDER, { has_proof: false })], ['not delivered yet', Object.assign({}, ORDER, { status: 'in_transit' })], ['a shop order', Object.assign({}, ORDER, { order_kind: 'shop' })]]) {
      await draw(o); t(n + 'no photo card for ' + label, await p.evaluate(() => !document.querySelector('.pkgp')));
    }
    await draw(ORDER);
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/pkg_proof_customer_' + mode.n.replace(/ /g, '_') + '_a.png' });
    await p.evaluate(() => { document.querySelector('.pkgp-b').click(); }); await p.waitForTimeout(500);
    t(n + 'View photo asks for this order only', asked.length === 1 && asked[0].order_id === 77, JSON.stringify(asked));
    if (mode.fail) {
      t(n + 'a failed load shows a message and the button works again', await p.evaluate(() => !!document.querySelector('.pkgp-b') && !document.querySelector('.pkgp-b').disabled && /Could not load the photo/.test(document.querySelector('.pkgp-msg').textContent)));
    } else {
      t(n + 'the photo is shown', await p.evaluate(() => { const i = document.querySelector('.pkgp-img'); return !!i && /^data:image\/png/.test(i.getAttribute('src')); }));
      await draw(ORDER);
      t(n + 'after the screen is redrawn the photo is still shown and not asked for again', (await p.evaluate(() => !!document.querySelector('.pkgp-img'))) && asked.length === 1);
      await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/pkg_proof_customer_' + mode.n + '_b.png' });
    }
    t(n + 'no page errors', errs.length === 0, errs.join('|'));
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

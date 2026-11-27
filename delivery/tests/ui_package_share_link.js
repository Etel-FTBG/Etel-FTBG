// Send a package: the tracking screen of any package of the sender shows "Share tracking link" when the server sent the token (also for an older package);
// no card without a token or for a shop order. The button gives the share sheet the text with the link, or copies the link. Real page and files, a fake API.
//   node delivery/tests/ui_package_share_link.js
const { chromium } = require('playwright'); const path = require('path'); const http = require('http'); const fs = require('fs');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.woff2': 'font/woff2' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });
const ORDER = { id: 77, order_code: 'ETP-AB12CD', order_kind: 'package', status: 'in_transit', track_token: '0123456789ab', pickup_address: 'Piassa shop', recipient_address: 'Tabor home', recipient_name: 'Hana', pkg_size: 'small', pkg_weight_kg: 2, pkg_desc: 'Books', vehicle_class: 'moto', pkg_times: null };
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const PAGE = 'http://127.0.0.1:' + srv.address().port + '/customer.html';
  const b = await chromium.launch();
  for (const lang of ['en', 'am']) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    await ctx.addInitScript(l => { localStorage.setItem('etel_lang', l); localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 5, name: 'Cust', phone: '0911000001', role: 'customer' })); }, lang);
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message + ' @ ' + (e.stack || '').split('\n')[1]));
    await p.route(/maps\.googleapis|gstatic|fonts\./, r => r.abort());
    await p.route(/boot\.php/, r => { const u = r.request().url(); let body = {}; try { body = JSON.parse(r.request().postData() || '{}'); } catch (e) {} const act = (u.match(/action=([a-z_]+)/) || [])[1] || body.action; r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: (act === 'customer_get_items' || act === 'get_items') ? { items: [], count: 0 } : {} }) }); });
    await p.goto(PAGE); await p.waitForTimeout(2000);
    await p.evaluate(() => { [].slice.call(document.querySelectorAll('button')).forEach(x => { if (/^\s*Not Now\s*$/.test(x.textContent)) x.click(); }); });
    const n = lang + ': ';
    const draw = o => p.evaluate(o => { document.getElementById('view-track-order').style.display = 'block'; window.renderTrackPackage(o); }, o);
    await draw(ORDER);
    const card = await p.evaluate(() => { const c = document.querySelector('.pkgw-trk'); return c ? c.innerText.replace(/\s+/g, ' ') : ''; });
    t(n + 'a package with a token has the Share tracking card', /Share tracking|መከታተያ አጋራ/.test(card), card);
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/pkg_share_link_' + lang + '.png' });
    t(n + 'no card when the server sent no token (not the sender)', await (async () => { await draw(Object.assign({}, ORDER, { track_token: undefined })); return p.evaluate(() => !document.querySelector('.pkgw-trk')); })());
    t(n + 'no card for a shop order', await (async () => { await draw(Object.assign({}, ORDER, { order_kind: 'shop' })); return p.evaluate(() => !document.querySelector('.pkgw-trk')); })());
    await draw(ORDER);
    const shared = await p.evaluate(() => { let got = null; navigator.share = function (o) { got = o; return Promise.resolve(); }; document.querySelector('.pkgw-trk-b').click(); return got; });
    t(n + 'the button gives the share sheet a text with the code, the places and the link', shared && /ETP-AB12CD/.test(shared.text) && /Piassa shop/.test(shared.text) && /Tabor home/.test(shared.text) && /track\.html\?c=ETP-AB12CD&t=0123456789ab/.test(shared.text), JSON.stringify(shared));
    const copied = await p.evaluate(() => { navigator.share = undefined; let got = ''; Object.defineProperty(navigator, 'clipboard', { value: { writeText: v => { got = v; return Promise.resolve(); } }, configurable: true }); document.querySelector('.pkgw-trk-b').click(); return got; });
    t(n + 'without a share sheet the link is copied', /track\.html\?c=ETP-AB12CD&t=0123456789ab$/.test(copied), copied);
    await draw(Object.assign({}, ORDER, { order_code: 'ETP-ZZ99YY', track_token: 'ffffffffffff' }));
    const again = await p.evaluate(() => { let got = null; navigator.share = function (o) { got = o; return Promise.resolve(); }; document.querySelector('.pkgw-trk-b').click(); return got; });
    t(n + 'a different package shares its own link, not the one before', again && /ETP-ZZ99YY/.test(again.text) && /t=ffffffffffff/.test(again.text) && !/0123456789ab/.test(again.text), JSON.stringify(again));
    t(n + 'no page errors', errs.length === 0, errs.join('|'));
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

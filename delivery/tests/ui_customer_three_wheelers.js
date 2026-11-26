// Customer app cart: Bajaj and Qute are two vehicles, each with its OWN real picture (img/vehicles/bajaj.png and qute.png) and its own price.
// The real page and file (js/customer.js), a fake API.   node delivery/tests/ui_customer_three_wheelers.js
const { chromium } = require('playwright'); const path = require('path'); const http = require('http'); const fs = require('fs');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.woff2': 'font/woff2' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });   // a tiny static server for the page's own files
let PAGE = '';
const VEHICLES = [
  { tier_key: 'moto', vehicle_class: 'moto', label: 'Motorbike', icon: '\u{1F6F5}', multiplier: 1.0, eta_delta_min: 0, couriers: 6, online: 2, available: true, names: ['motorcycle'] },
  { tier_key: 'x1', vehicle_class: 'x1', label: 'Bajaj', icon: '\u{1F6FA}', multiplier: 1.0, eta_delta_min: 0, couriers: 2, online: 1, available: true, names: ['bajaj'] },
  { tier_key: 'x2', vehicle_class: 'x2', label: 'Qute', icon: '\u{1F6FA}', multiplier: 1.0, eta_delta_min: 0, couriers: 1, online: 1, available: true, names: ['qute'] },
  { tier_key: 'car', vehicle_class: 'car', label: 'Car', icon: '\u{1F697}', multiplier: 1.12, eta_delta_min: -2, couriers: 1, online: 1, available: true, names: ['car'] }];
// an older server: the same list without the courier words
const OLD = VEHICLES.map(v => { const c = Object.assign({}, v); delete c.names; return c; });
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); PAGE = 'http://127.0.0.1:' + srv.address().port + '/customer.html';
  const b = await chromium.launch();
  for (const mode of [{ n: 'with courier words', list: VEHICLES }, { n: 'server without courier words', list: OLD }]) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 } });
    await ctx.addInitScript(() => { localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 5, name: 'Cust', phone: '0911000001', role: 'customer' })); });
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route(/boot\.php/, r => {
      const u = r.request().url(); let body = {}; try { body = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
      const act = (u.match(/action=([a-z_]+)/) || [])[1] || body.action;
      const items = [{ id: 1, name: 'Burger', price: '500.00', category: 'food', service_giver_id: 14, business_name: 'Shop', zone: 'Z', is_available: 1, stock_quantity: 50, is_online: 1, extras: [], gallery: [] }];
      let data = {};
      if (act === 'customer_get_items' || act === 'get_items') data = { items, count: 1 };
      else if (act === 'get_delivery_tiers') data = { tiers: [{ tier_key: 'moto', label: 'Delivery by moto', icon: 'x', multiplier: 1, eta_delta_min: 0 }], vehicles: mode.list };
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
    });
    await p.goto(PAGE); await p.waitForTimeout(2500);
    await p.evaluate(() => { cart.push({ id: 1, item_id: 1, name: 'Burger', price: 500, quantity: 1, extras: [], xkey: '' }); try { toggleCart(true); } catch (e) { try { openCart(); } catch (e2) {} } renderCartDrawer(); });
    await p.waitForTimeout(800);
    const pic = async key => p.evaluate(k => { const b = document.querySelector('button[data-tier="' + k + '"]'); const i = b && b.querySelector('img'); return i ? i.getAttribute('src') : (b ? 'no-img:' + b.innerText : 'no-button'); }, key);
    const bj = await pic('x1'), qu = await pic('x2'), mo = await pic('moto');
    t(mode.n + ': Bajaj shows the Bajaj picture', /img\/vehicles\/bajaj\.png/.test(bj), bj);
    t(mode.n + ': Qute shows the Qute picture', /img\/vehicles\/qute\.png/.test(qu), qu);
    t(mode.n + ': the two pictures are different files', bj !== qu, bj + ' | ' + qu);
    t(mode.n + ': the motorbike still shows its own picture', /img\/vehicles\/moto\.png/.test(mo), mo);
    const txt = await p.evaluate(() => (document.getElementById('cart-checkout') || document.body).innerText.replace(/\s+/g, ' '));
    t(mode.n + ': both names are in the list (Bajaj and Qute, no "Bajaj / Qute")', /Bajaj/.test(txt) && /Qute/.test(txt) && !/Bajaj \/ Qute/.test(txt), txt.slice(0, 400));
    await p.evaluate(() => selectTier('x2')); await p.waitForTimeout(200);
    t(mode.n + ': choosing Qute selects its own key (x2) for the order', await p.evaluate(() => selectedTier === 'x2' && _vehKey() === 'x2'));
    await p.evaluate(() => selectTier('x1')); await p.waitForTimeout(200);
    t(mode.n + ': choosing Bajaj selects its own key (x1) for the order', await p.evaluate(() => selectedTier === 'x1' && _vehKey() === 'x1'));
    t(mode.n + ': no page errors', errs.length === 0, errs.join('|'));
    await p.evaluate(() => { [].slice.call(document.querySelectorAll('button')).forEach(x => { if (/^\s*Not Now\s*$/.test(x.textContent)) x.click(); }); const d = document.getElementById('cart-checkout'); if (d) d.scrollIntoView(); }); await p.waitForTimeout(300);
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/cart_three_wheelers_' + (mode.list === VEHICLES ? 'new' : 'old') + '.png' });
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

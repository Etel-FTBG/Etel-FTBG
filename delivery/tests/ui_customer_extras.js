// Customer app: each extra has its own price and a - 0 + counter; the cart and the order carry the counts (js/customer.js, the real page and file).
// Needs Playwright (node). No server: the page is opened from disk and the API is a fake.   node delivery/tests/ui_customer_extras.js
const { chromium } = require('playwright'); const path = require('path'); const http = require('http'); const fs = require('fs');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.woff2': 'font/woff2' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });   // a tiny static server for the page's own files
let PAGE = '';
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); PAGE = 'http://127.0.0.1:' + srv.address().port + '/customer.html';
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: 390, height: 844 } });
  await ctx.addInitScript(() => { localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 5, name: 'Cust', phone: '0911000001', role: 'customer' })); });
  const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
  await p.route(/boot\.php/, r => {
    const u = r.request().url(); let body = {}; try { body = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
    const act = (u.match(/action=([a-z_]+)/) || [])[1] || body.action;
    const items = [{ id: 1, name: 'Burger', price: '500.00', category: 'food', service_giver_id: 14, business_name: 'Shop', zone: 'Z', is_available: 1, stock_quantity: 50, is_online: 1,
      extras: [{ id: 3, name: 'Mayonis', price: 50 }, { id: 4, name: 'Kachap', price: 0 }], gallery: [] }];
    r.fulfill({ contentType: 'application/json', body: JSON.stringify(act === 'customer_get_items' || act === 'get_items' ? { success: true, data: { items, count: 1 } } : { success: true, data: {} }) });
  });
  await p.goto(PAGE); await p.waitForTimeout(2500);
  t('the real page loads with the item', await p.evaluate(() => typeof itemsCache !== 'undefined' && itemsCache.length === 1));
  const sheet = () => p.evaluate(() => { const m = document.getElementById('item-detail-overlay'); return m ? m.innerText.replace(/\s+/g, ' ') : ''; });
  const click = (act, args) => p.evaluate(([a, g]) => { const el = document.querySelector('#item-detail-overlay [data-act="' + a + '"][data-args="' + JSON.stringify(g) + '"]'); if (el) el.click(); return !!el; }, [act, args]);
  const total = () => p.evaluate(() => document.getElementById('detail-add-total').textContent);
  const qty = (id) => p.evaluate((id) => document.getElementById('dx-qty-' + id).textContent, id);

  await p.evaluate(() => openItemDetail(1)); await p.waitForTimeout(100);
  let s = await sheet();
  t('each extra shows its price "each" (and Free when it is 0)', /Mayonis \+ ETB 50 each/.test(s) && /Kachap Free/.test(s), s);
  t('no tick boxes: a - 0 + counter per extra, starting at 0', await p.evaluate(() => !document.querySelector('#item-detail-overlay input[type=checkbox]')) && await qty(3) === '0' && await qty(4) === '0');
  t('the total starts at the item price', await total() === 'ETB 500', await total());
  await click('detailExtraChange', [3, 1]); await click('detailExtraChange', [3, 1]);
  t('+ twice on Mayonis: the counter shows 2 and the total is 500 + 2 x 50', await qty(3) === '2' && await total() === 'ETB 600', await qty(3) + ' ' + await total());
  await click('detailExtraChange', [3, -1]);
  t('- once: back to 1 and ETB 550', await qty(3) === '1' && await total() === 'ETB 550');
  await click('detailExtraChange', [3, -1]); await click('detailExtraChange', [3, -1]);
  t('- below 0 stays at 0', await qty(3) === '0' && await total() === 'ETB 500');
  for (let i = 0; i < 12; i++) await click('detailExtraChange', [3, 1]);
  t('+ stops at 9', await qty(3) === '9' && await total() === 'ETB 950', await qty(3) + ' ' + await total());
  for (let i = 0; i < 9; i++) await click('detailExtraChange', [3, -1]);
  await click('detailExtraChange', [3, 1]); await click('detailExtraChange', [3, 1]); await click('detailExtraChange', [4, 1]);
  t('a free extra adds nothing to the total', await total() === 'ETB 600' && await qty(4) === '1');
  await p.evaluate(() => { _detailQtyChange(1); });
  t('the quantity of the burger multiplies the whole (item + extras): 2 x 600', await total() === 'ETB 1200', await total());
  await p.evaluate(() => { _detailQtyChange(-1); });

  // ---- into the cart and the order ----
  await p.evaluate(() => { window.confirm = () => true; });
  await p.evaluate(() => { const el = document.querySelector('#item-detail-overlay [data-act="detailAddToCart"]'); el.click(); });
  await p.waitForTimeout(100);
  const line = await p.evaluate(() => ({ cart: JSON.parse(JSON.stringify(cart)), payload: cart.map(_cartLine) }));
  t('the cart line keeps the counts and the unit price with extras', line.cart.length === 1 && line.cart[0].price === 600 && line.cart[0].extras.map(x => x.id + ':' + x.qty).join() === '3:2,4:1', JSON.stringify(line.cart));
  t('the order sends the ids and how many of each', JSON.stringify(line.payload) === JSON.stringify([{ item_id: 1, quantity: 1, extras: [3, 4], extras_qty: { 3: 2, 4: 1 } }]), JSON.stringify(line.payload));
  t('the cart text says "Mayonis ×2, Kachap"', await p.evaluate(() => cart[0].extras.map(_xLabel).join(', ')) === 'Mayonis ×2, Kachap');
  // the same mix again combines; another mix is its own line
  await p.evaluate(() => { openItemDetail(1); detailExtraChange(3, 1); detailExtraChange(3, 1); detailExtraChange(4, 1); document.querySelector('#item-detail-overlay [data-act="detailAddToCart"]').click(); });
  await p.waitForTimeout(80);
  t('the same mix combines into one line (quantity 2)', await p.evaluate(() => cart.length === 1 && cart[0].quantity === 2), await p.evaluate(() => JSON.stringify(cart.map(c => [c.xkey, c.quantity]))));
  await p.evaluate(() => { openItemDetail(1); detailExtraChange(3, 1); document.querySelector('#item-detail-overlay [data-act="detailAddToCart"]').click(); });
  await p.waitForTimeout(80);
  t('another mix (1 Mayonis only) is a separate line', await p.evaluate(() => cart.length === 2 && cart[1].price === 550), await p.evaluate(() => JSON.stringify(cart.map(c => [c.xkey, c.price]))));
  await p.evaluate(() => { cart.pop(); localStorage.removeItem('etel_cart'); });
  // an old cart line (saved before counts existed) still works
  const old = await p.evaluate(() => _cartLine({ item_id: 1, quantity: 2, extras: [{ id: 3, name: 'Mayonis', price: 50 }] }));
  t('an old cart line without counts counts each extra once', JSON.stringify(old) === JSON.stringify({ item_id: 1, quantity: 2, extras: [3], extras_qty: { 3: 1 } }), JSON.stringify(old));
  t('no page errors', errs.length === 0, errs.slice(0, 3).join('|'));
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

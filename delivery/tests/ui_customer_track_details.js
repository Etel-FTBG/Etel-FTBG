// Customer app > track order (js/customer.js): the progress text and timeline follow the shop's own steps (stage), and an "Order details" card shows what was
// ordered, who it is for, the note, and (only for the person who ordered) the money lines. Real page and file, mocked API.
// Needs Playwright (node).   node delivery/tests/ui_customer_track_details.js
const { chromium } = require('playwright'); const path = require('path'); const http = require('http'); const fs = require('fs');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.woff2': 'font/woff2' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });
const TL = (cur, courier) => [['pending', 'Order placed'], ['assigned', 'Courier assigned'], ['preparing', 'Being prepared'], ['accepted', 'Ready for pickup'], ['picked_up', 'Courier picked up'], ['in_transit', 'On the way'], ['delivered', 'Delivered']]
  .map((x, i, a) => ({ status: x[0], label: x[1], done: i <= a.findIndex(y => y[0] === cur) && !(x[0] === 'assigned' && !courier), current: x[0] === cur }));
const ORDER = (stage, withMoney) => Object.assign({ id: 5, order_code: 'ETD-F23A5E', status: stage === 'pending' || stage === 'preparing' || stage === 'accepted' ? 'assigned' : stage, stage: stage, payment_method: 'cash', from_zone: 'Mehal Ketema', to_zone: 'University',
  recipient_name: 'Emu', recipient_phone: '0936533606', recipient_address: 'Cheffe', notes: 'No onions please', items: [{ item_name: 'Burger + Mayonis, Kachap', quantity: 2 }, { item_name: 'Soft Drink', quantity: 1 }], courier_name: 'Etel Admin', courier_vehicle: 'qute' },
  withMoney ? { money: { subtotal: 1100, delivery_fee: 105, service_charge: 0, courier_tip: 20, fare_total: 1225 } } : {});
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const PAGE = 'http://127.0.0.1:' + srv.address().port + '/customer.html';
  const b = await chromium.launch();
  const CASES = [
    { n: 'preparing, orderer, English', stage: 'preparing', money: true, lang: 'en', status: /Being prepared/, eta: /Your order is being prepared/ },
    { n: 'ready, orderer, English', stage: 'accepted', money: true, lang: 'en', status: /Ready for pickup/, eta: /Your order is ready . the courier is picking it up/ },
    { n: 'preparing, recipient only (no prices)', stage: 'preparing', money: false, lang: 'en', status: /Being prepared/, eta: /being prepared/ },
    { n: 'preparing, orderer, Amharic', stage: 'preparing', money: true, lang: 'am', status: /Being prepared/, eta: /being prepared/ }];
  for (const c of CASES) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    await ctx.addInitScript(l => { localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 5, name: 'Cust', phone: '0911000001', role: 'customer' })); localStorage.setItem('etel_lang', l); }, c.lang);
    const p = await ctx.newPage(); const errs = []; const seen = {}; p.on('pageerror', e => errs.push(e.message + ' @ ' + ((e.stack || '').split('\n')[1] || '').trim()));
    await p.route(/boot\.php/, r => {
      const u = r.request().url(); let body = {}; try { body = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
      const act = (u.match(/action=([a-z_]+)/) || [])[1] || body.action; let data = {}; seen[act] = 1;
      if (act === 'track_order') data = { order: ORDER(c.stage, c.money), timeline: TL(c.stage, true) };
      else if (act === 'customer_get_items' || act === 'get_items') data = { items: [], count: 0 };
      else if (act === 'order_chat_inbox') data = { chats: [], unread_total: 0 };
      else if (act === 'customer_get_orders') data = { orders: [] };
      else if (act === 'get_service_areas') data = { areas: [], enforced: false };
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
    });
    await p.goto(PAGE); await p.waitForTimeout(2200);
    await p.evaluate(() => openTrackOrder(5)); await p.waitForTimeout(900);
    const q = (sel) => p.evaluate(s => { const e = document.querySelector(s); return e ? e.innerText.replace(/\s+/g, ' ') : ''; }, sel);
    const n = c.n; const det = await q('#track-details'), st = await q('#track-order-status'), eta = await q('#track-eta'), tl = await q('#track-timeline');
    t(n + ': the header shows the current step', c.status.test(st), st);
    t(n + ': the progress text follows the shop (stage), not the old "shop is confirming"', c.eta.test(eta) && !/confirming/.test(eta), eta);
    t(n + ': the timeline lists the steps', /Order placed/.test(tl) && /Being prepared/.test(tl) && /Ready for pickup/.test(tl), tl);
    t(n + ': the details card shows the items with their counts', /2 ×\s*Burger \+ Mayonis, Kachap/.test(det) && /1 ×\s*Soft Drink/.test(det), det);
    t(n + ': ... who it is for, the address and the note', /Emu/.test(det) && /0936533606/.test(det) && /Cheffe/.test(det) && /No onions please/.test(det), det);
    if (c.lang === 'am') t(n + ': the card title is in Amharic', /የትዕዛዙ ዝርዝር/.test(det), det);
    if (c.money) {
      t(n + ': the money lines: subtotal, delivery fee, service charge Free, tip, total', /1100\.00/.test(det) && /105\.00/.test(det) && /(Free|ነፃ)/.test(det) && /20\.00/.test(det) && /ETB 1225\.00/.test(det), det);
      t(n + ': the payment method is shown', /CASH/.test(det), det);
    } else {
      t(n + ': no prices are shown', !/ETB/.test(det) && !/1225/.test(det) && !/105/.test(det), det);
    }
    await p.evaluate(() => { [].slice.call(document.querySelectorAll('button')).forEach(x => { if (/^\s*Not Now\s*$/.test(x.textContent)) x.click(); }); const d = document.getElementById('track-details'); if (d) d.scrollIntoView(); }); await p.waitForTimeout(300);
    if (c.n.startsWith('preparing, orderer, English')) await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/track_details.png' });
    t(n + ': no page errors', errs.length === 0, errs.join('|') + ' | ' + Object.keys(seen).join(','));
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

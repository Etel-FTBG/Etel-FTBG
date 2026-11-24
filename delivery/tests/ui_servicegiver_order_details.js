// Shop app (servicegiver.html, js/servicegiver.js): every order has a Details button that opens a window with what the shop needs (items, who it is for,
// the address, the note, the courier) and a Back button; the delivery fee and the service charge are NOT shown anywhere; the receipt that opens after
// "Preparing" has a Back button and a Print button and shows only the value of the items. Real page and file, mocked API.
// Needs Playwright (node).   node delivery/tests/ui_servicegiver_order_details.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml', '.webp': 'image/webp' };
const srv = http.createServer((q, r) => { let p = path.join(ROOT, decodeURIComponent(q.url.split('?')[0])); if (!p.startsWith(ROOT) || !fs.existsSync(p) || fs.statSync(p).isDirectory()) { r.statusCode = 404; return r.end(); } r.setHeader('Content-Type', MIME[path.extname(p)] || 'application/octet-stream'); r.end(fs.readFileSync(p)); });
const SG = { id: 14, business_name: 'Admin test shop', service_giver_code: 'SG-9F6373', service_type: 'restaurant', zone: 'Mehal Ketema', status: 'verified', is_online: 1, opens_at: null, closes_at: null, open_now: 1, hours_mode: 0, average_rating: 0, total_deliveries: 0, total_earnings: 0 };
const LIST = [{ assignment_id: 1, order_id: 163, order_code: 'ETD-F23A5E', status: 'pending', from_zone: 'Mehal Ketema', to_zone: 'University', recipient_name: 'Emu', recipient_phone: '0936533606', fare_total: 500, items_total: 500, placed_at: '2026-11-24 12:40:47', notes: 'Address: Cheffe' }];
const DETAIL = { assignment_id: 1, status: 'pending', order_status: 'assigned', id: 163, order_code: 'ETD-F23A5E', from_zone: 'Mehal Ketema', to_zone: 'University', recipient_name: 'Emu', recipient_phone: '0936533606', recipient_address: 'Cheffe', fare_total: 500, items_total: 500, placed_at: '2026-11-24 12:40:47', notes: 'No onions please',
  items: [{ item_name: 'Burger + Mayonis, Kachap', quantity: 1, unit_price: 600, subtotal: 600 }, { item_name: 'Soft Drink', quantity: 2, unit_price: 100, subtotal: 200 }], courier: { name: 'Etel Admin', phone: '0924445206', vehicle: 'qute' } };
const TICKET = { order_code: 'ETD-F23A5E', recipient_name: 'Emu', recipient_phone: '0936533606', notes: 'Address: Cheffe', placed_at: '2026-11-24 12:40:47', from_zone: 'Mehal Ketema', to_zone: 'University', fare_total: 500, subtotal: 500, items: [{ item_name: 'Burger', quantity: 1, unit_price: 500, subtotal: 500 }] };
(async () => {
  await new Promise(r => srv.listen(0, r)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  for (const mode of [{ n: 'phone', w: 390, h: 844, lang: 'en' }, { n: 'phone-am', w: 390, h: 844, lang: 'am' }]) {
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
    await ctx.addInitScript(l => { try { localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_user', JSON.stringify({ id: 5, name: 'Owner', phone: '0911000001', role: 'service_giver' })); localStorage.setItem('etel_lang', l); } catch (e) {} }, mode.lang);
    const p = await ctx.newPage(); const errs = [];
    p.on('pageerror', e => errs.push(e.message + ' @ ' + ((e.stack || '').split('\n')[1] || '').trim()));
    await p.route(/boot\.php|\/api\//, r => {
      let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
      const a = d.action || ((r.request().url().match(/action=([a-z_]+)/) || [])[1]); let data = {};
      if (a === 'sg_profile') data = { user: { id: 5, name: 'Owner', phone: '0911000001' }, service_giver: SG, staff_role: 'owner', role_name: 'Owner', capabilities: ['*'] };
      else if (a === 'sg_orders') data = { orders: LIST, count: 1 };
      else if (a === 'sg_order_detail') data = { order: DETAIL };
      else if (a === 'sg_update_order_status') data = { message: 'ok', order_id: d.order_id, status: d.new_status, ticket: d.new_status === 'preparing' ? TICKET : null };
      else if (a === 'order_chat_inbox') data = { chats: [], unread_total: 0 };
      else if (a === 'sg_pending_confirmations') data = { confirmations: [] };
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
    });
    await p.goto(HOST + '/servicegiver.html', { waitUntil: 'load' }); await p.waitForTimeout(2300);
    await p.evaluate(() => { [].slice.call(document.querySelectorAll('button')).forEach(x => { if (/^\s*Not Now\s*$/.test(x.textContent)) x.click(); }); try { switchPage('orders'); } catch (e) {} }); await p.waitForTimeout(900);
    const n = mode.n;
    const list = await p.evaluate(() => (document.getElementById('orders-container') || document.body).innerText.replace(/\s+/g, ' '));
    t(n + ': the order is in the list with the items value (Br 500.00)', /ETD-F23A5E/.test(list) && /Br 500\.00/.test(list), list.slice(0, 300));
    t(n + ': the list shows no delivery fee', !/delivery fee|service fee/i.test(list) && !/605|105\.00/.test(list), list.slice(0, 300));
    t(n + ': every order has a Details button', (await p.locator('#orders-container .sg-od-btn').count()) === 1);
    await p.locator('#orders-container .sg-od-btn').first().click(); await p.waitForTimeout(700);
    const det = await p.evaluate(() => (document.getElementById('sg-od-overlay') || { innerText: '' }).innerText.replace(/\s+/g, ' '));
    t(n + ': the details window opens', det.length > 0, det);
    t(n + ': it lists the items with their counts and prices', /1 ×\s*Burger \+ Mayonis, Kachap/.test(det) && /2 ×\s*Soft Drink/.test(det) && /Br 600\.00/.test(det) && /Br 200\.00/.test(det), det);
    t(n + ': it shows the items total (Br 500.00), the person, phone, address and note', /Br 500\.00/.test(det) && /Emu/.test(det) && /0936533606/.test(det) && /Cheffe/.test(det) && /No onions please/.test(det), det);
    t(n + ': it shows the courier (name, vehicle, phone)', /Etel Admin/.test(det) && /qute/.test(det) && /0924445206/.test(det), det);
    t(n + ': it has no delivery fee or service fee', !/delivery fee|service (fee|charge)/i.test(det) && !/605|105\.00/.test(det), det);
    t(n + ': the window has a Back button', (await p.locator('#sg-od-overlay .sg-od-back').count()) === 1);
    if (mode.lang === 'am') t(n + ': the Back button is in Amharic', /ተመለስ/.test(det), det);
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/sg_order_details_' + n + '.png' });
    await p.locator('#sg-od-overlay .sg-od-back').click(); await p.waitForTimeout(300);
    t(n + ': Back closes it', (await p.locator('#sg-od-overlay').count()) === 0);
    // Start preparing: the receipt page opens in its own window
    const popupP = ctx.waitForEvent('page', { timeout: 8000 }).catch(() => null);
    await p.locator('#orders-container button[data-act="sgUpdateStatus"]').first().click();
    const pop = await popupP;
    t(n + ': the receipt opens in its own window', !!pop);
    if (pop) {
      await pop.waitForLoadState('domcontentloaded'); await pop.waitForTimeout(500);
      const txt = await pop.evaluate(() => document.body.innerText.replace(/\s+/g, ' '));
      t(n + ': the receipt shows the kitchen ticket and the receipt with the items total (Br 500.00)', /KITCHEN TICKET/.test(txt) && /DELIVERY RECEIPT/.test(txt) && /Br 500\.00/.test(txt), txt);
      t(n + ': it does NOT show a delivery fee, a service fee or the full total', !/delivery fee|service fee/i.test(txt) && !/605/.test(txt) && !/105\.00/.test(txt), txt);
      t(n + ': it has a Back and a Print button', (await pop.locator('#bk').count()) === 1 && (await pop.locator('#pr').count()) === 1);
      await pop.click('#bk').catch(() => {}); await p.waitForTimeout(700);
      t(n + ': Back closes the receipt window', pop.isClosed());
    }
    t(n + ': no page errors', errs.length === 0, errs.join('|'));
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

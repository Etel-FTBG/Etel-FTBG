// Courier app: a SHOP order that is on the way (picked up / in transit) gets "Add photo proof (optional)" on its Active-tab card; not an assigned order, not a package
// (that has its own Delivered screen). The photo is shrunk on the phone and sent with the order id. English and Amharic. Real page and files, a fake API.
//   node delivery/tests/ui_courier_order_proof.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.webp': 'image/webp' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  for (const mode of [{ n: 'english', lang: 'en', fail: false }, { n: 'amharic', lang: 'am', fail: false }, { n: 'server says no', lang: 'en', fail: true }]) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    await ctx.addInitScript(l => { try { localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 9, name: 'Cou', phone: '0911000001', role: 'courier' })); localStorage.setItem('etel_lang', l); } catch (e) {} }, mode.lang);
    let myOrders = []; const p = await ctx.newPage(); const errs = []; const sent = []; p.on('pageerror', e => errs.push(e.message));
    await p.route(/cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net|maps\.googleapis\.com|fonts\.(googleapis|gstatic)\.com/, r => r.abort());
    await p.route(/boot\.php/, r => {
      let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
      const u = r.request().url(); const act = d.action || ((u.match(/action=([a-z_]+)/) || [])[1]); let data = {};
      if (act === 'get_service_areas') data = { areas: [], enforced: false };
      else if (act === 'order_chat_inbox') data = { chats: [], unread_total: 0 };
      else if (act === 'courier_earnings_summary') data = { today: { earned: 0, deliveries: 0, goal: 1000 }, week: { earned: 0 }, streak_days: 0, goal: 1000 };
      else if (act === 'courier_get_statement') data = { week_start: '2026-11-23', week_end: '2026-11-29', totals: { deliveries: 0, pay: 0, tips: 0, earned: 0 }, days: [], orders: [], prev_week: '2026-11-16', next_week: null };
      else if (act === 'courier_get_profile') data = { courier: { id: 22, status: 'online', vehicle_type: 'moto', approval_status: 'approved', active: 1 }, profile: { id: 22, status: 'online', vehicle_type: 'moto', approval_status: 'approved', active: 1 } };
      else if (act === 'courier_get_my_orders') data = { orders: myOrders };
      else if (act === 'courier_get_available_orders') data = { orders: [] };
      else if (act === 'courier_job_geo') data = { jobs: {} };
      else if (act === 'courier_package_proof') { sent.push(d); if (mode.fail) { r.fulfill({ status: 400, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'It is too late to add a photo to this delivery.' }) }); return; } data = { message: 'Photo saved.' }; }
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
    });
    await p.goto(HOST + '/delivery/courier/index.html', { waitUntil: 'load' }); await p.waitForTimeout(2500);
    const n = mode.n + ': ';
    const ord = (id, code, status, kind) => ({ id, order_code: code, status, order_kind: kind || 'shop', from_zone: 'Mehal Ketema', to_zone: 'Addis Ketema', sg_business_name: 'Shop', sg_lat: '7.05', sg_lng: '38.48', delivery_lat: '7.06', delivery_lng: '38.49', recipient_name: 'Emu', recipient_phone: '0936533606', notes: '', fare_total: 100, delivery_fee: 66, payment_method: 'cash', pickup_address: kind === 'package' ? 'Piassa' : undefined });
    const show = list => { myOrders = list; return p.evaluate(list => { document.querySelector('button.tab-btn:nth-child(2)').click(); window.renderActiveOrders(window._activeOrders = list); }, list); };
    await show([ord(31, 'ETD-ASG031', 'assigned'), ord(32, 'ETD-OTW032', 'in_transit'), ord(33, 'ETP-PKG033', 'in_transit', 'package')]); await p.waitForTimeout(2500);
    const cards = await p.evaluate(() => [].slice.call(document.querySelectorAll('#active-content .job-card.accepted')).map(c => ({ code: c.querySelector('.job-id').textContent.replace(/\s+/g, ''), has: !!c.querySelector('.cpp-btn') })));
    const by = Object.fromEntries(cards.map(c => [c.code, c.has]));
    t(n + 'a shop order on the way has the photo button', by['ETD-OTW032'] === true, JSON.stringify(cards));
    t(n + 'an order that is only assigned has none', by['ETD-ASG031'] === false, JSON.stringify(cards));
    t(n + 'a package keeps its own Delivered screen (no button on the card)', by['ETP-PKG033'] === false, JSON.stringify(cards));
    const btn = p.locator('#active-content .cpp-btn');
    t(n + 'one button, with the right words', (await btn.count()) === 1 && (mode.lang === 'am' ? /የፎቶ ማረጋገጫ ጨምር/ : /Add photo proof \(optional\)/).test(await btn.innerText()), await btn.allInnerTexts());
    await p.locator('#active-content .job-card.accepted').nth(1).screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/courier_order_proof_' + mode.n.replace(/ /g, '_') + '.png' });
    await p.setInputFiles('#active-content .cpp-file', { name: 'door.png', mimeType: 'image/png', buffer: PNG }); await p.waitForTimeout(1200);
    t(n + 'the photo was sent once with the id of the order on the way', sent.length === 1 && sent[0].order_id === 32, JSON.stringify(sent.map(s => s.order_id)));
    t(n + 'it is a small JPEG data URL', sent.length === 1 && /^data:image\/jpeg;base64,/.test(sent[0].photo) && sent[0].photo.length < 650000);
    const msg = await p.locator('#active-content .cpp-msg').innerText();
    if (mode.fail) t(n + 'a refusal from the server is shown as it is', /too late/.test(msg), msg);
    else {
      t(n + 'a saved photo says so', (mode.lang === 'am' ? /ፎቶው ተቀምጧል/ : /Photo saved/).test(msg), msg);
      await show([ord(31, 'ETD-ASG031', 'assigned'), ord(32, 'ETD-OTW032', 'in_transit'), ord(33, 'ETP-PKG033', 'in_transit', 'package')]); await p.waitForTimeout(2500);
      t(n + 'after the card is redrawn the button is back and still says the photo is saved', /Photo saved|ፎቶው ተቀምጧል/.test(await p.locator('#active-content .cpp-msg').innerText()));
    }
    t(n + 'no page errors', errs.length === 0, errs.join('|'));
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

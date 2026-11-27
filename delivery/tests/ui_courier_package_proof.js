// Courier app: after a package is delivered with the PIN, the green "Delivered" screen gets "Add photo proof (optional)". The photo is taken with the camera input,
// shrunk on the phone (JPEG) and sent with the order number of the delivery just made. English and Amharic. Real page and files, a fake API.
//   node delivery/tests/ui_courier_package_proof.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.webp': 'image/webp' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  for (const mode of [{ n: 'english', lang: 'en', fail: false }, { n: 'amharic', lang: 'am', fail: false }, { n: 'server says no', lang: 'en', fail: true }, { n: 'no delivery known', lang: 'en', fail: false, noDelivery: true }]) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    await ctx.addInitScript(l => { try { localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 9, name: 'Cou', phone: '0911000001', role: 'courier' })); localStorage.setItem('etel_lang', l); } catch (e) {} }, mode.lang);
    const p = await ctx.newPage(); const errs = []; const sent = []; p.on('pageerror', e => errs.push(e.message));
    await p.route(/cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net|maps\.googleapis\.com|fonts\.(googleapis|gstatic)\.com/, r => r.abort());
    await p.route(/boot\.php/, r => {
      let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
      const u = r.request().url(); const act = d.action || ((u.match(/action=([a-z_]+)/) || [])[1]); let data = {};
      if (act === 'get_service_areas') data = { areas: [], enforced: false };
      else if (act === 'order_chat_inbox') data = { chats: [], unread_total: 0 };
      else if (act === 'courier_earnings_summary') data = { today: { earned: 0, deliveries: 0, goal: 1000 }, week: { earned: 0 }, streak_days: 0, goal: 1000 };
      else if (act === 'courier_get_statement') data = { week_start: '2026-11-23', week_end: '2026-11-29', totals: { deliveries: 0, pay: 0, tips: 0, earned: 0 }, days: [], orders: [], prev_week: '2026-11-16', next_week: null };
      else if (act === 'courier_get_profile') data = { courier: { id: 22, status: 'online', vehicle_type: 'moto', approval_status: 'approved', active: 1 }, profile: { id: 22, status: 'online', vehicle_type: 'moto', approval_status: 'approved', active: 1 } };
      else if (act === 'courier_get_available_orders' || act === 'courier_get_my_orders') data = { orders: [] };
      else if (act === 'courier_package_deliver') data = { message: 'Delivered', status: 'delivered', order_id: d.order_id };
      else if (act === 'courier_package_proof') { sent.push(d); if (mode.fail) { r.fulfill({ status: 400, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'It is too late to add a photo to this delivery.' }) }); return; } data = { message: 'Photo saved.' }; }
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
    });
    await p.goto(HOST + '/delivery/courier/index.html', { waitUntil: 'load' }); await p.waitForTimeout(2500);
    const n = mode.n + ': ';
    t(n + 'the page has the courier api function', await p.evaluate(() => typeof window.api === 'function'));
    // what the courier app does on a good PIN: a deliver call, then the green overlay
    if (!mode.noDelivery) await p.evaluate(() => api('courier_package_deliver', { order_id: 4242, pin: '1234' }));
    await p.waitForTimeout(300);
    await p.evaluate(() => { const ov = document.createElement('div'); ov.id = 'pkg-done-overlay'; ov.setAttribute('role', 'dialog'); ov.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.72);display:flex;align-items:center;justify-content:center;padding:18px'; ov.innerHTML = '<div style="width:100%;max-width:340px;background:#1b1b2b;border-radius:18px;padding:22px;color:#fff;text-align:center"><div style="font-size:19px;font-weight:800">Delivered</div><button type="button" id="pkg-done-ok" style="width:100%;margin-top:16px;padding:13px">OK</button></div>'; document.body.appendChild(ov); });
    await p.waitForTimeout(mode.noDelivery ? 3500 : 600);
    if (mode.noDelivery) { t(n + 'without a known delivery the button is not shown', (await p.locator('.cpp-btn').count()) === 0); t(n + 'no page errors', errs.length === 0, errs.join('|')); await ctx.close(); continue; }
    const btn = p.locator('.cpp-btn');
    t(n + 'the Delivered screen has the photo button', (await btn.count()) === 1 && (mode.lang === 'am' ? /የፎቶ ማረጋገጫ ጨምር/ : /Add photo proof \(optional\)/).test(await btn.innerText()), await btn.allInnerTexts());
    t(n + 'the button sits above OK', await p.evaluate(() => { const b = document.querySelector('.cpp-box'), ok = document.getElementById('pkg-done-ok'); return b && ok && (b.compareDocumentPosition(ok) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0; }));
    t(n + 'the camera input is for pictures and opens the back camera', await p.evaluate(() => { const f = document.querySelector('.cpp-file'); return f.accept === 'image/*' && f.getAttribute('capture') === 'environment'; }));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/courier_proof_' + mode.n.replace(/ /g, '_') + '.png' });
    await p.setInputFiles('.cpp-file', { name: 'door.png', mimeType: 'image/png', buffer: PNG }); await p.waitForTimeout(1200);
    t(n + 'the photo was sent once with the order just delivered', sent.length === 1 && sent[0].order_id === 4242, JSON.stringify(sent.map(s => s.order_id)));
    t(n + 'it is a small JPEG data URL', sent.length === 1 && /^data:image\/jpeg;base64,/.test(sent[0].photo) && sent[0].photo.length < 650000, sent[0] && sent[0].photo.slice(0, 40));
    const msg = await p.locator('.cpp-msg').innerText();
    if (mode.fail) t(n + 'a refusal from the server is shown as it is', /too late/.test(msg), msg);
    else t(n + 'a saved photo says so', (mode.lang === 'am' ? /ፎቶው ተቀምጧል/ : /Photo saved/).test(msg), msg);
    t(n + 'no page errors', errs.length === 0, errs.join('|'));
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

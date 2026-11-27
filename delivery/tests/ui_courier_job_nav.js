// Courier app (courier/index.html): every job in the list shows the pickup km and the dropoff km, each with a Navigate button, BEFORE accepting.
// Real page and files, mocked API.   node delivery/tests/ui_courier_job_nav.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.webp': 'image/webp' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });
const job = (id, code) => ({ id: id, order_code: code, status: 'pending', assignment_status: 'accepted', from_zone: 'Mehal Ketema', to_zone: 'University', recipient_name: 'Emu', recipient_phone: '09****', fare_total: 100, delivery_fee: 105, package_type: 'food', placed_at: '2026-11-24 10:00:00', payment_method: 'cash' });
const GEO = { 7: { pickup: { lat: 7.05, lng: 38.48 }, drop: { lat: 7.06, lng: 38.49 }, pickup_label: 'Geo Kitchen', pickup_address: 'Piassa', drop_label: 'University', pickup_km: 2.3, drop_km: 3.1 }, 8: { pickup: { lat: 7.05, lng: 38.48 }, drop: null, pickup_label: 'Far Shop', pickup_address: '', drop_label: 'Tabor', pickup_km: null, drop_km: null, express: true } };
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  for (const mode of [{ n: 'phone', w: 390, h: 844, lang: 'en' }, { n: 'phone-am', w: 390, h: 844, lang: 'am' }]) {
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block', geolocation: { latitude: 7.04, longitude: 38.47 }, permissions: ['geolocation'] });
    await ctx.addInitScript(l => { try { localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 9, name: 'Cou', phone: '0911000001', role: 'courier' })); localStorage.setItem('etel_lang', l); } catch (e) {} }, mode.lang);
    const p = await ctx.newPage(); const errs = []; const geoCalls = [];
    p.on('pageerror', e => errs.push(e.message));
    await p.route(/cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net|maps\.googleapis\.com|fonts\.(googleapis|gstatic)\.com/, r => r.abort());
    await p.route(/boot\.php/, r => {
      let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
      const u = r.request().url(); const act = d.action || ((u.match(/action=([a-z_]+)/) || [])[1]); let data = {};
      if (act === 'get_service_areas') data = { areas: [], enforced: false };
      else if (act === 'order_chat_inbox') data = { chats: [], unread_total: 0 };
      else if (act === 'courier_get_available_orders') data = { orders: [job(7, 'ETD-AAA111'), job(8, 'ETD-BBB222')] };
      else if (act === 'courier_job_geo') { geoCalls.push(d); data = { jobs: GEO }; }
      else if (act === 'courier_get_profile') data = { courier: { id: 22, status: 'online', vehicle_type: 'moto', approval_status: 'approved', active: 1 }, profile: { id: 22, status: 'online', vehicle_type: 'moto', approval_status: 'approved', active: 1 } };
      else if (act === 'courier_get_my_orders') data = { orders: [] };
      else if (act === 'courier_earnings_summary') data = { today: { earned: 0, deliveries: 0, goal: 1000 }, week: { earned: 0 }, streak_days: 0, goal: 1000 };
      else if (act === 'courier_get_statement') data = { week_start: '2026-11-23', week_end: '2026-11-29', totals: { deliveries: 0, pay: 0, tips: 0, earned: 0 }, days: [], orders: [], prev_week: '2026-11-16', next_week: null };
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
    });
    await p.goto(HOST + '/delivery/courier/index.html', { waitUntil: 'load' }); await p.waitForTimeout(4500);
    const n = mode.n, am = mode.lang === 'am';
    const cards = await p.locator('#jobs-content .job-card').count();
    t(n + ': two jobs are listed', cards === 2, cards);
    t(n + ': each job has one distance box', (await p.locator('#jobs-content .cjn-box').count()) === 2);
    const c1 = p.locator('#jobs-content .job-card').nth(0);
    const txt = await c1.innerText();
    t(n + ': pickup shows the shop name', /Geo Kitchen/.test(txt), txt.slice(0, 200));
    t(n + ': pickup shows 2.3 km from you', /2\.3/.test(txt) && (am ? /ኪሜ ከእርስዎ/ : /km from you/).test(txt), txt);
    t(n + ': dropoff shows 3.1 km from the pickup', /3\.1/.test(txt) && (am ? /ኪሜ ከመውሰጃ/ : /km from pickup/).test(txt), txt);
    t(n + ': the whole trip is added up (5.4)', /5\.4/.test(txt), txt);
    const links = c1.locator('a.cjn-go');
    t(n + ': two Navigate buttons', (await links.count()) === 2);
    const h0 = await links.nth(0).getAttribute('href'), h1 = await links.nth(1).getAttribute('href');
    t(n + ': pickup button goes to the shop pin', /destination=7\.05,38\.48/.test(h0 || ''), h0);
    t(n + ': dropoff button goes to the customer pin', /destination=7\.06,38\.49/.test(h1 || ''), h1);
    t(n + ': the buttons open outside (new tab, safe)', (await links.nth(0).getAttribute('target')) === '_blank' && /noopener/.test(await links.nth(0).getAttribute('rel')));
    t(n + ': the button says ' + (am ? 'Amharic' : 'Navigate'), (am ? /መንገድ አሳይ/ : /Navigate/).test(await links.nth(0).innerText()));
    const c2 = p.locator('#jobs-content .job-card').nth(1); const t2 = await c2.innerText();
    t(n + ': a job with no dropoff pin has one button and says the pin is not set', (await c2.locator('a.cjn-go').count()) === 1 && (am ? /ካርታ አልተቀመጠም/ : /Pin not set/).test(t2), t2);
    t(n + ': a normal job has no Express strip', (await c1.locator('.cjn-x').count()) === 0);
    t(n + ': the Express job has a gold strip that says go straight to the recipient', (await c2.locator('.cjn-x').count()) === 1 && (am ? /ፈጣን \(express\)/i : /EXPRESS - go straight to the recipient/i).test(await c2.locator('.cjn-x').innerText()), await c2.locator('.cjn-x').allInnerTexts());
    t(n + ': Accept Job and Skip are still there', (await c1.locator('.job-actions button').count()) === 2);
    t(n + ': the courier position was sent', geoCalls.length > 0 && geoCalls.some(d => Math.abs(d.lat - 7.04) < 1e-6), JSON.stringify(geoCalls[0]));
    t(n + ': no customer name or phone asked or shown in the box', !/Emu/.test(await c1.locator('.cjn-box').innerText()));
    const over = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1); t(n + ': no sideways scroll on the phone', !over);
    await c1.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/courier_job_nav_' + n + '.png' });
    await c2.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/courier_job_express_' + n + '.png' });
    t(n + ': no page errors', errs.length === 0, errs.join('|')); await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

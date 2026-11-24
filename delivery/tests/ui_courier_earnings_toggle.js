// Courier app (courier/index.html): ONE button hides / shows the earnings (Today's earnings + the weekly statement) and remembers it on the phone, so the
// job buttons (Show Pickup QR, Request pickup confirmation) get room. Also: the QR and scanner libraries come from Etel itself, so the QR still shows when
// the outside websites (cdnjs, jsdelivr) do not load: they are blocked here on purpose. Real page and files, mocked API.
// Needs Playwright (node).   node delivery/tests/ui_courier_earnings_toggle.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp', '.woff2': 'font/woff2' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });
const ORDER = { id: 5, order_code: 'ETD-F23A5E', status: 'assigned', assignment_status: 'accepted', from_zone: 'Mehal Ketema', to_zone: 'University', recipient_name: 'Emu', recipient_phone: '0936533606', fare_total: 605, delivery_fee: 63, courier_pay: 63, courier_tip: 0, payment_method: 'cash', business_name: 'Admin test shop' };
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();
  for (const mode of [{ n: 'phone', w: 390, h: 844, lang: 'en' }, { n: 'phone-am', w: 390, h: 844, lang: 'am' }]) {
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
    await ctx.addInitScript(l => { try { localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 9, name: 'Cou', phone: '0911000001', role: 'courier' })); localStorage.setItem('etel_lang', l); } catch (e) {} }, mode.lang);
    const p = await ctx.newPage(); const errs = []; const blocked = []; const seen = {};
    p.on('pageerror', e => errs.push(e.message));
    await p.route(/cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net/, r => { blocked.push(r.request().url()); r.abort(); });
    await p.route(/maps\.googleapis\.com|fonts\.(googleapis|gstatic)\.com/, r => r.abort());
    await p.route(/boot\.php/, r => {
      let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
      const u = r.request().url(); const act = d.action || ((u.match(/action=([a-z_]+)/) || [])[1]); let data = {}; seen[act] = 1;
      if (act === 'get_service_areas') data = { areas: [], enforced: false };
      else if (act === 'order_chat_inbox') data = { chats: [], unread_total: 0 };
      else if (act === 'courier_get_available_orders') data = { orders: [] };
      else if (act === 'courier_get_profile') data = { courier: { id: 22, status: 'online', vehicle_type: 'qute', approval_status: 'approved', active: 1 }, profile: { id: 22, status: 'online', vehicle_type: 'qute', approval_status: 'approved' } };
      else if (act === 'courier_get_my_orders') data = { orders: /offers=1/.test(u) ? [] : [ORDER] };
      else if (act === 'courier_earnings_summary') data = { today: { earned: 0, deliveries: 0, goal: 1000 }, week: { earned: 0 }, streak_days: 0, goal: 1000 };
      else if (act === 'courier_get_statement') data = { week_start: '2026-11-23', week_end: '2026-11-29', totals: { deliveries: 0, pay: 0, tips: 0, earned: 0 }, days: [], orders: [], prev_week: '2026-11-16', next_week: null };
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
    });
    await p.goto(HOST + '/delivery/courier/index.html', { waitUntil: 'load' }); await p.waitForTimeout(3500);
    const n = mode.n, am = mode.lang === 'am';
    t(n + ': the QR library came from Etel, not from the outside website', await p.evaluate(() => typeof QRCode === 'function'), 'blocked: ' + blocked.join(', '));
    t(n + ': the QR scanner library came from Etel too', await p.evaluate(() => typeof jsQR === 'function'));
    t(n + ': no request to cdnjs or jsdelivr was even needed', blocked.length === 0, blocked.join(', '));
    t(n + ': a QR code can be drawn (a canvas or picture appears)', await p.evaluate(() => { const d = document.createElement('div'); document.body.appendChild(d); new QRCode(d, { text: JSON.stringify({ order_id: 5, type: 'pickup' }), width: 200, height: 200 }); return !!d.querySelector('canvas,img'); }));
    t(n + ': there is one button for the earnings', (await p.locator('#ect-btn').count()) === 1 && (await p.locator('.ect-bar button').count()) === 1);
    t(n + ': it says ' + (am ? 'hide (Amharic)' : 'Hide earnings'), (am ? /ገቢን ደብቅ/ : /Hide earnings/).test(await p.innerText('#ect-btn')), await p.innerText('#ect-btn'));
    const vis = (sel) => p.evaluate(s => { const e = document.querySelector(s); return !!e && getComputedStyle(e).display !== 'none' && e.getBoundingClientRect().height > 0; }, sel);
    t(n + ': the earnings box and the weekly statement are showing', await vis('.earn-card') && await vis('#cst-card'));
    const tabsTop = () => p.evaluate(() => document.querySelector('.tabs').getBoundingClientRect().top + window.scrollY);
    const before = await tabsTop();
    await p.click('#ect-btn'); await p.waitForTimeout(300);
    t(n + ': pressing it hides both cards', !(await vis('.earn-card')) && !(await vis('#cst-card')));
    t(n + ': the button now says Show (and a note says they are hidden)', (am ? /ገቢን አሳይ/ : /Show earnings/).test(await p.innerText('#ect-btn')) && (await p.innerText('#ect-note')).length > 0);
    t(n + ': the jobs move up the screen (more room for the job buttons)', (await tabsTop()) < before - 100, before + ' -> ' + (await tabsTop()));
    t(n + ': it is remembered', await p.evaluate(() => localStorage.getItem('etel_courier_earn_hidden')) === '1');
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/courier_earnings_hidden_' + n + '.png' });
    await p.reload({ waitUntil: 'load' }); await p.waitForTimeout(3500);
    t(n + ': after a reload the earnings are still hidden', !(await vis('.earn-card')) && !(await vis('#cst-card')));
    await p.evaluate(() => { if (typeof window.refreshEarningsCard === 'function') window.refreshEarningsCard(); }); await p.waitForTimeout(500);
    t(n + ': a refresh of the numbers does not bring them back', !(await vis('.earn-card')));
    await p.click('#ect-btn'); await p.waitForTimeout(300);
    t(n + ': pressing again shows both cards again', await vis('.earn-card') && await vis('#cst-card') && (am ? /ገቢን ደብቅ/ : /Hide earnings/).test(await p.innerText('#ect-btn')));
    t(n + ': no page errors', errs.length === 0, errs.join('|') + ' | actions asked: ' + Object.keys(seen).join(','));
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

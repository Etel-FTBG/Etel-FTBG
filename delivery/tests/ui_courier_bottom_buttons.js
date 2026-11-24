// Courier app: the two buttons at the bottom of the page (Refresh jobs, Earnings) are not hidden behind the floating buttons (help, job sound, theme, refresh).
// Real page, mocked API; the floating buttons are placed as on the phone.   node delivery/tests/ui_courier_bottom_buttons.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'application/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });
const ORDER = { id: 5, order_code: 'ETD-F23A5E', status: 'picked_up', assignment_status: 'accepted', from_zone: 'Mehal Ketema', to_zone: 'University', recipient_name: 'Emu', recipient_phone: '0936533606', recipient_address: 'Cheffe', delivery_lat: 7.06, delivery_lng: 38.49, fare_total: 605, delivery_fee: 63, courier_pay: 63, courier_tip: 0, payment_method: 'cash', business_name: 'Admin test shop' };
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  for (const mode of [{ n: 'phone', w: 390, h: 844 }, { n: 'small phone', w: 360, h: 640 }]) for (const tab of [0, 1, 2]) {
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
    await ctx.addInitScript(() => { try { localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 9, name: 'Cou', phone: '0911000001', role: 'courier' })); localStorage.setItem('etel_lang', 'en'); } catch (e) {} });
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route(/cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net|maps\.googleapis\.com|fonts\.(googleapis|gstatic)\.com/, r => r.abort());
    await p.route(/boot\.php/, r => { let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} const u = r.request().url(); const act = d.action || ((u.match(/action=([a-z_]+)/) || [])[1]); let data = {};
      if (act === 'get_service_areas') data = { areas: [], enforced: false }; else if (act === 'order_chat_inbox') data = { chats: [], unread_total: 0 }; else if (act === 'courier_get_available_orders') data = { orders: [] };
      else if (act === 'courier_get_profile') data = { courier: { id: 22, status: 'online', vehicle_type: 'qute', approval_status: 'approved', active: 1 }, profile: { id: 22, status: 'online', vehicle_type: 'qute', approval_status: 'approved', active: 1 } };
      else if (act === 'courier_get_my_orders') data = { orders: /offers=1/.test(u) ? [] : [ORDER] };
      else if (act === 'courier_earnings_summary') data = { today: { earned: 0, deliveries: 0, goal: 1000 }, week: { earned: 0 }, streak_days: 0, goal: 1000 };
      else if (act === 'courier_get_statement') data = { week_start: '2026-11-23', week_end: '2026-11-29', totals: { deliveries: 0, pay: 0, tips: 0, earned: 0 }, days: [], orders: [], prev_week: '2026-11-16', next_week: null };
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) }); });
    await p.goto(HOST + '/delivery/courier/index.html', { waitUntil: 'load' }); await p.waitForTimeout(3500);
    // the floating buttons as on the phone: job sound chip (bottom left), theme round button (bottom right)
    await p.evaluate(() => { const c = document.createElement('button'); c.id = 'etel-sound-chip'; c.textContent = '🔔 Job sound on'; c.style.cssText = 'position:fixed;left:12px;bottom:12px;z-index:99999;padding:9px 14px;border-radius:22px;font-size:13px'; document.body.appendChild(c);
      const m = document.createElement('button'); m.id = 'test-moon'; m.textContent = '🌙'; m.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:99999;width:56px;height:56px;border-radius:50%'; document.body.appendChild(m); });
    await p.evaluate(i => { document.querySelectorAll('.tab-btn')[i].click(); }, tab); await p.waitForTimeout(800);
    await p.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight)); await p.waitForTimeout(400);
    const r = await p.evaluate(() => {
      const hit = (a, b2) => !(a.right <= b2.left || a.left >= b2.right || a.bottom <= b2.top || a.top >= b2.bottom);
      const btns = [...document.querySelectorAll('.action-bar button')].map(e => ({ t: e.textContent.trim(), r: e.getBoundingClientRect() }));
      const fixed = [...document.querySelectorAll('body *')].filter(e => getComputedStyle(e).position === 'fixed' && getComputedStyle(e).display !== 'none' && e.getBoundingClientRect().height > 0 && !e.closest('#etel-master-splash')).map(e => ({ id: e.id, r: e.getBoundingClientRect() }));
      return btns.map(x => ({ t: x.t, inView: x.r.top >= 0 && x.r.bottom <= window.innerHeight, covered: fixed.filter(f => hit(x.r, f.r)).map(f => f.id || 'fixed') }));
    });
    const n = mode.n + ' / tab ' + tab;
    t(n + ': both buttons are there (Refresh jobs, Earnings)', r.length === 2 && /Refresh jobs/.test(r[0].t) && /Earnings/.test(r[1].t), JSON.stringify(r));
    t(n + ': both are fully on the screen at the bottom of the page', r.every(x => x.inView), JSON.stringify(r));
    t(n + ': nothing floats on top of them', r.every(x => x.covered.length === 0), JSON.stringify(r));
    if (mode.n === 'phone' && tab === 1) await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/courier_bottom_fixed.png' });
    t(n + ': no page errors', errs.length === 0, errs.join('|')); await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

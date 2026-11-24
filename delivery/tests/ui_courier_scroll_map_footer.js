// Courier app: (1) the page scrolls with a finger (it was locked by html{overflow:hidden}); (2) ONE button hides / shows the map and remembers it; (3) a footer at the end
// of the page holds Refresh jobs, Earnings, job sound, help and theme so nothing is hidden behind the floating buttons. Real page, mocked API, phone with touch.
// Needs Playwright (node).   node delivery/tests/ui_courier_scroll_map_footer.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'application/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });
const ORDER = { id: 5, order_code: 'ETD-F23A5E', status: 'picked_up', assignment_status: 'accepted', from_zone: 'Mehal Ketema', to_zone: 'University', recipient_name: 'Emu', recipient_phone: '0936533606', recipient_address: 'Cheffe', delivery_lat: 7.06, delivery_lng: 38.49, fare_total: 605, delivery_fee: 63, courier_pay: 63, courier_tip: 0, payment_method: 'cash', business_name: 'Admin test shop' };
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  for (const lang of ['en', 'am']) {
    const ctx = await b.newContext({ viewport: { width: 412, height: 915 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2, serviceWorkers: 'block' });
    await ctx.addInitScript(l => { try { localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 9, name: 'Cou', phone: '0911000001', role: 'courier' })); localStorage.setItem('etel_lang', l); } catch (e) {} }, lang);
    const p = await ctx.newPage(); const errs = []; const seen = {}; p.on('pageerror', e => errs.push(e.message));
    await p.route(/cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net|maps\.googleapis\.com|fonts\.(googleapis|gstatic)\.com/, r => r.abort());
    await p.route(/boot\.php/, r => { let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} const u = r.request().url(); const act = d.action || ((u.match(/action=([a-z_]+)/) || [])[1]); let data = {}; seen[act] = (seen[act] || 0) + 1;
      if (act === 'get_service_areas') data = { areas: [], enforced: false }; else if (act === 'order_chat_inbox') data = { chats: [], unread_total: 0 }; else if (act === 'courier_get_available_orders') data = { orders: [] };
      else if (act === 'courier_get_profile') data = { courier: { id: 22, status: 'online', vehicle_type: 'qute', approval_status: 'approved', active: 1 }, profile: { id: 22, status: 'online', vehicle_type: 'qute', approval_status: 'approved', active: 1 } };
      else if (act === 'courier_get_my_orders') data = { orders: /offers=1/.test(u) ? [] : [ORDER] };
      else if (act === 'courier_earnings_summary') data = { today: { earned: 0, deliveries: 0, goal: 1000 }, week: { earned: 0 }, streak_days: 0, goal: 1000 };
      else if (act === 'courier_get_statement') data = { week_start: '2026-11-23', week_end: '2026-11-29', totals: { deliveries: 0, pay: 0, tips: 0, earned: 0 }, days: [], orders: [], prev_week: '2026-11-16', next_week: null };
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) }); });
    await p.goto(HOST + '/delivery/courier/index.html', { waitUntil: 'load' }); await p.waitForTimeout(3500); const am = lang === 'am', n = lang;
    await p.evaluate(() => {   // the real job-sound chip and theme button are on the page; count taps on them (create them only if this build has none)
      let c = document.getElementById('etel-sound-chip'); if (!c) { c = document.createElement('button'); c.id = 'etel-sound-chip'; c.textContent = '\ud83d\udd14 Job sound on'; c.style.cssText = 'position:fixed;left:12px;bottom:12px;z-index:99999;padding:9px 14px;border-radius:22px;font-size:13px'; document.body.appendChild(c); }
      c.addEventListener('click', () => { window.__soundClicks = (window.__soundClicks || 0) + 1; });
      let m = document.getElementById('dark-theme-toggle'); if (!m) { m = document.createElement('button'); m.id = 'dark-theme-toggle'; m.textContent = '\ud83c\udf19'; m.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:99999;width:56px;height:56px;border-radius:50%'; document.body.appendChild(m); }
      m.addEventListener('click', () => { window.__themeClicks = (window.__themeClicks || 0) + 1; }); });
    await p.evaluate(() => { document.querySelectorAll('.tab-btn')[1].click(); }); await p.waitForTimeout(800);
    // 1. a finger / wheel scroll moves the page
    const sc = () => p.evaluate(() => window.scrollY);
    await p.mouse.move(200, 700); await p.mouse.wheel(0, 700); await p.waitForTimeout(500);
    t(n + ' 1 the page scrolls when the courier scrolls (it was locked)', (await sc()) > 100, await sc());
    for (let i = 0; i < 6; i++) { await p.mouse.wheel(0, 900); await p.waitForTimeout(150); }
    const maxY = await p.evaluate(() => document.documentElement.scrollHeight - innerHeight);
    t(n + ' 1 it scrolls all the way to the end', Math.abs((await sc()) - maxY) < 3, (await sc()) + ' / ' + maxY);
    // 2. the footer
    const foot = await p.evaluate(() => { const f = document.getElementById('cf-footer'); if (!f) return null; const hit = (a, b2) => !(a.right <= b2.left || a.left >= b2.right || a.bottom <= b2.top || a.top >= b2.bottom);
      const fixed = [...document.querySelectorAll('body *')].filter(e => getComputedStyle(e).position === 'fixed' && getComputedStyle(e).display !== 'none' && e.getBoundingClientRect().height > 0 && !f.contains(e) && !e.closest('#etel-master-splash')).map(e => ({ id: e.id, r: e.getBoundingClientRect() }));
      const items = [...f.querySelectorAll('button,a')].map(e => ({ t: e.textContent.trim(), r: e.getBoundingClientRect() }));
      return { items: items.map(x => ({ t: x.t, inView: x.r.top >= 0 && x.r.bottom <= innerHeight, covered: fixed.filter(g => hit(x.r, g.r)).map(g => g.id || 'fixed') })), hasBar: !!f.querySelector('.action-bar'), text: f.innerText }; });
    t(n + ' 2 there is a footer at the end of the page', !!foot);
    t(n + ' 2 it holds Refresh jobs and Earnings (moved, not copied)', foot && foot.hasBar && /Refresh jobs/.test(foot.text) && /Earnings/.test(foot.text) && (await p.locator('.action-bar').count()) === 1, foot && foot.text);
    t(n + ' 2 and job sound, help and theme', foot && (am ? /የሥራ ድምፅ/ : /Job sound/).test(foot.text) && (am ? /እገዛ/ : /Help/).test(foot.text) && (am ? /ገጽታ/ : /Theme/).test(foot.text), foot && foot.text);
    t(n + ' 2 every footer button is fully on the screen and nothing floats on top of it', foot && foot.items.length >= 5 && foot.items.every(x => x.inView && x.covered.length === 0), JSON.stringify(foot && foot.items));
    await p.locator('#cf-footer').screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/courier_footer_' + n + '.png' });
    // the moved buttons still work
    const before = seen['courier_get_available_orders'] || 0;
    await p.locator('#cf-footer .action-bar button').first().click(); await p.waitForTimeout(600);
    t(n + ' 2 Refresh jobs (in the footer) still refreshes the jobs', (seen['courier_get_available_orders'] || 0) > before, JSON.stringify(seen));
    await p.locator('#cf-sound').click(); await p.locator('#cf-theme').click(); await p.waitForTimeout(150);
    t(n + ' 2 the footer sound and theme buttons use the real ones', (await p.evaluate(() => window.__soundClicks)) === 1 && (await p.evaluate(() => window.__themeClicks)) === 1);
    // 3. the map button
    await p.evaluate(() => window.scrollTo(0, 0)); await p.waitForTimeout(200);
    t(n + ' 3 one button above the map', (await p.locator('#cmt-btn').count()) === 1 && (await p.locator('#cmt-bar button').count()) === 1);
    t(n + ' 3 it says ' + (am ? 'hide (Amharic)' : 'Hide map'), (am ? /ካርታውን ደብቅ/ : /Hide map/).test(await p.innerText('#cmt-btn')), await p.innerText('#cmt-btn'));
    const vis = () => p.evaluate(() => { const e = document.getElementById('courier-map-container'); return getComputedStyle(e).display !== 'none' && e.getBoundingClientRect().height > 0; });
    const tabsTop = () => p.evaluate(() => document.querySelector('.tabs').getBoundingClientRect().top + window.scrollY);
    t(n + ' 3 the map is showing', await vis()); const top0 = await tabsTop();
    await p.click('#cmt-btn'); await p.waitForTimeout(300);
    t(n + ' 3 pressing it hides the map', !(await vis()));
    t(n + ' 3 the button now says Show map and a note says it is hidden', (am ? /ካርታውን አሳይ/ : /Show map/).test(await p.innerText('#cmt-btn')) && (await p.innerText('#cmt-note')).length > 0);
    t(n + ' 3 the jobs move up (more room)', (await tabsTop()) < top0 - 150, top0 + ' -> ' + (await tabsTop()));
    t(n + ' 3 it is remembered', (await p.evaluate(() => localStorage.getItem('etel_courier_map_hidden'))) === '1');
    await p.evaluate(() => document.body.classList.add('cn-on')); await p.waitForTimeout(100);
    t(n + ' 3 full-screen navigation always shows the map, even when it is hidden', await vis());
    await p.evaluate(() => document.body.classList.remove('cn-on')); await p.waitForTimeout(100);
    await p.reload({ waitUntil: 'load' }); await p.waitForTimeout(3500);
    t(n + ' 3 after a reload the map is still hidden', !(await vis()));
    await p.click('#cmt-btn'); await p.waitForTimeout(300);
    t(n + ' 3 pressing again shows the map', await vis() && (am ? /ካርታውን ደብቅ/ : /Hide map/).test(await p.innerText('#cmt-btn')));
    t(n + ' 4 no page errors', errs.length === 0, errs.join('|')); await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

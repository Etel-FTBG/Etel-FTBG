// Admin > Operations > Live operations. Needs Playwright (node). No database: the API is a mock.
//   (cd <repo root> && python3 -m http.server 8098 &)   then   node delivery/tests/ui_admin_liveops.js
const { chromium } = require('playwright');
const HOST = process.env.ETEL_HOST || 'http://127.0.0.1:8098'; const SHOTS = process.env.ETEL_SHOTS || '/tmp';
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const sp = (r, o) => ({ rides: r, orders: o });
const snapshot = (waiting) => ({
  generated_at: '2026-11-19 10:00:00', has_order_assign_time: true,
  alerts: waiting ? [{ level: 'critical', text: '1 SOS alert(s) are open (the oldest for 4 min). Open the SOS panel and call the person now.' }, { level: 'critical', text: 'A ride request has waited 7 minutes for a driver.' }, { level: 'warn', text: 'An order has waited 9 minutes for a courier.' }] : [],
  now: {
    rides: { searching: waiting ? 2 : 0, on_the_way: 3, in_progress: 4, oldest_wait_min: waiting ? 7 : 0, waiting: waiting ? [{ id: 41, minutes: 7, where: 'Stadium gate', who: 'Abel' }, { id: 42, minutes: 1, where: 'Piassa', who: 'Sara' }] : [] },
    drivers: { online: 9, busy: 7, free: 2 },
    orders: { waiting: waiting ? 1 : 0, assigned: 2, on_the_way: 5, oldest_wait_min: waiting ? 9 : 0, waiting_list: waiting ? [{ id: 7, code: 'ET-77', minutes: 9, where: 'Stadium > Piassa' }] : [] },
    couriers: { online: 6, free: 1 }, sos: { open: waiting ? 1 : 0, oldest_min: waiting ? 4 : 0 } },
  speed: {
    today: sp({ requests: 20, taken: 17, taken_pct: 85, gave_up: 2, completed: 12, secs_to_take: { n: 17, typical: 45, slow: 130 } }, { placed: 11, taken: 9, taken_pct: 82, delivered: 6, cancelled: 1, secs_to_take: { n: 9, typical: 200, slow: 600 }, secs_to_deliver: { n: 6, typical: 1800, slow: 3300 } }),
    week: sp({ requests: 140, taken: 120, taken_pct: 86, gave_up: 9, completed: 99, secs_to_take: { n: 120, typical: 60, slow: 185 } }, { placed: 80, taken: 70, taken_pct: 88, delivered: 60, cancelled: 5, secs_to_take: { n: 70, typical: 240, slow: 720 }, secs_to_deliver: { n: 60, typical: 2400, slow: 4000 } }) } });
(async () => {
  const b = await chromium.launch();
  for (const mode of [{ n: 'desktop', w: 1360, h: 900, th: 'light' }, { n: 'mobile', w: 390, h: 844, th: 'dark' }]) {
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
    await ctx.addInitScript(th => { window.ETEL_CAPS = ['*']; try { localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_user', JSON.stringify({ id: 1, name: 'Owner', role: 'admin', phone: '0900000000' })); localStorage.setItem('theme', th); } catch (e) {} }, mode.th);
    const p = await ctx.newPage(); const errs = []; let calls = 0; let waiting = true; let fail = false;
    p.on('pageerror', e => { if (!/admin-home\.js/.test(e.stack || '')) errs.push(e.message); });
    await p.route('**/api/boot.php**', r => {
      let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} const a = d.action; let data = {}; let success = true; let message = '';
      if (a === 'admin_get_my_capabilities') data = { capabilities: ['*'], role: 'admin' };
      else if (a === 'admin_live_ops') { calls++; if (fail) { success = false; message = 'Could not load the live numbers.'; } else data = snapshot(waiting); }
      r.fulfill({ contentType: 'application/json', body: JSON.stringify(success ? { success: true, data } : { success: false, message }) });
    });
    await p.goto(HOST + '/delivery/admin.html', { waitUntil: 'load' }); await p.waitForTimeout(1500);
    t(mode.n + ': the menu has Live operations next to Rides', (await p.locator('.nav-item[data-page="liveops"]').count()) === 1);
    calls = 0;   // the workspace Home (js/admin-workspaces.js) also asks once for its own numbers; count only this page's calls
    await p.evaluate(() => { const n = document.querySelector('.nav-item[data-page="liveops"]'); if (n) n.classList.remove('etel-cap-hidden'); switchPage('liveops', n); }); await p.waitForTimeout(1500);
    let txt = await p.innerText('#panel-liveops');
    t(mode.n + ': the numbers right now are shown', /Right now/i.test(txt) && /Ride requests waiting/.test(txt) && /Drivers online \(2 free, 7 on a trip\)/.test(txt) && /Couriers online \(1 free\)/.test(txt), txt.slice(0, 300));
    t(mode.n + ': both alerts are shown, the critical one first', (await p.locator('.lo-alert').count()) === 3 && (await p.locator('.lo-alert').first().getAttribute('class')).indexOf('critical') > 0);
    t(mode.n + ': the open SOS alert is the first alert and has its own tile', /SOS alert\(s\) are open/.test(await p.locator('.lo-alert').first().innerText()) && (await p.locator('.lo-tile.hot', { hasText: 'Open SOS alerts' }).count()) === 1);
    t(mode.n + ': the waiting lists show the request, place, person, minutes and the order code', /#41 - Stadium gate \(Abel\)/.test(txt) && /7 min/.test(txt) && /ET-77 - Stadium > Piassa/.test(txt));
    t(mode.n + ': dispatch speed today: typical 45 s, slowest 2 min 10 s, taken 85%', /45 s/.test(txt) && /2 min 10 s/.test(txt) && /17 \(85%\)/.test(txt), txt.slice(txt.indexOf('Dispatch')));
    t(mode.n + ': order times are written in words (3 min 20 s, 30 min, 55 min)', /3 min 20 s/.test(txt) && /30 min/.test(txt) && /55 min/.test(txt));
    await p.click('[data-lo-win="week"]'); await p.waitForTimeout(300); txt = await p.innerText('#panel-liveops');
    t(mode.n + ': the Last 7 days tab switches the numbers without asking the server again', /185 s|3 min 5 s/.test(txt) && /120 \(86%\)/.test(txt) && calls === 1, 'calls ' + calls);
    // a quiet moment
    waiting = false; await p.click('[data-lo-refresh]'); await p.waitForTimeout(700); txt = await p.innerText('#panel-liveops');
    t(mode.n + ': refresh now: no alerts, a calm message, empty waiting lists; the chosen tab is kept', (await p.locator('.lo-alert').count()) === 0 && /Nothing is waiting too long/.test(txt) && /No ride request is waiting/.test(txt) && /No order is waiting for a courier/.test(txt) && (await p.locator('[data-lo-win="week"].on').count()) === 1);
    // it updates by itself
    const before = calls; await p.waitForTimeout(16000);
    t(mode.n + ': it asks again by itself after about 15 seconds', calls > before, 'before ' + before + ' after ' + calls);
    // leaving the page stops it
    await p.evaluate(() => { const n = document.querySelector('.nav-item[data-page="dashboard"]'); switchPage('dashboard', n); }); const c2 = calls; await p.waitForTimeout(16500);
    t(mode.n + ': leaving the page stops the asking', calls === c2, 'before ' + c2 + ' after ' + calls);
    // an error
    fail = true; await p.evaluate(() => { const n = document.querySelector('.nav-item[data-page="liveops"]'); switchPage('liveops', n); }); await p.waitForTimeout(1200);
    t(mode.n + ': a failure shows a message', /Could not load the live numbers/.test(await p.innerText('#panel-liveops')));
    fail = false; await p.click('body'); 
    const overflow = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth); t(mode.n + ': no sideways page scroll', overflow <= 1, String(overflow));
    waiting = true; await p.evaluate(() => { if (window.ETELLiveOps) window.ETELLiveOps.refresh(); }); await p.waitForTimeout(900);
    await p.setViewportSize({ width: mode.w, height: 2300 }); await p.waitForTimeout(400);
    await p.screenshot({ path: SHOTS + '/etel-liveops-' + mode.n + '.png' });
    const bad = errs.filter(e => !/favicon|manifest|Failed to load resource|Google Maps/.test(e)); t(mode.n + ': no JS errors', bad.length === 0, bad.join(' | '));
    await ctx.close();
  }
  await b.close(); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); fails.forEach(f => console.log(' - ' + f)); process.exit(fails.length ? 1 : 0);
})();

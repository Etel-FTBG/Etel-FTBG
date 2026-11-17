// Agent portal Home: "Your numbers" dashboard + desktop layout. Needs Playwright (node). No database: the API is a mock.
//   (cd <repo root> && python3 -m http.server 8098 &)   then   node delivery/tests/agents/ui_agent_dashboard.js
const { chromium } = require('playwright');
const HOST = process.env.ETEL_HOST || 'http://127.0.0.1:8098'; const SHOTS = process.env.ETEL_SHOTS || '/tmp';
let pass = 0; const fails = []; const ok = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
function day(n) { const d = new Date(Date.now() - n * 864e5); return d.toISOString().slice(0, 10); }
function dash(days) {
  const series = []; for (let i = days - 1; i >= 0; i--) series.push({ d: day(i), earned: i % 3 === 0 ? 50 + i : 0, riders: i % 2, qualified: i % 4 === 0 ? 1 : 0, bookings: i % 5 === 0 ? 1 : 0 });
  const sum = k => series.reduce((a, r) => a + r[k], 0);
  return { days, today: series[series.length - 1], current: { earned: sum('earned'), riders: sum('riders'), qualified: sum('qualified'), bookings: sum('bookings') },
    previous: { earned: sum('earned') / 2, riders: sum('riders') + 2, qualified: sum('qualified'), bookings: 0 }, conversion_pct: 40, best_day: { d: day(3), earned: 99 }, streak_days: 3, series };
}
const ME = { is_agent: true, program_enabled: true, agent: { id: 1, name: 'Abebe Kebede', venue: 'Lake Hotel', venue_type: 'hotel', venue_zone: 'Piassa', status: 'approved', code: 'AG1234', tier: 'silver', roles: ['ride', 'partner'], age_verified: true, payout_phone: '0911000001', decision_reason: '' },
  balances: { pending: 120, available: 300, paid: 500, total: 920 }, referral_counts: {}, progress: { trips: 1, days_left: 5 }, unread: 0, tier_progress: null,
  rules: { bonus_first: 50, bonus_repeat: 100, repeat_trips: 3, repeat_days: 14, hold_days: 7, min_payout: 100, min_km: 1, min_minutes: 2 } };
(async () => {
  const b = await chromium.launch();
  for (const cfg of [{ n: 'phone-dark', w: 390, h: 900, th: 'dark' }, { n: 'phone-light', w: 390, h: 900, th: 'light' }, { n: 'desk-dark', w: 1360, h: 900, th: 'dark' }, { n: 'desk-light', w: 1360, h: 900, th: 'light' }]) {
    const ctx = await b.newContext({ viewport: { width: cfg.w, height: cfg.h }, deviceScaleFactor: 1, serviceWorkers: 'block' });
    await ctx.addInitScript(th => { try { localStorage.setItem('etel_token', 't'); localStorage.setItem('theme', th); localStorage.setItem('etel_agent_lang', 'en'); } catch (e) {} }, cfg.th);
    const p = await ctx.newPage(); const errs = []; let dashCalls = [];
    p.on('pageerror', e => errs.push(e.message));
    await p.route('**/api/boot.php', r => { const d = JSON.parse(r.request().postData() || '{}');
      if (d.action === 'fa_agent_me') return r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: ME }) });
      if (d.action === 'fa_agent_dashboard') { dashCalls.push(d.days); return r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: dash(d.days) }) }); }
      return r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: {} }) }); });
    await p.goto(HOST + '/delivery/agent/index.html', { waitUntil: 'networkidle' }); await p.waitForTimeout(1500);
    const t = await p.evaluate(() => document.getElementById('dash') ? document.getElementById('dash').innerText : '');
    ok(cfg.n + ': dashboard shown', /Your numbers/.test(t) && /earned/i.test(t) && /new riders/i.test(t), t.slice(0, 60));
    ok(cfg.n + ': asked the server for 30 days', dashCalls[0] === 30, String(dashCalls));
    ok(cfg.n + ': two charts', (await p.locator('.ds-svg').count()) === 2);
    await p.click('[data-act=ds-days][data-d="7"]'); await p.waitForTimeout(600);
    ok(cfg.n + ': 7 days asked and pressed', dashCalls.indexOf(7) >= 0 && (await p.getAttribute('[data-act=ds-days][data-d="7"]', 'aria-pressed')) === 'true', String(dashCalls));
    await p.click('.ds-bar >> nth=2'); await p.waitForTimeout(200);
    ok(cfg.n + ': tapping a bar shows its value', (await p.locator('.ds-sel').count()) >= 1);
    await p.click('.ds-list summary'); ok(cfg.n + ': list opens', (await p.locator('.ds-tw tr').count()) > 1);
    const overflow = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    ok(cfg.n + ': no sideways scroll', overflow <= 1, String(overflow));
    if (cfg.w >= 1024) { const nav = await p.evaluate(() => { const n = document.querySelector('.nav').getBoundingClientRect(); return [n.left, n.width, n.height]; }); ok(cfg.n + ': side menu on the left', nav[0] === 0 && nav[1] >= 240 && nav[2] >= 800, JSON.stringify(nav)); }
    else { const nav = await p.evaluate(() => { const n = document.querySelector('.nav').getBoundingClientRect(); return [n.bottom, innerHeight]; }); ok(cfg.n + ': bottom menu on phone', Math.abs(nav[0] - nav[1]) <= 1, JSON.stringify(nav)); }
    ok(cfg.n + ': no page errors', errs.length === 0, errs.join(' | '));
    await p.screenshot({ path: SHOTS + '/agent-' + cfg.n + '.png', fullPage: false });
    await ctx.close();
  }
  await b.close(); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); fails.forEach(f => console.log(' - ' + f)); process.exit(fails.length ? 1 : 0);
})();

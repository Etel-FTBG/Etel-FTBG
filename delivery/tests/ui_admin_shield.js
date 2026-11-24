// Admin > Etel Shield page (admin-shield.html): loads the real page with a mocked API; checks what it shows, what it sends on Save, and that it never breaks.   node delivery/tests/ui_admin_shield.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
const srv = http.createServer((q, r) => { const u = q.url.split('?')[0]; fs.readFile(path.join(ROOT, u === '/' ? 'admin-shield.html' : u), (e, d) => { if (e) { r.statusCode = 404; return r.end(); }
  r.setHeader('Content-Type', u.endsWith('.js') ? 'application/javascript; charset=utf-8' : 'text/html; charset=utf-8'); r.end(d); }); });
const cfg = { enabled: 1, geo_mode: 'monitor', geo_device_mode: 'monitor', rate_mode: 'block', scanner_mode: 'block', waf_mode: 'monitor', rate_anon_per_min: 1500, rate_auth_per_min: 600, rate_login_per_min: 60, rate_signup_per_min: 20, allow_ips: ['10.1.1.1'] };
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  const run = async (o, steps) => {
    const ctx = await b.newContext({ serviceWorkers: 'block' }); await ctx.addInitScript(() => { localStorage.setItem('etel_token', 'T'); });
    const p = await ctx.newPage(); const calls = []; const errs = []; p.on('pageerror', e => errs.push(e.stack || e.message)); p.on('dialog', d => d.accept());
    await p.route(/fonts\.googleapis/, r => r.fulfill({ body: '' }));
    await p.route(/boot\.php/, r => { const d = JSON.parse(r.request().postData() || '{}'); calls.push(d); const a = d.action; let data = {};
      if (a === 'admin_shield_get' || a === 'admin_shield_save') data = { config: Object.assign({}, cfg, d.config || {}), defaults: {}, env_off: !!o.envOff, stats: { events_24h: 7, would_block_24h: 5, blocked_24h: 2, bans_active: 1, by_rule: [], top_ips: [{ ip: '9.9.9.9', n: 4 }] }, me: { ip: '1.2.3.4', country: o.country || 'ET', allowed: false } };
      else if (a === 'session_ping') data = { idle_minutes: 15, exempt: false, seconds_left: null };
      else if (a === 'admin_idle_get') data = { settings: { admin: 15, customer: 30 }, defaults: { admin: 15, customer: 30 }, env_off: false };
      else if (a === 'admin_idle_save') data = { settings: d.settings, defaults: {}, env_off: false };
      else if (a === 'admin_shield_bans') data = { bans: [{ ip: '8.8.8.8', reason: '<b>x</b>', minutes_left: 10 }] };
      else if (a === 'admin_shield_unban') data = { bans: [] };
      else if (a === 'admin_shield_ban') data = { bans: [{ ip: d.ip, reason: 'blocked by an admin', minutes_left: d.minutes }] };
      else if (a === 'admin_shield_events') data = { events: [{ at: '2026-11-24 10:00:00', ip: '5.5.5.5', country: 'US', rule: 'geo_ip', mode: 'monitor', detail: '<img src=x onerror=window.__xss=1>' }] };
      else if (a === 'admin_shield_geo_check') data = { ip: d.ip, ethiopia: false, list: { v4_ranges: 100 } };
      if (o.deny) return r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: false, message: 'Not allowed' }) });
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: data }) }); });
    await p.goto(HOST + '/'); await p.waitForTimeout(400); await steps(p, calls); await ctx.close(); return errs;
  };
  const sent = (calls, a) => calls.filter(c => c.action === a);
  let errs = await run({}, async (p, calls) => {
    t('1 loads the shield, idle, bans and log', ['admin_shield_get', 'admin_idle_get', 'admin_shield_bans', 'admin_shield_events'].every(a => sent(calls, a).length === 1));
    t('2 shows the numbers', /7\s*events/.test(await p.locator('#top').innerText()) && (await p.locator('#top').innerText()).includes('1.2.3.4'));
    t('3 settings filled in', (await p.inputValue('#geo_mode')) === 'monitor' && (await p.inputValue('#rate_anon_per_min')) === '1500' && (await p.inputValue('#allow_ips')) === '10.1.1.1');
    t('4 log text is escaped (no script from a log line)', !(await p.evaluate(() => window.__xss)) && (await p.locator('#events').innerText()).includes('<img'));
    t('5 ban reason is escaped', (await p.locator('#bans').innerText()).includes('<b>x</b>'));
    await p.selectOption('#geo_mode', 'enforce'); await p.click('#save-geo'); await p.waitForTimeout(200);
    const s = sent(calls, 'admin_shield_save'); t('6 Save sends only the two Ethiopia settings', s.length === 1 && s[0].config.geo_mode === 'enforce' && s[0].config.geo_device_mode === 'monitor' && Object.keys(s[0].config).length === 2, JSON.stringify(s));
    await p.fill('#allow_ips', '1.1.1.1\n2.2.2.0/24, 3.3.3.3'); await p.click('#save-allow'); await p.waitForTimeout(200);
    t('7 allowed addresses are split into a list', JSON.stringify(sent(calls, 'admin_shield_save')[1].config.allow_ips) === '["1.1.1.1","2.2.2.0/24","3.3.3.3"]');
    await p.selectOption('#waf_mode', 'block'); await p.fill('#rate_login_per_min', '90'); await p.click('#save-prot'); await p.waitForTimeout(200);
    const pr = sent(calls, 'admin_shield_save')[2].config; t('8 protection save sends the modes and numbers', pr.waf_mode === 'block' && pr.rate_login_per_min === 90 && pr.rate_anon_per_min === 1500);
    await p.fill('#idle-rows input[data-role=admin]', '20'); await p.click('#save-idle'); await p.waitForTimeout(200);
    t('9 idle save sends minutes for each role', sent(calls, 'admin_idle_save')[0].settings.admin === 20 && sent(calls, 'admin_idle_save')[0].settings.customer === 30);
    await p.click('[data-unban]'); await p.waitForTimeout(200); t('10 unblock calls the API and empties the list', sent(calls, 'admin_shield_unban')[0].ip === '8.8.8.8' && (await p.locator('#bans').innerText()).includes('No one'));
    await p.fill('#ban-ip', '7.7.7.7'); await p.fill('#ban-min', '30'); await p.click('#ban-go'); await p.waitForTimeout(200); t('11 block sends address and minutes', sent(calls, 'admin_shield_ban')[0].ip === '7.7.7.7' && sent(calls, 'admin_shield_ban')[0].minutes === 30);
    await p.fill('#chk-ip', '8.8.4.4'); await p.click('#chk-go'); await p.waitForTimeout(200); t('12 address check says not Ethiopian', (await p.locator('#msg-chk').innerText()).includes('NOT in the Ethiopian'));
    await p.selectOption('#ev-rule', 'geo'); await p.waitForTimeout(200); t('13 log filter is sent', sent(calls, 'admin_shield_events').pop().rule === 'geo');
  });
  t('14 no page errors', errs.length === 0, errs.join('|'));
  errs = await run({ envOff: true }, async (p) => { t('15 emergency switch is shown', (await p.locator('#top').innerText()).includes('SHIELD_OFF=1')); });
  errs = await run({ deny: true }, async (p) => { t('16 a denied admin sees the message, not a broken page', (await p.locator('#top').innerText()).includes('Not allowed')); });
  t('17 no page errors when denied', errs.length === 0, errs.join('|'));
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED (' + fails.length + '):\n' + fails.join('\n') : 'ok: ' + pass + ' checks passed'); process.exit(fails.length ? 1 : 0);
})();

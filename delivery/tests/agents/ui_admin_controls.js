// Admin > Agents > Controls tab. Needs Playwright (node). No database: the API is a mock.
//   (cd <repo root> && python3 -m http.server 8098 &)   then   node delivery/tests/agents/ui_admin_controls.js
const { chromium } = require('playwright');
const HOST = process.env.ETEL_HOST || 'http://127.0.0.1:8098'; const SHOTS = process.env.ETEL_SHOTS || '/tmp';
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const AG = [
  { id: 1, name: 'Abebe Kebede', venue: 'Lake Hotel', zone: 'Piassa', phone: '0911000001', status: 'approved', tier: 'silver', risk: 5, riders30: 12, earned30: 640, earned_today: 60, payable: 300, payout_hold: 0, hold_reason: '', daily_cap_etb: 0, note: '' },
  { id: 2, name: 'Birtukan Alemu', venue: 'Bus Station', zone: 'Tabor', phone: '0911000002', status: 'approved', tier: 'bronze', risk: 20, riders30: 0, earned30: 0, earned_today: 0, payable: 0, payout_hold: 0, hold_reason: '', daily_cap_etb: 0, note: '' },
  { id: 3, name: 'Chala Bekele', venue: 'Taxi Park', zone: 'Mehal', phone: '0911000003', status: 'suspended', tier: 'bronze', risk: 70, riders30: 3, earned30: 150, earned_today: 0, payable: 150, payout_hold: 1, hold_reason: 'Duplicate riders', daily_cap_etb: 250, note: 'Called on Monday' }];
(async () => {
  const b = await chromium.launch();
  for (const mode of [{ n: 'desktop', w: 1360, h: 900, th: 'light' }, { n: 'mobile', w: 390, h: 844, th: 'dark' }]) {
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
    await ctx.addInitScript(th => { window.ETEL_CAPS = ['*']; try { localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_user', JSON.stringify({ id: 1, name: 'Test Admin', role: 'admin', phone: '0900000000' })); localStorage.setItem('theme', th); } catch (e) {} }, mode.th);
    const p = await ctx.newPage(); const errs = []; const saves = []; let list = JSON.parse(JSON.stringify(AG));
    p.on('pageerror', e => { if (!/admin-home\.js/.test(e.stack || '')) errs.push(e.message); });   // the Home page script chokes on this mock's empty answers: not part of this tab
    await p.route('**/api/boot.php**', r => {
      let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} const a = d.action || new URL(r.request().url()).searchParams.get('action'); let data = {};
      if (a === 'admin_get_my_capabilities') data = { capabilities: ['*'], role: 'admin' };
      else if (a === 'admin_fa_controls_list') data = { agents: list, programme_daily_cap: 100 };
      else if (a === 'admin_fa_controls_save') { saves.push(d); list.forEach(x => { if (d.ids.indexOf(x.id) >= 0) { if ('payout_hold' in d) { x.payout_hold = d.payout_hold; x.hold_reason = d.hold_reason || ''; } if ('daily_cap_etb' in d) x.daily_cap_etb = Number(d.daily_cap_etb) || 0; if ('note' in d) x.note = d.note; } }); data = { changed: d.ids.length }; }
      else if (a === 'admin_fa_stats') data = { agents_active: 2, agents_pending: 0, repeat_rate_30d: 10, owed_payable: 450, in_hold: 0, flagged: 0, high_risk: 0, cost_per_rider: 10, programme_enabled: 1, budget: { budget: 0, spent: 0 } };
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
    });
    await p.goto(HOST + '/delivery/admin.html', { waitUntil: 'load' }); await p.waitForTimeout(1500);
    await p.evaluate(() => { const n = document.querySelector('.nav-item[data-page="agents"]'); n.classList.remove('etel-cap-hidden'); switchPage('agents', n); }); await p.waitForTimeout(1500);
    t(mode.n + ': Controls tab present', (await p.$('[data-ag-tab="controls"]')) !== null);
    await p.click('[data-ag-tab="controls"]'); await p.waitForTimeout(1200);
    let txt = await p.innerText('#ag-screen');
    t(mode.n + ': three agents listed', /Abebe Kebede/.test(txt) && /Birtukan Alemu/.test(txt) && /Chala Bekele/.test(txt));
    t(mode.n + ': hold and own limit badges shown', /On payout hold/i.test(txt) && /Own daily limit/i.test(txt));
    t(mode.n + ': summary counts one on hold', (await p.locator('.ak-kpi b').first().innerText()) === '3');
    await p.fill('#ctl-q', 'lake'); await p.waitForTimeout(300); txt = await p.innerText('#ag-screen');
    t(mode.n + ': search narrows to Abebe', /Abebe/.test(txt) && !/Birtukan/.test(txt) && !/Chala/.test(txt));
    t(mode.n + ': search keeps focus', await p.evaluate(() => document.activeElement && document.activeElement.id === 'ctl-q'));
    await p.fill('#ctl-q', ''); await p.click('[data-ctl-filter="hold"]'); await p.waitForTimeout(200); txt = await p.innerText('#ag-screen');
    t(mode.n + ': hold filter shows only Chala', /Chala/.test(txt) && !/Abebe/.test(txt));
    await p.click('[data-ctl-filter="dormant"]'); await p.waitForTimeout(200); txt = await p.innerText('#ag-screen');
    t(mode.n + ': dormant filter shows only Birtukan (approved, no riders)', /Birtukan/.test(txt) && !/Abebe/.test(txt) && !/Chala/.test(txt));
    await p.click('[data-ctl-filter="all"]');
    // open + save a hold without a reason: blocked in the browser, nothing sent
    await p.click('[data-ctl-open="1"]'); await p.waitForTimeout(500);
    await p.evaluate(() => { const c = document.querySelector('#ctl-hold'); c.checked = true; });
    await p.click('[data-ctl-save]'); await p.waitForTimeout(300);
    t(mode.n + ': hold without a reason is not sent', saves.length === 0);
    await p.fill('#ctl-reason', 'Checking riders'); await p.fill('#ctl-cap', '200'); await p.fill('#ctl-note', 'Phone call needed');
    await p.click('[data-ctl-save]'); await p.waitForTimeout(900);
    t(mode.n + ': one save sent with hold, reason, limit and note', saves.length === 1 && saves[0].payout_hold === 1 && saves[0].hold_reason === 'Checking riders' && saves[0].daily_cap_etb === '200' && saves[0].note === 'Phone call needed', JSON.stringify(saves[0]));
    txt = await p.innerText('#ag-screen');
    t(mode.n + ': list refreshed, Abebe now on hold with limit 200', (txt.match(/On payout hold/ig) || []).length >= 2 && /200/.test(txt));
    // bulk release
    await p.check('[data-ctl-pick="1"]'); await p.check('[data-ctl-pick="3"]'); await p.waitForTimeout(200);
    t(mode.n + ': bulk bar shows 2 selected', /Selected: 2/.test(await p.innerText('.ag-ctl-sel')));
    await p.click('[data-ctl-bulk="release"]'); await p.waitForTimeout(300); await p.click('.ak-modal [data-yes]'); await p.waitForTimeout(900);
    t(mode.n + ': bulk release sent for agents 1 and 3', saves.length === 2 && saves[1].ids.join() === '1,3' && saves[1].payout_hold === 0, JSON.stringify(saves[1]));
    // bulk hold needs a reason
    await p.check('[data-ctl-pick="2"]'); await p.click('[data-ctl-bulk="hold"]'); await p.waitForTimeout(300); await p.click('.ak-modal [data-yes]'); await p.waitForTimeout(300);
    t(mode.n + ': bulk hold without a reason is refused by the dialog', saves.length === 2 && (await p.$('.ak-modal')) !== null);
    await p.click('.ak-modal [data-no]');
    const overflow = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth); t(mode.n + ': no sideways page scroll', overflow <= 1, String(overflow));
    await p.screenshot({ path: SHOTS + '/ctl-' + mode.n + '.png' });
    // language
    await p.click('[data-ag-lang]'); await p.waitForTimeout(700); t(mode.n + ': Amharic label for Controls', /መቆጣጠሪያዎች/.test(await p.innerText('#panel-agents'))); await p.click('[data-ag-lang]');
    const bad = errs.filter(e => !/favicon|manifest|Failed to load resource|Google Maps/.test(e)); t(mode.n + ': no JS errors', bad.length === 0, bad.join(' | '));
    await ctx.close();
  }
  await b.close(); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); fails.forEach(f => console.log(' - ' + f)); process.exit(fails.length ? 1 : 0);
})();

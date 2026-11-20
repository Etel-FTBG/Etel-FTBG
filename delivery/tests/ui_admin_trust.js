// Admin > Operations > Driver trust. Needs Playwright (node). No database: the API is a mock.
//   (cd <repo root> && python3 -m http.server 8098 &)   then   node delivery/tests/ui_admin_trust.js
const { chromium } = require('playwright');
const HOST = process.env.ETEL_HOST || 'http://127.0.0.1:8098'; const SHOTS = process.env.ETEL_SHOTS || '/tmp';
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const drivers = () => [
  { user_id: 1, name: 'Abel Tesfaye', phone: '0911000001', online: 1, docs: { insurance: { label: 'insurance', expiry: '2026-11-17', days_left: -3, state: 'expired' } } },
  { user_id: 7, name: 'Meron Alemu', phone: '0911000007', online: 0, docs: { insurance: { label: 'insurance', expiry: '2026-11-21', days_left: 1, state: 'soon' }, road_fund: { label: 'road fund', expiry: '2026-11-19', days_left: -1, state: 'expired' } } },
  { user_id: 3, name: 'Dawit Bekele', phone: '0911000003', online: 0, docs: { technical_inspection: { label: 'technical inspection', expiry: '2026-11-20', days_left: 0, state: 'today' } } }];
(async () => {
  const b = await chromium.launch();
  for (const mode of [{ n: 'desktop', w: 1360, h: 900, th: 'light' }, { n: 'mobile', w: 390, h: 844, th: 'dark' }]) {
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
    await ctx.addInitScript(th => { window.ETEL_CAPS = ['*']; try { localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_user', JSON.stringify({ id: 1, name: 'Owner', role: 'admin', phone: '0900000000' })); localStorage.setItem('theme', th); } catch (e) {} }, mode.th);
    const p = await ctx.newPage(); const errs = []; const saves = []; let block = false; let empty = false; let refuse = ''; let gpsBlock = false; const clears = []; let gpsList = [{ user_id: 1, name: 'Abel Tesfaye', phone: '0911000001', online: 1, mocks: 2, jumps: 1, last_at: '2026-11-20 09:30:00', last_kind: 'mock', last_detail: 'the phone reported a mock location' }, { user_id: 7, name: 'Meron Alemu', phone: '0911000007', online: 0, mocks: 0, jumps: 3, last_at: '2026-11-19 17:00:00', last_kind: 'jump', last_detail: 'moved 3 km in 20 s (540 km/h)' }];
    p.on('pageerror', e => { if (!/admin-home\.js/.test(e.stack || '')) errs.push(e.message); });
    await p.route('**/api/boot.php**', r => {
      let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} const a = d.action; let data = {}; let success = true; let message = '';
      if (a === 'admin_get_my_capabilities') data = { capabilities: ['*'], role: 'admin' };
      else if (a === 'admin_driver_trust') data = { generated_at: '2026-11-20 10:00:00', documents: { block_expired: block, columns_ready: true, drivers: empty ? [] : drivers(), expired: empty ? 0 : 2, soon: empty ? 0 : 1 }, gps: { block_fake: gpsBlock, block_hours: 6, drivers: empty ? [] : gpsList } };
      else if (a === 'admin_hub_settings_save') { saves.push(d.settings); if (refuse) { success = false; message = refuse; } else { if ('trust_block_expired_docs' in d.settings) block = d.settings.trust_block_expired_docs === '1'; if ('trust_block_fake_gps' in d.settings) gpsBlock = d.settings.trust_block_fake_gps === '1'; data = { saved: true }; } }
      else if (a === 'admin_gps_clear') { clears.push(d.driver_user_id); gpsList = gpsList.filter(x => x.user_id !== d.driver_user_id); data = { cleared: 3 }; }
      r.fulfill({ contentType: 'application/json', body: JSON.stringify(success ? { success: true, data } : { success: false, message }) });
    });
    await p.goto(HOST + '/delivery/admin.html', { waitUntil: 'load' }); await p.waitForTimeout(1500);
    t(mode.n + ': the menu has Driver trust in Operations', (await p.locator('.nav-item[data-page="trust"]').count()) === 1);
    await p.evaluate(() => { const n = document.querySelector('.nav-item[data-page="trust"]'); if (n) n.classList.remove('etel-cap-hidden'); switchPage('trust', n); }); await p.waitForTimeout(1500);
    let txt = await p.innerText('#panel-trust');
    t(mode.n + ': the counts are shown', /2/.test(await p.locator('.tr-tile.hot .n').first().innerText()) && /Drivers with an expired document/.test(txt) && /expiring in 14 days/.test(txt));
    t(mode.n + ': each driver is listed with name, document, state and date', /Abel Tesfaye/.test(txt) && /insurance: expired 3 days ago \(2026-11-17\)/.test(txt) && /road fund: expired 1 day ago/.test(txt) && /insurance: expires in 1 day/.test(txt) && /technical inspection: expires today/.test(txt), txt.slice(0, 500));
    t(mode.n + ': an online driver is marked and each has a call link', /online now/.test(txt) && (await p.locator('a[href="tel:0911000001"]').count()) >= 1);
    t(mode.n + ': the block switch starts off', !(await p.locator('[data-tr-block]').isChecked()));
    // turn on: confirm first
    await p.check('[data-tr-block]'); await p.waitForTimeout(500);
    t(mode.n + ': turning it ON asks first and sends nothing yet', saves.length === 0 && /Block drivers with an expired document\?/.test(await p.innerText('body')));
    await p.getByRole('button', { name: 'Cancel', exact: true }).last().click(); await p.waitForTimeout(500);
    t(mode.n + ': cancelling leaves it off', saves.length === 0 && !(await p.locator('[data-tr-block]').isChecked()));
    await p.check('[data-tr-block]'); await p.waitForTimeout(400); await p.getByRole('button', { name: 'Turn it on' }).last().click(); await p.waitForTimeout(1000);
    t(mode.n + ': after confirming it is saved and shows ON', saves.length === 1 && saves[0].trust_block_expired_docs === '1' && (await p.locator('[data-tr-block]').isChecked()), JSON.stringify(saves));
    await p.uncheck('[data-tr-block]'); await p.waitForTimeout(900);
    t(mode.n + ': turning it OFF is saved without a question', saves.length === 2 && saves[1].trust_block_expired_docs === '0' && !(await p.locator('[data-tr-block]').isChecked()));
    // refusal (not the owner)
    refuse = 'Owner only'; await p.check('[data-tr-block]'); await p.waitForTimeout(400); await p.getByRole('button', { name: 'Turn it on' }).last().click(); await p.waitForTimeout(1000);
    t(mode.n + ': a refusal (not the owner) leaves the switch off', !(await p.locator('[data-tr-block]').isChecked()) && saves.length === 3);
    refuse = '';
    // fake GPS
    txt = await p.innerText('#panel-trust');
    t(mode.n + ': the Fake GPS card shows the count and each flagged driver with counts, last sighting and detail', /Fake GPS/.test(txt) && /Flagged drivers/.test(txt) && /mock location x2/.test(txt) && /position jump x1/.test(txt) && /position jump x3/.test(txt) && /moved 3 km in 20 s/.test(txt) && (await p.locator('.tr-tile.hot .n').last().innerText()) === '2');
    t(mode.n + ': the gps switch starts off', !(await p.locator('[data-tr-gps]').isChecked()));
    const before = saves.length; await p.check('[data-tr-gps]'); await p.waitForTimeout(500);
    t(mode.n + ': turning it ON asks first and sends nothing yet', saves.length === before && /Put drivers offline when a mock location is reported\?/.test(await p.innerText('body')));
    await p.getByRole('button', { name: 'Turn it on' }).last().click(); await p.waitForTimeout(1000);
    t(mode.n + ': after confirming it is saved (only the gps key) and shows ON', saves.length === before + 1 && saves[before].trust_block_fake_gps === '1' && !('trust_block_expired_docs' in saves[before]) && (await p.locator('[data-tr-gps]').isChecked()), JSON.stringify(saves[before]));
    await p.uncheck('[data-tr-gps]'); await p.waitForTimeout(900);
    t(mode.n + ': turning it OFF is saved without a question', saves.length === before + 2 && saves[before + 1].trust_block_fake_gps === '0' && !(await p.locator('[data-tr-gps]').isChecked()));
    await p.locator('[data-tr-clear="1"]').click(); await p.waitForTimeout(500);
    t(mode.n + ': Clear asks first and removes nothing yet', clears.length === 0 && /Clear the flags of Abel Tesfaye\?/.test(await p.innerText('body')));
    await p.getByRole('button', { name: 'Cancel', exact: true }).last().click(); await p.waitForTimeout(400);
    t(mode.n + ': cancelling keeps the driver in the list', clears.length === 0 && (await p.locator('[data-tr-clear="1"]').count()) === 1);
    await p.locator('[data-tr-clear="1"]').click(); await p.waitForTimeout(400); await p.getByRole('button', { name: 'Clear', exact: true }).last().click(); await p.waitForTimeout(1000);
    t(mode.n + ': confirming clears that driver and the list updates', clears.length === 1 && clears[0] === 1 && (await p.locator('[data-tr-clear="1"]').count()) === 0 && (await p.locator('[data-tr-clear="7"]').count()) === 1);
    // empty
    empty = true; await p.click('[data-tr-refresh]'); await p.waitForTimeout(800);
    t(mode.n + ': with nobody to renew: a calm message', /No driver has a document that is expired/.test(await p.innerText('#panel-trust')));
    const overflow = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth); t(mode.n + ': no sideways page scroll', overflow <= 1, String(overflow));
    empty = false; await p.click('[data-tr-refresh]'); await p.waitForTimeout(800);
    await p.screenshot({ path: SHOTS + '/etel-trust-' + mode.n + '.png' });
    const bad = errs.filter(e => !/favicon|manifest|Failed to load resource|Google Maps/.test(e)); t(mode.n + ': no JS errors', bad.length === 0, bad.join(' | '));
    await ctx.close();
  }
  await b.close(); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); fails.forEach(f => console.log(' - ' + f)); process.exit(fails.length ? 1 : 0);
})();

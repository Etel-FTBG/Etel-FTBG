// Admin > ETEL app. Needs Playwright (node). No database: the API is a mock.
//   (cd <repo root> && python3 -m http.server 8098 &)   then   node delivery/tests/ui_admin_etel_app.js
const { chromium } = require('playwright');
const HOST = process.env.ETEL_HOST || 'http://127.0.0.1:8098'; const SHOTS = process.env.ETEL_SHOTS || '/tmp';
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const cat = [['id', 'EtelID', 'Identity', 'on', 0, 'Everyone'], ['ride', 'ETEL Ride', 'Mobility', 'on', 0, 'Everyone'], ['agent', 'ETEL Agent', 'My programmes', 'on', 0, 'Approved agents only'],
  ['admin', 'ETEL Admin', 'Staff', 'off', 0, 'Admin accounts only'], ['pay', 'EtelPay', 'Finance and trade', 'off', 1, 'Everyone']];
(async () => {
  const b = await chromium.launch();
  for (const mode of [{ n: 'desktop', w: 1360, h: 900, th: 'light', caps: ['*'] }, { n: 'mobile', w: 390, h: 844, th: 'dark', caps: ['*'] }, { n: 'notowner', w: 1360, h: 900, th: 'light', caps: ['orders.view'] }]) {
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
    await ctx.addInitScript(([th, caps]) => { window.ETEL_CAPS = caps; try { localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_user', JSON.stringify({ id: 1, name: 'Owner', role: 'admin', phone: '0900000000' })); localStorage.setItem('theme', th); } catch (e) {} }, [mode.th, mode.caps]);
    const p = await ctx.newPage(); const errs = []; const saves = [];
    let apps = cat.map(c => ({ key: c[0], title: c[1], group: c[2], default_mode: c[3], mode: c[3], url: '', needs_url: c[4], who: c[5], changed: 0, default_path: c[4] ? '' : '/x/' }));
    p.on('pageerror', e => { if (!/admin-home\.js/.test(e.stack || '')) errs.push(e.message); });
    await p.route('**/api/boot.php**', r => {
      let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} const a = d.action; let data = {}; let success = true, message = '';
      if (a === 'admin_get_my_capabilities') data = { capabilities: mode.caps, role: 'admin' };
      else if (a === 'admin_hub_apps_get') data = { apps, host: 'ethelmarket.com' };
      else if (a === 'admin_hub_apps_save') {
        saves.push(d); const x = apps.find(y => y.key === d.key);
        if (x.needs_url && d.mode !== 'off' && !d.url) { success = false; message = 'Type the address of this app first.'; } else { x.mode = d.mode; x.url = d.url || ''; x.changed = 1; data = { saved: true }; } }
      r.fulfill({ contentType: 'application/json', body: JSON.stringify(success ? { success: true, data } : { success: false, message, error: message }) });
    });
    await p.goto(HOST + '/delivery/admin.html', { waitUntil: 'load' }); await p.waitForTimeout(1500);
    await p.evaluate(() => { const n = document.querySelector('.nav-item[data-page="etelapp"]'); if (n) n.classList.remove('etel-cap-hidden'); switchPage('etelapp', n); }); await p.waitForTimeout(1500);
    let txt = await p.innerText('#panel-etelapp');
    t(mode.n + ': nav item exists', (await p.$('.nav-item[data-page="etelapp"]')) !== null);
    if (mode.n === 'notowner') { t('notowner: refused with a plain message', /Only the owner/.test(txt)); t('notowner: no switches shown', (await p.$('.ha-card')) === null); await ctx.close(); continue; }
    t(mode.n + ': all 5 apps shown in groups', (await p.locator('.ha-card').count()) === 5 && /Identity/i.test(txt) && /Finance and trade/i.test(txt));
    t(mode.n + ': defaults shown (Ride visible, Admin hidden)', (await p.locator('.ha-card[data-key=ride] .ha-pill').innerText()) === 'Visible' && (await p.locator('.ha-card[data-key=admin] .ha-pill').innerText()) === 'Hidden');
    t(mode.n + ': only the app with no address shows an address box', (await p.locator('.ha-card[data-key=pay] [data-url]').count()) === 1 && (await p.locator('.ha-card[data-key=ride] [data-url]').count()) === 0);
    t(mode.n + ': Save is disabled until something changes', await p.locator('.ha-card[data-key=agent] [data-save]').isDisabled());
    // hide Agent
    await p.click('.ha-card[data-key=agent] [data-mode=off]'); await p.waitForTimeout(200);
    t(mode.n + ': Save enabled after a change', !(await p.locator('.ha-card[data-key=agent] [data-save]').isDisabled()));
    await p.click('.ha-card[data-key=agent] [data-save]'); await p.waitForTimeout(800);
    t(mode.n + ': hide saved with the right request', saves.length === 1 && saves[0].key === 'agent' && saves[0].mode === 'off', JSON.stringify(saves));
    t(mode.n + ': card now says Hidden', (await p.locator('.ha-card[data-key=agent] .ha-pill').innerText()) === 'Hidden');
    // admin: admins only
    await p.click('.ha-card[data-key=admin] [data-mode=admins]'); await p.click('.ha-card[data-key=admin] [data-save]'); await p.waitForTimeout(800);
    t(mode.n + ': Admin app set to admins only (no confirm needed)', saves.length === 2 && saves[1].mode === 'admins' && (await p.locator('.ha-card[data-key=admin] .ha-pill').innerText()) === 'Admins only');
    // pay without an address: server refuses, message shown
    await p.click('.ha-card[data-key=pay] [data-mode=admins]'); await p.click('.ha-card[data-key=pay] [data-save]'); await p.waitForTimeout(800);
    t(mode.n + ': EtelPay without an address is refused and still Hidden', saves.length === 3 && (await p.locator('.ha-card[data-key=pay] .ha-pill').innerText()) === 'Hidden');
    // pay with an address, Visible -> confirm dialog first
    await p.fill('.ha-card[data-key=pay] [data-url]', 'https://ethelmarket.com/Etel.Pay/wallet/'); await p.click('.ha-card[data-key=pay] [data-mode=on]');
    await p.waitForTimeout(150); const val = await p.inputValue('.ha-card[data-key=pay] [data-url]');
    t(mode.n + ': typed address kept after choosing a mode', val === 'https://ethelmarket.com/Etel.Pay/wallet/', val);
    await p.click('.ha-card[data-key=pay] [data-save]'); await p.waitForTimeout(400);
    t(mode.n + ': turning an app on for everyone asks first', (await p.$('.ak-modal')) !== null && saves.length === 3);
    await p.click('.ak-modal [data-no]'); await p.waitForTimeout(200); t(mode.n + ': cancel sends nothing', saves.length === 3);
    await p.click('.ha-card[data-key=pay] [data-save]'); await p.waitForTimeout(300); await p.click('.ak-modal [data-yes]'); await p.waitForTimeout(800);
    t(mode.n + ': confirmed: saved Visible with the address', saves.length === 4 && saves[3].mode === 'on' && saves[3].url === 'https://ethelmarket.com/Etel.Pay/wallet/' && (await p.locator('.ha-card[data-key=pay] .ha-pill').innerText()) === 'Visible', JSON.stringify(saves[3]));
    const overflow = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth); t(mode.n + ': no sideways page scroll', overflow <= 1, String(overflow));
    await p.screenshot({ path: SHOTS + '/etelapp-' + mode.n + '.png' });
    const bad = errs.filter(e => !/favicon|manifest|Failed to load resource|Google Maps/.test(e)); t(mode.n + ': no JS errors', bad.length === 0, bad.join(' | '));
    await ctx.close();
  }
  await b.close(); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); fails.forEach(f => console.log(' - ' + f)); process.exit(fails.length ? 1 : 0);
})();

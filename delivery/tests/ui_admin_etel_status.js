// Admin > ETEL app > Maintenance and updates. Needs Playwright (node). No database: the API is a mock.
//   (cd <repo root> && python3 -m http.server 8098 &)   then   node delivery/tests/ui_admin_etel_status.js
const { chromium } = require('playwright');
const HOST = process.env.ETEL_HOST || 'http://127.0.0.1:8098'; const SHOTS = process.env.ETEL_SHOTS || '/tmp';
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
(async () => {
  const b = await chromium.launch();
  for (const mode of [{ n: 'desktop', w: 1360, h: 900, th: 'light' }, { n: 'mobile', w: 390, h: 844, th: 'dark' }]) {
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
    await ctx.addInitScript(th => { window.ETEL_CAPS = ['*']; try { localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_user', JSON.stringify({ id: 1, name: 'Owner', role: 'admin', phone: '0900000000' })); localStorage.setItem('theme', th); } catch (e) {} }, mode.th);
    const p = await ctx.newPage(); const errs = []; const saves = []; let settings = {}; let refuse = '';
    p.on('pageerror', e => { if (!/admin-home\.js/.test(e.stack || '')) errs.push(e.message); });
    await p.route('**/api/boot.php**', r => {
      let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} const a = d.action; let data = {}; let success = true; let message = '';
      if (a === 'admin_get_my_capabilities') data = { capabilities: ['*'], role: 'admin' };
      else if (a === 'admin_hub_apps_get') data = { apps: [{ key: 'ride', title: 'ETEL Ride', group: 'Mobility', default_mode: 'on', mode: 'on', needs_url: 0, kind: 'app', url: '', default_url: '' }], host: 'ethelmarket.com' };
      else if (a === 'admin_hub_content_get') data = { content: {}, settings, host: 'ethelmarket.com' };
      else if (a === 'admin_hub_settings_save') { saves.push(d.settings); if (refuse) { success = false; message = refuse; } else { Object.keys(d.settings).forEach(k => { if (d.settings[k] === '') delete settings[k]; else settings[k] = d.settings[k]; }); data = { saved: true }; } }
      r.fulfill({ contentType: 'application/json', body: JSON.stringify(success ? { success: true, data } : { success: false, message }) });
    });
    await p.goto(HOST + '/delivery/admin.html', { waitUntil: 'load' }); await p.waitForTimeout(1500);
    await p.evaluate(() => { const n = document.querySelector('.nav-item[data-page="etelapp"]'); if (n) n.classList.remove('etel-cap-hidden'); switchPage('etelapp', n); }); await p.waitForTimeout(1500);
    await p.click('[data-ha-tab="status"]'); await p.waitForTimeout(1200);
    let txt = await p.innerText('#panel-etelapp');
    t(mode.n + ': both cards are shown, everything off', (await p.locator('[data-hs-group]').count()) === 2 && /Maintenance mode/i.test(txt) && /Minimum app version/i.test(txt) && /Not set/i.test(txt));
    t(mode.n + ': Save buttons are disabled until something changes', (await p.locator('[data-hs-save=maint]').isDisabled()) && (await p.locator('[data-hs-save=upd]').isDisabled()));
    // maintenance
    await p.check('[data-hs-f=maint_enabled]'); await p.waitForTimeout(300);
    t(mode.n + ': the switch shows ON right away and Save is enabled', /ON - phones are blocked/.test(await p.innerText('[data-hs-group=maint]')) && !(await p.locator('[data-hs-save=maint]').isDisabled()));
    await p.fill('[data-hs-f=maint_text_en]', 'Back at 3 PM'); t(mode.n + ': typed text is kept', (await p.inputValue('[data-hs-f=maint_text_en]')) === 'Back at 3 PM');
    await p.click('[data-hs-save=maint]'); await p.waitForTimeout(500);
    t(mode.n + ': turning it ON asks to confirm and sends nothing yet', saves.length === 0);
    await p.getByRole('button', { name: 'Turn it on' }).last().click(); await p.waitForTimeout(900);
    t(mode.n + ': after confirming, only the changed settings are sent', saves.length === 1 && saves[0].maint_enabled === '1' && saves[0].maint_text_en === 'Back at 3 PM' && !('min_version_android' in saves[0]), JSON.stringify(saves));
    t(mode.n + ': it stays ON after reload and Save is off', /ON - phones are blocked/.test(await p.innerText('[data-hs-group=maint]')) && (await p.locator('[data-hs-save=maint]').isDisabled()));
    // turning off needs no confirmation
    await p.uncheck('[data-hs-f=maint_enabled]'); await p.click('[data-hs-save=maint]'); await p.waitForTimeout(800);
    t(mode.n + ': turning it OFF is sent without a question', saves.length === 2 && saves[1].maint_enabled === '0', JSON.stringify(saves[1]));
    // minimum version
    await p.fill('[data-hs-f=min_version_android]', '1.8.0'); await p.fill('[data-hs-f=update_url_android]', 'https://play.google.com/store/apps/details?id=com.etelgroup.driver'); await p.fill('[data-hs-f=update_text_en]', 'Please update');
    t(mode.n + ': version Save enabled and maintenance Save untouched', !(await p.locator('[data-hs-save=upd]').isDisabled()) && (await p.locator('[data-hs-save=maint]').isDisabled()));
    await p.click('[data-hs-save=upd]'); await p.waitForTimeout(500);
    t(mode.n + ': the minimum asks to confirm and sends nothing yet', saves.length === 2);
    await p.getByRole('button', { name: 'Save the minimum' }).last().click(); await p.waitForTimeout(900);
    t(mode.n + ': the minimum, address and text were sent', saves.length === 3 && saves[2].min_version_android === '1.8.0' && /play\.google\.com/.test(saves[2].update_url_android) && saves[2].update_text_en === 'Please update', JSON.stringify(saves[2]));
    t(mode.n + ': the card now shows the minimum', /Minimum 1\.8\.0/.test(await p.innerText('[data-hs-group=upd]')));
    // server refuses
    refuse = 'The version must look like 1.7.0 (numbers and dots).';
    await p.fill('[data-hs-f=update_text_en]', 'Please update now'); await p.click('[data-hs-save=upd]'); await p.waitForTimeout(900);
    t(mode.n + ': a refusal keeps the typing and the Save button', (await p.inputValue('[data-hs-f=update_text_en]')) === 'Please update now' && !(await p.locator('[data-hs-save=upd]').isDisabled()));
    const overflow = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth); t(mode.n + ': no sideways page scroll', overflow <= 1, String(overflow));
    await p.screenshot({ path: SHOTS + '/etel-status-' + mode.n + '.png' });
    const bad = errs.filter(e => !/favicon|manifest|Failed to load resource|Google Maps/.test(e)); t(mode.n + ': no JS errors', bad.length === 0, bad.join(' | '));
    await ctx.close();
  }
  await b.close(); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); fails.forEach(f => console.log(' - ' + f)); process.exit(fails.length ? 1 : 0);
})();

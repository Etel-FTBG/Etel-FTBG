// Admin > ETEL app > Names, icons and texts. Needs Playwright (node). No database: the API is a mock.
//   (cd <repo root> && python3 -m http.server 8098 &)   then   node delivery/tests/ui_admin_etel_look.js
const { chromium } = require('playwright');
const HOST = process.env.ETEL_HOST || 'http://127.0.0.1:8098'; const SHOTS = process.env.ETEL_SHOTS || '/tmp';
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
(async () => {
  const b = await chromium.launch();
  for (const mode of [{ n: 'desktop', w: 1360, h: 900, th: 'light' }, { n: 'mobile', w: 390, h: 844, th: 'dark' }]) {
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
    await ctx.addInitScript(th => { window.ETEL_CAPS = ['*']; try { localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_user', JSON.stringify({ id: 1, name: 'Owner', role: 'admin', phone: '0900000000' })); localStorage.setItem('theme', th); } catch (e) {} }, mode.th);
    const p = await ctx.newPage(); const errs = []; const appSaves = []; const setSaves = []; const uploads = [];
    const apps = [['ride', 'ETEL Ride', 'Mobility'], ['driver', 'ETEL Driver', 'Mobility'], ['light', 'Floating golden light', 'Features']].map(c => ({ key: c[0], title: c[1], group: c[2], default_mode: 'on', mode: 'on', url: '', needs_url: 0, who: 'Everyone', kind: c[2] === 'Features' ? 'feature' : 'app', changed: 0, default_path: '/x/' }));
    let content = {}; let settings = {};
    p.on('pageerror', e => { if (!/admin-home\.js/.test(e.stack || '')) errs.push(e.message); });
    await p.route('**/api/upload.php', r => { uploads.push(r.request().postData() ? r.request().postData().length : 0); r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { url: 'https://ethelmarket.com/delivery/uploads/hub/icon_1_1_ab.png' } }) }); });
    await p.route('**/uploads/hub/**', r => r.fulfill({ contentType: 'image/png', body: PNG }));
    await p.route('**/api/boot.php**', r => {
      let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} const a = d.action; let data = {}; let success = true; let message = '';
      if (a === 'admin_get_my_capabilities') data = { capabilities: ['*'], role: 'admin' };
      else if (a === 'admin_hub_apps_get') data = { apps, host: 'ethelmarket.com' };
      else if (a === 'admin_hub_content_get') data = { content, settings, host: 'ethelmarket.com' };
      else if (a === 'admin_hub_content_save') { appSaves.push(d); const k = d.key; content[k] = Object.assign({ title_en: '', title_am: '', desc_en: '', desc_am: '', badge: '', sort_order: 0, icon_url: '' }, content[k] || {}); Object.keys(d).forEach(f => { if (f !== 'action' && f !== 'key') content[k][f] = f === 'sort_order' ? Number(d[f]) : d[f]; }); data = { saved: true }; }
      else if (a === 'admin_hub_settings_save') { setSaves.push(d.settings); Object.keys(d.settings).forEach(k => { if (d.settings[k] === '') delete settings[k]; else settings[k] = d.settings[k]; }); data = { saved: true }; }
      r.fulfill({ contentType: 'application/json', body: JSON.stringify(success ? { success: true, data } : { success: false, message }) });
    });
    await p.goto(HOST + '/delivery/admin.html', { waitUntil: 'load' }); await p.waitForTimeout(1500);
    await p.evaluate(() => { const n = document.querySelector('.nav-item[data-page="etelapp"]'); if (n) n.classList.remove('etel-cap-hidden'); switchPage('etelapp', n); }); await p.waitForTimeout(1500);
    t(mode.n + ': three tabs', (await p.locator('[data-ha-tab]').count()) === 3);
    await p.click('[data-ha-tab="look"]'); await p.waitForTimeout(1200);
    let txt = await p.innerText('#panel-etelapp');
    t(mode.n + ': the look tab lists the apps but not the feature switches', (await p.locator('.hl-card[data-hl-key]').count()) === 2 && !/Floating golden light/.test(txt));
    t(mode.n + ': home texts and banner sections are there', /Home screen texts/i.test(txt) && /Home banner/i.test(txt));
    t(mode.n + ': Save is disabled until something changes', await p.locator('.hl-card[data-hl-key=ride] [data-hl-save]').isDisabled());
    // typing keeps focus and updates the preview
    await p.fill('.hl-card[data-hl-key=ride] [data-hl-f=title_en]', 'ETEL Ride Hawassa'); await p.fill('.hl-card[data-hl-key=ride] [data-hl-f=badge]', 'New');
    t(mode.n + ': the preview follows what is typed', /ETEL Ride Hawassa/.test(await p.innerText('.hl-card[data-hl-key=ride] .hl-prev')) && /new/i.test(await p.innerText('.hl-card[data-hl-key=ride] .hl-prev')));
    t(mode.n + ': Save is enabled after typing', !(await p.locator('.hl-card[data-hl-key=ride] [data-hl-save]').isDisabled()));
    // upload an icon
    await p.setInputFiles('.hl-card[data-hl-key=ride] [data-hl-file=icon]', { name: 'icon.png', mimeType: 'image/png', buffer: PNG }); await p.waitForTimeout(700);
    t(mode.n + ': the icon was sent to the upload endpoint', uploads.length === 1);
    t(mode.n + ': the icon shows in the preview', (await p.locator('.hl-card[data-hl-key=ride] .hl-ico img').count()) === 1);
    t(mode.n + ': typed text survived the picture upload', (await p.inputValue('.hl-card[data-hl-key=ride] [data-hl-f=title_en]')) === 'ETEL Ride Hawassa');
    await p.click('.hl-card[data-hl-key=ride] [data-hl-save]'); await p.waitForTimeout(900);
    const s0 = appSaves[0] || {};
    t(mode.n + ': only the changed fields were saved', s0.key === 'ride' && s0.title_en === 'ETEL Ride Hawassa' && s0.badge === 'New' && /uploads\/hub\/icon_/.test(s0.icon_url || '') && !('desc_en' in s0) && !('title_am' in s0), JSON.stringify(s0));
    t(mode.n + ': after saving the button is disabled again', await p.locator('.hl-card[data-hl-key=ride] [data-hl-save]').isDisabled());
    // remove icon
    await p.click('.hl-card[data-hl-key=ride] [data-hl-clear=icon]'); await p.click('.hl-card[data-hl-key=ride] [data-hl-save]'); await p.waitForTimeout(800);
    t(mode.n + ': the icon can be removed', appSaves.length === 2 && appSaves[1].icon_url === '', JSON.stringify(appSaves[1]));
    // home texts
    await p.fill('.hl-card[data-hl-sets=home] [data-hl-s=support_phone]', '+251911234567'); await p.fill('.hl-card[data-hl-sets=home] [data-hl-s=tagline_am]', 'አንድ መለያ');
    await p.click('.hl-card[data-hl-sets=home] [data-hl-saveset]'); await p.waitForTimeout(800);
    t(mode.n + ': home texts saved (only the changed ones)', setSaves.length === 1 && setSaves[0].support_phone === '+251911234567' && setSaves[0].tagline_am === 'አንድ መለያ' && !('support_email' in setSaves[0]), JSON.stringify(setSaves[0]));
    // banner
    await p.check('.hl-card[data-hl-sets=banner] [data-hl-flag]'); await p.fill('.hl-card[data-hl-sets=banner] [data-hl-s=banner_title_en]', 'Peak hours'); await p.selectOption('.hl-card[data-hl-sets=banner] [data-hl-s=banner_audience]', 'driver');
    await p.selectOption('.hl-card[data-hl-sets=banner] [data-hl-s=banner_link_app]', 'driver');
    t(mode.n + ': the banner preview follows', /Peak hours/.test(await p.innerText('.hl-card[data-hl-sets=banner] .hl-banner')));
    await p.click('.hl-card[data-hl-sets=banner] [data-hl-saveset]'); await p.waitForTimeout(800);
    const bs = setSaves[1] || {};
    t(mode.n + ': banner saved with audience and link', bs.banner_enabled === '1' && bs.banner_title_en === 'Peak hours' && bs.banner_audience === 'driver' && bs.banner_link_app === 'driver', JSON.stringify(bs));
    const overflow = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth); t(mode.n + ': no sideways page scroll', overflow <= 1, String(overflow));
    await p.screenshot({ path: SHOTS + '/etel-look-' + mode.n + '.png' });
    // back to the switches
    await p.click('[data-ha-tab="apps"]'); await p.waitForTimeout(300); t(mode.n + ': the switches tab still works', (await p.locator('.ha-card').count()) === 3);
    const bad = errs.filter(e => !/favicon|manifest|Failed to load resource|Google Maps/.test(e)); t(mode.n + ': no JS errors', bad.length === 0, bad.join(' | '));
    await ctx.close();
  }
  await b.close(); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); fails.forEach(f => console.log(' - ' + f)); process.exit(fails.length ? 1 : 0);
})();

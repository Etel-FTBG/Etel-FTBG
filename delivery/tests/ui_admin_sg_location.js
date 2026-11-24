// Admin > Service Givers > a shop's drawer: the go-live checklist item "Address and map pin" has its own editor (js/admin-sg-pro.js), so it does not
// depend on the old Manage window. Real admin.html, mocked API.   node delivery/tests/ui_admin_sg_location.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
const srv = http.createServer((q, r) => { let p = path.join(ROOT, decodeURIComponent(q.url.split('?')[0])); if (!p.startsWith(ROOT) || !fs.existsSync(p) || fs.statSync(p).isDirectory()) { r.statusCode = 404; return r.end(); } r.setHeader('Content-Type', MIME[path.extname(p)] || 'application/octet-stream'); r.end(fs.readFileSync(p)); });
(async () => {
  await new Promise(r => srv.listen(0, r)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  for (const mode of [{ n: 'desktop', w: 1300, h: 900 }, { n: 'phone', w: 390, h: 844 }]) {
    let SG = { id: 14, business_name: 'Admin test shop (demo)', service_giver_code: 'SG-9F6373', status: 'verified', is_online: 0, opens_at: '06:00:00', closes_at: '22:00:00', address: '', lat: null, lng: null, name: 'Etel Admin', phone: '0924445206', created_at: '2026-10-29 21:22:00', average_rating: 0, rating_count: 0, service_type: 'restaurant', zone: 'Mehal Ketema' };
    const calls = []; let FAIL_SAVE = false;
    const readiness = () => { const ok = !!(SG.address && SG.lat && SG.lng); return { done: ok ? 7 : 6, total: 8, items: [
      { key: 'logo', label: 'Logo', ok: true, hint: '' }, { key: 'cover', label: 'Cover photo', ok: true, hint: '' }, { key: 'hours', label: 'Opening hours', ok: true, hint: '' },
      { key: 'location', label: 'Address and map pin', ok: ok, hint: 'Couriers and customers need to find the business.' }, { key: 'owner', label: "Owner's own phone number", ok: true, hint: '' },
      { key: 'payout', label: 'Payout details', ok: true, hint: '' }, { key: 'documents', label: 'Papers approved', ok: false, hint: 'The owner hands in a photo of each.' }, { key: 'menu', label: 'Menu (at least 3 items)', ok: true, hint: '' }] }; };
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block', geolocation: { latitude: 7.0512, longitude: 38.485 }, permissions: ['geolocation'] });
    await ctx.addInitScript(() => { window.ETEL_CAPS = ['*']; try { localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_user', JSON.stringify({ id: 1, name: 'Owner', role: 'admin' })); } catch (e) {} });
    const p = await ctx.newPage(); const errs = [];
    p.on('pageerror', e => { if (!/admin-home\.js/.test(e.stack || '')) errs.push(e.message); });
    await p.route('**/api/boot.php**', r => { let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} const a = d.action; let data = {}, ok = true, msg = '';
      if (a === 'admin_get_my_capabilities') data = { capabilities: ['*'], role: 'admin' };
      else if (a === 'admin_service_givers_board') data = { service_givers: [SG], summary: { total: 1 } };
      else if (a === 'admin_service_giver_detail') data = { service_giver: SG, stats: { orders: 0 }, readiness: readiness(), claim: {}, documents: [], doc_types: {}, recent: [] };
      else if (a === 'sg_update_profile') { calls.push(d); if (FAIL_SAVE) { ok = false; msg = 'Not allowed.'; } else { SG = Object.assign({}, SG, { address: d.address, lat: d.lat, lng: d.lng }); data = { message: 'Saved' }; } }
      r.fulfill({ contentType: 'application/json', body: JSON.stringify(ok ? { success: true, data } : { success: false, message: msg }) }); });
    await p.goto(HOST + '/delivery/admin.html', { waitUntil: 'load' }); await p.waitForTimeout(2200);
    const n = mode.n;
    await p.evaluate(() => { const nv = document.querySelector('.nav-item[data-page="servicegivers"]'); switchPage('servicegivers', nv); }); await p.waitForTimeout(700);
    await p.evaluate(() => window.etelOpenServiceGiver(14)); await p.waitForTimeout(900);
    const dr = '.ak-dr';
    t(n + ': the drawer opens with the go-live checklist', /Address and map pin/.test(await p.innerText(dr)));
    t(n + ': the address and pin row has its own editor (no "Open profile" button for it)', (await p.locator(dr + ' #gl-addr').count()) === 1 && (await p.locator(dr + ' #gl-lat').count()) === 1 && (await p.locator(dr + ' #gl-lng').count()) === 1
      && (await p.locator(dr + ' .gl-row', { hasText: 'Address and map pin' }).locator('[data-gl="full"]').count()) === 0);
    await p.locator(dr + ' [data-gl="loc"]').click(); await p.waitForTimeout(200);
    t(n + ': saving with nothing typed says what to write', /Write the address first/.test(await p.innerText(dr + ' [data-gl-note]')) && calls.length === 0);
    await p.fill(dr + ' #gl-addr', 'Piassa, near the market'); await p.locator(dr + ' [data-gl="loc"]').click(); await p.waitForTimeout(200);
    t(n + ': an address without a pin says so and sends nothing', /map pin as two numbers/.test(await p.innerText(dr + ' [data-gl-note]')) && calls.length === 0);
    await p.fill(dr + ' #gl-lat', '95'); await p.fill(dr + ' #gl-lng', '38.4'); await p.locator(dr + ' [data-gl="loc"]').click(); await p.waitForTimeout(200);
    t(n + ': a latitude out of range is refused before sending', /map pin as two numbers/.test(await p.innerText(dr + ' [data-gl-note]')) && calls.length === 0);
    await p.locator(dr + ' [data-gl="here"]').click(); await p.waitForTimeout(500);
    t(n + ': "Use my location" fills the two numbers', (await p.inputValue(dr + ' #gl-lat')) === '7.051200' && (await p.inputValue(dr + ' #gl-lng')) === '38.485000');
    FAIL_SAVE = true; await p.locator(dr + ' [data-gl="loc"]').click(); await p.waitForTimeout(500);
    t(n + ': a refusal from the server is shown in words and what was typed stays', /Not allowed/.test(await p.innerText(dr + ' [data-gl-note]')) && (await p.inputValue(dr + ' #gl-addr')) === 'Piassa, near the market');
    FAIL_SAVE = false; await p.locator(dr + ' [data-gl="loc"]').click(); await p.waitForTimeout(900);
    const sent = calls[calls.length - 1] || {};
    t(n + ': saving sends the business id, the address and the pin', sent.sg_id === 14 && sent.address === 'Piassa, near the market' && Math.abs(sent.lat - 7.0512) < 1e-9 && Math.abs(sent.lng - 38.485) < 1e-9, JSON.stringify(sent));
    t(n + ': the checklist then shows it done (the editor is gone, 7 of 8)', (await p.locator(dr + ' #gl-addr').count()) === 0 && /7 OF 8/i.test(await p.innerText(dr)), (await p.innerText(dr)).slice(0, 300));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/sg_location_' + n + '.png' });
    t(n + ': no page errors', errs.length === 0, errs.join('|')); await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

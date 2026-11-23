// Admin workspaces (js/admin-workspaces.js): Delivery / Ride / Company switch, per-workspace menu, Home cards, All pages finder. Real admin.html, mocked API.
// Needs Playwright (node).   node delivery/tests/ui_admin_workspaces.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
const SHOTS = process.env.ETEL_SHOTS || '/tmp';
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
const srv = http.createServer((q, r) => { let p = path.join(ROOT, decodeURIComponent(q.url.split('?')[0])); if (!p.startsWith(ROOT) || !fs.existsSync(p) || fs.statSync(p).isDirectory()) { r.statusCode = 404; return r.end(''); } r.setHeader('Content-Type', MIME[path.extname(p)] || 'application/octet-stream'); r.end(fs.readFileSync(p)); });
const SPD = { n: 0, typical: 0, slow: 0 };
const SP = { rides: { requests: 0, taken: 0, taken_pct: 0, gave_up: 0, completed: 0, secs_to_take: SPD }, orders: { placed: 0, taken: 0, taken_pct: 0, delivered: 0, cancelled: 0, secs_to_take: SPD, secs_to_deliver: SPD } };
const SNAP = { generated_at: '2026-11-23 10:00:00', has_order_assign_time: true, alerts: [], speed: { today: SP, week: SP }, now: { rides: { searching: 2, on_the_way: 3, in_progress: 4, oldest_wait_min: 0, waiting: [] }, drivers: { online: 9, busy: 7, free: 2 }, orders: { waiting: 5, assigned: 2, on_the_way: 3, oldest_wait_min: 0, waiting_list: [] }, couriers: { online: 6, free: 1 }, sos: { open: 1, oldest_min: 4 } } };
const vis = (p, sel) => p.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; }, sel);
async function open(b, w, h, caps) {
  const ctx = await b.newContext({ viewport: { width: w, height: h }, serviceWorkers: 'block' });
  await ctx.addInitScript((c) => { window.ETEL_CAPS = c; try { localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_user', JSON.stringify({ id: 1, name: 'Owner', role: 'admin' })); } catch (e) {} }, caps);
  const p = await ctx.newPage(); const errs = [];
  p.on('pageerror', e => { if (!/admin-home\.js/.test(e.stack || '')) errs.push(e.message); });
  await p.route('**/api/boot.php**', r => { let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} let data = {};
    if (d.action === 'admin_get_my_capabilities') data = { capabilities: caps, role: 'admin' }; else if (d.action === 'admin_live_ops') data = SNAP;
    r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) }); });
  await p.goto(HOST + '/delivery/admin.html', { waitUntil: 'load' }); await p.waitForTimeout(2600);
  return { ctx, p, errs };
}
let HOST = '';
(async () => {
  await new Promise(r => srv.listen(0, r)); HOST = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();
  for (const mode of [{ n: 'desktop', w: 1300, h: 900 }, { n: 'mobile', w: 390, h: 844 }]) {
    const { ctx, p, errs } = await open(b, mode.w, mode.h, ['*']);
    const n = mode.n;
    t(n + ': the switch has Delivery, Ride and Company', (await p.locator('#aws-switch .aws-chip').count()) === 3 && await vis(p, '#aws-switch .aws-chip[data-ws="ride"]'));
    t(n + ': it lands on the Home of a workspace (Delivery) with big cards, not a long menu', /Delivery home/.test(await p.innerText('#page-title')) && (await p.locator('#panel-wshome .aws-tile').count()) >= 10, await p.innerText('#page-title'));
    t(n + ': the Home cards are really on screen (not just in the page)', await vis(p, '#panel-wshome .aws-tile'));
    t(n + ': Delivery home shows live numbers and a hot badge for orders waiting', /Orders waiting/i.test(await p.innerText('#panel-wshome')) && (await p.locator('#panel-wshome .aws-tile[data-go="dispatch"] .bd.hot').count()) === 1);
    t(n + ': Delivery home has no ride pages', (await p.locator('#panel-wshome .aws-tile[data-go="rides"]').count()) === 0 && (await p.locator('#panel-wshome .aws-tile[data-go="couriers"]').count()) === 1);
    if (mode.n === 'desktop') {
      t(n + ': the menu shows only Delivery pages', await vis(p, '.sidebar .nav-item[data-page="couriers"]') && !(await vis(p, '.sidebar .nav-item[data-page="rides"]')) && !(await vis(p, '.sidebar .nav-item[data-page="settings"]')));
    } else {
      t(n + ': the long strip of tabs is gone on a phone', !(await vis(p, '.sidebar .nav-item[data-page="couriers"]')) && !(await vis(p, '.sidebar .nav-item[data-page="orders"]')));
    }
    await p.screenshot({ path: SHOTS + '/ws_delivery_' + n + '.png' });
    await p.locator('#aws-switch .aws-chip[data-ws="ride"]').click(); await p.waitForTimeout(500);
    t(n + ': tapping Ride opens Ride home with only ride pages and ride numbers', /Ride home/.test(await p.innerText('#page-title')) && (await p.locator('#panel-wshome .aws-tile[data-go="rides"]').count()) === 1 && (await p.locator('#panel-wshome .aws-tile[data-go="orders"]').count()) === 0 && /Requests waiting/i.test(await p.innerText('#panel-wshome')) && (await p.locator('#panel-wshome .aws-tile[data-go="liveops"] .bd.hot').count()) === 1);
    if (mode.n === 'desktop') t(n + ': the menu now shows only Ride pages', await vis(p, '.sidebar .nav-item[data-page="rides"]') && !(await vis(p, '.sidebar .nav-item[data-page="couriers"]')));
    await p.screenshot({ path: SHOTS + '/ws_ride_' + n + '.png' });
    await p.locator('#panel-wshome .aws-tile[data-go="rides"]').click(); await p.waitForTimeout(400);
    t(n + ': a card opens its page and the title above it names the workspace', /Rides/.test(await p.innerText('#page-title')) && /Ride/i.test(await p.innerText('#aws-eyebrow')), await p.innerText('#aws-eyebrow'));
    await p.evaluate(() => switchPage('couriers', document.querySelector('.sidebar .nav-item[data-page="couriers"]'))); await p.waitForTimeout(300);
    t(n + ': opening a Delivery page from anywhere moves to the Delivery workspace', await p.evaluate(() => AdminWS.current()) === 'delivery' && /Delivery/i.test(await p.innerText('#aws-eyebrow')));
    await p.locator('#aws-switch [data-aws-pages]').click(); await p.waitForTimeout(300);
    t(n + ': All pages lists every workspace', (await p.locator('.aws-ov .aws-grp').count()) === 3 && (await p.locator('.aws-ov .aws-row').count()) >= 25);
    await p.fill('.aws-ov input', 'live'); await p.waitForTimeout(200);
    t(n + ': typing finds a page across workspaces (Live operations, with its workspace)', /Live operations/.test(await p.innerText('.aws-ov .aws-list')) && (await p.locator('.aws-ov .aws-row').count()) <= 3, await p.innerText('.aws-ov .aws-list'));
    await p.keyboard.press('Enter'); await p.waitForTimeout(400);
    t(n + ': Enter opens the first match and the finder closes', (await p.locator('.aws-ov').count()) === 0 && await p.evaluate(() => AdminWS.current()) === 'ride' && /Live operations/i.test(await p.innerText('#page-title')), await p.innerText('#page-title'));
    await p.locator('#aws-switch .aws-chip[data-ws="company"]').click(); await p.waitForTimeout(400);
    t(n + ': Company has the shared pages (users, settings, dashboard)', (await p.locator('#panel-wshome .aws-tile[data-go="users"]').count()) === 1 && (await p.locator('#panel-wshome .aws-tile[data-go="settings"]').count()) === 1 && (await p.locator('#panel-wshome .aws-tile[data-go="dashboard"]').count()) === 1);
    t(n + ': no page errors', errs.length === 0, errs.join('|')); await ctx.close();
  }
  // staff who may only see rides: only the Ride workspace is offered and it opens by itself
  { const { ctx, p, errs } = await open(b, 1300, 900, ['rides.view', 'rides.manage']);
    t('ride-only staff: Delivery chip is hidden, Ride is open', !(await vis(p, '#aws-switch .aws-chip[data-ws="delivery"]')) && await p.evaluate(() => AdminWS.current()) === 'ride' && /Ride home/.test(await p.innerText('#page-title')), await p.innerText('#page-title'));
    t('ride-only staff: no delivery tile anywhere', (await p.locator('#panel-wshome .aws-tile[data-go="orders"]').count()) === 0);
    t('ride-only staff: no page errors', errs.length === 0, errs.join('|')); await ctx.close(); }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

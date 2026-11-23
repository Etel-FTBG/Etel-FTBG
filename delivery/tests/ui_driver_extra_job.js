// Driver app profile: the "Extra job: deliveries" card (js/driver-extra-job.js, the real file). Needs Playwright (node). No server: the API is a mock.
//   node delivery/tests/ui_driver_extra_job.js
const { chromium } = require('playwright'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const SRC = fs.readFileSync(path.join(__dirname, '../js/driver-extra-job.js'), 'utf8');
const PAGE = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div id="page-profile"><div class="profile-body"><div id="first">stats</div></div></div><script>' + SRC + '</scr' + 'ipt></body></html>';
(async () => {
  const b = await chromium.launch();
  const run = async (status, after) => {
    const ctx = await b.newContext({ viewport: { width: 390, height: 800 } }); const p = await ctx.newPage(); const errs = []; let applied = 0; let cur = status;
    p.on('pageerror', e => errs.push(e.message));
    await ctx.addInitScript(() => { localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 5, role: 'driver' })); });
    await p.route('http://etel.test/', r => r.fulfill({ contentType: 'text/html', body: PAGE }));
    await p.route('http://etel.test/api/boot.php', r => { const d = JSON.parse(r.request().postData() || '{}'); let data = {};
      if (d.action === 'driver_courier_status') data = cur; else if (d.action === 'driver_courier_apply') { applied++; cur = after; data = { message: 'Sent.', status: after }; }
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) }); });
    await p.goto('http://etel.test/'); await p.evaluate(() => { window.ETEL = { api: 'http://etel.test/api/boot.php' }; }); await p.waitForTimeout(2300);
    return { p, ctx, errs, applied: () => applied };
  };
  const base = { role: 'driver', is_ride_driver: true, onboarded: true, note: '', courier_code: '', vehicle: 'bajaj' };
  let r = await run(Object.assign({}, base, { state: 'none', can_apply: true }), Object.assign({}, base, { state: 'pending', can_apply: false }));
  let txt = await r.p.innerText('#drv-xj').catch(() => '');
  t('a verified driver sees the card first in the profile, with the ask button', /Extra job: deliveries/.test(txt) && /Ask for the courier job/.test(txt) && await r.p.evaluate(() => document.querySelector('.profile-body').firstChild.id === 'drv-xj'), txt);
  await r.p.click('#drv-xj [data-xj="apply"]'); await r.p.waitForTimeout(500);
  txt = await r.p.innerText('#drv-xj');
  t('asking sends the request and the card says waiting for the admin (no ask button)', r.applied() === 1 && /Waiting for the admin/.test(txt) && !(await r.p.locator('#drv-xj [data-xj]').count()), txt);
  t('no page errors', r.errs.length === 0, r.errs.join('|')); await r.p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/driver_extra_job.png' }); await r.ctx.close();
  r = await run(Object.assign({}, base, { state: 'none', can_apply: false, onboarded: false }), null);
  txt = await r.p.innerText('#drv-xj');
  t('a driver who is not verified yet is told to finish verification, with no button', /Finish your driver verification/.test(txt) && !(await r.p.locator('#drv-xj [data-xj]').count()), txt); await r.ctx.close();
  r = await run(Object.assign({}, base, { state: 'rejected', can_apply: true, note: 'Need a clearer plate photo' }), null);
  txt = await r.p.innerText('#drv-xj');
  t('a "no" shows the reason and lets the driver ask again', /did not approve/.test(txt) && /clearer plate photo/.test(txt) && /Ask again/.test(txt), txt); await r.ctx.close();
  r = await run(Object.assign({}, base, { state: 'approved', role: 'courier', can_apply: false }), null);
  txt = await r.p.innerText('#drv-xj');
  t('approved: says so, links to Courier, and the stored role becomes courier', /also do deliveries/.test(txt) && (await r.p.locator('#drv-xj a[href="../courier/"]').count()) === 1 && await r.p.evaluate(() => JSON.parse(localStorage.getItem('etel_user')).role === 'courier'), txt); await r.ctx.close();
  r = await run(Object.assign({}, base, { state: 'none', can_apply: false, is_ride_driver: false, role: 'customer' }), null);
  t('someone who is not a ride driver sees no card', (await r.p.locator('#drv-xj').count()) === 0); await r.ctx.close();
  await b.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

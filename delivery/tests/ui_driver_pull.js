// Driver app: the Pull tab and special offers (js/driver-pull.js, the real file). Needs Playwright (node). No server: the API is a mock.
//   node delivery/tests/ui_driver_pull.js
const { chromium } = require('playwright'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const SRC = fs.readFileSync(path.join(__dirname, '../js/driver-pull.js'), 'utf8');
const PAGE = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body data-page="requests">' +
  '<div class="sidebar-nav"><button class="sidebar-item nav-tab" data-page="profile">Profile</button></div>' +
  '<div class="drv-main"><div class="drv-page active" id="page-requests">Requests</div><div class="drv-page" id="page-money">Money</div><div class="drv-page" id="page-profile">Profile</div></div>' +
  '<nav class="driver-bottom-nav"><button class="nav-tab active" data-page="requests"><span class="nl">Requests</span></button><button class="nav-tab" data-page="profile"><span class="nl">Profile</span></button></nav>' +
  '<script>window.__accepted=[];window.switchPage=function(p){document.body.setAttribute("data-page",p);document.querySelectorAll(".drv-page").forEach(function(x){x.classList.remove("active")});document.querySelectorAll(".nav-tab").forEach(function(x){x.classList.toggle("active",x.getAttribute("data-page")===p)});var g=document.getElementById("page-"+p);if(g)g.classList.add("active");};window.acceptRide=function(id){window.__accepted.push(id)};</scr' + 'ipt><script>' + SRC + '</scr' + 'ipt></body></html>';
const ROW = (id, fare, tags, from, wait) => ({ id, pickup_address: 'Piassa gate ' + id, dropoff_address: 'University ' + id, trip_km: 4.2, fare, rider_offer: 0, from_driver_km: from, eta_min: Math.round(from / 25 * 60) + 1, waiting_s: wait, passenger_name: 'Abel', tags });
(async () => {
  const b = await chromium.launch();
  const run = async (data, vp) => {
    const ctx = await b.newContext({ viewport: vp || { width: 390, height: 800 } }); const p = await ctx.newPage(); const errs = []; let calls = 0;
    p.on('pageerror', e => errs.push(e.message));
    await ctx.addInitScript(() => { localStorage.setItem('etel_token', 'T'); });
    await p.route('http://etel.test/', r => r.fulfill({ contentType: 'text/html', body: PAGE }));
    await p.route('http://etel.test/api/boot.php', r => { calls++; r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) }); });
    await p.goto('http://etel.test/'); await p.evaluate(() => { window.ETEL = { api: 'http://etel.test/api/boot.php' }; }); await p.waitForTimeout(2100);
    return { p, ctx, errs, calls: () => calls };
  };
  const D = { no_position: false, settings: {}, offers: [ROW(7, 620, [{ kind: 'vip', label: 'High paying trip', note: 'ETB 620' }, { kind: 'sweet', label: 'Sweet area: Piassa', note: 'Many calls here' }], 6.1, 4)], pull: [ROW(8, 180, [], 3.4, 95)] };
  let r = await run(D);
  t('a Pull tab is added to the bottom bar (before Profile) and the side menu', (await r.p.locator('.driver-bottom-nav .nav-tab[data-page="pull"]').count()) === 1 && (await r.p.locator('.sidebar-nav .nav-tab[data-page="pull"]').count()) === 1 && await r.p.evaluate(() => document.querySelector('.driver-bottom-nav .nav-tab[data-page="pull"]').nextElementSibling.getAttribute('data-page') === 'profile'));
  t('it shows how many trips are waiting on the tab', (await r.p.innerText('.driver-bottom-nav .pl-bd')) === '2');
  await r.p.click('.driver-bottom-nav .nav-tab[data-page="pull"]'); await r.p.waitForTimeout(600);
  const txt = await r.p.innerText('#page-pull');
  t('opening it shows special offers first and the far waiting trip after', /Special offers \(1\)/i.test(txt) && /Waiting far from you \(1\)/i.test(txt) && txt.toLowerCase().indexOf('special offers') < txt.toLowerCase().indexOf('waiting far'), txt.slice(0, 200));
  t('an offer shows its tags, the staff note and every trip detail', /High paying trip/.test(txt) && /Sweet area: Piassa/.test(txt) && /Many calls here/.test(txt) && /Piassa gate 7/.test(txt) && /University 7/.test(txt) && /ETB 620/.test(txt) && /Trip 4.2 km/.test(txt) && /From you 6.1 km/.test(txt) && /Abel/.test(txt), txt);
  t('a waiting trip shows how long it has waited (95 s = 2 min)', /Waiting 2 min/.test(txt), txt);
  await r.p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/driver_pull.png' });
  await r.p.click('#page-pull [data-take="8"]'); await r.p.waitForTimeout(900);
  t('taking a trip uses the normal accept and goes back to Requests', await r.p.evaluate(() => JSON.stringify(window.__accepted) === '[8]' && document.body.getAttribute('data-page') === 'requests'));
  t('no page errors', r.errs.length === 0, r.errs.join('|')); await r.ctx.close();
  r = await run({ no_position: false, settings: {}, offers: [], pull: [] });
  await r.p.click('.driver-bottom-nav .nav-tab[data-page="pull"]'); await r.p.waitForTimeout(500);
  t('nothing waiting: a friendly empty message and no number on the tab', /Nothing to pull right now/.test(await r.p.innerText('#page-pull')) && (await r.p.locator('.pl-bd').count()) === 0); await r.ctx.close();
  r = await run({ no_position: true, settings: {}, offers: [], pull: [] });
  await r.p.click('.driver-bottom-nav .nav-tab[data-page="pull"]'); await r.p.waitForTimeout(500);
  t('no known position: asks to turn on the location and go online', /Turn on your location/.test(await r.p.innerText('#page-pull'))); await r.ctx.close();
  r = await run(D);
  await r.p.addStyleTag({ content: 'h2,h3,p,span,div{color:#fff3c4 !important;-webkit-text-fill-color:transparent !important}' });
  await r.p.click('.driver-bottom-nav .nav-tab[data-page="pull"]'); await r.p.waitForTimeout(500);
  const fill = await r.p.evaluate(() => getComputedStyle(document.querySelector('#page-pull h2')).webkitTextFillColor);
  t('a page that paints text see-through does not hide the Pull text', fill !== 'rgba(0, 0, 0, 0)', fill); await r.ctx.close();
  await b.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

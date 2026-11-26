/* UI test: the "Fit my budget" box on the Incentive planner page (mock page, no server, no login).
   Run: NODE_PATH=/opt/node22/lib/node_modules node EtelGroup/investors/tests/incentive_fit_ui.js [screenshot.png] */
'use strict';
var path = require('path'), fs = require('fs');
var pw = require('playwright');
var js = path.join(__dirname, '..', 'js');
var shot = process.argv[2] || '';
var fails = 0;
function ok(c, m) { if (!c) { fails++; console.log('FAIL: ' + m); } else console.log('ok: ' + m); }
var html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
  '<style>body{font:14px sans-serif;background:#0d1b3d;color:#eee;padding:12px}input,select{width:100%;box-sizing:border-box}</style></head><body>' +
  '<div id="irx-nav"><a data-page="incentives" href="#incentives">Incentive planner</a></div>' +
  '<div class="ir-card"><div id="ip-fields"></div><button id="ip-reset" type="button">Reset</button><div id="ip-output"></div></div>' +
  '<script>window.irMoney=function(n){return "ETB "+Math.round(n).toLocaleString("en-US")};window.irEsc=function(s){return String(s)};</script>' +
  '<script src="file://' + js + '/admin-incentives.js"></script><script src="file://' + js + '/admin-incentives-fit.js"></script></body></html>';
(async function () {
  var br = await pw.chromium.launch(), errs = [];
  var ctx = await br.newContext({ viewport: { width: 390, height: 900 }, serviceWorkers: 'block' });
  var pg = await ctx.newPage();
  pg.on('pageerror', function (e) { errs.push(String(e)); });
  var tmp = path.join(require('os').tmpdir(), 'ipf_harness.html'); fs.writeFileSync(tmp, html);
  await pg.goto('file://' + tmp);
  ok(await pg.locator('#ipf-box').count() === 0, 'no Fit box before the page is opened');
  await pg.click('#irx-nav a');
  await pg.waitForSelector('#ipf-box');
  ok(await pg.locator('#ip-promo_cap').count() === 1, 'discount cap box exists');
  ok(await pg.inputValue('#ipf-budget') === '150000', 'default budget 150000');
  // Plan A: 100k, 2 months, fare 200, 50 drivers
  await pg.fill('#ipf-budget', '100000'); await pg.fill('#ipf-months', '2'); await pg.fill('#ipf-fare', '200'); await pg.fill('#ipf-drivers', '50');
  await pg.click('#ipf-go');
  var msg = await pg.textContent('#ipf-msg');
  ok(/Plan set below/.test(msg), 'message says the plan is set');
  ok(await pg.inputValue('#ip-agents') === '10', '10 agents set');
  ok(await pg.inputValue('#ip-agent_per_trip') === '30', 'agent fee 30');
  ok(await pg.inputValue('#ip-avg_fare') === '200', 'fare copied into the planner');
  ok(await pg.inputValue('#ipu-promo_discount') === 'pct', 'discount unit is %');
  var out = await pg.textContent('#ip-output');
  ok(/Total spend/.test(out), 'the planner repainted its output');
  var tot = await pg.evaluate(function () { var p = window.irIncentivePlan(); return p.total; });
  ok(tot <= 90001 && tot > 70000, 'whole plan costs between 70k and 90k (got ' + Math.round(tot) + ')');
  var saved = await pg.evaluate(function () { return localStorage.getItem('ir_incentive_plan_v3'); });
  ok(saved && JSON.parse(saved).v.agents === 10, 'the planner saved the new numbers');
  // Plan B and the too-small case
  await pg.fill('#ipf-budget', '200000'); await pg.fill('#ipf-fare', '350'); await pg.click('#ipf-go');
  tot = await pg.evaluate(function () { return window.irIncentivePlan().total; });
  ok(tot <= 180001 && tot > 150000, 'plan B costs between 150k and 180k (got ' + Math.round(tot) + ')');
  ok(await pg.inputValue('#ip-agent_per_trip') === '40', 'agent fee 40 for the bigger budget');
  await pg.fill('#ipf-budget', '5000'); await pg.click('#ipf-go');
  ok(/too small/i.test(await pg.textContent('#ipf-msg')), 'tiny budget gives a clear message');
  ok(await pg.inputValue('#ip-agents') === '10', 'tiny budget leaves the plan as it was');
  // cap lowers the discount cost
  var before = await pg.evaluate(function () { return window.irIncentivePlan().promotion; });
  await pg.fill('#ip-promo_cap', '20'); await pg.dispatchEvent('#ip-promo_cap', 'input');
  var after = await pg.evaluate(function () { return window.irIncentivePlan().promotion; });
  ok(after < before, 'a cap of ETB 20 lowers the discount cost (' + Math.round(before) + ' -> ' + Math.round(after) + ')');
  // no horizontal scroll on a phone
  var sw = await pg.evaluate(function () { return document.documentElement.scrollWidth <= window.innerWidth + 1; });
  ok(sw, 'no sideways scroll at 390 px');
  ok(errs.length === 0, 'no page errors ' + errs.join('|'));
  if (shot) { await pg.fill('#ipf-budget', '100000'); await pg.fill('#ipf-fare', '275'); await pg.click('#ipf-go'); await pg.screenshot({ path: shot, fullPage: true }); }
  await br.close();
  console.log(fails ? fails + ' FAILED' : 'all ok'); process.exit(fails ? 1 : 0);
})().catch(function (e) { console.log('ERROR ' + e); process.exit(1); });

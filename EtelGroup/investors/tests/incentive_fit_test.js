/* Tests for the Incentive planner "Fit my budget" logic and the discount cap.
   Run: node EtelGroup/investors/tests/incentive_fit_test.js   (no browser, no server)
   On the OLD code (no irFitPlan, no promo_cap) this test fails. */
'use strict';
var fs = require('fs'), vm = require('vm'), path = require('path');
var root = path.join(__dirname, '..', 'js');
var fails = 0, n = 0;
function ok(cond, msg) { n++; if (!cond) { fails++; console.log('FAIL: ' + msg); } }

function load() {
  var win = {}, store = {};
  var sb = {
    window: win, document: { getElementById: function () { return null; }, addEventListener: function () {}, createElement: function () { return {}; } },
    location: { hash: '' }, localStorage: { getItem: function (k) { return store[k] || null; }, setItem: function (k, v) { store[k] = v; } },
    setTimeout: function () {}, console: console, Event: function () {}
  };
  sb.self = win; vm.createContext(sb);
  ['admin-incentives.js', 'admin-incentives-fit.js'].forEach(function (f) {
    var src = fs.readFileSync(path.join(root, f), 'utf8');
    vm.runInContext(src, sb, { filename: f });
  });
  return sb.window;
}
var w = load();
ok(typeof w.irFitPlan === 'function', 'irFitPlan exists');
ok(typeof w.irIncentiveCalc === 'function', 'irIncentiveCalc exists');
if (typeof w.irFitPlan !== 'function' || typeof w.irIncentiveCalc !== 'function') { console.log('fit logic missing'); process.exit(1); }

// every budget from 100k to 200k, 1..3 months, fares 200..350, 30..80 drivers: never over 90 % of the budget, and uses most of it
var cases = 0, minUse = 1, maxUse = 0;
[100000, 120000, 150000, 175000, 200000].forEach(function (b) {
  [1, 2, 3].forEach(function (m) {
    [200, 275, 350].forEach(function (f) {
      [30, 50, 80].forEach(function (d) {
        var r = w.irFitPlan({ budget: b, months: m, fare: f, drivers: d });
        cases++;
        ok(r.ok, 'ok for ' + [b, m, f, d]);
        var c = w.irIncentiveCalc(r.s), all = c.total * m;
        ok(all <= b * 0.9 + 1, 'under 90% of budget for ' + [b, m, f, d] + ' got ' + Math.round(all));
        ok(r.s.v.agents >= 10, 'at least 10 agents');
        ok(r.s.v.agent_trips >= 0, 'no negative trips');
        ok(r.s.u.agent_per_trip === 'etb' && r.s.u.promo_discount === 'pct' && r.s.p.agent_trips === 'day', 'units are set');
        if (r.ok && !r.note) { var use = all / b; if (use < minUse) minUse = use; if (use > maxUse) maxUse = use; }
      });
    });
  });
});
ok(minUse > 0.78, 'uses most of the money (lowest use ' + minUse.toFixed(3) + ')');
ok(maxUse <= 0.9001, 'never above 90 % (highest ' + maxUse.toFixed(3) + ')');

// the two plans we told Dagi about (2 months, fare 275, 50 drivers)
var a = w.irFitPlan({ budget: 100000, months: 2, fare: 275, drivers: 50 });
var b2 = w.irFitPlan({ budget: 200000, months: 2, fare: 275, drivers: 50 });
ok(a.fee === 30 && b2.fee === 40, 'agent fee 30 for 100k and 40 for 200k (got ' + a.fee + ', ' + b2.fee + ')');
ok(Math.abs(a.spendAll - 90000) < 4000 && Math.abs(b2.spendAll - 180000) < 6000, 'about 90k and 180k planned (got ' + Math.round(a.spendAll) + ', ' + Math.round(b2.spendAll) + ')');
ok(a.spare >= 10000 && b2.spare >= 20000, 'spare money kept (got ' + Math.round(a.spare) + ', ' + Math.round(b2.spare) + ')');

// too small a budget is refused in words, nothing is set
var tiny = w.irFitPlan({ budget: 5000, months: 2, fare: 275, drivers: 50 });
ok(!tiny.ok && /too small/i.test(tiny.note), 'tiny budget refused');
// no drivers: no bonus, still fits
var nd = w.irFitPlan({ budget: 100000, months: 2, fare: 275, drivers: 0 });
ok(nd.ok && nd.s.v.t1_share === 0 && nd.s.v.t2_share === 0, 'no drivers means no bonus');
ok(w.irIncentiveCalc(nd.s).total * 2 <= 90001, 'no-driver plan still under 90 %');
// nonsense input does not throw
var junk = w.irFitPlan({ budget: NaN, months: -3, fare: 'x', drivers: -5 });
ok(junk.ok === false, 'junk budget refused');

// discount cap: 10 % of ETB 350 = 35, capped at 30; 0 means no limit; the cap never raises a discount
var base = w.irIncentiveDefaults();
base.v.avg_fare = 350; base.v.promo_discount = 10; base.u.promo_discount = 'pct'; base.v.promo_rides = 1;
var c0 = w.irIncentiveCalc(base);
ok(Math.abs(c0.promoPerRider - 35) < 1e-9, 'no cap: ETB 35 per rider (got ' + c0.promoPerRider + ')');
base.v.promo_cap = 30; var c1 = w.irIncentiveCalc(base);
ok(Math.abs(c1.promoPerRider - 30) < 1e-9, 'cap 30: ETB 30 per rider (got ' + c1.promoPerRider + ')');
ok(c1.promoMonthly < c0.promoMonthly, 'cap lowers the monthly discount cost');
base.v.promo_cap = 100; ok(Math.abs(w.irIncentiveCalc(base).promoPerRider - 35) < 1e-9, 'a high cap changes nothing');
base.v.promo_rides = 2; base.v.promo_cap = 30; ok(Math.abs(w.irIncentiveCalc(base).promoPerRider - 60) < 1e-9, 'cap counts per ride (2 rides, cap 30 = 60)');

// the planner defaults are unchanged (cap off): 174,600 a month as told to Dagi on the first screen
var d = w.irIncentiveDefaults(); ok(d.v.promo_cap === 0, 'cap is off by default');
console.log(n + ' checks, ' + cases + ' budget cases, ' + fails + ' failed');
process.exit(fails ? 1 : 0);

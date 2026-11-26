/* ETEL Admin - Package prices: the pricing studio for package deliveries.
   Four tabs: Overview (a price table: every distance against every vehicle, how many couriers are online for each, what the customer
   would see), Vehicles (name, icon, on/off, max weight, which courier vehicles get its jobs, price ladder, own price rule),
   Distance and weight (the bands and the size multipliers, with a live example) and Rules (the late cancel fee).
   Warnings: courier vehicles that match no package vehicle (bajaj, qute...) with a one-click "add it", and vehicles nobody is online for.
   Data: api actions admin_package_rates_get (rates, settings, vehicles = the real vehicle catalog), admin_package_rates_save,
   admin_package_type_add, admin_package_setting_save (api/controllers/PackageController.php).
   Standalone on purpose: it adds its own menu item and page. Look and pieces come from js/admin-kit.js. No inline handlers; server text goes through esc. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;
var RATES = [], CAT = null, TAB = 'overview';
var SETTINGS = { express_on: 1, express_pct: 30, express_min: 20, booking_on: 1, late_cancel_fee: 30, tier_on: 1, d1_km: 5, d1_fee: 100, d2_km: 10, d2_fee: 200, d3_km: 25, d3_fee: 300, beyond_per_km: 15, w1_kg: 5, w1_fee: 0, w2_kg: 10, w2_fee: 50, w3_kg: 20, w3_fee: 100, w4_kg: 50, w4_fee: 200, w_beyond_per_kg: 10, mult_bike: 1, mult_moto: 1, mult_car: 1.5, mult_van: 2.5 };
var RANK = { bike: 1, moto: 2, car: 3, van: 4 };
var CALC = { km: 5, kg: 3 };
var BUILTIN = { bike: 1, moto: 1, car: 1, van: 1 };
var LIKE = [['bike', 'a bicycle'], ['moto', 'a motorbike'], ['car', 'a car'], ['van', 'a van / pickup']];
var RANK_KEY = { 1: 'bike', 2: 'moto', 3: 'car', 4: 'van' };
var NUM = [['base_fee', 'Base fee (ETB)', 'Charged on every package'], ['per_km', 'Per km (ETB)', 'Added for each kilometre'], ['free_kg', 'Free weight (kg)', 'No weight charge up to here'],
           ['per_kg_over', 'Per extra kg (ETB)', 'For every kg above the free weight'], ['min_fee', 'Minimum price (ETB)', 'Never charge less than this'], ['max_weight_kg', 'Maximum weight (kg)', 'Heavier packages are refused']];
var TABS = [['overview', '📊 Overview'], ['vehicles', '🚚 Vehicles'], ['bands', '📏 Distance & weight'], ['rules', '⚙️ Rules']];
var DIST = [1, 3, 5, 10, 15, 25, 40, 60];

function vmult(t, rank) { var k = { 1: 'mult_bike', 2: 'mult_moto', 3: 'mult_car', 4: 'mult_van' }[rank]; var m = k ? Number(t[k]) : 1; return (m >= 0.5 && m <= 10) ? m : 1; }
function panel() { return document.getElementById('panel-packages'); }
function tierPrice(t, km, kg, rank) {
  var dist = km <= t.d1_km ? Number(t.d1_fee) : km <= t.d2_km ? Number(t.d2_fee) : km <= t.d3_km ? Number(t.d3_fee) : Number(t.d3_fee) + Number(t.beyond_per_km) * Math.ceil(km - t.d3_km);
  var wt = kg <= t.w1_kg ? Number(t.w1_fee) : kg <= t.w2_kg ? Number(t.w2_fee) : kg <= t.w3_kg ? Number(t.w3_fee) : kg <= t.w4_kg ? Number(t.w4_fee) : Number(t.w4_fee) + Number(t.w_beyond_per_kg) * Math.ceil(kg - t.w4_kg);
  return Math.round(dist * vmult(t, rank) + wt);
}
function price(r, km, kg) {
  if (Number(SETTINGS.tier_on)) return tierPrice(SETTINGS, km, kg, Number(r.size_rank) || RANK[r.vehicle_class] || 0);
  var fee = Number(r.base_fee) + Number(r.per_km) * km, over = kg - Number(r.free_kg);
  if (over > 0) fee += Number(r.per_kg_over) * over;
  if (fee < Number(r.min_fee)) fee = Number(r.min_fee);
  return Math.round(fee);
}
function isOn(r) { return Number(r.active) === 1 || r.active === true || r.active === '1'; }
function note(c, msg, bad) { var n = c.querySelector('[data-note]'); if (n) { n.textContent = msg; n.style.color = bad ? '#d6364a' : '#1fa463'; } }
function supply(k) {
  var cl = (CAT && CAT.classes) || [], i;
  for (i = 0; i < cl.length; i++) { if (cl[i].class === k) return cl[i]; }
  return { couriers: 0, online: 0 };
}

var cssDone = false;
function css() {
  if (cssDone) return; cssDone = true;
  var s = document.createElement('style');
  s.textContent =
    '.pkp-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px;padding:5px;border-radius:16px;background:var(--soft);border:1px solid var(--line)}' +
    '.pkp-tabs button{flex:1 1 auto;border:0;background:transparent;color:var(--ink);font:inherit;font-weight:700;font-size:13px;padding:9px 12px;border-radius:12px;cursor:pointer;white-space:nowrap}' +
    '.pkp-tabs button.on{background:linear-gradient(135deg,var(--gold),var(--gold2,#c99a1c));color:#1c1500;box-shadow:0 3px 10px rgba(212,175,55,.35)}' +
    '.pkp-alert{display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;padding:11px 13px;border-radius:14px;margin-bottom:10px;font-size:13px;border:1px solid}' +
    '.pkp-alert.bad{background:rgba(214,54,74,.08);border-color:rgba(214,54,74,.4);color:var(--ink)}.pkp-alert.warn{background:rgba(240,180,41,.12);border-color:rgba(240,180,41,.5);color:var(--ink)}' +
    '.pkp-alert .t{flex:1 1 220px}.pkp-alert b{font-weight:800}' +
    '.pkp-mx{width:100%;border-collapse:separate;border-spacing:0;font-size:13px}' +
    '.pkp-mx th,.pkp-mx td{padding:9px 10px;text-align:center;border-top:1px solid var(--line);white-space:nowrap}' +
    '.pkp-mx thead th{border-top:0;background:var(--soft);font-size:12px;vertical-align:bottom}.pkp-mx thead th:first-child{border-radius:12px 0 0 0}.pkp-mx thead th:last-child{border-radius:0 12px 0 0}' +
    '.pkp-mx thead small{display:block;font-weight:500;color:var(--mute);font-size:10.5px}.pkp-mx td:first-child,.pkp-mx th:first-child{text-align:left;font-weight:700}' +
    '.pkp-mx tr.try td{background:rgba(212,175,55,.16)}.pkp-mx td.lo{font-weight:800;color:var(--green)}.pkp-mx td.no{color:var(--mute)}' +
    '.pkp-wrap{overflow-x:auto;border:1.5px solid var(--line);border-radius:16px;background:var(--card);box-shadow:var(--shadow)}' +
    '.pkp-opt{display:flex;gap:12px;align-items:center;padding:11px 12px;border:1.5px solid var(--line);border-radius:14px;background:var(--card);margin-top:8px}' +
    '.pkp-opt .ic{flex:none;width:44px;height:44px;border-radius:13px;background:var(--soft);display:flex;align-items:center;justify-content:center;font-size:24px}' +
    '.pkp-opt .nm{flex:1;min-width:0}.pkp-opt .nm b{display:block;font-size:14px}.pkp-opt .nm small{color:var(--mute);font-size:11.5px}.pkp-opt .pr{font-weight:800;font-size:15px}' +
    '.pkp-vh{cursor:default;margin-bottom:14px}.pkp-vh .stats{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}' +
    '.pkp-vh details{margin-top:12px;border:1px dashed var(--line);border-radius:12px;padding:8px 12px}.pkp-vh summary{cursor:pointer;font-weight:700;font-size:12.5px;color:var(--mute)}' +
    '.pkp-note{font-size:12px;color:var(--mute);margin:6px 0 0}' +
    '.pkp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}' +
    '.pkp-bar{display:grid;grid-template-columns:auto 1fr auto;gap:8px;align-items:center;font-size:13.5px;margin-top:8px;flex-wrap:wrap}';
  document.head.appendChild(s);
}

function likeSelect(cur) {
  return '<select name="size_like" class="ak-in">' + LIKE.map(function (l) { return '<option value="' + l[0] + '"' + (l[0] === cur ? ' selected' : '') + '>' + l[1] + '</option>'; }).join('') + '</select>';
}
function extraFields(r, namesVal) {
  return '<label style="flex:1 1 200px;font-size:12px">Carries about the same as' + likeSelect(r ? RANK_KEY[Number(r.size_rank)] || 'moto' : 'moto') + '</label>' +
    '<label style="flex:2 1 240px;font-size:12px">Couriers with these vehicle words get its jobs<input name="courier_names" class="ak-in" value="' + esc(r ? r.courier_names : (namesVal || '')) + '" maxlength="190" placeholder="for example: bajaj, tuk-tuk"></label>';
}
function numFields(r, def, only) {
  return '<div class="pkp-grid">' + NUM.filter(function (f) { return !only || only.indexOf(f[0]) >= 0; }).map(function (f) {
    return '<label style="font-size:12px" title="' + esc(f[2]) + '">' + esc(f[1]) + '<input name="' + f[0] + '" type="number" inputmode="decimal" min="0" step="any" class="ak-in" value="' + esc(r ? r[f[0]] : def[f[0]]) + '"></label>';
  }).join('') + '</div>';
}
function formRate(f) {
  var o = {};
  NUM.forEach(function (n) { o[n[0]] = f.elements[n[0]].value; });
  o.label = f.elements.label.value; o.icon = f.elements.icon.value; o.active = f.elements.active.checked;
  if (f.elements.size_like) { o.size_like = f.elements.size_like.value; o.courier_names = f.elements.courier_names.value; }
  return o;
}
function nin(name, v, w) { return '<input name="' + name + '" type="number" inputmode="decimal" min="0" max="5000" step="any" value="' + esc(v) + '" class="ak-in" style="width:' + (w || 84) + 'px">'; }

/* ---------- Overview ---------- */
function alerts() {
  var h = '', un = (CAT && CAT.unmapped) || [];
  un.forEach(function (u) {
    var nm = u.name ? u.name.replace(/_/g, ' ') : 'no vehicle';
    if (!u.name) return;
    h += '<div class="pkp-alert bad"><span aria-hidden="true">⚠️</span><div class="t"><b>' + esc(u.couriers) + ' courier' + (u.couriers === 1 ? '' : 's') + ' drive' + (u.couriers === 1 ? 's' : '') + ' a ' + esc(nm) + '</b> but no package vehicle matches it, so they get no package jobs.</div>' +
      '<button type="button" class="ak-btn gold" data-quickadd="' + esc(u.name) + '">＋ Add ' + esc(nm) + ' as a vehicle type</button></div>';
  });
  var none = RATES.filter(function (r) { return isOn(r) && supply(r.vehicle_class).online === 0; });
  if (none.length) h += '<div class="pkp-alert warn"><span aria-hidden="true">🕒</span><div class="t"><b>Nobody is online right now for:</b> ' + none.map(function (r) { return esc(r.label) + ' (' + esc(supply(r.vehicle_class).couriers) + ' registered)'; }).join(', ') + '. Customers can still choose them; the job waits for a courier.</div></div>';
  return h;
}
function matrix() {
  var on = RATES.filter(isOn), km = Number(CALC.km) || 0, kg = Number(CALC.kg) || 0, rows = DIST.slice();
  if (km > 0 && rows.indexOf(km) < 0) { rows.push(km); rows.sort(function (a, b) { return a - b; }); }
  if (!on.length) return K.empty('📦', 'No vehicle is offered', 'Switch a vehicle on in the Vehicles tab.');
  var head = '<tr><th>Distance</th>' + on.map(function (r) { var sp = supply(r.vehicle_class); return '<th><span aria-hidden="true">' + esc(r.icon || '📦') + '</span> ' + esc(r.label) + '<small>' + esc(sp.online) + ' online / ' + esc(sp.couriers) + '</small></th>'; }).join('') + '</tr>';
  var body = rows.map(function (d) {
    var ps = on.map(function (r) { return kg > Number(r.max_weight_kg) ? null : price(r, d, kg); });
    var lo = Math.min.apply(null, ps.filter(function (x) { return x !== null; }));
    return '<tr' + (d === km ? ' class="try"' : '') + '><td>' + esc(d) + ' km' + (d === km ? ' (your try)' : '') + '</td>' + ps.map(function (x) { return x === null ? '<td class="no" title="Too heavy for this vehicle">too heavy</td>' : '<td' + (x === lo ? ' class="lo"' : '') + '>ETB ' + esc(x) + '</td>'; }).join('') + '</tr>';
  }).join('');
  return '<div class="pkp-wrap"><table class="pkp-mx"><thead>' + head + '</thead><tbody>' + body + '</tbody></table></div>';
}
function customerView() {
  var km = Number(CALC.km) || 0, kg = Number(CALC.kg) || 0;
  var opts = RATES.filter(function (r) { return isOn(r) && kg <= Number(r.max_weight_kg); }).map(function (r) { return { r: r, p: price(r, km, kg) }; });
  if (!opts.length) return '<div class="pkp-note">No vehicle can carry ' + esc(kg) + ' kg.</div>';
  opts.sort(function (a, b) { return a.p - b.p; });
  return opts.map(function (o) { var sp = supply(o.r.vehicle_class);
    return '<div class="pkp-opt"><div class="ic">' + esc(o.r.icon || '📦') + '</div><div class="nm"><b>' + esc(o.r.label) + '</b><small>up to ' + esc(o.r.max_weight_kg) + ' kg · ' + (sp.online ? esc(sp.online) + ' courier' + (sp.online === 1 ? '' : 's') + ' online' : 'none online now') + '</small></div><div class="pr">ETB ' + esc(o.p) + '</div></div>'; }).join('');
}
function overview() {
  var tierOn = Number(SETTINGS.tier_on) === 1;
  return alerts() +
    '<div class="ak-card" style="cursor:default;margin-bottom:14px"><div style="font-weight:800;margin-bottom:6px">🧮 Try a package</div>' +
    '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end"><label style="font-size:12px">Distance (km)<input id="pkg-calc-km" type="number" min="1" step="any" value="' + esc(CALC.km) + '" class="ak-in" style="width:130px"></label>' +
    '<label style="font-size:12px">Weight (kg)<input id="pkg-calc-kg" type="number" min="0" step="any" value="' + esc(CALC.kg) + '" class="ak-in" style="width:130px"></label>' +
    '<div class="pkp-note" style="padding-bottom:10px">Pricing mode: <b>' + (tierOn ? 'distance and weight bands' : 'each vehicle\'s own base fee + per km') + '</b>. Change it in the Distance &amp; weight tab.</div></div>' +
    '<div data-cv style="margin-top:12px">' + customerView() + '</div></div>' +
    '<div data-mx>' + matrix() + '</div><p class="pkp-note">The green price is the cheapest for that distance. Prices are what the customer pays, before any promo code.</p>';
}

/* ---------- Vehicles ---------- */
function card(r) {
  var on = isOn(r), sp = supply(r.vehicle_class), cust = !BUILTIN[r.vehicle_class];
  var m = Number(SETTINGS.tier_on) ? vmult(SETTINGS, Number(r.size_rank) || RANK[r.vehicle_class] || 0) : null;
  return '<form data-class="' + esc(r.vehicle_class) + '" class="ak-card pkp-vh">' +
    '<div class="ak-top"><div class="ak-av" style="--a:#E8C14A;--b:#9a7a1c;font-size:22px">' + esc(r.icon || '📦') + '</div>' +
    '<div class="ak-nm"><b>' + esc(r.label) + '</b><small>' + esc(cust ? 'Added by you' : 'Built-in vehicle') + ' · up to ' + esc(r.max_weight_kg) + ' kg</small></div>' +
    '<span data-state>' + K.pill(on ? 'Offered to customers' : 'Switched off', on ? 'green' : 'grey') + '</span></div>' +
    '<div class="stats"><span class="ak-chip">👥 <b>' + esc(sp.online) + '</b> online / ' + esc(sp.couriers) + ' registered</span>' +
    (m !== null ? '<span class="ak-chip">distance price × <b>' + esc(m) + '</b></span>' : '') + '<span class="ak-chip">words: ' + esc(cust ? (r.courier_names || 'none') : 'built in') + '</span></div>' +
    '<div data-ladder class="ak-chips" aria-label="Sample prices"></div>' +
    '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin:12px 0 10px">' +
    '<label style="flex:0 0 74px;font-size:12px">Icon<input name="icon" class="ak-in" value="' + esc(r.icon) + '" maxlength="8"></label>' +
    '<label style="flex:1 1 160px;font-size:12px">Name customers see<input name="label" class="ak-in" value="' + esc(r.label) + '" maxlength="60"></label>' +
    '<label style="flex:0 0 130px;font-size:12px" title="Heavier packages are refused">Maximum weight (kg)<input name="max_weight_kg" type="number" inputmode="decimal" min="0" step="any" class="ak-in" value="' + esc(r.max_weight_kg) + '"></label>' +
    '<label style="font-size:13px;display:flex;gap:6px;align-items:center;padding-bottom:9px;cursor:pointer"><input type="checkbox" name="active"' + (on ? ' checked' : '') + ' style="width:auto;margin:0"> Offer this vehicle</label></div>' +
    (cust ? '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px">' + extraFields(r) + '</div>' : '') +
    '<details><summary>Its own price rule (used only when distance and weight bands are switched off)</summary><div style="margin-top:10px">' + numFields(r, null, ['base_fee', 'per_km', 'free_kg', 'per_kg_over', 'min_fee']) + '</div></details>' +
    '<div class="ak-row" style="margin-top:12px"><span data-preview style="font-size:13px"></span><button type="submit" class="ak-btn gold">Save ' + esc(r.label) + '</button></div>' +
    '<div data-note role="status" style="font-size:12.5px;min-height:18px;margin-top:6px"></div></form>';
}
function vehicles() {
  var h = '<div style="margin-bottom:14px"><button type="button" data-add class="ak-btn gold">＋ Add a vehicle type</button><div data-add-host></div></div>';
  if (!RATES.length) h += K.empty('📦', 'No vehicle prices found', 'Add a vehicle type to start taking package bookings.');
  return h + RATES.map(card).join('');
}

/* ---------- Distance & weight ---------- */
function bands() {
  var t = SETTINGS, on = Number(t.tier_on) === 1;
  return '<form data-tiers class="ak-card" style="cursor:default;margin-bottom:14px"><div style="font-weight:800;font-size:16px;margin-bottom:2px">📍 Delivery price by distance and weight</div>' +
    '<div class="pkp-note" style="margin-bottom:12px">The price is the distance band (multiplied for bigger vehicles) plus the weight band. Customers see it before they book. Changes apply to new bookings at once.</div>' +
    '<label style="display:flex;gap:8px;align-items:center;font-size:13.5px;font-weight:600;margin-bottom:12px"><input type="checkbox" name="tier_on"' + (on ? ' checked' : '') + ' style="width:auto"> Use these prices (switch off to charge each vehicle by its own price rule in the Vehicles tab)</label>' +
    '<div style="font-weight:700;margin:6px 0">Distance</div><div style="display:grid;gap:8px">' +
    [['d1', 'Up to'], ['d2', 'Up to'], ['d3', 'Up to']].map(function (b) { return '<div class="pkp-bar"><span>' + b[1] + ' ' + nin(b[0] + '_km', t[b[0] + '_km']) + ' km</span><span style="opacity:.6">costs</span><span>' + nin(b[0] + '_fee', t[b[0] + '_fee']) + ' ETB</span></div>'; }).join('') +
    '<div class="pkp-bar"><span>Longer: add ' + nin('beyond_per_km', t.beyond_per_km) + ' ETB</span><span></span><span>for every extra km</span></div></div>' +
    '<div style="font-weight:700;margin:14px 0 6px">Vehicle size multiplier (on the distance price)</div><div style="display:flex;gap:12px;flex-wrap:wrap;font-size:13.5px">' + [['mult_bike', 'Bicycle'], ['mult_moto', 'Motorbike'], ['mult_car', 'Car'], ['mult_van', 'Van']].map(function (m) { return '<label style="display:flex;gap:6px;align-items:center">' + m[1] + ' × ' + nin(m[0], t[m[0]], 70) + '</label>'; }).join('') + '</div>' +
    '<div class="pkp-note">A van with 2.5 charges 2.5 times the distance price. The weight price is not multiplied. A vehicle you added uses the multiplier of the vehicle it carries about the same as.</div>' +
    '<div style="font-weight:700;margin:14px 0 6px">Weight (added to the distance price)</div><div style="display:grid;gap:8px">' +
    [['w1', 'Up to'], ['w2', 'Up to'], ['w3', 'Up to'], ['w4', 'Up to']].map(function (b) { return '<div class="pkp-bar"><span>' + b[1] + ' ' + nin(b[0] + '_kg', t[b[0] + '_kg']) + ' kg</span><span style="opacity:.6">adds</span><span>' + nin(b[0] + '_fee', t[b[0] + '_fee']) + ' ETB</span></div>'; }).join('') +
    '<div class="pkp-bar"><span>Heavier: add ' + nin('w_beyond_per_kg', t.w_beyond_per_kg) + ' ETB</span><span></span><span>for every extra kg</span></div></div>' +
    '<div class="pkp-note">A vehicle still refuses what it cannot carry (its maximum weight).</div>' +
    '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px"><button type="submit" class="ak-btn gold">Save prices</button><span style="font-size:12.5px;opacity:.75">Example: 8 km and 12 kg on a motorbike = ETB <b data-tier-eg>' + tierPrice(t, 8, 12, 2) + '</b></span></div>' +
    '<div data-note role="status" style="font-size:12.5px;min-height:18px;margin-top:6px"></div></form>';
}

/* ---------- Rules ---------- */
function speedCard() {
  var t = SETTINGS, num = function (n, v, mx) { return '<input name="' + n + '" type="number" inputmode="decimal" min="0" max="' + mx + '" step="any" value="' + esc(v) + '" class="ak-input" style="width:110px">'; };
  return '<form data-speed="1" class="ak-card" style="cursor:default;margin-bottom:14px">' +
    '<div style="font-weight:800;margin-bottom:2px">⚡ Express delivery and the booking switch</div>' +
    '<div class="pkp-note" style="margin-bottom:10px">With Express on, the customer can choose Express for a higher price (the delivery fee plus the percent below, never less than the smallest extra). The courier sees EXPRESS in the order notes. Pause booking to stop new packages for a while (for example when there are no couriers or on a holiday). Packages already booked are not touched.</div>' +
    '<label style="display:block;font-size:13px;margin-bottom:8px"><input type="checkbox" name="express_on"' + (Number(t.express_on) === 1 ? ' checked' : '') + '> Express delivery is offered</label>' +
    '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:8px"><label style="font-size:12px">Express extra (% of the fee)' + '<br>' + num('express_pct', t.express_pct, 200) + '</label>' +
    '<label style="font-size:12px">Smallest extra (ETB)<br>' + num('express_min', t.express_min, 5000) + '</label></div>' +
    '<label style="display:block;font-size:13px;margin-bottom:10px"><input type="checkbox" name="booking_on"' + (Number(t.booking_on) === 1 ? ' checked' : '') + '> Customers can book packages (untick to pause)</label>' +
    '<button type="submit" class="ak-btn gold">Save</button><div data-note role="status" style="font-size:12.5px;min-height:18px;margin-top:6px"></div></form>';
}
function rules() {
  return speedCard() + '<form data-setting="late_cancel_fee" class="ak-card" style="cursor:default;margin-bottom:14px">' +
    '<div style="font-weight:800;margin-bottom:2px">↩️ Cancelling after a courier accepted</div>' +
    '<div class="pkp-note" style="margin-bottom:10px">A sender can cancel for free until a courier accepts. After that, if they paid online, this fee is kept out of their refund to pay for the courier\'s trip. Cash packages are never charged. Put 0 to keep cancelling always free.</div>' +
    '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end"><label style="font-size:12px">Cancel fee (ETB)<input name="value" type="number" inputmode="decimal" min="0" max="5000" step="any" value="' + esc(SETTINGS.late_cancel_fee) + '" class="ak-in" style="width:150px"></label>' +
    '<button type="submit" class="ak-btn gold">Save fee</button></div><div data-note role="status" style="font-size:12.5px;min-height:18px;margin-top:6px"></div></form>' +
    '<div class="ak-card" style="cursor:default"><div style="font-weight:800;margin-bottom:6px">How a package price is worked out</div><ol class="pkp-note" style="padding-left:18px;line-height:1.7"><li>The distance band price (up to 5 km, 10 km, 25 km, then per extra km).</li><li>× the vehicle size multiplier (bicycle, motorbike, car, van).</li><li>+ the weight band price.</li><li>The vehicle refuses packages heavier than its maximum weight.</li><li>Couriers get the jobs of their own vehicle only. Add the words a courier\'s vehicle is registered with in the Vehicles tab.</li></ol></div>';
}

function render() {
  var el = panel(); if (!el) return;
  K.css(); css();
  var active = RATES.filter(isOn).length, cheapest = RATES.length ? Math.min.apply(null, RATES.map(function (r) { return price(r, 5, 3); })) : 0;
  var on = 0, reg = 0; ((CAT && CAT.classes) || []).forEach(function (c) { on += c.online; reg += c.couriers; });
  var h = '<div class="ak">' + K.hero('Package prices', 'What each vehicle charges when a customer sends a package, and who can carry it. Changes apply to new bookings at once.', [
    ['Vehicle types', K.num(RATES.length)], ['Offered now', K.num(active)], ['Couriers online', K.num(on) + ' / ' + K.num(reg)], ['Cheapest 5 km / 3 kg', RATES.length ? K.money(cheapest) : '—']]) +
    '<div class="pkp-tabs" role="tablist">' + TABS.map(function (t) { return '<button type="button" role="tab" data-tab="' + t[0] + '" aria-selected="' + (TAB === t[0]) + '" class="' + (TAB === t[0] ? 'on' : '') + '">' + esc(t[1]) + '</button>'; }).join('') + '</div>' +
    (TAB === 'vehicles' ? vehicles() : TAB === 'bands' ? bands() : TAB === 'rules' ? rules() : overview()) + '</div>';
  el.innerHTML = h;
  refreshAll();
  wireOnce(el);
}

function refreshAll() {
  var el = panel(); if (!el) return;
  var km = Number(CALC.km) || 0, kg = Number(CALC.kg) || 0, mx = el.querySelector('[data-mx]'), cv = el.querySelector('[data-cv]');
  if (mx) mx.innerHTML = matrix(); if (cv) cv.innerHTML = customerView();
  Array.prototype.forEach.call(el.querySelectorAll('form[data-class]'), function (f) {
    var r = formRate(f), p = f.querySelector('[data-preview]'); r.size_rank = (RATES.filter(function (x) { return x.vehicle_class === f.getAttribute('data-class'); })[0] || {}).size_rank; r.vehicle_class = f.getAttribute('data-class');
    p.innerHTML = 'Customer pays <b>ETB ' + price(r, km, kg) + '</b> for ' + esc(km) + ' km, ' + esc(kg) + ' kg';
    var lad = f.querySelector('[data-ladder]');
    if (lad) lad.innerHTML = [1, 5, 10, 20].map(function (d) { return '<span class="ak-chip">' + d + ' km · <b>ETB ' + price(r, d, kg) + '</b></span>'; }).join('');
    var st = f.querySelector('[data-state]');
    if (st) st.innerHTML = K.pill(f.elements.active.checked ? 'Offered to customers' : 'Switched off', f.elements.active.checked ? 'green' : 'grey');
  });
}

function wireOnce(el) {
  if (el._pkgWired) return; el._pkgWired = true;
  el.addEventListener('input', function (e) {
    var t = e.target;
    if (t.id === 'pkg-calc-km') CALC.km = t.value; else if (t.id === 'pkg-calc-kg') CALC.kg = t.value;
    if (t && ((t.closest && t.closest('form[data-class]')) || t.id === 'pkg-calc-km' || t.id === 'pkg-calc-kg')) refreshAll();
    if (t && t.closest && t.closest('form[data-tiers]')) {   // live example: 8 km, 12 kg, motorbike
      var f = t.closest('form[data-tiers]'), tmp = {}, k; for (k in SETTINGS) tmp[k] = SETTINGS[k];
      Array.prototype.forEach.call(f.elements, function (x) { if (x.name && x.name !== 'tier_on') tmp[x.name] = x.value; });
      var eg = f.querySelector('[data-tier-eg]'); if (eg) eg.textContent = tierPrice(tmp, 8, 12, 2);
    }
  });
  el.addEventListener('change', function (e) { if (e.target.name === 'active') refreshAll(); });
  el.addEventListener('click', function (e) {
    var tg = e.target.closest ? e.target : null; if (!tg) return;
    var b;
    if ((b = tg.closest('[data-tab]'))) { TAB = b.getAttribute('data-tab'); render(); }
    else if ((b = tg.closest('[data-quickadd]'))) { TAB = 'vehicles'; render(); openAdd(el, b.getAttribute('data-quickadd')); var h = el.querySelector('[data-add-host]'); if (h && h.scrollIntoView) h.scrollIntoView({ block: 'center' }); }
    else if (tg.closest('[data-add]')) openAdd(el);
    else if (tg.closest('[data-cancel-add]')) { var hh = el.querySelector('[data-add-host]'); if (hh) hh.innerHTML = ''; }
  });
  el.addEventListener('submit', function (e) {
    var f = e.target; e.preventDefault();
    var btn = f.querySelector('button[type=submit]');
    if (f.hasAttribute("data-tiers") || f.hasAttribute("data-speed")) {
      var vals = {}; Array.prototype.forEach.call(f.elements, function (x) { if (x.type === 'checkbox' && x.name) vals[x.name] = x.checked ? 1 : 0; else if (x.name) vals[x.name] = x.value; });
      btn.disabled = true; note(f, 'Saving…');
      K.api('admin_package_setting_save', { values: vals }).then(function (res) {
        btn.disabled = false;
        if (K.okRes(res)) { var d = K.data(res); if (d.settings) SETTINGS = d.settings; render(); K.toast(d.message || 'Saved.'); }
        else note(f, K.failText(res, 'Could not save.'), true);
      });
    } else if (f.hasAttribute('data-setting')) {
      btn.disabled = true; note(f, 'Saving…');
      K.api('admin_package_setting_save', { key: f.getAttribute('data-setting'), value: f.elements.value.value }).then(function (res) {
        btn.disabled = false;
        if (K.okRes(res)) { var d = K.data(res); if (d.settings) SETTINGS = d.settings; note(f, d.message || 'Saved.', false); }
        else note(f, K.failText(res, 'Could not save.'), true);
      });
    } else if (f.hasAttribute('data-class')) {
      btn.disabled = true; note(f, 'Saving…');
      var body = formRate(f); body.vehicle_class = f.getAttribute('data-class');
      K.api('admin_package_rates_save', body).then(function (res) {
        btn.disabled = false;
        if (K.okRes(res)) { var d = K.data(res); if (d.rates) RATES = d.rates; note(f, d.message || 'Saved.', false); K.toast('Prices saved'); load(true); }
        else note(f, K.failText(res, 'Could not save.'), true);
      });
    } else if (f.hasAttribute('data-new')) {
      btn.disabled = true; note(f, 'Adding…');
      var nb = {}; NUM.forEach(function (n) { nb[n[0]] = f.elements[n[0]].value; });
      nb.label = f.elements.label.value; nb.icon = f.elements.icon.value; nb.active = true;
      nb.size_like = f.elements.size_like.value; nb.courier_names = f.elements.courier_names.value;
      K.api('admin_package_type_add', nb).then(function (res) {
        btn.disabled = false;
        if (K.okRes(res)) {
          var d = K.data(res); if (d.rates) RATES = d.rates;
          TAB = 'vehicles'; render(); load(true);
          var c = panel() && panel().querySelector('form[data-class="' + String(d.vehicle_class).replace(/[^a-z0-9_-]/gi, '') + '"]'); if (c) { note(c, d.message || 'Added.', false); c.scrollIntoView({ block: 'center' }); }
        } else note(f, K.failText(res, 'Could not add.'), true);
      });
    }
  });
}

function openAdd(el, prefill) {
  var host = el.querySelector('[data-add-host]'); if (!host) return;
  if (host.firstChild && !prefill) { host.innerHTML = ''; return; }
  var DEF = { base_fee: 100, per_km: 20, free_kg: 10, per_kg_over: 5, min_fee: 150, max_weight_kg: 300 };
  var nm = prefill ? String(prefill).replace(/_/g, ' ').replace(/^./, function (c) { return c.toUpperCase(); }) : '', words = prefill ? String(prefill).replace(/_/g, ' ') : '';
  if (prefill) { DEF = { base_fee: 60, per_km: 12, free_kg: 5, per_kg_over: 6, min_fee: 80, max_weight_kg: 80 }; }
  host.innerHTML = '<form data-new autocomplete="off" class="ak-card" style="cursor:default;margin-top:10px;border-color:var(--gold)">' +
    '<div style="font-weight:800;margin-bottom:4px">Add a vehicle type</div>' +
    '<div class="pkp-note" style="margin-bottom:10px">For example a Bajaj or a mini truck. Customers see it next to the other vehicles, and only couriers whose vehicle matches the words below get its jobs.</div>' +
    '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px">' +
    '<label style="flex:0 0 74px;font-size:12px">Icon<input name="icon" class="ak-in" value="' + (prefill ? '🛺' : '🛺') + '" maxlength="8"></label>' +
    '<label style="flex:1 1 160px;font-size:12px">Name customers see<input name="label" class="ak-in" maxlength="60" placeholder="Bajaj" value="' + esc(nm) + '"></label>' +
    extraFields(null, words) + '</div>' + numFields(null, DEF) +
    '<div class="ak-acts" style="margin-top:12px"><button type="submit" class="ak-btn gold">Add vehicle type</button><button type="button" data-cancel-add class="ak-btn">Cancel</button></div>' +
    '<div data-note role="status" style="font-size:12.5px;min-height:18px;margin-top:6px"></div></form>';
}

function load(quiet) {
  var el = panel(); if (!el) return;
  K.css();
  if (!RATES.length && !quiet) el.innerHTML = '<div class="ak">' + K.skeleton(3) + '</div>';
  K.api('admin_package_rates_get').then(function (res) {
    if (!K.okRes(res)) { if (!quiet) el.innerHTML = '<div class="ak">' + K.empty('⚠️', 'Could not load prices', K.failText(res)) + '</div>'; return; }
    var dd = K.data(res); RATES = dd.rates || []; CAT = dd.vehicles || null; if (dd.settings) SETTINGS = dd.settings;
    if (quiet) { refreshAll(); return; }   // after a save: refresh the numbers only, keep what is typed in the other cards
    render();
  });
}

function install() {
  if (document.querySelector('[data-page="packages"]')) return;
  var after = document.querySelector('.nav-item[data-page="dispatch"]') || document.querySelector('.nav-item[data-page="orders"]') || document.querySelector('.nav-item[data-page="dashboard"]');
  if (after) {
    var nav = document.createElement('div');
    nav.className = 'nav-item'; nav.setAttribute('data-page', 'packages');
    nav.setAttribute('onclick', "switchPage('packages',this)");
    nav.innerHTML = '<span class="nav-icon">📦</span><span>Package prices</span>';
    after.parentNode.insertBefore(nav, after.nextSibling);
  }
  var host = document.getElementById('panel-orders') || document.getElementById('panel-dispatch') || document.getElementById('panel-support');
  if (host && !panel()) {
    var p = document.createElement('div'); p.className = 'panel'; p.id = 'panel-packages'; p.style.display = 'none';
    host.parentNode.appendChild(p);
  }
  var orig = window.switchPage;
  if (typeof orig === 'function') {
    window.switchPage = function (page) {
      var r = orig.apply(this, arguments);
      if (page === 'packages') {
        var t = document.getElementById('page-title'); if (t) t.textContent = 'Package prices';
        var ab = document.getElementById('action-buttons'); if (ab) ab.innerHTML = '';
        load();
      }
      return r;
    };
  }
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();

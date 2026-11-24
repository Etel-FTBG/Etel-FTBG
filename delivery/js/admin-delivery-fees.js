/* ETEL Admin - Delivery fees: the price of every delivery by distance, one price list per real vehicle, and how the fee is shared.
   What the admin controls here (api/lib/delivery_fees.php):
   - for each vehicle (with its real picture): a base fee, ETB per km, and a minimum fee. Bicycle starts at 45 ETB + 15 ETB per km;
   - the share: the company keeps a percent of each fee (40), the courier gets the rest (60). The tip is always 100% the courier's;
   - the service charge: a percent of the food total, free (0) until you switch it on;
   - more controls: road distance vs straight line, rounding (1, 5 or 10 ETB), night multiplier with hours, farthest delivery.
   A live price table, a "try a trip" calculator and a courier / company split show every change BEFORE saving. After saving, the numbers
   are read back from the server and compared with what was typed.
   Data: admin_dfee_get, admin_dfee_save (api/controllers/DeliveryFeeController.php). Standalone: adds its own menu item and page.
   Look and pieces come from js/admin-kit.js; vehicle pictures from js/etel-vehicle-pic.js. No inline handlers; server text goes through esc. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;
var S = { loaded: false, settings: null, defaults: null, vehicles: [], stats: null, dirty: false, tryKm: 3, tryNight: false };
var DIST = [1, 2, 3, 5, 8, 12, 20];
var GKEYS = ['enabled', 'company_pct', 'service_pct', 'road_factor', 'round_step', 'max_km', 'night_mult', 'night_start', 'night_end'];

function panel() { return document.getElementById('panel-dfees'); }
function nm(v, d) { v = Number(v); return isFinite(v) ? v : (d || 0); }
function etb(v) { return 'ETB ' + Math.round(nm(v)).toLocaleString(); }
function pic(cls, px, icon) { return window.EtelVehiclePic ? window.EtelVehiclePic.html(cls, px, { fallback: icon || '🚚' }) : esc(icon || '🚚'); }
function note(c, msg, bad) { var el = c.querySelector('[data-note]'); if (el) { el.textContent = msg; el.style.color = bad ? '#d6364a' : '#1fa463'; } }

/* The same maths as etel_dfee_compute() in api/lib/delivery_fees.php. */
function roundTo(v, step) { step = Math.max(1, step); return Math.floor(v / step + 0.5) * step; }
function compute(v, km, night) {
  var G = S.settings, road = Math.max(0, km) * nm(G.road_factor, 1.3);
  var fee = Math.max(nm(v.min), (nm(v.base) + nm(v.per_km) * road) * (night ? nm(G.night_mult, 1) : 1));
  fee = roundTo(fee, nm(G.round_step, 5));
  if (fee < nm(v.min)) fee = Math.ceil(nm(v.min));
  var courier = Math.round(fee * (100 - nm(G.company_pct, 40)) / 100);
  return { fee: fee, courier: courier, company: fee - courier, road: road };
}

var cssDone = false;
function css() {
  if (cssDone) return; cssDone = true;
  var s = document.createElement('style');
  s.textContent =
    '.dfee-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:14px;margin:0 0 16px}' +
    '.dfee-card{border:1.5px solid var(--line);border-radius:18px;background:var(--card);box-shadow:var(--shadow);padding:16px;margin:0 0 14px}' +
    '.dfee-card h3{margin:0 0 2px;font-size:16px}.dfee-card .sub{color:var(--mute);font-size:12.5px;margin:0 0 12px}' +
    '.dfee-veh{border:1.5px solid var(--line);border-radius:18px;background:var(--card);box-shadow:var(--shadow);overflow:hidden;display:flex;flex-direction:column}' +
    '.dfee-veh.off{opacity:.62}' +
    '.dfee-pic{height:104px;display:flex;align-items:center;justify-content:center;background:radial-gradient(120% 120% at 50% 0%,rgba(212,175,55,.22),transparent 70%),var(--soft);position:relative}' +
    '.dfee-pic .etel-vpic{max-height:84px;width:auto;filter:drop-shadow(0 6px 10px rgba(0,0,0,.25))}' +
    '.dfee-pic .pill{position:absolute;top:10px;right:10px}' +
    '.dfee-body{padding:14px 14px 12px}.dfee-body h4{margin:0;font-size:16px}.dfee-body .meta{color:var(--mute);font-size:12px;margin:2px 0 12px}' +
    '.dfee-in{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.dfee-in label{font-size:11px;font-weight:700;color:var(--mute);display:block}' +
    '.dfee-in input{margin-top:4px;width:100%;box-sizing:border-box}' +
    '.dfee-ladder{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}.dfee-ladder span{background:var(--soft);border:1px solid var(--line);border-radius:999px;padding:4px 10px;font-size:11.5px;white-space:nowrap}' +
    '.dfee-ladder b{font-weight:800}' +
    '.dfee-link{background:none;border:0;color:var(--mute);text-decoration:underline;font:inherit;font-size:11.5px;cursor:pointer;padding:6px 0 0}' +
    '.dfee-bar{display:flex;height:34px;border-radius:12px;overflow:hidden;border:1px solid var(--line);font-weight:800;font-size:13px}' +
    '.dfee-bar div{display:flex;align-items:center;justify-content:center;min-width:0;white-space:nowrap;transition:width .15s}' +
    '.dfee-bar .c{background:linear-gradient(135deg,#2fa56a,#1f7d4f);color:#fff}.dfee-bar .m{background:linear-gradient(135deg,var(--gold),var(--gold2,#c99a1c));color:#1c1500}' +
    '.dfee-range{width:100%;accent-color:var(--gold);margin:14px 0 6px}' +
    '.dfee-chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}.dfee-chips button{border:1.5px solid var(--line);background:var(--soft);color:var(--ink);border-radius:999px;padding:6px 14px;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer}' +
    '.dfee-wrap{overflow-x:auto;border:1.5px solid var(--line);border-radius:16px;background:var(--card);box-shadow:var(--shadow)}' +
    '.dfee-tbl{width:100%;border-collapse:separate;border-spacing:0;font-size:13px}' +
    '.dfee-tbl th,.dfee-tbl td{padding:9px 12px;text-align:center;border-top:1px solid var(--line);white-space:nowrap}' +
    '.dfee-tbl thead th{border-top:0;background:var(--soft);font-size:12px;vertical-align:bottom}.dfee-tbl thead .etel-vpic{max-height:34px}' +
    '.dfee-tbl td:first-child,.dfee-tbl th:first-child{text-align:left;font-weight:700}.dfee-tbl td.off{color:var(--mute)}' +
    '.dfee-try{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:12px}' +
    '.dfee-try>div{border:1px solid var(--line);border-radius:14px;padding:10px 12px;background:var(--soft)}.dfee-try b{display:block;font-size:17px}' +
    '.dfee-try small{display:block;color:var(--mute);font-size:11.5px;margin-top:3px}.dfee-try .lbl{font-weight:700;font-size:12.5px;display:flex;align-items:center;gap:6px}' +
    '.dfee-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}' +
    '.dfee-save{position:sticky;bottom:0;z-index:5;display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:12px 14px;margin-top:6px;border:1.5px solid var(--line);border-radius:16px;background:var(--card);box-shadow:0 -6px 24px rgba(0,0,0,.18)}' +
    '.dfee-save .msg{flex:1 1 200px;font-size:12.5px;min-height:18px}.dfee-save.dirty{border-color:var(--gold)}' +
    '.dfee-sw{display:flex;gap:10px;align-items:center;font-weight:700;margin-bottom:6px}.dfee-sw input{width:auto}' +
    '@media(max-width:560px){.dfee-in{grid-template-columns:1fr 1fr 1fr}.dfee-pic{height:92px}}';
  document.head.appendChild(s);
}

/* ---------- pieces that change while typing (rewritten by live(), never the inputs) ---------- */
function heroHtml() {
  var G = S.settings, c = nm(G.company_pct), sp = nm(G.service_pct);
  return K.hero('Delivery fees', 'Price every delivery by distance, for each vehicle, and choose how the fee is shared between the company and the courier.',
    [['Courier gets', (100 - c) + '%'], ['Company keeps', c + '%'], ['Service charge', sp > 0 ? sp + '%' : 'Free']]);
}
function statsHtml() {
  var st = S.stats || {};
  if (!nm(st.orders)) return '<div class="dfee-card"><h3>Last 30 days</h3><p class="sub" style="margin:0">No order has been priced by distance yet. The numbers appear here after the first one.</p></div>';
  return K.kpis([{ key: 'o', icon: '📦', label: 'Orders, last 30 days', value: K.num(st.orders) }, { key: 'f', icon: '🧾', label: 'Delivery fees', value: K.money(st.fees) },
    { key: 'c', icon: '🛵', label: 'Couriers earned', value: K.money(st.courier) }, { key: 'm', icon: '🏢', label: 'Company earned', value: K.money(st.company) }], '', true);
}
function splitHtml() {
  var G = S.settings, c = Math.max(0, Math.min(100, nm(G.company_pct))), ex = null, i, v;
  for (i = 0; i < S.vehicles.length; i++) { if (S.vehicles[i].class === 'bike') ex = S.vehicles[i]; }
  ex = ex || S.vehicles[0];
  var h = '<div class="dfee-bar" role="img" aria-label="Courier ' + (100 - c) + ' percent, company ' + c + ' percent"><div class="c" style="width:' + (100 - c) + '%">' + (100 - c >= 12 ? 'Courier ' + (100 - c) + '%' : '') + '</div>' +
    '<div class="m" style="width:' + c + '%">' + (c >= 12 ? 'Company ' + c + '%' : '') + '</div></div>';
  if (ex) { v = compute(ex, 3, false); h += '<p class="sub" style="margin:10px 0 0">Example: a ' + esc(ex.label) + ' trip of 3 km costs <b>' + etb(v.fee) + '</b>. The courier gets <b>' + etb(v.courier) + '</b>, the company keeps <b>' + etb(v.company) + '</b>. The tip is extra, 100% the courier\'s.</p>'; }
  return h;
}
function ladderHtml(v) {
  return [1, 3, 5, 10].map(function (km) { return '<span>' + km + ' km <b>' + etb(compute(v, km, false).fee) + '</b></span>'; }).join('');
}
function tableHtml() {
  var h = '<div class="dfee-wrap"><table class="dfee-tbl"><thead><tr><th>Distance</th>' + S.vehicles.map(function (v) {
    return '<th>' + pic(v.class, 46, v.icon) + '<br>' + esc(v.label) + '</th>'; }).join('') + '</tr></thead><tbody>';
  DIST.forEach(function (km) {
    h += '<tr><td>' + km + ' km</td>' + S.vehicles.map(function (v) { return '<td class="' + (v.active ? '' : 'off') + '"><b>' + etb(compute(v, km, false).fee) + '</b></td>'; }).join('') + '</tr>';
  });
  return h + '</tbody></table></div><p class="sub" style="margin:8px 2px 0">Straight-line distance from the shop to the customer\'s pin. Prices include the road factor and rounding below.</p>';
}
function tryHtml() {
  var km = Math.max(0, nm(S.tryKm)), h = '<div class="dfee-try">';
  S.vehicles.forEach(function (v) {
    var r = compute(v, km, S.tryNight);
    h += '<div style="' + (v.active ? '' : 'opacity:.55') + '"><div class="lbl">' + pic(v.class, 30, v.icon) + esc(v.label) + '</div><b>' + etb(r.fee) + '</b>' +
      '<small>Courier ' + etb(r.courier) + ' · Company ' + etb(r.company) + '</small></div>';
  });
  return h + '</div>';
}
function barHtml(extra) {
  return '<div class="dfee-save' + (S.dirty ? ' dirty' : '') + '"><button type="button" class="ak-btn gold" data-save>💾 Save delivery fees</button>' +
    (S.dirty ? '<button type="button" class="ak-btn" data-discard>Undo changes</button>' : '') +
    '<div class="msg" data-note role="status">' + (extra ? esc(extra) : (S.dirty ? 'You have changes that are not saved yet.' : '')) + '</div></div>';
}

function live() {
  var el = panel(); if (!el) return;
  var set = function (k, html) { var t = el.querySelector('[data-live="' + k + '"]'); if (t) t.innerHTML = html; };
  set('hero', heroHtml()); set('split', splitHtml()); set('table', tableHtml()); set('try', tryHtml());
  S.vehicles.forEach(function (v) { set('ladder:' + v.class, ladderHtml(v)); });
  var out = el.querySelector('[data-live="cut"]'); if (out) out.textContent = 'Company ' + nm(S.settings.company_pct) + '% · Courier ' + (100 - nm(S.settings.company_pct)) + '%';
  var sv = el.querySelector('.dfee-save'); if (sv) { sv.classList.toggle('dirty', S.dirty); var m = sv.querySelector('[data-note]'); if (m && S.dirty) { m.textContent = 'You have changes that are not saved yet.'; m.style.color = ''; } }
}
function markDirty() {
  if (S.dirty) return; S.dirty = true;
  var sv = panel() && panel().querySelector('.dfee-save'); if (!sv) return;
  if (!sv.querySelector('[data-discard]')) { var b = document.createElement('button'); b.type = 'button'; b.className = 'ak-btn'; b.setAttribute('data-discard', ''); b.textContent = 'Undo changes'; sv.insertBefore(b, sv.querySelector('.msg')); }
}

/* ---------- the page ---------- */
function numIn(attrs, v, min, max, step) {
  return '<input class="ak-in" type="number" inputmode="decimal" ' + attrs + ' min="' + min + '" max="' + max + '" step="' + step + '" value="' + esc(v) + '">';
}
function vehicleCard(v) {
  var d = v.default || {}, same = nm(v.base) === nm(d.base) && nm(v.per_km) === nm(d.per_km) && nm(v.min) === nm(d.min);
  var online = nm(v.online) > 0 ? K.pill(nm(v.online) + ' online', 'green') : K.pill('none online', 'grey');
  return '<div class="dfee-veh' + (v.active ? '' : ' off') + '" data-veh="' + esc(v.class) + '"><div class="dfee-pic">' + pic(v.class, 120, v.icon) +
    '<span class="pill">' + online + '</span></div><div class="dfee-body"><h4>' + esc(v.label) + '</h4>' +
    '<div class="meta">' + nm(v.couriers) + ' courier' + (nm(v.couriers) === 1 ? '' : 's') + (v.active ? '' : ' · switched off in Package prices') + '</div>' +
    '<div class="dfee-in"><label>Base fee' + numIn('data-v="' + esc(v.class) + '" data-f="base" aria-label="' + esc(v.label) + ' base fee in ETB"', v.base, 0, 2000, 'any') + '</label>' +
    '<label>Per km' + numIn('data-v="' + esc(v.class) + '" data-f="per_km" aria-label="' + esc(v.label) + ' ETB per km"', v.per_km, 0, 500, 'any') + '</label>' +
    '<label>Minimum' + numIn('data-v="' + esc(v.class) + '" data-f="min" aria-label="' + esc(v.label) + ' minimum fee in ETB"', v.min, 0, 5000, 'any') + '</label></div>' +
    '<div class="dfee-ladder" data-live="ladder:' + esc(v.class) + '">' + ladderHtml(v) + '</div>' +
    '<button type="button" class="dfee-link" data-reset="' + esc(v.class) + '"' + (same ? ' hidden' : '') + '>Back to the starting price (' + etb(d.base) + ' + ' + nm(d.per_km) + '/km)</button></div></div>';
}
function render(extra) {
  var el = panel(); if (!el) return;
  K.css(); css();
  var G = S.settings, on = nm(G.enabled) === 1;
  var h = '<div class="ak"><div data-live="hero">' + heroHtml() + '</div>' + statsHtml();
  h += '<div class="dfee-card"><h3>💰 Who gets what</h3><p class="sub">Every delivery fee is split. The courier\'s part is saved on the order when it is placed, so a later change never rewrites an old order.</p>' +
    '<label class="dfee-sw"><input type="checkbox" data-g="enabled"' + (on ? ' checked' : '') + '> Price deliveries by distance</label>' +
    '<div class="ak-fh" style="margin:0 0 8px">Off: the old zone fees are used again, and no courier share is saved.</div>' +
    '<div style="font-weight:800;font-size:13px" data-live="cut">Company ' + nm(G.company_pct) + '% · Courier ' + (100 - nm(G.company_pct)) + '%</div>' +
    '<input class="dfee-range" type="range" min="0" max="100" step="1" data-g="company_pct" value="' + esc(G.company_pct) + '" aria-label="Percent of each delivery fee the company keeps">' +
    '<div data-live="split">' + splitHtml() + '</div>' +
    '<div class="dfee-chips" role="group" aria-label="Quick share">' + [[30, '70 / 30'], [40, '60 / 40'], [50, '50 / 50']].map(function (c) { return '<button type="button" data-chip="' + c[0] + '">Courier / company ' + c[1] + '</button>'; }).join('') + '</div></div>';
  h += '<div class="dfee-card"><h3>🧾 Service charge</h3><p class="sub">A percent of the food total, added at checkout. It starts free. Customers see the word Free while it is 0.</p>' +
    '<div class="dfee-fields">' + K.field('Service charge (% of the items)', numIn('data-g="service_pct"', G.service_pct, 0, 30, 'any'), '0 = free. Largest: 30.') + '</div></div>';
  h += '<div style="display:flex;gap:10px;align-items:center;margin:4px 0 10px"><h3 style="margin:0;font-size:15px">🚚 Price of each vehicle</h3></div>';
  h += S.vehicles.length ? '<div class="dfee-grid">' + S.vehicles.map(vehicleCard).join('') + '</div>' : K.empty('🚚', 'No vehicle yet', 'Vehicles come from Package prices. Add or switch one on there.');
  h += '<div class="dfee-card"><h3>📊 Price table</h3><p class="sub">What the customer pays for each distance and vehicle. It changes as you type.</p><div data-live="table">' + tableHtml() + '</div></div>';
  h += '<div class="dfee-card"><h3>🧮 Try a trip</h3><p class="sub">Type a distance to see the fee, and who gets what, for every vehicle.</p>' +
    '<div class="dfee-fields">' + K.field('Distance, shop to customer (km)', numIn('data-try="km"', S.tryKm, 0, 200, 'any')) +
    '<label class="dfee-sw" style="align-self:end;margin-bottom:8px"><input type="checkbox" data-try="night"' + (S.tryNight ? ' checked' : '') + '> At night</label></div><div data-live="try">' + tryHtml() + '</div></div>';
  h += '<details class="dfee-card"><summary style="cursor:pointer;font-weight:800;font-size:15px">⚙️ More controls</summary><div class="dfee-fields" style="margin-top:14px">' +
    K.field('Road distance vs straight line (1 to 2)', numIn('data-g="road_factor"', G.road_factor, 1, 2, 'any'), 'Roads are longer than a straight line. 1.3 is a good start.') +
    K.field('Round every fee to the nearest', '<select class="ak-in" data-g="round_step">' + [1, 5, 10].map(function (x) { return '<option value="' + x + '"' + (nm(G.round_step) === x ? ' selected' : '') + '>' + x + ' ETB</option>'; }).join('') + '</select>', 'The fee is never rounded below the minimum.') +
    K.field('Farthest delivery (km, 0 = no limit)', numIn('data-g="max_km"', G.max_km, 0, 200, 'any'), 'Orders beyond this by road are refused with a clear message.') +
    K.field('Night multiplier (1 to 3)', numIn('data-g="night_mult"', G.night_mult, 1, 3, 'any'), '1 = no night price.') +
    K.field('Night starts at hour (0-23)', numIn('data-g="night_start"', G.night_start, 0, 23, 1)) + K.field('Night ends at hour (0-23)', numIn('data-g="night_end"', G.night_end, 0, 23, 1)) +
    '</div></details>';
  el.innerHTML = h + barHtml(extra) + '</div>';
}

function load(quiet) {
  var el = panel(); if (!el) return;
  K.css(); css();
  if (!S.loaded && !quiet) el.innerHTML = '<div class="ak">' + K.skeleton(4) + '</div>';
  K.api('admin_dfee_get', {}).then(function (res) {
    if (!K.okRes(res)) { el.innerHTML = '<div class="ak">' + K.empty('⚠️', 'Could not load the delivery fees', K.failText(res)) + '</div>'; return; }
    apply(K.data(res)); S.loaded = true; S.dirty = false; render();
  });
}
function apply(d) { S.settings = d.settings || {}; S.defaults = d.defaults || {}; S.vehicles = d.vehicles || []; S.stats = d.stats || {}; }
function vehicleOf(cls) { for (var i = 0; i < S.vehicles.length; i++) { if (S.vehicles[i].class === cls) return S.vehicles[i]; } return null; }

/* ---------- events ---------- */
function onInput(e) {
  var t = e.target; if (!t || !t.getAttribute) return;
  var g = t.getAttribute('data-g'), v = t.getAttribute('data-v'), tr = t.getAttribute('data-try');
  if (g) {
    S.settings[g] = t.type === 'checkbox' ? (t.checked ? 1 : 0) : t.value;
    if (g === 'company_pct') { var other = panel().querySelector('[data-g="company_pct"]'); if (other && other !== t) other.value = t.value; }
  } else if (v) {
    var veh = vehicleOf(v); if (!veh) return;
    veh[t.getAttribute('data-f')] = t.value;
    var rs = panel().querySelector('[data-reset="' + v + '"]'); if (rs) { var d = veh.default || {}; rs.hidden = nm(veh.base) === nm(d.base) && nm(veh.per_km) === nm(d.per_km) && nm(veh.min) === nm(d.min); }
  } else if (tr) {
    if (tr === 'km') S.tryKm = t.value; else S.tryNight = !!t.checked;
    live(); return;
  } else return;
  markDirty(); live();
}
function onClick(e) {
  var t = e.target, b; if (!t.closest) return;
  if ((b = t.closest('[data-chip]'))) {
    S.settings.company_pct = Number(b.getAttribute('data-chip'));
    Array.prototype.forEach.call(panel().querySelectorAll('[data-g="company_pct"]'), function (x) { x.value = S.settings.company_pct; });
    markDirty(); live(); return;
  }
  if ((b = t.closest('[data-reset]'))) {
    var veh = vehicleOf(b.getAttribute('data-reset')); if (!veh || !veh.default) return;
    veh.base = veh.default.base; veh.per_km = veh.default.per_km; veh.min = veh.default.min;
    markDirty(); S.dirty = true; render(); return;
  }
  if (t.closest('[data-discard]')) { S.dirty = false; load(true); return; }
  if ((b = t.closest('[data-save]'))) save(b);
}
function bad(v) { return v === '' || v === null || !isFinite(Number(v)) || Number(v) < 0; }
function save(btn) {
  var el = panel(), body = { settings: {}, vehicles: {} }, i, v, problem = '';
  GKEYS.forEach(function (k) { body.settings[k] = S.settings[k]; if (bad(S.settings[k])) problem = 'Check the number for "' + k.replace(/_/g, ' ') + '".'; });
  if (nm(S.settings.company_pct) > 100) problem = 'The company share cannot be more than 100%.';
  for (i = 0; i < S.vehicles.length; i++) {
    v = S.vehicles[i]; body.vehicles[v.class] = { base: v.base, per_km: v.per_km, min: v.min };
    if (bad(v.base) || bad(v.per_km) || bad(v.min)) problem = 'Check the prices of ' + v.label + ': use numbers, not less than 0.';
  }
  if (problem) { note(el, problem, true); K.toast(problem, true); return; }
  btn.disabled = true; note(el, 'Saving…');
  K.api('admin_dfee_save', body).then(function (res) {
    btn.disabled = false;
    if (!K.okRes(res)) { note(el, K.failText(res, 'Could not save.'), true); K.toast(K.failText(res, 'Could not save.'), true); return; }
    var d = K.data(res), diff = [];
    GKEYS.forEach(function (k) { if (Math.abs(nm(d.settings && d.settings[k]) - nm(body.settings[k])) > 0.0001) diff.push(k.replace(/_/g, ' ') + ' (typed ' + body.settings[k] + ', server has ' + (d.settings ? d.settings[k] : '?') + ')'); });
    (d.vehicles || []).forEach(function (sv) {
      var sent = body.vehicles[sv.class]; if (!sent) return;
      ['base', 'per_km', 'min'].forEach(function (f) { if (Math.abs(nm(sv[f]) - nm(sent[f])) > 0.0001) diff.push(sv.label + ' ' + f.replace('_', ' ') + ' (typed ' + sent[f] + ', server has ' + sv[f] + ')'); });
    });
    apply(d); S.dirty = false; render();
    var m = diff.length ? 'Saved, but the server changed some numbers to keep them in range: ' + diff.join('; ') + '.' : 'Saved and checked: the server returns the same numbers. New orders use them at once.';
    note(panel(), m, !!diff.length); K.toast(diff.length ? 'Saved, some numbers were adjusted' : 'Delivery fees saved', false);
  });
}

function install() {
  K.ensurePanel('dfees', 'dfees', '🧮', 'Delivery fees', 'pricing');
  var el = panel();
  if (el && !el._df) { el._df = true; el.addEventListener('input', onInput); el.addEventListener('change', onInput); el.addEventListener('click', onClick); }
  var orig = window.switchPage;
  if (typeof orig === 'function' && !orig._df) {
    window.switchPage = function (page) {
      var r = orig.apply(this, arguments);
      if (page === 'dfees') { var t = document.getElementById('page-title'); if (t) t.textContent = 'Delivery fees'; var ab = document.getElementById('action-buttons'); if (ab) ab.innerHTML = ''; load(S.loaded); }
      return r;
    };
    window.switchPage._df = true;
  }
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();

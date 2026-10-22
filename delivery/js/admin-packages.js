/* ETEL Admin - Package prices: what each vehicle charges for a package delivery.
   Staff change the base fee, price per km, free weight, price per extra kg, minimum fee, maximum weight, name, icon,
   and can switch a vehicle off. A calculator shows the price a customer would see before saving, and every vehicle
   card shows a small price ladder (1 / 5 / 10 / 20 km) so a change is easy to judge.
   Data: api actions admin_package_rates_get, admin_package_rates_save, admin_package_type_add, admin_package_setting_save (api/controllers/PackageController.php).
   Standalone on purpose: it adds its own menu item and page, so no other admin file changes. Look and pieces come from js/admin-kit.js. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;
var RATES = [];
var SETTINGS = { late_cancel_fee: 30 };
var CALC = { km: 5, kg: 3 };
var BUILTIN = { bike: 1, moto: 1, car: 1, van: 1 };
var LIKE = [['bike', 'a bicycle'], ['moto', 'a motorbike'], ['car', 'a car'], ['van', 'a van / pickup']];
var RANK_KEY = { 1: 'bike', 2: 'moto', 3: 'car', 4: 'van' };
var NUM = [['base_fee', 'Base fee (ETB)', 'Charged on every package'], ['per_km', 'Per km (ETB)', 'Added for each kilometre'], ['free_kg', 'Free weight (kg)', 'No weight charge up to here'],
           ['per_kg_over', 'Per extra kg (ETB)', 'For every kg above the free weight'], ['min_fee', 'Minimum price (ETB)', 'Never charge less than this'], ['max_weight_kg', 'Maximum weight (kg)', 'Heavier packages are refused']];

function panel() { return document.getElementById('panel-packages'); }
function price(r, km, kg) {
  var fee = Number(r.base_fee) + Number(r.per_km) * km, over = kg - Number(r.free_kg);
  if (over > 0) fee += Number(r.per_kg_over) * over;
  if (fee < Number(r.min_fee)) fee = Number(r.min_fee);
  return Math.round(fee);
}
function note(c, msg, bad) { var n = c.querySelector('[data-note]'); if (n) { n.textContent = msg; n.style.color = bad ? '#d6364a' : '#1fa463'; } }

function likeSelect(cur) {
  return '<select name="size_like" class="ak-in">' + LIKE.map(function (l) { return '<option value="' + l[0] + '"' + (l[0] === cur ? ' selected' : '') + '>' + l[1] + '</option>'; }).join('') + '</select>';
}
function extraFields(r) {
  return '<label style="flex:1 1 200px;font-size:12px">Carries about the same as' + likeSelect(r ? RANK_KEY[Number(r.size_rank)] || 'moto' : 'moto') + '</label>' +
    '<label style="flex:2 1 240px;font-size:12px">Couriers with these vehicle words get its jobs<input name="courier_names" class="ak-in" value="' + esc(r ? r.courier_names : '') + '" maxlength="190" placeholder="for example: bajaj, tuk-tuk"></label>';
}
function numFields(r, def) {
  return '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px">' + NUM.map(function (f) {
    return '<label style="font-size:12px" title="' + esc(f[2]) + '">' + esc(f[1]) + '<input name="' + f[0] + '" type="number" inputmode="decimal" min="0" step="any" class="ak-in" value="' + esc(r ? r[f[0]] : def[f[0]]) + '"></label>';
  }).join('') + '</div>';
}
function card(r) {
  var on = Number(r.active) === 1 || r.active === true || r.active === '1';
  return '<form data-class="' + esc(r.vehicle_class) + '" class="ak-card" style="cursor:default;margin-bottom:14px">' +
    '<div class="ak-top"><div class="ak-av" style="--a:#E8C14A;--b:#9a7a1c;font-size:22px">' + esc(r.icon || '📦') + '</div>' +
    '<div class="ak-nm"><b>' + esc(r.label) + '</b><small>' + esc(BUILTIN[r.vehicle_class] ? 'Built-in vehicle' : 'Custom vehicle') + ' · up to ' + esc(r.max_weight_kg) + ' kg</small></div>' +
    '<span data-state>' + K.pill(on ? 'Offered to customers' : 'Switched off', on ? 'green' : 'grey') + '</span></div>' +
    '<div data-ladder class="ak-chips" aria-label="Sample prices"></div>' +
    '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin:12px 0 10px">' +
    '<label style="flex:0 0 74px;font-size:12px">Icon<input name="icon" class="ak-in" value="' + esc(r.icon) + '" maxlength="8"></label>' +
    '<label style="flex:1 1 160px;font-size:12px">Name customers see<input name="label" class="ak-in" value="' + esc(r.label) + '" maxlength="60"></label>' +
    '<label style="font-size:13px;display:flex;gap:6px;align-items:center;padding-bottom:9px;cursor:pointer"><input type="checkbox" name="active"' + (on ? ' checked' : '') + ' style="width:auto;margin:0"> Offer this vehicle</label></div>' +
    (BUILTIN[r.vehicle_class] ? '' : '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px">' + extraFields(r) + '</div>') +
    numFields(r) +
    '<div class="ak-row" style="margin-top:12px"><span data-preview style="font-size:13px"></span><button type="submit" class="ak-btn gold">Save ' + esc(BUILTIN[r.vehicle_class] ? r.vehicle_class : r.label) + '</button></div>' +
    '<div data-note role="status" style="font-size:12.5px;min-height:18px;margin-top:6px"></div></form>';
}

function formRate(f) {
  var o = {};
  NUM.forEach(function (n) { o[n[0]] = f.elements[n[0]].value; });
  o.label = f.elements.label.value; o.icon = f.elements.icon.value; o.active = f.elements.active.checked;
  if (f.elements.size_like) { o.size_like = f.elements.size_like.value; o.courier_names = f.elements.courier_names.value; }
  return o;
}

function render() {
  var el = panel(); if (!el) return;
  K.css();
  var active = RATES.filter(function (r) { return Number(r.active) === 1 || r.active === true; }).length;
  var cheapest = RATES.length ? Math.min.apply(null, RATES.map(function (r) { return price(r, 5, 3); })) : 0;
  var h = '<div class="ak">' + K.hero('Package prices', 'What each vehicle charges when a customer sends a package. Price = base fee + per km × distance + per extra kg above the free weight, never below the minimum. Changes apply to new bookings at once.', [
    ['Vehicle types', K.num(RATES.length)], ['Offered now', K.num(active)], ['Cheapest 5 km / 3 kg', RATES.length ? K.money(cheapest) : '—']]) +
    '<div class="ak-card" style="cursor:default;margin-bottom:14px"><div style="font-weight:800;margin-bottom:6px">🧮 Price calculator</div>' +
    '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end"><label style="font-size:12px">Try a distance (km)<input id="pkg-calc-km" type="number" min="1" step="any" value="' + esc(CALC.km) + '" class="ak-in" style="width:130px"></label>' +
    '<label style="font-size:12px">Try a weight (kg)<input id="pkg-calc-kg" type="number" min="0" step="any" value="' + esc(CALC.kg) + '" class="ak-in" style="width:130px"></label>' +
    '<div style="font-size:12px;color:var(--mute);padding-bottom:10px">Each card shows the price a customer would see.</div></div></div>';
  h += '<form data-setting="late_cancel_fee" class="ak-card" style="cursor:default;margin-bottom:14px">' +
    '<div style="font-weight:800;margin-bottom:2px">↩️ Cancelling after a courier accepted</div>' +
    '<div style="font-size:12px;color:var(--mute);margin-bottom:10px">A sender can cancel for free until a courier accepts. After that, if they paid online, this fee is kept out of their refund to pay for the courier\'s trip. Cash packages are never charged. Put 0 to keep cancelling always free.</div>' +
    '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end"><label style="font-size:12px">Cancel fee (ETB)<input name="value" type="number" inputmode="decimal" min="0" max="5000" step="any" value="' + esc(SETTINGS.late_cancel_fee) + '" class="ak-in" style="width:150px"></label>' +
    '<button type="submit" class="ak-btn gold">Save fee</button></div><div data-note role="status" style="font-size:12.5px;min-height:18px;margin-top:6px"></div></form>';
  h += '<div style="margin-bottom:14px"><button type="button" data-add class="ak-btn gold">＋ Add a vehicle type</button><div data-add-host></div></div>';
  if (!RATES.length) h += K.empty('📦', 'No vehicle prices found', 'Add a vehicle type to start taking package bookings.');
  h += RATES.map(card).join('') + '</div>';
  el.innerHTML = h;
  refreshAll();
  wireOnce(el);
}

function refreshAll() {
  var el = panel(); if (!el) return;
  var km = Number(CALC.km) || 0, kg = Number(CALC.kg) || 0;
  Array.prototype.forEach.call(el.querySelectorAll('form[data-class]'), function (f) {
    var r = formRate(f), p = f.querySelector('[data-preview]');
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
  });
  el.addEventListener('change', function (e) { if (e.target.name === 'active') refreshAll(); });
  el.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('[data-add]')) openAdd(el);
    else if (e.target.closest && e.target.closest('[data-cancel-add]')) { var h = el.querySelector('[data-add-host]'); if (h) h.innerHTML = ''; }
  });
  el.addEventListener('submit', function (e) {
    var f = e.target; e.preventDefault();
    var btn = f.querySelector('button[type=submit]');
    if (f.hasAttribute('data-setting')) {
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
        if (K.okRes(res)) { var d = K.data(res); if (d.rates) RATES = d.rates; note(f, d.message || 'Saved.', false); K.toast('Prices saved'); }
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
          render();
          var c = panel() && panel().querySelector('form[data-class="' + String(d.vehicle_class).replace(/[^a-z0-9_-]/gi, '') + '"]'); if (c) { note(c, d.message || 'Added.', false); c.scrollIntoView({ block: 'center' }); }
        } else note(f, K.failText(res, 'Could not add.'), true);
      });
    }
  });
}

function openAdd(el) {
  var host = el.querySelector('[data-add-host]'); if (!host) return;
  if (host.firstChild) { host.innerHTML = ''; return; }
  var DEF = { base_fee: 100, per_km: 20, free_kg: 10, per_kg_over: 5, min_fee: 150, max_weight_kg: 300 };
  host.innerHTML = '<form data-new autocomplete="off" class="ak-card" style="cursor:default;margin-top:10px;border-color:var(--gold)">' +
    '<div style="font-weight:800;margin-bottom:4px">Add a vehicle type</div>' +
    '<div style="font-size:12px;color:var(--mute);margin-bottom:10px">For example a Bajaj or a mini truck. Customers see it next to the other vehicles, and only couriers whose vehicle matches the words below get its jobs.</div>' +
    '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px">' +
    '<label style="flex:0 0 74px;font-size:12px">Icon<input name="icon" class="ak-in" value="🛺" maxlength="8"></label>' +
    '<label style="flex:1 1 160px;font-size:12px">Name customers see<input name="label" class="ak-in" maxlength="60" placeholder="Bajaj"></label>' +
    extraFields(null) + '</div>' + numFields(null, DEF) +
    '<div class="ak-acts" style="margin-top:12px"><button type="submit" class="ak-btn gold">Add vehicle type</button><button type="button" data-cancel-add class="ak-btn">Cancel</button></div>' +
    '<div data-note role="status" style="font-size:12.5px;min-height:18px;margin-top:6px"></div></form>';
}

function load() {
  var el = panel(); if (!el) return;
  K.css();
  if (!RATES.length) el.innerHTML = '<div class="ak">' + K.skeleton(3) + '</div>';
  K.api('admin_package_rates_get').then(function (res) {
    if (!K.okRes(res)) { el.innerHTML = '<div class="ak">' + K.empty('⚠️', 'Could not load prices', K.failText(res)) + '</div>'; return; }
    var dd = K.data(res); RATES = dd.rates || []; if (dd.settings) SETTINGS = dd.settings; render();
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

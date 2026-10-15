/* ETEL Admin - Package prices: what each vehicle charges for a package delivery.
   Staff change the base fee, price per km, free weight, price per extra kg, minimum fee, maximum weight, name, icon,
   and can switch a vehicle off. A calculator shows the price a customer would see before saving.
   Data: api actions admin_package_rates_get, admin_package_rates_save, admin_package_type_add, admin_package_setting_save (api/controllers/PackageController.php).
   Standalone on purpose: it adds its own menu item and page, so no other admin file changes. */
(function () {
'use strict';
var RATES = [];
var SETTINGS = { late_cancel_fee: 30 };
var BTN = 'padding:7px 14px;border-radius:8px;border:0;background:#D4AF37;color:#1A1200;font-weight:700;font-size:12.5px;cursor:pointer';
var BTN2 = 'padding:7px 12px;border-radius:8px;border:1px solid rgba(128,128,128,.4);background:transparent;color:inherit;font-size:12.5px;cursor:pointer';
var FIELD = 'padding:7px 8px;border-radius:8px;border:1px solid rgba(128,128,128,.4);background:transparent;color:inherit;font-size:13px;width:100%;box-sizing:border-box';
var BUILTIN = { bike: 1, moto: 1, car: 1, van: 1 };
var LIKE = [['bike', 'a bicycle'], ['moto', 'a motorbike'], ['car', 'a car'], ['van', 'a van / pickup']];
var RANK_KEY = { 1: 'bike', 2: 'moto', 3: 'car', 4: 'van' };
var NUM = [['base_fee', 'Base fee (ETB)'], ['per_km', 'Per km (ETB)'], ['free_kg', 'Free weight (kg)'], ['per_kg_over', 'Per extra kg (ETB)'], ['min_fee', 'Minimum price (ETB)'], ['max_weight_kg', 'Maximum weight (kg)']];

function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
function api(action, body) {
  var token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
  var url = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
  return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token }, body: JSON.stringify(Object.assign({ action: action }, body || {})) }).then(function (r) { return r.json(); });
}
function panel() { return document.getElementById('panel-packages'); }
function price(r, km, kg) {
  var fee = Number(r.base_fee) + Number(r.per_km) * km, over = kg - Number(r.free_kg);
  if (over > 0) fee += Number(r.per_kg_over) * over;
  if (fee < Number(r.min_fee)) fee = Number(r.min_fee);
  return Math.round(fee);
}
function note(c, msg, bad) { var n = c.querySelector('[data-note]'); if (n) { n.textContent = msg; n.style.color = bad ? '#e74c3c' : '#2ecc71'; } }

function likeSelect(cur) {
  return '<select name="size_like" style="' + FIELD + '">' + LIKE.map(function (l) { return '<option value="' + l[0] + '"' + (l[0] === cur ? ' selected' : '') + '>' + l[1] + '</option>'; }).join('') + '</select>';
}
function extraFields(r) {
  return '<label style="font-size:12px;flex:1 1 200px">Carries about the same as' + likeSelect(r ? RANK_KEY[Number(r.size_rank)] || 'moto' : 'moto') + '</label>' +
    '<label style="font-size:12px;flex:2 1 240px">Couriers with these vehicle words get its jobs<input name="courier_names" value="' + esc(r ? r.courier_names : '') + '" maxlength="190" placeholder="for example: bajaj, tuk-tuk" style="' + FIELD + '"></label>';
}
function card(r) {
  var h = '<form data-class="' + esc(r.vehicle_class) + '" style="border:1px solid rgba(128,128,128,.3);border-radius:12px;padding:12px;margin-bottom:12px">' +
    '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:8px">' +
    '<label style="flex:0 0 70px;font-size:12px">Icon<input name="icon" value="' + esc(r.icon) + '" maxlength="8" style="' + FIELD + '"></label>' +
    '<label style="flex:1 1 160px;font-size:12px">Name customers see<input name="label" value="' + esc(r.label) + '" maxlength="60" style="' + FIELD + '"></label>' +
    '<label style="font-size:13px;display:flex;gap:6px;align-items:center;padding-bottom:7px"><input type="checkbox" name="active"' + (Number(r.active) ? ' checked' : '') + '> Offer this vehicle</label></div>' +
    (BUILTIN[r.vehicle_class] ? '' : '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">' + extraFields(r) + '</div>') +
    '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px">';
  NUM.forEach(function (f) {
    h += '<label style="font-size:12px">' + f[1] + '<input name="' + f[0] + '" type="number" inputmode="decimal" min="0" step="any" value="' + esc(r[f[0]]) + '" style="' + FIELD + '"></label>';
  });
  h += '</div><div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:10px">' +
    '<button type="submit" style="' + BTN + '">Save ' + esc(BUILTIN[r.vehicle_class] ? r.vehicle_class : r.label) + '</button>' +
    '<span data-preview style="font-size:12.5px;opacity:.85"></span></div>' +
    '<div data-note role="status" style="font-size:12.5px;min-height:18px;margin-top:6px"></div></form>';
  return h;
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
  var h = '<div style="margin-bottom:10px"><div style="font-size:12.5px;opacity:.75">What each vehicle charges when a customer sends a package. Price = base fee + per km × distance + per extra kg above the free weight, never below the minimum. Changes apply to new bookings at once.</div></div>' +
    '<div style="border:1px solid rgba(128,128,128,.3);border-radius:12px;padding:10px;margin-bottom:12px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">' +
    '<label style="font-size:12px">Try a distance (km)<input id="pkg-calc-km" type="number" min="1" step="any" value="5" style="' + FIELD + ';width:120px"></label>' +
    '<label style="font-size:12px">Try a weight (kg)<input id="pkg-calc-kg" type="number" min="0" step="any" value="3" style="' + FIELD + ';width:120px"></label>' +
    '<div style="font-size:12px;opacity:.7;padding-bottom:7px">Each card shows the price a customer would see.</div></div>';
  h += '<form data-setting="late_cancel_fee" style="border:1px solid rgba(128,128,128,.3);border-radius:12px;padding:12px;margin-bottom:12px">' +
    '<div style="font-weight:800;margin-bottom:2px">Cancelling after a courier accepted</div>' +
    '<div style="font-size:12px;opacity:.75;margin-bottom:8px">A sender can cancel for free until a courier accepts. After that, if they paid online, this fee is kept out of their refund to pay for the courier\'s trip. Cash packages are never charged. Put 0 to keep cancelling always free.</div>' +
    '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end"><label style="font-size:12px">Cancel fee (ETB)<input name="value" type="number" inputmode="decimal" min="0" max="5000" step="any" value="' + esc(SETTINGS.late_cancel_fee) + '" style="' + FIELD + ';width:140px"></label>' +
    '<button type="submit" style="' + BTN + '">Save fee</button></div><div data-note role="status" style="font-size:12.5px;min-height:18px;margin-top:6px"></div></form>';
  h += '<div style="margin-bottom:12px"><button type="button" data-add style="' + BTN + '">＋ Add a vehicle type</button>' +
    '<div data-add-host></div></div>';
  if (!RATES.length) h += '<div style="padding:14px;border:1px dashed rgba(128,128,128,.4);border-radius:12px;opacity:.8">No vehicle prices found.</div>';
  RATES.forEach(function (r) { h += card(r); });
  el.innerHTML = h;
  function refreshAll() {
    var km = Number(document.getElementById('pkg-calc-km').value) || 0, kg = Number(document.getElementById('pkg-calc-kg').value) || 0;
    Array.prototype.forEach.call(el.querySelectorAll('form[data-class]'), function (f) {
      var r = formRate(f), p = f.querySelector('[data-preview]');
      p.textContent = 'Customer pays ETB ' + price(r, km, kg) + ' for ' + km + ' km, ' + kg + ' kg';
    });
  }
  if (!el._wired) { el._wired = true; el.addEventListener('input', function (e) { var t = e.target; if (t && ((t.closest && t.closest('form[data-class]')) || t.id === 'pkg-calc-km' || t.id === 'pkg-calc-kg')) refreshAll(); }); }
  refreshAll();
  var sf = el.querySelector('form[data-setting]');
  if (sf) sf.onsubmit = function (e) {
    e.preventDefault();
    var b = sf.querySelector('button[type=submit]'); b.disabled = true; note(sf, 'Saving…');
    api('admin_package_setting_save', { key: sf.getAttribute('data-setting'), value: sf.elements.value.value }).then(function (res) {
      b.disabled = false;
      if (res && res.success !== false && !res.error) { var d = res.data || res; if (d.settings) SETTINGS = d.settings; note(sf, d.message || 'Saved.', false); }
      else note(sf, (res && (res.message || res.error)) || 'Could not save.', true);
    }).catch(function () { b.disabled = false; note(sf, 'Could not reach the server. Try again.', true); });
  };
  var addBtn = el.querySelector('[data-add]');
  if (addBtn) addBtn.onclick = function () { openAdd(el); };
  Array.prototype.forEach.call(el.querySelectorAll('form[data-class]'), function (f) {
    f.onsubmit = function (e) {
      e.preventDefault();
      var btn = f.querySelector('button[type=submit]'); btn.disabled = true; note(f, 'Saving…');
      var body = formRate(f); body.vehicle_class = f.getAttribute('data-class');
      api('admin_package_rates_save', body).then(function (res) {
        btn.disabled = false;
        if (res && res.success !== false && !res.error) {
          var d = res.data || res;
          if (d.rates) RATES = d.rates;
          note(f, (d.message || 'Saved.'), false);
        } else note(f, (res && (res.message || res.error)) || 'Could not save.', true);
      }).catch(function () { btn.disabled = false; note(f, 'Could not reach the server. Try again.', true); });
    };
  });
}

function openAdd(el) {
  var host = el.querySelector('[data-add-host]'); if (!host) return;
  if (host.firstChild) { host.innerHTML = ''; return; }
  var h = '<form data-new autocomplete="off" style="border:1px solid #D4AF37;border-radius:12px;padding:12px;margin-top:10px">' +
    '<div style="font-weight:800;margin-bottom:4px">Add a vehicle type</div>' +
    '<div style="font-size:12px;opacity:.75;margin-bottom:8px">For example a Bajaj or a mini truck. Customers see it next to the other vehicles, and only couriers whose vehicle matches the words below get its jobs.</div>' +
    '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:8px">' +
    '<label style="flex:0 0 70px;font-size:12px">Icon<input name="icon" value="🛺" maxlength="8" style="' + FIELD + '"></label>' +
    '<label style="flex:1 1 160px;font-size:12px">Name customers see<input name="label" maxlength="60" placeholder="Bajaj" style="' + FIELD + '"></label>' +
    extraFields(null) + '</div>' +
    '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px">';
  var DEF = { base_fee: 100, per_km: 20, free_kg: 10, per_kg_over: 5, min_fee: 150, max_weight_kg: 300 };
  NUM.forEach(function (f) {
    h += '<label style="font-size:12px">' + f[1] + '<input name="' + f[0] + '" type="number" inputmode="decimal" min="0" step="any" value="' + DEF[f[0]] + '" style="' + FIELD + '"></label>';
  });
  h += '</div><div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px"><button type="submit" style="' + BTN + '">Add vehicle type</button>' +
    '<button type="button" data-cancel-add style="' + BTN2 + '">Cancel</button></div><div data-note role="status" style="font-size:12.5px;min-height:18px;margin-top:6px"></div></form>';
  host.innerHTML = h;
  var f = host.querySelector('form');
  f.querySelector('[data-cancel-add]').onclick = function () { host.innerHTML = ''; };
  f.onsubmit = function (e) {
    e.preventDefault();
    var btn = f.querySelector('button[type=submit]'); btn.disabled = true; note(f, 'Adding…');
    var body = {}; NUM.forEach(function (n) { body[n[0]] = f.elements[n[0]].value; });
    body.label = f.elements.label.value; body.icon = f.elements.icon.value; body.active = true;
    body.size_like = f.elements.size_like.value; body.courier_names = f.elements.courier_names.value;
    api('admin_package_type_add', body).then(function (res) {
      btn.disabled = false;
      if (res && res.success !== false && !res.error) {
        var d = res.data || res;
        if (d.rates) RATES = d.rates;
        render();
        var c = panel() && panel().querySelector('form[data-class="' + d.vehicle_class + '"]'); if (c) { note(c, d.message || 'Added.', false); c.scrollIntoView({ block: 'center' }); }
      } else note(f, (res && (res.message || res.error)) || 'Could not add.', true);
    }).catch(function () { btn.disabled = false; note(f, 'Could not reach the server. Try again.', true); });
  };
}

function load() {
  var el = panel(); if (!el) return;
  el.innerHTML = '<div style="padding:14px;opacity:.7">Loading…</div>';
  api('admin_package_rates_get').then(function (res) {
    if (!res || res.success === false || res.error) { el.innerHTML = '<div style="padding:14px;color:#e74c3c">' + esc((res && (res.message || res.error)) || 'Could not load prices.') + '</div>'; return; }
    var dd = res.data || res; RATES = dd.rates || []; if (dd.settings) SETTINGS = dd.settings; render();
  }).catch(function (e) { el.innerHTML = '<div style="padding:14px;color:#e74c3c">Package prices: ' + esc(e.message) + '</div>'; });
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

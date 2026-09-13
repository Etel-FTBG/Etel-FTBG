/* ETEL Admin Pricing Panel
   Lets an admin control ride fare (per vehicle type) and delivery zone
   fees from the panel instead of them being hardcoded in PHP - see
   RideController.php's rides_fare_rule()/rides_surge_multiplier(),
   OrderController.php's customer_create_order zone-fee lookup, and
   boot.php's get_zones, all of which read the tables this panel edits
   (migration 007_ride_fare_rules.sql) with hardcoded fallbacks if that
   migration hasn't been applied yet. */
(function(){
'use strict';

var VEHICLE_LABELS = { bajaj:'🛺 Bajaj', moto:'🏍️ Moto', car:'🚗 Car', minibus:'🚐 Minibus' };
var VEHICLE_ORDER = ['bajaj','moto','car','minibus'];

// Falls back to a generic icon + capitalized name for any vehicle type an
// admin adds beyond the original 4 built-in ones.
function vehicleLabel(vt){
  if (VEHICLE_LABELS[vt]) return VEHICLE_LABELS[vt];
  return '🚘 ' + vt.charAt(0).toUpperCase() + vt.slice(1).replace(/_/g, ' ');
}

function api(action, body, ok, fail){
  var token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
  var apiUrl = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
  var payload = Object.assign({action:action}, body||{});
  fetch(apiUrl, {method:'POST', headers:{'Content-Type':'application/json','Authorization':'Bearer '+token}, body:JSON.stringify(payload)})
    .then(function(r){ return r.json(); })
    .then(function(d){ if (d.success) ok(d.data); else if (fail) fail(d.message||'Error'); })
    .catch(function(e){ if (fail) fail(e.message); });
}

function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function num(v){ return (v===''||v==null||isNaN(v)) ? 0 : parseFloat(v); }

function toast(msg, type){
  if (typeof window.toast === 'function') { window.toast(msg, type); return; }
  console.log('[pricing]', msg);
}

// Nothing in the site's CSS styles .data-table th/td or plain <input>
// elements for a dark panel, so they fell back to browser defaults (dark
// grey text on this panel's dark background) - the data was loading fine,
// it just wasn't visible. Injecting explicit high-contrast styles scoped
// to just these two panel ids so nothing else on the admin page is
// affected.
function ensurePricingStyles(){
  if (document.getElementById('etel-pricing-styles')) return;
  var css = '#pricing-fare-rules table, #pricing-zones table, #pricing-tiers table, #pricing-bank-accounts table, #pricing-goal-tiers table { border-collapse:collapse; color:#fff; }' +
    '#pricing-fare-rules th, #pricing-zones th, #pricing-tiers th, #pricing-bank-accounts th, #pricing-goal-tiers th { color:#f0cf5a; font-size:13px; font-weight:800; text-transform:uppercase; letter-spacing:.4px; padding:12px 10px; text-align:left; border-bottom:2px solid #444; }' +
    '#pricing-fare-rules td, #pricing-zones td, #pricing-tiers td, #pricing-bank-accounts td, #pricing-goal-tiers td { padding:10px; font-size:15px; color:#fff; border-bottom:1px solid #333; }' +
    '#pricing-fare-rules input, #pricing-zones input, #pricing-tiers input, #pricing-bank-accounts input, #pricing-goal-tiers input, #pricing-peak-windows input[type=text], #pricing-peak-windows input[type=time] { background:#111; color:#fff; font-size:15px; font-weight:700; border:1.5px solid #6b5522; border-radius:6px; padding:8px 10px; width:100%; box-sizing:border-box; }' +
    '#pricing-fare-rules input:focus, #pricing-zones input:focus, #pricing-tiers input:focus, #pricing-bank-accounts input:focus, #pricing-goal-tiers input:focus, #pricing-peak-windows input:focus { border-color:#f0cf5a; outline:none; }' +
    '#pricing-fare-rules input[type=checkbox], #pricing-zones input[type=checkbox], #pricing-tiers input[type=checkbox], #pricing-bank-accounts input[type=checkbox], #pricing-goal-tiers input[type=checkbox], #pricing-peak-windows input[type=checkbox] { width:20px; height:20px; accent-color:#f0cf5a; }' +
    '#pricing-peak-windows input[type=time]::-webkit-calendar-picker-indicator { filter: invert(0.8) sepia(1) saturate(3) hue-rotate(0deg); cursor:pointer; }' +
    '#pricing-peak-windows input[type=time]:disabled { opacity:.4; }' +
    '#pricing-fare-rules label, #pricing-zones label, #pricing-tiers label, #pricing-bank-accounts label, #pricing-goal-tiers label, #pricing-peak-windows label { color:#f0cf5a; }' +
    '#pricing-peak-windows .pw-row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; padding:12px; margin-bottom:10px; background:#1a1a1a; border:1px solid #444; border-radius:8px; }' +
    '#pricing-peak-windows .pw-label-input { width:160px; }' +
    '#pricing-peak-windows .pw-time-input { width:110px; text-align:center; }' +
    '#pricing-peak-windows .pw-mult-input { width:60px; text-align:center; background:#111; color:#fff; border:1.5px solid #6b5522; border-radius:6px; padding:6px 6px; }' +
    '#pricing-peak-windows .pw-day-btn { width:30px; height:30px; border-radius:6px; border:1.5px solid #6b5522; background:#111; color:#999; font-size:11px; font-weight:800; cursor:pointer; }' +
    '#pricing-peak-windows .pw-day-btn.active { background:#f0cf5a; color:#1a1206; border-color:#f0cf5a; }';
  var style = document.createElement('style');
  style.id = 'etel-pricing-styles';
  style.textContent = css;
  document.head.appendChild(style);
}

/* ─── Ride fare rules ─────────────────────────────────────────── */

function loadFareRules(){
  var wrap = document.getElementById('pricing-fare-rules');
  if (!wrap) return;
  wrap.innerHTML = '<div style="padding:20px;color:#888">Loading…</div>';
  api('admin_get_ride_fare_rules', {}, function(d){
    renderFareRules(d.rules||[], d.ride_surge_multiplier);
  }, function(msg){
    wrap.innerHTML = '<div style="padding:16px;color:#c0392b;background:#fdecea;border-radius:8px">' +
      esc(msg) + '<br><span style="font-size:12px;color:#888">Has migration 007_ride_fare_rules.sql been applied? (api/migrate.php --apply)</span></div>';
  });
}

function renderFareRules(rules, surge){
  // Was fixed to VEHICLE_ORDER's 4 built-in types - now renders whatever
  // rows the server actually has (built-ins first in their usual order,
  // then any admin-added types alphabetically), so newly added vehicle
  // types show up here without a code change.
  var byType = {};
  rules.forEach(function(r){ byType[r.vehicle_type] = r; });
  var extra = Object.keys(byType).filter(function(vt){ return VEHICLE_ORDER.indexOf(vt) === -1; }).sort();
  var order = VEHICLE_ORDER.concat(extra);

  var rows = order.map(function(vt){
    var r = byType[vt];
    if (!r) return ''; // built-in type with no row yet (migration not applied) - skip rather than show fake zeros
    return '<tr data-vt="' + vt + '">' +
      '<td style="font-weight:700">' + vehicleLabel(vt) + '</td>' +
      '<td><input type="number" step="0.01" min="0" class="pf-input" data-f="base_fare" value="' + esc(r.base_fare) + '"></td>' +
      '<td><input type="number" step="0.01" min="0" class="pf-input" data-f="per_km_rate" value="' + esc(r.per_km_rate) + '"></td>' +
      '<td><input type="number" step="0.01" min="0" class="pf-input" data-f="per_km_rate_open" value="' + esc(r.per_km_rate_open) + '"></td>' +
      '<td><input type="number" step="0.01" min="0" class="pf-input" data-f="wait_rate_per_min" value="' + esc(r.wait_rate_per_min) + '"></td>' +
      '<td><input type="number" step="0.01" min="0" class="pf-input" data-f="min_fare" value="' + esc(r.min_fare) + '"></td>' +
      '<td><input type="number" step="0.01" min="0" class="pf-input" data-f="min_fare_short_trip" value="' + esc(r.min_fare_short_trip) + '"></td>' +
      '<td style="text-align:center"><input type="checkbox" data-f="active" ' + (parseInt(r.active,10) ? 'checked' : '') + '></td>' +
      '<td style="white-space:nowrap">' +
        '<button class="btn btn-primary" style="padding:6px 12px;font-size:12px" onclick="ETELPricing.saveFareRule(\'' + vt + '\')">💾 Save</button> ' +
        '<button class="btn btn-danger" style="padding:6px 10px;font-size:12px" onclick="ETELPricing.deleteVehicleType(\'' + vt + '\')">🗑️</button>' +
      '</td>' +
      '</tr>';
  }).join('');

  document.getElementById('pricing-fare-rules').innerHTML =
    '<table class="data-table" style="width:100%">' +
    '<thead><tr>' +
      '<th>Vehicle</th><th>Base Fare</th><th>Per-KM (metered)</th><th>Per-KM (open trip)</th>' +
      '<th>Wait / min</th><th>Min Fare</th><th>Min Fare (≤3km)</th><th>Active</th><th></th>' +
    '</tr></thead><tbody>' + rows + '</tbody></table>' +
    '<div style="margin-top:16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">' +
      '<label style="font-weight:700;font-size:13px">Peak-hour surge multiplier</label>' +
      '<input type="number" step="0.01" min="0.1" max="5" id="pf-surge" value="' + esc(surge) + '" style="width:90px">' +
      '<button class="btn btn-primary" style="padding:6px 12px;font-size:12px" onclick="ETELPricing.saveSurge()">💾 Save</button>' +
      '<span style="font-size:12px;color:#ccc">Default for new windows below - each window in "Peak-hour schedule" can override this with its own multiplier</span>' +
    '</div>' +
    '<div style="margin-top:16px;padding:14px;background:#1a1a1a;border:1px solid #444;border-radius:8px">' +
      '<div style="font-weight:700;font-size:13px;margin-bottom:8px;color:#f0cf5a">Add a vehicle type</div>' +
      '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">' +
        '<input type="text" id="pf-new-type" placeholder="e.g. tricycle" style="width:140px" oninput="ETELPricing.previewVehicleTypeSlug()">' +
        '<input type="number" id="pf-new-base" placeholder="Base fare (ETB)" style="width:130px">' +
        '<input type="number" id="pf-new-perkm" placeholder="Per-KM rate (ETB)" style="width:140px">' +
        '<button class="btn btn-primary" style="padding:7px 14px;font-size:12px" onclick="ETELPricing.addVehicleType()">➕ Add Vehicle Type</button>' +
      '</div>' +
      '<div id="pf-new-type-preview" style="font-size:12px;color:#ccc;margin-top:6px"></div>' +
      '<div style="font-size:12px;color:#ccc;margin-top:2px">Type it however feels natural (spaces, capitals, an emoji if you like) - it\'ll be cleaned up automatically to a plain identifier like "tricycle" or "three_wheeler" before saving. Open-trip rate, wait rate, and minimum fares default from these two values - fine-tune them afterward with Save above.</div>' +
    '</div>';
}

// Cleans up whatever the admin typed (spaces, capitals, emoji, punctuation)
// into the plain lowercase_underscore identifier the backend actually
// requires - shown live so there's no surprise rejection after typing
// something like "Qute 🚗", which previously just failed with no
// explanation of what WOULD have worked.
function slugifyVehicleType(raw){
  var s = (raw || '').toLowerCase();
  s = s.replace(/[^a-z0-9\s_-]/g, '');   // drop emoji/punctuation
  s = s.replace(/[\s-]+/g, '_');          // spaces/hyphens -> underscore
  s = s.replace(/_+/g, '_');              // collapse repeats
  s = s.replace(/^_+|_+$/g, '');          // trim leading/trailing underscores
  s = s.replace(/^[^a-z]+/, '');          // must start with a letter
  return s.slice(0, 20);
}

function previewVehicleTypeSlug(){
  var typeEl = document.getElementById('pf-new-type');
  var preview = document.getElementById('pf-new-type-preview');
  if (!typeEl || !preview) return;
  var slug = slugifyVehicleType(typeEl.value);
  if (!typeEl.value.trim()) { preview.textContent = ''; return; }
  if (slug.length < 2) {
    preview.innerHTML = '<span style="color:#e57373">Needs at least 2 letters/numbers after cleanup</span>';
  } else {
    preview.innerHTML = 'Will be saved as: <b style="color:#f0cf5a">' + esc(slug) + '</b>';
  }
}

function saveFareRule(vt){
  var row = document.querySelector('#pricing-fare-rules tr[data-vt="' + vt + '"]');
  if (!row) return;
  var body = { vehicle_type: vt };
  row.querySelectorAll('.pf-input').forEach(function(inp){ body[inp.dataset.f] = num(inp.value); });
  var activeBox = row.querySelector('input[type=checkbox][data-f=active]');
  body.active = activeBox && activeBox.checked ? 1 : 0;
  api('admin_update_ride_fare_rule', body, function(){
    toast(vehicleLabel(vt) + ' fare rule saved', 'ok');
  }, function(msg){ toast('Save failed: ' + msg, 'err'); });
}

function addVehicleType(){
  var typeEl = document.getElementById('pf-new-type');
  var vt = slugifyVehicleType(typeEl && typeEl.value);
  var base = num((document.getElementById('pf-new-base')||{}).value);
  var perKm = num((document.getElementById('pf-new-perkm')||{}).value);
  if (!/^[a-z][a-z0-9_]{1,19}$/.test(vt)) {
    toast('Type a name with at least 2 letters/numbers (e.g. "tricycle")', 'err');
    return;
  }
  if (base <= 0 || perKm <= 0) {
    toast('Enter a base fare and per-KM rate greater than 0', 'err');
    return;
  }
  api('admin_add_vehicle_type', { vehicle_type: vt, base_fare: base, per_km_rate: perKm }, function(){
    toast(vehicleLabel(vt) + ' added (saved as "' + vt + '")', 'ok');
    loadFareRules();
  }, function(msg){ toast('Add failed: ' + msg, 'err'); });
}

function deleteVehicleType(vt){
  if (!confirm('Deactivate "' + vehicleLabel(vt) + '"? Drivers already registered with this vehicle type and past rides keep their history; it just stops being offered for new rides.')) return;
  api('admin_delete_vehicle_type', { vehicle_type: vt }, function(){
    toast(vehicleLabel(vt) + ' deactivated', 'ok');
    loadFareRules();
  }, function(msg){ toast('Delete failed: ' + msg, 'err'); });
}

function saveSurge(){
  var el = document.getElementById('pf-surge');
  if (!el) return;
  api('admin_update_ride_surge_multiplier', { value: num(el.value) }, function(){
    toast('Surge multiplier saved', 'ok');
  }, function(msg){ toast('Save failed: ' + msg, 'err'); });
}

/* ─── Peak-hour schedule (when surge applies) ───────────────────
   days use PHP date('N') numbering: 1=Mon ... 7=Sun. */
var PW_DAY_LABELS = ['M','T','W','T','F','S','S'];
var pwWindows = [];

function loadPeakWindows(){
  var wrap = document.getElementById('pricing-peak-windows');
  if (!wrap) return;
  wrap.innerHTML = '<div style="padding:20px;color:#888">Loading…</div>';
  api('admin_get_ride_peak_windows', {}, function(d){
    pwWindows = (d.windows || []).map(function(w){
      return { label: w.label || '', days: (w.days || []).map(Number), start: w.start || '00:00', end: w.end || '00:00', multiplier: (w.multiplier != null ? Number(w.multiplier) : null) };
    });
    renderPeakWindows(d.is_default);
  }, function(msg){
    wrap.innerHTML = '<div style="padding:16px;color:#c0392b;background:#fdecea;border-radius:8px">' + esc(msg) + '</div>';
  });
}

function renderPeakWindows(isDefault){
  var wrap = document.getElementById('pricing-peak-windows');
  if (!wrap) return;
  var rows = pwWindows.map(function(w, i){
    var dayBtns = PW_DAY_LABELS.map(function(lbl, d){
      var dayNum = d + 1; // 1=Mon...7=Sun
      var active = w.days.indexOf(dayNum) !== -1;
      return '<button type="button" class="pw-day-btn' + (active ? ' active' : '') + '" data-row="' + i + '" data-day="' + dayNum + '" onclick="ETELPricing.togglePeakDay(this)">' + lbl + '</button>';
    }).join('');
    var isMidnight = w.end === '24:00';
    // Real time-selection controls (native <input type="time"> - opens the
    // device's own clock/scroll picker) instead of free-typed HH:MM text,
    // which was too easy to mistype. Native time inputs can't hold "24:00"
    // (they cap at 23:59), so "until midnight" is its own checkbox that,
    // when checked, disables the end-time picker and the saved value
    // becomes 24:00 regardless of whatever the (disabled) picker shows.
    return '<div class="pw-row" data-row="' + i + '">' +
      '<input type="text" class="pw-label-input" data-f="label" placeholder="Label (e.g. Morning rush)" value="' + esc(w.label) + '">' +
      '<div style="display:flex;gap:4px">' + dayBtns + '</div>' +
      '<input type="time" class="pw-time-input" data-f="start" value="' + esc(w.start) + '">' +
      '<span style="color:#ccc">to</span>' +
      '<input type="time" class="pw-time-input" data-f="end" value="' + (isMidnight ? '23:59' : esc(w.end)) + '"' + (isMidnight ? ' disabled' : '') + '>' +
      '<label style="display:flex;align-items:center;gap:5px;font-size:12px;color:#ccc;white-space:nowrap">' +
        '<input type="checkbox" data-f="midnight"' + (isMidnight ? ' checked' : '') + ' onchange="ETELPricing.toggleMidnight(this)"> until midnight' +
      '</label>' +
      '<label style="display:flex;align-items:center;gap:5px;font-size:12px;color:#ccc;white-space:nowrap">' +
        '×<input type="number" step="0.01" min="0.1" max="5" class="pw-mult-input" data-f="multiplier" value="' + esc(w.multiplier != null ? w.multiplier : '') + '" placeholder="1.30">' +
      '</label>' +
      '<button class="btn btn-danger" style="padding:6px 10px;font-size:12px" onclick="ETELPricing.removePeakWindow(' + i + ')">🗑️</button>' +
    '</div>';
  }).join('');

  wrap.innerHTML =
    (isDefault ? '<div style="font-size:12px;color:#ccc;margin-bottom:10px">Showing the built-in default schedule - nothing has been saved yet, so this is what\'s actually running.</div>' : '') +
    '<div id="pw-rows">' + rows + '</div>' +
    '<div style="display:flex;gap:10px;margin-top:10px">' +
      '<button class="btn btn-primary" style="padding:7px 14px;font-size:12px" onclick="ETELPricing.addPeakWindow()">➕ Add Window</button>' +
      '<button class="btn btn-primary" style="padding:7px 14px;font-size:12px" onclick="ETELPricing.savePeakWindows()">💾 Save Schedule</button>' +
    '</div>' +
    '<div style="font-size:12px;color:#ccc;margin-top:8px">Tap a time field to open your device\'s time picker - these are real Hawassa clock times (24-hour, e.g. 4:00 PM = 16:00), exactly what your phone shows. Check "until midnight" instead of picking an end time for windows that run to 24:00. The × field is that window\'s own surge multiplier (e.g. 1.30 = +30%) - leave it blank to use the global default above. Click the day letters to toggle which days a window applies (M T W T F S S = Mon...Sun).</div>';
}

function readPeakRowsFromDom(){
  document.querySelectorAll('#pw-rows .pw-row').forEach(function(rowEl){
    var i = parseInt(rowEl.dataset.row, 10);
    if (!pwWindows[i]) return;
    pwWindows[i].label = rowEl.querySelector('[data-f=label]').value.trim();
    pwWindows[i].start = rowEl.querySelector('[data-f=start]').value.trim();
    var midnightBox = rowEl.querySelector('[data-f=midnight]');
    var endInput = rowEl.querySelector('[data-f=end]');
    pwWindows[i].end = (midnightBox && midnightBox.checked) ? '24:00' : endInput.value.trim();
    var multVal = rowEl.querySelector('[data-f=multiplier]').value.trim();
    pwWindows[i].multiplier = multVal ? parseFloat(multVal) : null;
  });
}

function toggleMidnight(box){
  var rowEl = box.closest('.pw-row');
  var endInput = rowEl.querySelector('[data-f=end]');
  endInput.disabled = box.checked;
  if (box.checked) endInput.value = '23:59';
}

function togglePeakDay(btn){
  var i = parseInt(btn.dataset.row, 10);
  var day = parseInt(btn.dataset.day, 10);
  if (!pwWindows[i]) return;
  readPeakRowsFromDom();
  var idx = pwWindows[i].days.indexOf(day);
  if (idx === -1) pwWindows[i].days.push(day); else pwWindows[i].days.splice(idx, 1);
  btn.classList.toggle('active');
}

function addPeakWindow(){
  readPeakRowsFromDom();
  var surgeEl = document.getElementById('pf-surge');
  var defaultMult = surgeEl ? parseFloat(surgeEl.value) || 1.3 : 1.3;
  pwWindows.push({ label: 'New window', days: [1,2,3,4,5,6,7], start: '00:00', end: '01:00', multiplier: defaultMult });
  renderPeakWindows(false);
}

function removePeakWindow(i){
  readPeakRowsFromDom();
  pwWindows.splice(i, 1);
  renderPeakWindows(false);
}

function savePeakWindows(){
  readPeakRowsFromDom();
  if (!pwWindows.length) { toast('Add at least one window, or the schedule will never apply surge', 'err'); return; }
  api('admin_update_ride_peak_windows', { windows: pwWindows }, function(){
    toast('Peak-hour schedule saved', 'ok');
    loadPeakWindows();
  }, function(msg){ toast('Save failed: ' + msg, 'err'); });
}

/* ─── Delivery zones ──────────────────────────────────────────── */

function loadZones(){
  var wrap = document.getElementById('pricing-zones');
  if (!wrap) return;
  wrap.innerHTML = '<div style="padding:20px;color:#888">Loading…</div>';
  api('admin_get_zones', {}, function(d){
    renderZones(d.zones||[]);
  }, function(msg){
    wrap.innerHTML = '<div style="padding:16px;color:#c0392b;background:#fdecea;border-radius:8px">' +
      esc(msg) + '<br><span style="font-size:12px;color:#888">Has migration 007_ride_fare_rules.sql been applied? (api/migrate.php --apply)</span></div>';
  });
}

function renderZones(zones){
  var rows = zones.map(function(z){
    return '<tr data-id="' + z.id + '">' +
      '<td><input type="text" class="pz-input" data-f="name" value="' + esc(z.name) + '" style="width:150px"></td>' +
      '<td><input type="number" step="1" min="0" class="pz-input" data-f="base_fare" value="' + esc(z.base_fare) + '" style="width:80px"></td>' +
      '<td><input type="number" step="1" min="0" class="pz-input" data-f="est_minutes" value="' + esc(z.est_minutes) + '" style="width:70px"></td>' +
      '<td><input type="number" step="1" class="pz-input" data-f="sort_order" value="' + esc(z.sort_order) + '" style="width:60px"></td>' +
      '<td style="text-align:center"><input type="checkbox" data-f="active" ' + (parseInt(z.active,10) ? 'checked' : '') + '></td>' +
      '<td style="white-space:nowrap">' +
        '<button class="btn btn-primary" style="padding:6px 10px;font-size:12px" onclick="ETELPricing.saveZone(' + z.id + ')">💾</button> ' +
        '<button class="btn btn-danger" style="padding:6px 10px;font-size:12px" onclick="ETELPricing.deleteZone(' + z.id + ',' + "'" + esc(z.name).replace(/'/g,"\\'") + "'" + ')">🗑️</button>' +
      '</td>' +
      '</tr>';
  }).join('');

  document.getElementById('pricing-zones').innerHTML =
    '<table class="data-table" style="width:100%">' +
    '<thead><tr><th>Zone</th><th>Fee (ETB)</th><th>Est. minutes</th><th>Sort</th><th>Active</th><th></th></tr></thead>' +
    '<tbody>' + rows + '</tbody></table>' +
    '<div style="margin-top:16px;padding:14px;background:#1a1a1a;border:1px solid #444;border-radius:8px">' +
      '<div style="font-weight:700;font-size:13px;margin-bottom:8px;color:#f0cf5a">Add a zone</div>' +
      '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">' +
        '<input type="text" id="pz-new-name" placeholder="Zone name" style="width:160px">' +
        '<input type="number" id="pz-new-fee" placeholder="Fee (ETB)" style="width:100px">' +
        '<input type="number" id="pz-new-mins" placeholder="Est. minutes" style="width:110px">' +
        '<button class="btn btn-primary" style="padding:7px 14px;font-size:12px" onclick="ETELPricing.addZone()">➕ Add Zone</button>' +
      '</div>' +
    '</div>';
}

function saveZone(id){
  var row = document.querySelector('#pricing-zones tr[data-id="' + id + '"]');
  if (!row) return;
  var body = { id: id };
  row.querySelectorAll('.pz-input').forEach(function(inp){
    body[inp.dataset.f] = (inp.dataset.f === 'name') ? inp.value.trim() : num(inp.value);
  });
  var activeBox = row.querySelector('input[type=checkbox][data-f=active]');
  body.active = activeBox && activeBox.checked ? 1 : 0;
  if (!body.name) { toast('Zone name cannot be empty', 'err'); return; }
  api('admin_update_zone', body, function(){
    toast('Zone saved', 'ok');
  }, function(msg){ toast('Save failed: ' + msg, 'err'); });
}

function addZone(){
  var name = (document.getElementById('pz-new-name')||{}).value || '';
  var fee  = num((document.getElementById('pz-new-fee')||{}).value);
  var mins = num((document.getElementById('pz-new-mins')||{}).value) || 20;
  name = name.trim();
  if (!name) { toast('Enter a zone name', 'err'); return; }
  api('admin_add_zone', { name: name, base_fare: fee, est_minutes: mins }, function(){
    toast('Zone added', 'ok');
    loadZones();
  }, function(msg){ toast('Add failed: ' + msg, 'err'); });
}

function deleteZone(id, name){
  if (!confirm('Deactivate zone "' + name + '"? It will stop appearing to customers but past orders keep their history.')) return;
  api('admin_delete_zone', { id: id }, function(){
    toast('Zone deactivated', 'ok');
    loadZones();
  }, function(msg){ toast('Delete failed: ' + msg, 'err'); });
}

/* ─── Delivery tiers (moto / car / express cargo) ──────────────── */
// Fixed set of three tiers (multiplier applied on top of a zone's base_fare
// in OrderController.php) - editable, but not addable/deletable from here
// since the customer checkout UI has a hardcoded icon per tier_key.

function loadTiers(){
  var wrap = document.getElementById('pricing-tiers');
  if (!wrap) return;
  wrap.innerHTML = '<div style="padding:20px;color:#888">Loading…</div>';
  api('admin_get_delivery_tiers', {}, function(d){
    renderTiers(d.tiers||[]);
  }, function(msg){
    wrap.innerHTML = '<div style="padding:16px;color:#c0392b;background:#fdecea;border-radius:8px">' +
      esc(msg) + '<br><span style="font-size:12px;color:#888">Has migration 012_delivery_tiers.sql been applied? (api/migrate.php --apply)</span></div>';
  });
}

function renderTiers(tiers){
  var rows = tiers.map(function(t){
    return '<tr data-id="' + t.id + '">' +
      '<td>' + esc(t.tier_key) + '</td>' +
      '<td><input type="text" class="pt-input" data-f="label" value="' + esc(t.label) + '" style="width:150px"></td>' +
      '<td><input type="number" step="0.01" min="0.01" class="pt-input" data-f="multiplier" value="' + esc(t.multiplier) + '" style="width:80px"></td>' +
      '<td><input type="number" step="1" class="pt-input" data-f="eta_delta_min" value="' + esc(t.eta_delta_min) + '" style="width:70px"></td>' +
      '<td><input type="number" step="1" class="pt-input" data-f="sort_order" value="' + esc(t.sort_order) + '" style="width:60px"></td>' +
      '<td style="text-align:center"><input type="checkbox" data-f="active" ' + (parseInt(t.active,10) ? 'checked' : '') + '></td>' +
      '<td><button class="btn btn-primary" style="padding:6px 10px;font-size:12px" onclick="ETELPricing.saveTier(' + t.id + ')">💾</button></td>' +
      '</tr>';
  }).join('');

  document.getElementById('pricing-tiers').innerHTML =
    '<table class="data-table" style="width:100%">' +
    '<thead><tr><th>Key</th><th>Label</th><th>Multiplier</th><th>ETA delta (min)</th><th>Sort</th><th>Active</th><th></th></tr></thead>' +
    '<tbody>' + rows + '</tbody></table>' +
    '<div style="margin-top:10px;font-size:12px;color:#888">Multiplier applies on top of the destination zone\'s fee above. ETA delta is added to (or subtracted from) the zone\'s estimated minutes.</div>';
}

function saveTier(id){
  var row = document.querySelector('#pricing-tiers tr[data-id="' + id + '"]');
  if (!row) return;
  var body = { id: id };
  row.querySelectorAll('.pt-input').forEach(function(inp){
    body[inp.dataset.f] = (inp.dataset.f === 'label') ? inp.value.trim() : num(inp.value);
  });
  var activeBox = row.querySelector('input[type=checkbox][data-f=active]');
  body.active = activeBox && activeBox.checked ? 1 : 0;
  if (!body.label) { toast('Tier label cannot be empty', 'err'); return; }
  if (!body.multiplier || body.multiplier <= 0) { toast('Multiplier must be a positive number', 'err'); return; }
  api('admin_update_delivery_tier', body, function(){
    toast('Tier saved', 'ok');
  }, function(msg){ toast('Save failed: ' + msg, 'err'); });
}

/* ─── Bank transfer accounts (Abysinia / CBE) ──────────────────── */
// Fixed set of two accounts - editable, not addable/deletable, since
// customer.js's checkout only knows how to render these two bank_key
// values (abysinia, cbe).

function loadBankAccounts(){
  var wrap = document.getElementById('pricing-bank-accounts');
  if (!wrap) return;
  wrap.innerHTML = '<div style="padding:20px;color:#888">Loading…</div>';
  api('admin_get_bank_accounts', {}, function(d){
    renderBankAccounts(d.accounts||[]);
  }, function(msg){
    wrap.innerHTML = '<div style="padding:16px;color:#c0392b;background:#fdecea;border-radius:8px">' +
      esc(msg) + '<br><span style="font-size:12px;color:#888">Has migration 014_bank_accounts.sql been applied? (api/migrate.php --apply)</span></div>';
  });
}

function renderBankAccounts(accounts){
  var rows = accounts.map(function(a){
    return '<tr data-id="' + a.id + '">' +
      '<td>' + esc(a.bank_key) + '</td>' +
      '<td><input type="text" class="pb-input" data-f="display_name" value="' + esc(a.display_name) + '" style="width:170px"></td>' +
      '<td><input type="text" class="pb-input" data-f="account_name" value="' + esc(a.account_name) + '" style="width:150px"></td>' +
      '<td><input type="text" class="pb-input" data-f="account_number" value="' + esc(a.account_number) + '" style="width:150px"></td>' +
      '<td style="text-align:center"><input type="checkbox" data-f="active" ' + (parseInt(a.active,10) ? 'checked' : '') + '></td>' +
      '<td><button class="btn btn-primary" style="padding:6px 10px;font-size:12px" onclick="ETELPricing.saveBankAccount(' + a.id + ')">💾</button></td>' +
      '</tr>';
  }).join('');

  document.getElementById('pricing-bank-accounts').innerHTML =
    '<table class="data-table" style="width:100%">' +
    '<thead><tr><th>Bank</th><th>Label shown to customer</th><th>Account name</th><th>Account number</th><th>Active</th><th></th></tr></thead>' +
    '<tbody>' + rows + '</tbody></table>' +
    '<div style="margin-top:10px;font-size:12px;color:#888">Turning "Active" off makes that bank transfer option disappear from checkout instead of showing a stale account number.</div>';
}

function saveBankAccount(id){
  var row = document.querySelector('#pricing-bank-accounts tr[data-id="' + id + '"]');
  if (!row) return;
  var body = { id: id };
  row.querySelectorAll('.pb-input').forEach(function(inp){
    body[inp.dataset.f] = inp.value.trim();
  });
  var activeBox = row.querySelector('input[type=checkbox][data-f=active]');
  body.active = activeBox && activeBox.checked ? 1 : 0;
  if (!body.display_name) { toast('Label cannot be empty', 'err'); return; }
  if (!body.account_number) { toast('Account number cannot be empty', 'err'); return; }
  api('admin_update_bank_account', body, function(){
    toast('Bank account saved', 'ok');
  }, function(msg){ toast('Save failed: ' + msg, 'err'); });
}

/* ─── Entry point (called by switchPage in admin-ui.js) ──────── */
// Skips fetching (and skips the resulting "you don't have permission"
// error text) for sub-sections the signed-in staff member can't see.
// Awaits window.ETEL_CAPS_PROMISE (set by admin-roles.js) rather than
// reading window.ETEL_CAPS directly - Pricing is click-triggered so it
// wasn't actually racing in practice, but awaiting the shared promise
// here too keeps this file correct even if that ever changes, and
// matches the pattern used in admin-rides.js where it does matter.
function pricingCanSee(cap){
  return true; // placeholder, real check happens after caps resolve below
}
async function loadPricing(){
  ensurePricingStyles();
  var caps = window.ETEL_CAPS || await window.ETEL_CAPS_PROMISE;
  function can(cap){ return caps.indexOf('*') !== -1 || caps.indexOf(cap) !== -1; }
  if (can('pricing.manage')) { loadFareRules(); loadPeakWindows(); loadGoalTiers(); }
  if (can('settings.view')) { loadZones(); loadTiers(); }
  if (can('finance.view')) { loadBankAccounts(); }
}

/* ─── Driver daily-goal tiers ─────────────────────────────────────
   The "2 trips -> +ETB 250" chips on the driver home screen - used to
   be six values hardcoded directly into driver/index.html. Same
   load/render/save/add/delete pattern as the fare-rules table above. */

function loadGoalTiers(){
  var wrap = document.getElementById('pricing-goal-tiers');
  if (!wrap) return;
  wrap.innerHTML = '<div style="padding:20px;color:#888">Loading…</div>';
  api('admin_get_goal_tiers', {}, function(d){
    renderGoalTiers(d.tiers||[]);
  }, function(msg){
    wrap.innerHTML = '<div style="padding:16px;color:#c0392b;background:#fdecea;border-radius:8px">' + esc(msg) + '</div>';
  });
}

function renderGoalTiers(tiers){
  var sorted = tiers.slice().sort(function(a,b){ return (a.sort_order - b.sort_order) || (a.trips_required - b.trips_required); });
  var rows = sorted.map(function(t){
    return '<tr data-id="' + t.id + '">' +
      '<td><input type="number" min="1" max="200" class="pg-input" data-f="trips_required" value="' + esc(t.trips_required) + '" style="width:90px"></td>' +
      '<td style="text-align:center"><input type="checkbox" data-f="active" ' + (parseInt(t.active,10) ? 'checked' : '') + '></td>' +
      '<td style="white-space:nowrap">' +
        '<button class="btn btn-primary" style="padding:6px 12px;font-size:12px" onclick="ETELPricing.saveGoalTier(' + t.id + ')">💾 Save</button> ' +
        '<button class="btn btn-danger" style="padding:6px 10px;font-size:12px" onclick="ETELPricing.deleteGoalTier(' + t.id + ')">🗑️</button>' +
      '</td>' +
      '</tr>';
  }).join('');

  document.getElementById('pricing-goal-tiers').innerHTML =
    '<div style="font-size:12px;color:#ccc;margin-bottom:12px;padding:10px;background:#1a1a1a;border:1px solid #444;border-radius:8px">Each driver sees their own real projected earnings per tier (trips × their own actual average fare) - not a fixed amount you set here. This only controls WHICH trip-count tiers appear (2, 4, 6...) and whether each is active.</div>' +
    '<table class="data-table" style="width:100%">' +
    '<thead><tr><th>Trips required (today)</th><th>Active</th><th></th></tr></thead>' +
    '<tbody>' + rows + '</tbody></table>' +
    '<div style="margin-top:16px;padding:14px;background:#1a1a1a;border:1px solid #444;border-radius:8px">' +
      '<div style="font-weight:700;font-size:13px;margin-bottom:8px;color:#f0cf5a">Add a goal tier</div>' +
      '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">' +
        '<input type="number" id="pg-new-trips" placeholder="Trips (e.g. 15)" style="width:150px">' +
        '<button class="btn btn-primary" style="padding:7px 14px;font-size:12px" onclick="ETELPricing.addGoalTier()">➕ Add Tier</button>' +
      '</div>' +
    '</div>';
}

function saveGoalTier(id){
  var row = document.querySelector('#pricing-goal-tiers tr[data-id="' + id + '"]');
  if (!row) return;
  var body = { id: id };
  row.querySelectorAll('.pg-input').forEach(function(inp){ body[inp.dataset.f] = num(inp.value); });
  var activeBox = row.querySelector('input[type=checkbox][data-f=active]');
  body.active = activeBox && activeBox.checked ? 1 : 0;
  api('admin_save_goal_tier', body, function(){
    toast('Goal tier saved', 'ok');
    loadGoalTiers();
  }, function(msg){ toast('Save failed: ' + msg, 'err'); });
}

function addGoalTier(){
  var trips = num(document.getElementById('pg-new-trips').value);
  if (!trips || trips < 1) { toast('Enter a valid number of trips', 'err'); return; }
  api('admin_save_goal_tier', { trips_required: trips, active: 1 }, function(){
    toast('Goal tier added', 'ok');
    document.getElementById('pg-new-trips').value = '';
    loadGoalTiers();
  }, function(msg){ toast('Add failed: ' + msg, 'err'); });
}

function deleteGoalTier(id){
  if (!confirm('Delete this goal tier? Drivers currently viewing it will fall back to the next one on their next refresh.')) return;
  api('admin_delete_goal_tier', { id: id }, function(){
    toast('Goal tier deleted', 'ok');
    loadGoalTiers();
  }, function(msg){ toast('Delete failed: ' + msg, 'err'); });
}

window.ETELPricing = {
  loadPricing: loadPricing,
  saveFareRule: saveFareRule,
  addVehicleType: addVehicleType,
  previewVehicleTypeSlug: previewVehicleTypeSlug,
  deleteVehicleType: deleteVehicleType,
  saveSurge: saveSurge,
  togglePeakDay: togglePeakDay,
  toggleMidnight: toggleMidnight,
  addPeakWindow: addPeakWindow,
  removePeakWindow: removePeakWindow,
  savePeakWindows: savePeakWindows,
  saveZone: saveZone,
  addZone: addZone,
  deleteZone: deleteZone,
  saveTier: saveTier,
  saveBankAccount: saveBankAccount,
  saveGoalTier: saveGoalTier,
  addGoalTier: addGoalTier,
  deleteGoalTier: deleteGoalTier
};
window.loadPricing = loadPricing;
})();

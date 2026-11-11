/* Etel Madeya - driver account: "My Vehicles" (saved plates, queue status, own fuel history). Needs an EtelID sign-in. */
(function () {
  'use strict';
  var vehicleTypes = [], histId = 0;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function fmtL(v) { return Number(v || 0).toLocaleString(undefined, { maximumFractionDigits: 1 }); }
  function day(d) { if (!d) return ''; var x = new Date(String(d).slice(0, 10) + 'T00:00:00'); return isNaN(x) ? d : x.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }); }
  function root() { return document.getElementById('mineRoot'); }
  function api(action, params, post) {
    var opt = {}; var url = API + '&action=' + action;
    if (post) { var fd = new FormData(); Object.keys(params || {}).forEach(function (k) { fd.append(k, params[k]); }); opt = { method: 'POST', body: fd }; }
    else if (params) url += '&' + Object.keys(params).map(function (k) { return k + '=' + encodeURIComponent(params[k]); }).join('&');
    return fetch(url, opt).then(function (r) { return r.json(); });
  }

  window.initMinePage = function () {
    var r = root(); if (!r) return;
    api('driver_my_vehicles').then(function (d) {
      if (d && d.code === 'NOT_SIGNED_IN') {
        r.innerHTML = (window.madLockCard ? madLockCard() : '<p>Please sign in with your EtelID.</p>');
        return;
      }
      if (!d || !d.success) { r.innerHTML = '<p class="mine-empty">Could not load your vehicles. Try again.</p>'; return; }
      render(d);
    }).catch(function () { r.innerHTML = '<p class="mine-empty">Connection problem. Try again.</p>'; });
  };

  function vehicleCard(v) {
    var st = v.status || {}, now = v.now;
    var queue = now ? '<div class="mine-line mine-now">⏳ In the ' + (now.queue_type === 'vip' ? 'VIP' : now.queue_type === 'waiting' ? 'waiting' : '') + ' queue at <b>' + esc(now.station) + '</b>' + (now.queue_type === 'normal' ? ' &middot; position <b>#' + now.position + '</b>' : '') + '</div>'
      : '<div class="mine-line mine-muted">Not in a queue right now</div>';
    var last = st.last ? '<div class="mine-line">Last fuel: <b>' + esc(day(st.last.date)) + '</b> at ' + esc(st.last.station) + (st.last.liters != null ? ' &middot; ' + fmtL(st.last.liters) + ' L' : '') + '</div>' : '<div class="mine-line mine-muted">No fuel recorded yet</div>';
    var next = st.next_date ? (st.can_fuel_now ? '<div class="mine-line mine-ok">✅ Can fuel now</div>' : '<div class="mine-line mine-wait">🕒 Can fuel again on <b>' + esc(day(st.next_date)) + '</b></div>') : (st.last ? '<div class="mine-line mine-ok">✅ Can fuel now</div>' : '');
    return '<div class="mine-card"><div class="mine-top"><div class="mine-plate">' + (window.plateHtml ? plateHtml(v.plate_number) : esc(v.plate_number)) + '</div>' +
      '<div class="mine-type">' + (window.vehPic ? vehPic(v.icon, v.type_name, 30) : '') + '<span>' + esc(v.type_name) + '</span></div></div>' +
      (v.nickname ? '<div class="mine-nick">' + esc(v.nickname) + '</div>' : '') + queue + last + next +
      '<div class="mine-actions"><button type="button" class="btn btn-primary" onclick="mineCheck(\'' + esc(v.plate_number) + '\')">Check position</button>' +
      '<button type="button" class="btn btn-secondary" onclick="mineHistory(' + v.id + ')">Fuel history</button>' +
      '<button type="button" class="btn mine-del" onclick="mineRemove(' + v.id + ')">Remove</button></div></div>';
  }

  function render(d) {
    var r = root(); if (!r) return;
    var list = d.vehicles || [];
    r.innerHTML = '<h1 class="page-title" data-t="My Vehicles|ተሽከርካሪዎቼ">My Vehicles</h1>' +
      '<p class="page-subtitle">Signed in as <b>' + esc(d.name || '') + '</b> &middot; save up to ' + d.max + ' plates to see your queue position and your own fuel history.</p>' +
      (list.length ? '<div class="mine-grid">' + list.map(vehicleCard).join('') + '</div>' : '<div class="mine-empty">You have not saved a vehicle yet. Add your plate below.</div>') +
      (list.length < d.max ? addForm() : '<p class="mine-muted" style="text-align:center">You saved the maximum of ' + d.max + ' vehicles. Remove one to add another.</p>') +
      '<h2 class="mine-h2" data-t="Fuel history|የነዳጅ ታሪክ">Fuel history</h2><div id="mineHist" class="mine-hist"><p class="mine-muted">Loading...</p></div>';
    if (list.length < d.max) initAddForm();
    loadHistory(0);
  }

  function addForm() {
    return '<div class="card mine-add"><h3>Add a vehicle</h3>' +
      '<label>1. Plate category</label><div id="mineCats" class="pcats"></div>' +
      '<label>2. Region</label><div id="mineRegs" class="pregs"></div>' +
      '<label for="minePlate">3. Plate number</label><input type="text" id="minePlate" class="form-control" placeholder="06369" inputmode="numeric" maxlength="8">' +
      '<label for="mineType">4. Vehicle type</label><select id="mineType" class="form-control"></select>' +
      '<label for="mineNick">Name for this vehicle (optional)</label><input type="text" id="mineNick" class="form-control" maxlength="40" placeholder="e.g. My taxi">' +
      '<div id="mineErr" class="mine-err"></div><button type="button" class="btn btn-primary btn-block" onclick="mineAdd()">Save vehicle</button></div>';
  }
  function initAddForm() {
    if (window.plateCatButtons) plateCatButtons('mineCats', 'mad_mine_cat');
    if (window.plateRegButtons) plateRegButtons('mineRegs', 'mad_mine_reg');
    if (window.plateNumeric) plateNumeric(document.getElementById('minePlate'));
    var sel = document.getElementById('mineType');
    function fill() { sel.innerHTML = '<option value="">Choose vehicle type</option>' + vehicleTypes.map(function (t) { return '<option value="' + t.id + '">' + esc(t.name) + '</option>'; }).join(''); }
    if (vehicleTypes.length) fill();
    else api('get_vehicle_types').then(function (d) { vehicleTypes = (d && (d.vehicle_types || d.types || d.data)) || []; fill(); });
  }

  window.mineAdd = function () {
    var err = document.getElementById('mineErr'); err.textContent = '';
    var cat = window.plateCatSelected ? plateCatSelected('mineCats') : '', reg = window.plateRegSelected ? plateRegSelected('mineRegs') : '';
    var digits = (document.getElementById('minePlate').value || '').replace(/\D+/g, ''), type = document.getElementById('mineType').value;
    if (!cat || !reg || !digits) { err.textContent = 'Choose the plate category and region, and type the plate number.'; return; }
    if (!type) { err.textContent = 'Choose the vehicle type.'; return; }
    api('driver_add_vehicle', { plate_number: plateBuild(cat, reg, digits), vehicle_type_id: type, nickname: document.getElementById('mineNick').value }, true).then(function (d) {
      if (d && d.success) { initMinePage(); if (window.showNotification) showNotification('Vehicle saved'); }
      else err.textContent = (d && d.message) || 'Could not save';
    }).catch(function () { err.textContent = 'Connection problem. Try again.'; });
  };
  window.mineRemove = function (id) {
    if (!confirm('Remove this vehicle from your account? Your fuel history stays in the station records.')) return;
    api('driver_remove_vehicle', { id: id }, true).then(function () { initMinePage(); });
  };
  window.mineCheck = function (plate) {
    navigate('check');
    setTimeout(function () { var i = document.getElementById('checkPlate'); if (i) { i.value = String(plate).replace(/^[1-5]-/, '').replace(/^[A-Z]{2}-/, ''); if (window.checkPosition) { try { checkPosition({ preventDefault: function () {} }); } catch (e) {} } } }, 60);
  };
  window.mineHistory = function (id) { histId = id; loadHistory(id); var h = document.getElementById('mineHist'); if (h) h.scrollIntoView({ behavior: 'smooth', block: 'start' }); };

  function loadHistory(id) {
    api('driver_fuel_history', id ? { id: id } : null).then(function (d) {
      var box = document.getElementById('mineHist'); if (!box) return;
      if (!d || !d.success) { box.innerHTML = '<p class="mine-muted">Could not load the history.</p>'; return; }
      var t = d.totals || {};
      var head = '<div class="mine-kpis"><div><b>' + (t.fills || 0) + '</b><span>fills</span></div><div><b>' + fmtL(t.liters) + ' L</b><span>total</span></div><div><b>' + fmtL(t.month_liters) + ' L</b><span>this month</span></div></div>' +
        (id ? '<p class="mine-muted">Showing one vehicle. <a href="#" onclick="mineHistory(0);return false">Show all</a></p>' : '');
      if (!d.history.length) { box.innerHTML = head + '<p class="mine-muted">No fuel has been recorded for your vehicles yet.</p>'; return; }
      box.innerHTML = head + d.history.map(function (h) {
        return '<div class="mine-row"><div><b>' + esc(day(h.report_date)) + '</b><span>' + esc(h.station_name) + '</span></div><div class="mine-row-r"><span>' + (window.plateHtml ? plateHtml(h.plate_number) : esc(h.plate_number)) + '</span><b>' + (h.liters != null ? fmtL(h.liters) + ' L' : '-') + '</b></div></div>';
      }).join('');
    }).catch(function () { var b = document.getElementById('mineHist'); if (b) b.innerHTML = '<p class="mine-muted">Connection problem.</p>'; });
  }
})();

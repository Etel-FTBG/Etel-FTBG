/* ETEL Admin - Couriers: fix couriers that have no vehicle chosen.
   A courier with no vehicle is treated as a motorbike rider, so a bajaj, car or van driver can miss (or wrongly get) delivery and package jobs.
   This adds a warning bar on top of the Couriers page ("N couriers have no vehicle chosen") and a "Fix vehicles" sheet:
   one row per courier with the real vehicle pictures to tap, a one-tap "use the vehicle from their ride profile" suggestion for couriers who are also
   ride drivers, a Save per row, and "same vehicle for everyone shown" for a batch. Each save is read back from the server, so a vehicle the server did
   not keep is reported instead of looking saved.
   Data: admin_couriers_board (couriers, vehicle_choices, ride_vehicle), admin_update_courier, admin_courier_detail. No inline handlers; server text goes through esc. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;
var D = { rows: [], choices: [], at: 0, busy: false };
var sheet = null;

function css() {
  if (document.getElementById('cnv-css')) return;
  var s = document.createElement('style'); s.id = 'cnv-css';
  s.textContent =
    '.cnv-bar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:0 0 14px;padding:13px 15px;border-radius:16px;border:1.5px solid rgba(240,180,41,.6);background:rgba(240,180,41,.12)}' +
    '.cnv-bar .t{flex:1 1 240px;font-size:13.5px;line-height:1.45}.cnv-bar b{font-weight:800}' +
    '.cnv-ov{position:fixed;inset:0;z-index:9990;background:rgba(8,6,20,.6);backdrop-filter:blur(3px);display:flex;justify-content:center;align-items:flex-start;padding:5vh 12px}' +
    '.cnv-sheet{width:min(720px,100%);max-height:90vh;display:flex;flex-direction:column;border-radius:20px;background:#17152b;color:#f3efe2;border:1.5px solid rgba(212,175,55,.5);box-shadow:0 24px 70px rgba(0,0,0,.5);overflow:hidden}' +
    'body.light-mode .cnv-sheet,body.etel-light .cnv-sheet{background:#fffdf6;color:#1a1200}' +
    '.cnv-sheet>header{display:flex;gap:10px;align-items:center;padding:14px 16px;border-bottom:1px solid rgba(128,128,128,.3)}.cnv-sheet>header h3{margin:0;flex:1;font-size:16px}' +
    '.cnv-x{border:0;width:36px;height:36px;border-radius:50%;background:rgba(128,128,128,.2);color:inherit;font-size:15px;cursor:pointer}' +
    '.cnv-list{overflow-y:auto;padding:10px 14px 18px}' +
    '.cnv-row{padding:12px;margin-top:10px;border-radius:16px;border:1.5px solid rgba(128,128,128,.3);background:rgba(128,128,128,.07)}' +
    '.cnv-row.ok{border-color:rgba(31,164,99,.6)}.cnv-row .top{display:flex;gap:8px;align-items:baseline;flex-wrap:wrap}.cnv-row .top b{font-size:14.5px}.cnv-row .top small{opacity:.7;font-size:12px}' +
    '.cnv-chip{font-size:11px;font-weight:800;padding:2px 8px;border-radius:20px;background:rgba(59,130,246,.2)}' +
    '.cnv-tiles{display:flex;gap:7px;overflow-x:auto;padding:8px 0 4px}' +
    '.cnv-vt{flex:none;width:92px;display:flex;flex-direction:column;align-items:center;gap:2px;padding:7px 4px;border-radius:12px;border:1.5px solid rgba(128,128,128,.35);background:transparent;color:inherit;font:inherit;cursor:pointer;text-align:center}' +
    '.cnv-vt .p{height:44px;display:flex;align-items:center;justify-content:center;font-size:28px}.cnv-vt b{font-size:11.5px;line-height:1.15}.cnv-vt small{font-size:10px;opacity:.7}' +
    '.cnv-vt.on{border-color:#D4AF37;background:rgba(212,175,55,.2);box-shadow:0 0 0 3px rgba(212,175,55,.25)}' +
    '.cnv-act{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:6px}.cnv-act .st{font-size:12px;opacity:.85}' +
    '.cnv-all{padding:12px;border-radius:16px;border:1.5px dashed rgba(212,175,55,.6)}';
  document.head.appendChild(s);
}

function load(force) {
  if (D.busy || (!force && Date.now() - D.at < 20000)) return Promise.resolve();
  D.busy = true;
  return K.api('admin_couriers_board', {}).then(function (res) {
    D.busy = false;
    if (!K.okRes(res)) return;
    var d = K.data(res); D.rows = d.couriers || []; D.choices = d.vehicle_choices || []; D.at = Date.now();
  }, function () { D.busy = false; });
}
function missing() { return D.rows.filter(function (r) { return !r.vehicle_type; }); }
function nameOf(v) { for (var i = 0; i < D.choices.length; i++) if (D.choices[i].value === String(v || '').toLowerCase()) return D.choices[i].label; return String(v || '').replace(/_/g, ' '); }

function inject() {
  var el = document.getElementById('cou-pro'); if (!el) return;
  var ak = el.querySelector('.ak'), bar = document.getElementById('cnv-bar'), n = missing().length;
  if (!ak || !n) { if (bar && bar.parentNode) bar.parentNode.removeChild(bar); return; }
  css();
  var html = '<div class="t">⚠️ <b>' + esc(n) + ' courier' + (n === 1 ? ' has' : 's have') + ' no vehicle chosen.</b> Until one is chosen they count as motorbike riders and may miss jobs for their real vehicle.</div><button type="button" class="ak-btn gold" data-cnv-open>Fix vehicles</button>';
  if (bar && bar.parentNode === ak) { if (bar.innerHTML !== html) bar.innerHTML = html; return; }
  bar = document.createElement('div'); bar.id = 'cnv-bar'; bar.className = 'cnv-bar'; bar.innerHTML = html;
  var hero = ak.querySelector('.ak-hero'); ak.insertBefore(bar, hero ? hero.nextSibling : ak.firstChild);
}

function tiles(selected) {
  return D.choices.map(function (o) {
    var pic = (window.EtelVehiclePic && EtelVehiclePic.key(o.value)) ? EtelVehiclePic.html(o.value, 56, { fallback: o.icon }) : '<span>' + esc(o.icon || '🚚') + '</span>';
    return '<button type="button" class="cnv-vt' + (selected === o.value ? ' on' : '') + '" data-vt="' + esc(o.value) + '" aria-pressed="' + (selected === o.value) + '"><span class="p">' + pic + '</span><b>' + esc(o.label) + '</b><small>' + esc(o.class_label || 'No package jobs') + '</small></button>';
  }).join('');
}
function rowHtml(r) {
  var sug = r.ride_vehicle && !r.vehicle_type ? String(r.ride_vehicle).toLowerCase() : '';
  var known = sug && D.choices.some(function (o) { return o.value === sug; });
  return '<div class="cnv-row" data-id="' + esc(r.id) + '"><div class="top"><b>' + esc(r.name || 'Courier') + '</b><small>' + esc(r.courier_code || '') + ' · ' + esc(r.phone || '') + '</small>' +
    (r.ride_vehicle ? '<span class="cnv-chip">🚕 Ride driver: ' + esc(nameOf(r.ride_vehicle)) + '</span>' : '') + '</div>' +
    '<div class="cnv-tiles" role="group" aria-label="Vehicle for ' + esc(r.name || 'courier') + '">' + tiles('') + '</div>' +
    '<div class="cnv-act">' + (known ? '<button type="button" class="ak-btn" data-use="' + esc(sug) + '">Use their ride vehicle (' + esc(nameOf(sug)) + ')</button>' : '') +
    '<button type="button" class="ak-btn gold" data-save disabled>Save</button><span class="st" role="status"></span></div></div>';
}
function paint() {
  if (!sheet) return;
  var rows = missing(), list = sheet.querySelector('.cnv-list');
  list.innerHTML = rows.length
    ? '<div class="cnv-all"><b>Same vehicle for everyone shown (' + rows.length + ')</b><div class="cnv-tiles" data-all>' + tiles('') + '</div><div class="cnv-act"><button type="button" class="ak-btn" data-all-save disabled>Apply to all</button><span class="st" data-all-st role="status"></span></div></div>' + rows.map(rowHtml).join('')
    : '<div class="ak-empty"><b>✅</b><div style="font-weight:700">Every courier has a vehicle.</div></div>';
  sheet.querySelector('header h3').textContent = rows.length ? 'Choose the vehicle (' + rows.length + ' left)' : 'All done';
}
function openSheet() {
  if (sheet) return;
  css();
  sheet = document.createElement('div'); sheet.className = 'cnv-ov'; sheet.setAttribute('role', 'dialog'); sheet.setAttribute('aria-modal', 'true'); sheet.setAttribute('aria-label', 'Fix courier vehicles');
  sheet.innerHTML = '<div class="cnv-sheet"><header><h3>Choose the vehicle</h3><button type="button" class="cnv-x" data-x aria-label="Close">✕</button></header><div class="cnv-list"></div></div>';
  document.body.appendChild(sheet);
  load(true).then(paint);
  sheet.addEventListener('click', onSheet);
}
function closeSheet() { if (sheet && sheet.parentNode) sheet.parentNode.removeChild(sheet); sheet = null; if (typeof window.loadCouriers === 'function') { try { window.loadCouriers(); } catch (e) { /* page refreshes itself later */ } } }

function pick(group, b) {
  Array.prototype.forEach.call(group.querySelectorAll('[data-vt]'), function (x) { x.classList.toggle('on', x === b); x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
}
function saveOne(id, vt, st) {
  st.textContent = 'Saving…';
  return K.api('admin_update_courier', { courier_id: id, vehicle_type: vt }).then(function (res) {
    if (!K.okRes(res)) { st.textContent = 'Not saved: ' + K.failText(res); return false; }
    return K.api('admin_courier_detail', { id: id }).then(function (r2) {
      var kept = K.okRes(r2) ? String(((K.data(r2).courier || {}).vehicle_type) || '').toLowerCase() : vt;
      if (kept !== vt) { st.textContent = 'The server said saved but kept "' + (kept || 'no vehicle') + '". Upload the latest server files.'; return false; }
      st.textContent = '✓ ' + nameOf(vt); return true;
    });
  });
}
function onSheet(e) {
  var t = e.target; if (!t.closest) return;
  if (t === sheet || t.closest('[data-x]')) { closeSheet(); return; }
  var all = t.closest('[data-all]'), row = t.closest('.cnv-row'), b;
  if (all && (b = t.closest('[data-vt]'))) { pick(all, b); sheet.querySelector('[data-all-save]').disabled = false; return; }
  if (row && (b = t.closest('[data-vt]'))) { pick(row.querySelector('.cnv-tiles'), b); row.querySelector('[data-save]').disabled = false; return; }
  if (row && (b = t.closest('[data-use]'))) { var v = b.getAttribute('data-use'), g = row.querySelector('.cnv-tiles'), tb = g.querySelector('[data-vt="' + v + '"]'); if (tb) { pick(g, tb); row.querySelector('[data-save]').disabled = false; } return; }
  if (row && t.closest('[data-save]')) {
    var sel = row.querySelector('.cnv-tiles .on'); if (!sel) return;
    var btn = t.closest('[data-save]'); btn.disabled = true;
    saveOne(Number(row.getAttribute('data-id')), sel.getAttribute('data-vt'), row.querySelector('.st')).then(function (ok) {
      if (ok) { row.classList.add('ok'); D.rows.forEach(function (r) { if (String(r.id) === row.getAttribute('data-id')) r.vehicle_type = sel.getAttribute('data-vt'); }); setTimeout(function () { paint(); inject(); }, 700); } else btn.disabled = false;
    });
    return;
  }
  if (t.closest('[data-all-save]')) {
    var asel = sheet.querySelector('[data-all] .on'); if (!asel) return; var av = asel.getAttribute('data-vt'), todo = missing(), ast = sheet.querySelector('[data-all-st]');
    K.confirm({ title: 'Set ' + nameOf(av) + ' for ' + todo.length + ' courier' + (todo.length === 1 ? '' : 's') + '?', text: 'Only couriers with no vehicle are changed. You can change any of them later.', label: 'Apply' }).then(function (r) {
      if (!r) return;
      var i = 0, okN = 0, failN = 0;
      (function next() {
        if (i >= todo.length) { ast.textContent = okN + ' saved' + (failN ? ', ' + failN + ' not saved' : ''); load(true).then(function () { paint(); inject(); }); return; }
        var c = todo[i++]; ast.textContent = 'Saving ' + i + ' of ' + todo.length + '…';
        saveOne(c.id, av, document.createElement('span')).then(function (ok) { if (ok) okN++; else failN++; next(); });
      })();
    });
  }
}

function wire() {
  var el = document.getElementById('cou-pro'); if (!el || el._cnv) return; el._cnv = true;
  el.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('[data-cnv-open]')) openSheet(); });
  var t = null;
  new MutationObserver(function () { clearTimeout(t); t = setTimeout(function () { load(false).then(inject); inject(); }, 120); }).observe(el, { childList: true });
  load(false).then(inject);
}
function install() { wire(); setTimeout(wire, 800); setTimeout(wire, 2500); }
document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sheet) closeSheet(); });
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();

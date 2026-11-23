/* ETEL Admin - Courier drawer (premium). Drawn for js/admin-couriers-pro.js when this file is loaded; without it the older drawer still works.
   Shows: readiness ring + checklist (what is still missing before this courier can work), quick call / WhatsApp / copy,
   numbers (today / 7 / 30 days, completion rate, average time, fees, rating), a 14-day bar chart, the vehicle as the REAL
   package vehicle class (what it earns, how many couriers share it), grouped controls, edit details with the real vehicle
   types, and the recent jobs.
   Data comes from admin_courier_detail (vehicle_class, vehicle_choices, vehicles, daily, zones). Every server value goes through AdminKit.esc. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;
var done = false;

function css() {
  if (done) return; done = true;
  var s = document.createElement('style');
  s.textContent =
    '.acd-hero{display:flex;gap:14px;align-items:center;padding:14px;border-radius:18px;border:1.5px solid var(--line);background:linear-gradient(135deg,rgba(212,175,55,.14),var(--soft))}' +
    '.acd-ring{flex:none;width:74px;height:74px;position:relative}.acd-ring svg{width:74px;height:74px;transform:rotate(-90deg)}' +
    '.acd-ring b{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:17px}' +
    '.acd-hero h3{margin:0;font-size:15px}.acd-hero p{margin:2px 0 0;font-size:12px;color:var(--mute)}' +
    '.acd-quick{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.acd-quick .ak-btn{flex:1 1 auto;text-align:center;text-decoration:none;white-space:nowrap}' +
    '.acd-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}' +
    '.acd-kpis div{background:var(--soft);border:1px solid var(--line);border-radius:14px;padding:10px 11px}' +
    '.acd-kpis b{display:block;font-size:19px;line-height:1.1}.acd-kpis span{font-size:11px;color:var(--mute)}' +
    '.acd-kpis div.hi{background:linear-gradient(135deg,rgba(212,175,55,.2),var(--soft));border-color:rgba(212,175,55,.5)}' +
    '.acd-bars{display:flex;align-items:flex-end;gap:4px;height:64px;margin-top:6px}' +
    '.acd-bars i{flex:1;min-height:3px;border-radius:5px 5px 2px 2px;background:linear-gradient(180deg,var(--gold),var(--gold2,#c99a1c));opacity:.9}' +
    '.acd-bars i.z{background:var(--line);opacity:1}' +
    '.acd-axis{display:flex;justify-content:space-between;font-size:10.5px;color:var(--mute);margin-top:4px}' +
    '.acd-veh{display:flex;gap:14px;align-items:center;padding:14px;border-radius:18px;border:1.5px solid var(--line);background:var(--soft)}' +
    '.acd-veh .pic{flex:none;width:76px;height:76px;border-radius:18px;background:var(--card);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;font-size:38px;overflow:hidden}' +
    '.acd-veh h3{margin:0;font-size:15px}.acd-veh small{display:block;color:var(--mute);font-size:12px;margin-top:2px}' +
    '.acd-veh .ak-chips{margin-top:8px}' +
    '.acd-warn{margin-top:10px;padding:10px 12px;border-radius:12px;font-size:12.5px;background:rgba(214,54,74,.09);border:1px solid rgba(214,54,74,.4);color:var(--red)}' +
    '.acd-ok{margin-top:10px;padding:10px 12px;border-radius:12px;font-size:12.5px;background:rgba(31,164,99,.1);border:1px solid rgba(31,164,99,.4);color:var(--green)}' +
    '.acd-chk{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:1fr 1fr;gap:6px}' +
    '.acd-chk li{display:flex;gap:8px;align-items:center;font-size:12.5px;padding:7px 9px;border-radius:10px;background:var(--soft);border:1px solid var(--line)}' +
    '.acd-chk li i{font-style:normal;flex:none;width:18px;height:18px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#fff;background:var(--green)}' +
    '.acd-chk li.no i{background:var(--red)}.acd-chk li.no{border-color:rgba(214,54,74,.4)}.acd-chk li.na{opacity:.55}.acd-chk li.na i{background:var(--mute)}' +
    '.acd-grp{border:1.5px solid var(--line);border-radius:16px;padding:12px;margin-top:10px}.acd-grp:first-of-type{margin-top:0}' +
    '.acd-grp h5{margin:0 0 8px;font-size:12px;color:var(--mute);text-transform:uppercase;letter-spacing:.06em}' +
    '.acd-form{display:grid;gap:10px}.acd-form label{display:block;font-size:11.5px;color:var(--mute);font-weight:700}' +
    '.acd-vgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(104px,1fr));gap:8px;margin-top:6px}' +
    '.acd-vt{display:flex;flex-direction:column;align-items:center;gap:3px;padding:10px 6px;border-radius:14px;border:1.5px solid var(--line);background:var(--soft);color:var(--ink);cursor:pointer;font:inherit;text-align:center}' +
    '.acd-vt .p{height:54px;display:flex;align-items:center;justify-content:center;font-size:34px}.acd-vt b{font-size:12.5px;line-height:1.15}.acd-vt small{font-size:10.5px;color:var(--mute)}' +
    '.acd-vt.on{border-color:var(--gold);background:linear-gradient(135deg,rgba(212,175,55,.22),var(--soft));box-shadow:0 0 0 3px rgba(212,175,55,.25)}' +
    '.acd-vsel{margin:8px 0 0;font-size:12px;color:var(--mute)}' +
    '.acd-jobs{list-style:none;margin:0;padding:0}.acd-jobs li{display:flex;justify-content:space-between;gap:10px;padding:10px 0;border-top:1px solid var(--line);font-size:12.5px}' +
    '.acd-jobs li:first-child{border-top:0}.acd-jobs small{display:block;color:var(--mute);font-size:11.5px}' +
    '@media(max-width:420px){.acd-chk{grid-template-columns:1fr}.acd-kpis{gap:6px}.acd-kpis div{padding:9px 8px}.acd-kpis b{font-size:16px}.acd-kpis span{font-size:10.5px}}';
  document.head.appendChild(s);
}

function ring(pct, color) {
  var r = 30, c = 2 * Math.PI * r, off = c * (1 - pct / 100);
  return '<div class="acd-ring"><svg viewBox="0 0 74 74" aria-hidden="true"><circle cx="37" cy="37" r="' + r + '" fill="none" stroke="var(--line)" stroke-width="8"/>' +
    '<circle cx="37" cy="37" r="' + r + '" fill="none" stroke="' + color + '" stroke-width="8" stroke-linecap="round" stroke-dasharray="' + c.toFixed(1) + '" stroke-dashoffset="' + off.toFixed(1) + '"/></svg><b>' + pct + '%</b></div>';
}
function waNumber(phone) {
  var n = String(phone || '').replace(/[^0-9]/g, '');
  if (n.indexOf('251') === 0) return n;
  if (n.charAt(0) === '0') return '251' + n.slice(1);
  return n.length === 9 ? '251' + n : n;
}
function classOf(d) {
  var cl = (d.vehicles && d.vehicles.classes) || [], k = d.vehicle_class, i;
  for (i = 0; i < cl.length; i++) { if (cl[i].class === k) return cl[i]; }
  return null;
}
function choiceOf(d, value) {
  var ch = d.vehicle_choices || [], i;
  for (i = 0; i < ch.length; i++) { if (ch[i].value === String(value || '').toLowerCase()) return ch[i]; }
  return null;
}
function checks(d, c, cls, suspended) {
  var x = d.details || {}, foot = cls && cls.class === 'bike', has = !!c.vehicle_type;
  var rows = [
    ['Approved', !c.approval_status || c.approval_status === 'approved', true, 'Approve this courier below'],
    ['Account active', !suspended, true, 'Restore the courier below'],
    ['Vehicle set', has, true, 'Choose a vehicle below'],
    ['Vehicle serves package jobs', !!cls, true, 'No package vehicle matches this one - see Package prices'],
    ['Plate number', !!c.plate_number, !foot, 'Add the plate below'],
    ['Home zone', !!(c.zone || c.home_zone), true, 'Add the zone below'],
    ['ID number', !!x.id_number, true, 'Add it in the Courier desk'],
    ['ID photo', !!x.id_photo_url, true, 'Add it in the Courier desk'],
    ['Selfie', !!x.selfie_url, true, 'Add it in the Courier desk'],
    ['Driving licence', !!x.licence_number, !foot, 'Add it in the Courier desk'],
    ['Emergency contact', !!x.emergency_phone, true, 'Add it in the Courier desk']
  ];
  return rows;
}

function render(d, row, dr, H) {
  css();
  var c = d.courier, s = d.stats || {}, suspended = !(Number(c.active) !== 0 && d.account_active);
  var r = Object.assign({}, row || {}, c, { suspended: suspended });
  var cls = classOf(d), ch = choiceOf(d, c.vehicle_type), v = H.veh(r);
  var rows = checks(d, c, cls, suspended), need = rows.filter(function (x) { return x[2] && !x[1]; }), req = rows.filter(function (x) { return x[2]; }).length;
  var pct = req ? Math.round(100 * (req - need.length) / req) : 100;
  var col = pct >= 100 ? '#1fa463' : pct >= 70 ? '#f0b429' : '#d6364a';
  var wa = waNumber(c.phone);
  var d30 = Number(s.d30) || 0, x30 = Number(s.cancelled_30d) || 0, comp = (d30 + x30) ? Math.round(100 * d30 / (d30 + x30)) : null;
  var head = H.avatar(r, 'lg') + '<div class="ak-nm"><b>' + esc(c.name) + '</b><small>' + esc(c.courier_code || '') + ' · joined ' + esc(K.fullDate(c.created_at)) + '</small></div>' + H.pillFor(r);
  var h = '';

  h += '<div class="ak-sec"><div class="acd-hero">' + ring(pct, col) + '<div style="min-width:0"><h3>' + (need.length ? esc(need.length) + ' thing' + (need.length === 1 ? '' : 's') + ' left before this courier is ready' : 'Ready to work') + '</h3>' +
    '<p>' + (need.length ? esc(need.slice(0, 3).map(function (x) { return x[0]; }).join(', ')) + (need.length > 3 ? ' and more' : '') : 'Everything needed is on file.') + '</p>' +
    '<p>' + (c.last_seen ? 'Last seen ' + esc(K.ago(c.last_seen)) : 'Never seen online') + '</p>' +
    (d.ride_driver ? '<p>🚕 ' + (c.approval_status === 'pending' && d.account_role === 'driver' ? '<b>A ride driver asking for the courier job.</b> Approve to let the same account deliver too.' : 'Also a ride driver (' + esc(String(d.ride_driver.vehicle_type || 'vehicle').replace(/_/g, ' ')) + ').') + '</p>' : '') + '</div></div>' +
    '<div class="acd-quick"><a class="ak-btn" href="tel:' + esc(c.phone) + '">📞 Call</a><a class="ak-btn" href="https://wa.me/' + esc(wa) + '" target="_blank" rel="noopener">💬 WhatsApp</a>' +
    '<button type="button" class="ak-btn" data-copy="' + esc(c.courier_code || '') + '">📋 Copy code</button><button type="button" class="ak-btn" data-slip>🖨 ID slip</button></div></div>';

  h += '<div class="ak-sec"><h4>Performance</h4><div class="acd-kpis">' +
    '<div class="hi"><b>' + esc(s.today) + '</b><span>Delivered today</span></div><div><b>' + esc(s.d7) + '</b><span>Last 7 days</span></div><div><b>' + esc(s.d30) + '</b><span>Last 30 days</span></div>' +
    '<div><b>' + esc(s.live) + '</b><span>Live jobs now</span></div><div><b>' + (comp === null ? '—' : esc(comp) + '%') + '</b><span>Completion (30 d)</span></div><div><b>' + esc(s.avg_minutes == null ? '—' : K.minutes(s.avg_minutes)) + '</b><span>Avg time (30 d)</span></div>' +
    '<div><b>' + esc(s.fees_30d == null ? '—' : K.money(s.fees_30d)) + '</b><span>Fees (30 d)</span></div><div><b>' + esc(H.stars(c.rating)) + '</b><span>Rating</span></div><div><b>' + esc(K.num(c.total_deliveries)) + '</b><span>All-time deliveries</span></div></div>';
  var days = d.daily || [], max = 1; days.forEach(function (x) { if (x.n > max) max = x.n; });
  if (days.length) {
    h += '<div class="acd-bars" role="img" aria-label="Deliveries per day, last 14 days">' + days.map(function (x) { return '<i class="' + (x.n ? '' : 'z') + '" style="height:' + Math.max(5, Math.round(100 * x.n / max)) + '%" title="' + esc(x.d) + ': ' + esc(x.n) + '"></i>'; }).join('') + '</div>' +
      '<div class="acd-axis"><span>14 days ago</span><span>today</span></div>';
  }
  h += '</div>';

  h += '<div class="ak-sec"><h4>Vehicle</h4><div class="acd-veh"><div class="pic">' + H.vp(r, v[0], 64) + '</div><div style="min-width:0"><h3>' + (c.vehicle_type ? esc(ch ? ch.label : v[1]) : 'No vehicle chosen yet') + '</h3>' +
    '<small>' + (cls ? 'Counts as <b>' + esc(cls.label) + '</b> for package jobs' : 'Matches no package vehicle') + '</small>' +
    '<div class="ak-chips">' + (c.plate_number ? '<span class="ak-chip">' + esc(c.plate_number) + '</span>' : '<span class="ak-chip warn">No plate</span>') +
    (cls ? '<span class="ak-chip">5 km · 3 kg = <b>ETB ' + esc(cls.price_5km) + '</b></span><span class="ak-chip">up to ' + esc(cls.max_weight_kg) + ' kg</span><span class="ak-chip">' + esc(cls.online) + ' online / ' + esc(cls.couriers) + ' registered</span>' : '') + '</div></div></div>';
  if (!c.vehicle_type) h += '<div class="acd-warn">No vehicle is saved for this courier. Until one is set, they are treated as a motorbike rider. Choose the real one below.</div>';
  else if (!cls) h += '<div class="acd-warn">' + esc(ch ? ch.label : v[1]) + ' does not match any package vehicle, so this courier gets no package jobs. Add it in Package prices, or change the vehicle below.</div>';
  else if (!cls.active) h += '<div class="acd-warn">' + esc(cls.label) + ' is switched off in Package prices, so customers cannot choose it.</div>';
  else h += '<div class="acd-ok">Gets package jobs as ' + esc(cls.label) + '.</div>';
  h += '</div>';

  h += '<div class="ak-sec"><h4>Readiness checklist</h4><ul class="acd-chk">' + rows.map(function (x) {
    return '<li class="' + (x[1] ? '' : (x[2] ? 'no' : 'na')) + '" title="' + (x[1] ? '' : esc(x[3])) + '"><i>' + (x[1] ? '✓' : (x[2] ? '!' : '–')) + '</i>' + esc(x[0]) + '</li>';
  }).join('') + '</ul></div>';

  h += H.idBox(d);

  var appr = '';
  if (c.approval_status === 'pending' || c.approval_status === 'rejected') appr += '<button type="button" class="ak-btn good" data-approve="approved">✓ Approve</button>';
  if (c.approval_status === 'pending' || c.approval_status === 'approved') appr += '<button type="button" class="ak-btn danger" data-approve="rejected">Not approve…</button>';
  h += '<div class="ak-sec"><h4>Controls</h4>' +
    '<div class="acd-grp"><h5>Approval</h5><p class="ak-fh" style="margin:0 0 8px">' + esc(c.approval_status ? String(c.approval_status) + (c.approval_note ? ' - ' + c.approval_note : '') : 'approved') + '</p><div class="ak-acts">' + (appr || '<span class="ak-fh">Nothing to decide.</span>') + '</div></div>' +
    '<div class="acd-grp"><h5>Account</h5><p class="ak-fh" style="margin:0 0 8px">' + (suspended ? 'Suspended: cannot take jobs.' : 'Active: can go online and take jobs.') + '</p><div class="ak-acts">' +
    (suspended ? '<button type="button" class="ak-btn good" data-active="1">Restore courier</button>' : '<button type="button" class="ak-btn danger" data-active="0">Suspend courier…</button>') + '</div></div></div>';

  var cur = String(c.vehicle_type || '').toLowerCase(), choices = (d.vehicle_choices || []).slice();
  if (cur && !ch) choices.unshift({ value: cur, label: cur.replace(/_/g, ' ') + ' (not a listed type)', icon: '', class: '', class_label: '' });
  var tiles = choices.map(function (o) {
    var on = cur === o.value, pic = (window.EtelVehiclePic && EtelVehiclePic.key(o.value)) ? EtelVehiclePic.html(o.value, 72, { fallback: o.icon }) : '<span class="acd-vemo">' + esc(o.icon || '🚚') + '</span>';
    return '<button type="button" class="acd-vt' + (on ? ' on' : '') + '" data-vt="' + esc(o.value) + '" aria-pressed="' + on + '"><span class="p">' + pic + '</span><b>' + esc(o.label) + '</b><small>' + (o.class_label ? esc(o.class_label) : 'No package jobs') + '</small></button>';
  }).join('');
  var zones = (d.zones || []).map(function (z) { return '<option value="' + esc(z) + '">'; }).join('');
  h += '<div class="ak-sec"><h4>Edit details</h4><div class="acd-form">' +
    '<div><label>Vehicle</label><input type="hidden" data-f="vehicle_type" value="' + esc(cur) + '"><div class="acd-vgrid" role="group" aria-label="Vehicle type">' + tiles + '</div><p class="acd-vsel" data-vsel>' + (cur ? '' : 'Tap the real vehicle, then Save changes.') + '</p></div>' +
    '<div><label>Plate number<input class="ak-in" data-f="plate_number" maxlength="30" placeholder="e.g. 1 SD 10181" value="' + esc(c.plate_number || '') + '"></label></div>' +
    '<div><label>Zone<input class="ak-in" data-f="zone" list="acd-zones" maxlength="60" placeholder="Zone" value="' + esc(c.zone || c.home_zone || '') + '"></label><datalist id="acd-zones">' + zones + '</datalist></div>' +
    '<div><button type="button" class="ak-btn gold" data-save>Save changes</button></div></div></div>';

  if (d.recent && d.recent.length) {
    h += '<div class="ak-sec"><h4>Recent jobs</h4><ul class="acd-jobs">' + d.recent.map(function (o) {
      var st = String(o.status || ''), tone = st === 'delivered' ? 'green' : st === 'cancelled' ? 'red' : 'amber';
      return '<li><div><b>' + esc(o.order_code) + '</b> ' + K.pill(st.replace(/_/g, ' '), tone) + '<small>' + esc(o.from_zone || '') + ' → ' + esc(o.to_zone || '') + (o.placed_at ? ' · ' + esc(K.ago(o.placed_at)) : '') + '</small></div><div><b>' + esc(K.money(o.fare_total)) + '</b></div></li>';
    }).join('') + '</ul></div>';
  }

  dr.querySelector('.ak-dr-head').innerHTML = head + '<button type="button" class="ak-x" data-close aria-label="Close">✕</button>';
  dr.querySelector('.ak-dr-body').innerHTML = h;
  dr._d = d;
  var vg = dr.querySelector('.acd-vgrid');
  if (vg) vg.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-vt]') : null; if (!b) return;
    Array.prototype.forEach.call(vg.querySelectorAll('[data-vt]'), function (x) { x.classList.toggle('on', x === b); x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
    var hid = dr.querySelector('input[data-f="vehicle_type"]'); if (hid) hid.value = b.getAttribute('data-vt');
    var sel = dr.querySelector('[data-vsel]'); if (sel) sel.textContent = 'Selected: ' + b.querySelector('b').textContent + ' (' + b.querySelector('small').textContent + '). Tap Save changes.';
  });
  var cp = dr.querySelector('[data-copy]');
  if (cp) cp.addEventListener('click', function () {
    var t = cp.getAttribute('data-copy');
    try { navigator.clipboard.writeText(t).then(function () { K.toast('Code copied'); }, function () { K.toast(t); }); } catch (e) { K.toast(t); }
  });
}

window.AdminCourierDrawer = { render: render };
})();

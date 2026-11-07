/* ETEL Agent portal - "Book a ride for a customer". Loaded on demand by app.js (only when the agent opens #/book).
   The agent types the customer's phone, picks a vehicle type and the trip; the server (fa_agent_* in AgentBookingController.php) creates the ride exactly
   like Admin's phone booking and links the customer to the agent. Everything the agent reads here is plain words: no risk scores, no fraud rules. */
(function () {
'use strict';
var P = window.ETELAP; if (!P || window.ETELBook) return;
var t = P.t, esc = P.esc, ic = P.ic, api = P.api, fmt = P.fmt;
var VICON = { bajaj: 'bajaj', moto: 'moto', car: 'car', minibus: 'bus', qute: 'bajaj' };
var KMS = [2, 4, 6, 8, 12];
var B = { veh: null, vcap: 10, fares: {}, phone: '', name: '', vtype: '', km: '', open: false, pickup: '', dest: '', lat: null, lng: null, geo: '', list: null, result: null, busy: false, err: '', qt: null };

function vname(v) { var k = 'veh.' + v; var s = t(k); return s === k ? (v.charAt(0).toUpperCase() + v.slice(1)) : s; }
function phoneOk() { var d = B.phone.replace(/\D/g, ''); return /^(251|0)?[79]\d{8}$/.test(d); }
function tripOk() { return B.open || parseFloat(B.km) > 0; }
function ready() { return phoneOk() && !!B.vtype && tripOk(); }
function fareOf(v) { var f = B.fares[v]; return f ? f.fare : null; }
function money(n) { return n == null ? '-' : fmt(n); }

function load() {
  B.err = '';
  var a = api('fa_agent_vehicles').then(function (r) {
    if (r.ok) { B.veh = r.data.vehicles; B.vcap = r.data.daily_cap; if (!B.vtype && B.veh.length) { /* leave unselected: the agent chooses */ } quote(); }
    else B.err = reasonText(r);
    if (P.S.view === 'book') P.render();
  });
  var b = loadList();
  return Promise.all([a, b]);
}
function loadList() { return api('fa_agent_bookings').then(function (r) { B.list = r.ok ? r.data.bookings : (r.net ? [] : null); if (P.S.view === 'book') P.render(); }); }
function reasonText(r) {
  var c = r && r.j && r.j.reason ? r.j.reason : '';
  if (c) { var k = 'bk.err.' + c, s = t(k); if (s !== k) return s; }
  if (r && r.net) return t('c.offline');
  return (r && r.j && r.j.message) ? r.j.message : t('c.error');
}
function quote() {
  clearTimeout(B.qt);
  B.qt = setTimeout(function () {
    api('fa_agent_quote', { km: parseFloat(B.km) || 0, open: B.open ? 1 : 0 }).then(function (r) {
      if (!r.ok) return; B.fares = r.data.fares;
      document.querySelectorAll('[data-bkfare]').forEach(function (el) { var f = fareOf(el.getAttribute('data-bkfare')); el.textContent = f == null ? '' : (B.open ? t('bk.from', { f: money(f) }) : (money(f) + ' ' + t('c.etb'))); });
      summary();
    });
  }, 250);
}
function summary() {
  var el = document.getElementById('bk-sum'), btn = document.getElementById('bk-go'); if (!el) return;
  var f = fareOf(B.vtype);
  el.textContent = B.vtype && f != null && tripOk() ? (B.open ? t('bk.from', { f: money(f) }) : t('bk.fare', { f: money(f) })) : '';
  if (btn) btn.disabled = !ready();
}

function view() {
  var h = '<h1>' + esc(t('bk.title')) + '</h1><p class="sub">' + esc(t('bk.sub')) + '</p>';
  if (B.err) h += '<div class="err" role="alert">' + esc(B.err) + '</div>';
  if (B.result) h += resultCard(B.result);
  h += '<section class="card"><label class="fld" style="margin-top:0"><span>' + esc(t('bk.phone')) + '</span><input class="in ltr" id="bk-phone" type="tel" inputmode="tel" autocomplete="off" maxlength="16" placeholder="' + esc(t('bk.phone_ph')) + '" value="' + esc(B.phone) + '"></label>' +
    '<label class="fld"><span>' + esc(t('bk.name')) + '</span><input class="in" id="bk-name" maxlength="60" autocomplete="off" value="' + esc(B.name) + '"></label></section>';
  h += '<h2>' + esc(t('bk.vehicle')) + '</h2>';
  if (B.veh === null) h += '<div class="stack"><div class="skel" style="height:72px"></div></div>';
  else h += '<div class="bv-grid" role="radiogroup" aria-label="' + esc(t('bk.vehicle')) + '">' + B.veh.map(function (v) {
    var on = B.vtype === v.type, f = fareOf(v.type);
    return '<button type="button" class="bv-veh' + (on ? ' on' : '') + (v.online ? '' : ' off') + '" role="radio" aria-checked="' + on + '" data-act="bk-veh" data-v="' + esc(v.type) + '">' + ic(VICON[v.type] || 'car') + '<b>' + esc(vname(v.type)) + '</b>' +
      '<span class="bv-fare" data-bkfare="' + esc(v.type) + '">' + (f == null ? '' : esc(B.open ? t('bk.from', { f: money(f) }) : money(f) + ' ' + t('c.etb'))) + '</span>' +
      '<small>' + esc(v.online ? t('bk.online', { n: v.online }) : t('bk.none')) + '</small></button>'; }).join('') + '</div>';
  h += '<h2>' + esc(t('bk.trip')) + '</h2><section class="card" style="margin-top:8px">' +
    '<label class="fld" style="margin-top:0"><span>' + esc(t('bk.pickup')) + '</span><input class="in" id="bk-pickup" maxlength="120" autocomplete="off" placeholder="' + esc(t('bk.pickup_ph')) + '" value="' + esc(B.pickup) + '"></label>' +
    '<button type="button" class="btn" data-act="bk-loc" style="margin-top:10px;min-height:48px">' + ic('pin') + esc(B.lat ? t('bk.loc_on') : t('bk.loc')) + '</button>' + (B.geo ? '<p class="sub" style="margin-top:6px">' + esc(B.geo) + '</p>' : '') +
    '<label class="fld"><span>' + esc(t('bk.dest')) + '</span><input class="in" id="bk-dest" maxlength="120" autocomplete="off" value="' + esc(B.dest) + '"></label>' +
    '<div class="fld"><span id="bk-lkm">' + esc(t('bk.km')) + '</span><div class="bv-chips" role="group" aria-labelledby="bk-lkm">' + KMS.map(function (k) { return '<button type="button" class="bv-chip' + (!B.open && String(B.km) === String(k) ? ' on' : '') + '" data-act="bk-km" data-k="' + k + '" aria-pressed="' + (!B.open && String(B.km) === String(k)) + '">' + k + ' km</button>'; }).join('') +
    '<button type="button" class="bv-chip' + (B.open ? ' on' : '') + '" data-act="bk-open" aria-pressed="' + B.open + '">' + esc(t('bk.open')) + '</button></div>' +
    '<input class="in" id="bk-km" type="number" inputmode="decimal" min="0.5" max="100" step="0.5" placeholder="km" value="' + esc(B.open ? '' : B.km) + '" style="margin-top:10px"' + (B.open ? ' disabled' : '') + '></div></section>';
  h += '<p class="bv-sum" id="bk-sum" role="status" aria-live="polite"></p><div style="margin-top:12px"><button class="btn primary" id="bk-go" data-act="bk-go" disabled>' + ic('car') + esc(t('bk.book')) + '</button></div>';
  h += '<h2>' + esc(t('bk.list')) + ' <button type="button" class="ib" data-act="bk-refresh" style="min-height:40px;min-width:40px;vertical-align:middle" aria-label="' + esc(t('bk.refresh')) + '">' + ic('refresh') + '</button></h2>';
  if (B.list === null) h += '<div class="err" role="alert">' + esc(t('c.error')) + '</div>';
  else if (!B.list.length) h += '<div class="empty">' + ic('car') + '<p>' + esc(t('bk.list_none')) + '</p></div>';
  else h += '<div class="list">' + B.list.map(function (x) {
    var st = t('bk.s.' + x.ride_status); if (st === 'bk.s.' + x.ride_status) st = x.ride_status || '';
    return '<div class="li"><div class="m"><b>' + esc(x.customer) + '</b><small>' + esc(vname(x.vehicle)) + ' · ' + esc(fmt(x.fare)) + ' ' + esc(t('c.etb')) + ' · ' + esc(P.when(x.created_at)) + '</small><small>' + esc(t('bk.b.' + x.bonus)) + '</small></div><div class="r"><span class="chip ' + (x.ride_status === 'completed' ? 'ok' : (x.ride_status === 'cancelled' || x.ride_status === 'expired' ? 'bad' : 'gold')) + '">' + esc(st) + '</span></div></div>'; }).join('') + '</div>';
  setTimeout(summary, 0);
  return P.shell(h);
}
function resultCard(r) {
  var b = r.bonus || {}, lines = ['<p><b>' + esc(t('bk.done')) + '</b></p>'];
  lines.push('<p class="sub">' + esc(vname(r.vehicle_type)) + ' · ' + esc(fmt(r.fare)) + ' ' + esc(t('c.etb')) + ' · ' + esc(r.customer) + '</p>');
  if (r.new_account) lines.push('<p class="sub">' + esc(t('bk.done_new')) + '</p>');
  if (b.eligible) { lines.push('<p class="sub">' + esc(t('bk.bonus_yes')) + '</p>'); if (b.review) lines.push('<p class="sub">' + esc(t('bk.bonus_review')) + '</p>'); }
  else if (b.reason) lines.push('<p class="sub">' + esc(t('bk.bonus_' + b.reason)) + '</p>');
  lines.push('<p class="sub">' + esc(t('bk.left', { n: r.left_today })) + '</p>');
  return '<section class="card" role="status"><div class="row"><div>' + lines.join('') + '</div><span class="ver">' + ic('check') + '</span></div></section>';
}

function confirm() {
  if (!ready()) return;
  var f = fareOf(B.vtype), ov = document.createElement('div'); ov.className = 'ov noprint'; ov.id = 'ov';
  ov.innerHTML = '<div class="sheet" role="dialog" aria-modal="true" aria-label="' + esc(t('bk.confirm_title')) + '"><h2 style="margin:0 0 8px">' + esc(t('bk.confirm_title')) + '</h2>' +
    '<p>' + esc(t('bk.confirm_text', { v: vname(B.vtype), p: B.phone.trim(), f: (B.open ? t('bk.from', { f: money(f) }) : money(f)) })) + '</p>' +
    '<div class="stack" style="margin-top:16px"><button class="btn primary" id="bk-yes" data-act="bk-yes">' + ic('check') + esc(t('bk.confirm_btn')) + '</button><button class="btn ghost" data-act="closesheet">' + esc(t('bk.cancel')) + '</button></div></div>';
  document.body.appendChild(ov); var y = document.getElementById('bk-yes'); if (y) y.focus();
}
function submit() {
  if (B.busy) return; B.busy = true; var yes = document.getElementById('bk-yes'); if (yes) { yes.disabled = true; yes.textContent = t('c.sending'); }
  api('fa_agent_book_ride', { phone: B.phone.trim(), name: B.name.trim(), vehicle_type: B.vtype, km: parseFloat(B.km) || 0, open: B.open ? 1 : 0, pickup_note: B.pickup.trim(), dest_note: B.dest.trim(), pickup_lat: B.lat, pickup_lng: B.lng }).then(function (r) {
    B.busy = false; P.closeSheet();
    if (r.ok) { B.result = r.data; B.err = ''; B.phone = ''; B.name = ''; B.dest = ''; B.km = ''; B.open = false; B.vtype = ''; quote(); loadList(); }
    else { B.result = null; B.err = reasonText(r); }
    P.render(); try { window.scrollTo(0, 0); } catch (e) { /* not needed */ }
  });
}

function act(a, el) {
  if (a === 'bk-veh') { B.vtype = el.getAttribute('data-v'); sync(); P.render(); }
  else if (a === 'bk-km') { B.km = el.getAttribute('data-k'); B.open = false; sync(); P.render(); quote(); }
  else if (a === 'bk-open') { B.open = !B.open; if (B.open) B.km = ''; sync(); P.render(); quote(); }
  else if (a === 'bk-loc') {
    if (!navigator.geolocation) { B.geo = t('bk.loc_err'); sync(); P.render(); return; }
    B.geo = t('bk.loc_wait'); sync(); P.render();
    navigator.geolocation.getCurrentPosition(function (p) { B.lat = p.coords.latitude; B.lng = p.coords.longitude; B.geo = ''; sync(); P.render(); }, function () { B.geo = t('bk.loc_err'); sync(); P.render(); }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
  }
  else if (a === 'bk-go') { sync(); confirm(); }
  else if (a === 'bk-yes') submit();
  else if (a === 'bk-refresh') loadList();
}
/* typed text lives in the inputs; copy it into B before any re-render so nothing is lost */
function sync() {
  var g = function (id) { var e = document.getElementById(id); return e ? e.value : null; };
  var v; if ((v = g('bk-phone')) !== null) B.phone = v; if ((v = g('bk-name')) !== null) B.name = v; if ((v = g('bk-pickup')) !== null) B.pickup = v; if ((v = g('bk-dest')) !== null) B.dest = v;
  if (!B.open && (v = g('bk-km')) !== null && v !== '') B.km = v;
}
document.addEventListener('input', function (e) {
  if (P.S.view !== 'book' || !e.target.id || e.target.id.indexOf('bk-') !== 0) return;
  sync();
  if (e.target.id === 'bk-km') { B.open = false; document.querySelectorAll('.bv-chip').forEach(function (c) { c.classList.remove('on'); c.setAttribute('aria-pressed', 'false'); }); quote(); }
  summary();
});
function open() { B.result = B.result && P.S.bookKeep ? B.result : null; P.S.bookKeep = false; if (!B.veh) load(); else { loadList(); quote(); } }
window.ETELBook = { view: view, act: act, open: open };
})();

/* ETEL Admin - Promo codes.
   The list of codes becomes a board: counts that double as filters (running, scheduled, expired, switched off), search,
   and a card per code with the discount, its rules, how much of the use limit is gone, how much money it has given
   away, and a clear status (Running / Scheduled / Expired / Off). Edit loads the existing form below; turning a code off
   asks first. Data: admin_list_promos, admin_toggle_promo (boot.php); the form still saves with savePromo() in admin.js.
   Standalone: replaces loadPromos() so admin.js is not edited. Every server value goes through AdminKit.esc. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;
var V = { filter: '', q: '', rows: [], loaded: false, busy: false };

function root() { return document.getElementById('promo-pro'); }
function parse(s) { if (!s) return null; var d = new Date(String(s).replace(' ', 'T')); return isNaN(d.getTime()) ? null : d; }
function state(p) {
  if (String(p.is_active) !== '1') return 'off';
  var now = new Date(), s = parse(p.starts_at), e = parse(p.expires_at);
  if (e && e < now) return 'expired';
  if (s && s > now) return 'scheduled';
  if (p.max_uses && (parseInt(p.used_count, 10) || 0) >= parseInt(p.max_uses, 10)) return 'expired';
  return 'running';
}
var PILL = { running: ['Running', 'green'], scheduled: ['Starts ', 'blue'], expired: ['Ended', 'grey'], off: ['Switched off', 'red'] };
function disc(p) {
  return p.discount_type === 'percent' ? (parseFloat(p.value) + '% off') : p.discount_type === 'fixed' ? (K.money(p.value) + ' off') : 'Free delivery';
}

function load(quiet) {
  var el = root(); if (!el || V.busy) return Promise.resolve();
  K.css(); V.busy = true;
  if (!quiet && !V.loaded) el.innerHTML = '<div class="ak">' + K.skeleton(3) + '</div>';
  return K.api('admin_list_promos', {}).then(function (res) {
    V.busy = false;
    if (!K.okRes(res)) { if (!V.loaded) el.innerHTML = '<div class="ak">' + K.empty('⚠️', 'Could not load promo codes', K.failText(res)) + '</div>'; else K.toast(K.failText(res), true); return; }
    V.rows = K.data(res).promos || []; V.loaded = true;
    try { _promoRows = V.rows; } catch (e) { /* admin.js missing: Edit has no data */ }
    paint();
  });
}

function paint() {
  var el = root(); if (!el) return;
  var c = { all: V.rows.length, running: 0, scheduled: 0, expired: 0, off: 0 }, given = 0, used = 0;
  V.rows.forEach(function (p) { c[state(p)]++; given += parseFloat(p.total_discount) || 0; used += parseInt(p.used_count, 10) || 0; });
  var kp = [
    { key: '', icon: '🎟️', label: 'All codes', value: K.num(c.all) }, { key: 'running', icon: '🟢', label: 'Running', value: K.num(c.running) },
    { key: 'scheduled', icon: '🗓️', label: 'Scheduled', value: K.num(c.scheduled) }, { key: 'expired', icon: '⌛', label: 'Ended', value: K.num(c.expired) },
    { key: 'off', icon: '⏸️', label: 'Switched off', value: K.num(c.off) }
  ];
  var q = V.q.toLowerCase(), focus = document.activeElement && document.activeElement.id === 'prm-q';
  var rows = V.rows.filter(function (p) { return (!V.filter || state(p) === V.filter) && (!q || (String(p.code || '') + ' ' + String(p.description || '')).toLowerCase().indexOf(q) >= 0); });
  var h = '<div class="ak">' + K.hero('Promo codes', 'Discounts customers can enter at checkout. See what is running, how much each code has given away, and switch one off in a tap.', [
      ['Running now', K.num(c.running)], ['Times used', K.num(used)], ['Given away', K.money(given)]]) + K.kpis(kp, V.filter) +
    '<div class="ak-tools"><label class="ak-search"><u>🔎</u><input id="prm-q" type="search" placeholder="Code or description…" value="' + esc(V.q) + '" autocomplete="off"></label>' +
    '<button type="button" class="ak-btn" data-act="refresh" aria-label="Refresh">🔄</button><button type="button" class="ak-btn gold" data-act="new">➕ New code</button></div>' +
    '<div class="ak-count">' + K.num(rows.length) + ' of ' + K.num(V.rows.length) + ' codes</div>';
  h += rows.length ? '<div class="ak-grid">' + rows.map(card).join('') + '</div>' : K.empty('🎟️', V.rows.length ? 'No codes match' : 'No promo codes yet', V.rows.length ? 'Try clearing the filter.' : 'Use "New code" to create the first one.');
  el.innerHTML = h + '</div>';
  if (focus) { var i = document.getElementById('prm-q'); if (i) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); } }
}

function card(p) {
  var st = state(p), pl = PILL[st], used = parseInt(p.used_count, 10) || 0, max = parseInt(p.max_uses, 10) || 0;
  var pct = max ? Math.min(100, Math.round(used / max * 100)) : 0, rules = [];
  if (parseFloat(p.min_subtotal) > 0) rules.push('Min ' + K.money(p.min_subtotal));
  if (p.discount_type === 'percent' && parseFloat(p.max_discount) > 0) rules.push('Max ' + K.money(p.max_discount) + ' off');
  if (String(p.first_order_only) === '1') rules.push('First order only');
  rules.push((parseInt(p.per_user_limit, 10) || 1) + ' per customer');
  if (p.expires_at) rules.push('Until ' + String(p.expires_at).slice(0, 10));
  var label = st === 'scheduled' ? pl[0] + String(p.starts_at).slice(0, 10) : pl[0];
  var on = String(p.is_active) === '1';
  return '<div class="ak-card' + (st === 'off' ? ' off' : '') + '" style="cursor:default"><div class="ak-top"><div class="ak-av" style="--a:#E8C14A;--b:#9a7a1c;font-size:20px">🎟️</div>' +
    '<div class="ak-nm"><b style="font-family:ui-monospace,Menlo,monospace;letter-spacing:.04em">' + esc(p.code) + '</b><small>' + esc(p.description || '') + '</small></div>' + K.pill(label, pl[1]) + '</div>' +
    '<div style="margin-top:12px;font-size:20px;font-weight:800">' + esc(disc(p)) + '</div>' +
    '<div class="ak-chips">' + rules.map(function (r) { return '<span class="ak-chip">' + esc(r) + '</span>'; }).join('') + '</div>' +
    '<div class="ak-row"><span>Used <b>' + esc(K.num(used)) + '</b>' + (max ? ' of ' + esc(K.num(max)) : '') + '</span><span>Given away <b>' + esc(K.money(p.total_discount)) + '</b></span></div>' +
    (max ? '<div style="height:5px;border-radius:5px;background:var(--line);margin-top:8px;overflow:hidden" role="img" aria-label="' + esc(pct) + ' percent of uses taken"><div style="height:100%;width:' + pct + '%;background:linear-gradient(90deg,var(--gold),var(--gold2))"></div></div>' : '') +
    '<div class="ak-acts" style="margin-top:12px"><button type="button" class="ak-btn" data-edit="' + esc(p.id) + '">✏ Edit</button><button type="button" class="ak-btn ' + (on ? 'danger' : 'good') + '" data-toggle="' + esc(p.id) + '" data-on="' + (on ? 0 : 1) + '">' + (on ? 'Turn off' : 'Turn on') + '</button></div></div>';
}

var search = K.debounce(function () { paint(); }, 200);
function wire(el) {
  if (el._prmWired) return; el._prmWired = true;
  el.addEventListener('click', function (e) {
    var t = e.target, b;
    if ((b = t.closest('[data-kpi]'))) { var k = b.getAttribute('data-kpi'); V.filter = (V.filter === k ? '' : k); paint(); return; }
    if (t.closest('[data-act="refresh"]')) { load(true); return; }
    if (t.closest('[data-act="new"]')) {
      if (typeof resetPromoForm === 'function') resetPromoForm();
      var f = document.getElementById('promo-form-title'); if (f) { f.scrollIntoView({ behavior: 'smooth', block: 'center' }); var c = document.getElementById('promo-code'); if (c) c.focus(); }
      return;
    }
    if ((b = t.closest('[data-edit]'))) { if (typeof editPromo === 'function') editPromo(b.getAttribute('data-edit')); return; }
    if ((b = t.closest('[data-toggle]'))) {
      var id = parseInt(b.getAttribute('data-toggle'), 10), on = b.getAttribute('data-on') === '1';
      var go = function () { K.api('admin_toggle_promo', { id: id, is_active: on ? 1 : 0 }).then(function (res) { if (K.okRes(res)) { K.toast(on ? 'Code turned on' : 'Code turned off'); V.busy = false; load(true); } else K.toast(K.failText(res), true); }); };
      if (on) go(); else K.confirm({ title: 'Turn this code off?', text: 'Customers can no longer use it. You can turn it back on any time.', label: 'Turn off', danger: true }).then(function (r) { if (r) go(); });
    }
  });
  el.addEventListener('input', function (e) { if (e.target.id === 'prm-q') { V.q = e.target.value.trim(); search(); } });
}

function install() {
  var el = root(); if (!el) return;
  wire(el);
  window.loadPromos = function () { return load(V.loaded); };
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();

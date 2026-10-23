/* ETEL Admin - Promo codes.
   The list of codes becomes a board: counts that double as filters (running, scheduled, expired, switched off), search,
   and a card per code with the discount, its rules, how much of the use limit is gone, how much money it has given
   away, and a clear status (Running / Scheduled / Expired / Off). New code and Edit open a guided form in a side panel
   (pick the kind of discount, set the rules and dates, see exactly what customers will read, then save); turning a code
   off asks first. Data: admin_list_promos, admin_toggle_promo, admin_save_promo (boot.php).
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

/* ---------- guided form (side panel) ---------- */
var KINDS = [['percent', '％', 'Percent off', 'Of the items total'], ['fixed', '💵', 'Fixed amount', 'ETB off the items'], ['free_delivery', '🛵', 'Free delivery', 'No delivery fee']];
function dt(v) { return v ? String(v).replace(' ', 'T').slice(0, 16) : ''; }
function randomCode() { var a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789', o = ''; for (var i = 0; i < 6; i++) o += a.charAt(Math.floor(Math.random() * a.length)); return o; }
function val(id) { var e = document.getElementById(id); return e ? e.value.trim() : ''; }
function num(id) { var n = parseFloat(val(id)); return isNaN(n) ? 0 : n; }
function describe() {
  var code = val('pf-code').toUpperCase() || 'YOURCODE', kind = (document.querySelector('[data-opt="pf-kind"].on') || {}).getAttribute ? document.querySelector('[data-opt="pf-kind"].on').getAttribute('data-val') : 'percent';
  var what = kind === 'free_delivery' ? 'free delivery' : kind === 'fixed' ? K.money(num('pf-value')) + ' off your items' : (num('pf-value') || 0) + '% off your items' + (num('pf-max') > 0 ? ' (up to ' + K.money(num('pf-max')) + ')' : '');
  var bits = [];
  if (num('pf-min') > 0) bits.push('orders from ' + K.money(num('pf-min')));
  if (document.getElementById('pf-first') && document.getElementById('pf-first').checked) bits.push('first order only');
  bits.push((parseInt(val('pf-per'), 10) || 1) + ' use per customer');
  if (val('pf-uses')) bits.push('first ' + K.num(val('pf-uses')) + ' uses');
  if (val('pf-exp')) bits.push('until ' + val('pf-exp').slice(0, 10));
  return '<b>Customers will see</b><span style="font-family:ui-monospace,Menlo,monospace;font-weight:800;letter-spacing:.04em">' + esc(code) + '</span> — ' + esc(what) + '<div style="font-size:12px;color:var(--mute);margin-top:4px">' + esc(bits.join(' · ')) + '</div>';
}
function openForm(p) {
  var edit = !!p, kind = p ? p.discount_type : 'percent';
  var head = '<div class="ak-av lg" style="--a:#E8C14A;--b:#9a7a1c">🎟️</div><div class="ak-nm"><b>' + (edit ? 'Edit ' + esc(p.code) : 'New promo code') + '</b><small>' + (edit ? 'Changes apply to new orders at once.' : 'Discounts are paid by the platform, not the shop.') + '</small></div>';
  var body = '<form id="pf" autocomplete="off" novalidate><input type="hidden" id="pf-id" value="' + esc(p ? p.id : '') + '">' +
    '<div class="ak-sec"><h4>1 · The code</h4><div style="display:flex;gap:8px;align-items:flex-end">' + K.field('Code customers type', '<input id="pf-code" class="ak-in" maxlength="40" placeholder="WELCOME20" style="text-transform:uppercase;font-family:ui-monospace,Menlo,monospace;font-weight:800" value="' + esc(p ? p.code : '') + '">', '3–40 letters and numbers.') +
    '<button type="button" class="ak-btn" data-gen style="margin-bottom:18px">🎲 Suggest</button></div><div style="margin-top:12px">' + K.field('Description (shown to customers)', '<input id="pf-desc" class="ak-in" maxlength="255" placeholder="20% off your first order" value="' + esc(p ? p.description || '' : '') + '">') + '</div></div>' +
    '<div class="ak-sec"><h4>2 · The discount</h4>' + K.opts('pf-kind', KINDS, kind) +
    '<div class="ak-fg" style="margin-top:12px"><div id="pf-value-wrap">' + K.field('Value', '<input id="pf-value" type="number" min="0" step="any" class="ak-in" value="' + esc(p ? parseFloat(p.value) : '') + '">', 'A number from 1 to 100 for percent; ETB for a fixed amount.') + '</div>' +
    '<div id="pf-max-wrap">' + K.field('Biggest discount (ETB)', '<input id="pf-max" type="number" min="0" step="any" class="ak-in" placeholder="No limit" value="' + esc(p && parseFloat(p.max_discount) > 0 ? parseFloat(p.max_discount) : '') + '">', 'Only for percent codes.') + '</div></div></div>' +
    '<div class="ak-sec"><h4>3 · The rules</h4><div class="ak-fg">' + K.field('Smallest order (ETB)', '<input id="pf-min" type="number" min="0" step="any" class="ak-in" placeholder="0" value="' + esc(p && parseFloat(p.min_subtotal) > 0 ? parseFloat(p.min_subtotal) : '') + '">', 'Items subtotal needed.') +
    K.field('Uses per customer', '<input id="pf-per" type="number" min="1" class="ak-in" value="' + esc(p ? parseInt(p.per_user_limit, 10) || 1 : 1) + '">') +
    K.field('Total uses', '<input id="pf-uses" type="number" min="1" class="ak-in" placeholder="Unlimited" value="' + esc(p && p.max_uses ? p.max_uses : '') + '">', 'Leave empty for no limit.') + '</div>' +
    '<div style="margin-top:10px">' + K.sw('pf-first', 'First order only — only customers who have never ordered', p && String(p.first_order_only) === '1') + '</div></div>' +
    '<div class="ak-sec"><h4>4 · When it works</h4><div class="ak-fg">' + K.field('Starts', '<input id="pf-start" type="datetime-local" class="ak-in" value="' + esc(dt(p && p.starts_at)) + '">', 'Empty = right away.') +
    K.field('Ends', '<input id="pf-exp" type="datetime-local" class="ak-in" value="' + esc(dt(p && p.expires_at)) + '">', 'Empty = never.') + '</div>' +
    '<div style="margin-top:10px">' + K.sw('pf-active', 'Switched on', p ? String(p.is_active) === '1' : true) + '</div></div>' +
    '<div class="ak-sec"><div class="ak-prev" id="pf-prev" aria-live="polite"></div></div></form>';
  var dr = K.openDrawer(head, body, '<span class="ak-err" id="pf-err" role="alert"></span><button type="button" class="ak-btn" data-close>Cancel</button><button type="button" class="ak-btn gold" data-save>' + (edit ? 'Save changes' : 'Create code') + '</button>');
  var kindNow = kind;
  function sync() {
    var w = document.getElementById('pf-value-wrap'), m = document.getElementById('pf-max-wrap');
    if (w) w.style.display = kindNow === 'free_delivery' ? 'none' : '';
    if (m) m.style.display = kindNow === 'percent' ? '' : 'none';
    var pv = document.getElementById('pf-prev'); if (pv) pv.innerHTML = describe();
  }
  K.wireOpts(dr, function (n, v) { if (n === 'pf-kind') { kindNow = v; sync(); } });
  dr.addEventListener('input', sync); dr.addEventListener('change', sync);
  dr.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('[data-gen]')) { var c = document.getElementById('pf-code'); c.value = randomCode(); sync(); c.focus(); }
    if (e.target.closest && e.target.closest('[data-save]')) save(kindNow, dr);
  });
  sync();
  var first = document.getElementById('pf-code'); if (first) first.focus();
}
function save(kind, dr) {
  var err = document.getElementById('pf-err'), bad = function (m, id) { if (err) err.textContent = m; var e = document.getElementById(id); if (e) e.focus(); };
  var code = val('pf-code').toUpperCase().replace(/[^A-Z0-9]/g, ''), value = num('pf-value');
  if (code.length < 3 || code.length > 40) return bad('The code needs 3 to 40 letters or numbers.', 'pf-code');
  if (kind === 'percent' && (value <= 0 || value > 100)) return bad('Percent must be between 1 and 100.', 'pf-value');
  if (kind === 'fixed' && value <= 0) return bad('Enter how many ETB to take off.', 'pf-value');
  if (val('pf-start') && val('pf-exp') && val('pf-exp') <= val('pf-start')) return bad('The end must be after the start.', 'pf-exp');
  if (err) err.textContent = '';
  var btn = dr.querySelector('[data-save]'); btn.disabled = true;
  var body = { id: parseInt(val('pf-id'), 10) || 0, code: code, discount_type: kind, value: kind === 'free_delivery' ? 0 : value, min_subtotal: val('pf-min'), max_discount: kind === 'percent' ? val('pf-max') : '',
    starts_at: val('pf-start') ? val('pf-start').replace('T', ' ') + ':00' : '', expires_at: val('pf-exp') ? val('pf-exp').replace('T', ' ') + ':00' : '', max_uses: val('pf-uses'), per_user_limit: parseInt(val('pf-per'), 10) || 1,
    description: val('pf-desc'), first_order_only: document.getElementById('pf-first').checked ? 1 : 0, is_active: document.getElementById('pf-active').checked ? 1 : 0 };
  K.api('admin_save_promo', body).then(function (res) {
    btn.disabled = false;
    if (K.okRes(res)) { K.closeDrawer(); K.toast(body.id ? 'Code saved' : 'Code created'); V.busy = false; load(true); }
    else if (err) err.textContent = K.failText(res, 'Could not save the code.');
  });
}

var search = K.debounce(function () { paint(); }, 200);
function wire(el) {
  if (el._prmWired) return; el._prmWired = true;
  el.addEventListener('click', function (e) {
    var t = e.target, b;
    if ((b = t.closest('[data-kpi]'))) { var k = b.getAttribute('data-kpi'); V.filter = (V.filter === k ? '' : k); paint(); return; }
    if (t.closest('[data-act="refresh"]')) { load(true); return; }
    if (t.closest('[data-act="new"]')) { openForm(null); return; }
    if ((b = t.closest('[data-edit]'))) { var row = null; V.rows.forEach(function (r) { if (String(r.id) === b.getAttribute('data-edit')) row = r; }); if (row) openForm(row); return; }
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

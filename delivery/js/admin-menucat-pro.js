/* ETEL Admin - Menu catalog.
   The shared dishes every shop can pick from, as a board: counts that double as filters (suggested by shops waiting for
   approval, active, hidden), category chips, search, a card per dish with photo, both names, group, prep time and how
   many shops sell it, one-tap Approve for shop suggestions, and a guided side-panel form to add or edit a dish (with a
   live photo preview). "Copy a shop's menu" is a guided panel too. Hiding or deleting asks first.
   Data: admin_catalog_list, admin_catalog_save, admin_catalog_delete, admin_catalog_copy (boot.php), and
   admin_get_service_givers for the shop pickers. Standalone: replaces loadMenuCatalog() so admin.js is not edited.
   Every server value goes through AdminKit.esc; no inline handlers. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;
var CATS = { food: ['🍲', 'Food'], grocery: ['🛒', 'Grocery'], pharmacy: ['💊', 'Pharmacy'], package: ['📦', 'Package'], flower: ['💐', 'Flower'] };
var STAT = { active: ['Active', 'green'], pending: ['Suggested', 'amber'], hidden: ['Hidden', 'grey'] };
var V = { filter: '', cat: '', q: '', rows: [], pending: 0, loaded: false, busy: false, more: 1, shops: null };
var SHOW = 90;

function root() { return document.getElementById('mc-pro'); }
function pic(u) { u = String(u || ''); return (/^(https?:)?\/\//i.test(u) || u.charAt(0) === '/') ? u : ''; }
function cat(r) { return CATS[r.category] || ['🏷️', r.category ? String(r.category) : 'Other']; }
function shops(r) { return parseInt(r.shops, 10) || 0; }

function load(quiet) {
  var el = root(); if (!el || V.busy) return Promise.resolve();
  K.css(); V.busy = true;
  if (!quiet && !V.loaded) el.innerHTML = '<div class="ak">' + K.skeleton(6) + '</div>';
  return K.api('admin_catalog_list', { status: 'all', q: '' }).then(function (res) {
    V.busy = false;
    if (!K.okRes(res)) { if (!V.loaded) el.innerHTML = '<div class="ak">' + K.empty('⚠️', 'Could not load the catalog', K.failText(res)) + '</div>'; else K.toast(K.failText(res), true); return; }
    var d = K.data(res); V.rows = d.catalog || []; V.pending = d.pending || 0; V.loaded = true;
    try { _mcRows = V.rows; } catch (e) { /* admin.js missing: nothing else reads it */ }
    var badge = document.getElementById('mc-badge'); if (badge) { badge.textContent = V.pending; badge.style.display = V.pending ? 'inline-block' : 'none'; }
    paint();
  });
}

function visibleRows() {
  var q = V.q.toLowerCase();
  return V.rows.filter(function (r) {
    if (V.filter && r.status !== V.filter) return false;
    if (V.cat && r.category !== V.cat) return false;
    if (q && (String(r.name || '') + ' ' + String(r.name_am || '') + ' ' + String(r.cuisine || '')).toLowerCase().indexOf(q) < 0) return false;
    return true;
  });
}

function paint() {
  var el = root(); if (!el) return;
  var c = { all: V.rows.length, active: 0, pending: 0, hidden: 0 }, onShops = 0, focus = document.activeElement && document.activeElement.id === 'mcp-q';
  V.rows.forEach(function (r) { if (c[r.status] != null) c[r.status]++; if (shops(r) > 0) onShops++; });
  var kp = [
    { key: '', icon: '📚', label: 'All dishes', value: K.num(c.all) }, { key: 'pending', icon: '⏳', label: 'Suggested by shops', value: K.num(c.pending), warn: true },
    { key: 'active', icon: '✅', label: 'Active', value: K.num(c.active) }, { key: 'hidden', icon: '🙈', label: 'Hidden', value: K.num(c.hidden) }
  ];
  var cats = [['', 'All']].concat(Object.keys(CATS).map(function (k) { return [k, CATS[k][0] + ' ' + CATS[k][1]]; }));
  var rows = visibleRows(), shown = rows.slice(0, SHOW * V.more);
  var h = '<div class="ak">' + K.hero('Menu catalog', 'Common dishes every shop can pick from, so restaurants only type their own price. Approve the dishes shops suggest to add them for everyone.', [
      ['Dishes', K.num(c.all)], ['Waiting for approval', K.num(c.pending)], ['Used by shops', K.num(onShops)]]) + K.kpis(kp, V.filter) +
    '<div class="ak-tools"><label class="ak-search"><u>🔎</u><input id="mcp-q" type="search" placeholder="Dish, Amharic name or group…" value="' + esc(V.q) + '" autocomplete="off"></label>' +
    '<button type="button" class="ak-btn" data-act="refresh" aria-label="Refresh">🔄</button><button type="button" class="ak-btn" data-act="copy">📋 Copy a shop\'s menu</button><button type="button" class="ak-btn gold" data-act="new">➕ New dish</button></div>' +
    K.seg('cat', cats, V.cat) + '<div class="ak-count" style="margin-top:10px">' + K.num(rows.length) + ' of ' + K.num(V.rows.length) + ' dishes</div>';
  h += rows.length ? '<div class="ak-grid">' + shown.map(card).join('') + '</div>' + (rows.length > shown.length ? '<div style="text-align:center;margin-top:14px"><button type="button" class="ak-btn" data-act="more">Show more (' + K.num(rows.length - shown.length) + ' left)</button></div>' : '')
    : K.empty('📚', V.rows.length ? 'No dishes match' : 'The catalog is empty', V.rows.length ? 'Try clearing the filters.' : 'Use "New dish" to add the first one.');
  el.innerHTML = h + '</div>';
  if (focus) { var i = document.getElementById('mcp-q'); if (i) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); } }
}

function thumb(r) {
  var u = pic(r.image_url), cl = cat(r);
  return u ? '<div class="ak-av" style="overflow:hidden;--a:#E8C14A;--b:#9a7a1c"><img src="' + esc(u) + '" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover"></div>'
           : '<div class="ak-av" style="--a:#E8C14A;--b:#9a7a1c;font-size:22px">' + esc(cl[0]) + '</div>';
}
function card(r) {
  var st = STAT[r.status] || STAT.active, cl = cat(r), n = shops(r), pend = r.status === 'pending';
  return '<div class="ak-card' + (pend ? ' alert' : '') + (r.status === 'hidden' ? ' off' : '') + '" style="cursor:default"><div class="ak-top">' + thumb(r) +
    '<div class="ak-nm"><b>' + esc(r.name) + '</b><small>' + esc(r.name_am || '') + '</small></div>' + K.pill(st[0], st[1]) + '</div>' +
    '<div class="ak-chips"><span class="ak-chip">' + esc(cl[0]) + ' ' + esc(cl[1]) + '</span>' + (r.cuisine ? '<span class="ak-chip">' + esc(r.cuisine) + '</span>' : '') +
    (r.prep_time ? '<span class="ak-chip">⏱ ' + esc(r.prep_time) + ' min</span>' : '') + '</div>' +
    '<div class="ak-row"><span>Sold by <b>' + esc(n) + '</b> shop' + (n === 1 ? '' : 's') + '</span>' + (pend && r.suggested_by_name ? '<span>by ' + esc(r.suggested_by_name) + '</span>' : '') + '</div>' +
    '<div class="ak-acts" style="margin-top:10px">' + (pend ? '<button type="button" class="ak-btn good" data-approve="' + esc(r.id) + '">✓ Approve</button>' : '') +
    '<button type="button" class="ak-btn" data-edit="' + esc(r.id) + '">✏ Edit</button><button type="button" class="ak-btn danger" data-del="' + esc(r.id) + '">' + (n > 0 ? 'Hide' : 'Delete') + '</button></div></div>';
}

function find(id) { var f = null; V.rows.forEach(function (r) { if (String(r.id) === String(id)) f = r; }); return f; }
function val(id) { var e = document.getElementById(id); return e ? e.value.trim() : ''; }

/* ---------- dish form ---------- */
function openDish(r) {
  var edit = !!r, o = r || {}, status = o.status || 'active', category = o.category || 'food';
  var head = '<div class="ak-av lg" style="--a:#E8C14A;--b:#9a7a1c">📚</div><div class="ak-nm"><b>' + (edit ? 'Edit dish' : 'New dish') + '</b><small>' + (edit ? esc(o.name || '') : 'Shops can pick it and only type their own price.') + '</small></div>';
  var catOpts = Object.keys(CATS).map(function (k) { return [k, CATS[k][0], CATS[k][1], '']; });
  var body = '<form id="dsh" autocomplete="off" novalidate><input type="hidden" id="dsh-id" value="' + esc(o.id || '') + '">' +
    '<div class="ak-sec"><h4>Names</h4><div class="ak-fg">' + K.field('Name (English)', '<input id="dsh-name" class="ak-in" maxlength="150" value="' + esc(o.name || '') + '">') + K.field('Name (አማርኛ)', '<input id="dsh-am" class="ak-in" maxlength="150" value="' + esc(o.name_am || '') + '">') + '</div>' +
    '<div style="margin-top:10px">' + K.field('Description', '<textarea id="dsh-desc" class="ak-in" rows="2" maxlength="500">' + esc(o.description || '') + '</textarea>') + '</div></div>' +
    '<div class="ak-sec"><h4>Category</h4>' + K.opts('dsh-cat', catOpts, category) +
    '<div class="ak-fg" style="margin-top:12px">' + K.field('Group', '<input id="dsh-cuisine" class="ak-in" maxlength="80" placeholder="Ethiopian" value="' + esc(o.cuisine || '') + '">', 'For example Ethiopian, Pizza, Drinks.') +
    K.field('Starter packs', '<input id="dsh-packs" class="ak-in" maxlength="190" placeholder="ethiopian_restaurant, cafe" value="' + esc(o.packs || '') + '">', 'Comma separated.') +
    K.field('Prep time (min)', '<input id="dsh-prep" type="number" min="1" class="ak-in" value="' + esc(o.prep_time || 20) + '">') + '</div></div>' +
    '<div class="ak-sec"><h4>Photo</h4><div style="display:flex;gap:12px;align-items:flex-start"><div id="dsh-pic" style="flex:none;width:84px;height:84px"></div><div style="flex:1">' + K.field('Photo address', '<input id="dsh-img" class="ak-in" placeholder="https://…" value="' + esc(o.image_url || '') + '">', 'A web address of the photo (https://…).') + '</div></div></div>' +
    '<div class="ak-sec"><h4>Status</h4>' + K.opts('dsh-status', [['active', '✅', 'Active', 'Shops can pick it'], ['pending', '⏳', 'Suggested', 'Waiting for approval'], ['hidden', '🙈', 'Hidden', 'Not offered']], status) + '</div></form>';
  var dr = K.openDrawer(head, body, '<span class="ak-err" id="dsh-err" role="alert"></span><button type="button" class="ak-btn" data-close>Cancel</button><button type="button" class="ak-btn gold" data-save>' + (edit ? 'Save dish' : 'Add dish') + '</button>');
  var cur = { cat: category, status: status };
  function preview() {
    var u = pic(val('dsh-img')), b = document.getElementById('dsh-pic'); if (!b) return;
    b.innerHTML = u ? '<div class="ak-av" style="width:84px;height:84px;border-radius:18px;overflow:hidden"><img src="' + esc(u) + '" alt="" style="width:100%;height:100%;object-fit:cover"></div>'
                    : '<div class="ak-av" style="width:84px;height:84px;border-radius:18px;font-size:30px;--a:#E8C14A;--b:#9a7a1c">' + esc((CATS[cur.cat] || ['🏷️'])[0]) + '</div>';
  }
  K.wireOpts(dr, function (n, v) { if (n === 'dsh-cat') cur.cat = v; else cur.status = v; preview(); });
  dr.addEventListener('input', function (e) { if (e.target.id === 'dsh-img') preview(); });
  dr.addEventListener('click', function (e) {
    if (!(e.target.closest && e.target.closest('[data-save]'))) return;
    var err = document.getElementById('dsh-err');
    if (!val('dsh-name')) { if (err) err.textContent = 'Give the dish a name.'; document.getElementById('dsh-name').focus(); return; }
    if (val('dsh-img') && !pic(val('dsh-img'))) { if (err) err.textContent = 'The photo address must start with https://'; document.getElementById('dsh-img').focus(); return; }
    var btn = dr.querySelector('[data-save]'); btn.disabled = true; if (err) err.textContent = '';
    var body2 = { id: parseInt(val('dsh-id'), 10) || 0, name: val('dsh-name'), name_am: val('dsh-am'), description: val('dsh-desc'), description_am: o.description_am || '', category: cur.cat, cuisine: val('dsh-cuisine'),
      packs: val('dsh-packs'), image_url: val('dsh-img'), prep_time: parseInt(val('dsh-prep'), 10) || 20, status: cur.status };
    K.api('admin_catalog_save', body2).then(function (res) {
      btn.disabled = false;
      if (K.okRes(res)) { K.closeDrawer(); K.toast(body2.id ? 'Dish saved' : 'Dish added'); V.busy = false; load(true); } else if (err) err.textContent = K.failText(res, 'Could not save the dish.');
    });
  });
  preview();
  var f = document.getElementById('dsh-name'); if (f) f.focus();
}

/* ---------- copy a shop's menu ---------- */
function openCopy() {
  var head = '<div class="ak-av lg" style="--a:#E8C14A;--b:#9a7a1c">📋</div><div class="ak-nm"><b>Copy a shop\'s menu</b><small>Start a new shop from a shop that is already set up.</small></div>';
  var dr = K.openDrawer(head, '<div id="cpy-body">' + K.skeleton(1) + '</div>', '<span class="ak-err" id="cpy-msg" role="status"></span><button type="button" class="ak-btn" data-close>Close</button><button type="button" class="ak-btn gold" data-run disabled>Copy menu</button>');
  (V.shops ? Promise.resolve(V.shops) : K.api('admin_get_service_givers', {}).then(function (res) { V.shops = K.okRes(res) ? (K.data(res).service_givers || []) : []; return V.shops; })).then(function (list) {
    var b = document.getElementById('cpy-body'); if (!b) return;
    if (!list.length) { b.innerHTML = K.empty('🏪', 'No shops yet', 'Add a service giver first.'); return; }
    var opt = '<option value="">Choose a shop…</option>' + list.map(function (s) { return '<option value="' + esc(s.id) + '">' + esc((s.business_name || ('Shop #' + s.id)) + (s.zone ? ' · ' + s.zone : '')) + '</option>'; }).join('');
    b.innerHTML = '<div class="ak-sec">' + K.field('Copy from', '<select id="cpy-from" class="ak-in">' + opt + '</select>') + '<div style="height:10px"></div>' + K.field('Copy to', '<select id="cpy-to" class="ak-in">' + opt + '</select>') + '</div>' +
      '<div class="ak-sec">' + K.field('Change prices by (%)', '<input id="cpy-pct" type="number" min="-50" max="200" step="1" value="0" class="ak-in">', 'For example 10 makes every price 10% higher, −10 makes it lower.') + '<div style="margin-top:10px">' + K.sw('cpy-hidden', 'Also copy hidden (unavailable) dishes', false) + '</div></div>';
    dr.querySelector('[data-run]').disabled = false;
  });
  dr.addEventListener('click', function (e) {
    if (!(e.target.closest && e.target.closest('[data-run]'))) return;
    var from = val('cpy-from'), to = val('cpy-to'), msg = document.getElementById('cpy-msg'), pct = parseFloat(val('cpy-pct')) || 0;
    if (!from || !to) { if (msg) msg.textContent = 'Choose both shops.'; return; }
    if (from === to) { if (msg) msg.textContent = 'Choose two different shops.'; return; }
    var nm = function (id) { var s = document.getElementById(id); return s.selectedOptions[0].textContent; };
    K.confirm({ title: 'Copy this menu?', text: 'From "' + nm('cpy-from') + '" to "' + nm('cpy-to') + '"' + (pct ? ' with prices ' + (pct > 0 ? '+' : '') + pct + '%' : '') + '. Dishes the target already has are skipped.', label: 'Copy menu' }).then(function (r) {
      if (!r) return;
      var btn = dr.querySelector('[data-run]'); btn.disabled = true; if (msg) msg.textContent = 'Copying…';
      K.api('admin_catalog_copy', { from_sg: from, to_sg: to, percent: pct, include_hidden: document.getElementById('cpy-hidden').checked ? 1 : 0 }).then(function (res) {
        btn.disabled = false;
        if (!K.okRes(res)) { if (msg) msg.textContent = K.failText(res, 'Could not copy the menu.'); return; }
        var d = K.data(res);
        if (msg) msg.textContent = 'Copied ' + (d.copied || 0) + ' dishes' + (d.skipped ? ', ' + d.skipped + ' already there' : '') + (d.failed && d.failed.length ? ', ' + d.failed.length + ' failed' : '') + '.';
        K.toast('Menu copied');
      });
    });
  });
}

var search = K.debounce(function () { V.more = 1; paint(); }, 200);
function wire(el) {
  if (el._mcpWired) return; el._mcpWired = true;
  el.addEventListener('click', function (e) {
    var t = e.target, b;
    if ((b = t.closest('[data-kpi]'))) { var k = b.getAttribute('data-kpi'); V.filter = (V.filter === k ? '' : k); V.more = 1; paint(); return; }
    if ((b = t.closest('[data-seg]'))) { V.cat = b.getAttribute('data-val'); V.more = 1; paint(); return; }
    if (t.closest('[data-act="refresh"]')) { load(true); return; }
    if (t.closest('[data-act="more"]')) { V.more++; paint(); return; }
    if (t.closest('[data-act="new"]')) { openDish(null); return; }
    if (t.closest('[data-act="copy"]')) { openCopy(); return; }
    if ((b = t.closest('[data-edit]'))) { var r = find(b.getAttribute('data-edit')); if (r) openDish(r); return; }
    if ((b = t.closest('[data-approve]'))) {
      var a = find(b.getAttribute('data-approve')); if (!a) return;
      K.api('admin_catalog_save', { id: a.id, name: a.name, name_am: a.name_am || '', description: a.description || '', description_am: a.description_am || '', category: a.category, cuisine: a.cuisine || '', packs: a.packs || '', image_url: a.image_url || '', prep_time: a.prep_time || 20, status: 'active' })
        .then(function (res) { if (K.okRes(res)) { K.toast('Dish approved'); V.busy = false; load(true); } else K.toast(K.failText(res), true); });
      return;
    }
    if ((b = t.closest('[data-del]'))) {
      var d = find(b.getAttribute('data-del')); if (!d) return;
      var hide = shops(d) > 0;
      K.confirm({ title: (hide ? 'Hide "' : 'Delete "') + (d.name || 'this dish') + '"?', text: hide ? 'Shops that already sell it keep their items.' : 'It is removed from the catalog for good.', label: hide ? 'Hide' : 'Delete', danger: true }).then(function (ok) {
        if (ok) K.api('admin_catalog_delete', { id: parseInt(d.id, 10) }).then(function (res) { if (K.okRes(res)) { K.toast(hide ? 'Dish hidden' : 'Dish deleted'); V.busy = false; load(true); } else K.toast(K.failText(res), true); });
      });
    }
  });
  el.addEventListener('input', function (e) { if (e.target.id === 'mcp-q') { V.q = e.target.value.trim(); search(); } });
}

function install() {
  var el = root(); if (!el) return;
  wire(el);
  window.loadMenuCatalog = function () { return load(V.loaded); };
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();

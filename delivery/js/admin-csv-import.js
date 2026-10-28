/* ETEL Admin - CSV import panel, shared by Menu catalog (add many dishes) and Items (load one shop's menu).
   Flow in one side panel: choose the file, press "Check file" (the server does the whole import but saves nothing and answers
   with the counts and any bad rows), then press "Import". Nothing is saved before the second step is confirmed.
   Data: admin_catalog_import and admin_items_import (+ admin_get_service_givers for the shop picker).
   Built on AdminKit; every server value goes through AdminKit.esc; no inline handlers. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K || window.EtelCsvImport) return;
var esc = K.esc;

/* ---------- CSV reading: BOM, comma / semicolon / tab, quoted fields, quotes inside quotes, line breaks inside quotes ---------- */
function parse(text) {
  text = String(text || '').replace(/^﻿/, '');
  var first = text.split(/\r?\n/, 1)[0] || '';
  var n = { ',': (first.match(/,/g) || []).length, ';': (first.match(/;/g) || []).length, '\t': (first.match(/\t/g) || []).length };
  var d = n[';'] > n[','] && n[';'] >= n['\t'] ? ';' : (n['\t'] > n[','] ? '\t' : ',');
  var rows = [], row = [], cur = '', q = false, i, ch;
  for (i = 0; i < text.length; i++) {
    ch = text.charAt(i);
    if (q) { if (ch === '"') { if (text.charAt(i + 1) === '"') { cur += '"'; i++; } else q = false; } else cur += ch; }
    else if (ch === '"') q = true;
    else if (ch === d) { row.push(cur); cur = ''; }
    else if (ch === '\n' || ch === '\r') { if (ch === '\r' && text.charAt(i + 1) === '\n') i++; row.push(cur); cur = ''; if (row.some(function (c) { return c.trim() !== ''; })) rows.push(row); row = []; }
    else cur += ch;
  }
  row.push(cur); if (row.some(function (c) { return c.trim() !== ''; })) rows.push(row);
  return rows;
}

/* table + {key: [header names]} -> {rows:[{key: value, _line}], error}. The header row decides which column is which. */
function toRows(table, aliases, required) {
  if (table.length < 2) return { rows: [], error: 'The file needs a header row and at least one line below it.' };
  var head = table[0].map(function (h) { return h.trim().toLowerCase().replace(/[\s-]+/g, '_'); }), idx = {};
  Object.keys(aliases).forEach(function (k) { idx[k] = head.findIndex(function (h) { return aliases[k].indexOf(h) !== -1; }); });
  var miss = required.filter(function (k) { return idx[k] < 0; });
  if (miss.length) return { rows: [], error: 'The first row must have the column' + (miss.length > 1 ? 's' : '') + ' "' + miss.join('", "') + '". Download the template to see the format.' };
  var rows = table.slice(1).map(function (r, n) {
    var o = { _line: n + 2 };
    Object.keys(idx).forEach(function (k) { if (idx[k] >= 0) o[k] = (r[idx[k]] || '').trim(); });
    return o;
  });
  return { rows: rows, error: '' };
}

function download(name, csv) {
  var blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }), a = document.createElement('a');
  a.href = URL.createObjectURL(blob); a.download = name; document.body.appendChild(a); a.click(); a.remove();
  setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
}

/* ---------- the panel ---------- */
function panel(cfg) {
  var S = { rows: null, checked: false, busy: false };
  var head = '<div class="ak-av lg" style="--a:#E8C14A;--b:#9a7a1c">' + cfg.icon + '</div><div class="ak-nm"><b>' + esc(cfg.title) + '</b><small>' + esc(cfg.sub) + '</small></div>';
  var body = '<div class="ak-sec"><h4>1. Your file</h4><p class="ak-fh" style="margin:0 0 10px">' + cfg.intro + '</p>' +
    '<button type="button" class="ak-btn" data-tpl>⬇ Download the template</button></div>' +
    (cfg.extraHtml ? '<div class="ak-sec"><h4>2. ' + esc(cfg.extraTitle) + '</h4>' + cfg.extraHtml + '</div>' : '') +
    '<div class="ak-sec"><h4>' + (cfg.extraHtml ? '3' : '2') + '. Choose the CSV file</h4>' + K.field('CSV file', '<input id="csvi-file" type="file" class="ak-in" accept=".csv,text/csv,text/plain">', 'Save your spreadsheet as CSV first.') +
    '<div id="csvi-read" class="ak-fh" style="margin-top:8px" role="status"></div></div>' +
    '<div id="csvi-out" role="status" aria-live="polite" style="margin-top:14px"></div>';
  var foot = '<span class="ak-err" id="csvi-msg" role="status"></span><button type="button" class="ak-btn" data-close>Close</button>' +
    '<button type="button" class="ak-btn" data-check disabled>🔍 Check file</button><button type="button" class="ak-btn gold" data-run disabled>Import</button>';
  var dr = K.openDrawer(head, body, foot);
  var btnCheck = dr.querySelector('[data-check]'), btnRun = dr.querySelector('[data-run]');
  function el(id) { return document.getElementById(id); }
  function say(t) { var m = el('csvi-msg'); if (m) m.textContent = t || ''; }
  function sync() { btnCheck.disabled = S.busy || !S.rows; btnRun.disabled = S.busy || !S.rows || !S.checked; }
  function reset() { S.checked = false; el('csvi-out').innerHTML = ''; say(''); sync(); }

  dr.querySelector('[data-tpl]').addEventListener('click', function () { download(cfg.templateName, cfg.templateCsv); });
  if (cfg.bind) cfg.bind(dr, reset);
  el('csvi-file').addEventListener('change', function (e) {
    var f = e.target.files && e.target.files[0]; S.rows = null; reset();
    if (!f) { el('csvi-read').textContent = ''; return; }
    var rd = new FileReader();
    rd.onload = function () {
      var r = toRows(parse(rd.result), cfg.aliases, cfg.required);
      if (r.error) { el('csvi-read').innerHTML = '<span style="color:#ff5d6c">' + esc(r.error) + '</span>'; sync(); return; }
      if (r.rows.length > cfg.limit) { el('csvi-read').innerHTML = '<span style="color:#ff5d6c">The file has ' + K.num(r.rows.length) + ' lines. Import up to ' + K.num(cfg.limit) + ' at a time: split it into smaller files.</span>'; sync(); return; }
      S.rows = r.rows; el('csvi-read').textContent = 'Read ' + K.num(r.rows.length) + ' lines from "' + f.name + '". Press Check file to see what would happen.'; sync();
    };
    rd.onerror = function () { el('csvi-read').textContent = 'Could not read that file.'; };
    rd.readAsText(f, 'utf-8');
  });

  function send(dry) {
    var extra = cfg.extraBody ? cfg.extraBody() : {};
    if (extra === null) return Promise.resolve(null);
    S.busy = true; sync(); say(dry ? 'Checking…' : 'Importing…');
    return K.api(cfg.action, Object.assign({ rows: S.rows, dry_run: dry ? 1 : 0 }, extra)).then(function (res) { S.busy = false; sync(); say(''); return res; });
  }
  function show(d, dry) {
    var chips = cfg.chips(d).map(function (c) { return '<span class="ak-chip">' + esc(c[0]) + ' <b>' + esc(K.num(c[1])) + '</b></span>'; }).join('');
    var bad = (d.invalid || []).concat((d.failed || []).map(function (f) { return { row: f.row, reason: f.error }; }));
    el('csvi-out').innerHTML = '<div class="ak-sec"><h4>' + (dry ? 'This is what would happen' : 'Done') + (d.shop ? ' for ' + esc(d.shop) : '') + '</h4><div class="ak-chips">' + chips + '</div>' +
      (bad.length ? '<div class="ak-fh" style="margin-top:10px"><b>' + K.num(bad.length) + ' line' + (bad.length === 1 ? '' : 's') + ' will be left out:</b><ul style="margin:6px 0 0 18px;padding:0">' +
        bad.slice(0, 15).map(function (b) { return '<li>Line ' + esc(b.row) + ': ' + esc(b.reason) + '</li>'; }).join('') + (bad.length > 15 ? '<li>and ' + K.num(bad.length - 15) + ' more</li>' : '') + '</ul></div>' : '') +
      (dry ? '<p class="ak-fh" style="margin-top:10px">Nothing is saved yet. If this looks right, press Import.</p>' : '') + '</div>';
  }
  btnCheck.addEventListener('click', function () {
    send(true).then(function (res) {
      if (!res) return;
      if (!K.okRes(res)) { say(K.failText(res, 'Could not check the file.')); return; }
      S.checked = true; show(K.data(res), true); sync();
    });
  });
  btnRun.addEventListener('click', function () {
    K.confirm({ title: cfg.confirmTitle, text: cfg.confirmText(), label: 'Import' }).then(function (r) {
      if (!r) return;
      send(false).then(function (res) {
        if (!res) return;
        if (!K.okRes(res)) { say(K.failText(res, 'Could not import the file.')); return; }
        S.checked = false; S.rows = null; show(K.data(res), false); sync(); K.toast('Import finished');
        if (cfg.onDone) cfg.onDone();
      });
    });
  });
  return dr;
}

/* ---------- Menu catalog ---------- */
var CAT_COLS = 'name,name_am,description,description_am,category,cuisine,packs,prep_time,status,sort_order,image_file';
function openCatalog(onDone) {
  panel({
    icon: '📥', title: 'Import dishes', sub: 'Add many dishes to the menu catalog from a CSV file.',
    intro: 'One dish per line. Only <b>name</b> is required. Columns: ' + esc(CAT_COLS.replace(/,/g, ', ')) + '. For a photo, write its file name in <b>image_file</b> (for example kitfo.jpg) and upload the photos to <b>delivery/img/menu/</b>. A photo is linked only when its file is already there: upload more photos later and import the same file again with "Update".',
    templateName: 'menu-catalog-template.csv',
    templateCsv: CAT_COLS + '\r\nShiro,ሽሮ,Ground chickpea stew with injera.,,food,Ethiopian,"ethiopian_restaurant,fasting",15,active,10,shiro.jpg\r\nMacchiato,ማኪያቶ,Espresso with a little milk foam.,,food,Coffee & Tea,cafe,5,active,20,\r\n',
    aliases: { name: ['name', 'dish', 'item', 'title'], name_am: ['name_am', 'amharic', 'amharic_name', 'ስም'], description: ['description', 'desc'], description_am: ['description_am'],
               category: ['category', 'type'], cuisine: ['cuisine', 'group'], packs: ['packs', 'starter_packs', 'shop_types'], prep_time: ['prep_time', 'prep', 'preparation_time'],
               status: ['status'], sort_order: ['sort_order', 'order'], image_url: ['image_url', 'photo_url'], image_file: ['image_file', 'photo', 'photo_file'] },
    required: ['name'], limit: 500, action: 'admin_catalog_import',
    extraTitle: 'Dishes already in the catalog',
    extraHtml: '<label style="display:flex;gap:8px;align-items:flex-start;margin:6px 0;font-size:13.5px"><input type="radio" name="csvi-mode" value="skip" checked> <span>Skip them (safe: nothing already there is changed)</span></label>' +
               '<label style="display:flex;gap:8px;align-items:flex-start;margin:6px 0;font-size:13.5px"><input type="radio" name="csvi-mode" value="update"> <span>Update them with the values in the file</span></label>',
    extraBody: function () { var c = document.querySelector('input[name="csvi-mode"]:checked'); return { mode: c ? c.value : 'skip' }; },
    bind: function (dr, reset) { dr.addEventListener('change', function (e) { if (e.target && e.target.name === 'csvi-mode') reset(); }); },
    chips: function (d) { return [['Will be added', d.added || 0], ['Will be updated', d.updated || 0], ['Already there', d.skipped || 0], ['Photos not uploaded yet', d.photos_missing || 0]]; },
    confirmTitle: 'Import these dishes?', confirmText: function () { var c = document.querySelector('input[name="csvi-mode"]:checked'); return 'The dishes are added to the menu catalog' + (c && c.value === 'update' ? ' and the ones already there are updated.' : '. Dishes already there are not changed.'); },
    onDone: onDone
  });
}

/* ---------- a shop's items ---------- */
function openItems(onDone) {
  var shops = null;
  function modeVal() { var c = document.querySelector('input[name="csvi-imode"]:checked'); return c ? c.value : 'skip'; }
  var dr = panel({
    icon: '🏪', title: 'Import a shop\'s menu', sub: 'Load a shop\'s items, sections, prices and photos from a CSV file.',
    intro: 'One item per line. <b>name</b> and <b>price</b> are required. Optional: <b>section</b> (the heading the customer taps, like Burger, Chicken, Drinks), <b>popular</b> (yes = shown under Popular), <b>image_file</b> (a photo file name such as special-burger.jpg), <b>extras</b> (add-ons the customer can tick, written Name:price and separated by |, for example Mayonnaise:50|Cheese:100), <b>more_photos</b> (more photo file names separated by |, shown as a swipeable carousel), name_am, category (food, grocery, pharmacy, package, flower), quality (regular, special, premium) and portion. Upload the photos to <b>delivery/img/menu/</b> first, or after and import again with "Update". Items whose name matches a dish in the menu catalog are linked to it.',
    templateName: 'shop-menu-template.csv',
    templateCsv: 'name,price,section,popular,image_file,extras,more_photos,category,quality,portion,name_am\r\nSpecial Burger,750,Burger,yes,special-burger.jpg,"Mayonnaise:50|Ketchup:50|Chili:50|Cheese:100",special-burger-2.jpg|special-burger-3.jpg,food,special,,\r\nChicken Shawarma,800,Shawarma,yes,chicken-shawarma.jpg,,,food,,,\r\nBeef Chao Fan,450,Breakfast,,,,,food,,,\r\nMojito,180,Drinks,,mojito.jpg,,,food,,Large,\r\nMacchiato,35,Hot Drinks,,,,,food,,,ማኪያቶ\r\n',
    aliases: { name: ['name', 'dish', 'item', 'title', 'ስም'], price: ['price', 'cost', 'amount', 'ዋጋ'], quality: ['quality'], portion: ['portion', 'size'], category: ['category', 'type'], name_am: ['name_am', 'amharic'],
               section: ['section', 'menu_section', 'group', 'menu', 'heading'], popular: ['popular', 'most_popular', 'star'], image_file: ['image_file', 'photo', 'photo_file', 'image'], image_url: ['image_url', 'photo_url'],
               extras: ['extras', 'extra', 'add_ons', 'addons'], more_photos: ['more_photos', 'photos', 'gallery', 'more_images'] },
    required: ['name', 'price'], limit: 300, action: 'admin_items_import',
    extraTitle: 'Which shop?',
    extraHtml: '<div id="csvi-shop">' + K.skeleton(1) + '</div>' +
               '<div style="margin-top:12px"><b style="font-size:13px">Items the shop already has</b>' +
               '<label style="display:flex;gap:8px;align-items:flex-start;margin:6px 0;font-size:13.5px"><input type="radio" name="csvi-imode" value="skip" checked> <span>Skip them (safe: nothing already there is changed)</span></label>' +
               '<label style="display:flex;gap:8px;align-items:flex-start;margin:6px 0;font-size:13.5px"><input type="radio" name="csvi-imode" value="update"> <span>Update them with what the file says (price, section, popular, photo)</span></label></div>',
    extraBody: function () {
      var s = document.getElementById('csvi-shopsel'), m = document.getElementById('csvi-msg');
      if (!s || !s.value) { if (m) m.textContent = 'Choose the shop first.'; return null; }
      return { service_giver_id: parseInt(s.value, 10), mode: modeVal() };
    },
    bind: function (d, reset) { d.addEventListener('change', function (e) { if (e.target && (e.target.id === 'csvi-shopsel' || e.target.name === 'csvi-imode')) reset(); }); },
    chips: function (d) { return [['Linked to the catalog', d.catalog || 0], ['New custom items', d.custom || 0], ['Updated', d.updated || 0], ['Already sold', d.skipped || 0], ['Menu sections', d.sections || 0], ['Photos not uploaded yet', d.photos_missing || 0]]; },
    confirmTitle: 'Import this menu?', confirmText: function () { var s = document.getElementById('csvi-shopsel'); return 'The items are added to "' + (s && s.selectedOptions[0] ? s.selectedOptions[0].textContent : 'the shop') + '"' + (modeVal() === 'update' ? ' and the ones it already has are updated.' : '. Items it already sells are left as they are.'); },
    onDone: onDone
  });
  K.api('admin_get_service_givers', {}).then(function (res) {
    var b = document.getElementById('csvi-shop'); if (!b) return;
    shops = K.okRes(res) ? (K.data(res).service_givers || []) : [];
    if (!shops.length) { b.innerHTML = K.empty('🏪', 'No shops yet', 'Add a service giver first, then come back.'); return; }
    b.innerHTML = K.field('Shop', '<select id="csvi-shopsel" class="ak-in"><option value="">Choose a shop…</option>' + shops.map(function (s) {
      return '<option value="' + esc(s.id) + '">' + esc((s.business_name || ('Shop #' + s.id)) + (s.zone ? ' · ' + s.zone : '')) + '</option>'; }).join('') + '</select>');
  });
  return dr;
}

window.EtelCsvImport = { parse: parse, toRows: toRows, openCatalog: openCatalog, openItems: openItems };
})();

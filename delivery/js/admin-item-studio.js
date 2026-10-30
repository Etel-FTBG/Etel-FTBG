/* ETEL Admin - Item studio: one guided panel to add or edit a menu item.
   Replaces the old Add Item / Edit Item pop-ups (openAdminAddItem / openAdminEditItem). A live preview card at the top shows the item the way a customer
   sees it (photo, price with the discount, section, popular star) and updates as you type. Sections: where it is sold (shop), the item (name, Amharic name,
   description, category, menu section with one-tap suggestions, popular), price and stock (discount worked out for you, quality, portion, hidden/available),
   photos (a main photo and up to 5 more, uploaded as soon as you pick them) and extras (add-ons with prices).
   Data: admin_add_item, admin_update_item, admin_item_extras_save (api/controllers/AdminController.php); photos go to api/upload.php.
   Built on AdminKit; every server value goes through AdminKit.esc; no inline handlers. "Add another" keeps the shop and section so a whole menu can be typed in quickly. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K || window.EtelItemStudio) return;
var esc = K.esc;

var CATS = [['food', '🍽️', 'Food'], ['grocery', '🛒', 'Grocery'], ['pharmacy', '💊', 'Pharmacy'], ['package', '📦', 'Package'], ['flower', '💐', 'Flower']];
var QUAL = [['', 'Not set'], ['regular', 'Regular'], ['special', 'Special'], ['premium', 'Premium']];
var S = { dr: null, shops: [], items: [], busy: 0 };
var X; // the item being edited / added

function blank(sg) { return { id: 0, sg: sg || 0, name: '', name_am: '', desc: '', cat: 'food', section: '', popular: false, price: '', orig: '', stock: '100', prep: '30', quality: '', portion: '', img: '', gallery: [], extras: [], avail: true }; }
function fromRow(r) {
  var g = []; try { g = Array.isArray(r.gallery) ? r.gallery : (r.gallery ? JSON.parse(r.gallery) : []); } catch (e) { g = []; }
  return { id: parseInt(r.id, 10), sg: parseInt(r.service_giver_id, 10), name: r.name || '', name_am: r.name_am || '', desc: r.description || '', cat: r.category || 'food', section: r.menu_section || '',
    popular: parseInt(r.is_popular, 10) === 1, price: r.price != null ? String(parseFloat(r.price)) : '', orig: r.original_price != null && parseFloat(r.original_price) !== parseFloat(r.price) ? String(parseFloat(r.original_price)) : '',
    stock: String(r.stock_quantity != null ? r.stock_quantity : 0), prep: String(r.preparation_time || 30), quality: r.quality || '', portion: r.portion || '', img: r.image_url || '', gallery: Array.isArray(g) ? g.slice(0, 5) : [],
    extras: (r.extras || []).map(function (e) { return { id: e.id, name: e.name, price: String(e.price) }; }), avail: String(r.is_available) !== '0' };
}

function css() {
  if (document.getElementById('ak-item-css')) return;
  var s = document.createElement('style'); s.id = 'ak-item-css';
  s.textContent = [
    '.ak-dr.ak-item{width:min(680px,100vw)}',
    '.ak-item .it-top{position:sticky;top:-16px;z-index:3;margin:-16px -18px 14px;padding:12px 18px;background:var(--card);border-bottom:1px solid var(--line)}',
    '.ak-item .it-prev{display:flex;gap:12px;align-items:center}',
    '.ak-item .it-ph{width:72px;height:72px;border-radius:14px;flex:none;background:linear-gradient(135deg,#f3ead0,#e7d49a) center/cover no-repeat;display:flex;align-items:center;justify-content:center;font-size:28px;border:1px solid var(--line)}',
    '.ak-item .it-pt{min-width:0;flex:1}.ak-item .it-pt b{display:block;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.ak-item .it-pp{display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;margin-top:2px}.ak-item .it-pp strong{font-size:16px;color:var(--gold,#b8902a)}.ak-item .it-pp s{color:var(--mute);font-size:12.5px}',
    '.ak-item .it-off{background:#3ad08a;color:#07261a;border-radius:99px;font-size:11px;font-weight:800;padding:2px 8px}',
    '.ak-item .it-chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:5px}.ak-item .it-chips span{font-size:11px;font-weight:700;padding:2px 9px;border-radius:99px;background:rgba(212,175,55,.14);color:var(--ink)}',
    '.ak-item .it-prog{display:flex;align-items:center;gap:10px;margin-top:10px;font-size:12px;color:var(--mute)}',
    '.ak-item .it-bar{flex:1;height:7px;border-radius:99px;background:var(--line);overflow:hidden}',
    '.ak-item .it-bar i{display:block;height:100%;width:0;border-radius:99px;background:linear-gradient(90deg,#E8C14A,#3ad08a);transition:width .25s}',
    '.ak-item .it-sec{border:1px solid var(--line);border-radius:16px;padding:14px 14px 6px;margin-bottom:14px;background:var(--card)}',
    '.ak-item .it-sec>h4{display:flex;align-items:center;gap:9px;margin:0 0 12px;font-size:14px}',
    '.ak-item .it-n{width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#1a1405;background:linear-gradient(135deg,#E8C14A,#b8902a)}',
    '.ak-item .it-sec.done .it-n{background:#3ad08a}',
    '.ak-item .it-f{display:block;margin-bottom:12px}',
    '.ak-item .it-f>span{display:block;font-size:11.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--mute);margin-bottom:5px}',
    '.ak-item .it-f>span em{color:var(--red,#d6364a);font-style:normal}',
    '.ak-item .it-f .ak-in{font-size:15px;padding:11px 12px}',
    '.ak-item .it-f.bad .ak-in{border-color:var(--red,#d6364a)}',
    '.ak-item .it-f small{display:block;margin-top:4px;font-size:11.5px;color:var(--mute);min-height:14px;text-transform:none;letter-spacing:0;font-family:inherit;font-weight:400}',
    '.ak-item .it-f.bad small{color:var(--red,#d6364a)}',
    '.ak-item .it-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}',
    '.ak-item .it-tiles{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}',
    '.ak-item .it-tiles button,.ak-item .it-sug button{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:99px;border:1.5px solid var(--line);background:var(--card);color:var(--ink);font:inherit;font-size:12.5px;font-weight:600;cursor:pointer}',
    '.ak-item .it-tiles button.on,.ak-item .it-sug button.on{border-color:var(--gold);background:rgba(212,175,55,.14);box-shadow:0 0 0 3px rgba(212,175,55,.18)}',
    '.ak-item .it-sug{display:flex;gap:6px;flex-wrap:wrap;margin:-4px 0 12px}',
    '.ak-item .it-sw{display:flex;gap:10px;align-items:center;margin-bottom:12px;font-size:13.5px;cursor:pointer}',
    '.ak-item .it-drop{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;min-height:120px;border:2px dashed rgba(212,175,55,.55);border-radius:14px;background:rgba(212,175,55,.06) center/cover no-repeat;cursor:pointer;text-align:center;font-size:13px;color:var(--mute);margin-bottom:12px;position:relative;overflow:hidden}',
    '.ak-item .it-drop.has{min-height:180px}.ak-item .it-drop.over{background-color:rgba(212,175,55,.2)}',
    '.ak-item .it-drop b{color:var(--ink)}.ak-item .it-drop .it-chg{position:absolute;right:8px;bottom:8px;padding:5px 12px;border-radius:99px;background:rgba(26,26,46,.85);color:#E8C14A;font-size:12px;font-weight:700}',
    '.ak-item .it-gal{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}',
    '.ak-item .it-th{position:relative;width:72px;height:72px;border-radius:12px;background:center/cover no-repeat;border:1px solid var(--line)}',
    '.ak-item .it-th button{position:absolute;top:-6px;right:-6px;width:22px;height:22px;border-radius:50%;border:0;background:#d6364a;color:#fff;cursor:pointer;font-size:12px}',
    '.ak-item .it-add{width:72px;height:72px;border-radius:12px;border:2px dashed var(--line);background:transparent;color:var(--mute);font-size:24px;cursor:pointer}',
    '.ak-item .it-xr{display:grid;grid-template-columns:1fr 110px 36px;gap:8px;margin-bottom:8px}',
    '.ak-item .it-xr button{border:1.5px solid var(--line);border-radius:10px;background:transparent;color:#d6364a;cursor:pointer}',
    '.ak-item .it-done{text-align:center;padding:10px 0 4px}.ak-item .it-ok{width:64px;height:64px;margin:0 auto 10px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:30px;background:linear-gradient(135deg,#3ad08a,#1fa463);color:#fff}',
    '@media(max-width:560px){.ak-item .it-2{grid-template-columns:1fr}.ak-item .it-xr{grid-template-columns:1fr 90px 36px}}'
  ].join('\n');
  document.head.appendChild(s);
}

function $(id) { return document.getElementById(id); }
function field(id, label, input, hint, req) { return '<label class="it-f" data-f="' + id + '"><span>' + esc(label) + (req ? ' <em>*</em>' : '') + '</span>' + input + '<small data-h="' + esc(hint || '') + '">' + esc(hint || '') + '</small></label>'; }
function inp(id, val, attrs) { return '<input id="' + id + '" class="ak-in" value="' + esc(val == null ? '' : val) + '" autocomplete="off" ' + (attrs || '') + '>'; }
function mark(id, msg) {
  var f = S.dr && S.dr.querySelector('[data-f="' + id + '"]'); if (!f) return;
  f.classList.toggle('bad', !!msg);
  var sm = f.querySelector('small'); if (sm) sm.textContent = msg || sm.getAttribute('data-h') || '';
}
function shopName(id) { var n = ''; S.shops.forEach(function (s) { if (parseInt(s.id, 10) === parseInt(id, 10)) n = s.business_name || s.name || ('Shop ' + s.id); }); return n; }
function sectionsOf(sg) { var o = {}; S.items.forEach(function (i) { if (parseInt(i.service_giver_id, 10) === parseInt(sg, 10) && i.menu_section) o[i.menu_section] = 1; }); return Object.keys(o).sort(); }

/* ---------- markup ---------- */
function tiles(list, cur, attr, withIcon) {
  return '<div class="it-tiles" role="group">' + list.map(function (t) { var on = cur === t[0]; return '<button type="button" ' + attr + '="' + esc(t[0]) + '" class="' + (on ? 'on' : '') + '" aria-pressed="' + on + '">' + (withIcon ? t[1] + ' ' + esc(t[2]) : esc(t[1])) + '</button>'; }).join('') + '</div>';
}
function sugHtml() {
  var s = sectionsOf(X.sg); if (!s.length) return '';
  return '<div class="it-sug" role="group" aria-label="Sections this shop already uses">' + s.map(function (n) { return '<button type="button" data-sec-pick="' + esc(n) + '" class="' + (X.section === n ? 'on' : '') + '">' + esc(n) + '</button>'; }).join('') + '</div>';
}
function extraRow(e, i) {
  return '<div class="it-xr" data-xi="' + i + '"><input class="ak-in" data-xn value="' + esc(e.name) + '" maxlength="80" placeholder="e.g. Cheese" aria-label="Extra name">' +
    '<input class="ak-in" data-xp type="number" min="0" step="0.01" value="' + esc(e.price) + '" placeholder="Price" aria-label="Extra price"><button type="button" data-xdel="' + i + '" aria-label="Remove extra">✕</button></div>';
}
function galHtml() {
  return X.gallery.map(function (u, i) { return '<div class="it-th" style="background-image:url(\'' + esc(u).replace(/'/g, '%27') + '\')"><button type="button" data-gdel="' + i + '" aria-label="Remove photo">✕</button></div>'; }).join('') +
    (X.gallery.length < 5 ? '<button type="button" class="it-add" data-gadd aria-label="Add photos">＋</button>' : '');
}
function form() {
  var shopSel = X.id ? '<div class="it-f"><span>Shop</span><div style="font-weight:700;font-size:15px">' + esc(shopName(X.sg)) + '</div></div>' :
    field('it-sg', 'Shop', '<select id="it-sg" class="ak-in">' + S.shops.map(function (s) { return '<option value="' + esc(s.id) + '"' + (parseInt(s.id, 10) === parseInt(X.sg, 10) ? ' selected' : '') + '>' + esc(s.business_name || s.name || ('Shop ' + s.id)) + '</option>'; }).join('') + '</select>', 'The business that sells this item.', true);
  return '<div class="it-top"><div class="it-prev"><div class="it-ph" id="it-ph"></div><div class="it-pt"><b id="it-pn"></b><div class="it-pp" id="it-pp"></div><div class="it-chips" id="it-pc"></div></div></div>' +
      '<div class="it-prog"><div class="it-bar"><i id="it-bar"></i></div><b id="it-count"></b></div></div>' +

    '<section class="it-sec" data-sec="item"><h4><span class="it-n">1</span>The item</h4>' + shopSel +
      field('it-name', 'Name', inp('it-name', X.name, 'type="text" placeholder="e.g. Special Burger"'), '', true) +
      field('it-nam', 'Amharic name', inp('it-nam', X.name_am, 'type="text" placeholder="ለምሳሌ: ስፔሻል በርገር"'), 'Optional. Shown to customers who switch the app to Amharic.') +
      field('it-desc', 'Description', '<textarea id="it-desc" class="ak-in" rows="2" placeholder="What is in it? Size, taste, anything that helps the customer.">' + esc(X.desc) + '</textarea>', 'Optional.') +
      '<div class="it-f"><span>Category</span></div>' + tiles(CATS, X.cat, 'data-cat', true) +
      field('it-sec', 'Menu section', inp('it-sec', X.section, 'type="text" maxlength="60" placeholder="e.g. Burger, Drinks, Breakfast"'), 'The heading customers tap in the shop. Leave empty for no section.') + '<div id="it-sug">' + sugHtml() + '</div>' +
      '<label class="it-sw"><input type="checkbox" id="it-pop"' + (X.popular ? ' checked' : '') + '> ⭐ Show under "Most popular"</label></section>' +

    '<section class="it-sec" data-sec="price"><h4><span class="it-n">2</span>Price and stock</h4>' +
      '<div class="it-2">' + field('it-price', 'Price (ETB)', inp('it-price', X.price, 'type="number" min="0" step="0.01" inputmode="decimal" placeholder="0.00"'), '', true) +
      field('it-orig', 'Price before discount', inp('it-orig', X.orig, 'type="number" min="0" step="0.01" inputmode="decimal" placeholder="Only if discounted"'), 'Shows the old price crossed out.') + '</div>' +
      '<div class="it-2">' + field('it-stock', 'Stock', inp('it-stock', X.stock, 'type="number" min="0"'), 'How many can be sold today.') + field('it-prep', 'Preparation time (min)', inp('it-prep', X.prep, 'type="number" min="0"'), '') + '</div>' +
      '<div class="it-f"><span>Quality</span></div>' + tiles(QUAL, X.quality, 'data-q', false) +
      field('it-portion', 'Portion', inp('it-portion', X.portion, 'type="text" maxlength="40" placeholder="e.g. Large, 500g"'), 'Optional.') +
      (X.id ? '<label class="it-sw"><input type="checkbox" id="it-av"' + (X.avail ? ' checked' : '') + '> Available to customers (untick to hide it)</label>' : '') + '</section>' +

    '<section class="it-sec" data-sec="photo"><h4><span class="it-n">3</span>Photos</h4>' +
      '<div class="it-f"><span>Main photo</span></div><div class="it-drop' + (X.img ? ' has' : '') + '" id="it-drop" tabindex="0" role="button" aria-label="Choose the main photo"' + (X.img ? ' style="background-image:url(\'' + esc(X.img).replace(/\'/g, '%27') + '\')"' : '') + '>' +
        (X.img ? '<span class="it-chg">Change photo</span>' : '<div style="font-size:28px">📷</div><b>Drop a photo here or tap to choose</b><div>A bright, clear picture sells. Without one, customers see the shop logo.</div>') + '</div>' +
      '<input type="file" id="it-file" accept="image/*" hidden>' +
      '<div class="it-f"><span>More photos <small style="display:inline;text-transform:none;letter-spacing:0;font-weight:400">(customers swipe through them, up to 5)</small></span></div><div class="it-gal" id="it-gal">' + galHtml() + '</div>' +
      '<input type="file" id="it-gfile" accept="image/*" multiple hidden></section>' +

    '<section class="it-sec" data-sec="x"><h4><span class="it-n">4</span>Extras <small style="font-weight:400;color:var(--mute)">(optional)</small></h4>' +
      '<p class="ak-fh" style="margin:0 0 8px">Add-ons the customer can tick, like Cheese or Extra sauce. The price is added to the item price.</p><div id="it-xl">' + X.extras.map(extraRow).join('') + '</div>' +
      '<button type="button" class="ak-btn" data-xadd>➕ Add an extra</button><div style="height:12px"></div></section>';
}
function head() {
  return '<div class="ak-av lg" style="--a:#E8C14A;--b:#9a7a1c">🍔</div><div class="ak-nm"><b>' + (X.id ? 'Edit item' : 'Add an item') + '</b><small>' + (X.id ? esc(X.name) : 'A dish or product on a shop\'s menu') + '</small></div>';
}
function foot() {
  return '<span class="ak-err" id="it-msg" role="status" aria-live="polite"></span><button type="button" class="ak-btn" data-close>Cancel</button><button type="button" class="ak-btn gold" data-save>' + (X.id ? '💾 Save changes' : '✓ Add item') + '</button>';
}

/* ---------- live preview and progress ---------- */
function money(v) { var n = parseFloat(v); return isFinite(n) ? 'ETB ' + (Math.round(n * 100) / 100).toString() : ''; }
function readForm() {
  X.name = $('it-name').value.trim(); X.name_am = $('it-nam').value.trim(); X.desc = $('it-desc').value.trim();
  X.section = $('it-sec').value.trim(); X.popular = $('it-pop').checked;
  X.price = $('it-price').value.trim(); X.orig = $('it-orig').value.trim(); X.stock = $('it-stock').value.trim(); X.prep = $('it-prep').value.trim(); X.portion = $('it-portion').value.trim();
  if ($('it-av')) X.avail = $('it-av').checked;
  var sg = $('it-sg'); if (sg) X.sg = parseInt(sg.value, 10) || 0;
  X.extras = Array.prototype.map.call(S.dr.querySelectorAll('#it-xl .it-xr'), function (r, i) { return { id: (X.extras[i] && X.extras[i].id) || 0, name: r.querySelector('[data-xn]').value.trim(), price: r.querySelector('[data-xp]').value.trim() }; });
}
function refresh() {
  if (!S.dr) return;
  var price = parseFloat(X.price), orig = parseFloat(X.orig), off = isFinite(price) && isFinite(orig) && orig > price ? Math.round((1 - price / orig) * 100) : 0;
  var ph = $('it-ph'); ph.style.backgroundImage = X.img ? 'url(\'' + X.img.replace(/'/g, '%27') + '\')' : ''; ph.textContent = X.img ? '' : '🍽️';
  $('it-pn').textContent = (X.name || 'Item name') + (X.popular ? ' ⭐' : '');
  $('it-pp').innerHTML = '<strong>' + esc(money(X.price) || 'ETB 0') + '</strong>' + (off ? '<s>' + esc(money(X.orig)) + '</s><span class="it-off">-' + off + '%</span>' : '');
  var chips = []; if (X.section) chips.push(X.section); if (X.quality) chips.push(X.quality); if (X.portion) chips.push(X.portion);
  var xs = X.extras.filter(function (e) { return e.name; }).length; if (xs) chips.push(xs + (xs === 1 ? ' extra' : ' extras')); if (X.id && !X.avail) chips.push('Hidden');
  $('it-pc').innerHTML = chips.map(function (c) { return '<span>' + esc(c) + '</span>'; }).join('');
  var c = [X.sg > 0 && X.name.length > 0, isFinite(price) && price > 0, !!X.img, true], n = [c[0], c[1], c[2]].filter(Boolean).length;
  $('it-bar').style.width = Math.round(n / 3 * 100) + '%'; $('it-count').textContent = n + ' of 3 ready';
  [['item', c[0]], ['price', c[1]], ['photo', c[2]]].forEach(function (a) { var sec = S.dr.querySelector('[data-sec="' + a[0] + '"]'); if (sec) sec.classList.toggle('done', !!a[1]); });
}
function repaint(part) {
  if (part === 'tiles') { S.dr.querySelectorAll('[data-cat]').forEach(function (b) { var on = b.getAttribute('data-cat') === X.cat; b.classList.toggle('on', on); b.setAttribute('aria-pressed', String(on)); });
    S.dr.querySelectorAll('[data-q]').forEach(function (b) { var on = b.getAttribute('data-q') === X.quality; b.classList.toggle('on', on); b.setAttribute('aria-pressed', String(on)); }); }
  if (part === 'sug') $('it-sug').innerHTML = sugHtml();
  if (part === 'gal') $('it-gal').innerHTML = galHtml();
  if (part === 'x') $('it-xl').innerHTML = X.extras.map(extraRow).join('');
  if (part === 'drop') { var d = $('it-drop'); d.classList.toggle('has', !!X.img); d.style.backgroundImage = X.img ? 'url(\'' + X.img.replace(/'/g, '%27') + '\')' : ''; d.innerHTML = X.img ? '<span class="it-chg">Change photo</span>' : '<div style="font-size:28px">📷</div><b>Drop a photo here or tap to choose</b><div>A bright, clear picture sells. Without one, customers see the shop logo.</div>'; }
  refresh();
}

/* ---------- photos ---------- */
function uploadOne(file) {
  var fd = new FormData(); fd.append('image', file);
  return fetch(ETEL.api.replace('boot.php', 'upload.php'), { method: 'POST', headers: { 'Authorization': 'Bearer ' + Auth.getToken() }, body: fd })
    .then(function (r) { return r.json(); }).then(function (j) { if (!j || !j.success || !j.data) throw new Error((j && j.message) || 'Upload failed'); return j.data.url; });
}
function pickMain(file) {
  if (!file || !/^image\//.test(file.type)) { K.toast('Choose a picture file', true); return; }
  S.busy++; $('it-msg').textContent = 'Uploading the photo…';
  uploadOne(file).then(function (u) { X.img = u; repaint('drop'); }).catch(function (e) { K.toast(e.message, true); }).then(function () { S.busy--; if (!S.busy) $('it-msg').textContent = ''; });
}
function pickMore(files) {
  var list = Array.prototype.slice.call(files || []).filter(function (f) { return /^image\//.test(f.type); }).slice(0, 5 - X.gallery.length);
  if (!list.length) return;
  S.busy++; $('it-msg').textContent = 'Uploading ' + list.length + (list.length === 1 ? ' photo…' : ' photos…');
  list.reduce(function (p, f) { return p.then(function () { return uploadOne(f).then(function (u) { X.gallery.push(u); repaint('gal'); }); }); }, Promise.resolve())
    .catch(function (e) { K.toast(e.message, true); }).then(function () { S.busy--; if (!S.busy) $('it-msg').textContent = ''; });
}

/* ---------- save ---------- */
function validate() {
  var ok = true, first = null, price = parseFloat(X.price), orig = parseFloat(X.orig);
  function need(id, bad, msg) { mark(id, bad ? msg : ''); if (bad && ok) { ok = false; first = id; } else if (bad) ok = false; }
  need('it-sg', !(X.sg > 0), 'Choose the shop.');
  need('it-name', !X.name, 'Enter the item name.');
  need('it-price', !(isFinite(price) && price > 0), 'Enter a price above 0.');
  need('it-orig', X.orig !== '' && !(isFinite(orig) && orig >= price), 'The price before discount should be higher than the price, or leave it empty.');
  var xbad = X.extras.some(function (e) { return e.name && e.price !== '' && !(parseFloat(e.price) >= 0); });
  if (xbad) { ok = false; K.toast('Every extra needs a price of 0 or more.', true); }
  if (!ok && first) { var e = $(first); if (e) { e.scrollIntoView({ block: 'center', behavior: 'smooth' }); e.focus(); } }
  return ok;
}
function done(msg) {
  var el = S.dr.querySelector('.ak-dr-body');
  el.innerHTML = '<div class="it-done"><div class="it-ok">✓</div><h3 style="margin:0 0 4px">' + esc(msg) + '</h3><p class="ak-fh" style="margin:0"><b>' + esc(X.name) + '</b> · ' + esc(money(X.price)) + ' · ' + esc(shopName(X.sg)) + '</p></div>';
  S.dr.querySelector('.ak-dr-foot').innerHTML = '<button type="button" class="ak-btn" data-again>➕ Add another to this shop</button><button type="button" class="ak-btn gold" data-close>Done</button>';
  try { if (typeof window.loadItems === 'function') window.loadItems(); } catch (e) { /* the list refreshes on its next visit */ }
}
function save(btn) {
  readForm();
  if (S.busy) { $('it-msg').textContent = 'Wait for the photo to finish uploading.'; return; }
  if (!validate()) { $('it-msg').textContent = 'Check the highlighted fields.'; return; }
  var price = parseFloat(X.price), orig = X.orig !== '' ? parseFloat(X.orig) : price, msg = $('it-msg');
  var p = { name: X.name, name_am: X.name_am, description: X.desc, category: X.cat, price: price, original_price: orig, stock_quantity: parseInt(X.stock, 10) || 0, preparation_time: parseInt(X.prep, 10) || 30,
    image_url: X.img, menu_section: X.section, is_popular: X.popular ? 1 : 0, quality: X.quality, portion: X.portion };
  var extras = X.extras.filter(function (e) { return e.name; }).map(function (e) { return { id: e.id || 0, name: e.name, price: e.price === '' ? 0 : e.price }; });
  btn.disabled = true; msg.textContent = 'Saving…';
  var call;
  if (X.id) { p.item_id = X.id; p.is_available = X.avail ? 1 : 0; call = K.api('admin_update_item', p); }
  else { p.service_giver_id = X.sg; call = K.api('admin_add_item', p); }
  call.then(function (res) {
    if (!K.okRes(res)) { btn.disabled = false; msg.textContent = K.failText(res); return; }
    var id = X.id || parseInt((K.data(res) || {}).item_id, 10);
    if (!id || (!X.id && !extras.length && !X.gallery.length)) { done(X.id ? 'Item saved' : 'Item added'); return; }
    return K.api('admin_item_extras_save', { item_id: id, extras: extras, gallery: X.gallery }).then(function (r2) {
      if (!K.okRes(r2)) { btn.disabled = false; X.id = id; msg.textContent = 'The item was saved, but extras or photos were not: ' + K.failText(r2); try { window.loadItems(); } catch (e) { /* later */ } return; }
      done(X.id ? 'Item saved' : 'Item added');
    });
  });
}

/* ---------- open ---------- */
function loadData() {
  var shops = (typeof sgListCache !== 'undefined' && sgListCache && sgListCache.length) ? Promise.resolve(sgListCache) :
    K.api('admin_service_givers_board', {}).then(function (r) { return K.okRes(r) ? (K.data(r).rows || K.data(r).service_givers || []) : []; });
  var items = (typeof itemsData !== 'undefined' && itemsData && itemsData.length) ? Promise.resolve(itemsData) :
    K.api('admin_get_all_items', {}).then(function (r) { return K.okRes(r) ? (K.data(r).items || []) : []; });
  return Promise.all([shops, items]).then(function (a) { S.shops = a[0]; S.items = a[1]; });
}
function open(opts) {
  css();
  return loadData().then(function () {
    if (!S.shops.length) { K.toast('No shops yet. Register a business first.', true); return; }
    if (opts && opts.row) X = fromRow(opts.row); else { X = blank(opts && opts.sg ? opts.sg : S.shops[0].id); if (opts && opts.section) X.section = opts.section; if (opts && opts.cat) X.cat = opts.cat; }
    var dr = K.openDrawer(head(), form(), foot()); S.dr = dr; S.busy = 0; dr.classList.add('ak-item');
    refresh();
    dr.addEventListener('click', function (e) {
      var t = e.target, b;
      if ((b = t.closest('[data-cat]'))) { readForm(); X.cat = b.getAttribute('data-cat'); repaint('tiles'); }
      else if ((b = t.closest('[data-q]'))) { readForm(); X.quality = b.getAttribute('data-q'); repaint('tiles'); }
      else if ((b = t.closest('[data-sec-pick]'))) { readForm(); X.section = b.getAttribute('data-sec-pick'); $('it-sec').value = X.section; repaint('sug'); }
      else if (t.closest('#it-drop')) $('it-file').click();
      else if (t.closest('[data-gadd]')) $('it-gfile').click();
      else if ((b = t.closest('[data-gdel]'))) { X.gallery.splice(parseInt(b.getAttribute('data-gdel'), 10), 1); repaint('gal'); }
      else if (t.closest('[data-xadd]')) { readForm(); if (X.extras.length >= 12) { K.toast('Up to 12 extras per item.', true); return; } X.extras.push({ id: 0, name: '', price: '' }); repaint('x'); var l = dr.querySelectorAll('#it-xl [data-xn]'); if (l.length) l[l.length - 1].focus(); }
      else if ((b = t.closest('[data-xdel]'))) { readForm(); X.extras.splice(parseInt(b.getAttribute('data-xdel'), 10), 1); repaint('x'); }
      else if ((b = t.closest('[data-save]'))) save(b);
      else if (t.closest('[data-again]')) open({ sg: X.sg, section: X.section, cat: X.cat });
    });
    dr.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && e.target.id === 'it-drop') { e.preventDefault(); $('it-file').click(); } });
    dr.addEventListener('input', function (e) {
      var f = e.target.closest && e.target.closest('[data-f]'); if (f && f.classList.contains('bad')) mark(f.getAttribute('data-f'), '');
      readForm(); if (e.target.id === 'it-sec') repaint('sug'); else refresh();
      if (!dr.querySelector('.it-f.bad')) { var m = $('it-msg'); if (m && !S.busy) m.textContent = ''; }
    });
    dr.addEventListener('change', function (e) {
      if (e.target.id === 'it-file') { pickMain(e.target.files && e.target.files[0]); e.target.value = ''; }
      else if (e.target.id === 'it-gfile') { pickMore(e.target.files); e.target.value = ''; }
      else if (e.target.id === 'it-sg') { readForm(); repaint('sug'); }
    });
    var drop = $('it-drop');
    ['dragenter', 'dragover'].forEach(function (n) { dr.addEventListener(n, function (e) { if (e.target.closest && e.target.closest('#it-drop')) { e.preventDefault(); $('it-drop').classList.add('over'); } }); });
    ['dragleave', 'drop'].forEach(function (n) { dr.addEventListener(n, function (e) { if (e.target.closest && e.target.closest('#it-drop')) { e.preventDefault(); $('it-drop').classList.remove('over'); if (n === 'drop' && e.dataTransfer && e.dataTransfer.files[0]) pickMain(e.dataTransfer.files[0]); } }); });
    setTimeout(function () { var n = $('it-name'); if (n) n.focus(); }, 320);
  });
}

window.EtelItemStudio = { open: open };
/* The old Add / Edit item pop-ups open this panel instead. */
window.openAdminAddItem = function () { return open({}); };
window.openAdminEditItem = function (id) {
  var rows = (typeof itemsData !== 'undefined' && itemsData) ? itemsData : [];
  var r = null; rows.forEach(function (x) { if (String(x.id) === String(id)) r = x; });
  if (!r) { K.toast('Could not find that item. Refresh the list and try again.', true); return; }
  return open({ row: r });
};
})();

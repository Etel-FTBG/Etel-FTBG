/* Etel Code widget - mounts into <div id="etel-code-box" data-api=".../etelid/v1/code/"> */
(function () {
  if (window.__etelCodeLoaded) { return; }
  window.__etelCodeLoaded = true;
  var API = window.ETEL_CODE_API || (document.getElementById('etel-code-box') || {getAttribute:function(){return '';}}).getAttribute('data-api');
  if (!API) { return; }
  var $ = function (i) { return document.getElementById(i); };
  var timer = null, okCode = false;

  var tokenKeys = ['etelid_token', 'etelid_session', 'token'];
  function token() {
    for (var i = 0; i < tokenKeys.length; i++) {
      try { var v = localStorage.getItem(tokenKeys[i]) || sessionStorage.getItem(tokenKeys[i]); if (v) { return v; } } catch (e) {}
    }
    return '';
  }
  function call(path, method, data) {
    var h = { 'Content-Type': 'application/json' };
    var t = token(); if (t) { h['X-Etelid-Token'] = t; }
    var o = { method: method, headers: h, credentials: 'same-origin' };
    var url = API + path;
    if (method === 'POST') { o.body = JSON.stringify(data || {}); }
    else if (data) { url += '?' + Object.keys(data).map(function (k) { return k + '=' + encodeURIComponent(data[k]); }).join('&'); }
    return fetch(url, o).then(function (r) { return r.json(); });
  }
  var css = '#etel-code-box{max-width:420px;padding:22px;border-radius:14px;border:1px solid rgba(212,175,55,.35);' +
    'background:linear-gradient(165deg,#130d05,#0a0703);color:#F2CC6B;font-family:inherit;position:relative}' +
    '#etel-code-box:before{content:"";position:absolute;left:0;right:0;top:0;height:3px;background:linear-gradient(135deg,#C8960C,#D4AF37)}' +
    '#etel-code-box h3{margin:0 0 6px;font-size:18px;color:#D4AF37}#etel-code-box p{margin:4px 0 12px;font-size:13px;opacity:.85}' +
    '#etel-code-box input{width:100%;box-sizing:border-box;padding:12px;margin:6px 0;border-radius:8px;border:1px solid #5A3A08;' +
    'background:#0d0d0d;color:#F2CC6B;font-size:18px;letter-spacing:2px;text-transform:uppercase}' +
    '#etel-code-box input[type=password]{letter-spacing:2px;text-transform:none;font-size:16px}' +
    '#etel-code-box button{width:100%;padding:12px;margin-top:8px;border:0;border-radius:8px;cursor:pointer;font-weight:700;' +
    'background:linear-gradient(135deg,#C8960C,#D4AF37);color:#0d0d0d}#etel-code-box button:disabled{opacity:.5}' +
    '#etel-code-box .ec-msg{min-height:20px;font-size:13px;margin:4px 0}.ec-ok{color:#6fd98a}.ec-bad{color:#ff8a7a}' +
    '#etel-code-box .ec-cur{font-size:26px;font-weight:800;letter-spacing:3px;margin:6px 0}' +
    '#etel-code-box .ec-sug span{display:inline-block;margin:4px 6px 0 0;padding:4px 10px;border:1px solid #9A6818;border-radius:20px;cursor:pointer;font-size:13px}';
  css += '#ec-ov{position:fixed;left:0;top:0;right:0;bottom:0;z-index:999999;background:rgba(0,0,0,.75);display:flex;align-items:center;justify-content:center;padding:16px}' +
    '#ec-ov #etel-code-box{width:100%;max-height:90vh;overflow:auto}#ec-x{position:absolute;right:12px;top:10px;background:none!important;border:0;color:#D4AF37!important;font-size:22px;width:auto!important;margin:0!important;padding:0 6px!important}';
  var st = document.createElement('style'); st.appendChild(document.createTextNode(css)); document.head.appendChild(st);

  function html() { return '<button id="ec-x" type="button" style="display:none">&times;</button>' +
    FORM; }
  var FORM = '<h3>Your Etel Code</h3><div class="ec-cur" id="ec-cur">...</div>' +
    '<p id="ec-hint">Choose a short code that starts with E (example: E1616). Use it instead of your phone or long ID. To save it, type your EtelID password.</p>' +
    '<input id="ec-in" maxlength="12" placeholder="E1616" autocomplete="off">' +
    '<div class="ec-msg" id="ec-msg"></div><div class="ec-sug" id="ec-sug"></div>' +
    '<input id="ec-pin" type="password" placeholder="Your EtelID password" autocomplete="current-password">' +
    '<button id="ec-go" disabled>Save my code</button>';
  function msg(t, good) { var m = $('ec-msg'); m.textContent = t || ''; m.className = 'ec-msg ' + (good ? 'ec-ok' : 'ec-bad'); }
  function sugg(list) {
    var s = $('ec-sug'); s.innerHTML = '';
    (list || []).forEach(function (c) { var e = document.createElement('span'); e.textContent = c;
      e.onclick = function () { $('ec-in').value = c; check(); }; s.appendChild(e); });
  }
  function norm() { var v = $('ec-in').value.toUpperCase().replace(/[^A-Z0-9]/g, ''); $('ec-in').value = v; return v; }
  function check() {
    var v = norm(); okCode = false; $('ec-go').disabled = true; sugg([]);
    if (v.length < 4) { msg(''); return; }
    if (v.charAt(0) !== 'E') { msg('Code must start with E.'); return; }
    call('check', 'GET', { code: v }).then(function (r) {
      if (norm() !== v) { return; }
      okCode = !!r.available; msg(r.available ? v + ' is available' : r.message, r.available);
      sugg(r.suggestions); $('ec-go').disabled = !okCode;
    }).catch(function () { msg('Connection problem. Try again.'); });
  }
  function me() {
    call('me', 'GET').then(function (r) {
      if (r && r.success) {
        $('ec-cur').textContent = r.code || 'Not set yet';
        if (r.can_change_after) { $('ec-hint').textContent = 'You can change your code again on ' + r.can_change_after + '.'; }
      } else { $('ec-cur').textContent = 'Please sign in'; }
    }).catch(function () { $('ec-cur').textContent = '-'; });
  }
  function wire() {
  $('ec-in').addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(check, 350); });
  $('ec-go').addEventListener('click', function () {
    var b = $('ec-go'); b.disabled = true;
    call('claim', 'POST', { code: norm(), pin: $('ec-pin').value }).then(function (r) {
      msg(r.message, r.success); sugg(r.suggestions);
      if (r.success) { $('ec-pin').value = ''; $('ec-in').value = ''; me(); } else { b.disabled = !okCode; }
    }).catch(function () { msg('Connection problem. Try again.'); b.disabled = false; });
  });
  me();
  }

  function mountInline() {
    var box = document.getElementById('etel-code-box');
    if (!box) { return; }
    box.innerHTML = html(); wire();
  }

  function openModal() {
    if ($('ec-ov')) { return; }
    var ov = document.createElement('div'); ov.id = 'ec-ov';
    var box = document.createElement('div'); box.id = 'etel-code-box'; box.innerHTML = html();
    ov.appendChild(box); document.body.appendChild(ov);
    var x = $('ec-x'); x.style.display = 'block';
    function close() { if (ov.parentNode) { ov.parentNode.removeChild(ov); } }
    x.onclick = close; ov.addEventListener('click', function (e) { if (e.target === ov) { close(); } });
    wire(); setTimeout(function () { $('ec-in').focus(); }, 50);
  }

  // Add an "Etel Code" tile to the portal grid, cloned from the "Change Password" tile.
  function findTile(label) {
    var all = document.querySelectorAll('a,button,div,li,span');
    var hit = null;
    for (var i = 0; i < all.length; i++) {
      var el = all[i];
      if (el.children.length < 4 && (el.textContent || '').replace(/\s+/g, ' ').trim() === label) { hit = el; }
    }
    if (!hit) { return null; }
    // climb to the tile: the ancestor whose parent holds several same-tag siblings
    var t = hit;
    while (t.parentNode && t.parentNode !== document.body) {
      var sib = 0, kids = t.parentNode.children;
      for (var j = 0; j < kids.length; j++) { if (kids[j].tagName === t.tagName) { sib++; } }
      if (sib >= 3) { return t; }
      t = t.parentNode;
    }
    return null;
  }
  function addTile() {
    if (document.getElementById('ec-tile')) { return true; }
    // Preferred spot: next to "Customer App" (the free slot in the apps row); fallback: after "Change Password".
    var src = findTile('Customer App') || findTile('Change Password'); if (!src) { return false; }
    var tile = src.cloneNode(true);
    tile.id = 'ec-tile';
    ['onclick', 'target', 'href', 'data-action', 'data-open', 'data-modal', 'data-target', 'data-tab'].forEach(function (a) { tile.removeAttribute(a); });
    if (tile.tagName === 'A') { tile.setAttribute('href', '#'); }
    // label
    var w = document.createTreeWalker(tile, NodeFilter.SHOW_TEXT, null, false), n, done = false;
    while ((n = w.nextNode())) { if (n.nodeValue.trim() === 'Customer App' || n.nodeValue.trim() === 'Change Password') { n.nodeValue = 'Etel Code'; done = true; } }
    // icon: replace emoji/img/svg with a gold "E"
    var ic = tile.querySelector('img,svg') || null;
    var badge = document.createElement('span');
    badge.textContent = 'E'; badge.setAttribute('style', 'display:inline-flex;align-items:center;justify-content:center;width:100%;height:100%;font-weight:800;font-size:26px;color:#D4AF37');
    if (ic) { ic.parentNode.replaceChild(badge, ic); }
    else {
      var cand = tile.querySelectorAll('*');
      for (var k = 0; k < cand.length; k++) {
        if (cand[k].children.length === 0 && /[^\x00-\x7F]/.test(cand[k].textContent || '')) { cand[k].textContent = 'E'; cand[k].style.color = '#D4AF37'; cand[k].style.fontWeight = '800'; break; }
      }
    }
    tile.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); openModal(); }, true);
    src.parentNode.insertBefore(tile, src.nextSibling);
    return true;
  }
  function boot() {
    mountInline();
    if (addTile()) { return; }
    var tries = 0, iv = setInterval(function () { tries++; if (addTile() || tries > 40) { clearInterval(iv); } }, 500);
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();

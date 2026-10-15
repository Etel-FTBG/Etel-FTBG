/* ETEL Admin - Payments & banks: connect a payment company or bank by pasting its keys once, no server files to edit.
   Keys are stored encrypted on the server and are never shown again (only "Saved"). Only the main admin can open this page.
   Data: api actions admin_integrations_get, admin_integrations_save, admin_integrations_test, admin_integrations_add, admin_integrations_remove (api/lib/integrations.php).
   Standalone on purpose: it adds its own menu item and page, so no other admin file changes. */
(function () {
'use strict';
var DATA = [];
var BTN = 'padding:7px 14px;border-radius:8px;border:0;background:#D4AF37;color:#1A1200;font-weight:700;font-size:12.5px;cursor:pointer';
var BTN2 = 'padding:7px 12px;border-radius:8px;border:1px solid rgba(128,128,128,.4);background:transparent;color:inherit;font-size:12.5px;cursor:pointer';
var LINK = 'background:none;border:0;color:#c0392b;font-size:12px;cursor:pointer;text-decoration:underline;padding:0';
var FIELD = 'padding:7px 8px;border-radius:8px;border:1px solid rgba(128,128,128,.4);background:transparent;color:inherit;font-size:13px;width:100%;box-sizing:border-box';
var STATE = { ready: ['All keys set', '#1e8e4e'], partial: ['Some keys missing', '#b9770e'], empty: ['Not connected', '#7f8c8d'] };

function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
function api(action, body) {
  var token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
  var url = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
  return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token }, body: JSON.stringify(Object.assign({ action: action }, body || {})) }).then(function (r) { return r.json(); });
}
function panel() { return document.getElementById('panel-integrations'); }
function note(c, msg, bad) { var n = c.querySelector('[data-note]'); if (n) { n.textContent = msg; n.style.color = bad ? '#e74c3c' : '#2ecc71'; } }
function failText(res, dflt) { return (res && (res.message || res.error)) || dflt; }

function fieldRow(p, f) {
  var h = '<label style="font-size:12px;display:block">' + esc(f.label);
  if (f.source === 'admin') h += ' <span style="color:#1e8e4e;font-weight:700">✓ saved</span>';
  else if (f.source === 'env') h += ' <span style="opacity:.7">· using the value from the server file</span>';
  else if (f.unreadable) h += ' <span style="color:#c0392b;font-weight:700">· re-enter (the old one cannot be read)</span>';
  h += '<input name="' + esc(f.key) + '" type="' + (f.secret ? 'password' : 'text') + '" autocomplete="off" spellcheck="false" ' +
    'placeholder="' + (f.secret ? (f.source ? 'Type a new key to replace it' : 'Paste the key') : (f.value ? '' : 'Type it here')) + '" value="' + (f.secret ? '' : esc(f.value)) + '" style="' + FIELD + ';margin-top:3px"></label>';
  if (f.source === 'admin') h += '<button type="button" data-clear="' + esc(f.key) + '" style="' + LINK + '">Remove the saved ' + esc(f.label.toLowerCase()) + '</button>';
  return h;
}

function card(p) {
  var st = STATE[p.state] || STATE.empty;
  var h = '<form data-provider="' + esc(p.provider) + '" autocomplete="off" style="border:1px solid rgba(128,128,128,.3);border-radius:12px;padding:12px;margin-bottom:12px">' +
    '<div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center"><div style="font-size:15px;font-weight:800">' + esc(p.icon) + ' ' + esc(p.name) + '</div>' +
    '<span style="font-size:12px;font-weight:700;color:' + st[1] + '">' + st[0] + '</span></div>' +
    '<div style="font-size:12px;opacity:.75;margin:4px 0 4px">' + esc(p.help) + '</div>' +
    '<div style="font-size:12px;margin-bottom:8px;font-weight:600;color:' + (p.wired ? '#1e8e4e' : '#b9770e') + '">' +
    (p.wired ? 'Checkout is connected: customers can pay with this once the keys are set.' : 'Keys are stored now. Checkout for this company is connected when the deal is signed.') + '</div>' +
    '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px">';
  p.fields.forEach(function (f) { h += '<div>' + fieldRow(p, f) + '</div>'; });
  h += '</div><div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:10px">' +
    '<button type="submit" style="' + BTN + '">Save keys</button>' +
    (p.testable ? '<button type="button" data-test style="' + BTN2 + '">Test connection</button>' : '') +
    (p.custom ? '<button type="button" data-remove-co style="' + LINK + '">Remove this company</button>' : '') + '</div>' +
    '<div data-note role="status" style="font-size:12.5px;min-height:18px;margin-top:6px"></div></form>';
  return h;
}

function render() {
  var el = panel(); if (!el) return;
  var h = '<div style="margin:0 0 10px;padding:6px 4px 0;font-size:12.5px;opacity:.8">Connect a payment company or bank here when the deal is signed: paste its keys once and press Save. ' +
    'Keys are stored encrypted and are never shown again. Leave a box empty to keep what is saved. Every change is logged.</div>';
  h += '<div style="margin-bottom:12px"><button type="button" data-add style="' + BTN + '">＋ Add a payment company or bank</button><div data-add-host></div></div>';
  DATA.forEach(function (p) { h += card(p); });
  el.innerHTML = h;
  var addBtn = el.querySelector('[data-add]'); if (addBtn) addBtn.onclick = function () { openAdd(el); };
  Array.prototype.forEach.call(el.querySelectorAll('[data-remove-co]'), function (b) {
    b.onclick = function () {
      var f = b.closest('form');
      if (!window.confirm('Remove this company and its saved keys?')) return;
      api('admin_integrations_remove', { provider: f.getAttribute('data-provider') }).then(function (res) {
        if (res && res.success !== false && res.data) { DATA = res.data.providers || DATA; render(); } else note(f, failText(res, 'Could not remove.'), true);
      }).catch(function () { note(f, 'Could not reach the server. Try again.', true); });
    };
  });
  Array.prototype.forEach.call(el.querySelectorAll('form[data-provider]'), function (f) {
    var id = f.getAttribute('data-provider');
    f.onsubmit = function (e) {
      e.preventDefault();
      var values = {}; Array.prototype.forEach.call(f.querySelectorAll('input'), function (i) { if (i.value.trim() !== '') values[i.name] = i.value.trim(); });
      send(f, id, values);
    };
    Array.prototype.forEach.call(f.querySelectorAll('[data-clear]'), function (b) {
      b.onclick = function () {
        if (!window.confirm('Remove this saved key? Payments that need it will stop until you add it again.')) return;
        var v = {}; v[b.getAttribute('data-clear')] = '__clear__'; send(f, id, v);
      };
    });
    var t = f.querySelector('[data-test]');
    if (t) t.onclick = function () {
      t.disabled = true; note(f, 'Testing…');
      api('admin_integrations_test', { provider: id }).then(function (res) {
        t.disabled = false;
        var d = res && res.data;
        if (d) note(f, d.message, !d.ok); else note(f, failText(res, 'Could not test.'), true);
      }).catch(function () { t.disabled = false; note(f, 'Could not reach the server. Try again.', true); });
    };
  });
}

function openAdd(el) {
  var host = el.querySelector('[data-add-host]'); if (!host) return;
  if (host.firstChild) { host.innerHTML = ''; return; }
  host.innerHTML = '<form data-new autocomplete="off" style="border:1px solid #D4AF37;border-radius:12px;padding:12px;margin-top:10px">' +
    '<div style="font-weight:800;margin-bottom:4px">Add a payment company or bank</div>' +
    '<div style="font-size:12px;opacity:.75;margin-bottom:8px">For a company that is not in the list. Add it, then paste its keys on its card. Its checkout is connected when the deal is signed.</div>' +
    '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">' +
    '<label style="font-size:12px;flex:2 1 220px">Name<input name="name" maxlength="60" placeholder="Awash Bank" style="' + FIELD + '"></label>' +
    '<label style="font-size:12px;flex:1 1 160px">Type<select name="kind" style="' + FIELD + '"><option value="bank">Bank</option><option value="payment">Payment company / wallet</option></select></label>' +
    '<button type="submit" style="' + BTN + '">Add</button><button type="button" data-cancel-add style="' + BTN2 + '">Cancel</button></div>' +
    '<div data-note role="status" style="font-size:12.5px;min-height:18px;margin-top:6px"></div></form>';
  var f = host.querySelector('form');
  f.querySelector('[data-cancel-add]').onclick = function () { host.innerHTML = ''; };
  f.onsubmit = function (e) {
    e.preventDefault();
    var btn = f.querySelector('button[type=submit]'); btn.disabled = true; note(f, 'Adding…');
    api('admin_integrations_add', { name: f.elements.name.value, kind: f.elements.kind.value }).then(function (res) {
      btn.disabled = false;
      if (res && res.success !== false && res.data) {
        DATA = res.data.providers || DATA; render();
        var c = panel() && panel().querySelector('form[data-provider="' + res.data.provider + '"]'); if (c) { note(c, res.data.message, false); c.scrollIntoView({ block: 'center' }); }
      } else note(f, failText(res, 'Could not add.'), true);
    }).catch(function () { btn.disabled = false; note(f, 'Could not reach the server. Try again.', true); });
  };
}

function send(f, id, values) {
  var btn = f.querySelector('button[type=submit]'); btn.disabled = true; note(f, 'Saving…');
  api('admin_integrations_save', { provider: id, values: values }).then(function (res) {
    btn.disabled = false;
    if (res && res.success !== false && res.data) {
      DATA = res.data.providers || DATA; render();
      var f2 = panel() && panel().querySelector('form[data-provider="' + id + '"]'); if (f2) note(f2, res.data.message || 'Saved.', false);
    } else note(f, failText(res, 'Could not save.'), true);
  }).catch(function () { btn.disabled = false; note(f, 'Could not reach the server. Try again.', true); });
}

function load() {
  var el = panel(); if (!el) return;
  el.innerHTML = '<div style="padding:14px;opacity:.7">Loading…</div>';
  api('admin_integrations_get').then(function (res) {
    if (!res || res.success === false || !res.data) {
      el.innerHTML = '<div style="padding:14px;color:#e74c3c">' + esc(failText(res, 'Could not load.')) + '</div>'; return;
    }
    DATA = res.data.providers || []; render();
  }).catch(function (e) { el.innerHTML = '<div style="padding:14px;color:#e74c3c">Payments &amp; banks: ' + esc(e.message) + '</div>'; });
}

function install() {
  if (document.querySelector('[data-page="integrations"]')) return;
  var after = document.querySelector('.nav-item[data-page="money"]') || document.querySelector('.nav-item[data-page="packages"]') || document.querySelector('.nav-item[data-page="dashboard"]');
  if (after) {
    var nav = document.createElement('div');
    nav.className = 'nav-item'; nav.setAttribute('data-page', 'integrations');
    nav.setAttribute('onclick', "switchPage('integrations',this)");
    nav.innerHTML = '<span class="nav-icon">🔌</span><span>Payments &amp; banks</span>';
    after.parentNode.insertBefore(nav, after.nextSibling);
  }
  var host = document.getElementById('panel-orders') || document.getElementById('panel-packages') || document.getElementById('panel-support');
  if (host && !panel()) {
    var p = document.createElement('div'); p.className = 'panel'; p.id = 'panel-integrations'; p.style.display = 'none';
    host.parentNode.appendChild(p);
  }
  var orig = window.switchPage;
  if (typeof orig === 'function') {
    window.switchPage = function (page) {
      var r = orig.apply(this, arguments);
      if (page === 'integrations') {
        var t = document.getElementById('page-title'); if (t) t.textContent = 'Payments & banks';
        var ab = document.getElementById('action-buttons'); if (ab) ab.innerHTML = '';
        load();
      }
      return r;
    };
  }
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();

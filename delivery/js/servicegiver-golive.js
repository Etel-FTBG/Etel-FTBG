/* Service giver app - "Finish setting up" card on the dashboard, and the one-time "You're live" welcome.
   A business waiting for ETEL's verification can sign in (setup mode): this card shows the same go-live checklist admin sees
   (sg_golive) with a button on each missing point that jumps to the right screen. Once ETEL switches the business on, the card is
   replaced by a welcome message the owner dismisses once (remembered on this device).
   Additive: no inline handlers; every server value is escaped. */
(function () {
'use strict';
var card = null;
function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
function seenKey(id) { return 'etel_live_seen_' + id; }
function seen(id) { try { return localStorage.getItem(seenKey(id)) === '1'; } catch (e) { return false; } }
function markSeen(id) { try { localStorage.setItem(seenKey(id), '1'); } catch (e) { /* private mode: it shows again next time */ } }

/* Which screen fixes each point */
var GO = { logo: 'settings', cover: 'settings', hours: 'settings', location: 'settings', payout: 'settings', documents: 'settings', menu: 'services' };
var WHERE = { settings: 'Open Settings', services: 'Open Menu items' };

function ensureCard() {
  if (card) return card;
  var dash = document.getElementById('page-dashboard');
  if (!dash) return null;
  card = document.createElement('div');
  card.id = 'sg-golive-card';
  card.style.cssText = 'display:none;margin:0 0 18px;padding:18px;border-radius:16px;border:1px solid rgba(212,175,55,.35);background:linear-gradient(135deg,rgba(212,175,55,.12),rgba(212,175,55,.03))';
  var sub = dash.querySelector('.page-subtitle');
  if (sub && sub.nextSibling) dash.insertBefore(card, sub.nextSibling); else dash.appendChild(card);
  return card;
}

function paint(d) {
  var el = ensureCard(); if (!el) return;
  if (d.status === 'verified') {
    if (seen(d.sg_id)) { el.style.display = 'none'; return; }
    el.style.display = 'block';
    el.innerHTML = '<div style="font-size:22px;font-weight:800;margin-bottom:6px">🎉 Welcome to ETEL — you are live!</div>' +
      '<p style="margin:0 0 8px;font-size:14px;line-height:1.5"><b>' + esc(d.business_name) + '</b> is now visible to customers and can take orders.</p>' +
      '<ul style="margin:0 0 12px 18px;padding:0;font-size:13.5px;line-height:1.6"><li>Keep your shop switched to <b>Open</b> while you can take orders.</li><li>Accept new orders quickly: customers and couriers are waiting.</li><li>Mark an item hidden when it runs out.</li></ul>' +
      '<button type="button" class="btn btn-primary" data-golive-dismiss="' + esc(d.sg_id) + '">Got it</button>';
    return;
  }
  var rd = d.readiness || { items: [], done: 0, total: 0 }, pct = rd.total ? Math.round(rd.done / rd.total * 100) : 0;
  el.style.display = 'block';
  el.innerHTML = '<div style="font-size:18px;font-weight:800;margin-bottom:4px">Finish setting up · ' + rd.done + ' of ' + rd.total + '</div>' +
    '<p style="margin:0 0 10px;font-size:13px;opacity:.85">Your business is not visible to customers yet. When every point below is done, ETEL checks it and switches it on.</p>' +
    '<div style="height:8px;border-radius:6px;background:rgba(128,128,128,.25);margin-bottom:8px"><div style="height:8px;border-radius:6px;width:' + pct + '%;background:' + (pct >= 100 ? '#2ecc71' : '#D4AF37') + '"></div></div>' +
    rd.items.map(function (it) {
      var to = !it.ok && GO[it.key];
      return '<div style="display:flex;gap:10px;align-items:flex-start;padding:7px 0;border-top:1px solid rgba(128,128,128,.2)"><span aria-hidden="true">' + (it.ok ? '✅' : '⬜') + '</span>' +
        '<div style="flex:1;min-width:0"><b style="font-size:13.5px">' + esc(it.label) + '</b>' + (it.ok ? '' : '<div style="font-size:12px;opacity:.75">' + esc(it.hint) + '</div>') + '</div>' +
        (to ? '<button type="button" class="btn btn-secondary btn-small" data-golive-go="' + to + '">' + WHERE[to] + '</button>' : '') + '</div>';
    }).join('');
}

function load() {
  if (typeof apiCall !== 'function' || !ensureCard()) return;
  apiCall('sg_golive').then(function (d) { if (d) paint(d); });
}

function init() {
  document.addEventListener('click', function (e) {
    var t = e.target; if (!t || !t.closest) return;
    var go = t.closest('[data-golive-go]'), dis = t.closest('[data-golive-dismiss]');
    if (go) {
      var m = document.querySelector('.menu-item[data-page="' + go.getAttribute('data-golive-go') + '"]');
      if (m) m.click();
    } else if (dis) {
      markSeen(dis.getAttribute('data-golive-dismiss'));
      if (card) card.style.display = 'none';
    } else if (t.closest('.menu-item[data-page="dashboard"]')) setTimeout(load, 50);
  });
  setTimeout(load, 1500);
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

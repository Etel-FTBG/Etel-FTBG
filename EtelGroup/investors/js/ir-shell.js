/* Investor Relations admin - shell: light/dark theme, sidebar pages, dashboard widgets, table filters.
   Theme key 'theme' + body.dark-theme match the Delivery admin, so one choice follows you across both. */
(function () {
'use strict';
var $ = function (id) { return document.getElementById(id); };
var TITLES = {
  overview: ['Dashboard', 'Your investor desk at a glance'], deals: ['Deal sheets', 'Signed online by investors, reviewed by you'],
  investors: ['Investors', 'Everyone holding Profit Shares'], distributions: ['Distributions', 'Preview and lock each period’s payout'],
  costs: ['Costs & tranches', 'What reduces net profit, and how shares are released'], planner: ['Growth planner', 'What the profit share could look like at scale']
};

/* ---------- theme ---------- */
function isDark() { return document.body.classList.contains('dark-theme'); }
function readTheme() {
  var t = null; try { t = localStorage.getItem('theme'); } catch (e) { /* none */ }
  if (t !== 'dark' && t !== 'light') t = (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
  return t;
}
function paintTheme(t) {
  document.body.classList.toggle('dark-theme', t === 'dark');
  document.documentElement.setAttribute('data-theme', t);
  Array.prototype.forEach.call(document.querySelectorAll('[data-theme-toggle]'), function (b) {
    b.textContent = t === 'dark' ? '☀️' : '🌙';
    b.title = t === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
    b.setAttribute('aria-label', b.title);
  });
  document.dispatchEvent(new CustomEvent('ir:theme', { detail: t }));
}
paintTheme(readTheme());
document.addEventListener('click', function (e) {
  var b = e.target.closest && e.target.closest('[data-theme-toggle]');
  if (!b) return;
  var t = isDark() ? 'light' : 'dark';
  try { localStorage.setItem('theme', t); } catch (x) { /* not remembered */ }
  paintTheme(t);
});
window.addEventListener('storage', function (ev) { if (ev.key === 'theme') paintTheme(readTheme()); });

/* ---------- pages ---------- */
function showPage(name) {
  if (!TITLES[name]) name = 'overview';
  Array.prototype.forEach.call(document.querySelectorAll('.ir-page'), function (p) { p.classList.toggle('on', p.getAttribute('data-page') === name); });
  Array.prototype.forEach.call(document.querySelectorAll('#irx-nav a[data-page]'), function (a) { a.classList.toggle('on', a.getAttribute('data-page') === name); });
  $('irx-title').textContent = TITLES[name][0]; $('irx-sub').textContent = TITLES[name][1];
  document.body.classList.remove('nav-open');
  try { history.replaceState(null, '', '#' + name); } catch (e) { /* file:// */ }
  window.scrollTo(0, 0);
}
window.irShowPage = showPage;
document.addEventListener('click', function (e) {
  var a = e.target.closest && e.target.closest('#irx-nav a[data-page]');
  if (a) { showPage(a.getAttribute('data-page')); return; }
  var g = e.target.closest && e.target.closest('[data-go]');
  if (g) { showPage(g.getAttribute('data-go')); return; }
  if (e.target.closest && e.target.closest('#nav-setup')) { document.body.classList.remove('nav-open'); if (typeof irBackToWizard === 'function') irBackToWizard(); return; }
  if (e.target.closest && e.target.closest('#irx-burger')) { document.body.classList.toggle('nav-open'); return; }
  if (e.target.id === 'irx-scrim') document.body.classList.remove('nav-open');
  var seg = e.target.closest && e.target.closest('#deal-filter button');
  if (seg) {
    Array.prototype.forEach.call(document.querySelectorAll('#deal-filter button'), function (b) { b.classList.toggle('on', b === seg); });
    window.irApplyDealFilter();
  }
  if (e.target.id === 'hero-copy') {
    var u = new URL('deal.html', location.href).href;
    if (navigator.clipboard) navigator.clipboard.writeText(u).then(function () { e.target.textContent = '✓ Copied'; setTimeout(function () { e.target.innerHTML = '&#128279; Copy deal link'; }, 1600); });
    else prompt('Copy this link', u);
  }
});
window.addEventListener('hashchange', function () { var h = location.hash.slice(1); if (TITLES[h]) showPage(h); });
document.addEventListener('DOMContentLoaded', function () { var h = location.hash.slice(1); if (TITLES[h]) showPage(h); });

/* ---------- table search and deal filter ---------- */
function rowText(tr) { return (tr.textContent || '').toLowerCase(); }
window.irApplyDealFilter = function () {
  var f = (document.querySelector('#deal-filter button.on') || {}).dataset; f = f ? f.f : 'all';
  var q = (document.querySelector('input[data-filter="#ir-deals-table"]') || {}).value || ''; q = q.trim().toLowerCase();
  Array.prototype.forEach.call(document.querySelectorAll('#ir-deals-table tbody tr[data-status]'), function (tr) {
    tr.style.display = ((f === 'all' || tr.dataset.status === f) && (!q || rowText(tr).indexOf(q) >= 0)) ? '' : 'none';
  });
};
document.addEventListener('input', function (e) {
  var i = e.target; if (!i.matches || !i.matches('input[data-filter]')) return;
  if (i.getAttribute('data-filter') === '#ir-deals-table') { window.irApplyDealFilter(); return; }
  var q = i.value.trim().toLowerCase();
  Array.prototype.forEach.call(document.querySelectorAll(i.getAttribute('data-filter') + ' tbody tr'), function (tr) { tr.style.display = !q || rowText(tr).indexOf(q) >= 0 ? '' : 'none'; });
});

/* ---------- dashboard widgets ---------- */
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
function money(n) { return Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 0 }); }

function renderPool(d) {
  var total = (d.sold_shares || 0) + (d.unsold_shares || 0) || 2000, sold = d.sold_shares || 0;
  var unlocked = (d.tranches || []).reduce(function (s, t) { return s + (Number(t.is_unlocked) ? Number(t.shares_total) : 0); }, 0);
  var open = Math.max(0, Math.min(unlocked - sold, total - sold)), locked = Math.max(0, total - sold - open);
  var R = 62, C = 2 * Math.PI * R, segs = [[sold, '#d4af37', 'Sold'], [open, '#1f55b0', 'Open for sale now'], [locked, 'var(--line2)', 'Locked / not released']], off = 0, arcs = '';
  segs.forEach(function (s) {
    var len = total ? s[0] / total * C : 0;
    arcs += '<circle r="' + R + '" cx="80" cy="80" fill="none" stroke="' + s[1] + '" stroke-width="20" stroke-dasharray="' + len + ' ' + (C - len) + '" stroke-dashoffset="' + (-off) + '" transform="rotate(-90 80 80)"></circle>'; off += len;
  });
  $('irx-pool').innerHTML = '<svg class="irx-donut" viewBox="0 0 160 160" role="img" aria-label="Profit Share pool">' + arcs +
    '<text x="80" y="76" text-anchor="middle" font-size="26" font-weight="700" fill="var(--ink)" font-family="Poppins,sans-serif">' + (total ? Math.round(sold / total * 100) : 0) + '%</text><text x="80" y="96" text-anchor="middle" font-size="10" fill="var(--mute)">sold</text></svg>' +
    '<div class="irx-legend">' + segs.map(function (s) { return '<div><i style="background:' + s[1] + '"></i>' + esc(s[2]) + '<b>' + money(s[0]) + '</b></div>'; }).join('') +
    '<div style="border-top:1px solid var(--line);padding-top:8px"><i style="background:transparent"></i>Total in the pool<b>' + money(total) + '</b></div></div>';
}

function renderAttention(d) {
  var items = [], n = window.IR_DEALS_NEW || 0, hasT2 = (d.tranches || []).some(function (t) { return Number(t.tranche_number) === 2 && !Number(t.is_unlocked); });
  if (n) items.push(['hot', '📝', n + ' new deal sheet' + (n > 1 ? 's' : '') + ' to review', 'Accept or reject, then enroll the investor.', 'deals', 'Open']);
  if (d.delivery && !d.delivery.connected) items.push(['hot', '🔌', 'Delivery & Ride is not connected', 'Real commission needs the read-only connection.', null, 'Finish setup']);
  if (d.delivery && d.delivery.error) items.push(['hot', '⚠️', 'Could not read live ride data', String(d.delivery.error).slice(0, 90), null, '']);
  if (hasT2) items.push(['', '🔒', 'Tranche 2 is locked', 'Unlock it when the trip target is reached.', 'costs', 'Open']);
  if (!(d.monthly_cost_total > 0)) items.push(['hot', '💸', 'No monthly costs entered', 'Net profit needs your cost categories.', 'costs', 'Add']);
  $('irx-attn').innerHTML = items.length ? items.map(function (it) {
    return '<div class="it ' + it[0] + '"><i>' + it[1] + '</i><div><b>' + esc(it[2]) + '</b><span>' + esc(it[3]) + '</span></div>' +
      (it[5] ? '<button class="btn btn-outline btn-sm" ' + (it[4] ? 'data-go="' + it[4] + '"' : 'id="attn-setup"') + '>' + esc(it[5]) + '</button>' : '') + '</div>';
  }).join('') : '<div class="it"><i>✅</i><div><b>All clear</b><span>Nothing is waiting for you.</span></div></div>';
  var s = $('attn-setup'); if (s) s.onclick = function () { if (typeof irBackToWizard === 'function') irBackToWizard(); };
}

async function renderChart() {
  var res = await irApi('admin_list_distribution_periods'), el = $('irx-chart');
  if (!res.ok || !res.data || !res.data.length) { el.innerHTML = '<div class="irx-empty">No locked periods yet. Run and lock a distribution to see your first chart.</div>'; return; }
  var rows = res.data.slice(0, 8).reverse(), max = 1;
  rows.forEach(function (p) { max = Math.max(max, Number(p.net_profit) || 0, Number(p.total_investor_payout) || 0); });
  var W = 640, H = 190, pad = 26, bw = Math.min(26, (W - pad * 2) / rows.length / 2.6), step = (W - pad * 2) / rows.length, g = '';
  [0, .5, 1].forEach(function (f) { var y = H - 28 - f * (H - 52); g += '<line x1="' + pad + '" x2="' + (W - pad) + '" y1="' + y + '" y2="' + y + '" stroke="var(--line)" stroke-dasharray="3 4"></line><text x="2" y="' + (y + 3) + '" font-size="9" fill="var(--faint)">' + money(max * f) + '</text>'; });
  rows.forEach(function (p, i) {
    var x = pad + 22 + i * step, np = Math.max(0, Number(p.net_profit) || 0), ip = Math.max(0, Number(p.total_investor_payout) || 0);
    var h1 = np / max * (H - 52), h2 = ip / max * (H - 52);
    g += '<rect x="' + x + '" y="' + (H - 28 - h1) + '" width="' + bw + '" height="' + h1 + '" rx="5" fill="#d4af37"><title>Net profit ' + money(np) + '</title></rect>' +
      '<rect x="' + (x + bw + 3) + '" y="' + (H - 28 - h2) + '" width="' + bw + '" height="' + h2 + '" rx="5" fill="#1f55b0"><title>Investor payout ' + money(ip) + '</title></rect>' +
      '<text x="' + (x + bw) + '" y="' + (H - 12) + '" text-anchor="middle" font-size="9.5" fill="var(--mute)">' + esc(String(p.period_end).slice(2, 7)) + '</text>';
  });
  el.innerHTML = '<svg class="irx-bars" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Net profit and investor payouts by period">' + g + '</svg>' +
    '<div class="irx-legend" style="grid-auto-flow:column;justify-content:start;gap:18px"><div><i style="background:#d4af37"></i>Net profit</div><div><i style="background:#1f55b0"></i>Investor payout</div></div>';
}

document.addEventListener('ir:dashboard', function (e) { var d = e.detail; renderPool(d); renderAttention(d); renderChart(); });
document.addEventListener('ir:deals', function () { var d = window.IR_LAST_DASH; if (d) renderAttention(d); });
document.addEventListener('ir:dashboard', function (e) { window.IR_LAST_DASH = e.detail; });
})();

/* ETEL Admin - Finance & planning hub header.
   Adds a premium top to the hub that admin-finance-hub.js builds: a headline strip with the last 30 days of platform money
   (commission, costs, net profit) and the investor raise (committed, target, investors), a clearer tab bar with a one line
   explanation of the open tab, and a note that says plainly when a number is an assumption. The four screens inside
   (Equity, Platform economics, Payroll, Business plan) are untouched; their data and typed values are never reset here.
   Data: platform_economics_get_summary (platform_economics.view) and equity_get_dashboard (equity.view); a figure the
   signed-in person may not see is simply left out. Standalone: wraps loadFinanceHub() so admin-finance-hub.js is not edited.
   Every server value goes through AdminKit.esc. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K || typeof window.loadFinanceHub !== 'function') return;
var esc = K.esc;
var NOTES = {
  'equity': 'Who owns what: investors, the raise target, share price and profit sharing.',
  'platform-econ': 'Real commission from completed rides against the costs you enter. Revenue is live data; costs are only as good as your inputs.',
  'payroll': 'Plan salaries and what your team really costs each month.',
  'business-plan': 'The capital you need, built from your equity and cost numbers plus the hiring plan.',
  'reset': 'Clear the saved growth planner scenario back to its starting numbers.'
};
var S = { at: 0, busy: false, data: null };

function css() {
  if (document.getElementById('fin-pro-css')) return;
  var s = document.createElement('style'); s.id = 'fin-pro-css';
  s.textContent = [
    '#fin-subnav.ak{display:flex;gap:6px;flex-wrap:nowrap;overflow-x:auto;padding:4px;margin:0 0 6px;background:var(--soft);border:1.5px solid var(--line);border-radius:14px;scrollbar-width:thin}',
    '#fin-subnav.ak .eq-btn{flex:0 0 auto;border:0 !important;background:transparent !important;color:var(--mute) !important;box-shadow:none !important;font:inherit;font-size:13px;font-weight:700;padding:9px 14px;border-radius:10px;cursor:pointer;white-space:nowrap}',
    '#fin-subnav.ak .eq-btn:hover{color:var(--ink) !important}',
    '#fin-subnav.ak .eq-btn.primary{background:var(--card) !important;color:var(--ink) !important;box-shadow:0 2px 10px rgba(0,0,0,.14) !important}',
    '#fin-subnav.ak .eq-btn:focus-visible{outline:2px solid var(--gold);outline-offset:1px}',
    '.fin-note{font-size:12.5px;color:var(--mute);margin:2px 4px 16px}',
    '.ak-kpi.good b{color:var(--green)}.ak-kpi.bad b{color:var(--red)}'
  ].join('\n');
  document.head.appendChild(s);
}

function sub() { return typeof FIN_CURRENT_SUB === 'string' ? FIN_CURRENT_SUB : 'equity'; }

function headHtml() {
  var d = S.data || {}, p = d.pe, e = d.eq, tiles = [];
  if (p) {
    tiles.push({ key: 'c', icon: '💵', label: 'Commission · 30 days', value: K.money(p.commission_revenue) });
    tiles.push({ key: 'k', icon: '🧾', label: 'Costs · 30 days', value: K.money(p.total_costs) });
    tiles.push({ key: 'n', icon: Number(p.net_platform_profit) >= 0 ? '📈' : '📉', label: Number(p.net_platform_profit) >= 0 ? 'Net profit' : 'Net loss', value: K.money(p.net_platform_profit), cls: Number(p.net_platform_profit) >= 0 ? 'good' : 'bad' });
    tiles.push({ key: 'r', icon: '🛵', label: 'Completed rides', value: K.num(p.completed_rides) });
  }
  if (e) {
    var target = Number(e.settings && e.settings.target_amount) || 0, pct = target ? Math.min(100, Math.round((Number(e.total_committed) || 0) / target * 100)) : 0;
    tiles.push({ key: 'i', icon: '🤝', label: 'Raised of ' + K.money(target), value: pct + '%' });
    tiles.push({ key: 'v', icon: '👥', label: 'Investors', value: K.num(e.investor_count) });
  }
  var bigs = [];
  if (p) bigs.push(['Net profit · 30 days', K.money(p.net_platform_profit)]);
  if (e) bigs.push(['Committed by investors', K.money(e.total_committed)], ['Still to raise', K.money(e.remaining_to_raise)]);
  var h = '<div class="ak" id="fin-pro-head">' + K.hero('Finance & planning', 'The money picture in one place: what the platform earns, what it costs, who has invested, and the plan to grow.', bigs);
  if (tiles.length) {
    h += '<div class="ak-kpis">' + tiles.map(function (k) {
      return '<div class="ak-kpi static ' + (k.cls || '') + '"><i>' + esc(k.icon) + '</i><b>' + esc(k.value) + '</b><span>' + esc(k.label) + '</span></div>';
    }).join('') + '</div>';
  } else if (S.data) {
    h += '<div class="fin-note">Headline figures are shown to people with the platform economics or equity permission.</div>';
  }
  return h + '</div>';
}

function paintHead() {
  var section = document.querySelector('#panel-finance .data-section');
  if (!section) return;
  K.css(); css();
  var old = document.getElementById('fin-pro-head');
  var fresh = document.createElement('div'); fresh.innerHTML = headHtml();
  if (old) old.parentNode.replaceChild(fresh.firstChild, old);
  else section.insertBefore(fresh.firstChild, section.firstChild);
  // the plain title and sentence the hub wrote are replaced by the header above
  Array.prototype.forEach.call(section.children, function (c) {
    if ((c.classList && c.classList.contains('section-title')) || (c.tagName === 'P' && !c.classList.contains('fin-note'))) c.style.display = 'none';
  });
  var nav = document.getElementById('fin-subnav');
  if (nav) {
    nav.classList.add('ak'); nav.setAttribute('role', 'tablist'); nav.setAttribute('aria-label', 'Finance sections');
    Array.prototype.forEach.call(nav.querySelectorAll('[data-fin-sub]'), function (b) { b.setAttribute('role', 'tab'); });
    if (!nav._finPro) {
      nav._finPro = true;
      nav.addEventListener('click', function () { setTimeout(note, 0); });
    }
  }
  note();
}

function note() {
  var nav = document.getElementById('fin-subnav'); if (!nav) return;
  Array.prototype.forEach.call(nav.querySelectorAll('[data-fin-sub]'), function (b) { b.setAttribute('aria-selected', b.classList.contains('primary') ? 'true' : 'false'); });
  var el = document.getElementById('fin-pro-note');
  if (!el) { el = document.createElement('div'); el.id = 'fin-pro-note'; el.className = 'ak fin-note'; el.setAttribute('aria-live', 'polite'); nav.parentNode.insertBefore(el, nav.nextSibling); }
  el.textContent = NOTES[sub()] || '';
}

function refresh() {
  if (S.busy || Date.now() - S.at < 60000) return;
  S.busy = true;
  Promise.all([K.api('platform_economics_get_summary', { period: 'month' }), K.api('equity_get_dashboard', {})]).then(function (r) {
    S.busy = false; S.at = Date.now();
    S.data = { pe: K.okRes(r[0]) ? K.data(r[0]) : null, eq: K.okRes(r[1]) ? K.data(r[1]) : null };
    paintHead();
  }, function () { S.busy = false; });
}

var original = window.loadFinanceHub;
window.loadFinanceHub = function () {
  var out = original.apply(this, arguments);
  return Promise.resolve(out).then(function () { paintHead(); refresh(); });
};
})();

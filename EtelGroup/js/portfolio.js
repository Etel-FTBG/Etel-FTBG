/* ============================================================
   Etel Group — Portfolio page
   Renders the full company list (live + coming soon) from the same
   list_companies API the homepage's nav/grid/launcher/footer already
   use - no hardcoded company data on this page at all, so it can
   never drift the way the homepage's old footer list once did.
   ============================================================ */

(function () {
  'use strict';

  var ICONS = {
    fuel: '<path d="M3 22V8l9-6 9 6v14"/><path d="M9 22V12h6v10"/><circle cx="12" cy="7" r="1.5" fill="{c}" stroke="none"/><path d="M12 10v2"/>',
    cart: '<path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/>',
    truck: '<rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
    wallet: '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/><path d="M7 15h2M11 15h4"/>',
    ai: '<rect x="4" y="7" width="16" height="13" rx="2"/><path d="M12 7V3"/><circle cx="12" cy="3" r="1.3" fill="{c}" stroke="none"/><circle cx="8.5" cy="13" r="1.3" fill="{c}" stroke="none"/><circle cx="15.5" cy="13" r="1.3" fill="{c}" stroke="none"/><path d="M8 17h8"/>',
    id: '<rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="8" cy="12" r="2.2"/><path d="M13 10h6M13 14h4"/>',
    taxi: '<path d="M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h11a2 2 0 012 2v3"/><rect x="9" y="11" width="14" height="10" rx="2"/><circle cx="12" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>',
    laundry: '<rect x="2" y="3" width="20" height="18" rx="2"/><circle cx="12" cy="12" r="4"/><path d="M4 6h2M7 6h1"/><circle cx="12" cy="12" r="1.5" fill="{c}" stroke="none"/>',
    media: '<polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/><circle cx="6" cy="12" r="2" fill="{c}" stroke="none"/>',
    luxline: '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
    generic: '<circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/>',
  };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function iconSvg(key, color, strokeWidth) {
    var path = (ICONS[key] || ICONS.generic).split('{c}').join(color);
    return '<svg viewBox="0 0 24 24" fill="none" stroke="' + color + '" stroke-width="' + (strokeWidth || 1.6) + '" stroke-linecap="round" stroke-linejoin="round">' + path + '</svg>';
  }

  function apiBase() {
    return window.ETEL_GROUP_API || 'EtelGroup/api/boot.php';
  }

  function renderLive(companies) {
    var el = document.getElementById('pf-live-grid');
    if (!el) return;
    var live = companies.filter(function (c) { return c.status === 'live'; });
    if (!live.length) { el.innerHTML = ''; return; }
    el.innerHTML = live.map(function (c, i) {
      var tags = (c.tags || '').split(',').filter(Boolean).map(function (t) {
        return '<span>' + esc(t.trim()) + '</span>';
      }).join('');
      var hasLogo = !!c.logo_path;
      var bg = hasLogo
        ? 'background-image:linear-gradient(180deg, rgba(10,8,4,.30) 0%, rgba(10,8,4,.62) 55%, rgba(8,6,3,.94) 100%), url(\'' + esc(c.logo_path).replace(/'/g, "\\'") + '\');background-size:cover,cover;background-position:center,center;background-repeat:no-repeat,no-repeat'
        : '';
      return (
        '<article class="pf-card pf-card-live js-card" data-delay="' + Math.min(i, 9) + '" style="--pf-color:' + esc(c.dot_color) + ';' + bg + '">' +
        '<div class="pf-card-top">' +
        (hasLogo ? '' : '<div class="pf-card-icon">' + iconSvg(c.icon_key, c.dot_color) + '</div>') +
        '<span class="pf-badge pf-badge-live"><span class="pf-badge-dot"></span>Live</span>' +
        '</div>' +
        '<h3 class="pf-card-name">' + esc(c.name) + '</h3>' +
        '<p class="pf-card-tagline">' + esc(c.tagline) + (c.tagline_am ? ' &nbsp;·&nbsp; ' + esc(c.tagline_am) : '') + '</p>' +
        '<p class="pf-card-desc">' + esc(c.description_long || c.description_short) + '</p>' +
        (tags ? '<div class="pf-card-tags">' + tags + '</div>' : '') +
        '<a href="' + esc(c.url) + '" target="_blank" rel="noopener" class="pf-card-link">Visit ' + esc(c.name.replace(/^Etel\s+/i, '')) +
        '<svg width="11" height="11" viewBox="0 0 12 12" fill="none"><path d="M2 6h8M7 3l3 3-3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></a>' +
        '</article>'
      );
    }).join('');
  }

  function renderSoon(companies) {
    var el = document.getElementById('pf-soon-grid');
    if (!el) return;
    var soon = companies.filter(function (c) { return c.status !== 'live'; });
    if (!soon.length) { el.innerHTML = ''; return; }
    el.innerHTML = soon.map(function (c, i) {
      return (
        '<article class="pf-card pf-card-soon js-card" data-delay="' + Math.min(i, 9) + '" style="--pf-color:' + esc(c.dot_color) + '">' +
        '<div class="pf-card-top">' +
        '<div class="pf-card-icon">' + iconSvg(c.icon_key, c.dot_color) + '</div>' +
        '<span class="pf-badge pf-badge-soon">Coming in 2026</span>' +
        '</div>' +
        '<h3 class="pf-card-name">' + esc(c.name) + '</h3>' +
        '<p class="pf-card-tagline">' + esc(c.tagline) + (c.tagline_am ? ' &nbsp;·&nbsp; ' + esc(c.tagline_am) : '') + '</p>' +
        '<p class="pf-card-desc">' + esc(c.description_long || c.description_short) + '</p>' +
        '</article>'
      );
    }).join('');
  }

  function updateCounts(companies) {
    var liveCount = companies.filter(function (c) { return c.status === 'live'; }).length;
    var totalEl = document.getElementById('pf-total-count');
    var liveEl = document.getElementById('pf-live-count');
    if (totalEl) totalEl.textContent = companies.length;
    if (liveEl) liveEl.textContent = liveCount;
  }

  async function init() {
    try {
      var res = await fetch(apiBase() + '?action=list_companies');
      var data = await res.json();
      if (!data.ok && !data.success) throw new Error('bad response');
      var companies = data.data.companies;
      updateCounts(companies);
      renderLive(companies);
      renderSoon(companies);
      // These cards didn't exist when main.js's own reveal-on-scroll scan
      // ran at page load, so without this they'd sit at opacity:0 forever.
      if (typeof window.egRefreshRevealAndTilt === 'function') {
        window.egRefreshRevealAndTilt();
      }
    } catch (e) {
      console.warn('[EtelGroup] Could not load portfolio companies:', e);
      var live = document.getElementById('pf-live-grid');
      var soon = document.getElementById('pf-soon-grid');
      if (live) live.innerHTML = '<p class="pf-error">Could not load the live company list right now. Please refresh.</p>';
      if (soon) soon.innerHTML = '';
    }
  }

  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', init);
})();

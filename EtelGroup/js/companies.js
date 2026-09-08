/* ============================================================
   Etel Group super-page — dynamic company rendering
   Replaces what used to be 4 separate hardcoded lists (nav dropdown,
   mobile nav, company grid, launcher grid) with one fetch from
   api/boot.php?action=list_companies, rendered into the same CSS
   classes/markup shape those lists always used - so this is a
   no-visible-change deploy for anyone browsing the site, but the
   admin panel can now edit/add/reorder companies without touching
   this HTML file again.
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

  // Card background style when a logo is set: the logo image fills the
  // whole card (not just a small icon box), with a dark gradient baked
  // into the same background-image stack (CSS layers multiple
  // backgrounds with the first listed on top) so the name/category/
  // description text sitting on top of it stays readable regardless of
  // how bright or busy the logo image itself is. Falls back to nothing
  // extra (just the existing solid card background) when no logo is set.
  function logoBgDecl(c) {
    if (!c.logo_path) return '';
    var url = esc(c.logo_path).replace(/'/g, "\\'");
    return ';background-image:linear-gradient(180deg, rgba(10,8,4,.28) 0%, rgba(10,8,4,.58) 55%, rgba(8,6,3,.92) 100%), url(\'' + url + '\');' +
      'background-size:cover,cover;background-position:center,center;background-repeat:no-repeat,no-repeat';
  }

  // Renders a company's actual logo image when one has been set in the
  // admin panel (Companies -> Logo path), falling back to the existing
  // themed SVG glyph otherwise. The DB column and admin form for this
  // already existed - logo_path just was never read on the rendering
  // side, so every card showed a generic icon no matter what an admin
  // typed into that field.
  function apiBase() {
    return (window.ETEL_GROUP_API) || 'EtelGroup/api/boot.php';
  }

  function renderNavDropdown(companies) {
    var el = document.getElementById('nav-dropdown-list');
    if (!el) return;
    el.innerHTML = companies.map(function (c) {
      var disabled = c.status !== 'live';
      var href = disabled ? '#services' : esc(c.url);
      return '<a href="' + href + '" ' + (disabled ? '' : 'target="_blank" rel="noopener"') + ' role="menuitem"' +
        (disabled ? ' style="opacity:0.5;pointer-events:none;"' : '') + '>' +
        '<span class="dd-dot" style="background:' + esc(c.dot_color) + '"></span>' +
        esc(c.name) +
        '<span class="dd-sub">' + (disabled ? 'Coming Soon' : esc(c.tagline)) + '</span>' +
        '</a>';
    }).join('');
  }

  function renderMobileNav(companies) {
    var el = document.getElementById('mobile-nav-list');
    if (!el) return;
    el.innerHTML = companies.map(function (c) {
      var disabled = c.status !== 'live';
      var shortName = c.name.replace(/^Etel\s+/i, '');
      return '<a class="mob-company-link" href="' + (disabled ? '#' : esc(c.url)) + '" ' +
        (disabled ? 'style="opacity:0.4;pointer-events:none;"' : 'target="_blank" rel="noopener" onclick="closeMob()"') + '>' +
        '<span class="mob-company-dot" style="background:' + esc(c.dot_color) + '"></span>' +
        '<span class="mob-company-name">' + esc(shortName) + '</span>' +
        '</a>';
    }).join('');
  }

  function renderCoCardGrid(companies) {
    var el = document.getElementById('companies-grid-list');
    if (!el) return;
    el.innerHTML = companies.map(function (c, i) {
      var num = String(i + 1).padStart(2, '0');
      var isLive = c.status === 'live';
      var href = isLive ? esc(c.url) : '#';
      var badgeClass = isLive ? 'co-badge-live' : 'co-badge-plan';
      var badgeText = isLive ? 'Live' : 'Coming Soon';
      var hasLogo = !!c.logo_path;
      return (
        '<a href="' + href + '" ' + (isLive ? 'target="_blank" rel="noopener"' : '') + ' class="co-card js-card' + (hasLogo ? ' co-card-has-logo' : '') + '" style="--co-color:' + esc(c.dot_color) + logoBgDecl(c) + '" data-delay="' + Math.min(i, 9) + '">' +
        '<div class="co-card-corner"></div>' +
        '<span class="co-number">' + num + '</span>' +
        (hasLogo ? '' : '<div class="co-icon">' + iconSvg(c.icon_key, c.dot_color) + '</div>') +
        '<p class="co-name">' + esc(c.name) + '</p>' +
        '<p class="co-category">' + esc(c.tagline) + '</p>' +
        '<p class="co-desc">' + esc(c.description_short) + '</p>' +
        '<span class="co-badge ' + badgeClass + '"><span class="co-badge-dot"></span>' + badgeText + '</span>' +
        '<span class="co-arrow"><svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 6h8M7 3l3 3-3 3" stroke="#D4AF37" stroke-width="1.5" stroke-linecap="round"/></svg></span>' +
        '</a>'
      );
    }).join('');
  }

  function renderLauncherGrid(companies) {
    var el = document.getElementById('launcher-grid-list');
    if (!el) return;
    var live = companies.filter(function (c) { return c.status === 'live'; });
    el.innerHTML = live.map(function (c, i) {
      var num = String(i + 1).padStart(2, '0');
      var tags = (c.tags || '').split(',').filter(Boolean).map(function (t) { return '<span>' + esc(t.trim()) + '</span>'; }).join('');
      var hasLogo = !!c.logo_path;
      return (
        '<div class="launcher-card js-lcard' + (hasLogo ? ' co-card-has-logo' : '') + '" style="' + logoBgDecl(c).replace(/^;/, '') + '" data-url="' + esc(c.url) + '" data-delay="' + Math.min(i, 9) + '">' +
        '<div class="lc-status-bar">' +
        '<span class="lc-dot" id="ls-' + esc(c.slug) + '"></span>' +
        '<span class="lc-label" id="ll-' + esc(c.slug) + '">checking...</span>' +
        '<span class="lc-tag lc-tag-live">' + esc(c.tagline) + '</span>' +
        '</div>' +
        (hasLogo ? '' : '<div class="lc-icon" style="--lc-color:' + esc(c.dot_color) + '">' + iconSvg(c.icon_key, 'currentColor', 1.5) + '</div>') +
        '<div class="lc-body">' +
        '<p class="lc-num">' + num + '</p>' +
        '<p class="lc-name">' + esc(c.name) + '</p>' +
        '<p class="lc-cat">' + esc(c.tagline) + (c.tagline_am ? ' &nbsp;\u00b7&nbsp; ' + esc(c.tagline_am) : '') + '</p>' +
        '<p class="lc-desc">' + esc(c.description_long || c.description_short) + '</p>' +
        '<div class="lc-tags">' + tags + '</div>' +
        '</div>' +
        '<a href="' + esc(c.url) + '" target="_blank" rel="noopener" class="lc-btn">Launch &nbsp;<svg width="10" height="10" viewBox="0 0 12 12" fill="none"><path d="M2 6h8M7 3l3 3-3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></a>' +
        '</div>'
      );
    }).join('');
    // Re-run the live status checker (main.js) against this dynamic
    // set now that the dot/label elements exist in the DOM.
    if (typeof window.egRecheckLauncherStatus === 'function') {
      window.egRecheckLauncherStatus(live.map(function (c) { return { url: c.url, dot: 'ls-' + c.slug, label: 'll-' + c.slug }; }));
    }
  }

  async function init() {
    var res;
    try {
      res = await fetch(apiBase() + '?action=list_companies');
      var data = await res.json();
      if (!data.ok && !data.success) throw new Error('bad response');
      var companies = data.data.companies;
      renderNavDropdown(companies);
      renderMobileNav(companies);
      renderCoCardGrid(companies);
      renderLauncherGrid(companies);
      // The scroll-reveal IntersectionObserver and 3D tilt binding (see
      // main.js) already ran once by the time this async fetch resolves,
      // so newly-injected cards were never observed/bound - they'd sit
      // at opacity:0 forever, present and clickable but invisible.
      if (typeof window.egRefreshRevealAndTilt === 'function') {
        window.egRefreshRevealAndTilt();
      }
    } catch (e) {
      // Fail quiet - the four containers just stay empty rather than
      // breaking the rest of the page if the API is unreachable.
      console.warn('[EtelGroup] Could not load companies list:', e);
    }
  }

  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', init);
})();

/* ============================================================
   Investor + Equb account links — shared across ALL role apps
   (driver, courier, service-giver, customer). Previously injected as
   floating buttons over the page content; moved into each app's real
   Profile panel/page instead, since floating buttons covered real
   content (customer Home screenshot showed one covering an item
   card). Each app's Profile is structured differently (driver has a
   static full page; the other three use a slide-in side panel that's
   hidden by default but present in the DOM from page load), so this
   checks a priority list of known containers rather than assuming
   one shape fits all four apps.
   ============================================================ */
(function () {
  function getToken() {
    try {
      if (typeof Auth !== 'undefined' && Auth.getToken) return Auth.getToken();
    } catch (e) {}
    try { return localStorage.getItem('etel_token') || ''; } catch (e) { return ''; }
  }

  function getApiUrl() {
    try {
      if (typeof ETEL !== 'undefined' && ETEL.api) return ETEL.api;
    } catch (e) {}
    var loc = window.location.pathname;
    var marker = '/delivery/';
    var idx = loc.indexOf(marker);
    var dir = idx >= 0 ? loc.substring(0, idx + marker.length) : loc.substring(0, loc.lastIndexOf('/') + 1);
    return window.location.protocol + '//' + window.location.host + dir + 'api/boot.php';
  }

  function appPath(folder) {
    var loc = window.location.pathname;
    var marker = '/delivery/';
    var idx = loc.indexOf(marker);
    var dir = idx >= 0 ? loc.substring(0, idx + marker.length) : loc.substring(0, loc.lastIndexOf('/') + 1);
    return window.location.protocol + '//' + window.location.host + dir + folder + '/';
  }

  // Priority list of known Profile containers across the 4 apps.
  // Each entry's container must exist in the initial page HTML (even
  // if visually hidden behind display:none/transform) - all four do,
  // confirmed by inspecting each app's real markup rather than
  // assumed.
  function findProfileContainer() {
    var candidates = ['page-profile', 'cp-profile-panel', 'sg-profile-panel'];
    for (var i = 0; i < candidates.length; i++) {
      var el = document.getElementById(candidates[i]);
      if (el) return el;
    }
    // Customer app's #profile-info div gets its innerHTML replaced
    // each time the panel opens - append as a sibling in its parent
    // drawer instead, so our section survives that re-render.
    var info = document.getElementById('profile-info');
    if (info && info.parentElement) return info.parentElement;
    return null;
  }

  function ensureCss() {
    if (document.getElementById('etel-account-links-css')) return;
    var css = document.createElement('style');
    css.id = 'etel-account-links-css';
    css.textContent =
      '.etel-account-links{padding:16px 20px;margin-top:6px;border-top:1px solid rgba(255,255,255,.08)}' +
      '.etel-account-links-hdr{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#8a8a9a;margin-bottom:10px}' +
      '.etel-account-btn{display:flex;align-items:center;gap:10px;width:100%;padding:13px 15px;border-radius:14px;border:none;' +
      'font-weight:800;font-size:13.5px;cursor:pointer;margin-bottom:10px;text-align:left;' +
      'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}' +
      '.etel-account-btn:last-child{margin-bottom:0}' +
      '.etel-account-btn:active{transform:scale(.98)}' +
      '#etel-profile-investor-btn{background:linear-gradient(135deg,#C8960C,#F2CC6B);color:#100b02}' +
      // Gold outline, so it belongs to the design but stays different from the solid-gold Investor button.
      '#etel-profile-equb-btn{background:rgba(212,175,55,.12);border:1px solid rgba(212,175,55,.5);color:#F4D56A}' +
      'body.light-mode #etel-profile-equb-btn,body.etel-light #etel-profile-equb-btn{background:rgba(160,120,20,.1);border-color:rgba(160,120,20,.55);color:#6B4E00}';
    document.head.appendChild(css);
  }

  function injectSection(showInvestor, showEqub) {
    if (!showInvestor && !showEqub) return;
    if (document.getElementById('etel-account-links')) return; // already inserted
    var container = findProfileContainer();
    if (!container) return; // this app's profile markup didn't match any known shape - fail quiet, never break the app
    ensureCss();
    var section = document.createElement('div');
    section.id = 'etel-account-links';
    section.className = 'etel-account-links';
    var html = '<div class="etel-account-links-hdr">Your Accounts</div>';
    if (showInvestor) html += '<button id="etel-profile-investor-btn" class="etel-account-btn">💰 Investor Portal</button>';
    if (showEqub) html += '<button id="etel-profile-equb-btn" class="etel-account-btn">🤝 Equb</button>';
    section.innerHTML = html;
    container.appendChild(section);
    if (showInvestor) document.getElementById('etel-profile-investor-btn').onclick = function () { window.open(appPath('investor'), '_blank'); };
    if (showEqub) document.getElementById('etel-profile-equb-btn').onclick = function () { window.open(appPath('equb'), '_blank'); };
  }

  function callApi(action, cb) {
    var token = getToken();
    if (!token) return;
    fetch(getApiUrl(), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
      body: JSON.stringify({ action: action }),
    })
      .then(function (r) { return r.json(); })
      .then(cb)
      .catch(function () { /* silent - a failed check just means no button, never breaks the app */ });
  }

  var isInvestor = false, isEqubPermitted = false, checksReturned = 0;
  function maybeInject() {
    checksReturned++;
    if (checksReturned >= 2) injectSection(isInvestor, isEqubPermitted);
  }

  function check() {
    callApi('investor_portal_am_i_investor', function (res) {
      if (res && res.success && res.data && res.data.is_investor) isInvestor = true;
      maybeInject();
    });
    callApi('equb_am_i_permitted', function (res) {
      if (res && res.success && res.data && res.data.is_permitted) isEqubPermitted = true;
      maybeInject();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', check);
  } else {
    check();
  }
})();

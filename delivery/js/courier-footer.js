/* ETEL Courier - a real footer at the very end of the page, so nothing is ever hidden behind the floating buttons (additive script, no bundle release).
   The page ends with the buttons that matter (Refresh jobs, Earnings) and the small controls that otherwise float over the content (job sound, help, theme).
   The footer sits in the normal page flow and keeps room under it for the floating buttons, so every control can be reached by scrolling to the bottom.
   The existing Refresh jobs / Earnings buttons are MOVED into it (not copied), so they keep working exactly as before. English / Amharic (etel_lang). No inline handlers. */
(function () {
  'use strict';
  if (window.__etelFooter) return;
  window.__etelFooter = true;
  var AM = { 'Help': 'እገዛ', 'Theme': 'ገጽታ', 'Job sound': 'የሥራ ድምፅ', 'Job sound on': 'የሥራ ድምፅ በርቷል', 'Job sound off': 'የሥራ ድምፅ ጠፍቷል', 'Tap to turn job sound on': 'ድምፁን ለማብራት ይንኩ', 'ETEL Courier': 'ኢቴል ሹፌር' };
  function lang() { try { return localStorage.getItem('etel_lang') === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
  function T(s) { return lang() === 'am' && AM[s] ? AM[s] : s; }
  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

  function css() {
    if (document.getElementById('cf-css')) return;
    var s = document.createElement('style'); s.id = 'cf-css';
    s.textContent =
      '#cf-footer{margin:18px 0 0;padding:6px 14px calc(104px + env(safe-area-inset-bottom,0px));border-top:1px solid rgba(212,175,55,.35);background:rgba(128,128,128,.07)}' +
      '#cf-footer .action-bar{margin:0 -14px;border-top:0;background:transparent}' +
      '.cf-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}' +
      '.cf-pill{flex:1 1 30%;min-width:96px;display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:44px;padding:8px 10px;border-radius:14px;border:1px solid rgba(212,175,55,.45);background:rgba(212,175,55,.1);color:inherit;font:inherit;font-size:13px;font-weight:700;text-decoration:none;cursor:pointer}' +
      '.cf-pill:focus-visible{outline:2px solid #D4AF37;outline-offset:2px}' +
      '.cf-small{text-align:center;font-size:11.5px;opacity:.65;margin-top:12px}';
    document.head.appendChild(s);
  }
  function soundLabel() { var c = document.getElementById('etel-sound-chip'); var t = c ? (c.textContent || '').replace(/^[^A-Za-z]+/, '').trim() : ''; return t ? T(t) : T('Job sound'); }
  function paint() {
    var snd = document.getElementById('cf-sound'); if (snd) snd.textContent = '🔔 ' + soundLabel();
    var h = document.getElementById('cf-help'), fab = document.getElementById('etel-help-fab');
    if (h && fab && fab.getAttribute('href')) h.setAttribute('href', fab.getAttribute('href'));
  }
  var tries = 0;
  (function wait() {
    var box = document.querySelector('.container');
    if (!box || !box.parentNode) { if (tries++ < 100) return setTimeout(wait, 200); return; }
    if (document.getElementById('cf-footer')) return;
    css();
    var f = document.createElement('div'); f.id = 'cf-footer';
    f.innerHTML =
      '<div class="cf-row"><button type="button" class="cf-pill" id="cf-sound">🔔 ' + esc(T('Job sound')) + '</button>' +
      '<a class="cf-pill" id="cf-help" href="#">💬 ' + esc(T('Help')) + '</a>' +
      '<button type="button" class="cf-pill" id="cf-theme">🌙 ' + esc(T('Theme')) + '</button></div>' +
      '<div class="cf-small">' + esc(T('ETEL Courier')) + ' · Hawassa</div>';
    var bar = document.querySelector('.action-bar');
    if (bar) f.insertBefore(bar, f.firstChild);   /* Refresh jobs + Earnings: moved here, not copied */
    box.parentNode.insertBefore(f, box.nextSibling);
    f.querySelector('#cf-sound').addEventListener('click', function () { var c = document.getElementById('etel-sound-chip'); if (c) c.click(); setTimeout(paint, 80); });
    f.querySelector('#cf-theme').addEventListener('click', function () { var t = document.getElementById('dark-theme-toggle'); if (t) t.click(); });
    f.querySelector('#cf-help').addEventListener('click', function (e) { var fab = document.getElementById('etel-help-fab'); if (fab && !fab.getAttribute('href')) { e.preventDefault(); fab.click(); } });
    paint(); setInterval(paint, 2000);
  })();
})();

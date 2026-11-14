/* Etel Pay - eye button on the balance card: hide / show the amount. Remembered on this device.
   Visual hiding only (the amount is blurred and cannot be selected); nothing is sent anywhere. */
(function () {
  'use strict';
  var KEY = 'etelpay_hidebal';
  var EYE = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  var EYE_OFF = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 5.2A10.5 10.5 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4.1M6.5 6.6A17 17 0 0 0 2 12s3.6 7 10 7a10 10 0 0 0 4.2-.9"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';
  var root = document.documentElement;
  function get() { try { return localStorage.getItem(KEY) === '1'; } catch (e) { return false; } }
  function set(v) { try { localStorage.setItem(KEY, v ? '1' : '0'); } catch (e) { } }
  function paint(btn) {
    var h = root.classList.contains('ep-hidebal');
    btn.innerHTML = h ? EYE_OFF : EYE;
    btn.setAttribute('aria-pressed', h ? 'true' : 'false');
    btn.setAttribute('aria-label', h ? 'Show balance' : 'Hide balance');
    btn.title = h ? 'Show balance' : 'Hide balance';
  }
  if (get()) root.classList.add('ep-hidebal');
  function boot() {
    var card = document.querySelector('.balance-card');
    if (!card || card.querySelector('.ep-eye')) return;
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'ep-eye';
    b.onclick = function () { var h = !root.classList.contains('ep-hidebal'); root.classList.toggle('ep-hidebal', h); set(h); paint(b); };
    card.appendChild(b); paint(b);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();

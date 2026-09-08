/**
 * ኢE Pay Desktop Helper v3
 * Only used by wallet.html
 * Sidebar HTML is static - this just syncs data into it
 */
(function() {
  'use strict';

  // Sync exchange rates from rate-bar to desktop panel
  function syncRates() {
    var map = {usd:'desk-r-usd', eur:'desk-r-eur', gbp:'desk-r-gbp', sar:'desk-r-sar'};
    Object.keys(map).forEach(function(k) {
      var src = document.getElementById('r-'+k);
      var dst = document.getElementById(map[k]);
      if (src && dst && src.textContent && src.textContent !== '—') {
        dst.textContent = src.textContent;
      }
    });
  }

  // Public functions called by wallet.html
  window.EP_DESKTOP = {
    showAdmin: function() {
      var el = document.getElementById('sb-admin-block');
      if (el) el.style.display = 'block';
    },
    updateUser: function(name, phone, avatar) {
      var nm = document.getElementById('sb-name');
      var ph = document.getElementById('sb-phone');
      var av = document.getElementById('sb-avatar');
      if (nm) nm.textContent = name || '';
      if (ph) ph.textContent = phone || '';
      if (av && avatar) av.innerHTML = '<img src="' + avatar + '" alt="" style="width:100%;height:100%;border-radius:50%;object-fit:cover">';
    },
    syncRates: syncRates
  };

  // Auto-sync rates every 5 seconds
  setInterval(syncRates, 5000);
  setTimeout(syncRates, 1500);

})();

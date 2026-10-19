/* ETEL Ride - show the promo discount on the trip-end screen (additive script, no bundle release).
   When a trip used a promo code, the big fare on the "You have arrived" card is what the rider pays (after the discount) while
   the Fare Breakdown under it still adds up to the fare before the discount. This adds one line to that breakdown:
   "Promo discount (CODE)  - ETB 20" so the two numbers agree. Details come from the trip status answers the app already
   fetches (promo_code, promo_discount); nothing extra is requested. Text is translated by ride-i18n.js (Amharic). */
(function () {
    'use strict';
    if (window.__etelRidePromoLines) return;
    window.__etelRidePromoLines = true;

    var promo = null;   // { code, discount } of the current trip, or null
    var realFetch = window.fetch ? window.fetch.bind(window) : null;
    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

    if (realFetch) {
        window.fetch = function (input, init) {
            var p = realFetch(input, init);
            try {
                var u = typeof input === 'string' ? input : (input && input.url) || '';
                if (u.indexOf('boot.php') !== -1) {
                    p.then(function (r) { return r.clone().json(); }).then(function (j) {
                        var d = j && j.success && j.data;
                        if (!d || typeof d !== 'object' || !d.status) return;
                        var disc = parseFloat(d.promo_discount) || 0;
                        if (disc > 0 && d.promo_code) { promo = { code: String(d.promo_code), discount: disc }; schedule(); }
                        else if (d.status === 'searching' || d.status === 'accepted') promo = null;
                    }).catch(function () {});
                }
            } catch (e) { /* ignore */ }
            return p;
        };
    }

    var queued = false;
    function schedule() { if (queued) return; queued = true; (window.requestAnimationFrame || setTimeout)(function () { queued = false; apply(); }); }
    function apply() {
        if (!promo) return;
        var heads = document.querySelectorAll('div[style*="text-transform:uppercase"]');
        for (var i = 0; i < heads.length; i++) {
            if (heads[i].textContent.indexOf('Fare Breakdown') === -1) continue;
            var block = heads[i].parentNode;
            if (!block || block.querySelector('.rpl-line')) continue;
            var row = document.createElement('div');
            row.className = 'rpl-line';
            row.style.cssText = 'display:flex;justify-content:space-between;gap:8px;align-items:center;border-top:1px solid rgba(255,255,255,.08);margin-top:6px;padding-top:6px;font-size:12px;color:#2ECC71';
            row.innerHTML = '<span><span>Promo discount</span> <span data-no-i18n>(' + esc(promo.code) + ')</span></span><span data-no-i18n>- ETB ' + esc(Math.round(promo.discount)) + '</span>';
            var note = document.createElement('div');
            note.className = 'rpl-line';
            note.style.cssText = 'font-size:10.5px;opacity:.75;margin-top:3px';
            note.textContent = 'The total above is already after your promo.';
            block.appendChild(row); block.appendChild(note);
        }
    }
    try { new MutationObserver(function () { if (promo) schedule(); }).observe(document.documentElement, { childList: true, subtree: true }); } catch (e) {}
})();

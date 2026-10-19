/* ETEL Driver - tell the driver when a trip used a promo code (additive script, no bundle release).
   The fare shown at the end of the trip is what the rider pays after the promo. The discount is paid by Etel: the driver
   collects the shown amount in cash and Etel adds the discount to the driver's ኢE Money balance. Without a word about it the
   lower number looks like a mistake. When the end-of-trip answer carries a promo (promo_code, promo_discount) a notice appears. */
(function () {
    'use strict';
    if (window.__etelDriverPromoNote) return;
    window.__etelDriverPromoNote = true;
    var realFetch = window.fetch ? window.fetch.bind(window) : null;
    if (!realFetch) return;
    window.fetch = function (input, init) {
        var p = realFetch(input, init);
        try {
            var u = typeof input === 'string' ? input : (input && input.url) || '';
            if (u.indexOf('boot.php') !== -1 && init && typeof init.body === 'string' && init.body.indexOf('"driver_arrived"') !== -1) {
                p.then(function (r) { return r.clone().json(); }).then(function (j) {
                    var d = j && j.success && j.data;
                    if (d && parseFloat(d.promo_discount) > 0 && typeof window.showDriverToast === 'function') {
                        window.showDriverToast('The rider used a promo code. Collect the amount shown. Etel pays the discount: it is added to your Etel money balance.');
                    }
                }).catch(function () {});
            }
        } catch (e) { /* ignore */ }
        return p;
    };
})();

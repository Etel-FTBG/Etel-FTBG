/* Customer app: ETEL sounds when an order moves on (js/etel-sounds.js).
 * Listens to the answers the app already gets (customer_get_orders for the home card, track_order for the tracking screen): when an order's status
 * changes while the app is open it plays one cue: courier found / picked up / on the way = a friendly rise, delivered = the ETEL fanfare,
 * cancelled = a soft low pair. The first answer for an order is only remembered, never played, so opening the app is silent.
 * It changes no request and no screen. Phones play sound only after one touch of the page; closed apps use the phone's push tone instead. */
(function () {
    'use strict';
    if (window.__etelCustomerSound || !window.EtelSound || typeof window.apiGet !== 'function') return;
    window.__etelCustomerSound = 'v1';
    var seen = {};
    var RANK = { progress: 1, cancel: 2, delivered: 3 };

    function cueFor(status) {
        if (status === 'delivered') return 'delivered';
        if (status === 'cancelled') return 'cancel';
        if (status === 'assigned' || status === 'accepted' || status === 'preparing' || status === 'picked_up' || status === 'in_transit') return 'progress';
        return '';
    }
    function look(list) {
        var best = '';
        (list || []).forEach(function (o) {
            if (!o || o.id == null || !o.status) return;
            var was = seen[o.id];
            seen[o.id] = o.status;
            if (was === undefined || was === o.status) return;
            var c = cueFor(o.status);
            if (c && (!best || RANK[c] > RANK[best])) best = c;
        });
        if (best) window.EtelSound.play(best);
    }

    var orig = window.apiGet;
    window.apiGet = function (action) {
        var p = orig.apply(this, arguments);
        if (action === 'track_order' || action === 'customer_get_orders') {
            Promise.resolve(p).then(function (res) {
                try {
                    if (!res || !res.success || !res.data) return;
                    look(action === 'track_order' ? (res.data.order ? [res.data.order] : []) : res.data.orders);
                } catch (e) {}
            });
        }
        return p;
    };
})();

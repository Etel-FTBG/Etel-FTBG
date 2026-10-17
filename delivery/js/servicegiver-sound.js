/* Service giver (shop) app: the ETEL order bell (js/etel-sounds.js).
 * Every 15 s while the app is open it asks for the shop's orders (the same sg_orders answer the dashboard uses). When an order arrives in
 * "pending" that was not there before, the bell rings (and the phone vibrates) every 2.4 s until the order is accepted or declined, for at
 * most 90 s. Opening the app with orders already waiting stays quiet: that list is the baseline.
 * A small chip bottom-left turns the bell on or off (and asks for one touch when the phone still blocks sound). It writes nothing. */
(function () {
    'use strict';
    if (window.__etelShopSound || !window.EtelSound) return;
    window.__etelShopSound = 'v1';
    var ES = window.EtelSound, known = null, started = 0, KEY = 'sg-new';

    function ask() {
        var token = typeof Auth !== 'undefined' && Auth.getToken ? Auth.getToken() : '';
        if (!token) return Promise.resolve(null);
        var url = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
        return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token }, body: JSON.stringify({ action: 'sg_orders' }) })
            .then(function (r) { return r.json(); }).then(function (j) { return j && j.success && j.data ? j.data : null; }).catch(function () { return null; });
    }
    function check() {
        if (document.visibilityState === 'hidden') return;
        ask().then(function (d) {
            if (!d) return;
            var list = d.assignments || d.orders || (Array.isArray(d) ? d : []);
            var pending = {}, n = 0, fresh = false;
            list.forEach(function (o) { if ((o.status || 'pending') === 'pending') { pending[o.id] = 1; n++; if (known && !known[o.id]) fresh = true; } });
            if (known === null) { known = pending; return; }
            known = pending;
            if (!n) { ES.stop(KEY); started = 0; }
            else if (fresh) { ES.stop(KEY); started = Date.now(); ES.ring(KEY, 'order', 2400); }
            else if (ES.ringing(KEY) && Date.now() - started > 90000) ES.stop(KEY);
        });
    }
    function chip() {
        if (document.getElementById('etel-sound-chip')) return;
        var b = document.createElement('button');
        b.type = 'button'; b.id = 'etel-sound-chip';
        b.style.cssText = 'position:fixed;left:12px;bottom:12px;z-index:99999;padding:9px 14px;border-radius:22px;border:1px solid #D4AF37;background:#0B1E3F;color:#F5D77A;font-size:13px;font-weight:700;box-shadow:0 4px 16px rgba(0,0,0,.35);cursor:pointer';
        function paint() {
            b.textContent = ES.isMuted() ? '🔕 Order bell off' : ES.locked() ? '🔔 Tap to turn order bell on' : '🔔 Order bell on';
        }
        b.addEventListener('click', function () {
            if (ES.isMuted()) ES.setMuted(false); else if (ES.locked()) { ES.unlock(); ES.test('order'); } else ES.setMuted(true);
            paint();
        });
        document.body.appendChild(b); paint(); setInterval(paint, 2000);
    }
    function go() { chip(); check(); setInterval(check, 15000); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go); else go();
})();

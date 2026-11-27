/* ETEL - Send a package: the courier's photo proof on the sender's tracking screen (additive script, loaded before js/customer-package.js).
     PkgProof.attach(box, order)   adds a "Photo from the courier" card under a delivered package when the server says there is one (order.has_proof)
   Works for a package and for a shop order. The photo is not in the tracking answer: "View photo" asks api customer_package_proof (the sender's own package only) and shows it. The shown photo is
   remembered for this visit, because the tracking screen is redrawn every few seconds. */
(function () {
    'use strict';
    if (window.PkgProof) return;
    ETEL_I18N.register('customer-package-proof', {
        en: { pkgp_t: 'Photo from the courier', pkgp_s: 'Taken by the courier when they delivered your order.', pkgp_view: 'View photo', pkgp_load: 'Loading the photo…', pkgp_fail: 'Could not load the photo. Try again.', pkgp_alt: 'Photo of the delivered package' },
        am: { pkgp_t: 'ከመልእክተኛው የተላከ ፎቶ', pkgp_s: 'ትዕዛዝዎን ሲያደርስ መልእክተኛው ያነሳው።', pkgp_view: 'ፎቶውን ይመልከቱ', pkgp_load: 'ፎቶውን በመጫን ላይ…', pkgp_fail: 'ፎቶውን መጫን አልተቻለም። እንደገና ይሞክሩ።', pkgp_alt: 'የደረሰው ጥቅል ፎቶ' }
    });
    function T(k) { return ETEL_I18N.t(k); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    var SHOWN = {};   // order id -> data URL

    function html(id) {
        var inner = SHOWN[id]
            ? '<img class="pkgp-img" src="' + esc(SHOWN[id]) + '" alt="' + esc(T('pkgp_alt')) + '">'
            : '<button type="button" class="pkgp-b"' + EtelActions.attrs('pkgProofShow', [id]) + '>' + esc(T('pkgp_view')) + '</button>';
        return '<section class="pkg-card pkgp"><h3 class="pkg-h">📷 ' + esc(T('pkgp_t')) + '</h3><p class="pkgp-s">' + esc(T('pkgp_s')) + '</p><div class="pkgp-in">' + inner + '</div><div class="pkgp-msg" role="status"></div></section>';
    }
    function attach(box, o) {
        if (!box || !o || !o.has_proof) return;   // the server sets has_proof only for the customer's own order that has a photo
        box.insertAdjacentHTML('beforeend', html(o.id));
    }
    window.pkgProofShow = function (id) {
        var msg = document.querySelector('.pkgp-msg'), btn = document.querySelector('.pkgp-b');
        if (msg) msg.textContent = T('pkgp_load'); if (btn) btn.disabled = true;
        api('customer_package_proof', { order_id: id }).then(function (r) {
            var p = r && r.success && r.data && r.data.photo;
            if (p) { SHOWN[id] = p; var box = document.querySelector('.pkgp'); if (box) box.outerHTML = html(id); }
            else { var m2 = document.querySelector('.pkgp-msg'), b2 = document.querySelector('.pkgp-b'); if (m2) m2.textContent = T('pkgp_fail'); if (b2) b2.disabled = false; }
        }).catch(function () { var m3 = document.querySelector('.pkgp-msg'), b3 = document.querySelector('.pkgp-b'); if (m3) m3.textContent = T('pkgp_fail'); if (b3) b3.disabled = false; });
    };
    window.PkgProof = { attach: attach };
})();

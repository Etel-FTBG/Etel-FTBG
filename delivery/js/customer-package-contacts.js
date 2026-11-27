/* ETEL - Send a package: the address book (names, phones and addresses of people the customer sends to or from). Additive script, loaded before
   js/customer-package.js.
     pkgContactsOpen(which)   opens the list; "Use" fills the pickup ('pickup': sender name + phone + address) or the drop-off ('drop': recipient) of the wizard
     pkgContactSave(which)    saves the name, phone and address now in the form to the address book
   Asks api customer_package_contacts / customer_package_contact_save / customer_package_contact_delete (the customer's own contacts only).
   The wizard (customer-package.js) provides window.pkgContactCollect(which) and window.pkgContactFill(which, contact). Delete needs a second tap. */
(function () {
    'use strict';
    if (window.PkgContacts) return;
    ETEL_I18N.register('customer-package-contacts', {
        en: { pkgc_btn: 'Address book', pkgc_save: 'Save to address book', pkgc_title: 'Address book', pkgc_use: 'Use', pkgc_del: 'Delete', pkgc_del2: 'Tap again to delete', pkgc_close: 'Close',
            pkgc_empty: 'Nothing saved yet. Fill the name, phone and address, then tap "Save to address book".', pkgc_loading: 'Loading…', pkgc_fail: 'Could not load your address book.',
            pkgc_saved: 'Saved to your address book.', pkgc_need: 'Fill the name, phone and address first.', pkgc_err: 'Could not save. Try again.', pkgc_deleted: 'Deleted.', pkgc_use_pick: 'Choose who sends it', pkgc_use_drop: 'Choose who gets it' },
        am: { pkgc_btn: 'የአድራሻ መዝገብ', pkgc_save: 'በአድራሻ መዝገብ ያስቀምጡ', pkgc_title: 'የአድራሻ መዝገብ', pkgc_use: 'ተጠቀም', pkgc_del: 'ሰርዝ', pkgc_del2: 'ለመሰረዝ እንደገና ይንኩ', pkgc_close: 'ዝጋ',
            pkgc_empty: 'ገና ምንም አልተቀመጠም። ስሙን፣ ስልኩንና አድራሻውን ሞልተው "በአድራሻ መዝገብ ያስቀምጡ" ይንኩ።', pkgc_loading: 'በመጫን ላይ…', pkgc_fail: 'የአድራሻ መዝገብዎን መጫን አልተቻለም።',
            pkgc_saved: 'በአድራሻ መዝገብዎ ተቀምጧል።', pkgc_need: 'መጀመሪያ ስሙን፣ ስልኩንና አድራሻውን ይሙሉ።', pkgc_err: 'ማስቀመጥ አልተቻለም። እንደገና ይሞክሩ።', pkgc_deleted: 'ተሰርዟል።', pkgc_use_pick: 'የሚልከውን ሰው ይምረጡ', pkgc_use_drop: 'የሚቀበለውን ሰው ይምረጡ' }
    });
    function T(k) { return ETEL_I18N.t(k); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function A(name, args) { return EtelActions.attrs(name, args); }
    function toast(m, k) { try { showToast(m, k || 'ok'); } catch (e) {} }
    var LIST = [], WHICH = 'drop', ARMED = 0, FAILED = false;

    function view() { return document.getElementById('pkgc-view'); }
    function close() { var v = view(); if (v && v.parentNode) v.parentNode.removeChild(v); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') close(); }
    function item(c, i) {
        var title = c.label && c.label !== c.name ? c.label + ' · ' + c.name : c.name;
        return '<li class="pkgc-item"><div class="pkgc-t">' + esc(title) + '</div><div class="pkgc-p">' + esc(c.phone) + '</div><div class="pkgc-a">' + esc(c.address) + '</div>' +
            '<div class="pkgc-btns"><button type="button" class="pkgc-b pkgc-go"' + A('pkgContactUse', [i]) + '>' + esc(T('pkgc_use')) + '</button>' +
            '<button type="button" class="pkgc-b pkgc-del' + (ARMED === c.id ? ' armed' : '') + '"' + A('pkgContactDelete', [c.id]) + '>' + esc(T(ARMED === c.id ? 'pkgc_del2' : 'pkgc_del')) + '</button></div></li>';
    }
    function draw() {
        var v = view(); if (!v) return;
        var body = FAILED ? '<p class="pkgc-empty">' + esc(T('pkgc_fail')) + '</p>' : (LIST.length ? '<ul class="pkgc-list">' + LIST.map(item).join('') + '</ul>' : '<p class="pkgc-empty">' + esc(T('pkgc_empty')) + '</p>');
        v.innerHTML = '<div class="pkgc-in"><div class="pkgc-top"><h2 class="pkgc-h">' + esc(T('pkgc_title')) + '</h2><button type="button" class="pkgc-x" aria-label="' + esc(T('pkgc_close')) + '"' + A('pkgContactsClose') + '>✕</button></div>' +
            '<p class="pkgc-s">' + esc(T(WHICH === 'pickup' ? 'pkgc_use_pick' : 'pkgc_use_drop')) + '</p>' + body + '</div>';
    }
    function load(done) {
        api('customer_package_contacts', {}).then(function (r) {
            if (r && r.success && r.data && r.data.contacts) { LIST = r.data.contacts; FAILED = false; } else { FAILED = true; }
            if (done) done();
        }).catch(function () { FAILED = true; if (done) done(); });
    }
    window.pkgContactsOpen = function (which) {
        close(); WHICH = which === 'pickup' ? 'pickup' : 'drop'; ARMED = 0;
        var v = document.createElement('div');
        v.id = 'pkgc-view'; v.className = 'pkgc-view'; v.setAttribute('role', 'dialog'); v.setAttribute('aria-modal', 'true'); v.setAttribute('aria-label', T('pkgc_title'));
        v.innerHTML = '<div class="pkgc-in"><p class="pkgc-empty">' + esc(T('pkgc_loading')) + '</p></div>';
        document.body.appendChild(v);
        document.addEventListener('keydown', onKey);
        load(draw);
    };
    window.pkgContactsClose = close;
    window.pkgContactUse = function (i) { var c = LIST[i]; close(); if (c && window.pkgContactFill) window.pkgContactFill(WHICH, c); };
    window.pkgContactDelete = function (id) {
        if (ARMED !== id) { ARMED = id; draw(); setTimeout(function () { if (ARMED === id) { ARMED = 0; draw(); } }, 3500); return; }
        ARMED = 0;
        api('customer_package_contact_delete', { id: id }).then(function (r) {
            if (r && r.success) { LIST = LIST.filter(function (c) { return c.id !== id; }); toast(T('pkgc_deleted')); }
            draw();
        }).catch(function () { draw(); });
    };
    window.pkgContactSave = function (which) {
        var c = window.pkgContactCollect ? window.pkgContactCollect(which === 'pickup' ? 'pickup' : 'drop') : null;
        if (!c || !c.name || !c.phone || !c.address) { toast(T('pkgc_need'), 'err'); return; }
        api('customer_package_contact_save', c).then(function (r) {
            if (r && r.success) { toast(T('pkgc_saved')); } else { toast((r && r.message) || T('pkgc_err'), 'err'); }
        }).catch(function () { toast(T('pkgc_err'), 'err'); });
    };
    window.PkgContacts = {
        // the two small buttons for one side of the route
        buttons: function (which) {
            return '<div class="pkg-acts pkgc-acts"><button type="button" class="pkg-act"' + A('pkgContactsOpen', [which]) + '><span aria-hidden="true">📒</span> ' + esc(T('pkgc_btn')) + '</button>' +
                '<button type="button" class="pkg-act"' + A('pkgContactSave', [which]) + '><span aria-hidden="true">💾</span> ' + esc(T('pkgc_save')) + '</button></div>';
        }
    };
})();

/* ETEL - Send a package: the premium booking confirmation (waybill). Additive script, loaded before js/customer-package.js.
     PkgWaybill.html(wb, code, total)   the Etel-branded waybill card: tracking number, QR, route, details, the delivery timeline, Share / Copy / Receipt buttons
     PkgWaybill.mount(root, code)       draws the QR code in the card (the QR holds the tracking number; the courier or support can type or scan it)
   Buttons: pkgShare (phone share sheet, else copy), pkgCopy, pkgReceipt (a printable waybill in a new tab: Print or Save as PDF).
   wb = { from, to, vehicle, vclass, size, weight, km, eta, pay, recipient, desc }. Nothing here talks to the server. */
(function () {
    'use strict';
    if (window.PkgWaybill) return;
    ETEL_I18N.register('customer-package-wb', {
        en: { pkgw_brand: 'ETEL DELIVERY', pkgw_wb: 'Package waybill', pkgw_track_no: 'Tracking number', pkgw_from: 'From', pkgw_to: 'To', pkgw_to_who: 'Recipient',
            pkgw_vehicle: 'Vehicle', pkgw_pack: 'Package', pkgw_dist: 'Distance', pkgw_speed: 'Speed', pkgw_sp_standard: 'Standard', pkgw_sp_express: 'Express', pkgw_pay: 'Payment', pkgw_total: 'Total',
            pkgw_tl_t: 'Delivery timeline', pkgw_tl1: 'Package booked', pkgw_tl2: 'Courier on the way to pick it up', pkgw_tl3: 'Package picked up', pkgw_tl4: 'On the way to the recipient', pkgw_tl5: 'Delivered',
            pkgw_share: 'Share', pkgw_copy: 'Copy number', pkgw_receipt: 'Receipt / PDF', pkgw_copied: 'Tracking number copied',
            pkgw_msg: 'ETEL Delivery package {code}. From {from} to {to}. Track it in the ETEL app: ethelmarket.com/delivery', pkgw_eta: 'about {min} min', pkgw_kg: 'kg',
            pkgw_pay_cash: 'Cash', pkgw_pay_telebirr: 'Telebirr', pkgw_pay_wallet: 'Etel Pay wallet', pkgw_pay_chapa: 'Chapa (online)', pkgw_pay_abysinia: 'Abyssinia Bank', pkgw_pay_cbe: 'CBE',
            pkgw_print_fail: 'Could not open the receipt. Allow pop-ups and try again.', pkgw_pins: 'Keep your pickup and delivery PINs private. Only give them to the courier.' },
        am: { pkgw_brand: 'ኢትኤል ዴሊቨሪ', pkgw_wb: 'የጥቅል ደረሰኝ', pkgw_track_no: 'የመከታተያ ቁጥር', pkgw_from: 'ከ', pkgw_to: 'ወደ', pkgw_to_who: 'ተቀባይ',
            pkgw_vehicle: 'ተሽከርካሪ', pkgw_pack: 'ጥቅል', pkgw_dist: 'ርቀት', pkgw_speed: 'ፍጥነት', pkgw_sp_standard: 'መደበኛ', pkgw_sp_express: 'ፈጣን (Express)', pkgw_pay: 'ክፍያ', pkgw_total: 'ጠቅላላ',
            pkgw_tl_t: 'የመላኪያ ሂደት', pkgw_tl1: 'ጥቅሉ ተይዟል', pkgw_tl2: 'መልእክተኛው ሊወስድ በመንገድ ላይ ነው', pkgw_tl3: 'ጥቅሉ ተወስዷል', pkgw_tl4: 'ወደ ተቀባዩ በመንገድ ላይ', pkgw_tl5: 'ደርሷል',
            pkgw_share: 'አጋራ', pkgw_copy: 'ቁጥር ቅዳ', pkgw_receipt: 'ደረሰኝ / PDF', pkgw_copied: 'የመከታተያ ቁጥሩ ተቀድቷል',
            pkgw_msg: 'የኢትኤል ዴሊቨሪ ጥቅል {code}። ከ {from} ወደ {to}። በኢትኤል መተግበሪያ ይከታተሉ: ethelmarket.com/delivery', pkgw_eta: 'ወደ {min} ደቂቃ', pkgw_kg: 'ኪ.ግ',
            pkgw_pay_cash: 'ጥሬ ገንዘብ', pkgw_pay_telebirr: 'ቴሌብር', pkgw_pay_wallet: 'የኢትኤል ፔይ ዋሌት', pkgw_pay_chapa: 'Chapa (ኦንላይን)', pkgw_pay_abysinia: 'አቢሲንያ ባንክ', pkgw_pay_cbe: 'ንግድ ባንክ',
            pkgw_print_fail: 'ደረሰኙን መክፈት አልተቻለም። ብቅ-ባይ ፈቅደው እንደገና ይሞክሩ።', pkgw_pins: 'የመረከቢያና የማስረከቢያ ፒንዎን በሚስጥር ይያዙ። ለመልእክተኛው ብቻ ይስጡ።' }
    });
    function T(k) { return ETEL_I18N.t(k); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    var LAST = null;   // the booking on screen: { wb, code, total }

    function payName(m) { var k = 'pkgw_pay_' + m; var v = T(k); return (!v || v === k) ? m : v; }
    function pic(wb) { try { if (window.EtelVehiclePic && EtelVehiclePic.key(wb.vclass || wb.vehicle)) return EtelVehiclePic.html(wb.vclass || wb.vehicle, 56); } catch (e) {} return esc(wb.icon || '🚚'); }
    function row(k, v) { return '<div class="pkgw-row"><span class="pkgw-k">' + esc(T(k)) + '</span><span class="pkgw-v">' + esc(v) + '</span></div>'; }
    function timeline() {
        var h = '<ol class="pkgw-tl" aria-label="' + esc(T('pkgw_tl_t')) + '">';
        for (var i = 1; i <= 5; i++) h += '<li class="pkgw-st' + (i === 1 ? ' done' : '') + '"><span class="pkgw-dot" aria-hidden="true">' + (i === 1 ? '✓' : '') + '</span><span>' + esc(T('pkgw_tl' + i)) + '</span></li>';
        return h + '</ol>';
    }
    function html(wb, code, total) {
        LAST = { wb: wb || {}, code: code, total: total };
        wb = LAST.wb;
        return '<section class="pkgw" aria-label="' + esc(T('pkgw_wb')) + '">' +
            '<div class="pkgw-top"><span class="pkgw-brand">' + esc(T('pkgw_brand')) + '</span><span class="pkgw-tag">' + esc(T('pkgw_wb')) + '</span></div>' +
            '<div class="pkgw-id"><div><div class="pkgw-k">' + esc(T('pkgw_track_no')) + '</div><div class="pkgw-code">' + esc(code) + '</div></div><div id="pkgw-qr" class="pkgw-qr" aria-hidden="true"></div></div>' +
            '<div class="pkgw-body">' +
            '<div class="pkgw-route"><div class="pkgw-pt"><span class="pkgw-d a">A</span><div><div class="pkgw-k">' + esc(T('pkgw_from')) + '</div><div class="pkgw-addr">' + esc(wb.from) + '</div></div></div>' +
            '<div class="pkgw-pt"><span class="pkgw-d b">B</span><div><div class="pkgw-k">' + esc(T('pkgw_to')) + '</div><div class="pkgw-addr">' + esc(wb.to) + '</div></div></div></div>' +
            '<div class="pkgw-veh"><span class="pkgw-vp" aria-hidden="true">' + pic(wb) + '</span><span class="pkgw-vn">' + esc(wb.vehicle) + '</span></div>' +
            row('pkgw_to_who', wb.recipient) + row('pkgw_pack', wb.size + ' · ' + wb.weight + ' ' + T('pkgw_kg') + ' · ' + wb.desc) +
            row('pkgw_dist', wb.km + ' km · ' + T('pkgw_eta').replace('{min}', wb.eta)) + row('pkgw_speed', T(wb.speed === 'express' ? 'pkgw_sp_express' : 'pkgw_sp_standard')) + row('pkgw_pay', payName(wb.pay)) +
            '<div class="pkgw-total"><span>' + esc(T('pkgw_total')) + '</span><span>ETB ' + esc(total) + '</span></div></div>' +
            '<div class="pkgw-sec"><h3 class="pkgw-h">' + esc(T('pkgw_tl_t')) + '</h3>' + timeline() + '</div>' +
            '<div class="pkgw-btns"><button type="button" class="pkgw-b"' + EtelActions.attrs('pkgShare') + '>' + esc(T('pkgw_share')) + '</button>' +
            '<button type="button" class="pkgw-b"' + EtelActions.attrs('pkgCopy') + '>' + esc(T('pkgw_copy')) + '</button>' +
            '<button type="button" class="pkgw-b"' + EtelActions.attrs('pkgReceipt') + '>' + esc(T('pkgw_receipt')) + '</button></div>' +
            '<p class="pkgw-note">🔐 ' + esc(T('pkgw_pins')) + '</p></section>';
    }
    function qr(el, text, px) {
        if (!el || typeof QRCode === 'undefined') return false;
        try { el.innerHTML = ''; new QRCode(el, { text: String(text), width: px, height: px, colorDark: '#0B1530', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M }); return true; } catch (e) { return false; }
    }
    function mount(root, code) { var el = (root || document).querySelector('#pkgw-qr'); if (el && !el.firstChild) qr(el, code, 84); }
    function text() {
        var w = LAST.wb;
        return T('pkgw_msg').replace('{code}', LAST.code).replace('{from}', w.from).replace('{to}', w.to);
    }
    function toast(m, kind) { try { showToast(m, kind || 'ok'); } catch (e) {} }
    window.pkgCopy = function () {
        if (!LAST) return;
        var done = function () { toast(T('pkgw_copied')); };
        try { if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(LAST.code).then(done, done); return; } } catch (e) {}
        try { var t = document.createElement('textarea'); t.value = LAST.code; document.body.appendChild(t); t.select(); document.execCommand('copy'); document.body.removeChild(t); } catch (e2) {}
        done();
    };
    window.pkgShare = function () {
        if (!LAST) return;
        try { if (navigator.share) { navigator.share({ title: 'ETEL Delivery', text: text() }).catch(function () {}); return; } } catch (e) {}
        window.pkgCopy();
    };
    window.pkgReceipt = function () {
        if (!LAST) return;
        var w = LAST.wb, holder = document.createElement('div'), img = '';
        holder.style.cssText = 'position:fixed;left:-999px;top:0';
        document.body.appendChild(holder);
        if (qr(holder, LAST.code, 120)) { var c = holder.querySelector('canvas'), i = holder.querySelector('img'); try { img = c ? c.toDataURL('image/png') : (i ? i.src : ''); } catch (e) {} }
        document.body.removeChild(holder);
        var rows = [['pkgw_from', w.from], ['pkgw_to', w.to], ['pkgw_to_who', w.recipient], ['pkgw_vehicle', w.vehicle], ['pkgw_pack', w.size + ' - ' + w.weight + ' ' + T('pkgw_kg') + ' - ' + w.desc], ['pkgw_dist', w.km + ' km'], ['pkgw_speed', T(w.speed === 'express' ? 'pkgw_sp_express' : 'pkgw_sp_standard')], ['pkgw_pay', payName(w.pay)]];
        var d = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' + esc(LAST.code) + '</title><style>' +
            'body{font-family:Arial,Helvetica,sans-serif;margin:0;padding:18px;color:#0B1530}.c{max-width:420px;margin:0 auto;border:2px solid #0B1530;border-radius:14px;overflow:hidden}' +
            '.t{background:#0B1530;color:#D4AF37;padding:14px 16px;display:flex;justify-content:space-between;font-weight:800;letter-spacing:.08em}' +
            '.id{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px dashed #999}.k{font-size:11px;text-transform:uppercase;color:#666;letter-spacing:.05em}' +
            '.n{font-size:24px;font-weight:800;letter-spacing:1px}.r{display:flex;justify-content:space-between;gap:12px;padding:8px 16px;border-bottom:1px solid #eee;font-size:14px}.r b{text-align:right}' +
            '.tt{display:flex;justify-content:space-between;padding:14px 16px;font-size:20px;font-weight:800;background:#FFF4CC}.f{padding:10px 16px;font-size:11px;color:#666}' +
            '.b{display:block;margin:16px auto 0;padding:12px 26px;border:0;border-radius:10px;background:#D4AF37;font-weight:800;font-size:15px}@media print{.b{display:none}}</style></head><body><div class="c">' +
            '<div class="t"><span>' + esc(T('pkgw_brand')) + '</span><span>' + esc(T('pkgw_wb')) + '</span></div>' +
            '<div class="id"><div><div class="k">' + esc(T('pkgw_track_no')) + '</div><div class="n">' + esc(LAST.code) + '</div></div>' + (img ? '<img src="' + img + '" width="96" height="96" alt="">' : '') + '</div>' +
            rows.map(function (r) { return '<div class="r"><span>' + esc(T(r[0])) + '</span><b>' + esc(r[1]) + '</b></div>'; }).join('') +
            '<div class="tt"><span>' + esc(T('pkgw_total')) + '</span><span>ETB ' + esc(LAST.total) + '</span></div>' +
            '<div class="f">' + esc(new Date().toLocaleString()) + ' - ethelmarket.com/delivery</div></div><button class="b" id="pr">' + esc(T('pkgw_receipt')) + '</button>' +
            '<script>document.getElementById("pr").onclick=function(){window.print()};</script></body></html>';
        var win = null;
        try { win = window.open('', '_blank'); } catch (e3) {}
        if (!win) { toast(T('pkgw_print_fail'), 'err'); return; }
        win.document.open(); win.document.write(d); win.document.close();
    };
    window.PkgWaybill = { html: html, mount: mount };
})();

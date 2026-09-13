/* ════════════════════════════════════════════════════════════════
   ETEL ADMIN — direct Bluetooth (BLE) thermal printer support
   Same approach as delivery/js/servicegiver.js's BLEPrinter: talks
   straight to a paired 58mm ESC/POS printer (e.g. P58E) over Web
   Bluetooth, no bridge app, no injected ads. This is the admin-side
   printer - always ETEL Delivery branded (not a specific business),
   used for order receipts, daily report summaries, and service
   giver/courier verification slips.
   Load AFTER js/core.js. Exposes window.AdminPrinter.
   ════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    const GATT_CANDIDATES = [
        '000018f0-0000-1000-8000-00805f9b34fb', // common clone "printer service"
        '49535343-fe7d-4ae5-8fa9-9fafd205e455', // ISSC/Microchip transparent UART
        'e7810a71-73ae-499d-8c15-faa9aef0c3f2'  // seen on some P58E units
    ];
    const DEVICE_ID_KEY = 'etel_admin_printer_device_id';
    let cachedDevice = null;
    let cachedChar = null;

    function isSupported() { return !!(navigator.bluetooth); }

    async function pickDevice() {
        if (navigator.bluetooth.getDevices) {
            try {
                const known = await navigator.bluetooth.getDevices();
                const savedId = localStorage.getItem(DEVICE_ID_KEY);
                const match = known.find(d => d.id === savedId) || known.find(d => (d.name || '').toUpperCase().indexOf('P58E') !== -1);
                if (match) return match;
            } catch (e) { /* fall through to picker */ }
        }
        const device = await navigator.bluetooth.requestDevice({
            acceptAllDevices: true,
            optionalServices: GATT_CANDIDATES
        });
        localStorage.setItem(DEVICE_ID_KEY, device.id);
        return device;
    }

    async function findWritableCharacteristic(server) {
        const services = await server.getPrimaryServices();
        for (const svc of services) {
            const chars = await svc.getCharacteristics();
            for (const ch of chars) {
                if (ch.properties && (ch.properties.write || ch.properties.writeWithoutResponse)) return ch;
            }
        }
        return null;
    }

    async function connect() {
        if (cachedChar && cachedDevice && cachedDevice.gatt.connected) return cachedChar;
        const device = cachedDevice || await pickDevice();
        const server = await device.gatt.connect();
        const char = await findWritableCharacteristic(server);
        if (!char) throw new Error('Printer has no writable Bluetooth channel');
        cachedDevice = device;
        cachedChar = char;
        return char;
    }

    function forget() {
        cachedDevice = null;
        cachedChar = null;
        localStorage.removeItem(DEVICE_ID_KEY);
    }

    async function sendBytes(bytes) {
        const char = await connect();
        const chunkSize = 20; // conservative default BLE MTU
        for (let i = 0; i < bytes.length; i += chunkSize) {
            const chunk = bytes.slice(i, i + chunkSize);
            if (char.properties.writeWithoutResponse) {
                await char.writeValueWithoutResponse(chunk);
            } else {
                await char.writeValue(chunk);
            }
            await new Promise(r => setTimeout(r, 8)); // pace writes for image payloads
        }
    }

    // ESC/POS byte builder --------------------------------------------
    function esc(bytes, ...vals) { vals.forEach(v => bytes.push(v)); }
    function text(bytes, str) { bytes.push(...new TextEncoder().encode(str)); }
    function append(bytes, arr) { for (let i = 0; i < arr.length; i++) bytes.push(arr[i]); }
    const DASH = '--------------------------------\n';

    // Same Floyd-Steinberg dithering as servicegiver.js's BLEPrinter -
    // a flat threshold turns the gradient-heavy ETEL coin logo into a
    // solid black blob; dithering diffuses rounding error into
    // neighboring pixels so mid-tones survive as a dot pattern.
    function imageUrlToRasterBytes(url, maxWidthPx) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => {
                try {
                    const scale = Math.min(1, maxWidthPx / img.naturalWidth);
                    const rawW = Math.max(8, Math.round(img.naturalWidth * scale));
                    const w = Math.ceil(rawW / 8) * 8;
                    const h = Math.max(1, Math.round(img.naturalHeight * scale));

                    const canvas = document.createElement('canvas');
                    canvas.width = w; canvas.height = h;
                    const ctx = canvas.getContext('2d');
                    ctx.fillStyle = '#fff';
                    ctx.fillRect(0, 0, w, h);
                    ctx.drawImage(img, 0, 0, w, h);
                    const px = ctx.getImageData(0, 0, w, h).data;

                    const gray = new Float32Array(w * h);
                    for (let y = 0; y < h; y++) {
                        for (let x = 0; x < w; x++) {
                            const i = (y * w + x) * 4;
                            const lum = 0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2];
                            const a = px[i + 3] / 255;
                            gray[y * w + x] = lum * a + 255 * (1 - a);
                        }
                    }

                    const widthBytes = w / 8;
                    const bitmap = new Uint8Array(widthBytes * h);
                    for (let y = 0; y < h; y++) {
                        for (let x = 0; x < w; x++) {
                            const idx = y * w + x;
                            const old = gray[idx];
                            const isBlack = old < 128;
                            if (isBlack) bitmap[y * widthBytes + (x >> 3)] |= (0x80 >> (x & 7));
                            const err = old - (isBlack ? 0 : 255);
                            if (x + 1 < w) gray[idx + 1] += err * 7 / 16;
                            if (y + 1 < h) {
                                if (x > 0) gray[idx - 1 + w] += err * 3 / 16;
                                gray[idx + w] += err * 5 / 16;
                                if (x + 1 < w) gray[idx + 1 + w] += err * 1 / 16;
                            }
                        }
                    }

                    const header = [0x1D, 0x76, 0x30, 0x00, widthBytes & 0xFF, (widthBytes >> 8) & 0xFF, h & 0xFF, (h >> 8) & 0xFF];
                    const out = new Uint8Array(header.length + bitmap.length);
                    out.set(header, 0);
                    out.set(bitmap, header.length);
                    resolve(out);
                } catch (e) { reject(e); }
            };
            img.onerror = () => reject(new Error('Could not load logo image'));
            img.src = url;
        });
    }

    function platformLogoUrl() {
        return new URL('assets/images/etel-delivery-coin-crop.png', window.location.href).href;
    }

    async function writeBrandHeader(bytes, title) {
        esc(bytes, 0x1B, 0x61, 0x01); // center
        try {
            append(bytes, await imageUrlToRasterBytes(platformLogoUrl(), 160));
            text(bytes, '\n');
        } catch (e) { console.error('Platform logo print skipped:', e); }
        esc(bytes, 0x1B, 0x45, 0x01);
        text(bytes, 'ETEL DELIVERY\n');
        esc(bytes, 0x1B, 0x45, 0x00);
        text(bytes, title + '\n');
        text(bytes, DASH);
    }

    async function writeBrandFooter(bytes) {
        esc(bytes, 0x1B, 0x61, 0x01);
        text(bytes, 'admin.ethelmarket.com\n');
        text(bytes, '\n\n\n');
        esc(bytes, 0x1D, 0x56, 0x00); // cut (ignored harmlessly if no cutter)
    }

    // ── Order receipt ────────────────────────────────────────────────
    async function buildOrderReceiptBytes(order, items, businessName) {
        const bytes = [];
        esc(bytes, 0x1B, 0x40);
        await writeBrandHeader(bytes, 'ORDER RECEIPT');

        esc(bytes, 0x1B, 0x61, 0x00); // left
        const placedTime = order.placed_at ? new Date(order.placed_at).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '';
        text(bytes, (order.order_code || '') + '\n');
        if (placedTime) text(bytes, placedTime + '\n');
        if (businessName) text(bytes, 'From: ' + businessName + '\n');
        if (order.from_zone || order.to_zone) text(bytes, (order.from_zone || '') + (order.to_zone ? ' -> ' + order.to_zone : '') + '\n');
        text(bytes, 'Status: ' + (order.status || '').toUpperCase() + '\n');
        text(bytes, DASH);

        const list = items || [];
        if (list.length) {
            list.forEach(it => {
                const price = parseFloat(it.subtotal != null ? it.subtotal : (it.unit_price || 0) * (it.quantity || 1));
                text(bytes, (it.quantity || 1) + 'x ' + (it.item_name || '') + '\n');
                text(bytes, '   Br ' + price.toFixed(2) + '\n');
            });
        } else {
            text(bytes, 'No itemized items\n');
        }
        text(bytes, DASH);

        esc(bytes, 0x1D, 0x21, 0x01);
        esc(bytes, 0x1B, 0x45, 0x01);
        text(bytes, 'TOTAL: Br ' + parseFloat(order.fare_total || 0).toFixed(2) + '\n');
        esc(bytes, 0x1D, 0x21, 0x00);
        esc(bytes, 0x1B, 0x45, 0x00);
        text(bytes, DASH);

        if (order.recipient_name || order.recipient_phone) {
            esc(bytes, 0x1B, 0x45, 0x01);
            if (order.recipient_name) text(bytes, 'FOR: ' + order.recipient_name + '\n');
            if (order.recipient_phone) text(bytes, 'TEL: ' + order.recipient_phone + '\n');
            esc(bytes, 0x1B, 0x45, 0x00);
            text(bytes, DASH);
        }
        if (order.notes) { text(bytes, 'NOTE: ' + order.notes + '\n'); text(bytes, DASH); }

        await writeBrandFooter(bytes);
        return new Uint8Array(bytes);
    }

    // ── Daily summary ────────────────────────────────────────────────
    async function buildDailySummaryBytes(stats, dateLabel) {
        const bytes = [];
        esc(bytes, 0x1B, 0x40);
        await writeBrandHeader(bytes, 'DAILY SUMMARY');

        esc(bytes, 0x1B, 0x61, 0x01);
        text(bytes, (dateLabel || new Date().toLocaleDateString()) + '\n');
        text(bytes, DASH);

        esc(bytes, 0x1B, 0x61, 0x00);
        const rows = [
            ['Total Orders', stats.orders],
            ['Pending', stats.pending],
            ['Delivered', stats.delivered],
            ['Couriers', stats.couriers],
            ['Service Givers', stats.service_givers],
            ['Users', stats.users]
        ];
        rows.forEach(([label, val]) => {
            if (val === undefined || val === null) return;
            text(bytes, label + ': ' + Number(val).toLocaleString() + '\n');
        });
        text(bytes, DASH);

        esc(bytes, 0x1D, 0x21, 0x01);
        esc(bytes, 0x1B, 0x45, 0x01);
        text(bytes, 'REVENUE: Br ' + Number(stats.revenue || 0).toLocaleString() + '\n');
        esc(bytes, 0x1D, 0x21, 0x00);
        esc(bytes, 0x1B, 0x45, 0x00);
        text(bytes, DASH);

        await writeBrandFooter(bytes);
        return new Uint8Array(bytes);
    }

    // ── Verification / ID slip (service giver or courier) ───────────
    async function buildVerificationSlipBytes(entity) {
        // entity: { kind: 'Service Giver'|'Courier', code, name, subtitle,
        //           zone, phone, statusLabel }
        const bytes = [];
        esc(bytes, 0x1B, 0x40);
        await writeBrandHeader(bytes, (entity.kind || 'PARTNER').toUpperCase() + ' SLIP');

        esc(bytes, 0x1B, 0x61, 0x01);
        esc(bytes, 0x1D, 0x21, 0x11); // double height+width - short codes/names fit fine
        esc(bytes, 0x1B, 0x45, 0x01);
        text(bytes, (entity.code || '-') + '\n');
        esc(bytes, 0x1D, 0x21, 0x00);
        esc(bytes, 0x1B, 0x45, 0x00);
        text(bytes, DASH);

        esc(bytes, 0x1B, 0x61, 0x00);
        esc(bytes, 0x1B, 0x45, 0x01);
        text(bytes, (entity.name || '') + '\n');
        esc(bytes, 0x1B, 0x45, 0x00);
        if (entity.subtitle) text(bytes, entity.subtitle + '\n');
        if (entity.zone) text(bytes, 'Zone: ' + entity.zone + '\n');
        if (entity.phone) text(bytes, 'Tel: ' + entity.phone + '\n');
        if (entity.statusLabel) text(bytes, 'Status: ' + entity.statusLabel.toUpperCase() + '\n');
        text(bytes, DASH);

        await writeBrandFooter(bytes);
        return new Uint8Array(bytes);
    }

    // ── Browser-print (window.print) fallback --------------------------
    function openPrintWindow(title, bodyHtml) {
        const html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' + title + '</title>' +
            '<style>' +
            '  @page { size: 80mm auto; margin: 4mm; }' +
            '  body { font-family: "Courier New", monospace; width: 72mm; margin: 0; color: #000; }' +
            '  .logo { display: block; max-width: 70px; max-height: 70px; margin: 0 auto 4px; object-fit: contain; }' +
            '  .brand { text-align: center; font-size: 13px; font-weight: 900; letter-spacing: 2px; }' +
            '  h1 { font-size: 16px; margin: 2px 0 0; text-align: center; letter-spacing: 1px; font-weight: 900; }' +
            '  .rule { border: none; border-top: 2px dashed #000; margin: 8px 0; }' +
            '  .row { font-size: 13px; margin: 3px 0; }' +
            '  table { width: 100%; border-collapse: collapse; }' +
            '  td { padding: 4px 2px; font-size: 15px; font-weight: 700; }' +
            '  .price { text-align: right; }' +
            '  .total { font-size: 18px; font-weight: 900; text-align: center; margin: 6px 0; }' +
            '  .footer { text-align: center; font-size: 9px; font-weight: 700; letter-spacing: 1px; color: #555; margin-top: 10px; }' +
            '</style></head><body>' +
            '<img class="logo" src="' + platformLogoUrl() + '">' +
            '<div class="brand">ETEL DELIVERY</div>' +
            bodyHtml +
            '<div class="footer">admin.ethelmarket.com</div>' +
            '<script>window.onload = function () { window.print(); };<\/script>' +
            '</body></html>';
        const win = window.open('', '_blank', 'width=380,height=600');
        if (!win) { if (window.toast) toast('Please allow pop-ups to print.', 'err'); return; }
        win.document.write(html);
        win.document.close();
    }

    function esc_html(str) {
        const div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function orderReceiptFallbackHtml(order, items, businessName) {
        const rows = (items || []).map(it => {
            const price = parseFloat(it.subtotal != null ? it.subtotal : (it.unit_price || 0) * (it.quantity || 1));
            return '<tr><td>' + (it.quantity || 1) + 'x ' + esc_html(it.item_name || '') + '</td><td class="price">Br ' + price.toFixed(2) + '</td></tr>';
        }).join('') || '<tr><td colspan="2">No itemized items</td></tr>';
        const placedTime = order.placed_at ? new Date(order.placed_at).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '';
        return '<h1>ORDER RECEIPT</h1><hr class="rule">' +
            '<div class="row"><strong>' + esc_html(order.order_code || '') + '</strong></div>' +
            (placedTime ? '<div class="row">' + placedTime + '</div>' : '') +
            (businessName ? '<div class="row">From: ' + esc_html(businessName) + '</div>' : '') +
            ((order.from_zone || order.to_zone) ? '<div class="row">' + esc_html(order.from_zone || '') + (order.to_zone ? ' &rarr; ' + esc_html(order.to_zone) : '') + '</div>' : '') +
            '<div class="row">Status: ' + esc_html((order.status || '').toUpperCase()) + '</div>' +
            '<hr class="rule"><table>' + rows + '</table><hr class="rule">' +
            '<div class="total">TOTAL: Br ' + parseFloat(order.fare_total || 0).toFixed(2) + '</div><hr class="rule">' +
            (order.recipient_name ? '<div class="row">For: ' + esc_html(order.recipient_name) + '</div>' : '') +
            (order.recipient_phone ? '<div class="row">Tel: ' + esc_html(order.recipient_phone) + '</div>' : '') +
            (order.notes ? '<div class="row">Note: ' + esc_html(order.notes) + '</div>' : '');
    }

    function dailySummaryFallbackHtml(stats, dateLabel) {
        const rows = [
            ['Total Orders', stats.orders], ['Pending', stats.pending], ['Delivered', stats.delivered],
            ['Couriers', stats.couriers], ['Service Givers', stats.service_givers], ['Users', stats.users]
        ].filter(([, v]) => v !== undefined && v !== null)
         .map(([l, v]) => '<div class="row">' + l + ': ' + Number(v).toLocaleString() + '</div>').join('');
        return '<h1>DAILY SUMMARY</h1><hr class="rule">' +
            '<div class="row" style="text-align:center">' + esc_html(dateLabel || new Date().toLocaleDateString()) + '</div>' +
            '<hr class="rule">' + rows + '<hr class="rule">' +
            '<div class="total">REVENUE: Br ' + Number(stats.revenue || 0).toLocaleString() + '</div>';
    }

    function verificationSlipFallbackHtml(entity) {
        return '<h1>' + esc_html((entity.kind || 'PARTNER').toUpperCase()) + ' SLIP</h1><hr class="rule">' +
            '<div class="total">' + esc_html(entity.code || '-') + '</div><hr class="rule">' +
            '<div class="row" style="text-align:center;font-weight:900">' + esc_html(entity.name || '') + '</div>' +
            (entity.subtitle ? '<div class="row" style="text-align:center">' + esc_html(entity.subtitle) + '</div>' : '') +
            (entity.zone ? '<div class="row">Zone: ' + esc_html(entity.zone) + '</div>' : '') +
            (entity.phone ? '<div class="row">Tel: ' + esc_html(entity.phone) + '</div>' : '') +
            (entity.statusLabel ? '<div class="row">Status: ' + esc_html(entity.statusLabel.toUpperCase()) + '</div>' : '');
    }

    // ── Public API: BLE first, browser print dialog as fallback ──────
    async function printOrderReceipt(order, items, businessName) {
        if (isSupported()) {
            try { await sendBytes(await buildOrderReceiptBytes(order, items, businessName)); return; }
            catch (e) { console.error('BLE print failed, falling back:', e); if (window.toast) toast('Direct printer failed - opening print dialog.', 'err'); }
        }
        openPrintWindow('Order ' + (order.order_code || ''), orderReceiptFallbackHtml(order, items, businessName));
    }

    async function printDailySummary(stats, dateLabel) {
        if (isSupported()) {
            try { await sendBytes(await buildDailySummaryBytes(stats, dateLabel)); return; }
            catch (e) { console.error('BLE print failed, falling back:', e); if (window.toast) toast('Direct printer failed - opening print dialog.', 'err'); }
        }
        openPrintWindow('Daily Summary', dailySummaryFallbackHtml(stats, dateLabel));
    }

    async function printVerificationSlip(entity) {
        if (isSupported()) {
            try { await sendBytes(await buildVerificationSlipBytes(entity)); return; }
            catch (e) { console.error('BLE print failed, falling back:', e); if (window.toast) toast('Direct printer failed - opening print dialog.', 'err'); }
        }
        openPrintWindow(entity.kind + ' ' + (entity.code || ''), verificationSlipFallbackHtml(entity));
    }

    window.AdminPrinter = {
        isSupported, connect, forget,
        printOrderReceipt, printDailySummary, printVerificationSlip
    };
})();

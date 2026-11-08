/* ETEL - ask a courier or driver for three photos of their vehicle (front, side, back)
 *
 * Added to the Courier app (release v15) and the Driver app (release v27) as a readable add-on, built by tools/build_courier_release.py and
 * tools/build_driver_release.py. It does nothing for anyone but a signed-in courier or driver whose vehicle pictures are not complete yet.
 * After a short wait it asks the server (vehicle_photos_get) and, only if pictures are missing, shows one friendly sheet with three picture boxes.
 * "Later" hides it for a day; it never blocks the app. Pictures are uploaded with api/upload.php (types vehicle_front, vehicle_side, vehicle_back)
 * and saved with vehicle_photos_save. Only plain browser features and the app's own Auth / ETEL globals are used.
 */
(function () {
    'use strict';
    if (window.__etelVehiclePhotosPrompt) return;
    window.__etelVehiclePhotosPrompt = 'v1';

    var KEY_SNOOZE = 'etel_vp_snooze';
    var SIDES = [['front', 'vehicle_front'], ['side', 'vehicle_side'], ['back', 'vehicle_back']];
    var TXT = {
        en: { title: 'Photos of your vehicle', sub: 'Take one clear photo of the front, the side and the back of your vehicle. ETEL checks them before you start working.', front: 'Front', side: 'Side', back: 'Back', tap: 'Tap to take a photo', change: 'Change', up: 'Uploading...', save: 'Save photos', later: 'Later', saving: 'Saving...', done: 'Thank you. Your vehicle photos are saved.', need: 'Add all three photos first.', fail: 'Could not save. Check your connection and try again.', wait: 'Wait for the photos to finish uploading.' },
        am: { title: '\u12e8\u1270\u123d\u12a8\u122d\u12ab\u122a \u134e\u1276\u12ce\u127d', sub: '\u12e8\u1270\u123d\u12a8\u122d\u12ab\u122a\u12ce\u1295 \u134a\u1275\u1363 \u130e\u1295 \u12a5\u1293 \u1300\u122d\u1263 \u12a0\u1295\u12f5 \u12a0\u1295\u12f5 \u130d\u120d\u133d \u134e\u1276 \u12eb\u1295\u1231\u1362 \u1225\u122b \u12a8\u1218\u1300\u1218\u122d\u12ce \u1260\u134a\u1275 \u12a2\u1274\u120d \u12ed\u1218\u1208\u12a8\u1273\u1278\u12cb\u120d\u1362', front: '\u134a\u1275', side: '\u130e\u1295', back: '\u1300\u122d\u1263', tap: '\u134e\u1276 \u1208\u121b\u1295\u1233\u1275 \u12ed\u1295\u12a9', change: '\u1240\u12ed\u122d', up: '\u1260\u1218\u132b\u1295 \u120b\u12ed...', save: '\u134e\u1276\u12ce\u127d\u1295 \u12a0\u1235\u1240\u121d\u1325', later: '\u1260\u128b\u120b', saving: '\u1260\u121b\u1235\u1240\u1218\u1325 \u120b\u12ed...', done: '\u12a0\u1218\u1230\u130d\u1293\u1208\u1201\u1362 \u134e\u1276\u12ce\u127d\u12ce \u1270\u1240\u121d\u1320\u12cb\u120d\u1362', need: '\u1226\u1235\u1271\u1295\u121d \u134e\u1276\u12ce\u127d \u12eb\u12ad\u1209\u1362', fail: '\u12a0\u120d\u1270\u127b\u1208\u121d\u1362 \u130d\u1295\u1299\u1290\u1275\u12ce\u1295 \u12a0\u12ed\u1270\u12cd \u12f0\u130d\u1218\u12cd \u12ed\u121e\u12ad\u1229\u1362', wait: '\u134e\u1276\u12ce\u1279 \u12a5\u1235\u12aa\u132b\u1291 \u12ed\u1320\u1265\u1241\u1362' }
    };
    function lang() {
        var l = '';
        try { l = (localStorage.getItem('etel_lang') || document.documentElement.lang || '').toLowerCase(); } catch (e) { /* default English */ }
        return l.indexOf('am') === 0 ? 'am' : 'en';
    }
    function tx(k) { return (TXT[lang()] || TXT.en)[k] || TXT.en[k]; }
    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

    function token() { try { return (window.Auth && Auth.getToken && Auth.getToken()) || localStorage.getItem('etel_token') || ''; } catch (e) { return ''; } }
    function me() { try { return (window.Auth && Auth.getUser && Auth.getUser()) || JSON.parse(localStorage.getItem('etel_user') || 'null'); } catch (e) { return null; } }
    function apiUrl() { try { if (typeof ETEL !== 'undefined' && ETEL.api) return ETEL.api; } catch (e) { /* fall through */ } return '/delivery/api/boot.php'; }
    function post(action, body) {
        var b = { action: action }; for (var k in body) if (Object.prototype.hasOwnProperty.call(body, k)) b[k] = body[k];
        return fetch(apiUrl(), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() }, body: JSON.stringify(b) })
            .then(function (r) { return r.json(); });
    }
    function upload(file, type) {
        var fd = new FormData(); fd.append('image', file); fd.append('type', type);
        return fetch(apiUrl().replace('boot.php', 'upload.php'), { method: 'POST', headers: { 'Authorization': 'Bearer ' + token() }, body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (!j || !j.success || !j.data) throw new Error((j && j.message) || 'Upload failed'); return j.data.url; });
    }
    function snoozed() { try { return parseInt(localStorage.getItem(KEY_SNOOZE) || '0', 10) > Date.now(); } catch (e) { return false; } }
    function snooze() { try { localStorage.setItem(KEY_SNOOZE, String(Date.now() + 24 * 3600 * 1000)); } catch (e) { /* asked again next time */ } }

    var CSS = '.etelvp{position:fixed;inset:0;z-index:99990;background:rgba(0,0,0,.72);display:flex;align-items:flex-end;justify-content:center;padding:0}' +
        '.etelvp .vp-card{width:min(520px,100%);max-height:92vh;overflow:auto;background:#17171f;color:#f5f5f7;border-radius:20px 20px 0 0;border:1px solid rgba(212,175,55,.35);padding:20px 16px 18px;font-family:inherit}' +
        'body.etel-light .etelvp .vp-card{background:#fff;color:#1a1a2e}' +
        '.etelvp h3{margin:0 0 6px;font-size:19px}.etelvp p{margin:0 0 14px;font-size:13.5px;line-height:1.5;opacity:.8}' +
        '.etelvp .vp-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}' +
        '.etelvp .vp-box{min-height:112px;border-radius:14px;border:2px dashed rgba(212,175,55,.5);background:rgba(212,175,55,.06) center/cover no-repeat;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;text-align:center;padding:6px;cursor:pointer;position:relative;overflow:hidden;font-size:11.5px;color:inherit}' +
        '.etelvp .vp-box b{font-size:14px}.etelvp .vp-box.has{border-style:solid}' +
        '.etelvp .vp-chg{position:absolute;left:6px;bottom:6px;padding:3px 8px;border-radius:99px;background:rgba(0,0,0,.65);color:#fff;font-size:11px}' +
        '.etelvp .vp-msg{min-height:18px;margin:10px 0 0;font-size:12.5px;color:#e8a33d}' +
        '.etelvp .vp-acts{display:flex;gap:10px;margin-top:12px}.etelvp .vp-acts button{flex:1;min-height:46px;border-radius:12px;border:1px solid rgba(212,175,55,.5);background:transparent;color:inherit;font:inherit;font-weight:700;cursor:pointer}' +
        '.etelvp .vp-acts .vp-go{background:#D4AF37;color:#1a1a2e;border-color:#D4AF37}.etelvp .vp-acts button:disabled{opacity:.5}';

    var S = { urls: { front: '', side: '', back: '' }, prev: {}, busy: 0, el: null };

    function boxes() {
        return SIDES.map(function (sd) {
            var k = sd[0], has = !!S.prev[k];
            return '<div><div class="vp-box' + (has ? ' has' : '') + '" data-vp="' + k + '" tabindex="0" role="button" aria-label="' + esc(tx(k)) + '"' + (has ? ' style="background-image:url(\'' + esc(S.prev[k]) + '\')"' : '') + '>' +
                (has ? '<span class="vp-chg">' + esc(S.urls[k] ? tx('change') : tx('up')) + '</span>' : '<b>' + esc(tx(k)) + '</b><span>' + esc(tx('tap')) + '</span>') + '</div>' +
                '<input type="file" accept="image/*" capture="environment" hidden data-vpf="' + k + '"></div>';
        }).join('');
    }
    function paint() { var g = S.el && S.el.querySelector('.vp-grid'); if (g) g.innerHTML = boxes(); }
    function say(m) { var e = S.el && S.el.querySelector('.vp-msg'); if (e) e.textContent = m || ''; }
    function close() { if (S.el && S.el.parentNode) S.el.parentNode.removeChild(S.el); S.el = null; }

    function pick(side, file) {
        if (!file || !/^image\//.test(file.type)) return;
        var type = ''; SIDES.forEach(function (sd) { if (sd[0] === side) type = sd[1]; });
        S.prev[side] = URL.createObjectURL(file); S.urls[side] = ''; S.busy++; paint(); say('');
        upload(file, type).then(function (u) { S.urls[side] = u; })
            .catch(function (e) { S.prev[side] = ''; say(e && e.message ? e.message : tx('fail')); })
            .then(function () { S.busy--; paint(); });
    }
    function save(btn) {
        if (S.busy) { say(tx('wait')); return; }
        if (!S.urls.front || !S.urls.side || !S.urls.back) { say(tx('need')); return; }
        btn.disabled = true; say(tx('saving'));
        post('vehicle_photos_save', { front: S.urls.front, side: S.urls.side, back: S.urls.back }).then(function (r) {
            if (!r || !r.success) { btn.disabled = false; say((r && r.message) || tx('fail')); return; }
            close(); try { if (typeof showToast === 'function') showToast(tx('done'), 'success'); } catch (e) { /* the sheet closing is enough */ }
        }).catch(function () { btn.disabled = false; say(tx('fail')); });
    }
    function show() {
        if (S.el || document.getElementById('etelvp')) return;
        if (!document.getElementById('etelvp-css')) { var st = document.createElement('style'); st.id = 'etelvp-css'; st.textContent = CSS; document.head.appendChild(st); }
        var d = document.createElement('div'); d.className = 'etelvp'; d.id = 'etelvp'; d.setAttribute('role', 'dialog'); d.setAttribute('aria-modal', 'true'); d.setAttribute('aria-label', tx('title'));
        d.innerHTML = '<div class="vp-card"><h3>' + esc(tx('title')) + '</h3><p>' + esc(tx('sub')) + '</p><div class="vp-grid"></div><div class="vp-msg" role="status" aria-live="polite"></div>' +
            '<div class="vp-acts"><button type="button" data-vp-later>' + esc(tx('later')) + '</button><button type="button" class="vp-go" data-vp-save>' + esc(tx('save')) + '</button></div></div>';
        document.body.appendChild(d); S.el = d; paint();
        d.addEventListener('click', function (e) {
            var t = e.target, b;
            if ((b = t.closest('[data-vp]'))) { var f = d.querySelector('[data-vpf="' + b.getAttribute('data-vp') + '"]'); if (f) f.click(); }
            else if (t.closest('[data-vp-later]')) { snooze(); close(); }
            else if ((b = t.closest('[data-vp-save]'))) save(b);
        });
        d.addEventListener('change', function (e) { var k = e.target.getAttribute && e.target.getAttribute('data-vpf'); if (k && e.target.files && e.target.files[0]) { pick(k, e.target.files[0]); e.target.value = ''; } });
        d.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('[data-vp]')) { e.preventDefault(); e.target.click(); } });
    }

    function check() {
        var u = me();
        if (!u || (u.role !== 'courier' && u.role !== 'driver') || !token() || snoozed()) return;
        post('vehicle_photos_get', {}).then(function (r) { if (r && r.success && r.data && r.data.needed) show(); }).catch(function () { /* try again at the next start */ });
    }
    window.etelVehiclePhotosPrompt = { check: check, show: show };
    setTimeout(check, 4000);
})();

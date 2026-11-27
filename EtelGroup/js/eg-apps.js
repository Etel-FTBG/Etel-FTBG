/* ETEL Group master page - the Android apps (APK downloads).
   One list of apps (below) draws four places: the panel under the header button (#egap-panel), the strip under the hero buttons (#egap-hero),
   the band at the top of the footer (#egap-footer) and the list in the mobile menu (#egap-mobile). Each app has its own medallion logo.
   The APK files are the ones the Delivery install page already uses: /delivery/apk/ + the file name (the name starts with the Ethiopic letter).
   A quick HEAD request checks each file: a file that is not uploaded yet shows "Coming soon" (not a dead link) and a real file shows its size.
   English / Amharic follow the page's language buttons (setLang). Nothing here is sent anywhere else. */
(function () {
    'use strict';
    if (window.__egApps) return;
    window.__egApps = true;
    var APK_BASE = '/delivery/apk/';
    var APPS = [
        { key: 'etel',     file: '\u12A2E_Etel.apk',     name: 'ETEL',          logo: '/EtelGroup/icons/etelgroup-logo.png', main: true,
          en: 'The all-in-one app: shop, ride, pay and more', am: 'ሁሉንም አገልግሎት በአንድ መተግበሪያ' },
        { key: 'delivery', file: '\u12A2E_Delivery.apk', name: 'ETEL Delivery', logo: '/delivery/icons/icon-192.png',
          en: 'Food, shops and packages to your door', am: 'ምግብ፣ ሱቆችና ጥቅሎች እስከ በርዎ' },
        { key: 'ride',     file: '\u12A2E_Ride.apk',     name: 'ETEL Ride',     logo: '/delivery/icons/ride-icon-192.png',
          en: 'Safe rides across Hawassa', am: 'በሃዋሳ ደህንነቱ የተጠበቀ ጉዞ' },
        { key: 'driver',   file: '\u12A2E_Driver.apk',   name: 'ETEL Driver',   logo: '/delivery/icons/driver-icon-192.png',
          en: 'Drive, accept rides and earn', am: 'ያሽከርክሩ፣ ጉዞ ይቀበሉ፣ ይሥሩ' },
        { key: 'courier',  file: '\u12A2E_Courier.apk',  name: 'ETEL Courier',  logo: '/delivery/icons/courier-icon-192.png',
          en: 'Pick up, deliver and earn', am: 'ይውሰዱ፣ ያድርሱ፣ ይሥሩ' },
        { key: 'service',  file: '\u12A2E_Service.apk',  name: 'ETEL Service',  logo: '/delivery/icons/service-icon-192.png',
          en: 'For shops and service providers', am: 'ለሱቆችና አገልግሎት ሰጪዎች' }
    ];
    var T = {
        btn:   { en: 'Apps', am: 'መተግበሪያዎች' },
        title: { en: 'Get the ETEL apps for Android', am: 'የETEL መተግበሪያዎችን ለAndroid ያውርዱ' },
        sub:   { en: 'Direct APK download from ethelmarket.com. No Play Store needed.', am: 'ከethelmarket.com በቀጥታ APK ያውርዱ። Play Store አያስፈልግም።' },
        dl:    { en: 'Download APK', am: 'APK ያውርዱ' },
        soon:  { en: 'Coming soon', am: 'በቅርቡ ይመጣል' },
        main:  { en: 'All-in-one', am: 'ሁሉም በአንድ' },
        t1:    { en: 'Direct download', am: 'ቀጥታ ማውረድ' },
        t2:    { en: 'No Play Store needed', am: 'Play Store አያስፈልግም' },
        t3:    { en: 'Official ETEL apps', am: 'የETEL ይፋዊ መተግበሪያዎች' },
        how:   { en: 'How to install', am: 'እንዴት እንደሚጫን' },
        heroL: { en: 'Get the ETEL apps for Android', am: 'የETEL መተግበሪያዎችን ለAndroid ያውርዱ' },
        all:   { en: 'All apps', am: 'ሁሉንም' },
        s1:    { en: 'Tap Download APK for the app you want.', am: 'የሚፈልጉትን መተግበሪያ "APK ያውርዱ" ይንኩ።' },
        s2:    { en: 'Open the file when it finishes downloading.', am: 'ማውረዱ ሲጨርስ ፋይሉን ይክፈቱ።' },
        s3:    { en: 'Allow "Install unknown apps" once, then tap Install.', am: '"Install unknown apps"ን አንድ ጊዜ ይፍቀዱ፣ ከዚያ Install ይንኩ።' }
    };
    var ANDROID = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 18c0 .55.45 1 1 1h1v3.5a1.5 1.5 0 0 0 3 0V19h2v3.5a1.5 1.5 0 0 0 3 0V19h1c.55 0 1-.45 1-1V8H6v10zM3.5 8A1.5 1.5 0 0 0 2 9.5v7a1.5 1.5 0 0 0 3 0v-7A1.5 1.5 0 0 0 3.5 8zm17 0A1.5 1.5 0 0 0 19 9.5v7a1.5 1.5 0 0 0 3 0v-7A1.5 1.5 0 0 0 20.5 8zM15.53 2.16l1.3-1.3a.5.5 0 0 0-.7-.71l-1.48 1.48A5.97 5.97 0 0 0 12 1c-.96 0-1.86.23-2.66.63L7.85.15a.5.5 0 0 0-.7.7l1.31 1.32A5.99 5.99 0 0 0 6 7h12c0-1.99-.97-3.75-2.47-4.84zM10 5H9V4h1v1zm5 0h-1V4h1v1z"/></svg>';
    var STATE = {};      // key -> 'ok' | 'soon' | undefined (not known yet = shown as available)
    var SIZE = {};       // key -> "38 MB"
    var VERSION = '';    // all-in-one app version text, when the update file has one

    function lang() { try { return (typeof currentLang !== 'undefined' && currentLang === 'am') ? 'am' : 'en'; } catch (e) { return 'en'; } }
    function tr(k) { return T[k][lang()]; }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function url(a) { return APK_BASE + encodeURIComponent(a.file); }
    function bi(k) { return ' data-en="' + esc(T[k].en) + '" data-am="' + esc(T[k].am) + '"'; }
    function fmtSize(n) { n = parseInt(n, 10); if (!n || n < 1) return ''; return n >= 1048576 ? Math.round(n / 1048576 * 10) / 10 + ' MB' : Math.round(n / 1024) + ' KB'; }

    function dlLabel(a) {
        if (STATE[a.key] === 'soon') return '<span' + bi('soon') + '>' + esc(tr('soon')) + '</span>';
        return ANDROID + '<span' + bi('dl') + '>' + esc(tr('dl')) + '</span>' + (SIZE[a.key] ? '<small>' + esc(SIZE[a.key]) + '</small>' : '');
    }
    function dlLink(a) {
        var soon = STATE[a.key] === 'soon';
        return '<a class="egap-dl" data-egap-dl="' + a.key + '" href="' + (soon ? '#' : url(a)) + '"' + (soon ? ' aria-disabled="true"' : ' download') +
            ' aria-label="' + esc((soon ? tr('soon') + ': ' : tr('dl') + ': ') + a.name) + '">' + dlLabel(a) + '</a>';
    }
    function card(a) {
        return '<article class="egap-card' + (a.main ? ' is-main' : '') + '" data-egap-app="' + a.key + '">' + (a.main ? '<span class="egap-badge"' + bi('main') + '>' + esc(tr('main')) + '</span>' : '') +
            '<img class="egap-logo" src="' + a.logo + '" alt="' + esc(a.name) + '" width="62" height="62" loading="lazy" decoding="async" draggable="false">' +
            '<div class="egap-text"><h3 class="egap-name">' + esc(a.name) + '</h3><p class="egap-tag" data-en="' + esc(a.en) + '" data-am="' + esc(a.am) + '">' + esc(a[lang()]) + '</p>' +
            (a.main && VERSION ? '<span class="egap-ver">v' + esc(VERSION) + '</span>' : '') + '</div>' + dlLink(a) + '</article>';
    }
    function chip(a) {
        var soon = STATE[a.key] === 'soon';
        return '<a class="egap-chip" data-egap-chip="' + a.key + '" href="' + (soon ? '#egap-footer' : url(a)) + '"' + (soon ? ' aria-disabled="true"' : ' download') + ' aria-label="' + esc(tr('dl') + ': ' + a.name) + '">' +
            '<img src="' + a.logo + '" alt="" width="36" height="36" loading="lazy" draggable="false"><span>' + esc(a.name) + '</span></a>';
    }
    function trust() { return '<div class="egap-trust"><span' + bi('t1') + '>' + esc(tr('t1')) + '</span><span' + bi('t2') + '>' + esc(tr('t2')) + '</span><span' + bi('t3') + '>' + esc(tr('t3')) + '</span></div>'; }

    function render() {
        var panel = document.getElementById('egap-panel');
        if (panel) panel.innerHTML = '<div class="egap-head"><div><h2 class="egap-title" id="egap-title"' + bi('title') + '>' + esc(tr('title')) + '</h2><p class="egap-sub"' + bi('sub') + '>' + esc(tr('sub')) + '</p></div>' +
            '<button type="button" class="egap-x" data-egap-close aria-label="Close">✕</button></div><div class="egap-grid">' + APPS.map(card).join('') + '</div>' +
            '<div class="egap-note">' + trust() + '<a href="/delivery/install.html"' + bi('how') + '>' + esc(tr('how')) + '</a></div>';
        var hero = document.getElementById('egap-hero');
        if (hero) hero.innerHTML = '<span class="egap-hero-label">' + ANDROID + '<span' + bi('heroL') + '>' + esc(tr('heroL')) + '</span></span><div class="egap-chips">' + APPS.map(chip).join('') +
            '<a class="egap-chip" href="#egap-footer" data-egap-open><span' + bi('all') + '>' + esc(tr('all')) + '</span></a></div>';
        var foot = document.getElementById('egap-footer');
        if (foot) foot.innerHTML = '<div class="egap-footer-in"><div class="egap-head"><div><h2 class="egap-title"' + bi('title') + '>' + esc(tr('title')) + '</h2><p class="egap-sub"' + bi('sub') + '>' + esc(tr('sub')) + '</p></div></div>' +
            '<div class="egap-grid">' + APPS.map(card).join('') + '</div><ol class="egap-steps"><li' + bi('s1') + '>' + esc(tr('s1')) + '</li><li' + bi('s2') + '>' + esc(tr('s2')) + '</li><li' + bi('s3') + '>' + esc(tr('s3')) + '</li></ol>' +
            '<div class="egap-note">' + trust() + '<a href="/delivery/install.html"' + bi('how') + '>' + esc(tr('how')) + '</a></div></div>';
        var mob = document.getElementById('egap-mobile');
        if (mob) mob.innerHTML = APPS.map(card).join('');
    }
    // paint the real state of one app into every place it shows
    function paint(a) {
        [].slice.call(document.querySelectorAll('[data-egap-dl="' + a.key + '"]')).forEach(function (el) {
            var soon = STATE[a.key] === 'soon';
            el.innerHTML = dlLabel(a);
            if (soon) { el.setAttribute('aria-disabled', 'true'); el.setAttribute('href', '#'); el.removeAttribute('download'); } else { el.removeAttribute('aria-disabled'); el.setAttribute('href', url(a)); el.setAttribute('download', ''); }
        });
        [].slice.call(document.querySelectorAll('[data-egap-chip="' + a.key + '"]')).forEach(function (el) {
            if (STATE[a.key] === 'soon') { el.setAttribute('aria-disabled', 'true'); el.setAttribute('href', '#egap-footer'); el.removeAttribute('download'); }
        });
    }
    function check(a) {
        if (typeof fetch !== 'function') return;
        fetch(url(a), { method: 'HEAD', cache: 'no-store' }).then(function (r) {
            if (r.status === 404 || r.status === 410) STATE[a.key] = 'soon';
            else if (r.ok) { STATE[a.key] = 'ok'; var s = fmtSize(r.headers.get('content-length')); if (s) SIZE[a.key] = s; }
            paint(a);
        }).catch(function () { /* offline or blocked: leave the link as it is */ });
    }
    function loadVersion() {
        if (typeof fetch !== 'function') return;
        fetch('/delivery/app/version.json', { cache: 'no-store' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
            if (j && j.versionCode > 0 && j.versionName) { VERSION = String(j.versionName); render(); applyLang(lang()); APPS.forEach(paint); }
        }).catch(function () {});
    }

    // language: swap every text we drew
    function applyLang(l) {
        [].slice.call(document.querySelectorAll('#egap-panel [data-en][data-am], #egap-hero [data-en][data-am], #egap-footer [data-en][data-am], #egap-mobile [data-en][data-am], #egap-nav-btn [data-en][data-am]')).forEach(function (el) {
            var v = el.getAttribute('data-' + l); if (v) el.textContent = v;
        });
        [].slice.call(document.querySelectorAll('[data-egap-dl]')).forEach(function (el) {
            var a = APPS.filter(function (x) { return x.key === el.getAttribute('data-egap-dl'); })[0];
            if (a) el.setAttribute('aria-label', (STATE[a.key] === 'soon' ? tr('soon') + ': ' : tr('dl') + ': ') + a.name);
        });
    }

    // the panel under the header button
    function setOpen(on) {
        var p = document.getElementById('egap-panel'), b = document.getElementById('egap-nav-btn');
        if (!p) return;
        p.classList.toggle('open', !!on);
        if (b) b.setAttribute('aria-expanded', on ? 'true' : 'false');
        p.setAttribute('aria-hidden', on ? 'false' : 'true');
    }
    document.addEventListener('click', function (e) {
        var t = e.target; if (!t || !t.closest) return;
        var dis = t.closest('[aria-disabled="true"].egap-dl, [aria-disabled="true"].egap-chip');
        if (dis) { e.preventDefault(); return; }
        if (t.closest('#egap-nav-btn')) { var p = document.getElementById('egap-panel'); setOpen(!(p && p.classList.contains('open'))); return; }
        if (t.closest('[data-egap-close]')) { setOpen(false); var b = document.getElementById('egap-nav-btn'); if (b) b.focus(); return; }
        if (t.closest('[data-egap-open]')) { return; }
        if (!t.closest('#egap-panel')) setOpen(false);
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { var p = document.getElementById('egap-panel'); if (p && p.classList.contains('open')) { setOpen(false); var b = document.getElementById('egap-nav-btn'); if (b) b.focus(); } } });

    function start() {
        render();
        applyLang(lang());
        APPS.forEach(check);
        loadVersion();
        // keep up with the page's language buttons
        if (typeof window.setLang === 'function' && !window.setLang.__egApps) {
            var orig = window.setLang;
            window.setLang = function (l) { orig.apply(this, arguments); applyLang(l === 'am' ? 'am' : 'en'); };
            window.setLang.__egApps = true;
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();

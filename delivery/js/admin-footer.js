/* ETEL Admin - footer: the year, and the system status mirrored from the header (#sys-status) so the two never disagree. */
(function () {
    'use strict';
    function ready(fn) { if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn); else fn(); }
    ready(function () {
        // Phone layout: the menu strip and the page area are pinned just under the header. Their offsets used to be fixed numbers (56px),
        // which is wrong as soon as the header is taller than normal (Display Size set to a large text size): the strip then slid under the
        // header and the other pages could not be reached. Follow the header's real height instead (css/admin-footer.css reads these two).
        var hdr = document.querySelector('.header'), nav = document.querySelector('.sidebar');
        function layout() {
            var root = document.documentElement.style;
            if (hdr) root.setProperty('--af-header-h', hdr.offsetHeight + 'px');
            if (nav) root.setProperty('--af-nav-h', nav.offsetHeight + 'px');
        }
        layout();
        window.addEventListener('resize', layout);
        try { var ro = new ResizeObserver(layout); if (hdr) ro.observe(hdr); if (nav) ro.observe(nav); } catch (e) { /* older browser: resize + load are enough */ }
        window.addEventListener('load', layout);
        var y = document.getElementById('af-year'); if (y) y.textContent = String(new Date().getFullYear());
        var src = document.getElementById('sys-status'), txt = document.getElementById('af-status'), dot = document.getElementById('af-dot');
        if (!src || !txt || !dot) return;
        function sync() {
            var t = (src.textContent || '').trim() || 'All Systems Online';
            txt.textContent = t;
            dot.className = 'af-dot' + (/offline|down|error|fail/i.test(t) ? ' af-bad' : (/degrad|slow|warn|issue|partial/i.test(t) ? ' af-warn' : ''));
        }
        sync();
        try { new MutationObserver(sync).observe(src, { childList: true, characterData: true, subtree: true }); } catch (e) { /* header text is simply not mirrored */ }
    });
})();

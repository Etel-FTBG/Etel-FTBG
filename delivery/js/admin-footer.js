/* ETEL Admin - footer: the year, and the system status mirrored from the header (#sys-status) so the two never disagree. */
(function () {
    'use strict';
    function ready(fn) { if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn); else fn(); }
    ready(function () {
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

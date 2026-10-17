/* Admin on a phone: the refresh, diagnostics and dark-mode round buttons used to sit on top of the numbers on the right edge.
 * On narrow screens css/admin-polish.css hides them and this one small button opens or closes the three together
 * (they close again by themselves after 8 seconds). On wide screens the button is not shown. It changes nothing else. */
(function () {
    'use strict';
    if (document.getElementById('etel-fab-toggle')) return;
    var timer = null;
    function init() {
        var b = document.createElement('button');
        b.type = 'button'; b.id = 'etel-fab-toggle';
        b.setAttribute('aria-label', 'Quick tools'); b.setAttribute('aria-expanded', 'false');
        b.textContent = '⋯';
        function set(open) {
            document.body.classList.toggle('etel-fab-open', open);
            b.setAttribute('aria-expanded', open ? 'true' : 'false');
            b.textContent = open ? '✕' : '⋯';
            clearTimeout(timer);
            if (open) timer = setTimeout(function () { set(false); }, 8000);
        }
        b.addEventListener('click', function () { set(!document.body.classList.contains('etel-fab-open')); });
        document.body.appendChild(b);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

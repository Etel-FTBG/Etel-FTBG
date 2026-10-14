/* Customer page start-up bits that used to sit in inline <script> blocks (a strict Content-Security-Policy refuses those). */
window.ETEL_NO_THEME_FAB = true;
// Google Maps loads on demand (customer.js etelEnsureMaps); this keeps its callback name harmless until then.
window.initCustomerMap = function () {};
// Fade out and remove the start-up splash.
(function () {
    setTimeout(function () {
        var el = document.getElementById('etel-master-splash');
        if (!el) return;
        el.style.opacity = '0';
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 400);
    }, 900);
})();

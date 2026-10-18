/* ETEL - browser error beacon (additive script, loaded first by the rider and driver apps).
   Sends a short note to api/client-error.php when a page throws an uncaught error, a promise is rejected with nobody
   listening, or an API call answers with a server error (status 500 or more). Admins read the list in System health.
   Sends nothing private: no tokens, no query strings, no response bodies; at most 8 notes per page load, the same
   error is sent once. The request id (rid) of a failed API call is included so support can find the server log line.
   Never throws, never slows the page. */
(function () {
    'use strict';
    if (window.__etelBeacon) return;
    window.__etelBeacon = true;

    var sent = {}, count = 0, MAX = 8;
    var app = (location.pathname.match(/\/(ride|driver|courier|customer|admin)(?:\/|\.html|$)/) || [0, 'web'])[1];

    function url() {
        var a = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
        return a.replace(/boot\.php.*$/, 'client-error.php');
    }
    function uid() { try { var u = JSON.parse(localStorage.getItem('etel_user') || 'null'); return u && u.id ? u.id : 0; } catch (e) { return 0; } }
    function clean(s, n) { return String(s == null ? '' : s).replace(/([?&#])[^\s)"']*/g, '').slice(0, n); }
    function send(kind, message, extra) {
        try {
            if (count >= MAX || !message) return;
            var key = kind + '|' + message + '|' + ((extra && (extra.source || extra.rid)) || '');
            if (sent[key]) return;
            sent[key] = true; count++;
            var body = JSON.stringify(Object.assign({ app: app, kind: kind, message: String(message).slice(0, 300), page: location.pathname,
                uid: uid(), ua: (navigator.userAgent || '').slice(0, 200) }, extra || {}));
            if (navigator.sendBeacon) { navigator.sendBeacon(url(), new Blob([body], { type: 'text/plain' })); return; }
            fetch(url(), { method: 'POST', body: body, keepalive: true, headers: { 'Content-Type': 'text/plain' } }).catch(function () {});
        } catch (e) { /* the beacon must never matter */ }
    }

    window.addEventListener('error', function (ev) {
        // resource load failures (images, scripts) have no message; cross-origin scripts hide theirs
        if (!ev || !ev.message || ev.message === 'Script error.' || ev.message === 'Script error') return;
        send('js', ev.message, { source: clean(ev.filename, 160), line: ev.lineno || 0, stack: clean(ev.error && ev.error.stack, 1200) });
    });
    window.addEventListener('unhandledrejection', function (ev) {
        var r = ev && ev.reason, m = r && (r.message || r);
        if (!m || typeof m === 'object') return;
        send('promise', m, { stack: clean(r && r.stack, 1200) });
    });

    // server errors from the API: note the action and the request id
    var realFetch = window.fetch ? window.fetch.bind(window) : null;
    if (realFetch) {
        window.fetch = function (input, init) {
            var p = realFetch(input, init);
            try {
                var u = typeof input === 'string' ? input : (input && input.url) || '';
                if (u.indexOf('boot.php') !== -1) {
                    var action = '';
                    try { action = JSON.parse((init && init.body) || '{}').action || ''; } catch (e) {}
                    if (!action) { var m = u.match(/[?&]action=([a-z0-9_]+)/i); action = m ? m[1] : ''; }
                    p.then(function (r) {
                        if (r && r.status >= 500) {
                            send('api', 'API ' + (action || 'call') + ' answered ' + r.status, { rid: r.headers.get('X-Request-Id') || '' });
                        }
                    }, function () { /* offline or cancelled: not an app error */ });
                }
            } catch (e) { /* ignore */ }
            return p;
        };
    }
})();

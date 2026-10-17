/* ETEL signature sounds for the delivery apps (courier, shop, customer). No audio files: every sound is synthesised in the browser, so it
 * costs no download and has no licensing question. The brand motif is E - G - B - E (E5 G5 B5 E6, an E-minor arpeggio rising to the high E,
 * "E" for ETEL): bright bell notes with a gold shimmer on the last one.
 *
 *   EtelSound.play('job' | 'order' | 'progress' | 'delivered' | 'cancel' | 'message')
 *   EtelSound.ring(key, name, everyMs)   repeat a sound (and vibrate) until EtelSound.stop(key)
 *   EtelSound.stop(key) / stopAll()
 *   EtelSound.setMuted(true|false) / isMuted()   remembered on this phone (etel_sound_off)
 *
 * Phones only let a page make sound after the person has touched it once; until then play() is silent and EtelSound.locked() is true
 * (the shop and courier pages show a "tap to turn sound on" chip). A page that is closed or in the background cannot play sound:
 * that case is the phone's own push-notification tone. */
(function () {
    'use strict';
    if (window.EtelSound) return;
    var ctx = null, master = null, muted = false, loops = {}, gestured = false;
    try { muted = localStorage.getItem('etel_sound_off') === '1'; } catch (e) {}

    var E5 = 659.25, G5 = 783.99, A5 = 880, B5 = 987.77, E6 = 1318.51, G6 = 1567.98, B6 = 1975.53, E4 = 329.63, B4 = 493.88, E3 = 164.81;

    function ensure() {
        try {
            if (!ctx) {
                var C = window.AudioContext || window.webkitAudioContext;
                if (!C) return null;
                ctx = new C();
                var comp = ctx.createDynamicsCompressor();   // keeps it loud without crackling on small phone speakers
                comp.threshold.value = -16; comp.knee.value = 12; comp.ratio.value = 10; comp.attack.value = 0.003; comp.release.value = 0.25;
                master = ctx.createGain(); master.gain.value = 1.6;
                master.connect(comp); comp.connect(ctx.destination);
            }
            if (ctx.state === 'suspended') ctx.resume().catch(function () {});
            return ctx;
        } catch (e) { return null; }
    }

    // One bell note: a sine body, a bright triangle, and two inharmonic partials that give the "struck metal" sparkle, each fading out.
    function bell(freq, at, dur, vol) {
        var t0 = ctx.currentTime + at;
        [[1, 'sine', 1], [2, 'triangle', 0.42], [2.76, 'sine', 0.26], [5.4, 'sine', 0.1]].forEach(function (p) {
            var o = ctx.createOscillator(), g = ctx.createGain();
            o.type = p[1]; o.frequency.value = freq * p[0];
            g.gain.setValueAtTime(0.0001, t0);
            g.gain.exponentialRampToValueAtTime(vol * p[2], t0 + 0.008);
            g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur / (p[0] > 2 ? 2 : 1));
            o.connect(g); g.connect(master);
            o.start(t0); o.stop(t0 + dur + 0.05);
        });
    }
    // A soft low thump under the first note so a ring is felt as well as heard.
    function thump(at, vol) {
        var t0 = ctx.currentTime + at, o = ctx.createOscillator(), g = ctx.createGain();
        o.type = 'sine'; o.frequency.setValueAtTime(E3 * 1.5, t0); o.frequency.exponentialRampToValueAtTime(E3, t0 + 0.18);
        g.gain.setValueAtTime(0.0001, t0); g.gain.exponentialRampToValueAtTime(vol, t0 + 0.01); g.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.32);
        o.connect(g); g.connect(master); o.start(t0); o.stop(t0 + 0.35);
    }
    function motif(at, step, vol, tail) {
        bell(E5, at, 0.5, vol); bell(G5, at + step, 0.5, vol); bell(B5, at + 2 * step, 0.55, vol);
        bell(E6, at + 3 * step, tail, vol * 1.15); bell(B6, at + 3 * step + 0.02, tail * 0.8, vol * 0.45);   // the gold shimmer
    }

    var SOUNDS = {
        // New job for a courier: fast, urgent, loud. About 1.3 s, so a ring can repeat every 1.4 s.
        job: function () { thump(0, 0.9); motif(0, 0.12, 0.62, 0.95); bell(E6, 0.62, 0.6, 0.5); bell(G6, 0.7, 0.55, 0.4); },
        // New order for a shop: the motif, then a calling answer (B5 - E6). About 2 s.
        order: function () { thump(0, 0.8); motif(0, 0.16, 0.58, 0.8); bell(B5, 1.0, 0.45, 0.5); bell(E6, 1.2, 0.8, 0.58); bell(B6, 1.22, 0.6, 0.25); },
        // Order moved on (courier found, picked up): a friendly two-note rise.
        progress: function () { bell(G5, 0, 0.45, 0.42); bell(B5, 0.14, 0.7, 0.46); },
        // Delivered: the full motif and a held, bright chord.
        delivered: function () {
            motif(0, 0.13, 0.5, 1.3);
            [E5, G5, B5, E6].forEach(function (f) { bell(f, 0.62, 1.4, 0.26); });
            bell(G6, 0.7, 1.0, 0.2); bell(B6, 0.78, 0.9, 0.16);
        },
        cancel: function () { bell(B4, 0, 0.5, 0.4); bell(E4, 0.2, 0.9, 0.42); },
        message: function () { bell(B5, 0, 0.5, 0.42); bell(E6, 0.1, 0.6, 0.34); }
    };
    var BUZZ = { job: [300, 120, 300, 120, 300], order: [250, 100, 250, 100, 250], delivered: [80, 60, 80, 60, 200], progress: [90], message: [60], cancel: [160] };

    function buzz(name) { if (!muted && BUZZ[name] && navigator.vibrate) try { navigator.vibrate(BUZZ[name]); } catch (e) {} }

    var api = {
        play: function (name, opts) {
            if (!SOUNDS[name]) return;
            if (!(opts && opts.noBuzz)) buzz(name);
            if (muted) return;
            if (!ensure() || ctx.state !== 'running') return;   // locked until the first touch
            try { SOUNDS[name](); } catch (e) {}
        },
        ring: function (key, name, everyMs) {
            if (loops[key]) return;
            api.play(name);
            loops[key] = setInterval(function () { api.play(name); }, everyMs || 2200);
        },
        stop: function (key) { if (loops[key]) { clearInterval(loops[key]); delete loops[key]; } },
        stopAll: function () { Object.keys(loops).forEach(api.stop); },
        ringing: function (key) { return !!loops[key]; },
        isMuted: function () { return muted; },
        setMuted: function (m) {
            muted = !!m;
            try { localStorage.setItem('etel_sound_off', muted ? '1' : '0'); } catch (e) {}
            if (muted) api.stopAll();
            else api.play('progress');   // a short sample, so the person hears what is now on
        },
        locked: function () { return !ctx || ctx.state !== 'running'; },
        unlock: function () { gestured = true; ensure(); },
        // Plays one sound right now (for the "test the ring" buttons), even if the loops are idle.
        test: function (name) { gestured = true; ensure(); api.play(name || 'order'); }
    };
    ['touchstart', 'click', 'keydown'].forEach(function (ev) {
        window.addEventListener(ev, function once() { if (gestured) return; gestured = true; ensure(); }, { passive: true, capture: true });
    });
    window.EtelSound = api;
})();

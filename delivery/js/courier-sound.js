/* Courier app: the ETEL ring for a new job (add-on, release v10 canary).
 *
 * The app already rings (and vibrates) for every job on the board until the courier takes or declines it: startRingingForJob() calls
 * _playCourierRingBurst() every 1.4 s. That burst was two thin beeps. Here it is replaced by the ETEL signature ring from js/etel-sounds.js
 * (bell motif E-G-B-E with a low thump, loud and compressed so it carries on a small phone speaker). The ringing logic, the vibration and
 * the stop-when-handled behaviour are the app's own and are not touched. If the sound engine is missing the old beeps keep working.
 * Phones only play sound after one touch of the page, so a small chip asks for it when the sound is still locked. */
(function () {
    'use strict';
    if (window.__etelCourierSound) return;
    window.__etelCourierSound = 'v10';
    if (!window.EtelSound || typeof window._playCourierRingBurst !== 'function') return;

    window._playCourierRingBurst = function () { window.EtelSound.play('job', { noBuzz: true }); };

    function chip() {
        if (document.getElementById('etel-sound-chip')) return;
        var b = document.createElement('button');
        b.type = 'button'; b.id = 'etel-sound-chip';
        b.style.cssText = 'position:fixed;left:12px;bottom:12px;z-index:99999;padding:9px 14px;border-radius:22px;border:1px solid #D4AF37;background:#0B1E3F;color:#F5D77A;font-size:13px;font-weight:700;box-shadow:0 4px 16px rgba(0,0,0,.35);cursor:pointer';
        function paint() {
            var ES = window.EtelSound;
            if (ES.isMuted()) { b.textContent = '🔕 Job sound off'; b.style.display = 'block'; }
            else if (ES.locked()) { b.textContent = '🔔 Tap to turn job sound on'; b.style.display = 'block'; }
            else { b.textContent = '🔔 Job sound on'; b.style.display = 'block'; }
        }
        b.addEventListener('click', function () {
            var ES = window.EtelSound;
            if (ES.isMuted()) ES.setMuted(false); else if (ES.locked()) { ES.unlock(); ES.test('job'); } else ES.setMuted(true);
            paint();
        });
        document.body.appendChild(b); paint();
        setInterval(paint, 2000);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', chip); else chip();
})();

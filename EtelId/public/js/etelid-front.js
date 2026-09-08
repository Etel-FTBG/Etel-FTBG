/**
 * Etel-ID Premium — Frontend JavaScript
 *
 * @package    Etel-ID Premium
 * @version    2.0.0
 * @author     Etel Group · ኢትኤል ቡድን
 * @link       https://etelgroup.com
 * @since      2.0.0
 *
 * Provides: etelIDFlip(uid) — 3D card flip toggle
 *           Mouse-tilt parallax on .eid-scene hover
 *           etelIDSubmitApply() — multi-step form AJAX submission
 */
(function() {
  'use strict';

  /* ── 3D flip ───────────────────────────────────────── */
  window.etelIDFlip = function(uid) {
    var el = document.getElementById(uid);
    if (el) el.classList.toggle('flipped');
  };

  /* ── Mouse tilt parallax on card ──────────────────── */
  function initTilt() {
    var scenes = document.querySelectorAll('.eid-scene');
    scenes.forEach(function(scene) {
      var card = scene.querySelector('.eid-card-wrap');
      if (!card) return;

      scene.addEventListener('mousemove', function(e) {
        if (card.classList.contains('flipped')) return;
        var r  = scene.getBoundingClientRect();
        var cx = r.left + r.width  / 2;
        var cy = r.top  + r.height / 2;
        var dx = (e.clientX - cx) / (r.width  / 2);
        var dy = (e.clientY - cy) / (r.height / 2);
        var rx =  dy * 6;
        var ry = -dx * 8;
        card.style.transition = 'transform 0.1s ease';
        card.style.transform  = 'rotateX(' + rx + 'deg) rotateY(' + ry + 'deg)';
      });

      scene.addEventListener('mouseleave', function() {
        card.style.transition = 'transform 0.5s ease';
        if (card.classList.contains('flipped')) {
          card.style.transform = 'rotateY(180deg)';
        } else {
          card.style.transform = 'rotateX(0deg) rotateY(0deg)';
        }
      });
    });
  }

  /* ── Apply form multi-step (Step 4 builds full UI) ─ */
  window.etelIDSubmitApply = function(formId, btnId) {
    var form = document.getElementById(formId);
    var btn  = document.getElementById(btnId);
    if (!form || !btn) return;

    var data = {};
    var inputs = form.querySelectorAll('input,select,textarea');
    inputs.forEach(function(inp) {
      if (inp.name) data[inp.name] = inp.value;
    });

    btn.disabled = true;
    btn.textContent = 'Issuing your ID…';

    var body = Object.keys(data).map(function(k) {
      return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
    }).join('&');
    body += '&action=etelid_apply&nonce=' + (window.EtelIDConfig ? window.EtelIDConfig.nonce : '');

    fetch(window.EtelIDConfig ? window.EtelIDConfig.ajax : '/wp-admin/admin-ajax.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (res.success) {
        var wrap = document.getElementById('etelid-success');
        if (wrap) {
          wrap.querySelector('[data-field="etel_id"]').textContent  = res.data.etel_id;
          wrap.querySelector('[data-field="fin"]').textContent      = res.data.fin;
          var link = wrap.querySelector('[data-field="verify_url"]');
          if (link) link.href = res.data.verify_url;
          wrap.style.display = 'block';
          form.style.display = 'none';
        }
      } else {
        var err = document.getElementById('etelid-error');
        if (err) { err.textContent = res.data.message; err.style.display = 'block'; }
        btn.disabled = false;
        btn.textContent = 'Apply for Etel-ID';
      }
    })
    .catch(function() {
      btn.disabled = false;
      btn.textContent = 'Apply for Etel-ID';
    });
  };

  document.addEventListener('DOMContentLoaded', initTilt);
})();

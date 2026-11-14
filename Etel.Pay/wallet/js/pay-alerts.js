/* Etel Pay - top-up result notices on the wallet home.
   Shows a card when an admin rejected a top-up request (with the reason) or approved one, until the user taps OK.
   Read-only: uses ep_my_topups; nothing here moves money. */
(function () {
  'use strict';
  function seenKey(ref) { return 'ep_tu_seen_' + ref; }
  function seen(ref) { try { return localStorage.getItem(seenKey(ref)) === '1'; } catch (e) { return false; } }
  function mark(ref) { try { localStorage.setItem(seenKey(ref), '1'); } catch (e) { } }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function fmt(n) { try { return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); } catch (e) { return n; } }

  function render(list) {
    var host = document.getElementById('tu-alerts');
    if (!host) {
      var anchor = document.querySelector('.recent-wrap');
      if (!anchor || !anchor.parentNode) return;
      host = document.createElement('div');
      host.id = 'tu-alerts'; host.className = 'tu-alerts';
      anchor.parentNode.insertBefore(host, anchor);
    }
    var html = '';
    for (var i = 0; i < list.length; i++) {
      var r = list[i];
      if (r.status !== 'failed' && r.status !== 'completed') continue;
      if (seen(r.ref)) continue;
      var t = Date.parse(String(r.updated_at || r.created_at || '').replace(' ', 'T'));
      if (t && Date.now() - t > 7 * 86400000) continue;            // older than a week: stay quiet
      var bad = r.status === 'failed';
      html += '<div class="tu-alert ' + (bad ? 'bad' : 'good') + '" role="status">' +
        '<div class="tu-alert-body"><strong>' + (bad ? 'Top-up rejected' : 'Top-up approved') + '</strong> - ETB ' + esc(fmt(r.amount)) +
        '<div class="tu-alert-sub">Ref ' + esc(r.ref) + (bad ? ' - ' + esc(r.reject_reason || 'Payment not received') : ' - your balance was credited') + '</div></div>' +
        '<button type="button" class="tu-alert-ok" data-ref="' + esc(r.ref) + '">OK</button></div>';
    }
    host.innerHTML = html;
    var btns = host.querySelectorAll('.tu-alert-ok');
    for (var j = 0; j < btns.length; j++) btns[j].onclick = function () { mark(this.getAttribute('data-ref')); var c = this.parentNode; if (c && c.parentNode) c.parentNode.removeChild(c); };
  }

  function boot() {
    if (typeof EP_API === 'undefined' || typeof EP_CORE === 'undefined' || !EP_CORE.getToken || !EP_CORE.getToken()) return;
    EP_API.call('ep_my_topups').then(function (j) {
      if (j && j.success && j.data && j.data.requests) render(j.data.requests);
    }).catch(function () { });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(boot, 600); });
  else setTimeout(boot, 600);
})();

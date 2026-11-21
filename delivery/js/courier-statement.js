/* ETEL Courier - weekly statement under the earnings box (additive script, no bundle release).
   One week (Monday to Sunday) of delivered orders from api action courier_get_statement: courier pay, tips, what was earned each day and
   for each delivery. The order total (the food) is never counted. Share sends the week as text. English / Amharic (etel_lang) built in. Fails quietly. */
(function () {
  'use strict';
  if (window.__etelCourierStatement) return;
  window.__etelCourierStatement = true;

  var AM = {
    'Weekly statement': 'ሳምንታዊ የገቢ መግለጫ', 'Open': 'ክፈት', 'Close': 'ዝጋ', 'You earned this week': 'በዚህ ሳምንት ያገኙት',
    'deliveries': 'አቅርቦቶች', 'delivery': 'አቅርቦት', 'Delivery pay': 'የአቅርቦት ክፍያ', 'Tips': 'ጉርሻ', 'Total earned': 'ጠቅላላ ገቢ',
    'Per delivery': 'በአንድ አቅርቦት', 'Day by day': 'ቀን በቀን', 'Deliveries': 'አቅርቦቶች', 'No deliveries this week.': 'በዚህ ሳምንት ምንም አቅርቦት የለም።',
    'Share this statement': 'ይህን መግለጫ አጋራ', 'Previous week': 'ያለፈው ሳምንት', 'Next week': 'የሚቀጥለው ሳምንት', 'Could not load.': 'መጫን አልተቻለም።',
    'ETEL courier statement': 'የኢቴል ሹፌር የገቢ መግለጫ', 'tip': 'ጉርሻ'
  };
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var DAYN = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  var D = null, WEEK = null, OPEN = false, BUSY = false;

  function lang() { try { return localStorage.getItem('etel_lang') === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
  function T(s) { return lang() === 'am' && AM[s] ? AM[s] : s; }
  function token() { try { return (window.Auth && Auth.getToken) ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); } catch (e) { return ''; } }
  function apiUrl() { return (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php'; }
  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function money(n) { return 'ETB ' + (Math.round((parseFloat(n) || 0) * 100) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function dmy(ymd) { var p = String(ymd || '').split('-'); return p.length === 3 ? (parseInt(p[2], 10) + ' ' + MON[parseInt(p[1], 10) - 1]) : ''; }
  function when(at) { var m = /\s(\d{2}:\d{2})/.exec(at || ''); return dmy(String(at || '').substring(0, 10)) + (m ? ' ' + m[1] : ''); }
  function countWord(n) { return n + ' ' + T(n === 1 ? 'delivery' : 'deliveries'); }

  function css() {
    if (document.getElementById('cst-css')) return;
    var s = document.createElement('style'); s.id = 'cst-css';
    s.textContent =
      '#cst-card{margin:10px 0;padding:14px;border-radius:16px;border:1px solid rgba(212,175,55,.35);background:rgba(128,128,128,.08);font-variant-numeric:lining-nums tabular-nums}' +
      '#cst-card .cst-top{display:flex;justify-content:space-between;align-items:center;gap:8px}#cst-card b{font-weight:800}' +
      '#cst-card .cst-btn{background:none;border:1px solid rgba(212,175,55,.55);color:#E5C76B;border-radius:10px;padding:6px 12px;font-size:12px;font-weight:700;cursor:pointer}' +
      '#cst-card .cst-btn[disabled]{opacity:.35}#cst-card .cst-gold{background:#D4AF37;color:#1a1200;border-color:#D4AF37;width:100%;margin-top:10px;padding:10px}' +
      '#cst-card .cst-sub{font-size:12px;opacity:.75;margin-top:3px}#cst-card .cst-big{font-size:26px;font-weight:800;color:#E5C76B;margin-top:6px}' +
      '#cst-card .cst-nav{display:flex;align-items:center;justify-content:space-between;margin:12px 0 6px;font-weight:700;font-size:13px}' +
      '#cst-card .cst-line{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid rgba(128,128,128,.25);font-size:13px}' +
      '#cst-card .cst-day{display:flex;align-items:center;gap:8px;margin:6px 0;font-size:12px}#cst-card .cst-day i{flex:1;height:8px;border-radius:4px;background:rgba(128,128,128,.25);overflow:hidden}' +
      '#cst-card .cst-day u{display:block;height:8px;border-radius:4px;background:#D4AF37}#cst-card .cst-ord{padding:8px 0;border-bottom:1px solid rgba(128,128,128,.2);font-size:12.5px}' +
      '#cst-card .cst-ord div{display:flex;justify-content:space-between}#cst-card .cst-mute{opacity:.7;font-size:11.5px}';
    document.head.appendChild(s);
  }

  function shareText() {
    var t = D.totals, L = [T('ETEL courier statement') + ' ' + dmy(D.week_start) + ' - ' + dmy(D.week_end), T('Deliveries') + ': ' + t.deliveries,
      T('Delivery pay') + ': ' + money(t.pay), T('Tips') + ': ' + money(t.tips), T('Total earned') + ': ' + money(t.earned)];
    if (D.per_delivery != null) L.push(T('Per delivery') + ': ' + money(D.per_delivery));
    L.push('');
    D.days.forEach(function (d, i) { L.push(DAYN[i] + ' ' + dmy(d.date) + ': ' + countWord(d.deliveries) + ', ' + money(d.earned)); });
    return L.join('\n');
  }

  function render() {
    var c = document.getElementById('cst-card'); if (!c) return;
    if (!D) { c.innerHTML = '<div class="cst-top"><b>' + esc(T('Weekly statement')) + '</b></div>'; return; }
    var t = D.totals, best = 0, h;
    D.days.forEach(function (d) { if (d.earned > best) best = d.earned; });
    h = '<div class="cst-top"><div><b>' + esc(T('Weekly statement')) + '</b><div class="cst-sub">' + esc(dmy(D.week_start) + ' - ' + dmy(D.week_end) + ' - ' + countWord(t.deliveries) + ' - ' + money(t.earned)) + '</div></div>' +
      '<button type="button" class="cst-btn" data-cst="toggle">' + esc(T(OPEN ? 'Close' : 'Open')) + '</button></div>';
    if (OPEN) {
      h += '<div class="cst-nav"><button type="button" class="cst-btn" data-cst="prev" aria-label="' + esc(T('Previous week')) + '"' + (D.prev_week && !BUSY ? '' : ' disabled') + '>&lt;</button>' +
        '<span>' + esc(dmy(D.week_start) + ' - ' + dmy(D.week_end)) + '</span>' +
        '<button type="button" class="cst-btn" data-cst="next" aria-label="' + esc(T('Next week')) + '"' + (D.next_week && !BUSY ? '' : ' disabled') + '>&gt;</button></div>' +
        '<div class="cst-sub">' + esc(T('You earned this week')) + '</div><div class="cst-big">' + esc(money(t.earned)) + '</div>' +
        '<div class="cst-line"><span>' + esc(T('Delivery pay')) + '</span><span>' + esc(money(t.pay)) + '</span></div>' +
        '<div class="cst-line"><span>' + esc(T('Tips')) + '</span><span>' + esc(money(t.tips)) + '</span></div>' +
        '<div class="cst-line"><b>' + esc(T('Total earned')) + '</b><b>' + esc(money(t.earned)) + '</b></div>' +
        (D.per_delivery != null ? '<div class="cst-line"><span>' + esc(T('Per delivery')) + '</span><span>' + esc(money(D.per_delivery)) + '</span></div>' : '') +
        '<div class="cst-sub" style="margin-top:12px"><b>' + esc(T('Day by day')) + '</b></div>';
      D.days.forEach(function (d, i) {
        var w = best > 0 && d.earned > 0 ? Math.max(6, Math.round(d.earned / best * 100)) : 0;
        h += '<div class="cst-day"><span style="width:30px">' + DAYN[i] + '</span><i><u style="width:' + w + '%"></u></i><span style="width:92px;text-align:right">' + (d.deliveries ? esc(money(d.earned)) : '-') + '</span></div>';
      });
      h += '<div class="cst-sub" style="margin-top:12px"><b>' + esc(T('Deliveries')) + '</b></div>';
      if (!D.orders.length) h += '<div class="cst-sub">' + esc(T('No deliveries this week.')) + '</div>';
      D.orders.forEach(function (o) {
        h += '<div class="cst-ord"><div><span class="cst-mute">' + esc(when(o.done_at)) + ' - ' + esc(o.order_code) + '</span><b>' + esc(money(o.earned)) + '</b></div>' +
          '<div><span>' + esc((o.from || '-') + '  >  ' + (o.to || '-')) + '</span>' + (o.tip > 0 ? '<span class="cst-mute">+ ' + esc(money(o.tip)) + ' ' + esc(T('tip')) + '</span>' : '') + '</div></div>';
      });
      h += '<button type="button" class="cst-btn cst-gold" data-cst="share">' + esc(T('Share this statement')) + '</button>';
    }
    c.innerHTML = h;
  }

  function load(week) {
    if (!token() || BUSY) return;
    BUSY = true; render();
    fetch(apiUrl(), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() }, body: JSON.stringify(week ? { action: 'courier_get_statement', week_start: week } : { action: 'courier_get_statement' }) })
      .then(function (r) { return r.json(); })
      .then(function (r) { BUSY = false; if (r && r.success && r.data && r.data.totals) { D = r.data; WEEK = D.week_start; } render(); })
      .catch(function () { BUSY = false; render(); });
  }

  function onClick(e) {
    var b = e.target.closest ? e.target.closest('[data-cst]') : null; if (!b || b.disabled) return;
    var k = b.getAttribute('data-cst');
    if (k === 'toggle') { OPEN = !OPEN; render(); }
    else if (k === 'prev' && D && D.prev_week) load(D.prev_week);
    else if (k === 'next' && D && D.next_week) load(D.next_week);
    else if (k === 'share' && D) {
      var txt = shareText();
      if (navigator.share) navigator.share({ text: txt }).catch(function () {});
      else if (navigator.clipboard) navigator.clipboard.writeText(txt).catch(function () {});
    }
  }

  var tries = 0;
  (function wait() {
    var earn = document.querySelector('.earn-card');
    if (!earn || !token()) { if (tries++ < 100) return setTimeout(wait, 200); return; }
    if (document.getElementById('cst-card')) return;
    css();
    var c = document.createElement('div'); c.id = 'cst-card';
    earn.parentNode.insertBefore(c, earn.nextSibling);
    c.addEventListener('click', onClick);
    render(); load();
    document.addEventListener('visibilitychange', function () { if (!document.hidden && !BUSY && D && !OPEN) load(WEEK === D.week_start && !D.next_week ? null : WEEK); });
  })();
})();

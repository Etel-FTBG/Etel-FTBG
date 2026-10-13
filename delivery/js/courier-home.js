/* Courier home: accurate earnings (delivery fee, not order total) + daily goal + week + streak.
   Standalone on purpose: the courier app code ships as versioned releases, this only decorates the page. */
(function () {
  'use strict';
  var GOAL_KEY = 'etel_courier_daily_goal', DEFAULT_GOAL = 1000, PRESETS = [500, 800, 1000, 1500, 2000];
  var card = null, last = null;

  function goal() {
    try { var g = parseInt(localStorage.getItem(GOAL_KEY), 10); if (g >= 100 && g <= 100000) return g; } catch (e) {}
    return DEFAULT_GOAL;
  }
  function setGoal(v) { try { localStorage.setItem(GOAL_KEY, String(v)); } catch (e) {} render(); }
  function money(n) { return Math.round(n || 0).toLocaleString(); }

  function css() {
    if (document.getElementById('ch-css')) return;
    var s = document.createElement('style'); s.id = 'ch-css';
    s.textContent =
      '.ch-goal{margin-top:12px}' +
      '.ch-goal-row{display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:12px;font-weight:700}' +
      '.ch-goal-row button{background:none;border:1px solid rgba(212,175,55,.45);color:#E5C76B;border-radius:8px;padding:3px 9px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit}' +
      '.ch-track{height:10px;border-radius:6px;background:rgba(128,128,128,.25);overflow:hidden;margin-top:7px}' +
      '.ch-fill{height:100%;width:0;border-radius:6px;background:linear-gradient(90deg,#D4AF37,#2ECC71);transition:width .7s cubic-bezier(.22,1,.36,1)}' +
      '.ch-note{font-size:11px;opacity:.8;margin-top:5px}' +
      '.ch-stats{display:flex;gap:8px;margin-top:12px}' +
      '.ch-stat{flex:1;min-width:0;text-align:center;padding:8px 4px;border-radius:12px;background:rgba(128,128,128,.12)}' +
      '.ch-stat b{display:block;font-size:15px;font-weight:800}' +
      '.ch-stat span{font-size:10.5px;opacity:.75}' +
      '#ch-sheet{position:fixed;inset:0;z-index:9999;display:flex;align-items:flex-end;justify-content:center;background:rgba(5,5,10,.7)}' +
      '#ch-sheet .ch-box{width:min(420px,100%);background:#1b1b2b;color:#F5F5F7;border-radius:20px 20px 0 0;padding:20px 18px 26px;border:1px solid rgba(212,175,55,.3)}' +
      '#ch-sheet h3{margin:0 0 4px;font-size:17px}#ch-sheet p{margin:0 0 14px;font-size:12px;opacity:.7}' +
      '#ch-sheet .ch-chips{display:flex;flex-wrap:wrap;gap:8px}' +
      '#ch-sheet .ch-chips button{flex:1 1 28%;padding:12px 6px;border-radius:12px;border:1px solid rgba(212,175,55,.4);background:rgba(212,175,55,.08);color:#E5C76B;font-weight:800;font-size:14px;cursor:pointer;font-family:inherit}' +
      '#ch-sheet .ch-chips button.on{background:#D4AF37;color:#1b1b2b}' +
      '#ch-sheet .ch-close{margin-top:14px;width:100%;padding:12px;border-radius:12px;border:1px solid rgba(255,255,255,.15);background:none;color:#A8A8B8;font-weight:700;cursor:pointer;font-family:inherit}';
    document.head.appendChild(s);
  }

  function openSheet() {
    var old = document.getElementById('ch-sheet'); if (old) old.remove();
    var cur = goal(), el = document.createElement('div'); el.id = 'ch-sheet';
    el.innerHTML = '<div class="ch-box"><h3>Daily goal</h3><p>How much do you want to earn from delivery fees today?</p><div class="ch-chips">' +
      PRESETS.map(function (v) { return '<button data-v="' + v + '"' + (v === cur ? ' class="on"' : '') + '>ETB ' + money(v) + '</button>'; }).join('') +
      '</div><button class="ch-close">Close</button></div>';
    el.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('button[data-v]');
      if (b) { setGoal(parseInt(b.getAttribute('data-v'), 10)); el.remove(); }
      else if (e.target === el || (e.target.classList && e.target.classList.contains('ch-close'))) el.remove();
    });
    document.body.appendChild(el);
  }

  function render() {
    if (!card) return;
    var t = last ? last.today.earned : 0, g = goal(), pct = Math.max(0, Math.min(100, t / g * 100));
    var left = Math.max(0, g - t);
    var note = !last ? 'Loading…' : (t >= g ? 'Goal reached — great work! 🎉' :
      (t > 0 ? 'ETB ' + money(left) + ' to go' : 'Your first delivery of the day starts the bar'));
    card.innerHTML =
      '<div class="earn-top"><span class="earn-label">Today’s earnings</span>' +
      '<button class="earn-refresh" title="Refresh" aria-label="Refresh earnings">↻</button></div>' +
      '<div class="earn-amount"><span class="cur">ETB</span><span>' + (last ? money(t) : '0') + '</span></div>' +
      '<div class="ch-goal"><div class="ch-goal-row"><span>Goal ETB ' + money(g) + ' · ' + Math.round(pct) + '%</span>' +
      '<button type="button" class="ch-edit">Set goal</button></div>' +
      '<div class="ch-track"><div class="ch-fill" style="width:' + pct + '%"></div></div><div class="ch-note">' + note + '</div></div>' +
      '<div class="ch-stats">' +
      '<div class="ch-stat"><b>' + (last ? last.today.deliveries : 0) + '</b><span>Today</span></div>' +
      '<div class="ch-stat"><b>ETB ' + money(last ? last.week.earned : 0) + '</b><span>Last 7 days</span></div>' +
      '<div class="ch-stat"><b>' + (last ? last.streak_days : 0) + '</b><span>Day streak</span></div></div>';
    card.querySelector('.ch-edit').onclick = openSheet;
    card.querySelector('.earn-refresh').onclick = function () { load(); };
  }

  function load() {
    if (typeof apiGet !== 'function') return;
    apiGet('courier_earnings_summary', {}).then(function (res) {
      if (res && res.success && res.data && res.data.today) { last = res.data; render(); }
    }).catch(function () {});
  }
  window.refreshEarningsCard = load;

  var tries = 0;
  (function wait() {
    card = document.querySelector('.earn-card');
    if (!card || typeof apiGet !== 'function') { if (tries++ < 100) return setTimeout(wait, 200); return; }
    css(); render(); load();
    setInterval(function () { if (!document.hidden) load(); }, 60000);
    document.querySelectorAll('.tab-btn').forEach(function (b) { b.addEventListener('click', function () { setTimeout(load, 700); }); });
  })();
})();

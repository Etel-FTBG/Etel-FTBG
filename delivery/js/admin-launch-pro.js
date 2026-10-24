/* ETEL Admin - Launch readiness.
   The pre-launch checklist as one board: a readiness gauge with a plain verdict, "do these next" (the first things still open),
   a filter for open or done items, a search, sections you can fold with their own progress bars, and the staggered rollout as a
   route. Ticking an item saves at once and puts it back with a message if the save fails (the old screen kept the tick even when
   the save failed). Who ticked what and when is shown under each item.
   Data: admin_get_launch_checklist_state / admin_toggle_launch_checklist_item (AdminController.php); the wording of the items
   lives in this file and only the ticked state is stored. Standalone: replaces loadLaunchChecklist() so
   admin-launch-checklist.js is not edited. Every server value goes through AdminKit.esc. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;

  var SECTIONS = [
    {
      id: "sec1", eyebrow: "Section 1", title: "Before any real money or real users",
      icon: "🛡️", tone: "red",
      groups: [
        {
          label: "Payments & payouts",
          items: [
            ["s1-pay-1", "Recompute 10 random completed rides' fares by hand and confirm they match what was charged"],
            ["s1-pay-2", "Confirm driver payout math for cash vs wallet rides"],
            ["s1-pay-3", "Test a ride cancelled after fare calc but before payment - confirm nothing charges or double-charges"],
            ["s1-pay-4", "Test insufficient wallet balance mid-ride - confirm graceful failure, not a stuck ride"],
            ["s1-pay-5", "Confirm 'owed' vs 'already paid out' are tracked as two separate numbers per driver"]
          ]
        },
        {
          label: "Concurrency",
          items: [
            ["s1-con-1", "Two riders book the same nearby driver simultaneously - only one should match"],
            ["s1-con-2", "A driver goes offline mid-request - the request should still reach other nearby drivers"],
            ["s1-con-3", "Same rider double-tapping 'book' rapidly should never create duplicate rides"],
            ["s1-con-4", "Two different riders scheduling the same time slot both succeed independently"]
          ]
        },
        {
          label: "Security basics",
          items: [
            ["s1-sec-1", "Trip-share link stops working once the ride ends"],
            ["s1-sec-2", "Rate limiting exists on booking/scheduling actions"],
            ["s1-sec-3", "A rider can't view or cancel another rider's ride by guessing an ID"],
            ["s1-sec-4", "A driver can't see a rider's phone number before accepting"]
          ]
        }
      ]
    },
    {
      id: "sec2", eyebrow: "Section 2", title: "Soft launch — small trusted group first",
      icon: "🧪", tone: "amber",
      groups: [
        {
          label: "Execution",
          items: [
            ["s2-1", "Dedicated private Telegram group created, admin-only add"],
            ["s2-2", "Pinned bug-report template posted"],
            ["s2-3", "Run for at least 1-2 weeks before opening wider"],
            ["s2-4", "Check the PHP error log daily during this period"],
            ["s2-5", "A real completed ride with both sides rating each other"],
            ["s2-6", "A real trip-share link actually opened by a second person"],
            ["s2-7", "A real scheduled ride the cron dispatcher fires on time"],
            ["s2-8", "Push notifications confirmed on 2-3 different real devices"]
          ]
        }
      ]
    },
    {
      id: "sec3", eyebrow: "Section 3", title: "Ongoing discipline",
      icon: "🔁", tone: "blue",
      groups: [
        {
          label: "Habits, not one-time tasks",
          items: [
            ["s3-1", "Re-test book → complete → pay after every deploy, even 'unrelated' changes"],
            ["s3-2", "Keep a running 'known working as of [date]' note per major flow"],
            ["s3-3", "Verify fixes on a real device before trusting a code review alone"],
            ["s3-4", "Revisit and update this checklist as new features ship"]
          ]
        }
      ]
    }
  ];

  var PHASES = [
    { key: "A", title: "Drivers only", when: "Days 1–3", icon: "🚗",
      desc: "Start with 2 of the 5 drivers. Login, GPS, and push notifications confirmed on their real phones before any real ride happens." },
    { key: "B", title: "First riders", when: "Days 3–6", icon: "🧍",
      desc: "Once drivers are comfortable, add 3-4 riders. This is where ratings, trip-share, and scheduling actually get exercised for real." },
    { key: "C", title: "Full group", when: "Days 6–10", icon: "👥",
      desc: "Remaining riders join once Phase B has run a couple of days without anything serious. Normal soft-launch operation from here." }
  ];

var V = { state: {}, filter: '', q: '', folded: {}, loaded: false, busy: false };
function root() { return document.getElementById('launch-root'); }
function isDone(k) { return !!(V.state[k] && V.state[k].checked); }
function progress(items) { var d = 0; items.forEach(function (i) { if (isDone(i[0])) d++; }); return { done: d, total: items.length }; }
function allItems() { var a = []; SECTIONS.forEach(function (s) { s.groups.forEach(function (g) { g.items.forEach(function (i) { a.push([i[0], i[1], s, g]); }); }); }); return a; }
function verdict(p) {
  if (p === 100) return ['Ready to launch', 'green', 'Every item is confirmed. Keep re-testing after each release.'];
  if (p >= 75) return ['Almost ready', 'green', 'Finish the last few items before opening to more people.'];
  if (p >= 40) return ['Getting there', 'amber', 'Good progress. Money and security items come first.'];
  return ['Not ready yet', 'red', 'Do not open to real users until the first section is done.'];
}

function load(quiet) {
  var el = root(); if (!el || V.busy) return Promise.resolve();
  K.css(); V.busy = true;
  if (!quiet && !V.loaded) el.innerHTML = '<div class="ak">' + K.skeleton(3) + '</div>';
  return K.api('admin_get_launch_checklist_state', {}).then(function (res) {
    V.busy = false;
    if (K.okRes(res)) V.state = K.data(res).state || {}; else { V.state = V.state || {}; K.toast('Could not load the saved ticks. Showing what this screen knows.', true); }
    V.loaded = true; paint();
  });
}

function gauge(pct, tone) {
  var r = 70, c = 2 * Math.PI * r, off = c - pct / 100 * c;
  var col = tone === 'green' ? '#35c987' : tone === 'amber' ? '#F2CC6B' : '#ff6b7a';
  return '<svg width="170" height="170" viewBox="0 0 170 170" role="img" aria-label="' + pct + ' percent ready" style="transform:rotate(-90deg);flex:none"><circle cx="85" cy="85" r="' + r + '" fill="none" stroke="rgba(255,255,255,.14)" stroke-width="12"/>' +
    '<circle cx="85" cy="85" r="' + r + '" fill="none" stroke="' + col + '" stroke-width="12" stroke-linecap="round" stroke-dasharray="' + c + '" stroke-dashoffset="' + off + '" style="transition:stroke-dashoffset .6s"/></svg>';
}

function paint() {
  var el = root(); if (!el) return;
  var items = allItems(), tp = progress(items), pct = tp.total ? Math.round(tp.done / tp.total * 100) : 0, vd = verdict(pct);
  var q = V.q.toLowerCase(), focus = document.activeElement && document.activeElement.id === 'lc-q';
  var open = items.filter(function (i) { return !isDone(i[0]); });
  var h = '<div class="ak"><div class="ak-hero"><div style="display:flex;gap:22px;align-items:center;flex-wrap:wrap;position:relative"><div style="position:relative;width:170px;height:170px;flex:none">' + gauge(pct, vd[1]) +
    '<div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center"><b style="font-size:36px;color:#F2CC6B;line-height:1">' + pct + '%</b><span style="font-size:10px;letter-spacing:.08em;text-transform:uppercase;opacity:.75;margin-top:4px">ready</span></div></div>' +
    '<div style="flex:1;min-width:220px"><h2>Launch readiness</h2><div style="margin:8px 0">' + K.pill(vd[0], vd[1]) + '</div><p>' + esc(vd[2]) + ' ' + K.num(tp.done) + ' of ' + K.num(tp.total) + ' items confirmed for real.</p></div></div></div>';
  var kp = [{ key: '', icon: '📋', label: 'All items', value: K.num(tp.total) }, { key: 'open', icon: '⏳', label: 'Still to do', value: K.num(tp.total - tp.done) }, { key: 'done', icon: '✅', label: 'Done', value: K.num(tp.done) }];
  h += K.kpis(kp, V.filter);
  if (open.length && !V.filter && !q) {
    h += '<div class="ak-card" style="cursor:default;margin:6px 0 14px"><b>👉 Do these next</b><div style="margin-top:8px;display:grid;gap:6px">' + open.slice(0, 3).map(function (i) {
      return '<label class="ak-sw" style="align-items:flex-start"><input type="checkbox" data-tick="' + esc(i[0]) + '"><span>' + esc(i[1]) + ' <small style="color:var(--mute)">· ' + esc(i[3].label) + '</small></span></label>';
    }).join('') + '</div></div>';
  }
  h += '<div class="ak-tools"><label class="ak-search"><u>🔎</u><input id="lc-q" type="search" placeholder="Search the checklist…" value="' + esc(V.q) + '" autocomplete="off"></label><button type="button" class="ak-btn" data-act="refresh" aria-label="Refresh">🔄</button></div>';
  var shown = 0;
  SECTIONS.forEach(function (s) {
    var sp = progress(s.groups.reduce(function (a, g) { return a.concat(g.items); }, [])), groups = '';
    s.groups.forEach(function (g) {
      var rows = g.items.filter(function (i) { return (!V.filter || (V.filter === 'done') === isDone(i[0])) && (!q || (i[1] + ' ' + g.label).toLowerCase().indexOf(q) >= 0); });
      if (!rows.length) return; shown += rows.length;
      groups += '<div class="ak-sec"><h4>' + esc(g.label) + '</h4><div style="display:grid;gap:4px">' + rows.map(row).join('') + '</div></div>';
    });
    if (!groups) return;
    var done = sp.done === sp.total, folded = V.folded[s.id] && !q, pc = sp.total ? Math.round(sp.done / sp.total * 100) : 0;
    h += '<div class="ak-card' + (done ? '' : '') + '" style="cursor:default;margin-bottom:12px"><button type="button" data-fold="' + esc(s.id) + '" aria-expanded="' + (folded ? 'false' : 'true') + '" style="all:unset;cursor:pointer;display:block;width:100%">' +
      '<div class="ak-top"><div class="ak-av" style="font-size:20px">' + esc(s.icon) + '</div><div class="ak-nm"><small>' + esc(s.eyebrow) + '</small><b style="white-space:normal">' + esc(s.title) + '</b></div>' + K.pill(sp.done + '/' + sp.total, done ? 'green' : s.tone) + '<span aria-hidden="true">' + (folded ? '▸' : '▾') + '</span></div>' +
      '<div style="height:5px;border-radius:5px;background:var(--line);margin-top:10px;overflow:hidden"><div style="height:100%;width:' + pc + '%;background:linear-gradient(90deg,var(--gold),var(--gold2))"></div></div></button>' +
      (folded ? '' : groups) + '</div>';
  });
  if (!shown) h += K.empty(V.filter === 'open' ? '🎉' : '🔎', V.filter === 'open' ? 'Nothing left to do' : 'Nothing matches', V.filter === 'open' ? 'Every item is ticked.' : 'Try another word or clear the filter.');
  h += route();
  el.innerHTML = h + '</div>';
  if (focus) { var i = document.getElementById('lc-q'); if (i) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); } }
}

function when(raw) {
  var d = raw ? new Date(String(raw).replace(' ', 'T')) : null;
  return d && !isNaN(d) ? d.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ', ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
}
function row(i) {
  var k = i[0], st = V.state[k] || {}, done = !!st.checked;
  var who = done && st.checked_by_name ? '<div style="font-size:11px;color:var(--mute);margin:0 0 4px 32px">✓ ' + esc(st.checked_by_name) + (st.checked_at ? ' · ' + esc(when(st.checked_at)) : '') + '</div>' : '';
  return '<div><label class="ak-sw" style="align-items:flex-start;padding:6px 4px"><input type="checkbox" data-tick="' + esc(k) + '"' + (done ? ' checked' : '') + '><span style="' + (done ? 'color:var(--mute);text-decoration:line-through' : '') + '">' + esc(i[1]) + '</span></label>' + who + '</div>';
}
function route() {
  return '<div class="ak-card" style="cursor:default;margin-top:6px"><div class="ak-top"><div class="ak-av" style="font-size:20px">🗺️</div><div class="ak-nm"><small>Rollout route</small><b style="white-space:normal">Drivers first, riders once ready</b></div></div><ol style="list-style:none;margin:14px 0 0;padding:0">' +
    PHASES.map(function (p, n) {
      return '<li style="display:flex;gap:14px;position:relative;padding-bottom:' + (n === PHASES.length - 1 ? 0 : 18) + 'px">' + (n === PHASES.length - 1 ? '' : '<span style="position:absolute;left:21px;top:44px;bottom:0;width:2px;background:var(--line)"></span>') +
        '<span class="ak-av" style="width:44px;height:44px;border-radius:50%;font-size:19px;flex:none">' + esc(p.icon) + '</span><div><b>Phase ' + esc(p.key) + ' · ' + esc(p.title) + '</b> <span style="font-size:11.5px;color:var(--gold2)">' + esc(p.when) + '</span><div style="font-size:12.5px;color:var(--mute);margin-top:3px;max-width:560px">' + esc(p.desc) + '</div></div></li>';
    }).join('') + '</ol></div>';
}

function tick(box) {
  var key = box.getAttribute('data-tick'), want = box.checked, prev = V.state[key] ? JSON.parse(JSON.stringify(V.state[key])) : null;
  V.state[key] = V.state[key] || {}; V.state[key].checked = want;
  V.state[key].checked_at = want ? new Date().toISOString().slice(0, 19).replace('T', ' ') : null;
  paint();
  K.api('admin_toggle_launch_checklist_item', { item_key: key, checked: want ? 1 : 0 }).then(function (res) {
    if (K.okRes(res)) return;
    if (prev) V.state[key] = prev; else delete V.state[key];
    paint(); K.toast(K.failText(res, 'Could not save that tick. It was put back.'), true);
  });
}

var search = K.debounce(function () { paint(); }, 200);
function wire(el) {
  if (el._lcWired) return; el._lcWired = true;
  el.addEventListener('change', function (e) { if (e.target.getAttribute && e.target.getAttribute('data-tick')) tick(e.target); });
  el.addEventListener('click', function (e) {
    var t = e.target, b;
    if ((b = t.closest('[data-kpi]'))) { var k = b.getAttribute('data-kpi'); V.filter = (V.filter === k ? '' : k); paint(); return; }
    if ((b = t.closest('[data-fold]'))) { var s = b.getAttribute('data-fold'); V.folded[s] = !V.folded[s]; paint(); return; }
    if (t.closest('[data-act="refresh"]')) { V.busy = false; load(true); }
  });
  el.addEventListener('input', function (e) { if (e.target.id === 'lc-q') { V.q = e.target.value.trim(); search(); } });
}

function install() {
  var el = root(); if (!el) return;
  wire(el);
  window.loadLaunchChecklist = function () { return load(V.loaded); };
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();

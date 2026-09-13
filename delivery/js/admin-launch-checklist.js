// js/admin-launch-checklist.js
// Launch Readiness tab: renders the pre-launch checklist (payments/
// concurrency/security, soft-launch execution, ongoing discipline) and
// the staggered driver-then-rider rollout plan as one interactive,
// persisted control center. Checkbox state is saved server-side via
// admin_toggle_launch_checklist_item so it's consistent across devices.
//
// Design: a circular readiness gauge as the hero (styled like a vehicle
// instrument dial - deliberately tying the "launch readiness" concept
// to the product this checklist is actually FOR), and the staggered
// rollout rendered as a route line with stop markers, mirroring the
// gold route-polyline language already used in the rider-facing trip
// screens. Built entirely on css/etel-system.css tokens - no new colors
// invented, no other design language mixed in.

(function () {
  "use strict";

  // ─── Content (static - only checked-state is persisted) ──────────

  var SECTIONS = [
    {
      id: "sec1", eyebrow: "Section 1", title: "Before any real money or real users",
      icon: "🛡️", accent: "var(--etel-err)",
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
      icon: "🧪", accent: "var(--etel-gold-3)",
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
      icon: "🔁", accent: "var(--etel-info)",
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

  // ─── State ─────────────────────────────────────────────────────

  var STATE = {}; // item_key -> {checked, checked_at, checked_by_name}

  function allItemKeys() {
    var keys = [];
    SECTIONS.forEach(function (s) { s.groups.forEach(function (g) { g.items.forEach(function (it) { keys.push(it[0]); }); }); });
    return keys;
  }

  function sectionProgress(section) {
    var total = 0, done = 0;
    section.groups.forEach(function (g) {
      g.items.forEach(function (it) {
        total++;
        if (STATE[it[0]] && STATE[it[0]].checked) done++;
      });
    });
    return { done: done, total: total };
  }

  function overallProgress() {
    var total = 0, done = 0;
    SECTIONS.forEach(function (s) {
      var p = sectionProgress(s);
      total += p.total; done += p.done;
    });
    return { done: done, total: total, pct: total ? Math.round((done / total) * 100) : 0 };
  }

  // ─── Rendering ─────────────────────────────────────────────────

  function gaugeSvg(pct) {
    var r = 74, c = 2 * Math.PI * r;
    var offset = c - (pct / 100) * c;
    return '<svg width="180" height="180" viewBox="0 0 180 180" style="transform:rotate(-90deg)">' +
      '<circle cx="90" cy="90" r="' + r + '" fill="none" stroke="rgba(212,175,55,.12)" stroke-width="12"/>' +
      '<circle cx="90" cy="90" r="' + r + '" fill="none" stroke="url(#launchGaugeGrad)" stroke-width="12" ' +
      'stroke-linecap="round" stroke-dasharray="' + c + '" stroke-dashoffset="' + offset + '" ' +
      'style="transition:stroke-dashoffset .6s var(--etel-ease)"/>' +
      '<defs><linearGradient id="launchGaugeGrad" x1="0%" y1="0%" x2="100%" y2="100%">' +
      '<stop offset="0%" stop-color="var(--etel-gold-2)"/><stop offset="100%" stop-color="var(--etel-gold-5)"/>' +
      "</linearGradient></defs></svg>";
  }

  function renderHero() {
    var p = overallProgress();
    return '<div style="display:flex;align-items:center;gap:32px;flex-wrap:wrap;padding:8px 4px 28px">' +
      '<div style="position:relative;width:180px;height:180px;flex-shrink:0">' +
      gaugeSvg(p.pct) +
      '<div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center">' +
      '<div style="font-family:var(--etel-font-display);font-size:38px;font-weight:800;color:var(--etel-gold-4);line-height:1">' + p.pct + '%</div>' +
      '<div style="font-family:var(--etel-font-mono);font-size:9px;letter-spacing:.08em;color:var(--etel-ink-dim);text-transform:uppercase;margin-top:4px">Launch Readiness</div>' +
      "</div></div>" +
      '<div style="flex:1;min-width:240px">' +
      '<div style="font-family:var(--etel-font-display);font-weight:800;font-size:22px;color:var(--etel-ink);margin-bottom:6px">ETEL Ride — Launch Readiness</div>' +
      '<div style="font-size:13px;color:var(--etel-ink-dim);line-height:1.6;max-width:480px;margin-bottom:16px">' + p.done + ' of ' + p.total + ' checklist items confirmed. Check items off as they\'re verified for real — this isn\'t a formality, every item here was chosen because something real was found or could be.</div>' +
      '<div style="display:flex;gap:10px;flex-wrap:wrap">' +
      SECTIONS.map(function (s) {
        var sp = sectionProgress(s);
        return '<div class="etel-stat"><div class="etel-stat-value" style="font-size:16px;color:' + s.accent + '">' + sp.done + '/' + sp.total + '</div><div class="etel-stat-label">' + s.eyebrow + '</div></div>';
      }).join("") +
      "</div></div></div>";
  }

  function renderSection(section) {
    var sp = sectionProgress(section);
    var complete = sp.done === sp.total;
    return '<div class="etel-card" style="margin-bottom:16px;border-color:' + (complete ? 'rgba(46,204,113,.35)' : 'var(--etel-gold-line)') + '">' +
      '<div style="display:flex;align-items:center;gap:12px;margin-bottom:4px">' +
      '<div style="width:38px;height:38px;border-radius:11px;background:var(--etel-gold-wash);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0">' + section.icon + '</div>' +
      '<div style="flex:1">' +
      '<div class="etel-badge etel-badge-gold" style="margin-bottom:3px">' + section.eyebrow + '</div>' +
      '<div style="font-family:var(--etel-font-display);font-weight:700;font-size:15.5px;color:var(--etel-ink)">' + esc(section.title) + '</div>' +
      "</div>" +
      '<div class="etel-badge ' + (complete ? 'etel-badge-ok' : 'etel-badge-warn') + '">' + sp.done + '/' + sp.total + "</div>" +
      "</div>" +
      section.groups.map(function (g) {
        return '<div style="margin-top:16px">' +
          '<div style="font-family:var(--etel-font-mono);font-size:10px;letter-spacing:.05em;text-transform:uppercase;color:var(--etel-ink-faint);margin-bottom:8px;padding-left:2px">' + esc(g.label) + '</div>' +
          g.items.map(renderItem).join("") +
          "</div>";
      }).join("") +
      "</div>";
  }

  function renderItem(it) {
    var key = it[0], label = it[1];
    var st = STATE[key] || {};
    var checked = !!st.checked;
    var meta = checked && st.checked_by_name ? ('<div style="font-size:10.5px;color:var(--etel-ink-faint);margin-top:2px;margin-left:32px">✓ ' + esc(st.checked_by_name) + (st.checked_at ? ' · ' + esc(fmtWhen(st.checked_at)) : '') + '</div>') : '';
    return '<div style="margin-bottom:2px">' +
      '<label style="display:flex;align-items:flex-start;gap:10px;padding:7px 6px;border-radius:8px;cursor:pointer;transition:background .15s" onmouseover="this.style.background=\'var(--etel-gold-wash)\'" onmouseout="this.style.background=\'transparent\'">' +
      '<input type="checkbox" data-launch-key="' + key + '" ' + (checked ? 'checked' : '') + ' onchange="toggleLaunchItem(this)" style="width:18px;height:18px;margin-top:1px;accent-color:var(--etel-gold-3);flex-shrink:0;cursor:pointer">' +
      '<span style="font-size:13px;line-height:1.5;color:' + (checked ? 'var(--etel-ink-dim)' : 'var(--etel-ink)') + ';' + (checked ? 'text-decoration:line-through;text-decoration-color:var(--etel-ink-faint)' : '') + '">' + esc(label) + "</span>" +
      "</label>" + meta +
      "</div>";
  }

  function renderRoute() {
    return '<div class="etel-card" style="margin-top:6px">' +
      '<div style="display:flex;align-items:center;gap:10px;margin-bottom:4px">' +
      '<div style="width:38px;height:38px;border-radius:11px;background:var(--etel-gold-wash);display:flex;align-items:center;justify-content:center;font-size:18px">🗺️</div>' +
      '<div><div class="etel-badge etel-badge-gold" style="margin-bottom:3px">Rollout Route</div>' +
      '<div style="font-family:var(--etel-font-display);font-weight:700;font-size:15.5px;color:var(--etel-ink)">Staggered rollout — drivers first, riders once ready</div></div>' +
      "</div>" +
      '<div style="position:relative;padding:28px 6px 6px;margin-top:12px">' +
      '<div style="position:absolute;left:23px;top:44px;bottom:34px;width:2px;background:linear-gradient(180deg,var(--etel-gold-3),var(--etel-gold-1));border-radius:2px"></div>' +
      PHASES.map(function (ph, i) {
        return '<div style="display:flex;gap:16px;margin-bottom:' + (i === PHASES.length - 1 ? '0' : '26px') + ';position:relative">' +
          '<div style="width:46px;height:46px;border-radius:50%;background:var(--etel-carbon-3);border:2px solid var(--etel-gold-3);display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0;z-index:1;box-shadow:var(--etel-shadow-gold)">' + ph.icon + '</div>' +
          '<div style="flex:1;padding-top:2px">' +
          '<div style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap">' +
          '<span style="font-family:var(--etel-font-display);font-weight:700;font-size:14.5px;color:var(--etel-ink)">Phase ' + ph.key + ' — ' + esc(ph.title) + '</span>' +
          '<span style="font-family:var(--etel-font-mono);font-size:10px;color:var(--etel-gold-4);letter-spacing:.04em">' + esc(ph.when) + '</span>' +
          "</div>" +
          '<div style="font-size:12.5px;color:var(--etel-ink-dim);line-height:1.55;margin-top:4px;max-width:520px">' + esc(ph.desc) + "</div>" +
          "</div></div>";
      }).join("") +
      "</div></div>";
  }

  function esc(s) { return String(s == null ? "" : s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;"); }
  function fmtWhen(raw) {
    try {
      var d = new Date(String(raw).replace(" ", "T"));
      if (isNaN(d)) return raw;
      return d.toLocaleDateString([], { month: "short", day: "numeric" }) + ", " + d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
    } catch (e) { return raw; }
  }

  function renderAll() {
    var root = document.getElementById("launch-root");
    if (!root) return;
    root.innerHTML =
      renderHero() +
      SECTIONS.map(renderSection).join("") +
      renderRoute();
  }

  // ─── Data + interaction ────────────────────────────────────────

  window.loadLaunchChecklist = function () {
    var root = document.getElementById("launch-root");
    if (root) root.innerHTML = '<div style="text-align:center;padding:60px;color:var(--etel-ink-faint);font-family:var(--etel-font-mono);font-size:12px">LOADING…</div>';
    adm("admin_get_launch_checklist_state", {}, true).then(function (res) {
      STATE = (res && res.success && res.data && res.data.state) ? res.data.state : {};
      renderAll();
    }).catch(function () {
      STATE = {};
      renderAll();
      if (typeof toast === "function") toast("Could not load saved checklist state - showing unchecked", "error");
    });
  };

  window.toggleLaunchItem = function (checkbox) {
    var key = checkbox.getAttribute("data-launch-key");
    var checked = checkbox.checked;
    STATE[key] = STATE[key] || {};
    STATE[key].checked = checked;
    STATE[key].checked_at = new Date().toISOString().slice(0, 19).replace("T", " ");
    renderAll();
    adm("admin_toggle_launch_checklist_item", { item_key: key, checked: checked ? 1 : 0 }).then(function (res) {
      if (!res || !res.success) {
        if (typeof toast === "function") toast("Could not save - try again", "error");
      }
    }).catch(function () {
      if (typeof toast === "function") toast("Network error - change not saved", "error");
    });
  };
})();

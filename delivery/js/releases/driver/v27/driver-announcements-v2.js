/* ETEL Driver - Announcements viewer v2 (v1.0)
 * Replaces the old openAnnouncements() from driver-bundle.js with:
 *   1. Actual category filtering - the three Chats-tab rows (Pro news /
 *      Support notifications / From Etel Group) previously all called
 *      the exact same function with no category passed at all, so every
 *      tab showed an identical, unfiltered mix of every announcement
 *      ever posted. Now each row passes its real category and the
 *      backend filters accordingly (see driver_get_announcements fix).
 *   2. A properly designed full-height slide-up sheet instead of the
 *      old cramped generic modal - category header with icon, clean
 *      dated cards, unread accent, loading/empty states.
 *
 * Own file, loaded after driver-bundle.js so this definition of
 * window.openAnnouncements wins - the old one is never called, and
 * driver-bundle.js itself is untouched.
 */
(function () {
  "use strict";

  var CATEGORY_META = {
    news: { label: "Pro News", icon: "📰", sub: "Latest updates for drivers", accent: "#D4AF37" },
    notification: { label: "Support Notifications", icon: "🔔", sub: "Alerts and reminders", accent: "#60B4FF" },
    partner: { label: "From Etel Group", icon: "🏢", sub: "Partner announcements", accent: "#E5C76B" }
  };

  function esc(s) {
    return String(s == null ? "" : s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
  }

  function fmtDate(raw) {
    if (!raw) return "";
    try {
      var d = new Date(raw.replace(" ", "T"));
      if (isNaN(d)) return raw;
      var now = new Date();
      var sameDay = d.toDateString() === now.toDateString();
      if (sameDay) return "Today, " + d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
      return d.toLocaleDateString([], { weekday: "short", month: "short", day: "numeric" }) +
        " · " + d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
    } catch (e) { return raw; }
  }

  function ensureCss() {
    if (document.getElementById("etel-ann-css")) return;
    var s = document.createElement("style");
    s.id = "etel-ann-css";
    s.textContent =
      "#etel-ann-overlay{position:fixed;inset:0;z-index:9500;display:none;align-items:flex-end;justify-content:center;background:rgba(5,5,10,.68);backdrop-filter:blur(5px);font-family:'Outfit',sans-serif}" +
      "#etel-ann-overlay.open{display:flex}" +
      "#etel-ann-sheet{width:min(520px,100vw);height:min(88vh,760px);background:linear-gradient(180deg,#14141f 0%,#0b0b13 100%);border:1px solid rgba(212,175,55,.16);border-bottom:none;border-radius:22px 22px 0 0;display:flex;flex-direction:column;transform:translateY(100%);transition:transform .32s cubic-bezier(.22,1,.36,1);overflow:hidden;box-shadow:0 -20px 60px -20px rgba(0,0,0,.6)}" +
      "#etel-ann-overlay.open #etel-ann-sheet{transform:translateY(0)}" +
      "#etel-ann-handle-wrap{display:flex;justify-content:center;padding:9px 0 2px;flex-shrink:0}" +
      "#etel-ann-handle{width:36px;height:4px;border-radius:3px;background:rgba(255,255,255,.18)}" +
      "#etel-ann-head{display:flex;align-items:center;gap:12px;padding:14px 18px 16px;border-bottom:1px solid rgba(255,255,255,.07);flex-shrink:0}" +
      "#etel-ann-icon{width:44px;height:44px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:21px;flex-shrink:0}" +
      "#etel-ann-title{font-family:'Sora',serif;font-size:16px;font-weight:700;color:#EDEDF5;line-height:1.25}" +
      "#etel-ann-sub{font-size:11.5px;color:#8A8AA8;margin-top:2px}" +
      "#etel-ann-close{margin-left:auto;width:32px;height:32px;border-radius:50%;flex-shrink:0;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);color:#9A9AAE;font-size:15px;cursor:pointer}" +
      "#etel-ann-body{flex:1;overflow-y:auto;padding:14px 16px 22px}" +
      ".etel-ann-card{border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.03);border-radius:16px;padding:15px 16px;margin-bottom:12px;position:relative}" +
      ".etel-ann-card.unread{border-color:rgba(212,175,55,.35);background:rgba(212,175,55,.05)}" +
      ".etel-ann-card-title{display:flex;align-items:center;gap:8px;font-size:14.5px;font-weight:700;color:#F5EDD8;line-height:1.35;margin-bottom:6px}" +
      ".etel-ann-dot{width:7px;height:7px;border-radius:50%;background:#D4AF37;flex-shrink:0;box-shadow:0 0 0 3px rgba(212,175,55,.18)}" +
      ".etel-ann-card-body{font-size:13px;line-height:1.65;color:rgba(245,237,216,.82);white-space:pre-wrap}" +
      ".etel-ann-card-date{font-size:10.5px;color:rgba(245,237,216,.4);margin-top:9px}" +
      ".etel-ann-empty,.etel-ann-loading{text-align:center;padding:60px 20px;color:#6B6E82;font-size:13px}" +
      ".etel-ann-empty-icon{font-size:36px;margin-bottom:10px;opacity:.7}";
    document.head.appendChild(s);
  }

  function ensureOverlay() {
    if (document.getElementById("etel-ann-overlay")) return;
    var wrap = document.createElement("div");
    wrap.id = "etel-ann-overlay";
    wrap.innerHTML =
      '<div id="etel-ann-sheet">' +
      '<div id="etel-ann-handle-wrap"><div id="etel-ann-handle"></div></div>' +
      '<div id="etel-ann-head">' +
      '<div id="etel-ann-icon"></div>' +
      '<div><div id="etel-ann-title"></div><div id="etel-ann-sub"></div></div>' +
      '<button id="etel-ann-close" aria-label="Close">✕</button>' +
      "</div>" +
      '<div id="etel-ann-body"></div>' +
      "</div>";
    wrap.addEventListener("click", function (e) { if (e.target === wrap) closeAnnouncements(); });
    document.body.appendChild(wrap);
    document.getElementById("etel-ann-close").addEventListener("click", closeAnnouncements);
  }

  function closeAnnouncements() {
    var overlay = document.getElementById("etel-ann-overlay");
    if (overlay) overlay.classList.remove("open");
  }

  function render(list, meta) {
    var body = document.getElementById("etel-ann-body");
    if (!list.length) {
      body.innerHTML = '<div class="etel-ann-empty"><div class="etel-ann-empty-icon">' + meta.icon + '</div>No ' + esc(meta.label.toLowerCase()) + ' yet.</div>';
      return;
    }
    body.innerHTML = list.map(function (a) {
      return '<div class="etel-ann-card' + (a.is_read ? "" : " unread") + '">' +
        '<div class="etel-ann-card-title">' + (a.is_read ? "" : '<span class="etel-ann-dot"></span>') + esc(a.title) + "</div>" +
        '<div class="etel-ann-card-body">' + esc(a.body) + "</div>" +
        '<div class="etel-ann-card-date">' + esc(fmtDate(a.created_at)) + "</div>" +
        "</div>";
    }).join("");

    list.forEach(function (a) {
      if (!a.is_read && typeof window.drvApi === "function") {
        window.drvApi("driver_mark_announcement_read", { announcement_id: a.id }, function () {}, function () {});
      }
    });
  }

  window.openAnnouncements = function (category) {
    ensureCss();
    ensureOverlay();
    var meta = CATEGORY_META[category] || { label: "Notifications & Updates", icon: "🔔", sub: "", accent: "#D4AF37" };

    document.getElementById("etel-ann-icon").textContent = meta.icon;
    document.getElementById("etel-ann-icon").style.background = "rgba(212,175,55,.12)";
    document.getElementById("etel-ann-icon").style.color = meta.accent;
    document.getElementById("etel-ann-title").textContent = meta.label;
    document.getElementById("etel-ann-sub").textContent = meta.sub;
    document.getElementById("etel-ann-body").innerHTML = '<div class="etel-ann-loading">Loading…</div>';
    document.getElementById("etel-ann-overlay").classList.add("open");

    if (typeof window.drvApi !== "function") {
      document.getElementById("etel-ann-body").innerHTML = '<div class="etel-ann-empty">Could not load - app not ready yet.</div>';
      return;
    }
    window.drvApi("driver_get_announcements", { category: category || "" },
      function (data) {
        var list = (data && data.announcements) || [];
        render(list, meta);
      },
      function (errMsg) {
        document.getElementById("etel-ann-body").innerHTML = '<div class="etel-ann-empty">Could not load updates: ' + esc(errMsg || "unknown error") + "</div>";
      }
    );
  };

  window.closeAnnouncements = closeAnnouncements;
})();

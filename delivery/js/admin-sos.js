/* ETEL Admin SOS Monitor - "intruder alert" style banner for driver/rider
 * panic-button reports (see js/sos-button.js + api sos_trigger).
 * Polls admin_get_sos_alerts every few seconds from anywhere in admin.html,
 * regardless of which tab is open, flashes a full-screen red alert with
 * sound the moment a new open SOS comes in, and lets admin resolve it.
 * Uses the global api() helper + ETEL.api from js/core.js.
 */
(function () {
    "use strict";

    var POLL_MS = 6000;
    var seen = {}; // alert id -> true, so we only "ring" once per alert
    var pollTimer = null;
    var audioCtx = null;
    var ringing = false;
    var ringInterval = null;

    function css() {
        if (document.getElementById("admsos-css")) return;
        var s = document.createElement("style");
        s.id = "admsos-css";
        s.textContent =
            "#admsos-bar{display:none;position:fixed;top:0;left:0;right:0;z-index:99999;" +
            "background:linear-gradient(90deg,#B0141F,#E63946,#B0141F);color:#fff;" +
            "font-family:'Outfit',sans-serif;padding:10px 16px;align-items:center;gap:12px;" +
            "box-shadow:0 4px 18px rgba(0,0,0,.5);cursor:pointer;animation:admsosFlash 1s ease-in-out infinite}" +
            "#admsos-bar.show{display:flex}" +
            "@keyframes admsosFlash{0%,100%{filter:brightness(1)}50%{filter:brightness(1.35)}}" +
            "#admsos-bar .admsos-icn{font-size:22px}" +
            "#admsos-bar .admsos-txt{flex:1;font-weight:800;font-size:13.5px;letter-spacing:.02em}" +
            "#admsos-bar .admsos-txt small{display:block;font-weight:500;opacity:.9;font-size:11.5px;margin-top:1px}" +
            "#admsos-bar button{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.5);" +
            "color:#fff;font-weight:700;font-size:12px;padding:6px 12px;border-radius:8px;cursor:pointer}" +
            "#admsos-modal-bg{display:none;position:fixed;inset:0;z-index:99998;background:rgba(10,0,0,.65);" +
            "align-items:flex-start;justify-content:center;padding:60px 16px 16px;overflow-y:auto}" +
            "#admsos-modal-bg.open{display:flex}" +
            "#admsos-modal{width:min(560px,100%);background:linear-gradient(180deg,#1a0a0a,#0a0303);" +
            "border:1px solid rgba(230,57,70,.4);border-radius:18px;padding:20px;font-family:'Outfit',sans-serif;color:#F5EDE8}" +
            "#admsos-modal h3{font-family:'Sora',sans-serif;color:#FF6B6B;font-size:16px;margin-bottom:12px;" +
            "display:flex;align-items:center;justify-content:space-between}" +
            "#admsos-modal h3 button{background:none;border:1px solid #444;color:#F5EDE8;border-radius:6px;" +
            "padding:3px 9px;font-size:12px;cursor:pointer}" +
            ".admsos-row{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);" +
            "border-radius:12px;padding:12px;margin-bottom:10px}" +
            ".admsos-row.open{border-color:rgba(230,57,70,.5);background:rgba(230,57,70,.08)}" +
            ".admsos-row-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}" +
            ".admsos-role{font-weight:800;font-size:12.5px;padding:3px 9px;border-radius:999px}" +
            ".admsos-role.driver{background:rgba(96,180,255,.15);color:#60B4FF}" +
            ".admsos-role.rider{background:rgba(212,175,55,.15);color:#D4AF37}" +
            ".admsos-time{font-size:11px;color:#9A9AAE}" +
            ".admsos-name{font-size:13.5px;font-weight:700;margin-bottom:2px}" +
            ".admsos-sub{font-size:12px;color:#B8B0A0;margin-bottom:8px}" +
            ".admsos-actions{display:flex;gap:8px}" +
            ".admsos-actions a,.admsos-actions button{flex:1;text-align:center;padding:8px;border-radius:9px;" +
            "font-size:12px;font-weight:700;text-decoration:none;cursor:pointer;border:none}" +
            ".admsos-map{background:rgba(96,180,255,.12);color:#60B4FF;border:1px solid rgba(96,180,255,.3)!important}" +
            ".admsos-resolve{background:linear-gradient(135deg,#2ECC71,#1E8E4E);color:#06210f}" +
            "#admsos-empty{text-align:center;color:#9A9AAE;padding:20px;font-size:13px}";
        document.head.appendChild(s);
    }

    function buildDom() {
        css();
        if (!document.getElementById("admsos-bar")) {
            var bar = document.createElement("div");
            bar.id = "admsos-bar";
            bar.innerHTML =
                '<span class="admsos-icn">\uD83C\uDD98</span>' +
                '<span class="admsos-txt" id="admsos-bar-txt">SOS alert</span>' +
                '<button id="admsos-bar-btn">View</button>';
            bar.onclick = openModal;
            document.body.appendChild(bar);
        }
        if (!document.getElementById("admsos-modal-bg")) {
            var bg = document.createElement("div");
            bg.id = "admsos-modal-bg";
            bg.innerHTML =
                '<div id="admsos-modal">' +
                "<h3>\uD83C\uDD98 SOS Alerts <button id=\"admsos-close\">\u2715 Close</button></h3>" +
                '<div id="admsos-list"><div id="admsos-empty">Loading…</div></div>' +
                "</div>";
            bg.addEventListener("click", function (e) {
                if (e.target === bg) closeModal();
            });
            document.body.appendChild(bg);
            document.getElementById("admsos-close").onclick = closeModal;
        }
    }

    function apiBase() {
        return (typeof ETEL !== "undefined" && ETEL.api) ? ETEL.api : "api/boot.php";
    }
    function authHeader() {
        var t = "";
        try { t = (typeof Auth !== "undefined" && Auth.getToken) ? Auth.getToken() : (localStorage.getItem("etel_token") || ""); } catch (e) {}
        return { "Content-Type": "application/json", Authorization: "Bearer " + t };
    }

    function fetchAlerts(status, cb) {
        fetch(apiBase() + "?action=admin_get_sos_alerts&status=" + encodeURIComponent(status), {
            headers: authHeader(),
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && (res.success || res.ok) && res.data) cb(res.data.alerts || []);
                else cb([]);
            })
            .catch(function () { cb(null); });
    }

    function esc(s) {
        return String(s == null ? "" : s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
    }

    function timeAgo(dtStr) {
        if (!dtStr) return "";
        var d = new Date(dtStr.replace(" ", "T") + "Z");
        var mins = Math.floor((Date.now() - d.getTime()) / 60000);
        if (mins < 1) return "just now";
        if (mins < 60) return mins + "m ago";
        return Math.floor(mins / 60) + "h ago";
    }

    function playSiren() {
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            if (!audioCtx) audioCtx = new Ctx();
            if (audioCtx.state === "suspended") audioCtx.resume().catch(function () {});
            [700, 1000].forEach(function (freq, i) {
                var o = audioCtx.createOscillator();
                var g = audioCtx.createGain();
                o.type = "sawtooth";
                o.frequency.value = freq;
                g.gain.setValueAtTime(0.0001, audioCtx.currentTime);
                var t0 = audioCtx.currentTime + i * 0.22;
                g.gain.exponentialRampToValueAtTime(0.7, t0 + 0.03);
                g.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.4);
                o.connect(g);
                g.connect(audioCtx.destination);
                o.start(t0);
                o.stop(t0 + 0.42);
            });
        } catch (e) {}
    }

    function startRinging() {
        if (ringing) return;
        ringing = true;
        playSiren();
        ringInterval = setInterval(playSiren, 1600);
        try { if (navigator.vibrate) navigator.vibrate([300, 150, 300, 150, 300]); } catch (e) {}
    }

    function stopRinging() {
        ringing = false;
        if (ringInterval) { clearInterval(ringInterval); ringInterval = null; }
    }

    function renderBar(openAlerts) {
        var bar = document.getElementById("admsos-bar");
        var txt = document.getElementById("admsos-bar-txt");
        if (!bar || !txt) return;
        if (!openAlerts.length) {
            bar.classList.remove("show");
            stopRinging();
            return;
        }
        var latest = openAlerts[0];
        var roleLabel = latest.role === "driver" ? "Driver" : "Rider";
        txt.innerHTML =
            openAlerts.length +
            " active SOS alert" + (openAlerts.length === 1 ? "" : "s") +
            "<small>Latest: " + roleLabel + " " + esc(latest.reporter_name || "Unknown") + " \u00b7 " + timeAgo(latest.created_at) + "</small>";
        bar.classList.add("show");
        startRinging();
    }

    function renderList(alerts) {
        var list = document.getElementById("admsos-list");
        if (!list) return;
        if (alerts === null) {
            list.innerHTML = '<div id="admsos-empty">Could not load alerts — check connection.</div>';
            return;
        }
        if (!alerts.length) {
            list.innerHTML = '<div id="admsos-empty">No SOS alerts. All clear.</div>';
            return;
        }
        list.innerHTML = alerts
            .map(function (a) {
                var mapLink = a.lat && a.lng ? "https://maps.google.com/?q=" + a.lat + "," + a.lng : null;
                var roleLabel = a.role === "driver" ? "Driver" : "Rider";
                return (
                    '<div class="admsos-row ' + (a.status === "open" ? "open" : "") + '">' +
                    '<div class="admsos-row-top">' +
                    '<span class="admsos-role ' + esc(a.role) + '">' + roleLabel + "</span>" +
                    '<span class="admsos-time">' + timeAgo(a.created_at) + "</span>" +
                    "</div>" +
                    '<div class="admsos-name">' + esc(a.reporter_name || "Unknown user") + "</div>" +
                    '<div class="admsos-sub">' +
                    (a.reporter_phone ? "\uD83D\uDCDE " + esc(a.reporter_phone) + " \u00b7 " : "") +
                    (a.ride_id ? "Ride #" + esc(a.ride_id) : "No active ride linked") +
                    "</div>" +
                    '<div class="admsos-actions">' +
                    (mapLink
                        ? '<a class="admsos-map" href="' + mapLink + '" target="_blank" rel="noopener">\uD83D\uDCCD View location</a>'
                        : '<span class="admsos-map" style="opacity:.5;pointer-events:none">No location</span>') +
                    (a.reporter_phone ? '<a class="admsos-map" href="tel:' + esc(a.reporter_phone) + '">\uD83D\uDCDE Call</a>' : "") +
                    (a.status === "open"
                        ? '<button class="admsos-resolve" onclick="AdminSOS.resolve(' + a.id + ')">\u2705 Resolve</button>'
                        : '<span style="flex:1;text-align:center;color:#9A9AAE;font-size:12px;padding-top:8px">Resolved</span>') +
                    "</div>" +
                    "</div>"
                );
            })
            .join("");
    }

    function openModal() {
        document.getElementById("admsos-modal-bg").classList.add("open");
        fetchAlerts("all", renderList);
    }
    function closeModal() {
        document.getElementById("admsos-modal-bg").classList.remove("open");
    }

    function resolve(id) {
        fetch(apiBase(), {
            method: "POST",
            headers: authHeader(),
            body: JSON.stringify({ action: "admin_resolve_sos", id: id }),
        })
            .then(function (r) { return r.json(); })
            .then(function () {
                fetchAlerts("all", renderList);
                poll();
            })
            .catch(function () {});
    }

    function poll() {
        fetchAlerts("open", function (alerts) {
            if (alerts === null) return;
            var newOnes = alerts.filter(function (a) { return !seen[a.id]; });
            alerts.forEach(function (a) { seen[a.id] = true; });
            renderBar(alerts);
            if (document.getElementById("admsos-modal-bg").classList.contains("open")) {
                fetchAlerts("all", renderList);
            }
            if (newOnes.length && document.title.indexOf("\uD83C\uDD98") !== 0) {
                document.title = "\uD83C\uDD98 " + document.title;
            }
        });
    }

    function init() {
        // Only run this on pages where an admin is actually logged in.
        try {
            var u = (typeof Auth !== "undefined" && Auth.getUser) ? Auth.getUser() : JSON.parse(localStorage.getItem("etel_user") || "null");
            if (!u || u.role !== "admin") return;
        } catch (e) {
            return;
        }
        // CAPABILITY GUARD: this used to start polling admin_get_sos_alerts
        // unconditionally for any admin-role account, including capability-
        // restricted EtelGroup-managed staff (Finance, Traffic Officer, etc.)
        // who don't have 'sos.view'. That's a correct, intentional 403 from
        // require_admin() every single poll (every 6s, forever, for the
        // entire time that staff member has the tab open) - but it's pure
        // log/network noise for an account that will never legitimately see
        // an SOS alert. Check capabilities once via the same
        // admin_get_my_capabilities call the rest of admin.html already
        // relies on, and only start polling if this account can actually
        // use the feature. Wildcard ['*'] (the founder / non-managed admin
        // account - see user_capabilities() in boot.php) always passes.
        fetch(apiBase() + "?action=admin_get_my_capabilities", { headers: authHeader() })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                var caps = (res && (res.success || res.ok) && res.data && res.data.capabilities) || [];
                var allowed = caps.indexOf("*") !== -1 || caps.indexOf("sos.view") !== -1;
                if (!allowed) return; // silently skip - this staff role just doesn't have SOS access
                buildDom();
                poll();
                pollTimer = setInterval(poll, POLL_MS);
            })
            .catch(function () {
                // If the capability check itself fails (network hiccup, etc.)
                // fail closed - don't start polling an endpoint we don't yet
                // know this account is allowed to use.
            });
        document.addEventListener("click", function () {
            // Any admin interaction with the page is implicit acknowledgement
            // that they've seen the flashing title; stop decorating it.
            if (document.title.indexOf("\uD83C\uDD98 ") === 0) {
                document.title = document.title.replace("\uD83C\uDD98 ", "");
            }
        });
    }

    window.AdminSOS = { resolve: resolve, open: openModal, close: closeModal };

    if (document.readyState !== "loading") init();
    else document.addEventListener("DOMContentLoaded", init);
})();

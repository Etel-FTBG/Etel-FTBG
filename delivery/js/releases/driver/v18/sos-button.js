/* ETEL SOS / Panic Button - shared widget for driver/index.html and ride/index.html
 * Floating red SOS button. Tap -> confirm -> sends current GPS + active ride id
 * straight to admin (sos_trigger). Admin sees it live via js/admin-sos.js.
 * Depends on: ETEL.api, Auth (from driver-bundle.js / ride-bundle.js), and
 * either window.DRV (driver app) or window.RIDE (rider app) for the current
 * ride id + last known coordinates. Safe to include on either page.
 */
(function () {
    "use strict";

    function css() {
        if (document.getElementById("sos-css")) return;
        var s = document.createElement("style");
        s.id = "sos-css";
        s.textContent =
            "#sos-fab{position:fixed;left:14px;top:calc(env(safe-area-inset-top,0px) + 84px);" +
            "width:46px;height:46px;border-radius:50%;z-index:9500;border:2px solid rgba(255,255,255,.85);" +
            "background:#C62832;color:#fff;font-weight:800;font-size:11px;" +
            "font-family:'Outfit',sans-serif;letter-spacing:.04em;cursor:pointer;" +
            "box-shadow:0 4px 12px rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;" +
            "line-height:1;text-align:center;gap:1px}" +
            "#sos-overlay{display:none;position:fixed;inset:0;z-index:99997;background:rgba(10,0,0,.72);" +
            "backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px}" +
            "#sos-overlay.open{display:flex}" +
            "#sos-box{width:min(360px,100%);background:linear-gradient(180deg,#1a0a0a,#0a0303);" +
            "border:1px solid rgba(230,57,70,.45);border-radius:20px;padding:24px 22px;text-align:center;" +
            "font-family:'Outfit',sans-serif;color:#F5EDE8}" +
            "#sos-box .sos-icn{font-size:40px;margin-bottom:8px}" +
            "#sos-box h3{font-family:'Sora',sans-serif;color:#FF6B6B;font-size:17px;margin-bottom:8px}" +
            "#sos-box p{font-size:13px;color:rgba(245,237,232,.75);line-height:1.5;margin-bottom:16px}" +
            "#sos-box button{width:100%;padding:13px;border-radius:12px;border:none;font-weight:800;font-size:14px;" +
            "cursor:pointer;margin-bottom:10px;font-family:inherit}" +
            "#sos-send{background:linear-gradient(135deg,#FF5A5F,#C62828);color:#fff}" +
            "#sos-cancel{background:rgba(255,255,255,.08);color:#F5EDE8;border:1px solid rgba(255,255,255,.15)!important}" +
            "#sos-status{font-size:12px;margin-top:2px;min-height:16px;color:#FFB199}" +
            // Was pinned to the exact same top-left corner as the app's
            // own branding/logo, which it sat on top of and covered
            // completely. Moved down to the bottom-left, alongside the
            // fab it belongs to - clear of both the header and (on pages
            // that have one) a bottom tab bar, and still an easy thumb
            // reach in an emergency.
            "#sos-sent-badge{position:fixed;left:14px;top:calc(env(safe-area-inset-top,0px) + 138px);" +
            "z-index:9501;background:#2ECC71;color:#06210f;font-weight:800;font-size:11px;padding:8px 12px;" +
            "border-radius:12px;display:none;font-family:'Outfit',sans-serif;box-shadow:0 6px 16px rgba(0,0,0,.4)}";
        document.head.appendChild(s);
    }

    function activeRideId() {
        try {
            if (window.DRV && DRV.activeRide && DRV.activeRide.id) return DRV.activeRide.id;
            if (window.RIDE && RIDE.currentRideId) return RIDE.currentRideId;
        } catch (e) {}
        return null;
    }

    function lastKnownLatLng() {
        try {
            if (window.DRV && DRV.currentLat) return { lat: DRV.currentLat, lng: DRV.currentLng };
            if (window.RIDE && RIDE.pickupLatLng) return { lat: RIDE.pickupLatLng.lat, lng: RIDE.pickupLatLng.lng };
        } catch (e) {}
        return { lat: null, lng: null };
    }

    function build() {
        if (document.getElementById("sos-fab")) return;
        css();
        var fab = document.createElement("button");
        fab.id = "sos-fab";
        fab.innerHTML = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.9\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\"><path d=\"M12 3.5l7 2.6v5.4c0 4.2-2.9 7.3-7 9-4.1-1.7-7-4.8-7-9V6.1z\"/><path d=\"M12 8.5v4M12 15.2v.1\"/></svg><span>SOS</span>";
        fab.style.flexDirection = "column";
        fab.setAttribute("aria-label", "Emergency SOS");
        fab.onclick = openModal;
        document.body.appendChild(fab);

        var badge = document.createElement("div");
        badge.id = "sos-sent-badge";
        badge.textContent = "Alert sent to ETEL support";
        document.body.appendChild(badge);

        var overlay = document.createElement("div");
        overlay.id = "sos-overlay";
        overlay.innerHTML =
            '<div id="sos-box">' +
            '<div class="sos-icn" style="color:#FF6B6B"><svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3.5l7 2.6v5.4c0 4.2-2.9 7.3-7 9-4.1-1.7-7-4.8-7-9V6.1z"/><path d="M12 8.5v4M12 15.2v.1"/></svg></div>' +
            "<h3>Send emergency alert?</h3>" +
            "<p>This immediately notifies ETEL support with your role, location, and current trip (if any). Use this if you feel unsafe or something is wrong right now.</p>" +
            '<button id="sos-send">Send SOS Now</button>' +
            '<button id="sos-cancel">Cancel</button>' +
            '<div id="sos-status"></div>' +
            "</div>";
        overlay.addEventListener("click", function (e) {
            if (e.target === overlay) closeModal();
        });
        document.body.appendChild(overlay);
        document.getElementById("sos-cancel").onclick = closeModal;
        document.getElementById("sos-send").onclick = send;
    }

    function openModal() {
        var st = document.getElementById("sos-status");
        if (st) st.textContent = "";
        var sendBtn = document.getElementById("sos-send");
        if (sendBtn) { sendBtn.disabled = false; sendBtn.textContent = "Send SOS Now"; }
        document.getElementById("sos-overlay").classList.add("open");
    }

    function closeModal() {
        document.getElementById("sos-overlay").classList.remove("open");
    }

    function apiBase() {
        return (typeof ETEL !== "undefined" && ETEL.api) ? ETEL.api : "api/boot.php";
    }

    function authHeader() {
        var t = "";
        try { t = (typeof Auth !== "undefined" && Auth.getToken) ? Auth.getToken() : (localStorage.getItem("etel_token") || ""); } catch (e) {}
        return { "Content-Type": "application/json", Authorization: "Bearer " + t };
    }

    function send() {
        var btn = document.getElementById("sos-send");
        var st = document.getElementById("sos-status");
        if (btn) { btn.disabled = true; btn.textContent = "Sending…"; }
        if (st) st.textContent = "Sending your location…";
        var pos = lastKnownLatLng();
        var payload = { ride_id: activeRideId() || "", lat: pos.lat || "", lng: pos.lng || "" };

        function post(p) {
            fetch(apiBase(), {
                method: "POST",
                headers: authHeader(),
                body: JSON.stringify(Object.assign({ action: "sos_trigger" }, p)),
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && (res.success || res.ok)) {
                        closeModal();
                        var badge = document.getElementById("sos-sent-badge");
                        if (badge) {
                            badge.style.display = "block";
                            setTimeout(function () { badge.style.display = "none"; }, 6000);
                        }
                        try { if (navigator.vibrate) navigator.vibrate([200, 100, 200]); } catch (e) {}
                    } else {
                        if (st) st.textContent = (res && (res.message || (res.data && res.data.message))) || "Could not send - try again";
                        if (btn) { btn.disabled = false; btn.textContent = "Send SOS Now"; }
                    }
                })
                .catch(function () {
                    if (st) st.textContent = "Network error - try again";
                    if (btn) { btn.disabled = false; btn.textContent = "Send SOS Now"; }
                });
        }

        if ((!pos.lat || !pos.lng) && navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                function (p) {
                    payload.lat = p.coords.latitude;
                    payload.lng = p.coords.longitude;
                    post(payload);
                },
                function () { post(payload); },
                { enableHighAccuracy: true, timeout: 4000 }
            );
        } else {
            post(payload);
        }
    }

    if (document.readyState !== "loading") build();
    else document.addEventListener("DOMContentLoaded", build);
})();

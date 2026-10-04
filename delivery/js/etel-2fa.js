/* Etel2FA.ask(message) -> Promise<string|null>
   Asks for the 6-digit authenticator code (or a backup code) during sign-in
   and resolves with what was typed, or null if cancelled. Used by login.html
   and admin-login.html when the server answers { requires_2fa: true }. */
(function () {
    "use strict";
    function ask(message) {
        return new Promise(function (resolve) {
            var old = document.getElementById("etel-2fa-overlay");
            if (old) old.remove();
            var el = document.createElement("div");
            el.id = "etel-2fa-overlay";
            el.setAttribute("role", "dialog");
            el.setAttribute("aria-modal", "true");
            el.setAttribute("aria-label", "Two-step sign-in");
            el.style.cssText = "position:fixed;inset:0;z-index:100001;background:rgba(0,0,0,.7);display:flex;align-items:center;justify-content:center;padding:18px;font-family:Outfit,system-ui,sans-serif";
            var card = document.createElement("form");
            card.style.cssText = "width:100%;max-width:380px;background:#11131C;color:#F7F5EE;border:1px solid rgba(212,175,55,.35);border-radius:20px;padding:22px 20px 18px;box-shadow:0 20px 60px rgba(0,0,0,.6)";
            card.innerHTML =
                '<div style="font-weight:800;font-size:18px;margin-bottom:6px">Two-step sign-in</div>' +
                '<div id="etel-2fa-msg" style="font-size:14px;color:#C4C6D0;line-height:1.5;margin-bottom:14px"></div>' +
                '<input id="etel-2fa-code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" placeholder="123456" aria-label="Verification code" ' +
                'style="width:100%;box-sizing:border-box;min-height:52px;border-radius:14px;border:1px solid rgba(212,175,55,.5);background:#0B0C12;color:#F7F5EE;font-size:24px;letter-spacing:.3em;text-align:center">' +
                '<div style="font-size:12px;color:#9A9AAE;margin:8px 2px 14px">Lost your phone? Type one of your backup codes instead.</div>' +
                '<div style="display:flex;gap:8px"><button type="button" id="etel-2fa-cancel" style="flex:1;min-height:46px;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:transparent;color:#C4C6D0;font-size:15px">Cancel</button>' +
                '<button type="submit" style="flex:1.4;min-height:46px;border:0;border-radius:14px;background:linear-gradient(135deg,#F4D56A,#C89A24);color:#16120A;font-weight:800;font-size:15px">Verify</button></div>';
            card.querySelector("#etel-2fa-msg").textContent = message || "Enter the code from your authenticator app.";
            el.appendChild(card);
            document.body.appendChild(el);
            var input = card.querySelector("#etel-2fa-code");
            function done(v) { el.remove(); resolve(v); }
            card.addEventListener("submit", function (e) { e.preventDefault(); var v = (input.value || "").trim(); if (v) done(v); else input.focus(); });
            card.querySelector("#etel-2fa-cancel").onclick = function () { done(null); };
            setTimeout(function () { input.focus(); }, 50);
        });
    }
    window.Etel2FA = { ask: ask };
})();

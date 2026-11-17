/* ETEL Ride - Quick zones can be hidden with a button (v1.0)
 * The Quick zones box (#zone-pick, added by ride-fix.js) used to be fixed on the Book tab and took a lot of room.
 * This add-on puts a small button above it. Tap it to show or hide the zones. The choice is remembered on the phone.
 * Hidden by default. Own file: it does not edit ride-bundle.js. Works with any later list of zones (it only hides/shows the box).
 */
(function () {
  "use strict";
  if (window.__etelQzToggle) return;
  window.__etelQzToggle = true;

  var KEY = "etel_quickzones_open";

  function readOpen() { try { return localStorage.getItem(KEY) === "1"; } catch (e) { return false; } }
  function saveOpen(v) { try { localStorage.setItem(KEY, v ? "1" : "0"); } catch (e) {} }

  function css() {
    if (document.getElementById("qz-toggle-css")) return;
    var st = document.createElement("style");
    st.id = "qz-toggle-css";
    st.textContent =
      "#zone-pick.qz-hidden{display:none!important}" +
      "#view-book[data-step=\"2\"] > #qz-toggle-row{display:none!important}" +
      "#qz-toggle-row{margin:10px 0 0}" +
      "#qz-toggle-btn{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;padding:11px 14px;border-radius:14px;" +
      "border:1px solid rgba(212,175,55,.35);background:rgba(212,175,55,.08);color:var(--gold-lt,#E5C76B);font:inherit;font-size:13px;font-weight:700;cursor:pointer}" +
      "#qz-toggle-btn .qz-arrow{font-size:12px;opacity:.8}";
    document.head.appendChild(st);
  }

  function apply(box, row, open) {
    box.classList.toggle("qz-hidden", !open);
    var b = row.querySelector("#qz-toggle-btn");
    b.setAttribute("aria-expanded", open ? "true" : "false");
    b.querySelector(".qz-text").textContent = open ? "Hide quick zones" : "Show quick zones";
    b.querySelector(".qz-arrow").innerHTML = open ? "&#9650;" : "&#9660;";
  }

  function install() {
    var box = document.getElementById("zone-pick");
    if (!box || document.getElementById("qz-toggle-row")) return !!box;
    css();
    var row = document.createElement("div");
    row.id = "qz-toggle-row";
    row.innerHTML = '<button type="button" id="qz-toggle-btn" aria-controls="zone-pick" aria-expanded="false">' +
      '<span>&#128205; <span class="qz-text"></span> <span style="opacity:.6;font-weight:500">&middot; no GPS needed</span></span><span class="qz-arrow"></span></button>';
    box.parentNode.insertBefore(row, box);
    apply(box, row, readOpen());
    row.querySelector("#qz-toggle-btn").addEventListener("click", function () {
      var open = box.classList.contains("qz-hidden");
      saveOpen(open);
      apply(box, row, open);
    });
    return true;
  }

  // ride-fix.js adds the box after the page has loaded: try a few times, then watch for it.
  var tries = 0;
  function poll() {
    if (install()) return;
    if (++tries < 60) setTimeout(poll, 500);
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", poll); else poll();
})();

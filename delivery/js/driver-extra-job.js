/* ETEL Driver - "Extra job: deliveries" card on the profile page.
   A verified ride driver can ask to deliver orders and packages too. An admin approves in Admin -> Couriers; until then no delivery job reaches the driver.
   After approval the same account does both (it becomes a courier-role account that keeps its ride profile) - sign out and in once, then open Courier.
   Actions: driver_courier_status, driver_courier_apply (api/controllers/DriverCourierController.php). Server text goes through esc; no inline handlers. */
(function () {
'use strict';
var T = {
  en: { title: 'Extra job: deliveries', ask: 'Ask for the courier job', again: 'Ask again', open: 'Open Courier', sending: 'Sending…', badge: 'Pending',
    admin: 'You are signed in as an admin, so you can already open Courier and work deliveries. Drivers ask for the courier job here and you approve them in Admin, Couriers.',
    none: 'Earn more: deliver orders and packages too, with the same account and the same vehicle. An admin approves you first.',
    pending: 'Waiting for the admin. You will see the answer here. Until then you get no delivery jobs.',
    approved: 'You also do deliveries. Sign out and sign in once, then open Courier.',
    rejected: 'The admin did not approve this time.', suspended: 'Your delivery job is paused by the admin.', verify: 'Finish your driver verification first, then you can ask for the courier job.' },
  am: { title: 'ተጨማሪ ሥራ: ማድረስ', ask: 'የኩሪየር ሥራ ጠይቅ', again: 'እንደገና ጠይቅ', open: 'ኩሪየር ክፈት', sending: 'በመላክ ላይ…', badge: 'በመጠባበቅ ላይ',
    admin: 'በአስተዳዳሪነት ገብተዋል፣ ስለዚህ ኩሪየርን ቀድመው መክፈት ይችላሉ። ሹፌሮች የኩሪየር ሥራ እዚህ ይጠይቃሉ፣ እርስዎ በአስተዳዳሪው ኩሪየርስ ውስጥ ያጸድቃሉ።',
    none: 'ተጨማሪ ያግኙ: በተመሳሳይ መለያና ተሽከርካሪ ትዕዛዞችንና ጥቅሎችን ያደርሱ። መጀመሪያ አስተዳዳሪ ያጸድቅዎታል።',
    pending: 'አስተዳዳሪውን በመጠበቅ ላይ። መልሱን እዚህ ያያሉ። እስከዚያ የማድረስ ሥራ አይደርስዎትም።',
    approved: 'ማድረስም ያደርጋሉ። አንድ ጊዜ ይውጡና ይግቡ፣ ከዚያ ኩሪየርን ይክፈቱ።',
    rejected: 'አስተዳዳሪው በዚህ ጊዜ አላጸደቀም።', suspended: 'የማድረስ ሥራዎ በአስተዳዳሪ ቆሟል።', verify: 'መጀመሪያ የሹፌር ማረጋገጫዎን ያጠናቅቁ።' }
};
function lang() { try { return localStorage.getItem('etel_driver_lang') === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
function tx(k) { return (T[lang()] || T.en)[k] || T.en[k] || k; }
function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
function api(action, cb) {
  var tok = ''; try { tok = localStorage.getItem('etel_token') || ''; } catch (e) { /* private window */ }
  var url = (window.ETEL && ETEL.api) || '../api/boot.php';
  fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + tok }, body: JSON.stringify({ action: action }) })
    .then(function (r) { return r.json(); }).then(function (j) { cb(j); }, function () { cb(null); });
}
function css() {
  if (document.getElementById('drv-xj-css')) return;
  var s = document.createElement('style'); s.id = 'drv-xj-css';
  s.textContent = '#drv-xj{margin:12px 0;padding:14px;border-radius:16px;border:1.5px solid rgba(212,175,55,.5);background:linear-gradient(135deg,rgba(212,175,55,.14),rgba(212,175,55,.04));color:inherit}' +
    '#drv-xj h4{margin:0 0 6px;font-size:14px;font-weight:800}#drv-xj p{margin:0 0 10px;font-size:12.5px;line-height:1.5;opacity:.9}' +
    '#drv-xj .xj-b{display:inline-block;border:0;border-radius:12px;padding:10px 16px;font:inherit;font-weight:800;font-size:13px;cursor:pointer;text-decoration:none;color:#1c1500;background:linear-gradient(135deg,#f3d36b,#c99a1c)}' +
    '#drv-xj .xj-s{display:inline-block;font-size:11px;font-weight:800;padding:3px 9px;border-radius:20px;margin-bottom:8px}' +
    '#drv-xj .pending{background:rgba(240,180,41,.2);color:#b07a00}#drv-xj .approved{background:rgba(31,164,99,.18);color:#1fa463}#drv-xj .rejected,#drv-xj .suspended{background:rgba(214,54,74,.15);color:#d6364a}';
  document.head.appendChild(s);
}
var last = 0;
function paint(st) {
  var host = document.querySelector('#page-profile .profile-body'); if (!host) return;
  var el = document.getElementById('drv-xj');
  if (!st || !st.is_ride_driver || (st.role !== 'driver' && st.role !== 'courier' && st.role !== 'admin')) { if (el) el.parentNode.removeChild(el); return; }
  css();
  if (!el) { el = document.createElement('div'); el.id = 'drv-xj'; host.insertBefore(el, host.firstChild); }
  var s = st.state, h = '<h4>🛵 ' + esc(tx('title')) + '</h4>';
  if (st.role === 'admin') h += '<span class="xj-s approved">✅</span><p>' + esc(tx('admin')) + '</p><a class="xj-b" href="../courier/">' + esc(tx('open')) + '</a>';
  else if (s === 'pending') h += '<span class="xj-s pending">⏳ ' + esc(tx('badge')) + '</span><p>' + esc(tx('pending')) + '</p>';
  else if (s === 'approved') {
    h += '<span class="xj-s approved">✅</span><p>' + esc(tx('approved')) + '</p><a class="xj-b" href="../courier/">' + esc(tx('open')) + '</a>';
    if (st.role === 'courier') { try { var u = JSON.parse(localStorage.getItem('etel_user') || 'null'); if (u && u.role !== 'courier') { u.role = 'courier'; localStorage.setItem('etel_user', JSON.stringify(u)); } } catch (e) { /* ignore */ } }
  } else if (s === 'suspended') h += '<span class="xj-s suspended">⛔</span><p>' + esc(tx('suspended')) + '</p>';
  else if (s === 'rejected') h += '<span class="xj-s rejected">✕ ' + esc(tx('rejected')) + '</span>' + (st.note ? '<p>' + esc(st.note) + '</p>' : '<p></p>') + (st.can_apply ? '<button type="button" class="xj-b" data-xj="apply">' + esc(tx('again')) + '</button>' : '');
  else h += '<p>' + esc(st.can_apply ? tx('none') : tx('verify')) + '</p>' + (st.can_apply ? '<button type="button" class="xj-b" data-xj="apply">' + esc(tx('ask')) + '</button>' : '');
  el.innerHTML = h;
}
function refresh(force) {
  var now = Date.now(); if (!force && now - last < 20000) return; last = now;
  api('driver_courier_status', function (j) { paint(j && j.success ? j.data : null); });
}
document.addEventListener('click', function (e) {
  var b = e.target.closest ? e.target.closest('[data-xj="apply"]') : null; if (!b) return;
  b.disabled = true; b.textContent = tx('sending');
  api('driver_courier_apply', function (j) {
    if (j && j.success) { paint((j.data || {}).status); if (typeof showDriverToast === 'function') showDriverToast((j.data || {}).message || 'Sent', 'ok'); }
    else { b.disabled = false; b.textContent = tx('ask'); if (typeof showDriverToast === 'function') showDriverToast((j && j.message) || 'Could not send', 'err'); }
  });
});
function boot() {
  refresh(true);
  var pg = document.getElementById('page-profile');
  if (pg && 'IntersectionObserver' in window) new IntersectionObserver(function (en) { if (en[0] && en[0].isIntersecting) refresh(false); }).observe(pg);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(false); });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(boot, 1200); }); else setTimeout(boot, 1200);
})();

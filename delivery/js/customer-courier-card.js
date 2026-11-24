/* ETEL Customer - "Your courier": who is coming, so the customer can recognise the person and the vehicle at the door (additive script).
   customer.js calls etelCourierCard(order) when the server sent order.courier_card. Shows the courier's photo, name, rating, deliveries, the REAL picture of
   the vehicle type, the plate number, make / colour, the vehicle photos (tap to enlarge) and a call button. Photo links are signed and expire, so each
   photo keeps the first address it got (no flicker on every refresh). Never shows ID, licence or home address (the server does not send them). English / Amharic. */
(function () {
  'use strict';
  var AM = { 'YOUR COURIER': 'አቅራቢዎ', 'Verified': 'የተረጋገጠ', 'deliveries': 'አቅርቦቶች', 'delivery': 'አቅርቦት', 'Since': 'ከ', 'Plate number': 'የሰሌዳ ቁጥር', 'Call': 'ደውል', 'Vehicle photos': 'የተሽከርካሪ ፎቶዎች', 'Close': 'ዝጋ',
    'Motorcycle': 'ሞተር ሳይክል', 'Bajaj': 'ባጃጅ', 'Qute (bajaj)': 'ኩቴ (ባጃጅ)', 'Car': 'መኪና', 'Electric car': 'ኤሌክትሪክ መኪና', 'Minibus': 'ሚኒባስ', 'Van': 'ቫን', 'Bicycle': 'ብስክሌት', 'On foot': 'በእግር', 'Courier': 'አቅራቢ', 'New courier': 'አዲስ አቅራቢ' };
  var LABEL = { moto: 'Motorcycle', motorcycle: 'Motorcycle', bajaj: 'Bajaj', qute: 'Qute (bajaj)', car: 'Car', ev_car: 'Electric car', minibus: 'Minibus', van: 'Van', express_cargo: 'Van', cargo: 'Van', bicycle: 'Bicycle', on_foot: 'On foot' };
  var seen = {};   // photo file -> { url, at }
  function lang() { try { return localStorage.getItem('etel_lang') === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
  function T(s) { return lang() === 'am' && AM[s] ? AM[s] : s; }
  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function safeUrl(u) { return /^(https?:)?\/\//.test(u || '') || /^\/[^\/]/.test(u || '') ? u : ''; }
  function stable(u) {   /* keep the first signed address of a photo for 50 minutes */
    u = safeUrl(u); if (!u) return '';
    var m = /[?&]f=([^&]+)/.exec(u), key = m ? m[1] : u.split('?')[0], now = Date.now();
    if (seen[key] && now - seen[key].at < 50 * 60000) return seen[key].url;
    seen[key] = { url: u, at: now }; return u;
  }
  function css() {
    if (document.getElementById('ccd-css')) return;
    var s = document.createElement('style'); s.id = 'ccd-css';
    s.textContent =
      '.ccd{margin-top:14px;border-radius:20px;border:1px solid rgba(212,175,55,.45);background:var(--glass-2);overflow:hidden;font-variant-numeric:lining-nums tabular-nums}' +
      '.ccd-top{display:flex;align-items:center;gap:14px;padding:16px 16px 12px}' +
      '.ccd-av{width:68px;height:68px;border-radius:50%;flex:none;overflow:hidden;background:radial-gradient(circle at 30% 25%,#F6DD84,#C99A1E 70%);display:flex;align-items:center;justify-content:center;font:800 28px system-ui,sans-serif;color:#1a1200;border:3px solid rgba(212,175,55,.7)}' +
      '.ccd-av img{width:100%;height:100%;object-fit:cover;display:block}' +
      '.ccd-id{min-width:0;flex:1}.ccd-k{font:800 10.5px system-ui,sans-serif;letter-spacing:.14em;color:#C99A1E}' +
      '.ccd-nm{font-size:18px;font-weight:800;color:var(--text);line-height:1.25;overflow-wrap:anywhere}' +
      '.ccd-st{font-size:12.5px;color:var(--text-muted);margin-top:3px}.ccd-ok{display:inline-block;margin-left:6px;padding:1px 8px;border-radius:999px;background:rgba(34,197,94,.16);color:#22c55e;font-size:10.5px;font-weight:800;vertical-align:middle}' +
      '.ccd-call{flex:none;width:46px;height:46px;border-radius:50%;background:rgba(34,197,94,.16);border:1px solid rgba(34,197,94,.4);display:flex;align-items:center;justify-content:center;font-size:20px;text-decoration:none}' +
      '.ccd-veh{display:flex;align-items:center;gap:14px;margin:0 12px;padding:12px;border-radius:16px;background:rgba(128,128,128,.1)}' +
      '.ccd-vp{flex:none;width:96px;display:flex;align-items:center;justify-content:center}.ccd-vp img{max-width:96px;height:auto}' +
      '.ccd-vt{font-size:15px;font-weight:800;color:var(--text)}.ccd-vi{font-size:12.5px;color:var(--text-muted);margin-top:2px;overflow-wrap:anywhere}' +
      '.ccd-plate{display:inline-block;margin-top:8px;padding:5px 12px;border-radius:8px;border:2px solid var(--text);background:#fff;color:#111;font:800 16px ui-monospace,Menlo,Consolas,monospace;letter-spacing:.12em}' +
      '.ccd-ph{display:flex;gap:10px;padding:12px 12px 14px;overflow-x:auto}.ccd-ph button{flex:none;width:96px;height:72px;border-radius:12px;overflow:hidden;border:1px solid rgba(212,175,55,.4);padding:0;background:#222;cursor:pointer}' +
      '.ccd-ph img{width:100%;height:100%;object-fit:cover;display:block}' +
      '.ccd-box{position:fixed;inset:0;z-index:2147482000;background:rgba(0,0,0,.88);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:16px}' +
      '.ccd-box img{max-width:100%;max-height:78vh;border-radius:14px}.ccd-box button{margin-top:14px;min-height:46px;padding:0 26px;border-radius:999px;border:0;background:#D4AF37;color:#1a1200;font:800 15px system-ui,sans-serif;cursor:pointer}';
    document.head.appendChild(s);
  }
  function vehiclePic(type) {
    if (window.EtelVehiclePic && EtelVehiclePic.has(type)) return EtelVehiclePic.html(type, 96, { fallback: EtelVehiclePic.emoji(type) });
    return '<span style="font-size:44px">' + esc((window.EtelVehiclePic && EtelVehiclePic.emoji(type)) || '🛵') + '</span>';
  }
  window.etelCourierCard = function (o) {
    var c = o && o.courier_card; if (!c) return '';
    css();
    var name = c.name || o.courier_name || T('Courier'), photo = stable(c.photo), type = String(c.vehicle_type || '').toLowerCase().replace(/[\s-]+/g, '_');
    var vt = LABEL[type] ? T(LABEL[type]) : (c.vehicle_type || T('Courier'));
    var stats = [];
    if (parseFloat(c.rating) > 0) stats.push('⭐ ' + parseFloat(c.rating).toFixed(1));
    if (c.deliveries > 0) stats.push(c.deliveries + ' ' + T(c.deliveries === 1 ? 'delivery' : 'deliveries')); else stats.push(T('New courier'));
    if (c.since) stats.push(T('Since') + ' ' + c.since);
    var photos = [stable(c.vehicle_photo_front), stable(c.vehicle_photo_side)].filter(Boolean);
    var avatar = photo ? '<img src="' + esc(photo) + '" alt="' + esc(name) + '" data-ccd-photo="1">' : esc(String(name).trim().charAt(0).toUpperCase());
    return '<div class="ccd" data-ccd="1">' +
      '<div class="ccd-top"><div class="ccd-av" data-ccd-initial="' + esc(String(name).trim().charAt(0).toUpperCase()) + '">' + avatar + '</div>' +
      '<div class="ccd-id"><div class="ccd-k">' + esc(T('YOUR COURIER')) + '</div><div class="ccd-nm">' + esc(name) + '</div>' +
      '<div class="ccd-st">' + esc(stats.join(' · ')) + '<span class="ccd-ok">✓ ' + esc(T('Verified')) + '</span></div></div>' +
      (c.phone ? '<a class="ccd-call" href="tel:' + esc(c.phone) + '" aria-label="' + esc(T('Call')) + '">📞</a>' : '') + '</div>' +
      '<div class="ccd-veh"><div class="ccd-vp">' + vehiclePic(type) + '</div><div style="min-width:0"><div class="ccd-vt">' + esc(vt) + '</div>' +
      (c.vehicle_info ? '<div class="ccd-vi">' + esc(c.vehicle_info) + '</div>' : '') +
      (c.plate ? '<div class="ccd-plate" aria-label="' + esc(T('Plate number')) + '">' + esc(c.plate) + '</div>' : '') + '</div></div>' +
      (photos.length ? '<div class="ccd-ph" aria-label="' + esc(T('Vehicle photos')) + '">' + photos.map(function (u) { return '<button type="button" data-ccd-zoom="' + esc(u) + '"><img src="' + esc(u) + '" alt="" loading="lazy"></button>'; }).join('') + '</div>' : '<div style="height:14px"></div>') +
      '</div>';
  };
  /* photos that fail to load fall back (image errors do not bubble: capture phase); a tap on a vehicle photo opens it large */
  document.addEventListener('error', function (e) {
    var im = e.target; if (!im || im.tagName !== 'IMG') return;
    if (im.getAttribute('data-ccd-photo')) { var av = im.parentNode; if (av) av.textContent = av.getAttribute('data-ccd-initial') || ''; }
    else if (im.closest && im.closest('.ccd-ph')) { var b = im.closest('button'); if (b && b.parentNode) b.parentNode.removeChild(b); }
  }, true);
  document.addEventListener('click', function (e) {
    var z = e.target && e.target.closest ? e.target.closest('[data-ccd-zoom]') : null;
    if (z) {
      var box = document.createElement('div'); box.className = 'ccd-box'; box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true');
      box.innerHTML = '<img src="' + esc(z.getAttribute('data-ccd-zoom')) + '" alt=""><button type="button" data-ccd-close="1">' + esc(T('Close')) + '</button>';
      document.body.appendChild(box); var cb = box.querySelector('button'); if (cb) cb.focus(); return;
    }
    var cl = e.target && e.target.closest ? e.target.closest('[data-ccd-close],.ccd-box') : null;
    if (cl && (cl.hasAttribute('data-ccd-close') || e.target === cl)) { var bx = document.querySelector('.ccd-box'); if (bx && bx.parentNode) bx.parentNode.removeChild(bx); }
  });
})();

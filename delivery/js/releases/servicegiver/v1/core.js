/**
 * ETEL Delivery v3.2.6 — Core Engine · Hawassa Edition
 * Serving Hawassa, Ethiopia · ሀዋሳ
 */

/* Captures errors/rejections from the earliest possible moment - before
   DiagPanel (further down this file) even exists. DiagPanel.init() only
   runs on the 'load' event, but page-specific boot code often runs on
   the EARLIER 'DOMContentLoaded' event - so without this, an error
   during early boot would be invisible to the diagnostic panel ("0
   errors" would mean "wasn't listening yet", not "nothing broke").
   DiagPanel replays this buffer into its own log once it starts. */
window.__etelEarlyErrors = [];
window.addEventListener('error', function(e) {
  window.__etelEarlyErrors.push('EARLY JS: ' + e.message + ' (' + (e.filename||'').split('/').pop() + ':' + e.lineno + ')');
});
window.addEventListener('unhandledrejection', function(e) {
  window.__etelEarlyErrors.push('EARLY Unhandled promise rejection: ' + (e.reason && e.reason.message || e.reason || 'unknown'));
});

/* Network logging ALSO needs to install this early, not inside
   DiagPanel.init() (which only runs on the 'load' event). Page boot
   code (like a driver-page's loadGoalTiers()) runs on the EARLIER
   'DOMContentLoaded' event - patching fetch only on 'load' meant those
   early calls used the ORIGINAL fetch and were invisible to this panel
   entirely, not even logged as failed - just silently absent, which is
   far more confusing than an error would have been. Buffered into a
   plain array (not DiagPanel._capture() directly - DiagPanel._con
   doesn't exist until _inject() runs, and _log() silently drops
   anything logged before that), replayed once the panel actually
   initializes. */
window.__etelEarlyNetworkLogs = [];
(function(){
  const origFetch = window.fetch.bind(window);
  window.fetch = function(input, init) {
    const url = typeof input === 'string' ? input : (input && input.url) || '';
    const method = (init && init.method) || (input && input.method) || 'GET';
    let actionLabel = '';
    try {
      if (init && init.body && typeof init.body === 'string') {
        const parsed = JSON.parse(init.body);
        if (parsed && parsed.action) actionLabel = ' [' + parsed.action + ']';
      }
    } catch (e) {}
    const start = performance.now();
    const record = (tag, msg) => {
      var handled = false;
      try { if (typeof DiagPanel !== 'undefined' && DiagPanel._con) { DiagPanel._capture(tag, [msg]); handled = true; } } catch (e) {}
      if (!handled) window.__etelEarlyNetworkLogs.push([tag, msg]);
    };
    return origFetch(input, init).then(res => {
      const ms = Math.round(performance.now() - start);
      const short = url.replace(location.origin, '');
      const label = method + ' ' + short + actionLabel;
      if (!res.ok) {
        record('er', 'NETWORK ' + label + ' -> ' + res.status + ' ' + res.statusText + ' (' + ms + 'ms)');
        return res;
      }
      res.clone().json().then(data => {
        if (data && data.success === false) {
          record('er', 'API ' + label + ' -> success:false: ' + (data.message || 'no message') + ' (' + ms + 'ms)');
        } else {
          record('nt', label + ' -> ' + res.status + ' (' + ms + 'ms)');
        }
      }).catch(() => {
        record('nt', label + ' -> ' + res.status + ' (' + ms + 'ms)');
      });
      return res;
    }).catch(err => {
      const ms = Math.round(performance.now() - start);
      const short = url.replace(location.origin, '');
      record('er', 'NETWORK ' + method + ' ' + short + actionLabel + ' -> FAILED: ' + err.message + ' (' + ms + 'ms)');
      throw err;
    });
  };
})();

/**
 * Escape a value for safe insertion into innerHTML. Use this for ANY
 * user-supplied or server-fetched string (names, addresses, phone numbers,
 * statuses, etc.) before interpolating it into a template string that gets
 * assigned to innerHTML. Prevents stored XSS.
 */
function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

const ETEL = {
  version: '3.2.6',
  city: 'Hawassa',
  city_am: 'ሀዋሳ',
  api: (function() {
    // Anchor on the known app-root marker rather than just "current
    // directory" - needed because ride.html/driver.html live one level
    // deeper (delivery/ride/) than the other pages (delivery/), but both
    // need to reach the SAME api/ folder at the delivery root. Falls back
    // to the old current-directory behavior if the marker isn't found
    // (e.g. a different deployment path), so this stays safe everywhere.
    const loc = window.location.pathname;
    const marker = '/delivery/';
    const markerIdx = loc.indexOf(marker);
    const dir = markerIdx >= 0 ? loc.substring(0, markerIdx + marker.length) : loc.substring(0, loc.lastIndexOf('/') + 1);
    return window.location.protocol + '//' + window.location.host + dir + 'api/boot.php';
  })(),

  // ── HAWASSA ZONES ──────────────────────────────────────────
  zones: [
    'Mehal Ketema','Tabor','Addis Ketema','Haik Dar','Tulu Dimtu',
    'Misrak','Referral','Hawela Teklehaimanot','Dore Bafana',
    'Stadium','Liqe Mender','University'
  ],
  zonePos: {
    'Mehal Ketema':          {x:.48, y:.42},
    'Tabor':                 {x:.35, y:.30},
    'Addis Ketema':          {x:.62, y:.35},
    'Haik Dar':              {x:.72, y:.50},
    'Tulu Dimtu':            {x:.58, y:.60},
    'Misrak':                {x:.40, y:.62},
    'Referral':              {x:.28, y:.50},
    'Hawela Teklehaimanot':  {x:.52, y:.75},
    'Dore Bafana':           {x:.22, y:.65},
    'Stadium':               {x:.55, y:.48},
    'Liqe Mender':           {x:.38, y:.48},
    'University':            {x:.68, y:.28},
  },
  roads: [
    ['Mehal Ketema','Tabor'],['Mehal Ketema','Addis Ketema'],
    ['Mehal Ketema','Stadium'],['Mehal Ketema','Liqe Mender'],
    ['Tabor','Referral'],['Addis Ketema','University'],
    ['Addis Ketema','Haik Dar'],['Haik Dar','Tulu Dimtu'],
    ['Stadium','Tulu Dimtu'],['Liqe Mender','Misrak'],
    ['Misrak','Dore Bafana'],['Tulu Dimtu','Hawela Teklehaimanot'],
    ['Referral','Dore Bafana'],['University','Haik Dar'],
  ],

  // ── SERVICE CATEGORIES (klik-style) ───────────────────────
  services: [
    {id:'food',      icon:'🍔', label:'Food',      label_am:'ምግብ',      color:'#E65100'},
    {id:'grocery',   icon:'🛒', label:'Grocery',   label_am:'ሱቅ',       color:'#2E7D32'},
    {id:'pharmacy',  icon:'💊', label:'Pharmacy',  label_am:'ፋርማሲ',    color:'#1565C0'},
    {id:'parcel',    icon:'📦', label:'Parcel',    label_am:'ጥቅል',      color:'#6D4C41'},
    {id:'laundry',   icon:'👕', label:'Laundry',   label_am:'ልብስ ማጠቢያ', color:'#6A1B9A'},
    {id:'gift',      icon:'🎁', label:'Gift',      label_am:'ስጦታ',     color:'#C62828'},
  ],

  // ── HAWASSA RESTAURANTS ───────────────────────────────────
  restaurants: [
    {id:1, name:'Lewi Resort Restaurant', zone:'Referral',    cuisine:'Ethiopian·International', rating:4.8, time:25, fee:40, emoji:'🍽️', hot:true},
    {id:2, name:'Pinna Hotel',            zone:'Mehal Ketema',cuisine:'Ethiopian·Buffet',        rating:4.7, time:20, fee:35, emoji:'🥘', hot:false},
    {id:3, name:'Haile Resort Grill',     zone:'Tabor',       cuisine:'Grill·BBQ',              rating:4.9, time:30, fee:45, emoji:'🔥', hot:true},
    {id:4, name:'Juice House Hawassa',    zone:'Stadium',     cuisine:'Juices·Snacks',          rating:4.6, time:15, fee:30, emoji:'🥤', hot:false},
    {id:5, name:'Chaka Hawassa',          zone:'Addis Ketema',cuisine:'Coffee·Pastry',          rating:4.5, time:18, fee:35, emoji:'☕', hot:false},
    {id:6, name:'Summerfield',            zone:'Tulu Dimtu',  cuisine:'Ethiopian·Pizza',        rating:4.7, time:28, fee:40, emoji:'🍕', hot:true},
    {id:7, name:'Genet Fast Food',        zone:'Liqe Mender', cuisine:'Burgers·Fries',          rating:4.4, time:20, fee:30, emoji:'🍔', hot:false},
    {id:8, name:'Yemane Kitfo',           zone:'Misrak',      cuisine:'Kitfo·Traditional',      rating:4.8, time:22, fee:35, emoji:'🥩', hot:true},
  ],

  // ── GROCERIES ────────────────────────────────────────────
  groceries: [
    {id:1, name:'Shoa Supermarket',    zone:'Mehal Ketema', rating:4.6, time:20, fee:30, emoji:'🏪'},
    {id:2, name:'Awash Supermarket',   zone:'Addis Ketema', rating:4.5, time:25, fee:35, emoji:'🛒'},
    {id:3, name:'Merkato Mini Market', zone:'Tabor',        rating:4.3, time:22, fee:30, emoji:'🥦'},
  ],

  gold: '#C8960C',
};

// ── AUTH ─────────────────────────────────────────────────────
const Auth = {
  getToken()  { return localStorage.getItem('etel_token') || ''; },
  getUser()   { try { return JSON.parse(localStorage.getItem('etel_user') || 'null'); } catch(e) { return null; } },
  getCourier(){ try { return JSON.parse(localStorage.getItem('etel_courier') || 'null'); } catch(e) { return null; } },
  isLoggedIn(){ return !!this.getToken() && !!this.getUser(); },
  save(data) {
    localStorage.setItem('etel_token', data.token);
    localStorage.setItem('etel_user',  JSON.stringify(data.user));
    if (data.courier) localStorage.setItem('etel_courier', JSON.stringify(data.courier));
    if (data.service_giver) localStorage.setItem('etel_service_giver', JSON.stringify(data.service_giver));
    // Real cross-app SSO token (Etelid_sessions table) - shared key every
    // Etel Group app checks (EtelAI, future ones), so logging into
    // Delivery/Ride is recognized elsewhere too. Only present when the
    // person signed in via EtelID (not a plain phone+password account)
    // and the Service API key is configured server-side.
    if (data.sso_token) {
      localStorage.setItem('etel_sso', JSON.stringify({ token: data.sso_token, etel_id: data.etel_id || null }));
    }
  },
  logout() {
    ['etel_token','etel_user','etel_courier','etel_service_giver','etel_sso'].forEach(k => localStorage.removeItem(k));
    const base = ETEL.api.replace('api/boot.php','');
    window.location.href = base + 'login.html';
  },
};

// ── ROLE VERIFICATION (protect each portal) ──
// Maps a requireRole() allowedRoles array to the login.html query params
// its own lock logic expects (role=driver, role=courier&redirect=courier/,
// etc.), and does the redirect. Shared by every failure branch below -
// "not logged in", "role missing", and "wrong role" all need the SAME
// treatment, since a genuinely logged-out visit (the most common case)
// hits the FIRST branch, not the "wrong role" one. An earlier fix only
// patched the third branch and missed this - the bug this now fixes.
function _requireRoleRedirect(allowedRoles) {
  ['etel_token','etel_user','etel_courier','etel_service_giver','etel_sso'].forEach(k => localStorage.removeItem(k));
  var map = {
    driver:        { role: 'driver' },
    courier:       { role: 'courier',       redirect: 'courier/' },
    service_giver: { role: 'service_giver', redirect: 'servicegiver.html' },
    admin:         { role: 'admin',         redirect: 'admin.html' }
  };
  var key = (allowedRoles || []).find(function (r) { return map[r]; });
  var base = ETEL.api.replace('api/boot.php', '');
  if (key) {
    var target = map[key];
    var qs = 'role=' + target.role + (target.redirect ? '&redirect=' + encodeURIComponent(target.redirect) : '');
    window.location.href = base + 'login.html?' + qs;
  } else {
    window.location.href = base + 'login.html';
  }
}
function requireRole(allowedRoles) {
  const user = Auth.getUser();
  if (!user) {
    console.warn('Access denied: User is not logged in');
    _requireRoleRedirect(allowedRoles);
    return false;
  }
  if (!user.role) {
    console.warn('Access denied: User role is undefined');
    _requireRoleRedirect(allowedRoles);
    return false;
  }
  // Admin accounts are exempted from every role gate - an admin needs to
  // reach every interface (customer/courier/driver/service/admin) for
  // testing and support. This doesn't weaken security: the account's
  // real role is still 'admin' either way, this only decides whether
  // THIS PAGE lets them stay on it instead of bouncing them out.
  if (user.role === 'admin') return true;
  if (!allowedRoles.includes(user.role)) {
    console.warn(`Access denied: User is ${user.role}, requires ${allowedRoles.join(' or ')}`);
    _requireRoleRedirect(allowedRoles);
    return false;
  }
  return true;
}

// ── API ──────────────────────────────────────────────────────
async function api(action, body = {}) {
  try {
    const r = await fetch(ETEL.api, {
      method: 'POST',
      headers: { 'Content-Type':'application/json', 'Authorization':'Bearer '+Auth.getToken() },
      body: JSON.stringify({ action, ...body }),
    });
    const raw = await r.text();
    try { return JSON.parse(raw); }
    catch(e) {
      const stripped = raw.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim().slice(0,200);
      console.error('API non-JSON:', raw.slice(0,500));
      return { success:false, message:'Server error: '+stripped, data:[] };
    }
  } catch(e) {
    return { success:false, message:'Cannot reach server: '+e.message, data:[] };
  }
}

// ── SHARED I18N (English / Amharic) ─────────────────────────────
// One toggle mechanism reused by every app, instead of each app building
// its own isolated version (driver.js has its own separate DRIVER_I18N -
// left as-is to avoid touching working code, but every app from here on
// uses this shared one). Each app registers its own dictionary with
// ETEL_I18N.register(appName, dict), then calls ETEL_I18N.init() once on
// load. Language preference is stored under one shared key so switching
// language in one ETEL app is remembered across all of them.
const ETEL_I18N = (function () {
  const LANG_KEY = 'etel_lang';
  let dict = { en: {}, am: {} };
  function register(appName, appDict) {
    dict.en = Object.assign({}, dict.en, appDict.en || {});
    dict.am = Object.assign({}, dict.am, appDict.am || {});
  }
  function current() {
    try { return localStorage.getItem(LANG_KEY) || 'en'; } catch (e) { return 'en'; }
  }
  function apply(lang) {
    const d = dict[lang] || dict.en;
    document.querySelectorAll('[data-i18n]').forEach(function (el) {
      const key = el.getAttribute('data-i18n');
      if (d[key]) el.textContent = d[key];
    });
    // Inputs/textareas can't be translated via textContent - their visible
    // label is the placeholder attribute instead.
    document.querySelectorAll('[data-i18n-ph]').forEach(function (el) {
      const key = el.getAttribute('data-i18n-ph');
      if (d[key]) el.setAttribute('placeholder', d[key]);
    });
    try { localStorage.setItem(LANG_KEY, lang); } catch (e) {}
    document.documentElement.setAttribute('lang', lang);
  }
  function toggle() {
    const next = current() === 'en' ? 'am' : 'en';
    apply(next);
    return next;
  }
  function init() {
    const saved = current();
    if (document.readyState !== 'loading') apply(saved);
    else document.addEventListener('DOMContentLoaded', function () { apply(saved); });
  }
  function t(key, lang) {
    const d = dict[lang || current()] || dict.en;
    return d[key] || dict.en[key] || key;
  }
  return { register: register, apply: apply, toggle: toggle, init: init, current: current, t: t };
})();

// ── TOAST ────────────────────────────────────────────────────
function toast(msg, type='ok', dur=3400) {
  const wrap = document.getElementById('toasts'); if (!wrap) return;
  const el = document.createElement('div');
  el.className = 'toast' + (type==='err'?' err':'');
  el.textContent = (type==='err'?'⚠ ':'✓ ') + msg;
  wrap.appendChild(el);
  setTimeout(()=>el.classList.add('show'),10);
  setTimeout(()=>{el.classList.remove('show');setTimeout(()=>el.remove(),350);},dur);
}

/* Does what DevTools' Application tab "Clear site data" button does -
   unregister every service worker for this origin, delete every Cache
   Storage entry (this is what actually causes "stuck on an old version"
   - a controlling service worker keeps serving its own cached bundle
   even after new files are uploaded), then clear local/session storage
   and reload. This is a full wipe, same as the real DevTools action -
   it WILL log the current user out, since the auth token lives in
   localStorage. Callers should confirm with the person before calling
   this, same as any other destructive action. */
async function etelForceClearCacheAndReload() {
  try {
    if ('serviceWorker' in navigator) {
      const regs = await navigator.serviceWorker.getRegistrations();
      await Promise.all(regs.map(r => r.unregister()));
    }
  } catch (e) { console.error('[etelForceClearCacheAndReload] SW unregister failed', e); }
  try {
    if ('caches' in window) {
      const keys = await caches.keys();
      await Promise.all(keys.map(k => caches.delete(k)));
    }
  } catch (e) { console.error('[etelForceClearCacheAndReload] cache delete failed', e); }
  // Many service worker implementations (Workbox and similar) track
  // their own precache manifest/state in IndexedDB, separately from the
  // Cache Storage API cleared above - deleting caches alone left a
  // service worker able to reconstruct/keep serving a stale precache
  // from this. This is also the one thing Chrome's own "Delete browsing
  // data" was reaching that this function previously wasn't.
  try {
    if ('indexedDB' in window && indexedDB.databases) {
      const dbs = await indexedDB.databases();
      await Promise.all(dbs.map(db => db.name ? new Promise(resolve => {
        const req = indexedDB.deleteDatabase(db.name);
        req.onsuccess = req.onerror = req.onblocked = () => resolve();
      }) : Promise.resolve()));
    }
  } catch (e) { console.error('[etelForceClearCacheAndReload] IndexedDB delete failed', e); }
  try {
    document.cookie.split(';').forEach(c => {
      const eq = c.indexOf('=');
      const name = (eq > -1 ? c.substring(0, eq) : c).trim();
      if (name) document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';
    });
  } catch (e) {}
  try { localStorage.clear(); } catch (e) {}
  try { sessionStorage.clear(); } catch (e) {}
  // Cache-bust the reload itself too, in case the browser's own HTTP
  // cache (separate from the Cache Storage API above) still has a stale
  // copy of this exact HTML document.
  location.href = location.pathname + '?_cachebust=' + Date.now();
}

// ── CAMERA DIAGNOSTICS ───────────────────────────────────────
// Shared by every QR scanner in the app (customer, driver, service giver).
// "Cannot access camera" alone is a dead end for the person seeing it --
// this turns getUserMedia's error into something they can actually act on.
function cameraAccessMessage(err) {
  if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
    return 'Camera requires a secure connection (https://). Reload this page over https and try again.';
  }
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    return 'This browser does not support camera access here. Try Chrome, or update your browser.';
  }
  const name = (err && err.name) || '';
  if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
    return 'Camera permission was denied. Tap the lock/info icon in your address bar \u2192 Permissions \u2192 Camera \u2192 Allow, then try again.';
  }
  if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
    return 'No camera was found on this device.';
  }
  if (name === 'NotReadableError' || name === 'TrackStartError') {
    return 'Your camera is being used by another app. Close other camera apps and try again.';
  }
  if (name === 'OverconstrainedError') {
    return 'No camera matches what was requested. Please try again.';
  }
  if (name === 'SecurityError') {
    return 'Camera access is blocked by your browser\'s security settings for this page.';
  }
  return 'Cannot access camera' + (name ? ' (' + name + ')' : '') + '. Check camera permission in your browser settings and try again.';
}

// ── MODAL ────────────────────────────────────────────────────
function openModal(html) {
  const m = document.getElementById('modal'); if (!m) return;
  m.innerHTML = `<div class="modal-bg" onclick="closeModal()"></div><div class="modal-box">${html}<button class="modal-close" onclick="closeModal()">✕</button></div>`;
  m.classList.add('open');
}
function closeModal() {
  const m = document.getElementById('modal');
  if (m) { m.classList.remove('open'); m.innerHTML=''; }
}

// ── MAP DRAWING ──────────────────────────────────────────────
const MapDraw = {
  base(ctx, W, H) {
    // Hawassa golden map — Lake Hawassa on left side
    const g = ctx.createLinearGradient(0,0,W,H);
    g.addColorStop(0,'#C8860A'); g.addColorStop(.5,'#B07808'); g.addColorStop(1,'#9A6806');
    ctx.fillStyle = g; ctx.fillRect(0,0,W,H);
    // Lake Hawassa (left side)
    ctx.beginPath();
    ctx.ellipse(W*.08, H*.5, W*.10, H*.38, 0, 0, Math.PI*2);
    ctx.fillStyle='rgba(30,80,180,0.35)'; ctx.fill();
    ctx.strokeStyle='rgba(30,80,180,0.5)'; ctx.lineWidth=1.5; ctx.stroke();
    ctx.fillStyle='rgba(255,255,255,.18)'; ctx.font='9px monospace'; ctx.fillText('Lake Hawassa',W*.01,H*.5);
    // Grid
    ctx.strokeStyle='rgba(255,255,255,.07)'; ctx.lineWidth=.5;
    for(let gx=0;gx<W;gx+=50){ctx.beginPath();ctx.moveTo(gx,0);ctx.lineTo(gx,H);ctx.stroke();}
    for(let gy=0;gy<H;gy+=50){ctx.beginPath();ctx.moveTo(0,gy);ctx.lineTo(W,gy);ctx.stroke();}
    // Roads
    ETEL.roads.forEach(([a,b])=>{
      if(!ETEL.zonePos[a]||!ETEL.zonePos[b]) return;
      const ax=ETEL.zonePos[a].x*W, ay=ETEL.zonePos[a].y*H;
      const bx=ETEL.zonePos[b].x*W, by=ETEL.zonePos[b].y*H;
      ctx.strokeStyle='rgba(255,255,255,.22)'; ctx.lineWidth=2; ctx.setLineDash([]);
      ctx.beginPath(); ctx.moveTo(ax,ay); ctx.lineTo(bx,by); ctx.stroke();
    });
  },
  zoneNode(ctx, zone, W, H, color='rgba(255,255,255,.4)', r=4) {
    if(!ETEL.zonePos[zone]) return {x:0,y:0};
    const x=ETEL.zonePos[zone].x*W, y=ETEL.zonePos[zone].y*H;
    ctx.beginPath(); ctx.arc(x,y,r,0,Math.PI*2); ctx.fillStyle=color; ctx.fill();
    return {x,y};
  },
  route(ctx, from, to, W, H, t, color='rgba(255,255,255,.8)') {
    if(!ETEL.zonePos[from]||!ETEL.zonePos[to]) return;
    const px=ETEL.zonePos[from].x*W, py=ETEL.zonePos[from].y*H;
    const dx=ETEL.zonePos[to].x*W,   dy=ETEL.zonePos[to].y*H;
    ctx.strokeStyle=color; ctx.lineWidth=2.5;
    ctx.setLineDash([7,5]); ctx.lineDashOffset=-t*48;
    ctx.beginPath(); ctx.moveTo(px,py); ctx.lineTo(dx,dy); ctx.stroke();
    ctx.setLineDash([]); ctx.lineDashOffset=0;
  },
  pin(ctx, x, y, color, label) {
    ctx.beginPath(); ctx.arc(x,y,10,0,Math.PI*2); ctx.fillStyle=color; ctx.fill();
    ctx.fillStyle='#fff'; ctx.font='bold 9px monospace'; ctx.textAlign='center'; ctx.textBaseline='middle';
    ctx.fillText(label,x,y); ctx.textAlign='left'; ctx.textBaseline='alphabetic';
  },
  courier(ctx, x, y, t, i=0) {
    for(let r=14;r<=26;r+=6){
      ctx.beginPath(); ctx.arc(x,y,r+Math.sin(t*3+i)*2,0,Math.PI*2);
      ctx.strokeStyle=`rgba(255,255,255,${.2-(r-14)*.015})`; ctx.lineWidth=1.5; ctx.stroke();
    }
    ctx.beginPath(); ctx.arc(x,y,7,0,Math.PI*2); ctx.fillStyle='rgba(255,255,255,.9)'; ctx.fill();
    ctx.beginPath(); ctx.arc(x,y,4,0,Math.PI*2); ctx.fillStyle='#8B6000'; ctx.fill();
    ctx.font='11px serif'; ctx.fillText('🛵',x-7,y-12);
  },
  labels(container, W, H) {
    container.innerHTML='';
    Object.entries(ETEL.zonePos).forEach(([n,p])=>{
      const el=document.createElement('div');
      el.style.cssText=`position:absolute;font-family:monospace;font-size:8px;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.5);pointer-events:none;transform:translate(-50%,-50%);left:${p.x*100}%;top:${p.y*100}%;white-space:nowrap`;
      el.textContent=n; container.appendChild(el);
    });
    // Lake label
    const lake=document.createElement('div');
    lake.style.cssText=`position:absolute;font-family:monospace;font-size:8px;color:rgba(100,150,255,.7);pointer-events:none;left:1%;top:46%;white-space:nowrap;writing-mode:vertical-lr;transform:rotate(180deg)`;
    lake.textContent='ሀዋሳ ሐይቅ'; container.appendChild(lake);
  },
};

// ── FARE ENGINE (Hawassa pricing) ─────────────────────────────
const Fare = {
  BASE:20, PER_KM:10, MIN:35, SURGE:1.4, CROSS:15,
  PKG:{ food:25, grocery:20, document:30, parcel:45, pharmacy:25, laundry:35, bulk:100, gift:40 },
  calc(from, to, pkg='parcel', surge=false) {
    const z=ETEL.zones, pi=z.indexOf(from), di=z.indexOf(to);
    if(pi<0||di<0) return null;
    const dist=Math.abs(pi-di)*this.PER_KM+this.BASE;
    const pf=this.PKG[pkg]||45, zf=(Math.abs(pi-di)>3)?this.CROSS:0;
    const sub=dist+pf+zf, s=surge?Math.round(sub*(this.SURGE-1)):0;
    return {base:this.BASE,distance:dist-this.BASE,packageFee:pf,zoneSurcharge:zf,surge:s,total:Math.max(this.MIN,sub+s)};
  },
};

// ── TIME ─────────────────────────────────────────────────────
const Time = {
  now()  { return new Date().toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'}); },
  ago(ts){ const d=Math.floor((Date.now()-new Date(ts))/60000); if(d<1) return 'just now'; if(d<60) return d+'m ago'; return Math.floor(d/60)+'h ago'; },
};

// ── REVEAL ───────────────────────────────────────────────────
function initReveal() {
  const obs=new IntersectionObserver(es=>es.forEach(e=>{if(e.isIntersecting)e.target.classList.add('v');}),{threshold:.12});
  document.querySelectorAll('[data-r]').forEach(el=>obs.observe(el));
}
async function silentPatchCheck() {
  try {
    const base=ETEL.api.replace('boot.php','');
    const app = window.ETEL_APP; // set by release-loader.js's inline config on canary-enabled pages
    let url = base+'boot.php?action=get_patch_info&t='+Date.now();
    if (app) {
      const currentVersion = window.__ETEL_LOADED_VERSION || '';
      url += '&app='+encodeURIComponent(app)+'&current_version='+encodeURIComponent(currentVersion);
    }
    const token = (typeof Auth !== 'undefined' && Auth.getToken) ? Auth.getToken() : null;
    const r=await fetch(url, token ? {headers:{'Authorization':'Bearer '+token}} : undefined);
    if(!r.ok) {
      console.log('Patch check: No updates available');
      return;
    }
    const d=await r.json();
    if(!d.success) {
      console.log('Patch check: Server error');
      return;
    }
    const p=d.data;
    // Canary-enabled pages: the backend already resolved whether this
    // exact device is stale (patches_available), comparing the version
    // it actually has loaded against current stable - a simple flag,
    // not a semver comparison, since release labels (v1, v49,
    // 2026-08-22a, ...) are opaque strings here, not X.Y.Z numbers.
    // Pages with no ETEL_APP (the marketing/login pages, never part of
    // the canary system) keep the original isNewer() semver check
    // exactly as before.
    const stale = app ? !!p.patches_available : isNewer(p.version, ETEL.version);
    if (stale) {
      document.querySelectorAll('.upd-ver').forEach(el=>el.textContent=app ? p.version : ('v'+p.version));
      const b=document.getElementById('update-banner'); if(b) b.style.display='flex';
    }
  } catch(e) {
    console.log('Patch check: Unable to check for updates');
  }
}
function isNewer(a,b){
  const pa=a.split('.').map(Number),pb=b.split('.').map(Number);
  for(let i=0;i<3;i++){if((pa[i]||0)>(pb[i]||0))return true;if((pa[i]||0)<(pb[i]||0))return false;}
  return false;
}


/* ══════════════════════════════════════════════════════════════
   ETEL DiagPanel — floating diagnostic console on every page
   Visible automatically to signed-in admin accounts (Auth.getUser().role
   === 'admin') - a real server-verified check, not a flag users could
   set themselves. For diagnosing PRE-login pages (e.g. login.html) where
   there's no logged-in user yet, a manual override still works:
   Activate in browser console:  localStorage.setItem('etelDev','1'); location.reload()
   Deactivate:                    localStorage.removeItem('etelDev'); location.reload()
══════════════════════════════════════════════════════════════ */
const DiagPanel = {
  _el: null,
  _con: null,
  _errs: 0,
  _warns: 0,
  _open: false,

  init() {
    /* Always inject the FAB button — no activation needed.
       The panel itself only opens on tap.
       To auto-run on load: localStorage.setItem('etelDev','1') */

    /* Intercept console.error and console.warn */
    const orig_err  = console.error.bind(console);
    const orig_warn = console.warn.bind(console);
    console.error = (...a) => { orig_err(...a);  DiagPanel._capture('er', a); };
    console.warn  = (...a) => { orig_warn(...a); DiagPanel._capture('wn', a); };
    window.onerror = (msg, src, line, col, err) => {
      DiagPanel._capture('er', ['JS: ' + msg + ' (' + (src||'').split('/').pop() + ':' + line + ')']);
    };
    window.addEventListener('unhandledrejection', e => {
      DiagPanel._capture('er', ['Unhandled promise rejection: ' + (e.reason||'unknown')]);
    });

    /* Intercept every fetch() call on the page - real network logging,
       not just the manual test buttons below. Logs method, URL, status,
       latency for every request; failures/non-2xx get logged as errors
       the same way a real browser DevTools Network tab would flag them,
       without needing to open real DevTools (not always available on a
       phone). Wrapped once (DiagPanel._fetchWrapped guards against
       double-wrapping if init() somehow runs twice). */
/* Network logging is installed at the very top of this file now, not
       here - patching it only on 'load' missed every DOMContentLoaded-
       phase call (see the early network-log buffer near the top). */

    this._inject();

    if (window.__etelEarlyErrors && window.__etelEarlyErrors.length) {
      window.__etelEarlyErrors.forEach(msg => DiagPanel._capture('er', [msg]));
      window.__etelEarlyErrors = [];
    }
    if (window.__etelEarlyNetworkLogs && window.__etelEarlyNetworkLogs.length) {
      window.__etelEarlyNetworkLogs.forEach(entry => DiagPanel._capture(entry[0], [entry[1]]));
      window.__etelEarlyNetworkLogs = [];
    }

    this._autoRun();
  },

  _capture(tag, args) {
    const msg = args.map(a => typeof a === 'object' ? JSON.stringify(a,null,2) : String(a)).join(' ');
    if (tag === 'er') this._errs++;
    if (tag === 'wn') this._warns++;
    this._log(tag, msg);
    this._updateBadge();
  },

  _ts() {
    return new Date().toLocaleTimeString('en-GB',{hour12:false,hour:'2-digit',minute:'2-digit',second:'2-digit'});
  },

  _log(tag, msg, raw) {
    if (!this._con) return;
    const el  = document.createElement('div');
    el.style.cssText = 'padding:3px 0;border-bottom:1px solid rgba(255,255,255,.1);display:flex;gap:7px;align-items:flex-start;font-size:11px';
    const tagColors = {ok:'#1a2a0a:#639922',er:'#3a1010:#E24B4A',wn:'#2a1a00:#D4AF37',in:'#0a1a2a:#60B4FF',rn:'#151515:#666',nt:'#0a2a26:#4ECDC4'};
    const [bg,col] = (tagColors[tag]||tagColors.in).split(':');
    const rawHtml = raw !== undefined
      ? `<pre style="background:#050505;padding:5px;border-radius:3px;margin-top:3px;white-space:pre-wrap;word-break:break-all;font-size:10px;color:#e8e2d4;border:1px solid #111;max-height:120px;overflow-y:auto">${typeof raw==='string'?raw:JSON.stringify(raw,null,2)}</pre>`
      : '';
    el.innerHTML =
      `<span style="color:#9A9AAE;min-width:60px;flex-shrink:0;padding-top:1px">${this._ts()}</span>` +
      `<span style="min-width:26px;flex-shrink:0;font-size:9px;padding:1px 4px;border-radius:2px;background:${bg};color:${col};text-align:center;margin-top:1px">${tag.toUpperCase()}</span>` +
      `<span style="flex:1;color:#b8afa0;line-height:1.5;word-break:break-all">${msg}${rawHtml}</span>`;
    this._con.appendChild(el);
    this._con.scrollTop = this._con.scrollHeight;
  },

  _updateBadge() {
    const badge = document.getElementById('_diag_badge');
    if (!badge) return;
    const total = this._errs + this._warns;
    badge.textContent = total > 0 ? (total > 9 ? '9+' : total) : '';
    badge.style.display = total > 0 ? 'flex' : 'none';
    badge.style.background = this._errs > 0 ? '#E24B4A' : '#D4AF37';
  },

  _inject() {
    const style = document.createElement('style');
    style.textContent = `
      #_diag_fab{position:fixed;bottom:80px;right:20px;width:44px;height:44px;border-radius:50%;
        background:linear-gradient(135deg,#1a1206,#2a1a06);border:1.5px solid rgba(212,175,55,.5);
        box-shadow:0 4px 16px rgba(0,0,0,.5);cursor:pointer;z-index:99998;
        display:flex;align-items:center;justify-content:center;font-size:18px;
        transition:all .2s;user-select:none}
      #_diag_fab:hover{transform:scale(1.12);border-color:#D4AF37;box-shadow:0 6px 20px rgba(212,175,55,.3)}
      #_diag_badge{position:absolute;top:-4px;right:-4px;min-width:16px;height:16px;border-radius:8px;
        background:#E24B4A;color:#fff;font-size:9px;font-weight:700;font-family:monospace;
        display:none;align-items:center;justify-content:center;padding:0 3px;
        border:1.5px solid #07050a}
      #_diag_panel{position:fixed;bottom:134px;right:16px;width:min(94vw,480px);
        background:#07050a;border:1px solid rgba(212,175,55,.25);border-radius:12px;
        box-shadow:0 20px 60px rgba(0,0,0,.8);z-index:99999;
        font-family:'SF Mono',Consolas,monospace;font-size:12px;
        display:none;flex-direction:column;max-height:70vh}
      #_diag_panel.open{display:flex}
      #_diag_hdr{display:flex;align-items:center;gap:8px;padding:10px 12px;
        border-bottom:1px solid rgba(212,175,55,.15);flex-shrink:0}
      #_diag_title{color:#D4AF37;font-weight:700;font-size:13px;flex:1}
      #_diag_page{font-size:10px;color:#9A9AAE}
      #_diag_toolbar{display:flex;gap:5px;padding:8px 10px;flex-wrap:wrap;flex-shrink:0;
        border-bottom:1px solid rgba(255,255,255,.1)}
      #_diag_toolbar button{padding:4px 10px;border-radius:5px;border:1px solid rgba(255,255,255,.15);
        background:#0f0f0f;color:#B8B0A0;font-size:10px;font-family:inherit;cursor:pointer;transition:all .15s}
      #_diag_toolbar button:hover{border-color:#D4AF37;color:#D4AF37}
      #_diag_con{flex:1;overflow-y:auto;padding:8px 10px;min-height:120px}
      #_diag_stats{display:grid;grid-template-columns:repeat(4,1fr);gap:4px;padding:8px 10px;
        flex-shrink:0;border-bottom:1px solid rgba(255,255,255,.1)}
      .dstat{background:#0f0f0f;border:1px solid #1a1a1a;border-radius:5px;padding:5px;text-align:center}
      .dstat-n{font-size:15px;font-weight:700;color:#D4AF37;line-height:1}
      .dstat-l{font-size:9px;color:#9A9AAE;margin-top:2px}
      .dok .dstat-n{color:#639922} .derr .dstat-n{color:#E24B4A} .dwn .dstat-n{color:#D4AF37}
    `;
    document.head.appendChild(style);

    /* FAB button */
    const fab = document.createElement('div');
    fab.id = '_diag_fab';
    fab.innerHTML = '🔬<div id="_diag_badge"></div>';
    fab.onclick = () => this._toggle();
    document.body.appendChild(fab);

    /* Panel */
    const panel = document.createElement('div');
    panel.id = '_diag_panel';
    panel.innerHTML = `
      <div id="_diag_hdr">
        <span id="_diag_title">ኢE Diagnostic</span>
        <span id="_diag_page">${location.pathname.split('/').pop()||'index'}</span>
        <button onclick="DiagPanel._clear()" style="padding:2px 7px;font-size:10px;border:1px solid #333;background:#111;color:#B8B0A0;border-radius:4px;cursor:pointer;margin-left:6px">Clear</button>
        <button onclick="DiagPanel._close()" style="padding:2px 7px;font-size:10px;border:1px solid #333;background:#111;color:#B8B0A0;border-radius:4px;cursor:pointer;margin-left:4px">✕</button>
      </div>
      <div id="_diag_stats">
        <div class="dstat" id="_ds_api"><div class="dstat-n">—</div><div class="dstat-l">API</div></div>
        <div class="dstat" id="_ds_db"><div class="dstat-n">—</div><div class="dstat-l">DB</div></div>
        <div class="dstat" id="_ds_ms"><div class="dstat-n">—</div><div class="dstat-l">Latency</div></div>
        <div class="dstat" id="_ds_er"><div class="dstat-n">0</div><div class="dstat-l">Errors</div></div>
      </div>
      <div id="_diag_toolbar">
        <button onclick="DiagPanel.runAll()">▶ All</button>
        <button onclick="DiagPanel.testPing()">⚡ Ping</button>
        <button onclick="DiagPanel.testDB()">🗄 DB</button>
        <button onclick="DiagPanel.testAuth()">🔐 Auth</button>
        <button onclick="DiagPanel.testReset()">🔑 Reset</button>
        <button onclick="DiagPanel.testNetwork()">🌐 Net</button>
      </div>
      <div id="_diag_con"></div>
    `;
    document.body.appendChild(panel);
    this._el  = panel;
    this._con = document.getElementById('_diag_con');
  },

  _toggle() { this._open ? this._close() : this._open_panel(); },
  _open_panel() {
    this._open = true;
    this._el.classList.add('open');
    this._errs = 0; this._warns = 0;
    this._updateBadge();
  },
  _close() { this._open = false; this._el.classList.remove('open'); },
  _clear() { if (this._con) this._con.innerHTML = ''; this._errs = 0; this._warns = 0; this._updateBadge(); },

  _setStat(id, val, cls) {
    const el = document.getElementById(id); if (!el) return;
    el.className = 'dstat ' + (cls||'');
    el.querySelector('.dstat-n').textContent = val;
  },

  async _call(action, body={}) {
    const t0 = performance.now();
    try {
      const r = await fetch(ETEL.api, {
        method:'POST',
        headers:{'Content-Type':'application/json','Authorization':'Bearer '+Auth.getToken()},
        body: JSON.stringify({action,...body})
      });
      const ms = Math.round(performance.now()-t0);
      const text = await r.text();
      let data;
      try { data = JSON.parse(text); }
      catch(e) { return {ok:false,ms,data:null,raw:text.slice(0,400),parseErr:true}; }
      return {ok:r.ok,status:r.status,ms,data};
    } catch(e) {
      return {ok:false,ms:Math.round(performance.now()-t0),data:null,netErr:e.message};
    }
  },

  async testPing() {
    this._log('rn','Testing API ping...');
    const r = await this._call('ping');
    if (r.netErr)   { this._log('er','Network error: '+r.netErr); this._setStat('_ds_api','ERR','derr'); return; }
    if (r.parseErr) { this._log('er','Non-JSON response (PHP error?)', r.raw); this._setStat('_ds_api','ERR','derr'); return; }
    if (r.data && r.data.success) {
      this._log('ok','API ✅ v'+(r.data.v||'?')+' · '+r.ms+'ms');
      this._setStat('_ds_api','OK','dok');
      this._setStat('_ds_ms', r.ms+'ms', r.ms<500?'dok':r.ms<1500?'dwn':'derr');
    } else {
      this._log('er','Ping failed: '+(r.data&&r.data.message||'unknown'), r.data);
      this._setStat('_ds_api','ERR','derr');
    }
  },

  async testDB() {
    this._log('rn','Testing database...');
    const r = await this._call('db_ping');
    if (r.parseErr) { this._log('er','DB connection error (non-JSON)', r.raw); this._setStat('_ds_db','ERR','derr'); return; }
    const db = r.data && r.data.data && r.data.data.db;
    if (db === 'connected') {
      this._log('ok','DB ✅ connected'); this._setStat('_ds_db','OK','dok');
    } else if (db === 'error') {
      this._log('er','DB ❌ '+(r.data.data.db_message||'unknown')); this._setStat('_ds_db','ERR','derr');
    } else {
      this._log('wn','DB response unexpected', r.data); this._setStat('_ds_db','?','dwn');
    }
  },

  async testAuth() {
    this._log('rn','Testing auth session...');
    const token = Auth.getToken();
    const user  = Auth.getUser();
    if (!token) { this._log('wn','No session token — user not logged in on this page'); return; }
    this._log('in','User: '+(user&&user.name||'?')+' · Role: '+(user&&user.role||'?'));
    const r = await this._call('get_profile');
    if (r.data && r.data.success) {
      this._log('ok','Session ✅ valid · '+r.ms+'ms');
    } else {
      this._log('er','Session ❌ invalid or expired: '+(r.data&&r.data.message||'unknown'));
    }
  },

  async testReset() {
    this._log('rn','Testing password reset endpoint...');
    const r = await this._call('send_reset_otp',{phone:'+251900000000'});
    if (r.parseErr) { this._log('er','Reset endpoint non-JSON error', r.raw); return; }
    const msg = (r.data&&r.data.message||'').toLowerCase();
    if (r.status===404||msg.includes('unknown action')) {
      this._log('er','❌ send_reset_otp NOT in boot.php — deploy FIX8_password_reset_otp.zip');
    } else if (msg.includes("table")||msg.includes("doesn't exist")) {
      this._log('er','❌ password_resets table MISSING — run: php api/migrate.php --apply');
    } else {
      this._log('ok','Reset endpoint ✅ responding · '+r.ms+'ms');
      if (r.data&&r.data.data&&r.data.data.dev_otp!==undefined&&r.data.data.dev_otp!==null) {
        this._log('wn','SMS not configured (dev mode) — add AT_API_KEY to api/.env for real SMS');
      } else {
        this._log('ok','SMS ✅ configured');
      }
    }
  },

  async testNetwork() {
    this._log('rn','Testing external connectivity...');
    const tests = [
      {url:'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js',label:'cdnjs (GSAP)'},
      {url:'https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap',label:'Google Fonts'},
    ];
    for (const t of tests) {
      try {
        const t0 = performance.now();
        const r = await fetch(t.url,{method:'HEAD',mode:'no-cors'});
        this._log('ok',t.label+' ✅ '+Math.round(performance.now()-t0)+'ms');
      } catch(e) {
        this._log('er',t.label+' ❌ '+e.message);
      }
    }
  },

  async runAll() {
    this._clear();
    this._log('in','=== ETEL Delivery · '+location.pathname.split('/').pop()+' ===');
    this._log('in','User-Agent: '+navigator.userAgent.slice(0,80));
    await this.testPing();
    await this.testDB();
    await this.testAuth();
    await this.testReset();
    const errs = document.querySelectorAll('#_diag_con .dstat-n').length;
    this._log('in','=== Done · errors='+this._errs+' warns='+this._warns+' ===');
    this._setStat('_ds_er', this._errs, this._errs>0?'derr':this._warns>0?'dwn':'dok');
  },

  _autoRun() {
    /* Run silently 2 seconds after page load — catches errors on startup */
    setTimeout(() => { if (!this._open) this.testPing().then(()=>this.testDB()); }, 2000);
  }
};

window.addEventListener('load',()=>{initReveal();silentPatchCheck();pwaInit();PWAInstall.init();DarkMode.injectToggle();
  // Admin-only: checks the real, server-verified role from login (Auth.getUser().role),
  // not the old self-settable localStorage.etelDev flag anyone could flip themselves.
  // etelDev is kept as a manual override ONLY for diagnosing pre-login pages (like
  // login.html itself, where there's no logged-in user/role to check yet).
  let _diagUser = null;
  try { _diagUser = (typeof Auth !== 'undefined' && Auth.getUser) ? Auth.getUser() : null; } catch (e) {}
  if ((_diagUser && _diagUser.role === 'admin') || localStorage.getItem('etelDev') === '1') DiagPanel.init();
});

// ── PWA: inject manifest + meta tags + register SW ────────────
function pwaInit() {
  // Manifest link (absolute path so it works from any sub-path)
  if (!document.querySelector('link[rel="manifest"]')) {
    const l=document.createElement('link'); l.rel='manifest';
    l.href=ETEL.api.replace('api/boot.php', '') + 'manifest.php';
    document.head.appendChild(l);
  }
  // Apple PWA meta tags (iOS standalone support)
  const appleKeys = [
    ['mobile-web-app-capable','yes'],
    ['apple-mobile-web-app-capable','yes'],
    ['apple-mobile-web-app-status-bar-style','black-translucent'],
    ['apple-mobile-web-app-title','ETEL'],
  ];
  appleKeys.forEach(([name,content]) => {
    if (!document.querySelector('meta[name="'+name+'"]')) {
      const m=document.createElement('meta'); m.name=name; m.content=content;
      document.head.appendChild(m);
    }
  });
  // Resolve the app root the same depth-safe way ETEL.api does, and detect
  // Ride context by path - needed because this can now run from pages one
  // level deeper (delivery/ride/) than the rest, and Ride needs its own
  // branded splash screens/icon, not Delivery's.
  const _pwaRoot = ETEL.api.replace('api/boot.php', '');
  const _isRide = /\/(ride|driver)\//.test(window.location.pathname);
  const _splashPrefix = _isRide ? 'ride-splash-' : 'splash-';
  const _touchIcon = _isRide ? 'ride-apple-touch-icon.png' : 'apple-touch-icon.png';

  // Apple touch icon
  if (!document.querySelector('link[rel="apple-touch-icon"]')) {
    const l=document.createElement('link'); l.rel='apple-touch-icon'; l.href=_pwaRoot+'icons/'+_touchIcon;
    document.head.appendChild(l);
  }
  // iOS PWA splash screens (portrait)
  const splashes=[
    ['640px','1136px','640x1136'],
    ['750px','1334px','750x1334'],
    ['1242px','2208px','1242x2208'],
    ['1125px','2436px','1125x2436'],
    ['828px','1792px','828x1792'],
    ['1242px','2688px','1242x2688'],
    ['1170px','2532px','1170x2532'],
    ['1284px','2778px','1284x2778'],
    ['1179px','2556px','1179x2556'],
    ['1290px','2796px','1290x2796'],
    ['1536px','2048px','1536x2048'],
    ['1668px','2388px','1668x2388'],
    ['2048px','2732px','2048x2732'],
  ];
  splashes.forEach(([w,h,name])=>{
    if(document.querySelector(`link[media*="${w}"]`)) return;
    const l=document.createElement('link');
    l.rel='apple-touch-startup-image';
    l.href=_pwaRoot+'icons/'+_splashPrefix+name+'.png';
    l.media=`(device-width: ${w}) and (device-height: ${h}) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)`;
    document.head.appendChild(l);
  });
  // Register service worker
  // Use the same absolute-root pattern as _pwaRoot above (derived from
  // ETEL.api), not a bare relative 'sw.js' string. This same file runs
  // on every role page, including ones nested a folder deep
  // (ride/index.html, driver/index.html, courier/courier-index.html) -
  // a bare 'sw.js' silently resolves to e.g. /delivery/ride/sw.js on
  // those, a path that doesn't exist, and registration fails silently
  // (caught below), leaving that page with no offline support and no
  // working push-notification click handling for its entire session.
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register(_pwaRoot + 'sw.js', {scope: _pwaRoot})
      .then(r => {
        r.addEventListener('updatefound', () => {
          const nw = r.installing;
          nw.addEventListener('statechange', () => {
            if (nw.state === 'installed' && navigator.serviceWorker.controller) {
              // New version available — show subtle banner if present
              const b = document.getElementById('update-banner');
              if (b) b.style.display = 'flex';
            }
          });
        });
      }).catch(() => {});
  }
}


// ── PWA: floating "Install App" button, shared across every page ──
// Works for both manifests (Delivery's manifest.php and Ride's
// manifest-ride.json) automatically - the browser's install prompt is
// always tied to whatever manifest the current page actually links, so
// this button doesn't need to know or care which one is active.
const PWAInstall = {
  _deferred: null,
  _btn: null,
  _autoFlag: 'etel_auto_prompt_install',

  _isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true; // iOS Safari
  },
  _isIOS() {
    return /iP(hone|od|ad)/.test(navigator.userAgent)
        && !/CriOS|FxiOS|EdgiOS/.test(navigator.userAgent);
  },

  /* Best-effort "is this specific app already installed?" check. Chrome/Edge
     only (Safari and Firefox don't implement this API at all) - always
     treat "unsupported" as "unknown", never as "definitely not installed",
     since we'd rather under-prompt than nag someone who already has it. */
  async isInstalled() {
    if (this._isStandalone()) return true;
    if (!('getInstalledRelatedApps' in navigator)) return null; // unknown
    try {
      const apps = await navigator.getInstalledRelatedApps();
      return apps && apps.length > 0;
    } catch (e) { return null; }
  },

  /* Called from a card's onclick before navigating to customer.html/ride.html
     etc. Sets a flag so that once we land on the target page (which links
     its OWN manifest - Delivery's or Ride's), PWAInstall.init() there shows
     the native prompt immediately instead of waiting for a button tap.
     Does NOT and cannot switch focus to an already-installed separate PWA
     window - no website can do that in any browser, by design. If the app
     is already installed this just navigates normally. */
  async prepareCardLaunch() {
    const installed = await this.isInstalled();
    if (installed === true) { sessionStorage.removeItem(this._autoFlag); return; }
    sessionStorage.setItem(this._autoFlag, '1');
  },

  async init() {
    // Don't just check "is the CURRENT window standalone" - that's true
    // whenever we're inside ANY installed app's shell, not necessarily
    // this one. Delivery's manifest scope ("./") still overlaps Ride's
    // ("./ride/"), so once Delivery is installed, Android can link-capture
    // ride.html into Delivery's own shell - _isStandalone() would then
    // wrongly read as "Ride is installed". Check this page's own app
    // identity specifically first; only treat standalone-without-that-
    // confirmation as inconclusive, not as "installed".
    const installed = await this.isInstalled();
    if (installed === true) return; // confirmed THIS app is installed
    if (installed === null && this._isStandalone()) return; // unsupported browser, best guess only

    // Don't offer this on the bare marketing/landing page login flow before
    // a role is chosen - only pages that are actually "an app" once signed
    // into. Cheap check: skip on login.html itself.
    if (/\/login\.html$/.test(location.pathname)) return;

    this._inject();
    // beforeinstallprompt may have already fired and been captured by the
    // top-level listener below, before this DOM-dependent init() ran (it's
    // called from window `load`, which fires late - after images/fonts -
    // while the browser can dispatch the install event much earlier). If
    // so, surface it now that the button actually exists.
    if (this._deferred) {
      this._show();
      if (sessionStorage.getItem(this._autoFlag) === '1') {
        sessionStorage.removeItem(this._autoFlag);
        this._onClick();
      }
    }

    // Chrome/Android fires beforeinstallprompt async and only if the page
    // qualifies; iOS Safari never fires it at all but still supports
    // "Add to Home Screen" manually, so show our button there too with a
    // one-time instructional tap-through instead of a native prompt.
    if (this._isIOS()) {
      this._show();
      if (sessionStorage.getItem(this._autoFlag) === '1') {
        sessionStorage.removeItem(this._autoFlag);
        this._onClick();
      }
    }
  },

  _inject() {
    if (document.getElementById('pwa-install-btn')) return;
    const style = document.createElement('style');
    style.textContent = `
      #pwa-install-btn{
        position:fixed;left:20px;bottom:24px;z-index:9997;
        display:none;align-items:center;gap:8px;
        padding:11px 18px 11px 14px;border-radius:100px;border:1px solid rgba(212,175,55,.4);
        background:linear-gradient(135deg,#1a1206,#2a1a06);color:#E8C84A;
        font-family:'Outfit','Poppins',sans-serif;font-size:12.5px;font-weight:700;
        cursor:pointer;box-shadow:0 8px 24px -8px rgba(0,0,0,.6);
        transition:transform .15s,box-shadow .15s;
      }
      #pwa-install-btn:hover{transform:translateY(-2px);box-shadow:0 12px 28px -8px rgba(212,175,55,.35)}
      #pwa-install-btn:active{transform:translateY(0)}
      #pwa-install-btn svg{width:16px;height:16px;flex-shrink:0}
      #pwa-install-btn.show{display:flex}
      @media (max-width:480px){
        #pwa-install-btn{left:14px;bottom:18px;padding:10px 16px 10px 12px;font-size:12px}
      }
      #pwa-ios-tip{
        position:fixed;inset:0;z-index:99996;display:none;align-items:flex-end;justify-content:center;
        background:rgba(5,5,10,.6);backdrop-filter:blur(4px);
      }
      #pwa-ios-tip.open{display:flex}
      #pwa-ios-tip-box{
        width:min(440px,100%);background:linear-gradient(180deg,#171006,#0a0703);
        border:1px solid rgba(212,175,55,.25);border-bottom:none;border-radius:20px 20px 0 0;
        padding:22px 22px max(22px,env(safe-area-inset-bottom,22px));
        font-family:'Outfit','Poppins',sans-serif;color:#F5EDD8;
      }
      #pwa-ios-tip-box h4{font-family:'Sora',serif;color:#D4AF37;font-size:15px;margin-bottom:10px}
      #pwa-ios-tip-box ol{padding-left:18px;font-size:13px;line-height:1.8;color:rgba(245,237,216,.85)}
      #pwa-ios-tip-box button{margin-top:16px;width:100%;padding:12px;border:none;border-radius:12px;
        background:linear-gradient(135deg,#FFD700,#D4AF37);color:#1a1206;font-weight:700;font-size:13px;cursor:pointer}
    `;
    document.head.appendChild(style);

    const btn = document.createElement('button');
    btn.id = 'pwa-install-btn';
    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0l-4-4m4 4l4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg><span>Install App</span>';
    btn.addEventListener('click', () => this._onClick());
    document.body.appendChild(btn);
    this._btn = btn;

    if (this._isIOS()) {
      const tip = document.createElement('div');
      tip.id = 'pwa-ios-tip';
      tip.innerHTML = `<div id="pwa-ios-tip-box">
        <h4>Install this app</h4>
        <ol>
          <li>Tap the <strong>Share</strong> icon in Safari's toolbar</li>
          <li>Scroll down and tap <strong>Add to Home Screen</strong></li>
          <li>Tap <strong>Add</strong> — done!</li>
        </ol>
        <button id="pwa-ios-tip-close">Got it</button>
      </div>`;
      tip.addEventListener('click', (e) => { if (e.target === tip) tip.classList.remove('open'); });
      document.body.appendChild(tip);
      document.getElementById('pwa-ios-tip-close').addEventListener('click', () => tip.classList.remove('open'));
    }
  },

  _show() { if (this._btn) this._btn.classList.add('show'); },
  _hide() { if (this._btn) this._btn.classList.remove('show'); },

  /* Public entry point - call this from any button anywhere on the page
     (header, footer, profile/settings menu, etc.), not just the floating
     button this module creates itself. Safe to call even if install isn't
     currently available - it just won't do anything in that case. */
  trigger() { this._onClick(); },

  async _onClick() {
    if (this._isIOS()) {
      const tip = document.getElementById('pwa-ios-tip');
      if (tip) tip.classList.add('open');
      else if (typeof toast === 'function') toast('Tap Share, then "Add to Home Screen" to install', 'ok');
      return;
    }
    if (!this._deferred) {
      if (this._isStandalone()) {
        if (typeof toast === 'function') toast('Already installed on this device', 'ok');
      } else if (typeof toast === 'function') {
        toast('Install isn\u2019t available yet - try again in a moment', 'err');
      }
      return;
    }
    this._deferred.prompt();
    try { await this._deferred.userChoice; } catch (e) {}
    this._deferred = null;
    this._hide();
  }
};

/* Attach these the instant this script parses (core.js loads in <head>,
   well before document.body exists) rather than waiting for window
   `load` inside PWAInstall.init(). Chrome can dispatch
   beforeinstallprompt any time after it decides the page qualifies -
   often well before all images/fonts finish loading - and it only fires
   ONCE per page load with no replay. A listener attached late can miss
   it entirely, which looked exactly like "the button just never shows up"
   with everything else deployed and working correctly. */
window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  PWAInstall._deferred = e;
  PWAInstall._show(); // no-op safely if the button doesn't exist yet
  if (sessionStorage.getItem(PWAInstall._autoFlag) === '1') {
    sessionStorage.removeItem(PWAInstall._autoFlag);
    PWAInstall._onClick();
  }
});
window.addEventListener('appinstalled', () => {
  PWAInstall._deferred = null;
  PWAInstall._hide();
  if (typeof toast === 'function') toast('App installed — find it on your home screen', 'ok');
});


// ── DARK SYSTEM-WIDE MODE ──────────────────────────────────
const DarkMode = {
  injectToggle() {
    if (document.getElementById('dark-theme-toggle')) return;

    const style = document.createElement('style');
    style.id = 'dark-theme-styles';
    style.innerHTML = `
      body.dark-theme {
        --white-bg: #121212 !important;
        --white-off: #1E1E1E !important;
        --gray-light: #2D2D2D !important;
        --gray-medium: #404040 !important;
        --gray-dark: #A0A0B0 !important;
        --gray-darker: #FAFAF8 !important;
        --text-primary: #FAFAF8 !important;
        --text-secondary: #B0B0C0 !important;
        --text-light: #707080 !important;
        
        background-color: #121212 !important;
        background: #121212 !important;
        color: #FAFAF8 !important;
      }
      
      body.dark-theme .header,
      body.dark-theme header,
      body.dark-theme .hero-section {
        background: linear-gradient(135deg, #1E1E1E 0%, #121212 100%) !important;
        box-shadow: 0 10px 30px rgba(0,0,0,0.5) !important;
        border-bottom: 1px solid #2D2D2D !important;
      }
      
      body.dark-theme .sidebar {
        background: rgba(30, 30, 30, 0.95) !important;
        border-right: 1px solid #2D2D2D !important;
      }
      
      body.dark-theme .data-section,
      body.dark-theme .chart-card,
      body.dark-theme .stat-card,
      body.dark-theme .panel,
      body.dark-theme .nav-panel,
      body.dark-theme .card,
      body.dark-theme .order-card,
      body.dark-theme .hero-stats,
      body.dark-theme .service-card,
      body.dark-theme .restaurant-card,
      body.dark-theme .auth-card,
      body.dark-theme .upd-panel {
        background: #1E1E1E !important;
        border-color: #2D2D2D !important;
        color: #FAFAF8 !important;
      }

      body.dark-theme th {
        background: rgba(212, 175, 55, 0.15) !important;
        border-bottom: 2px solid #2D2D2D !important;
        color: #FAFAF8 !important;
      }

      body.dark-theme td {
        border-bottom: 1px solid #2D2D2D !important;
        color: #B0B0C0 !important;
      }
      
      body.dark-theme tbody tr:hover {
        background: rgba(212, 175, 55, 0.1) !important;
      }
      
      body.dark-theme .content-header,
      body.dark-theme .modal-box,
      body.dark-theme .modal-content,
      body.dark-theme #modal-box,
      body.dark-theme .order-details-box {
        background: #1E1E1E !important;
        border-color: #2D2D2D !important;
        color: #FAFAF8 !important;
      }
      
      body.dark-theme input, 
      body.dark-theme select, 
      body.dark-theme textarea {
        background: #1E1E1E !important;
        border-color: #2D2D2D !important;
        color: #FAFAF8 !important;
      }
      
      body.dark-theme .testimonial-card {
        background: rgba(30, 30, 30, 0.6) !important;
        border: 1px solid rgba(255, 255, 255, 0.05) !important;
      }
      
      body.dark-theme footer {
        background: #121212 !important;
        border-top: 1px solid #2D2D2D !important;
      }

      .dark-toggle-btn {
        position: fixed;
        bottom: 24px;
        right: 24px;
        width: 50px;
        height: 50px;
        border-radius: 50%;
        border: none;
        cursor: pointer;
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      }
      .dark-toggle-btn.mode-light {
        background: #FFFFFF;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15), 0 0 0 1px rgba(0,0,0,0.06);
        color: #F59E0B;
      }
      .dark-toggle-btn.mode-dark {
        background: #1A1A2E;
        box-shadow: 0 4px 20px rgba(0,0,0,0.5), 0 0 0 1px rgba(255,215,0,0.15);
        color: #E0E0FF;
      }
      .dark-toggle-btn:hover {
        transform: scale(1.12) rotate(18deg);
      }
      .dark-toggle-btn.mode-light:hover {
        box-shadow: 0 6px 24px rgba(245,158,11,0.3), 0 0 0 1px rgba(0,0,0,0.08);
      }
      .dark-toggle-btn.mode-dark:hover {
        box-shadow: 0 6px 24px rgba(255,215,0,0.25), 0 0 0 1px rgba(255,215,0,0.2);
      }
      
      
      /* Premium Loader / Spinner */
      .etel-spinner {
        width: 28px;
        height: 28px;
        border: 3px solid rgba(212, 175, 55, 0.15);
        border-top: 3px solid #D4AF37;
        border-radius: 50%;
        animation: etelSpin 0.8s linear infinite;
        display: inline-block;
      }
      @keyframes etelSpin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
      }
      .etel-loading-container {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 30px;
        color: #B8B0A0;
        font-family: 'Poppins', sans-serif;
        font-size: 13px;
        font-weight: 500;
        text-align: center;
        width: 100%;
        box-sizing: border-box;
      }

      body.dark-theme #map,
      body.dark-theme #customer-map {
        filter: invert(90%) hue-rotate(180deg);
      }
    `;
    document.head.appendChild(style);

    const btn = document.createElement('button');
    btn.id = 'dark-theme-toggle';
    btn.className = 'dark-toggle-btn';
    btn.title = 'Toggle Dark Mode';
    
    const currentTheme = localStorage.getItem('theme') || 'light';
    if (currentTheme === 'dark') {
      document.body.classList.add('dark-theme');
      btn.innerHTML = '☀️'; btn.className = 'dark-toggle-btn mode-dark';
    } else {
      btn.innerHTML = '🌙'; btn.className = 'dark-toggle-btn mode-light';
    }

    btn.onclick = () => {
      document.body.classList.toggle('dark-theme');
      const isDark = document.body.classList.contains('dark-theme');
      localStorage.setItem('theme', isDark ? 'dark' : 'light');
      btn.innerHTML = isDark ? '☀️' : '🌙';
      btn.className = isDark ? 'dark-toggle-btn mode-dark' : 'dark-toggle-btn mode-light';
      
      if (typeof updateChartsForTheme === 'function') {
         updateChartsForTheme(isDark);
      }
    };

    document.body.appendChild(btn);
  }
};

function updateChartsForTheme(isDark) {
  if (typeof revenueOrdersChart !== 'undefined' && revenueOrdersChart) {
     const gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.05)';
     const textColor = isDark ? '#FAFAF8' : '#666680';
     
     if (revenueOrdersChart.options.scales['y-revenue']) {
       revenueOrdersChart.options.scales['y-revenue'].grid.color = gridColor;
       revenueOrdersChart.options.scales['y-revenue'].title.color = textColor;
       revenueOrdersChart.options.scales['y-revenue'].ticks.color = textColor;
     }
     if (revenueOrdersChart.options.scales['y-count']) {
       revenueOrdersChart.options.scales['y-count'].title.color = textColor;
       revenueOrdersChart.options.scales['y-count'].ticks.color = textColor;
     }
     revenueOrdersChart.options.plugins.legend.labels.color = textColor;
     revenueOrdersChart.update();
  }
  
  if (typeof statusDistChart !== 'undefined' && statusDistChart) {
     const textColor = isDark ? '#FAFAF8' : '#666680';
     statusDistChart.options.plugins.legend.labels.color = textColor;
     statusDistChart.update();
  }
  
  if (typeof zoneRevChart !== 'undefined' && zoneRevChart) {
     const gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.05)';
     const textColor = isDark ? '#FAFAF8' : '#666680';
     
     zoneRevChart.options.scales.x.ticks.color = textColor;
     zoneRevChart.options.scales.y.grid.color = gridColor;
     zoneRevChart.options.scales.y.ticks.color = textColor;
     zoneRevChart.update();
  }
}

// ── UI ENGINE ────────────────────────────────────────────────
const UI = {
  loading(text = 'Loading...') {
    return `<div class="etel-loading-container">
      <div class="etel-spinner"></div>
      <div style="margin-top: 8px;">${text}</div>
    </div>`;
  },
  error(msg, retryFnName) {
    const btn = retryFnName ? `<button class="btn btn-secondary" style="margin-top:12px;padding:6px 12px;font-size:11px;" onclick="${retryFnName}">🔄 Retry</button>` : '';
    return `<div class="etel-loading-container" style="color:#e63946;">
      <div style="font-size:24px;margin-bottom:8px;">⚠️</div>
      <div>${msg}</div>
      ${btn}
    </div>`;
  }
};

async function loadGoogleMaps(libraries, callbackName) {
  if (window.google && window.google.maps) {
    if (typeof window[callbackName] === 'function') {
      window[callbackName]();
    }
    return;
  }

  // Google calls this global when the key/referrer is rejected
  // (RefererNotAllowedMapError, InvalidKey, BillingNotEnabled, etc.)
  window.gm_authFailure = function () {
    window.ETEL_MAPS_FAILED = true;
    console.error('[ETEL] Google Maps auth failed — check API key referrer restrictions in Google Cloud Console (use https://ethelmarket.com/* and https://www.ethelmarket.com/*).');
    try { document.dispatchEvent(new CustomEvent('etel:maps-failed', { detail: { reason: 'auth' } })); } catch (e) {}
  };

  try {
    const r = await fetch(ETEL.api + '?action=get_config');
    const d = await r.json();
    if (d && d.success && d.data && d.data.google_maps_api_key) {
      ETEL.mapsKey = d.data.google_maps_api_key;
      const script = document.createElement('script');
      script.src = 'https://maps.googleapis.com/maps/api/js?key=' + ETEL.mapsKey + '&libraries=' + libraries + '&loading=async&callback=' + callbackName;
      script.async = true;
      script.defer = true;
      script.onerror = function () {
        window.ETEL_MAPS_FAILED = true;
        console.error('[ETEL] Google Maps script failed to load (network/CSP).');
        try { document.dispatchEvent(new CustomEvent('etel:maps-failed', { detail: { reason: 'network' } })); } catch (e) {}
      };
      document.head.appendChild(script);
    } else {
      window.ETEL_MAPS_FAILED = true;
      console.error('Failed to retrieve Google Maps API key from backend');
      try { document.dispatchEvent(new CustomEvent('etel:maps-failed', { detail: { reason: 'no-key' } })); } catch (e) {}
    }
  } catch (e) {
    window.ETEL_MAPS_FAILED = true;
    console.error('Error fetching dynamic Google Maps configuration:', e);
    try { document.dispatchEvent(new CustomEvent('etel:maps-failed', { detail: { reason: 'config' } })); } catch (err) {}
  }
}

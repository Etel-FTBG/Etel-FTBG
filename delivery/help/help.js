/* ETEL Help & Support page. ONE help desk for every Etel app. Plain JavaScript, no framework (cheap phones, low data).
   Signs in with EtelID like every other Etel page (same token and theme keys), then talks to ../api/boot.php (sup_* actions).
   A "Help" button in any Etel app only needs to link here: ../delivery/help/?app=pay  (app = ride, delivery, driver, courier, service, agent, pay, gebeya, taxi, madeya, group, id, ai).
   Optional: &cat=payment|account|trip|order|safety|app|other   &ref=<trip or order number>. */
(function () {
'use strict';
var API = new URL('../api/boot.php', location.href).pathname;
var K = { tok: 'etel_token', user: 'etel_user', lang: 'etel_help_lang', alang: 'etel_agent_lang', theme: 'theme' };
var EID = 'https://ethelmarket.com/EtelId/';
var SUPPORT = { telegram: 'https://t.me/etelsupport', email: 'support@ethelmarket.com' };
var APPS = ['ride', 'delivery', 'driver', 'courier', 'service', 'agent', 'pay', 'gebeya', 'taxi', 'madeya', 'group', 'id', 'ai', 'other'];
var CATS = [['payment', 'wallet'], ['account', 'user'], ['trip', 'car'], ['order', 'box'], ['safety', 'shield'], ['app', 'phone'], ['other', 'chat']];
var Q = new URLSearchParams(location.search);
var S = { lang: 'am', dict: {}, fall: {}, view: 'boot', me: null, list: null, meta: null, ticket: null, err: '', need2fa: false, app: Q.get('app') || '', cat: Q.get('cat') || '', ref: (Q.get('ref') || '').slice(0, 60), busy: false, timer: null, deep: !!(Q.get('app') || Q.get('cat')) };

function ls(k, v) { try { if (v === undefined) return localStorage.getItem(k); if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) { /* private window */ } return null; }
function $(id) { return document.getElementById(id); }
function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
function t(key, vars) { var s = S.dict[key] || S.fall[key] || key; if (vars) for (var k in vars) if (Object.prototype.hasOwnProperty.call(vars, k)) s = s.split('{' + k + '}').join(vars[k]); return s; }
function ic(n) { return '<svg aria-hidden="true"><use href="#i-' + n + '"/></svg>'; }
function when(s) {
  if (!s) return ''; var d = new Date(String(s).replace(' ', 'T')), m = Math.round((Date.now() - d.getTime()) / 60000);
  if (isNaN(m) || m < 1) return t('time.now'); if (m < 60) return t('time.min', { n: m }); if (m < 1440) return t('time.hour', { n: Math.round(m / 60) }); return t('time.day', { n: Math.round(m / 1440) });
}
function toast(msg) { var o = $('toast'); if (o) o.remove(); var e = document.createElement('div'); e.id = 'toast'; e.className = 'toast'; e.setAttribute('role', 'status'); e.textContent = msg; document.body.appendChild(e); setTimeout(function () { if (e.parentNode) e.parentNode.removeChild(e); }, 2800); }

/* ---------- network ---------- */
function api(action, body) {
  var tok = ls(K.tok) || '';
  return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + tok }, body: JSON.stringify(Object.assign({ action: action }, body || {})) })
    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok && j.success !== false, status: r.status, data: j.data || {}, j: j }; }, function () { return { ok: false, status: r.status, data: {}, j: {} }; }); })
    .catch(function () { return { ok: false, net: true, status: 0, data: {}, j: {} }; });
}
function signOut() { ls(K.tok, null); ls(K.user, null); S.me = null; S.list = null; go('login'); }

/* ---------- theme (same 'theme' key as every Etel page) and language ---------- */
function themeNow() { return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark'; }
function applyTheme(v, save) { document.documentElement.setAttribute('data-theme', v); var m = document.querySelector('meta[name=theme-color]'); if (m) m.content = v === 'light' ? '#FFFBEF' : '#0a0703'; if (save) ls(K.theme, v); }
window.addEventListener('storage', function (e) { if (e.key === K.theme && (e.newValue === 'light' || e.newValue === 'dark')) { applyTheme(e.newValue, false); render(); } });
function themeBtn() { var d = themeNow() === 'dark'; return '<button class="tsw" role="switch" aria-checked="' + d + '" data-act="theme" aria-label="' + esc(t('theme.dark')) + '">' + ic(d ? 'moon' : 'sun') + '</button>'; }
function loadLang() {
  var l = ls(K.lang) || ls(K.alang); S.lang = (l === 'en' || l === 'am') ? l : 'am';
  var get = function (c) { return fetch('lang/' + c + '.json').then(function (r) { return r.json(); }); };
  return Promise.all([get(S.lang), get('en')]).then(function (r) { S.dict = r[0]; S.fall = r[1]; document.documentElement.lang = S.lang; });
}
function setLang(l) { S.lang = l; ls(K.lang, l); ls(K.alang, l); return fetch('lang/' + l + '.json').then(function (r) { return r.json(); }).then(function (d) { S.dict = d; document.documentElement.lang = l; render(); }); }

/* ---------- views ---------- */
function topbar(back) {
  return '<header class="top noprint">' + (back ? '<button class="ib" data-act="back" aria-label="' + esc(t('c.back')) + '">&larr;</button>' : '<img class="logo" src="../agent/logo-128.webp" width="44" height="44" alt="">') +
    '<div class="brand">ETEL<small>' + esc(t('app.name')) + '</small></div>' +
    '<button class="ib" data-act="lang" aria-label="' + esc(t('c.langname') + ' - ' + t('c.lang')) + '">' + esc(t('c.langname')) + '</button>' + themeBtn() + '</header>';
}
function vLogin() {
  return '<div class="lg"><div class="lg-bg" aria-hidden="true"></div><main class="lg-in">' +
    '<div class="lg-tools"><button class="ib" data-act="lang" aria-label="' + esc(t('c.langname') + ' - ' + t('c.lang')) + '">' + esc(t('c.langname')) + '</button>' + themeBtn() + '</div>' +
    '<div class="lg-hero"><div class="lg-halo" aria-hidden="true"></div><img class="lg-logo" src="../agent/logo-320.webp" width="160" height="160" alt="Etel" fetchpriority="high"></div>' +
    '<p class="lg-eyebrow">' + esc(t('login.eyebrow')) + '</p><h1 class="lg-h1">' + esc(t('login.welcome')) + '</h1><p class="lg-tag">' + esc(t('login.tag')) + '</p>' +
    '<section class="lg-card" aria-label="' + esc(t('login.title')) + '">' + (S.err ? '<div class="err" role="alert">' + esc(S.err) + '</div>' : '') +
    '<form id="lf" autocomplete="on"><label class="lg-f"><span>' + esc(t('login.id')) + '</span><div class="lg-w">' + ic('user') + '<input class="lg-in2" name="ident" autocomplete="username" autocapitalize="off" placeholder="' + esc(t('login.ph_id')) + '" required></div></label>' +
    '<label class="lg-f"><span>' + esc(t('login.pw')) + '</span><div class="lg-w">' + ic('lock') + '<input class="lg-in2" type="password" name="pw" autocomplete="current-password" placeholder="' + esc(t('login.ph_pw')) + '" required><button type="button" class="lg-eye" data-act="eye" aria-label="' + esc(t('login.show')) + '" aria-pressed="false">' + ic('eye') + '</button></div></label>' +
    (S.need2fa ? '<label class="lg-f"><span>' + esc(t('login.2fa')) + '</span><div class="lg-w">' + ic('shield') + '<input class="lg-in2" name="totp" inputmode="numeric" autocomplete="one-time-code" maxlength="8"></div></label>' : '') +
    '<div class="lg-row"><a class="lg-link" href="' + EID + 'reset-password/" target="_blank" rel="noopener">' + esc(t('login.forgot')) + '</a></div>' +
    '<button class="lg-go" type="submit" id="lb"><span class="lg-shine" aria-hidden="true"></span>' + ic('lock') + '<b>' + esc(t('login.submit')) + '</b></button></form>' +
    '<div class="lg-or"><i></i><span>' + esc(t('login.need')) + '</span><i></i></div><a class="lg-alt" href="' + EID + '" target="_blank" rel="noopener">' + esc(t('login.create')) + ' <span aria-hidden="true">&rarr;</span></a></section>' +
    '<ul class="lg-trust"><li>' + ic('shield') + '<span>' + esc(t('trust.1')) + '</span></li><li>' + ic('chat') + '<span>' + esc(t('trust.2')) + '</span></li><li>' + ic('globe') + '<span>' + esc(t('trust.3')) + '</span></li></ul>' +
    '<p class="lg-foot">' + esc(t('login.foot')) + '</p></main></div>';
}
function stChip(st) { var c = st === 'solved' ? 'ok' : (st === 'pending' ? 'warn' : (st === 'closed' ? '' : 'gold')); return '<span class="chip ' + c + '">' + esc(t('st.' + st)) + '</span>'; }
function vHome() {
  var name = String((S.me && S.me.name) || '').split(' ')[0], L = S.list;
  var h = '<h1>' + esc(t('home.hello')) + (S.lang === 'am' ? '፣ ' : ', ') + esc(name) + '</h1><p class="sub">' + esc(t('home.sub')) + '</p>';
  if (S.meta && !S.meta.enabled) h += '<div class="banner" role="status">' + esc(t('home.paused')) + '</div>';
  h += '<button class="btn primary hp-new" data-act="new"' + (S.meta && !S.meta.enabled ? ' disabled' : '') + '>' + ic('plus') + esc(t('home.new')) + '</button>' +
    '<h2>' + esc(t('home.topics')) + '</h2><div class="hp-tiles">' + CATS.map(function (c) { return '<button class="hp-tile" data-act="topic" data-cat="' + c[0] + '"' + (S.meta && !S.meta.enabled ? ' disabled' : '') + '>' + ic(c[1]) + '<span>' + esc(t('cat.' + c[0])) + '</span></button>'; }).join('') + '</div>' +
    '<h2>' + esc(t('home.mine')) + '</h2>';
  if (L === null) h += '<div class="err" role="alert">' + esc(t('c.error')) + '</div>';
  else if (!L.length) h += '<div class="empty">' + ic('chat') + '<p>' + esc(t('home.none')) + '</p></div>';
  else h += '<div class="list">' + L.map(function (x) {
    return '<a class="li hp-row" href="#/t/' + x.id + '"><div class="m"><b>' + esc(x.subject) + '</b><small>' + esc(x.ref) + ' · ' + esc(t('app.' + x.app)) + ' · ' + esc(when(x.updated_at)) + '</small></div>' +
      '<div class="r">' + (x.unread ? '<span class="hp-dot" role="img" aria-label="' + esc(t('t.new_reply')) + '"></span>' : '') + stChip(x.status) + '</div></a>'; }).join('') + '</div>';
  h += '<h2>' + esc(t('home.contact')) + '</h2><div class="stack"><a class="btn" href="' + SUPPORT.telegram + '" target="_blank" rel="noopener">' + ic('send') + esc(t('home.tg')) + '</a><a class="btn" href="mailto:' + SUPPORT.email + '">' + ic('mail') + esc(t('home.mail')) + '</a>' +
    '<button class="btn ghost" data-act="signout">' + esc(t('home.signout')) + '</button></div>';
  return '<div class="app">' + topbar(false) + h + '</div>';
}
function vNew() {
  var apps = APPS.map(function (a) { return '<button type="button" class="hp-chip' + (S.app === a ? ' on' : '') + '" data-act="pickapp" data-v="' + a + '" aria-pressed="' + (S.app === a) + '">' + esc(t('app.' + a)) + '</button>'; }).join('');
  var cats = CATS.map(function (c) { return '<button type="button" class="hp-chip' + (S.cat === c[0] ? ' on' : '') + '" data-act="pickcat" data-v="' + c[0] + '" aria-pressed="' + (S.cat === c[0]) + '">' + esc(t('cat.' + c[0])) + '</button>'; }).join('');
  return '<div class="app">' + topbar(true) + '<h1>' + esc(t('new.title')) + '</h1>' + (S.err ? '<div class="err" role="alert">' + esc(S.err) + '</div>' : '') +
    '<form id="nf"><div class="fld"><span id="la">' + esc(t('new.app')) + '</span><div class="hp-chips" role="group" aria-labelledby="la">' + apps + '</div></div>' +
    '<div class="fld"><span id="lc">' + esc(t('new.cat')) + '</span><div class="hp-chips" role="group" aria-labelledby="lc">' + cats + '</div></div>' +
    (S.cat === 'safety' ? '<div class="banner" role="note">' + esc(t('new.safety')) + '</div>' : '') +
    '<label class="fld"><span>' + esc(t('new.subject')) + '</span><input class="in" name="subject" maxlength="160" required placeholder="' + esc(t('new.subject_ph')) + '"></label>' +
    '<label class="fld"><span>' + esc(t('new.body')) + '</span><textarea class="in hp-ta" name="body" rows="6" maxlength="4000" required placeholder="' + esc(t('new.body_ph')) + '"></textarea></label>' +
    '<label class="fld"><span>' + esc(t('new.related')) + '</span><input class="in" name="related" maxlength="60" value="' + esc(S.ref) + '" inputmode="text"></label>' +
    '<p class="sub" style="margin-top:12px">' + esc(t('new.private')) + '</p><div style="margin-top:16px"><button class="btn primary" type="submit" id="nb">' + ic('send') + esc(t('new.submit')) + '</button></div></form></div>';
}
function vThread() {
  var d = S.ticket; if (!d) return '<div class="app">' + topbar(true) + '<div class="stack" style="margin-top:16px"><div class="skel" style="height:60px"></div><div class="skel" style="height:90px"></div></div></div>';
  var T = d.ticket;
  var msgs = d.messages.map(function (m) {
    if (m.from === 'system') return '<div class="hp-sys">' + esc(m.body) + ' · ' + esc(when(m.at)) + '</div>';
    return '<div class="hp-msg ' + (m.from === 'me' ? 'me' : 'sup') + '"><div class="hp-who">' + esc(m.from === 'me' ? t('t.me') : t('t.support')) + '</div><div class="hp-b">' + esc(m.body) + '</div><div class="hp-t">' + esc(when(m.at)) + '</div></div>'; }).join('');
  var foot = '';
  if (d.can_reply) foot += '<form id="rf" class="hp-comp"><textarea class="in hp-ta" name="body" rows="2" maxlength="4000" required placeholder="' + esc(t('t.reply_ph')) + '" aria-label="' + esc(t('t.reply_ph')) + '"></textarea><button class="btn primary" type="submit" id="rb" aria-label="' + esc(t('c.send')) + '">' + ic('send') + '</button></form>' +
    (T.status !== 'solved' ? '<button class="btn ghost" data-act="solved" style="margin-top:10px">' + ic('check') + esc(t('t.solved_btn')) + '</button>' : '');
  else foot += '<div class="banner" role="status">' + esc(t('t.closed_note')) + '</div>';
  if (d.can_rate) foot += '<section class="card hp-rate"><b>' + esc(t('t.rate')) + '</b><div class="hp-stars" role="radiogroup" aria-label="' + esc(t('t.rate')) + '">' + [1, 2, 3, 4, 5].map(function (n) { return '<button type="button" class="hp-star' + (S.stars >= n ? ' on' : '') + '" role="radio" aria-checked="' + (S.stars === n) + '" data-act="star" data-n="' + n + '" aria-label="' + esc(t('t.stars', { n: n })) + '">' + ic('star') + '</button>'; }).join('') + '</div>' +
    '<input class="in" id="rnote" maxlength="255" placeholder="' + esc(t('t.rate_ph')) + '"><button class="btn primary" data-act="rate" style="margin-top:10px"' + (S.stars ? '' : ' disabled') + '>' + esc(t('t.rate_send')) + '</button></section>';
  else if (T.rating) foot += '<p class="sub" style="margin-top:12px;text-align:center">' + esc(t('t.thanks')) + '</p>';
  return '<div class="app">' + topbar(true) + '<p class="lbl" style="margin-top:12px">' + esc(t('t.title', { ref: T.ref })) + ' · ' + esc(t('app.' + T.app)) + '</p><h1 style="margin-top:4px">' + esc(T.subject) + '</h1><div>' + stChip(T.status) + '</div><div class="hp-thread" id="th">' + msgs + '</div>' + foot + '</div>';
}
function render() {
  var el = $('app'); if (!el) return;
  var keep = document.activeElement && document.activeElement.name; var vals = {};
  if (document.activeElement && /^(subject|body|related)$/.test(keep || '')) vals[keep] = document.activeElement.value;
  var v = S.view, h = v === 'login' ? vLogin() : (v === 'new' ? vNew() : (v === 'thread' ? vThread() : (v === 'home' ? vHome() : '<div class="boot"><img class="logo" src="../agent/logo-128.webp" width="76" height="76" alt=""></div>')));
  el.innerHTML = h;
  if (v === 'thread') { var th = $('th'); if (th && th.lastElementChild) th.lastElementChild.scrollIntoView({ block: 'nearest' }); }
}

/* ---------- flow ---------- */
function go(view) { S.view = view; S.err = ''; if (view === 'home') { if (location.hash !== '#/') location.hash = '#/'; } render(); }
function loadHome() {
  return Promise.all([api('sup_meta'), api('sup_my_tickets')]).then(function (r) {
    if (r[0].status === 401 || r[1].status === 401) return signOut();
    S.meta = r[0].ok ? r[0].data : { enabled: true }; S.list = r[1].ok ? r[1].data.tickets : (r[1].net ? [] : null);
    if (S.view === 'home') render();
  });
}
function loadMe() {
  var u = ls(K.user); try { S.me = JSON.parse(u || 'null'); } catch (e) { S.me = null; }
  return route();
}
function route() {
  stopPoll();
  if (!ls(K.tok)) { S.view = 'login'; return render(); }
  var h = (location.hash || '#/').replace(/^#\/?/, '');
  if (S.deep && h === '') { S.deep = false; location.hash = '#/new'; return; }
  if (h === 'new') { S.view = 'new'; S.err = ''; render(); if (!S.meta) loadHome(); return; }
  var m = h.match(/^t\/(\d+)$/);
  if (m) { S.view = 'thread'; S.ticket = null; S.stars = 0; render(); return openThread(m[1]); }
  S.view = 'home'; render(); return loadHome();
}
function openThread(id) {
  return api('sup_ticket_get', { id: id }).then(function (r) {
    if (r.status === 401) return signOut();
    if (!r.ok) { toast(t('c.error')); location.hash = '#/'; return; }
    S.ticket = r.data; if (S.view === 'thread') { render(); startPoll(id); }
  });
}
function startPoll(id) { stopPoll(); S.timer = setInterval(function () { if (document.hidden || S.view !== 'thread') return; var a = document.activeElement; if (a && a.name === 'body' && a.value) return; api('sup_ticket_get', { id: id }).then(function (r) { if (r.ok && S.view === 'thread' && r.data.messages.length !== (S.ticket ? S.ticket.messages.length : -1)) { S.ticket = r.data; render(); } }); }, 25000); }
function stopPoll() { if (S.timer) { clearInterval(S.timer); S.timer = null; } }

document.addEventListener('click', function (e) {
  var b = e.target.closest && e.target.closest('[data-act]'); if (!b) return;
  var act = b.getAttribute('data-act');
  if (act === 'lang') setLang(S.lang === 'am' ? 'en' : 'am');
  else if (act === 'theme') { applyTheme(themeNow() === 'dark' ? 'light' : 'dark', true); render(); }
  else if (act === 'eye') { var pw = document.querySelector('#lf [name=pw]'); if (pw) { var show = pw.type === 'password'; pw.type = show ? 'text' : 'password'; b.setAttribute('aria-pressed', String(show)); b.setAttribute('aria-label', t(show ? 'login.hide' : 'login.show')); } }
  else if (act === 'back') { if (history.length > 1 && document.referrer.indexOf(location.host) !== -1 && S.view !== 'home' && location.hash) history.back(); else location.hash = '#/'; }
  else if (act === 'new') { location.hash = '#/new'; }
  else if (act === 'topic') { S.cat = b.getAttribute('data-cat'); location.hash = '#/new'; }
  else if (act === 'pickapp') { S.app = b.getAttribute('data-v'); keepForm(); }
  else if (act === 'pickcat') { S.cat = b.getAttribute('data-v'); keepForm(); }
  else if (act === 'signout') signOut();
  else if (act === 'solved') { api('sup_ticket_close', { id: S.ticket.ticket.id }).then(function () { return openThread(S.ticket.ticket.id); }); }
  else if (act === 'star') { S.stars = parseInt(b.getAttribute('data-n'), 10) || 0; var note = $('rnote') ? $('rnote').value : ''; render(); if ($('rnote')) $('rnote').value = note; }
  else if (act === 'rate') { var n = $('rnote') ? $('rnote').value : ''; api('sup_ticket_rate', { id: S.ticket.ticket.id, rating: S.stars, note: n }).then(function (r) { if (r.ok) { toast(t('t.thanks')); openThread(S.ticket.ticket.id); } else toast(r.j.message || t('c.error')); }); }
});
function keepForm() { var f = $('nf'), v = f ? { s: f.subject.value, b: f.body.value, r: f.related.value } : null; S.ref = v ? v.r : S.ref; render(); var g = $('nf'); if (g && v) { g.subject.value = v.s; g.body.value = v.b; g.related.value = v.r; } }
document.addEventListener('submit', function (e) {
  var f = e.target; e.preventDefault();
  if (f.id === 'lf') {
    var btn = $('lb'); btn.disabled = true; btn.innerHTML = '<span class="spin" aria-hidden="true"></span><b>' + esc(t('login.busy')) + '</b>';
    api('etelid_login', { identifier: f.ident.value.trim(), password: f.pw.value, totp_code: f.totp ? f.totp.value.trim() : '' }).then(function (r) {
      if (r.ok && r.data.token) { S.need2fa = false; S.err = ''; ls(K.tok, r.data.token); ls(K.user, JSON.stringify(r.data.user || {})); S.meta = null; S.list = null; loadMe(); return; }
      if (r.j && r.j.requires_2fa) S.need2fa = true;
      S.err = r.net ? t('c.error') : (r.j.message || t('login.err')); render();
    });
  } else if (f.id === 'nf') {
    if (!S.app) S.app = 'other'; if (!S.cat) S.cat = 'other';
    var nb = $('nb'); nb.disabled = true; S.err = '';
    api('sup_ticket_create', { app: S.app, category: S.cat, subject: f.subject.value, body: f.body.value, related: f.related.value, lang: S.lang }).then(function (r) {
      if (r.ok) { toast(t('new.done', { ref: r.data.ticket.ref })); S.cat = ''; S.list = null; location.hash = '#/t/' + r.data.ticket.id; return; }
      if (r.status === 401) return signOut();
      S.err = r.net ? t('c.offline') : (r.j.message || t('c.error')); keepForm();
    });
  } else if (f.id === 'rf') {
    var rb = $('rb'); rb.disabled = true;
    api('sup_ticket_reply', { id: S.ticket.ticket.id, body: f.body.value }).then(function (r) { if (r.ok) openThread(S.ticket.ticket.id); else { rb.disabled = false; toast(r.net ? t('c.offline') : (r.j.message || t('c.error'))); } });
  }
});
window.addEventListener('hashchange', function () { if (S.view !== 'login') route(); });
if (S.cat && CATS.every(function (c) { return c[0] !== S.cat; })) S.cat = '';
if (S.app && APPS.indexOf(S.app) === -1) S.app = '';
loadLang().then(function () {
  if (ls(K.tok)) loadMe(); else { S.view = 'login'; render(); }
}, function () { document.body.innerHTML = '<p style="padding:24px;color:#F5E6A3">Could not load. Please refresh.</p>'; });
})();

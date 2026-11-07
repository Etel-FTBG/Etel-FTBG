/* ETEL Agent portal. Plain JavaScript, no framework (cheap phones, low data). Amharic first, English second.
   Talks to ../api/boot.php (fa_agent_* actions). Everything the agent sees is plain: no risk scores, no fraud words.
   The QR comes from qr.js (works offline). The last good answer is kept on the phone so the code and QR still show without internet. */
(function () {
'use strict';
var API = new URL('../api/boot.php', location.href).pathname;
var VER = (function () { try { var m = /[?&]v=([^&]+)/.exec(document.currentScript.src); return m ? m[1] : '0'; } catch (e) { return '0'; } })();
function langUrl(c) { return 'lang/' + c + '.json?v=' + VER; }
var K = { theme: 'theme', tok: 'etel_token', user: 'etel_user', lang: 'etel_agent_lang', me: 'etel_agent_me', photo: 'etel_agent_photo', dev: 'etel_dev' };
var SUPPORT = { telegram: 'https://t.me/etelsupport', email: 'support@ethelmarket.com' };
var ROUTES = ['home', 'code', 'badge', 'card', 'refs', 'pay', 'me', 'rules', 'support', 'book'];
var S = { lang: 'am', dict: {}, fall: {}, me: null, view: 'boot', data: {}, offline: false, err: '', need2fa: false, installEv: null, sheet: null };

function ls(k, v) { try { if (v === undefined) return localStorage.getItem(k); if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) { /* private window: not remembered */ } return null; }
function $(id) { return document.getElementById(id); }
function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
function t(key, vars) {
  var s = S.dict[key] || S.fall[key] || key;
  if (vars) for (var k in vars) if (Object.prototype.hasOwnProperty.call(vars, k)) s = s.split('{' + k + '}').join(vars[k]);
  return s;
}
function fmt(n) { n = Number(n) || 0; return n % 1 === 0 ? String(n) : n.toFixed(2); }
function ic(name) { return '<svg aria-hidden="true"><use href="#i-' + name + '"/></svg>'; }
function when(s) {
  if (!s) return '';
  var d = new Date(String(s).replace(' ', 'T')), m = Math.round((Date.now() - d.getTime()) / 60000);
  if (isNaN(m) || m < 1) return t('t.now'); if (m < 60) return t('t.min', { n: m });
  if (m < 1440) return t('t.hour', { n: Math.round(m / 60) }); return t('t.day', { n: Math.round(m / 1440) });
}
function device() {
  var d = ls(K.dev);
  if (!d) { var a = new Uint8Array(16); try { crypto.getRandomValues(a); } catch (e) { for (var i = 0; i < 16; i++) a[i] = Math.floor(Math.random() * 256); } d = ''; for (var j = 0; j < 16; j++) d += ('0' + a[j].toString(16)).slice(-2); ls(K.dev, d); }
  return d;
}
function toast(msg) {
  var old = $('toast'); if (old) old.remove();
  var el = document.createElement('div'); el.id = 'toast'; el.className = 'toast'; el.setAttribute('role', 'status'); el.textContent = msg; document.body.appendChild(el);
  setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 2600);
}

/* ---------- network ---------- */
function api(action, body) {
  var tok = ls(K.tok) || '';
  return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + tok }, body: JSON.stringify(Object.assign({ action: action }, body || {})) })
    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok && j.success !== false, status: r.status, data: j.data || {}, j: j }; }, function () { return { ok: false, status: r.status, data: {}, j: {} }; }); })
    .catch(function () { return { ok: false, net: true, status: 0, data: {}, j: {} }; });
}
function signOut(msg) { ls(K.tok, null); ls(K.user, null); ls(K.me, null); S.me = null; S.err = msg || ''; go('login'); }

/* ---------- theme: the same 'theme' key and 'dark'/'light' values as the Ride, Driver and Admin pages, so one choice follows the person everywhere ---------- */
function themeNow() { var a = document.documentElement.getAttribute('data-theme'); return a === 'light' ? 'light' : 'dark'; }
function applyTheme(v, save) {
  document.documentElement.setAttribute('data-theme', v);
  var m = document.querySelector('meta[name=theme-color]'); if (m) m.content = v === 'light' ? '#FFFBEF' : '#0a0703';
  if (save) ls(K.theme, v);
}
window.addEventListener('storage', function (e) { if (e.key === K.theme && (e.newValue === 'light' || e.newValue === 'dark')) { applyTheme(e.newValue, false); render(); } });

/* ---------- language ---------- */
function loadLang() {
  var get = function (c) { return fetch(langUrl(c)).then(function (r) { return r.json(); }); };
  var l = ls(K.lang); S.lang = (l === 'en' || l === 'am') ? l : 'am';
  return Promise.all([get(S.lang), get('en')]).then(function (r) { S.dict = r[0]; S.fall = r[1]; document.documentElement.lang = S.lang; });
}
function setLang(l) {
  S.lang = l; ls(K.lang, l);
  return Promise.all([fetch(langUrl(l)).then(function (r) { return r.json(); })]).then(function (r) { S.dict = r[0]; document.documentElement.lang = l; render(); });
}

/* ---------- state machine ---------- */
function go(view) { S.view = view; if (ROUTES.indexOf(view) >= 0) { if (location.hash !== '#/' + view) location.hash = '#/' + view; } render(); }
function loadMe() {
  return api('fa_agent_me', { device: device() }).then(function (r) {
    if (r.status === 401) return signOut();
    if (r.net) { var c = ls(K.me); if (c) { try { S.me = JSON.parse(c); S.offline = true; return settle(); } catch (e) { /* fall through */ } } S.err = t('c.error'); S.view = 'fail'; return render(); }
    if (r.status === 403 && r.j.profile_incomplete) { S.view = 'limited'; return render(); }
    if (!r.ok) { S.err = r.j.message || t('c.error'); S.view = 'fail'; return render(); }
    S.offline = false; S.me = r.data; ls(K.me, JSON.stringify(r.data)); settle();
  });
}
function settle() {
  var m = S.me;
  if (!m || !m.is_agent) { S.view = 'apply'; return render(); }
  if (m.agent.status !== 'approved') { S.view = 'wait'; return render(); }
  var h = (location.hash || '').replace('#/', '');
  S.view = ROUTES.indexOf(h) >= 0 ? h : 'home'; render(); if (S.view !== h) location.replace('#/' + S.view);
  loadView();
}
function loadView() {
  var v = S.view;
  if (v === 'book' && window.ETELBook && window.ETELBook.open) window.ETELBook.open();
  if (v === 'refs' && !S.data.refs) api('fa_agent_referrals').then(function (r) { S.data.refs = r.ok ? r.data.referrals : (r.net ? [] : null); S.data.refsErr = !r.ok && !r.net; if (S.view === 'refs') render(); });
  if (v === 'pay' && !S.data.pays) api('fa_agent_payouts').then(function (r) { S.data.pays = r.ok ? r.data.payouts : (r.net ? [] : null); S.data.paysErr = !r.ok && !r.net; if (S.view === 'pay') render(); });
}

/* ---------- views ---------- */
function themeBtn() {
  var dark = themeNow() === 'dark';
  return '<button class="tsw" role="switch" aria-checked="' + dark + '" data-act="theme" aria-label="' + esc(t('theme.dark')) + '">' + ic(dark ? 'moon' : 'sun') + '</button>';
}
function topbar(back) {
  var u = S.me && S.me.unread ? '<span class="dot">' + esc(S.me.unread) + '</span>' : '';
  return '<header class="top noprint"><img class="logo" src="logo-128.webp" width="44" height="44" alt=""><div class="brand">ETEL<small>' + esc(t('app.name')) + '</small></div>' +
    (S.me && S.me.is_agent && S.me.agent.status === 'approved' ? '<button class="ib" data-act="bell" aria-label="' + esc((S.me.unread ? S.me.unread + ' - ' : '') + t('home.messages')) + '">' + ic('bell') + u + '</button>' : '') +
    '<button class="ib" data-act="lang" aria-label="' + esc(t('c.langname') + ' - ' + t('c.lang')) + '">' + esc(t('c.langname')) + '</button>' + themeBtn() + '</header>';
}
function navbar() {
  var items = [['home', 'home', 'nav.home'], ['code', 'qr', 'nav.code'], ['refs', 'users', 'nav.refs'], ['pay', 'wallet', 'nav.pay'], ['me', 'user', 'nav.me']];
  var cur = S.view === 'badge' || S.view === 'card' || S.view === 'book' ? 'code' : (S.view === 'rules' || S.view === 'support' ? 'me' : S.view);
  return '<nav class="nav noprint" aria-label="Menu">' + items.map(function (i) { return '<a href="#/' + i[0] + '"' + (cur === i[0] ? ' aria-current="page"' : '') + '>' + ic(i[1]) + '<span>' + esc(t(i[2])) + '</span></a>'; }).join('') + '</nav>';
}
function shell(inner) { return '<div class="app">' + topbar() + (S.offline ? '<div class="banner" role="status">' + esc(t('c.offline')) + '</div>' : '') + inner + '</div>' + navbar(); }
function plain(inner) { return '<div class="app">' + topbar() + inner + '</div>'; }
function emptyBox(icon, text) { return '<div class="empty">' + ic(icon) + '<p>' + esc(text) + '</p></div>'; }
function skel(n) { var h = '<div class="list" aria-busy="true">'; for (var i = 0; i < n; i++) h += '<div class="li"><div class="av" style="background:rgba(212,175,55,.15)"></div><div class="m"><div class="skel" style="width:55%"></div><div class="skel" style="width:35%;margin-top:8px"></div></div></div>'; return h + '</div>'; }

function vLogin() {
  var EID = 'https://ethelmarket.com/EtelId/';
  return '<div class="lg"><div class="lg-bg" aria-hidden="true"></div><main class="lg-in">' +
    '<div class="lg-tools"><button class="ib" data-act="lang" aria-label="' + esc(t('c.langname') + ' - ' + t('c.lang')) + '">' + esc(t('c.langname')) + '</button>' + themeBtn() + '</div>' +
    '<div class="lg-hero"><div class="lg-halo" aria-hidden="true"></div><img class="lg-logo" src="logo-320.webp" width="160" height="160" alt="Etel" fetchpriority="high"></div>' +
    '<p class="lg-eyebrow">' + esc(t('login.eyebrow')) + '</p><h1 class="lg-h1">' + esc(t('login.welcome')) + '</h1><p class="lg-tag">' + esc(t('login.tag')) + '</p>' +
    '<section class="lg-card" aria-label="' + esc(t('login.title')) + '">' + (S.err ? '<div class="err" role="alert">' + esc(S.err) + '</div>' : '') +
    '<form id="lf" autocomplete="on"><label class="lg-f"><span>' + esc(t('login.id')) + '</span><div class="lg-w">' + ic('user') + '<input class="lg-in2" name="ident" autocomplete="username" autocapitalize="off" inputmode="email" placeholder="' + esc(t('login.ph_id')) + '" required></div></label>' +
    '<label class="lg-f"><span>' + esc(t('login.pw')) + '</span><div class="lg-w">' + ic('lock') + '<input class="lg-in2" type="password" name="pw" autocomplete="current-password" placeholder="' + esc(t('login.ph_pw')) + '" required><button type="button" class="lg-eye" data-act="eye" aria-label="' + esc(t('login.show')) + '" aria-pressed="false">' + ic('eye') + '</button></div></label>' +
    (S.need2fa ? '<label class="lg-f"><span>' + esc(t('login.2fa')) + '</span><div class="lg-w">' + ic('shield') + '<input class="lg-in2" name="totp" inputmode="numeric" autocomplete="one-time-code" maxlength="8"></div></label>' : '') +
    '<div class="lg-row"><a class="lg-link" href="' + EID + 'reset-password/" target="_blank" rel="noopener">' + esc(t('login.forgot')) + '</a></div>' +
    '<button class="lg-go" type="submit" id="lb"><span class="lg-shine" aria-hidden="true"></span>' + ic('lock') + '<b>' + esc(t('login.submit')) + '</b></button></form>' +
    '<div class="lg-or"><i></i><span>' + esc(t('login.need')) + '</span><i></i></div>' +
    '<a class="lg-alt" href="' + EID + '" target="_blank" rel="noopener">' + esc(t('login.create')) + ' <span aria-hidden="true">&rarr;</span></a></section>' +
    '<ul class="lg-trust"><li>' + ic('shield') + '<span>' + esc(t('trust.1')) + '</span></li><li>' + ic('check') + '<span>' + esc(t('trust.2')) + '</span></li><li>' + ic('wallet') + '<span>' + esc(t('trust.3')) + '</span></li></ul>' +
    '<p class="lg-foot">' + esc(t('login.foot')) + '</p></main></div>';
}
function vLimited() { return plain('<div class="card"><p>' + esc(t('limited')) + '</p></div><div style="margin-top:16px"><button class="btn" data-act="signout">' + esc(t('me.signout')) + '</button></div>'); }
function vFail() { return plain('<div class="err" role="alert">' + esc(S.err || t('c.error')) + '</div><div style="margin-top:16px"><button class="btn primary" data-act="retry">' + esc(t('c.retry')) + '</button></div>'); }
function vApply() {
  var types = ['hotel', 'parking', 'shoe_shine', 'restaurant', 'shop', 'other'];
  return plain('<h1>' + esc(t('apply.title')) + '</h1><p class="sub">' + esc(t('apply.intro')) + '</p>' + (S.err ? '<div class="err" role="alert">' + esc(S.err) + '</div>' : '') +
    '<form id="af"><label class="fld"><span>' + esc(t('apply.venue')) + '</span><input class="in" name="venue" maxlength="120" required></label>' +
    '<label class="fld"><span>' + esc(t('apply.type')) + '</span><select class="in" name="type">' + types.map(function (x) { return '<option value="' + x + '">' + esc(t('type.' + x)) + '</option>'; }).join('') + '</select></label>' +
    '<label class="fld"><span>' + esc(t('apply.zone')) + '</span><input class="in" name="zone" maxlength="80"></label>' +
    '<label class="fld"><span>' + esc(t('apply.payout')) + '</span><input class="in ltr" name="payout" inputmode="tel" autocomplete="tel" placeholder="09XXXXXXXX" required></label>' +
    '<p class="sub" style="margin-top:12px">' + esc(t('apply.age')) + '</p><div style="margin-top:16px"><button class="btn primary" type="submit" id="ab">' + ic('check') + esc(t('apply.send')) + '</button></div></form>' +
    '<div style="margin-top:12px"><button class="btn ghost" data-act="signout">' + esc(t('me.signout')) + '</button></div>');
}
function vWait() {
  var st = S.me.agent.status, key = st === 'rejected' ? 'rejected' : (st === 'suspended' ? 'suspended' : 'pending');
  var off = key === 'pending' && S.me.program_enabled === false;
  return plain('<div class="card"><h1 style="margin-top:0">' + esc(t(off ? 'st.off.t' : 'st.' + key + '.t')) + '</h1><p class="sub">' + esc(t(off ? 'st.off.x' : 'st.' + key + '.x')) + '</p></div>' +
    '<div class="stack" style="margin-top:16px"><button class="btn primary" data-act="retry">' + ic('refresh') + esc(t('c.retry')) + '</button>' + (key !== 'pending' ? '<a class="btn" href="' + SUPPORT.telegram + '" rel="noopener">' + ic('chat') + esc(t('sup.telegram')) + '</a>' : '') + '<button class="btn ghost" data-act="signout">' + esc(t('me.signout')) + '</button></div>');
}
function count(n) {
  var v = Number(n) || 0, lbl = fmt(v) + ' ' + t('c.etb');
  return '<span class="sr">' + esc(lbl) + '</span><span aria-hidden="true"><span class="count' + (v % 1 ? ' dec' : '') + '" style="--n:' + Math.floor(v) + '">' + (v % 1 ? fmt(v) : '') + '</span><span class="cur">' + esc(t('c.etb')) + '</span></span>';
}
function vHome() {
  var m = S.me, a = m.agent, b = m.balances, r = m.rules, p = m.progress, need = Number(r.repeat_trips) || 3;
  var trips = p ? p.trips : 0, off = Math.round(264 * (1 - Math.min(1, trips / need)));
  var h = '<h1>' + esc(t('home.hello')) + (S.lang === 'am' ? '\u1363 ' : ', ') + esc(String(a.name || '').split(' ')[0]) + '</h1><p class="sub">' + esc(a.venue) + '</p>' +
    (a.age_verified ? '<span class="ver">' + ic('check') + esc(t('home.verified')) + '</span>' : '') +
    '<section class="card" aria-label="' + esc(t('home.total')) + '"><div class="row"><div><div class="lbl">' + esc(t('home.total')) + '</div>' + count(b.total) + '</div>' +
    '<div class="ringw" role="img" aria-label="' + esc(trips + '/' + need) + '"><svg class="ring" viewBox="0 0 100 100"><circle class="bg" cx="50" cy="50" r="42"/><circle class="fg" cx="50" cy="50" r="42" style="--off:' + off + '"/></svg><b>' + trips + '/' + need + '</b></div></div>' +
    '<div class="bk3"><div class="bk"><span><i style="background:var(--warn)"></i>' + esc(t('home.pending')) + '</span><b class="num">' + esc(fmt(b.pending)) + '</b></div>' +
    '<div class="bk"><span><i style="background:var(--ok)"></i>' + esc(t('home.available')) + '</span><b class="num">' + esc(fmt(Math.max(0, b.available))) + '</b></div>' +
    '<div class="bk"><span><i style="background:var(--gold)"></i>' + esc(t('home.paid')) + '</span><b class="num">' + esc(fmt(b.paid)) + '</b></div></div>' +
    '<p class="sub" style="margin-top:12px">' + esc(t('home.pending_hint', { d: r.hold_days })) + '</p>' + (b.available < 0 ? '<p class="banner">' + esc(t('home.owed', { x: fmt(-b.available) })) + '</p>' : '') + '</section>' +
    '<div class="nudge">' + ic('star') + '<span><b>' + esc(t('home.next')) + '</b><br>' + esc(p ? t('home.next_text', { n: p.trips, t: need, d: p.days_left }) : t('home.next_none', { t: need, d: r.repeat_days, b: fmt(r.bonus_repeat) })) + '</span></div>' +
    '<div class="grid2"><a class="btn primary" href="#/code">' + ic('qr') + esc(t('home.show_code')) + '</a><a class="btn" href="#/badge">' + ic('badge') + esc(t('home.my_badge')) + '</a></div>' +
    '<a class="btn" href="#/book" style="margin-top:12px">' + ic('car') + esc(t('home.book')) + '</a>' +
    '<div style="margin-top:12px"><span class="chip gold">' + esc(t('tier.' + a.tier)) + '</span></div>' + tierCard(m.tier_progress);
  return shell(h);
}
/* How far to the next level. Only riders and repeat rate are shown: never any fraud or risk limits. */
function tierCard(tp) {
  if (!tp) return '';
  if (!tp.next) return '<section class="card tier-prog"><b>' + esc(t('tier.' + tp.tier)) + '</b><p class="sub">' + esc(t('tier.top')) + '</p></section>';
  var nr = Number(tp.need_riders) || 0, np = Number(tp.need_repeat_pct) || 0, riders = Number(tp.riders) || 0, rp = Number(tp.repeat_pct) || 0;
  var p1 = nr ? riders / (riders + nr) : 1, p2 = np ? rp / (rp + np) : 1, pct = Math.round(Math.min(p1, p2) * 100);
  var lines = []; if (nr) lines.push(t('tier.need_riders', { n: nr })); if (np) lines.push(t('tier.need_repeat', { p: np }));
  var perk = tp.next_hold_days != null && Number(tp.next_hold_days) < Number(tp.hold_days) ? '<p class="sub" style="margin-top:6px">' + esc(t('tier.perk_next', { tier: t('tier.' + tp.next), d: tp.next_hold_days, c: tp.hold_days })) + '</p>' : '';
  return '<section class="card tier-prog" aria-label="' + esc(t('tier.next_title', { tier: t('tier.' + tp.next) })) + '"><b>' + esc(t('tier.next_title', { tier: t('tier.' + tp.next) })) + '</b>' +
    '<div class="bar" role="progressbar" aria-label="' + esc(t('tier.next_title', { tier: t('tier.' + tp.next) })) + '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + pct + '"><i style="width:' + pct + '%"></i></div>' +
    '<p class="sub" style="margin-top:8px">' + esc(lines.join(' ')) + ' ' + esc(t('tier.window', { d: tp.window_days })) + '</p>' + perk + '</section>';
}
function rideLink() { return new URL('../ride/?ref=' + encodeURIComponent(S.me.agent.code || ''), location.href).href; }
function qrSvg() {
  var q = QR.encode(rideLink()), n = q.size + 8;
  return '<svg viewBox="0 0 ' + n + ' ' + n + '" shape-rendering="crispEdges" role="img" aria-label="QR ' + esc(S.me.agent.code) + '"><rect width="' + n + '" height="' + n + '" fill="#fff"/><path d="' + QR.path(q, 4) + '" fill="#000"/></svg>';
}
function vCode() {
  var a = S.me.agent; if (!a.code) return shell('<h1>' + esc(t('code.title')) + '</h1>' + emptyBox('qr', t('code.pending')));
  return shell('<h1>' + esc(t('code.title')) + '</h1><p class="sub">' + esc(t('code.scan')) + '</p><div class="qrbox">' + qrSvg() + '</div><div class="codebig" id="cd">' + esc(a.code) + '</div><div class="linkbox">' + esc(rideLink()) + '</div>' +
    '<div class="stack" style="margin-top:20px"><button class="btn primary" data-act="copy">' + ic('copy') + esc(t('c.copy')) + '</button><button class="btn" data-act="share">' + ic('share') + esc(t('code.share')) + '</button>' +
    '<button class="btn" data-act="saveqr">' + ic('image') + esc(t('code.save_qr')) + '</button><a class="btn" href="#/card">' + ic('print') + esc(t('code.print')) + '</a><a class="btn ghost" href="#/badge">' + ic('badge') + esc(t('home.my_badge')) + '</a></div>');
}
function vBadge() {
  var has = !!ls(K.photo);
  return shell('<h1>' + esc(t('badge.title')) + '</h1><p class="sub">' + esc(t('badge.show')) + '</p><div class="badgebox"><canvas id="bc" width="720" height="1080" role="img" aria-label="' + esc(S.me.agent.name + ' ' + S.me.agent.code) + '"></canvas></div>' +
    '<div class="stack" style="margin-top:20px"><button class="btn primary" data-act="savebadge">' + ic('image') + esc(t('badge.save')) + '</button>' +
    '<label class="btn" for="ph" style="cursor:pointer">' + ic('user') + esc(t('badge.photo')) + '</label><input id="ph" class="sr" type="file" accept="image/*" capture="user"><p class="sub" style="text-align:center">' + esc(t('badge.photo_note')) + '</p></div>');
}
function vCard() {
  var a = S.me.agent;
  return shell('<div class="noprint"><h1>' + esc(t('code.print')) + '</h1><p class="sub">A6 - 105 x 148 mm</p><div style="margin-top:12px"><button class="btn primary" data-act="print">' + ic('print') + esc(t('card.print')) + '</button></div></div>' +
    '<div class="a6wrap"><div class="a6"><div><img class="mark" src="logo-320.webp" width="60" height="60" alt="" style="margin:0 auto;display:block"><h3>' + esc(S.cardAm.ride) + '</h3><h4>' + esc(S.cardEn.ride) + '</h4></div>' +
    '<div class="qr">' + qrSvg() + '</div><div><div class="scan">' + esc(S.cardAm.scan) + '</div><small>' + esc(S.cardEn.scan) + '</small></div>' +
    '<div><small>' + esc(S.cardAm.code) + ' / ' + esc(S.cardEn.code) + '</small><div class="cd">' + esc(a.code) + '</div><small>' + esc(a.venue) + '</small></div></div></div>');
}
function refLine(e) {
  var cls = { pending: 'warn', available: 'ok', paid: 'ok', rejected: 'bad', reversed: 'bad' }[e.status] || '';
  return '<div style="display:flex;gap:8px;align-items:center;justify-content:space-between;margin-top:6px"><span>' + esc(t(e.kind === 'repeat3' ? 'rs.repeat' : 'rs.first')) + ' <b class="num">' + esc(fmt(e.amount)) + '</b></span><span class="chip ' + cls + '">' + esc(t('rs.' + e.status)) + '</span></div>' +
    (e.reason ? '<small class="sub">' + esc(t('why.' + e.reason) === 'why.' + e.reason ? t('why.default') : t('why.' + e.reason)) + '</small>' : '');
}
function vRefs() {
  var L = S.data.refs, h = '<h1>' + esc(t('refs.title')) + '</h1>';
  if (L === undefined) return shell(h + skel(4));
  if (L === null) return shell(h + '<div class="err" role="alert">' + esc(t('c.error')) + '</div><div style="margin-top:12px"><button class="btn primary" data-act="retry">' + esc(t('c.retry')) + '</button></div>');
  if (!L.length) return shell(h + emptyBox('users', t('refs.empty')));
  return shell(h + '<div class="list">' + L.map(function (r) {
    var cls = { waiting: '', expired: '', rejected: 'bad', active: 'ok' }[r.status] || '';
    var body = r.earnings && r.earnings.length ? r.earnings.map(refLine).join('') : '<span class="chip ' + cls + '" style="margin-top:6px">' + esc(t('rs.' + (r.status === 'active' ? 'pending' : r.status))) + '</span>' + (r.reason ? '<br><small class="sub">' + esc(t('why.' + r.reason) === 'why.' + r.reason ? t('why.default') : t('why.' + r.reason)) + '</small>' : '');
    return '<div class="li" style="align-items:flex-start"><div class="av" aria-hidden="true">' + esc(String(r.rider).replace(/\D/g, '').slice(0, 2) || '09') + '</div><div class="m"><b class="ltr num">' + esc(r.rider) + '</b><small>' + esc(when(r.claimed_at)) + '</small>' + body + '</div></div>';
  }).join('') + '</div>');
}
function vPay() {
  var L = S.data.pays, r = S.me.rules, h = '<h1>' + esc(t('pay.title')) + '</h1><p class="sub">' + esc(t('pay.how', { phone: S.me.agent.payout_phone, min: fmt(r.min_payout) })) + '</p>';
  if (L === undefined) return shell(h + skel(3));
  if (L === null) return shell(h + '<div class="err" role="alert">' + esc(t('c.error')) + '</div><div style="margin-top:12px"><button class="btn primary" data-act="retry">' + esc(t('c.retry')) + '</button></div>');
  if (!L.length) return shell(h + emptyBox('wallet', t('pay.empty')));
  return shell(h + '<div class="list">' + L.map(function (p) {
    return '<div class="li"><div class="av" aria-hidden="true">' + ic('wallet') + '</div><div class="m"><b class="num">' + esc(t('c.etb')) + ' ' + esc(fmt(p.amount)) + '</b><small>' + esc(when(p.paid_at || p.created_at)) + (p.tx_ref ? ' - ' + esc(t('pay.ref')) + ' ' + esc(p.tx_ref) : '') + '</small></div><div class="r"><span class="chip ' + (p.status === 'paid' ? 'ok' : 'warn') + '">' + esc(t(p.status === 'paid' ? 'pay.sent' : 'pay.preparing')) + '</span></div></div>';
  }).join('') + '</div>');
}
function vMe() {
  var a = S.me.agent;
  return shell('<h1>' + esc(t('me.title')) + '</h1><div class="list"><div class="li"><div class="m"><small>' + esc(t('me.name')) + '</small><b>' + esc(a.name) + '</b></div><span class="chip gold">' + esc(t('tier.' + a.tier)) + '</span></div>' +
    '<div class="li"><div class="m"><small>' + esc(t('me.venue')) + '</small><b>' + esc(a.venue) + '</b></div></div></div>' +
    '<form id="pf" class="card" style="margin-top:16px"><label class="fld" style="margin-top:0"><span>' + esc(t('me.telebirr')) + '</span><input class="in ltr" name="payout" inputmode="tel" value="' + esc(a.payout_phone) + '"></label><div style="margin-top:12px"><button class="btn" type="submit">' + ic('check') + esc(t('c.save')) + '</button></div></form>' +
    '<div class="stack" style="margin-top:16px"><a class="btn" href="#/rules">' + ic('info') + esc(t('me.rules')) + '</a><a class="btn" href="#/support">' + ic('chat') + esc(t('me.support')) + '</a>' +
    (S.installEv ? '<button class="btn" data-act="install">' + ic('plus') + esc(t('me.install')) + '</button>' : '') +
    '<button class="btn" data-act="lang">' + ic('globe') + esc(t('c.langname')) + '</button><button class="btn danger" data-act="signout">' + esc(t('me.signout')) + '</button></div>');
}
function vRules() {
  var r = S.me.rules, v = { b1: fmt(r.bonus_first), b2: fmt(r.bonus_repeat), km: fmt(r.min_km || 1), t: r.repeat_trips, d: r.repeat_days, h: r.hold_days, min: fmt(r.min_payout) };
  return shell('<h1>' + esc(t('rules.title')) + '</h1>' + [1, 2, 3, 4, 5, 6].map(function (i) { return '<details' + (i === 1 ? ' open' : '') + '><summary>' + esc(t('rules.q' + i)) + '</summary><p>' + esc(t('rules.a' + i, v)) + '</p></details>'; }).join(''));
}
function vSupport() {
  return shell('<h1>' + esc(t('sup.title')) + '</h1><p class="sub">' + esc(t('sup.text')) + '</p><div class="stack" style="margin-top:16px"><a class="btn primary" href="../help/?app=agent">' + ic('chat') + esc(t('sup.ticket')) + '</a><a class="btn" href="' + SUPPORT.telegram + '" rel="noopener">' + ic('chat') + esc(t('sup.telegram')) + '</a><a class="btn" href="mailto:' + SUPPORT.email + '">' + ic('mail') + esc(t('sup.email')) + '</a></div><p class="sub" style="margin-top:16px">' + esc(t('sup.note')) + '</p>');
}
/* "Book a ride for a customer": loaded on demand (book.js) so the first screen stays small. */
function vBook() {
  if (window.ETELBook) return window.ETELBook.view();
  loadBook();
  return shell('<div class="stack" style="margin-top:16px"><div class="skel" style="height:56px"></div><div class="skel" style="height:160px"></div></div>');
}
function loadBook() {
  if (S.bookLoading) return; S.bookLoading = true;
  var sc = document.createElement('script'); sc.src = 'book.js?v=3';
  sc.onload = function () { S.bookLoading = false; if (S.view === 'book') { if (window.ETELBook.open) window.ETELBook.open(); render(); } };
  sc.onerror = function () { S.bookLoading = false; toast(t('c.error')); };
  document.body.appendChild(sc);
}
var VIEWS = { book: vBook, login: vLogin, limited: vLimited, fail: vFail, apply: vApply, wait: vWait, home: vHome, code: vCode, badge: vBadge, card: vCard, refs: vRefs, pay: vPay, me: vMe, rules: vRules, support: vSupport };

function render() {
  var f = VIEWS[S.view];
  if (!f) { $('app').innerHTML = '<div class="boot"><img class="logo" src="logo-128.webp" width="76" height="76" alt=""></div>'; return; }
  if (S.view === 'card' && !S.cardAm) { Promise.all([fetch(langUrl('am')).then(function (r) { return r.json(); }), fetch(langUrl('en')).then(function (r) { return r.json(); })]).then(function (x) { S.cardAm = { ride: x[0]['card.ride'], scan: x[0]['card.scan'], code: x[0]['card.code'] }; S.cardEn = { ride: x[1]['card.ride'], scan: x[1]['card.scan'], code: x[1]['card.code'] }; render(); }); return; }
  var keep = document.activeElement && document.activeElement.name;
  $('app').innerHTML = f(); window.scrollTo(0, 0);
  if (S.view === 'badge') drawBadge($('bc'));
  if (keep) { var el = document.querySelector('[name="' + keep + '"]'); if (el) el.focus(); }
}

/* ---------- canvas: badge and QR picture ---------- */
function spaced(ctx, text, x, y, gap) {
  var w = 0, i; for (i = 0; i < text.length; i++) w += ctx.measureText(text[i]).width + gap; w -= gap;
  var cx = x - w / 2; ctx.textAlign = 'left'; for (i = 0; i < text.length; i++) { ctx.fillText(text[i], cx, y); cx += ctx.measureText(text[i]).width + gap; }
}
function fit(ctx, text, max, size, weight) { do { ctx.font = weight + ' ' + size + 'px "Noto Sans Ethiopic",system-ui,sans-serif'; size -= 2; } while (ctx.measureText(text).width > max && size > 18); }
function qrOn(ctx, x, y, size, pad) {
  var q = QR.encode(rideLink()), cell = (size - 2 * pad) / q.size;
  ctx.fillStyle = '#fff'; ctx.fillRect(x, y, size, size); ctx.fillStyle = '#000';
  for (var r = 0; r < q.size; r++) for (var c = 0; c < q.size; c++) if (q.modules[r][c]) ctx.fillRect(Math.floor(x + pad + c * cell), Math.floor(y + pad + r * cell), Math.ceil(cell), Math.ceil(cell));
}
function drawBadge(cv) {
  if (!cv) return; var a = S.me.agent, ctx = cv.getContext('2d'), W = 720, H = 1080;
  var logo = null;
  var paint = function (img) {
    var g = ctx.createLinearGradient(0, 0, 0, H); g.addColorStop(0, '#1a1206'); g.addColorStop(1, '#0a0703'); ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
    var gb = ctx.createLinearGradient(0, 0, W, 0); gb.addColorStop(0, '#8B6914'); gb.addColorStop(.5, '#F5E6A3'); gb.addColorStop(1, '#8B6914'); ctx.fillStyle = gb; ctx.fillRect(0, 0, W, 14);
    ctx.strokeStyle = '#D4AF37'; ctx.lineWidth = 6; ctx.strokeRect(14, 14, W - 28, H - 28);
    if (logo) ctx.drawImage(logo, W / 2 - 64, 36, 128, 128);
    ctx.fillStyle = '#D4AF37'; ctx.font = '700 26px "Noto Sans Ethiopic",sans-serif'; spaced(ctx, t('badge.agent'), W / 2, 190, 4);
    var cy = 300, R = 100; ctx.save(); ctx.beginPath(); ctx.arc(W / 2, cy, R, 0, 7); ctx.clip();
    if (img) { var s = Math.min(img.width, img.height); ctx.drawImage(img, (img.width - s) / 2, (img.height - s) / 2, s, s, W / 2 - R, cy - R, 2 * R, 2 * R); }
    else { var ig = ctx.createLinearGradient(0, cy - R, 0, cy + R); ig.addColorStop(0, '#C9A227'); ig.addColorStop(1, '#FFD700'); ctx.fillStyle = ig; ctx.fillRect(W / 2 - R, cy - R, 2 * R, 2 * R); ctx.fillStyle = '#1a1206'; ctx.font = '800 84px "Noto Sans Ethiopic",sans-serif'; ctx.textAlign = 'center'; ctx.fillText(String(a.name || '?').trim().charAt(0).toUpperCase(), W / 2, cy + 30); }
    ctx.restore(); ctx.beginPath(); ctx.arc(W / 2, cy, R, 0, 7); ctx.strokeStyle = '#D4AF37'; ctx.lineWidth = 6; ctx.stroke();
    if (a.age_verified) { ctx.beginPath(); ctx.arc(W / 2 + 78, cy + 78, 30, 0, 7); ctx.fillStyle = '#2e9e6a'; ctx.fill(); ctx.lineWidth = 4; ctx.strokeStyle = '#0a0703'; ctx.stroke(); ctx.beginPath(); ctx.moveTo(W / 2 + 66, cy + 79); ctx.lineTo(W / 2 + 76, cy + 89); ctx.lineTo(W / 2 + 92, cy + 68); ctx.strokeStyle = '#fff'; ctx.lineWidth = 7; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.stroke(); }
    ctx.fillStyle = '#F5E6A3'; ctx.textAlign = 'center'; fit(ctx, a.name, 600, 52, 700); ctx.fillText(a.name, W / 2, 470);
    ctx.fillStyle = 'rgba(245,230,163,.8)'; fit(ctx, a.venue, 600, 32, 400); ctx.fillText(a.venue, W / 2, 516);
    ctx.fillStyle = '#D4AF37'; ctx.font = '700 26px "Noto Sans Ethiopic",sans-serif'; ctx.fillText(t('tier.' + a.tier), W / 2, 566);
    qrOn(ctx, W / 2 - 165, 596, 330, 18);
    ctx.fillStyle = '#D4AF37'; ctx.font = '800 62px "Noto Sans Ethiopic",sans-serif'; spaced(ctx, a.code, W / 2, 1000, 14);
    ctx.fillStyle = 'rgba(245,230,163,.7)'; ctx.font = '400 22px "Noto Sans Ethiopic",sans-serif'; ctx.textAlign = 'center'; ctx.fillText('ethelmarket.com', W / 2, 1044);
  };
  var go2 = function () { var lg = new Image(); lg.onload = function () { logo = lg; go3(); }; lg.onerror = go3; lg.src = 'logo-320.webp'; };
  var go3 = function () { var p = ls(K.photo); if (!p) return paint(null); var im = new Image(); im.onload = function () { paint(im); }; im.onerror = function () { paint(null); }; im.src = p; };
  (document.fonts && document.fonts.load ? Promise.all([document.fonts.load('700 30px "Noto Sans Ethiopic"'), document.fonts.load('400 22px "Noto Sans Ethiopic"')]).catch(function () {}) : Promise.resolve()).then(go2);
}
function saveBlob(blob, name) {
  var file = null; try { file = new File([blob], name, { type: blob.type }); } catch (e) { /* old browser */ }
  if (file && navigator.canShare && navigator.canShare({ files: [file] }) && /Android|iPhone/i.test(navigator.userAgent) && navigator.share) { navigator.share({ files: [file] }).catch(function () {}); return; }
  var u = URL.createObjectURL(blob), a = document.createElement('a'); a.href = u; a.download = name; document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(u); a.remove(); }, 800);
}
function savePhoto(file) {
  var rd = new FileReader();
  rd.onload = function () { var im = new Image(); im.onload = function () { var c = document.createElement('canvas'); c.width = c.height = 240; var x = c.getContext('2d'), s = Math.min(im.width, im.height); x.drawImage(im, (im.width - s) / 2, (im.height - s) / 2, s, s, 0, 0, 240, 240); ls(K.photo, c.toDataURL('image/jpeg', 0.72)); drawBadge($('bc')); toast(t('me.saved')); }; im.src = rd.result; };
  rd.readAsDataURL(file);
}

/* ---------- messages sheet ---------- */
function noteText(n) {
  var p = n.params || {}, v = { amount: fmt(p.amount), rider: p.rider || '', tier: p.tier ? t('tier.' + p.tier) : '', bonus: fmt(p.bonus), reason: p.reason ? (t('why.' + p.reason) === 'why.' + p.reason ? t('why.default') : t('why.' + p.reason)) : '' };
  var s = t('n.' + n.kind, v); return s === 'n.' + n.kind ? n.kind : s;
}
function openSheet() {
  var ov = document.createElement('div'); ov.className = 'ov noprint'; ov.id = 'ov'; ov.innerHTML = '<div class="sheet" role="dialog" aria-modal="true" aria-label="' + esc(t('home.messages')) + '"><div class="row"><h2 style="margin:0">' + esc(t('home.messages')) + '</h2><button class="ib" data-act="closesheet" aria-label="' + esc(t('c.close')) + '">' + ic('x') + '</button></div><div id="nl">' + skel(3) + '</div></div>';
  document.body.appendChild(ov); var x = ov.querySelector('button'); if (x) x.focus();
  api('fa_agent_notifications').then(function (r) {
    var L = r.ok ? r.data.notifications : [], box = $('nl'); if (!box) return;
    box.innerHTML = !L.length ? emptyBox('bell', t('home.no_messages')) : '<div>' + L.map(function (n) { return '<div class="li"><div class="m"><b>' + esc(noteText(n)) + '</b><small>' + esc(when(n.created_at)) + '</small></div>' + (n.read_at ? '' : '<span class="chip gold">&bull;</span>') + '</div>'; }).join('') + '</div>';
    if (r.ok) api('fa_agent_notifications_read').then(function () { if (S.me) { S.me.unread = 0; ls(K.me, JSON.stringify(S.me)); } var d = document.querySelector('.top .dot'); if (d) d.remove(); });
  });
}
function closeSheet() { var o = $('ov'); if (o) o.remove(); }

/* ---------- events ---------- */
document.addEventListener('click', function (e) {
  var b = e.target.closest && e.target.closest('[data-act]'); if (!b) { if (e.target.id === 'ov') closeSheet(); return; }
  var act = b.getAttribute('data-act'), a = S.me && S.me.agent;
  if (act === 'lang') setLang(S.lang === 'am' ? 'en' : 'am');
  else if (act === 'theme') { applyTheme(themeNow() === 'dark' ? 'light' : 'dark', true); render(); }
  else if (act === 'eye') { var pw = document.querySelector('#lf [name=pw]'); if (pw) { var show = pw.type === 'password'; pw.type = show ? 'text' : 'password'; b.setAttribute('aria-pressed', String(show)); b.setAttribute('aria-label', t(show ? 'login.hide' : 'login.show')); } }
  else if (act === 'retry') { S.data = {}; S.err = ''; if (S.view === 'fail' || S.view === 'wait') { S.view = 'boot'; render(); } loadMe(); }
  else if (act === 'signout') signOut();
  else if (act === 'bell') openSheet(); else if (act === 'closesheet') closeSheet();
  else if (act.indexOf('bk-') === 0 && window.ETELBook) window.ETELBook.act(act, b);
  else if (act === 'copy') { var done = function () { toast(t('c.copied')); }; if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(a.code).then(done, done); else { var ta = document.createElement('textarea'); ta.value = a.code; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch (x) { /* manual */ } ta.remove(); done(); } }
  else if (act === 'share') { var text = t('code.share_text', { code: a.code, link: rideLink() }); if (navigator.share) navigator.share({ text: text }).catch(function () {}); else if (navigator.clipboard) navigator.clipboard.writeText(text).then(function () { toast(t('c.copied')); }); }
  else if (act === 'saveqr') { var c = document.createElement('canvas'); c.width = c.height = 800; var x2 = c.getContext('2d'); qrOn(x2, 0, 0, 800, 48); c.toBlob(function (bl) { saveBlob(bl, 'etel-qr-' + a.code + '.png'); }, 'image/png'); }
  else if (act === 'savebadge') $('bc').toBlob(function (bl) { saveBlob(bl, 'etel-agent-badge-' + a.code + '.png'); }, 'image/png');
  else if (act === 'print') window.print();
  else if (act === 'install' && S.installEv) { S.installEv.prompt(); S.installEv = null; render(); }
});
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSheet(); });
document.addEventListener('change', function (e) { if (e.target.id === 'ph' && e.target.files && e.target.files[0]) savePhoto(e.target.files[0]); });
document.addEventListener('submit', function (e) {
  var f = e.target; e.preventDefault();
  if (f.id === 'lf') {
    var btn = $('lb'); btn.disabled = true; btn.innerHTML = '<span class="spin" aria-hidden="true"></span><b>' + esc(t('login.busy')) + '</b>';
    api('etelid_login', { identifier: f.ident.value.trim(), password: f.pw.value, totp_code: f.totp ? f.totp.value.trim() : '' }).then(function (r) {
      if (r.ok && r.data.token) { S.need2fa = false; S.err = ''; ls(K.tok, r.data.token); ls(K.user, JSON.stringify(r.data.user || {})); S.view = 'boot'; render(); loadMe(); return; }
      if (r.j && r.j.requires_2fa) S.need2fa = true;
      S.err = r.net ? t('c.error') : (r.j.message || t('login.err')); render();
    });
  } else if (f.id === 'af') {
    var venue = f.venue.value.trim(), ph = f.payout.value.replace(/[^0-9+]/g, ''), digits = ph.replace(/\D/g, '');
    if (venue.length < 2) { S.err = t('apply.err_venue'); return render(); }
    if (!/^(251|0)?[79]\d{8}$/.test(digits)) { S.err = t('apply.err_phone'); return render(); }
    var ab = $('ab'); ab.disabled = true; S.err = '';
    api('fa_agent_apply', { venue_name: venue, venue_type: f.type.value, venue_zone: f.zone.value.trim(), payout_phone: ph, device: device() }).then(function (r) {
      if (r.ok && r.data.reason === 'AGE_REQUIREMENT') { S.err = t('apply.age_failed'); S.me = null; return render(); }
      if (!r.ok) { S.err = r.net ? t('c.error') : (r.j.message || t('c.error')); return render(); }
      loadMe();
    });
  } else if (f.id === 'pf') {
    var d2 = f.payout.value.replace(/\D/g, ''); if (!/^(251|0)?[79]\d{8}$/.test(d2)) return toast(t('apply.err_phone'));
    api('fa_agent_update_profile', { payout_phone: f.payout.value.replace(/[^0-9+]/g, '') }).then(function (r) { if (r.ok) { toast(t('me.saved')); loadMe(); } else toast(t('c.error')); });
  }
});
window.addEventListener('hashchange', function () { if (!S.me || !S.me.is_agent || S.me.agent.status !== 'approved') return; var h = location.hash.replace('#/', ''); if (ROUTES.indexOf(h) >= 0 && h !== S.view) { S.view = h; render(); loadView(); } });
window.addEventListener('online', function () { if (S.offline && ls(K.tok)) { S.data = {}; loadMe(); } });
window.addEventListener('offline', function () { S.offline = true; if (S.me) render(); });
window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); S.installEv = e; });
if ('serviceWorker' in navigator) window.addEventListener('load', function () { navigator.serviceWorker.register('sw.js').catch(function () { /* works without it */ }); });

window.ETELAP = { API: API, api: api, t: t, esc: esc, ic: ic, S: S, shell: shell, toast: toast, fmt: fmt, render: render, when: when, closeSheet: closeSheet };
loadLang().then(function () { if (!ls(K.tok)) { S.view = 'login'; render(); } else loadMe(); }, function () { document.body.innerHTML = '<p style="padding:24px;color:#F5E6A3">Could not load. Please try again.</p>'; });
})();

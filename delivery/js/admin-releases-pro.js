/* ETEL Admin - Releases (canary rollout).
   One card per app showing, side by side, the build everyone runs (stable) and the build only testers run (canary), with a
   plain status line ("Everyone is on v3" / "Testers are trying v4"). Each step asks first in words people can follow:
   set a version, promote the canary to everyone, roll back, clear the canary. Testers are chips you can remove and a search
   that adds more. A four step guide at the top says how a release goes from upload to everyone.
   Data: admin_list_release_channels / admin_set_release_channel / admin_promote_canary_to_stable / admin_rollback_stable /
   admin_search_canary_candidates / admin_add_canary_tester / admin_remove_canary_tester (owner only).
   Standalone: replaces loadReleasesPanel() so admin-releases.js is not edited. Every server value goes through AdminKit.esc. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;
var V = { channels: [], loaded: false, busy: false, results: {}, open: {} };
var STEPS = [['1', 'Upload', 'Put the new files in a version folder with the Updater.'], ['2', 'Canary', 'Point the canary at it. Only testers get it.'], ['3', 'Try it', 'Testers use it for real and tell you what breaks.'], ['4', 'Promote', 'Happy? Move it to everyone in one tap.']];
var ICON = { driver: '🚖', ride: '🛺', courier: '📦', service: '🏪', admin: '🛡️', customer: '🛍️', delivery: '🛍️' };

function root() { return document.getElementById('releases-root'); }
function appIcon(n) { return ICON[String(n || '').toLowerCase()] || '📱'; }
function label(n) { return String(n || '').replace(/[-_]/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }); }
function ids(app) { return String(app).replace(/[^a-z0-9_-]/gi, ''); }

function load(quiet) {
  var el = root(); if (!el || V.busy) return Promise.resolve();
  K.css(); V.busy = true;
  if (!quiet && !V.loaded) el.innerHTML = '<div class="ak">' + K.skeleton(2) + '</div>';
  return K.api('admin_list_release_channels', {}).then(function (res) {
    V.busy = false;
    if (!K.okRes(res)) { if (!V.loaded) el.innerHTML = '<div class="ak">' + K.empty('⚠️', 'Could not load releases', K.failText(res)) + '</div>'; else K.toast(K.failText(res), true); return; }
    V.channels = K.data(res).channels || []; V.loaded = true; paint();
  });
}
function reload() { V.busy = false; return load(true); }

function paint() {
  var el = root(); if (!el) return;
  var canaries = 0, testers = 0;
  V.channels.forEach(function (c) { if (c.canary_version) canaries++; testers += (c.canary_testers || []).length; });
  var keep = document.activeElement && document.activeElement.id, val = keep && document.activeElement.value;
  var h = '<div class="ak">' + K.hero('Releases', 'Roll a new version out safely: a few testers first, then everyone. Nothing here uploads files; it only chooses which uploaded version each group gets.', [
    ['Apps', K.num(V.channels.length)], ['Canaries running', K.num(canaries)], ['Testers', K.num(testers)]]);
  h += '<div class="ak-grid" style="margin-bottom:14px">' + STEPS.map(function (s) {
    return '<div class="ak-card" style="cursor:default;padding:12px"><div class="ak-top"><div class="ak-av" style="width:34px;height:34px;border-radius:11px;font-size:14px">' + esc(s[0]) + '</div><div class="ak-nm"><b>' + esc(s[1]) + '</b></div></div><div style="font-size:12px;color:var(--mute);margin-top:8px">' + esc(s[2]) + '</div></div>';
  }).join('') + '</div>';
  h += V.channels.length ? '<div class="ak-grid" style="grid-template-columns:repeat(auto-fill,minmax(min(100%,420px),1fr))">' + V.channels.map(card).join('') + '</div>' : K.empty('📱', 'No apps found', 'Release channels appear here once the apps are registered.');
  el.innerHTML = h + '</div>';
  if (keep) { var i = document.getElementById(keep); if (i) { i.focus(); if (val != null && i.value !== val) i.value = val; } }
}

function card(ch) {
  var a = ids(ch.app_name), testers = ch.canary_testers || [], can = !!ch.canary_version;
  var status = can ? K.pill('Testers are trying ' + ch.canary_version, 'blue') : K.pill('Everyone is on ' + ch.stable_version, 'green');
  var h = '<div class="ak-card" style="cursor:default"><div class="ak-top"><div class="ak-av" style="--a:#E8C14A;--b:#9a7a1c;font-size:21px">' + appIcon(ch.app_name) + '</div>' +
    '<div class="ak-nm"><b>' + esc(label(ch.app_name)) + '</b><small>' + (ch.previous_stable_version ? 'Before this: ' + esc(ch.previous_stable_version) : 'No earlier version saved') + '</small></div>' + status + '</div>';
  h += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px">' +
    '<div class="ak-prev" style="margin:0"><b>Stable · everyone</b><span style="font-size:22px;font-weight:800">' + esc(ch.stable_version) + '</span></div>' +
    '<div class="ak-prev" style="margin:0;' + (can ? 'border-color:var(--blue)' : '') + '"><b>Canary · testers only</b><span style="font-size:22px;font-weight:800">' + (can ? esc(ch.canary_version) : '— none —') + '</span></div></div>';
  h += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px">' + verBox(a, 'stable', 'New stable version') + verBox(a, 'canary', 'New canary version') + '</div>';
  h += '<div class="ak-acts" style="margin-top:12px">';
  if (can) h += '<button type="button" class="ak-btn gold" data-promote="' + esc(ch.app_name) + '" data-ver="' + esc(ch.canary_version) + '">🚀 Promote ' + esc(ch.canary_version) + ' to everyone</button><button type="button" class="ak-btn" data-clear="' + esc(ch.app_name) + '">Clear canary</button>';
  if (ch.previous_stable_version) h += '<button type="button" class="ak-btn danger" data-rollback="' + esc(ch.app_name) + '" data-ver="' + esc(ch.previous_stable_version) + '">↩ Roll back to ' + esc(ch.previous_stable_version) + '</button>';
  h += '</div>';
  h += '<div class="ak-sec"><h4>Testers (' + testers.length + ')</h4>';
  h += testers.length ? '<div class="ak-chips" style="margin:0 0 8px">' + testers.map(function (t) {
    return '<span class="ak-chip">' + esc(t.name || t.phone) + ' <button type="button" data-untest="' + esc(ch.app_name) + '" data-uid="' + esc(t.user_id) + '" data-name="' + esc(t.name || t.phone) + '" aria-label="Remove ' + esc(t.name || t.phone) + '" style="border:0;background:none;color:var(--red);cursor:pointer;font-size:14px;padding:0 0 0 4px">×</button></span>';
  }).join('') + '</div>' : '<div style="font-size:12.5px;color:var(--mute);margin-bottom:8px">No testers yet. Add a few trusted people before setting a canary.</div>';
  h += '<label class="ak-search"><u>🔎</u><input id="rel-q-' + a + '" data-tsearch="' + esc(ch.app_name) + '" type="search" placeholder="Search name or phone to add a tester…" autocomplete="off"></label>';
  h += '<div id="rel-res-' + a + '">' + results(ch.app_name) + '</div></div></div>';
  return h;
}
function verBox(a, kind, ph) {
  return '<div style="display:flex;gap:6px"><input id="rel-' + kind + '-' + a + '" class="ak-in" style="flex:1;min-width:0" placeholder="' + esc(ph) + '" aria-label="' + esc(ph) + '" autocomplete="off" autocapitalize="off">' +
    '<button type="button" class="ak-btn" data-setver="' + kind + '" data-app="' + esc(a) + '">Set</button></div>';
}
function results(app) {
  var r = V.results[app]; if (!r) return '';
  if (!r.length) return '<div style="font-size:12.5px;color:var(--mute);padding:6px 2px">No one matches.</div>';
  return '<div style="display:grid;gap:6px;margin-top:6px">' + r.map(function (u) {
    return '<button type="button" class="ak-btn" style="text-align:left;display:flex;justify-content:space-between;gap:8px" data-addtest="' + esc(app) + '" data-uid="' + esc(u.id) + '" data-name="' + esc(u.name) + '"><span>' + esc(u.name) + ' <small style="color:var(--mute)">(' + esc(u.role) + ')</small></span><span style="color:var(--mute)">' + esc(u.phone) + '</span></button>';
  }).join('') + '</div>';
}

function call(action, body, done) {
  return K.api(action, body).then(function (res) { if (K.okRes(res)) { K.toast(done); return reload(); } K.toast(K.failText(res), true); });
}
function setVersion(kind, app) {
  var input = document.getElementById('rel-' + kind + '-' + ids(app));
  var v = input ? input.value.trim().toLowerCase() : '';
  if (!v) { K.toast('Type the version name first, for example v3.', true); if (input) input.focus(); return; }
  if (/[\s\/\\]/.test(v)) { K.toast('A version name has no spaces or slashes.', true); if (input) input.focus(); return; }
  var words = kind === 'stable' ? 'Everyone will get it the next time they open or refresh the app.' : 'Only the testers listed on this card will get it.';
  K.confirm({ title: 'Set ' + kind + ' for ' + label(app) + ' to “' + v + '”?', text: words + ' Make sure the files are already uploaded to js/releases/' + app + '/' + v + '/ — this only points at them.', label: 'Set ' + kind, danger: kind === 'stable' }).then(function (r) {
    if (r) call('admin_set_release_channel', { app: app, channel: kind, version: v }, kind === 'stable' ? 'Stable version set' : 'Canary version set');
  });
}
var findTesters = K.debounce(function (app, q) {
  if (!q || q.length < 2) { delete V.results[app]; var b = document.getElementById('rel-res-' + ids(app)); if (b) b.innerHTML = ''; return; }
  K.api('admin_search_canary_candidates', { q: q }).then(function (res) {
    V.results[app] = K.okRes(res) ? (K.data(res).users || []) : [];
    var b = document.getElementById('rel-res-' + ids(app)); if (b) b.innerHTML = results(app);
  });
}, 300);

function wire(el) {
  if (el._relWired) return; el._relWired = true;
  el.addEventListener('click', function (e) {
    var t = e.target, b;
    if ((b = t.closest('[data-setver]'))) { var app = b.getAttribute('data-app'); V.channels.forEach(function (c) { if (ids(c.app_name) === app) setVersion(b.getAttribute('data-setver'), c.app_name); }); return; }
    if ((b = t.closest('[data-promote]'))) {
      var pa = b.getAttribute('data-promote'), pv = b.getAttribute('data-ver');
      K.confirm({ title: 'Promote ' + pv + ' to everyone?', text: 'Every user of ' + label(pa) + ' gets ' + pv + ' from their next refresh. Only do this once your testers found no problems.', label: 'Promote to everyone' }).then(function (r) { if (r) call('admin_promote_canary_to_stable', { app: pa }, 'Promoted to everyone'); });
      return;
    }
    if ((b = t.closest('[data-rollback]'))) {
      var ra = b.getAttribute('data-rollback'), rv = b.getAttribute('data-ver');
      K.confirm({ title: 'Roll back ' + label(ra) + ' to ' + rv + '?', text: 'Everyone returns to ' + rv + ' on their next refresh. Nothing is forced mid-session.', label: 'Roll back', danger: true }).then(function (r) { if (r) call('admin_rollback_stable', { app: ra }, 'Rolled back'); });
      return;
    }
    if ((b = t.closest('[data-clear]'))) {
      var ca = b.getAttribute('data-clear');
      K.confirm({ title: 'Switch the canary off for ' + label(ca) + '?', text: 'Testers go back to the stable version until you set a new canary.', label: 'Clear canary', danger: true }).then(function (r) { if (r) call('admin_set_release_channel', { app: ca, channel: 'canary', version: '' }, 'Canary cleared'); });
      return;
    }
    if ((b = t.closest('[data-untest]'))) {
      var ua = b.getAttribute('data-untest'), uid = parseInt(b.getAttribute('data-uid'), 10), nm = b.getAttribute('data-name');
      K.confirm({ title: 'Remove ' + nm + ' as a tester?', text: 'They go back to the stable version.', label: 'Remove', danger: true }).then(function (r) { if (r) call('admin_remove_canary_tester', { app: ua, user_id: uid }, 'Tester removed'); });
      return;
    }
    if ((b = t.closest('[data-addtest]'))) {
      var aa = b.getAttribute('data-addtest'), aid = parseInt(b.getAttribute('data-uid'), 10);
      delete V.results[aa];
      call('admin_add_canary_tester', { app: aa, user_id: aid }, b.getAttribute('data-name') + ' added as a tester');
    }
  });
  el.addEventListener('input', function (e) { var a = e.target.getAttribute && e.target.getAttribute('data-tsearch'); if (a) findTesters(a, e.target.value.trim()); });
  el.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.id && /^rel-(stable|canary)-/.test(e.target.id)) { e.preventDefault(); var btn = e.target.parentNode.querySelector('[data-setver]'); if (btn) btn.click(); }
  });
}

function install() {
  var el = root(); if (!el) return;
  wire(el);
  window.loadReleasesPanel = function () { return load(V.loaded); };
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();

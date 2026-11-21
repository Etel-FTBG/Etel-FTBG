/* ETEL Admin - "ETEL app" > Send a message. The owner sends a notification to everyone, to one group (customers, drivers, couriers, service givers) or to one
   person (by phone number), and sees the history with how many people it reached.
   Data: admin_broadcast_preview / admin_broadcast_send / admin_broadcast_list (api/controllers/BroadcastController.php). Owner admins only.
   Needs js/admin-etel-app.js. Plain text only. It reaches people who allowed notifications on the phone app or in the browser. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K || window.ETELHubSend) return;
var esc = K.esc;
var B = { list: null, err: '', f: { audience: 'customer', phone: '', title: '', body: '' }, busy: false, tries: 0, timer: null };
var AUD = [['customer', 'Customers'], ['driver', 'Drivers'], ['courier', 'Couriers'], ['service_giver', 'Service givers'], ['all', 'Everyone'], ['user', 'One person']];
function audName(a, who) { if (a === 'user') return who ? who : 'One person'; var m = AUD.filter(function (x) { return x[0] === a; })[0]; return m ? m[1] : a; }
function run(a, b) { return K.api(a, b || {}); }
function ok(r) { return K.okRes(r); }
function dat(r) { return K.data(r); }
function repaint() { if (window.ETELHubApps) window.ETELHubApps.repaint(); }
function css() {
  if (document.getElementById('hb-css')) return;
  var s = document.createElement('style'); s.id = 'hb-css';
  s.textContent = [
    '.hb-card{background:var(--card);border:1.5px solid var(--line);border-radius:18px;padding:14px;margin-bottom:12px;display:flex;flex-direction:column;gap:8px;box-shadow:var(--shadow);animation:none}',
    '.hb-card h3{margin:0;font-size:16px}.hb-note{font-size:12.5px;color:var(--mute);margin:0}.hb-lbl{font-size:12px;color:var(--mute);display:block;margin-bottom:2px}',
    '.hb-two{display:grid;grid-template-columns:1fr 1fr;gap:8px}@media(max-width:560px){.hb-two{grid-template-columns:1fr}}',
    '.hb-row{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap}.hb-ta{width:100%;min-height:90px;font:inherit;font-size:14px;padding:10px;border-radius:12px;border:1px solid var(--line);background:var(--soft);color:inherit;resize:vertical}',
    '.hb-sec{margin:20px 0 8px;font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--mute)}',
    '.hb-prev{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:18px;background:#1c1c24;color:#fff;box-shadow:0 6px 18px rgba(0,0,0,.25);max-width:420px}.hb-ico{flex:none;width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#F2CC6B,#C9A24B);color:#1a1a1a;font-style:normal;font-weight:800;display:flex;align-items:center;justify-content:center}.hb-pt{min-width:0}.hb-pt em{display:block;font-style:normal;font-size:11px;opacity:.65;margin-bottom:2px}.hb-pt b{display:block;font-size:14px}.hb-pt span{display:block;font-size:13px;opacity:.85;word-break:break-word}.hb-cap{font-size:12px;color:var(--mute);margin:2px 0 0}',
    '.hb-h{border-top:1px solid var(--line);padding-top:10px}.hb-h b{font-size:14px}'
  ].join('');
  document.head.appendChild(s);
}
function histCard(h) {
  var done = h.status === 'sent', lost = h.status === 'unfinished';
  return '<div class="hb-h"><div class="hb-row"><b>' + esc(h.title) + '</b><span class="hb-note">' + esc(String(h.created_at).substring(0, 16)) + '</span></div>' +
    '<p class="hb-note">' + esc(h.body) + '</p>' +
    '<p class="hb-note">To: ' + esc(audName(h.audience, h.target_name)) + ' - ' + (done ? 'reached ' + h.reached + ' of ' + h.recipients + (h.failed ? ' (' + h.failed + ' could not be reached)' : '') : lost ? 'did not finish: the server stopped before it wrote the result (' + h.recipients + ' people)' : 'sending... (' + h.recipients + ' ' + (h.recipients === 1 ? 'person' : 'people') + ')') + '</p></div>';
}
function view() {
  css();
  if (B.err) return K.empty('!', B.err, '');
  if (!B.list) return K.skeleton(3);
  var f = B.f;
  var sent = B.list.length, reached = B.list.reduce(function (a, h) { return a + (h.reached || 0); }, 0);
  return '<div class="ak-hero"><div class="ak-hero-row"><div><h2>Send a message</h2><p>A push notification to people who allowed notifications.</p></div></div><div class="ak-hero-big"><div><b>' + esc(sent) + '</b><span>Messages sent</span></div><div><b>' + esc(reached) + '</b><span>People reached</span></div></div></div>' +
    '<div class="hb-card" data-hb-form><h3><span aria-hidden="true">✉️</span>New message</h3>' +
    '<p class="hb-note">It reaches people who allowed notifications (on the ETEL phone app or in the browser). Nobody gets it in an inbox: if they have notifications off, they do not see it.</p>' +
    '<div class="hb-two"><div><label class="hb-lbl">Who gets it</label><select class="ak-in" data-hb-f="audience">' + AUD.map(function (a) { return '<option value="' + a[0] + '"' + (f.audience === a[0] ? ' selected' : '') + '>' + esc(a[1]) + '</option>'; }).join('') + '</select></div>' +
    (f.audience === 'user' ? '<div><label class="hb-lbl">Phone number of the person</label><input class="ak-in" data-hb-f="phone" maxlength="20" value="' + esc(f.phone) + '" placeholder="+251911234567"></div>' : '<div></div>') + '</div>' +
    '<div><label class="hb-lbl">Title (<span data-hb-count="title">' + f.title.length + '</span>/60)</label><input class="ak-in" data-hb-f="title" maxlength="60" value="' + esc(f.title) + '" placeholder="Rain tonight"></div>' +
    '<div><label class="hb-lbl">Message (<span data-hb-count="body">' + f.body.length + '</span>/240)</label><textarea class="hb-ta" data-hb-f="body" maxlength="240">' + esc(f.body) + '</textarea></div>' +
    '<p class="hb-cap">What it looks like on a phone:</p><div class="hb-prev" data-hb-prev><i class="hb-ico" aria-hidden="true">E</i><div class="hb-pt"><em>ETEL &middot; now</em><b>' + esc(f.title || 'Title') + '</b><span>' + esc(f.body || 'Your message') + '</span></div></div>' +
    '<div class="hb-row"><span class="hb-note">You will see how many people get it before it is sent.</span><button type="button" class="ak-btn gold" data-hb-send' + (B.busy || !f.title.trim() || !f.body.trim() ? ' disabled' : '') + '>Send</button></div></div>' +
    '<div class="hb-sec hb-row"><span>History</span><button type="button" class="ak-btn" data-hb-refresh>Refresh</button></div><div class="hb-card">' + (B.list.length ? B.list.map(histCard).join('') : '<p class="hb-note">Nothing sent yet.</p>') + '</div>';
}
function load() {
  return run('admin_broadcast_list', {}).then(function (r) {
    if (!ok(r)) { B.err = K.failText(r, 'Could not load. Run the database update (migration 114).'); B.list = null; }
    else { B.err = ''; B.list = dat(r).broadcasts || []; }
    repaint();
    // A message that is still being sent finishes a few seconds later: look again by itself (up to about a minute).
    clearTimeout(B.timer);
    var pending = (B.list || []).some(function (h) { return h.status === 'sending'; });
    if (pending && B.tries < 15) { B.tries++; B.timer = setTimeout(function () { if (document.querySelector('[data-hb-form]')) load(); }, 4000); } else if (!pending) B.tries = 0;
  });
}
function onInput(e) {
  var el = e.target.closest('[data-hb-f]'); if (!el) return;
  var k = el.getAttribute('data-hb-f'); B.f[k] = el.value;
  if (k === 'audience') { repaint(); return; }      // the phone box appears or goes away
  var form = el.closest('[data-hb-form]');          // typing never rebuilds the boxes
  var c = form.querySelector('[data-hb-count="' + k + '"]'); if (c) c.textContent = String(el.value.length);
  var pv = form.querySelector('[data-hb-prev]'); if (pv) { pv.querySelector('b').textContent = B.f.title || 'Title'; pv.querySelector('span').textContent = B.f.body || 'Your message'; }
  var sv = form.querySelector('[data-hb-send]'); if (sv) sv.disabled = B.busy || !B.f.title.trim() || !B.f.body.trim();
}
function send(btn) {
  var f = B.f; btn.disabled = true; B.busy = true;
  var fail = function (r, d) { B.busy = false; btn.disabled = false; K.toast(K.failText(r, d), true); };
  run('admin_broadcast_preview', { audience: f.audience, phone: f.phone }).then(function (r) {
    if (!ok(r)) return fail(r, 'Could not check who gets it.');
    var d = dat(r), who = f.audience === 'user' ? (d.name || 'this person') : (d.reachable + ' people');
    var more = f.audience !== 'user' && d.people > d.reachable ? ' (' + (d.people - d.reachable) + ' more have notifications off and will not get it)' : '';
    return K.confirm({ title: 'Send to ' + who + '?', text: '"' + f.title + '" - ' + f.body + more + ' It cannot be taken back once sent.', label: 'Send now' }).then(function (yes) {
      if (!yes) { B.busy = false; btn.disabled = false; return; }
      return run('admin_broadcast_send', { audience: f.audience, phone: f.phone, title: f.title, body: f.body }).then(function (r2) {
        if (!ok(r2)) return fail(r2, 'Could not send.');
        B.busy = false; B.tries = 0; B.f.title = ''; B.f.body = ''; K.toast('Sending to ' + dat(r2).recipients + ' ' + (dat(r2).recipients === 1 ? 'person' : 'people')); return load();
      });
    });
  });
}
function onClick(e) {
  if (e.target.closest('[data-hb-refresh]')) { B.tries = 0; load(); return; }
  var s = e.target.closest('[data-hb-send]'); if (s && !s.disabled) send(s);
}
function open() {
  var el = document.getElementById('panel-etelapp'); if (!el) return;
  if (!el._hb) { el._hb = true; el.addEventListener('click', onClick); el.addEventListener('input', onInput); el.addEventListener('change', onInput); }
  return load();
}
window.ETELHubSend = { view: view, open: open };
})();

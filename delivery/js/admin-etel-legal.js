/* ETEL Admin - "ETEL app" > Privacy and accounts. The owner (1) writes the Privacy policy and the Terms text that the public pages and the phone app show,
   and (2) answers "Delete my account" requests: Delete (the account can no longer sign in, name, phone and email are replaced, saved lists are removed)
   or Reject. Data: admin_legal_get / admin_legal_save / admin_deletion_list / admin_deletion_decide (api/controllers/LegalController.php). Owner admins only.
   Needs js/admin-etel-app.js. Text is plain text: a blank line starts a paragraph, a line starting with "# " is a heading. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K || window.ETELHubLegal) return;
var esc = K.esc;
var G = { docs: null, reqs: null, err: '', draft: {} };
function run(a, b) { return K.api(a, b || {}); }
function ok(r) { return K.okRes(r); }
function dat(r) { return K.data(r); }
function repaint() { if (window.ETELHubApps) window.ETELHubApps.repaint(); }
var DOCS = [['privacy', 'Privacy policy', 'privacy.html'], ['terms', 'Terms of service', 'terms.html']];

function css() {
  if (document.getElementById('hg-css')) return;
  var s = document.createElement('style'); s.id = 'hg-css';
  s.textContent = [
    '.hg-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px;margin-bottom:12px;display:flex;flex-direction:column;gap:8px}',
    '.hg-card h3{margin:0;font-size:16px}.hg-note{font-size:12.5px;color:var(--mute);margin:0}.hg-lbl{font-size:12px;color:var(--mute);display:block;margin-bottom:2px}',
    '.hg-ta{width:100%;min-height:260px;font:inherit;font-size:13px;line-height:1.5;padding:10px;border-radius:12px;border:1px solid var(--line);background:var(--soft);color:inherit;resize:vertical}',
    '.hg-row{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap}.hg-sec{margin:20px 0 8px;font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--mute)}',
    '.hg-pill{display:inline-block;font-size:11px;font-weight:700;padding:1px 8px;border-radius:99px;border:1px solid var(--line)}.hg-pill.open{border-color:#B8962E;color:#7A5A00}.hg-pill.done{border-color:#2f855a;color:#2f855a}',
    '.hg-req{border-top:1px solid var(--line);padding-top:10px}.hg-btns{display:flex;gap:8px;flex-wrap:wrap}'
  ].join('');
  document.head.appendChild(s);
}
function cur(k, f) { var d = G.draft[k]; if (d && d[f] !== undefined) return d[f]; var b = G.docs && G.docs[k]; return (b && b[f]) || ''; }
function changed(k) { var b = (G.docs && G.docs[k]) || { title: '', body: '' }; return cur(k, 'title') !== (b.title || '') || cur(k, 'body') !== (b.body || ''); }

function docCard(d) {
  var k = d[0], b = (G.docs && G.docs[k]) || {};
  return '<div class="hg-card" data-hg-doc="' + k + '"><div class="hg-row"><h3>' + esc(d[1]) + '</h3><span class="hg-note">' + (b.body ? 'Your text is live. Last saved ' + esc(String(b.updated_at || '').substring(0, 10)) : 'The built-in text is live.') + '</span></div>' +
    '<label class="hg-lbl">Title (shown at the top)</label><input class="ak-in" data-hg-f="title" maxlength="80" value="' + esc(cur(k, 'title')) + '" placeholder="' + esc(d[1]) + '">' +
    '<label class="hg-lbl">Text. A blank line starts a new paragraph; a line that starts with "# " is a heading. Leave empty to use the built-in text.</label>' +
    '<textarea class="hg-ta" data-hg-f="body" maxlength="60000" spellcheck="true">' + esc(cur(k, 'body')) + '</textarea>' +
    '<div class="hg-row"><a class="hg-note" href="' + d[2] + '" target="_blank" rel="noopener">Open the public page</a><button type="button" class="ak-btn gold" data-hg-save' + (changed(k) ? '' : ' disabled') + '>Save</button></div></div>';
}
function reqCard(q) {
  var open = q.status === 'open', who = (q.name || 'Unknown') + (q.phone ? ' - ' + q.phone : '') + (q.role ? ' (' + q.role + ')' : '');
  return '<div class="hg-req" data-hg-req="' + q.message_id + '"><div class="hg-row"><b>' + esc(who) + '</b><span class="hg-pill ' + (open ? 'open' : 'done') + '">' + esc(open ? 'Waiting' : q.status === 'done' ? 'Deleted' : 'Rejected') + '</span></div>' +
    '<p class="hg-note">Asked ' + esc(String(q.asked_at).substring(0, 16)) + (q.handled_at ? ' - answered ' + esc(String(q.handled_at).substring(0, 16)) : '') + (q.note ? ' - note: ' + esc(q.note) : '') + '</p>' +
    (open ? (q.is_admin ? '<p class="hg-note">This is an admin or staff account. Remove the staff access first; it cannot be deleted here.</p>' :
      '<input class="ak-in" data-hg-note maxlength="200" placeholder="Note (optional)"><div class="hg-btns"><button type="button" class="ak-btn" data-hg-reject>Reject</button><button type="button" class="ak-btn gold" data-hg-delete>Delete the account</button></div>') : '') + '</div>';
}
function view() {
  css();
  if (G.err) return K.empty('!', G.err, '');
  if (!G.docs || !G.reqs) return K.skeleton(3);
  var waiting = G.reqs.filter(function (q) { return q.status === 'open'; }).length;
  return '<p class="hg-note">The Privacy policy and Terms are shown on the public pages and linked in the phone app (Profile). Google Play needs both, and an account deletion page.</p>' +
    '<div class="hg-sec">Privacy policy and Terms</div>' + DOCS.map(docCard).join('') +
    '<div class="hg-sec">Delete my account requests' + (waiting ? ' (' + waiting + ' waiting)' : '') + '</div>' +
    '<div class="hg-card"><p class="hg-note">People ask from the app or the web. First confirm it is really them (call the phone number). <b>Delete</b> signs them out for good, replaces the name, phone and email and removes saved addresses, favourites and notification subscriptions. Orders, trips and payments stay without the name, as the law and disputes require. Check there is no open trip, order or wallet balance first.</p>' +
    (G.reqs.length ? G.reqs.map(reqCard).join('') : '<p class="hg-note">No requests.</p>') + '</div>';
}
function load() {
  return Promise.all([run('admin_legal_get', {}), run('admin_deletion_list', {})]).then(function (rs) {
    if (!ok(rs[0])) { G.err = K.failText(rs[0], 'Could not load. Run the database update (migration 113).'); G.docs = null; }
    else if (!ok(rs[1])) { G.err = K.failText(rs[1], 'Could not load the requests. Run the database update (migration 113).'); G.reqs = null; }
    else { G.err = ''; G.docs = dat(rs[0]).docs || {}; G.reqs = dat(rs[1]).requests || []; G.draft = {}; }
    repaint();
  });
}
function onInput(e) {
  var f = e.target.closest('[data-hg-f]'); if (!f) return;
  var card = f.closest('[data-hg-doc]'), k = card.getAttribute('data-hg-doc');
  (G.draft[k] = G.draft[k] || {})[f.getAttribute('data-hg-f')] = f.value;
  var sv = card.querySelector('[data-hg-save]'); if (sv) sv.disabled = !changed(k);   // typing never rebuilds the box
}
function decide(row, decision, btn) {
  var id = parseInt(row.getAttribute('data-hg-req'), 10), noteEl = row.querySelector('[data-hg-note]'), note = noteEl ? noteEl.value : '';
  var go = function () { btn.disabled = true; run('admin_deletion_decide', { message_id: id, decision: decision, note: note }).then(function (r) {
    if (!ok(r)) { btn.disabled = false; K.toast(K.failText(r, 'Could not save.'), true); return; }
    K.toast(decision === 'delete' ? 'Account deleted' : 'Request rejected'); return load(); }); };
  if (decision === 'delete') K.confirm({ title: 'Delete this account?', text: 'It cannot sign in again and its name, phone and email are removed. This cannot be undone. Did you confirm it is really the owner, and that there is no open trip or order?', label: 'Delete the account' }).then(function (res) { if (res) go(); });
  else go();
}
function onClick(e) {
  var sv = e.target.closest('[data-hg-save]');
  if (sv && !sv.disabled) {
    var k = sv.closest('[data-hg-doc]').getAttribute('data-hg-doc'); sv.disabled = true;
    run('admin_legal_save', { doc: k, title: cur(k, 'title'), body: cur(k, 'body') }).then(function (r) {
      if (!ok(r)) { sv.disabled = false; K.toast(K.failText(r, 'Could not save.'), true); return; }
      K.toast('Saved'); return load(); });
    return;
  }
  var row = e.target.closest('[data-hg-req]'); if (!row) return;
  var d = e.target.closest('[data-hg-delete]'), r = e.target.closest('[data-hg-reject]');
  if (d && !d.disabled) decide(row, 'delete', d); else if (r && !r.disabled) decide(row, 'reject', r);
}
function open() {
  var el = document.getElementById('panel-etelapp'); if (!el) return;
  if (!el._hg) { el._hg = true; el.addEventListener('click', onClick); el.addEventListener('input', onInput); }
  return load();
}
window.ETELHubLegal = { view: view, open: open };
})();

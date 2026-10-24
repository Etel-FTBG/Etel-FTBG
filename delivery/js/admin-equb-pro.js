/* ETEL Admin - Equb (rotating savings).
   Three views in one place: Groups (a board with the money in play, who is paid up and what needs attention), Packages
   (the tiers people pick from, with a guided form and a live preview) and People (who may use Equb). A group opens in a
   side panel: members and this round's payments, the payout schedule, add a member, start the group, or process the round.
   Data: equb_admin_list_groups / equb_admin_group_detail / equb_admin_list_packages / equb_admin_update_package /
   equb_admin_create_package / equb_create_group / equb_start_group / equb_admin_add_member / equb_admin_force_process_round /
   equb_admin_list_participants / equb_admin_grant_access / equb_admin_revoke_access (EqubController.php).
   Standalone: replaces loadEqubAdminPanel() so admin-equb.js is not edited. Every server value goes through AdminKit.esc. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;
var V = { tab: 'groups', filter: '', q: '', groups: [], packages: [], people: [], pkgOk: true, ppOk: true, loaded: false, busy: false, openId: 0 };

var FREQ = { weekly: ['Weekly', 'week', 1], biweekly: ['Every 2 weeks', '2 weeks', 2], monthly: ['Monthly', 'month', 4] };
var TIER = {
  bronze: { n: 'Bronze', e: '🥉', a: '#CD7F32', b: '#8B5A2B' }, silver: { n: 'Silver', e: '🥈', a: '#C9CED6', b: '#8E96A3' },
  gold: { n: 'Gold', e: '🥇', a: '#F2CC6B', b: '#C8960C' }, platinum: { n: 'Platinum', e: '💎', a: '#7DC8E8', b: '#3E7C99' }
};
var STATE = { forming: ['Forming', 'blue'], active: ['Running', 'green'], at_risk: ['Needs attention', 'red'], completed: ['Completed', 'grey'], cancelled: ['Cancelled', 'grey'] };

function root() { return document.getElementById('eqb-pro'); }
function n(v) { return parseFloat(v) || 0; }
function freq(f) { return FREQ[f] || [f || '—', f || '', 1]; }
function dateOnly(s) { return s ? String(s).slice(0, 10) : '—'; }
function pot(amount, members) { return n(amount) * (parseInt(members, 10) || 0); }
function duration(f, members) {
  var m = parseInt(members, 10) || 0, fr = freq(f);
  if (!m) return '';
  if (f === 'monthly') return m + (m === 1 ? ' month' : ' months');
  var w = m * fr[2];
  return w + (w === 1 ? ' week' : ' weeks');
}
function stateOf(g) { return STATE[g.status] || [String(g.status || '').replace('_', ' '), 'grey']; }

function load(quiet) {
  var el = root(); if (!el || V.busy) return Promise.resolve();
  K.css(); V.busy = true;
  if (!quiet && !V.loaded) el.innerHTML = '<div class="ak">' + K.skeleton(3) + '</div>';
  return Promise.all([K.api('equb_admin_list_groups', {}), K.api('equb_admin_list_packages', {}), K.api('equb_admin_list_participants', {})]).then(function (r) {
    V.busy = false;
    if (!K.okRes(r[0])) { if (!V.loaded) el.innerHTML = '<div class="ak">' + K.empty('⚠️', 'Could not load Equb', K.failText(r[0])) + '</div>'; else K.toast(K.failText(r[0]), true); return; }
    V.groups = K.data(r[0]).groups || [];
    V.pkgOk = K.okRes(r[1]); V.packages = V.pkgOk ? (K.data(r[1]).packages || []) : [];
    V.ppOk = K.okRes(r[2]); V.people = V.ppOk ? (K.data(r[2]).participants || []) : [];
    V.loaded = true; paint();
  });
}
function reload() { V.busy = false; return load(true); }

function paint() {
  var el = root(); if (!el) return;
  var c = { all: V.groups.length, forming: 0, active: 0, at_risk: 0, completed: 0 }, perRound = 0, saved = 0;
  V.groups.forEach(function (g) {
    if (c[g.status] != null) c[g.status]++;
    if (g.status === 'active' || g.status === 'at_risk') perRound += pot(g.contribution_amount, g.members_count);
    saved += n(g.contribution_amount) * Math.max(0, (parseInt(g.current_round, 10) || 1) - 1) * (parseInt(g.members_count, 10) || 0);
  });
  var allowed = V.people.filter(function (p) { return p.status === 'permitted'; }).length;
  var h = '<div class="ak">' + K.hero('Equb', 'Rotating savings circles. Members pay in each round and one member receives the pot — the money moves between their wallets automatically.', [
    ['Moving each round', K.money(perRound)], ['Running groups', K.num(c.active + c.at_risk)], ['People with access', K.num(allowed)]]) +
    '<div class="ak-tools">' + K.seg('eqtab', [['groups', '🤝 Groups'], ['packages', '📦 Packages'], ['people', '👥 People']], V.tab) +
    '<span style="flex:1"></span><button type="button" class="ak-btn" data-act="refresh" aria-label="Refresh">🔄</button>' +
    '<button type="button" class="ak-btn gold" data-act="newgroup">➕ New group</button></div>';
  if (V.tab === 'groups') h += groupsView(c);
  else if (V.tab === 'packages') h += packagesView();
  else h += peopleView();
  var focusId = document.activeElement && document.activeElement.id, pos = document.activeElement && document.activeElement.selectionStart;
  el.innerHTML = h + '</div>';
  if (focusId) { var i = document.getElementById(focusId); if (i && i.setSelectionRange) { i.focus(); try { i.setSelectionRange(pos, pos); } catch (e) { /* not a text field */ } } }
}

/* ---------- groups ---------- */
function groupsView(c) {
  var kp = [
    { key: '', icon: '🤝', label: 'All groups', value: K.num(c.all) }, { key: 'forming', icon: '🌱', label: 'Forming', value: K.num(c.forming) },
    { key: 'active', icon: '🟢', label: 'Running', value: K.num(c.active) }, { key: 'at_risk', icon: '⚠️', label: 'Needs attention', value: K.num(c.at_risk), warn: true },
    { key: 'completed', icon: '🏁', label: 'Completed', value: K.num(c.completed) }
  ];
  var q = V.q.toLowerCase();
  var rows = V.groups.filter(function (g) { return (!V.filter || g.status === V.filter) && (!q || (String(g.name || '') + ' ' + String(g.creator_name || '')).toLowerCase().indexOf(q) >= 0); });
  var h = '';
  if (c.at_risk > 0) h += '<div class="ak-card alert" style="cursor:default;margin-bottom:12px"><b>⚠️ ' + esc(K.num(c.at_risk)) + (c.at_risk === 1 ? ' group needs' : ' groups need') + ' your attention</b><div style="font-size:12.5px;color:var(--mute);margin-top:4px">A member could not pay and the grace days ran out. Open the group to see who, ask them to top up their wallet, then process the round.</div></div>';
  h += K.kpis(kp, V.filter) + '<div class="ak-tools"><label class="ak-search"><u>🔎</u><input id="eqb-q" type="search" placeholder="Group or creator…" value="' + esc(V.q) + '" autocomplete="off"></label></div>' +
    '<div class="ak-count">' + K.num(rows.length) + ' of ' + K.num(V.groups.length) + ' groups</div>';
  return h + (rows.length ? '<div class="ak-grid">' + rows.map(groupCard).join('') + '</div>' : K.empty('🤝', V.groups.length ? 'No groups match' : 'No Equb groups yet', V.groups.length ? 'Try clearing the filter.' : 'Use "New group" or pick a package to start one.'));
}
function groupCard(g) {
  var st = stateOf(g), members = parseInt(g.members_count, 10) || 0, max = parseInt(g.max_members, 10) || 0, fr = freq(g.frequency);
  var round = parseInt(g.current_round, 10) || 0, total = parseInt(g.total_rounds, 10) || 0, forming = g.status === 'forming';
  var pct = forming ? (max ? Math.round(members / max * 100) : 0) : (total ? Math.min(100, Math.round(Math.max(0, round - 1) / total * 100)) : 0);
  var label = forming ? 'Joined ' + members + ' of ' + max : (total ? 'Round ' + round + ' of ' + total : '—');
  return '<div class="ak-card' + (g.status === 'at_risk' ? ' alert' : '') + '" tabindex="0" role="button" data-group="' + esc(g.id) + '" aria-label="Open ' + esc(g.name) + '">' +
    '<div class="ak-top"><div class="ak-av" style="--a:#5aa9ff;--b:#1f6fd1;font-size:20px">🤝</div><div class="ak-nm"><b>' + esc(g.name) + '</b><small>By ' + esc(g.creator_name || '—') + '</small></div>' + K.pill(st[0], st[1]) + '</div>' +
    '<div style="margin-top:12px;font-size:20px;font-weight:800">' + esc(K.money(pot(g.contribution_amount, forming ? max : members))) + '<span style="font-size:12px;font-weight:600;color:var(--mute)"> pot per round</span></div>' +
    '<div class="ak-chips"><span class="ak-chip">' + esc(K.money(g.contribution_amount)) + ' each</span><span class="ak-chip">' + esc(fr[0]) + '</span><span class="ak-chip">' + esc(String(g.payout_order) === 'lottery' ? 'Lottery order' : 'Join order') + '</span></div>' +
    '<div class="ak-row"><span>' + esc(label) + '</span><span>' + (forming ? 'Not started' : 'Next due <b>' + esc(dateOnly(g.next_contribution_date)) + '</b>') + '</span></div>' +
    '<div style="height:5px;border-radius:5px;background:var(--line);margin-top:8px;overflow:hidden" role="img" aria-label="' + esc(pct) + ' percent"><div style="height:100%;width:' + pct + '%;background:linear-gradient(90deg,var(--gold),var(--gold2))"></div></div></div>';
}

/* ---------- group panel ---------- */
function openGroup(id) {
  V.openId = id;
  var g = null; V.groups.forEach(function (x) { if (String(x.id) === String(id)) g = x; });
  var st = g ? stateOf(g) : ['', 'grey'];
  K.openDrawer('<div class="ak-av lg" style="--a:#5aa9ff;--b:#1f6fd1">🤝</div><div class="ak-nm"><b>' + esc(g ? g.name : 'Group') + '</b><small>' + K.pill(st[0], st[1]) + '</small></div>', K.skeleton(1));
  K.api('equb_admin_group_detail', { group_id: id }).then(function (res) {
    var body = K.drawerBody(); if (!body || V.openId !== id) return;
    if (!K.okRes(res)) { body.innerHTML = K.empty('⚠️', 'Could not open the group', K.failText(res)); return; }
    paintGroup(K.data(res), body);
  });
}
function paintGroup(d, body) {
  var g = d.group, members = d.members || [], payouts = d.payouts || [], fr = freq(g.frequency);
  var active = members.filter(function (m) { return m.status === 'active'; });
  var forming = g.status === 'forming', running = g.status === 'active' || g.status === 'at_risk';
  var round = parseInt(g.current_round, 10) || 0, total = parseInt(g.total_rounds, 10) || 0;
  var paidMap = {}; payouts.forEach(function (p) { paidMap[p.round_number] = p; });
  var h = '<div class="ak-sec"><h4>Summary</h4>' + K.kv([
    ['Each pays', K.money(g.contribution_amount) + ' · ' + fr[0]], ['Pot per round', K.money(pot(g.contribution_amount, forming ? g.max_members : active.length))],
    ['Payout order', String(g.payout_order) === 'lottery' ? 'Random lottery' : 'Order of joining'], ['Members', active.length + ' of ' + g.max_members],
    ['Round', forming ? 'Not started' : (total ? round + ' of ' + total : '—')], ['Next payment due', forming ? '' : dateOnly(g.next_contribution_date)],
    ['Collected so far', K.money(d.collected_total)], ['Created by', g.creator_name], ['Started', g.started_at ? K.fullDate(g.started_at) : '']]) + '</div>';
  if (g.status === 'at_risk') h += '<div class="ak-sec"><div class="ak-card alert" style="cursor:default"><b>⚠️ Waiting on a payment</b><div style="font-size:12.5px;color:var(--mute);margin-top:4px">Someone below is marked <b>Missed</b>. Once they have topped up their wallet, tap “Process this round”.</div></div></div>';
  h += '<div class="ak-sec"><h4>Members (' + active.length + ')</h4>' + (members.length ? '<div style="display:grid;gap:8px">' + members.map(function (m) { return memberRow(m, running, g); }).join('') + '</div>' : '<div style="font-size:12.5px;color:var(--mute)">No one has joined yet.</div>') + '</div>';
  if (!forming && total) {
    var byRound = {}; members.forEach(function (m) { if (m.payout_round) byRound[m.payout_round] = m; });
    var sch = '';
    for (var r = 1; r <= total; r++) {
      var p = paidMap[r], m = byRound[r];
      sch += '<div class="ak-row" style="margin:0;padding:8px 0;border-top:1px solid var(--line)"><span>' + (p ? '✅' : (r === round ? '⏳' : '▫️')) + ' Round <b>' + r + '</b> · ' + esc(m ? m.name : '—') + '</span><span>' + (p ? esc(K.money(p.amount)) + ' · ' + esc(dateOnly(p.paid_at)) : (r === round ? 'This round' : 'Upcoming')) + '</span></div>';
    }
    h += '<div class="ak-sec"><h4>Payout schedule</h4>' + sch + '</div>';
  }
  if (forming) h += '<div class="ak-sec"><h4>Add a member</h4><div style="display:flex;gap:8px;align-items:flex-end">' + K.field('EtelID phone number', '<input id="eqg-phone" class="ak-in" inputmode="tel" autocomplete="off" placeholder="+2519…">', 'They must already have Equb access (People tab).') +
    '<button type="button" class="ak-btn" data-addmember style="margin-bottom:18px">Add</button></div></div>';
  h += '<div class="ak-sec" id="eqg-log" aria-live="polite"></div>';
  body.innerHTML = h;
  var foot = document.querySelector('.ak-dr-foot');
  var acts = '<span class="ak-err" id="eqg-err" role="alert"></span><button type="button" class="ak-btn" data-close>Close</button>';
  if (forming) acts += '<button type="button" class="ak-btn gold" data-startgroup="' + esc(g.id) + '" data-name="' + esc(g.name) + '" data-members="' + active.length + '">🚀 Start group</button>';
  if (running) acts += '<button type="button" class="ak-btn gold" data-process="' + esc(g.id) + '">⚙️ Process this round</button>';
  var dr = K.drawerEl();
  if (!dr) return;
  if (!foot) { foot = document.createElement('div'); foot.className = 'ak-dr-foot'; dr.appendChild(foot); }
  foot.innerHTML = acts;
  if (!dr._eqbWired) { dr._eqbWired = true; dr.addEventListener('click', drawerClick); }
  dr._eqbGroup = g.id;
}
function memberRow(m, running, g) {
  var tone = { paid: 'green', pending: 'blue', missed: 'red' }, label = { paid: 'Paid', pending: 'Not yet', missed: 'Missed' }, cs = m.current_round_status;
  var right = m.status !== 'active' ? K.pill(m.status === 'left' ? 'Left' : 'Defaulted', 'grey') : (running && cs ? K.pill(label[cs] || cs, tone[cs] || 'grey') : '');
  return '<div class="ak-card" style="cursor:default;padding:10px 12px"><div class="ak-top"><div class="ak-av" style="width:38px;height:38px;border-radius:12px;font-size:13px">' + esc(K.initials(m.name)) + '</div>' +
    '<div class="ak-nm"><b style="font-size:13.5px">' + esc(m.name) + '</b><small>' + esc(m.phone || '') + (m.payout_round ? ' · paid out in round ' + esc(m.payout_round) : '') + '</small></div>' + right + '</div>' +
    (running && (parseInt(m.rounds_paid, 10) || parseInt(m.rounds_missed, 10)) ? '<div class="ak-row"><span>Rounds paid <b>' + esc(m.rounds_paid) + '</b></span><span>' + (parseInt(m.rounds_missed, 10) ? 'Missed <b style="color:var(--red)">' + esc(m.rounds_missed) + '</b>' : '') + '</span></div>' : '') + '</div>';
}
function drawerClick(e) {
  var t = e.target, b, dr = K.drawerEl(), id = dr && dr._eqbGroup, err = document.getElementById('eqg-err');
  if (err) err.textContent = '';
  if (t.closest('[data-addmember]')) {
    var ph = document.getElementById('eqg-phone'), phone = ph ? ph.value.trim() : '';
    if (!phone) { if (err) err.textContent = 'Enter the phone number first.'; if (ph) ph.focus(); return; }
    var btn = t.closest('[data-addmember]'); btn.disabled = true;
    K.api('equb_admin_add_member', { group_id: id, phone: phone }).then(function (res) {
      btn.disabled = false;
      if (K.okRes(res)) { K.toast('Member added'); reload().then(function () { openGroup(id); }); }
      else if (err) err.textContent = K.failText(res, 'Could not add the member.');
    });
    return;
  }
  if ((b = t.closest('[data-startgroup]'))) {
    var cnt = parseInt(b.getAttribute('data-members'), 10) || 0;
    K.confirm({ title: 'Start ' + b.getAttribute('data-name') + '?', text: 'Payout order is fixed now and contributions begin right away with ' + cnt + ' member' + (cnt === 1 ? '' : 's') + '. No one can join after this.', label: 'Start group' }).then(function (r) {
      if (!r) return; b.disabled = true;
      K.api('equb_start_group', { group_id: id }).then(function (res) {
        b.disabled = false;
        if (K.okRes(res)) { K.toast('Group started'); reload().then(function () { openGroup(id); }); }
        else if (err) err.textContent = K.failText(res, 'Could not start the group.');
      });
    });
    return;
  }
  if ((b = t.closest('[data-process]'))) {
    K.confirm({ title: 'Process this round now?', text: 'This collects every member’s payment from their wallet and pays the recipient only if everyone has paid — the same as the scheduled job.', label: 'Process round' }).then(function (r) {
      if (!r) return; b.disabled = true; b.textContent = 'Processing…';
      K.api('equb_admin_force_process_round', { group_id: id }).then(function (res) {
        b.disabled = false; b.textContent = '⚙️ Process this round';
        if (!K.okRes(res)) { if (err) err.textContent = K.failText(res, 'Could not process the round.'); return; }
        var lines = K.data(res).log || [], lg = document.getElementById('eqg-log');
        if (lg) lg.innerHTML = '<h4>What happened</h4><div class="ak-prev">' + lines.map(function (l) { return '<div>' + esc(l) + '</div>'; }).join('') + '</div>';
        reload().then(function () { K.api('equb_admin_group_detail', { group_id: id }).then(function (r2) { if (K.okRes(r2)) { var body = K.drawerBody(), keep = lg ? lg.innerHTML : ''; if (body) { paintGroup(K.data(r2), body); var l2 = document.getElementById('eqg-log'); if (l2) l2.innerHTML = keep; } } }); });
      });
    });
  }
}

/* ---------- new group (guided) ---------- */
function val(id) { var e = document.getElementById(id); return e ? e.value.trim() : ''; }
var FREQ_OPTS = [['weekly', '🗓️', 'Weekly', 'Every week'], ['biweekly', '📆', 'Every 2 weeks', 'Twice a month'], ['monthly', '🌙', 'Monthly', 'Once a month']];
var ORDER_OPTS = [['lottery', '🎲', 'Random lottery', 'Fair, no favourites'], ['join_order', '🔢', 'Order of joining', 'First in, first paid']];
function openGroupForm(pre) {
  pre = pre || {};
  var fq = pre.frequency || 'weekly', ord = pre.payout_order || 'lottery';
  var head = '<div class="ak-av lg" style="--a:#5aa9ff;--b:#1f6fd1">🤝</div><div class="ak-nm"><b>New Equb group</b><small>You create it, people join, then you start it.</small></div>';
  var body = '<form id="eqf" autocomplete="off" novalidate><div class="ak-sec"><h4>1 · The group</h4>' + K.field('Name', '<input id="eqf-name" class="ak-in" maxlength="120" placeholder="Merkato Traders Equb" value="' + esc(pre.name || '') + '">') + '</div>' +
    '<div class="ak-sec"><h4>2 · The money</h4><div class="ak-fg">' + K.field('Each person pays (ETB)', '<input id="eqf-amount" type="number" min="1" step="any" class="ak-in" value="' + esc(pre.contribution_amount || '') + '">', 'Every round.') +
    K.field('Number of people', '<input id="eqf-max" type="number" min="2" max="50" class="ak-in" value="' + esc(pre.max_members || '') + '">', '2 to 50.') + '</div></div>' +
    '<div class="ak-sec"><h4>3 · How often</h4>' + K.opts('eqf-freq', FREQ_OPTS, fq) + '</div>' +
    '<div class="ak-sec"><h4>4 · Who is paid first</h4>' + K.opts('eqf-order', ORDER_OPTS, ord) + '</div>' +
    '<div class="ak-sec"><div class="ak-prev" id="eqf-prev" aria-live="polite"></div></div></form>';
  var dr = K.openDrawer(head, body, '<span class="ak-err" id="eqf-err" role="alert"></span><button type="button" class="ak-btn" data-close>Cancel</button><button type="button" class="ak-btn gold" data-save>Create group</button>');
  var f = fq, o = ord;
  function sync() {
    var a = parseFloat(val('eqf-amount')) || 0, m = parseInt(val('eqf-max'), 10) || 0, pv = document.getElementById('eqf-prev');
    if (pv) pv.innerHTML = '<b>What this means</b>' + (a > 0 && m > 1 ? esc(m + ' people each pay ' + K.money(a) + ' ' + freq(f)[0].toLowerCase() + '. Every round one person receives ' + K.money(a * m) + '. The circle runs about ' + duration(f, m) + ' and everyone gets the pot once.') : 'Fill in the amount and the number of people to see the plan.');
  }
  K.wireOpts(dr, function (nm, v) { if (nm === 'eqf-freq') f = v; else o = v; sync(); });
  dr.addEventListener('input', sync);
  dr.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('[data-save]')) saveGroup(f, o, dr); });
  sync();
  var first = document.getElementById('eqf-name'); if (first) first.focus();
}
function saveGroup(f, o, dr) {
  var err = document.getElementById('eqf-err'), bad = function (m, id) { if (err) err.textContent = m; var e = document.getElementById(id); if (e) e.focus(); };
  var a = parseFloat(val('eqf-amount')) || 0, m = parseInt(val('eqf-max'), 10) || 0, name = val('eqf-name');
  if (!name) return bad('Give the group a name.', 'eqf-name');
  if (a <= 0) return bad('Enter how much each person pays.', 'eqf-amount');
  if (m < 2 || m > 50) return bad('The group needs 2 to 50 people.', 'eqf-max');
  if (err) err.textContent = '';
  var btn = dr.querySelector('[data-save]'); btn.disabled = true;
  K.api('equb_create_group', { name: name, contribution_amount: a, frequency: f, max_members: m, payout_order: o }).then(function (res) {
    btn.disabled = false;
    if (K.okRes(res)) { K.closeDrawer(); K.toast('Group created'); V.tab = 'groups'; V.filter = 'forming'; reload(); }
    else if (err) err.textContent = K.failText(res, 'Could not create the group.');
  });
}

/* ---------- packages ---------- */
function packagesView() {
  if (!V.pkgOk) return K.empty('⚠️', 'Packages are not available', 'Equb packages need migration 042 on the server.');
  var list = V.packages.slice().sort(function (a, b) { return (b.active - a.active) || (n(a.contribution_amount) - n(b.contribution_amount)); });
  var h = '<div class="ak-count">' + K.num(list.length) + ' packages · people pick one and join or start a group from it</div>';
  h += '<div style="margin-bottom:10px"><button type="button" class="ak-btn gold" data-act="newpkg">➕ New package</button></div>';
  return h + (list.length ? '<div class="ak-grid">' + list.map(pkgCard).join('') + '</div>' : K.empty('📦', 'No packages yet', 'Create tiers so people can pick what they can afford.'));
}
function pkgCard(p) {
  var t = TIER[p.tier] || TIER.bronze, on = String(p.active) === '1', fr = freq(p.frequency);
  return '<div class="ak-card' + (on ? '' : ' off') + '" style="cursor:default">' +
    '<div class="ak-top"><div class="ak-av" style="--a:' + t.a + ';--b:' + t.b + ';font-size:22px">' + t.e + '</div><div class="ak-nm"><b>' + esc(p.name) + '</b><small>' + esc(p.description || t.n + ' tier') + '</small></div>' + K.pill(on ? 'On sale' : 'Switched off', on ? 'green' : 'red') + '</div>' +
    '<div style="margin-top:12px;font-size:20px;font-weight:800">' + esc(K.money(p.contribution_amount)) + '<span style="font-size:12px;font-weight:600;color:var(--mute)"> ' + esc(fr[0].toLowerCase()) + '</span></div>' +
    '<div class="ak-chips"><span class="ak-chip">' + esc(t.n) + '</span><span class="ak-chip">Up to ' + esc(p.max_members) + ' people</span><span class="ak-chip">Pot ' + esc(K.money(pot(p.contribution_amount, p.max_members))) + '</span><span class="ak-chip">' + esc(String(p.payout_order) === 'lottery' ? 'Lottery' : 'Join order') + '</span></div>' +
    '<div class="ak-row"><span>Groups started from it <b>' + esc(K.num(p.groups_spawned)) + '</b></span><span>' + esc(duration(p.frequency, p.max_members)) + '</span></div>' +
    '<div class="ak-acts" style="margin-top:12px"><button type="button" class="ak-btn" data-editpkg="' + esc(p.id) + '">✏ Edit</button><button type="button" class="ak-btn" data-pkggroup="' + esc(p.id) + '">🤝 Start a group</button>' +
    '<button type="button" class="ak-btn ' + (on ? 'danger' : 'good') + '" data-togglepkg="' + esc(p.id) + '" data-on="' + (on ? 0 : 1) + '">' + (on ? 'Switch off' : 'Switch on') + '</button></div></div>';
}
function findPkg(id) { var r = null; V.packages.forEach(function (p) { if (String(p.id) === String(id)) r = p; }); return r; }
var TIER_OPTS = [['bronze', '🥉', 'Bronze', 'Entry level'], ['silver', '🥈', 'Silver', 'Mid tier'], ['gold', '🥇', 'Gold', 'Higher commitment'], ['platinum', '💎', 'Platinum', 'Premium']];
function openPkgForm(p) {
  var edit = !!p, tier = p ? p.tier : 'bronze', fq = p ? p.frequency : 'weekly', ord = p ? p.payout_order : 'lottery';
  var head = '<div class="ak-av lg" style="--a:#E8C14A;--b:#9a7a1c">📦</div><div class="ak-nm"><b>' + (edit ? 'Edit ' + esc(p.name) : 'New package') + '</b><small>' + (edit ? 'Groups already started keep their own terms.' : 'People choose from these tiers.') + '</small></div>';
  var body = '<form id="epf" autocomplete="off" novalidate><div class="ak-sec"><h4>1 · The tier</h4>' + K.opts('epf-tier', TIER_OPTS, tier) + '</div>' +
    '<div class="ak-sec"><h4>2 · Name and words</h4>' + K.field('Package name', '<input id="epf-name" class="ak-in" maxlength="120" placeholder="Starter Saver" value="' + esc(p ? p.name : '') + '">') +
    '<div style="margin-top:10px">' + K.field('Short description (people read this)', '<input id="epf-desc" class="ak-in" maxlength="255" placeholder="Great for getting started" value="' + esc(p ? p.description || '' : '') + '">') + '</div></div>' +
    '<div class="ak-sec"><h4>3 · The money</h4><div class="ak-fg">' + K.field('Each person pays (ETB)', '<input id="epf-amount" type="number" min="1" step="any" class="ak-in" value="' + esc(p ? n(p.contribution_amount) : '') + '">') +
    K.field('Most people', '<input id="epf-max" type="number" min="2" max="50" class="ak-in" value="' + esc(p ? p.max_members : '') + '">', '2 to 50.') + '</div></div>' +
    '<div class="ak-sec"><h4>4 · How often</h4>' + K.opts('epf-freq', FREQ_OPTS, fq) + '</div>' +
    '<div class="ak-sec"><h4>5 · Who is paid first</h4>' + K.opts('epf-order', ORDER_OPTS, ord) + '</div>' +
    '<div class="ak-sec"><div class="ak-prev" id="epf-prev" aria-live="polite"></div></div></form>';
  var dr = K.openDrawer(head, body, '<span class="ak-err" id="epf-err" role="alert"></span><button type="button" class="ak-btn" data-close>Cancel</button><button type="button" class="ak-btn gold" data-save>' + (edit ? 'Save changes' : 'Create package') + '</button>');
  var st = { t: tier, f: fq, o: ord };
  function sync() {
    var a = parseFloat(val('epf-amount')) || 0, m = parseInt(val('epf-max'), 10) || 0, t = TIER[st.t] || TIER.bronze, pv = document.getElementById('epf-prev');
    if (pv) pv.innerHTML = '<b>People will see</b>' + esc(t.e + ' ' + (val('epf-name') || 'Your package')) + (val('epf-desc') ? ' — ' + esc(val('epf-desc')) : '') +
      '<div style="font-size:12px;color:var(--mute);margin-top:4px">' + (a > 0 && m > 1 ? esc(K.money(a) + ' ' + freq(st.f)[0].toLowerCase() + ' · up to ' + m + ' people · pot ' + K.money(a * m) + ' · about ' + duration(st.f, m)) : 'Fill in the amount and the number of people.') + '</div>';
  }
  K.wireOpts(dr, function (nm, v) { if (nm === 'epf-tier') st.t = v; else if (nm === 'epf-freq') st.f = v; else st.o = v; sync(); });
  dr.addEventListener('input', sync);
  dr.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('[data-save]')) savePkg(p, st, dr); });
  sync();
  var first = document.getElementById('epf-name'); if (first) first.focus();
}
function savePkg(p, st, dr) {
  var err = document.getElementById('epf-err'), bad = function (m, id) { if (err) err.textContent = m; var e = document.getElementById(id); if (e) e.focus(); };
  var a = parseFloat(val('epf-amount')) || 0, m = parseInt(val('epf-max'), 10) || 0, name = val('epf-name');
  if (!name) return bad('Give the package a name.', 'epf-name');
  if (a <= 0) return bad('Enter how much each person pays.', 'epf-amount');
  if (m < 2 || m > 50) return bad('A package needs 2 to 50 people.', 'epf-max');
  if (err) err.textContent = '';
  var body = { name: name, tier: st.t, description: val('epf-desc'), contribution_amount: a, frequency: st.f, max_members: m, payout_order: st.o };
  if (p) body.id = p.id;
  var btn = dr.querySelector('[data-save]'); btn.disabled = true;
  K.api(p ? 'equb_admin_update_package' : 'equb_admin_create_package', body).then(function (res) {
    btn.disabled = false;
    if (K.okRes(res)) { K.closeDrawer(); K.toast(p ? 'Package saved' : 'Package created'); V.tab = 'packages'; reload(); }
    else if (err) err.textContent = K.failText(res, 'Could not save the package.');
  });
}

/* ---------- people ---------- */
function peopleView() {
  if (!V.ppOk) return K.empty('⚠️', 'People are not available', 'Equb access needs migration 041 on the server.');
  var q = V.q.toLowerCase();
  var rows = V.people.filter(function (p) { return !q || (String(p.name || '') + ' ' + String(p.phone || '')).toLowerCase().indexOf(q) >= 0; });
  var h = '<div class="ak-card" style="cursor:default;margin-bottom:12px"><b>Give someone Equb access</b><div style="font-size:12.5px;color:var(--mute);margin:4px 0 10px">Only people you allow can join a group. Enter the phone number of their EtelID account.</div>' +
    '<div style="display:flex;gap:8px;flex-wrap:wrap"><input id="eqp-phone" class="ak-in" inputmode="tel" autocomplete="off" placeholder="+2519…" style="flex:1;min-width:180px"><button type="button" class="ak-btn gold" data-act="grant">✅ Allow</button></div><div class="ak-err" id="eqp-err" role="alert" style="margin-top:6px"></div></div>' +
    '<div class="ak-tools"><label class="ak-search"><u>🔎</u><input id="eqb-q" type="search" placeholder="Name or phone…" value="' + esc(V.q) + '" autocomplete="off"></label></div>' +
    '<div class="ak-count">' + K.num(rows.length) + ' of ' + K.num(V.people.length) + ' people</div>';
  return h + (rows.length ? '<div class="ak-grid">' + rows.map(personCard).join('') + '</div>' : K.empty('👥', V.people.length ? 'No one matches' : 'No one has access yet', 'Allow a person above to get started.'));
}
function personCard(p) {
  var ok = p.status === 'permitted';
  return '<div class="ak-card' + (ok ? '' : ' off') + '" style="cursor:default"><div class="ak-top"><div class="ak-av">' + esc(K.initials(p.name)) + '</div><div class="ak-nm"><b>' + esc(p.name) + '</b><small>' + esc(p.phone || '') + '</small></div>' + K.pill(ok ? 'Allowed' : 'Access removed', ok ? 'green' : 'red') + '</div>' +
    '<div class="ak-row"><span>In <b>' + esc(K.num(p.active_groups)) + '</b> group' + (parseInt(p.active_groups, 10) === 1 ? '' : 's') + '</span><span>Since ' + esc(dateOnly(p.permitted_at)) + '</span></div>' +
    '<div class="ak-acts" style="margin-top:12px">' + (ok ? '<button type="button" class="ak-btn danger" data-revoke="' + esc(p.user_id) + '" data-name="' + esc(p.name) + '">Remove access</button>' : '<button type="button" class="ak-btn good" data-regrant="' + esc(p.phone) + '">Allow again</button>') + '</div></div>';
}
function grant(phone, errEl) {
  if (!phone) { if (errEl) errEl.textContent = 'Enter a phone number first.'; return; }
  K.api('equb_admin_grant_access', { phone: phone }).then(function (res) {
    if (K.okRes(res)) { K.toast('Access granted'); reload(); } else if (errEl) errEl.textContent = K.failText(res, 'Could not grant access.'); else K.toast(K.failText(res), true);
  });
}

/* ---------- wiring ---------- */
var search = K.debounce(function () { paint(); }, 200);
function wire(el) {
  if (el._eqbWired) return; el._eqbWired = true;
  el.addEventListener('click', function (e) {
    var t = e.target, b;
    if ((b = t.closest('[data-seg="eqtab"]'))) { V.tab = b.getAttribute('data-val'); V.q = ''; paint(); return; }
    if ((b = t.closest('[data-kpi]'))) { var k = b.getAttribute('data-kpi'); V.filter = (V.filter === k ? '' : k); paint(); return; }
    if (t.closest('[data-act="refresh"]')) { reload(); return; }
    if (t.closest('[data-act="newgroup"]')) { openGroupForm(); return; }
    if (t.closest('[data-act="newpkg"]')) { openPkgForm(null); return; }
    if (t.closest('[data-act="grant"]')) { var ph = document.getElementById('eqp-phone'); grant(ph ? ph.value.trim() : '', document.getElementById('eqp-err')); return; }
    if ((b = t.closest('[data-group]'))) { openGroup(parseInt(b.getAttribute('data-group'), 10)); return; }
    if ((b = t.closest('[data-editpkg]'))) { var p = findPkg(b.getAttribute('data-editpkg')); if (p) openPkgForm(p); return; }
    if ((b = t.closest('[data-pkggroup]'))) { var pk = findPkg(b.getAttribute('data-pkggroup')); if (pk) openGroupForm({ name: pk.name, contribution_amount: n(pk.contribution_amount), frequency: pk.frequency, max_members: pk.max_members, payout_order: pk.payout_order }); return; }
    if ((b = t.closest('[data-togglepkg]'))) {
      var id = parseInt(b.getAttribute('data-togglepkg'), 10), on = b.getAttribute('data-on') === '1';
      var go = function () { K.api('equb_admin_update_package', { id: id, active: on ? 1 : 0 }).then(function (res) { if (K.okRes(res)) { K.toast(on ? 'Package switched on' : 'Package switched off'); reload(); } else K.toast(K.failText(res), true); }); };
      if (on) go(); else K.confirm({ title: 'Switch this package off?', text: 'People can no longer pick it. Groups already started from it carry on.', label: 'Switch off', danger: true }).then(function (r) { if (r) go(); });
      return;
    }
    if ((b = t.closest('[data-revoke]'))) {
      var uid = parseInt(b.getAttribute('data-revoke'), 10);
      K.confirm({ title: 'Remove access for ' + b.getAttribute('data-name') + '?', text: 'They keep any groups they are already in, but cannot join or start new ones.', label: 'Remove access', danger: true }).then(function (r) {
        if (!r) return;
        K.api('equb_admin_revoke_access', { user_id: uid }).then(function (res) { if (K.okRes(res)) { K.toast('Access removed'); reload(); } else K.toast(K.failText(res), true); });
      });
      return;
    }
    if ((b = t.closest('[data-regrant]'))) { grant(b.getAttribute('data-regrant'), null); }
  });
  el.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('[data-group]')) { e.preventDefault(); openGroup(parseInt(e.target.getAttribute('data-group'), 10)); }
    if (e.key === 'Enter' && e.target.id === 'eqp-phone') { e.preventDefault(); grant(e.target.value.trim(), document.getElementById('eqp-err')); }
  });
  el.addEventListener('input', function (e) { if (e.target.id === 'eqb-q') { V.q = e.target.value.trim(); search(); } });
}

function install() {
  var el = root(); if (!el) return;
  wire(el);
  window.loadEqubAdminPanel = function () { return load(V.loaded); };
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();

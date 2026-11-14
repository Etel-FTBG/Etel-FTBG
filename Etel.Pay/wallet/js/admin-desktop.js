/**
 * ኢE Pay - admin console enhancements (additive; no change to any admin action or money logic).
 *  - icons for the section tabs (vector, replacing emoji) and clearer names
 *  - on wide screens the tabs become a left rail (layout is in css/admin-desktop.css)
 *  - section titles, "Last updated" + Refresh, auto-refresh of the dashboard every 60 s while visible
 *  - dashboard sub-figures (active share, average transaction)
 *  - Export CSV of the rows already loaded (users, transactions)
 *  - keyboard: 1-0 switch sections (not while typing or while a sheet is open), R refreshes
 */
(function () {
  'use strict';
  var root = document.documentElement;
  root.classList.add('ep-admin');

  var SECTIONS = [
    { id: 'dashboard', label: 'Dashboard', sub: 'Live figures for the whole wallet', icon: '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>' },
    { id: 'users', label: 'Users', sub: 'Search, open, suspend, credit or debit a wallet', icon: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><circle cx="17.5" cy="9" r="2.6"/><path d="M17 14.5c2.4.1 4.1 1.5 4.6 4.5"/>' },
    { id: 'transactions', label: 'Transactions', sub: 'Every payment; reverse a completed one', icon: '<path d="M4 8h14l-3.5-3.5M20 16H6l3.5 3.5"/>' },
    { id: 'topups', label: 'Top-ups', sub: 'Approve or reject top-up requests', icon: '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6.5 15h4"/>' },
    { id: 'kyc', label: 'KYC', sub: 'Review identity submissions', icon: '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><circle cx="8.5" cy="11" r="2.2"/><path d="M5.5 16c.5-1.7 1.6-2.4 3-2.4s2.5.7 3 2.4M14.5 10h4M14.5 13.5h3"/>' },
    { id: 'disputes', label: 'Disputes', sub: 'Refund or resolve reported payments', icon: '<path d="M12 3v18M5 7h14M5 7l-2.5 6a3 3 0 0 0 5 0L5 7zM19 7l-2.5 6a3 3 0 0 0 5 0L19 7z"/>' },
    { id: 'analytics', label: 'Analytics', sub: 'Volume and activity over time', icon: '<path d="M3 20h18M6 16v-5M11 16V6M16 16v-8M21 16v-3"/>' },
    { id: 'rates', label: 'Rates', sub: 'Exchange rates used by the wallet', icon: '<path d="M7 7h12l-3-3M17 17H5l3 3"/>' },
    { id: 'settings', label: 'Settings', sub: 'Wallet limits and options', icon: '<circle cx="12" cy="12" r="3"/><path d="M19.4 14a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>' },
    { id: 'updater', label: 'Updater', sub: 'Upload and edit site files', icon: '<path d="M12 16V4M7 9l5-5 5 5M4 20h16"/>' }
  ];
  function svg(inner) { return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + inner + '</svg>'; }

  function active() {
    var a = document.querySelector('.admin-panel.active');
    return a ? a.id.replace('panel-', '') : 'dashboard';
  }
  function $(id) { return document.getElementById(id); }

  function decorate() {
    // tabs: vector icons and clear names
    var tabs = document.querySelectorAll('.admin-tab');
    for (var i = 0; i < tabs.length && i < SECTIONS.length; i++) {
      tabs[i].innerHTML = '<span class="adm-ic">' + svg(SECTIONS[i].icon) + '</span><span class="adm-lb">' + SECTIONS[i].label + '</span>';
      tabs[i].setAttribute('title', SECTIONS[i].label + ' (' + ((i + 1) % 10) + ')');
    }
    // section titles
    SECTIONS.forEach(function (s) {
      var p = $('panel-' + s.id); if (!p || p.querySelector('.adm-title')) return;
      var h = document.createElement('div');
      h.className = 'adm-title';
      h.innerHTML = '<h2>' + s.label + '</h2><p>' + s.sub + '</p>';
      p.insertBefore(h, p.firstChild);
    });
    // brand block for the left rail
    if (!$('adm-brand')) {
      var b = document.createElement('div');
      b.id = 'adm-brand';
      b.innerHTML = '<img src="icons/logo.png" alt="" width="40" height="40"><div><b>ኢE Pay</b><span>Admin console</span></div>';
      document.body.appendChild(b);
    }
    // header: last updated + refresh
    var hdr = document.querySelector('.ep-header');
    if (hdr && !$('adm-refresh')) {
      var r = document.createElement('div');
      r.id = 'adm-refresh';
      r.innerHTML = '<span id="adm-updated" aria-live="polite"></span><button type="button" id="adm-refresh-btn" title="Refresh (R)">' +
        svg('<path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7"/>') + '<span>Refresh</span></button>';
      var live = hdr.querySelector('.live-badge');
      hdr.insertBefore(r, live || null);
      $('adm-refresh-btn').addEventListener('click', refresh);
    }
    var back = document.querySelector('.ep-header-back');
    if (back) back.setAttribute('aria-label', 'Back to profile');
  }

  function stamp() {
    var el = $('adm-updated'); if (!el) return;
    var d = new Date(), p = function (n) { return (n < 10 ? '0' : '') + n; };
    el.textContent = 'Updated ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }

  function refresh() {
    var s = active();
    try {
      if (s === 'dashboard') { EP_API.adminStats().then(function (j) { if (j && j.success) renderStats(j.data); loadDashTx(); stamp(); }); return; }
      var fn = { users: 'loadUsers', transactions: 'loadAdminTx', topups: function () { loadTopups('processing'); }, kyc: function () { loadKYC('submitted'); }, disputes: function () { loadDisputes('open'); }, analytics: function () { loadAnalytics('30d'); }, rates: 'loadRates', settings: 'loadSettings' }[s];
      if (typeof fn === 'string') window[fn](); else if (fn) fn();
      stamp();
    } catch (e) { /* the page's own error toast covers failures */ }
  }

  // dashboard sub-figures
  function subfigs() {
    try {
      var total = parseFloat((($('st-users') || {}).textContent || '').replace(/,/g, '')) || 0;
      var act = parseFloat((($('st-active') || {}).textContent || '').replace(/,/g, '')) || 0;
      var txs = parseFloat((($('st-txs') || {}).textContent || '').replace(/,/g, '')) || 0;
      var vol = parseFloat((($('st-vol') || {}).textContent || '').replace(/,/g, '')) || 0;
      function put(id, text) {
        var el = $(id); if (!el || !el.parentNode) return;
        var s = el.parentNode.querySelector('.adm-sub');
        if (!s) { s = document.createElement('div'); s.className = 'adm-sub'; el.parentNode.appendChild(s); }
        s.textContent = text;
      }
      put('st-users', 'registered wallets');
      put('st-active', total > 0 ? Math.round(act / total * 100) + '% of all users' : 'active users');
      put('st-txs', 'all time');
      put('st-vol', txs > 0 ? 'average ETB ' + Math.round(vol / txs).toLocaleString('en-US') + ' per transaction' : 'all time');
    } catch (e) { /* cosmetic only */ }
  }
  var _rs = window.renderStats;
  if (typeof _rs === 'function') {
    window.renderStats = function (d) { _rs(d); subfigs(); stamp(); };
  }

  // CSV export of the rows already loaded
  function csvCell(v) { v = v == null ? '' : String(v); return /[",\n\r]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; }
  function download(name, rows) {
    var csv = '﻿' + rows.map(function (r) { return r.map(csvCell).join(','); }).join('\r\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    a.download = name; document.body.appendChild(a); a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
  }
  function today() { return new Date().toISOString().slice(0, 10); }
  function addExport(panelId, label, handler) {
    var p = $(panelId); if (!p || p.querySelector('.adm-export')) return;
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'adm-export';
    b.innerHTML = svg('<path d="M12 4v12M7 11l5 5 5-5M4 20h16"/>') + '<span>' + label + '</span>';
    b.addEventListener('click', handler);
    var t = p.querySelector('.adm-title'); if (t) t.appendChild(b);
  }
  function exportUsers() {
    var rows = [['id', 'name', 'phone', 'status', 'balance_etb']];
    (typeof _allUsers !== 'undefined' ? _allUsers : []).forEach(function (u) { rows.push([u.id || u.user_id, u.full_name, u.phone, u.status, u.balance_etb]); });
    if (rows.length < 2) { alert('Nothing loaded yet.'); return; }
    download('etelpay-users-' + today() + '.csv', rows);
  }
  function exportTx() {
    var rows = [['id', 'type', 'status', 'sender', 'receiver', 'amount', 'currency', 'date']];
    (typeof _allTx !== 'undefined' ? _allTx : []).forEach(function (t) { rows.push([t.id || t.tx_ref, t.type, t.status, t.sender_phone, t.receiver_phone, t.amount, t.currency || 'ETB', t.created_at]); });
    if (rows.length < 2) { alert('Nothing loaded yet.'); return; }
    download('etelpay-transactions-' + today() + '.csv', rows);
  }

  function typing(e) {
    var t = e.target, n = t && t.tagName;
    return n === 'INPUT' || n === 'TEXTAREA' || n === 'SELECT' || (t && t.isContentEditable);
  }
  document.addEventListener('keydown', function (e) {
    if (e.ctrlKey || e.metaKey || e.altKey || typing(e)) return;
    if (document.querySelector('.ep-overlay.open, .ep-overlay.active, .ep-overlay.show')) return;
    if (/^[0-9]$/.test(e.key)) {
      var i = e.key === '0' ? 9 : parseInt(e.key, 10) - 1;
      if (SECTIONS[i] && typeof switchTab === 'function') { switchTab(SECTIONS[i].id); e.preventDefault(); }
    } else if (e.key === 'r' || e.key === 'R') { refresh(); }
  });

  function boot() {
    decorate();
    addExport('panel-users', 'Export CSV', exportUsers);
    addExport('panel-transactions', 'Export CSV', exportTx);
    subfigs(); stamp();
    setInterval(function () { if (!document.hidden && active() === 'dashboard') refresh(); }, 60000);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();

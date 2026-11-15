/* Etel Taxi admin - search, filter, sort and CSV export on every table (drivers, pending, overdue, bank accounts, associations, officers).
   Additive and read-only: it only hides/reorders rows already on the page and downloads what you see. Nothing is sent to the server. */
(function () {
  'use strict';
  var IDS = ['drivers-table', 'pending-table', 'overdue-table', 'bank-table', 'associations-table', 'officers-table', 'audit-table'];
  var NAMES = { 'drivers-table': 'drivers', 'pending-table': 'pending-payments', 'overdue-table': 'overdue-drivers', 'bank-table': 'bank-accounts', 'associations-table': 'associations', 'officers-table': 'traffic-officers', 'audit-table': 'activity-log' };
  var state = {};                                   // per table: { q, status }

  function cellText(td) { return (td.textContent || '').replace(/\s+/g, ' ').trim(); }
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  function csvCell(v) { v = v == null ? '' : String(v); return /[",\n\r]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; }

  function statusCol(table) {
    var ths = table.querySelectorAll('thead th');
    for (var i = 0; i < ths.length; i++) if (/^status$/i.test(cellText(ths[i]))) return i;
    return -1;
  }
  function rows(table) { return table.querySelectorAll('tbody tr'); }

  function apply(id) {
    var box = document.getElementById(id), tb = document.querySelector('.tx-tools[data-for="' + id + '"]');
    if (!box || !tb) return;
    var table = box.querySelector('table');
    var st = state[id] || (state[id] = { q: '', status: '' });
    if (!table) { tb.style.display = 'none'; return; }
    tb.style.display = '';
    var sc = statusCol(table), sel = tb.querySelector('select');
    // status choices come from the rows on the page
    if (sc >= 0) {
      var seen = {}, opts = ['<option value="">All statuses</option>'];
      rows(table).forEach(function (tr) { var c = tr.children[sc]; if (c) { var t = cellText(c); if (t && !seen[t]) { seen[t] = 1; opts.push('<option value="' + esc(t) + '">' + esc(t) + '</option>'); } } });
      var keep = st.status; sel.innerHTML = opts.join(''); sel.value = seen[keep] ? keep : ''; st.status = sel.value; sel.style.display = '';
    } else { sel.style.display = 'none'; st.status = ''; }
    var q = st.q.toLowerCase(), shown = 0, total = 0;
    rows(table).forEach(function (tr) {
      total++;
      var ok = !q || cellText(tr).toLowerCase().indexOf(q) >= 0;
      if (ok && st.status && sc >= 0) ok = !!tr.children[sc] && cellText(tr.children[sc]) === st.status;
      tr.style.display = ok ? '' : 'none';
      if (ok) shown++;
    });
    tb.querySelector('.tx-count').textContent = shown === total ? total + ' rows' : shown + ' of ' + total;
  }

  function sortBy(id, idx, th) {
    var box = document.getElementById(id), table = box && box.querySelector('table'); if (!table) return;
    var dir = th.getAttribute('data-dir') === 'asc' ? 'desc' : 'asc';
    table.querySelectorAll('th').forEach(function (h) { h.removeAttribute('data-dir'); h.removeAttribute('aria-sort'); });
    th.setAttribute('data-dir', dir); th.setAttribute('aria-sort', dir === 'asc' ? 'ascending' : 'descending');
    var body = table.querySelector('tbody'), list = Array.prototype.slice.call(rows(table));
    list.sort(function (a, b) {
      var x = cellText(a.children[idx] || { textContent: '' }), y = cellText(b.children[idx] || { textContent: '' });
      var cx = x.replace(/,/g, '').replace(/^ETB\s*/i, ''), cy = y.replace(/,/g, '').replace(/^ETB\s*/i, '');
      var num = /^-?\d+(\.\d+)?$/.test(cx) && /^-?\d+(\.\d+)?$/.test(cy);
      var r = num ? parseFloat(cx) - parseFloat(cy) : x.localeCompare(y, undefined, { sensitivity: 'base', numeric: true });
      return dir === 'asc' ? r : -r;
    });
    list.forEach(function (tr) { body.appendChild(tr); });
  }

  function exportCsv(id) {
    var box = document.getElementById(id), table = box && box.querySelector('table'); if (!table) return;
    var out = [], head = [];
    table.querySelectorAll('thead th').forEach(function (h) { head.push(cellText(h)); });
    out.push(head.map(csvCell).join(','));
    rows(table).forEach(function (tr) {
      if (tr.style.display === 'none') return;
      var r = []; Array.prototype.forEach.call(tr.children, function (td) { r.push(cellText(td)); });
      out.push(r.map(csvCell).join(','));
    });
    var blob = new Blob(['﻿' + out.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    var a = document.createElement('a'); a.href = URL.createObjectURL(blob);
    a.download = 'etel-taxi-' + NAMES[id] + '-' + new Date().toISOString().slice(0, 10) + '.csv';
    document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
  }

  function build(id) {
    var box = document.getElementById(id); if (!box || document.querySelector('.tx-tools[data-for="' + id + '"]')) return;
    var tb = document.createElement('div'); tb.className = 'tx-tools'; tb.setAttribute('data-for', id);
    tb.innerHTML = '<input type="search" class="tx-search" placeholder="Search this list" aria-label="Search this list">' +
      '<select class="tx-filter" aria-label="Filter by status" style="display:none"></select>' +
      '<span class="tx-count" aria-live="polite"></span>' +
      '<button type="button" class="btn btn-outline tx-csv">Export CSV</button>';
    box.parentNode.insertBefore(tb, box);
    state[id] = { q: '', status: '' };
    tb.querySelector('.tx-search').addEventListener('input', function () { state[id].q = this.value; apply(id); });
    tb.querySelector('.tx-filter').addEventListener('change', function () { state[id].status = this.value; apply(id); });
    tb.querySelector('.tx-csv').addEventListener('click', function () { exportCsv(id); });
    box.addEventListener('click', function (e) {
      var th = e.target.closest && e.target.closest('th'); if (!th || !box.contains(th)) return;
      var idx = Array.prototype.indexOf.call(th.parentNode.children, th); sortBy(id, idx, th);
    });
    new MutationObserver(function () { prep(id); apply(id); }).observe(box, { childList: true });
    prep(id); apply(id);
  }
  function prep(id) {                               // make headers look clickable
    var box = document.getElementById(id), table = box && box.querySelector('table'); if (!table) return;
    table.querySelectorAll('thead th').forEach(function (th) { th.classList.add('tx-sortable'); th.setAttribute('title', 'Click to sort'); });
  }
  function boot() { IDS.forEach(build); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();

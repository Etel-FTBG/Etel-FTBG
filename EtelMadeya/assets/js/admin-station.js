/* Admin "Station Console": pick a station, then work it with the full controller screen in an iframe. */
(function () {
  'use strict';
  var el = function (id) { return document.getElementById(id); };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function post(action, data) {
    var fd = new FormData(); Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(API + '?action=' + action, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function toast(m) { if (typeof showNotification === 'function') { try { showNotification(m); return; } catch (e) { /* fall through */ } } }

  function loadStations() {
    var box = el('stationPicker'); if (!box) return;
    fetch(API + '?action=get_controllers', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      var list = (d && (d.controllers || d.data)) || [];
      if (!list.length) { box.innerHTML = '<p class="empty-state">No controllers yet. Add one under Controllers.</p>'; return; }
      box.innerHTML = list.map(function (c) {
        var on = parseInt(c.is_active, 10) === 1;
        return '<button class="station-card' + (on ? '' : ' off') + '" data-open="' + c.id + '"' + (on ? '' : ' disabled') + '>' +
          '<span class="sc-ic">⛽</span><b>' + esc(c.station_name || c.full_name) + '</b><small>' + esc(c.full_name) + ' · ' + esc(c.username) + '</small>' +
          '<em>' + (on ? 'Open console' : 'Inactive') + '</em></button>';
      }).join('');
    }).catch(function () { box.innerHTML = '<p class="empty-state">Could not load stations.</p>'; });
  }
  function openStation(id) {
    post('open_station', { controller_id: id }).then(function (r) {
      if (!r.success) { alert(r.message || 'Could not open the station'); return; }
      var f = el('stationFrame'); f.style.display = 'block'; f.src = '../controller.php?embed=1&_=' + Date.now();
      el('stationClose').style.display = ''; el('stationFull').style.display = '';
      el('stationPicker').classList.add('compact');
      var cards = document.querySelectorAll('#stationPicker .station-card');
      for (var i = 0; i < cards.length; i++) cards[i].classList.toggle('on', cards[i].getAttribute('data-open') === String(id));
    });
  }
  function closeStation() {
    post('close_station', {}).then(function () {
      var f = el('stationFrame'); f.removeAttribute('src'); f.style.display = 'none';
      el('stationClose').style.display = 'none'; el('stationFull').style.display = 'none';
      el('stationPicker').classList.remove('compact');
      var cards = document.querySelectorAll('#stationPicker .station-card'); for (var i = 0; i < cards.length; i++) cards[i].classList.remove('on');
    });
  }
  document.addEventListener('click', function (e) {
    var c = e.target.closest && e.target.closest('.station-card[data-open]'); if (c) { openStation(c.getAttribute('data-open')); return; }
    if (e.target.id === 'stationClose') closeStation();
  });
  var orig = window.showTab;
  window.showTab = function (name) { orig.apply(this, arguments); if (name === 'station') loadStations(); };
})();

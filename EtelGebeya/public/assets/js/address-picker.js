/**
 * Reusable map + search + "use my location" address picker.
 * Requires Leaflet (CSS+JS) already loaded on the page. Talks to this
 * app's own /geocode/search and /geocode/reverse routes (which proxy
 * OpenStreetMap's free Nominatim geocoder server-side).
 *
 * Usage:
 *   EGAddressPicker({
 *     mapEl: 'addr-map', searchInput: 'addr-search', resultsEl: 'addr-results',
 *     latField: 'delivery_lat', lngField: 'delivery_lng', addressField: 'address',
 *     locateBtn: 'addr-locate', initialLat: 7.0500, initialLng: 38.4700,
 *     onChange: function(lat, lng, label) {}
 *   });
 */
function EGAddressPicker(opts) {
  const mapEl    = document.getElementById(opts.mapEl);
  const searchEl = document.getElementById(opts.searchInput);
  const resEl    = document.getElementById(opts.resultsEl);
  const latEl    = document.getElementById(opts.latField);
  const lngEl    = document.getElementById(opts.lngField);
  const addrEl   = opts.addressField ? document.getElementById(opts.addressField) : null;
  const locateBtn = opts.locateBtn ? document.getElementById(opts.locateBtn) : null;
  if (!mapEl || !window.L) return null;

  // Default view: Hawassa, Ethiopia (this marketplace's home city).
  const startLat = opts.initialLat || 7.0500;
  const startLng = opts.initialLng || 38.4700;

  const map = L.map(mapEl).setView([startLat, startLng], opts.initialLat ? 15 : 13);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors', maxZoom: 19,
  }).addTo(map);

  let marker = L.marker([startLat, startLng], { draggable: true }).addTo(map);

  function setPoint(lat, lng, label) {
    marker.setLatLng([lat, lng]);
    map.setView([lat, lng], Math.max(map.getZoom(), 15));
    if (latEl) latEl.value = lat.toFixed(7);
    if (lngEl) lngEl.value = lng.toFixed(7);
    if (label && addrEl) addrEl.value = label;
    if (opts.onChange) opts.onChange(lat, lng, label);
  }

  if (opts.initialLat && opts.initialLng) setPoint(opts.initialLat, opts.initialLng, null);

  marker.on('dragend', function () {
    const p = marker.getLatLng();
    reverseLookup(p.lat, p.lng);
  });
  map.on('click', function (e) {
    setPoint(e.latlng.lat, e.latlng.lng, null);
    reverseLookup(e.latlng.lat, e.latlng.lng);
  });

  function reverseLookup(lat, lng) {
    fetch((window.EG_BASE_URL || '') + '/geocode/reverse?lat=' + lat + '&lng=' + lng)
      .then(r => r.json()).then(d => { if (d && d.label) setPoint(lat, lng, d.label); })
      .catch(() => {});
  }

  let searchTimer = null;
  if (searchEl && resEl) {
    searchEl.addEventListener('input', function () {
      clearTimeout(searchTimer);
      const q = searchEl.value.trim();
      if (q.length < 3) { resEl.innerHTML = ''; resEl.style.display = 'none'; return; }
      searchTimer = setTimeout(function () {
        fetch((window.EG_BASE_URL || '') + '/geocode/search?q=' + encodeURIComponent(q))
          .then(r => r.json())
          .then(rows => {
            if (!rows || !rows.length) { resEl.innerHTML = '<div class="ap-result-empty">No matches</div>'; resEl.style.display = 'block'; return; }
            resEl.innerHTML = rows.map((r, i) =>
              `<div class="ap-result" data-i="${i}">${r.label}</div>`
            ).join('');
            resEl.style.display = 'block';
            resEl.querySelectorAll('.ap-result').forEach(el => {
              el.addEventListener('click', function () {
                const r = rows[+this.dataset.i];
                setPoint(r.lat, r.lng, r.label);
                searchEl.value = r.label;
                resEl.style.display = 'none';
              });
            });
          }).catch(() => {});
      }, 400);
    });
    document.addEventListener('click', function (e) {
      if (e.target !== searchEl && !resEl.contains(e.target)) resEl.style.display = 'none';
    });
  }

  if (locateBtn) {
    locateBtn.addEventListener('click', function () {
      if (!navigator.geolocation) { alert('Your browser does not support location access.'); return; }
      const orig = locateBtn.innerHTML;
      locateBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Locating…';
      locateBtn.disabled = true;
      navigator.geolocation.getCurrentPosition(function (pos) {
        setPoint(pos.coords.latitude, pos.coords.longitude, null);
        reverseLookup(pos.coords.latitude, pos.coords.longitude);
        locateBtn.innerHTML = orig; locateBtn.disabled = false;
      }, function () {
        alert('Could not get your location -- please allow location access, or search/click the map instead.');
        locateBtn.innerHTML = orig; locateBtn.disabled = false;
      }, { enableHighAccuracy: true, timeout: 10000 });
    });
  }

  setTimeout(function () { map.invalidateSize(); }, 200);
  return { map, marker, setPoint };
}

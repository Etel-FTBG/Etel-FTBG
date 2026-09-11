/**
 * Reusable map + search + "use my location" address picker.
 * Uses Leaflet (CSS+JS) for the visual map when it's available, but
 * search and "use my location" work independently of it -- neither
 * needs the map to actually be drawn. Talks to this app's own
 * /geocode/search and /geocode/reverse routes (which proxy
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

  // Default view: Hawassa, Ethiopia (this marketplace's home city).
  const startLat = opts.initialLat || 7.0500;
  const startLng = opts.initialLng || 38.4700;

  let map = null, marker = null;

  function showMapError(reason) {
    console.error('EGAddressPicker: map unavailable -- ' + reason);
    if (mapEl) {
      mapEl.innerHTML = '<div style="height:100%;display:flex;align-items:center;justify-content:center;'
        + 'text-align:center;padding:1rem;font-size:.82rem;color:#c9a96e;background:rgba(0,0,0,.15)">'
        + 'Map preview unavailable right now -- search or "Use My Location" below still work.</div>';
    }
  }

  if (!mapEl) {
    console.error('EGAddressPicker: element #' + opts.mapEl + ' not found in the page.');
  } else if (!window.L) {
    // The single most common cause: leaflet.js either 404'd, or was
    // blocked by the browser (e.g. served with a non-JS Content-Type
    // under X-Content-Type-Options: nosniff) -- check the Network tab
    // for the actual status of that request if this shows up.
    showMapError('window.L is undefined, meaning leaflet.js did not load or did not run.');
  } else {
    try {
      map = L.map(mapEl).setView([startLat, startLng], opts.initialLat ? 15 : 13);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors', maxZoom: 19,
      }).addTo(map);
      marker = L.marker([startLat, startLng], { draggable: true }).addTo(map);
      marker.on('dragend', function () {
        const p = marker.getLatLng();
        reverseLookup(p.lat, p.lng);
      });
      map.on('click', function (e) {
        setPoint(e.latlng.lat, e.latlng.lng, null);
        reverseLookup(e.latlng.lat, e.latlng.lng);
      });
      setTimeout(function () { map.invalidateSize(); }, 200);
    } catch (err) {
      map = null; marker = null;
      showMapError('Leaflet threw while initializing: ' + (err && err.message ? err.message : err));
    }
  }

  function setPoint(lat, lng, label) {
    if (marker) marker.setLatLng([lat, lng]);
    if (map) map.setView([lat, lng], Math.max(map.getZoom(), 15));
    if (latEl) latEl.value = lat.toFixed(7);
    if (lngEl) lngEl.value = lng.toFixed(7);
    if (label && addrEl) addrEl.value = label;
    if (opts.onChange) opts.onChange(lat, lng, label);
  }

  if (opts.initialLat && opts.initialLng) setPoint(opts.initialLat, opts.initialLng, null);

  function reverseLookup(lat, lng) {
    fetch((window.EG_BASE_URL || '') + '/geocode/reverse?lat=' + lat + '&lng=' + lng)
      .then(r => r.json()).then(d => { if (d && d.label) setPoint(lat, lng, d.label); })
      .catch(err => console.error('EGAddressPicker: reverse geocode request failed', err));
  }

  // Search and "Use My Location" only need the lat/lng/address fields
  // and the fetch endpoints -- neither depends on Leaflet, so both are
  // wired up unconditionally below, even if the map above failed.

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
          }).catch(err => {
            console.error('EGAddressPicker: address search request failed', err);
            resEl.innerHTML = '<div class="ap-result-empty">Search failed -- check your connection and try again.</div>';
            resEl.style.display = 'block';
          });
      }, 400);
    });
    document.addEventListener('click', function (e) {
      if (e.target !== searchEl && !resEl.contains(e.target)) resEl.style.display = 'none';
    });
  } else if (opts.searchInput || opts.resultsEl) {
    console.error('EGAddressPicker: search input (#' + opts.searchInput + ') or results element (#' + opts.resultsEl + ') not found.');
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
      }, function (err) {
        console.error('EGAddressPicker: geolocation failed', err);
        alert('Could not get your location -- please allow location access, or search/click the map instead.');
        locateBtn.innerHTML = orig; locateBtn.disabled = false;
      }, { enableHighAccuracy: true, timeout: 10000 });
    });
  } else if (opts.locateBtn) {
    console.error('EGAddressPicker: locate button (#' + opts.locateBtn + ') not found.');
  }

  return { map, marker, setPoint };
}

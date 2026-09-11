/**
 * Reusable map + search + "use my location" address picker.
 * Renders a real Google Map (matching the look of Delivery & Ride's own
 * map screens) via window.EG_GOOGLE_MAPS_KEY, but search and "use my
 * location" work independently of it -- neither needs the map to
 * actually be drawn. Talks to this app's own /geocode/search and
 * /geocode/reverse routes (which proxy OpenStreetMap's free Nominatim
 * geocoder server-side), so no Places/Geocoding billing is required --
 * only "Maps JavaScript API" needs to be enabled on the key.
 *
 * Usage:
 *   EGAddressPicker({
 *     mapEl: 'addr-map', searchInput: 'addr-search', resultsEl: 'addr-results',
 *     latField: 'delivery_lat', lngField: 'delivery_lng', addressField: 'address',
 *     locateBtn: 'addr-locate', initialLat: 7.0500, initialLng: 38.4700,
 *     onChange: function(lat, lng, label) {}
 *   });
 */
// Self-correcting fallback for window.EG_BASE_URL: if the page didn't
// define it (or a cache served a stale copy of the page's HTML from
// before it existed), derive it from this very script's own src --
// the browser only runs this file because it just fetched it from a
// real, correct URL, so that URL is trustworthy even when the page
// around it might not be. Without this, a missing EG_BASE_URL sends
// every geocode request to the domain root instead of the app's own
// subdirectory, 404ing silently.
(function () {
  if (window.EG_BASE_URL) return;
  var s = document.currentScript
    || Array.prototype.find.call(document.querySelectorAll('script[src*="address-picker.js"]'), function () { return true; });
  if (s && s.src) {
    var idx = s.src.indexOf('/public/assets/js/address-picker.js');
    if (idx > -1) window.EG_BASE_URL = s.src.slice(0, idx);
  }
})();

// Loads the Google Maps JS API script exactly once even if multiple
// EGAddressPicker() instances are created on the same page (e.g. two
// address fields on one form) -- every caller after the first just
// waits on this shared promise instead of injecting its own <script>
// tag, which would trigger Google's "included the Maps API multiple
// times" warning and race two competing callbacks.
let _egMapsLoading = null;
function _egLoadGoogleMaps() {
  if (window.google && window.google.maps) return Promise.resolve();
  if (_egMapsLoading) return _egMapsLoading;
  _egMapsLoading = new Promise(function (resolve, reject) {
    if (!window.EG_GOOGLE_MAPS_KEY) {
      reject(new Error('EG_GOOGLE_MAPS_KEY is not set on the page.'));
      return;
    }
    window.gm_authFailure = function () {
      console.error('EGAddressPicker: Google Maps auth failed -- check the API key and its referrer restrictions (https://ethelmarket.com/*) in Google Cloud Console.');
      reject(new Error('gm_authFailure'));
    };
    window._egInitGoogleMaps = function () { resolve(); };
    const script = document.createElement('script');
    script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(window.EG_GOOGLE_MAPS_KEY)
      + '&loading=async&callback=_egInitGoogleMaps';
    script.async = true;
    script.defer = true;
    script.onerror = function () { reject(new Error('Google Maps script failed to load (network/CSP).')); };
    document.head.appendChild(script);
  });
  return _egMapsLoading;
}

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
  const picker = { map: null, marker: null, setPoint: setPoint };

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
  } else {
    _egLoadGoogleMaps().then(function () {
      try {
        map = new google.maps.Map(mapEl, {
          center: { lat: startLat, lng: startLng },
          zoom: opts.initialLat ? 15 : 13,
          mapTypeControl: false, streetViewControl: false, fullscreenControl: false,
        });
        marker = new google.maps.Marker({ position: { lat: startLat, lng: startLng }, map, draggable: true });
        picker.map = map; picker.marker = marker;
        marker.addListener('dragend', function () {
          const p = marker.getPosition();
          reverseLookup(p.lat(), p.lng());
        });
        map.addListener('click', function (e) {
          setPoint(e.latLng.lat(), e.latLng.lng(), null);
          reverseLookup(e.latLng.lat(), e.latLng.lng());
        });
      } catch (err) {
        map = null; marker = null;
        showMapError('Google Maps threw while initializing: ' + (err && err.message ? err.message : err));
      }
    }).catch(function (err) {
      showMapError((err && err.message ? err.message : err));
    });
  }

  function setPoint(lat, lng, label) {
    if (marker) marker.setPosition({ lat, lng });
    if (map) map.setCenter({ lat, lng });
    if (map && map.getZoom() < 15) map.setZoom(15);
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
  // and the fetch endpoints -- neither depends on the map, so both are
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

  return picker;
}

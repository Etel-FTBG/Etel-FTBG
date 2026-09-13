/* ════════════════════════════════════════════════════════════════
   ETEL RIDE — map + navigation fix (ADD-ON)
   Load LAST on ride.html, after ride.js (and ride-premium.js):
     <script src="js/ride-fix.js"></script>

   Fixes:
   1. Removes the giant decorative trucks (premium-bg.js) on this map page.
   2. Makes the ride map look/behave like the working courier map
      (hybrid satellite + controls + a green "Me" marker) when it loads.
   3. Clean, branded fallback when Google rejects the map — instead of a
      raw warning + giant cars.
   4. A Hawassa-zone "navigation" fallback so customers can set pickup /
      drop-off and get a fare even when Google Places/Directions are down.

   NOTE: the underlying "Maps API restricted" is a Google Cloud Console
   setting — see the checklist. This file makes the page robust either way.
   ════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  /* ── Approximate Hawassa zone centroids (EDIT to your exact points) ── */
  var ZONES = [
    { n: 'Mehal Ketema',          am: 'መሃል ከተማ',      lat: 7.0606, lng: 38.4760 },
    { n: 'Tabor',                 am: 'ታቦር',          lat: 7.0395, lng: 38.4895 },
    { n: 'Addis Ketema',          am: 'አዲስ ከተማ',     lat: 7.0700, lng: 38.4720 },
    { n: 'Haik Dar',              am: 'ሐይቅ ዳር',       lat: 7.0648, lng: 38.4560 },
    { n: 'Tulu Dimtu',            am: 'ቱሉ ድምቱ',       lat: 7.0500, lng: 38.4990 },
    { n: 'Misrak',                am: 'ምስራቅ',         lat: 7.0620, lng: 38.4945 },
    { n: 'Referral',              am: 'ሪፈራል',         lat: 7.0532, lng: 38.4812 },
    { n: 'Hawela Teklehaimanot',  am: 'ሐዋላ',          lat: 7.0795, lng: 38.4850 },
    { n: 'Dore Bafana',           am: 'ዶሬ ባፋና',       lat: 7.0895, lng: 38.4700 },
    { n: 'Stadium',               am: 'ስታዲየም',        lat: 7.0560, lng: 38.4690 },
    { n: 'Liqe Mender',           am: 'ልቀ መንደር',      lat: 7.0480, lng: 38.4725 },
    { n: 'University (HU)',       am: 'ዩኒቨርሲቲ',       lat: 7.0452, lng: 38.4958 }
  ];
  var HAWASSA = { lat: 7.0621, lng: 38.4761 };

  injectStyles();
  whenReady(function () {
    killDecorTrucks();
    clearErrorText();
    injectZonePicker();
    hardenNavigation();
    overrideAuthFailure();
    enhanceMapWhenReady();
    // If the map never initialises (auth/quota), show the clean fallback.
    setTimeout(function () { if (!(window.RIDE && RIDE.map)) mapUnavailable(); }, 6500);
  });

  function whenReady(fn) { if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

  /* ── 1. Kill the giant decorative trucks/pins on this map page ── */
  function killDecorTrucks() {
    document.querySelectorAll('.etl-truck-layer,.etl-truck-layer-2,.etl-pin,.etl-pin-1,.etl-pin-2,.etl-pin-3,.etl-pin-4,.etl-speedlines')
      .forEach(function (el) { el.parentNode && el.parentNode.removeChild(el); });
  }

  /* ── clear any leftover "Oops! Something went wrong" in the inputs ── */
  function clearErrorText() {
    ['pickup-input', 'dropoff-input'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el && /oops|something went wrong|error/i.test(el.value || '')) el.value = '';
    });
  }

  /* ── 2/3. Make ride map match courier when it loads ── */
  function enhanceMapWhenReady() {
    var tries = 0;
    (function w() {
      if (window.RIDE && RIDE.map && window.google && google.maps) return enhance();
      if (tries++ < 60) setTimeout(w, 200);
    })();
    function enhance() {
      try {
        RIDE.map.setOptions({
          styles: null,
          disableDefaultUI: false,
          zoomControl: true,
          fullscreenControl: true,
          mapTypeControl: true,
          mapTypeControlOptions: {
            position: google.maps.ControlPosition.TOP_RIGHT,
            mapTypeIds: ['hybrid', 'roadmap']
          }
        });
        RIDE.map.setMapTypeId('hybrid');
      } catch (e) {}
      // green "Me" marker like the courier map
      if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function (pos) {
          try {
            var c = { lat: pos.coords.latitude, lng: pos.coords.longitude };
            if (RIDE._me) RIDE._me.setMap(null);
            RIDE._me = new google.maps.Marker({
              position: c, map: RIDE.map, title: 'You', zIndex: 999,
              icon: { path: google.maps.SymbolPath.CIRCLE, scale: 7, fillColor: '#2ECC71', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 3 }
            });
          } catch (e) {}
        }, function () {}, { enableHighAccuracy: true, timeout: 8000 });
      }
    }
  }

  /* ── 3. Clean branded fallback when Google rejects the map ── */
  function overrideAuthFailure() {
    window.gm_authFailure = function () { mapUnavailable(); };
  }
  function mapUnavailable() {
    var m = document.getElementById('ride-map');
    if (m && !m.dataset.fallback) {
      m.dataset.fallback = '1';
      m.innerHTML =
        '<div style="height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;padding:28px;text-align:center;font-family:Outfit,sans-serif">' +
          '<div style="font-size:42px">🛰️</div>' +
          '<div style="font-size:16px;font-weight:800;color:#E5C76B">Live map is being set up</div>' +
          '<div style="font-size:12.5px;color:#9A9AAE;max-width:300px;line-height:1.7">No problem — pick your <b style="color:#EDEDF5">pickup &amp; drop-off zones</b> below to book your ride right now.</div>' +
        '</div>';
    }
    var zp = document.getElementById('zone-pick');
    if (zp) { zp.style.display = 'block'; zp.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
  }

  /* ── 4. Hawassa zone navigation (works without Google Places) ── */
  var activeTarget = 'pickup';
  function injectZonePicker() {
    var locCard = document.querySelector('.loc-card');
    if (!locCard || document.getElementById('zone-pick')) return;
    var box = document.createElement('div');
    box.id = 'zone-pick';
    box.className = 'zone-pick';
    var chips = ZONES.map(function (z, i) {
      return '<button type="button" class="zchip" data-i="' + i + '">' + z.n + '</button>';
    }).join('');
    box.innerHTML =
      '<div class="zp-head">' +
        '<span class="zp-title">📍 Quick zones <span style="opacity:.6;font-weight:500">· no GPS needed</span></span>' +
        '<div class="zp-toggle">' +
          '<button type="button" class="zt-btn on" data-t="pickup">Set pickup</button>' +
          '<button type="button" class="zt-btn" data-t="dropoff">Set drop-off</button>' +
        '</div>' +
      '</div>' +
      '<div class="zp-chips">' + chips + '</div>';
    locCard.parentNode.insertBefore(box, locCard.nextSibling);

    box.querySelectorAll('.zt-btn').forEach(function (b) {
      b.addEventListener('click', function () {
        activeTarget = b.getAttribute('data-t');
        box.querySelectorAll('.zt-btn').forEach(function (x) { x.classList.toggle('on', x === b); });
      });
    });
    box.querySelectorAll('.zchip').forEach(function (c) {
      c.addEventListener('click', function () {
        var z = ZONES[parseInt(c.getAttribute('data-i'), 10)];
        if (!z) return;
        if (navigator.vibrate) navigator.vibrate(12);
        if (activeTarget === 'pickup') {
          window.setPickup(z.lat, z.lng, z.n + ', Hawassa');
          activeTarget = 'dropoff';
          box.querySelectorAll('.zt-btn').forEach(function (x) { x.classList.toggle('on', x.getAttribute('data-t') === 'dropoff'); });
        } else {
          window.setDropoff(z.lat, z.lng, z.n + ', Hawassa');
        }
      });
    });
  }

  /* ── make setPickup / setDropoff / fare robust (map-optional) ── */
  function hardenNavigation() {
    window.setPickup  = makeSetter('pickup');
    window.setDropoff = makeSetter('dropoff');
    window.calcRouteIfReady = robustCalc;
  }
  function makeSetter(kind) {
    var isPickup = kind === 'pickup';
    return function (lat, lng, addr) {
      RIDE[isPickup ? 'pickupLatLng' : 'dropoffLatLng'] = { lat: lat, lng: lng };
      var inp = document.getElementById(isPickup ? 'pickup-input' : 'dropoff-input');
      if (inp) inp.value = addr || (lat.toFixed(5) + ',' + lng.toFixed(5));
      try {
        if (RIDE.map && window.google && google.maps && google.maps.Marker) {
          var mk = isPickup ? 'pickupMarker' : 'dropoffMarker';
          if (RIDE[mk]) RIDE[mk].setMap(null);
          RIDE[mk] = new google.maps.Marker({
            position: { lat: lat, lng: lng }, map: RIDE.map,
            icon: { path: google.maps.SymbolPath.CIRCLE, fillColor: isPickup ? '#E5C76B' : '#60B4FF', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2, scale: 10 },
            title: isPickup ? 'Pickup' : 'Drop-off'
          });
        }
      } catch (e) {}
      robustCalc();
    };
  }
  function robustCalc() {
    var p = RIDE.pickupLatLng, d = RIDE.dropoffLatLng;
    if (!p || !d) return;
    var done = false;
    function showFare(km, durTxt) {
      var fare = (20 + 8 * km).toFixed(2);
      var fa = document.getElementById('fare-amount'); if (fa) fa.textContent = 'ETB ' + parseFloat(fare).toFixed(2);
      var fd = document.getElementById('fare-dist');   if (fd) fd.textContent = km.toFixed(1) + ' km' + (durTxt ? ' · ' + durTxt : '');
      var fc = document.getElementById('fare-card');    if (fc) fc.style.display = 'flex';
    }
    function fallback() {
      var km = haversine(p.lat, p.lng, d.lat, d.lng);
      showFare(km, '~' + Math.max(3, Math.round(km * 3)) + ' min');
      try {
        if (RIDE.map && google.maps.Polyline) {
          if (RIDE._fbLine) RIDE._fbLine.setMap(null);
          RIDE._fbLine = new google.maps.Polyline({ map: RIDE.map, path: [p, d], strokeColor: '#E5C76B', strokeWeight: 4, strokeOpacity: .85 });
          var b = new google.maps.LatLngBounds(); b.extend(p); b.extend(d);
          RIDE.map.fitBounds(b, { bottom: 340, top: 80, left: 24, right: 24 });
        }
      } catch (e) {}
    }
    try {
      if (RIDE.directionsService && RIDE.map) {
        RIDE.directionsService.route(
          { origin: p, destination: d, travelMode: google.maps.TravelMode.DRIVING },
          function (res, st) {
            if (done) return; done = true;
            if (st === 'OK' && res && res.routes[0]) {
              try { RIDE.directionsRenderer.setDirections(res); } catch (e) {}
              var leg = res.routes[0].legs[0];
              showFare(leg.distance.value / 1000, leg.duration.text);
              try { var b = new google.maps.LatLngBounds(); b.extend(p); b.extend(d); RIDE.map.fitBounds(b, { bottom: 340, top: 80, left: 24, right: 24 }); } catch (e) {}
            } else { fallback(); }
          }
        );
        setTimeout(function () { if (!done) { done = true; fallback(); } }, 4000); // Directions API blocked/slow
      } else { fallback(); }
    } catch (e) { fallback(); }
  }
  function haversine(la1, lo1, la2, lo2) {
    var R = 6371, r = Math.PI / 180;
    var dLa = (la2 - la1) * r, dLo = (lo2 - lo1) * r;
    var x = Math.sin(dLa / 2) * Math.sin(dLa / 2) + Math.cos(la1 * r) * Math.cos(la2 * r) * Math.sin(dLo / 2) * Math.sin(dLo / 2);
    return R * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
  }

  /* ── styles ── */
  function injectStyles() {
    var css = ''
      + '.etl-truck-layer,.etl-truck-layer-2,.etl-pin,.etl-pin-1,.etl-pin-2,.etl-pin-3,.etl-pin-4,.etl-speedlines{display:none!important}'
      + '#ride-map{background:#0a0a16!important}'
      + '.zone-pick{margin:0 0 14px;padding:14px;border-radius:18px;background:rgba(255,255,255,.04);border:1px solid rgba(229,199,107,.18)}'
      + '.zp-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:11px}'
      + '.zp-title{font-family:Outfit,sans-serif;font-size:12px;font-weight:700;color:#E5C76B}'
      + '.zp-toggle{display:flex;gap:5px;background:rgba(0,0,0,.25);border-radius:999px;padding:3px}'
      + '.zt-btn{border:none;cursor:pointer;font-family:Outfit,sans-serif;font-size:11px;font-weight:700;color:#9A9AAE;background:transparent;padding:6px 12px;border-radius:999px;transition:all .2s}'
      + '.zt-btn.on{color:#1a1206;background:linear-gradient(135deg,#FFE9A8,#D4AF37)}'
      + '.zp-chips{display:flex;flex-wrap:wrap;gap:7px}'
      + '.zchip{border:1px solid rgba(255,255,255,.12);cursor:pointer;font-family:Outfit,sans-serif;font-size:11.5px;font-weight:600;color:#EDEDF5;background:rgba(255,255,255,.04);padding:8px 12px;border-radius:10px;transition:all .18s cubic-bezier(.34,1.56,.64,1)}'
      + '.zchip:hover{border-color:rgba(229,199,107,.4);transform:translateY(-2px)}'
      + '.zchip:active{transform:scale(.94)}'
      + '@media (prefers-reduced-motion:reduce){.zchip,.zt-btn{transition:none}}';
    var s = document.createElement('style'); s.textContent = css; document.head.appendChild(s);
  }
})();

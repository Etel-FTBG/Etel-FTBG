/* ════════════════════════════════════════════════════════════════
   ETEL RIDE — map + navigation fix v2 (ADD-ON)
   Load LAST in ride.html: <script src="js/ride-fix.js"></script>
   ════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ── Hawassa zone fallback (edit coords as needed) ── */
  var ZONES = [
    {n:'Mehal Ketema',am:'መሃል ከተማ',lat:7.0606,lng:38.4760},
    {n:'Tabor',am:'ታቦር',lat:7.0395,lng:38.4895},
    {n:'Addis Ketema',am:'አዲስ ከተማ',lat:7.0700,lng:38.4720},
    {n:'Haik Dar',am:'ሐይቅ ዳር',lat:7.0648,lng:38.4560},
    {n:'Tulu Dimtu',am:'ቱሉ ድምቱ',lat:7.0500,lng:38.4990},
    {n:'Misrak',am:'ምስራቅ',lat:7.0620,lng:38.4945},
    {n:'Referral',am:'ሪፈራል',lat:7.0532,lng:38.4812},
    {n:'Hawela',am:'ሐዋላ',lat:7.0795,lng:38.4850},
    {n:'Dore Bafana',am:'ዶሬ ባፋና',lat:7.0895,lng:38.4700},
    {n:'Stadium',am:'ስታዲየም',lat:7.0560,lng:38.4690},
    {n:'Liqe Mender',am:'ልቀ መንደር',lat:7.0480,lng:38.4725},
    {n:'University',am:'ዩኒቨርሲቲ',lat:7.0452,lng:38.4958}
  ];

  /* ══════════════════════════════════════════════════════════
     CRITICAL: initRideMap FALLBACK — runs IMMEDIATELY so it
     exists before the Google Maps callback fires, even if
     ride.js didn't load or has a different filename on server.
  ══════════════════════════════════════════════════════════ */
  if (typeof window.initRideMap !== 'function') {
    window.initRideMap = function () {
      window.RIDE = window.RIDE || {
        map:null,directionsService:null,directionsRenderer:null,
        pickupMarker:null,dropoffMarker:null,driverMarker:null,
        pickupLatLng:null,dropoffLatLng:null,currentRequestId:null,
        currentRideId:null,pollInterval:null,selectedPayment:'cash',
        selectedRating:0,geocoder:null,autocompletePickup:null,autocompleteDropoff:null
      };
      var _rm = document.getElementById('ride-map');
      _rm.style.cssText += ';position:fixed;top:0;left:0;right:0;bottom:0;width:100vw;height:100vh;z-index:0;';
      RIDE.map = new google.maps.Map(_rm, {
        center:{lat:7.0621,lng:38.4761}, zoom:14,
        backgroundColor:'#10101c',
        disableDefaultUI:false, mapTypeId:'roadmap',
        mapTypeControl:false, zoomControl:false, fullscreenControl:false, streetViewControl:false
      });
      RIDE.directionsService  = new google.maps.DirectionsService();
      RIDE.directionsRenderer = new google.maps.DirectionsRenderer({suppressMarkers:true,
        polylineOptions:{strokeColor:'#E5C76B',strokeWeight:4,strokeOpacity:.85}});
      RIDE.directionsRenderer.setMap(RIDE.map);
      RIDE.geocoder = new google.maps.Geocoder();
      _setupAutocomplete();
      _setupMapClick();
      _geolocateUser();
      if (typeof window.checkActiveRide === 'function') window.checkActiveRide();
    };
  }

  function _setupAutocomplete() {
    var pe = document.getElementById('pickup-input');
    var de = document.getElementById('dropoff-input');
    if (!pe || typeof google.maps.places === 'undefined') return;
    try {
      RIDE.autocompletePickup = new google.maps.places.Autocomplete(pe,{componentRestrictions:{country:'ET'}});
      RIDE.autocompletePickup.addListener('place_changed', function(){
        var p=RIDE.autocompletePickup.getPlace();
        if(p.geometry) setPickup(p.geometry.location.lat(),p.geometry.location.lng(),p.formatted_address||p.name);
      });
      RIDE.autocompleteDropoff = new google.maps.places.Autocomplete(de,{componentRestrictions:{country:'ET'}});
      RIDE.autocompleteDropoff.addListener('place_changed', function(){
        var p=RIDE.autocompleteDropoff.getPlace();
        if(p.geometry) setDropoff(p.geometry.location.lat(),p.geometry.location.lng(),p.formatted_address||p.name);
      });
    } catch(e){}
  }
  function _setupMapClick(){
    if(!RIDE.map) return;
    RIDE.map.addListener('click', function(e){
      var lat=e.latLng.lat(), lng=e.latLng.lng();
      RIDE.geocoder.geocode({location:e.latLng},function(res,st){
        var addr=(st==='OK'&&res[0])?res[0].formatted_address:lat.toFixed(5)+','+lng.toFixed(5);
        if(!RIDE.pickupLatLng){ setPickup(lat,lng,addr); document.getElementById('pickup-input').value=addr; }
        else if(!RIDE.dropoffLatLng){ setDropoff(lat,lng,addr); document.getElementById('dropoff-input').value=addr; }
      });
    });
  }
  function _geolocateUser(){
    if(!navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition(function(pos){
      try{
        RIDE.map.setCenter({lat:pos.coords.latitude,lng:pos.coords.longitude});
        if(!RIDE.pickupLatLng){ setPickup(pos.coords.latitude,pos.coords.longitude,'Your location'); document.getElementById('pickup-input').value='Your location'; }
        if(RIDE._me) RIDE._me.setMap(null);
        RIDE._me = new google.maps.Marker({position:{lat:pos.coords.latitude,lng:pos.coords.longitude},map:RIDE.map,title:'You',zIndex:999,
          icon:{path:google.maps.SymbolPath.CIRCLE,scale:7,fillColor:'#2ECC71',fillOpacity:1,strokeColor:'#fff',strokeWeight:3}});
      }catch(e){}
    },function(){},{enableHighAccuracy:true,timeout:8000});
  }

  /* ══════════════ DOM-READY SECTION ══════════════ */
  function whenReady(fn){ if(document.readyState!=='loading') fn(); else document.addEventListener('DOMContentLoaded',fn); }
  whenReady(function(){
    killDecorTrucks();
    clearErrorText();
    injectZonePicker();
    hardenNavigation();
    injectNavPanel();
    overrideAuthFailure();
    enhanceMapWhenReady();
    setTimeout(function(){ if(!(window.RIDE&&RIDE.map)) mapUnavailable(); },7000);
  });

  function killDecorTrucks(){
    document.querySelectorAll('.etl-truck-layer,.etl-truck-layer-2,.etl-pin,.etl-pin-1,.etl-pin-2,.etl-pin-3,.etl-pin-4,.etl-speedlines')
      .forEach(function(el){ el.parentNode&&el.parentNode.removeChild(el); });
  }
  function clearErrorText(){
    ['pickup-input','dropoff-input'].forEach(function(id){
      var el=document.getElementById(id);
      if(el&&/oops|something went wrong|error/i.test(el.value||'')) el.value='';
    });
  }

  /* ── Enhance map when it loads (hybrid + Me marker + controls) ── */
  function enhanceMapWhenReady(){
    var t=0;(function w(){ if(window.RIDE&&RIDE.map&&window.google&&google.maps){ enhance(); } else if(t++<60) setTimeout(w,200); })();
    function enhance(){
      try{
        /* styles: this used to reset to null, wiping the carbon/gold map style set in ride.js */
        RIDE.map.setOptions({styles:window.EtelUI?EtelUI.mapStyles(false):null,disableDefaultUI:false,zoomControl:false,fullscreenControl:false,mapTypeControl:false,streetViewControl:false});
        RIDE.map.setMapTypeId('roadmap');
      }catch(e){}
      _geolocateUser();
    }
  }

  /* ── Auth failure ── */
  /* Chain, don't replace: core.js sets ETEL_MAPS_FAILED and records Google's reason
     in its own gm_authFailure, and replacing it here lost both. */
  function overrideAuthFailure(){
    var prev = window.gm_authFailure;
    window.__etelMapUnavailable = mapUnavailable;
    window.gm_authFailure = function(){ try{ if(typeof prev==='function') prev(); }catch(e){} mapUnavailable(); };
  }
  function mapUnavailable(){
    var m=document.getElementById('ride-map');
    if(m&&!m.dataset.fallback){ m.dataset.fallback='1';
      m.innerHTML='<div style="height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;padding:28px;text-align:center;font-family:Outfit,sans-serif"><div style="font-size:42px">🛰️</div><div style="font-size:16px;font-weight:800;color:#E5C76B">Live map is being set up</div><div style="font-size:12.5px;color:#9A9AAE;max-width:300px;line-height:1.7">Pick your <b style="color:#EDEDF5">pickup &amp; drop-off zones</b> below to book now.</div></div>';
    }
    var zp=document.getElementById('zone-pick');
    if(zp){ zp.style.display='block'; zp.scrollIntoView({behavior:'smooth',block:'nearest'}); }
  }

  /* ── Zone picker ── */
  var activeTarget='pickup';
  function injectZonePicker(){
    var loc=document.querySelector('.loc-card');
    if(!loc||document.getElementById('zone-pick')) return;
    var box=document.createElement('div'); box.id='zone-pick'; box.className='zone-pick';
    var chips=ZONES.map(function(z,i){ return '<button type="button" class="zchip" data-i="'+i+'">'+z.n+'</button>'; }).join('');
    box.innerHTML='<div class="zp-head"><span class="zp-title">Quick zones <span style="opacity:.6;font-weight:500">· no GPS needed</span></span><div class="zp-toggle"><button type="button" class="zt-btn on" data-t="pickup">Set pickup</button><button type="button" class="zt-btn" data-t="dropoff">Set drop-off</button></div></div><div class="zp-chips">'+chips+'</div>';
    loc.parentNode.insertBefore(box,loc.nextSibling);
    box.querySelectorAll('.zt-btn').forEach(function(b){ b.addEventListener('click',function(){ activeTarget=b.getAttribute('data-t'); box.querySelectorAll('.zt-btn').forEach(function(x){ x.classList.toggle('on',x===b); }); }); });
    box.querySelectorAll('.zchip').forEach(function(c){ c.addEventListener('click',function(){
      var z=ZONES[parseInt(c.getAttribute('data-i'),10)]; if(!z) return;
      if(navigator.vibrate) navigator.vibrate(12);
      if(activeTarget==='pickup'){ window.setPickup(z.lat,z.lng,z.n+', Hawassa'); activeTarget='dropoff'; box.querySelectorAll('.zt-btn').forEach(function(x){ x.classList.toggle('on',x.getAttribute('data-t')==='dropoff'); }); }
      else { window.setDropoff(z.lat,z.lng,z.n+', Hawassa'); }
    }); });
  }

  /* ── Harden navigation (robust setPickup/setDropoff/calcRoute) ── */
  function hardenNavigation(){
    window.setPickup  = makeSetter('pickup');
    window.setDropoff = makeSetter('dropoff');
    window.calcRouteIfReady = robustCalc;
  }
  function makeSetter(kind){
    var isP=kind==='pickup';
    return function(lat,lng,addr){
      RIDE[isP?'pickupLatLng':'dropoffLatLng']={lat:lat,lng:lng};
      var inp=document.getElementById(isP?'pickup-input':'dropoff-input'); if(inp) inp.value=addr||(lat.toFixed(5)+','+lng.toFixed(5));
      try{ if(RIDE.map&&window.google&&google.maps&&google.maps.Marker){
        var mk=isP?'pickupMarker':'dropoffMarker';
        if(RIDE[mk]) RIDE[mk].setMap(null);
        RIDE[mk]=new google.maps.Marker({position:{lat:lat,lng:lng},map:RIDE.map,
          icon:{path:google.maps.SymbolPath.CIRCLE,fillColor:isP?'#E5C76B':'#60B4FF',fillOpacity:1,strokeColor:'#fff',strokeWeight:2,scale:10},title:kind});
      }}catch(e){}
      robustCalc();
    };
  }
  function robustCalc(){
    var p=RIDE.pickupLatLng, d=RIDE.dropoffLatLng; if(!p||!d) return;
    var done=false;
    function showFare(km,dur){
      // Used to compute its own flat fare here (120+30*km), completely
      // ignoring RIDE.selectedVtype - since this function runs on every
      // pickup/dropoff set AND gets re-triggered by ride-fare-self-heal.js
      // after every vtype tap + every 2s on a timer, that flat number was
      // silently overwriting whatever refreshFareDisplay() had just
      // correctly rendered for the selected vehicle type. Real bug: no
      // matter which vtype you tapped, the fare always snapped back to
      // this one flat formula's output within ~0-2s.
      // Fix: only compute km/duration here (this function's actual job -
      // getting a real route or a haversine fallback), then hand off to
      // the real vtype-aware + server-backed fare pipeline in ride.js.
      RIDE.lastRouteKm = km;
      RIDE.lastRouteDurLabel = dur;
      RIDE.lastRouteIsManual = false;
      if (typeof window.refreshFareDisplay === 'function') {
        window.refreshFareDisplay();
        return;
      }
      // Fallback only if ride.js's real fare function somehow isn't
      // loaded - keeps this file's original resilience intent for that
      // edge case, without being the thing that runs in normal operation.
      var f=(120+30*km).toFixed(2); var fa=document.getElementById('fare-amount'); if(fa) fa.textContent='ETB '+parseFloat(f).toFixed(2); var fd=document.getElementById('fare-dist'); if(fd) fd.textContent=km.toFixed(1)+' km'+(dur?' · '+dur:''); var fc=document.getElementById('fare-card'); if(fc) fc.style.display='flex';
    }
    function fallback(){ var km=haversine(p.lat,p.lng,d.lat,d.lng); showFare(km,'~'+Math.max(3,Math.round(km*3))+' min'); try{ if(RIDE.map&&google.maps.Polyline){ if(RIDE._fbLine) RIDE._fbLine.setMap(null); RIDE._fbLine=new google.maps.Polyline({map:RIDE.map,path:[p,d],strokeColor:'#E5C76B',strokeWeight:4,strokeOpacity:.85}); var b=new google.maps.LatLngBounds();b.extend(p);b.extend(d);RIDE.map.fitBounds(b,{bottom:340,top:80,left:24,right:24}); }}catch(e){} }
    try{ if(RIDE.directionsService&&RIDE.map){
      RIDE.directionsService.route({origin:p,destination:d,travelMode:google.maps.TravelMode.DRIVING},function(res,st){
        if(done) return; done=true;
        if(st==='OK'&&res&&res.routes[0]){ try{RIDE.directionsRenderer.setDirections(res);}catch(e){} var leg=res.routes[0].legs[0]; showFare(leg.distance.value/1000,leg.duration.text); try{var b=new google.maps.LatLngBounds();b.extend(p);b.extend(d);RIDE.map.fitBounds(b,{bottom:340,top:80,left:24,right:24});}catch(e){} }
        else fallback();
      });
      setTimeout(function(){ if(!done){done=true;fallback();} },4000);
    } else fallback(); }catch(e){ fallback(); }
  }
  function haversine(a,b,c,d){ var R=6371,r=Math.PI/180,dA=(c-a)*r,dB=(d-b)*r,x=Math.sin(dA/2)*Math.sin(dA/2)+Math.cos(a*r)*Math.cos(c*r)*Math.sin(dB/2)*Math.sin(dB/2); return R*2*Math.atan2(Math.sqrt(x),Math.sqrt(1-x)); }

  /* ════════════════════════════════════════════════════════
     LIVE DRIVER NAVIGATION PANEL (customer sees driver approach)
  ════════════════════════════════════════════════════════ */
  function injectNavPanel(){
    if(document.getElementById('rf-nav-panel')) return;
    var panel=document.createElement('div'); panel.id='rf-nav-panel'; panel.className='rf-nav-panel';
    panel.innerHTML='<div class="rfnp-icon">🚗</div><div class="rfnp-body"><div class="rfnp-status"></div><div class="rfnp-dist"></div></div><div class="rfnp-bar"><div class="rfnp-prog"></div></div>';
    var sheet=document.querySelector('.ride-sheet'); if(sheet) sheet.prepend(panel); else document.body.appendChild(panel);

    /* Poll driver position every 5s while active */
    setInterval(function(){
      if(!window.RIDE||!RIDE.driverMarker) { panel.style.display='none'; return; }
      var dp; try{ dp=RIDE.driverMarker.getPosition(); }catch(e){} if(!dp) return;
      /* Street-hail / open trips never have a fixed pickup phase to
         navigate to -- the driver already linked up with the customer in
         person. dropoff_lat/lng are intentionally never set for these
         (there's no fixed destination, the GPS meter tracks distance as
         they go), which used to make the "heading to pickup" branch below
         run forever, computing distance from the driver's live, moving
         position back to a stale snapshot of where the hail happened --
         growing nonsensically large as the trip actually progressed. */
      var isOpenTrip = !!(RIDE.activeRideData && String(RIDE.activeRideData.open_trip) === '1');
      if (isOpenTrip) {
        var km3 = (RIDE.activeRideData && RIDE.activeRideData.traveled_km) ? parseFloat(RIDE.activeRideData.traveled_km) : 0;
        var fare3 = (RIDE.activeRideData && RIDE.activeRideData.fare_final) ? parseFloat(RIDE.activeRideData.fare_final) : 0;
        panel.style.display='flex';
        panel.querySelector('.rfnp-icon').textContent='🚗';
        panel.querySelector('.rfnp-status').textContent='Trip in progress — GPS meter running';
        panel.querySelector('.rfnp-dist').textContent=km3.toFixed(2)+' km · ETB '+fare3.toFixed(0)+' so far';
        panel.querySelector('.rfnp-prog').style.width='100%';
        return;
      }
      if(RIDE.pickupLatLng&&!RIDE.dropoffLatLng){
        /* Driver heading to pickup */
        var km=haversine(dp.lat(),dp.lng(),RIDE.pickupLatLng.lat,RIDE.pickupLatLng.lng);
        var eta=Math.max(1,Math.round(km*3));
        panel.style.display='flex';
        panel.querySelector('.rfnp-status').textContent='Driver is on the way';
        panel.querySelector('.rfnp-dist').textContent=km.toFixed(1)+' km away · ETA '+eta+' min';
        var prog=Math.max(0,Math.min(1,1-km/5));
        panel.querySelector('.rfnp-prog').style.width=(prog*100)+'%';
        /* Draw route from driver → pickup */
        if(RIDE.directionsService&&RIDE.map){
          RIDE.directionsService.route({origin:{lat:dp.lat(),lng:dp.lng()},destination:RIDE.pickupLatLng,travelMode:google.maps.TravelMode.DRIVING},function(res,st){
            if(st==='OK') try{RIDE.directionsRenderer.setDirections(res);}catch(e){}
          });
        }
      } else if(RIDE.dropoffLatLng){
        /* Driver carrying passenger to dropoff */
        var km2=haversine(dp.lat(),dp.lng(),RIDE.dropoffLatLng.lat,RIDE.dropoffLatLng.lng);
        var eta2=Math.max(1,Math.round(km2*3));
        panel.style.display='flex';
        panel.querySelector('.rfnp-icon').textContent='📍';
        panel.querySelector('.rfnp-status').textContent='Heading to your destination';
        panel.querySelector('.rfnp-dist').textContent=km2.toFixed(1)+' km remaining · ETA '+eta2+' min';
        var prog2=Math.max(0,Math.min(1,1-km2/10));
        panel.querySelector('.rfnp-prog').style.width=(prog2*100)+'%';
      }
    },5000);
  }

  /* ── Styles ── */
  (function(){
    var css='.etl-truck-layer,.etl-truck-layer-2,.etl-pin,.etl-pin-1,.etl-pin-2,.etl-pin-3,.etl-pin-4,.etl-speedlines{display:none!important}'
      +'#ride-map{background:#0a0a16!important}'
      +'.zone-pick{margin:0 0 14px;padding:14px;border-radius:18px;background:rgba(255,255,255,.04);border:1px solid rgba(229,199,107,.18)}'
      +'.zp-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:11px}'
      +'.zp-title{font-family:Outfit,sans-serif;font-size:12px;font-weight:700;color:#E5C76B}'
      +'.zp-toggle{display:flex;gap:5px;background:rgba(0,0,0,.25);border-radius:999px;padding:3px}'
      +'.zt-btn{border:none;cursor:pointer;font-family:Outfit,sans-serif;font-size:11px;font-weight:700;color:#9A9AAE;background:transparent;padding:6px 12px;border-radius:999px;transition:all .2s}'
      +'.zt-btn.on{color:#1a1206;background:linear-gradient(135deg,#FFE9A8,#D4AF37)}'
      +'.zp-chips{display:flex;flex-wrap:wrap;gap:7px}'
      +'.zchip{border:1px solid rgba(255,255,255,.12);cursor:pointer;font-family:Outfit,sans-serif;font-size:11.5px;font-weight:600;color:#EDEDF5;background:rgba(255,255,255,.04);padding:8px 12px;border-radius:10px;transition:all .18s}'
      +'.zchip:hover{border-color:rgba(229,199,107,.4);transform:translateY(-2px)}'
      +'.zchip:active{transform:scale(.94)}'
      /* nav panel */
      +'.rf-nav-panel{display:none;align-items:center;gap:12px;padding:12px 16px;margin:0 0 10px;border-radius:16px;'
      +'background:linear-gradient(135deg,rgba(46,204,113,.14),rgba(46,204,113,.04));border:1px solid rgba(46,204,113,.3);position:relative;overflow:hidden}'
      +'.rfnp-icon{font-size:22px;flex-shrink:0}'
      +'.rfnp-body{flex:1}'
      +'.rfnp-status{font-family:Outfit,sans-serif;font-size:13px;font-weight:700;color:#EDEDF5}'
      +'.rfnp-dist{font-family:Outfit,sans-serif;font-size:11px;color:#9A9AAE;margin-top:3px}'
      +'.rfnp-bar{position:absolute;bottom:0;left:0;right:0;height:2px;background:rgba(255,255,255,.08)}'
      +'.rfnp-prog{height:100%;background:linear-gradient(90deg,#2ECC71,#E5C76B);border-radius:2px;transition:width .6s ease}';
    var s=document.createElement('style');s.textContent=css;document.head.appendChild(s);
  })();

  window.RideFix={redraw:function(){try{killDecorTrucks();}catch(e){}}};

  /* ── MAP WATCHDOG: detect + recover blank map ─────────────
     If the map container was zero-size at init (or tiles never
     loaded), Google Maps renders blank white. This checks 3s
     after load, logs the cause, and force-recovers. */
  function mapWatchdog(getMap, divId) {
    setTimeout(function () {
      var m = (typeof getMap === 'function') ? getMap() : null;
      var el = document.getElementById(divId);
      if (!el) return;
      var w = el.offsetWidth, h = el.offsetHeight;
      console.log('[ETEL][map] ' + divId + ' size: ' + w + 'x' + h + ', map=' + (m ? 'created' : 'MISSING'));
      if (!m) return;
      // .gm-style exists as soon as Google has built the map; raster tiles (no mapId) can still be
    // downloading at the 3 s check on a slow connection, which used to trigger a false "blank map" banner.
    var hasTiles = el.querySelector('img[src*="googleapis"], img[src*="gstatic"], canvas, .gm-style');
      if (w === 0 || h === 0 || !hasTiles) {
        console.warn('[ETEL][map] blank map detected — forcing resize + recenter');
        try {
          var b = document.createElement('div');
          b.style.cssText = 'position:fixed;bottom:8px;left:8px;z-index:9999;background:rgba(0,0,0,.85);color:#E5C76B;font:11px monospace;padding:6px 10px;border-radius:8px;pointer-events:none';
          b.textContent = '[map] ' + w + 'x' + h + (hasTiles ? ' tiles:yes' : ' tiles:NO') + ' recovering...';
          document.body.appendChild(b);
          setTimeout(function(){ b.remove(); }, 12000);
        } catch (e) {}
        if (w === 0 || h === 0) {
          el.style.cssText += ';position:fixed!important;top:0;left:0;right:0;bottom:0;width:100vw;height:100vh;z-index:0;';
        }
        try {
          var c = m.getCenter();
          google.maps.event.trigger(m, 'resize');
          m.setCenter(c || new google.maps.LatLng(7.0621, 38.4761));
          m.setZoom(m.getZoom() || 14);
        } catch (e) { console.warn('[ETEL][map] recover failed:', e); }
      }
    }, 3000);
  }
  if (document.getElementById('ride-map')) {
    mapWatchdog(function(){ return window.RIDE ? RIDE.map : null; }, 'ride-map');
  }
})();

/* ETEL Admin Rides Panel - Phase 4 */
(function(){
'use strict';
var RS={status:'all',search:'',page:0,limit:25,total:0,rows:[],statsLoaded:false,timer:null};
var RI={filter:'all',rows:[]};
var VC={rows:[]};
var SL={searching:['🔍 Searching','rs-searching'],accepted:['✅ Accepted','rs-accepted'],
  in_progress:['🚗 In Progress','rs-in_progress'],arrived:['🏁 Arrived','rs-arrived'],
  completed:['💰 Completed','rs-completed'],cancelled:['✕ Cancelled','rs-cancelled']};

function api(action,body,ok,fail){
  var token=typeof Auth!=='undefined'?Auth.getToken():'';
  var apiUrl=(window.ETEL&&window.ETEL.api)?window.ETEL.api:'/delivery/api/boot.php';
  var payload=Object.assign({action:action},body||{});
  fetch(apiUrl,{method:'POST',headers:{'Content-Type':'application/json','Authorization':'Bearer '+token},body:JSON.stringify(payload)})
  .then(function(r){return r.json();})
  .then(function(d){if(d.success)ok(d.data);else if(fail)fail(d.message||'Error');})
  .catch(function(e){if(fail)fail(e.message);});
}

function fmt(n){return parseFloat(n||0).toLocaleString('en-ET',{maximumFractionDigits:0});}
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

function loadStats(){
  api('admin_get_ride_stats',{},function(d){
    RS.statsLoaded=true;
    var el=document.getElementById('rides-stats-strip'); if(!el)return;
    var cards=[
      {label:'Active Now',val:d.active_now,sub:'Searching / in progress',color:'#60B4FF'},
      {label:'Online Drivers',val:d.online_drivers,sub:'Last 5 minutes',color:'#2ECC71'},
      {label:'Today Completed',val:d.today_completed,sub:'ETB '+fmt(d.today_revenue),color:'#E5C76B'},
      {label:'This Week',val:d.week_completed,sub:'ETB '+fmt(d.week_revenue),color:'#4ECDC4'},
      {label:'All Time',val:d.total_completed,sub:'ETB '+fmt(d.total_revenue),color:'#D4AF37'},
    ];
    el.innerHTML=cards.map(function(c){
      return '<div class="rides-stat-card"><div class="rides-stat-label">'+c.label+'</div>'+
        '<div class="rides-stat-val" style="color:'+c.color+'">'+(c.val||0)+'</div>'+
        '<div class="rides-stat-sub">'+c.sub+'</div></div>';
    }).join('');
  });
}

/* ── System health: is everything that should be running actually running? (admin_system_health, read-only) ── */
var SH={timer:null};
var SH_DOT={ok:'#2ECC71',info:'#7AA7FF',warn:'#F0B84A',bad:'#FF6B6B'};
function systemHealthLoad(){
  var box=document.getElementById('sys-health-list');
  if(!box)return;
  api('admin_system_health',{},function(d){
    var sum=document.getElementById('sys-health-sum');
    if(sum){
      sum.style.color=d.overall==='bad'?'#FF6B6B':(d.overall==='warn'?'#F0B84A':'#2ECC71');
      sum.textContent=d.overall==='ok'?'All good':(d.problems?d.problems+' problem'+(d.problems===1?'':'s'):'')+(d.problems&&d.warnings?' · ':'')+(d.warnings?d.warnings+' to check':'');
    }
    box.innerHTML=(d.checks||[]).map(function(c){
      return '<div style="display:flex;gap:10px;align-items:flex-start;padding:7px 0;border-top:1px solid rgba(255,255,255,.07)">'+
        '<span style="flex:none;width:10px;height:10px;border-radius:50%;margin-top:5px;background:'+(SH_DOT[c.status]||SH_DOT.ok)+'"></span>'+
        '<div style="flex:none;width:130px;color:#f1ece0;font-weight:700">'+esc(c.name)+'</div>'+
        '<div style="flex:1;min-width:0;color:#B8B0A0">'+esc(c.detail)+'</div></div>';
    }).join('');
  },function(m){box.textContent='Could not load: '+m;});
  if(!SH.timer){
    SH.timer=setInterval(function(){
      var el=document.getElementById('sys-health-list');
      if(document.hidden||!el||!el.offsetParent)return;
      systemHealthLoad();
    },60000);
  }
}
window.systemHealthLoad=systemHealthLoad;

/* ── Live trip watch: stuck trips right now (admin_ride_watch, read-only). Refreshes every 30 s while the Rides tab is on screen. ── */
var RW={timer:null};
var RW_TITLE={searching:'No driver',pickup:'Driver not at pickup',gps:'GPS silent',long:'Long trip',arrived:'Not confirmed',issue:'Needs review'};
function rwFmt(m){m=parseInt(m,10)||0;return m<60?m+' min':Math.floor(m/60)+' h '+(m%60)+' min';}
function rideWatchLoad(){
  var box=document.getElementById('ride-watch-list');
  if(!box)return;
  api('admin_ride_watch',{},function(d){
    var f=d.findings||[];
    var note=document.getElementById('ride-watch-note');
    var crit=f.filter(function(x){return x.severity==='critical';}).length;
    if(note)note.textContent=(d.active||0)+' active trips'+(d.api?' · API '+d.api.avg_ms+' ms avg, '+d.api.error_pct+'% errors (last '+d.api.window_min+' min)':'')+(d.sweeper_last_run?' · sweeper last ran '+String(d.sweeper_last_run).slice(11,16):'');
    if(!f.length){
      box.innerHTML='<div style="color:#2ECC71;font-weight:700">✓ All clear</div><div style="font-size:12px;color:#9A9AAE;margin-top:2px">No stuck trips right now.</div>';
      return;
    }
    box.innerHTML='<div style="margin-bottom:8px;font-weight:700;color:'+(crit?'#FF6B6B':'#F0B84A')+'">'+f.length+' problem'+(f.length===1?' needs':'s need')+' attention'+(crit?' · '+crit+' critical':'')+'</div>'+
      f.map(function(x){
        var c=x.severity==='critical'?'#FF6B6B':'#F0B84A';
        var who=[x.passenger?('Rider '+esc(x.passenger)+(x.passenger_phone?' '+esc(x.passenger_phone):'')):'',
                 x.driver?('Driver '+esc(x.driver)+(x.driver_phone?' '+esc(x.driver_phone):'')):''].filter(Boolean).join(' · ');
        var canCancel=x.ride_id>0&&x.rule!=='issue'&&x.rule!=='safety'&&x.status!=='arrived';
        return '<div style="display:flex;gap:10px;align-items:flex-start;padding:10px 0;border-top:1px solid rgba(255,255,255,.07)">'+
          '<span style="flex:none;margin-top:2px;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:800;text-transform:uppercase;color:#1a1408;background:'+c+'">'+x.severity+'</span>'+
          '<div style="flex:1;min-width:0"><div style="color:#f1ece0;font-weight:700">'+(x.ride_id>0?'#'+x.ride_id+' · ':'')+esc(x.title)+' <span style="color:#9A9AAE;font-weight:400">· '+rwFmt(x.minutes)+'</span></div>'+
          '<div style="font-size:12px;color:#B8B0A0">'+esc(x.detail)+'</div>'+
          (who?'<div style="font-size:12px;color:#9A9AAE;margin-top:2px">'+who+'</div>':'')+'</div>'+
          (canCancel?'<button class="rides-filter-btn" onclick="ridesCancel('+x.ride_id+');setTimeout(rideWatchLoad,800)">Cancel ride</button>':'')+
          '</div>';
      }).join('');
  },function(m){box.textContent='Could not load: '+m;});
  if(!RW.timer){
    RW.timer=setInterval(function(){
      if(document.hidden||!document.getElementById('ride-watch-list')||!document.getElementById('ride-watch-list').offsetParent)return;
      rideWatchLoad();
    },30000);
  }
}
window.rideWatchLoad=rideWatchLoad;

function ridesLoad(){
  if(!RS.statsLoaded)loadStats();
  systemHealthLoad();
  rideWatchLoad();
  loadRideIssues();
  loadVehicleChanges();
  loadFlaggedIdentities();
  loadPendingComplianceRequests();
  loadDriverCompliance();
  var tb=document.getElementById('rides-tbody');
  if(tb)tb.innerHTML='<tr><td colspan="8" style="padding:40px;text-align:center;color:#B8B0A0">Loading...</td></tr>';
  api('admin_get_rides',{status:RS.status,search:RS.search,limit:RS.limit,offset:RS.page*RS.limit},
    function(d){
      RS.rows=d.rides||[];RS.total=d.total||0;
      renderTable();renderPager();
      if(!RS.statsLoaded)loadStats();
    },
    function(msg){
      if(tb)tb.innerHTML='<tr><td colspan="8" style="padding:40px;text-align:center;color:#e74c3c">'+
        '<b>Could not load rides</b><br><small style="color:#B8B0A0">'+esc(msg||'API error — check console')+'</small>'+
        '<br><br><button onclick="ridesLoad()" style="padding:8px 18px;border-radius:8px;border:none;background:rgba(212,175,55,.15);color:#E5C76B;cursor:pointer;font-family:Outfit,sans-serif">&#8635; Retry</button></td></tr>';
    }
  );
}

function renderTable(){
  var tb=document.getElementById('rides-tbody'); if(!tb)return;
  if(!RS.rows.length){tb.innerHTML='<tr><td colspan="8" style="padding:40px;text-align:center;color:#B8B0A0">No rides found</td></tr>';return;}
  tb.innerHTML=RS.rows.map(function(r){
    var sl=SL[r.status]||[r.status,'rs-searching'];
    var fare=r.fare_paid>0?'ETB '+parseFloat(r.fare_paid).toFixed(0):
             r.fare_final>0?'~ETB '+parseFloat(r.fare_final).toFixed(0):
             r.fare_estimate>0?'~ETB '+parseFloat(r.fare_estimate).toFixed(0):'—';
    var pickup=(r.pickup_address||'').substring(0,30);
    var drop=(r.dropoff_address||'').substring(0,30);
    var route=pickup?(pickup+(drop?' \u2192 '+drop:'')):'—';
    var created=(r.created_at||'—').replace('T',' ').substring(0,16);
    var canCancel=(r.status==='searching'||r.status==='accepted');
    var detailsBtn='<button onclick="event.stopPropagation();ridesShowDetail('+r.id+')" style="padding:5px 10px;border-radius:8px;border:1px solid rgba(212,175,55,.4);background:rgba(212,175,55,.12);color:#E5C76B;font-size:11px;cursor:pointer;font-family:Outfit,sans-serif;margin-right:6px">Details</button>';
    var act=detailsBtn+(canCancel
      ?'<button onclick="event.stopPropagation();ridesCancel('+r.id+')" style="padding:5px 10px;border-radius:8px;border:1px solid rgba(230,57,70,.4);background:rgba(230,57,70,.1);color:#E74C3C;font-size:11px;cursor:pointer;font-family:Outfit,sans-serif">Cancel</button>'
      :'');
    return '<tr onclick="ridesShowDetail('+r.id+')" style="cursor:pointer">'+
      '<td style="color:#B8B0A0;font-size:11px">#'+r.id+'</td>'+
      '<td><span class="ride-status-pill '+sl[1]+'">'+sl[0]+'</span></td>'+
      '<td><div style="font-weight:700">'+esc(r.passenger_name||'—')+'</div><div style="font-size:11px;color:#B8B0A0">'+esc(r.passenger_phone||'')+'</div></td>'+
      '<td><div style="font-weight:700">'+esc(r.driver_name||'—')+'</div><div style="font-size:11px;color:#B8B0A0">'+esc(r.vehicle_plate||r.driver_phone||'')+'</div></td>'+
      '<td style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="'+esc(route)+'">'+esc(route)+'</td>'+
      '<td style="text-align:right;font-weight:800;color:#E5C76B">'+fare+'</td>'+
      '<td style="font-size:11px;color:#9A9AAE;white-space:nowrap">'+created+'</td>'+
      '<td style="text-align:center">'+act+'</td>'+
      '</tr>';
  }).join('');
}

function ridesShowDetail(id){
  var r=null;
  for(var i=0;i<RS.rows.length;i++){if(RS.rows[i].id==id){r=RS.rows[i];break;}}
  if(!r)return;
  var sl=SL[r.status]||[r.status,'rs-searching'];
  var fareRows=[
    ['Estimate', r.fare_estimate],
    ['Final', r.fare_final],
    ['Paid', r.fare_paid]
  ].filter(function(x){return x[1]>0;}).map(function(x){
    return '<div style="display:flex;justify-content:space-between;padding:4px 0;"><span style="color:#9A9AAE">'+x[0]+'</span><span style="color:#E5C76B;font-weight:700">ETB '+parseFloat(x[1]).toFixed(2)+'</span></div>';
  }).join('') || '<div style="color:#B8B0A0">No fare recorded</div>';
  var html=
    '<div id="ride-detail-overlay" onclick="if(event.target===this)ridesCloseDetail()" style="position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.75);display:flex;align-items:center;justify-content:center;padding:16px;">'+
      '<div style="background:linear-gradient(165deg,#130d05,#0a0703);border:1px solid rgba(212,175,55,.35);border-radius:14px;width:min(96vw,520px);max-height:88vh;overflow-y:auto;padding:20px;font-family:Outfit,sans-serif;">'+
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">'+
          '<div style="font-size:15px;font-weight:800;color:#D4AF37">Ride #'+r.id+'</div>'+
          '<button onclick="ridesCloseDetail()" style="background:none;border:1px solid #333;color:#B8B0A0;border-radius:6px;padding:4px 10px;cursor:pointer;">✕</button>'+
        '</div>'+
        '<div style="margin-bottom:12px;"><span class="ride-status-pill '+sl[1]+'">'+sl[0]+'</span>'+(r.open_trip==1?' <span style="font-size:11px;color:#B8B0A0;margin-left:6px;">Open trip (GPS meter)</span>':'')+'</div>'+
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">'+
          '<div><div style="font-size:10px;color:#B8B0A0;text-transform:uppercase;">Passenger</div><div style="color:#e8e2d4;font-weight:700">'+esc(r.passenger_name||'—')+'</div><div style="color:#9A9AAE;font-size:12px">'+esc(r.passenger_phone||'')+'</div></div>'+
          '<div><div style="font-size:10px;color:#B8B0A0;text-transform:uppercase;">Driver</div><div style="color:#e8e2d4;font-weight:700">'+esc(r.driver_name||'—')+'</div><div style="color:#9A9AAE;font-size:12px">'+esc(r.driver_phone||'')+(r.vehicle_plate?' · '+esc(r.vehicle_plate):'')+'</div></div>'+
        '</div>'+
        '<div style="margin-bottom:14px;">'+
          '<div style="font-size:10px;color:#B8B0A0;text-transform:uppercase;margin-bottom:4px;">Route</div>'+
          '<div style="color:#e8e2d4;font-size:13px;margin-bottom:3px;">📍 '+esc(r.pickup_address||'—')+'</div>'+
          '<div style="color:#e8e2d4;font-size:13px;">🏁 '+esc(r.dropoff_address||'—')+'</div>'+
        '</div>'+
        '<div style="display:flex;gap:20px;margin-bottom:14px;">'+
          '<div><div style="font-size:10px;color:#B8B0A0;text-transform:uppercase;">Distance</div><div style="color:#e8e2d4;">'+parseFloat(r.distance_km||r.traveled_km||0).toFixed(2)+' km</div></div>'+
          '<div><div style="font-size:10px;color:#B8B0A0;text-transform:uppercase;">Payment</div><div style="color:#e8e2d4;">'+esc(r.payment_method||'cash')+'</div></div>'+
          '<div><div style="font-size:10px;color:#B8B0A0;text-transform:uppercase;">Driver Rating</div><div style="color:#e8e2d4;">'+(r.driver_rating>0?'★ '+r.driver_rating:'—')+'</div></div>'+
        '</div>'+
        '<div style="border-top:1px solid rgba(212,175,55,.15);padding-top:10px;margin-bottom:14px;">'+fareRows+'</div>'+
        '<div style="font-size:11px;color:#B8B0A0;margin-bottom:'+((r.status==='in_progress'||r.status==='arrived')?'14px':'0')+'">Created '+(r.created_at||'—')+(r.completed_at?' · Completed '+r.completed_at:'')+'</div>'+
        // 'arrived' means driver_arrived already fired and computed a
        // fare from whatever GPS was tracked - but if that trip is stuck
        // because the passenger's side can never reach
        // customer_confirm_arrival (same dead connection that killed live
        // tracking), it needs the exact same rescue path as 'in_progress'.
        ((r.status==='in_progress'||r.status==='arrived')
          ?'<button onclick="ridesShowManualComplete('+r.id+')" style="width:100%;padding:10px;border-radius:9px;border:1px solid rgba(230,180,80,.4);background:rgba(230,180,80,.1);color:#E5C76B;font-size:12px;font-weight:700;cursor:pointer;font-family:Outfit,sans-serif">📞 Manually Complete (GPS/connection lost)</button>'
          :'')+
      '</div>'+
    '</div>';
  var wrap=document.createElement('div');
  wrap.id='ride-detail-wrap';
  wrap.innerHTML=html;
  document.body.appendChild(wrap);
}
function ridesCloseDetail(){
  var el=document.getElementById('ride-detail-wrap');
  if(el)el.remove();
}
window.ridesShowDetail=ridesShowDetail;
window.ridesCloseDetail=ridesCloseDetail;

/* ── Manually complete a trip whose GPS/connection died mid-ride ──
   Support-desk rescue path: the driver calls in, reads out the pickup
   point and where they actually dropped the passenger, an admin/support
   agent types both into the two address fields below (the same Google
   Places autocomplete every booking screen in this app already uses -
   ride.js's RIDE.autocompletePickup/autocompleteDropoff is the pattern
   this mirrors), and admin_manual_complete_ride (RideController.php)
   computes the real distance/fare from those two points and completes
   the trip through the same payout path every other completed ride
   uses. See that action's own header comment for the full rationale. */
var MC={pickup:null,dropoff:null,rideId:null,map:null,geocoder:null,pickupMarker:null,dropoffMarker:null,line:null,active:'pickup',waypoints:[],waypointMarkers:[]};

function _mcHaversineKm(a,b,c,d){ // mirrors rides_haversine_km (lib/ride_fare.php) exactly, so the
  var R=6371,r=Math.PI/180,dA=(c-a)*r,dB=(d-b)*r;               // live preview never disagrees with what
  var s=Math.sin(dA/2)*Math.sin(dA/2)+Math.cos(a*r)*Math.cos(c*r)*Math.sin(dB/2)*Math.sin(dB/2);
  return R*2*Math.atan2(Math.sqrt(s),Math.sqrt(1-s));
}

// Google appends .pac-container (the autocomplete suggestion dropdown)
// directly to <body>, completely outside this modal's own stacking
// context - every OTHER app in this codebase (ride/driver/courier/
// customer bundles) already had to style this explicitly for the same
// reason (see css/ride-bundle.css "GOOGLE PLACES AUTOCOMPLETE DROPDOWN"),
// but admin.html never has, because this is the first Autocomplete used
// anywhere in the admin dashboard. Left unstyled, it rendered BEHIND
// this modal's own z-index:10000 overlay - suggestions existed and were
// clickable in theory, just invisible, so search looked "dead" and an
// admin was left with raw unselected text (no lat/lng, nothing to
// calculate a distance from). Injected once, z-index above the modal.
function _mcEnsurePacStyle(){
  if(document.getElementById('mc-pac-style'))return;
  var s=document.createElement('style');
  s.id='mc-pac-style';
  s.textContent='.pac-container{background:#151016!important;border:1px solid rgba(212,175,55,.4)!important;'+
    'border-radius:12px!important;margin-top:6px!important;box-shadow:0 16px 40px -12px rgba(0,0,0,.7)!important;'+
    'font-family:Outfit,sans-serif!important;overflow:hidden!important;z-index:100002!important}'+
    '.pac-item{padding:10px 14px!important;border-top:1px solid rgba(255,255,255,.06)!important;'+
    'color:#EDEDF5!important;font-size:13px!important;cursor:pointer!important}'+
    '.pac-item:first-child{border-top:none!important}'+
    '.pac-item:hover,.pac-item-selected{background:rgba(212,175,55,.16)!important}'+
    '.pac-item-query{color:#F5EDD8!important;font-weight:700!important;font-size:13.5px!important}'+
    '.pac-matched{color:#FFD700!important;font-weight:700!important}'+
    '.pac-icon{filter:invert(1) brightness(1.6)!important;opacity:.7!important}'+
    '.pac-logo:after{filter:invert(1) brightness(1.8)!important;opacity:.5!important}';
  document.head.appendChild(s);
}

function ridesShowManualComplete(id){
  ridesCloseDetail();
  _mcEnsurePacStyle();
  MC={pickup:null,dropoff:null,rideId:id,map:null,geocoder:null,pickupMarker:null,dropoffMarker:null,line:null,active:'pickup',waypoints:[],waypointMarkers:[]};
  var html=
    '<div id="mc-overlay" onclick="if(event.target===this)ridesCloseManualComplete()" style="position:fixed;inset:0;z-index:10000;background:rgba(6,4,1,.86);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;padding:16px;">'+
      '<div style="background:linear-gradient(165deg,#171009,#0a0703);border:1px solid rgba(212,175,55,.4);border-radius:18px;width:min(96vw,580px);max-height:92vh;overflow-y:auto;font-family:Outfit,sans-serif;box-shadow:0 30px 90px -20px rgba(0,0,0,.8),0 0 0 1px rgba(212,175,55,.08) inset;">'+
        '<div style="padding:20px 22px 16px;background:linear-gradient(135deg,rgba(212,175,55,.14),transparent 70%);border-bottom:1px solid rgba(212,175,55,.18);display:flex;justify-content:space-between;align-items:flex-start;">'+
          '<div style="display:flex;gap:12px;align-items:center;">'+
            '<div style="width:40px;height:40px;flex:none;border-radius:11px;background:linear-gradient(135deg,#8a6410,#D4AF37);display:flex;align-items:center;justify-content:center;font-size:19px;box-shadow:0 6px 16px -4px rgba(212,175,55,.5)">📞</div>'+
            '<div>'+
              '<div style="font-size:16px;font-weight:800;color:#F2E4B8;letter-spacing:-.2px">Manually Complete Ride #'+id+'</div>'+
              '<div style="font-size:11px;color:#9A9AAE;margin-top:1px">GPS/connection-lost rescue path</div>'+
            '</div>'+
          '</div>'+
          '<button onclick="ridesCloseManualComplete()" style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);color:#B8B0A0;border-radius:8px;width:30px;height:30px;cursor:pointer;flex:none">✕</button>'+
        '</div>'+
        '<div style="padding:18px 22px 22px;">'+
        '<div style="font-size:12px;color:#9A9AAE;margin-bottom:16px;line-height:1.55">Call the driver, get the pickup point and where the passenger was actually dropped. Search each address below <b style="color:#c9bd9c">or tap the map</b> to place the pin precisely — add a stop if the driver went somewhere in between. The real distance and fare are calculated from every point in order, and the trip is completed immediately.</div>'+

        '<div style="display:flex;gap:8px;margin-bottom:10px">'+
          '<button type="button" id="mc-active-pickup" onclick="mcSetActive(\'pickup\')" style="flex:1;padding:8px 10px;border-radius:9px;border:1px solid rgba(46,204,113,.45);background:rgba(46,204,113,.16);color:#6EE7A8;font-size:11.5px;font-weight:700;cursor:pointer;font-family:Outfit,sans-serif;display:flex;align-items:center;justify-content:center;gap:6px">🟢 Tap map = Pickup</button>'+
          '<button type="button" id="mc-active-dropoff" onclick="mcSetActive(\'dropoff\')" style="flex:1;padding:8px 10px;border-radius:9px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.04);color:#9A9AAE;font-size:11.5px;font-weight:700;cursor:pointer;font-family:Outfit,sans-serif;display:flex;align-items:center;justify-content:center;gap:6px">🔴 Tap map = Drop-off</button>'+
        '</div>'+

        '<div style="margin-bottom:8px;position:relative;">'+
          '<label style="display:block;font-size:10px;color:#B8B0A0;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px">🟢 Pickup point</label>'+
          '<input id="mc-pickup" type="text" placeholder="Search pickup address..." style="width:100%;padding:10px 12px;border-radius:9px;border:1px solid rgba(46,204,113,.3);background:rgba(255,255,255,.05);color:#e8e2d4;font-size:13px;font-family:Outfit,sans-serif;box-sizing:border-box" autocomplete="off" onfocus="mcSetActive(\'pickup\')">'+
        '</div>'+

        '<div id="mc-waypoints-list"></div>'+
        '<button type="button" onclick="mcAddWaypoint()" style="width:100%;padding:7px;border-radius:8px;border:1px dashed rgba(212,175,55,.4);background:transparent;color:#c9bd9c;font-size:11.5px;font-weight:700;cursor:pointer;font-family:Outfit,sans-serif;margin-bottom:12px">+ Add stop between pickup and drop-off</button>'+

        '<div style="margin-bottom:12px;position:relative;">'+
          '<label style="display:block;font-size:10px;color:#B8B0A0;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px">🔴 Where the passenger was dropped off</label>'+
          '<input id="mc-dropoff" type="text" placeholder="Search drop-off address..." style="width:100%;padding:10px 12px;border-radius:9px;border:1px solid rgba(230,80,80,.3);background:rgba(255,255,255,.05);color:#e8e2d4;font-size:13px;font-family:Outfit,sans-serif;box-sizing:border-box" autocomplete="off" onfocus="mcSetActive(\'dropoff\')">'+
        '</div>'+

        '<div style="position:relative;border-radius:13px;overflow:hidden;border:1px solid rgba(212,175,55,.28);margin-bottom:10px;background:#10101c">'+
          '<div id="mc-map" style="width:100%;height:240px"></div>'+
          '<div id="mc-map-hint" style="position:absolute;left:10px;bottom:10px;padding:6px 11px;border-radius:8px;background:rgba(10,7,3,.82);backdrop-filter:blur(4px);color:#c9bd9c;font-size:11px;font-weight:600;pointer-events:none;border:1px solid rgba(212,175,55,.25)">Tap the map to drop the 🟢 pickup pin</div>'+
        '</div>'+

        '<div style="display:flex;gap:10px;margin-bottom:16px">'+
          '<div style="flex:1;background:rgba(212,175,55,.07);border:1px solid rgba(212,175,55,.18);border-radius:10px;padding:10px 12px;text-align:center">'+
            '<div style="font-size:9.5px;color:#9A9AAE;text-transform:uppercase;letter-spacing:.05em">Distance</div>'+
            '<div id="mc-distance-val" style="font-size:16px;font-weight:800;color:#E5C76B;margin-top:2px">—</div>'+
          '</div>'+
          '<div style="flex:1;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:10px 12px;text-align:center">'+
            '<div style="font-size:9.5px;color:#9A9AAE;text-transform:uppercase;letter-spacing:.05em">Route</div>'+
            '<div id="mc-route-val" style="font-size:12px;font-weight:700;color:#e8e2d4;margin-top:5px">Set both points</div>'+
          '</div>'+
        '</div>'+

        '<div style="margin-bottom:12px;"><label style="display:block;font-size:10px;color:#B8B0A0;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px">Payment method</label>'+
          '<select id="mc-payment" style="width:100%;padding:9px 12px;border-radius:8px;border:1px solid rgba(212,175,55,.3);background:rgba(255,255,255,.05);color:#e8e2d4;font-size:13px;font-family:Outfit,sans-serif;box-sizing:border-box">'+
            '<option value="cash">Cash (paid to driver directly)</option>'+
            '<option value="telebirr">Telebirr</option>'+
            '<option value="cbe">CBE</option>'+
          '</select>'+
        '</div>'+
        '<div style="margin-bottom:16px;"><label style="display:block;font-size:10px;color:#B8B0A0;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px">Note (optional)</label>'+
          '<textarea id="mc-note" rows="2" placeholder="e.g. driver\'s phone battery died, called from a borrowed phone" style="width:100%;padding:9px 12px;border-radius:8px;border:1px solid rgba(212,175,55,.3);background:rgba(255,255,255,.05);color:#e8e2d4;font-size:13px;font-family:Outfit,sans-serif;resize:vertical;box-sizing:border-box"></textarea>'+
        '</div>'+
        '<div id="mc-alert" style="display:none;padding:10px 12px;border-radius:8px;font-size:12px;margin-bottom:12px"></div>'+
        '<button id="mc-submit-btn" onclick="ridesSubmitManualComplete()" style="width:100%;padding:12px;border-radius:10px;border:none;background:linear-gradient(135deg,#8a6410,#D4AF37);color:#1a1200;font-weight:800;font-size:13.5px;cursor:pointer;font-family:Outfit,sans-serif;box-shadow:0 8px 20px -6px rgba(212,175,55,.5)">Calculate Fare &amp; Complete Trip</button>'+
        '</div>'+
      '</div>'+
    '</div>';
  var wrap=document.createElement('div');
  wrap.id='mc-wrap';
  wrap.innerHTML=html;
  document.body.appendChild(wrap);

  function initMcMap(){
    if(!(window.google&&google.maps&&google.maps.places)){
      setTimeout(initMcMap,400); // Maps JS API still loading (loadGoogleMaps() is async) - retry shortly
      return;
    }
    var pe=document.getElementById('mc-pickup'), de=document.getElementById('mc-dropoff'), mapEl=document.getElementById('mc-map');
    if(!pe||!de||!mapEl)return; // modal was closed before Maps finished loading

    MC.geocoder=new google.maps.Geocoder();
    MC.map=new google.maps.Map(mapEl,{
      center:{lat:7.0621,lng:38.4761}, zoom:13, mapId:'ETEL_RIDE_MAP',
      disableDefaultUI:false, mapTypeControl:false, streetViewControl:false,
      fullscreenControl:true, zoomControl:true, backgroundColor:'#10101c'
    });

    mcWireAutocomplete(pe,'pickup');
    mcWireAutocomplete(de,'dropoff');

    MC.map.addListener('click',function(e){
      var lat=e.latLng.lat(), lng=e.latLng.lng(), key=MC.active;
      mcSetPoint(key,lat,lng,lat.toFixed(5)+', '+lng.toFixed(5),true); // placeholder text, replaced once reverse-geocode resolves
      MC.geocoder.geocode({location:e.latLng},function(res,st){
        if(st==='OK'&&res&&res[0])mcSetPoint(key,lat,lng,res[0].formatted_address,false);
      });
    });
  }
  initMcMap();
}

// Shared by pickup/dropoff (fixed inputs in the markup) and every dynamically
// added stop input (mcAddWaypoint) - one Autocomplete instance per input,
// scoped to Ethiopia same as every other address field in this app.
function mcWireAutocomplete(inputEl,key){
  if(!inputEl||!(window.google&&google.maps&&google.maps.places))return;
  var ac=new google.maps.places.Autocomplete(inputEl,{componentRestrictions:{country:'ET'}});
  ac.addListener('place_changed',function(){
    var p=ac.getPlace();
    if(p&&p.geometry)mcSetPoint(key,p.geometry.location.lat(),p.geometry.location.lng(),p.formatted_address||p.name,false);
  });
}

// key is 'pickup', 'dropoff', or 'wp'+index (a stop between them).
window.mcSetActive=function(key){
  MC.active=key;
  var pb=document.getElementById('mc-active-pickup'), db=document.getElementById('mc-active-dropoff'), hint=document.getElementById('mc-map-hint');
  var offStyle='flex:1;padding:8px 10px;border-radius:9px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.04);color:#9A9AAE;font-size:11.5px;font-weight:700;cursor:pointer;font-family:Outfit,sans-serif;display:flex;align-items:center;justify-content:center;gap:6px';
  if(pb)pb.style.cssText=key==='pickup'
    ?'flex:1;padding:8px 10px;border-radius:9px;border:1px solid rgba(46,204,113,.45);background:rgba(46,204,113,.16);color:#6EE7A8;font-size:11.5px;font-weight:700;cursor:pointer;font-family:Outfit,sans-serif;display:flex;align-items:center;justify-content:center;gap:6px'
    :offStyle;
  if(db)db.style.cssText=key==='dropoff'
    ?'flex:1;padding:8px 10px;border-radius:9px;border:1px solid rgba(230,80,80,.45);background:rgba(230,80,80,.16);color:#F5A3A3;font-size:11.5px;font-weight:700;cursor:pointer;font-family:Outfit,sans-serif;display:flex;align-items:center;justify-content:center;gap:6px'
    :offStyle;
  if(hint){
    if(key==='pickup')hint.textContent='Tap the map to drop the 🟢 pickup pin';
    else if(key==='dropoff')hint.textContent='Tap the map to drop the 🔴 drop-off pin';
    else hint.textContent='Tap the map to drop the 🟡 stop '+(parseInt(key.slice(2),10)+1)+' pin';
  }
};

function mcSetPoint(key,lat,lng,addr,isPlaceholder){
  var isWp=(typeof key==='string'&&key.indexOf('wp')===0), wpIdx=isWp?parseInt(key.slice(2),10):-1;
  if(isWp){ MC.waypoints[wpIdx]={lat:lat,lng:lng,address:addr}; }
  else{ MC[key]={lat:lat,lng:lng,address:addr}; }

  var input=document.getElementById(isWp?'mc-wp-'+wpIdx:(key==='pickup'?'mc-pickup':'mc-dropoff'));
  if(input)input.value=addr;

  var color=key==='pickup'?'#2ECC71':(key==='dropoff'?'#E63946':'#D4AF37');
  var label=key==='pickup'?'P':(key==='dropoff'?'D':String(wpIdx+1));
  var existingMarker=isWp?MC.waypointMarkers[wpIdx]:MC[key==='pickup'?'pickupMarker':'dropoffMarker'];
  try{
    if(existingMarker){
      existingMarker.setPosition({lat:lat,lng:lng});
    }else if(MC.map){
      var marker=new google.maps.Marker({
        position:{lat:lat,lng:lng}, map:MC.map, draggable:true,
        label:{text:label,color:'#1a1200',fontWeight:'800',fontSize:'11px'},
        icon:{path:google.maps.SymbolPath.CIRCLE,fillColor:color,fillOpacity:1,strokeColor:'#fff',strokeWeight:2,scale:13}
      });
      marker.addListener('dragend',function(e){
        var la=e.latLng.lat(), ln=e.latLng.lng();
        mcSetPoint(key,la,ln,la.toFixed(5)+', '+ln.toFixed(5),true);
        MC.geocoder.geocode({location:e.latLng},function(res,st){
          if(st==='OK'&&res&&res[0])mcSetPoint(key,la,ln,res[0].formatted_address,false);
        });
      });
      if(isWp)MC.waypointMarkers[wpIdx]=marker; else MC[key==='pickup'?'pickupMarker':'dropoffMarker']=marker;
    }
  }catch(e){}
  if(MC.map)MC.map.panTo({lat:lat,lng:lng});
  // Autocomplete/click on pickup with drop-off still empty hands control
  // straight to drop-off next - the natural order for reading a trip out
  // over the phone. Doesn't override a deliberate manual re-tap.
  if(key==='pickup'&&!MC.dropoff&&!isPlaceholder)window.mcSetActive('dropoff');
  mcRedraw();
}

// Ordered list of every set point from pickup through each stop to
// drop-off - the single source of truth for both the polyline and the
// summed leg-by-leg distance, so the two can never disagree.
function mcOrderedPoints(){
  var pts=[];
  if(MC.pickup)pts.push(MC.pickup);
  MC.waypoints.forEach(function(w){if(w)pts.push(w);});
  if(MC.dropoff)pts.push(MC.dropoff);
  return pts;
}

window.mcAddWaypoint=function(){
  MC.waypoints.push(null);
  mcRenderWaypoints();
};

window.mcRemoveWaypoint=function(idx){
  try{if(MC.waypointMarkers[idx])MC.waypointMarkers[idx].setMap(null);}catch(e){}
  MC.waypoints.splice(idx,1);
  MC.waypointMarkers.splice(idx,1);
  // Every stop after the removed one shifts down a slot - relabel its
  // marker pin to match its new position number, not the one it had before.
  MC.waypointMarkers.forEach(function(m,i){
    if(!m)return;
    try{m.setLabel({text:String(i+1),color:'#1a1200',fontWeight:'800',fontSize:'11px'});}catch(e){}
  });
  if(MC.active==='wp'+idx)window.mcSetActive('dropoff');
  mcRenderWaypoints();
  mcRedraw();
};

function mcRenderWaypoints(){
  var list=document.getElementById('mc-waypoints-list');
  if(!list)return;
  var h='';
  MC.waypoints.forEach(function(wp,i){
    h+='<div style="margin-bottom:10px;position:relative">'+
      '<label style="display:block;font-size:10px;color:#B8B0A0;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px">🟡 Stop '+(i+1)+'</label>'+
      '<div style="display:flex;gap:6px">'+
        '<input id="mc-wp-'+i+'" type="text" placeholder="Search a stop along the way..." value="'+(wp?String(wp.address).replace(/"/g,'&quot;'):'')+'" style="flex:1;min-width:0;padding:10px 12px;border-radius:9px;border:1px solid rgba(212,175,55,.35);background:rgba(255,255,255,.05);color:#e8e2d4;font-size:13px;font-family:Outfit,sans-serif;box-sizing:border-box" autocomplete="off" onfocus="mcSetActive(\'wp'+i+'\')">'+
        '<button type="button" onclick="mcRemoveWaypoint('+i+')" title="Remove this stop" style="flex:none;width:38px;border-radius:9px;border:1px solid rgba(230,80,80,.3);background:rgba(230,80,80,.1);color:#F5A3A3;cursor:pointer;font-size:14px">✕</button>'+
      '</div>'+
    '</div>';
  });
  list.innerHTML=h;
  MC.waypoints.forEach(function(wp,i){
    mcWireAutocomplete(document.getElementById('mc-wp-'+i),'wp'+i);
  });
}

function mcRedraw(){
  var distEl=document.getElementById('mc-distance-val'), routeEl=document.getElementById('mc-route-val');
  try{if(MC.line){MC.line.setMap(null);MC.line=null;}}catch(e){}
  if(!MC.pickup||!MC.dropoff){
    if(distEl)distEl.textContent='—';
    if(routeEl)routeEl.textContent='Set both points';
    return;
  }
  var pts=mcOrderedPoints(), km=0;
  for(var i=1;i<pts.length;i++)km+=_mcHaversineKm(pts[i-1].lat,pts[i-1].lng,pts[i].lat,pts[i].lng);
  var stopCount=pts.length-2; // total points minus pickup and drop-off
  if(distEl)distEl.textContent=km.toFixed(2)+' km';
  if(routeEl)routeEl.textContent=stopCount>0?('🟢 → 🟡×'+stopCount+' → 🔴 via '+stopCount+' stop'+(stopCount===1?'':'s')):'🟢 → 🔴 direct line';
  try{
    MC.line=new google.maps.Polyline({
      path:pts.map(function(p){return {lat:p.lat,lng:p.lng};}),
      map:MC.map, strokeColor:'#E5C76B', strokeOpacity:.85, strokeWeight:3,
      icons:[{icon:{path:'M 0,-1 0,1',strokeOpacity:1,scale:3},offset:'0',repeat:'14px'}]
    });
    var b=new google.maps.LatLngBounds();
    pts.forEach(function(p){b.extend({lat:p.lat,lng:p.lng});});
    MC.map.fitBounds(b,64);
  }catch(e){}
}

function ridesCloseManualComplete(){
  var el=document.getElementById('mc-wrap');
  if(el)el.remove();
  MC.map=null;MC.pickupMarker=null;MC.dropoffMarker=null;MC.line=null;MC.geocoder=null;MC.waypointMarkers=[];
}

function _mcAlert(msg,ok){
  var a=document.getElementById('mc-alert');
  if(!a)return;
  a.style.display='block';
  a.style.background=ok?'rgba(46,204,113,.12)':'rgba(230,57,70,.12)';
  a.style.border='1px solid '+(ok?'rgba(46,204,113,.3)':'rgba(230,57,70,.3)');
  a.style.color=ok?'#2ECC71':'#E74C3C';
  a.textContent=msg;
}

function ridesSubmitManualComplete(){
  if(!MC.pickup||!MC.dropoff){
    _mcAlert('Pick a real suggestion from the dropdown for both the pickup and drop-off fields — typed text alone has no coordinates to calculate distance from.',false);
    return;
  }
  if(MC.waypoints.some(function(w){return !w;})){
    _mcAlert('Every stop needs a real suggestion picked from the dropdown (or a pin placed on the map) before you can complete the trip — remove any empty stop with ✕ if it\'s not needed.',false);
    return;
  }
  var btn=document.getElementById('mc-submit-btn');
  if(btn){btn.disabled=true;btn.textContent='Calculating…';}
  api('admin_manual_complete_ride',{
    ride_id: MC.rideId,
    pickup_lat: MC.pickup.lat, pickup_lng: MC.pickup.lng, pickup_address: MC.pickup.address,
    dropoff_lat: MC.dropoff.lat, dropoff_lng: MC.dropoff.lng, dropoff_address: MC.dropoff.address,
    waypoints: MC.waypoints.map(function(w){return {lat:w.lat,lng:w.lng,address:w.address};}),
    payment_method: (document.getElementById('mc-payment')||{value:'cash'}).value,
    note: (document.getElementById('mc-note')||{value:''}).value.trim(),
  },function(d){
    _mcAlert('Trip completed — '+parseFloat(d.distance_km).toFixed(2)+' km, ETB '+parseFloat(d.fare).toFixed(0)+' ('+d.payment_method+')',true);
    setTimeout(function(){ridesCloseManualComplete();ridesLoad();},1800);
  },function(msg){
    if(btn){btn.disabled=false;btn.textContent='Calculate Fare & Complete Trip';}
    _mcAlert(msg||'Could not complete the trip',false);
  });
}

window.ridesShowManualComplete=ridesShowManualComplete;
window.ridesCloseManualComplete=ridesCloseManualComplete;
window.ridesSubmitManualComplete=ridesSubmitManualComplete;

function renderPager(){
  var el=document.getElementById('rides-pagination'); if(!el)return;
  var pages=Math.ceil(RS.total/RS.limit);
  if(pages<=1){el.innerHTML='<span style="color:#B8B0A0;font-size:12px">'+RS.total+' rides</span>';return;}
  var h='<span style="color:#B8B0A0;font-size:12px">'+RS.total+' rides</span>&nbsp;&nbsp;';
  for(var i=0;i<pages;i++){
    var a=i===RS.page;
    h+='<button onclick="ridesGoPage('+i+')" style="padding:6px 12px;border-radius:8px;border:1px solid '+(a?'rgba(212,175,55,.5)':'rgba(255,255,255,.12)')+
      ';background:'+(a?'rgba(212,175,55,.15)':'rgba(255,255,255,.04)')+
      ';color:'+(a?'#E5C76B':'#9A9AAE')+
      ';font-family:Outfit,sans-serif;font-size:12px;cursor:pointer">'+(i+1)+'</button> ';
  }
  el.innerHTML=h;
}

function ridesFilter(status,btn){
  RS.status=status; RS.page=0;
  document.querySelectorAll('.rides-filter-btn').forEach(function(b){b.classList.remove('active');});
  if(btn)btn.classList.add('active');
  ridesLoad();
}

function ridesSearchDebounce(){
  clearTimeout(RS.timer);
  RS.timer=setTimeout(function(){
    RS.search=(document.getElementById('rides-search')||{value:''}).value.trim();
    RS.page=0; ridesLoad();
  },400);
}

function ridesGoPage(n){RS.page=n;ridesLoad();}

function ridesCancel(id){
  if(!confirm('Cancel ride #'+id+'? The passenger and driver will be notified.'))return;
  api('admin_cancel_ride',{ride_id:id},function(){ridesLoad();},function(m){alert('Cannot cancel: '+m);});
}

function ridesExport(){
  var hdrs=['ID','Status','Passenger','Phone','Driver','Driver Phone','Plate','Pickup','Dropoff','Distance','Fare','Paid','Payment','Created'];
  var rows=RS.rows.map(function(r){
    return [r.id,r.status,r.passenger_name,r.passenger_phone,r.driver_name,r.driver_phone,
      r.vehicle_plate,r.pickup_address,r.dropoff_address,r.distance_km,
      r.fare_final||r.fare_estimate,r.fare_paid,r.payment_method,r.created_at]
      .map(function(v){return '"'+String(v||'').replace(/"/g,'""')+'"';}).join(',');
  });
  var csv=hdrs.join(',')+'\n'+rows.join('\n');
  var a=document.createElement('a');
  a.href='data:text/csv;charset=utf-8,'+encodeURIComponent(csv);
  a.download='etel-rides-'+new Date().toISOString().slice(0,10)+'.csv';
  a.click();
}

window.ridesLoad=ridesLoad;
window.ridesFilter=ridesFilter;
window.ridesSearchDebounce=ridesSearchDebounce;
window.ridesGoPage=ridesGoPage;
window.ridesCancel=ridesCancel;
window.ridesExport=ridesExport;

/* ── Ride issue reports (was backend-only - admin_get_ride_issues and
   admin_resolve_ride_issue existed but nothing in admin.html ever called
   them, same gap the Pricing tab had before it got wired in). ── */
var RI_LABEL={open:['🟡 Open','#e65100','rgba(255,122,69,.15)'],resolved:['✅ Resolved','#1b7e3a','rgba(46,204,113,.15)']};

function loadRideIssues(){
  var wrap=document.getElementById('ride-issues-wrap');
  if(!wrap)return;
  wrap.innerHTML='<div style="padding:20px;text-align:center;color:#B8B0A0">Loading…</div>';
  api('admin_get_ride_issues',{},function(d){
    RI.rows=d.issues||[];
    renderRideIssues();
  },function(msg){
    wrap.innerHTML='<div style="padding:16px;color:#e74c3c">Could not load ride issues: '+esc(msg||'API error')+'</div>';
  });
}

function renderRideIssues(){
  var wrap=document.getElementById('ride-issues-wrap');
  if(!wrap)return;
  var rows=RI.rows.filter(function(r){
    if(RI.filter==='all')return true;
    return (r.status||'open')===RI.filter;
  });
  if(!rows.length){
    wrap.innerHTML='<div style="padding:24px;text-align:center;color:#B8B0A0">No '+(RI.filter==='all'?'':RI.filter+' ')+'ride issues.</div>';
    return;
  }
  var trs=rows.map(function(r){
    var label=RI_LABEL[r.status||'open']||RI_LABEL.open;
    return '<tr>'+
      '<td>'+(r.ride_id?'#'+r.ride_id:'—')+'</td>'+
      '<td>'+esc(r.reporter_name||'—')+'<br><small style="color:#9A9AAE">'+esc(r.reporter_phone||'')+'</small></td>'+
      '<td>'+esc(r.category||'—')+'</td>'+
      '<td style="max-width:260px;white-space:normal">'+esc(r.details||'—')+'</td>'+
      '<td><span style="display:inline-flex;padding:5px 10px;border-radius:6px;font-size:11px;font-weight:700;text-transform:uppercase;color:'+label[1]+';background:'+label[2]+';border:1px solid '+label[1]+'">'+label[0]+'</span></td>'+
      '<td>'+fmtTime(r.created_at)+'</td>'+
      '<td>'+((r.status||'open')==='open'
        ? '<button class="btn btn-primary" style="padding:6px 10px;font-size:12px" onclick="resolveRideIssue('+r.id+')">Mark resolved</button>'
        : '<span style="color:#9A9AAE;font-size:12px">'+fmtTime(r.resolved_at)+'</span>') +
      '</td>'+
      '</tr>';
  }).join('');
  wrap.innerHTML='<div style="overflow-x:auto;border-radius:14px;border:1px solid rgba(255,255,255,.08)">'+
    '<table style="width:100%;border-collapse:collapse;font-size:13px;font-family:Outfit,sans-serif">'+
    '<thead><tr style="border-bottom:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.04)">'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Ride</th>'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Reporter</th>'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Category</th>'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Details</th>'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Status</th>'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Reported</th>'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Action</th>'+
    '</tr></thead><tbody>'+trs+'</tbody></table></div>';
}

function ridesIssuesFilter(f,el){
  RI.filter=f;
  document.querySelectorAll('[data-issue-filter]').forEach(function(b){b.classList.remove('active');});
  if(el)el.classList.add('active');
  renderRideIssues();
}

function resolveRideIssue(id){
  if(!confirm('Mark this ride issue as resolved?'))return;
  api('admin_resolve_ride_issue',{id:id},function(){
    var r=RI.rows.find(function(x){return x.id===id;});
    if(r){r.status='resolved';r.resolved_at=new Date().toISOString();}
    renderRideIssues();
  },function(msg){alert('Could not resolve: '+(msg||'unknown error'));});
}

function loadVehicleChanges(){
  var wrap=document.getElementById('vehicle-change-wrap');
  if(!wrap)return;
  wrap.innerHTML='<div style="padding:20px;text-align:center;color:#B8B0A0">Loading…</div>';
  api('admin_get_pending_vehicle_changes',{},function(d){
    VC.rows=d.requests||[];
    renderVehicleChanges();
  },function(msg){
    wrap.innerHTML='<div style="padding:16px;color:#e74c3c">Could not load pending vehicle changes: '+esc(msg||'API error')+'</div>';
  });
}

function renderVehicleChanges(){
  var wrap=document.getElementById('vehicle-change-wrap');
  if(!wrap)return;
  if(!VC.rows.length){
    wrap.innerHTML='<div style="padding:24px;text-align:center;color:#B8B0A0">No pending vehicle changes.</div>';
    return;
  }
  var trs=VC.rows.map(function(r){
    return '<tr>'+
      '<td>'+esc(r.driver_name||'—')+'<br><small style="color:#9A9AAE">'+esc(r.driver_phone||'')+'</small></td>'+
      '<td style="color:#9A9AAE">'+esc(r.current_vehicle_type||'—')+' · '+esc(r.current_vehicle_make||'')+' · '+esc(r.current_vehicle_plate||'')+'</td>'+
      '<td style="color:#f0cf5a;font-weight:600">'+esc(r.requested_vehicle_type||'—')+' · '+esc(r.requested_vehicle_make||'')+' · '+esc(r.requested_vehicle_color||'')+' · '+esc(r.requested_vehicle_year||'')+' · '+esc(r.requested_vehicle_plate||'')+'</td>'+
      '<td>'+fmtTime(r.created_at)+'</td>'+
      '<td style="white-space:nowrap">'+
        '<button class="btn btn-primary" style="padding:6px 10px;font-size:12px;margin-right:6px" onclick="approveVehicleChange('+r.id+')">Approve</button>'+
        '<button class="btn" style="padding:6px 10px;font-size:12px;background:rgba(231,76,60,.12);color:#e74c3c" onclick="rejectVehicleChange('+r.id+')">Reject</button>'+
      '</td>'+
      '</tr>';
  }).join('');
  wrap.innerHTML='<div style="overflow-x:auto;border-radius:14px;border:1px solid rgba(255,255,255,.08)">'+
    '<table style="width:100%;border-collapse:collapse;font-size:13px;font-family:Outfit,sans-serif">'+
    '<thead><tr style="border-bottom:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.04)">'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Driver</th>'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Current vehicle</th>'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Requested vehicle</th>'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Requested</th>'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Action</th>'+
    '</tr></thead><tbody>'+trs+'</tbody></table></div>';
}

function approveVehicleChange(id){
  if(!confirm('Approve this vehicle change? It will immediately update the map, ride previews, and the driver\'s profile everywhere.'))return;
  api('admin_approve_vehicle_change',{request_id:id},function(){
    VC.rows=VC.rows.filter(function(x){return x.id!==id;});
    renderVehicleChanges();
  },function(msg){alert('Could not approve: '+(msg||'unknown error'));});
}

function rejectVehicleChange(id){
  var reason=prompt('Reason for rejecting this vehicle change (shown to the driver, optional):','');
  if(reason===null)return;
  api('admin_reject_vehicle_change',{request_id:id,reason:reason},function(){
    VC.rows=VC.rows.filter(function(x){return x.id!==id;});
    renderVehicleChanges();
  },function(msg){alert('Could not reject: '+(msg||'unknown error'));});
}

/* ── Identity mismatch review ──────────────────────────────────────
   Registration flags a driver as identity_match='mismatch' and tells
   them "flagged for admin review before you can go online" - that
   block is now actually enforced server-side (driver_go_online), but
   there was previously no way for an admin to ever see or clear the
   flag at all. Modeled directly on the vehicle-change review pattern
   right above. */
var FI={rows:[]};
async function loadFlaggedIdentities(){
  var wrap=document.getElementById('identity-flagged-wrap');
  if(!wrap)return;
  // Requires users.manage server-side (admin_get_flagged_identities) -
  // Dispatcher and other lower roles only get users.view, so this used
  // to fire anyway and render a raw "you don't have permission" error
  // in red text on the Rides tab. Awaits the shared caps promise
  // (admin-roles.js) rather than reading window.ETEL_CAPS directly -
  // Rides is the default landing tab, so this init can run BEFORE that
  // async capabilities fetch resolves; reading the plain variable here
  // raced it and saw null, silently failing open every time.
  var caps = window.ETEL_CAPS || await window.ETEL_CAPS_PROMISE;
  if (caps.indexOf('*')===-1 && caps.indexOf('users.manage')===-1) {
    wrap.style.display='none';
    var heading = wrap.previousElementSibling;
    if (heading) heading.style.display='none';
    return;
  }
  wrap.innerHTML='<div style="padding:20px;text-align:center;color:#B8B0A0">Loading…</div>';
  api('admin_get_flagged_identities',{},function(d){
    FI.rows=d.drivers||[];
    renderFlaggedIdentities();
  },function(msg){
    wrap.innerHTML='<div style="padding:16px;color:#e74c3c">Could not load flagged accounts: '+esc(msg||'API error')+'</div>';
  });
}
function renderFlaggedIdentities(){
  var wrap=document.getElementById('identity-flagged-wrap');
  if(!wrap)return;
  if(!FI.rows.length){
    wrap.innerHTML='<div style="padding:24px;text-align:center;color:#B8B0A0">No accounts currently flagged.</div>';
    return;
  }
  var trs=FI.rows.map(function(r){
    var photos='';
    if(r.selfie_url)photos+='<a href="'+esc(r.selfie_url)+'" target="_blank" style="color:#4ECDC4;font-size:11.5px;margin-right:10px">📷 Selfie</a>';
    if(r.license_photo_url)photos+='<a href="'+esc(r.license_photo_url)+'" target="_blank" style="color:#4ECDC4;font-size:11.5px">🪪 License</a>';
    return '<tr>'+
      '<td>'+esc(r.name||'—')+'<br><small style="color:#9A9AAE">'+esc(r.phone||'')+'</small></td>'+
      '<td style="color:#f0cf5a">'+esc(r.license_name||'—')+'<br><small style="color:#9A9AAE">'+esc(r.license_number||'')+' · '+esc(r.license_dob||'')+'</small></td>'+
      '<td style="color:#9A9AAE">'+esc(r.vehicle_type||'—')+' · '+esc(r.vehicle_plate||'')+'</td>'+
      '<td>'+photos+'</td>'+
      '<td>'+fmtTime(r.created_at)+'</td>'+
      '<td style="white-space:nowrap">'+
        '<button class="btn btn-primary" style="padding:6px 10px;font-size:12px;margin-right:6px" onclick="approveDriverIdentity('+r.id+')">Approve</button>'+
        '<button class="btn" style="padding:6px 10px;font-size:12px;background:rgba(231,76,60,.12);color:#e74c3c" onclick="rejectDriverIdentity('+r.id+')">Reject &amp; Suspend</button>'+
      '</td>'+
      '</tr>';
  }).join('');
  wrap.innerHTML='<div style="overflow-x:auto;border-radius:14px;border:1px solid rgba(255,255,255,.08)">'+
    '<table style="width:100%;border-collapse:collapse;font-size:13px;font-family:Outfit,sans-serif">'+
    '<thead><tr style="border-bottom:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.04)">'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Driver account</th>'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">License on file</th>'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Vehicle</th>'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Documents</th>'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Registered</th>'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Action</th>'+
    '</tr></thead><tbody>'+trs+'</tbody></table></div>';
}
function approveDriverIdentity(id){
  if(!confirm('Approve this account? License name/DOB will be treated as matching and the driver can go online immediately.'))return;
  api('admin_approve_driver_identity',{user_id:id},function(){
    FI.rows=FI.rows.filter(function(x){return x.id!==id;});
    renderFlaggedIdentities();
  },function(msg){alert('Could not approve: '+(msg||'unknown error'));});
}
function rejectDriverIdentity(id){
  var reason=prompt('Reason for rejecting (this suspends the account immediately):','License identity did not match EtelID record.');
  if(reason===null)return;
  api('admin_reject_driver_identity',{user_id:id,reason:reason},function(){
    FI.rows=FI.rows.filter(function(x){return x.id!==id;});
    renderFlaggedIdentities();
  },function(msg){alert('Could not reject: '+(msg||'unknown error'));});
}

/* ── Driver compliance (association dues / insurance) ───────────────
   Feeds the Traffic Officer NFC "Driver Info" check. Same review-panel
   pattern as flagged identities right above, gated on the same
   users.manage capability since this is a management action, not a
   view - the Traffic Officer role only gets a separate, narrower
   drivers.compliance.view capability checked server-side by
   nfc_driver_status, and never sees this admin panel at all. */
/* ── Pending compliance submissions (driver self-reported) ──────────
   A driver can submit their own document expiry date + photo directly
   from their app (driver_submit_compliance_document) - this queue is
   the review step before it becomes what a Traffic Officer sees.
   Same review-panel pattern as flagged identities/vehicle changes. */
var CR={rows:[]};
async function loadPendingComplianceRequests(){
  var wrap=document.getElementById('compliance-pending-wrap');
  if(!wrap)return;
  var caps = window.ETEL_CAPS || await window.ETEL_CAPS_PROMISE;
  if (caps.indexOf('*')===-1 && caps.indexOf('users.manage')===-1) {
    wrap.style.display='none';
    var heading = wrap.previousElementSibling;
    if (heading) heading.style.display='none';
    return;
  }
  wrap.innerHTML='<div style="padding:20px;text-align:center;color:#B8B0A0">Loading…</div>';
  api('admin_get_pending_compliance_requests',{},function(d){
    CR.rows=d.requests||[];
    renderPendingComplianceRequests();
  },function(msg){
    wrap.innerHTML='<div style="padding:16px;color:#e74c3c">Could not load submissions: '+esc(msg||'API error')+'</div>';
  });
}
function renderPendingComplianceRequests(){
  var wrap=document.getElementById('compliance-pending-wrap');
  if(!wrap)return;
  if(!CR.rows.length){
    wrap.innerHTML='<div style="padding:24px;text-align:center;color:#B8B0A0">No pending submissions.</div>';
    return;
  }
  var docLabels={insurance:'Insurance',technical_inspection:'Technical Inspection',road_fund:'Road Fund'};
  var trs=CR.rows.map(function(r){
    var photo=r.requested_receipt_url?'<a href="'+esc(r.requested_receipt_url)+'" target="_blank" style="color:#4ECDC4;font-size:11.5px">📷 Photo</a>':'—';
    return '<tr>'+
      '<td>'+esc(r.name||'—')+'<br><small style="color:#9A9AAE">'+esc(r.phone||'')+'</small></td>'+
      '<td>'+esc(docLabels[r.doc_type]||r.doc_type)+'</td>'+
      '<td style="color:#f0cf5a">'+esc(r.requested_expiry_date||'')+'</td>'+
      '<td>'+photo+'</td>'+
      '<td>'+fmtTime(r.created_at)+'</td>'+
      '<td style="white-space:nowrap">'+
        '<button class="btn btn-primary" style="padding:6px 10px;font-size:12px;margin-right:6px" onclick="approveComplianceRequest('+r.id+')">Approve</button>'+
        '<button class="btn" style="padding:6px 10px;font-size:12px;background:rgba(231,76,60,.12);color:#e74c3c" onclick="rejectComplianceRequest('+r.id+')">Reject</button>'+
      '</td>'+
      '</tr>';
  }).join('');
  wrap.innerHTML='<div style="overflow-x:auto;border-radius:14px;border:1px solid rgba(255,255,255,.08)">'+
    '<table style="width:100%;border-collapse:collapse;font-size:13px;font-family:Outfit,sans-serif">'+
    '<thead><tr style="border-bottom:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.04)">'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Driver</th>'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Document</th>'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Requested Expiry</th>'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Photo</th>'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Submitted</th>'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Action</th>'+
    '</tr></thead><tbody>'+trs+'</tbody></table></div>';
}
function approveComplianceRequest(id){
  if(!confirm('Approve this document? It becomes visible on the Traffic Officer NFC check immediately.'))return;
  api('admin_approve_compliance_document',{request_id:id},function(){
    CR.rows=CR.rows.filter(function(x){return x.id!==id;});
    renderPendingComplianceRequests();
    loadDriverCompliance();
  },function(msg){alert('Could not approve: '+(msg||'unknown error'));});
}
function rejectComplianceRequest(id){
  var reason=prompt('Reason for rejecting (shown to the driver):','Photo unclear - please resubmit.');
  if(reason===null)return;
  api('admin_reject_compliance_document',{request_id:id,reason:reason},function(){
    CR.rows=CR.rows.filter(function(x){return x.id!==id;});
    renderPendingComplianceRequests();
  },function(msg){alert('Could not reject: '+(msg||'unknown error'));});
}
window.loadPendingComplianceRequests=loadPendingComplianceRequests;
window.approveComplianceRequest=approveComplianceRequest;
window.rejectComplianceRequest=rejectComplianceRequest;

/* Association dues are NOT managed here at all - nfc_driver_status
   checks EtelTaxi's own live database instead (see taxi_bridge.php),
   since EtelTaxi is the actual system of record for that. This panel
   only manages the three physical documents Delivery has no other
   source for: insurance, technical inspection, and Road Fund sticker -
   each by real expiry date (matching what's printed on the actual
   certificate/sticker), not a manually-flipped paid/unpaid switch. */
var DC={rows:[]};
var DC_DOCS=[
  {key:'insurance',label:'Insurance'},
  {key:'technical_inspection',label:'Technical Inspection'},
  {key:'road_fund',label:'Road Fund'}
];
async function loadDriverCompliance(){
  var wrap=document.getElementById('driver-compliance-wrap');
  if(!wrap)return;
  var caps = window.ETEL_CAPS || await window.ETEL_CAPS_PROMISE;
  if (caps.indexOf('*')===-1 && caps.indexOf('users.manage')===-1) {
    wrap.style.display='none';
    var heading = wrap.previousElementSibling;
    if (heading) heading.style.display='none';
    return;
  }
  wrap.innerHTML='<div style="padding:20px;text-align:center;color:#B8B0A0">Loading…</div>';
  api('admin_list_driver_compliance',{},function(d){
    DC.rows=d.drivers||[];
    renderDriverCompliance();
  },function(msg){
    wrap.innerHTML='<div style="padding:16px;color:#e74c3c">Could not load driver compliance: '+esc(msg||'API error')+'</div>';
  });
}
function dcDocCell(r,doc){
  var expiry=r[doc.key+'_expiry_date'];
  var receipt=r[doc.key+'_receipt_url'];
  var badge;
  if(!expiry){
    badge='<span style="color:#9A9AAE">Not set</span>';
  }else{
    var today=new Date().toISOString().slice(0,10);
    var valid=expiry>=today;
    badge='<span style="color:'+(valid?'#2ECC71':'#e74c3c')+'">'+(valid?'Valid':'EXPIRED')+' until '+esc(expiry)+'</span>';
  }
  if(receipt)badge+=' <a href="'+esc(receipt)+'" target="_blank" style="color:#4ECDC4;font-size:11px">photo</a>';
  return '<td>'+badge+'<br><button class="btn" style="padding:4px 8px;font-size:11px;margin-top:4px" onclick="dcSetCompliance('+r.user_id+',\''+doc.key+'\')">Set</button></td>';
}
function renderDriverCompliance(){
  var wrap=document.getElementById('driver-compliance-wrap');
  if(!wrap)return;
  if(!DC.rows.length){
    wrap.innerHTML='<div style="padding:24px;text-align:center;color:#B8B0A0">No onboarded drivers yet.</div>';
    return;
  }
  var trs=DC.rows.map(function(r){
    return '<tr>'+
      '<td>'+esc(r.name||'—')+'<br><small style="color:#9A9AAE">'+esc(r.phone||'')+'</small></td>'+
      '<td style="color:#9A9AAE">'+esc(r.vehicle_type||'—')+' · '+esc(r.vehicle_plate||'')+'</td>'+
      DC_DOCS.map(function(doc){return dcDocCell(r,doc);}).join('')+
      '</tr>';
  }).join('');
  wrap.innerHTML='<div style="overflow-x:auto;border-radius:14px;border:1px solid rgba(255,255,255,.08)">'+
    '<table style="width:100%;border-collapse:collapse;font-size:13px;font-family:Outfit,sans-serif">'+
    '<thead><tr style="border-bottom:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.04)">'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Driver</th>'+
    '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Vehicle</th>'+
    DC_DOCS.map(function(doc){return '<th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">'+esc(doc.label)+'</th>';}).join('')+
    '</tr></thead><tbody>'+trs+'</tbody></table></div>'+
    '<div style="font-size:11px;color:#9A9AAE;margin-top:8px">Association dues are not set here - they come live from EtelTaxi\'s own records when a Traffic Officer checks a card.</div>';
}
function dcApplyCompliance(userId,docKey,expiryDate,receiptUrl){
  var body={user_id:userId};
  body[docKey+'_expiry_date']=expiryDate;
  if(receiptUrl)body[docKey+'_receipt_url']=receiptUrl;
  api('admin_update_driver_compliance',body,function(){
    loadDriverCompliance();
  },function(msg){alert('Could not update: '+(msg||'unknown error'));});
}
function dcSetCompliance(userId,docKey){
  var expiryDate=prompt('Expiry date for this document (YYYY-MM-DD), or leave blank to clear:','');
  if(expiryDate===null)return;
  expiryDate=expiryDate.trim();
  if(expiryDate!==''&&!/^\d{4}-\d{2}-\d{2}$/.test(expiryDate)){alert('Enter a date as YYYY-MM-DD, e.g. 2027-06-26.');return;}

  if(expiryDate===''||!confirm('Attach a photo of this document now? Cancel to save the date without one.')){
    dcApplyCompliance(userId,docKey,expiryDate,'');
    return;
  }
  var input=document.getElementById('dc-file-input');
  if(!input){
    input=document.createElement('input');
    input.type='file';
    input.accept='image/*';
    input.id='dc-file-input';
    input.style.display='none';
    document.body.appendChild(input);
  }
  input.onchange=function(){
    var file=input.files&&input.files[0];
    input.value='';
    if(!file){dcApplyCompliance(userId,docKey,expiryDate,'');return;}
    var token=typeof Auth!=='undefined'?Auth.getToken():'';
    var uploadBase=(window.ETEL&&window.ETEL.api)?window.ETEL.api.replace('boot.php','upload.php'):'/delivery/api/upload.php';
    var fd=new FormData();
    fd.append('image',file);
    fd.append('type','driver_'+docKey+'_photo');
    fetch(uploadBase,{method:'POST',headers:{'Authorization':'Bearer '+token},body:fd})
      .then(function(r){return r.json();})
      .then(function(d){
        if(!d||!d.success)throw new Error(d&&d.message?d.message:'Upload failed');
        dcApplyCompliance(userId,docKey,expiryDate,d.data.url);
      })
      .catch(function(e){alert('Photo upload failed: '+(e.message||'unknown error'));});
  };
  input.click();
}

window.ridesIssuesFilter=ridesIssuesFilter;
window.resolveRideIssue=resolveRideIssue;
window.approveVehicleChange=approveVehicleChange;
window.rejectVehicleChange=rejectVehicleChange;
window.loadFlaggedIdentities=loadFlaggedIdentities;
window.approveDriverIdentity=approveDriverIdentity;
window.rejectDriverIdentity=rejectDriverIdentity;
window.loadDriverCompliance=loadDriverCompliance;
window.dcSetCompliance=dcSetCompliance;

/* Auto-load stats when the rides panel becomes active.
   Called both by switchPage and by a MutationObserver watching display changes. */
function watchRidesPanel() {
    var panel = document.getElementById('panel-rides');
    if (!panel) return;
    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
            if (m.attributeName === 'style') {
                var visible = panel.style.display !== 'none';
                if (visible && !RS.statsLoaded) {
                    setTimeout(ridesLoad, 120);
                    // Growth chart isn't covered by RS.statsLoaded (that flag
                    // belongs to the rides table/stats-strip only) - fire it
                    // unconditionally whenever the panel becomes visible.
                    // This is the ONLY path that runs when Rides is the
                    // default active tab on initial page load - the
                    // admin-ui.js nav-click handler that also calls
                    // growthLoad() only fires on a manual tab switch, which
                    // never happens if the page opens straight to Rides.
                    if (typeof growthLoad === 'function') setTimeout(growthLoad, 120);
                }
            }
        });
    });
    observer.observe(panel, { attributes: true, attributeFilter: ['style'] });
}
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(watchRidesPanel, 500);
});
})();

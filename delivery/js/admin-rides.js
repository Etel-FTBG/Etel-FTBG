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

function ridesLoad(){
  if(!RS.statsLoaded)loadStats();
  loadRideIssues();
  loadVehicleChanges();
  loadFlaggedIdentities();
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
        '<div style="font-size:11px;color:#B8B0A0;">Created '+(r.created_at||'—')+(r.completed_at?' · Completed '+r.completed_at:'')+'</div>'+
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

window.ridesIssuesFilter=ridesIssuesFilter;
window.resolveRideIssue=resolveRideIssue;
window.approveVehicleChange=approveVehicleChange;
window.rejectVehicleChange=rejectVehicleChange;
window.loadFlaggedIdentities=loadFlaggedIdentities;
window.approveDriverIdentity=approveDriverIdentity;
window.rejectDriverIdentity=rejectDriverIdentity;

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

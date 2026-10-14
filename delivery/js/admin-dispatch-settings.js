/* ETEL Admin - Couriers page: take an order back from a courier who never started it (see api/lib/courier_stale.php). */
(function(){
'use strict';
function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function api(action,body){
  var token=typeof Auth!=='undefined'?Auth.getToken():'';
  var apiUrl=(window.ETEL&&window.ETEL.api)?window.ETEL.api:'/delivery/api/boot.php';
  return fetch(apiUrl,{method:'POST',headers:{'Content-Type':'application/json','Authorization':'Bearer '+token},body:JSON.stringify(Object.assign({action:action},body||{}))}).then(function(r){return r.json();});
}
function card(){
  var host=document.getElementById('co-offers-card'); if(!host) return null;
  var el=document.getElementById('stale-assign-card');
  if(!el){
    el=document.createElement('div'); el.id='stale-assign-card';
    el.style.cssText='margin-bottom:18px;border:1px solid rgba(212,175,55,.25);border-radius:14px;padding:14px 16px';
    host.parentNode.insertBefore(el,host.nextSibling);
  }
  return el;
}
function render(d){
  var el=card(); if(!el) return;
  el.innerHTML='<div style="font-weight:700;margin-bottom:4px">Orders nobody starts</div>'+
    '<div style="font-size:12px;opacity:.8;margin-bottom:10px">If a courier is given an order and has not accepted or picked it up after this many minutes, the order is taken back and offered to the next nearest courier (never the same one twice). When every courier has had it, it goes to the open job list and the system health page flags it.</div>'+
    '<label style="font-size:12px;display:block;margin-bottom:10px">Minutes before an order is taken back (0 = off, else 10-180)<input id="sa-min" type="number" min="0" max="180" step="5" class="filter-sel" style="width:100%;max-width:220px;display:block;margin-top:4px"></label>'+
    '<label style="font-size:12px;display:block;margin-bottom:10px">Hide an order nobody accepted from couriers after this many minutes (0 = never hide, else 30-1440). It is not cancelled; staff still see it in Needs attention.<input id="sa-age" type="number" min="0" max="1440" step="30" class="filter-sel" style="width:100%;max-width:220px;display:block;margin-top:4px"></label>'+
    '<label style="font-size:12px;display:block;margin-bottom:10px">Take back an order a courier accepted but has not picked up after this many minutes, when that courier has also gone quiet (0 = off, else 20-360). A courier who is online and just waiting at the shop is left alone.<input id="sa-pickup" type="number" min="0" max="360" step="10" class="filter-sel" style="width:100%;max-width:220px;display:block;margin-top:4px"></label>'+
    '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><button class="btn btn-primary" id="sa-save" style="padding:8px 16px">💾 Save</button><span id="sa-msg" style="font-size:12px;opacity:.85"></span></div>';
  document.getElementById('sa-min').value=d.minutes;
  document.getElementById('sa-age').value=(d.job_max_age==null?120:d.job_max_age);
  document.getElementById('sa-pickup').value=(d.pickup_minutes==null?60:d.pickup_minutes);
  document.getElementById('sa-msg').textContent=(d.minutes>0?'On. ':'Off. ')+'Taken back in the last 7 days: '+(d.taken_back_7d||0)+'.';
  document.getElementById('sa-save').onclick=function(){
    var msg=document.getElementById('sa-msg'); msg.textContent='Saving…';
    api('admin_save_stale_assign',{minutes:document.getElementById('sa-min').value,job_max_age:document.getElementById('sa-age').value,pickup_minutes:document.getElementById('sa-pickup').value}).then(function(r){
      if(!r.success){msg.textContent=r.message||'Could not save.';return;}
      document.getElementById('sa-min').value=r.data.minutes;
      if(r.data.job_max_age!=null)document.getElementById('sa-age').value=r.data.job_max_age;
      if(r.data.pickup_minutes!=null)document.getElementById('sa-pickup').value=r.data.pickup_minutes;
      msg.textContent=(r.data.minutes>0?'Saved. Orders are taken back after '+r.data.minutes+' minutes.':'Saved. Taking orders back is off.')+(r.data.job_max_age>0?' Old jobs are hidden after '+r.data.job_max_age+' minutes.':' Old jobs are never hidden.')+(r.data.pickup_minutes>0?' Accepted orders are taken back after '+r.data.pickup_minutes+' minutes if the courier is quiet.':' Accepted orders are never taken back.');
    }).catch(function(e){msg.textContent=e.message;});
  };
}
function load(){
  var el=card(); if(!el) return;
  api('admin_get_stale_assign').then(function(r){ if(r.success) render(r.data); else el.innerHTML='<div style="font-size:12px;opacity:.7">Orders nobody starts: '+esc(r.message||'could not load')+'</div>'; })
   .catch(function(e){el.innerHTML='<div style="font-size:12px">'+esc(e.message)+'</div>';});
}
function watch(){
  var panel=document.getElementById('panel-couriers'); if(!panel) return;
  var last=false;
  function check(){var vis=panel.style.display!=='none'&&panel.offsetParent!==null; if(vis&&!last)load(); last=vis;}
  new MutationObserver(check).observe(panel,{attributes:true,attributeFilter:['style','class']});
  check();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',watch);else watch();
})();

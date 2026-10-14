/* ETEL Admin - Pricing page: Courier pay card. The admin controls how couriers are paid and how tips work.
   Backend: api/controllers/CourierPayController.php, api/lib/courier_pay.php. */
(function(){
'use strict';
function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function api(action,body){
  var token=typeof Auth!=='undefined'?Auth.getToken():'';
  var apiUrl=(window.ETEL&&window.ETEL.api)?window.ETEL.api:'/delivery/api/boot.php';
  return fetch(apiUrl,{method:'POST',headers:{'Content-Type':'application/json','Authorization':'Bearer '+token},body:JSON.stringify(Object.assign({action:action},body||{}))}).then(function(r){return r.json();});
}
var FIELDS=[
  ['base','Base pay per order (ETB)','0'],['per_km','Pay per km (ETB)','0'],['pickup_cap_km','Most km counted to the shop','0'],
  ['min','Minimum pay per order (ETB)','0'],['road_factor','Road distance vs straight line (1 to 2)','1'],['night_mult','Night multiplier (1 to 3)','1'],
  ['night_start','Night starts at hour (0-23)','0'],['night_end','Night ends at hour (0-23)','0'],
  ['tip_max','Largest tip a customer can add (ETB)','0'],['tip_presets','Tip buttons at checkout (ETB, comma separated)','']
];
function card(){
  var panel=document.getElementById('panel-pricing'); if(!panel) return null;
  var el=document.getElementById('cpay-card');
  if(!el){
    el=document.createElement('div'); el.id='cpay-card';
    el.style.cssText='margin-bottom:22px;border:1px solid rgba(212,175,55,.3);border-radius:14px;padding:16px';
    panel.insertBefore(el,panel.firstChild);
  }
  return el;
}
function money(n){return Math.round(Number(n)||0).toLocaleString();}
function render(d){
  var el=card(); if(!el) return;
  var s=d.settings, st=d.stats||{};
  var inputs=FIELDS.map(function(f){
    return '<label style="font-size:12px;display:block">'+esc(f[1])+'<input id="cp-'+f[0]+'" class="filter-sel" style="width:100%" '+(f[0]==='tip_presets'?'type="text"':'type="number" step="any" min="'+f[2]+'"')+'></label>';
  }).join('');
  el.innerHTML=
    '<div style="font-weight:800;font-size:15px;margin-bottom:4px">Courier pay and tips</div>'+
    '<div style="font-size:12px;opacity:.8;margin-bottom:12px;line-height:1.5">You decide how couriers are paid. <b>Off</b>: the courier is paid the delivery fee the customer was charged. <b>On</b>: pay = (base + per-km x distance) x night x vehicle, never below the minimum. Distance = courier to shop (capped) + shop to customer. The amount is saved on the order the first time the courier sees it, so changing these numbers never rewrites old orders. Tips always go 100% to the courier.</div>'+
    '<label style="display:flex;align-items:center;gap:10px;font-weight:700;margin-bottom:12px;cursor:pointer"><input type="checkbox" id="cp-enabled" style="width:20px;height:20px"> Pay couriers by distance</label>'+
    '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-bottom:12px">'+inputs+'</div>'+
    '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:6px"><button class="btn btn-primary" id="cp-save" style="padding:8px 16px">💾 Save</button><span id="cp-msg" style="font-size:12px"></span></div>'+
    '<div style="font-size:12px;opacity:.75;margin-bottom:14px">Last 7 days (delivered): average delivery fee ETB '+esc(st.avg_fee_7d||0)+', average courier pay ETB '+esc(st.avg_pay_7d||0)+', orders paid by distance '+esc(st.paid_by_distance_7d||0)+', tips ETB '+money(st.tips_7d)+'.</div>'+
    '<div style="border-top:1px solid rgba(255,255,255,.1);padding-top:12px;margin-bottom:12px"><div style="font-weight:700;margin-bottom:6px">Try a number</div>'+
    '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">'+
    '<label style="font-size:12px">Courier to shop (km)<input id="cp-t-pick" type="number" step="any" min="0" value="1" class="filter-sel" style="width:110px;display:block"></label>'+
    '<label style="font-size:12px">Shop to customer (km)<input id="cp-t-drop" type="number" step="any" min="0" value="2" class="filter-sel" style="width:110px;display:block"></label>'+
    '<label style="font-size:12px">Vehicle<select id="cp-t-tier" class="filter-sel" style="display:block"><option value="1">Moto x1.00</option><option value="1.12">Car x1.12</option><option value="1.6">Cargo x1.60</option></select></label>'+
    '<label style="font-size:12px;display:flex;gap:6px;align-items:center"><input type="checkbox" id="cp-t-night"> Night</label>'+
    '<button class="btn btn-secondary" id="cp-t-go" style="padding:8px 14px">Calculate</button></div>'+
    '<div id="cp-t-out" style="font-size:13px;margin-top:8px;min-height:18px"></div></div>'+
    '<div style="border-top:1px solid rgba(255,255,255,.1);padding-top:12px"><div style="font-weight:700;margin-bottom:6px">Change the pay of one order</div>'+
    '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">'+
    '<label style="font-size:12px">Order code<input id="cp-o-code" class="filter-sel" placeholder="ETD-XXXXXX" style="width:150px;display:block"></label>'+
    '<button class="btn btn-secondary" id="cp-o-find" style="padding:8px 14px">Find</button></div>'+
    '<div id="cp-o-out" style="font-size:13px;margin-top:10px"></div></div>';
  FIELDS.forEach(function(f){document.getElementById('cp-'+f[0]).value=s[f[0]];});
  document.getElementById('cp-enabled').checked=!!s.enabled;
  document.getElementById('cp-save').onclick=save;
  document.getElementById('cp-t-go').onclick=tryNumber;
  document.getElementById('cp-o-find').onclick=findOrder;
}
function save(){
  var msg=document.getElementById('cp-msg'); msg.textContent='Saving…';
  var body={enabled:document.getElementById('cp-enabled').checked?1:0};
  FIELDS.forEach(function(f){body[f[0]]=document.getElementById('cp-'+f[0]).value;});
  api('admin_save_courier_pay',body).then(function(r){
    if(!r.success){msg.textContent=r.message||'Could not save.';return;}
    FIELDS.forEach(function(f){document.getElementById('cp-'+f[0]).value=r.data.settings[f[0]];});
    msg.textContent=r.data.settings.enabled?'Saved. Couriers are paid by distance from now on.':'Saved. Pay by distance is off: couriers get the delivery fee.';
  }).catch(function(e){msg.textContent=e.message;});
}
function tryNumber(){
  var out=document.getElementById('cp-t-out'); out.textContent='…';
  api('admin_preview_courier_pay',{pickup_km:document.getElementById('cp-t-pick').value,drop_km:document.getElementById('cp-t-drop').value,
    night:document.getElementById('cp-t-night').checked,tier:document.getElementById('cp-t-tier').value}).then(function(r){
    if(!r.success){out.textContent=r.message||'Could not calculate.';return;}
    var x=r.data.result;
    out.innerHTML='<b>ETB '+esc(x.pay)+'</b> &nbsp; base '+esc(x.base_part)+' + distance '+esc(x.distance_part)+' ('+esc(x.km_total)+' km: '+esc(x.km_pickup)+' to the shop + '+esc(x.km_drop)+' to the customer)'+
      (x.night>1?' x night '+esc(x.night):'')+(x.tier!=1?' x vehicle '+esc(x.tier):'')+(x.minimum_applied?' (minimum applied)':'')+
      (r.data.settings_on?'':'<br><span style="opacity:.7">Pay by distance is currently off, so real orders still pay the delivery fee.</span>');
  }).catch(function(e){out.textContent=e.message;});
}
function findOrder(){
  var out=document.getElementById('cp-o-out'); out.textContent='…';
  api('admin_get_order_pay',{order_code:document.getElementById('cp-o-code').value}).then(function(r){
    if(!r.success){out.textContent=r.message||'Not found.';return;}
    var o=r.data.order;
    var log=(r.data.log||[]).map(function(l){return '<div style="font-size:11.5px;opacity:.75">'+esc(l.created_at)+' · '+esc(l.admin_name||'admin')+': '+(l.old_pay==null?'—':esc(l.old_pay))+' → '+(l.new_pay==null?'cleared':esc(l.new_pay))+' · '+esc(l.reason)+'</div>';}).join('');
    out.innerHTML='<div><b>'+esc(o.order_code)+'</b> · '+esc(o.status)+' · courier '+esc(o.courier_name||'none')+'</div>'+
      '<div>Customer paid ETB '+esc(o.fare_total)+' (delivery fee '+esc(o.delivery_fee)+', tip '+esc(o.courier_tip)+')</div>'+
      '<div>Courier gets <b>ETB '+esc(r.data.courier_gets)+'</b> (pay from '+esc(r.data.pay_source)+(o.courier_pay_note?': '+esc(o.courier_pay_note):'')+' + tip)</div>'+
      '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:8px">'+
      '<label style="font-size:12px">New pay, before tip (ETB; empty = go back to the automatic pay)<input id="cp-o-amt" type="number" step="any" min="0" max="2000" class="filter-sel" style="width:150px;display:block"></label>'+
      '<label style="font-size:12px">Reason (saved in the log)<input id="cp-o-why" class="filter-sel" style="width:240px;display:block"></label>'+
      '<button class="btn btn-primary" id="cp-o-set" style="padding:8px 14px">Save for this order</button></div>'+
      '<div id="cp-o-msg" style="font-size:12px;margin-top:6px"></div>'+log;
    document.getElementById('cp-o-set').onclick=function(){
      var m=document.getElementById('cp-o-msg'); m.textContent='Saving…';
      api('admin_set_order_pay',{order_id:o.id,amount:document.getElementById('cp-o-amt').value,reason:document.getElementById('cp-o-why').value}).then(function(x){
        m.textContent=x.success?'Saved.':(x.message||'Could not save.'); if(x.success) findOrder();
      }).catch(function(e){m.textContent=e.message;});
    };
  }).catch(function(e){out.textContent=e.message;});
}
function load(){
  var el=card(); if(!el) return;
  api('admin_get_courier_pay').then(function(r){ if(r.success) render(r.data); else el.innerHTML='<div style="font-size:12px;opacity:.7">Courier pay: '+esc(r.message||'could not load')+'</div>'; })
    .catch(function(e){el.innerHTML='<div style="font-size:12px">'+esc(e.message)+'</div>';});
}
function watch(){
  var panel=document.getElementById('panel-pricing'); if(!panel) return;
  var last=false;
  function check(){var vis=panel.style.display!=='none'&&panel.offsetParent!==null; if(vis&&!last)load(); last=vis;}
  new MutationObserver(check).observe(panel,{attributes:true,attributeFilter:['style','class']});
  check();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',watch);else watch();
})();

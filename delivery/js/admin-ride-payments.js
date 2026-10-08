/* ETEL Admin - Ride Payments tab: fare revenue by method, driver balances,
   driver payouts and approval of driver Etel Money top-ups. Ride data only. */
(function(){
'use strict';
function api(action,body,ok,fail){
  var token=typeof Auth!=='undefined'?Auth.getToken():'';
  var apiUrl=(window.ETEL&&window.ETEL.api)?window.ETEL.api:'/delivery/api/boot.php';
  fetch(apiUrl,{method:'POST',headers:{'Content-Type':'application/json','Authorization':'Bearer '+token},body:JSON.stringify(Object.assign({action:action},body||{}))})
    .then(function(r){return r.json();})
    .then(function(d){if(d.success)ok(d.data);else if(fail)fail(d.message||'Error');})
    .catch(function(e){if(fail)fail(e.message);});
}
function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function etb(n){return 'ETB '+parseFloat(n||0).toLocaleString('en-ET',{maximumFractionDigits:2});}
var METHOD={cash:'Cash',etelpay:'Etel Pay wallet',wallet:'Etel Pay wallet',etel_money:'Etel Money'};
function card(label,val){return '<div class="rp-tile"><div class="rp-k">'+esc(label)+'</div><div class="rp-v">'+val+'</div></div>';}
function table(head,rows,empty){
  if(!rows.length) return '<div class="rp-empty">'+esc(empty)+'</div>';
  return '<div class="rp-scroll"><table class="rp-t"><thead><tr>'+head.map(function(h){return '<th>'+esc(h)+'</th>';}).join('')+'</tr></thead><tbody>'+rows.join('')+'</tbody></table></div>';
}
function render(d){
  var t=d.totals||{}, el=document.getElementById('ridepay-body'); if(!el) return;
  var h='<div class="rp-tiles">'+
    card('Today',etb(t.today))+card('Last 7 days',etb(t.week))+card('Last 30 days',etb(t.month))+card('All time',etb(t.total))+
    card('Completed trips',parseInt(t.trips||0,10))+card('Driver earnings',etb(t.earnings))+card('Paid out to drivers',etb(t.paid_out))+
    card('Commission owed',etb(t.commission_owed))+card('Driver Etel Money',etb(t.etel_money))+'</div>';
  h+='<h3 class="rp-h">Fare revenue by payment method</h3>'+table(['Method','Trips','Amount'],(d.by_method||[]).map(function(r){
    return '<tr><td>'+esc(METHOD[r.method]||r.method)+'</td><td>'+parseInt(r.trips,10)+'</td><td>'+etb(r.amount)+'</td></tr>';}),'No completed trips yet.');
  h+='<h3 class="rp-h">Pending driver Etel Money top-ups</h3>'+table(['Driver','Amount','Source','Reference','Requested',''],(d.topups||[]).map(function(r){
    return '<tr><td>'+esc(r.driver_name||'')+'<div class="rp-s">'+esc(r.driver_phone||'')+'</div></td><td>'+etb(r.amount)+'</td><td>'+esc(r.source)+'</td><td>'+esc(r.tx_ref)+'</td><td>'+esc(r.created_at)+'</td>'+
      '<td style="white-space:nowrap"><button class="btn btn-primary" style="padding:6px 12px" onclick="ridePayDecide('+parseInt(r.id,10)+',true)">Approve</button> '+
      '<button class="btn btn-secondary" style="padding:6px 12px" onclick="ridePayDecide('+parseInt(r.id,10)+',false)">Reject</button></td></tr>';}),'No top-ups waiting for approval.');
  h+='<h3 class="rp-h">Recent driver payouts</h3>'+table(['Driver','Amount','Status','Reference','Date'],(d.payouts||[]).map(function(r){
    return '<tr><td>'+esc(r.driver_name||'')+'<div class="rp-s">'+esc(r.driver_phone||'')+'</div></td><td>'+etb(r.amount)+'</td><td>'+esc(r.status)+'</td><td>'+esc(r.tx_ref||'')+'</td><td>'+esc(r.created_at)+'</td></tr>';}),'No payouts yet.');
  el.innerHTML=h;
}
window.ridePaymentsLoad=function(){
  var el=document.getElementById('ridepay-body'); if(el) el.innerHTML='<div class="rp-empty">Loading...</div>';
  api('admin_ride_payments',{},render,function(m){if(el) el.innerHTML='<div class="rp-empty">'+esc(m)+'</div>';});
};
window.ridePayDecide=function(id,approve){
  var reason='';
  if(!approve){reason=window.prompt('Reason for rejecting (optional):','');if(reason===null) return;}
  else if(!window.confirm('Approve this top-up and credit the driver Etel Money balance?')) return;
  api(approve?'admin_approve_etel_topup':'admin_reject_etel_topup',{id:id,reason:reason},function(){window.ridePaymentsLoad();},function(m){window.alert(m);});
};
})();

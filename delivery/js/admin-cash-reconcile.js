/* ETEL Admin - Payments panel: delivered orders that were never marked paid (cash collected, Telebirr/bank not recorded).
   Finance admins can mark them paid; every change is logged with who did it. */
(function(){
'use strict';
function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function etb(n){return 'ETB '+parseFloat(n||0).toLocaleString('en-ET',{maximumFractionDigits:2});}
function api(action,body,ok,fail){
  var token=typeof Auth!=='undefined'?Auth.getToken():'';
  var apiUrl=(window.ETEL&&window.ETEL.api)?window.ETEL.api:'/delivery/api/boot.php';
  fetch(apiUrl,{method:'POST',headers:{'Content-Type':'application/json','Authorization':'Bearer '+token},body:JSON.stringify(Object.assign({action:action},body||{}))})
    .then(function(r){return r.json();}).then(function(d){if(d.success)ok(d.data);else fail(d.message||'Error');}).catch(function(e){fail(e.message);});
}
function render(d){
  var el=document.getElementById('cash-reconcile'); if(!el) return;
  var rows=d.orders||[];
  var h='<style>#cash-reconcile table{width:100%;border-collapse:collapse;font-size:12px}#cash-reconcile th,#cash-reconcile td{text-align:left;padding:7px 8px;border-bottom:1px solid rgba(128,128,128,.25)}'+
   '#cash-reconcile .cr-tiles{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0}#cash-reconcile .cr-tile{background:rgba(128,128,128,.12);border-radius:10px;padding:8px 12px;font-size:12px}#cash-reconcile .cr-tile b{display:block;font-size:15px}#cash-reconcile .cr-scroll{overflow-x:auto}</style>';
  h+='<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h3 style="margin:0">Delivered orders not marked paid</h3>'+
     (rows.length?'<button class="btn btn-secondary" id="cr-all" style="padding:8px 14px">Mark all shown as received</button>':'')+'</div>';
  h+='<p style="font-size:12px;opacity:.8;margin:6px 0">Orders that were delivered but have no payment record, mostly cash the courier collected. Check the money, then mark it received. Each change is logged with your name.</p>';
  if(!rows.length){h+='<p style="opacity:.75;text-align:center;padding:16px">Nothing to reconcile. Every delivered order has a payment record.</p>';}
  else{
    h+='<div class="cr-tiles"><div class="cr-tile"><span>Orders</span><b>'+parseInt(d.count||rows.length,10)+'</b></div><div class="cr-tile"><span>Total</span><b>'+etb(d.total)+'</b></div>'+
       (d.by_courier||[]).map(function(c){return '<div class="cr-tile"><span>'+esc(c.courier)+' ('+parseInt(c.orders,10)+')</span><b>'+etb(c.amount)+'</b></div>';}).join('')+'</div>';
    h+='<div class="cr-scroll"><table><thead><tr><th>Delivered</th><th>Order</th><th>Customer</th><th>Courier</th><th>Method</th><th>Amount</th><th></th></tr></thead><tbody>'+rows.map(function(r){
      return '<tr><td>'+esc((r.delivered_at||r.placed_at||'').slice(0,16))+'</td><td>'+esc(r.order_code)+'</td><td>'+esc(r.customer_name||'')+'</td><td>'+esc(r.courier_name||'-')+'</td><td>'+esc(r.payment_method||'cash')+'</td><td>'+etb(r.fare_total)+'</td>'+
        '<td><button class="btn btn-secondary cr-one" data-id="'+parseInt(r.id,10)+'" style="padding:5px 10px">Received</button></td></tr>';}).join('')+'</tbody></table></div>';
  }
  el.innerHTML=h;
  function mark(ids){
    api('admin_mark_order_paid',{order_ids:ids,note:'Marked received in Payments'},function(r){if(typeof toast==='function')toast(r.message||'Done');load();},function(m){alert(m);});
  }
  var all=document.getElementById('cr-all');
  if(all) all.onclick=function(){ if(confirm('Mark all '+rows.length+' order(s) as received? Only do this after you have counted the money.')) mark(rows.slice(0,50).map(function(r){return r.id;})); };
  Array.prototype.forEach.call(el.querySelectorAll('.cr-one'),function(b){b.onclick=function(){mark([parseInt(b.getAttribute('data-id'),10)]);};});
}
function load(){
  var el=document.getElementById('cash-reconcile'); if(!el) return;
  api('admin_unpaid_delivered',{},render,function(m){el.innerHTML='<p style="color:#e74c3c">Delivered orders: '+esc(m)+'</p>';});
}
window.loadCashReconcile=load;
function watch(){
  var panel=document.getElementById('panel-telebirr'); if(!panel) return;
  var last=false;
  function check(){var vis=panel.style.display!=='none'&&panel.offsetParent!==null; if(vis&&!last)load(); last=vis;}
  new MutationObserver(check).observe(panel,{attributes:true,attributeFilter:['style','class']});
  check();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',watch);else watch();
})();

/* ETEL Admin - Payments panel: online (Chapa) payments list with problem flags.
   Hooks loadTelebirrPayments so it refreshes whenever the Payments page opens. */
(function(){
'use strict';
function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function etb(n){return 'ETB '+parseFloat(n||0).toLocaleString('en-ET',{maximumFractionDigits:2});}
var FLAG={retry_unused:'Customer paid on another attempt - nothing to do',stuck_pending:'Waiting over 30 min - check with Chapa',paid_but_order_unpaid:'Paid at Chapa but order not marked paid - needs attention'};
function api(body,ok,fail){
  var token=typeof Auth!=='undefined'?Auth.getToken():'';
  var apiUrl=(window.ETEL&&window.ETEL.api)?window.ETEL.api:'/delivery/api/boot.php';
  fetch(apiUrl,{method:'POST',headers:{'Content-Type':'application/json','Authorization':'Bearer '+token},body:JSON.stringify(Object.assign({action:'admin_list_chapa_payments'},body))})
    .then(function(r){return r.json();}).then(function(d){if(d.success)ok(d.data);else fail(d.message||'Error');}).catch(function(e){fail(e.message);});
}
function tile(k,v){return '<div class="cp-tile"><div class="cp-k">'+esc(k)+'</div><div class="cp-v">'+v+'</div></div>';}
function render(d){
  var el=document.getElementById('chapa-online-payments'); if(!el) return;
  var s=d.summary||{}, rows=d.payments||[];
  var h='<style>#chapa-online-payments .cp-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin:10px 0}'+
   '#chapa-online-payments .cp-tile{background:rgba(128,128,128,.12);border-radius:10px;padding:10px 12px}'+
   '#chapa-online-payments .cp-k{font-size:11px;opacity:.75}#chapa-online-payments .cp-v{font-size:18px;font-weight:700}'+
   '#chapa-online-payments table{width:100%;border-collapse:collapse;font-size:12px}'+
   '#chapa-online-payments th,#chapa-online-payments td{text-align:left;padding:7px 8px;border-bottom:1px solid rgba(128,128,128,.25)}'+
   '#chapa-online-payments .cp-bad{color:#e74c3c;font-weight:700}#chapa-online-payments .cp-ok{color:#27ae60;font-weight:700}#chapa-online-payments .cp-wait{color:#d68910;font-weight:700}'+
   '#chapa-online-payments .cp-mute{opacity:.65}#chapa-online-payments .cp-scroll{overflow-x:auto}</style>';
  h+='<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h3 style="margin:0">Online payments (Chapa)</h3>'+
     '<div><select id="cp-days" class="filter-sel"><option value="7">Last 7 days</option><option value="14" selected>Last 14 days</option><option value="30">Last 30 days</option><option value="90">Last 90 days</option></select> '+
     '<button class="act-btn" id="cp-refresh">Refresh</button></div></div>';
  if(!d.enabled) h+='<p class="cp-wait">Chapa is not switched on on this server yet (see the Chapa setup guide).</p>';
  h+='<div class="cp-tiles">'+tile('Paid',parseInt(s.completed||0,10))+tile('Paid amount',etb(s.completed_amount))+tile('Waiting',parseInt(s.pending||0,10))+tile('Failed',parseInt(s.failed||0,10))+tile('Needs attention','<span class="'+(s.needs_attention?'cp-bad':'cp-ok')+'">'+parseInt(s.needs_attention||0,10)+'</span>')+'</div>';
  if(!rows.length){h+='<p style="opacity:.75;text-align:center;padding:20px">No online payments in this period.</p>';}
  else{
    h+='<div class="cp-scroll"><table><thead><tr><th>When</th><th>For</th><th>Customer</th><th>Amount</th><th>Status</th><th>Reference</th></tr></thead><tbody>'+rows.map(function(r){
      var cls=r.status==='completed'?'cp-ok':(r.status==='failed'?'cp-bad':'cp-wait');
      var target=r.kind==='order'?('Order '+esc(r.order_code||('#'+r.target_id))):('Ride #'+parseInt(r.target_id,10));
      return '<tr><td>'+esc(r.created_at)+'</td><td>'+target+'</td><td>'+esc(r.customer_name||'')+'<div style="opacity:.7">'+esc(r.customer_phone||'')+'</div></td><td>'+etb(r.amount)+'</td>'+
        '<td><span class="'+cls+'">'+esc(r.status)+'</span>'+(r.flag?'<div class="'+(r.flag==='retry_unused'?'cp-mute':'cp-bad')+'">'+esc(FLAG[r.flag]||r.flag)+'</div>':'')+'</td><td style="font-family:monospace">'+esc(r.tx_ref)+'</td></tr>';}).join('')+'</tbody></table></div>';
  }
  el.innerHTML=h;
  var sel=document.getElementById('cp-days'); sel.value=String(d.days||14);
  sel.onchange=load; document.getElementById('cp-refresh').onclick=load;
}
function load(){
  var el=document.getElementById('chapa-online-payments'); if(!el) return;
  var sel=document.getElementById('cp-days');
  api({days:sel?parseInt(sel.value,10):14},render,function(m){el.innerHTML='<p class="cp-bad">Online payments: '+esc(m)+'</p>';});
}
window.loadChapaPayments=load;
// Load whenever the Payments panel is shown, however the page switcher is built.
function watchPanel(){
  var panel=document.getElementById('panel-telebirr'); if(!panel) return;
  var last=false;
  function check(){var vis=panel.style.display!=='none'&&panel.offsetParent!==null; if(vis&&!last)load(); last=vis;}
  new MutationObserver(check).observe(panel,{attributes:true,attributeFilter:['style','class']});
  check();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',watchPanel);else watchPanel();
var orig=window.loadTelebirrPayments;
if(typeof orig==='function'){window.loadTelebirrPayments=function(){return orig.apply(this,arguments);};}
})();

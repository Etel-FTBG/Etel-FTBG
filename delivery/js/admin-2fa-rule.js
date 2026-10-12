/* ETEL Admin - Settings card: require two-step sign-in for every admin.
   Works for Delivery-only owners too (the same rule also lives on the Rides > System health page). */
(function(){
'use strict';
function api(action,body,ok,fail){
  var token=typeof Auth!=='undefined'?Auth.getToken():'';
  var apiUrl=(window.ETEL&&window.ETEL.api)?window.ETEL.api:'/delivery/api/boot.php';
  fetch(apiUrl,{method:'POST',headers:{'Content-Type':'application/json','Authorization':'Bearer '+token},body:JSON.stringify(Object.assign({action:action},body||{}))})
    .then(function(r){return r.json();}).then(function(d){if(d.success)ok(d.data);else fail(d.message||'Error');}).catch(function(e){fail(e.message);});
}
function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function card(){
  var panel=document.getElementById('panel-settings'); if(!panel) return null;
  var el=document.getElementById('admin-2fa-rule');
  if(!el){
    el=document.createElement('div'); el.id='admin-2fa-rule';
    el.style.cssText='margin:0 0 18px;padding:14px 16px;border:1px solid rgba(128,128,128,.35);border-radius:12px';
    panel.insertBefore(el,panel.firstChild);
  }
  return el;
}
function render(d){
  var el=card(); if(!el) return;
  var h='<div style="font-weight:700;margin-bottom:4px">Two-step sign-in for admins</div>';
  h+='<div style="font-size:13px;opacity:.85;margin-bottom:8px">'+(d.required?'Required: any admin without two-step sign-in is blocked from admin pages until they set it up.':'Optional right now. Turn it on once every admin has saved their backup codes.')+'</div>';
  h+='<div style="font-size:13px;margin-bottom:8px">Your own two-step sign-in: <b>'+(d.mine?'On':'Off')+'</b>'+(d.mine?'':' (turn it on from the Two-step sign-in menu first)')+'</div>';
  if(d.can_change) h+='<button class="act-btn" id="admin-2fa-rule-btn">'+(d.required?'Stop requiring it':'Require it for every admin')+'</button>';
  else h+='<div style="font-size:12px;opacity:.7">Only an owner admin can change this rule.</div>';
  el.innerHTML=h;
  var b=document.getElementById('admin-2fa-rule-btn');
  if(b) b.onclick=function(){
    var on=!d.required;
    if(on&&!d.mine){alert('Turn on your own two-step sign-in first, then require it for everyone.');return;}
    if(on&&!confirm('Require two-step sign-in for every admin? Admins without it will be asked to set it up before they can open admin pages. Make sure everyone has saved their backup codes.'))return;
    b.disabled=true;
    api('admin_set_2fa_required',{required:on},load,function(m){b.disabled=false;alert(m);});
  };
}
function load(){ api('admin_get_2fa_rule',{},render,function(m){var el=card(); if(el) el.innerHTML='<div style="font-size:12px;opacity:.7">Two-step rule: '+esc(m)+'</div>';}); }
function watch(){
  var panel=document.getElementById('panel-settings'); if(!panel) return;
  var last=false;
  function check(){var vis=panel.style.display!=='none'&&panel.offsetParent!==null; if(vis&&!last)load(); last=vis;}
  new MutationObserver(check).observe(panel,{attributes:true,attributeFilter:['style','class']});
  check();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',watch);else watch();
})();

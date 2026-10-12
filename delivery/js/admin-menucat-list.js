/* ETEL Admin - Menu catalog list: one flexible row per dish instead of a six-column table, so it fits any screen
   width and the Edit / Hide / Delete buttons wrap under the name instead of being cut off. Used by loadMenuCatalog(). */
(function(){
'use strict';
function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
var css='.mc-row{display:flex;gap:10px;justify-content:space-between;align-items:center;flex-wrap:wrap;padding:12px 0;border-bottom:1px solid rgba(128,128,128,.25)}'+
  '.mc-main{flex:1 1 200px;min-width:0}.mc-name{font-weight:700}.mc-am{font-size:12px;opacity:.7}.mc-sub{font-size:12px;opacity:.8;margin-top:3px}'+
  '.mc-actions{display:flex;gap:6px;flex-wrap:wrap}.mc-actions .btn{padding:5px 12px !important;font-size:11px !important;letter-spacing:.02em !important;white-space:nowrap;width:auto !important;min-width:0 !important;max-width:none !important;flex:0 0 auto !important;overflow:visible !important}';
function inject(){ if(document.getElementById('mc-list-css')) return; var s=document.createElement('style'); s.id='mc-list-css'; s.textContent=css; document.head.appendChild(s); }
window.mcRenderRows=function(rows){
  inject();
  return rows.map(function(r){
    var status=r.status==='pending'?'<span class="oc-tag warn">Suggested</span>':(r.status==='hidden'?'<span class="oc-tag">Hidden</span>':'Active');
    var id=parseInt(r.id,10);
    var sub=[esc(r.category),esc(r.cuisine||'-'),(parseInt(r.shops,10)||0)+' shop'+((parseInt(r.shops,10)||0)===1?'':'s')].join(' · ');
    return '<div class="mc-row"><div class="mc-main"><div class="mc-name">'+esc(r.name)+'</div>'+(r.name_am?'<div class="mc-am">'+esc(r.name_am)+'</div>':'')+
      '<div class="mc-sub">'+sub+' · '+status+'</div>'+
      (r.status==='pending'&&r.suggested_by_name?'<div class="mc-sub">Suggested by '+esc(r.suggested_by_name)+'</div>':'')+'</div>'+
      '<div class="mc-actions">'+(r.status==='pending'?'<button class="btn btn-primary" onclick="mcApprove('+id+')">✓ Approve</button>':'')+
      '<button class="btn btn-secondary" onclick="mcEdit('+id+')">Edit</button>'+
      '<button class="btn btn-secondary" onclick="mcDelete('+id+')">'+((parseInt(r.shops,10)||0)>0?'Hide':'Delete')+'</button></div></div>';
  }).join('');
};
})();

/* ETEL Admin - Menu items page: photo coverage. Shows how many items and shops have pictures and lets an admin
   add them in one step each (pick a photo -> uploaded and saved). Shops with no pictures look empty to customers. */
(function(){
'use strict';
function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function token(){return typeof Auth!=='undefined'?Auth.getToken():'';}
function apiUrl(){return (window.ETEL&&window.ETEL.api)?window.ETEL.api:'/delivery/api/boot.php';}
function api(action,body){
  return fetch(apiUrl(),{method:'POST',headers:{'Content-Type':'application/json','Authorization':'Bearer '+token()},body:JSON.stringify(Object.assign({action:action},body||{}))}).then(function(r){return r.json();});
}
function upload(file,type){
  var fd=new FormData(); fd.append('image',file); if(type) fd.append('type',type);
  return fetch(apiUrl().replace('boot.php','upload.php'),{method:'POST',headers:{'Authorization':'Bearer '+token()},body:fd}).then(function(r){return r.json();});
}
function say(m,bad){ if(typeof toast==='function') toast(m,bad?'err':undefined); else alert(m); }
function pct(a,b){return b?Math.round(100*a/b):0;}
function bar(label,a,b){var p=pct(a,b);return '<div style="flex:1;min-width:150px"><div style="font-size:12px;opacity:.8">'+esc(label)+' <b>'+a+'/'+b+'</b> ('+p+'%)</div><div style="height:8px;border-radius:6px;background:rgba(128,128,128,.25);margin-top:4px"><div style="height:8px;border-radius:6px;width:'+p+'%;background:'+(p>=90?'#2ecc71':(p>=50?'#D4AF37':'#e74c3c'))+'"></div></div></div>';}
function render(d){
  var el=document.getElementById('photo-coverage'); if(!el) return;
  var t=d.totals||{}, items=d.items||[], shops=d.shops||[];
  var h='<style>#photo-coverage .pc-row{display:flex;align-items:center;gap:10px;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(128,128,128,.25);flex-wrap:wrap}'+
        '#photo-coverage .pc-pick{display:inline-block;padding:6px 12px;border:1px solid rgba(212,175,55,.6);border-radius:8px;cursor:pointer;font-size:12px}'+
        '#photo-coverage .pc-pick input{display:none}#photo-coverage .pc-small{font-size:11px;opacity:.7}</style>';
  h+='<div style="border:1px solid rgba(128,128,128,.35);border-radius:12px;padding:14px 16px;margin-bottom:18px">';
  h+='<div style="font-weight:700;margin-bottom:8px">📷 Photo coverage</div>';
  h+='<div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:10px">'+bar('Items with a photo',t.items_with_photo||0,t.items||0)+bar('Shops with a logo',t.shops_with_logo||0,t.shops||0)+bar('Shops with a cover',t.shops_with_cover||0,t.shops||0)+'</div>';
  h+='<div class="pc-small" style="margin-bottom:8px">Customers choose food with their eyes. Add a clear, bright photo of each item and a logo and cover for each shop. Pick a photo and it uploads and saves straight away.</div>';
  if(!items.length&&!shops.length) h+='<div style="padding:8px;opacity:.8">Every item and shop has its pictures. Nice.</div>';
  if(shops.length){
    h+='<div style="font-weight:600;margin:10px 0 2px">Shops missing a logo or cover ('+shops.length+')</div>';
    h+=shops.map(function(s){
      return '<div class="pc-row" data-shop="'+parseInt(s.id,10)+'"><div>'+esc(s.business_name)+(s.status&&s.status!=='verified'?' <span class="pc-small">('+esc(s.status)+')</span>':'')+'</div><div>'+
        (s.logo_url?'<span class="pc-small">Logo ✓</span> ':'<label class="pc-pick">Add logo<input type="file" accept="image/*" data-kind="logo_url"></label> ')+
        (s.cover_url?'<span class="pc-small">Cover ✓</span>':'<label class="pc-pick">Add cover<input type="file" accept="image/*" data-kind="cover_url"></label>')+'</div></div>';}).join('');
  }
  if(items.length){
    h+='<div style="font-weight:600;margin:14px 0 2px">Items without a photo ('+items.length+(items.length>=200?'+':'')+')</div>';
    h+=items.map(function(i){
      return '<div class="pc-row" data-item="'+parseInt(i.id,10)+'"><div>'+esc(i.name)+' <span class="pc-small">'+esc(i.business_name||'')+'</span></div><div><label class="pc-pick">Add photo<input type="file" accept="image/*"></label></div></div>';}).join('');
  }
  h+='</div>';
  el.innerHTML=h;
  Array.prototype.forEach.call(el.querySelectorAll('input[type=file]'),function(inp){
    inp.onchange=function(){
      var f=inp.files&&inp.files[0]; if(!f) return;
      var row=inp.closest('.pc-row'), lab=inp.parentNode; lab.firstChild.textContent='Uploading…';
      var isShop=row.hasAttribute('data-shop'), kind=inp.getAttribute('data-kind');
      upload(f,isShop?'shop_cover':'').then(function(u){
        if(!u.success) throw new Error(u.message||'Upload failed');
        var url=u.data.url;
        if(isShop){var b={service_giver_id:parseInt(row.getAttribute('data-shop'),10)}; b[kind]=url; return api('admin_set_shop_branding',b);}
        return api('admin_update_item',{item_id:parseInt(row.getAttribute('data-item'),10),image_url:url});
      }).then(function(r){
        if(!r.success) throw new Error(r.message||'Could not save');
        say('Saved'); load();
      }).catch(function(e){lab.firstChild.textContent='Try again'; say(e.message,true);});
    };
  });
}
function load(){
  var el=document.getElementById('photo-coverage'); if(!el) return;
  api('admin_photo_coverage').then(function(r){ if(r.success) render(r.data); else el.innerHTML='<div class="pc-small" style="color:#e74c3c">Photo coverage: '+esc(r.message||'could not load')+'</div>'; })
    .catch(function(e){el.innerHTML='<div style="color:#e74c3c">Photo coverage: '+esc(e.message)+'</div>';});
}
window.loadPhotoCoverage=load;
// Menu catalog dish form: add an upload button next to the Photo URL box.
function watchCatalogPhoto(){
  var inp=document.getElementById('mc-img'); if(!inp||document.getElementById('mc-img-file')) return;
  var lab=document.createElement('label'); lab.className='pc-pick'; lab.style.cssText='display:inline-block;margin-top:6px;padding:6px 12px;border:1px solid rgba(212,175,55,.6);border-radius:8px;cursor:pointer;font-size:12px';
  lab.innerHTML='<span>Upload a photo</span><input id="mc-img-file" type="file" accept="image/*" style="display:none">';
  inp.parentNode.appendChild(lab);
  lab.querySelector('input').onchange=function(){
    var f=this.files&&this.files[0]; if(!f) return; var span=lab.firstChild; span.textContent='Uploading…';
    upload(f,'').then(function(u){ if(!u.success) throw new Error(u.message||'Upload failed'); inp.value=u.data.url; span.textContent='Photo added - press Save'; })
      .catch(function(e){span.textContent='Upload a photo'; say(e.message,true);});
  };
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',watchCatalogPhoto);else watchCatalogPhoto();

function watch(){
  var panel=document.getElementById('panel-items'); if(!panel) return;
  if(!document.getElementById('photo-coverage')){var d=document.createElement('div'); d.id='photo-coverage'; panel.insertBefore(d,panel.firstChild);}
  var last=false;
  function check(){var vis=panel.style.display!=='none'&&panel.offsetParent!==null; if(vis&&!last)load(); last=vis;}
  new MutationObserver(check).observe(panel,{attributes:true,attributeFilter:['style','class']});
  check();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',watch);else watch();
})();

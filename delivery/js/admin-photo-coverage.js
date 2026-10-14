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
// Phone photos are 3-8 MB (the server refuses over 5 MB) and a shop on mobile data should not send that.
// Resize to 1280 px on the long side and re-encode as JPEG ~82% before uploading: usually 150-400 KB. The browser also
// applies the photo's rotation, so portrait photos are not sent sideways. Falls back to the original file on any problem.
function shrink(file){
  var MAX=1280;
  if(!file||!/^image\//.test(file.type)||file.type==='image/gif'||!window.HTMLCanvasElement) return Promise.resolve(file);
  function draw(src,w,h){
    var k=Math.min(1,MAX/Math.max(w,h)), cw=Math.max(1,Math.round(w*k)), ch=Math.max(1,Math.round(h*k));
    var c=document.createElement('canvas'); c.width=cw; c.height=ch; var x=c.getContext('2d');
    x.fillStyle='#fff'; x.fillRect(0,0,cw,ch); x.drawImage(src,0,0,cw,ch);
    return new Promise(function(res){c.toBlob(res,'image/jpeg',0.82);});
  }
  var loaded=(window.createImageBitmap)
    ? createImageBitmap(file,{imageOrientation:'from-image'}).then(function(b){return {src:b,w:b.width,h:b.height};})
    : new Promise(function(res,rej){var u=URL.createObjectURL(file),im=new Image();im.onload=function(){URL.revokeObjectURL(u);res({src:im,w:im.naturalWidth,h:im.naturalHeight});};im.onerror=rej;im.src=u;});
  return loaded.then(function(o){return draw(o.src,o.w,o.h).then(function(blob){
    if(!blob||blob.size>=file.size) return file;
    return new File([blob],(file.name||'photo').replace(/\.[A-Za-z0-9]{2,5}$/,'')+'.jpg',{type:'image/jpeg'});
  });}).catch(function(){return file;});
}
function upload(file,type){
  return shrink(file).then(function(f){
    var fd=new FormData(); fd.append('image',f); if(type) fd.append('type',type);
    return fetch(apiUrl().replace('boot.php','upload.php'),{method:'POST',headers:{'Authorization':'Bearer '+token()},body:fd}).then(function(r){return r.json();});
  });
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
  if(items.length){
    h+='<div style="margin:6px 0 10px"><label class="pc-pick" style="font-weight:600">📁 Add many photos at once<input id="pc-bulk" type="file" accept="image/*" multiple></label> '+
       '<span class="pc-small">Name each photo after the dish (for example <b>shiro.jpg</b> or <b>fasika shiro.jpg</b>). You check every match before anything is uploaded.</span></div><div id="pc-bulk-review"></div>';
  }
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
  var bulk=document.getElementById('pc-bulk'); if(bulk) bulk.onchange=function(){ startBulk(bulk.files,items); bulk.value=''; };
  Array.prototype.forEach.call(el.querySelectorAll('input[type=file]:not(#pc-bulk)'),function(inp){
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

// ---- bulk upload: pick many photos, match by file name, confirm, upload one by one ----
function itemLabel(i){return i.name+(i.business_name?' - '+i.business_name:'');}
function startBulk(fileList,items){
  var box=document.getElementById('pc-bulk-review'); if(!box) return;
  var files=Array.prototype.slice.call(fileList||[]).filter(function(f){return /^image\//.test(f.type);});
  if(!files.length){box.innerHTML='';return;}
  if(files.length>60){say('Please pick up to 60 photos at a time.',true); files=files.slice(0,60);}
  var match=(window.EtelPhotoMatch?EtelPhotoMatch.matchFiles(files,items):files.map(function(f){return {file:f,item:null};}));
  var opts='<option value="">- skip this photo -</option>'+items.map(function(i){return '<option value="'+parseInt(i.id,10)+'">'+esc(itemLabel(i))+'</option>';}).join('');
  var rows=match.map(function(m,k){
    var url=URL.createObjectURL(m.file);
    return '<div class="pc-row" data-k="'+k+'"><div style="display:flex;align-items:center;gap:10px;min-width:0"><img src="'+url+'" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:8px;flex:none">'+
      '<div style="min-width:0"><div style="font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:200px">'+esc(m.file.name)+'</div><div class="pc-small pc-st">'+(m.item?'Matched':(m.ambiguous?'Fits more than one item - choose':'No match - choose or skip'))+'</div></div></div>'+
      '<select class="pc-sel" style="max-width:240px;padding:6px;border-radius:8px" aria-label="Item for '+esc(m.file.name)+'">'+opts.replace('value="'+(m.item?parseInt(m.item.id,10):'-')+'"','value="'+(m.item?parseInt(m.item.id,10):'-')+'" selected')+'</select></div>';
  }).join('');
  box.innerHTML='<div style="border:1px solid rgba(212,175,55,.5);border-radius:12px;padding:10px 12px;margin-bottom:12px"><div style="font-weight:600;margin-bottom:4px">Check the matches ('+files.length+' photos)</div>'+rows+
    '<div style="display:flex;gap:10px;align-items:center;margin-top:10px;flex-wrap:wrap"><button type="button" class="btn btn-primary" id="pc-go" style="padding:8px 16px">Upload</button><button type="button" class="btn" id="pc-cancel" style="padding:8px 16px">Cancel</button><span class="pc-small" id="pc-msg"></span></div></div>';
  var go=document.getElementById('pc-go'), msg=document.getElementById('pc-msg');
  function chosen(){return Array.prototype.map.call(box.querySelectorAll('.pc-row'),function(r){return {k:parseInt(r.getAttribute('data-k'),10),row:r,id:parseInt(r.querySelector('select').value,10)||0};}).filter(function(c){return c.id;});}
  function refresh(){var c=chosen(),seen={},dup=false;c.forEach(function(x){if(seen[x.id])dup=true;seen[x.id]=1;}); go.textContent='Upload '+c.length+' photo'+(c.length===1?'':'s'); go.disabled=!c.length||dup; msg.textContent=dup?'Two photos are set for the same item - change one.':'';}
  box.addEventListener('change',refresh); refresh();
  document.getElementById('pc-cancel').onclick=function(){box.innerHTML='';};
  go.onclick=function(){
    var list=chosen(), done=0, bad=0; go.disabled=true; document.getElementById('pc-cancel').disabled=true;
    function next(){
      if(done+bad>=list.length){ msg.textContent=done+' saved'+(bad?', '+bad+' failed (you can try those again)':'.'); say(done+' photo'+(done===1?'':'s')+' saved',bad>0); setTimeout(load,1200); return; }
      var c=list[done+bad], st=c.row.querySelector('.pc-st'); st.textContent='Uploading…'; msg.textContent=(done+bad+1)+' of '+list.length;
      upload(match[c.k].file,'').then(function(u){
        if(!u.success) throw new Error(u.message||'Upload failed');
        return api('admin_update_item',{item_id:c.id,image_url:u.data.url});
      }).then(function(r){
        if(!r.success) throw new Error(r.message||'Could not save');
        st.textContent='Saved'; c.row.querySelector('select').disabled=true; done++;
      }).catch(function(e){st.textContent='Failed: '+e.message; bad++;}).then(next);
    }
    next();
  };
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

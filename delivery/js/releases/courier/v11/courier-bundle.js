/* ============================================================
   courier-bundle.js - bundled scripts
   Auto-generated & minified by concatenating (in this exact execution order):
     - js/courier.js
     - js/courier-premium.js
   Do not hand-edit; edit the source files above and rebuild with
   node js/build-bundles.js
   ============================================================ */

if(!requireRole(["courier","admin"]))throw new Error("Access denied");const user=Auth.getUser();let availableJobs=[];function apiGet(e,t){if(!t)t={};const o=Object.entries(t).map(function(e){return e[0]+"="+encodeURIComponent(e[1])}).join("&");return fetch(ETEL.api+"?action="+e+(o?"&"+o:""),{headers:{Authorization:"Bearer "+Auth.getToken()}}).then(function(e){return e.json()})}function fmtDate(e){if(!e)return"—";const t=new Date(e);return t.toLocaleDateString("en-GB",{day:"2-digit",month:"short"})+" "+t.toLocaleTimeString("en-GB",{hour:"2-digit",minute:"2-digit"})}function switchTab(e){document.querySelectorAll(".tab-content").forEach(function(e){e.classList.remove("active")});document.querySelectorAll(".tab-btn").forEach(function(e){e.classList.remove("active")});document.getElementById(e).classList.add("active");event.target.classList.add("active");if(e==="jobs")loadAvailableJobs();else if(e==="active")loadActiveOrders();else if(e==="history")loadHistory()}var _courierAudioCtx=null;if(!window._courierRingingTimers)window._courierRingingTimers={};function _playCourierRingBurst(){try{var e=window.AudioContext||window.webkitAudioContext;if(!e)return;if(!_courierAudioCtx)_courierAudioCtx=new e;var t=_courierAudioCtx;if(t.state==="suspended")t.resume().catch(function(){});[660,990].forEach(function(e,o){[{type:"sine",gain:.9},{type:"triangle",gain:.5}].forEach(function(n){var r=t.createOscillator();var i=t.createGain();r.type=n.type;r.frequency.value=e;i.gain.setValueAtTime(1e-4,t.currentTime);var a=t.currentTime+o*.16;i.gain.exponentialRampToValueAtTime(n.gain,a+.02);i.gain.exponentialRampToValueAtTime(1e-4,a+.26);r.connect(i);i.connect(t.destination);r.start(a);r.stop(a+.28)})})}catch(e){}}function startRingingForJob(e){if(window._courierRingingTimers[e])return;if(navigator.vibrate)navigator.vibrate([200,100,200,100,200]);_playCourierRingBurst();window._courierRingingTimers[e]=setInterval(function(){if(navigator.vibrate)navigator.vibrate([200,100,200]);_playCourierRingBurst()},1400)}function stopRingingForJob(e){if(window._courierRingingTimers[e]){clearInterval(window._courierRingingTimers[e]);delete window._courierRingingTimers[e]}}function stopAllCourierRinging(){Object.keys(window._courierRingingTimers).forEach(stopRingingForJob)}function muteJobRing(e,t){stopRingingForJob(e);if(t){t.textContent="🔇 Muted";t.disabled=true;t.style.opacity="0.5"}}async function loadAvailableJobs(){document.getElementById("jobs-content").innerHTML='<div style="text-align:center;padding:40px;color:#A8A8B8;font-size:14px">📍 Getting your location…</div>';if(locationGranted===null){await new Promise(function(e){initCourierLocation(e)})}document.getElementById("jobs-content").innerHTML=UI.loading("Loading available jobs...");const e=await apiGet("courier_get_available_orders",{});if(!e.success){document.getElementById("jobs-content").innerHTML='<div style="padding:20px;color:#E63946;font-size:13px">'+(e.message||"Failed to load jobs")+"</div>";return}availableJobs=e.data.orders;if(!window._seenJobIds)window._seenJobIds={};availableJobs.forEach(function(e){window._seenJobIds[e.id]=true});renderJobs(availableJobs)}function filterJobs(e){document.querySelectorAll(".filter-btn").forEach(function(e){e.classList.remove("active")});event.target.classList.add("active");if(e==="high"){renderJobs(availableJobs.filter(function(e){return parseFloat(e.fare_total||0)>=100}))}else{renderJobs(availableJobs)}}function paymentBadgeHtml(e){if(!e)return"";var t=String(e).toLowerCase();var o={cash:{label:"💵 Cash",bg:"rgba(255,193,7,.15)",fg:"#e0a800"},wallet:{label:"💰 ኢE Pay",bg:"rgba(212,175,55,.18)",fg:"#D4AF37"},telebirr:{label:"📱 Telebirr",bg:"rgba(33,150,243,.15)",fg:"#2196f3"},abysinia:{label:"🏦 Bank",bg:"rgba(78,205,196,.15)",fg:"#4ECDC4"},cbe:{label:"🏛️ Bank",bg:"rgba(78,205,196,.15)",fg:"#4ECDC4"}};var n=o[t]||{label:t.toUpperCase(),bg:"rgba(255,255,255,.1)",fg:"#A8A8B8"};return'<span style="display:inline-block;padding:3px 9px;border-radius:6px;font-size:10.5px;font-weight:700;background:'+n.bg+";color:"+n.fg+';white-space:nowrap">'+n.label+"</span>"}function renderJobs(e){var t=e||[];if(!t.length){document.getElementById("jobs-content").innerHTML='<div style="text-align:center;padding:60px 20px;color:#A8A8B8">'+'<div style="font-size:48px;margin-bottom:16px">📦</div>'+'<div style="font-size:16px;font-weight:600;margin-bottom:8px">No jobs available right now</div>'+'<div style="font-size:13px">New orders appear here as soon as they\'re placed</div>'+'<button onclick="loadAvailableJobs()" style="margin-top:20px;padding:12px 24px;background:var(--primary);color:#1a1a2e;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer">Refresh</button>'+"</div>";return}t=t.slice().sort(function(e,t){var o=jobDistanceKm(e),n=jobDistanceKm(t);if(o===null&&n===null)return 0;if(o===null)return 1;if(n===null)return-1;return o-n});document.getElementById("jobs-content").innerHTML=t.map(function(e){var t=jobDistanceKm(e);var o=t!==null?'<span class="dist-badge">'+t.toFixed(1)+" km away</span>":"";return'<div class="job-card">'+'<div class="job-header">'+'<span class="job-id">'+escapeHtml(e.order_code)+"</span>"+'<span class="job-wait" data-placed="'+escapeHtml(e.placed_at||"")+'"></span>'+'<span class="job-status">📦 '+escapeHtml((e.package_type||"delivery").toUpperCase())+"</span>"+'<button onclick="muteJobRing('+e.id+', this)" style="margin-left:8px;padding:4px 9px;border:1px solid rgba(255,255,255,.2);background:transparent;color:#A8A8B8;border-radius:8px;font-size:11px;cursor:pointer">🔇 Mute</button>'+'</div><div class="job-info">'+'<div class="job-address"><span class="job-address-icon">🏪</span><span>Pickup: <strong>'+escapeHtml(e.from_zone||"—")+"</strong> "+o+"</span></div>"+'<div class="job-address" style="margin-top:6px"><span class="job-address-icon">📍</span><span>Dropoff: '+escapeHtml(e.to_zone||"—")+" · "+escapeHtml(e.recipient_name||"—")+(e.delivery_photo_url?" · 📷":"")+"</span></div>"+'<div class="job-meta">'+'<div class="job-meta-item">📦 '+escapeHtml(e.package_type||"package")+"</div>"+'<div class="job-meta-item">🕐 '+fmtDate(e.placed_at)+"</div>"+(e.payment_method?'<div class="job-meta-item">'+paymentBadgeHtml(e.payment_method)+"</div>":"")+'</div><div class="job-fee">You earn: ETB '+parseFloat(e.delivery_fee!=null?e.delivery_fee:0).toFixed(2)+"</div>"+'<div class="job-actions">'+'<button class="btn btn-primary" onclick="acceptJob('+e.id+",'"+escapeHtml(e.order_code).replace(/'/g,"\\'")+"')\">Accept Job</button>"+'<button class="btn btn-secondary" onclick="skipJob('+e.id+')">Skip</button>'+"</div></div></div>"}).join("")}async function acceptJob(e,t){if(!confirm("Accept job "+t+"?"))return;stopRingingForJob(e);const o=await api("courier_accept_order",{order_id:e});if(o&&o.success){showToast("Job accepted! Go to Active tab.");availableJobs=availableJobs.filter(function(t){return t.id!==e});renderJobs(availableJobs);setTimeout(function(){document.querySelectorAll(".tab-btn").forEach(function(e){e.classList.remove("active")});document.querySelectorAll(".tab-content").forEach(function(e){e.classList.remove("active")});document.getElementById("active").classList.add("active");document.querySelectorAll(".tab-btn")[1].classList.add("active");loadActiveOrders()},800)}else{showToast(o&&o.message||"Failed to accept job","err")}}function skipJob(e){stopRingingForJob(e);availableJobs=availableJobs.filter(function(t){return t.id!==e});renderJobs(availableJobs)}async function loadActiveOrders(){document.getElementById("active-content").innerHTML=UI.loading("Loading active deliveries...");const e=await apiGet("courier_get_my_orders",{offers:1});if(!e.success){document.getElementById("active-content").innerHTML='<div style="text-align:center;padding:40px;color:#A8A8B8">Could not load active orders.</div>';return}const t=(e.data.orders||[]).filter(function(e){return e.status!=="delivered"&&e.status!=="cancelled"});renderActiveOrders(t)}function renderActiveOrders(e){window._activeOrders=e;if(!e.length){document.getElementById("active-content").innerHTML='<div style="text-align:center;padding:60px 20px;color:#A8A8B8">'+'<div style="font-size:48px;margin-bottom:16px">🚗</div>'+'<div style="font-size:16px;font-weight:600;margin-bottom:8px">No active deliveries</div>'+'<div style="font-size:13px">Accept a job from the Jobs tab</div>'+"</div>";return}document.getElementById("active-content").innerHTML=e.map(function(e){if(e.offer_pending)return etelOfferCardHtml(e);const t={assigned:"📦 Go to Shop — Pickup",picked_up:"🚚 Deliver to Customer",in_transit:"🚗 In Transit to Customer"}[e.status]||e.status;var o=parseFloat(e.sg_lat)||null;var n=parseFloat(e.sg_lng)||null;var r=e.sg_business_name||e.from_zone||"Shop";var i=e.sg_address||e.from_zone||"";var a=parseFloat(e.delivery_lat)||null;var s=parseFloat(e.delivery_lng)||null;var l=e.recipient_address&&e.recipient_address.trim()?e.recipient_address:(e.to_zone||"")+", Hawassa";var d="";if(e.notes&&e.notes.startsWith("Address:")){var c=e.notes.split("\n")[0].replace("Address:","").trim();d='<div class="job-address" style="margin-top:6px;color:#A8A8B8;font-size:12px"><span class="job-address-icon">🏠</span><span>'+escapeHtml(c)+"</span></div>"}var u="";var p=window._confState&&window._confState[e.id];if(e.status==="assigned"){var f=o&&n?"navigateToShop('"+(e.from_zone||"").replace(/'/g,"\\'")+"',"+o+","+n+")":"navigateToShop('"+(e.from_zone||"").replace(/'/g,"\\'")+"')";var g='<button class="nav-btn nav-btn-shop" style="width:100%;padding:14px;font-size:15px;font-weight:700;background:linear-gradient(135deg,#D4AF37,#b8922e);color:#1a1a2e;border:none;border-radius:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:8px" onclick="'+f+'">'+"🗺️ NAVIGATE TO SHOP</button>";if(p==="pickup_pending"){u=g+'<div class="conf-waiting" style="margin-top:8px;padding:14px;background:linear-gradient(135deg, rgba(212,175,55,.12), rgba(212,175,55,.03));border:1px solid rgba(212,175,55,.35);border-radius:12px;text-align:center;box-shadow:0 10px 24px -8px rgba(0,0,0,.4)">'+'<div style="display:flex;align-items:center;justify-content:center;gap:8px;font-size:12.5px;font-weight:600;color:#F2CC6B"><span class="conf-pulse-dot" style="width:8px;height:8px;border-radius:50%;background:#F2CC6B;display:inline-block;animation:confPulse 1.4s ease-in-out infinite"></span>Waiting for service giver to confirm pickup…</div>'+'<button class="conf-cancel-btn" onclick="cancelConfirmation('+e.id+',\'pickup\')" style="margin-top:8px;font-size:11px;padding:5px 14px;border-radius:7px;border:1px solid rgba(255,255,255,.15);background:transparent;color:#B8B0A0;cursor:pointer">Cancel request</button></div>'}else{u=g+'<button onclick="showOrderQR('+e.id+',\'pickup\')" style="width:100%;padding:12px;font-size:13px;font-weight:700;background:rgba(212,175,55,.15);color:#D4AF37;border:1px solid rgba(212,175,55,.4);border-radius:10px;cursor:pointer;margin-bottom:8px">'+"📱 Show Pickup QR</button>"+'<button onclick="requestPickupConfirmation('+e.id+",'"+(e.order_code||"").replace(/[<>"'\\]/g,"")+'\')" style="width:100%;padding:12px;font-size:13px;font-weight:600;background:rgba(52,152,219,.15);color:#3498db;border:1px solid rgba(52,152,219,.4);border-radius:10px;cursor:pointer">'+"📦 I'm at the shop — Request Pickup Confirmation</button>"}}else if(e.status==="picked_up"||e.status==="in_transit"){var m=l.replace(/[<>"'\\]/g,"");var v=a&&s?"navigateToCustomer("+a+","+s+",'"+m+"')":"navigateToCustomer(null,null,'"+m+"')";var b='<button class="nav-btn nav-btn-customer" style="width:100%;padding:14px;font-size:15px;font-weight:700;background:linear-gradient(135deg,#2ECC71,#27ae60);color:#fff;border:none;border-radius:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:8px" onclick="'+v+'">'+"🗺️ NAVIGATE TO CUSTOMER</button>";if(p==="delivery_pending"){u=b+'<div class="conf-waiting" style="margin-top:8px;padding:10px;background:rgba(46,204,113,.1);border-radius:8px;text-align:center;font-size:12px">'+"⏳ Waiting for customer to confirm receipt…<br>"+'<button class="conf-cancel-btn" onclick="cancelConfirmation('+e.id+',\'delivery\')" style="margin-top:6px;font-size:11px;padding:4px 12px;border-radius:6px;border:1px solid #888;background:transparent;color:#B8B0A0;cursor:pointer">Cancel request</button></div>'}else{u=b+'<button onclick="showOrderQR('+e.id+',\'delivery\')" style="width:100%;padding:12px;font-size:13px;font-weight:700;background:rgba(212,175,55,.15);color:#D4AF37;border:1px solid rgba(212,175,55,.4);border-radius:10px;cursor:pointer;margin-bottom:8px">'+"📱 Show Delivery QR</button>"+'<button onclick="requestDeliveryConfirmation('+e.id+",'"+(e.order_code||"").replace(/[<>"'\\]/g,"")+'\')" style="width:100%;padding:12px;font-size:13px;font-weight:600;background:rgba(46,204,113,.15);color:#2ECC71;border:1px solid rgba(46,204,113,.4);border-radius:10px;cursor:pointer">'+"🚪 I've delivered — Request Customer Confirmation</button>"}}var y="";if(o&&n){var h="https://maps.googleapis.com/maps/api/staticmap?center="+o+","+n+"&zoom=16&size=600x180&scale=2&maptype=roadmap"+"&markers=color:red%7Clabel:S%7C"+o+","+n+"&key="+(ETEL.mapsKey||"AIzaSyDeZ0j7cZcctskKKO_D27Qgm_38sXHy0QA");var x="https://www.google.com/maps/dir/?api=1&destination="+o+","+n+"&travelmode=driving";y='<div style="margin:10px 0 4px;font-size:11px;color:#A8A8B8;font-weight:600;letter-spacing:.05em">📍 SHOP LOCATION</div>'+'<a href="'+x+'" target="_blank" rel="noopener" style="display:block;border-radius:10px;overflow:hidden;position:relative">'+'<img src="'+h+'" style="width:100%;height:130px;object-fit:cover;border-radius:10px;border:1px solid rgba(212,175,55,.2)" alt="Shop location map" onerror="this.onerror=null;this.style.display=\'none\'">'+'<div style="position:absolute;bottom:8px;right:8px;background:rgba(0,0,0,.75);color:#fff;font-size:11px;font-weight:600;padding:4px 10px;border-radius:20px">Open in Maps ↗</div>'+"</a>"+'<div style="font-size:11px;color:#A8A8B8;margin-top:4px">📍 '+escapeHtml(i)+"</div>"}else{var w=ZONE_COORDS[e.from_zone];if(w){var _="https://www.google.com/maps/dir/?api=1&destination="+w.lat+","+w.lng+"&travelmode=driving";y='<div style="margin:10px 0 4px;font-size:11px;color:#A8A8B8;font-weight:600;letter-spacing:.05em">📍 PICKUP ZONE</div>'+'<a href="'+_+'" target="_blank" rel="noopener" '+'style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:rgba(212,175,55,.08);border:1px solid rgba(212,175,55,.2);border-radius:10px;color:#D4AF37;text-decoration:none">'+'<span style="font-size:22px">🗺️</span>'+'<div><div style="font-size:13px;font-weight:700">'+escapeHtml(e.from_zone||"Unknown zone")+"</div>"+'<div style="font-size:11px;opacity:.7">Tap to open in Google Maps navigation</div></div>'+"</a>"}}var C="";if((e.status==="picked_up"||e.status==="in_transit")&&a&&s){var E="https://maps.googleapis.com/maps/api/staticmap?center="+a+","+s+"&zoom=16&size=600x180&scale=2&maptype=roadmap"+"&markers=color:blue%7Clabel:C%7C"+a+","+s+"&key="+(ETEL.mapsKey||"AIzaSyDeZ0j7cZcctskKKO_D27Qgm_38sXHy0QA");var T="https://www.google.com/maps/dir/?api=1&destination="+a+","+s+"&travelmode=driving";C='<div style="margin:10px 0 4px;font-size:11px;color:#A8A8B8;font-weight:600;letter-spacing:.05em">📍 CUSTOMER LOCATION</div>'+'<a href="'+T+'" target="_blank" rel="noopener" style="display:block;border-radius:10px;overflow:hidden;position:relative">'+'<img src="'+E+'" style="width:100%;height:130px;object-fit:cover;border-radius:10px;border:1px solid rgba(52,152,219,.2)" alt="Customer location map" onerror="this.onerror=null;this.style.display=\'none\'">'+'<div style="position:absolute;bottom:8px;right:8px;background:rgba(0,0,0,.75);color:#fff;font-size:11px;font-weight:600;padding:4px 10px;border-radius:20px">Open in Maps ↗</div>'+"</a>"+'<div style="font-size:11px;color:#A8A8B8;margin-top:4px">📍 '+escapeHtml(l)+"</div>"}var A=e.status==="assigned"?y:C;return'<div class="job-card accepted">'+'<div class="job-header"><span class="job-id">'+escapeHtml(e.order_code)+"</span>"+'<div class="status-badge"><div class="status-dot"></div><span>'+t+"</span></div></div>"+'<div class="job-info">'+'<div class="job-address"><span class="job-address-icon">🏪</span><span>Pickup: <strong>'+escapeHtml(r)+"</strong> · "+escapeHtml(e.from_zone||"—")+"</span></div>"+'<div class="job-address" style="margin-top:6px"><span class="job-address-icon">📍</span><span>Dropoff: '+escapeHtml(e.to_zone||"—")+"</span></div>"+d+'<div class="job-meta" style="margin-top:8px">'+'<div class="job-meta-item">👤 '+escapeHtml(e.recipient_name||"—")+"</div>"+'<div class="job-meta-item">📞 '+escapeHtml(e.recipient_phone||"—")+"</div>"+(e.payment_method?'<div class="job-meta-item">'+paymentBadgeHtml(e.payment_method)+"</div>":"")+"</div>"+(e.delivery_photo_url?'<a href="'+escUrl(e.delivery_photo_url)+'" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:8px;margin-top:8px;text-decoration:none">'+'<img src="'+escUrl(e.delivery_photo_url)+'" style="width:48px;height:48px;object-fit:cover;border-radius:8px;border:1px solid rgba(255,255,255,.15)" alt="Gate/entrance photo">'+'<span style="font-size:12px;color:#A8A8B8">📷 Customer added a gate/entrance photo — tap to view</span>'+"</a>":"")+(String(e.payment_method).toLowerCase()==="cash"&&(e.status==="picked_up"||e.status==="in_transit")?'<div style="margin-top:6px;font-size:11.5px;font-weight:700;color:#e0a800;background:rgba(255,193,7,.1);padding:6px 10px;border-radius:8px">💵 Collect ETB '+parseFloat(e.fare_total||0).toFixed(2)+" cash from the customer</div>":"")+'<div class="job-fee" style="color:var(--accent-green)">You earn: ETB '+parseFloat(e.delivery_fee!=null?e.delivery_fee:0).toFixed(2)+"</div>"+'<div class="job-actions" style="margin-top:12px">'+u+"</div>"+A+"</div></div>"}).join("");var t=e[0];if(t&&map){if(t.status==="assigned"){var o=parseFloat(t.sg_lat)||null;var n=parseFloat(t.sg_lng)||null;var r=t.from_zone||"";if(o&&n){drawRouteOnMap(o,n,"Shop · "+r)}else if(ZONE_COORDS[r]){drawRouteOnMap(ZONE_COORDS[r].lat,ZONE_COORDS[r].lng,"Shop · "+r)}}else if(t.status==="picked_up"||t.status==="in_transit"){var i=parseFloat(t.delivery_lat)||null;var a=parseFloat(t.delivery_lng)||null;var s=t.recipient_address&&t.recipient_address.trim()?t.recipient_address:(t.to_zone||"")+", Hawassa";if(i&&a)drawRouteOnMap(i,a,s);else if(ZONE_COORDS[t.to_zone])drawRouteOnMap(ZONE_COORDS[t.to_zone].lat,ZONE_COORDS[t.to_zone].lng,s)}}}const ZONE_COORDS={Stadium:{lat:7.0606,lng:38.4775},"Mehal Ketema":{lat:7.0559,lng:38.4762},Tabor:{lat:7.04,lng:38.49},"Liqe Mender":{lat:7.07,lng:38.48},Referral:{lat:7.049,lng:38.468},"Addis Ketema":{lat:7.045,lng:38.475},University:{lat:7.062,lng:38.511},Misrak:{lat:7.08,lng:38.485},"Haik Dar":{lat:7.035,lng:38.5},"Tulu Dimtu":{lat:7.1,lng:38.5},"Hawela Teklehaimanot":{lat:7.065,lng:38.49},"Dore Bafana":{lat:7.12,lng:38.47}};const HAWASSA_CENTER={lat:7.0559,lng:38.4762};const HAWASSA_MAX_RADIUS_KM=60;let _locWarnedThisSession=false;let courierLat=null,courierLng=null,locationGranted=null;function initCourierLocation(e){if(!navigator.geolocation){locationGranted=false;if(e)e();return}navigator.geolocation.getCurrentPosition(function(t){courierLat=t.coords.latitude;courierLng=t.coords.longitude;locationGranted=true;if(e)e();navigator.geolocation.watchPosition(function(e){courierLat=e.coords.latitude;courierLng=e.coords.longitude})},function(){locationGranted=false;if(e)e()},{enableHighAccuracy:true,timeout:8e3})}function haversineKm(e,t,o,n){var r=6371,i=(o-e)*Math.PI/180,a=(n-t)*Math.PI/180;var s=Math.sin(i/2)*Math.sin(i/2)+Math.cos(e*Math.PI/180)*Math.cos(o*Math.PI/180)*Math.sin(a/2)*Math.sin(a/2);return r*2*Math.atan2(Math.sqrt(s),Math.sqrt(1-s))}function courierLocationIsPlausible(){if(!courierLat||!courierLng)return false;return haversineKm(courierLat,courierLng,HAWASSA_CENTER.lat,HAWASSA_CENTER.lng)<=HAWASSA_MAX_RADIUS_KM}function jobDistanceKm(e){if(!courierLocationIsPlausible())return null;var t=ZONE_COORDS[e.from_zone];if(!t)return null;return haversineKm(courierLat,courierLng,t.lat,t.lng)}window._confState={};window._confPollers={};function setConfState(e,t){window._confState[e]=t}function clearConfState(e){delete window._confState[e];if(window._confPollers[e]){clearInterval(window._confPollers[e]);delete window._confPollers[e]}}function startPolling(e,t){if(window._confPollers[e])clearInterval(window._confPollers[e]);const o=t==="pickup"?"check_pickup_confirmation":"check_delivery_confirmation";window._confPollers[e]=setInterval(async function(){try{const n=await apiGet(o,{order_id:e});if(n&&n.success&&n.data&&n.data.status==="confirmed"){clearConfState(e);if(t==="pickup"){showToast("✅ Pickup confirmed by service giver! Order is now in transit.")}else{showToast("🎉 Delivery confirmed by customer! Great job!")}loadActiveOrders();if(t==="delivery"){setTimeout(function(){document.querySelectorAll(".tab-btn").forEach(function(e){e.classList.remove("active")});document.querySelectorAll(".tab-content").forEach(function(e){e.classList.remove("active")});document.getElementById("history").classList.add("active");document.querySelectorAll(".tab-btn")[2].classList.add("active");loadHistory()},1200)}}}catch(e){}},5e3)}async function requestPickupConfirmation(e,t){if(!confirm("Request pickup confirmation from the service giver for order "+t+"?\n\nThey will see your request in their app and must confirm before the order is marked as picked up."))return;const o=await api("request_pickup_confirmation",{order_id:e});if(o&&o.success){setConfState(e,"pickup_pending");renderActiveOrders(window._activeOrders||[]);startPolling(e,"pickup");showToast("Request sent! Waiting for service giver to confirm…")}else{showToast(o&&o.message||"Failed to send request","err")}}async function requestDeliveryConfirmation(e,t){if(!confirm("Request delivery confirmation from the customer for order "+t+"?\n\nThey will see your request in their app and must confirm receipt and payment."))return;const o=await api("request_delivery_confirmation",{order_id:e});if(o&&o.success){setConfState(e,"delivery_pending");renderActiveOrders(window._activeOrders||[]);startPolling(e,"delivery");showToast("Request sent! Waiting for customer to confirm…")}else{showToast(o&&o.message||"Failed to send request","err")}}window._orderQrPoller=null;function showOrderQR(e,t){var o=document.getElementById("order-qr-code");o.innerHTML="";new QRCode(o,{text:JSON.stringify({order_id:e,type:t}),width:200,height:200,colorDark:"#000000",colorLight:"#ffffff"});document.getElementById("order-qr-title").textContent=t==="pickup"?"Show this to the Service Giver":"Show this to the Customer";document.getElementById("order-qr-sub").textContent="They scan it in their app to confirm "+t+" instantly";document.getElementById("order-qr-overlay").style.display="flex";if(t==="delivery"&&window._confState[e]!=="delivery_pending"){api("request_delivery_confirmation",{order_id:e}).catch(function(){})}var n=t==="pickup"?"assigned":(window._activeOrders.find(function(t){return t.id===e})||{}).status;if(window._orderQrPoller)clearInterval(window._orderQrPoller);window._orderQrPoller=setInterval(async function(){await loadActiveOrders();var o=(window._activeOrders||[]).find(function(t){return t.id===e});if(!o||o.status!==n){clearInterval(window._orderQrPoller);window._orderQrPoller=null;closeOrderQR();showToast(t==="pickup"?"✅ Pickup confirmed! Order is now in transit.":"🎉 Delivery confirmed! Great job!")}},4e3)}function closeOrderQR(){document.getElementById("order-qr-overlay").style.display="none";if(window._orderQrPoller){clearInterval(window._orderQrPoller);window._orderQrPoller=null}}function cancelConfirmation(e,t){clearConfState(e);renderActiveOrders(window._activeOrders||[]);showToast("Confirmation request cancelled")}async function updateStatus(e,t){const o={picked_up:"Picked Up",in_transit:"In Transit",delivered:"Delivered"};if(!confirm("Override and mark as "+(o[t]||t)+"?"))return;const n=await api("courier_update_order_status",{order_id:e,status:t});if(n&&n.success){clearConfState(e);showToast(o[t]+"! "+(t==="delivered"?"Great job! 🎉":""));if(t==="delivered"){setTimeout(function(){document.querySelectorAll(".tab-btn").forEach(function(e){e.classList.remove("active")});document.querySelectorAll(".tab-content").forEach(function(e){e.classList.remove("active")});document.getElementById("history").classList.add("active");document.querySelectorAll(".tab-btn")[2].classList.add("active");loadHistory()},1e3)}else{loadActiveOrders()}}else{showToast(n&&n.message||"Update failed","err")}}async function loadHistory(){document.getElementById("history-content").innerHTML=UI.loading("Loading history...");const e=await apiGet("courier_get_my_orders",{status:"delivered"});if(!e.success){document.getElementById("history-content").innerHTML='<div style="text-align:center;padding:40px;color:#A8A8B8">No history available.</div>';return}const t=e.data.orders||[];if(!t.length){document.getElementById("history-content").innerHTML='<div style="text-align:center;padding:60px 20px;color:#A8A8B8">'+'<div style="font-size:48px;margin-bottom:16px">📋</div>'+'<div style="font-size:16px;font-weight:600;margin-bottom:8px">No completed deliveries yet</div>'+"</div>";return}const o=t.reduce(function(e,t){return e+parseFloat(t.delivery_fee!=null?t.delivery_fee:0)},0);document.getElementById("history-content").innerHTML='<div style="background:rgba(46,204,113,.1);border:1px solid rgba(46,204,113,.3);border-radius:10px;padding:14px;margin-bottom:16px;text-align:center">'+'<div style="font-size:13px;color:#A8A8B8;margin-bottom:4px">Total Earned</div>'+'<div style="font-size:22px;font-weight:700;color:#2ECC71">ETB '+o.toFixed(2)+"</div>"+'<div style="font-size:12px;color:#A8A8B8">'+t.length+" deliveries completed</div>"+"</div>"+t.map(function(e){return'<div class="job-card" style="opacity:0.85">'+'<div class="job-header"><span class="job-id">'+escapeHtml(e.order_code)+"</span>"+'<span class="job-status" style="background:rgba(46,204,113,.2);color:var(--accent-green);border-color:rgba(46,204,113,.3)">✓ DELIVERED</span></div>'+'<div class="job-info">'+'<div class="job-address"><span class="job-address-icon">📍</span><span>'+escapeHtml(e.from_zone||"—")+" → "+escapeHtml(e.to_zone||"—")+"</span></div>"+(e.payment_method?'<div style="margin-top:6px">'+paymentBadgeHtml(e.payment_method)+"</div>":"")+'<div class="job-fee" style="color:var(--accent-green)">You earned: ETB '+parseFloat(e.delivery_fee!=null?e.delivery_fee:0).toFixed(2)+"</div>"+"</div></div>"}).join("")}async function showEarnings(){const e=await apiGet("courier_get_my_orders",{status:"delivered"});if(!e.success){showToast("Could not load earnings","err");return}const t=e.data.orders||[];const o=t.reduce(function(e,t){return e+parseFloat(t.delivery_fee!=null?t.delivery_fee:0)},0);showToast(t.length+" deliveries · ETB "+o.toFixed(2)+" earned")}function cpInitials(e){var t=String(e||"").trim().split(/\s+/).filter(Boolean);if(!t.length)return"?";return(t[0][0]+(t[1]?t[1][0]:"")).toUpperCase()}var _cpProfileLoaded=false;function toggleProfile(){var e=document.getElementById("profile-drop");var t=document.getElementById("cp-profile-panel");var o=e.style.display==="block";if(o){closeProfile();return}if(user){document.getElementById("cp-avatar").textContent=cpInitials(user.name);document.getElementById("cp-name").textContent=user.name||"Courier";document.getElementById("cp-phone").textContent=user.phone||""}e.style.display="block";requestAnimationFrame(function(){requestAnimationFrame(function(){t.style.transform="translateX(0)"})});loadCourierKpiSummary("today");loadCourierFuelLogs();if(!_cpProfileLoaded){_cpProfileLoaded=true;loadCourierProfileStats()}}function closeProfile(){var e=document.getElementById("profile-drop");var t=document.getElementById("cp-profile-panel");t.style.transform="translateX(100%)";setTimeout(function(){e.style.display="none"},340)}function loadCourierProfileStats(){apiGet("courier_get_profile",{}).then(function(e){if(!e||!e.success||!e.data)return;var t=e.data;var o=document.getElementById("cp-vehicle");var n=document.getElementById("cp-zone");var r=document.getElementById("cp-code");var i=document.getElementById("cp-stat-rating");if(o)o.textContent=t.vehicle_type||"Not set";if(n)n.textContent=t.zone||t.home_zone||"Not set";if(r)r.textContent=t.courier_code||"—";if(i)i.textContent=t.rating&&t.rating>0?t.rating.toFixed(1)+" ⭐":"New"}).catch(function(){});apiGet("courier_get_my_orders",{status:"delivered"}).then(function(e){if(!e||!e.success||!e.data)return;var t=e.data.orders||[];var o=t.reduce(function(e,t){return e+(parseFloat(t.delivery_fee!=null?t.delivery_fee:0)||0)},0);var n=document.getElementById("cp-stat-deliveries");var r=document.getElementById("cp-stat-earnings");if(n)n.textContent=t.length;if(r)r.textContent="ETB "+Math.round(o).toLocaleString()}).catch(function(){})}function openCourierFuelLogForm(){let e=document.getElementById("courier-fuel-bg");if(e)e.remove();e=document.createElement("div");e.id="courier-fuel-bg";e.style.cssText="position:fixed;inset:0;z-index:9999;display:flex;align-items:flex-end;justify-content:center;background:rgba(5,5,10,.7);backdrop-filter:blur(4px)";e.addEventListener("click",function(t){if(t.target===e)e.remove()});e.innerHTML='<div style="width:min(460px,100vw);background:linear-gradient(180deg,#171006,#0a0703);border:1px solid rgba(212,175,55,.25);border-bottom:none;border-radius:20px 20px 0 0;padding:22px 22px max(22px,env(safe-area-inset-bottom,22px));color:#F5EDD8;font-family:inherit">'+'<div style="font-size:17px;font-weight:800;color:#D4AF37;margin-bottom:10px">⛽ Log a Fill-up</div>'+'<p style="margin-bottom:14px;font-size:12.5px;color:rgba(245,237,216,.6)">Enter what you actually paid. Adding your current odometer reading lets us calculate your real cost per km once you have 2+ logs.</p>'+'<label style="display:block;font-size:11px;color:rgba(245,237,216,.5);margin-bottom:5px;text-transform:uppercase;letter-spacing:.06em">Liters</label>'+'<input id="courier-fuel-liters" type="number" min="0" step="0.1" placeholder="e.g. 5" style="width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:#191926;color:#EDEDF5;font-size:14px;margin-bottom:12px;box-sizing:border-box">'+'<label style="display:block;font-size:11px;color:rgba(245,237,216,.5);margin-bottom:5px;text-transform:uppercase;letter-spacing:.06em">Amount paid (ETB)</label>'+'<input id="courier-fuel-cost" type="number" min="0" step="0.01" placeholder="e.g. 950" style="width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:#191926;color:#EDEDF5;font-size:14px;margin-bottom:12px;box-sizing:border-box">'+'<label style="display:block;font-size:11px;color:rgba(245,237,216,.5);margin-bottom:5px;text-transform:uppercase;letter-spacing:.06em">Odometer reading (km) — optional but recommended</label>'+'<input id="courier-fuel-odo" type="number" min="0" step="0.1" placeholder="e.g. 12450" style="width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:#191926;color:#EDEDF5;font-size:14px;margin-bottom:12px;box-sizing:border-box">'+'<div id="courier-fuel-msg" style="display:none;font-size:12.5px;font-weight:600;line-height:1.5;padding:10px 12px;border-radius:10px;margin:10px 0"></div>'+'<button id="courier-fuel-submit" onclick="saveCourierFuelLog()" style="width:100%;padding:13px;border:none;border-radius:12px;background:linear-gradient(135deg,#FFE9A8,#D4AF37);color:#1a1a2e;font-weight:800;font-size:14px;cursor:pointer;margin-top:8px">Save Fill-up</button>'+'<button onclick="document.getElementById(\'courier-fuel-bg\').remove()" style="width:100%;padding:12px;margin-top:8px;border:1px solid rgba(255,255,255,.15);background:transparent;color:#F5EDD8;border-radius:12px;font-weight:700;cursor:pointer">Cancel</button>'+"</div>";document.body.appendChild(e)}function saveCourierFuelLog(){var e=parseFloat(document.getElementById("courier-fuel-liters").value);var t=parseFloat(document.getElementById("courier-fuel-cost").value);var o=document.getElementById("courier-fuel-odo");var n=o&&o.value!==""?parseFloat(o.value):null;var r=document.getElementById("courier-fuel-msg");var i=document.getElementById("courier-fuel-submit");function a(e,t){r.style.display="block";r.style.color=t?"#2ECC71":"#ff6b6b";r.style.background=t?"rgba(46,204,113,.1)":"rgba(255,107,107,.1)";r.style.border="1px solid "+(t?"rgba(46,204,113,.3)":"rgba(255,107,107,.3)");r.textContent=e}if(!(e>0)){a("Enter how many liters you bought.",false);return}if(!(t>0)){a("Enter how much you paid.",false);return}i.disabled=true;i.textContent="Saving…";fetch(ETEL.api,{method:"POST",headers:{"Content-Type":"application/json",Authorization:"Bearer "+Auth.getToken()},body:JSON.stringify({action:"courier_add_fuel_log",liters:e,cost_etb:t,odometer_km:n})}).then(function(e){return e.json()}).then(function(e){if(e&&e.success){a("✓ Fill-up saved",true);loadCourierFuelLogs();loadCourierKpiSummary("today");setTimeout(function(){var e=document.getElementById("courier-fuel-bg");if(e)e.remove()},700)}else{i.disabled=false;i.textContent="Save Fill-up";a(e&&e.message||"Could not save",false)}}).catch(function(){i.disabled=false;i.textContent="Save Fill-up";a("Could not save",false)})}function loadCourierFuelLogs(){var e=document.getElementById("courier-fuel-log-list");if(!e)return;e.textContent="Loading…";apiGet("courier_list_fuel_logs",{}).then(function(t){if(!t||!t.success){e.textContent=t&&t.message||"Could not load";return}var o=t.data.logs||[];if(!o.length){e.innerHTML='<div style="text-align:center;padding:10px 0;color:rgba(168,168,184,.6)">No fill-ups logged yet.</div>';return}e.innerHTML=o.map(function(e){var t=(e.logged_at||"").slice(0,10);var o=e.odometer_km!=null?" · odo "+parseFloat(e.odometer_km).toFixed(0)+" km":"";return'<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.05)">'+'<div><div style="color:#F5F5F7;font-weight:600">'+t+"</div>"+'<div style="font-size:11px;color:#8a8a9a">'+parseFloat(e.liters).toFixed(1)+" L · ETB "+parseFloat(e.cost_etb).toFixed(0)+o+"</div></div>"+'<button onclick="deleteCourierFuelLog('+e.id+')" style="background:none;border:none;color:#ff6b6b;font-size:15px;cursor:pointer;padding:4px 8px">🗑</button></div>'}).join("")}).catch(function(){e.textContent="Could not load"})}function deleteCourierFuelLog(e){if(!confirm("Remove this fuel log?"))return;fetch(ETEL.api,{method:"POST",headers:{"Content-Type":"application/json",Authorization:"Bearer "+Auth.getToken()},body:JSON.stringify({action:"courier_delete_fuel_log",id:e})}).then(function(e){return e.json()}).then(function(){loadCourierFuelLogs();loadCourierKpiSummary("today")}).catch(function(){showToast("Could not delete","err")})}function doLogout(){if(confirm("Logout?"))Auth.logout()}function showToast(e,t){const o=document.createElement("div");o.style.cssText="position:fixed;bottom:80px;left:50%;transform:translateX(-50%);z-index:999;padding:12px 20px;background:"+(t==="err"?"#E63946":"#2ECC71")+";color:#fff;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.3);white-space:nowrap;max-width:90vw";o.textContent=e;document.body.appendChild(o);setTimeout(function(){o.remove()},3e3)}function openCourierReferral(){let e=document.getElementById("courier-referral-bg");if(!e){e=document.createElement("div");e.id="courier-referral-bg";e.style.cssText="position:fixed;inset:0;z-index:9999;display:flex;align-items:flex-end;justify-content:center;background:rgba(5,5,10,.7);backdrop-filter:blur(4px)";e.addEventListener("click",function(t){if(t.target===e)e.remove()});e.innerHTML='<div id="courier-referral-box" style="width:min(460px,100vw);background:linear-gradient(180deg,#171006,#0a0703);border:1px solid rgba(212,175,55,.25);border-bottom:none;border-radius:20px 20px 0 0;padding:22px 22px max(22px,env(safe-area-inset-bottom,22px));color:#F5EDD8;font-family:inherit"><div style="font-size:17px;font-weight:800;color:#D4AF37;margin-bottom:14px">👥 Invite Friends</div><div id="courier-referral-body" style="text-align:center;padding:20px;color:rgba(245,237,216,.5)">Loading…</div><button onclick="document.getElementById(\'courier-referral-bg\').remove()" style="width:100%;padding:12px;margin-top:10px;border:1px solid rgba(255,255,255,.15);background:transparent;color:#F5EDD8;border-radius:12px;font-weight:700;cursor:pointer">Close</button></div>';document.body.appendChild(e)}apiGet("driver_get_referral_info").then(function(e){const t=e.data||e;const o=t&&t.referral_code||"—";const n=t&&t.referred_count||0;const r="Join me delivering for ETEL Delivery in Hawassa! Use my code "+o+" when you sign up: https://ethelmarket.com/delivery/login?role=courier&mode=register";document.getElementById("courier-referral-body").innerHTML='<p style="text-align:left;font-size:13px;line-height:1.7">Share your code with other couriers. When someone signs up with it, it\'ll count toward your referrals below.</p>'+'<div style="font-family:monospace;font-size:26px;font-weight:800;letter-spacing:.15em;color:#D4AF37;text-align:center;padding:18px;background:rgba(212,175,55,.08);border:1px dashed rgba(212,175,55,.4);border-radius:14px;margin:12px 0">'+o+"</div>"+'<p style="text-align:center;color:rgba(245,237,216,.6);font-size:12px">'+n+" courier"+(n===1?"":"s")+" referred so far</p>"+'<button onclick="etelShareCourierReferral('+JSON.stringify(r)+')" style="display:block;width:100%;padding:13px;border:none;border-radius:12px;background:linear-gradient(135deg,#FFD700,#D4AF37);color:#1a1206;font-weight:700;font-size:13px;cursor:pointer;margin-top:8px">Share Code</button>'}).catch(function(){const e=document.getElementById("courier-referral-body");if(e)e.innerHTML='<div style="color:#E63946">Could not load your code — try again shortly.</div>'})}function etelShareCourierReferral(e){if(navigator.share){navigator.share({text:e}).catch(function(){});return}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(e).then(function(){showToast("Copied to clipboard","ok")})}else{showToast("Share not supported on this browser","err")}}let map,courierMarker;let mapsReady=false,mapWatchId=null;document.addEventListener("etel:maps-failed",function(e){mapsReady=false;if(mapWatchId!==null&&navigator.geolocation){navigator.geolocation.clearWatch(mapWatchId);mapWatchId=null}var t=document.getElementById("map");if(t){var o=e&&e.detail?e.detail.reason:"";var n=o==="auth"?"Map authorization failed.<br><small>Add <b>https://ethelmarket.com/*</b> to the API key’s allowed referrers in Google Cloud Console.</small>":"Map could not load.<br><small>Check your internet connection and try again.</small>";t.innerHTML='<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:12px;padding:24px;text-align:center;color:#A8A8B8">'+'<div style="font-size:34px">⚠️</div>'+'<div style="color:#D4AF37;font-weight:700">Maps API Error</div>'+'<div style="font-size:13px;line-height:1.5">'+n+"</div>"+'<button onclick="location.reload()" style="padding:10px 22px;background:#D4AF37;color:#1a1a2e;border:none;border-radius:8px;font-weight:700;cursor:pointer">Retry</button>'+"</div>"}});const DEFAULT_LOC={lat:7.0504,lng:38.4955};const MAP_STYLES=[{elementType:"geometry",stylers:[{color:"#1a1a2e"}]},{elementType:"labels.text.stroke",stylers:[{color:"#1a1a2e"}]},{elementType:"labels.text.fill",stylers:[{color:"#d4af37"}]},{featureType:"road",elementType:"geometry",stylers:[{color:"#2D2D45"}]},{featureType:"water",elementType:"geometry",stylers:[{color:"#0d1117"}]}];function centerOnMe(){if(!navigator.geolocation){showToast("Geolocation not available","err");return}navigator.geolocation.getCurrentPosition(function(e){const t={lat:e.coords.latitude,lng:e.coords.longitude};if(map){map.setCenter(t);map.setZoom(16)}if(courierMarker)courierMarker.position=t},function(){showToast("Could not get location","err")},{enableHighAccuracy:true,timeout:8e3})}function initMap(){try{if(typeof google==="undefined"||!google.maps)return;var e=document.getElementById("map-loading");if(e)e.style.display="none";var t=document.getElementById("map");if(t&&(!t.offsetHeight||t.offsetHeight<50)){var o=t.parentElement;if(o)o.style.height=Math.max(280,Math.round(window.innerHeight*.5))+"px"}map=new google.maps.Map(t,{zoom:15,center:DEFAULT_LOC,mapId:"ETEL_COURIER_MAP",mapTypeId:google.maps.MapTypeId.HYBRID,mapTypeControl:true,mapTypeControlOptions:{style:google.maps.MapTypeControlStyle.HORIZONTAL_BAR,position:google.maps.ControlPosition.TOP_RIGHT,mapTypeIds:[google.maps.MapTypeId.HYBRID,google.maps.MapTypeId.ROADMAP]},fullscreenControl:true,zoomControl:true});setTimeout(function(){try{google.maps.event.trigger(map,"resize");map.setCenter(DEFAULT_LOC)}catch(e){}},300);setTimeout(function(){try{google.maps.event.trigger(map,"resize");map.setCenter(DEFAULT_LOC)}catch(e){}},1e3);const r={motorcycle:"🛵",bicycle:"🚲",car:"🚗",on_foot:"🚶"};function n(e){const t=document.createElement("div");const o=r[e]||null;if(o){t.style.cssText="width:34px;height:34px;border-radius:50%;background:#1a1a2e;border:3px solid #2ECC71;box-shadow:0 0 0 3px rgba(46,204,113,.35);display:flex;align-items:center;justify-content:center;font-size:18px;line-height:1";t.textContent=o}else{t.style.cssText="width:20px;height:20px;border-radius:50%;background:#2ECC71;border:3px solid #fff;box-shadow:0 0 0 3px rgba(46,204,113,.4)"}return t}const i=n(null);courierMarker=new google.maps.marker.AdvancedMarkerElement({position:DEFAULT_LOC,map:map,title:user?user.name:"Your Location",content:i});apiGet("courier_get_profile",{}).then(function(e){if(e&&e.success&&e.data&&e.data.vehicle_type&&courierMarker){courierMarker.content=n(e.data.vehicle_type)}}).catch(function(){});const a=document.createElement("button");a.textContent="📍 Me";a.onclick=centerOnMe;a.style.cssText="position:absolute;top:10px;left:10px;z-index:10;padding:8px 14px;background:#2ECC71;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.4)";document.getElementById("map").appendChild(a);if(navigator.geolocation){navigator.geolocation.getCurrentPosition(function(e){if(!mapsReady)return;const t={lat:e.coords.latitude,lng:e.coords.longitude};try{map.setCenter(t);map.setZoom(16);if(courierMarker)courierMarker.position=t}catch(e){console.warn("Map center failed:",e)}},null,{enableHighAccuracy:true,timeout:1e4});mapWatchId=navigator.geolocation.watchPosition(function(e){if(!mapsReady||window.ETEL_MAPS_FAILED)return;const t={lat:e.coords.latitude,lng:e.coords.longitude};try{if(courierMarker)courierMarker.position=t;if(map){const e=map.getCenter();if(e){const o=Math.abs(e.lat()-t.lat)+Math.abs(e.lng()-t.lng);if(o>.003)map.panTo(t)}}}catch(e){console.warn("Live tracking update failed:",e)}},null,{enableHighAccuracy:true,maximumAge:15e3,timeout:2e4})}mapsReady=true}catch(s){console.warn("Maps init failed:",s)}initMapNavAutocomplete()}window.initMap=initMap;var dirService=null,dirRenderer=null,mapNavAC=null;function ensureDirections(){if(!map||typeof google==="undefined")return false;if(!dirService)dirService=new google.maps.DirectionsService;if(!dirRenderer){dirRenderer=new google.maps.DirectionsRenderer({map:map,suppressMarkers:false,polylineOptions:{strokeColor:"#1A73E8",strokeWeight:6,strokeOpacity:.9}})}return true}function drawRouteOnMap(e,t,o){if(!ensureDirections()){showToast("Map not ready — try again","err");return}var n=courierLocationIsPlausible();var r=n?new google.maps.LatLng(courierLat,courierLng):new google.maps.LatLng(HAWASSA_CENTER.lat,HAWASSA_CENTER.lng);if(!n&&courierLat&&courierLng&&!_locWarnedThisSession){_locWarnedThisSession=true;showToast("Your device location looks inaccurate (too far from Hawassa) — showing route from central Hawassa instead. Enable precise/GPS location for accurate routing.","err")}document.getElementById("map-route-time").textContent="…";document.getElementById("map-route-dist").textContent="Calculating…";document.getElementById("map-route-label").textContent=o||"Destination";document.getElementById("map-nav-bar").style.display="block";document.getElementById("map-nav-input").value=o||"";document.getElementById("map-route-panel").style.display="block";dirService.route({origin:r,destination:new google.maps.LatLng(e,t),travelMode:google.maps.TravelMode.DRIVING},function(o,n){if(n==="OK"){dirRenderer.setDirections(o);var r=o.routes[0].legs[0];document.getElementById("map-route-time").textContent=r.duration.text;document.getElementById("map-route-dist").textContent=r.distance.text}else{document.getElementById("map-route-time").textContent="—";document.getElementById("map-route-dist").textContent="Route unavailable";map.panTo({lat:e,lng:t});map.setZoom(15)}})}function clearRoute(){if(dirRenderer)dirRenderer.setDirections({routes:[]});document.getElementById("map-nav-bar").style.display="none";document.getElementById("map-route-panel").style.display="none"}function navigateToShop(e,t,o){if(t&&o){drawRouteOnMap(t,o,"Shop · "+e);return}var n=ZONE_COORDS[e];if(n){drawRouteOnMap(n.lat,n.lng,"Shop · "+e)}else if(typeof google!=="undefined"&&google.maps){var r=new google.maps.Geocoder;r.geocode({address:e+", Hawassa, Ethiopia",region:"ET"},function(t,o){if(o==="OK"&&t[0]){var n=t[0].geometry.location;drawRouteOnMap(n.lat(),n.lng(),"Shop · "+e)}else{showToast("Could not find location for "+e,"err")}})}else{showToast("Map not ready — try again in a moment","err")}}function navigateToCustomer(e,t,o){if(e&&t){drawRouteOnMap(parseFloat(e),parseFloat(t),o||"Customer location")}else if(o&&typeof google!=="undefined"&&google.maps){var n=new google.maps.Geocoder;n.geocode({address:o+", Hawassa, Ethiopia",region:"ET"},function(e,t){if(t==="OK"&&e[0]){var n=e[0].geometry.location;drawRouteOnMap(n.lat(),n.lng(),o||"Customer")}else{showToast("Could not find customer location","err")}})}else{showToast("Customer location not available","err")}}function initMapNavAutocomplete(){var e=document.getElementById("map-nav-input");if(!e||mapNavAC||typeof google==="undefined"||!google.maps||!google.maps.places)return;try{mapNavAC=new google.maps.places.Autocomplete(e,{componentRestrictions:{country:"et"},fields:["geometry","name","formatted_address"]});mapNavAC.addListener("place_changed",function(){var t=mapNavAC.getPlace();if(!t||!t.geometry)return;drawRouteOnMap(t.geometry.location.lat(),t.geometry.location.lng(),t.formatted_address||t.name||e.value)})}catch(e){console.warn("Places autocomplete unavailable:",e)}}function urlBase64ToUint8Array(e){var t="=".repeat((4-e.length%4)%4);var o=(e+t).replace(/-/g,"+").replace(/_/g,"/");var n=window.atob(o);var r=new Uint8Array(n.length);for(var i=0;i<n.length;++i)r[i]=n.charCodeAt(i);return r}function subscribeCourierToPush(){if(!("serviceWorker"in navigator)||!("PushManager"in window))return;navigator.serviceWorker.ready.then(function(e){return e.pushManager.getSubscription().then(function(t){if(t)return t;return fetch(ETEL.api+"?action=get_vapid_public_key").then(function(e){return e.json()}).then(function(t){if(!t||!t.success||!t.data||!t.data.publicKey)throw new Error("VAPID key unavailable");return e.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:urlBase64ToUint8Array(t.data.publicKey)})})})}).then(function(e){if(!e)return;return fetch(ETEL.api,{method:"POST",headers:{Authorization:"Bearer "+Auth.getToken(),"Content-Type":"application/json"},body:JSON.stringify({action:"save_push_subscription",subscription:e.toJSON()})})}).catch(function(e){console.error("[push] subscription setup failed",e)})}function maybeRequestCourierNotifications(){if(!("Notification"in window))return;if(Notification.permission==="granted"){subscribeCourierToPush();return}if(Notification.permission!=="default")return;try{if(localStorage.getItem("etel_courier_notif_asked"))return}catch(e){}showToast("Enable notifications to hear about new jobs even when the app is in the background","ok");setTimeout(function(){Notification.requestPermission().then(function(e){try{localStorage.setItem("etel_courier_notif_asked","1")}catch(e){}if(e==="granted")subscribeCourierToPush()})},1500)}loadAvailableJobs();maybeRequestCourierNotifications();initCourierSlideOnline();setCourierOnlineUI(false);loadCourierOnlineStatus();let COURIER_IS_ONLINE=false;function loadCourierOnlineStatus(){apiGet("courier_get_profile",{}).then(function(e){if(e&&e.success&&e.data&&e.data.status==="online"){COURIER_IS_ONLINE=true;setCourierOnlineUI(true);if(typeof window.showCourierOnlineReminder==="function")window.showCourierOnlineReminder()}}).catch(function(){})}function setCourierOnlineUI(e){var t=document.getElementById("c-slide-online-wrap");var o=document.getElementById("c-online-pill");if(t)t.style.display=e?"none":"";if(o)o.style.display=e?"flex":"none";document.body.classList.toggle("is-offline",!e)}function courierGoOnline(){apiGet("courier_go_online",{}).then(function(e){if(e&&e.success){COURIER_IS_ONLINE=true;setCourierOnlineUI(true);if(navigator.vibrate)navigator.vibrate([12,40,12]);if(typeof window.showCourierOnlineReminder==="function")window.showCourierOnlineReminder();showToast("You're online","ok")}else{showToast(e&&e.message||"Could not go online","err")}}).catch(function(){showToast("Could not go online","err")})}function courierGoOffline(){if(!confirm("Go offline? You will stop appearing available for new deliveries."))return;apiGet("courier_go_offline",{}).then(function(e){if(e&&e.success){COURIER_IS_ONLINE=false;setCourierOnlineUI(false);if(navigator.vibrate)navigator.vibrate(12);if(typeof window.clearCourierOnlineReminder==="function")window.clearCourierOnlineReminder();showToast("You're offline","ok")}else{showToast(e&&e.message||"Could not go offline","err")}}).catch(function(){showToast("Could not go offline","err")})}function initCourierSlideOnline(){var e=document.getElementById("c-slide-online-track");var t=document.getElementById("c-slide-online-thumb");var o=document.getElementById("c-slide-online-fill");if(!e||!t)return;var n=false,r=0,i=0;function a(e){return e.touches?e.touches[0].clientX:e.clientX}function s(e){if(COURIER_IS_ONLINE)return;n=true;r=a(e);i=0;e.preventDefault()}function l(s){if(!n)return;var l=e.offsetWidth-t.offsetWidth-12;if(l<=0)return;var d=r-a(s);d=Math.max(0,Math.min(d,l));i=d/l;t.style.transform="translateX("+-d+"px)";if(o)o.style.transform="scaleX("+i+")";s.preventDefault()}function d(){if(!n)return;n=false;if(i>=.75){var r=e.offsetWidth-t.offsetWidth-12;t.style.transform="translateX("+-r+"px)";if(o)o.style.transform="scaleX(1)";setTimeout(function(){t.style.transform="";if(o)o.style.transform="";courierGoOnline()},180)}else{t.style.transition="transform .25s";t.style.transform="translateX(0)";if(o)o.style.transform="scaleX(0)";i=0;setTimeout(function(){t.style.transition=""},260)}}e.addEventListener("mousedown",s);e.addEventListener("touchstart",s,{passive:false});document.addEventListener("mousemove",l);document.addEventListener("touchmove",l,{passive:false});document.addEventListener("mouseup",d);document.addEventListener("touchend",d);var c=document.getElementById("c-online-pill");if(c)c.addEventListener("click",courierGoOffline);document.addEventListener("visibilitychange",function(){if(document.visibilityState==="hidden"&&COURIER_IS_ONLINE&&typeof window.showCourierOnlineReminder==="function"){window.showCourierOnlineReminder()}})}function loadCourierKpiSummary(e){var t=document.getElementById("cp-kpi-body");if(!t)return;document.querySelectorAll(".cp-kpi-period-btn").forEach(function(t){var o=t.getAttribute("data-period")===e;t.style.background=o?"rgba(212,175,55,.1)":"transparent";t.style.borderColor=o?"rgba(212,175,55,.3)":"rgba(255,255,255,.1)";t.style.color=o?"#F2CC6B":"#A8A8B8"});t.textContent="Loading…";apiGet("courier_get_kpi_summary",{period:e||"today"}).then(function(e){if(!e||!e.success){t.textContent=e&&e.message||"Could not load";return}var o=e.data;var n=o.net_etb_per_hour!=null?"ETB "+Math.round(o.net_etb_per_hour)+"/hr":"—";var r=o.net_after_fuel!=null?'<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06)"><span style="color:#A8A8B8">Net after fuel'+(o.using_real_fuel_data?"":"")+'</span><b style="color:#F2CC6B">ETB '+Math.round(o.net_after_fuel)+"</b></div>":"";t.innerHTML='<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06)"><span style="color:#A8A8B8">Net ETB/hour</span><b style="color:#F2CC6B">'+n+"</b></div>"+'<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06)"><span style="color:#A8A8B8">Deliveries</span><b>'+o.completed_deliveries+"</b></div>"+'<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06)"><span style="color:#A8A8B8">Earnings</span><b style="color:#2ECC71">ETB '+Math.round(o.earnings)+"</b></div>"+r+'<div style="display:flex;justify-content:space-between;padding:8px 0"><span style="color:#A8A8B8">Online time</span><b>'+o.online_hours.toFixed(1)+" hrs</b></div>"+(o.note?'<div style="font-size:11px;color:#8a8a9a;margin-top:6px">'+o.note+"</div>":"")}).catch(function(){t.textContent="Could not load"})}setInterval(function(){var e=document.querySelector(".tab-content.active");if(e&&e.id==="jobs"){apiGet("courier_get_available_orders",{}).then(function(e){if(e.success&&e.data.orders){if(!window._seenJobIds)window._seenJobIds={};var t={};e.data.orders.forEach(function(e){t[e.id]=true});Object.keys(window._seenJobIds).forEach(function(e){if(!t[e]){stopRingingForJob(e);delete window._seenJobIds[e]}});var o=false;e.data.orders.forEach(function(e){if(!window._seenJobIds[e.id]){window._seenJobIds[e.id]=true;startRingingForJob(e.id);o=true}});availableJobs=e.data.orders;if(o)showToast("📦 New job available!","ok");renderJobs(availableJobs)}}).catch(function(){})}},2e4);setInterval(function(){var e=document.querySelector(".tab-content.active");if(e&&e.id==="active"){loadActiveOrders()}},15e3);setInterval(function(){apiGet("get_my_notifications",{}).then(function(e){if(e.success&&e.data.unread>0){var t=document.querySelector('.tab-btn[onclick*="active"]');if(t&&!t.querySelector(".notif-badge")){var o=document.createElement("span");o.className="notif-badge";o.style.cssText="display:inline-block;background:#E63946;color:#fff;border-radius:10px;font-size:9px;padding:1px 5px;margin-left:4px;font-weight:700";o.textContent=e.data.unread;t.appendChild(o)}}}).catch(function(){})},6e4);(function(){"use strict";var e=matchMedia("(prefers-reduced-motion: reduce)").matches;function t(e){if(document.readyState!=="loading")e();else document.addEventListener("DOMContentLoaded",e)}function o(){try{return typeof apiGet==="function"?apiGet:null}catch(e){return null}}function n(){try{return typeof user!=="undefined"?user:null}catch(e){return null}}t(function(){s();p();f();r()});window._courierMapCollapsed=false;function r(){}window.toggleCourierMap=function(){window._courierMapCollapsed=!window._courierMapCollapsed;var e=document.getElementById("courier-map-container");var t=document.querySelector(".header");var o=document.querySelector(".sidebar");var n=document.getElementById("courier-map-expand-btn");if(window._courierMapCollapsed){if(e){e.style.height="100vh";e.style.maxHeight="100vh"}if(t){t.style.opacity="0";t.style.pointerEvents="none"}if(o){o.style.opacity="0";o.style.pointerEvents="none"}if(n){n.innerHTML="✕";n.title="Show panel"}var r=document.getElementById("courier-map-fab");if(!r){r=document.createElement("button");r.id="courier-map-fab";r.style.cssText="position:fixed;bottom:30px;left:50%;transform:translateX(-50%);z-index:12;"+"background:rgba(212,175,55,.95);color:#0d0d0d;padding:10px 22px;border-radius:999px;"+"font-family:Poppins,sans-serif;font-size:13px;font-weight:800;cursor:pointer;border:none;"+"box-shadow:0 6px 20px rgba(0,0,0,.4);display:none;white-space:nowrap;";r.textContent="🗺 Full Map — tap to restore panel";r.onclick=function(){window.toggleCourierMap()};document.body.appendChild(r)}r.style.display="block"}else{if(e){e.style.height="";e.style.maxHeight=""}if(t){t.style.opacity="1";t.style.pointerEvents=""}if(o){o.style.opacity="1";o.style.pointerEvents=""}if(n){n.innerHTML="⤢";n.title="Full map"}var i=document.getElementById("courier-map-fab");if(i)i.style.display="none"}};function i(){try{if(navigator.setAppBadge)navigator.setAppBadge()}catch(e){}if(!("Notification"in window)||!navigator.serviceWorker)return;function e(){navigator.serviceWorker.ready.then(function(e){e.showNotification("ETEL Courier — Available",{body:"Ready for deliveries. Tap to return to the courier app.",icon:"./icons/icon-192.png",badge:"./icons/icon-72.png",tag:"etel-online-status",renotify:false,requireInteraction:true,silent:true,data:"./courier.html"}).catch(function(){})})}if(Notification.permission==="granted")e();else if(Notification.permission==="default"){Notification.requestPermission().then(function(t){if(t==="granted")e()})}}function a(){try{if(navigator.clearAppBadge)navigator.clearAppBadge()}catch(e){}if(!navigator.serviceWorker)return;navigator.serviceWorker.ready.then(function(e){e.getNotifications({tag:"etel-online-status"}).then(function(e){e.forEach(function(e){e.close()})})}).catch(function(){})}window.showCourierOnlineReminder=i;window.clearCourierOnlineReminder=a;function s(){var e=document.querySelector(".sidebar");var t=e&&e.querySelector(".tabs");if(!e||!t||document.querySelector(".earn-card"))return;var o=document.createElement("div");o.className="earn-card";o.innerHTML='<div class="earn-top">'+'<span class="earn-label">Today’s Earnings</span>'+'<button class="earn-refresh" title="Refresh" aria-label="Refresh earnings">↻</button>'+"</div>"+'<div class="earn-amount"><span class="cur">ETB</span><span id="earn-today">0.00</span></div>'+'<div class="earn-sub">'+'<div class="earn-stat"><b id="earn-count">0</b><span>Deliveries</span></div>'+'<div class="earn-stat"><b id="earn-total">ETB 0</b><span>All-time</span></div>'+"</div>";e.insertBefore(o,t);o.querySelector(".earn-refresh").addEventListener("click",function(){var e=this;e.classList.add("spinning");setTimeout(function(){e.classList.remove("spinning")},700);c()});c();setInterval(function(){if(!document.hidden)c()},6e4)}function l(e){if(!e)return false;var t=new Date(e),o=new Date;return t.getFullYear()===o.getFullYear()&&t.getMonth()===o.getMonth()&&t.getDate()===o.getDate()}var d=0;function c(){var e=o();if(!e){if(d++<25)return setTimeout(c,200);return}e("courier_get_my_orders",{status:"delivered"}).then(function(e){if(!e||!e.success||!e.data)return;var t=e.data.orders||[];var o=0,n=0;t.forEach(function(e){var t=parseFloat(e.fare_total||0)||0;o+=t;var r=e.delivered_at||e.completed_at||e.updated_at||e.placed_at;if(l(r))n+=t});u(document.getElementById("earn-today"),n,2);u(document.getElementById("earn-count"),t.length,0);var r=document.getElementById("earn-total");if(r)r.textContent="ETB "+Math.round(o).toLocaleString()}).catch(function(){})}function u(t,o,n){if(!t)return;if(e){t.textContent=o.toFixed(n);return}var r=parseFloat((t.textContent||"0").replace(/[^0-9.]/g,""))||0;var i=performance.now(),a=650;function s(e){var l=Math.min((e-i)/a,1);var d=1-Math.pow(1-l,3);t.textContent=(r+(o-r)*d).toFixed(n);if(l<1)requestAnimationFrame(s);else t.textContent=o.toFixed(n)}requestAnimationFrame(s)}function p(){if(!navigator.vibrate||e)return;document.addEventListener("click",function(e){var t=e.target.closest&&e.target.closest(".btn-primary, .nav-btn");if(t)navigator.vibrate(15)},true)}function f(){document.querySelectorAll(".tab-btn").forEach(function(e){e.addEventListener("click",function(){setTimeout(c,600)})})}window.refreshEarningsCard=c})();

/* Courier v4: live "waiting" timer on each available job (time since the order was placed). */
(function(){function pad(n){return n<10?"0"+n:""+n}function tick(){var els=document.querySelectorAll(".job-wait");var now=Date.now();for(var i=0;i<els.length;i++){var el=els[i];var raw=el.getAttribute("data-placed")||"";var t=new Date(raw.replace(" ","T")).getTime();if(isNaN(t)){el.style.display="none";continue}var sec=Math.floor((now-t)/1000);if(sec<0||sec>86400){el.style.display="none";continue}el.style.display="";el.textContent="Waiting "+pad(Math.floor(sec/60))+":"+pad(sec%60);el.className="job-wait "+(sec<180?"wait-ok":sec<480?"wait-warn":"wait-hot")}}setInterval(tick,1000);tick()})();

/* Courier v5: expiring job offers. The server (api/lib/courier_offers.php) assigns an order with a time limit when the
   admin has turned offers on; this shows the Accept / Decline card with a countdown, rings, and keeps polling
   (offers=1 also tells the server this app can show offers). Without offers enabled none of this ever appears. */
(function(){
  var seen={};
  window.etelOfferCardHtml=function(o){
    var left=Math.max(0,parseInt(o.offer_seconds_left,10)||0);
    var exp=Date.now()+left*1000;
    var fee=parseFloat(o.delivery_fee!=null?o.delivery_fee:0).toFixed(2);
    return '<div class="job-card offer-card" data-offer-order="'+o.id+'" data-offer-exp="'+exp+'" data-offer-total="'+Math.max(left,1)+'">'+
      '<div class="offer-head"><span class="offer-tag">NEW JOB OFFER</span><span class="offer-count" data-offer-count></span></div>'+
      '<div class="offer-bar"><span class="offer-bar-fill" data-offer-fill></span></div>'+
      '<div class="job-info">'+
      '<div class="job-address"><span class="job-address-icon">🏪</span><span>Pickup: <strong>'+escapeHtml(o.sg_business_name||o.from_zone||"Shop")+'</strong></span></div>'+
      '<div class="job-address" style="margin-top:6px"><span class="job-address-icon">📍</span><span>Dropoff: '+escapeHtml(o.to_zone||"—")+'</span></div>'+
      '<div class="job-fee">You earn: ETB '+fee+'</div>'+
      '<div class="job-meta"><div class="job-meta-item">'+escapeHtml(o.order_code||"")+'</div>'+(o.payment_method?'<div class="job-meta-item">'+paymentBadgeHtml(o.payment_method)+'</div>':'')+'</div>'+
      '<div class="job-actions"><button class="btn btn-primary" onclick="etelOfferAccept('+o.id+')">Accept</button><button class="btn btn-secondary" onclick="etelOfferDecline('+o.id+')">Decline</button></div></div></div>';
  };
  window.etelOfferAccept=async function(id){
    stopRingingForJob(id);
    var r=null;try{r=await api("courier_accept_offer",{order_id:id})}catch(e){}
    if(r&&r.success){showToast("Job accepted! Head to the shop.")}else{showToast((r&&r.message)||"This offer is no longer available")}
    loadActiveOrders();
  };
  window.etelOfferDecline=async function(id){
    stopRingingForJob(id);
    var r=null;try{r=await api("courier_decline_offer",{order_id:id})}catch(e){}
    showToast("Offer declined");
    loadActiveOrders();
  };
  function showActiveTab(){
    document.querySelectorAll(".tab-content").forEach(function(x){x.classList.remove("active")});
    document.querySelectorAll(".tab-btn").forEach(function(x){x.classList.remove("active")});
    var a=document.getElementById("active");if(a)a.classList.add("active");
    var b=document.querySelectorAll(".tab-btn")[1];if(b)b.classList.add("active");
  }
  function pad(n){return n<10?"0"+n:""+n}
  setInterval(function(){
    var cards=document.querySelectorAll(".offer-card");
    for(var i=0;i<cards.length;i++){
      var c=cards[i],exp=parseInt(c.getAttribute("data-offer-exp"),10),tot=parseInt(c.getAttribute("data-offer-total"),10)||1;
      var secs=Math.max(0,Math.ceil((exp-Date.now())/1000));
      var cnt=c.querySelector("[data-offer-count]"),fill=c.querySelector("[data-offer-fill]");
      if(cnt)cnt.textContent=Math.floor(secs/60)+":"+pad(secs%60);
      if(fill)fill.style.width=Math.max(0,Math.min(100,secs*100/tot))+"%";
      c.classList.toggle("offer-urgent",secs<=10);
      if(secs<=0&&!c.getAttribute("data-offer-done")){
        c.setAttribute("data-offer-done","1");
        stopRingingForJob(parseInt(c.getAttribute("data-offer-order"),10));
        setTimeout(function(){loadActiveOrders()},1200);
      }
    }
  },1000);
  function poll(){
    if(document.visibilityState==="hidden"||document.body.classList.contains("is-offline"))return;
    apiGet("courier_get_my_orders",{offers:1}).then(function(r){
      if(!r||!r.success)return;
      var list=(r.data.orders||[]).filter(function(o){return o.status!=="delivered"&&o.status!=="cancelled"});
      var offers=list.filter(function(o){return o.offer_pending});
      var ids={},fresh=false;
      offers.forEach(function(o){ids[o.id]=1;if(!seen[o.id]){seen[o.id]=1;fresh=true;startRingingForJob(o.id)}});
      var gone=false;
      Object.keys(seen).forEach(function(k){if(!ids[k]){delete seen[k];stopRingingForJob(parseInt(k,10));gone=true}});
      if(fresh){showActiveTab();renderActiveOrders(list);showToast("New job offer - accept it before the timer runs out")}
      else if(gone){renderActiveOrders(list)}
    }).catch(function(){});
  }
  setInterval(poll,5000);
  setTimeout(poll,2500);
})();

/* ---- release v7: js/courier-packages.js (added to v5) ---- */
/* ETEL Courier - package delivery (courier app release v7)
 *
 * A customer can send a package with no shop involved (api/lib/package_orders.php). This adds the courier side:
 *   - the job cards and the job offer say "package" and show the pickup ADDRESS (there is no shop to go to),
 *   - an accepted package has its own card: a progress strip, a trip clock (running timer for the leg in progress, clock time and duration
 *     of each finished leg, total), where to go, the contact, what it is, and the PIN step: the SENDER's pickup PIN picks it up, the
 *     RECIPIENT's delivery PIN delivers it (api actions courier_package_pickup / courier_package_deliver; 5 wrong PINs lock the step 15 minutes),
 *   - "Navigate" works INSIDE the app: it scrolls to the map and draws the route with distance and arrival time (the app's own
 *     drawRouteOnMap), and the route for the current leg is drawn automatically when a package becomes active. It no longer opens Google Maps,
 *   - when the package is delivered a short summary shows what was earned and how long each part took,
 *   - the app tells the server it can do all this (pkg=1 on its polls). The server never offers a package to an app that did not.
 * Shop orders are untouched: they still go through the original functions. This file is appended to the v5 bundle to make v7
 * (js/releases/courier/v7/courier-bundle.js, built by tools/build_courier_release.py) and is released to canary testers first.
 * It only wraps globals the bundle defines: apiGet, renderJobs, renderActiveOrders, etelOfferCardHtml, drawRouteOnMap, navigateToCustomer.
 * Times come from the server (order.pkg_times, epoch seconds on the database clock), so the phone's clock never matters.
 */
(function () {
    'use strict';
    if (window.__etelCourierPackages) return;
    window.__etelCourierPackages = 'v7';

    var SIZE = { document: 'Document or envelope', small: 'Small (shoebox)', medium: 'Medium (backpack)', large: 'Large (box, suitcase)' };

    function esc(s) {
        s = s == null ? '' : String(s);
        return typeof escapeHtml === 'function' ? escapeHtml(s) : s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function isPkg(o) { return !!o && o.order_kind === 'package'; }
    function money(n) { return 'ETB ' + (parseFloat(n) || 0).toFixed(2); }
    function toast(msg, kind) { if (typeof showToast === 'function') showToast(msg, kind); }

    // ---- styles (one block; light theme is body.etel-light) ----
    var CSS =
        '.pk7{--pk-gold:#D4AF37;--pk-text:#F5F5F7;--pk-dim:#A8A8B8;--pk-line:rgba(255,255,255,.12);--pk-panel:rgba(255,255,255,.05);--pk-ok:#2ECC71}' +
        'body.etel-light .pk7{--pk-gold:#8a6d00;--pk-text:#1A1A2E;--pk-dim:#5c5a70;--pk-line:rgba(0,0,0,.12);--pk-panel:rgba(0,0,0,.04);--pk-ok:#1b7f46}' +
        '.pk7 .pk7-top{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px}' +
        '.pk7 .pk7-code{font-weight:800;letter-spacing:.04em;color:var(--pk-text)}' +
        '.pk7 .pk7-pill{margin-left:auto;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;background:rgba(212,175,55,.16);color:var(--pk-gold);border:1px solid rgba(212,175,55,.4)}' +
        '.pk7 .pk7-steps{display:flex;align-items:center;gap:0;margin:2px 0 12px}' +
        '.pk7 .pk7-step{flex:1;text-align:center;font-size:11px;font-weight:700;color:var(--pk-dim)}' +
        '.pk7 .pk7-step i{display:block;width:22px;height:22px;margin:0 auto 4px;border-radius:50%;border:2px solid var(--pk-line);font-style:normal;font-size:12px;line-height:18px;color:#1a1200;box-sizing:border-box}' +
        '.pk7 .pk7-step.on{color:var(--pk-text)}.pk7 .pk7-step.on i{border-color:var(--pk-gold);box-shadow:0 0 0 0 rgba(212,175,55,.5);animation:pk7pulse 1.8s ease-out infinite}' +
        '.pk7 .pk7-step.done i{background:var(--pk-gold);border-color:var(--pk-gold)}' +
        '.pk7 .pk7-bar{flex:0 0 18%;height:2px;background:var(--pk-line);margin-bottom:18px}.pk7 .pk7-bar.done{background:var(--pk-gold)}' +
        '@keyframes pk7pulse{0%{box-shadow:0 0 0 0 rgba(212,175,55,.55)}70%,100%{box-shadow:0 0 0 9px rgba(212,175,55,0)}}' +
        '@media (prefers-reduced-motion:reduce){.pk7 .pk7-step.on i{animation:none}}' +
        '.pk7 .pk7-clock{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 14px;border-radius:14px;background:linear-gradient(135deg,rgba(212,175,55,.2),rgba(212,175,55,.05));border:1px solid rgba(212,175,55,.35);margin-bottom:12px}' +
        '.pk7 .pk7-clock-k{display:block;font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--pk-gold)}' +
        '.pk7 .pk7-clock-v{display:block;font-size:30px;font-weight:800;line-height:1.1;font-variant-numeric:tabular-nums;color:var(--pk-text)}' +
        '.pk7 .pk7-clock-side{text-align:right;font-size:12px;line-height:1.55;color:var(--pk-dim)}.pk7 .pk7-clock-side b{color:var(--pk-text);font-variant-numeric:tabular-nums}' +
        '.pk7 .pk7-earn{display:flex;align-items:baseline;justify-content:space-between;gap:8px;margin:2px 0 10px;padding:10px 14px;border-radius:12px;background:rgba(46,204,113,.12);border:1px solid rgba(46,204,113,.35)}' +
        '.pk7 .pk7-earn span{font-size:12px;font-weight:700;color:var(--pk-dim)}.pk7 .pk7-earn b{font-size:22px;color:var(--pk-ok);font-variant-numeric:tabular-nums}' +
        '.pk7 .pk7-place{display:flex;gap:11px;align-items:flex-start;margin:10px 0}.pk7 .pk7-place .ic{flex:0 0 34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:var(--pk-panel);border:1px solid var(--pk-line);font-size:17px}' +
        '.pk7 .pk7-place .k{font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--pk-dim)}.pk7 .pk7-place .v{font-size:15.5px;font-weight:700;line-height:1.3;color:var(--pk-text);overflow-wrap:anywhere}' +
        '.pk7 .pk7-place .w{font-size:12.5px;color:var(--pk-dim);margin-top:2px}' +
        '.pk7 .pk7-meta{display:flex;flex-wrap:wrap;gap:6px;margin:6px 0 10px}.pk7 .pk7-chip{padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600;background:var(--pk-panel);border:1px solid var(--pk-line);color:var(--pk-text)}' +
        '.pk7 .pk7-cash{margin:8px 0;padding:10px 12px;border-radius:10px;background:rgba(230,126,34,.14);border:1px solid rgba(230,126,34,.45);color:#F0A560;font-size:12.5px;font-weight:700}' +
        'body.etel-light .pk7 .pk7-cash{color:#8a4b00}' +
        '.pk7 .pk7-actions{display:flex;gap:8px;margin:10px 0 8px}.pk7 .pk7-nav{flex:1;display:flex;align-items:center;justify-content:center;gap:8px;padding:13px 14px;border:0;border-radius:12px;font-size:14.5px;font-weight:800;cursor:pointer;color:#1a1a2e;background:linear-gradient(135deg,#E5C76B,#D4AF37 55%,#b8922e)}' +
        '.pk7 .pk7-nav.drop{background:linear-gradient(135deg,#59e096,#2ECC71 55%,#1fa85a);color:#06220f}' +
        '.pk7 .pk7-call{display:flex;align-items:center;justify-content:center;gap:6px;padding:13px 14px;border-radius:12px;border:1px solid rgba(46,204,113,.5);color:var(--pk-ok);font-size:14px;font-weight:800;text-decoration:none;background:transparent}' +
        '.pk7 .pk7-pin{width:100%;padding:14px;border-radius:12px;border:1.5px solid var(--pk-gold);background:rgba(212,175,55,.1);color:var(--pk-text);font-size:14.5px;font-weight:800;cursor:pointer}' +
        '.pk7 .pk7-hint{font-size:11.5px;color:var(--pk-dim);margin-top:6px;text-align:center}' +
        '.pk7 .pk7-route{position:relative;margin:6px 0 10px}.pk7 .pk7-route .pk7-place{margin:0 0 12px}.pk7 .pk7-route .pk7-place:last-child{margin-bottom:0}' +
        '#pk7-eta{margin-top:4px;font-size:12px;font-weight:700;color:#4ECDC4}' +
        '#pkg-pin-overlay .pk7-pinbox{position:relative;margin:6px 0 4px}#pkg-pin-overlay .pk7-digits{display:flex;justify-content:center;gap:10px}' +
        '#pkg-pin-overlay .pk7-digit{width:54px;height:66px;border-radius:14px;border:1.5px solid rgba(212,175,55,.35);background:rgba(255,255,255,.06);display:flex;align-items:center;justify-content:center;font-size:30px;font-weight:800;color:#fff;font-variant-numeric:tabular-nums}' +
        '#pkg-pin-overlay .pk7-digit.cur{border-color:#D4AF37;box-shadow:0 0 0 3px rgba(212,175,55,.25)}' +
        '#pkg-pin-input{position:absolute;inset:0;width:100%;height:100%;opacity:0;font-size:16px;border:0;background:transparent;color:transparent;caret-color:transparent}';
    function injectCss() {
        if (document.getElementById('pk7-css') || !document.head) return;
        var s = document.createElement('style'); s.id = 'pk7-css'; s.textContent = CSS; document.head.appendChild(s);
    }
    injectCss();
    document.addEventListener('DOMContentLoaded', injectCss);

    // ---- 1. tell the server this app can run packages ----
    var origGet = window.apiGet;
    if (typeof origGet === 'function') {
        window.apiGet = function (action, params) {
            if (action === 'courier_get_my_orders' || action === 'courier_get_available_orders') params = Object.assign({ pkg: 1 }, params || {});
            return origGet.call(this, action, params);
        };
    }

    // ---- small pieces ----
    function callLink(phone, label) {
        if (!phone) return '';
        var p = String(phone).replace(/[^\d+]/g, '');
        return '<a class="pk7-call" href="tel:' + esc(p) + '" aria-label="' + esc(label) + '" title="' + esc(label) + '">📞 Call</a>';
    }
    function details(o) {
        var bits = [];
        if (o.pkg_size) bits.push(SIZE[o.pkg_size] || o.pkg_size);
        if (parseFloat(o.pkg_weight_kg) > 0) bits.push(parseFloat(o.pkg_weight_kg) + ' kg');
        if (parseFloat(o.distance_km) > 0) bits.push(parseFloat(o.distance_km) + ' km trip');
        return bits.join(' · ');
    }
    function chips(o) {
        var c = details(o).split(' · ').filter(Boolean);
        if (o.pkg_desc) c.push(o.pkg_desc);
        return c.length ? '<div class="pk7-meta">' + c.map(function (x) { return '<span class="pk7-chip">' + esc(x) + '</span>'; }).join('') + '</div>' : '';
    }
    function place(icon, kind, addr, who) {
        return '<div class="pk7-place"><span class="ic" aria-hidden="true">' + icon + '</span><div><div class="k">' + esc(kind) + '</div><div class="v">' + esc(addr) + '</div>' + (who ? '<div class="w">' + esc(who) + '</div>' : '') + '</div></div>';
    }

    // ---- times: server epoch seconds, so the phone's clock never matters ----
    var TM = { offset: 0, byId: {}, timer: null };
    function nowSec() { return Math.floor(Date.now() / 1000) - TM.offset; }
    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function clockText(sec) { sec = Math.max(0, sec); var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), x = sec % 60; return (h ? h + ':' + pad2(m) : pad2(m)) + ':' + pad2(x); }
    function durText(sec) {
        sec = Math.max(0, Math.round(sec));
        if (sec < 60) return sec + ' s';
        var m = Math.floor(sec / 60);
        if (m < 60) return m + ' min' + (m < 10 ? ' ' + pad2(sec % 60) + ' s' : '');
        return Math.floor(m / 60) + ' h ' + pad2(m % 60) + ' min';
    }
    function wallClock(sec) { try { return new Date(sec * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); } catch (e) { return ''; } }
    function learnTimes(orders) {
        (orders || []).forEach(function (o) {
            if (o && o.pkg_times) { TM.byId[o.id] = o.pkg_times; if (o.pkg_times.now) TM.offset = Math.floor(Date.now() / 1000) - o.pkg_times.now; }
        });
    }
    function tick() {
        var els = document.querySelectorAll('[data-pt-from]');
        if (!els.length) { clearInterval(TM.timer); TM.timer = null; return; }
        Array.prototype.forEach.call(els, function (el) { el.textContent = clockText(nowSec() - parseInt(el.getAttribute('data-pt-from'), 10)); });
    }
    function startTick() { if (!TM.timer) TM.timer = setInterval(tick, 1000); }

    // ---- in-app navigation ----
    // The app already draws a route (with distance and time) on its own map: use it. No external map is opened.
    function mapReady() { return typeof google !== 'undefined' && google.maps && google.maps.DirectionsService; }
    function navTo(lat, lng, addr, label, scroll) {
        if (scroll !== false) {
            var c = document.getElementById('courier-map-container') || document.getElementById('map');
            try { if (c && c.scrollIntoView) c.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
        }
        if (typeof navigateToCustomer === 'function') navigateToCustomer(lat, lng, label || addr);
        else toast('Map not ready. Try again in a moment.', 'err');
    }
    var routed = {};   // order id + leg, so the route is drawn once per leg and a courier who clears it is not nagged
    function autoRoute(o) {
        var picked = o.status === 'picked_up' || o.status === 'in_transit';
        var key = o.id + ':' + (picked ? 'drop' : 'pick');
        if (routed[key] || !mapReady()) return;
        routed[key] = true;
        var t = leg(o, picked);
        navTo(t.lat, t.lng, t.addr, t.label, false);
    }
    function leg(o, picked) {
        return picked
            ? { lat: o.delivery_lat, lng: o.delivery_lng, addr: o.recipient_address || o.to_zone, label: 'Drop-off · ' + (o.recipient_address || o.to_zone || '') }
            : { lat: o.pickup_lat, lng: o.pickup_lng, addr: o.pickup_address || o.from_zone, label: 'Pickup · ' + (o.pickup_address || o.from_zone || '') };
    }
    // arrival time next to the route's distance and duration
    var etaPatched = false;
    function patchEta() {
        if (etaPatched || typeof dirRenderer === 'undefined' || !dirRenderer || typeof dirRenderer.setDirections !== 'function') return;
        etaPatched = true;
        var orig = dirRenderer.setDirections;
        dirRenderer.setDirections = function (res) {
            var r = orig.apply(this, arguments);
            try {
                var secs = res && res.routes && res.routes[0] && res.routes[0].legs[0].duration.value;
                var panel = document.getElementById('map-route-panel');
                if (panel && secs) {
                    var el = document.getElementById('pk7-eta');
                    if (!el) { el = document.createElement('div'); el.id = 'pk7-eta'; panel.insertBefore(el, panel.firstChild); }
                    el.textContent = 'Arrive about ' + wallClock(Math.floor(Date.now() / 1000) + secs);
                } else { var e2 = document.getElementById('pk7-eta'); if (e2) e2.textContent = ''; }
            } catch (e) { /* the route itself is already drawn */ }
            return r;
        };
    }
    var origDraw = window.drawRouteOnMap;
    if (typeof origDraw === 'function') {
        window.drawRouteOnMap = function () { var r = origDraw.apply(this, arguments); patchEta(); return r; };
    }

    // ---- 2. available jobs ----
    function jobCard(o) {
        var d = typeof jobDistanceKm === 'function' ? jobDistanceKm(o) : null;
        var dist = d !== null && d !== undefined ? d.toFixed(1) + ' km away' : '';
        return '<div class="job-card pk7" data-pkg-card="' + o.id + '">' +
            '<div class="pk7-top"><span class="pk7-code">' + esc(o.order_code) + '</span>' +
            '<span class="job-wait" data-placed="' + esc(o.placed_at || '') + '"></span>' +
            '<span class="pk7-pill">📦 Package</span>' +
            '<button type="button" data-pkg-act="mute" data-id="' + o.id + '" style="padding:4px 9px;border:1px solid rgba(255,255,255,.2);background:transparent;color:#A8A8B8;border-radius:8px;font-size:11px;cursor:pointer" aria-label="Mute the job ring">🔕</button></div>' +
            '<div class="pk7-route">' + place('📍', 'Pickup' + (dist ? ' · ' + dist : ''), o.pickup_address || o.from_zone || '—') +
            place('🏁', 'Drop-off', o.recipient_address || o.to_zone || '—') + '</div>' + chips(o) +
            '<div class="pk7-earn"><span>You earn</span><b>' + money(o.delivery_fee) + '</b></div>' +
            (o.payment_method && typeof paymentBadgeHtml === 'function' ? '<div style="margin-bottom:8px">' + paymentBadgeHtml(o.payment_method) + '</div>' : '') +
            '<div class="job-actions"><button type="button" class="btn btn-primary" data-pkg-act="accept" data-id="' + o.id + '" data-code="' + esc(o.order_code) + '">Accept Job</button>' +
            '<button type="button" class="btn btn-secondary" data-pkg-act="skip" data-id="' + o.id + '">Skip</button></div></div>';
    }

    var origJobs = window.renderJobs;
    if (typeof origJobs === 'function') {
        window.renderJobs = function (list) {
            list = list || [];
            var pk = list.filter(isPkg), rest = list.filter(function (o) { return !isPkg(o); });
            origJobs(rest);
            if (!pk.length) return;
            var box = document.getElementById('jobs-content');
            if (!box) return;
            if (!rest.length) box.innerHTML = '';
            box.insertAdjacentHTML('afterbegin', pk.map(jobCard).join(''));
        };
    }

    // ---- 3. the timed job offer ----
    var origOffer = window.etelOfferCardHtml;
    if (typeof origOffer === 'function') {
        window.etelOfferCardHtml = function (o) {
            var h = origOffer(o);
            if (!isPkg(o)) return h;
            return h.replace('NEW JOB OFFER', 'NEW PACKAGE OFFER')
                .replace('<span class="job-address-icon">🏪</span>', '<span class="job-address-icon">📦</span>')
                .replace(/<span>Pickup: <strong>[^<]*<\/strong><\/span>/, '<span>Pickup: <strong>' + esc(o.pickup_address || o.from_zone || 'Sender') + '</strong></span>');
        };
    }

    // ---- 4. an accepted package ----
    function clockBlock(o, picked) {
        var t = o.pkg_times;
        if (!t || !t.accepted) return '';
        var legFrom = picked ? (t.picked_up || t.accepted) : t.accepted;
        var side = '<div>Accepted <b>' + esc(wallClock(t.accepted)) + '</b></div>';
        if (picked && t.picked_up) side += '<div>Collected <b>' + esc(wallClock(t.picked_up)) + '</b> (' + esc(durText(t.picked_up - t.accepted)) + ')</div>';
        side += '<div>Total <b data-pt-from="' + t.accepted + '">' + clockText(nowSec() - t.accepted) + '</b></div>';
        return '<div class="pk7-clock"><div><span class="pk7-clock-k">' + (picked ? 'To the recipient' : 'To the pickup') + '</span>' +
            '<span class="pk7-clock-v" data-pt-from="' + legFrom + '">' + clockText(nowSec() - legFrom) + '</span></div><div class="pk7-clock-side">' + side + '</div></div>';
    }
    function stepStrip(picked) {
        return '<div class="pk7-steps" aria-label="Progress">' +
            '<div class="pk7-step ' + (picked ? 'done' : 'on') + '"><i>' + (picked ? '✓' : '') + '</i>Pickup</div><div class="pk7-bar ' + (picked ? 'done' : '') + '"></div>' +
            '<div class="pk7-step ' + (picked ? 'on' : '') + '"><i></i>Drop-off</div><div class="pk7-bar"></div>' +
            '<div class="pk7-step"><i></i>Done</div></div>';
    }
    function activeCard(o) {
        var picked = o.status === 'picked_up' || o.status === 'in_transit';
        var t = leg(o, picked);
        var cash = (!picked && o.payment_method === 'cash')
            ? '<div class="pk7-cash">💵 Cash package: collect ' + money(o.fare_total) + ' from the sender when you pick it up.</div>' : '';
        var who = picked ? place('🏁', 'Deliver to', o.recipient_address || o.to_zone || '—', o.recipient_name || 'Recipient')
                         : place('📍', 'Collect from', o.pickup_address || o.from_zone || '—', o.sender_name ? 'Sender: ' + o.sender_name : '');
        var phone = picked ? o.recipient_phone : o.sender_phone;
        return '<div class="job-card pk7" data-pkg-card="' + o.id + '">' +
            '<div class="pk7-top"><span class="pk7-code">' + esc(o.order_code) + '</span><span class="pk7-pill">' + (picked ? '🚚 Take it to the recipient' : '📦 Collect from the sender') + '</span></div>' +
            stepStrip(picked) + clockBlock(o, picked) +
            '<div class="pk7-earn"><span>You earn</span><b>' + money(o.delivery_fee) + '</b></div>' + who + chips(o) + cash +
            '<div class="pk7-actions"><button type="button" class="pk7-nav' + (picked ? ' drop' : '') + '" data-pkg-act="nav" data-lat="' + esc(t.lat || '') + '" data-lng="' + esc(t.lng || '') + '" data-addr="' + esc(t.addr || '') + '" data-label="' + esc(t.label) + '">🧭 Navigate ' + (picked ? 'to drop-off' : 'to pickup') + '</button>' +
            callLink(phone, picked ? 'Call recipient' : 'Call sender') + '</div>' +
            '<button type="button" class="pk7-pin" data-pkg-act="' + (picked ? 'deliver' : 'pickup') + '" data-id="' + o.id + '" data-code="' + esc(o.order_code) + '">🔢 ' +
            (picked ? 'Enter the recipient’s delivery PIN' : 'Enter the sender’s pickup PIN') + '</button>' +
            '<div class="pk7-hint">' + (picked ? 'The recipient reads the 4-digit PIN to you. It confirms they received it.' : 'The sender shows the 4-digit PIN in their app. It confirms you have the right package.') + '</div></div>';
    }

    var origActive = window.renderActiveOrders;
    if (typeof origActive === 'function') {
        window.renderActiveOrders = function (orders) {
            orders = orders || [];
            learnTimes(orders);
            var mine = orders.filter(function (o) { return isPkg(o) && !o.offer_pending; });
            var rest = orders.filter(function (o) { return !(isPkg(o) && !o.offer_pending); });
            origActive(rest);
            window._activeOrders = orders;
            if (!mine.length) return;
            var box = document.getElementById('active-content');
            if (!box) return;
            if (!rest.length) box.innerHTML = '';
            box.insertAdjacentHTML('afterbegin', mine.map(activeCard).join(''));
            startTick();
            autoRoute(mine[0]);   // the first package's current leg is drawn on the map without a tap
        };
    }

    // ---- 5. the PIN dialog: four boxes over one real input (paste, autofill and the numeric keypad all work) ----
    function closePin() { var el = document.getElementById('pkg-pin-overlay'); if (el) el.remove(); }

    function openPin(kind, id, code) {
        closePin();
        var pickup = kind === 'pickup';
        var ov = document.createElement('div');
        ov.id = 'pkg-pin-overlay';
        ov.setAttribute('role', 'dialog');
        ov.setAttribute('aria-modal', 'true');
        ov.setAttribute('aria-label', pickup ? 'Pickup PIN' : 'Delivery PIN');
        ov.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.72);display:flex;align-items:center;justify-content:center;padding:18px';
        ov.innerHTML = '<div style="width:100%;max-width:340px;background:#1b1b2b;border:1px solid rgba(212,175,55,.4);border-radius:18px;padding:20px;color:#fff;font-family:inherit">' +
            '<div style="font-size:16px;font-weight:800;margin-bottom:4px">' + (pickup ? 'Sender’s pickup PIN' : 'Recipient’s delivery PIN') + '</div>' +
            '<div style="font-size:12.5px;color:#A8A8B8;margin-bottom:14px">' + esc(code || '') + ' · ' + (pickup ? 'Ask the sender to open this package in their app and read you the 4 digits.' : 'Ask the recipient for the 4-digit PIN they were given.') + '</div>' +
            '<div class="pk7-pinbox"><div class="pk7-digits" aria-hidden="true"><div class="pk7-digit"></div><div class="pk7-digit"></div><div class="pk7-digit"></div><div class="pk7-digit"></div></div>' +
            '<input id="pkg-pin-input" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="4" autocomplete="one-time-code" aria-label="4-digit PIN"></div>' +
            '<div id="pkg-pin-err" role="alert" style="min-height:18px;margin:10px 0 8px;font-size:12.5px;font-weight:700;color:#ff8a8a !important;text-align:center"></div>' +
            '<div style="display:flex;gap:10px"><button type="button" id="pkg-pin-cancel" style="flex:1;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.2);background:transparent;color:#ccc;font-size:14px;cursor:pointer">Cancel</button>' +
            '<button type="button" id="pkg-pin-ok" style="flex:2;padding:12px;border-radius:10px;border:0;background:linear-gradient(135deg,#D4AF37,#b8922e);color:#1a1a2e;font-size:14px;font-weight:800;cursor:pointer">' + (pickup ? 'Confirm pickup' : 'Confirm delivery') + '</button></div></div>';
        document.body.appendChild(ov);
        var input = document.getElementById('pkg-pin-input'), err = document.getElementById('pkg-pin-err'), ok = document.getElementById('pkg-pin-ok');
        var boxes = ov.querySelectorAll('.pk7-digit');
        function paint() {
            var v = input.value;
            Array.prototype.forEach.call(boxes, function (b, i) { b.textContent = v.charAt(i); b.className = 'pk7-digit' + (i === Math.min(v.length, 3) ? ' cur' : ''); });
        }
        input.addEventListener('input', function () { input.value = input.value.replace(/\D/g, '').slice(0, 4); paint(); });
        paint();
        document.getElementById('pkg-pin-cancel').addEventListener('click', closePin);
        ov.addEventListener('click', function (e) { if (e.target === ov) closePin(); });
        ov.addEventListener('keydown', function (e) { if (e.key === 'Escape') closePin(); if (e.key === 'Enter') submit(); });
        ok.addEventListener('click', submit);
        setTimeout(function () { try { input.focus(); } catch (e) {} }, 50);

        function submit() {
            var pin = input.value.trim();
            if (!/^\d{4}$/.test(pin)) { err.textContent = 'Enter the 4-digit PIN.'; return; }
            ok.disabled = true; err.textContent = '';
            api(pickup ? 'courier_package_pickup' : 'courier_package_deliver', { order_id: id, pin: pin }).then(function (res) {
                if (res && res.success) {
                    closePin();
                    if (pickup) toast((res.data && res.data.message) || 'Picked up');
                    else showSummary(id, (res.data && res.data.message) || 'Delivered');
                    if (typeof loadActiveOrders === 'function') loadActiveOrders();
                } else {
                    ok.disabled = false;
                    err.textContent = (res && res.message) || 'Could not check the PIN. Try again.';
                    input.value = ''; paint(); input.focus();
                }
            }).catch(function () { ok.disabled = false; err.textContent = 'No connection. Try again.'; });
        }
    }

    // ---- 5b. the delivered summary: what was earned and how long each part took ----
    function showSummary(id, message) {
        var t = TM.byId[id];
        var earn = '';
        try { var o = (window._activeOrders || []).filter(function (x) { return x.id == id; })[0]; if (o) earn = money(o.delivery_fee); } catch (e) {}
        var lines = '';
        if (t && t.accepted) {
            var end = nowSec();
            lines = '<div style="display:flex;justify-content:space-between;padding:7px 0;border-top:1px solid rgba(255,255,255,.1)"><span>To the pickup</span><b>' + esc(durText((t.picked_up || end) - t.accepted)) + '</b></div>' +
                (t.picked_up ? '<div style="display:flex;justify-content:space-between;padding:7px 0;border-top:1px solid rgba(255,255,255,.1)"><span>To the recipient</span><b>' + esc(durText(end - t.picked_up)) + '</b></div>' : '') +
                '<div style="display:flex;justify-content:space-between;padding:9px 0 2px;border-top:1px solid rgba(255,255,255,.18);font-size:15px"><span>Total</span><b style="color:#D4AF37">' + esc(durText(end - t.accepted)) + '</b></div>';
        }
        var ov = document.createElement('div');
        ov.id = 'pkg-done-overlay';
        ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true'); ov.setAttribute('aria-label', 'Delivered');
        ov.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.72);display:flex;align-items:center;justify-content:center;padding:18px';
        ov.innerHTML = '<div style="width:100%;max-width:340px;background:#1b1b2b;border:1px solid rgba(46,204,113,.5);border-radius:18px;padding:22px;color:#fff;text-align:center;font-family:inherit">' +
            '<div style="font-size:40px">✅</div><div style="font-size:19px;font-weight:800;margin:4px 0">Delivered</div>' +
            '<div style="font-size:13px;color:#A8A8B8;margin-bottom:10px">' + esc(message) + '</div>' +
            (earn ? '<div style="font-size:26px;font-weight:800;color:#2ECC71;margin:6px 0 12px">' + esc(earn) + '</div>' : '') +
            '<div style="text-align:left;font-size:13px;color:#d6d6e0">' + lines + '</div>' +
            '<button type="button" id="pkg-done-ok" style="width:100%;margin-top:16px;padding:13px;border-radius:12px;border:0;background:linear-gradient(135deg,#D4AF37,#b8922e);color:#1a1a2e;font-size:14.5px;font-weight:800;cursor:pointer">Done</button></div>';
        document.body.appendChild(ov);
        var ok = document.getElementById('pkg-done-ok');
        ok.addEventListener('click', function () { ov.remove(); });
        ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
        try { ok.focus(); } catch (e) {}
    }

    // ---- 6. the buttons on the cards (one listener; the cards carry data-pkg-act) ----
    document.addEventListener('click', function (e) {
        var b = e.target && e.target.closest ? e.target.closest('[data-pkg-act]') : null;
        if (!b) return;
        var id = parseInt(b.getAttribute('data-id'), 10), code = b.getAttribute('data-code') || '', act = b.getAttribute('data-pkg-act');
        if (act === 'accept' && typeof acceptJob === 'function') acceptJob(id, code);
        else if (act === 'skip' && typeof skipJob === 'function') skipJob(id);
        else if (act === 'mute' && typeof muteJobRing === 'function') muteJobRing(id, b);
        else if (act === 'nav') navTo(b.getAttribute('data-lat'), b.getAttribute('data-lng'), b.getAttribute('data-addr'), b.getAttribute('data-label'), true);
        else if (act === 'pickup' || act === 'deliver') openPin(act, id, code);
    });
})();

/* ---- release v8: js/courier-i18n.js (added to v7) ---- */
/* Courier app: English / Amharic (add-on, v8 canary).
 * The courier screens are drawn by the release bundle, which has no translation hooks. Rather than change that bundle, this add-on translates
 * what is on screen: it looks up each piece of text in a dictionary (exact match, then a few number patterns), keeps the English original so the
 * switch can go back, and watches the page so text drawn later (job cards, toasts, timers) is translated as it appears.
 * Same language setting as every other ETEL app (localStorage "etel_lang"). Text with no entry stays English and is listed by
 * window.__etelCourierI18nMissing() so the next release can add it. Nothing here touches orders, money or location. */
(function () {
    'use strict';
    if (window.__etelCourierI18n) return;
    window.__etelCourierI18n = 'v8';

    var EXACT = {
        // header, status, tabs
        'ETEL Courier': 'ኢቴል ሹፌር', 'Professional Delivery': 'ሙያዊ አቅርቦት', 'LIVE': 'ቀጥታ', 'Slide to go online': 'በመስመር ለመሆን ይጎትቱ',
        'Online — Ready for deliveries': 'በመስመር ላይ — ለማድረስ ዝግጁ', 'Loading map…': 'ካርታ በመጫን ላይ…', 'Calculating…': 'በማስላት ላይ…',
        'Fastest driving route · Hawassa': 'ፈጣኑ መንገድ · ሃዋሳ', '✕ Clear Route': '✕ መንገዱን አጽዳ',
        'JOBS': 'ሥራዎች', 'ACTIVE': 'ንቁ', 'HISTORY': 'ታሪክ', 'All': 'ሁሉም', 'High Pay': 'ከፍተኛ ክፍያ', 'Refresh': 'አድስ', 'Refresh jobs': 'ሥራዎችን አድስ',
        'Loading available jobs…': 'ያሉ ሥራዎች በመጫን ላይ…', 'Loading active deliveries…': 'ንቁ አቅርቦቶች በመጫን ላይ…', 'Loading history…': 'ታሪክ በመጫን ላይ…', 'Loading…': 'በመጫን ላይ…',
        'A new version (': 'አዲስ ስሪት (', ') is available.': ') ተዘጋጅቷል።', 'Update Now': 'አሁን አዘምን',
        // profile
        'Earnings': 'ገቢ', '🛵 VERIFIED COURIER': '🛵 የተረጋገጠ ሹፌር', 'Deliveries': 'አቅርቦቶች', 'Rating': 'ደረጃ', 'All-time': 'ሁሉም ጊዜ', 'Vehicle': 'ተሽከርካሪ', 'Zone': 'አካባቢ', 'Courier ID': 'የሹፌር መታወቂያ',
        '📊 Your Performance': '📊 አፈጻጸምዎ', 'Today': 'ዛሬ', 'Week': 'ሳምንት', 'Month': 'ወር', 'View delivery totals': 'የአቅርቦት ድምርን ይመልከቱ',
        'Delivery History': 'የአቅርቦት ታሪክ', 'All your past deliveries': 'ያለፉ አቅርቦቶችዎ ሁሉ', 'Invite Friends': 'ጓደኞችን ይጋብዙ', 'Earn together with ETEL Delivery': 'ከኢቴል ዴሊቨሪ ጋር አብረው ይሥሩ',
        'Two-step sign-in': 'ባለ ሁለት ደረጃ መግቢያ', 'A code from your phone when you sign in': 'ሲገቡ ከስልክዎ የሚላክ ኮድ', 'Delete my account': 'አካውንቴን ሰርዝ', 'Ask ETEL to delete your account and data': 'ኢቴል አካውንትዎን እና መረጃዎን እንዲሰርዝ ይጠይቁ',
        'Privacy Policy': 'የግላዊነት መመሪያ', 'How ETEL uses your data': 'ኢቴል መረጃዎን እንዴት እንደሚጠቀም', 'Display Size': 'የማሳያ መጠን', 'Text, icons, buttons and logos': 'ጽሑፍ፣ ምልክቶች፣ አዝራሮች እና አርማዎች',
        '📲 Download the ETEL app': '📲 የኢቴል መተግበሪያን ያውርዱ', '🛡️ Admin Dashboard': '🛡️ የአስተዳዳሪ ዳሽቦርድ', '🧹 Fix app not updating': '🧹 መተግበሪያው አልዘመነም? አስተካክል', '🚪 Logout': '🚪 ውጣ',
        'Show this to the Service Giver': 'ይህን ለአገልግሎት ሰጪው ያሳዩ', "They'll scan it to confirm instantly": 'ወዲያውኑ ለማረጋገጥ ይቃኙታል', 'Close': 'ዝጋ',
        'Dismiss': 'አሰናብት', 'Switch to Driver Dashboard': 'ወደ የመኪና ሹፌር ዳሽቦርድ ቀይር', 'Driver mode': 'የመኪና ሹፌር ሁነታ', 'Switch to dark mode': 'ወደ ጨለማ ሁነታ ቀይር', 'Profile': 'መገለጫ',
        'Full map': 'ሙሉ ካርታ', 'Search destination…': 'መድረሻ ፈልግ…', 'Main menu': 'ዋና ምናሌ', 'Tap to refresh. Hold to fully update.': 'ለማደስ ይንኩ። ሙሉ በሙሉ ለማዘመን ተጭነው ይያዙ።', 'Install App': 'መተግበሪያ ጫን', 'Language': 'ቋንቋ',
        // job cards
        'Accept': 'ተቀበል', 'Accept Job': 'ሥራውን ተቀበል', 'Accept a job from the Jobs tab': 'ከ"ሥራዎች" ትር ሥራ ይቀበሉ', 'Accepted': 'ተቀብሏል', 'Add': 'ጨምር', 'Amount paid (ETB)': 'የተከፈለ መጠን (ብር)',
        'Cancel': 'ተው', 'Cancel request': 'ጥያቄውን ተው', 'Check your internet connection and try again.': 'የበይነመረብ ግንኙነትዎን ይፈትሹ እና እንደገና ይሞክሩ።', 'Collected': 'ተሰብስቧል', 'Could not load active orders.': 'ንቁ ትዕዛዞችን መጫን አልተቻለም።',
        'Could not load your code — try again shortly.': 'ኮድዎን መጫን አልተቻለም — ትንሽ ቆይተው ይሞክሩ።', 'Decline': 'አትቀበል', 'Delivered': 'ደርሷል', 'Done': 'ተጠናቋል', 'Drop-off': 'ማስረከቢያ', 'ETB': 'ብር',
        'Liters': 'ሊትር', 'Maps API Error': 'የካርታ ስህተት', 'NEW JOB OFFER': 'አዲስ የሥራ ጥያቄ', 'NEW PACKAGE OFFER': 'አዲስ የጥቅል ጥያቄ', 'Net ETB/hour': 'የተጣራ ብር/ሰዓት',
        'No active deliveries': 'ንቁ አቅርቦት የለም', 'No completed deliveries yet': 'እስካሁን የተጠናቀቀ አቅርቦት የለም', 'No fill-ups logged yet.': 'እስካሁን የነዳጅ ሙሌት አልተመዘገበም።', 'No history available.': 'ታሪክ የለም።', 'No jobs available right now': 'አሁን ምንም ሥራ የለም',
        'Odometer reading (km) — optional but recommended': 'የኦዶሜትር ንባብ (ኪ.ሜ) — አማራጭ ግን ይመከራል', 'Online time': 'በመስመር ላይ የቆዩበት ጊዜ', 'Open in Maps ↗': 'በካርታ ክፈት ↗', 'Pickup': 'መረከቢያ', 'Pickup:': 'መረከቢያ፦',
        'Retry': 'እንደገና ሞክር', 'Save Fill-up': 'ሙሌቱን አስቀምጥ', 'Share Code': 'ኮዱን አጋራ', 'Skip': 'እለፍ', 'Tap to open in Google Maps navigation': 'በGoogle Maps ለመክፈት ይንኩ',
        'To the pickup': 'ወደ መረከቢያው', 'To the recipient': 'ወደ ተቀባዩ', 'Today’s Earnings': 'የዛሬ ገቢ', 'Total': 'ጠቅላላ', 'Total Earned': 'ጠቅላላ ያገኙት', 'Waiting for service giver to confirm pickup…': 'አገልግሎት ሰጪው መረከቡን እስኪያረጋግጥ በመጠበቅ ላይ…',
        'You earn': 'የሚያገኙት', '⛽ Log a Fill-up': '⛽ የነዳጅ ሙሌት መዝግብ', '✓ DELIVERED': '✓ ደርሷል', '👥 Invite Friends': '👥 ጓደኞችን ይጋብዙ', '📍 CUSTOMER LOCATION': '📍 የደንበኛ አድራሻ',
        '📍 Getting your location…': '📍 አካባቢዎን በማግኘት ላይ…', '📍 PICKUP ZONE': '📍 የመረከቢያ አካባቢ', '📍 SHOP LOCATION': '📍 የሱቅ አድራሻ', '📞 Call': '📞 ደውል', '📦 Package': '📦 ጥቅል',
        '📷 Customer added a gate/entrance photo — tap to view': '📷 ደንበኛው የበር ፎቶ አክሏል — ለማየት ይንኩ', '🔇 Mute': '🔇 አጥፋ',
        // package add-on
        'Arrive about ': 'ይደርሳል በ ', 'Call recipient': 'ተቀባዩን ደውል', 'Call sender': 'ላኪውን ደውል', 'Collect from': 'ከዚህ ተረከብ', 'Deliver to': 'ወደዚህ አድርስ', 'Delivery PIN': 'የማስረከቢያ ፒን', 'Pickup PIN': 'የመረከቢያ ፒን',
        'Picked up': 'ተወስዷል', 'Sender': 'ላኪ', 'Could not check the PIN. Try again.': 'ፒኑን ማረጋገጥ አልተቻለም። እንደገና ይሞክሩ።', 'Enter the 4-digit PIN.': 'ባለ 4 አሃዝ ፒኑን ያስገቡ።', 'No connection. Try again.': 'ግንኙነት የለም። እንደገና ይሞክሩ።',
        'Enter the recipient’s delivery PIN': 'የተቀባዩን የማስረከቢያ ፒን ያስገቡ', 'Enter the sender’s pickup PIN': 'የላኪውን የመረከቢያ ፒን ያስገቡ', 'Recipient’s delivery PIN': 'የተቀባዩ የማስረከቢያ ፒን', 'Sender’s pickup PIN': 'የላኪው የመረከቢያ ፒን',
        'The recipient reads the 4-digit PIN to you. It confirms they received it.': 'ተቀባዩ ባለ 4 አሃዝ ፒኑን ይነግርዎታል። መቀበላቸውን ያረጋግጣል።', 'The sender shows the 4-digit PIN in their app. It confirms you have the right package.': 'ላኪው ባለ 4 አሃዝ ፒኑን በመተግበሪያው ያሳይዎታል። ትክክለኛውን ጥቅል መውሰድዎን ያረጋግጣል።',
        'Document or envelope': 'ሰነድ ወይም ፖስታ', 'Small (shoebox)': 'ትንሽ (የጫማ ሳጥን)', 'Medium (backpack)': 'መካከለኛ (ቦርሳ)', 'Large (box, suitcase)': 'ትልቅ (ሳጥን፣ ሻንጣ)',
        '📦 Collect from the sender': '📦 ከላኪው ተረከብ', '🚚 Take it to the recipient': '🚚 ወደ ተቀባዩ ውሰድ', 'Enter': 'አስገባ',
        // messages and toasts
        'Confirmation request cancelled': 'የማረጋገጫ ጥያቄው ተሰርዟል', 'Copied to clipboard': 'ወደ ክሊፕቦርድ ተቀድቷል', 'Could not delete': 'መሰረዝ አልተቻለም', 'Could not find customer location': 'የደንበኛውን አድራሻ ማግኘት አልተቻለም',
        'Could not get location': 'አካባቢን ማግኘት አልተቻለም', 'Could not go offline': 'ከመስመር መውጣት አልተቻለም', 'Could not go online': 'በመስመር መሆን አልተቻለም', 'Could not load earnings': 'ገቢን መጫን አልተቻለም',
        'Customer location not available': 'የደንበኛ አድራሻ አልተገኘም', 'Enable notifications to hear about new jobs even when the app is in the background': 'መተግበሪያው ከበስተጀርባ ሲሆንም አዳዲስ ሥራዎችን ለመስማት ማሳወቂያዎችን ያንቁ',
        'Geolocation not available': 'የአካባቢ መረጃ አይገኝም', 'Go offline? You will stop appearing available for new deliveries.': 'ከመስመር ይውጡ? ለአዳዲስ አቅርቦቶች ዝግጁ ሆነው መታየት ያቆማሉ።', 'Job accepted! Go to Active tab.': 'ሥራው ተቀባይነት አግኝቷል! ወደ "ንቁ" ትር ይሂዱ።',
        'Job accepted! Head to the shop.': 'ሥራው ተቀባይነት አግኝቷል! ወደ ሱቁ ይሂዱ።', 'Logout?': 'ይውጡ?', 'Map not ready — try again': 'ካርታው ዝግጁ አይደለም — እንደገና ይሞክሩ', 'Map not ready — try again in a moment': 'ካርታው ዝግጁ አይደለም — ትንሽ ቆይተው ይሞክሩ',
        'Map not ready. Try again in a moment.': 'ካርታው ዝግጁ አይደለም። ትንሽ ቆይተው ይሞክሩ።', 'New job offer - accept it before the timer runs out': 'አዲስ የሥራ ጥያቄ - ጊዜው ሳያልቅ ይቀበሉ', 'Offer declined': 'ጥያቄው ውድቅ ተደርጓል',
        'Remove this fuel log?': 'ይህ የነዳጅ መዝገብ ይወገድ?', 'Request sent! Waiting for customer to confirm…': 'ጥያቄ ተልኳል! ደንበኛው እስኪያረጋግጥ በመጠበቅ ላይ…', 'Request sent! Waiting for service giver to confirm…': 'ጥያቄ ተልኳል! አገልግሎት ሰጪው እስኪያረጋግጥ በመጠበቅ ላይ…',
        'Share not supported on this browser': 'ማጋራት በዚህ ብራውዘር አይደገፍም', "You're offline": 'ከመስመር ውጭ ነዎት', "You're online": 'በመስመር ላይ ነዎት', '✅ Pickup confirmed by service giver! Order is now in transit.': '✅ አገልግሎት ሰጪው መረከቡን አረጋግጧል! ትዕዛዙ አሁን በጉዞ ላይ ነው።',
        '🎉 Delivery confirmed by customer! Great job!': '🎉 ደንበኛው ማድረሱን አረጋግጧል! ጥሩ ሥራ!', '📦 New job available!': '📦 አዲስ ሥራ አለ!'
    };
    // text that carries a number or a name: the captured parts are put back unchanged
    var PATTERNS = [
        [/^Arrive about (.+)$/, 'ይደርሳል በ $1'], [/^ETEL Courier v(.+)$/, 'ኢቴል ሹፌር v$1'],
        [/^(\d+(?:\.\d+)?) km away$/, '$1 ኪ.ሜ ይርቃል'], [/^(\d+(?:\.\d+)?) km trip$/, '$1 ኪ.ሜ ጉዞ'], [/^(\d+(?:\.\d+)?) km$/, '$1 ኪ.ሜ'],
        [/^(\d+) min$/, '$1 ደቂቃ'], [/^ETB (.+)$/, 'ብር $1'],
        [/^Sender: (.+)$/, 'ላኪ፦ $1'], [/^Pickup · (.+)$/, 'መረከቢያ · $1'], [/^Drop-off · (.+)$/, 'ማስረከቢያ · $1'],
        [/^Accept job (.+)$/, 'ሥራ $1 ተቀበል'], [/^(\d+) jobs?$/, '$1 ሥራ'], [/^(\d+) deliver(?:y|ies)$/, '$1 አቅርቦት']
    ];
    var ETHIOPIC = /[ሀ-፿]/;
    var lang = 'en';
    var missing = {};

    function norm(s) { return s.replace(/\s+/g, ' ').trim(); }
    function translate(text) {
        var core = norm(text);
        if (!core) return null;
        var hit = EXACT.hasOwnProperty(core) ? EXACT[core] : null;
        if (hit === null) {
            for (var i = 0; i < PATTERNS.length; i++) {
                if (PATTERNS[i][0].test(core)) { hit = core.replace(PATTERNS[i][0], PATTERNS[i][1]); break; }
            }
        }
        if (hit === null) {
            if (/[A-Za-z]{3}/.test(core) && !ETHIOPIC.test(core) && core.length < 160) missing[core] = 1;
            return null;
        }
        var lead = text.match(/^\s*/)[0], tail = text.match(/\s*$/)[0];
        return lead + hit + tail;
    }

    // ---- text nodes ----
    function skip(el) {
        for (var n = el; n && n.nodeType === 1; n = n.parentNode) {
            var t = n.tagName;
            if (t === 'SCRIPT' || t === 'STYLE' || t === 'TEXTAREA' || t === 'NOSCRIPT' || n.hasAttribute('data-no-i18n')) return true;
        }
        return false;
    }
    function doNode(node) {
        if (node.__etelAm === node.nodeValue) return;            // our own translation
        if (node.__etelEn !== undefined && node.__etelAm !== node.nodeValue) { node.__etelEn = undefined; node.__etelAm = undefined; }   // the app wrote new text
        if (lang !== 'am') return;
        if (!node.parentNode || skip(node.parentNode)) return;
        var tr = translate(node.nodeValue);
        if (tr === null || tr === node.nodeValue) return;
        node.__etelEn = node.nodeValue; node.__etelAm = tr; node.nodeValue = tr;
    }
    var ATTRS = ['placeholder', 'title', 'aria-label'];
    function doAttrs(el) {
        if (!el.getAttribute || skip(el)) return;
        ATTRS.forEach(function (a) {
            var v = el.getAttribute(a);
            if (!v) return;
            var key = '__etel_' + a;
            if (el[key] && el[key].am === v) return;
            if (lang !== 'am') return;
            var tr = translate(v);
            if (tr !== null && tr !== v) { el[key] = { en: v, am: tr }; el.setAttribute(a, tr); }
        });
    }
    function walk(root) {
        if (!root) return;
        if (root.nodeType === 3) { doNode(root); return; }
        if (root.nodeType !== 1 || skip(root)) return;
        doAttrs(root);
        var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT, null);
        var n;
        while ((n = w.nextNode())) { if (n.nodeType === 3) doNode(n); else doAttrs(n); }
    }
    function restore(root) {
        var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT, null), n;
        while ((n = w.nextNode())) {
            if (n.nodeType === 3) { if (n.__etelEn !== undefined && n.nodeValue === n.__etelAm) n.nodeValue = n.__etelEn; n.__etelEn = undefined; n.__etelAm = undefined; }
            else ATTRS.forEach(function (a) { var k = '__etel_' + a; if (n[k] && n.getAttribute(a) === n[k].am) n.setAttribute(a, n[k].en); n[k] = undefined; });
        }
    }

    // ---- watch the page (batched: many changes in one frame are handled once) ----
    var pending = [], queued = false;
    function flush() {
        queued = false;
        var list = pending; pending = [];
        if (lang !== 'am') return;
        list.forEach(function (r) {
            if (r.type === 'childList') { for (var i = 0; i < r.addedNodes.length; i++) walk(r.addedNodes[i]); }
            else if (r.type === 'characterData') doNode(r.target);
            else if (r.type === 'attributes') doAttrs(r.target);
        });
    }
    var obs = new MutationObserver(function (records) {
        if (lang !== 'am') return;
        pending = pending.concat(records);
        if (!queued) { queued = true; (window.requestAnimationFrame || setTimeout)(flush); }
    });
    function start() {
        if (!document.body) return;
        obs.observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ATTRS });
        setLang(currentLang(), true);
        addSwitch();
    }

    // ---- language ----
    function currentLang() { try { return localStorage.getItem('etel_lang') === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
    function setLang(l, quiet) {
        lang = l === 'am' ? 'am' : 'en';
        try { localStorage.setItem('etel_lang', lang); } catch (e) {}
        document.documentElement.setAttribute('lang', lang);
        if (lang === 'am') walk(document.body); else restore(document.body);
        Array.prototype.forEach.call(document.querySelectorAll('.cl8-b'), function (b) {
            var on = b.getAttribute('data-lang') === lang;
            b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        if (!quiet) { try { if (window.ETEL_I18N) ETEL_I18N.apply(lang); } catch (e) {} }
    }
    function addSwitch() {
        if (document.getElementById('cl8-lang')) return;
        var host = document.querySelector('.header-right');
        if (!host) { setTimeout(addSwitch, 500); return; }
        var css = document.createElement('style');
        css.id = 'cl8-css';
        css.textContent = '#cl8-lang{display:inline-flex;padding:2px;border-radius:999px;background:rgba(255,255,255,.07);border:1px solid rgba(212,175,55,.4);margin-right:6px;flex-shrink:0}' +
            '.cl8-b{min-width:30px;min-height:30px;padding:0 6px;border:0;border-radius:999px;background:transparent;color:#C9C3B0;font:800 12px/1 inherit;font-family:inherit;cursor:pointer}' +
            '.cl8-b.on{background:linear-gradient(135deg,#FFE9A8,#D4AF37);color:#1a1400}';
        document.head.appendChild(css);
        var box = document.createElement('div');
        box.id = 'cl8-lang'; box.setAttribute('role', 'group'); box.setAttribute('aria-label', 'Language');
        [['en', 'EN'], ['am', 'አማ']].forEach(function (p) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'cl8-b'; b.setAttribute('data-lang', p[0]); b.textContent = p[1];
            b.addEventListener('click', function () { setLang(p[0]); });
            box.appendChild(b);
        });
        host.insertBefore(box, host.firstChild);
        setLang(lang, true);
    }

    window.__etelCourierI18nMissing = function () { return Object.keys(missing); };
    window.etelCourierSetLang = setLang;
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();

/* ---- release v9: js/courier-fixes.js (added to v8) ---- */
/* Courier app: small fixes added as an add-on (release v9 canary).
 *
 * 1. Pop-ups built in script with their own colours (the "Delivered" summary, the PIN box) showed dark text on their dark panel for a moment,
 *    sometimes for seconds on a slow phone, because the page's stylesheet paints text dark with !important and only a later readability pass
 *    corrected it. Here each such pop-up gets its own colours pinned (inline !important) the moment it is added, before it is first drawn.
 *    Every element takes the colour of the nearest ancestor that sets one (the pop-up's panel), so nothing falls back to the page's dark text.
 * It changes no data, no order logic and no network call. */
(function () {
    'use strict';
    if (window.__etelCourierFixes) return;
    window.__etelCourierFixes = 'v9';

    var POPUPS = ['pkg-done-overlay', 'pkg-pin-overlay'];

    function ownColor(el) { return el.style && el.style.color ? el.style.color : ''; }
    function inheritedColor(el, root) {
        for (var n = el; n; n = n.parentElement) {
            var c = ownColor(n);
            if (c) return c;
            if (n === root) break;
        }
        return '';
    }
    function pin(root) {
        var els = [root].concat(Array.prototype.slice.call(root.querySelectorAll('*')));
        els.forEach(function (el) {
            var c = inheritedColor(el, root);
            if (c) el.style.setProperty('color', c, 'important');
        });
    }
    function consider(node) {
        if (!node || node.nodeType !== 1) return;
        if (node.id && POPUPS.indexOf(node.id) !== -1) { pin(node); return; }
        if (node.querySelectorAll) {
            POPUPS.forEach(function (id) { var el = node.querySelector('#' + id); if (el) pin(el); });
        }
    }
    function start() {
        if (!document.body) return;
        new MutationObserver(function (records) {
            records.forEach(function (r) { for (var i = 0; i < r.addedNodes.length; i++) consider(r.addedNodes[i]); });
        }).observe(document.body, { childList: true });
        POPUPS.forEach(function (id) { var el = document.getElementById(id); if (el) pin(el); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();

/* ---- release v10: js/etel-sounds.js (added to v9) ---- */
/* ETEL signature sounds for the delivery apps (courier, shop, customer). No audio files: every sound is synthesised in the browser, so it
 * costs no download and has no licensing question. The brand motif is E - G - B - E (E5 G5 B5 E6, an E-minor arpeggio rising to the high E,
 * "E" for ETEL): bright bell notes with a gold shimmer on the last one.
 *
 *   EtelSound.play('job' | 'order' | 'progress' | 'delivered' | 'cancel' | 'message')
 *   EtelSound.ring(key, name, everyMs)   repeat a sound (and vibrate) until EtelSound.stop(key)
 *   EtelSound.stop(key) / stopAll()
 *   EtelSound.setMuted(true|false) / isMuted()   remembered on this phone (etel_sound_off)
 *
 * Phones only let a page make sound after the person has touched it once; until then play() is silent and EtelSound.locked() is true
 * (the shop and courier pages show a "tap to turn sound on" chip). A page that is closed or in the background cannot play sound:
 * that case is the phone's own push-notification tone. */
(function () {
    'use strict';
    if (window.EtelSound) return;
    var ctx = null, master = null, muted = false, loops = {}, gestured = false;
    try { muted = localStorage.getItem('etel_sound_off') === '1'; } catch (e) {}

    var E5 = 659.25, G5 = 783.99, A5 = 880, B5 = 987.77, E6 = 1318.51, G6 = 1567.98, B6 = 1975.53, E4 = 329.63, B4 = 493.88, E3 = 164.81;

    function ensure() {
        try {
            if (!ctx) {
                var C = window.AudioContext || window.webkitAudioContext;
                if (!C) return null;
                ctx = new C();
                var comp = ctx.createDynamicsCompressor();   // keeps it loud without crackling on small phone speakers
                comp.threshold.value = -16; comp.knee.value = 12; comp.ratio.value = 10; comp.attack.value = 0.003; comp.release.value = 0.25;
                master = ctx.createGain(); master.gain.value = 1.6;
                master.connect(comp); comp.connect(ctx.destination);
            }
            if (ctx.state === 'suspended') ctx.resume().catch(function () {});
            return ctx;
        } catch (e) { return null; }
    }

    // One bell note: a sine body, a bright triangle, and two inharmonic partials that give the "struck metal" sparkle, each fading out.
    function bell(freq, at, dur, vol) {
        var t0 = ctx.currentTime + at;
        [[1, 'sine', 1], [2, 'triangle', 0.42], [2.76, 'sine', 0.26], [5.4, 'sine', 0.1]].forEach(function (p) {
            var o = ctx.createOscillator(), g = ctx.createGain();
            o.type = p[1]; o.frequency.value = freq * p[0];
            g.gain.setValueAtTime(0.0001, t0);
            g.gain.exponentialRampToValueAtTime(vol * p[2], t0 + 0.008);
            g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur / (p[0] > 2 ? 2 : 1));
            o.connect(g); g.connect(master);
            o.start(t0); o.stop(t0 + dur + 0.05);
        });
    }
    // A soft low thump under the first note so a ring is felt as well as heard.
    function thump(at, vol) {
        var t0 = ctx.currentTime + at, o = ctx.createOscillator(), g = ctx.createGain();
        o.type = 'sine'; o.frequency.setValueAtTime(E3 * 1.5, t0); o.frequency.exponentialRampToValueAtTime(E3, t0 + 0.18);
        g.gain.setValueAtTime(0.0001, t0); g.gain.exponentialRampToValueAtTime(vol, t0 + 0.01); g.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.32);
        o.connect(g); g.connect(master); o.start(t0); o.stop(t0 + 0.35);
    }
    function motif(at, step, vol, tail) {
        bell(E5, at, 0.5, vol); bell(G5, at + step, 0.5, vol); bell(B5, at + 2 * step, 0.55, vol);
        bell(E6, at + 3 * step, tail, vol * 1.15); bell(B6, at + 3 * step + 0.02, tail * 0.8, vol * 0.45);   // the gold shimmer
    }

    var SOUNDS = {
        // New job for a courier: fast, urgent, loud. About 1.3 s, so a ring can repeat every 1.4 s.
        job: function () { thump(0, 0.9); motif(0, 0.12, 0.62, 0.95); bell(E6, 0.62, 0.6, 0.5); bell(G6, 0.7, 0.55, 0.4); },
        // New order for a shop: the motif, then a calling answer (B5 - E6). About 2 s.
        order: function () { thump(0, 0.8); motif(0, 0.16, 0.58, 0.8); bell(B5, 1.0, 0.45, 0.5); bell(E6, 1.2, 0.8, 0.58); bell(B6, 1.22, 0.6, 0.25); },
        // Order moved on (courier found, picked up): a friendly two-note rise.
        progress: function () { bell(G5, 0, 0.45, 0.42); bell(B5, 0.14, 0.7, 0.46); },
        // Delivered: the full motif and a held, bright chord.
        delivered: function () {
            motif(0, 0.13, 0.5, 1.3);
            [E5, G5, B5, E6].forEach(function (f) { bell(f, 0.62, 1.4, 0.26); });
            bell(G6, 0.7, 1.0, 0.2); bell(B6, 0.78, 0.9, 0.16);
        },
        cancel: function () { bell(B4, 0, 0.5, 0.4); bell(E4, 0.2, 0.9, 0.42); },
        message: function () { bell(B5, 0, 0.5, 0.42); bell(E6, 0.1, 0.6, 0.34); }
    };
    var BUZZ = { job: [300, 120, 300, 120, 300], order: [250, 100, 250, 100, 250], delivered: [80, 60, 80, 60, 200], progress: [90], message: [60], cancel: [160] };

    function buzz(name) { if (!muted && BUZZ[name] && navigator.vibrate) try { navigator.vibrate(BUZZ[name]); } catch (e) {} }

    var api = {
        play: function (name, opts) {
            if (!SOUNDS[name]) return;
            if (!(opts && opts.noBuzz)) buzz(name);
            if (muted) return;
            if (!ensure() || ctx.state !== 'running') return;   // locked until the first touch
            try { SOUNDS[name](); } catch (e) {}
        },
        ring: function (key, name, everyMs) {
            if (loops[key]) return;
            api.play(name);
            loops[key] = setInterval(function () { api.play(name); }, everyMs || 2200);
        },
        stop: function (key) { if (loops[key]) { clearInterval(loops[key]); delete loops[key]; } },
        stopAll: function () { Object.keys(loops).forEach(api.stop); },
        ringing: function (key) { return !!loops[key]; },
        isMuted: function () { return muted; },
        setMuted: function (m) {
            muted = !!m;
            try { localStorage.setItem('etel_sound_off', muted ? '1' : '0'); } catch (e) {}
            if (muted) api.stopAll();
            else api.play('progress');   // a short sample, so the person hears what is now on
        },
        locked: function () { return !ctx || ctx.state !== 'running'; },
        unlock: function () { gestured = true; ensure(); },
        // Plays one sound right now (for the "test the ring" buttons), even if the loops are idle.
        test: function (name) { gestured = true; ensure(); api.play(name || 'order'); }
    };
    ['touchstart', 'click', 'keydown'].forEach(function (ev) {
        window.addEventListener(ev, function once() { if (gestured) return; gestured = true; ensure(); }, { passive: true, capture: true });
    });
    window.EtelSound = api;
})();


/* ---- release v10: js/courier-sound.js (added to v9) ---- */
/* Courier app: the ETEL ring for a new job (add-on, release v10 canary).
 *
 * The app already rings (and vibrates) for every job on the board until the courier takes or declines it: startRingingForJob() calls
 * _playCourierRingBurst() every 1.4 s. That burst was two thin beeps. Here it is replaced by the ETEL signature ring from js/etel-sounds.js
 * (bell motif E-G-B-E with a low thump, loud and compressed so it carries on a small phone speaker). The ringing logic, the vibration and
 * the stop-when-handled behaviour are the app's own and are not touched. If the sound engine is missing the old beeps keep working.
 * Phones only play sound after one touch of the page, so a small chip asks for it when the sound is still locked. */
(function () {
    'use strict';
    if (window.__etelCourierSound) return;
    window.__etelCourierSound = 'v10';
    if (!window.EtelSound || typeof window._playCourierRingBurst !== 'function') return;

    window._playCourierRingBurst = function () { window.EtelSound.play('job', { noBuzz: true }); };

    function chip() {
        if (document.getElementById('etel-sound-chip')) return;
        var b = document.createElement('button');
        b.type = 'button'; b.id = 'etel-sound-chip';
        b.style.cssText = 'position:fixed;left:12px;bottom:12px;z-index:99999;padding:9px 14px;border-radius:22px;border:1px solid #D4AF37;background:#0B1E3F;color:#F5D77A;font-size:13px;font-weight:700;box-shadow:0 4px 16px rgba(0,0,0,.35);cursor:pointer';
        function paint() {
            var ES = window.EtelSound;
            if (ES.isMuted()) { b.textContent = '🔕 Job sound off'; b.style.display = 'block'; }
            else if (ES.locked()) { b.textContent = '🔔 Tap to turn job sound on'; b.style.display = 'block'; }
            else { b.textContent = '🔔 Job sound on'; b.style.display = 'block'; }
        }
        b.addEventListener('click', function () {
            var ES = window.EtelSound;
            if (ES.isMuted()) ES.setMuted(false); else if (ES.locked()) { ES.unlock(); ES.test('job'); } else ES.setMuted(true);
            paint();
        });
        document.body.appendChild(b); paint();
        setInterval(paint, 2000);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', chip); else chip();
})();

/* ---- release v11: js/courier-offline.js (added to v10) ---- */
/* ETEL Courier - works through a dropped connection.
   What it does, and what it deliberately does not:
   - The list of the courier's orders is kept on the phone each time it loads. With no signal the app shows that saved list,
     marked "saved at HH:MM", instead of "Could not load".
   - Status changes (picked up / in transit / delivered) and fuel fill-ups made with no signal are saved on the phone and sent,
     in the order they were made, as soon as the connection is back. The real time of the action is sent with it, so a delivery
     made in a dead zone is not stamped with the time the signal returned.
   - A saved action the server refuses (order cancelled, reassigned...) is NOT dropped quietly: it stays in the waiting list
     with the server's reason until the courier discards it.
   - Anything that moves money or needs another person (accepting a job, PIN / QR confirmations, wallet, going online) stays online-only.
   Standalone: wraps window.fetch for the API address, so courier.js and the other courier scripts are not edited. */
(function () {
'use strict';
if (window.__courierOffline || typeof window.fetch !== 'function') return;
window.__courierOffline = true;

var API = (window.ETEL && window.ETEL.api) || 'boot.php';
var KEY_Q = 'etel_courier_outbox_v1', KEY_C = 'etel_courier_orders_v1';
var QUEUE_ACTIONS = { courier_update_order_status: 1, courier_add_fuel_log: 1 };
var origFetch = window.fetch.bind(window);
var memQ = null, memC = {};
var flushing = false, timer = null;

function store(get, k, v) {
  try { if (get) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; }
  return null;
}
function readQ() {
  if (memQ) return memQ;
  try { var a = JSON.parse(store(true, KEY_Q) || '[]'); memQ = Array.isArray(a) ? a : []; } catch (e) { memQ = []; }
  return memQ;
}
function saveQ() { store(false, KEY_Q, JSON.stringify(readQ())); paint(); }
function uid() { return 'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10); }
function isApi(u) { return typeof u === 'string' && u.indexOf('boot.php') !== -1 && u.split('?')[0].indexOf('boot.php') !== -1; }
function userKey() { try { var u = window.Auth && Auth.getUser && Auth.getUser(); return u && u.id ? String(u.id) : ''; } catch (e) { return ''; } }
function hhmm(t) { var d = new Date(t); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
function jsonResponse(obj) { return new Response(JSON.stringify(obj), { status: 200, headers: { 'Content-Type': 'application/json' } }); }
function mine() { var u = userKey(); return readQ().filter(function (e) { return !e.user || e.user === u; }); }

var LABEL = { picked_up: 'Picked up', in_transit: 'In transit', delivered: 'Delivered' };
function describe(e) {
  if (e.action === 'courier_add_fuel_log') return 'Fuel fill-up ' + (e.body.liters || '') + ' L';
  return (LABEL[e.body.status] || e.body.status) + ' · order #' + e.body.order_id;
}

/* ---- saved orders ---- */
function ckey(url) { return userKey() + '|' + url.replace(/^.*[?]/, ''); }
function saveOrders(url, data) {
  var entry = { at: Date.now(), data: data };
  memC[ckey(url)] = entry;
  try {
    var all = JSON.parse(store(true, KEY_C) || '{}'); if (!all || typeof all !== 'object') all = {};
    all[ckey(url)] = entry;
    var keys = Object.keys(all); if (keys.length > 6) keys.sort(function (a, b) { return all[a].at - all[b].at; }).slice(0, keys.length - 6).forEach(function (k) { delete all[k]; });
    store(false, KEY_C, JSON.stringify(all));
  } catch (e) {}
}
function loadOrders(url) {
  if (memC[ckey(url)]) return memC[ckey(url)];
  try { var all = JSON.parse(store(true, KEY_C) || '{}'); return all && all[ckey(url)] || null; } catch (e) { return null; }
}
/* what the saved list would look like once the waiting status changes are applied */
function overlay(data) {
  var pend = mine().filter(function (e) { return e.action === 'courier_update_order_status' && !e.failed; });
  if (!pend.length || !data || !data.orders) return data;
  var out = JSON.parse(JSON.stringify(data));
  out.orders.forEach(function (o) {
    pend.forEach(function (e) { if (String(o.id) === String(e.body.order_id)) o.status = e.body.status; });
  });
  return out;
}

/* ---- banner ---- */
var staleAt = 0;
function css() {
  if (document.getElementById('cof-css')) return;
  var s = document.createElement('style'); s.id = 'cof-css';
  s.textContent = '#cof-bar{position:fixed;left:10px;right:10px;bottom:calc(10px + env(safe-area-inset-bottom,0px));z-index:9998;background:#1b1b2a;color:#f4f1e6;border:1.5px solid #f2c14e;border-radius:14px;padding:10px 12px;font:600 13px/1.4 system-ui,sans-serif;box-shadow:0 8px 28px rgba(0,0,0,.45);display:none}' +
    '#cof-bar.on{display:block}#cof-bar b{color:#f2c14e}#cof-bar .r{display:flex;gap:8px;align-items:center;justify-content:space-between}' +
    '#cof-bar button{font:inherit;border:0;border-radius:9px;padding:7px 11px;cursor:pointer;background:#f2c14e;color:#1b1b2a}#cof-bar button.g{background:transparent;color:#f4f1e6;border:1px solid #6b6b80}' +
    '#cof-bar ul{list-style:none;margin:8px 0 0;padding:0;max-height:34vh;overflow:auto}#cof-bar li{display:flex;gap:8px;justify-content:space-between;align-items:center;padding:6px 0;border-top:1px solid #34344a;font-weight:500}' +
    '#cof-bar li.bad{color:#ff8a98}#cof-bar button:focus-visible{outline:2px solid #fff;outline-offset:2px}';
  document.head.appendChild(s);
}
function paint() {
  if (!document.body) return;
  css();
  var bar = document.getElementById('cof-bar');
  if (!bar) { bar = document.createElement('div'); bar.id = 'cof-bar'; bar.setAttribute('role', 'status'); bar.setAttribute('aria-live', 'polite'); document.body.appendChild(bar); }
  var q = mine(), off = navigator.onLine === false;
  if (!q.length && !off && !staleAt) { bar.className = ''; bar.innerHTML = ''; return; }
  var head = off ? '📡 <b>No connection.</b> ' : '';
  if (staleAt) head += 'Showing saved orders from <b>' + esc(hhmm(staleAt)) + '</b>. ';
  var html = '<div class="r"><span>' + head + (q.length ? q.length + ' waiting to send' : (off ? 'Your work is saved on this phone.' : '')) + '</span>' +
    (q.length ? '<button type="button" data-cof="send">Send now</button>' : '') + '</div>';
  if (q.length) {
    html += '<ul>' + q.map(function (e) {
      return '<li class="' + (e.failed ? 'bad' : '') + '"><span>' + esc(describe(e)) + (e.failed ? ' — ' + esc(e.failed) : ' · ' + esc(hhmm(e.at))) + '</span>' +
        '<button type="button" class="g" data-cof="drop" data-id="' + esc(e.id) + '" aria-label="Discard ' + esc(describe(e)) + '">Discard</button></li>';
    }).join('') + '</ul>';
  }
  bar.innerHTML = html; bar.className = 'on';
}
document.addEventListener('click', function (ev) {
  var b = ev.target && ev.target.closest && ev.target.closest('[data-cof]'); if (!b) return;
  if (b.getAttribute('data-cof') === 'send') flush(true);
  else {
    var id = b.getAttribute('data-id'); memQ = readQ().filter(function (e) { return e.id !== id; }); saveQ();
  }
});

/* ---- sending ---- */
function netDown(e) { return e && (e.name === 'TypeError' || /fetch|network|load failed/i.test(String(e.message))); }
function flush(manual) {
  if (flushing) return Promise.resolve();
  var list = mine().filter(function (e) { return !e.failed; });
  if (!list.length) return Promise.resolve();
  flushing = true;
  var chain = Promise.resolve(), stop = false, sent = 0;
  list.forEach(function (e) {
    chain = chain.then(function () {
      if (stop) return;
      var body = Object.assign({}, e.body, { action: e.action, client_req_id: e.id, occurred_at: new Date(e.at).toISOString() });
      return origFetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (window.Auth && Auth.getToken ? Auth.getToken() : '') }, body: JSON.stringify(body) })
        .then(function (r) { return r.text().then(function (t) { var j = null; try { j = JSON.parse(t); } catch (x) {} return { status: r.status, json: j }; }); })
        .then(function (r) {
          if (r.json && r.json.success) { memQ = readQ().filter(function (x) { return x.id !== e.id; }); sent++; return; }
          if (r.status === 401) { stop = true; return; }
          if (r.status >= 500 || !r.json) { stop = true; return; }
          e.failed = (r.json && r.json.message) || 'The server did not accept this';
        }, function () { stop = true; });
    });
  });
  return chain.then(function () {
    flushing = false; saveQ();
    if (sent) {
      if (typeof window.showToast === 'function') window.showToast('✓ ' + sent + ' saved action' + (sent > 1 ? 's' : '') + ' sent');
      if (typeof window.loadActiveOrders === 'function') { try { window.loadActiveOrders(); } catch (x) {} }
    } else if (manual && typeof window.showToast === 'function' && mine().some(function (e) { return !e.failed; })) window.showToast('Still no connection', 'err');
  });
}

/* ---- the fetch wrapper ---- */
window.fetch = function (input, init) {
  var url = typeof input === 'string' ? input : (input && input.url) || '';
  if (!isApi(url)) return origFetch(input, init);
  var method = ((init && init.method) || (input && input.method) || 'GET').toUpperCase();

  if (method === 'GET') {
    var m = /[?&]action=courier_get_my_orders(&|$)/.exec(url);
    return origFetch(input, init).then(function (r) {
      if (m && r.ok) { staleAt = 0; paint(); r.clone().json().then(function (j) { if (j && j.success) saveOrders(url, j.data); }, function () {}); }
      return r;
    }, function (err) {
      if (!m || !netDown(err)) throw err;
      var c = loadOrders(url); if (!c) throw err;
      staleAt = c.at; paint();
      return jsonResponse({ success: true, offline: true, saved_at: c.at, data: overlay(c.data) });
    });
  }

  var body = null; try { body = JSON.parse((init && init.body) || 'null'); } catch (e) {}
  var action = body && body.action;
  if (!QUEUE_ACTIONS[action]) return origFetch(input, init);
  return origFetch(input, init).catch(function (err) {
    if (!netDown(err)) throw err;
    var clean = Object.assign({}, body); delete clean.action;
    readQ().push({ id: uid(), user: userKey(), action: action, body: clean, at: Date.now() });
    saveQ();
    return jsonResponse({ success: true, queued: true, message: 'Saved on your phone - it will be sent when you are back online', data: { queued: true, status: clean.status } });
  });
};

window.CourierOffline = { flush: function () { return flush(true); }, pending: function () { return mine().length; } };
window.addEventListener('online', function () {
  paint();
  flush(false).then(function () { if (staleAt && typeof window.loadActiveOrders === 'function') { try { window.loadActiveOrders(); } catch (x) {} } });
});
window.addEventListener('offline', paint);
timer = setInterval(function () { if (navigator.onLine !== false && mine().some(function (e) { return !e.failed; })) flush(false); }, 20000);
function start() { paint(); flush(false); }
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();

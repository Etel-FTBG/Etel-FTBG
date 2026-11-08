/* ETEL Agent portal - "Register a partner". Loaded on demand by app.js (only when the agent opens #/partners).
   An agent registers a courier, a driver or a shop (with its products) step by step. The draft is saved on every step, so nothing is lost
   if the phone goes offline. At the end it is sent to the ETEL owner, who approves it; the agent is paid a fixed fee once, after approval.
   Server: fa_partner_* in api/controllers/FieldAgentPartnerController.php. Photos use api/upload.php like the other ETEL apps.
   Texts live here (English + Amharic) so the first screen of the portal stays small. */
(function () {
'use strict';
var P = window.ETELAP; if (!P || window.ETELPartners) return;
var esc = P.esc, ic = P.ic, api = P.api, fmt = P.fmt;

var TX = {
  title: ['Register a partner', '\u12a0\u130b\u122d \u12eb\u1235\u1218\u12dd\u130d\u1261'],
  sub: ['Bring a courier, a driver or a shop to ETEL and get paid once ETEL approves them.', '\u1230\u132a\u1363 \u1239\u134c\u122d \u12c8\u12ed\u121d \u1231\u1245 \u12c8\u12f0 \u12a2\u1275\u12a4\u120d \u12eb\u121d\u1321\u1364 \u12a2\u1275\u12a4\u120d \u1232\u12eb\u1338\u12f5\u1243\u1278\u12cd \u12ed\u12a8\u1348\u120d\u12ce\u1273\u120d\u1362'],
  courier: ['Courier', '\u1270\u120b\u120b\u12aa'], driver: ['Driver', '\u1239\u134c\u122d'], shop: ['Shop or service', '\u1231\u1245 \u12c8\u12ed\u121d \u12a0\u1308\u120d\u130d\u120e\u1275'],
  courier_d: ['Delivers orders on a bike, motorbike or car', '\u1275\u12d5\u12db\u12de\u127d\u1295 \u1260\u1265\u1235\u12ad\u120c\u1275\u1363 \u1260\u121e\u1270\u122d \u12c8\u12ed\u121d \u1260\u1218\u12aa\u1293 \u12eb\u12f0\u122d\u1233\u120d'],
  driver_d: ['Drives passengers (Bajaj, Moto, Car)', '\u1270\u1233\u134b\u122a\u12ce\u127d\u1295 \u12eb\u1313\u1309\u12db\u120d (\u1263\u1303\u1305\u1363 \u121e\u1276\u1363 \u1218\u12aa\u1293)'],
  shop_d: ['Restaurant, shop or service with its products', '\u121d\u130d\u1265 \u1264\u1275\u1363 \u1231\u1245 \u12c8\u12ed\u121d \u12a0\u1308\u120d\u130d\u120e\u1275 \u12a8\u121d\u122d\u1276\u1279 \u130b\u122d'],
  you_earn: ['You earn', '\u12eb\u1308\u129b\u1209'], etb: ['ETB', '\u1265\u122d'], start: ['Start', '\u1300\u121d\u122d'],
  pay_note: ['Paid once, after ETEL approves.', '\u12a2\u1275\u12a4\u120d \u12ab\u1338\u12f0\u1240 \u1260\u128b\u120b \u12a0\u1295\u12f5 \u130a\u12dc \u12ed\u12a8\u1348\u120b\u120d\u1362'],
  mine: ['My partners', '\u12e8\u12a5\u1294 \u12a0\u130b\u122e\u127d'], none: ['Nobody yet. Start with the first one above.', '\u12a5\u1235\u12ab\u1201\u1295 \u121b\u1295\u121d \u12e8\u1208\u121d\u1362 \u12a8\u120b\u12ed \u12e8\u1218\u1300\u1218\u122a\u12eb\u12cd\u1295 \u12ed\u1300\u121d\u1229\u1362'],
  s_draft: ['Draft', '\u1228\u1242\u1245'], s_submitted: ['With ETEL', '\u1260\u12a2\u1275\u12a4\u120d \u130d\u121d\u1308\u121b \u120b\u12ed'], s_changes: ['Changes needed', '\u121b\u1235\u1270\u12ab\u12a8\u12eb \u12eb\u1235\u1348\u120d\u130b\u120d'], s_approved: ['Approved', '\u1338\u12f5\u124b\u120d'], s_rejected: ['Not approved', '\u12a0\u120d\u1338\u12f0\u1240\u121d'],
  counts_wait: ['Waiting', '\u1260\u1218\u1320\u1263\u1260\u1245'], counts_ok: ['Approved', '\u12e8\u1338\u12f0\u1241'], counts_earned: ['Earned', '\u12e8\u1270\u1308\u1298'],
  continue: ['Continue', '\u1240\u1325\u120d'], fix: ['Fix and resend', '\u12a0\u1235\u1270\u12ab\u12ad\u1208\u12cd \u12ed\u120b\u12a9'], view: ['View', '\u12ed\u1218\u120d\u12a8\u1271'], del: ['Delete draft', '\u1228\u1242\u1241\u1295 \u1230\u122d\u12dd'],
  next: ['Next', '\u1240\u1323\u12ed'], back: ['Back', '\u1270\u1218\u1208\u1235'], save: ['Save draft', '\u1228\u1242\u1245 \u12a0\u1235\u1240\u121d\u1325'], saved: ['Saved', '\u1270\u1240\u121d\u1327\u120d'], send: ['Send to ETEL', '\u12c8\u12f0 \u12a2\u1275\u12a4\u120d \u120b\u12ad'], sent: ['Sent. ETEL will check it soon.', '\u1270\u120d\u12b3\u120d\u1362 \u12a2\u1275\u12a4\u120d \u1260\u1245\u122d\u1261 \u12ed\u1218\u1208\u12a8\u1270\u12cb\u120d\u1362'],
  step_of: ['Step {n} of {m}', '\u12f0\u1228\u1303 {n} \u12a8 {m}'],
  st_person: ['Person', '\u1230\u12cd\u12ec\u12cd'], st_vehicle: ['Vehicle', '\u1270\u123d\u12a8\u122d\u12ab\u122a'], st_licence: ['Licence', '\u1218\u1295\u1303 \u1348\u1243\u12f5'], st_contact: ['Contact', '\u12a0\u12f5\u122b\u123b'], st_owner: ['Owner', '\u1263\u1208\u1264\u1275'], st_shop: ['Shop', '\u1231\u1241'], st_products: ['Products', '\u121d\u122d\u1276\u127d'], st_review: ['Review', '\u121b\u1228\u130b\u1308\u132b'],
  name: ['Full name', '\u1219\u1209 \u1235\u121d'], phone: ['Mobile number', '\u121e\u1263\u12ed\u120d \u1241\u1325\u122d'], phone_h: ['Must be their own number. They will use it to sign in.', '\u12e8\u122b\u1233\u1278\u12cd \u1241\u1325\u122d \u1218\u1206\u1295 \u12a0\u1208\u1260\u1275\u1362 \u1208\u1218\u130d\u1263\u1275 \u12ed\u1320\u1240\u1219\u1260\u1273\u120d\u1362'],
  idtype: ['Kind of ID', '\u12e8\u1218\u1273\u12c8\u1242\u12eb \u12a0\u12ed\u1290\u1275'], idnum: ['ID number', '\u12e8\u1218\u1273\u12c8\u1242\u12eb \u1241\u1325\u122d'], idphoto: ['Photo of the ID card', '\u12e8\u1218\u1273\u12c8\u1242\u12eb \u12ab\u122d\u12f5 \u134e\u1276'], selfie: ['Selfie of the person', '\u12e8\u1230\u12cd\u12ec\u12cd \u134e\u1276 (\u1230\u120d\u134a)'],
  tap_photo: ['Tap to take or choose a photo', '\u134e\u1276 \u1208\u121b\u1295\u1233\u1275 \u12c8\u12ed\u121d \u1208\u1218\u121d\u1228\u1325 \u12ed\u1295\u12a9'], change_photo: ['Change photo', '\u134e\u1276 \u1240\u12ed\u122d'], uploading: ['Uploading...', '\u1260\u1218\u132b\u1295 \u120b\u12ed...'],
  national_id: ['National ID', '\u1265\u1214\u122b\u12ca \u1218\u1273\u12c8\u1242\u12eb'], kebele_id: ['Kebele ID', '\u12e8\u1240\u1260\u120c \u1218\u1273\u12c8\u1242\u12eb'], passport: ['Passport', '\u1353\u1235\u1356\u122d\u1275'], driving_licence: ['Driving licence', '\u1218\u1295\u1303 \u1348\u1243\u12f5'],
  vehicle: ['Vehicle', '\u1270\u123d\u12a8\u122d\u12ab\u122a'], plate: ['Plate number', '\u12e8\u1230\u120c\u12f3 \u1241\u1325\u122d'], make: ['Make (optional)', '\u121e\u12f4\u120d (\u12a0\u121b\u122b\u132d)'], color: ['Colour (optional)', '\u1240\u1208\u121d (\u12a0\u121b\u122b\u132d)'], year: ['Year (optional)', '\u12d3\u1218\u1275 (\u12a0\u121b\u122b\u132d)'],
  v_bajaj: ['Bajaj', '\u1263\u1303\u1305'],
  v_qute: ['Qute', '\u12a9\u1274'],
  v_minibus: ['Minibus', '\u121a\u1292\u1263\u1235'],
  v_ev_car: ['Electric vehicle (EV)', '\u12a4\u120c\u12ad\u1275\u122a\u12ad \u1270\u123d\u12a8\u122d\u12ab\u122a (EV)'],
  v_motorcycle: ['Motorcycle', '\u121e\u1270\u122d'], v_car: ['Car', '\u1218\u12aa\u1293'], v_bicycle: ['Bicycle', '\u1265\u1235\u12ad\u120c\u1275'], v_on_foot: ['On foot', '\u1260\u12a5\u130d\u122d'],
  lic_name: ['Name on the licence', '\u1260\u1218\u1295\u1303 \u1348\u1243\u12f1 \u120b\u12ed \u12eb\u1208 \u1235\u121d'], lic_num: ['Licence number', '\u12e8\u1218\u1295\u1303 \u1348\u1243\u12f5 \u1241\u1325\u122d'], lic_photo: ['Photo of the licence', '\u12e8\u1218\u1295\u1303 \u1348\u1243\u12f5 \u134e\u1276'], lic_opt: ['Licence number (optional)', '\u12e8\u1218\u1295\u1303 \u1348\u1243\u12f5 \u1241\u1325\u122d (\u12a0\u121b\u122b\u132d)'],
  em_name: ['Contact person (relative or friend)', '\u12e8\u12d8\u1218\u12f5 \u12c8\u12ed\u121d \u12e8\u1313\u12f0\u129b \u1235\u121d'], em_phone: ['Their phone number', '\u1235\u120d\u12ad \u1241\u1325\u122b\u1278\u12cd'], zone: ['Area or neighbourhood', '\u1230\u1348\u122d'], address: ['Address', '\u12a0\u12f5\u122b\u123b'],
  biz: ['Shop name', '\u12e8\u1231\u1241 \u1235\u121d'], biz_type: ['What does it sell?', '\u121d\u1295 \u12ed\u1238\u1323\u120d?'], desc: ['Short description (optional)', '\u12a0\u132d\u122d \u1218\u130d\u1208\u132b (\u12a0\u121b\u122b\u132d)'], city: ['City', '\u12a8\u1270\u121b'],
  loc: ['Shop location', '\u12e8\u1231\u1241 \u12a0\u12ab\u1263\u1262'], loc_btn: ['Use my location (stand at the shop)', '\u12a0\u12ab\u1263\u1262\u12ec\u1295 \u1270\u1320\u1240\u121d (\u1231\u1241 \u130b\u122d \u12ed\u1241\u1219)'], loc_ok: ['Location saved', '\u12a0\u12ab\u1263\u1262 \u1270\u1240\u121d\u1327\u120d'], loc_wait: ['Finding the location...', '\u12a0\u12ab\u1263\u1262 \u1260\u1218\u1348\u1208\u130d \u120b\u12ed...'], loc_err: ['Could not get the location. Allow location and try again.', '\u12a0\u12ab\u1263\u1262 \u121b\u130d\u1298\u1275 \u12a0\u120d\u1270\u127b\u1208\u121d\u1362 \u12a0\u12ab\u1263\u1262\u1295 \u1348\u1245\u12f0\u12cd \u12f0\u130d\u1218\u12cd \u12ed\u121e\u12ad\u1229\u1362'],
  logo: ['Logo or photo of the front', '\u12a0\u122d\u121b \u12c8\u12ed\u121d \u12e8\u134a\u1275 \u1208\u134a\u1275 \u134e\u1276'], cover: ['Cover photo (optional)', '\u12e8\u123d\u134b\u1295 \u134e\u1276 (\u12a0\u121b\u122b\u132d)'], opens: ['Opens', '\u12e8\u121a\u12a8\u1348\u1275\u1260\u1275'], closes: ['Closes', '\u12e8\u121a\u12d8\u130b\u1260\u1275'],
  t_restaurant: ['Restaurant', '\u121d\u130d\u1265 \u1264\u1275'], t_cafe: ['Cafe', '\u12ab\u134c'], t_fastfood: ['Fast food', '\u1348\u1323\u1295 \u121d\u130d\u1265'], t_grocery: ['Grocery', '\u130d\u122e\u1230\u122a'], t_pharmacy: ['Pharmacy', '\u134b\u122d\u121b\u1232'], t_laundry: ['Laundry', '\u120d\u1265\u1235 \u121b\u1320\u1262\u12eb'], t_electronics: ['Electronics', '\u12a4\u120c\u12ad\u1275\u122e\u1292\u12ad\u1235'], t_retail: ['Shop', '\u1231\u1245'], t_custom: ['Other', '\u120c\u120b'],
  p_have: ['{n} of {m} products', '{n} \u12a8 {m} \u121d\u122d\u1276\u127d'], p_add: ['Add a product', '\u121d\u122d\u1275 \u1328\u121d\u122d'], p_name: ['Product name', '\u12e8\u121d\u122d\u1271 \u1235\u121d'], p_name_am: ['Name in Amharic (optional)', '\u1260\u12a0\u121b\u122d\u129b \u1235\u121d (\u12a0\u121b\u122b\u132d)'], p_price: ['Price (ETB)', '\u12cb\u130b (\u1265\u122d)'], p_cat: ['Type', '\u12a0\u12ed\u1290\u1275'], p_prep: ['Minutes to prepare', '\u1208\u121b\u12d8\u130b\u1300\u1275 \u12f0\u1242\u1243'], p_photo: ['Photo of the product', '\u12e8\u121d\u122d\u1271 \u134e\u1276'], p_desc: ['Description (optional)', '\u1218\u130d\u1208\u132b (\u12a0\u121b\u122b\u132d)'],
  p_done: ['Add product', '\u121d\u122d\u1271\u1295 \u1328\u121d\u122d'], p_edit: ['Save product', '\u121d\u122d\u1271\u1295 \u12a0\u1235\u1240\u121d\u1325'], p_remove: ['Remove', '\u12a0\u1235\u12c8\u130d\u12f5'], p_empty: ['No products yet. A shop needs at least {m} to start.', '\u12a5\u1235\u12ab\u1201\u1295 \u121d\u122d\u1275 \u12e8\u1208\u121d\u1362 \u1231\u1245 \u1208\u1218\u1300\u1218\u122d \u1262\u12eb\u1295\u1235 {m} \u12eb\u1235\u1348\u120d\u1308\u12cb\u120d\u1362'], p_cancel: ['Cancel', '\u1270\u12cd'],
  c_food: ['Food', '\u121d\u130d\u1265'], c_grocery: ['Grocery', '\u1238\u1240\u1323 \u1238\u1240\u1325'], c_pharmacy: ['Pharmacy', '\u1218\u12f5\u1203\u1292\u1275'], c_flower: ['Flowers', '\u12a0\u1260\u1263'], c_package: ['Other', '\u120c\u120b'],
  rv_title: ['Check before sending', '\u12a8\u1218\u120b\u12ad\u12ce \u1260\u134a\u1275 \u12eb\u1228\u130b\u130d\u1321'], rv_ok: ['Everything is filled in.', '\u1201\u1209\u121d \u1270\u121e\u120d\u1277\u120d\u1362'], rv_missing: ['Still missing', '\u1308\u1293 \u12eb\u120d\u1270\u121e\u120b'], rv_fix: ['Fix', '\u12a0\u1235\u1270\u12ab\u12ad\u120d'],
  fee_line: ['If ETEL approves, you earn {f} ETB.', '\u12a2\u1275\u12a4\u120d \u12ab\u1338\u12f0\u1240 {f} \u1265\u122d \u12eb\u1308\u129b\u1209\u1362'],
  note_title: ['ETEL says', '\u12a2\u1275\u12a4\u120d \u12a5\u1295\u12f2\u1205 \u12ed\u120b\u120d'], saving: ['Saving...', '\u1260\u121b\u1235\u1240\u1218\u1325 \u120b\u12ed...'],
  phone_taken: ['This phone number already has an ETEL account.', '\u12ed\u1205 \u1235\u120d\u12ad \u1241\u1325\u122d \u12a0\u1235\u1240\u12f5\u121e \u12e8\u12a2\u1275\u12a4\u120d \u1218\u1208\u12eb \u12a0\u1208\u12cd\u1362'],
  off: ['Registering partners is switched off right now.', '\u12a0\u130b\u122d \u121b\u1235\u1218\u12dd\u1308\u1265 \u1208\u130a\u12dc\u12cd \u1270\u12d8\u130d\u1277\u120d\u1362'], daily: ['You reached the daily limit. Try again tomorrow.', '\u12e8\u12d5\u1208\u1275 \u1308\u12f0\u1265\u12ce \u12f0\u122d\u1237\u120d\u1362 \u1290\u1308 \u12ed\u121e\u12ad\u1229\u1362'],
  del_q: ['Delete this draft?', '\u12ed\u1205 \u1228\u1242\u1245 \u12ed\u1230\u1228\u12dd?'], yes: ['Yes, delete', '\u12a0\u12ce\u1363 \u1230\u122d\u12dd'], no: ['Keep it', '\u1270\u12c8\u12cd'], home_btn: ['Register a courier, driver or shop', '\u1270\u120b\u120b\u12aa\u1363 \u1239\u134c\u122d \u12c8\u12ed\u121d \u1231\u1245 \u12eb\u1235\u1218\u12dd\u130d\u1261'], retry: ['Try again', '\u12a5\u1295\u12f0\u1308\u1293 \u121e\u12ad\u122d'],
  dup_block: ['Already on ETEL', '\u12a0\u1235\u1240\u12f5\u121e \u1260\u12a2\u1275\u12a4\u120d \u120b\u12ed \u12a0\u1208'],
  dup_warn: ['Looks similar. Please check.', '\u1270\u1218\u1233\u1233\u12ed \u1290\u12cd\u1362 \u12a5\u1263\u12ad\u12ce \u12eb\u1228\u130b\u130d\u1321\u1362'],
  dup_cant: ['You cannot send this one. Change the details or choose someone else.', '\u12ed\u1205\u1295 \u1218\u120b\u12ad \u12a0\u12ed\u127d\u1209\u121d\u1362 \u1218\u1228\u1303\u12cd\u1295 \u12ed\u1240\u12ed\u1229 \u12c8\u12ed\u121d \u120c\u120b \u1230\u12cd \u12ed\u121d\u1228\u1321\u1362'],
  dup_ok: ['Not found on ETEL yet.', '\u1260\u12a2\u1275\u12a4\u120d \u120b\u12ed \u12a5\u1235\u12ab\u1201\u1295 \u12a0\u120d\u1270\u1308\u1298\u121d\u1362'],
  dup_checking: ['Checking ETEL...', '\u12a2\u1275\u12a4\u120d\u1295 \u1260\u1218\u1348\u1270\u123d \u120b\u12ed...'],
  role_no: ['ETEL has not given you this role. Ask the ETEL team.', '\u12a2\u1275\u12a4\u120d \u12ed\u1205\u1295 \u121a\u1293 \u12a0\u120d\u1230\u1320\u12ce\u1275\u121d\u1362 \u12e8\u12a2\u1275\u12a4\u120d\u1295 \u1261\u12f5\u1295 \u12ed\u1320\u12ed\u1241\u1362'],
  pay_wait: ['Payment arrives {d} days after approval.', '\u12ad\u134d\u12eb\u12cd \u12a8\u1338\u12f0\u1240 \u12a8{d} \u1240\u1293\u1275 \u1260\u128b\u120b \u12ed\u12f0\u122d\u1233\u120d\u1362']
};
function L() { return P.S.lang === 'en' ? 0 : 1; }
function tx(k, v) { var e = TX[k]; var s = e ? e[L()] : k; if (v) for (var x in v) if (Object.prototype.hasOwnProperty.call(v, x)) s = s.split('{' + x + '}').join(v[x]); return s; }

var STEPS = { courier: ['person', 'vehicle', 'contact', 'review'], driver: ['person', 'vehicle', 'licence', 'contact', 'review'], shop: ['owner', 'shop', 'products', 'review'] };
var COURIER_VEH = ['motorcycle', 'car', 'bicycle', 'bajaj', 'qute', 'minibus', 'ev_car'];
var ID_TYPES = ['national_id', 'kebele_id', 'passport', 'driving_licence'];
var SHOP_TYPES = ['restaurant', 'cafe', 'fastfood', 'grocery', 'pharmacy', 'laundry', 'electronics', 'retail', 'custom'];
var ITEM_CATS = ['food', 'grocery', 'pharmacy', 'flower', 'package'];
/* Real pictures, like the admin's registration screens: the same vehicle artwork as the Ride app (agent/veh/*.webp). A vehicle or shop with no picture
   file yet (bicycle, on foot, shop) keeps a drawn icon until the file is added: bicycle.webp, on_foot.webp, shop.webp in agent/veh/. */
var VPIC = { bajaj: 'bajaj', qute: 'qute', moto: 'moto', motorcycle: 'moto', car: 'car', ev_car: 'ev_car', minibus: 'minibus', van: 'minibus', bicycle: 'bicycle', on_foot: 'on_foot', shop: 'shop' };
var VICON = { bicycle: 'moto', on_foot: 'users', shop: 'badge', moto: 'moto', motorcycle: 'moto', car: 'car', bajaj: 'bajaj', qute: 'bajaj', ev_car: 'bajaj', minibus: 'bus', van: 'bus' };
function vkey(v) {
  v = String(v || '').toLowerCase().replace(/[\s-]+/g, '_');
  if (VPIC[v]) return VPIC[v];
  if (v.indexOf('moto') >= 0) return 'moto'; if (v.indexOf('qute') >= 0) return 'qute'; if (v.indexOf('bajaj') >= 0 || v.indexOf('tuk') >= 0) return 'bajaj';
  if (v.indexOf('mini') >= 0 || v.indexOf('bus') >= 0 || v.indexOf('van') >= 0) return 'minibus'; if (v.indexOf('bicy') >= 0) return 'bicycle'; if (v.indexOf('foot') >= 0) return 'on_foot'; if (v.indexOf('car') >= 0) return 'car';
  return '';
}
function vpic(v, w, cls) {
  var k = vkey(v); if (!k) return '';
  return '<img class="pt-vimg ' + (cls || '') + '" data-ico="' + esc(VICON[k] || 'car') + '" src="veh/' + k + '.webp" alt="" width="' + w + '" height="' + Math.round(w * 0.7) + '" decoding="async" draggable="false">';
}
/* a picture file that is not there yet is swapped for the drawn icon: never a broken image */
document.addEventListener('error', function (e) { var im = e.target; if (im && im.classList && im.classList.contains('pt-vimg')) { var s = document.createElement('span'); s.className = 'pt-vico'; s.innerHTML = ic(im.getAttribute('data-ico') || 'car'); im.replaceWith(s); } }, true);
function vlabel(v) { var k = 'v_' + v; if (TX[k]) return tx(k); var s = P.t('veh.' + v); return s === 'veh.' + v ? v.charAt(0).toUpperCase() + v.slice(1).replace(/_/g, ' ') : s; }
function vtiles(name, list, cur, labelFn) {
  return '<div class="pt-vts" role="radiogroup">' + list.map(function (v) { var on = cur === v; return '<button type="button" class="pt-vt' + (on ? ' on' : '') + '" role="radio" aria-checked="' + on + '" data-act="pt-set" data-n="' + name + '" data-v="' + esc(v) + '"><span class="pt-vpic">' + (vpic(v, 96) || ic('car')) + '</span><b>' + esc(labelFn(v)) + '</b></button>'; }).join('') + '</div>';
}
var UPTYPE = { courier: { id_photo_url: 'courier_id', selfie_url: 'courier_selfie', licence_photo_url: 'courier_licence' }, driver: { id_photo_url: 'courier_id', selfie_url: 'driver_selfie', license_photo_url: 'driver_license' }, shop: { id_photo_url: 'courier_id', selfie_url: 'courier_selfie', logo_url: 'shop_cover', cover_url: 'shop_cover', item: 'items' } };

var Q = { list: null, counts: {}, fees: {}, minItems: 5, payDays: 0, err: '', cur: null, step: 0, busy: false, missing: {}, prev: {}, up: 0, msg: '', vehicles: null, areas: null, item: null, geo: '', loaded: false, dups: { matches: [], blocked: false, done: false }, dupKey: '', dupTimer: null, dupBusy: false };

/* ---------------- data ---------------- */
function reason(r) {
  var c = r && r.j && r.j.reason ? r.j.reason : '';
  if (c.indexOf('ROLE_') === 0) return tx('role_no');
  if (c === 'PHONE_TAKEN') return tx('phone_taken'); if (c === 'PARTNERS_OFF') return tx('off'); if (c === 'DAILY_CAP') return tx('daily');
  if (r && r.net) return P.t('c.offline');
  return (r && r.j && r.j.message) ? r.j.message : P.t('c.error');
}
function load() {
  Q.err = '';
  return api('fa_partner_list').then(function (r) {
    if (r.ok) { Q.list = r.data.partners; Q.counts = r.data.counts; Q.fees = r.data.fees; Q.minItems = r.data.shop_min_items; Q.payDays = r.data.pay_days; Q.earned = r.data.earned; Q.allowed = r.data.allowed_kinds; }
    else { Q.list = r.net ? [] : null; Q.err = reason(r); }
    Q.loaded = true; if (P.S.view === 'partners') P.render();
  });
}
function loadVehicles() { if (Q.vehicles) return; api('fa_agent_vehicles').then(function (r) { Q.vehicles = r.ok ? r.data.vehicles.map(function (v) { return v.type; }) : ['bajaj', 'moto', 'car']; if (P.S.view === 'partners') P.render(); }); }
function loadAreas(lat, lng) {
  api('get_service_areas', lat ? { lat: lat, lng: lng } : {}).then(function (r) {
    if (!r.ok) return; Q.areas = r.data.areas || [];
    if (r.data.current && Q.cur && Q.cur.kind === 'shop' && !Q.cur.data.city) Q.cur.data.city = r.data.current.name;
    if (P.S.view === 'partners') P.render();
  });
}
function blank(kind) { return { id: 0, kind: kind, name: '', phone: '', id_number: '', data: { id_type: 'national_id', city: '', vehicle_type: kind === 'courier' ? 'motorcycle' : '' }, items: [] }; }
function openDraft(id) {
  Q.busy = true; P.render();
  api('fa_partner_get', { id: id }).then(function (r) {
    Q.busy = false;
    if (!r.ok) { P.toast(reason(r)); P.render(); return; }
    var p = r.data.partner; Q.cur = { id: p.id, kind: p.kind, name: p.name, phone: p.phone, id_number: p.id_number, data: p.data || {}, items: p.items || [], status: p.status, note: p.decision_note };
    Q.step = 0; Q.missing = {}; Q.prev = {}; Q.item = null; startKind(); P.render();
  });
}
function startKind() { Q.dups = { matches: [], blocked: false, done: false }; Q.dupKey = ''; if (Q.cur.kind === 'driver') loadVehicles(); if (Q.cur.kind === 'shop') loadAreas(); window.scrollTo(0, 0); }

/* ---------------- helpers ---------------- */
function fld(label, id, val, extra, hint) {
  return '<label class="fld"><span>' + esc(label) + '</span><input class="in" id="' + id + '" data-pf="' + id.replace('pt-', '') + '" value="' + esc(val == null ? '' : val) + '" ' + (extra || '') + '>' + (hint ? '<small class="pt-h">' + esc(hint) + '</small>' : '') + '</label>';
}
function miss(k) { return Q.missing[k] ? '<p class="pt-miss" role="alert">' + esc(Q.missing[k]) + '</p>' : ''; }
function chips(name, list, cur, labelFn) {
  return '<div class="pt-chips" role="radiogroup">' + list.map(function (v) { var on = cur === v; return '<button type="button" class="pt-chip' + (on ? ' on' : '') + '" role="radio" aria-checked="' + on + '" data-act="pt-set" data-n="' + name + '" data-v="' + esc(v) + '">' + esc(labelFn(v)) + '</button>'; }).join('') + '</div>';
}
function photo(key, title, cap) {
  var url = Q.cur.data[key], pv = Q.prev[key], has = !!(url || pv);
  return '<div class="fld"><span>' + esc(title) + '</span><button type="button" class="pt-up' + (has ? ' has' : '') + '" data-act="pt-pick" data-k="' + key + '"' + (pv ? ' style="background-image:url(\'' + pv + '\')"' : '') + ' aria-label="' + esc(title) + '">' +
    (has ? '<span class="pt-chg">' + esc(url ? tx('change_photo') : tx('uploading')) + '</span>' : ic('image') + '<b>' + esc(tx('tap_photo')) + '</b>') + '</button>' +
    '<input type="file" accept="image/*"' + (cap ? ' capture="' + cap + '"' : '') + ' hidden data-file="' + key + '">' + miss(key) + '</div>';
}
/* typed text lives in the inputs; copy it into the draft before any re-render */
function sync() {
  document.querySelectorAll('[data-pf]').forEach(function (e) {
    var k = e.getAttribute('data-pf'), v = e.value;
    if (k === 'name' || k === 'phone' || k === 'id_number') Q.cur[k] = v; else if (k.indexOf('i_') === 0) { if (Q.item) Q.item[k.slice(2)] = v; } else Q.cur.data[k] = v;
  });
}
function stepKey() { return STEPS[Q.cur.kind][Q.step]; }

/* ---------------- live "already on ETEL?" check ----------------
   While the agent types, the name, phone, ID number, plate, licence and shop details are checked against the live database. Exact matches (same person)
   block sending; similar ones are only a warning. Only the kind and a short masked name of the other record is shown. */
var DUPF = ['pt-name', 'pt-phone', 'pt-id_number', 'pt-plate_number', 'pt-vehicle_plate', 'pt-licence_number', 'pt-license_number', 'pt-business_name'];
function dupPayload() { var c = Q.cur; return { id: c.id || 0, kind: c.kind, name: c.name, phone: c.phone, id_number: c.id_number, data: c.data }; }
function dupHtml() {
  var d = Q.dups, h = '';
  if (!Q.cur) return '';
  var blocks = d.matches.filter(function (m) { return m.level === 'block'; }), warns = d.matches.filter(function (m) { return m.level !== 'block'; });
  var line = function (m) { return '<li>' + esc(L() ? (m.message_am || m.message) : m.message) + (m.who ? ' <small>(' + esc(m.who) + ')</small>' : '') + '</li>'; };
  if (blocks.length) h += '<div class="pt-dup bad" role="alert"><b>' + ic('x') + esc(tx('dup_block')) + '</b><ul>' + blocks.map(line).join('') + '</ul><p>' + esc(tx('dup_cant')) + '</p></div>';
  if (warns.length) h += '<div class="pt-dup warn" role="status"><b>' + ic('info') + esc(tx('dup_warn')) + '</b><ul>' + warns.map(line).join('') + '</ul></div>';
  if (!blocks.length && !warns.length && d.done) h += '<p class="pt-dupok">' + ic('check') + esc(tx('dup_ok')) + '</p>';
  return h;
}
/* update only the panel, the field borders and the Next / Send buttons: never redraw (that would disturb typing) */
function paintDups() {
  var box = document.getElementById('pt-dups'); if (!box) return;
  box.innerHTML = dupHtml();
  var lv = {}; Q.dups.matches.forEach(function (m) { var id = ({ phone: 'pt-phone', id_number: 'pt-id_number', name: 'pt-name', plate_number: 'pt-plate_number', vehicle_plate: 'pt-vehicle_plate', licence_number: 'pt-licence_number', license_number: 'pt-license_number', business_name: 'pt-business_name' })[m.field]; if (id && lv[id] !== 'block') lv[id] = m.level; });
  DUPF.forEach(function (id) { var e = document.getElementById(id); if (e) { if (lv[id]) e.setAttribute('data-dup', lv[id]); else e.removeAttribute('data-dup'); } });
  var lock = !!Q.dups.blocked;
  document.querySelectorAll('[data-act="pt-next"],#pt-send').forEach(function (b) { b.disabled = lock || (b.id === 'pt-send' && (Q.busy || Q.up > 0)) || (b.getAttribute('data-act') === 'pt-next' && Q.busy); });
}
function checkNow() {
  if (!Q.cur || Q.dupBusy) return; sync();
  var body = dupPayload(), key = JSON.stringify(body);
  if (!(body.name || body.phone || body.id_number)) { Q.dups = { matches: [], blocked: false, done: false }; paintDups(); return; }
  if (key === Q.dupKey) { paintDups(); return; }
  Q.dupBusy = true; Q.dupKey = key;
  api('fa_partner_check', body).then(function (r) {
    Q.dupBusy = false;
    if (r.ok && Q.cur) { Q.dups = { matches: r.data.matches || [], blocked: !!r.data.blocked, done: true }; paintDups(); }
    if (JSON.stringify(dupPayload()) !== key) queueCheck(300);   // the agent kept typing while we asked
  });
}
function queueCheck(ms) { clearTimeout(Q.dupTimer); Q.dupTimer = setTimeout(checkNow, ms == null ? 600 : ms); }
document.addEventListener('input', function (e) { if (P.S.view === 'partners' && Q.cur && e.target && DUPF.indexOf(e.target.id) >= 0) queueCheck(); });

/* ---------------- screens ---------------- */
function hub() {
  Q.domFor = null;
  var h = '<h1>' + esc(tx('title')) + '</h1><p class="sub">' + esc(tx('sub')) + '</p>';
  if (Q.err) h += '<div class="err" role="alert">' + esc(Q.err) + ' <button class="btn" style="margin-top:8px" data-act="pt-reload">' + esc(tx('retry')) + '</button></div>';
  h += '<div class="pt-kinds">' + ['courier', 'driver', 'shop'].filter(function (k) { return !Array.isArray(Q.allowed) || Q.allowed.indexOf(k) >= 0; }).map(function (k) {
    return '<section class="card pt-kind"><div class="pt-ki">' + vpic(k === 'shop' ? 'shop' : (k === 'driver' ? 'bajaj' : 'moto'), 72, 'big') + '</div><div class="pt-kt"><b>' + esc(tx(k)) + '</b><small>' + esc(tx(k + '_d')) + '</small></div>' +
      '<div class="pt-fee"><small>' + esc(tx('you_earn')) + '</small><b class="num">' + esc(fmt(Q.fees[k] || 0)) + ' <i>' + esc(tx('etb')) + '</i></b></div>' +
      '<button class="btn primary" data-act="pt-new" data-k="' + k + '">' + ic('plus') + esc(tx('start')) + '</button></section>';
  }).join('') + '</div><p class="sub" style="margin-top:10px">' + esc(tx('pay_note')) + (Q.payDays ? ' ' + esc(tx('pay_wait', { d: Q.payDays })) : '') + '</p>';
  var c = Q.counts || {};
  h += '<div class="bk3" style="margin-top:14px"><div class="bk"><span><i style="background:var(--warn)"></i>' + esc(tx('counts_wait')) + '</span><b class="num">' + ((c.submitted || 0) + (c.changes || 0)) + '</b></div>' +
    '<div class="bk"><span><i style="background:var(--ok)"></i>' + esc(tx('counts_ok')) + '</span><b class="num">' + (c.approved || 0) + '</b></div>' +
    '<div class="bk"><span><i style="background:var(--gold)"></i>' + esc(tx('counts_earned')) + '</span><b class="num">' + esc(fmt(Q.earned || 0)) + '</b></div></div>';
  h += '<h2>' + esc(tx('mine')) + '</h2>';
  if (Q.list === null) h += '<div class="stack"><div class="skel" style="height:64px"></div><div class="skel" style="height:64px"></div></div>';
  else if (!Q.list.length) h += '<div class="empty">' + ic('users') + '<p>' + esc(tx('none')) + '</p></div>';
  else h += '<div class="list">' + Q.list.map(function (x) {
    var tone = x.status === 'approved' ? 'ok' : (x.status === 'submitted' ? 'warn' : (x.status === 'changes' || x.status === 'rejected' ? 'bad' : ''));
    var act = (x.status === 'draft' || x.status === 'changes') ? '<button class="btn" style="min-height:44px;width:auto;padding:6px 14px" data-act="pt-open" data-id="' + x.id + '">' + esc(x.status === 'changes' ? tx('fix') : tx('continue')) + '</button>' : '';
    var th = x.logo ? '<img class="pt-lth" src="' + esc(x.logo) + '" alt="" width="48" height="48" loading="lazy">' : (vpic(x.vehicle || (x.kind === 'shop' ? 'shop' : ''), 56) || '');
    return '<div class="li">' + (th ? '<span class="pt-lpic">' + th + '</span>' : '') + '<div class="m"><b>' + esc(x.name || x.phone || '-') + '</b><small>' + esc(tx(x.kind)) + (x.item_count ? ' \u00b7 ' + x.item_count : '') + ' \u00b7 ' + esc(P.when(x.updated_at)) + '</small>' +
      (x.decision_note && (x.status === 'changes' || x.status === 'rejected') ? '<small class="pt-note">' + esc(x.decision_note) + '</small>' : '') +
      (x.status === 'approved' && x.fee_amount ? '<small>+' + esc(fmt(x.fee_amount)) + ' ' + esc(tx('etb')) + '</small>' : '') + '</div><span class="chip ' + tone + '">' + esc(tx('s_' + x.status)) + '</span>' + act + '</div>';
  }).join('') + '</div>';
  return P.shell(h);
}

function bar() {
  var steps = STEPS[Q.cur.kind], n = steps.length;
  return '<div class="pt-bar" role="progressbar" aria-valuemin="1" aria-valuemax="' + n + '" aria-valuenow="' + (Q.step + 1) + '"><i style="width:' + Math.round((Q.step + 1) / n * 100) + '%"></i></div>' +
    '<p class="sub pt-stepn">' + esc(tx('step_of', { n: Q.step + 1, m: n })) + ' \u00b7 <b>' + esc(tx('st_' + stepKey())) + '</b></p>';
}
function idFields(withLic) {
  var d = Q.cur.data;
  return fld(tx('name'), 'pt-name', Q.cur.name, 'maxlength="100" autocomplete="off"') + miss('name') +
    fld(tx('phone'), 'pt-phone', Q.cur.phone, 'type="tel" inputmode="tel" maxlength="16" autocomplete="off" class="in ltr"', tx('phone_h')) + miss('phone') +
    '<div class="fld"><span>' + esc(tx('idtype')) + '</span>' + chips('id_type', ID_TYPES, d.id_type || 'national_id', function (v) { return tx(v); }) + '</div>' +
    fld(tx('idnum'), 'pt-id_number', Q.cur.id_number, 'maxlength="60" autocomplete="off"') +
    photo('id_photo_url', tx('idphoto'), 'environment') + photo('selfie_url', tx('selfie'), 'user');
}
function contactFields() {
  var d = Q.cur.data;
  return fld(tx('em_name'), 'pt-emergency_name', d.emergency_name, 'maxlength="100"') + fld(tx('em_phone'), 'pt-emergency_phone', d.emergency_phone, 'type="tel" inputmode="tel" maxlength="16"') + miss('emergency_phone') +
    fld(tx('zone'), 'pt-zone', d.zone, 'maxlength="80"') + fld(tx('address'), 'pt-address', d.address, 'maxlength="200"');
}
function productsStep() {
  var items = Q.cur.items, need = Q.minItems, enough = items.length >= need;
  if (enough) delete Q.missing.items;
  var h = '<div class="pt-prog' + (enough ? ' ok' : '') + '"><b class="num">' + esc(tx('p_have', { n: items.length, m: need })) + '</b><div class="pt-bar sm"><i style="width:' + Math.min(100, Math.round(items.length / Math.max(1, need) * 100)) + '%"></i></div></div>' + miss('items');
  if (!items.length && !Q.item) h += '<div class="empty">' + ic('badge') + '<p>' + esc(tx('p_empty', { m: need })) + '</p></div>';
  h += '<div class="pt-items">' + items.map(function (it, i) {
    return '<div class="pt-it"><div class="pt-th"' + (it.image_url ? ' style="background-image:url(\'' + esc(it.image_url) + '\')"' : '') + '>' + (it.image_url ? '' : ic('image')) + '</div><div class="m"><b>' + esc(it.name) + '</b><small>' + esc(fmt(it.price)) + ' ' + esc(tx('etb')) + ' \u00b7 ' + esc(tx('c_' + it.category)) + '</small></div>' +
      '<button class="ib" data-act="pt-iedit" data-i="' + i + '" aria-label="' + esc(it.name) + '">' + ic('eye') + '</button><button class="ib" data-act="pt-idel" data-i="' + i + '" aria-label="' + esc(tx('p_remove')) + '">' + ic('x') + '</button></div>';
  }).join('') + '</div>';
  if (Q.item) {
    var it = Q.item;
    h += '<section class="card"><h2 style="margin:0 0 4px;font-size:18px">' + esc(tx('p_add')) + '</h2>' +
      fld(tx('p_name'), 'pt-i_name', it.name, 'maxlength="100" autocomplete="off"') + fld(tx('p_name_am'), 'pt-i_name_am', it.name_am, 'maxlength="100"') +
      fld(tx('p_price'), 'pt-i_price', it.price, 'type="number" inputmode="decimal" min="1" step="1"') +
      '<div class="fld"><span>' + esc(tx('p_cat')) + '</span>' + chips('i_category', ITEM_CATS, it.category || 'food', function (v) { return tx('c_' + v); }) + '</div>' +
      fld(tx('p_prep'), 'pt-i_prep_time', it.prep_time, 'type="number" inputmode="numeric" min="0" max="240"') + fld(tx('p_desc'), 'pt-i_description', it.description, 'maxlength="500"') +
      '<div class="fld"><span>' + esc(tx('p_photo')) + '</span><button type="button" class="pt-up' + (it.image_url || Q.prev.item ? ' has' : '') + '" data-act="pt-pick" data-k="item"' + (Q.prev.item ? ' style="background-image:url(\'' + Q.prev.item + '\')"' : '') + '>' +
      (it.image_url || Q.prev.item ? '<span class="pt-chg">' + esc(it.image_url ? tx('change_photo') : tx('uploading')) + '</span>' : ic('image') + '<b>' + esc(tx('tap_photo')) + '</b>') + '</button><input type="file" accept="image/*" capture="environment" hidden data-file="item"></div>' +
      '<div class="grid2"><button class="btn primary" data-act="pt-isave">' + ic('check') + esc(it.idx != null ? tx('p_edit') : tx('p_done')) + '</button><button class="btn ghost" data-act="pt-icancel">' + esc(tx('p_cancel')) + '</button></div></section>';
  } else h += '<button class="btn" style="margin-top:12px" data-act="pt-iadd">' + ic('plus') + esc(tx('p_add')) + '</button>';
  return h;
}
function shopFields() {
  var d = Q.cur.data, hasLoc = d.lat != null && d.lng != null && d.lat !== '' && d.lng !== '';
  var cities = (Q.areas || []).map(function (a) { return a.name; });
  return fld(tx('biz'), 'pt-business_name', d.business_name, 'maxlength="120"') + miss('business_name') +
    '<div class="fld"><span>' + esc(tx('biz_type')) + '</span>' + chips('service_type', SHOP_TYPES, d.service_type || '', function (v) { return tx('t_' + v); }) + miss('service_type') + '</div>' +
    (cities.length ? '<div class="fld"><span>' + esc(tx('city')) + '</span>' + chips('city', cities, d.city || '', function (v) { return v; }) + '</div>' : '') +
    fld(tx('zone'), 'pt-zone', d.zone, 'maxlength="80"') + fld(tx('address'), 'pt-address', d.address, 'maxlength="200"') + miss('address') +
    '<div class="fld"><span>' + esc(tx('loc')) + '</span><button type="button" class="btn' + (hasLoc ? '' : ' primary') + '" data-act="pt-loc">' + ic('pin') + esc(hasLoc ? tx('loc_ok') + ' \u00b7 ' + Number(d.lat).toFixed(4) + ', ' + Number(d.lng).toFixed(4) : tx('loc_btn')) + '</button>' + (Q.geo ? '<p class="sub">' + esc(Q.geo) + '</p>' : '') + miss('location') + '</div>' +
    photo('logo_url', tx('logo'), 'environment') + photo('cover_url', tx('cover'), 'environment') +
    '<div class="grid2"><label class="fld"><span>' + esc(tx('opens')) + '</span><input class="in" type="time" id="pt-opens_at" data-pf="opens_at" value="' + esc(d.opens_at || '') + '"></label><label class="fld"><span>' + esc(tx('closes')) + '</span><input class="in" type="time" id="pt-closes_at" data-pf="closes_at" value="' + esc(d.closes_at || '') + '"></label></div>' +
    fld(tx('desc'), 'pt-description', d.description, 'maxlength="500"');
}
function reviewStep() {
  var c = Q.cur, d = c.data, keys = Object.keys(Q.missing), h = '<h2 style="margin-top:0">' + esc(tx('rv_title')) + '</h2>';
  var rows = [[tx('name'), c.name], [tx('phone'), c.phone]];
  if (c.kind === 'shop') rows.push([tx('biz'), d.business_name], [tx('city'), d.city], [tx('st_products'), c.items.length]);
  else rows.push([tx('vehicle'), d.vehicle_type], [tx('plate'), d.vehicle_plate || d.plate_number]);
  var rp = c.kind === 'shop' ? (d.logo_url ? '<img class="pt-rimg" src="' + esc(d.logo_url) + '" alt="">' : vpic('shop', 120)) : vpic(d.vehicle_type, 120);
  if (rp) h += '<div class="pt-rpic">' + rp + '</div>';
  h += '<div class="list pt-sum">' + rows.filter(function (r) { return r[1] !== '' && r[1] != null; }).map(function (r) { return '<div class="li"><div class="m"><small>' + esc(r[0]) + '</small><b>' + esc(r[1]) + '</b></div></div>'; }).join('') + '</div>';
  if (keys.length) h += '<div class="err" role="alert"><b>' + esc(tx('rv_missing')) + '</b><ul class="pt-ml">' + keys.map(function (k) { return '<li>' + esc(Q.missing[k]) + '</li>'; }).join('') + '</ul></div>';
  else if (Q.checked) h += '<p class="ver">' + ic('check') + esc(tx('rv_ok')) + '</p>';
  h += '<p class="sub">' + esc(tx('fee_line', { f: fmt(Q.fees[c.kind] || 0) })) + '</p>';
  return h;
}
function wizard() {
  if (Q.domFor === Q.cur) sync();   // an async load (vehicles, cities) may redraw while the agent is typing: keep what was typed
  Q.domFor = Q.cur;
  var c = Q.cur, k = stepKey(), steps = STEPS[c.kind], last = Q.step === steps.length - 1, h = '<button class="ib pt-back" data-act="pt-exit" aria-label="' + esc(tx('back')) + '">' + ic('x') + '</button><h1>' + esc(tx(c.kind)) + '</h1>' + bar();
  if (c.note && c.status === 'changes') h += '<div class="pt-changes" role="status"><b>' + esc(tx('note_title')) + '</b><p>' + esc(c.note) + '</p></div>';
  var body = '';
  if (k === 'person' || k === 'owner') body = idFields();
  else if (k === 'vehicle' && c.kind === 'courier') body = '<div class="fld"><span>' + esc(tx('vehicle')) + '</span>' + vtiles('vehicle_type', COURIER_VEH, c.data.vehicle_type || 'motorcycle', vlabel) + miss('vehicle_type') + '</div>' +
    fld(tx('plate'), 'pt-plate_number', c.data.plate_number, 'maxlength="30"') + fld(tx('lic_opt'), 'pt-licence_number', c.data.licence_number, 'maxlength="60"') + photo('licence_photo_url', tx('lic_photo') + ' (' + (L() ? '\u12a0\u121b\u122b\u132d' : 'optional') + ')', 'environment');
  else if (k === 'vehicle') body = '<div class="fld"><span>' + esc(tx('vehicle')) + '</span>' + (Q.vehicles ? vtiles('vehicle_type', Q.vehicles, c.data.vehicle_type || '', vlabel) : '<div class="skel" style="height:52px"></div>') + miss('vehicle_type') + '</div>' +
    fld(tx('plate'), 'pt-vehicle_plate', c.data.vehicle_plate, 'maxlength="30"') + miss('vehicle_plate') + fld(tx('make'), 'pt-vehicle_make', c.data.vehicle_make, 'maxlength="60"') + fld(tx('color'), 'pt-vehicle_color', c.data.vehicle_color, 'maxlength="40"') + fld(tx('year'), 'pt-vehicle_year', c.data.vehicle_year || '', 'type="number" inputmode="numeric" min="1980" max="2100"');
  else if (k === 'licence') body = fld(tx('lic_name'), 'pt-license_name', c.data.license_name, 'maxlength="100"') + fld(tx('lic_num'), 'pt-license_number', c.data.license_number, 'maxlength="60"') + miss('license_number') + photo('license_photo_url', tx('lic_photo'), 'environment');
  else if (k === 'contact') body = contactFields();
  else if (k === 'shop') body = shopFields();
  else if (k === 'products') body = productsStep();
  else if (k === 'review') body = reviewStep();
  h += '<div id="pt-dups" aria-live="polite">' + dupHtml() + '</div><section class="card" style="margin-top:12px">' + body + '</section>';
  h += '<div class="pt-nav">' + (Q.step > 0 ? '<button class="btn" data-act="pt-prev">' + esc(tx('back')) + '</button>' : '<button class="btn ghost" data-act="pt-save">' + esc(tx('save')) + '</button>') +
    (last ? '<button class="btn primary" id="pt-send" data-act="pt-send"' + (Q.busy || Q.up || Q.dups.blocked ? ' disabled' : '') + '>' + ic('check') + esc(tx('send')) + '</button>' : '<button class="btn primary" data-act="pt-next"' + (Q.busy || Q.dups.blocked ? ' disabled' : '') + '>' + esc(tx('next')) + '</button>') + '</div>';
  if (last) h += '<div style="margin-top:10px"><button class="btn ghost" data-act="pt-save">' + esc(tx('save')) + '</button></div>';
  setTimeout(function () { paintDups(); queueCheck(150); }, 0);
  return P.shell(h);
}
function view() { return Q.cur ? wizard() : hub(); }

/* ---------------- actions ---------------- */
function payload() { var c = Q.cur; return { id: c.id || 0, kind: c.kind, name: c.name, phone: c.phone, id_number: c.id_number, data: c.data, items: c.items }; }
function save(then) {
  sync(); if (Q.busy) return; Q.busy = true;
  api('fa_partner_save', payload()).then(function (r) {
    Q.busy = false;
    if (!r.ok) { P.toast(reason(r)); P.render(); return; }
    var p = r.data.partner; Q.cur.id = p.id; Q.missing = r.data.missing || {}; Q.checked = true;
    if (then) then(r.data); else { P.toast(tx('saved')); P.render(); }
  });
}
function submit() {
  save(function (d) {
    if (!d.ready) { P.render(); P.toast(tx('rv_missing')); return; }
    Q.busy = true; P.render();
    api('fa_partner_submit', { id: Q.cur.id }).then(function (r) {
      Q.busy = false;
      if (!r.ok) { if (r.j && r.j.missing) Q.missing = r.j.missing; if (r.j && r.j.reason === 'DUPLICATE') Q.dups = { matches: r.j.matches || [], blocked: true, done: true }; P.toast(reason(r)); P.render(); return; }
      P.toast(tx('sent')); Q.cur = null; Q.list = null; load(); window.scrollTo(0, 0); P.render();
    });
  });
}
function pickFile(key, file) {
  if (!file || !/^image\//.test(file.type)) return;
  var isItem = key === 'item', type = UPTYPE[Q.cur.kind][isItem ? 'item' : key] || 'items';
  Q.prev[key] = URL.createObjectURL(file); Q.up++; sync(); P.render();
  var fd = new FormData(); fd.append('image', file); fd.append('type', type);
  fetch(new URL('../api/upload.php', location.href).pathname, { method: 'POST', headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('etel_token') || '') }, body: fd })
    .then(function (r) { return r.json(); })
    .then(function (j) { if (!j || !j.success || !j.data) throw new Error((j && j.message) || P.t('c.error')); if (isItem) { if (Q.item) Q.item.image_url = j.data.url; } else Q.cur.data[key] = j.data.url; })
    .catch(function (e) { delete Q.prev[key]; P.toast(e.message || P.t('c.error')); })
    .then(function () { Q.up--; P.render(); });
}
function locate() {
  if (!navigator.geolocation) { Q.geo = tx('loc_err'); P.render(); return; }
  sync(); Q.geo = tx('loc_wait'); P.render();
  navigator.geolocation.getCurrentPosition(function (p) {
    Q.cur.data.lat = Math.round(p.coords.latitude * 1e7) / 1e7; Q.cur.data.lng = Math.round(p.coords.longitude * 1e7) / 1e7; Q.geo = '';
    loadAreas(Q.cur.data.lat, Q.cur.data.lng); P.render();
  }, function () { Q.geo = tx('loc_err'); P.render(); }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 });
}
function act(a, el) {
  if (a === 'pt-reload') { Q.list = null; load(); P.render(); }
  else if (a === 'pt-new') { Q.cur = blank(el.getAttribute('data-k')); Q.step = 0; Q.missing = {}; Q.prev = {}; Q.item = null; Q.checked = false; startKind(); P.render(); }
  else if (a === 'pt-open') openDraft(el.getAttribute('data-id'));
  else if (a === 'pt-exit') {
    sync(); var c = Q.cur, leave = function () { Q.cur = null; Q.list = null; load(); P.render(); window.scrollTo(0, 0); };
    if (c && (c.name || c.phone) && !Q.busy) save(leave); else leave();
  }
  else if (a === 'pt-set') { sync(); var n = el.getAttribute('data-n'), v = el.getAttribute('data-v'); if (n.indexOf('i_') === 0 && Q.item) Q.item[n.slice(2)] = v; else Q.cur.data[n] = v; P.render(); }
  else if (a === 'pt-pick') { var inp = el.parentNode.querySelector('input[type=file]'); if (inp) inp.click(); }
  else if (a === 'pt-next') { save(function () { Q.step = Math.min(STEPS[Q.cur.kind].length - 1, Q.step + 1); if (stepKey() === 'review') Q.checked = true; window.scrollTo(0, 0); P.render(); }); }
  else if (a === 'pt-prev') { sync(); Q.step = Math.max(0, Q.step - 1); window.scrollTo(0, 0); P.render(); }
  else if (a === 'pt-save') save();
  else if (a === 'pt-send') submit();
  else if (a === 'pt-loc') locate();
  else if (a === 'pt-iadd') { sync(); Q.item = { name: '', name_am: '', price: '', category: 'food', prep_time: 15, description: '', image_url: '' }; delete Q.prev.item; P.render(); }
  else if (a === 'pt-icancel') { sync(); Q.item = null; delete Q.prev.item; P.render(); }
  else if (a === 'pt-iedit') { sync(); var i = parseInt(el.getAttribute('data-i'), 10); Q.item = Object.assign({ idx: i }, Q.cur.items[i]); delete Q.prev.item; P.render(); }
  else if (a === 'pt-idel') { sync(); Q.cur.items.splice(parseInt(el.getAttribute('data-i'), 10), 1); P.render(); }
  else if (a === 'pt-isave') {
    sync(); var it = Q.item, price = parseFloat(it.price);
    if (!String(it.name || '').trim() || !(price > 0)) { P.toast(tx('p_name') + ' + ' + tx('p_price')); return; }
    var rec = { name: String(it.name).trim(), name_am: it.name_am || '', description: it.description || '', category: it.category || 'food', price: price, image_url: it.image_url || '', prep_time: parseInt(it.prep_time, 10) || 15 };
    if (it.idx != null) Q.cur.items[it.idx] = rec; else Q.cur.items.push(rec);
    Q.item = null; delete Q.prev.item; P.render();
  }
}
document.addEventListener('change', function (e) {
  var f = e.target; if (P.S.view !== 'partners' || !f || !f.getAttribute || !f.getAttribute('data-file')) return;
  pickFile(f.getAttribute('data-file'), f.files && f.files[0]);
});
function open() { load(); }
window.ETELPartners = { view: view, act: act, open: open };
})();

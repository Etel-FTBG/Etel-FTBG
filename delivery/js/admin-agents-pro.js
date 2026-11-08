/* ETEL Admin - Agents (referral programme): core + dashboard.
   The "Etel Intelligent System Controller" for field agents. This file builds the page shell (tabs, English/Amharic switch),
   the Dashboard (KPI strip, live referral feed, charts, budget) and the small shared API that the other two files use:
     js/admin-agents-review.js  - Agents list + approval, Referrals, Fraud queue
     js/admin-agents-money.js   - Payouts, Reports, Settings, Audit log
   Built on AdminKit (same look as Pricing, Rides, Equb). Data actions: admin_fa_* in api/controllers/FieldAgent*Controller.php.
   Nothing here decides anything by itself: every approve / reject / release / pay is a button an admin presses, with a reason. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K || window.ETELAgents) return;
var esc = K.esc;
var LS = 'etel_agents_admin_lang';

/* ---------- English -> Amharic (labels only; anything not listed stays English). Please have a native speaker review. ---------- */
var AM = {
  'Dashboard': 'ዳሽቦርድ', 'Messages': 'መልእክቶች', 'Agents': 'ወኪሎች', 'Referrals': 'ሪፈራሎች', 'Fraud queue': 'የማጭበርበር ጥርጣሬ', 'Payouts': 'ክፍያዎች', 'Reports': 'ሪፖርቶች', 'Insights': 'ግንዛቤዎች', 'Agent booking': 'የወኪል ቦታ ማስያዝ', 'Settings': 'ቅንብሮች', 'Audit log': 'የኦዲት መዝገብ',
  'Agents control centre': 'የወኪሎች መቆጣጠሪያ ማዕከል', 'Etel Ride referral programme': 'የኢትኤል ራይድ የሪፈራል ፕሮግራም',
  'Active agents': 'ንቁ ወኪሎች', 'Waiting for approval': 'ማረጋገጫ የሚጠብቁ', 'Riders acquired (30 days)': 'የተገኙ ተሳፋሪዎች (30 ቀን)', '30-day repeat rate': 'የ30 ቀን ተደጋጋሚ መጠን',
  'Cost per rider': 'በአንድ ተሳፋሪ ወጪ', 'Ready to pay': 'ለክፍያ ዝግጁ', 'On hold': 'በመያዣ ላይ', 'Needs a decision': 'ውሳኔ የሚፈልጉ', 'High risk': 'ከፍተኛ ስጋት',
  'Live referral feed': 'የቀጥታ ሪፈራል ዝርዝር', 'Budget and projection': 'በጀት እና ትንበያ', 'Referrals and repeat riders': 'ሪፈራሎች እና ተደጋጋሚ ተሳፋሪዎች', 'New riders': 'አዲስ ተሳፋሪዎች', 'Repeat riders': 'ተደጋጋሚ ተሳፋሪዎች',
  'Approve': 'አጽድቅ', 'Reject': 'ውድቅ አድርግ', 'Suspend': 'አግድ', 'Reinstate': 'መልስ', 'Send reset message': 'የይለፍ ቃል መልእክት ላክ', 'Remind to verify profile': 'መገለጫ እንዲያረጋግጡ አስታውስ', 'Send': 'ላክ', 'Release': 'ፍቀድ', 'Keep on hold': 'በመያዣ አቆይ', 'Save changes': 'ለውጦችን አስቀምጥ', 'Cancel': 'ሰርዝ',
  'Export CSV': 'CSV አውጣ', 'Refresh': 'አድስ', 'Run now': 'አሁን አስኪድ', 'Create payout batch': 'የክፍያ ቡድን ፍጠር', 'Mark paid': 'እንደተከፈለ ምልክት አድርግ', 'Verify chain': 'ሰንሰለቱን አረጋግጥ', 'Search': 'ፈልግ',
  'All': 'ሁሉም', 'Pending': 'በመጠባበቅ ላይ', 'Approved': 'ጸድቋል', 'Suspended': 'ታግዷል', 'Rejected': 'ውድቅ ሆኗል', 'Held': 'በመያዣ', 'Payable': 'ሊከፈል የሚችል', 'Paid': 'ተከፍሏል', 'Clawed back': 'ተመላሽ ተደርጓል',
  'Agent': 'ወኪል', 'Venue': 'ቦታ', 'Status': 'ሁኔታ', 'Risk': 'ስጋት', 'Amount': 'መጠን', 'Rider': 'ተሳፋሪ', 'Reason': 'ምክንያት', 'Tier': 'ደረጃ', 'Code': 'ኮድ', 'Age': 'ዕድሜ', 'Time': 'ጊዜ', 'Type': 'ዓይነት',
  'Programme is OFF': 'ፕሮግራሙ ጠፍቷል', 'Programme is ON': 'ፕሮግራሙ በርቷል', 'Open settings': 'ቅንብሮችን ክፈት',
  'Pending agents': 'የሚጠብቁ ወኪሎች', 'Nothing waiting. Great.': 'የሚጠብቅ የለም።', 'Last earnings run': 'የመጨረሻው የገቢ ስሌት', 'Never run': 'አልተካሄደም',
  'Bonuses': 'ጉርሻዎች', 'Qualifying trips': 'ብቁ ጉዞዎች', 'Hold and clawback': 'መያዣ እና ተመላሽ', 'Caps and budget': 'ገደቦች እና በጀት', 'Risk scoring': 'የስጋት ውጤት', 'Programme': 'ፕሮግራም',
  'Reset section to defaults': 'ወደ ነባሪ መልስ', 'No changes to save': 'የሚቀመጥ ለውጥ የለም', 'Saved': 'ተቀምጧል',
  'Candidates for payout': 'ለክፍያ ብቁ የሆኑ', 'Payout batches': 'የክፍያ ቡድኖች', 'Export for Telebirr': 'ለቴሌብር አውጣ',
  'Language': 'ቋንቋ', 'English': 'English', 'Amharic': 'አማርኛ', 'Load more': 'ተጨማሪ አሳይ', 'Select all': 'ሁሉንም ምረጥ', 'selected': 'ተመርጠዋል',
  'Acquired': 'የተገኙ', 'Repeat rate': 'ተደጋጋሚ መጠን', 'Claims': 'ጥያቄዎች', 'Total cost': 'ጠቅላላ ወጪ', 'Per agent': 'በወኪል', 'By venue type': 'በቦታ ዓይነት'
};

var S = { lang: 'en', tab: '', caps: [], ready: false, cfg: null, stats: null, daily: null, feed: [], lastRun: null, pending: [], badges: {}, err: '' };
try { var sv = localStorage.getItem(LS); if (sv === 'am' || sv === 'en') S.lang = sv; } catch (e) { /* private window: default language */ }
var SCREENS = {}, ORDER = [];

function T(s) { return (S.lang === 'am' && AM[s]) ? AM[s] : s; }
function f(n) { return parseFloat(n) || 0; }
function etb(n) { return 'ETB ' + (Math.round(f(n) * 100) / 100).toLocaleString(undefined, { maximumFractionDigits: 2 }); }
function can(cap) { return S.caps.indexOf('*') !== -1 || S.caps.indexOf(cap) !== -1; }
function root() { return document.getElementById('panel-agents'); }
function run(action, body) { return K.api(action, body || {}); }
function ok(res) { return K.okRes(res); }
function dat(res) { return K.data(res); }
function when(s) { return s ? K.ago(s) : ''; }

/* ---------- shared look ---------- */
function css() {
  K.css();
  if (document.getElementById('ag-css')) return;
  var s = document.createElement('style'); s.id = 'ag-css';
  s.textContent = [
    '.ag-top{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin:2px 0 12px}',
    '.ag-lang,.ag-theme{min-height:40px}',
    '.ag-banner{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;border:1.5px solid var(--amber);background:var(--soft);border-radius:16px;padding:12px 16px;margin-bottom:12px;color:var(--ink)}',
    '.ag-banner.on{border-color:var(--green)}',
    '.ag-banner b{display:block}.ag-banner span{font-size:12.5px;color:var(--mute)}',
    '.ag-grid2{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);gap:14px;margin-bottom:14px}@media(max-width:960px){.ag-grid2{grid-template-columns:1fr}}',
    '.ag-grid3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}@media(max-width:960px){.ag-grid3{grid-template-columns:1fr}}',
    '.ag-box{background:var(--card);border:1.5px solid var(--line);border-radius:18px;padding:16px;box-shadow:var(--shadow);margin-bottom:14px;min-width:0}',
    '.ag-grid2 .ag-box,.ag-grid3 .ag-box{margin-bottom:0}',
    '.ag-box h3{margin:0 0 2px;font-size:14.5px;color:var(--ink)}.ag-box .sub{font-size:12px;color:var(--mute);margin-bottom:10px}',
    '.ag-feed{list-style:none;margin:0;padding:0}.ag-feed li{display:flex;gap:10px;padding:9px 0;border-top:1px solid var(--line);font-size:13px;color:var(--ink);align-items:flex-start}.ag-feed li:first-child{border:0}',
    '.ag-feed time{color:var(--mute);font-size:11.5px;min-width:62px;flex:none}.ag-feed i{font-style:normal;flex:none}',
    '.ag-risk{display:inline-flex;gap:8px;align-items:center;min-width:104px}.ag-risk b{min-width:24px;text-align:right;font-size:13px;color:var(--ink)}',
    '.ag-risk i{flex:1;height:7px;border-radius:4px;background:var(--line);overflow:hidden;display:block;min-width:50px}.ag-risk u{display:block;height:100%;background:var(--green)}',
    '.ag-risk.mid u{background:#c98a00}.ag-risk.hi u{background:var(--red)}',
    '.ag-pill{display:inline-block;font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:99px;white-space:nowrap;background:var(--soft);color:var(--ink);border:1px solid var(--line)}',
    '.ag-pill.green{color:var(--green);border-color:var(--green)}.ag-pill.red{color:var(--red);border-color:var(--red)}.ag-pill.amber{color:var(--amber);border-color:var(--amber)}.ag-pill.blue{color:var(--blue);border-color:var(--blue)}',
    '.ag-why{list-style:none;margin:0;padding:0}.ag-why li{display:flex;gap:10px;padding:8px 0;border-top:1px solid var(--line);font-size:13px;color:var(--ink)}.ag-why li:first-child{border:0}',
    '.ag-why b{flex:none;min-width:42px;color:var(--red)}',
    '.ag-bar{height:12px;border-radius:6px;background:var(--line);position:relative;overflow:hidden;margin:8px 0}.ag-bar i{position:absolute;left:0;top:0;bottom:0;background:linear-gradient(90deg,var(--gold2),var(--gold));display:block}',
    '.ag-bar u{position:absolute;top:0;bottom:0;width:2px;background:var(--ink);opacity:.7}',
    '.ag-chart{width:100%;height:auto;display:block}.ag-leg{display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:var(--mute);margin-top:6px}.ag-leg i{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:5px;vertical-align:-1px}',
    '.ag-bulk{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:10px 12px;border:1.5px solid var(--gold);border-radius:14px;background:var(--soft);margin-bottom:10px;font-size:13px;color:var(--ink)}',
    '.ag-tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 12px}',
    '.ag-num{text-align:right;font-variant-numeric:tabular-nums}',
    '.ag-set{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px 12px}',
    '.ag-set .ak-fl.chg .ak-in{border-color:var(--gold);box-shadow:0 0 0 2px rgba(212,175,55,.25)}',
    '.ag-mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}',
    '.ag-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}',
    '.ag-bulk label,.ag-set .ak-sw,.ag-set .ak-sw span,.ak-sw span{text-transform:none!important;letter-spacing:0!important;font-family:inherit!important;font-size:13px!important;font-weight:600!important;line-height:1.4!important}',
    '.ag-tools .ak-seg{flex-wrap:wrap}',
    '.ag-bulk label{font-weight:600}',
    '.ag-set .ak-sw{align-items:flex-start;gap:10px}',
    '.ak-tbl td.ag-why-td{white-space:normal;min-width:220px;max-width:320px;overflow-wrap:anywhere;font-size:12.5px;line-height:1.45}',
    '.ak-tbl td.ag-wrap{white-space:normal;overflow-wrap:anywhere}',
    '.ak-tbl td.ag-nowrap,.ak-tbl th{white-space:nowrap}',
    '.ag-sec{padding:0;overflow:hidden}',
    '.ag-sh{display:grid;grid-template-columns:40px minmax(0,1fr) auto;gap:4px 12px;align-items:center;padding:16px 18px;border-bottom:1px solid var(--line);background:linear-gradient(180deg,var(--soft),transparent)}',
    '.ag-si{flex:none;width:40px;height:40px;border-radius:13px;display:grid;place-items:center;font-size:19px;background:linear-gradient(135deg,var(--gold2),var(--gold));box-shadow:0 4px 12px rgba(212,175,55,.28)}',
    '.ag-st{flex:1;min-width:0}.ag-st h3{margin:0;font-size:15.5px;color:var(--ink)}.ag-st p{margin:2px 0 0;font-size:12.5px;color:var(--mute);line-height:1.4}',
    '.ag-ghost{white-space:nowrap;flex:none;background:none;border:0;color:var(--mute);font:inherit;font-size:12px;font-weight:700;padding:8px 10px;border-radius:10px;cursor:pointer;text-decoration:underline;text-underline-offset:3px}.ag-ghost:hover{color:var(--ink);background:var(--soft)}',
    '.ag-row{display:flex;gap:14px;align-items:center;justify-content:space-between;padding:14px 18px;border-top:1px solid var(--line);cursor:pointer;transition:background .15s}',
    '.ag-rows .ag-row:first-child{border-top:0}.ag-row:hover{background:var(--soft)}',
    '.ag-row.chg{background:rgba(212,175,55,.10);box-shadow:inset 3px 0 0 var(--gold)}',
    '.ag-rt{min-width:0;flex:1}.ag-rt b{display:block;font-size:14px;font-weight:650;color:var(--ink);line-height:1.35;text-transform:none;letter-spacing:0;font-family:inherit}',
    '.ag-rt small{display:block;margin-top:3px;font-size:12px;color:var(--mute);font-weight:400;line-height:1.4;text-transform:none;letter-spacing:0;font-family:inherit}',
    '.ag-num-in{flex:none;display:flex;align-items:center;gap:8px}',
    '.ag-num-in .ak-in{width:108px;text-align:right;font-size:16px;font-weight:700;padding:10px 12px;font-variant-numeric:tabular-nums}',
    '.ag-num-in .ak-in:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(212,175,55,.25);outline:0}',
    '.ag-num-in em{font-style:normal;font-size:12px;font-weight:700;color:var(--mute);min-width:38px}',
    '.ag-sw2{flex:none;position:relative;width:52px;height:30px}.ag-sw2 input{position:absolute;inset:0;width:100%;height:100%;opacity:0;margin:0;cursor:pointer;z-index:2}',
    '.ag-sw2 i{position:absolute;inset:0;border-radius:99px;background:var(--line);transition:background .2s}',
    '.ag-sw2 i:after{content:"";position:absolute;top:3px;left:3px;width:24px;height:24px;border-radius:50%;background:#fff;box-shadow:0 2px 6px rgba(0,0,0,.3);transition:transform .2s}',
    '.ag-sw2 input:checked+i{background:linear-gradient(135deg,var(--gold2),var(--gold))}.ag-sw2 input:checked+i:after{transform:translateX(22px)}',
    '.ag-sw2 input:focus-visible+i{outline:3px solid var(--focus);outline-offset:2px}.ag-sw2 input:disabled+i{opacity:.5}',
    '.ag-bk{border:1.5px solid var(--line);border-radius:14px;padding:12px 14px;margin-top:10px;background:var(--card)}',
    '.ag-bk-top{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.ag-bk-top small{margin-left:auto;color:var(--mute);font-size:12px}',
    '.ag-bk-sub{margin-top:4px;font-size:12.5px;color:var(--mute);text-transform:capitalize}',
    '.ag-bk-acts{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.ag-bk-acts .ak-btn{flex:1 1 150px;white-space:normal;text-align:center;line-height:1.3}',
    '.ag-note{margin:0;padding:14px 18px;border-top:1px solid var(--line);font-size:12.5px;color:var(--mute);line-height:1.5}',
    '.ag-savebar{position:sticky;bottom:10px;display:flex;gap:12px;justify-content:flex-end;align-items:center;padding:10px 12px;border:1.5px solid var(--line);border-radius:16px;background:var(--card);box-shadow:var(--shadow);z-index:5}',
    '.ag-chg-n{font-size:12.5px;font-weight:700;color:var(--ink);flex:1;padding-left:6px}',
    '.ag-row,.ag-row *{font-family:Outfit,"Noto Sans Ethiopic",system-ui,sans-serif!important;text-transform:none!important;letter-spacing:0!important}',
    '@media(max-width:520px){.ag-row{padding:14px;flex-wrap:wrap}.ag-row .ag-num-in{width:100%}.ag-row .ag-num-in .ak-in{flex:1 1 0;min-width:0;width:auto;text-align:left}.ag-sh{padding:14px;grid-template-columns:40px minmax(0,1fr)}.ag-ghost{grid-column:2;justify-self:start;padding-left:0}.ag-row.ag-tgl{flex-wrap:nowrap}}',
    '@media(prefers-reduced-motion:reduce){.ag-box,.ak-drawer,.ag-row,.ag-sw2 i,.ag-sw2 i:after{transition:none!important;animation:none!important}}'
  ].join('');
  document.head.appendChild(s);
}

/* ---------- small renderers other files share ---------- */
function riskBar(score) {
  var n = Math.max(0, Math.min(100, parseInt(score, 10) || 0)), hold = S.cfg ? f(S.cfg.risk_hold_threshold) : 60, hi = S.cfg ? f(S.cfg.risk_high_threshold) : 80;
  var cls = n >= hi ? 'hi' : (n >= hold ? 'mid' : '');
  return '<span class="ag-risk ' + cls + '" role="img" aria-label="' + esc(T('Risk') + ' ' + n + '/100') + '"><b>' + n + '</b><i><u style="width:' + n + '%"></u></i></span>';
}
var STATUS_TONE = { pending: 'amber', approved: 'green', suspended: 'red', rejected: 'red', held: 'amber', payable: 'blue', paid: 'green', clawed_back: 'red', draft: '', exported: 'blue', cancelled: 'red', failed: 'red', claimed: '', qualified: 'green', expired: '' };
var STATUS_LABEL = { pending: 'Pending', approved: 'Approved', suspended: 'Suspended', rejected: 'Rejected', held: 'Held', payable: 'Payable', paid: 'Paid', clawed_back: 'Clawed back' };
function statusPill(st) {
  var label = STATUS_LABEL[st] ? T(STATUS_LABEL[st]) : String(st).replace(/_/g, ' ');
  return '<span class="ag-pill ' + (STATUS_TONE[st] || '') + '">' + esc(label) + '</span>';
}
function reasonList(reasons) {
  if (!reasons || !reasons.length) return '<p style="font-size:13px;color:var(--mute);margin:0">No risk signals on this one.</p>';
  return '<ul class="ag-why">' + reasons.map(function (r) { return '<li><b>+' + esc(r.points) + '</b><span>' + esc(r.text || r.code) + '</span></li>'; }).join('') + '</ul>';
}
function csvCell(v) {
  v = String(v == null ? '' : v);
  if (v !== '' && '=+-@'.indexOf(v.charAt(0)) !== -1) v = "'" + v;     // spreadsheet formula injection
  return '"' + v.replace(/"/g, '""') + '"';
}
function download(name, text, type) {
  try {
    var blob = new Blob(['﻿' + text], { type: type || 'text/csv;charset=utf-8' });
    var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name;
    document.body.appendChild(a); a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); if (a.parentNode) a.parentNode.removeChild(a); }, 500);
  } catch (e) { K.toast('Could not create the file on this browser.', true); }
}
function exportRows(name, head, rows) {
  download(name, [head.map(csvCell).join(',')].concat(rows.map(function (r) { return r.map(csvCell).join(','); })).join('\r\n') + '\r\n');
}
/* Run an async action while a button is disabled, so a double click cannot send it twice. */
function busy(btn, fn) {
  if (btn && btn.disabled) return Promise.resolve();
  if (btn) btn.disabled = true;
  return Promise.resolve().then(fn).then(function (v) { if (btn) btn.disabled = false; return v; }, function (e) { if (btn) btn.disabled = false; throw e; });
}

/* ---------- charts (plain SVG, no library) ---------- */
function lineChart(rows, lines, label) {
  var W = 640, H = 200, L = 34, R = 10, Tp = 12, B = 24, n = rows.length;
  if (!n) return '';
  var max = 4; rows.forEach(function (r) { lines.forEach(function (l) { max = Math.max(max, f(r[l.key])); }); });
  max = Math.ceil(max / 4) * 4;
  var X = function (i) { return L + (n === 1 ? 0 : i * (W - L - R) / (n - 1)); }, Y = function (v) { return Tp + (H - Tp - B) * (1 - v / max); };
  var g = '', i, s = '';
  for (i = 0; i <= 4; i++) { var yy = Y(max * i / 4); g += '<line x1="' + L + '" x2="' + (W - R) + '" y1="' + yy + '" y2="' + yy + '" stroke="currentColor" opacity=".13"/><text x="' + (L - 6) + '" y="' + (yy + 4) + '" text-anchor="end" font-size="10" fill="currentColor" opacity=".7">' + Math.round(max * i / 4) + '</text>'; }
  [0, Math.floor((n - 1) / 2), n - 1].forEach(function (k) { if (rows[k]) g += '<text x="' + X(k) + '" y="' + (H - 6) + '" text-anchor="' + (k === 0 ? 'start' : (k === n - 1 ? 'end' : 'middle')) + '" font-size="10" fill="currentColor" opacity=".7">' + esc(String(rows[k].d).slice(5)) + '</text>'; });
  lines.forEach(function (l) {
    var pts = rows.map(function (r, k) { return (k ? 'L' : 'M') + X(k).toFixed(1) + ' ' + Y(f(r[l.key])).toFixed(1); }).join(' ');
    s += '<path d="' + pts + '" fill="none" stroke="' + l.color + '" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"' + (l.dash ? ' stroke-dasharray="6 4"' : '') + '/>';
  });
  var tot = lines.map(function (l) { return l.name + ': ' + rows.reduce(function (a, r) { return a + f(r[l.key]); }, 0); }).join(', ');
  return '<div style="color:var(--ink)"><svg class="ag-chart" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + esc(label + '. ' + tot) + '">' + g + s + '</svg></div>' +
    '<div class="ag-leg">' + lines.map(function (l) { return '<span><i style="background:' + l.color + '"></i>' + esc(l.name) + '</span>'; }).join('') + '</div>';
}
function barRows(items, valueKey, labelKey, fmt) {
  var max = 1; items.forEach(function (x) { max = Math.max(max, f(x[valueKey])); });
  return '<div>' + items.map(function (x) {
    var p = Math.round(f(x[valueKey]) / max * 100);
    return '<div style="display:grid;grid-template-columns:minmax(80px,1fr) 2fr auto;gap:10px;align-items:center;padding:5px 0;font-size:13px;color:var(--ink)"><span style="overflow-wrap:anywhere">' + esc(x[labelKey]) + '</span>' +
      '<span class="ag-bar" style="margin:0"><i style="width:' + p + '%"></i></span><b class="ag-num">' + esc(fmt ? fmt(x[valueKey]) : x[valueKey]) + '</b></div>';
  }).join('') + '</div>';
}

/* ---------- feed ---------- */
var FEED = { agent_roles_set: ['🎛️', 'Roles changed'], partner_approved: ['✅', 'Partner approved'], partner_rejected: ['✖️', 'Partner rejected'], partner_changes: ['↩️', 'Changes asked for a partner'], partner_submitted: ['📨', 'Partner sent for review'], partner_sms_resent: ['✉️', 'Sign-in text sent again'],
  agent_applied: ['🆕', 'New agent application'], tier_changed: ['🏅', 'An agent moved to a new tier'], agent_booking_created: ['🚕', 'An agent booked a ride for a customer'], nudge_sent: ['💬', 'A reminder was sent to an agent'], digest_created: ['🗞️', 'Weekly digest created'], tiers_recomputed: ['🏅', 'Tiers recalculated by an admin'], agent_approved: ['✅', 'Agent approved'], agent_rejected: ['⛔', 'Agent rejected'], agent_suspended: ['⏸️', 'Agent suspended'], agent_reinstated: ['▶️', 'Agent reinstated'],
  age_manually_verified: ['🪪', 'Age checked by an admin'], referral_claimed: ['📲', 'A rider used an agent code'], claim_blocked_self: ['🚫', 'A self-referral attempt was blocked'],
  earning_pending: ['⏳', 'Bonus earned, on hold'], earning_held: ['🛑', 'Bonus held for review'], earning_rejected: ['❌', 'Referral rejected'], earning_payable: ['💰', 'Bonus ready to pay'],
  earning_release: ['✅', 'Held bonus released by an admin'], earning_reject: ['❌', 'Bonus rejected by an admin'], earning_clawed_back: ['↩️', 'Bonus reversed (trip cancelled or refunded)'], clawback_created: ['↩️', 'Adjustment for a paid bonus'],
  referral_rejected: ['❌', 'Referral rejected'], referral_expired: ['⌛', 'Referral expired (no qualifying trip)'], payout_batch_created: ['🧾', 'Payout batch created'], payout_batch_exported: ['📤', 'Payout batch exported'],
  payout_batch_paid: ['💸', 'Payout batch marked paid'], payout_batch_cancelled: ['🗑️', 'Payout batch cancelled'], setting_changed: ['⚙️', 'A setting was changed'], earnings_job_run: ['🔄', 'Earnings calculated'], agent_profile_updated: ['✏️', 'Agent updated their profile']
};
function feedText(e) {
  var m = FEED[e.action] || ['•', String(e.action).replace(/_/g, ' ')], d = {};
  try { d = JSON.parse(e.detail || '{}') || {}; } catch (x) { d = {}; }
  var extra = '';
  if (d.amount != null && d.amount !== '') extra = ' - ' + etb(d.amount);
  if (e.action === 'setting_changed' && d.key) extra = ': ' + d.key + ' ' + d.old + ' -> ' + d.new;
  if (e.action === 'earnings_job_run') extra = ': ' + f(d.qualified) + ' new, ' + f(d.repeat) + ' repeat, ' + f(d.payable) + ' now payable';
  if (e.reason && e.action.indexOf('earning_') === 0 && e.reason !== 'RISK_HOLD' && e.reason.indexOf('CAP_') !== 0 && e.reason !== 'PAID') extra += ' (' + e.reason + ')';
  return { icon: m[0], text: T(m[1]) + extra };
}
function feedHtml() {
  if (!S.feed.length) return K.empty('📭', 'No activity yet', 'Referrals, approvals and payments show here as they happen.');
  return '<ul class="ag-feed" aria-live="polite">' + S.feed.map(function (e) { var x = feedText(e); return '<li><i aria-hidden="true">' + x.icon + '</i><span style="flex:1;overflow-wrap:anywhere">' + esc(x.text) + '</span><time datetime="' + esc(String(e.created_at).replace(' ', 'T')) + '">' + esc(when(e.created_at)) + '</time></li>'; }).join('') + '</ul>';
}

/* ---------- dashboard ---------- */
function budgetHtml(b) {
  if (!b || !(b.budget > 0)) return '<p style="font-size:13px;color:var(--mute);margin:0">No monthly budget is set. Month so far: <b style="color:var(--ink)">' + etb(b ? b.spent : 0) + '</b>. Projected to month end: <b style="color:var(--ink)">' + etb(b ? b.projected : 0) + '</b>. Set a budget in Settings to get an alert before the cap.</p>';
  var alertAt = S.cfg ? f(S.cfg.budget_alert_pct) : 80, pct = Math.min(100, b.pct_used);
  return '<div class="ag-bar" role="img" aria-label="' + esc(b.pct_used + '% of the monthly budget used') + '"><i style="width:' + pct + '%"></i><u style="left:' + alertAt + '%"></u></div>' +
    '<p style="font-size:13px;color:var(--ink);margin:0">Spent or owed <b>' + etb(b.spent) + '</b> of <b>' + etb(b.budget) + '</b> (' + b.pct_used + '%). Projected to month end: <b>' + etb(b.projected) + '</b> (' + b.projected_pct + '%).</p>' +
    (b.alert ? '<p style="margin:8px 0 0">' + '<span class="ag-pill red">Budget alert</span> <span style="font-size:12.5px;color:var(--mute)">Spend has passed ' + alertAt + '% or is projected to pass the budget.</span></p>' : '');
}
function dashboard() {
  var st = S.stats;
  if (!st) return '<div class="ak">' + K.skeleton(6) + '</div>';
  var b = st.budget;
  var h = '<div class="ag-banner ' + (st.programme_enabled ? 'on' : '') + '"><div><b>' + T(st.programme_enabled ? 'Programme is ON' : 'Programme is OFF') + '</b><span>' +
    (st.programme_enabled ? 'Agents can earn bonuses. Nothing is paid until you create a payout batch.' : 'Nothing is earned while the programme is off. Switch it on in Settings when you are ready.') + '</span></div>' +
    (can('agents.manage') ? '<button type="button" class="ak-btn gold" data-ag-tab="settings">' + esc(T('Open settings')) + '</button>' : '') + '</div>';
  h += K.hero(T('Agents control centre'), T('Etel Ride referral programme'), [[T('Ready to pay'), etb(st.owed_payable)], [T('Cost per rider'), etb(st.cost_per_rider)], [T('Riders acquired (30 days)'), K.num(st.riders_acquired_30d)]]);
  h += K.kpis([
    { key: 'agents', icon: '🤝', label: T('Active agents'), value: st.agents_active },
    { key: 'agents:pending', icon: '🕓', label: T('Waiting for approval'), value: st.agents_pending, warn: true },
    { key: 'dash', icon: '🔁', label: T('30-day repeat rate'), value: st.repeat_rate_30d + '%' },
    { key: 'payouts', icon: '💰', label: T('Ready to pay'), value: etb(st.owed_payable) },
    { key: 'dash', icon: '⏳', label: T('On hold'), value: etb(st.in_hold) },
    { key: 'fraud', icon: '🛑', label: T('Needs a decision'), value: st.flagged, warn: true },
    { key: 'fraud', icon: '⚠️', label: T('High risk'), value: st.high_risk, warn: true }
  ], '', false);
  h += '<div class="ag-grid2"><section class="ag-box"><h3>' + esc(T('Referrals and repeat riders')) + '</h3><div class="sub">Last 30 days</div>' +
    (S.daily ? lineChart(S.daily, [{ key: 'new_riders', name: T('New riders'), color: '#B8860B' }, { key: 'repeat', name: T('Repeat riders'), color: '#2e9e6a', dash: true }], 'Daily new and repeat riders') : '<div class="ak-skel"></div>') + '</section>' +
    '<section class="ag-box"><h3>' + esc(T('Live referral feed')) + '</h3><div class="sub">Updates every 20 seconds while this page is open</div><div id="ag-feed">' + feedHtml() + '</div></section></div>';
  h += '<div class="ag-grid3"><section class="ag-box"><h3>' + esc(T('Budget and projection')) + '</h3>' + budgetHtml(b) + '</section>' +
    '<section class="ag-box"><h3>' + esc(T('Pending agents')) + '</h3><div class="sub">' + esc(T('Waiting for approval')) + '</div>' + pendingHtml() + '</section>' +
    '<section class="ag-box"><h3>' + esc(T('Last earnings run')) + '</h3><div class="sub">Calculates bonuses from completed trips. Safe to run any time.</div>' +
    '<p style="font-size:13px;color:var(--ink);margin:0 0 10px">' + (S.lastRun ? esc(when(S.lastRun.created_at)) : esc(T('Never run'))) + '</p>' +
    (can('agents.manage') ? '<button type="button" class="ak-btn gold" data-ag-run>' + esc(T('Run now')) + '</button>' : '') + '</section></div>';
  return h;
}
function pendingHtml() {
  if (!S.pending.length) return '<p style="font-size:13px;color:var(--mute);margin:0">' + esc(T('Nothing waiting. Great.')) + '</p>';
  return '<ul class="ag-feed">' + S.pending.map(function (a) { return '<li><span style="flex:1;overflow-wrap:anywhere"><b>' + esc(a.full_name) + '</b><br><small style="color:var(--mute)">' + esc(a.venue_name) + '</small></span><button type="button" class="ak-btn" data-ag-open-agent="' + esc(a.id) + '">' + esc(T('Approve')) + '...</button></li>'; }).join('') + '</ul>';
}
function loadDashboard() {
  return Promise.all([run('admin_fa_stats'), run('admin_fa_reports', { days: 30 }), run('admin_fa_audit_list', { limit: 12 }), run('admin_fa_audit_list', { action_name: 'earnings_job_run', limit: 1 }), run('admin_fa_agents_list', { status: 'pending', limit: 5 })]).then(function (r) {
    if (!ok(r[0])) { S.err = K.failText(r[0], 'Could not load the dashboard.'); S.stats = null; return; }
    S.err = ''; S.stats = dat(r[0]);
    S.daily = ok(r[1]) ? dat(r[1]).daily : null;
    S.feed = ok(r[2]) ? dat(r[2]).entries || [] : [];
    S.lastRun = ok(r[3]) && (dat(r[3]).entries || []).length ? dat(r[3]).entries[0] : null;
    S.pending = ok(r[4]) ? dat(r[4]).agents || [] : [];
    S.badges = { agents: S.stats.agents_pending, fraud: S.stats.flagged, partners: S.stats.partners_waiting };
  });
}
var pollTimer = null;
function pollFeed() {
  if (S.tab !== 'dashboard' || document.hidden || !root() || root().style.display === 'none') return;
  run('admin_fa_audit_list', { limit: 12 }).then(function (r) { if (!ok(r)) return; S.feed = dat(r).entries || []; var el = document.getElementById('ag-feed'); if (el) el.innerHTML = feedHtml(); });
}
function startPoll() { stopPoll(); pollTimer = setInterval(pollFeed, 20000); }
function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

/* ---------- shell ---------- */
function register(key, def) { SCREENS[key] = def; if (ORDER.indexOf(key) === -1) ORDER.push(key); }
var TAB_ORDER = ['dashboard', 'agents', 'referrals', 'fraud', 'payouts', 'reports', 'insights', 'partners', 'messages', 'settings', 'audit'];
var TAB_LABEL = { dashboard: 'Dashboard', agents: 'Agents', referrals: 'Referrals', fraud: 'Fraud queue', payouts: 'Payouts', reports: 'Reports', insights: 'Insights', partners: 'Partners', messages: 'Messages', settings: 'Settings', audit: 'Audit log' };
var TAB_ICON = { dashboard: '📊', agents: '🤝', referrals: '📲', fraud: '🛑', payouts: '💸', reports: '📈', insights: '💡', partners: '🧩', messages: '✉️', settings: '⚙️', audit: '🧾' };
var TAB_CAP = { dashboard: 'agents.view', agents: 'agents.view', referrals: 'agents.view', fraud: 'agents.view', payouts: 'agents.view', reports: 'agents.view', insights: 'agents.view', partners: 'agents.view', messages: 'agents.view', settings: 'agents.view', audit: 'agents.view' };

function tabs() {
  var list = TAB_ORDER.filter(function (k) { return k === 'dashboard' || SCREENS[k]; });
  return '<div class="ak-seg" role="tablist" aria-label="Agents sections" style="flex-wrap:wrap">' + list.map(function (k) {
    var b = S.badges[k], on = S.tab === k;
    return '<button type="button" role="tab" aria-selected="' + on + '" class="' + (on ? 'on' : '') + '" data-ag-tab="' + k + '">' + TAB_ICON[k] + ' ' + esc(T(TAB_LABEL[k])) + (b ? ' <span class="ag-pill amber" style="margin-left:4px;padding:1px 7px">' + esc(b) + '</span>' : '') + '</button>';
  }).join('') + '</div>';
}
/* Light / dark switch. Uses the same 'theme' key and body.dark-theme class as every other Etel page, so the choice follows the admin everywhere. */
function isDark() { return document.body.classList.contains('dark-theme'); }
function themeSwitch() {
  var d = isDark(), lbl = (S.lang === 'am' ? (d ? 'ጨለማ' : 'ብሩህ') : (d ? 'Dark' : 'Light'));
  return '<button type="button" class="ak-btn ag-theme" role="switch" aria-checked="' + d + '" data-ag-theme aria-label="' + esc(S.lang === 'am' ? 'ገጽታ' : 'Theme') + ': ' + esc(lbl) + '">' + (d ? '🌙' : '☀️') + ' ' + esc(lbl) + '</button>';
}
function toggleTheme() {
  var fab = document.getElementById('dark-theme-toggle');
  if (fab) fab.click();
  else { var nd = !isDark(); document.body.classList.toggle('dark-theme', nd); try { localStorage.setItem('theme', nd ? 'dark' : 'light'); } catch (e) { /* not remembered */ } }
  var b = document.querySelector('[data-ag-theme]'); if (b) { var tmp = document.createElement('div'); tmp.innerHTML = themeSwitch(); b.parentNode.replaceChild(tmp.firstChild, b); }
}
function paint() {
  var el = root(); if (!el) return;
  css();
  var h = '<div class="ak"><div class="ag-top">' + tabs() + '<span style="display:flex;gap:8px;flex-wrap:wrap"><button type="button" class="ak-btn ag-lang" data-ag-lang aria-label="' + esc(T('Language')) + '">🌐 ' + esc(S.lang === 'am' ? 'English' : 'አማርኛ') + '</button>' + themeSwitch() + '</span></div><div id="ag-screen"></div></div>';
  el.innerHTML = h; paintScreen();
}
function paintScreen() {
  var box = document.getElementById('ag-screen'); if (!box) return;
  var sc = SCREENS[S.tab];
  if (S.tab === 'dashboard') box.innerHTML = S.err ? '<div class="ak-empty"><b>⚠️</b><div>' + esc(S.err) + '</div></div>' : dashboard();
  else if (sc) box.innerHTML = sc.render();
  if (sc && sc.after) sc.after(box);
}
function openTab(key, arg) {
  if (key !== 'dashboard' && !SCREENS[key]) key = 'dashboard';
  S.tab = key; paint();
  var sc = SCREENS[key];
  if (key === 'dashboard') { return loadDashboard().then(function () { paintScreen(); paintTabsOnly(); }); }
  if (sc && sc.open) return Promise.resolve(sc.open(arg));
}
function paintTabsOnly() { var el = root(); if (!el) return; var t = el.querySelector('[role=tablist]'); if (t) { var tmp = document.createElement('div'); tmp.innerHTML = tabs(); t.parentNode.replaceChild(tmp.firstChild, t); } }

function onClick(e) {
  var t = e.target, b;
  if ((b = t.closest('[data-ag-tab]'))) { var k = b.getAttribute('data-ag-tab'); openTab(k); return; }
  if ((b = t.closest('[data-kpi]'))) {
    var key = b.getAttribute('data-kpi').split(':'); if (key[0] === 'dash') return;
    openTab(key[0], key[1]); return;
  }
  if (t.closest('[data-ag-theme]')) { toggleTheme(); return; }
  if (t.closest('[data-ag-lang]')) { S.lang = S.lang === 'am' ? 'en' : 'am'; try { localStorage.setItem(LS, S.lang); } catch (x) { /* not remembered */ } paint(); var s2 = SCREENS[S.tab]; if (S.tab === 'dashboard') paintScreen(); else if (s2 && s2.open) s2.open(); return; }
  if ((b = t.closest('[data-ag-run]'))) {
    busy(b, function () { return run('admin_fa_run_earnings').then(function (r) {
      if (!ok(r)) { K.toast(K.failText(r, 'Could not run.'), true); return; }
      var x = dat(r).result; K.toast(x.enabled ? ('Done: ' + x.qualified + ' new, ' + x.repeat + ' repeat, ' + x.payable + ' payable, ' + x.held + ' held' + (x.errors ? ', ' + x.errors + ' errors' : '')) : (x.skipped || 'Programme is off'), !!(x && x.errors));
      return loadDashboard().then(function () { paintScreen(); paintTabsOnly(); });
    }); }); return;
  }
  if ((b = t.closest('[data-ag-open-agent]'))) { openTab('agents', 'open:' + b.getAttribute('data-ag-open-agent')); return; }
}

function load() {
  var el = root(); if (!el) return;
  css();
  if (!el._ag) { el._ag = true; el.addEventListener('click', onClick); }
  el.innerHTML = '<div class="ak">' + K.skeleton(4) + '</div>';
  var caps = window.ETEL_CAPS ? Promise.resolve(window.ETEL_CAPS) : (window.ETEL_CAPS_PROMISE || Promise.resolve([]));
  caps.then(function (c) {
    S.caps = c || [];
    if (!can('agents.view')) { el.innerHTML = '<div class="ak">' + K.empty('🔒', 'You do not have access to Agents', 'Ask the owner to give your role the "agents.view" permission.') + '</div>'; return; }
    return run('admin_fa_settings_get').then(function (r) { if (ok(r)) S.cfg = dat(r).settings; }).then(function () { S.ready = true; return openTab(S.tab || 'dashboard'); });
  });
  startPoll();
}

/* Open when the sidebar item is clicked. switchPage() is a plain global in admin-ui.js, so wrap it instead of editing that file. */
function hook() {
  if (window.__agHooked || typeof window.switchPage !== 'function') return;
  window.__agHooked = true;
  var orig = window.switchPage;
  window.switchPage = function (page, elm) {
    var r = orig.apply(this, arguments);
    if (page === 'agents') {
      var ttl = document.getElementById('page-title'); if (ttl) ttl.textContent = T('Agents');
      var ab = document.getElementById('action-buttons'); if (ab) ab.innerHTML = '<button class="btn btn-primary" onclick="ETELAgents.refresh()">🔄 ' + esc(T('Refresh')) + '</button>';
      load();
    } else { stopPoll(); }
    return r;
  };
}
function install() {
  K.ensurePanel('agents', 'agents', '🤝', 'Agents', 'rides');
  hook();
  window.ETELAgents = {
    K: K, S: S, T: T, esc: esc, etb: etb, f: f, can: can, run: run, ok: ok, dat: dat, when: when, riskBar: riskBar, statusPill: statusPill, reasonList: reasonList, STATUS_LABEL: STATUS_LABEL,
    exportRows: exportRows, download: download, busy: busy, lineChart: lineChart, barRows: barRows, feedText: feedText, register: register, paintScreen: paintScreen, paintTabsOnly: paintTabsOnly, openTab: openTab, css: css,
    refresh: function () { var sc = SCREENS[S.tab]; if (S.tab === 'dashboard') return loadDashboard().then(function () { paintScreen(); paintTabsOnly(); }); if (sc && sc.open) return sc.open(); }
  };
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();

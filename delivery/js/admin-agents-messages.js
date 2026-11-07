/* ETEL Admin - Agents: Messages tab. The text messages (SMS) a customer an agent booked for can get, in English, Amharic or both.
   Needs js/admin-agents-pro.js. Admin picks the language customers receive, edits the wording of any message and any language, sees a live phone
   preview and an SMS-credit estimate, and can send a draft to a test phone before saving. Nothing is sent to a customer from here.
   Data actions: admin_fa_sms_templates_get / admin_fa_sms_template_save / admin_fa_sms_mode_save / admin_fa_sms_test (api/controllers/FieldAgentAdminController.php,
   wording and cost rules in api/lib/fa_sms_templates.php). Amharic labels below: please have a native speaker review. */
(function () {
'use strict';
function init() {
var A = window.ETELAgents; if (!A || A.__msg) return; A.__msg = true;
var K = A.K, esc = A.esc, run = A.run, ok = A.ok, dat = A.dat;

var AMM = {
  'Messages': 'መልእክቶች', 'Customer messages': 'የደንበኛ መልእክቶች',
  'What customers read when an agent books a ride for them.': 'ወኪል ለደንበኛ ጉዞ ሲያዝ ደንበኛው የሚያነበው መልእክት።',
  'Language customers receive': 'ደንበኞች የሚቀበሉት ቋንቋ', 'English': 'English', 'Amharic': 'አማርኛ', 'English and Amharic': 'እንግሊዝኛ እና አማርኛ',
  'Your messages': 'መልእክቶችዎ', 'Automatic': 'በራስ-ሰር', 'Manual button': 'በአዝራር', 'Test only': 'ለሙከራ ብቻ',
  'New customer text': 'ለአዲስ ደንበኛ መልእክት', 'Reset message': 'የይለፍ ቃል መልእክት', 'Verify reminder': 'የማረጋገጫ ማስታወሻ', 'Test text': 'የሙከራ መልእክት',
  'Save': 'አስቀምጥ', 'Saved': 'ተቀምጧል', 'Reset to default': 'ወደ ነባሪ መልስ', 'Insert link': 'አድራሻ አስገባ', 'Send to test phone': 'ወደ መሞከሪያ ስልክ ላክ',
  'Test phone': 'መሞከሪያ ስልክ', 'Edited': 'ተስተካክሏል', 'Sent to customers': 'ለደንበኞች የሚላክ', 'Preview': 'ቅድመ እይታ',
  'Send automatically': 'በራስ-ሰር ላክ', 'credit': 'ክሬዲት', 'credits': 'ክሬዲቶች', 'characters': 'ቁምፊዎች',
  'Why Amharic costs more': 'አማርኛ ለምን ይበልጣል', 'View only': 'ለማየት ብቻ'
};
function T(s) { return (A.S.lang === 'am' && AMM[s]) ? AMM[s] : A.T(s); }

var LANGS = ['en', 'am', 'both'];
var LNAME = { en: 'English', am: 'Amharic', both: 'English and Amharic' };
var LBADGE = { en: 'Aa', am: 'አ', both: 'Aa አ' };
var LDESC = { en: 'One credit. Simple and cheap.', am: 'Customers read it in Amharic.', both: 'Both languages in one short text.' };
var KICON = { welcome: '👋', reset: '🔑', verify: '✅', test: '🧪' };
var HOW = { automatic: 'Automatic', manual: 'Manual button', test: 'Test only' };
var PHK = 'etel_ms_phone';

var M = { loaded: false, err: '', d: null, open: 'welcome', tab: {}, draft: {}, phone: '' };
try { M.phone = localStorage.getItem(PHK) || ''; } catch (e) { /* not remembered */ }

/* ---------- the same cost rule as api/lib/fa_sms_templates.php (fa_sms_cost): plain ASCII = 160 / 153 per part, anything else = Unicode 70 / 67 ---------- */
function costOf(text) {
  text = String(text || '');
  var uni = /[^\x00-\x7F]/.test(text) || text.indexOf('`') !== -1, chars, segs, per;
  if (uni) { chars = text.length; per = chars <= 70 ? 70 : 67; segs = chars === 0 ? 0 : (chars <= 70 ? 1 : Math.ceil(chars / 67)); }
  else { chars = text.length + (text.match(/[\^{}\\\[\]~|]/g) || []).length; per = chars <= 160 ? 160 : 153; segs = chars === 0 ? 0 : (chars <= 160 ? 1 : Math.ceil(chars / 153)); }
  return { chars: chars, encoding: uni ? 'Unicode' : 'GSM-7', per: per, segments: segs };
}
window.__etelSmsCost = costOf;
function link() { return M.d ? M.d.link : 'ethelmarket.com/delivery/ride/'; }
function resolve(t) { return String(t || '').split('{link}').join(link()); }
function manage() { return A.can('agents.manage'); }
function kindOf(k) { var r = null; (M.d ? M.d.kinds : []).forEach(function (x) { if (x.kind === k) r = x; }); return r; }
function saved(k, l) { var x = kindOf(k); return x ? x.variants[l].body : ''; }
function key(k, l) { return k + '|' + l; }
function textOf(k, l) { var kk = key(k, l); return Object.prototype.hasOwnProperty.call(M.draft, kk) ? M.draft[kk] : saved(k, l); }
function dirty(k, l) { return Object.prototype.hasOwnProperty.call(M.draft, key(k, l)) && M.draft[key(k, l)] !== saved(k, l); }
function curTab(k) { return M.tab[k] || (M.d ? M.d.mode : 'en'); }
function creditWord(n) { return n + ' ' + T(n === 1 ? 'credit' : 'credits'); }
function creditCls(n) { return n <= 1 ? 'g' : (n === 2 ? 'a' : 'r'); }
function bubble(text) { return esc(resolve(text)).replace(/\n/g, '<br>').split(esc(link())).join('<u>' + esc(link()) + '</u>'); }

/* ---------- look: Etel gold and navy, light and dark ---------- */
function css() {
  if (document.getElementById('ms-css')) return;
  var s = document.createElement('style'); s.id = 'ms-css';
  s.textContent = [
    '.ms{font-family:Outfit,"Noto Sans Ethiopic",system-ui,sans-serif;--ms-navy:#0b1220;--ms-navy2:#1a2744;--ms-gold:#d4af37;--ms-gold2:#f0d77a;--ms-gold3:#8b6914;--ms-bub:#e9e9ee;--ms-bubi:#1b1b1f;display:grid;gap:18px}',
    'body.dark-theme .ms{--ms-bub:#2a2a31;--ms-bubi:#f2f2f5}',
    '.ms label,.ms button,.ms span,.ms b,.ms small{text-transform:none!important;letter-spacing:normal!important;font-family:inherit}',
    '.ms .ms-eyebrow{text-transform:uppercase!important;letter-spacing:.22em!important}',
    '.ms .ms-test label{font-size:13px;font-weight:800;color:var(--ink)}',
    '.ms-hero{position:relative;overflow:hidden;border-radius:24px;padding:20px 20px 18px;color:#fff;border:1px solid rgba(212,175,55,.4);background:linear-gradient(135deg,#0b1220 0%,#1a2744 58%,#4a3a12 100%);box-shadow:0 18px 40px -22px rgba(11,18,32,.8)}',
    '.ms-hero:before{content:"";position:absolute;right:-60px;top:-70px;width:240px;height:240px;border-radius:50%;background:radial-gradient(circle,rgba(240,215,122,.35),rgba(240,215,122,0) 68%);pointer-events:none}',
    '.ms-hero:after{content:"";position:absolute;left:0;right:0;bottom:0;height:3px;background:linear-gradient(90deg,transparent,#d4af37,#f0d77a,#d4af37,transparent)}',
    '.ms-hero-in{position:relative;display:flex;gap:14px;align-items:center}',
    '.ms-logo{flex:none;width:58px;height:58px;border-radius:50%;object-fit:cover;background:#0b1220;box-shadow:0 0 0 2px #d4af37,0 8px 22px rgba(0,0,0,.45)}',
    '.ms-eyebrow{display:block;font-size:10.5px;letter-spacing:.22em;font-weight:700;color:#f0d77a;text-transform:uppercase}',
    '.ms-hero h2{margin:2px 0 3px;font-size:22px;line-height:1.15;color:#fff;font-weight:800}',
    '.ms-hero p{margin:0;font-size:13px;line-height:1.45;color:rgba(255,255,255,.78)}',
    '.ms-pills{position:relative;display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}',
    '.ms-pill{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;padding:5px 11px;border-radius:99px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.22);color:#fff}',
    '.ms-pill i{width:8px;height:8px;border-radius:50%;background:#37d67a;display:inline-block;box-shadow:0 0 0 3px rgba(55,214,122,.25)}',
    '.ms-pill.warn i{background:#ffb02e;box-shadow:0 0 0 3px rgba(255,176,46,.25)}',
    '.ms-h{display:flex;align-items:baseline;justify-content:space-between;gap:10px;margin:0 2px 8px}.ms-h h3{margin:0;font-size:15.5px;color:var(--ink)}.ms-h small{color:var(--mute);font-size:12px}',
    '.ms-langs{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}@media(max-width:820px){.ms-langs{grid-template-columns:1fr}}',
    '.ms-lang{position:relative;text-align:left;cursor:pointer;display:flex;flex-direction:column;gap:9px;padding:15px;border-radius:20px;border:1.5px solid var(--line);background:var(--card);color:var(--ink);font:inherit;transition:transform .15s,box-shadow .2s,border-color .2s;min-height:44px}',
    '.ms-lang:hover:not([disabled]){transform:translateY(-2px);box-shadow:var(--shadow)}',
    '.ms-lang.on{border-color:var(--ms-gold);box-shadow:0 0 0 3px rgba(212,175,55,.22),var(--shadow);background:linear-gradient(180deg,rgba(212,175,55,.09),transparent 60%),var(--card)}',
    '.ms-lang[disabled]{cursor:default;opacity:.85}',
    '.ms-lb{display:flex;align-items:center;gap:10px}.ms-badge{flex:none;min-width:42px;height:42px;padding:0 10px;border-radius:13px;display:grid;place-items:center;font-weight:800;font-size:15px;color:#1a1206;background:linear-gradient(135deg,#f0d77a,#d4af37 60%,#b8860b)}',
    '.ms-ln{font-weight:800;font-size:14.5px;line-height:1.2}.ms-ld{font-size:12px;color:var(--mute);line-height:1.35;font-weight:500}',
    '.ms-chk{position:absolute;right:12px;top:12px;width:24px;height:24px;border-radius:50%;display:grid;place-items:center;font-size:13px;color:#1a1206;background:linear-gradient(135deg,#f0d77a,#d4af37);transform:scale(0);transition:transform .2s}.ms-lang.on .ms-chk{transform:scale(1)}',
    '.ms-mini{font-size:12.5px;line-height:1.45;padding:9px 12px;border-radius:15px 15px 15px 4px;background:var(--ms-bub);color:var(--ms-bubi);min-height:60px;overflow:hidden;font-weight:500;overflow-wrap:anywhere}',
    '.ms-cost{display:inline-flex;align-self:flex-start;align-items:center;gap:6px;font-size:11.5px;font-weight:800;padding:3px 10px;border-radius:99px;border:1px solid var(--line);background:var(--soft);color:var(--ink)}',
    '.ms-cost.g{color:var(--green);border-color:var(--green)}.ms-cost.a{color:var(--amber);border-color:var(--amber)}.ms-cost.r{color:var(--red);border-color:var(--red)}',
    '.ms-card{border:1.5px solid var(--line);border-radius:22px;background:var(--card);box-shadow:var(--shadow);overflow:hidden}',
    '.ms-card.open{border-color:rgba(212,175,55,.55)}',
    '.ms-ch{width:100%;display:flex;align-items:center;gap:12px;padding:15px 16px;background:none;border:0;cursor:pointer;color:var(--ink);font:inherit;text-align:left;min-height:56px}',
    '.ms-ci{flex:none;width:42px;height:42px;border-radius:14px;display:grid;place-items:center;font-size:20px;background:linear-gradient(135deg,rgba(240,215,122,.35),rgba(212,175,55,.2));border:1px solid rgba(212,175,55,.45)}',
    '.ms-ct{flex:1;min-width:0}.ms-ct b{display:block;font-size:15px;line-height:1.25}.ms-ct span{display:block;margin-top:2px;font-size:12px;color:var(--mute);line-height:1.4;font-weight:500}',
    '.ms-how{flex:none;font-size:11px;font-weight:800;letter-spacing:.04em;padding:3px 9px;border-radius:99px;background:var(--soft);border:1px solid var(--line);color:var(--ink)}',
    '.ms-how.automatic{background:rgba(212,175,55,.16);border-color:rgba(212,175,55,.6)}',
    '.ms-chev{flex:none;transition:transform .2s;color:var(--mute);font-size:18px}.ms-card.open .ms-chev{transform:rotate(180deg)}',
    '.ms-cb{display:none;padding:2px 16px 16px;border-top:1px solid var(--line)}.ms-card.open .ms-cb{display:block}',
    '.ms-auto{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:14px 0 4px;padding:11px 14px;border-radius:15px;background:var(--soft);border:1px solid var(--line);font-size:13px;font-weight:700}',
    '.ms-sw{flex:none;position:relative;width:52px;height:30px}.ms-sw input{position:absolute;inset:0;width:100%;height:100%;opacity:0;margin:0;cursor:pointer;z-index:2}',
    '.ms-sw i{position:absolute;inset:0;border-radius:99px;background:var(--line);transition:background .2s}.ms-sw i:after{content:"";position:absolute;top:3px;left:3px;width:24px;height:24px;border-radius:50%;background:#fff;box-shadow:0 2px 6px rgba(0,0,0,.3);transition:transform .2s}',
    '.ms-sw input:checked+i{background:linear-gradient(135deg,#f0d77a,#d4af37)}.ms-sw input:checked+i:after{transform:translateX(22px)}.ms-sw input:focus-visible+i{outline:3px solid var(--focus);outline-offset:2px}',
    '.ms-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:14px 0 12px;padding:4px;border-radius:16px;background:var(--soft);border:1px solid var(--line)}',
    '.ms-tab{flex:1 1 0;min-width:96px;display:flex;flex-direction:column;align-items:center;gap:2px;padding:8px 8px;border-radius:12px;border:0;background:none;color:var(--ink);font:inherit;font-weight:800;font-size:13px;cursor:pointer;min-height:44px}',
    '.ms-tab small{font-size:10.5px;font-weight:700;color:var(--mute);letter-spacing:.02em}',
    '.ms-tab.on{background:var(--card);box-shadow:0 2px 10px rgba(0,0,0,.14);outline:1.5px solid rgba(212,175,55,.7)}.ms-tab.live small{color:var(--green)}',
    '.ms-edit{display:grid;grid-template-columns:minmax(0,1fr) 270px;gap:16px;align-items:start}@media(max-width:820px){.ms-edit{grid-template-columns:1fr}}',
    '.ms-tools{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px}',
    '.ms-ta{display:block;width:100%;min-height:132px;resize:vertical;box-sizing:border-box;padding:13px 14px;border-radius:16px;border:1.5px solid var(--line);background:var(--card);color:var(--ink);font:inherit;font-size:15px;line-height:1.55}',
    '.ms-ta:focus{outline:0;border-color:var(--ms-gold);box-shadow:0 0 0 3px rgba(212,175,55,.25)}.ms-ta[disabled]{opacity:.75}',
    '.ms-meter{display:flex;gap:5px;margin:10px 0 6px}.ms-seg{flex:1;height:9px;border-radius:5px;background:var(--line);overflow:hidden;min-width:18px}.ms-seg b{display:block;height:100%;background:linear-gradient(90deg,#8b6914,#d4af37,#f0d77a);transition:width .15s}',
    '.ms-count{display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:12.5px;color:var(--mute);font-weight:600}',
    '.ms-warn{margin-top:8px;font-size:12.5px;color:var(--red);font-weight:700}',
    '.ms-phone{margin:0 auto;width:100%;max-width:270px;border-radius:34px;padding:9px;background:linear-gradient(160deg,#1a2744,#0b1220);box-shadow:0 18px 34px -18px rgba(11,18,32,.9)}',
    '.ms-scr{border-radius:26px;background:#f6f6f8;min-height:230px;padding:12px 12px 16px;color:#111}body.dark-theme .ms-scr{background:#0f0f12;color:#f2f2f5}',
    '.ms-snd{display:flex;flex-direction:column;align-items:center;gap:3px;margin:2px 0 12px;font-size:11px;font-weight:700;color:#6a6a74}',
    '.ms-av{width:34px;height:34px;border-radius:50%;display:grid;place-items:center;font-weight:800;font-size:12px;color:#1a1206;background:linear-gradient(135deg,#f0d77a,#d4af37 60%,#b8860b)}',
    '.ms-bub{max-width:92%;padding:10px 13px;border-radius:19px 19px 19px 5px;background:var(--ms-bub);color:var(--ms-bubi);font-size:13.5px;line-height:1.45;overflow-wrap:anywhere}.ms-bub u{color:#2a6fe0;text-decoration:underline}',
    '.ms-time{margin:6px 6px 0;font-size:10.5px;color:#8a8a94}',
    '.ms-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px;padding-top:14px;border-top:1px solid var(--line)}.ms-actions .ak-btn{min-height:44px}.ms-actions .sp{flex:1}',
    '.ms-edited{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:800;color:var(--ms-gold3)}body.dark-theme .ms-edited{color:var(--ms-gold2)}',
    '.ms-test{display:flex;gap:10px;flex-wrap:wrap;align-items:center;padding:14px 16px;border-radius:20px;border:1.5px dashed rgba(212,175,55,.7);background:linear-gradient(180deg,rgba(212,175,55,.08),transparent)}',
    '.ms-test label{font-weight:800;font-size:13px}.ms-test input{flex:1 1 160px;min-width:0;min-height:44px;padding:0 14px;border-radius:14px;border:1.5px solid var(--line);background:var(--card);color:var(--ink);font:inherit;font-size:15px}.ms-test input:focus{outline:0;border-color:var(--ms-gold);box-shadow:0 0 0 3px rgba(212,175,55,.25)}',
    '.ms-note{padding:14px 16px;border-radius:18px;background:var(--soft);border:1px solid var(--line);font-size:12.5px;line-height:1.55;color:var(--mute)}.ms-note b{color:var(--ink)}',
    '@media(prefers-reduced-motion:reduce){.ms-lang,.ms-chk,.ms-chev,.ms-sw i,.ms-sw i:after,.ms-seg b{transition:none!important}}'
  ].join('');
  document.head.appendChild(s);
}

/* ---------- pieces ---------- */
function heroHtml() {
  var d = M.d, pv = d.provider, ok1 = !!pv;
  var pvName = pv === 'smsethiopia' ? 'SMSEthiopia' : (pv === 'africastalking' ? 'Africa\'s Talking' : '');
  return '<section class="ms-hero"><div class="ms-hero-in"><img class="ms-logo" src="agent/logo-128.webp" alt="" width="58" height="58"><div><span class="ms-eyebrow">ETEL · RIDE AGENTS</span><h2>' + esc(T('Customer messages')) + '</h2><p>' + esc(T('What customers read when an agent books a ride for them.')) + '</p></div></div>' +
    '<div class="ms-pills"><span class="ms-pill' + (ok1 ? '' : ' warn') + '"><i></i>' + esc(ok1 ? 'SMS: ' + pvName : 'SMS service not set up') + '</span><span class="ms-pill">🌐 ' + esc(T(LNAME[d.mode])) + '</span>' + (manage() ? '' : '<span class="ms-pill">👁 ' + esc(T('View only')) + '</span>') + '</div></section>';
}
function langHtml() {
  var d = M.d, w = kindOf('welcome');
  return '<section><div class="ms-h"><h3>' + esc(T('Language customers receive')) + '</h3></div><div class="ms-langs" role="radiogroup" aria-label="' + esc(T('Language customers receive')) + '">' + LANGS.map(function (l) {
    var on = d.mode === l, c = w ? w.variants[l].cost.segments : 1, sample = w ? resolve(w.variants[l].body) : '';
    return '<button type="button" role="radio" aria-checked="' + on + '" class="ms-lang' + (on ? ' on' : '') + '" data-ms-mode="' + l + '"' + (manage() ? '' : ' disabled') + '>' +
      '<span class="ms-chk" aria-hidden="true">✓</span><span class="ms-lb"><span class="ms-badge" aria-hidden="true">' + esc(LBADGE[l]) + '</span><span><span class="ms-ln">' + esc(T(LNAME[l])) + '</span><br><span class="ms-ld">' + esc(LDESC[l]) + '</span></span></span>' +
      '<span class="ms-mini">' + esc(sample.length > 118 ? sample.slice(0, 117) + '…' : sample).replace(/\n/g, '<br>') + '</span>' +
      '<span class="ms-cost ' + creditCls(c) + '">' + esc(creditWord(c)) + '</span></button>';
  }).join('') + '</div></section>';
}
function testHtml() {
  return '<section class="ms-test"><label for="ms-phone">📱 ' + esc(T('Test phone')) + '</label><input id="ms-phone" type="tel" inputmode="tel" autocomplete="off" placeholder="09…" value="' + esc(M.phone) + '"' + (manage() ? '' : ' disabled') + '><small style="color:var(--mute);flex:1 1 100%">' + esc('Every message below has a "Send to test phone" button. On the free SMS package only numbers you verified in the SMS dashboard can receive texts.') + '</small></section>';
}
function meterHtml(c) {
  var n = Math.max(1, Math.min(8, c.segments)), h = '';
  for (var i = 1; i <= n; i++) {
    var fill = c.segments === 0 ? 0 : (i < c.segments ? 100 : Math.min(100, Math.round((c.chars - c.per * (c.segments - 1)) / c.per * 100)));
    h += '<span class="ms-seg"><b style="width:' + fill + '%"></b></span>';
  }
  return h;
}
function countHtml(c, text) {
  var miss = text.indexOf('{link}') === -1;
  return '<span class="ms-cost ' + creditCls(c.segments) + '">' + esc(creditWord(c.segments)) + '</span><span>' + c.chars + ' ' + esc(T('characters')) + ' · ' + esc(c.encoding) + ' · ' + c.per + ' per part</span>';
}
function cardHtml(x) {
  var k = x.kind, l = curTab(k), text = textOf(k, l), c = costOf(resolve(text)), open = M.open === k, edited = x.variants[l].custom, dt = dirty(k, l);
  var mg = manage();
  var h = '<article class="ms-card' + (open ? ' open' : '') + '" data-ms-card="' + k + '">' +
    '<button type="button" class="ms-ch" data-ms-toggle="' + k + '" aria-expanded="' + open + '"><span class="ms-ci" aria-hidden="true">' + KICON[k] + '</span><span class="ms-ct"><b>' + esc(T(x.title)) + '</b><span>' + esc(x.when) + '</span></span><span class="ms-how ' + x.how + '">' + esc(T(HOW[x.how])) + '</span><span class="ms-chev" aria-hidden="true">⌄</span></button>' +
    '<div class="ms-cb">';
  if (k === 'welcome') h += '<div class="ms-auto"><span>⚡ ' + esc(T('Send automatically')) + '</span><label class="ms-sw"><input type="checkbox" data-ms-auto' + (M.d.auto_on ? ' checked' : '') + (mg ? '' : ' disabled') + ' aria-label="' + esc(T('Send automatically')) + '"><i aria-hidden="true"></i></label></div>';
  h += '<div class="ms-tabs" role="tablist">' + LANGS.map(function (L) {
    var live = M.d.mode === L, cu = x.variants[L].custom || dirty(k, L);
    return '<button type="button" role="tab" aria-selected="' + (L === l) + '" class="ms-tab' + (L === l ? ' on' : '') + (live ? ' live' : '') + '" data-ms-tab="' + k + ':' + L + '">' + esc(T(L === 'both' ? 'English and Amharic' : LNAME[L])) + '<small>' + (live ? '● ' + esc(T('Sent to customers')) : (cu ? '✎ ' + esc(T('Edited')) : ' ')) + '</small></button>';
  }).join('') + '</div>';
  h += '<div class="ms-edit"><div><div class="ms-tools">' +
    (mg ? '<button type="button" class="ak-btn" data-ms-ins="' + k + '">🔗 ' + esc(T('Insert link')) + ' {link}</button>' : '') +
    (mg && (edited || dt) ? '<button type="button" class="ak-btn" data-ms-reset="' + k + '">↺ ' + esc(T('Reset to default')) + '</button>' : '') +
    '</div><textarea class="ms-ta" id="ms-ta-' + k + '" data-ms-ta="' + k + '" aria-label="' + esc(T(x.title)) + ' – ' + esc(T(LNAME[l])) + '" spellcheck="false"' + (mg ? '' : ' disabled') + '>' + esc(text) + '</textarea>' +
    '<div class="ms-meter" id="ms-meter-' + k + '">' + meterHtml(c) + '</div><div class="ms-count" id="ms-count-' + k + '">' + countHtml(c, text) + '</div><div class="ms-warn" id="ms-warn-' + k + '">' + (k !== 'test' && text.indexOf('{link}') === -1 ? esc('Keep {link} in the text so the customer gets the sign-in address.') : '') + '</div></div>' +
    '<div><div class="ms-phone" aria-label="' + esc(T('Preview')) + '"><div class="ms-scr"><div class="ms-snd"><span class="ms-av">ETEL</span><span>ETEL</span></div><div class="ms-bub" id="ms-bub-' + k + '">' + bubble(text) + '</div><div class="ms-time">' + esc(T('Preview')) + '</div></div></div></div></div>';
  h += '<div class="ms-actions">' + (mg ? '<button type="button" class="ak-btn gold" data-ms-save="' + k + '" id="ms-save-' + k + '"' + (dt ? '' : ' disabled') + '>' + esc(T('Save')) + '</button><button type="button" class="ak-btn" data-ms-test="' + k + '">✉️ ' + esc(T('Send to test phone')) + '</button>' : '') +
    '<span class="sp"></span>' + (edited ? '<span class="ms-edited">✎ ' + esc(T('Edited')) + '</span>' : '') + '</div></div></article>';
  return h;
}
function noteHtml() {
  return '<div class="ms-note"><b>' + esc(T('Why Amharic costs more')) + '.</b> A text with only English letters and numbers fits 160 characters in one SMS credit. As soon as a text has Amharic letters it is sent as Unicode, which fits only 70 characters per credit (67 when it needs more than one). A short text that has both languages usually costs 2 credits. The credit numbers here are an estimate; your SMS service has the final count.</div>';
}
function render() {
  css();
  if (M.err) return '<div class="ak-empty"><b>⚠️</b><div>' + esc(M.err) + '</div></div>';
  if (!M.loaded) return K.skeleton(4);
  return '<div class="ms" id="ms-root">' + heroHtml() + langHtml() + testHtml() + '<section><div class="ms-h"><h3>' + esc(T('Your messages')) + '</h3></div><div style="display:grid;gap:12px">' + M.d.kinds.map(cardHtml).join('') + '</div></section>' + noteHtml() + '</div>';
}
function paintNow() { var box = document.getElementById('ag-screen'); if (box && A.S.tab === 'messages') box.innerHTML = render(); }
function load() {
  return run('admin_fa_sms_templates_get').then(function (r) {
    if (!ok(r)) { M.err = K.failText(r, 'Could not load the messages.'); M.d = null; } else { M.err = ''; M.d = dat(r); M.loaded = true; }
    paintNow();
  });
}
/* typing: patch only the counter, meter, preview and buttons of that card (no re-render, so the cursor stays) */
function typed(k, ta) {
  var l = curTab(k), text = ta.value; M.draft[key(k, l)] = text;
  var c = costOf(resolve(text)), g = function (id) { return document.getElementById(id + k); };
  if (g('ms-meter-')) g('ms-meter-').innerHTML = meterHtml(c);
  if (g('ms-count-')) g('ms-count-').innerHTML = countHtml(c, text);
  if (g('ms-bub-')) g('ms-bub-').innerHTML = bubble(text);
  if (g('ms-warn-')) g('ms-warn-').textContent = (k !== 'test' && text.indexOf('{link}') === -1) ? 'Keep {link} in the text so the customer gets the sign-in address.' : '';
  if (g('ms-save-')) g('ms-save-').disabled = !dirty(k, l);
}
function setBusy(b, on) { if (b) { b.disabled = on; b.classList.toggle('busy', on); } }

function onClick(e) {
  if (A.S.tab !== 'messages') return;
  var t = e.target, b;
  if ((b = t.closest('[data-ms-mode]'))) {
    var m = b.getAttribute('data-ms-mode'); if (!manage() || !M.d || m === M.d.mode) return;
    A.busy(b, function () { return run('admin_fa_sms_mode_save', { mode: m }).then(function (r) {
      if (!ok(r)) { K.toast(K.failText(r, 'Could not save.'), true); return; }
      M.d = dat(r); K.toast(T('Saved') + ': ' + T(LNAME[m])); paintNow();
    }); }); return;
  }
  if ((b = t.closest('[data-ms-toggle]'))) { var k = b.getAttribute('data-ms-toggle'); M.open = M.open === k ? '' : k; paintNow(); return; }
  if ((b = t.closest('[data-ms-tab]'))) { var p = b.getAttribute('data-ms-tab').split(':'); M.tab[p[0]] = p[1]; M.open = p[0]; paintNow(); return; }
  if ((b = t.closest('[data-ms-ins]'))) {
    var kk = b.getAttribute('data-ms-ins'), ta = document.getElementById('ms-ta-' + kk); if (!ta) return;
    var s0 = ta.selectionStart == null ? ta.value.length : ta.selectionStart, s1 = ta.selectionEnd == null ? s0 : ta.selectionEnd;
    ta.value = ta.value.slice(0, s0) + '{link}' + ta.value.slice(s1); ta.focus(); ta.selectionStart = ta.selectionEnd = s0 + 6; typed(kk, ta); return;
  }
  if ((b = t.closest('[data-ms-save]'))) {
    var ks = b.getAttribute('data-ms-save'), ls = curTab(ks);
    A.busy(b, function () { return run('admin_fa_sms_template_save', { kind: ks, lang: ls, body: textOf(ks, ls) }).then(function (r) {
      if (!ok(r)) { K.toast(K.failText(r, 'Could not save.'), true); return; }
      delete M.draft[key(ks, ls)]; M.d = dat(r); K.toast(T('Saved')); paintNow();
    }); }); return;
  }
  if ((b = t.closest('[data-ms-reset]'))) {
    var kr = b.getAttribute('data-ms-reset'), lr = curTab(kr);
    K.confirm({ title: T('Reset to default') + '?', text: 'The wording goes back to the built-in text for ' + LNAME[lr] + '. Your edit is removed.', label: T('Reset to default') }).then(function (res) {
      if (!res) return;
      return run('admin_fa_sms_template_save', { kind: kr, lang: lr, reset: true }).then(function (r) {
        if (!ok(r)) { K.toast(K.failText(r, 'Could not reset.'), true); return; }
        delete M.draft[key(kr, lr)]; M.d = dat(r); K.toast(T('Saved')); paintNow();
      });
    }); return;
  }
  if ((b = t.closest('[data-ms-test]'))) {
    var kt = b.getAttribute('data-ms-test'), lt = curTab(kt), ph = document.getElementById('ms-phone'), num = ph ? ph.value.trim() : '';
    if (!num) { K.toast('Type the test phone number first (the box above the messages).', true); if (ph) ph.focus(); return; }
    A.busy(b, function () { return run('admin_fa_sms_test', { phone: num, kind: kt, lang: lt, body: textOf(kt, lt) }).then(function (r) {
      if (!ok(r)) { K.toast(K.failText(r, 'The test text was not sent.'), true); return; }
      var x = dat(r); K.toast('Test text sent: ' + x.segments + ' ' + (x.segments === 1 ? 'credit' : 'credits') + ', ' + x.chars + ' characters. Check the phone.');
    }); }); return;
  }
}
function onInput(e) {
  if (A.S.tab !== 'messages') return;
  var t = e.target;
  if (t.matches && t.matches('[data-ms-ta]')) { typed(t.getAttribute('data-ms-ta'), t); return; }
  if (t.id === 'ms-phone') { M.phone = t.value; try { localStorage.setItem(PHK, M.phone); } catch (x) { /* not remembered */ } }
}
function onChange(e) {
  if (A.S.tab !== 'messages') return;
  var t = e.target;
  if (t.matches && t.matches('[data-ms-auto]')) {
    var on = t.checked ? 1 : 0;
    run('admin_fa_settings_save', { settings: { agent_booking_sms: on } }).then(function (r) {
      if (!ok(r)) { t.checked = !on; K.toast(K.failText(r, 'Could not save.'), true); return; }
      M.d.auto_on = !!on; K.toast(on ? 'New customers get this text automatically' : 'Automatic text is off');
    });
  }
}
document.addEventListener('click', function (e) { var h = document.getElementById('panel-agents'); if (h && h.contains(e.target)) onClick(e); });
document.addEventListener('input', function (e) { var h = document.getElementById('panel-agents'); if (h && h.contains(e.target)) onInput(e); });
document.addEventListener('change', function (e) { var h = document.getElementById('panel-agents'); if (h && h.contains(e.target)) onChange(e); });
A.register('messages', { render: render, open: function () { M.loaded = false; paintNow(); return load(); } });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

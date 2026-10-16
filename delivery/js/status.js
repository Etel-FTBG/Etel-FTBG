/* ETEL public status page. Reads api/health.php (public, shows nothing private) every minute.
   Only what matters to a customer is shown: can people order/ride (database) and is dispatch running (background job).
   Disk and backup warnings are for the owner (Admin) and are not shown here. */
(function () {
'use strict';
var HEALTH = 'api/health.php';
var LANG = 'en';
try { LANG = localStorage.getItem('etel_status_lang') === 'am' ? 'am' : 'en'; } catch (e) {}
var T = {
  en: { title: 'System status', core: 'Ordering, rides and payments', live: 'Dispatch and live updates', checked: 'Checked', auto: 'refreshes every minute', back: 'Back to ETEL Delivery',
        ok: 'All systems working', okSub: 'Orders and rides are running normally.', warn: 'Some delays are possible', warnSub: 'You can still order. Live updates may be slower than usual.',
        bad: 'We have a problem', badSub: 'Orders and rides may not work right now. We are working on it.', unk: 'Cannot check right now', unkSub: 'Your connection may be offline, or the check did not answer. Try again in a minute.',
        up: 'Working', slow: 'Delayed', down: 'Down', na: 'Unknown', lang: 'አማርኛ' },
  am: { title: 'የስርዓት ሁኔታ', core: 'ትዕዛዝ፣ ጉዞ እና ክፍያ', live: 'ማሰማራት እና ቀጥታ መረጃ', checked: 'የተፈተሸው', auto: 'በየደቂቃው ይታደሳል', back: 'ወደ ኢትኤል ዴሊቨሪ ተመለስ',
        ok: 'ሁሉም ስርዓቶች በትክክል ይሰራሉ', okSub: 'ትዕዛዞች እና ጉዞዎች በመደበኛ ሁኔታ እየሄዱ ነው።', warn: 'ትንሽ መዘግየት ሊኖር ይችላል', warnSub: 'አሁንም ማዘዝ ይችላሉ። ቀጥታ መረጃ ከወትሮው ሊዘገይ ይችላል።',
        bad: 'ችግር አጋጥሞናል', badSub: 'ትዕዛዞች እና ጉዞዎች አሁን ላይሰሩ ይችላሉ። እየሰራንበት ነው።', unk: 'አሁን መፈተሽ አልተቻለም', unkSub: 'ኢንተርኔትዎ ጠፍቶ ሊሆን ይችላል። ከአንድ ደቂቃ በኋላ ይሞክሩ።',
        up: 'ይሰራል', slow: 'ዘግይቷል', down: 'አይሰራም', na: 'አይታወቅም', lang: 'English' }
};
function $(id) { return document.getElementById(id); }
function set(row, v, state, text) { $('row-' + row).setAttribute('data-state', state); $('v-' + row).textContent = text; }
function paint(core, live, overall, when) {
  var t = T[LANG];
  $('hero').setAttribute('data-state', overall);
  $('head').textContent = t[overall === 'ok' ? 'ok' : overall === 'warn' ? 'warn' : overall === 'bad' ? 'bad' : 'unk'];
  $('sub').textContent = t[overall === 'ok' ? 'okSub' : overall === 'warn' ? 'warnSub' : overall === 'bad' ? 'badSub' : 'unkSub'];
  set('core', 0, core === 'ok' ? 'ok' : core === 'bad' ? 'bad' : 'none', t[core === 'ok' ? 'up' : core === 'bad' ? 'down' : 'na']);
  set('live', 0, live === 'ok' ? 'ok' : live === 'warn' ? 'warn' : 'none', t[live === 'ok' ? 'up' : live === 'warn' ? 'slow' : 'na']);
  $('when').textContent = when || '—';
}
var last = null;
function apply(d) {
  var core, live, overall;
  if (!d) { core = live = 'na'; overall = 'none'; }
  else {
    core = d.db === 'ok' ? 'ok' : (d.db === 'down' ? 'bad' : 'na');
    live = d.cron === 'stale' ? 'warn' : (d.cron === 'ok' ? 'ok' : 'na');
    overall = core === 'bad' ? 'bad' : (live === 'warn' ? 'warn' : (core === 'ok' ? 'ok' : 'none'));
  }
  last = [core, live, overall, new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })];
  paint.apply(null, last);
}
function check() {
  fetch(HEALTH, { cache: 'no-store' }).then(function (r) { return r.json(); }).then(apply).catch(function () { apply(null); });
}
function labels() {
  var t = T[LANG];
  document.documentElement.lang = LANG;
  document.title = t.title + ' · ETEL';
  Array.prototype.forEach.call(document.querySelectorAll('[data-i]'), function (el) { el.textContent = t[el.getAttribute('data-i')]; });
  $('lang').textContent = t.lang;
  if (last) paint.apply(null, last);
}
$('lang').addEventListener('click', function () {
  LANG = LANG === 'en' ? 'am' : 'en';
  try { localStorage.setItem('etel_status_lang', LANG); } catch (e) {}
  labels();
});
labels();
check();
setInterval(check, 60000);
})();

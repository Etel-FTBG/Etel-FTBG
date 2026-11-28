// Language switch for all Ethiopian languages: picker (English, Amharic, Oromo, Tigrinya, Somali, Afar), reload on pick, translation of what is on screen for the new languages
// (the REAL js/etel-langs.js and the REAL dictionaries delivery/i18n/*.js), old two-language buttons hidden, and the real login page in Oromo.   node delivery/tests/ui_languages.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp' };
const srv = http.createServer((q, r) => { const u = decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, ''); fs.readFile(path.join(ROOT, u), (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('content-type', MIME[path.extname(u)] || 'application/octet-stream'); r.end(d); } }); });
const PAGE = (extra) => '<!doctype html><html lang="en"><head><meta charset="utf-8"><script src="/delivery/js/etel-langs.js?v=1" defer></script></head><body>' + (extra || '') + '</body></html>';
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  // ---------- 1. a stub page with the real files ----------
  const ctx = await b.newContext({ viewport: { width: 390, height: 800 }, serviceWorkers: 'block' });
  async function stub(lang, body, setup) {
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route('http://app.test/**', r => r.request().url().includes('/delivery/js/') || r.request().url().includes('/delivery/i18n/') ? r.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(path.join(ROOT, new URL(r.request().url()).pathname)) }) : r.fulfill({ contentType: 'text/html', body: PAGE(body) }));
    await p.addInitScript(l => { try { if (l) localStorage.setItem('etel_lang', l); } catch (e) {} }, lang);
    if (setup) await p.addInitScript(setup);
    await p.goto('http://app.test/delivery/ride/'); p.errs = errs; return p;
  }
  const BODY = '<h1 id="h">Book</h1><p id="p1">Where are you going?</p><button id="b1">Cancel Ride</button><p id="p2">🚗 Call</p><p id="p3">Something not in the dictionary</p>' +
    '<input id="i1" placeholder="Where to?" value="Cancel Ride"><textarea id="t1">Cancel Ride</textarea><div data-no-i18n="1"><span id="n1">Cancel Ride</span></div><span id="s1">Loading...</span>';
  // the picker
  let p = await stub('', BODY); await p.waitForSelector('#etel-lang-btn'); 
  t('English: no dictionary asked for, text unchanged', (await p.textContent('#p1')) === 'Where are you going?');
  t('English: the picker button shows EN and is a floating button (no old button on this page)', (await p.textContent('#etel-lang-btn')).trim() === 'EN' && (await p.evaluate(() => document.getElementById('etel-lang-btn').classList.contains('fl'))));
  await p.click('#etel-lang-btn');
  const opts = await p.evaluate(() => [...document.querySelectorAll('#etel-lang-sheet .opt')].map(o => ({ code: o.getAttribute('data-code'), name: o.querySelector('b').textContent, badge: (o.querySelector('.tag') || {}).textContent || '', dis: o.classList.contains('soon'), flag: !!o.querySelector('svg.elg-flag'), on: o.classList.contains('on') })));
  t('the sheet lists the six languages in order', opts.map(o => o.code).join() === 'en,am,om,ti,so,aa', JSON.stringify(opts.map(o => o.code)));
  t('names are in their own language', opts[2].name === 'Afaan Oromoo' && opts[3].name === 'ትግርኛ' && opts[4].name === 'Soomaali' && opts[5].name === 'Qafar af' && opts[1].name === 'አማርኛ');
  t('Oromo, Tigrinya and Somali say BETA; Afar says COMING SOON and cannot be picked; English and Amharic have no badge', opts[2].badge === 'BETA' && opts[3].badge === 'BETA' && opts[4].badge === 'BETA' && opts[5].badge === 'COMING SOON' && opts[5].dis === true && !opts[0].badge && !opts[1].badge, JSON.stringify(opts.map(o => o.badge)));
  t('the current language (English) is marked', opts[0].on && !opts[2].on);
  t('the sheet text is not touched by the translator (data-no-i18n)', (await p.getAttribute('#etel-lang-sheet', 'data-no-i18n')) === '1');
  // flags and readable colours, even when the page forces its own button and text colours
  await p.addStyleTag({ content: 'button,b,em,p,span,small{color:#222!important;background:#eee!important}' });
  const look = await p.evaluate(() => { const rgb = c => c.match(/\d+/g).slice(0, 3).map(Number); const lum = a => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(a[0]) + 0.7152 * f(a[1]) + 0.0722 * f(a[2]); };
    const bg = rgb(getComputedStyle(document.querySelector('#etel-lang-sheet .box')).backgroundColor.indexOf('rgb') === 0 ? getComputedStyle(document.querySelector('#etel-lang-sheet .box')).backgroundColor : 'rgb(14,18,38)');
    const ratio = el => { const L1 = lum(rgb(getComputedStyle(el).color)), L2 = lum([14, 18, 38]); return (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05); };
    const names = [...document.querySelectorAll('#etel-lang-sheet .tx b')].map(ratio), subs = [...document.querySelectorAll('#etel-lang-sheet .tx em')].map(ratio), note = ratio(document.querySelector('#etel-lang-sheet .note'));
    return { n: Math.min.apply(null, names), s: Math.min.apply(null, subs), note, btn: ratio(document.querySelector('#etel-lang-btn span')) }; });
  t('every language row has its own flag', opts.every(o => o.flag));
  t('language names are high contrast (7:1 or better) on the sheet, even though the page forces dark button text', look.n >= 7, JSON.stringify(look));
  t('English names, the note and the button text are at least 4.5:1', look.s >= 4.5 && look.note >= 4.5, JSON.stringify(look));
  t('the close button is there and closes the sheet', await p.evaluate(() => { document.querySelector('#etel-lang-sheet .x').click(); return !document.getElementById('etel-lang-sheet').classList.contains('open'); }));
  await p.click('#etel-lang-btn');
  // Escape closes
  await p.keyboard.press('Escape'); t('Escape closes the sheet', !(await p.evaluate(() => document.getElementById('etel-lang-sheet').classList.contains('open'))));
  // picking Afar does nothing
  await p.evaluate(() => EtelLangs.set('aa')); t('Afar (coming soon) cannot be set: the language stays English', (await p.evaluate(() => localStorage.getItem('etel_lang'))) !== 'aa' && (await p.evaluate(() => EtelLangs.get())) === 'en');
  // picking Oromo saves and reloads
  await p.click('#etel-lang-btn'); const nav = p.waitForNavigation({ timeout: 5000 }).catch(() => null);
  await p.click('#etel-lang-sheet .opt[data-code="om"]'); await nav;
  await p.waitForFunction(() => EtelLangs.get() === 'om'); await p.waitForTimeout(700);
  t('picking Oromo is remembered under the shared key etel_lang', (await p.evaluate(() => localStorage.getItem('etel_lang'))) === 'om');
  t('and the page reloaded in Oromo: html lang="om"', (await p.getAttribute('html', 'lang')) === 'om');
  const tx = async (sel) => p.textContent(sel);
  for (const [code, want] of [['om', { p1: 'Eessa deemtu?', b1: 'Imala haqi', s1: "Fe'aa jira..." }], ['ti', { p1: 'ናበይ ትኸይድ ኣለኻ?', b1: 'ጉዕዞ ሰርዝ', s1: 'ይጽዓን ኣሎ...' }], ['so', { p1: 'Xagee u socotaa?', b1: 'Jooji safarka', s1: 'Waa la raraya...' }]]) {
    const q = await stub(code, BODY); await q.waitForFunction(() => document.getElementById('p1').textContent !== 'Where are you going?', null, { timeout: 5000 }); await q.waitForTimeout(200);
    t(code + ': a whole sentence is translated', (await q.textContent('#p1')) === want.p1, await q.textContent('#p1'));
    t(code + ': a button is translated', (await q.textContent('#b1')) === want.b1, await q.textContent('#b1'));
    t(code + ': three dots are kept', (await q.textContent('#s1')) === want.s1, await q.textContent('#s1'));
    t(code + ': text with an emoji in front keeps the emoji and is translated', /^🚗 /.test(await q.textContent('#p2')) && (await q.textContent('#p2')) !== '🚗 Call', await q.textContent('#p2'));
    t(code + ': text with no dictionary entry stays English', (await q.textContent('#p3')) === 'Something not in the dictionary');
    t(code + ': the placeholder is translated', (await q.getAttribute('#i1', 'placeholder')) !== 'Where to?', await q.getAttribute('#i1', 'placeholder'));
    t(code + ': typed values and text areas are never touched', (await q.inputValue('#i1')) === 'Cancel Ride' && (await q.inputValue('#t1')) === 'Cancel Ride');
    t(code + ': data-no-i18n is left alone', (await q.textContent('#n1')) === 'Cancel Ride');
    t(code + ': html lang is the code', (await q.getAttribute('html', 'lang')) === code);
    // text drawn later is translated too
    await q.evaluate(() => { const d = document.createElement('div'); d.innerHTML = '<span id="late">Rate Your Driver</span>'; document.body.appendChild(d); document.getElementById('h').textContent = 'Where to?'; });
    await q.waitForTimeout(250);
    t(code + ': text added or changed later is translated (the apps draw lists and toasts after load)', (await q.textContent('#late')) !== 'Rate Your Driver' && (await q.textContent('#h')) !== 'Where to?', await q.textContent('#late') + ' | ' + await q.textContent('#h'));
    t(code + ': the picker shows the language short name', (await q.textContent('#etel-lang-btn')).trim() === ({ om: 'OM', ti: 'ትግ', so: 'SO' })[code]);
    t(code + ': no page errors', q.errs.length === 0, q.errs.join('|'));
    await q.close();
  }
  // Customer, Service Giver and login texts (their English lives in data-i18n keys; the walker matches the English values on screen)
  const APPS = '<p id="c1">Place Order</p><p id="c2">Delivery Fee</p><p id="c3">Today\'s Orders</p><p id="c4">Forgot password?</p><p id="c5">Sign in with EtelID</p><p id="c6">\u{1F4E6} My Orders</p><p id="c7">View cart \u2192</p>';
  for (const code of ['om', 'ti', 'so']) {
    const q = await stub(code, APPS); await q.waitForFunction(() => document.getElementById('c1').textContent !== 'Place Order', null, { timeout: 5000 });
    const r = await q.evaluate(() => ['c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7'].map(i => document.getElementById(i).textContent));
    t(code + ': Customer, Service Giver and login texts are translated (' + r.slice(0, 3).join(' | ') + ')', r.slice(0, 5).every((v, i) => v !== ['Place Order', 'Delivery Fee', "Today's Orders", 'Forgot password?', 'Sign in with EtelID'][i]), JSON.stringify(r));
    t(code + ': emoji in front and arrows behind are kept', /^\u{1F4E6} /u.test(r[5]) && r[5] !== '\u{1F4E6} My Orders' && /\u2192$/.test(r[6]), JSON.stringify([r[5], r[6]]));
    await q.close();
  }
  // Amharic: this file does nothing to the text (the apps' own engine does that)
  const am = await stub('am', BODY); await am.waitForTimeout(500);
  t('Amharic: the dictionary of the new languages is not loaded and the text is left to the apps', (await am.textContent('#p1')) === 'Where are you going?' && (await am.evaluate(() => !document.querySelector('script[src*="i18n/"]'))));
  t('Amharic: the picker shows አማ', (await am.textContent('#etel-lang-btn')).trim() === 'አማ');
  await am.close();
  // a saved value that is not a language, or Afar, falls back to English
  for (const bad of ['xx', 'aa']) { const q = await stub(bad, BODY); await q.waitForTimeout(300); t('a saved "' + bad + '" is treated as English', (await q.evaluate(() => EtelLangs.get())) === 'en' && (await q.textContent('#p1')) === 'Where are you going?'); await q.close(); }
  // ---------- 2. old two-language buttons are replaced where they are ----------
  const OLDS = [['ride', '<div id="hd"><button id="ride-lang-btn">አማ</button></div>', '#ride-lang-btn'], ['driver', '<div id="hd"><button id="drv-lang-btn">አማ</button></div>', '#drv-lang-btn'], ['courier', '<div id="hd"><div id="cl8-lang"><button>EN</button><button>አማ</button></div></div>', '#cl8-lang'],
    ['service', '<div id="hd"><div class="sg-lang"><button>EN</button></div></div>', '.sg-lang'], ['customer', '<div id="hd"><button data-act="toggleCustomerLanguage">EN/አማ</button></div>', '[data-act="toggleCustomerLanguage"]'],
    ['login', '<div id="hd" style="position:relative;height:60px"><div class="del-lang-switch" style="position:absolute;top:14px;right:16px;z-index:5"><button id="lang-btn-en">EN</button></div></div>', '.del-lang-switch']];
  for (const [name, body, sel] of OLDS) {
    const q = await stub('', body); await q.waitForSelector('#etel-lang-btn'); await q.waitForTimeout(200);
    const r = await q.evaluate((sel) => { const old = document.querySelector(sel), btn = document.getElementById('etel-lang-btn'); const cs = getComputedStyle(old); return { hidden: cs.display === 'none', same: btn.parentNode === old.parentNode, before: btn.nextElementSibling === old, floating: btn.classList.contains('fl'), pos: getComputedStyle(btn).position, top: getComputedStyle(btn).top }; }, sel);
    t(name + ': the old English / Amharic button is hidden and the picker sits in its place', r.hidden && r.same && r.before && !r.floating, JSON.stringify(r));
    if (name === 'login') t('login: the picker takes over the corner the old switch had (absolute, top 14px)', r.pos === 'absolute' && r.top === '14px', JSON.stringify(r));
    await q.close();
  }
  // an old button created later (the apps add theirs after load) still gets replaced
  const late = await stub('', '<div id="hd"></div>'); await late.waitForSelector('#etel-lang-btn'); t('late: before the app builds its button the picker floats', await late.evaluate(() => document.getElementById('etel-lang-btn').classList.contains('fl')));
  await late.evaluate(() => { const b = document.createElement('button'); b.id = 'ride-lang-btn'; b.textContent = 'አማ'; document.getElementById('hd').appendChild(b); });
  await late.waitForFunction(() => !document.getElementById('etel-lang-btn').classList.contains('fl'), null, { timeout: 4000 });
  t('late: the app builds its button later: the picker moves into its place and the old one is hidden', await late.evaluate(() => getComputedStyle(document.getElementById('ride-lang-btn')).display === 'none' && document.getElementById('etel-lang-btn').nextElementSibling.id === 'ride-lang-btn'));
  await late.close();
  // ---------- 3. the real login page ----------
  const lp = await ctx.newPage(); const lerr = []; lp.on('pageerror', e => lerr.push(e.message));
  await lp.route(/maps\.googleapis|gstatic|cdnjs|jsdelivr|fonts\.|etel-ai-widget|etel-help-fab/, r => r.abort()); await lp.route(/\.php/, r => r.fulfill({ contentType: 'application/json', body: '{"success":true,"data":{}}' }));
  await lp.addInitScript(() => { try { localStorage.setItem('etel_lang', 'om'); } catch (e) {} });
  await lp.goto(HOST + '/delivery/login.html', { waitUntil: 'load' }); await lp.waitForTimeout(2500);
  const L = await lp.evaluate(() => ({ lang: document.documentElement.getAttribute('lang'), btn: !!document.getElementById('etel-lang-btn'), oldHidden: getComputedStyle(document.querySelector('.del-lang-switch')).display === 'none', short: (document.getElementById('etel-lang-btn') || {}).textContent, scripts: [...document.scripts].map(s => s.src).filter(s => /i18n\/om\.js/.test(s)).length }));
  t('real login page in Oromo: html lang om, the picker is there showing OM, the old EN / አማ switch is hidden, the Oromo dictionary was loaded', L.lang === 'om' && L.btn && L.oldHidden && /OM/.test(L.short) && L.scripts === 1, JSON.stringify(L));
  await lp.getByText('Choose app').first().click().catch(() => {}); await lp.waitForTimeout(800);
  const texts = await lp.evaluate(() => [...document.querySelectorAll('.del-rtab')].map(b => b.innerText.trim()));
  t('real login page in Oromo: the role buttons are translated where the dictionary has the word (Driver -> Konkolaachisaa) and the other ones stay English', texts.some(x => /Konkolaachisaa/.test(x)) && texts.some(x => /Courier|Service|Customer/.test(x)), JSON.stringify(texts));
  const bodyTxt = await lp.evaluate(() => document.body.innerText);
  t('real login page in Oromo: the sign-in sentence and the Choose app line are translated (no English left)', !/One sign-in for every|Need a specific app|Choose app/.test(bodyTxt));
  t('real login page: no page errors', lerr.length === 0, lerr.join('|'));
  await lp.close();
  // ---------- the owner's settings (Admin > Branding > Languages) ----------
  async function withCfg(cfg, stored, setup) {
    const q = await ctx.newPage(); const errs = []; q.on('pageerror', e => errs.push(e.message)); q.errs = errs; q.asked = 0;
    await q.route('http://app.test/**', r => { const u = r.request().url(); if (u.includes('/delivery/js/') || u.includes('/delivery/i18n/')) return r.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(path.join(ROOT, new URL(u).pathname)) });
      if (u.includes('/api/boot.php')) { q.asked++; return r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: cfg }) }); }
      return r.fulfill({ contentType: 'text/html', body: PAGE('<script>window.ETEL={api:"http://app.test/delivery/api/boot.php"}</script><p id="p1">Where are you going?</p>') }); });
    await q.addInitScript(a => { try { localStorage.clear(); if (a) localStorage.setItem('etel_lang', a); } catch (e) {} }, stored);
    await q.goto('http://app.test/delivery/ride/'); return q;
  }
  const CFG1 = { default: 'en', langs: [{ code: 'en', status: 'live' }, { code: 'am', status: 'live' }, { code: 'om', status: 'off' }, { code: 'ti', status: 'live' }, { code: 'so', status: 'beta' }, { code: 'aa', status: 'beta' }] };
  let c1 = await withCfg(CFG1, 'en'); await c1.waitForFunction(() => localStorage.getItem('etel_lang_cfg')); await c1.click('#etel-lang-btn');
  const rows = await c1.evaluate(() => [...document.querySelectorAll('#etel-lang-sheet .opt')].map(o => o.getAttribute('data-code') + ':' + ((o.querySelector('.tag') || {}).textContent || '') + (o.classList.contains('soon') ? ':soon' : '')));
  t('owner settings: a language switched off is hidden, live has no tag, beta has BETA, and Afar can now be chosen', rows.join() === 'en:,am:,ti:,so:BETA,aa:BETA', rows.join());
  t('the settings are kept on the phone for the next visit', !!(await c1.evaluate(() => localStorage.getItem('etel_lang_cfg'))));
  let c2 = await withCfg(CFG1, 'om'); await c2.waitForNavigation({ timeout: 5000 }).catch(() => null); await c2.waitForFunction(() => localStorage.getItem('etel_lang') === 'en', null, { timeout: 5000 });
  t('a visitor whose language was switched off is moved back to English', (await c2.evaluate(() => localStorage.getItem('etel_lang'))) === 'en');
  let c3 = await withCfg(Object.assign({}, CFG1, { default: 'so' }), ''); await c3.waitForFunction(() => localStorage.getItem('etel_lang') === 'so', null, { timeout: 5000 });
  t('a new visitor starts in the language the owner chose as the default', (await c3.evaluate(() => localStorage.getItem('etel_lang'))) === 'so');
  await c3.close();
  let c4 = await withCfg(Object.assign({}, CFG1, { default: 'so' }), 'am'); await c4.waitForTimeout(600);
  t('a visitor who already chose a language keeps it, whatever the default is', (await c4.evaluate(() => localStorage.getItem('etel_lang'))) === 'am', await c4.evaluate(() => localStorage.getItem('etel_lang')));
  await b.close(); srv.close(); console.log(pass + ' passed, ' + fails.length + ' failed'); fails.forEach(f => console.log(' FAIL ' + f)); process.exit(fails.length ? 1 : 0);
})();

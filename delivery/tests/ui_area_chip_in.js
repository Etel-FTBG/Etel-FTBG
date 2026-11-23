// The city chip (js/etel-area.js, the real file): where a page has a top bar with room, the chip sits inside it instead of floating over its middle.
// Needs Playwright (node). No server.   node delivery/tests/ui_area_chip_in.js
const { chromium } = require('playwright'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const SRC = fs.readFileSync(path.join(__dirname, '../js/etel-area.js'), 'utf8');
const AREAS = { success: true, data: { enforced: true, areas: [{ code: 'hawassa', name: 'Hawassa', name_am: 'ሐዋሳ', lat: 7.05, lng: 38.48, radius_km: 25 }], current: { code: 'hawassa', name: 'Hawassa', name_am: 'ሐዋሳ', lat: 7.05, lng: 38.48, radius_km: 25 } } };
const page = (attr) => '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><script' + attr + '>' + SRC + '</scr' + 'ipt></head><body>' +
  '<div class="header" style="display:flex;justify-content:space-between;height:60px;padding:0 18px"><div class="header-left" style="display:flex;gap:10px"><div class="header-info"><div class="header-title">ETEL Courier</div><div class="header-subtitle">Professional Delivery</div></div></div>' +
  '<div class="header-right"><button id="lang">EN</button></div></div></body></html>';
(async () => {
  const b = await chromium.launch();
  const run = async (attr) => {
    const ctx = await b.newContext({ viewport: { width: 360, height: 700 } }); const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    await ctx.addInitScript((d) => { localStorage.setItem('etel_area_list_v1', JSON.stringify({ t: Date.now(), areas: d.areas, enforced: true })); localStorage.setItem('etel_area_v1', JSON.stringify({ code: 'hawassa', manual: true, t: Date.now() })); }, AREAS.data);
    await p.route('http://etel.test/delivery/api/**', r => r.fulfill({ contentType: 'application/json', body: JSON.stringify(AREAS) }));
    await p.route('http://etel.test/', r => r.fulfill({ contentType: 'text/html', body: page(attr) }));
    await p.goto('http://etel.test/'); await p.waitForTimeout(700);
    const o = await p.evaluate(() => { const c = document.querySelector('.eta-chip'); if (!c) return null; const r = c.getBoundingClientRect(), l = document.getElementById('lang').getBoundingClientRect(); const s = document.querySelector('.header-subtitle'); const cs = getComputedStyle(c);
      return { inHeader: !!c.closest('.header-info'), cls: c.className, pos: cs.position, text: c.textContent.trim(), subHidden: getComputedStyle(s).display === 'none', overlapsLang: !(r.right < l.left || r.left > l.right || r.bottom < l.top || r.top > l.bottom), top: Math.round(r.top) }; });
    await ctx.close(); return { o, errs };
  };
  let r = await run(' data-chip-in=".header-info"');
  t('with data-chip-in the chip sits inside the top bar (under the title), not floating', r.o && r.o.inHeader && /eta-in/.test(r.o.cls) && r.o.pos === 'static', JSON.stringify(r.o));
  t('... it still says the city, and takes the line of "Professional Delivery"', /Hawassa/.test(r.o.text) && r.o.subHidden, JSON.stringify(r.o));
  t('... and it no longer covers the language button', r.o.overlapsLang === false, JSON.stringify(r.o));
  r = await run('');
  t('without it nothing changes: the chip floats at the top centre as before', r.o && !r.o.inHeader && r.o.pos === 'fixed' && !r.o.subHidden, JSON.stringify(r.o));
  r = await run(' data-chip-in=".no-such-bar"');
  t('a bar that is not on the page: the chip stays floating (never lost)', r.o && !r.o.inHeader && r.o.pos === 'fixed', JSON.stringify(r.o));
  r = await run(' data-chip="off" data-chip-in=".header-info"');
  t('data-chip="off" still means no chip at all', r.o === null);
  // the chooser sheet next to a page that colours every h3 / p dark with !important (Courier does)
  {
    const ctx = await b.newContext({ viewport: { width: 360, height: 700 } }); const p = await ctx.newPage();
    await ctx.addInitScript((d) => { localStorage.setItem('etel_area_list_v1', JSON.stringify({ t: Date.now(), areas: d.areas, enforced: true })); localStorage.setItem('etel_area_v1', JSON.stringify({ code: 'hawassa', manual: true, t: Date.now() })); }, AREAS.data);
    await p.route('http://etel.test/delivery/api/**', r => r.fulfill({ contentType: 'application/json', body: JSON.stringify(AREAS) }));
    await p.route('http://etel.test/', r => r.fulfill({ contentType: 'text/html', body: page('').replace('</head>', '<style>h3,p{color:#1a1200 !important;font-size:30px !important}</style></head>') }));
    await p.goto('http://etel.test/'); await p.waitForTimeout(600); await p.click('.eta-chip'); await p.waitForTimeout(200);
    const c = await p.evaluate(() => { const h = document.querySelector('.eta-sh h3'), q = document.querySelector('.eta-sh p'); return { h: getComputedStyle(h).color, p: getComputedStyle(q).color, fs: getComputedStyle(h).fontSize }; });
    t('the chooser title and hint stay readable (light on the dark sheet) even when the page sets every h3 / p dark', c.h === 'rgb(246, 231, 176)' && c.p === 'rgb(170, 182, 207)' && c.fs === '17px', JSON.stringify(c));
    await ctx.close();
  }
  t('no page errors', r.errs.length === 0, r.errs.join('|'));
  await b.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

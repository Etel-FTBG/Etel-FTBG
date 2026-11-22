// Run: node tests/pagesize.test.js (needs Playwright, as the delivery UI tests). The "Page size" choice: src/hub/pageSize.ts and the real pageScaleJs / FIT_JS in src/hub/webBridge.ts,
// run in a real mobile page (360 px wide, pixel ratio 3, the phone from the Courier screenshots).
const ts = require('typescript'); const fs = require('fs'); const path = require('path'); const Module = require('module'); const { chromium } = require('playwright');
const root = path.join(__dirname, '..');
function load(rel, stubs) {
  const js = ts.transpileModule(fs.readFileSync(path.join(root, rel), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020 } }).outputText;
  const m = new Module(rel); m.paths = Module._nodeModulePaths(__dirname); const req = m.require.bind(m); m.require = (id) => (stubs && id in stubs ? stubs[id] : req(id)); m._compile(js, path.join(root, rel)); return m.exports;
}
const W = load('src/hub/webBridge.ts'); const P = load('src/hub/pageSize.ts', { 'expo-secure-store': { getItemAsync: async () => null, setItemAsync: async () => {} }, react: { useState: () => [], useEffect: () => {} } });
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
t('three choices, in this order', P.PAGE_SIZES.join() === 'normal,smaller,twa');
t('the default is "Like the TWA" (what the owner asked for)', P.DEFAULT_PAGE_SIZE === 'twa' && P.PAGE_SCALE.twa < P.PAGE_SCALE.smaller && P.PAGE_SCALE.normal === 1);
t('a saved word is read back; anything else falls back to the default', P.parsePageSize('normal') === 'normal' && P.parsePageSize('smaller') === 'smaller' && P.parsePageSize('x') === 'twa' && P.parsePageSize(null) === 'twa');
t('tapping the row cycles normal -> smaller -> twa -> normal', P.nextPageSize('normal') === 'smaller' && P.nextPageSize('smaller') === 'twa' && P.nextPageSize('twa') === 'normal');
(async () => {
  const b = await chromium.launch();
  const run = async (scale) => {
    const ctx = await b.newContext({ viewport: { width: 360, height: 800 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true }); const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route('http://etel.test/**', r => r.fulfill({ contentType: 'text/html', body: '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1.0"></head><body><div id="x" style="width:100%;height:20px">hi</div></body></html>' }));
    await p.addInitScript(W.pageScaleJs(scale));           // what the app injects before the page loads
    await p.goto('http://etel.test/'); await p.waitForTimeout(300);
    const before = await p.evaluate(() => ({ iw: window.innerWidth, meta: document.querySelector('meta[name=viewport]').content }));
    await p.evaluate(W.FIT_JS ? () => 0 : () => 0); await p.evaluate(`(function(){${W.FIT_JS}})()`); await p.waitForTimeout(200);
    const after = await p.evaluate(() => ({ iw: window.innerWidth, meta: document.querySelector('meta[name=viewport]').content, vv: window.visualViewport ? window.visualViewport.scale : 0, bodyW: document.getElementById('x').getBoundingClientRect().width }));
    await ctx.close(); return { before, after, errs };
  };
  let r = await run(1);
  t('Normal: the page is laid out at the phone width (360 px), as before', r.before.iw === 360 && r.after.iw === 360 && /initial-scale=1/.test(r.after.meta), JSON.stringify(r));
  r = await run(0.62);
  t('Like the TWA: before the page finishes loading the viewport is already the wide one (about 580 px)', r.before.iw >= 570 && r.before.iw <= 590, JSON.stringify(r.before));
  t('... and it stays that way after the app\'s own fit step', r.after.iw >= 570 && r.after.iw <= 590 && /width=581/.test(r.after.meta) && /initial-scale=0.62/.test(r.after.meta), JSON.stringify(r.after));
  t('... the page is shrunk to fit the phone (scale about 0.62) so nothing runs off the side', r.after.vv > 0.58 && r.after.vv < 0.66, String(r.after.vv));
  r = await run(0.8);
  t('Smaller: about 450 px wide', r.after.iw >= 440 && r.after.iw <= 460, JSON.stringify(r.after));
  t('a nonsense number is held between 0.5 and 1', /__etelPageScale=0.5;/.test(W.pageScaleJs(0.01)) && /__etelPageScale=1;/.test(W.pageScaleJs(7)) && /__etelPageScale=1;/.test(W.pageScaleJs(NaN)));
  t('no page errors', r.errs.length === 0, r.errs.join('|'));
  await b.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

// Run: node tests/fitjs.test.js (needs Playwright, as the delivery UI tests). FIT_JS (src/hub/webBridge.ts, the real file) in a real page: the size note and the zoom correction.
const ts = require('typescript'); const fs = require('fs'); const path = require('path'); const { chromium } = require('playwright');
const root = path.join(__dirname, '..');
const js = ts.transpileModule(fs.readFileSync(path.join(root, 'src/hub/webBridge.ts'), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020 } }).outputText;
const m = { exports: {} }; new Function('module', 'exports', js)(m, m.exports); const FIT_JS = m.exports.FIT_JS;
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
(async () => {
  const b = await chromium.launch();
  const run = async (width, screenW) => {
    const ctx = await b.newContext({ viewport: { width, height: 800 } }); const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message)); const posts = [];
    await p.route('http://etel.test/**', r => { if (r.request().method() === 'POST') { posts.push(JSON.parse(r.request().postData())); return r.fulfill({ status: 204 }); } r.fulfill({ contentType: 'text/html', body: '<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1.0"><body>hi</body>' }); });
    await p.addInitScript((sw) => { Object.defineProperty(window.screen, 'width', { value: sw }); }, screenW);
    await p.goto('http://etel.test/delivery/courier/'); await p.evaluate(FIT_JS); await p.waitForTimeout(3900);
    const meta = await p.evaluate(() => document.querySelector('meta[name=viewport]').content); await ctx.close(); return { meta, posts, errs };
  };
  let r = await run(412, 412);
  t('sizes match: the viewport is only pinned as before (no zoom correction)', /maximum-scale=1/.test(r.meta) && !/^width=412/.test(r.meta), r.meta);
  t('... and one size note is sent', r.posts.length === 1 && r.posts[0].app === 'inapp' && r.posts[0].kind === 'js' && /LAYOUT inner=412 screen=412x/.test(r.posts[0].message) && /fixed=0/.test(r.posts[0].message), JSON.stringify(r.posts));
  t('the note holds only sizes (no token, no account data)', !/token|phone|password|etel_/i.test(JSON.stringify(r.posts)));
  r = await run(255, 412);
  t('the page is laid out much narrower than the screen: it is laid out at the screen width', /^width=412/.test(r.meta), r.meta);
  t('... and the note says fixed=1 with both sizes', r.posts.length === 1 && /inner=255 screen=412x/.test(r.posts[0].message) && /fixed=1/.test(r.posts[0].message) && r.posts[0].source === 'w255-s412', JSON.stringify(r.posts));
  r = await run(390, 412);
  t('a small difference (under 10%) is left alone', !/^width=412/.test(r.meta), r.meta);
  t('no page errors', r.errs.length === 0, r.errs.join('|'));
  await b.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

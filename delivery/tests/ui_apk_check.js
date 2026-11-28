// APK checker page (apk-check.html): tells a good APK from the usual causes of "problem parsing the package". Real page and script, fake zip files.   node delivery/tests/ui_apk_check.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path'); const os = require('os'); const { execFileSync } = require('child_process');
const ROOT = path.join(__dirname, '..'); let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const FIX = fs.mkdtempSync(path.join(os.tmpdir(), 'apkfix-')); execFileSync('python3', [path.join(__dirname, 'apk_fixtures.py'), FIX]);
const types = { '.html': 'text/html; charset=utf-8', '.js': 'application/javascript; charset=utf-8' };
const srv = http.createServer((q, r) => { const u = decodeURIComponent(q.url.split('?')[0]); fs.readFile(path.join(ROOT, u), (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', types[path.extname(u)] || 'text/plain'); r.end(d); } }); });
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  const ctx = await b.newContext({ serviceWorkers: 'block' }); const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
  let upload = 0; await p.route('**/*', r => { if (r.request().method() === 'POST') upload++; r.continue(); });
  const run = async (f) => { await p.goto(HOST + '/apk-check.html'); await p.setInputFiles('#pick', path.join(FIX, f)); await p.waitForSelector('#out .r.ok, #out .r.bad', { timeout: 5000 }); await p.waitForTimeout(150); return p.innerText('#out'); };
  let o = await run('good.apk');
  t('1 a good APK: shows it is an APK, signed, app code, both phone types, no PROBLEM', /It is an APK/.test(o) && /It is signed/.test(o) && /App code found/.test(o) && /arm64-v8a, armeabi-v7a/.test(o) && !/PROBLEM/.test(o), o);
  t('1 the byte size is shown to compare with the built file', /\(\d+ bytes\)/.test(o));
  o = await run('unsigned.apk'); t('2 an unsigned APK says NOT signed', /PROBLEM\s+It is NOT signed/.test(o), o);
  o = await run('bundle.aab'); t('3 an App Bundle says it is not installable', /PROBLEM\s+This is an App Bundle/.test(o) && /PREVIEW profile/.test(o), o);
  o = await run('cut.apk'); t('4 a cut-off file says incomplete / damaged', /PROBLEM\s+The file is (cut off|damaged)/.test(o), o);
  o = await run('page.apk'); t('5 a web page saved as .apk says it is a web page', /not an APK file/.test(o) && /web page/.test(o), o);
  o = await run('arm32only.apk'); t('6 only 32-bit code: says 64-bit phones are missing', /PROBLEM\s+No 64-bit/.test(o) || /Only 64-bit/.test(o) || /No 64-bit/.test(o), o);
  t('7 nothing is uploaded (no POST request)', upload === 0, upload);
  t('8 no page errors', errs.length === 0, errs.join('|'));
  await b.close(); srv.close(); fs.rmSync(FIX, { recursive: true, force: true });
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

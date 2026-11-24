// The early error report (js/core.js, shown in the Diagnostic panel) names the file, the line, the COLUMN and the first call-stack lines (function names),
// so a screenshot says where an error really comes from. Real admin.html with one deliberate error injected after core.js. Mocked API.
// Needs Playwright (node).   node delivery/tests/ui_early_error_detail.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
const srv = http.createServer((q, r) => { let p = path.join(ROOT, decodeURIComponent(q.url.split('?')[0])); if (!p.startsWith(ROOT) || !fs.existsSync(p) || fs.statSync(p).isDirectory()) { r.statusCode = 404; return r.end(); } r.setHeader('Content-Type', MIME[path.extname(p)] || 'application/octet-stream'); r.end(fs.readFileSync(p)); });
(async () => {
  await new Promise(r => srv.listen(0, r)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  await ctx.addInitScript(() => {
    window.ETEL_CAPS = ['*']; try { localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_user', JSON.stringify({ id: 1, name: 'Owner', role: 'admin' })); } catch (e) {}
    document.addEventListener('DOMContentLoaded', () => { window.__snap = (window.__etelEarlyErrors || []).slice(); });   // the buffer is emptied into the panel at load: keep a copy
  });
  const p = await ctx.newPage();
  await p.route('**/api/boot.php**', r => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: {} }) }));
  await p.route('**/admin.html', r => { let h = fs.readFileSync(path.join(ROOT, 'delivery', 'admin.html'), 'utf8'); h = h.replace(/(<script src="js\/core\.js[^"]*"><\/script>)/, '$1<script>function boomInPanelCode(){ document.getElementById("no-such-element").style.display = "none"; }\nboomInPanelCode();</script>'); r.fulfill({ contentType: 'text/html', body: h }); });
  await p.goto(HOST + '/delivery/admin.html', { waitUntil: 'load' }); await p.waitForTimeout(1500);
  const snap = await p.evaluate(() => window.__snap || []);
  const line = snap.filter(x => /no-such|reading 'style'|Cannot read/.test(x))[0] || '';
  t('the early error is recorded', !!line, JSON.stringify(snap).slice(0, 300));
  t('it keeps the message', /Cannot read properties of null \(reading 'style'\)/.test(line), line);
  t('it names the file, the line AND the column (three numbers)', /\(admin\.html:\d+:\d+\)/.test(line), line);
  t('it names the function it came from (first stack lines)', /boomInPanelCode/.test(line), line);
  t('the stack part is short (two frames at most)', (line.match(/ \| /g) || []).length <= 1 && line.length < 420, line.length + ' ' + line);
  await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/early_error_detail.png' });
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

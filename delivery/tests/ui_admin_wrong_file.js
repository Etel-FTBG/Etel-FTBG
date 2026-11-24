// Admin (admin.html): a wrong js/admin.js on the server (the old EtelGroup admin.js, which fails with "Cannot read properties of null (reading 'style')" in
// showLogin) is announced by a red bar; the right file shows nothing and has no such error. Real page; the wrong file is a copy of the old one.
// Needs Playwright (node).   node delivery/tests/ui_admin_wrong_file.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'application/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });
const WRONG = fs.readFileSync(path.join(__dirname, 'fixtures', 'etelgroup_old_admin.js.txt'), 'utf8');
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  for (const c of [{ n: 'right file', wrong: false }, { n: 'wrong file (old EtelGroup admin.js)', wrong: true }]) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    await ctx.addInitScript(() => { window.ETEL_CAPS = ['*']; try { localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_user', JSON.stringify({ id: 1, name: 'Owner', role: 'admin' })); } catch (e) {} });
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => { if (!/admin-home\.js/.test(e.stack || '')) errs.push(e.message + ' @ ' + ((e.stack || '').split('\n')[1] || '').trim()); });
    await p.route(/cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net|maps\.googleapis\.com|fonts\.(googleapis|gstatic)\.com/, r => r.abort());
    if (c.wrong) await p.route(/\/js\/admin\.js/, r => r.fulfill({ contentType: 'application/javascript', body: WRONG }));
    await p.route(/boot\.php/, r => { let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} const a = d.action; let data = {};
      if (a === 'admin_get_my_capabilities') data = { capabilities: ['*'], role: 'admin' };
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) }); });
    await p.goto(HOST + '/delivery/admin.html', { waitUntil: 'load' }); await p.waitForTimeout(2600);
    const bar = await p.evaluate(() => { const e = document.getElementById('etel-wrong-file-bar'); return e ? e.innerText : ''; });
    const styleErr = errs.filter(e => /reading 'style'/.test(e));
    if (!c.wrong) {
      t(c.n + ': no red bar', bar === '', bar);
      t(c.n + ': no "reading style" error from admin.js', styleErr.length === 0, errs.join('|'));
    } else {
      t(c.n + ': the old file reproduces the error from the diagnostic (showLogin, reading style)', styleErr.length >= 1 && /showLogin/.test(errs.join('|')), errs.join('|').slice(0, 300));
      t(c.n + ': a red bar says it is the wrong file and what to do', /Wrong file on this server/.test(bar) && /delivery\/js\/admin\.js/.test(bar) && /Ctrl\+F5/.test(bar), bar);
      await p.locator('#etel-wrong-file-bar button').click(); await p.waitForTimeout(150);
      t(c.n + ': the bar can be closed', (await p.locator('#etel-wrong-file-bar').count()) === 0);
      await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/admin_wrong_file.png' });
    }
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

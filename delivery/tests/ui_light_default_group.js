// EtelID (the WordPress plugin), the EtelGroup site and the investors pages start LIGHT when nobody has chosen a theme yet, on a light phone and on a
// dark phone, and keep a saved "dark" choice. One shared key ("theme") is used by all of them. Real pages and scripts from the repo; the PHP
// templates of EtelID are tested through their real theme scripts (and the real inline snippet of the admin console template).
//   NODE_PATH=/opt/node22/lib/node_modules node delivery/tests/ui_light_default_group.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.webp': 'image/webp' };
// the Group site pages say "EtelGroup/js/..." from the site root; in the repo that folder is /EtelGroup/js/
const srv = http.createServer((q, r) => { const u = decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '').replace('/EtelGroup/EtelGroup/', '/EtelGroup/'); fs.readFile(path.join(ROOT, u), (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(u)] || 'application/octet-stream'); r.end(d); } }); });

const adminConsoleSnippet = (() => { const s = fs.readFileSync(path.join(ROOT, 'EtelId/templates/admin-console.php'), 'utf8'); const m = /\(function\(\)\{var t=null;try\{t=localStorage\.getItem\('theme'\);\}catch\(e\)\{\}[^\n]*?\}\)\(\);/.exec(s); return m ? m[0] : ''; })();
const tiny = (body) => '<!doctype html><html><head></head><body><button class="p-theme-btn"></button>' + body + '</body></html>';

const SYSTEMS = [
  // real pages
  { name: 'Group master page', url: '/EtelGroup/index.html', read: 'data-theme' },
  { name: 'Group admin', url: '/EtelGroup/admin.html', read: 'data-theme' },
  { name: 'investors admin', url: '/EtelGroup/investors/admin.html', read: 'data-theme' },
  { name: 'investors portal', url: '/EtelGroup/investors/portal.html', read: 'data-theme' },
  { name: 'investors pitch', url: '/EtelGroup/investors/pitch.html', read: 'data-theme' },
  { name: 'investors deal sheet', url: '/EtelGroup/investors/deal.html', read: 'data-theme' },
  // EtelID: the real scripts on a tiny page
  { name: 'EtelID sign-in/NFC script (no data-default)', page: tiny('<script src="/EtelId/public/js/etelid-theme.js"></script>'), read: 'data-eid-theme' },
  { name: 'EtelID sign-in script (data-default light)', page: tiny('<script src="/EtelId/public/js/etelid-theme.js" data-default="light"></script>'), read: 'data-eid-theme' },
  { name: 'EtelID portal script', page: tiny('<script src="/EtelId/public/js/etelid-portal-pro.js"></script>'), read: 'data-eid-theme' },
  { name: 'EtelID WordPress admin script', page: tiny('<script src="/EtelId/admin/js/etelid-admin-pro.js"></script>'), read: 'data-eid-theme' },
  { name: 'EtelID admin console template', page: tiny('<script>' + adminConsoleSnippet + '</script>'), read: 'data-eid-theme' }
];

(async () => {
  t('the admin console template snippet was found in the real file', adminConsoleSnippet.length > 50, adminConsoleSnippet.slice(0, 60));
  const nfc = fs.readFileSync(path.join(ROOT, 'EtelId/templates/nfc-profile.php'), 'utf8');
  const nfcLine = nfc.split('\n').filter(l => /etelid-theme\.js/.test(l)).join(' ');
  t('the NFC card page asks for the light look by default', /data-default="light"/.test(nfcLine) && !/data-default="dark"/.test(nfcLine), nfcLine.slice(-80));
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  const readAttr = n => document.documentElement.getAttribute(n);
  async function open(s, scheme, stored) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block', colorScheme: scheme });
    await ctx.addInitScript(v => { try { if (v) localStorage.setItem('theme', v); } catch (e) {} }, stored || '');
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route(/maps\.googleapis|gstatic|cdnjs|jsdelivr|fonts\.|\.php/, r => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: {} }) }));
    if (s.page) { await p.route(HOST + '/__t.html', r => r.fulfill({ contentType: 'text/html', body: s.page })); await p.goto(HOST + '/__t.html', { waitUntil: 'load' }); }
    else await p.goto(HOST + s.url, { waitUntil: 'load' });
    await p.waitForTimeout(1500);
    return { ctx, p, errs };
  }
  for (const s of SYSTEMS) {
    for (const scheme of ['light', 'dark']) {
      const { ctx, p } = await open(s, scheme, '');
      t(s.name + ': starts light on a ' + scheme + ' phone', (await p.evaluate(readAttr, s.read)) === 'light', await p.evaluate(readAttr, s.read));
      await ctx.close();
    }
    const { ctx, p } = await open(s, 'light', 'dark');
    t(s.name + ': a saved dark choice is kept', (await p.evaluate(readAttr, s.read)) === 'dark', await p.evaluate(readAttr, s.read));
    await ctx.close();
  }
  // the Group pages that also use body.dark-theme must agree with the attribute
  for (const u of ['/EtelGroup/index.html', '/EtelGroup/investors/admin.html', '/EtelGroup/investors/portal.html']) {
    const { ctx, p } = await open({ url: u }, 'dark', '');
    t(u + ': body is not dark-theme by default', !(await p.evaluate(() => document.body.classList.contains('dark-theme'))));
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

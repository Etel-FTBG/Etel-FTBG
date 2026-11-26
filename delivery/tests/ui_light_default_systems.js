// EtelPay, EtelTaxi, EtelAi, EtelGebeya and EtelMadeya start LIGHT when nobody has chosen a theme yet, on a light phone and on a dark phone,
// and keep a saved "dark" choice. Real pages and scripts from the repo; the pages are served statically (the PHP-only systems use a tiny page that loads
// their real theme script).   NODE_PATH=/opt/node22/lib/node_modules node delivery/tests/ui_light_default_systems.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.webp': 'image/webp' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });

const attr = a => a;   // the name of the <html> attribute the system's theme script sets
const readAttr = n => document.documentElement.getAttribute(n);
const SYSTEMS = [
  { name: 'EtelPay login', url: '/Etel.Pay/wallet/login.html', key: 'etelpay_theme', read: attr('data-theme') },
  { name: 'EtelPay offline page', url: '/Etel.Pay/wallet/offline.html', key: 'etelpay_theme', read: attr('data-theme') },
  { name: 'EtelTaxi start page', url: '/EtelTaxi/index.html', key: 'etaxi_theme', read: attr('data-theme') },
  { name: 'EtelTaxi driver', url: '/EtelTaxi/driver.html', key: 'etaxi_theme', read: attr('data-theme') },
  { name: 'EtelTaxi admin', url: '/EtelTaxi/admin.html', key: 'etaxi_theme', read: attr('data-theme') },
  { name: 'EtelAi sign-in', url: '/EtelAi/login.html', key: 'etelai_theme', read: attr('data-theme') },
  { name: 'EtelAi customer', url: '/EtelAi/customer.html', key: 'etelai_theme', read: attr('data-theme') },
  // PHP systems: a tiny page that loads their real theme script
  { name: 'EtelGebeya theme script', page: '<!doctype html><html><head><script src="/EtelGebeya/public/assets/js/etel-theme.js"></script></head><body></body></html>', key: 'egebya_theme', read: attr('data-theme') },
  { name: 'EtelMadeya theme script', page: '<!doctype html><html><head></head><body><button class="mp-theme"></button><script src="/EtelMadeya/assets/js/madeya-pro-r2.js"></script></body></html>', key: 'theme', read: attr('data-mp-theme') }
];

(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  async function open(s, scheme, stored) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block', colorScheme: scheme });
    await ctx.addInitScript(([k, v]) => { try { if (v) localStorage.setItem(k, v); } catch (e) {} }, [s.key, stored || '']);
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route(/maps\.googleapis|gstatic|cdnjs|jsdelivr|fonts\.|\.php/, r => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: {} }) }));
    if (s.page) { await p.route(HOST + '/__t.html', r => r.fulfill({ contentType: 'text/html', body: s.page })); await p.goto(HOST + '/__t.html', { waitUntil: 'load' }); }
    else await p.goto(HOST + s.url, { waitUntil: 'load' });
    await p.waitForTimeout(1200);
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
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

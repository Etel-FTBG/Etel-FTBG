// Delivery apps start LIGHT (not dark) when nobody has chosen a theme yet, whatever the phone's own light/dark setting is, and a saved "dark" choice is kept.
// Real pages and files, fake API.   NODE_PATH=/opt/node22/lib/node_modules node delivery/tests/ui_light_default.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.webp': 'image/webp' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });

// what each app says about its theme, once it has started
const APPS = [
  { name: 'customer app', url: '/delivery/customer.html', role: 'customer', light: () => !document.body.classList.contains('dark-theme'), dark: () => document.body.classList.contains('dark-theme'), chosen: { theme: 'dark' } },
  { name: 'ride app', url: '/delivery/ride/index.html', role: 'customer', light: () => document.documentElement.getAttribute('data-etel-theme') === 'light', dark: () => document.documentElement.getAttribute('data-etel-theme') === 'dark', chosen: { etel_ride_theme: 'dark' } },
  { name: 'driver app', url: '/delivery/driver/index.html', role: 'driver', light: () => document.body.classList.contains('light-mode'), dark: () => !document.body.classList.contains('light-mode') && document.body.classList.contains('dark-page'), chosen: { etel_driver_theme: 'dark' } },
  { name: 'agent app', url: '/delivery/agent/index.html', role: 'customer', light: () => document.documentElement.getAttribute('data-theme') === 'light', dark: () => document.documentElement.getAttribute('data-theme') === 'dark', chosen: { theme: 'dark' } },
  { name: 'help page', url: '/delivery/help/index.html', role: 'customer', light: () => document.documentElement.getAttribute('data-theme') === 'light', dark: () => document.documentElement.getAttribute('data-theme') === 'dark', chosen: { theme: 'dark' } },
  { name: 'status page', url: '/delivery/status.html', role: '', light: 'bg', chosen: null },
  { name: 'Shield admin page', url: '/delivery/admin-shield.html', role: '', light: 'bg', chosen: null }
];
const bgLum = () => { const parse = s => { const m = s.match(/[\d.]+/g) || []; return { r: +m[0] || 0, g: +m[1] || 0, b: +m[2] || 0, a: m.length > 3 ? +m[3] : 1 }; };
  let el = document.elementFromPoint(195, 300) || document.body, c = null; for (; el; el = el.parentElement) { const x = parse(getComputedStyle(el).backgroundColor); if (x.a > 0.5) { c = x; break; } }
  if (!c) c = { r: 255, g: 255, b: 255 }; return 0.2126 * c.r + 0.7152 * c.g + 0.0722 * c.b; };

(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  async function open(app, scheme, stored) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block', colorScheme: scheme });
    await ctx.addInitScript(([role, stored]) => { try { if (role) { localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 9, name: 'Test', phone: '0911000001', role })); } for (const k in stored) localStorage.setItem(k, stored[k]); } catch (e) {} }, [app.role, stored || {}]);
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route(/maps\.googleapis|gstatic|cdnjs|jsdelivr|fonts\./, r => r.abort());
    await p.route(/boot\.php/, r => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: {} }) }));
    await p.goto(HOST + app.url, { waitUntil: 'load' }); await p.waitForTimeout(2200);
    return { ctx, p, errs };
  }
  for (const app of APPS) {
    for (const scheme of ['light', 'dark']) {
      const { ctx, p } = await open(app, scheme, null);
      if (app.light === 'bg') t(app.name + ': starts light on a ' + scheme + ' phone', (await p.evaluate(bgLum)) > 200, await p.evaluate(bgLum));
      else t(app.name + ': starts light on a ' + scheme + ' phone', await p.evaluate(app.light));
      await ctx.close();
    }
    if (app.chosen) {
      const { ctx, p } = await open(app, 'light', app.chosen);
      t(app.name + ': a saved dark choice is kept', await p.evaluate(app.dark));
      await ctx.close();
    }
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

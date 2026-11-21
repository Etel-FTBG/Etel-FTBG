// Driver app > Street Hail: the "Etel Code ...: Name. Is this the right person?" line is readable (contrast), in the dark and the light theme, and the box sits above the
// floating buttons. Needs Playwright (node). No server: the API is a mock.
//   (cd <repo root> && python3 -m http.server 8098 &)   then   node delivery/tests/ui_hail_contrast.js
const { chromium } = require('playwright');
const HOST = process.env.ETEL_HOST || 'http://127.0.0.1:8098'; const SHOTS = process.env.ETEL_SHOTS || '/tmp';
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const lum = (rgb) => { const [r, g, b] = rgb.match(/\d+(\.\d+)?/g).slice(0, 3).map(Number).map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
(async () => {
  const b = await chromium.launch();
  for (const th of ['dark', 'light']) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    await ctx.addInitScript((th) => { try { localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_user', JSON.stringify({ id: 1, name: 'Dagi', role: 'driver', phone: '0911000001' })); localStorage.setItem('theme', th); } catch (e) {} }, th);
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route('**/api/boot.php**', r => { let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} let data = {}; if (d.action === 'etel_code_whois') data = d.code === 'E2121' ? { found: true, name: 'Emebet Besha mandefro' } : { found: false }; r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) }); });
    await p.goto(HOST + '/delivery/driver/index.html', { waitUntil: 'load' }); await p.waitForTimeout(2500);
    await p.evaluate(() => window.openHailModal()); await p.waitForTimeout(400);
    const read = () => p.evaluate(() => { const n = document.getElementById('hail-phone-ecname'); if (!n || n.style.display === 'none') return null; const c = getComputedStyle(n); return { text: n.textContent, color: c.color, bg: c.backgroundColor }; });
    await p.fill('#hail-phone', 'E2121'); await p.waitForFunction(() => { const n = document.getElementById('hail-phone-ecname'); return n && /right person/.test(n.textContent); }, null, { timeout: 8000 });
    let r = await read();
    t(th + ': found: dark text on a light box, contrast 7:1 or better', r && ratio(r.color, r.bg) >= 7, JSON.stringify(r) + ' ' + (r ? ratio(r.color, r.bg).toFixed(2) : ''));
    t(th + ': found: the box is the green "ok" box and says the name', r && /Emebet Besha mandefro/.test(r.text) && r.bg === 'rgb(223, 245, 227)');
    await p.screenshot({ path: SHOTS + '/etel-hail-contrast-' + th + '.png' });
    await p.fill('#hail-phone', 'E9999'); await p.waitForFunction(() => { const n = document.getElementById('hail-phone-ecname'); return n && /not|No /.test(n.textContent) || (n && n.textContent && !/right person|Checking/.test(n.textContent)); }, null, { timeout: 8000 }).catch(() => {});
    r = await read();
    t(th + ': not found: readable (contrast 7:1 or better)', r && ratio(r.color, r.bg) >= 7, JSON.stringify(r));
    await p.fill('#hail-phone', 'E1234'); const w = await read();
    t(th + ': "checking" state: readable too', w && ratio(w.color, w.bg) >= 4.5, JSON.stringify(w));
    const z = await p.evaluate(() => { const m = document.getElementById('hail-modal'); const fab = document.getElementById('etel-help-fab'); return { modal: parseInt(getComputedStyle(m).zIndex, 10), fab: fab ? parseInt(getComputedStyle(fab).zIndex, 10) : 0 }; });
    t(th + ': the Street Hail box is above the help bubble and the Hot areas chip (9400)', z.modal > 9400 && z.modal > z.fab, JSON.stringify(z));
    t(th + ': no page errors', errs.length === 0, errs.join('|'));
    await ctx.close();
  }
  await b.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

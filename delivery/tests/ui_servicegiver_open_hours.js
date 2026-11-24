// Shop app (servicegiver.html, js/servicegiver.js): the header says Open / Closed by the OPENING HOURS when the shop has them, and tapping it explains that
// instead of flipping a switch that does nothing. A shop with no hours keeps the online / offline switch. Real page, mocked API.
// Needs Playwright (node).   node delivery/tests/ui_servicegiver_open_hours.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml', '.webp': 'image/webp' };
const srv = http.createServer((q, r) => { let p = path.join(ROOT, decodeURIComponent(q.url.split('?')[0])); if (!p.startsWith(ROOT) || !fs.existsSync(p) || fs.statSync(p).isDirectory()) { r.statusCode = 404; return r.end(); } r.setHeader('Content-Type', MIME[path.extname(p)] || 'application/octet-stream'); r.end(fs.readFileSync(p)); });
const SG = (extra) => Object.assign({ id: 14, business_name: 'Admin test shop', service_giver_code: 'SG-9F6373', service_type: 'restaurant', zone: 'Mehal Ketema', status: 'verified', is_online: 0, opens_at: null, closes_at: null, open_now: 0, hours_mode: 0, average_rating: 0, total_deliveries: 0, total_earnings: 0 }, extra);
(async () => {
  await new Promise(r => srv.listen(0, r)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  const CASES = [
    { n: 'hours, open now, switch off', sg: SG({ opens_at: '06:00:00', closes_at: '22:00:00', open_now: 1, hours_mode: 1, is_online: 0 }), want: /Open now . until 22:00/, tapCalls: 0 },
    { n: 'hours, closed now, switch on', sg: SG({ opens_at: '13:00:00', closes_at: '10:00:00', open_now: 0, hours_mode: 1, is_online: 1 }), want: /Closed . opens at 13:00/, tapCalls: 0 },
    { n: 'no hours, switch off', sg: SG({ is_online: 0 }), want: /Offline/, tapCalls: 1 },
    { n: 'no hours, switch on', sg: SG({ is_online: 1 }), want: /Online/, tapCalls: 1 },
    { n: 'older server (no hours fields)', sg: (() => { const s = SG({ is_online: 1 }); delete s.open_now; delete s.hours_mode; return s; })(), want: /Online/, tapCalls: 1 }];
  for (const mode of [{ n: 'phone', w: 390, h: 844 }, { n: 'desktop', w: 1280, h: 860 }]) {
    for (const c of CASES) {
      const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
      await ctx.addInitScript(() => { try { localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_user', JSON.stringify({ id: 5, name: 'Owner', phone: '0911000001', role: 'service_giver' })); localStorage.setItem('etel_lang', 'en'); } catch (e) {} });
      const p = await ctx.newPage(); const errs = []; const online = []; const seen = [];
      p.on('pageerror', e => errs.push(e.message + ' @ ' + ((e.stack || '').split('\n')[1] || '').trim()));
      p.on('request', q => { try { const d = JSON.parse(q.postData() || '{}'); if (d.action) seen.push(d.action); } catch (e) {} });
      await p.route(/boot\.php|\/api\//, r => {
        let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
        const a = d.action || ((r.request().url().match(/action=([a-z_]+)/) || [])[1]);
        let data = {};
        if (a === 'sg_profile') data = { user: { id: 5, name: 'Owner', phone: '0911000001' }, service_giver: c.sg, staff_role: 'owner', role_name: 'Owner', capabilities: ['*'] };
        else if (a === 'sg_set_online') { online.push(d); data = { is_online: d.is_online, message: 'ok' }; }
        else if (a === 'sg_orders') data = { orders: [] };
        else if (a === 'order_chat_inbox') data = { chats: [], unread_total: 0 };
        r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
      });
      await p.goto(HOST + '/servicegiver.html', { waitUntil: 'load' }); await p.waitForTimeout(2200);
      const label = (await p.evaluate(() => { const e = document.getElementById('profile-status'); return e ? e.textContent : ''; })).trim();
      const n = mode.n + ' / ' + c.n;
      t(n + ': the header says ' + c.want, c.want.test(label), label);
      await p.evaluate(() => { const e = document.getElementById('profile-status'); if (e) e.click(); }); await p.waitForTimeout(700);
      t(n + ': tapping it ' + (c.tapCalls ? 'flips the switch (as before)' : 'does not flip a switch'), online.length === c.tapCalls, JSON.stringify(online));
      if (!c.tapCalls) {
        const toast = await p.evaluate(() => document.body.innerText);
        t(n + ': ... it says the opening hours decide', /Open and closed follow your opening hours/.test(toast), toast.slice(0, 120));
      }
      if (c.n === 'hours, open now, switch off' && mode.n === 'phone') await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/servicegiver_open_hours.png' });
      t(n + ': no page errors', errs.length === 0, errs.join('|')); await ctx.close();
    }
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

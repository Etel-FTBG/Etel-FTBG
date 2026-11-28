// Login page: Customer, Ride and Driver share the transport logo, so each gets a small gold ROLE MARK (bag, person, steering wheel); Courier and Service keep their own logos and get no mark.
// Real login page, real files (a local server), nothing from the network.   node delivery/tests/ui_login_role_marks.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp' };
const srv = http.createServer((q, r) => { const u = decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, ''); fs.readFile(path.join(ROOT, u), (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('content-type', MIME[path.extname(u)] || 'application/octet-stream'); r.end(d); } }); });
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  for (const vw of [390, 320]) {
    const ctx = await b.newContext({ viewport: { width: vw, height: 800 }, serviceWorkers: 'block' }); const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route(/maps\.googleapis|gstatic|cdnjs|jsdelivr|fonts\.|etel-ai-widget|etel-help-fab/, r => r.abort()); await p.route(/\.php/, r => r.fulfill({ contentType: 'application/json', body: '{"success":true,"data":{}}' }));
    await p.goto(HOST + '/delivery/login.html', { waitUntil: 'load' }); await p.waitForTimeout(1000); await p.getByText('Choose app').first().click(); await p.waitForTimeout(700);
    const tabs = await p.evaluate(() => [].slice.call(document.querySelectorAll('.del-rtab')).map(btn => {
      const m = btn.querySelector('.del-rtab-mark'), i = btn.querySelector('.del-rtab-icon img'); const br = btn.getBoundingClientRect();
      const mr = m ? m.getBoundingClientRect() : null, cs = m ? getComputedStyle(m) : null;
      return { txt: btn.innerText.trim(), logo: i ? i.getAttribute('src').split('?')[0].split('/').pop() : '', mark: m ? m.getAttribute('data-mark') : null, svg: m ? m.querySelector('svg').innerHTML : '',
        w: mr ? mr.width : 0, h: mr ? mr.height : 0, inside: mr ? (mr.left >= br.left - 8 && mr.right <= br.right + 8 && mr.top >= br.top && mr.bottom <= br.bottom + 2) : false, bg: cs ? cs.backgroundColor : '', aria: m ? m.getAttribute('aria-hidden') : '', vis: cs ? cs.display !== 'none' && cs.visibility !== 'hidden' : false };
    }));
    const by = {}; tabs.forEach(x => by[x.txt] = x);
    const tag = vw + 'px: ';
    t(tag + 'five role buttons', tabs.length === 5, JSON.stringify(tabs.map(x => x.txt)));
    for (const [name, key] of [['Customer', 'customer'], ['Ride', 'ride'], ['Driver', 'driver']]) {
      const x = by[name] || {};
      t(tag + name + ' has its role mark (' + key + ')', x.mark === key && x.vis, JSON.stringify([x.mark, x.vis]));
      t(tag + name + ' mark is a 14 to 20 px gold dot, hidden from screen readers, inside the button', x.w >= 14 && x.w <= 20 && x.h >= 14 && x.h <= 20 && x.bg === 'rgb(212, 175, 55)' && x.aria === 'true' && x.inside, JSON.stringify([x.w, x.h, x.bg, x.aria, x.inside]));
      t(tag + name + ' still shows the transport logo picture', /^tab-(customer|ride|driver)\.png$/.test(x.logo), x.logo);
    }
    t(tag + 'the three marks are three different drawings', new Set([by.Customer.svg, by.Ride.svg, by.Driver.svg]).size === 3 && by.Customer.svg.length > 30);
    t(tag + 'Courier and Service have no mark (their own logos already differ)', by.Courier.mark === null && by.Service.mark === null, JSON.stringify([by.Courier.mark, by.Service.mark]));
    t(tag + 'no page errors', errs.length === 0, errs.join('|'));
    await ctx.close();
  }
  await b.close(); srv.close(); console.log(pass + ' passed, ' + fails.length + ' failed'); fails.forEach(f => console.log(' FAIL ' + f)); process.exit(fails.length ? 1 : 0);
})();

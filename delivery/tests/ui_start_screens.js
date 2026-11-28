// Own iOS start screens: etel-start-screens.js puts the ROLE's start screens in the page head (13 links) before core.js would add the Delivery ones.
// Real file, fake page address per role. Needs Playwright (node).   node delivery/tests/ui_start_screens.js
const { chromium } = require('playwright'); const path = require('path'); const fs = require('fs');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const JS = fs.readFileSync(path.join(__dirname, '..', 'js', 'etel-start-screens.js'), 'utf8');
(async () => {
  const b = await chromium.launch(); const ctx = await b.newContext({ serviceWorkers: 'block' });
  const cases = [['/delivery/courier/courier-index.html', 'courier'], ['/delivery/servicegiver.html', 'service'], ['/delivery/admin.html', 'admin'], ['/delivery/agent/index.html', 'agent'], ['/delivery/customer.html', null], ['/delivery/ride/index.html', null]];
  for (const [url, role] of cases) {
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route('http://etel.test/**', r => r.request().url().includes('/etel-start-screens.js') ? r.fulfill({ contentType: 'application/javascript', body: JS }) : r.fulfill({ contentType: 'text/html', body: '<!doctype html><html><head><script src="/delivery/js/etel-start-screens.js?v=1"></script></head><body></body></html>' }));
    await p.goto('http://etel.test' + url);
    const links = await p.evaluate(() => [...document.querySelectorAll('link[rel="apple-touch-startup-image"]')].map(l => ({ h: l.getAttribute('href'), m: l.media })));
    if (role) {
      t(role + ': 13 start screens', links.length === 13, String(links.length));
      t(role + ': files are the role\'s own jpg in /delivery/icons/', links.every(l => /^http:\/\/etel\.test\/delivery\/icons\/[a-z]+-splash-\d+x\d+\.jpg/.test(l.h) && l.h.indexOf('/' + role + '-splash-') > 0), links[0] && links[0].h);
      t(role + ': the media rule matches the file size', links.every(l => { const m = /(\d+)x(\d+)\.jpg/.exec(l.h); return l.m.indexOf('device-width: ' + m[1] + 'px') > -1 && l.m.indexOf('device-height: ' + m[2] + 'px') > -1; }));
      t(role + ': every file really exists', links.every(l => fs.existsSync(path.join(__dirname, '..', 'icons', l.h.split('/').pop().split('?')[0]))));
    } else t((url) + ': no start screens added (core.js keeps Delivery / Ride)', links.length === 0, String(links.length));
    t(url + ': no page errors', errs.length === 0, errs.join('|'));
    await p.close();
  }
  await b.close(); console.log(pass + ' passed, ' + fails.length + ' failed'); fails.forEach(f => console.log(' FAIL ' + f)); process.exit(fails.length ? 1 : 0);
})();

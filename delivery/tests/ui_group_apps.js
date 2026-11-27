// The Etel Group master page: the Android apps (APK downloads) in the header (button + panel), under the hero buttons, in the footer band and in the mobile menu,
// each app with its own logo; a file that is not uploaded shows "Coming soon", a real one shows its size; English / Amharic; light and dark; phone and desktop.
// Real page and files (EtelGroup/index.html, css/eg-apps.css, js/eg-apps.js), fake APK answers.   node delivery/tests/ui_group_apps.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp' };
// the master page says "EtelGroup/..." from the site root; the repo folder is /EtelGroup/ (same trick as ui_light_default_group.js)
const srv = http.createServer((q, r) => { const u = decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '').replace('/EtelGroup/EtelGroup/', '/EtelGroup/'); fs.readFile(path.join(ROOT, u), (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(u)] || 'application/octet-stream'); r.end(d); } }); });
const KEYS = ['etel', 'delivery', 'ride', 'driver', 'courier', 'service'];
const FILES = { etel: '%E1%8A%A2E_Etel.apk', delivery: '%E1%8A%A2E_Delivery.apk', ride: '%E1%8A%A2E_Ride.apk', driver: '%E1%8A%A2E_Driver.apk', courier: '%E1%8A%A2E_Courier.apk', service: '%E1%8A%A2E_Service.apk' };
const probe = (sel) => {
  const parse = s => { const m = s.match(/[\d.]+/g) || []; return { r: +m[0] || 0, g: +m[1] || 0, b: +m[2] || 0, a: m.length > 3 ? +m[3] : 1 }; };
  const over = (top, bot) => ({ r: top.r * top.a + bot.r * (1 - top.a), g: top.g * top.a + bot.g * (1 - top.a), b: top.b * top.a + bot.b * (1 - top.a), a: 1 });
  const lum = c => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
  const el = document.querySelector(sel); if (!el) return { missing: true };
  let layers = []; for (let e = el; e; e = e.parentElement) { const c = parse(getComputedStyle(e).backgroundColor); if (c.a > 0) layers.push(c); if (c.a >= 0.999) break; }
  let bg = { r: 20, g: 10, b: 0, a: 1 }; for (let i = layers.length - 1; i >= 0; i--) bg = over(layers[i], bg);
  const fg = over(parse(getComputedStyle(el).color), bg); const L1 = lum(fg), L2 = lum(bg);
  return { ratio: Math.round((Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05) * 10) / 10 };
};
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  for (const mode of [{ n: 'desktop dark', w: 1280, h: 800, theme: 'dark' }, { n: 'desktop light', w: 1280, h: 800, theme: 'light' }, { n: 'wide desktop', w: 1440, h: 800, theme: 'dark', nowrap: true }, { n: 'phone', w: 390, h: 844, theme: 'dark' }]) {
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
    await ctx.addInitScript(th => { try { localStorage.setItem('theme', th); } catch (e) {} }, mode.theme);
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route(/maps\.googleapis|gstatic|cdnjs|jsdelivr|fonts\.|etel-ai-widget|etel-help-fab/, r => r.abort());
    await p.route(/\/delivery\/apk\//, r => { const u = r.request().url(); if (/Service/.test(u)) r.fulfill({ status: 404, body: '' }); else r.fulfill({ status: 200, headers: { 'content-length': '12582912', 'content-type': 'application/vnd.android.package-archive' }, body: '' }); });
    await p.route(/\/delivery\/app\/version\.json/, r => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ versionCode: 12, versionName: '1.8.0' }) }));
    await p.route(/boot\.php|\.php/, r => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: {} }) }));
    await p.goto(HOST + '/EtelGroup/index.html', { waitUntil: 'load' }); await p.waitForTimeout(2500);
    await p.evaluate(th => document.documentElement.setAttribute('data-theme', th), mode.theme);
    const n = mode.n + ': ';
    if (mode.nowrap) t(n + 'the new button does not make the header menu words wrap', await p.evaluate(() => [].slice.call(document.querySelectorAll('.nav-links > li > a')).filter(a => a.getBoundingClientRect().height > 30).length === 0));
    // header button
    const btn = p.locator('#egap-nav-btn');
    t(n + 'the header has the Apps button', (await btn.count()) === 1 && await btn.isVisible());
    t(n + 'the panel starts closed', await p.evaluate(() => !document.getElementById('egap-panel').classList.contains('open')) && (await btn.getAttribute('aria-expanded')) === 'false');
    await btn.click(); await p.waitForTimeout(500);
    t(n + 'tapping it opens the panel', await p.evaluate(() => document.getElementById('egap-panel').classList.contains('open')) && (await btn.getAttribute('aria-expanded')) === 'true');
    const names = await p.evaluate(() => [].slice.call(document.querySelectorAll('#egap-panel .egap-name')).map(x => x.textContent));
    t(n + 'the panel lists all six apps', JSON.stringify(names) === JSON.stringify(['ETEL', 'ETEL Delivery', 'ETEL Ride', 'ETEL Driver', 'ETEL Courier', 'ETEL Service']), JSON.stringify(names));
    // own logos
    const logos = await p.evaluate(() => Promise.all([].slice.call(document.querySelectorAll('#egap-panel .egap-logo')).map(i => new Promise(res => { const f = () => { let al = []; try { const c = document.createElement('canvas'); c.width = i.naturalWidth; c.height = i.naturalHeight; const x = c.getContext('2d'); x.drawImage(i, 0, 0); const w = c.width, h = c.height; al = [x.getImageData(0, 0, 1, 1).data[3], x.getImageData(w - 1, 0, 1, 1).data[3], x.getImageData(0, h - 1, 1, 1).data[3], x.getImageData(w - 1, h - 1, 1, 1).data[3]]; } catch (e) {} res({ src: i.getAttribute('src'), w: i.naturalWidth, al: al }); }; if (i.complete) f(); else { i.onload = f; i.onerror = f; } }))));
    t(n + 'every app shows a real logo (six, all loaded; four different pictures because Delivery, Ride and Driver share one)', logos.length === 6 && logos.every(l => l.w > 0) && new Set(logos.map(l => l.src)).size === 4, JSON.stringify(logos));
    t(n + 'the logos are the transparent ETEL badges: group, transport (Delivery, Ride, Driver), courier, service', ['/EtelGroup/icons/brand/etel-group-master.webp', '/EtelGroup/icons/brand/etel-transport.webp', '/EtelGroup/icons/brand/etel-transport.webp', '/EtelGroup/icons/brand/etel-transport.webp', '/EtelGroup/icons/brand/etel-courier.webp', '/EtelGroup/icons/brand/etel-service.webp'].every((s, i) => logos[i] && logos[i].src.split('?')[0] === s), JSON.stringify(logos.map(l => l.src)));
    t(n + 'the logos have no background (all four corners fully transparent)', logos.every(l => l.al.length === 4 && l.al.every(a => a === 0)), JSON.stringify(logos.map(l => l.al)));
    // links
    const links = await p.evaluate(() => [].slice.call(document.querySelectorAll('#egap-panel .egap-dl')).map(a => ({ href: a.getAttribute('href'), dis: a.getAttribute('aria-disabled'), dl: a.hasAttribute('download'), txt: a.innerText.replace(/\s+/g, ' ').trim() })));
    t(n + 'five real APK links with the right file names', KEYS.filter(k => k !== 'service').every(k => links.some(l => l.href === '/delivery/apk/' + FILES[k] && l.dl && !l.dis)), JSON.stringify(links));
    t(n + 'the file that is not uploaded says Coming soon and is not a link', links.some(l => l.dis === 'true' && /Coming soon/.test(l.txt) && !l.dl && l.href === '#'), JSON.stringify(links));
    t(n + 'a real file shows its size (12 MB)', links.filter(l => /Download APK/.test(l.txt) && /12 MB/.test(l.txt)).length === 5, JSON.stringify(links.map(l => l.txt)));
    t(n + 'the all-in-one app shows its version (v1.8.0) and a badge', await p.evaluate(() => /v1\.8\.0/.test(document.querySelector('#egap-panel .egap-card.is-main').innerText) && !!document.querySelector('#egap-panel .egap-badge')));
    // clicking Coming soon does nothing
    const before = p.url();
    await p.evaluate(() => { document.querySelector('#egap-panel [aria-disabled="true"].egap-dl').click(); }); await p.waitForTimeout(200);
    t(n + 'tapping Coming soon goes nowhere', p.url() === before);
    // contrast
    const c1 = await p.evaluate(probe, '#egap-panel .egap-name'), c2 = await p.evaluate(probe, '#egap-panel .egap-tag'), c3 = await p.evaluate(probe, '#egap-panel .egap-dl:not([aria-disabled])');
    t(n + 'names, descriptions and the download button are readable (contrast 4.5+)', c1.ratio >= 4.5 && c2.ratio >= 4.5 && c3.ratio >= 4.5, JSON.stringify([c1, c2, c3]));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/group_apps_panel_' + mode.n.replace(/ /g, '_') + '.png' });
    // close ways
    await p.keyboard.press('Escape'); await p.waitForTimeout(300);
    t(n + 'Escape closes the panel', await p.evaluate(() => !document.getElementById('egap-panel').classList.contains('open')));
    await btn.click(); await p.waitForTimeout(300); await p.mouse.click(mode.w / 2, mode.h - 8); await p.waitForTimeout(300);
    t(n + 'tapping outside closes it', await p.evaluate(() => !document.getElementById('egap-panel').classList.contains('open')));
    await btn.click(); await p.waitForTimeout(300); await p.evaluate(() => document.querySelector('#egap-panel [data-egap-close]').click()); await p.waitForTimeout(300);
    t(n + 'the X closes it', await p.evaluate(() => !document.getElementById('egap-panel').classList.contains('open')));
    // hero strip
    const chips = await p.evaluate(() => [].slice.call(document.querySelectorAll('#egap-hero .egap-chip')).map(a => a.innerText.replace(/\s+/g, ' ').trim()));
    t(n + 'the hero has a chip with the logo for every app, plus All apps', chips.length === 7 && chips[0] === 'ETEL' && chips[5] === 'ETEL Service', JSON.stringify(chips));
    await p.evaluate(() => { document.documentElement.style.scrollBehavior = 'auto'; document.getElementById('egap-hero').scrollIntoView({ block: 'center' }); }); await p.waitForTimeout(700);
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/group_apps_hero_' + mode.n.replace(/ /g, '_') + '.png' });
    t(n + 'the hero strip does not run into the Scroll hint', await p.evaluate(() => { const a = document.getElementById('egap-hero').getBoundingClientRect(), h = document.querySelector('.scroll-hint').getBoundingClientRect(); return h.width === 0 || !(a.bottom > h.top && a.top < h.bottom); }));
    // footer band
    await p.evaluate(() => { document.documentElement.style.scrollBehavior = 'auto'; document.getElementById('egap-footer').scrollIntoView({ block: 'start' }); }); await p.waitForTimeout(900);
    const foot = await p.evaluate(() => ({ cards: document.querySelectorAll('#egap-footer .egap-card').length, steps: document.querySelectorAll('#egap-footer .egap-steps li').length, how: !!document.querySelector('#egap-footer a[href="/delivery/install.html"]') }));
    t(n + 'the footer band has the six apps, the three install steps and the install help link', foot.cards === 6 && foot.steps === 3 && foot.how, JSON.stringify(foot));
    const c4 = await p.evaluate(probe, '#egap-footer .egap-name'), c5 = await p.evaluate(probe, '#egap-footer .egap-steps li');
    t(n + 'the footer text is readable', c4.ratio >= 4.5 && c5.ratio >= 4.5, JSON.stringify([c4, c5]));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/group_apps_footer_' + mode.n.replace(/ /g, '_') + '.png' });
    if (mode.n === 'desktop dark') {
      // Amharic
      await p.evaluate(() => setLang('am')); await p.waitForTimeout(300);
      const am = await p.evaluate(() => ({ dl: document.querySelector('#egap-footer .egap-dl:not([aria-disabled])').innerText.replace(/\s+/g, ' '), title: document.querySelector('#egap-footer .egap-title').textContent, btn: document.querySelector('#egap-nav-btn .egap-btn-t').textContent }));
      t(n + 'Amharic: the download button, the title and the header button switch', /APK ያውርዱ/.test(am.dl) && /የETEL መተግበሪያዎችን/.test(am.title) && am.btn === 'መተግበሪያዎች', JSON.stringify(am));
      await p.evaluate(() => setLang('en')); await p.waitForTimeout(300);
      t(n + 'and back to English', /Download APK/.test(await p.evaluate(() => document.querySelector('#egap-footer .egap-dl:not([aria-disabled])').innerText)));
    }
    if (mode.w < 500) {
      await p.evaluate(() => window.scrollTo(0, 0)); await p.click('#hamburger'); await p.waitForTimeout(500);
      const mob = await p.evaluate(() => ({ rows: document.querySelectorAll('#egap-mobile .egap-card').length, vis: document.getElementById('egap-mobile').offsetParent !== null }));
      t(n + 'the mobile menu lists the six apps', mob.rows === 6 && mob.vis, JSON.stringify(mob));
      await p.evaluate(() => document.getElementById('egap-mobile').scrollIntoView({ block: 'center' })); await p.waitForTimeout(300);
      await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/group_apps_mobile_menu.png' });
      await p.evaluate(() => closeMob());
      t(n + 'no sideways scroll on the phone', !(await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)));
    }
    t(n + 'no page errors', errs.length === 0, errs.join('|'));
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

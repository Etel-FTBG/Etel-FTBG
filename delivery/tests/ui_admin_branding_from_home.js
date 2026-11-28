// The real admin.html: opening Branding (and the other pages that start their own loader) from the Company / Delivery home cards must fill the page,
// not leave it empty (Dagi saw an empty Branding page, 28 Nov 2026).   Needs php -S 127.0.0.1:8765 -t delivery   node delivery/tests/ui_admin_branding_from_home.js
const { chromium } = require('playwright');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const p = await ctx.newPage();
  await p.route(/^https?:\/\/(?!127\.0\.0\.1)/, r => r.abort());
  await p.route('**/api/boot.php**', r => {
    let a = ''; try { a = JSON.parse(r.request().postData() || '{}').action; } catch (e) { /* not JSON */ }
    if (a === 'admin_get_languages') return r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { config: { default: 'en', langs: { en: 'live', am: 'live', om: 'beta', ti: 'beta', so: 'beta', aa: 'soon' } }, stats: {} } }) });
    return r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: {} }) });
  });
  await p.addInitScript(() => { try { localStorage.setItem('etel_token', 'x.y.z'); localStorage.setItem('etel_admin_token', 'x.y.z'); localStorage.setItem('etel_user', JSON.stringify({ id: 1, role: 'admin', name: 'A', capabilities: ['*'], is_owner: 1 })); } catch (e) { /* no storage */ } });
  await p.goto('http://127.0.0.1:8765/admin.html', { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(3000);
  await p.evaluate(() => AdminWS.show('company'));
  await p.waitForTimeout(600);
  // click the Branding card of the home page (added when the account's menu has no such card in this stub)
  await p.evaluate(() => { let c = document.querySelector('#panel-wshome [data-go="branding"]'); if (!c) { c = document.createElement('div'); c.setAttribute('data-go', 'branding'); document.getElementById('panel-wshome').appendChild(c); } c.click(); });
  await p.waitForTimeout(1200);
  t('Branding opened from the home card shows the image cards', (await p.$$('#brand-pro .ak-card')).length > 5, String((await p.$$('#brand-pro .ak-card')).length));
  t('and the Languages section under them', !!(await p.$('#brand-lang select[data-st="om"]')));
  console.log(fails.length ? fails.map(f => ' FAIL ' + f).join('\n') + '\n' : '', pass + ' passed, ' + fails.length + ' failed'); await b.close();
  if (fails.length) process.exitCode = 1;
})();

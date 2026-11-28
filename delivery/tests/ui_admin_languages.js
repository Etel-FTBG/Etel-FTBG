// Admin > Branding > Languages (the REAL js/admin-languages.js, js/admin-kit.js and js/etel-langs.js; the server is mocked).   node delivery/tests/ui_admin_languages.js
const { chromium } = require('playwright'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..', '..');
(async () => {
  const b = await chromium.launch(); const p = await b.newPage({ viewport: { width: 420, height: 1100 }, deviceScaleFactor: 2 }); const errs = []; p.on('pageerror', e => errs.push(e.message));
  let saved = null;
  await p.route('http://adm.test/**', r => { const u = r.request().url();
    if (u.includes('/delivery/js/')) return r.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(path.join(ROOT, new URL(u).pathname)) });
    if (u.includes('/api/boot.php')) { const body = JSON.parse(r.request().postData() || '{}');
      if (body.action === 'admin_get_languages') return r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { config: { default: 'en', langs: { en: 'live', am: 'live', om: 'beta', ti: 'beta', so: 'beta', aa: 'soon' } }, stats: { en: 12, am: 5, om: 2 } } }) });
      if (body.action === 'admin_save_languages') { saved = body; return r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { config: { default: body.default, langs: body.langs } } }) }); }
      return r.fulfill({ contentType: 'application/json', body: '{"success":false}' }); }
    return r.fulfill({ contentType: 'text/html', body: '<!doctype html><meta charset="utf-8"><body style="background:#0b0e20;color:#fff;margin:0"><div id="brand-pro"></div><script>window.ETEL={api:"http://adm.test/delivery/api/boot.php"};window.ETEL_LANGS_NO_PICKER=true;window.loadBrandingPanel=function(){}</script></body>' }); });
  await p.goto('http://adm.test/delivery/admin.html');
  for (const f of ['admin-kit.js', 'etel-langs.js', 'admin-languages.js']) await p.addScriptTag({ url: 'http://adm.test/delivery/js/' + f });
  await p.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
  await p.evaluate(() => window.loadBrandingPanel());
  await p.waitForSelector('#brand-lang select[data-st="om"]');
  t('no floating picker button on the admin page', !(await p.$('#etel-lang-btn')));
  t('six rows, each with a flag', (await p.$$('#brand-lang .ak-card svg.elg-flag')).length === 6);
  t('English cannot be changed', await p.$eval('select[data-st="en"]', e => e.disabled));
  t('the counts of people are shown', /12 people chose it/.test(await p.textContent('#brand-lang')));
  t('Afar shows Coming soon and cannot be the default', (await p.$eval('select[data-st="aa"]', e => e.value)) === 'soon' && await p.$eval('input[data-def="aa"]', e => e.disabled));
  await p.screenshot({ path: '/tmp/sc/admin-lang.png', fullPage: true });
  await p.selectOption('select[data-st="aa"]', 'beta'); t('turning Afar to Beta lets it be the default', !(await p.$eval('input[data-def="aa"]', e => e.disabled)));
  await p.check('input[data-def="so"]'); await p.selectOption('select[data-st="om"]', 'off');
  await p.click('[data-save-lang]'); await p.waitForFunction(() => /Save languages/.test(document.querySelector('[data-save-lang]').textContent));
  t('Save sends every state and the default', saved && saved.default === 'so' && saved.langs.om === 'off' && saved.langs.aa === 'beta' && saved.langs.en === 'live', JSON.stringify(saved));
  await p.selectOption('select[data-st="so"]', 'off'); t('hiding the default language moves the default back to English', await p.$eval('input[data-def="en"]', e => e.checked));
  t('no page errors', errs.length === 0, errs.join('|'));
  console.log(fails.length ? fails.map(f => ' FAIL ' + f).join('\n') + '\n' : '', pass + ' passed, ' + fails.length + ' failed'); await b.close();
  if (fails.length) process.exitCode = 1;
})();

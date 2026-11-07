// Browser test of js/courier-i18n-brand.js together with js/courier-i18n.js (needs Playwright). Edit the two script paths in brand_addon_page.html, copy it to /tmp/cbrand/page.html, then: node tests/courier/brand_addon_browser.js
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch(); const p = await (await b.newContext()).newPage(); let pass = 0; const fails = [];
  const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
  const OLD = 'ኢቴል', NEW = 'ኢትኤል';
  await p.addInitScript(() => { try { localStorage.setItem('etel_lang', 'am'); } catch (e) {} });
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  await p.goto('file:///tmp/cbrand/page.html'); await p.waitForTimeout(900);
  const all = async () => p.evaluate(() => document.body.innerText + ' ' + [].map.call(document.querySelectorAll('[placeholder],[title]'), e => (e.placeholder || '') + (e.title || '')).join(' '));
  let txt = await all();
  t('Amharic mode: the heading uses the new spelling', (await p.innerText('#h')).indexOf(NEW) >= 0, await p.innerText('#h'));
  t('Amharic mode: the old spelling is nowhere on the page', txt.indexOf(OLD) < 0, txt.slice(0, 120));
  t('Amharic mode: the placeholder uses the new spelling', (await p.getAttribute('#i', 'placeholder')).indexOf(NEW) >= 0, await p.getAttribute('#i', 'placeholder'));
  t('other translated text is untouched', (await p.innerText('#p')).length > 0 && /[ሀ-፿]/.test(await p.innerText('#p')));
  await p.evaluate(() => window.etelCourierSetLang('en')); await p.waitForTimeout(500);
  t('English again: the heading is back to English', (await p.innerText('#h')) === 'ETEL Courier', await p.innerText('#h'));
  t('English again: the placeholder is back to English', (await p.getAttribute('#i', 'placeholder')) === 'ETEL Courier', await p.getAttribute('#i', 'placeholder'));
  await p.evaluate(() => window.etelCourierSetLang('am')); await p.waitForTimeout(700);
  t('Amharic again: new spelling, never the old one', (await p.innerText('#h')).indexOf(NEW) >= 0 && (await all()).indexOf(OLD) < 0, await p.innerText('#h'));
  await p.evaluate(() => { var d = document.createElement('div'); d.id = 'late'; d.textContent = 'ETEL Courier'; document.getElementById('host').appendChild(d); });
  await p.waitForTimeout(800);
  t('text drawn later also gets the new spelling', (await p.innerText('#late')).indexOf(NEW) >= 0 && (await p.innerText('#late')).indexOf(OLD) < 0, await p.innerText('#late'));
  t('no JavaScript errors', errs.length === 0, errs.join('|'));
  console.log('PASSED', pass, 'FAILED', fails.length); fails.forEach(f => console.log('  X ' + f)); await b.close();
})();

// Service giver item form: extras as rows (name + price + remove) writing Name:price lines into the page's own box (js/servicegiver-extras.js, the real file).
// Needs Playwright (node). No server.   node delivery/tests/ui_sg_extras.js
const { chromium } = require('playwright'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const SRC = fs.readFileSync(path.join(__dirname, '../js/servicegiver-extras.js'), 'utf8');
(async () => {
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: 390, height: 844 } }); const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
  await p.setContent('<!doctype html><body><div><label class="field-label">Extras</label><textarea id="item-extras"></textarea></div></body>');
  await p.evaluate(() => { document.getElementById('item-extras').value = 'Mayonis, 50\nKachap:40\nPlain'; });
  await p.addScriptTag({ content: SRC }); await p.waitForTimeout(600);
  const rows = () => p.evaluate(() => Array.from(document.querySelectorAll('[data-sgx-row]')).map(r => [r.querySelector('[data-sgx-name]').value, r.querySelector('[data-sgx-price]').value]));
  const box = () => p.evaluate(() => document.getElementById('item-extras').value);
  t('the old box is hidden and rows are drawn from it ("Mayonis, 50" is the extra Mayonis at 50)', await p.evaluate(() => document.getElementById('item-extras').style.display === 'none') && JSON.stringify(await rows()) === JSON.stringify([['Mayonis', '50'], ['Kachap', '40'], ['Plain', '']]), JSON.stringify(await rows()));
  t('each row has a name box, a price box and a remove button; an add button and a hint exist', await p.evaluate(() => document.querySelectorAll('[data-sgx-del]').length === 3 && /Add an extra/.test(document.getElementById('sgx-wrap').innerText) && /Price is for ONE/.test(document.getElementById('sgx-wrap').innerText)));
  await p.fill('[data-sgx-row]:nth-child(3) [data-sgx-name]', 'Cheese: extra'); await p.fill('[data-sgx-row]:nth-child(3) [data-sgx-price]', '100,5');
  t('typing writes Name:price lines into the page box (a colon in a name is removed, a comma price works)', await box() === 'Mayonis:50\nKachap:40\nCheese extra:100.5', await box());
  await p.fill('[data-sgx-row]:nth-child(2) [data-sgx-price]', '');
  t('an empty price is 0 (free)', (await box()).split('\n')[1] === 'Kachap:0', await box());
  await p.fill('[data-sgx-row]:nth-child(1) [data-sgx-price]', 'abc');
  t('letters in a price are dropped (the price becomes 0, never text)', (await box()).split('\n')[0] === 'Mayonis:0', await box());
  await p.click('[data-sgx-del] >> nth=0');
  t('remove deletes the row and its line', JSON.stringify(await rows()) === JSON.stringify([['Kachap', ''], ['Cheese: extra', '100,5']]) && (await box()).split('\n').length === 2, JSON.stringify(await rows()) + ' | ' + await box());
  await p.click('#sgx-wrap button:not([data-sgx-del])');
  t('add draws an empty row and moves the cursor into it; an empty row writes nothing', (await rows()).length === 3 && await p.evaluate(() => document.activeElement && document.activeElement.hasAttribute('data-sgx-name')) && (await box()).split('\n').length === 2);
  // the page opens another item: it sets the box itself
  await p.evaluate(() => { document.activeElement && document.activeElement.blur(); document.getElementById('item-extras').value = 'Egg:30\nSauce;10'; });
  await p.waitForTimeout(700);
  t('when the page fills the box (an item opened to edit) the rows follow', JSON.stringify(await rows()) === JSON.stringify([['Egg', '30'], ['Sauce', '10']]), JSON.stringify(await rows()));
  await p.evaluate(() => { document.getElementById('item-extras').value = ''; }); await p.waitForTimeout(700);
  t('when the page clears it (a new item) the rows are gone', (await rows()).length === 0);
  const many = Array.from({ length: 12 }, (_, i) => 'X' + i + ':1').join('\n');
  await p.evaluate((m) => { document.getElementById('item-extras').value = m; }, many); await p.waitForTimeout(700);
  t('12 extras is the most: the add button disappears', (await rows()).length === 12 && await p.evaluate(() => document.querySelector('#sgx-wrap button:not([data-sgx-del])').style.display === 'none'));
  await p.evaluate(() => { document.getElementById('item-extras').value = '<img src=x onerror=alert(1)>:5'; }); await p.waitForTimeout(700);
  t('a name with markup is shown as text, not run', (await rows())[0][0] === '<img src=x onerror=alert(1)>' && await p.evaluate(() => !document.querySelector('#sgx-wrap img')));
  t('no page errors', errs.length === 0, errs.join('|'));
  await b.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

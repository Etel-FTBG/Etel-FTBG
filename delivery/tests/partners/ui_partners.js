const { chromium } = require('playwright'); const fs = require('fs'); const { execSync } = require('child_process');
const D = __dirname, BASE = 'http://127.0.0.1:8099/delivery/agent/index.html';
const q = sql => execSync('mysql fa_test -N -e ' + JSON.stringify(sql)).toString().trim();
const tok = fs.readFileSync(D + '/tok-0911000001.txt', 'utf8').trim();
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
// a real 2x2 PNG
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAFElEQVR4nGP8z8Dwn4EIwDiqEF8oAA0uA/1o2wGeAAAAAElFTkSuQmCC', 'base64');

(async () => {
  q("ALTER TABLE fa_agents ADD COLUMN IF NOT EXISTS roles VARCHAR(40) NOT NULL DEFAULT 'ride'"); q("UPDATE fa_agents SET roles='ride,courier,driver,shop' WHERE phone='0911000001'");
  q("DELETE FROM fa_partners"); q("DELETE FROM fa_earnings");
  const b = await chromium.launch(); const errs = [];
  const upl = async (p, key, n) => { await p.setInputFiles(`[data-file="${key}"]`, D + '/p.jpg'); await p.waitForFunction(k => { const f = document.querySelector(`[data-file="${k}"]`); const u = f && f.previousElementSibling; return u && u.classList.contains('has') && !/Uploading/.test(u.innerText); }, key, { timeout: 20000 }); };
  for (const theme of ['dark', 'light']) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1, geolocation: { latitude: 7.04, longitude: 38.48 }, permissions: ['geolocation'] });
    await ctx.addInitScript(([tk, th]) => { try { localStorage.setItem('etel_token', tk); localStorage.setItem('etel_agent_lang', 'en'); localStorage.setItem('theme', th); } catch (e) {} }, [tok, theme]);
    const p = await ctx.newPage(); p.on('pageerror', e => errs.push(theme + ': ' + e.message));
    await p.goto(BASE + '#/partners', { waitUntil: 'domcontentloaded' });
    await p.waitForSelector('.pt-kind', { timeout: 30000 }); await p.waitForTimeout(800);
    t(theme + ': the start cards show real pictures', (await p.$$eval('.pt-kind .pt-ki img.pt-vimg', a => a.filter(i => i.complete && i.naturalWidth > 0).length)) >= 2);
    let txt = await p.innerText('body');
    t(theme + ': hub shows three kinds with fees', /Courier/.test(txt) && /Driver/.test(txt) && /Shop or service/.test(txt) && /50/.test(txt) && /150/.test(txt), txt.slice(0, 160));
    t(theme + ': no horizontal scroll', await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    await p.screenshot({ path: `pt-hub-${theme}.png` });
    if (theme === 'dark') {
      // ---- courier, step by step ----
      await p.click('[data-act="pt-new"][data-k="courier"]'); await p.waitForSelector('#pt-name');
      t('courier wizard opens on step 1 of 4', (await p.innerText('.pt-stepn')).includes('Step 1 of 4'));
      await p.fill('#pt-name', 'Chala Bekele'); await p.fill('#pt-phone', '0955123456'); await p.fill('#pt-id_number', 'NID-778899');
      await upl(p, 'id_photo_url'); await upl(p, 'selfie_url');
      await p.screenshot({ path: 'pt-courier-1.png' });
      await p.click('[data-act="pt-next"]'); await p.waitForSelector('.pt-vt'); await p.waitForTimeout(900);
      t('step 2 shows the vehicles', (await p.innerText('body')).includes('Motorcycle'));
      const loaded = await p.$$eval('.pt-vt img.pt-vimg', a => a.map(i => i.complete && i.naturalWidth > 0));
      t('a courier can choose from seven vehicles, each with a real picture', loaded.length === 7 && loaded.every(Boolean) && (await p.$$('.pt-vt')).length === 7, JSON.stringify(loaded));
      const names = await p.$$eval('.pt-vt b', a => a.map(x => x.textContent));
      t('the courier choices are motorcycle, car, bicycle, bajaj, qute, minibus and the EV (no on foot)', ['Motorcycle', 'Car', 'Bicycle', 'Bajaj', 'Qute', 'Minibus'].every(n => names.includes(n)) && names.some(n => /Electric vehicle/.test(n)) && !names.some(n => /foot/i.test(n)), JSON.stringify(names));
      t('the bicycle and the EV have their own pictures', await p.$$eval('.pt-vt img', a => a.some(i => /bicycle\.webp/.test(i.src)) && a.some(i => /ev_car\.webp/.test(i.src))));
      await p.screenshot({ path: 'pt-real-vehicles.png' });
      await p.click('.pt-vt:has-text("Bicycle")'); await p.waitForTimeout(300);
      t('the vehicle step asks for the front, side and back photos', (await p.$$('[data-file^="vehicle_photo_"]')).length === 3 && /Front/.test(await p.innerText('.pt-vps')) && /Back/.test(await p.innerText('.pt-vps')));
      await upl(p, 'vehicle_photo_front'); await upl(p, 'vehicle_photo_side'); await upl(p, 'vehicle_photo_back');
      await p.screenshot({ path: 'pt-vehicle-photos.png' });
      await p.click('[data-act="pt-next"]'); await p.waitForSelector('#pt-emergency_phone'); await p.waitForTimeout(500);
      await p.fill('#pt-emergency_name', 'Mother'); await p.fill('#pt-emergency_phone', '0944000099'); await p.fill('#pt-zone', 'Tabor');
      await p.click('[data-act="pt-next"]'); await p.waitForSelector('#pt-send'); await p.waitForTimeout(600);
      txt = await p.innerText('body');
      t('the review shows the vehicle picture', (await p.$$('.pt-rpic img.pt-vimg')).length + (await p.$$('.pt-rpic .pt-vico')).length === 1);
      t('review says everything is filled in', txt.includes('Everything is filled in'), txt.slice(-300));
      t('the review shows the fee', txt.includes('50 ETB'));
      t('the draft is saved on the server', q("SELECT COUNT(*) FROM fa_partners WHERE name='Chala Bekele' AND status='draft'") === '1');
      await p.screenshot({ path: 'pt-courier-review.png' });
      await p.click('#pt-send'); await p.waitForSelector('.pt-kind'); await p.waitForTimeout(1200);
      t('sent: it moves to With ETEL', q("SELECT status FROM fa_partners WHERE name='Chala Bekele'") === 'submitted');
      t('hub lists it as With ETEL', (await p.innerText('body')).includes('With ETEL'));
      // ---- live duplicate check: warns while typing, before anything is sent ----
      await p.click('[data-act="pt-new"][data-k="courier"]'); await p.waitForSelector('#pt-name');
      await p.fill('#pt-phone', '0955123456'); await p.waitForSelector('.pt-dup.bad', { timeout: 8000 });
      txt = await p.innerText('#pt-dups');
      t('dup: typing a phone that is already with another registration shows a red warning', /Already on ETEL/.test(txt) && /already registered|already has/i.test(txt), txt);
      t('dup: the warning shows only the kind and a short name, never the full number', !txt.includes('0955123456'));
      t('dup: Next is locked while the same person is already registered', await p.evaluate(() => document.querySelector('[data-act="pt-next"]').disabled));
      t('dup: the field itself is marked', (await p.getAttribute('#pt-phone', 'data-dup')) === 'block');
      await p.screenshot({ path: 'pt-dup-block.png' });
      await p.fill('#pt-phone', '0955999111'); await p.waitForFunction(() => !document.querySelector('.pt-dup.bad'), null, { timeout: 8000 });
      t('dup: with a new number the warning goes away and Next opens', !(await p.evaluate(() => document.querySelector('[data-act="pt-next"]').disabled)));
      await p.fill('#pt-name', 'Bekele Chala'); await p.waitForSelector('.pt-dup.warn', { timeout: 8000 });
      txt = await p.innerText('#pt-dups');
      t('dup: the same name in another word order is only a warning (Next stays open)', /Looks similar/.test(txt) && !(await p.evaluate(() => document.querySelector('[data-act="pt-next"]').disabled)), txt);
      await p.screenshot({ path: 'pt-dup-warn.png' });
      await p.fill('#pt-id_number', 'NID-778899'); await p.waitForSelector('.pt-dup.bad', { timeout: 8000 });
      t('dup: the same ID number is a block', /ID number/i.test(await p.innerText('#pt-dups')));
      await p.fill('#pt-name', ''); await p.fill('#pt-phone', ''); await p.fill('#pt-id_number', '');
      await p.click('[data-act="pt-exit"]'); await p.waitForSelector('.pt-kind'); await p.waitForTimeout(800);
      // ---- an incomplete draft shows what is missing ----
      await p.click('[data-act="pt-new"][data-k="driver"]'); await p.waitForSelector('#pt-name');
      await p.fill('#pt-name', 'Half Done'); await p.fill('#pt-phone', '0966111222');
      for (let i = 0; i < 4; i++) { await p.click('[data-act="pt-next"]'); await p.waitForTimeout(900); }
      await p.waitForSelector('#pt-send');
      txt = await p.innerText('body');
      t('an incomplete driver lists what is missing', /Still missing/.test(txt) && /selfie|ID card|licence/i.test(txt), txt.slice(-400));
      await p.screenshot({ path: 'pt-missing.png' });
      await p.click('[data-act="pt-exit"]'); await p.waitForSelector('.pt-kind'); await p.waitForTimeout(1000);
      t('leaving keeps the draft', q("SELECT COUNT(*) FROM fa_partners WHERE name='Half Done' AND status='draft'") === '1');
      // ---- shop with products ----
      await p.click('[data-act="pt-new"][data-k="shop"]'); await p.waitForSelector('#pt-name');
      await p.fill('#pt-name', 'Sara Tadesse'); await p.fill('#pt-phone', '0977123456'); await p.fill('#pt-id_number', 'NID-1');
      await upl(p, 'id_photo_url'); await upl(p, 'selfie_url');
      await p.click('[data-act="pt-next"]'); await p.waitForSelector('#pt-business_name'); await p.waitForTimeout(900);
      await p.fill('#pt-business_name', 'Lakeside Burger'); await p.click('.pt-chip:has-text("Restaurant")'); await p.fill('#pt-address', 'Tabor, Hawassa');
      await p.click('[data-act="pt-loc"]'); await p.waitForFunction(() => /Location saved/.test(document.body.innerText), null, { timeout: 15000 });
      await upl(p, 'logo_url');
      await upl(p, 'place_photo_url'); await upl(p, 'center_photo_url');
      t('the shop step asks for the shop place and the shopping center pictures', (await p.$$('[data-file="place_photo_url"],[data-file="center_photo_url"]')).length === 2);
      t('the city is picked from the location', (await p.innerText('body')).includes('Hawassa'));
      await p.screenshot({ path: 'pt-shop-2.png' });
      await p.click('[data-act="pt-next"]'); await p.waitForSelector('[data-act="pt-iadd"]'); await p.waitForTimeout(600);
      t('products step says 0 of 5', (await p.innerText('body')).includes('0 of 5 products'));
      for (let i = 1; i <= 5; i++) {
        await p.click('[data-act="pt-iadd"]'); await p.waitForSelector('#pt-i_name');
        await p.fill('#pt-i_name', 'Burger ' + i); await p.fill('#pt-i_price', String(100 + i));
        if (i === 1) await upl(p, 'item');
        await p.click('[data-act="pt-isave"]'); await p.waitForTimeout(300);
      }
      t('five products are counted', (await p.innerText('body')).includes('5 of 5 products'));
      await p.screenshot({ path: 'pt-shop-products.png' });
      await p.click('[data-act="pt-next"]'); await p.waitForSelector('#pt-send'); await p.waitForTimeout(700);
      t('shop review is complete', (await p.innerText('body')).includes('Everything is filled in'), (await p.innerText('body')).slice(-300));
      await p.click('#pt-send'); await p.waitForSelector('.pt-kind'); await p.waitForTimeout(1200);
      t('shop is submitted with 5 products', q("SELECT CONCAT(status,'/',item_count) FROM fa_partners WHERE name='Sara Tadesse'") === 'submitted/5', q("SELECT CONCAT(status,'/',item_count) FROM fa_partners WHERE name='Sara Tadesse'"));
      // ---- the owner asks for changes; the agent sees it and fixes ----
      q("UPDATE fa_partners SET status='changes', decision_note='ID photo is blurry' WHERE name='Chala Bekele'");
      await p.reload({ waitUntil: 'domcontentloaded' }); await p.waitForSelector('.pt-kind', { timeout: 8000 }).catch(async () => { console.log('RELOAD STATE:', (await p.evaluate(() => location.hash + ' | ' + document.body.innerText.slice(0, 200)))); await p.screenshot({ path: 'pt-reload-dbg.png' }); throw new Error('reload'); }); await p.waitForTimeout(1200);
      t('the agent sees the owner note', (await p.innerText('body')).includes('ID photo is blurry') && (await p.innerText('body')).includes('Changes needed'));
      await p.click('[data-act="pt-open"]:has-text("Fix and resend")'); await p.waitForSelector('#pt-name'); await p.waitForTimeout(600);
      t('the fix screen shows the owner note and the saved values', (await p.innerText('.pt-changes')).includes('blurry') && (await p.inputValue('#pt-name')) === 'Chala Bekele');
      await p.screenshot({ path: 'pt-changes.png' });
    } else {
      await p.click('[data-act="pt-new"][data-k="shop"]'); await p.waitForSelector('#pt-name'); await p.waitForTimeout(400);
      t('light: wizard fits the screen', await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
      await p.screenshot({ path: 'pt-wizard-light.png' });
    }
    t(theme + ': no page errors', true); await ctx.close();
  }
  console.log('PASSED', pass, 'FAILED', fails.length); fails.forEach(f => console.log('  X ' + f)); console.log('errs', errs.slice(0, 4)); await b.close();
})();

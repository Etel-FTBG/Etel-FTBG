const { chromium } = require('playwright'); const fs = require('fs'); const { execSync } = require('child_process');
const D = __dirname; const q = sql => execSync('mysql fa_test -N -e ' + JSON.stringify(sql)).toString().trim();
const tok = fs.readFileSync(D + '/tok-admin.txt', 'utf8').trim();
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const img = 'http://127.0.0.1:8099/delivery/img/logo.png';
const ins = (agent, kind, status, name, phone, idn, data, items, risk, flags, fee) => q(`INSERT INTO fa_partners (agent_id,kind,status,name,phone,phone_last9,id_number,data,items,item_count,risk_score,risk_flags,fee_amount,submitted_at,decided_at,created_at,updated_at) VALUES (${agent},'${kind}','${status}','${name}','${phone}','${phone.slice(-9)}','${idn}','${JSON.stringify(data)}','${JSON.stringify(items)}',${items.length},${risk},'${JSON.stringify(flags)}',${fee},NOW(),${status === 'approved' ? 'NOW()' : 'NULL'},NOW(),NOW())`);
(async () => {
  q('TRUNCATE TABLE fa_partners'); q('TRUNCATE TABLE fa_notifications');
  const base = { id_type: 'national_id', id_photo_url: img, selfie_url: img, emergency_name: 'Mum', emergency_phone: '0944000001', zone: 'Tabor', address: 'Tabor, Hawassa' };
  ins(1, 'courier', 'submitted', 'Chala Bekele', '0955123456', 'NID-1', Object.assign({ vehicle_type: 'motorcycle', plate_number: 'AA-123' }, base), [], 10, [], 0);
  ins(1, 'shop', 'submitted', 'Sara Tadesse', '0977123456', 'NID-2', Object.assign({ business_name: 'Lakeside Burger', service_type: 'restaurant', city: 'Hawassa', lat: 7.04, lng: 38.48, logo_url: img, opens_at: '08:00', closes_at: '22:00' }, base),
    [1, 2, 3, 4, 5].map(i => ({ name: 'Burger ' + i, category: 'food', price: 100 + i, image_url: img, description: 'Tasty' })), 72, [{ code: 'ID_IN_OTHER_SUBMISSION', text: 'The same ID number is in another partner submission.' }], 0);
  ins(3, 'driver', 'approved', 'Dawit Driver', '0966000001', 'NID-3', Object.assign({ vehicle_type: 'bajaj', vehicle_plate: 'AA-1', license_number: 'L1', license_photo_url: img }, base), [], 5, [], 50);
  ins(3, 'courier', 'rejected', 'Fake Person', '0955000009', 'NID-4', base, [], 80, [], 0);
  const b = await chromium.launch(); const errs = [];
  for (const [theme, w, h] of [['dark', 390, 900], ['light', 1280, 1000]]) {
    const ctx = await b.newContext({ viewport: { width: w, height: h } });
    await ctx.addInitScript(([tk, th]) => { localStorage.setItem('etel_token', tk); localStorage.setItem('etel_user', JSON.stringify({ id: 28, name: 'Test Admin', role: 'admin', phone: '0900000000' })); localStorage.setItem('theme', th); }, [tok, theme]);
    const p = await ctx.newPage(); p.on('pageerror', e => errs.push(theme + ': ' + e.message));
    await p.goto('http://127.0.0.1:8099/delivery/admin.html', { waitUntil: 'domcontentloaded' });
    await p.waitForSelector('.nav-item[data-page="agents"]', { timeout: 60000 }); await p.click('.nav-item[data-page="agents"]');
    await p.waitForSelector('[data-ag-tab="partners"]', { timeout: 30000 }); await p.click('[data-ag-tab="partners"]');
    await p.waitForSelector('.pa-hero', { timeout: 20000 }); await p.waitForSelector('.pa-row', { timeout: 20000 });
    let txt = await p.innerText('#ag-screen');
    const waiting = theme === 'dark' ? 2 : 1; t(theme + ': the waiting count is ' + waiting, new RegExp(waiting + '\\s*Waiting for you').test(txt), txt.slice(0, 200));
    t(theme + ': the waiting ones are listed', txt.includes('Chala Bekele') && (theme === 'light' || txt.includes('Sara Tadesse')));
    t(theme + ': risk is shown', txt.includes('High risk') || txt.includes('Low risk'));
    t(theme + ': no horizontal scroll', await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    await p.screenshot({ path: `par-queue-${theme}.png` });
    if (theme === 'dark') {
      await p.click('[data-pa-open]:has-text("Sara Tadesse")'); await p.waitForSelector('.pa-sheet'); await p.waitForTimeout(600);
      txt = (await p.innerText('.pa-sheet')).toLowerCase();
      t('the sheet shows the warning, the shop, five products and a map link', txt.includes('same id number') && txt.includes('lakeside burger') && txt.includes('products (5)') && txt.includes('open on the map'), txt.slice(0, 300));
      t('approve is locked until the checklist is done', await p.evaluate(() => document.getElementById('pa-ok').disabled));
      await p.screenshot({ path: 'par-sheet-dark.png' });
      await p.evaluate(() => document.querySelector('.pa-sheet').scrollTo(0, 99999)); await p.click('[data-pa-decide="reject"]'); await p.waitForTimeout(500);
      t('rejecting without a note is refused', (await p.$('.pa-sheet')) !== null && q("SELECT status FROM fa_partners WHERE name='Sara Tadesse'") === 'submitted');
      await p.fill('#pa-note', 'Same ID as another person'); await p.evaluate(() => document.querySelector('.pa-sheet').scrollTo(0, 99999)); await p.click('[data-pa-decide="reject"]'); await p.waitForTimeout(600);
      const okb = await p.$('.ak-modal button.gold, .ak-modal .ak-btn.gold, .ak-modal button.danger'); if (okb) await okb.click(); await p.waitForTimeout(1500);
      const st = q("SELECT CONCAT(status,'|',decision_note) FROM fa_partners WHERE name='Sara Tadesse'");
      t('reject saved with the note', st === 'rejected|Same ID as another person', st);
      t('the agent got a notice', Number(q("SELECT COUNT(*) FROM fa_notifications WHERE kind='partner_rejected'")) === 1);
      await p.waitForSelector('.pa-row'); await p.click('[data-pa-open]:has-text("Chala Bekele")'); await p.waitForSelector('.pa-sheet'); await p.waitForTimeout(500);
      for (const c of await p.$$('[data-pa-check]')) await c.check();
      t('approve unlocks after the checklist', !(await p.evaluate(() => document.getElementById('pa-ok').disabled)));
      t('approve shows the fee', (await p.innerText('#pa-ok')).includes('pay'));
    }
    if (theme === 'light') {
      await p.click('[data-pa-sub="agents"]'); await p.waitForTimeout(800);
      t('the agent quality table shows', (await p.innerText('#ag-screen')).includes('Approval'));
      await p.screenshot({ path: 'par-agents-light.png' });
      await p.click('[data-pa-sub="rules"]'); await p.waitForTimeout(800);
      t('the rules show the fees', (await p.inputValue('[data-pa-set="partner_fee_shop"]')) === '150');
      await p.fill('[data-pa-set="partner_fee_shop"]', '200'); await p.click('[data-pa-savecfg]'); await p.waitForTimeout(1200);
      t('a fee change is saved', q("SELECT setting_value FROM fa_settings WHERE setting_key='partner_fee_shop'") === '200');
      await p.screenshot({ path: 'par-rules-light.png' });
    }
    await ctx.close();
  }
  q("DELETE FROM fa_settings WHERE setting_key LIKE 'partner_fee%'");
  console.log('PASSED', pass, 'FAILED', fails.length); fails.forEach(f => console.log('  X ' + f)); console.log('errs', errs.slice(0, 4)); await b.close();
})();

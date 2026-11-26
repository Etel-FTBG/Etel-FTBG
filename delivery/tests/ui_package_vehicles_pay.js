// Send a package: step 3 shows EVERY vehicle type with its real picture (the ones that cannot take the package are greyed out with the reason),
// and step 4 shows every payment choice (Cash, Telebirr, Etel Pay wallet, Chapa, Abyssinia, CBE) with the right extra box and the right calls after booking.
// Real page and files (customer.html, js/customer-package.js), a fake API.   node delivery/tests/ui_package_vehicles_pay.js
const { chromium } = require('playwright'); const path = require('path'); const http = require('http'); const fs = require('fs');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.woff2': 'font/woff2' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });
const OPTIONS = [
  { vehicle_class: 'moto', label: 'Motorbike', icon: '\u{1F3CD}', price: '120.00', max_weight_kg: 20, rank: 1 },
  { vehicle_class: 'car', label: 'Car', icon: '\u{1F697}', price: '220.00', max_weight_kg: 120, rank: 2 }];
const OFF = [
  { vehicle_class: 'bike', label: 'Bicycle', icon: '\u{1F6B2}', reason: 'weight', max_weight_kg: 5, rank: 0 },
  { vehicle_class: 'van', label: 'Van', icon: '\u{1F690}', reason: 'size', max_weight_kg: 500, rank: 3 }];
const BANKS = [{ bank_key: 'abysinia', display_name: 'Bank of Abyssinia', account_name: 'Etel Group', account_number: '1234567890' }];
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const PAGE = 'http://127.0.0.1:' + srv.address().port + '/customer.html';
  const b = await chromium.launch();
  for (const mode of [{ n: 'new server', unavailable: OFF }, { n: 'older server (no unavailable list)', unavailable: undefined }]) {
    for (const wallet of [false, true]) {
      const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
      await ctx.addInitScript(() => { localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 5, name: 'Cust', phone: '0911000001', role: 'customer' })); });
      const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message + ' @ ' + (e.stack || '').split('\n')[1])); const calls = [];
      await p.route(/maps\.googleapis|gstatic|fonts\./, r => r.abort());
      await p.route(/boot\.php/, r => {
        const u = r.request().url(); let body = {}; try { body = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
        const act = (u.match(/action=([a-z_]+)/) || [])[1] || body.action; let data = {};
        if (act === 'customer_get_items' || act === 'get_items') data = { items: [], count: 0 };
        if (act !== 'customer_get_items') calls.push({ act, body });
        if (act === 'customer_package_options') data = { sizes: [{ size: 'document' }, { size: 'small' }, { size: 'medium' }], vehicles: [], zones: [] };
        else if (act === 'customer_package_quote') data = { distance_km: 4.2, eta_min: 18, options: OPTIONS, unavailable: mode.unavailable, pickup_zone: 'A', drop_zone: 'B' };
        else if (act === 'customer_package_create') data = { order_id: 77, order_code: 'PKG-77', total: '120.00' };
        else if (act === 'get_bank_accounts') data = { accounts: BANKS };
        else if (act === 'etelpay_status' || act === 'get_etelpay_status') data = { etelpay_live: wallet };
        else if (act === 'initiate_telebirr_payment') data = { payment_id: 9, status: 'pending' };
        else if (act === 'confirm_bank_payment') data = { payment_id: 3, status: 'pending' };
        r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
      });
      await p.goto(PAGE); await p.waitForTimeout(2000);
      await p.evaluate(() => { [].slice.call(document.querySelectorAll('button')).forEach(x => { if (/^\s*Not Now\s*$/.test(x.textContent)) x.click(); }); });
      await p.evaluate(w => { try { etelPayLive = w; } catch (e) {} pkgOpen(); }, wallet); await p.waitForTimeout(600);
      const setf = async (f, v) => { await p.evaluate(([f, v]) => { const el = document.querySelector('[data-f="' + f + '"]'); el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }, [f, v]); };
      // step 1
      const zone = await p.evaluate(() => { const s = document.querySelector('select[data-f="pickup.zone"]'); return s && s.options[1] ? s.options[1].value : ''; });
      await setf('pickup.address', 'Piassa shop'); await setf('drop.address', 'Tabor home');
      if (zone) { await setf('pickup.zone', zone); await setf('drop.zone', zone); } else { await p.evaluate(() => {}); }
      await setf('sender_name', 'Sender'); await setf('sender_phone', '0911000001'); await setf('recipient_name', 'Rita'); await setf('recipient_phone', '0911223344');
      await p.evaluate(() => pkgNext()); await p.waitForTimeout(300);
      // step 2
      await p.evaluate(() => { pkgSize('small'); }); await setf('weight', '8'); await setf('desc', 'Books'); await p.evaluate(() => { const c = document.getElementById('pkg-f-ack'); c.checked = true; c.dispatchEvent(new Event('change', { bubbles: true })); });
      await p.evaluate(() => pkgNext()); await p.waitForTimeout(800);
      const n = mode.n + (wallet ? ' / wallet live' : ' / wallet off');
      const step = await p.evaluate(() => document.querySelector('.pkg-step.now .pkg-step-n') && document.querySelector('.pkg-step.now .pkg-step-n').textContent);
      t(n + ': reached the vehicle step', step === '3', step);
      const names = await p.evaluate(() => [].slice.call(document.querySelectorAll('.pkg-veh .pkg-veh-name')).map(x => x.textContent.replace(/Best price/, '').trim()));
      if (mode.unavailable) t(n + ': all four vehicles are listed, smallest first', JSON.stringify(names) === JSON.stringify(['Bicycle', 'Motorbike', 'Car', 'Van']), JSON.stringify(names));
      else t(n + ': the two available vehicles are listed', JSON.stringify(names) === JSON.stringify(['Motorbike', 'Car']), JSON.stringify(names));
      const pics = await p.evaluate(() => [].slice.call(document.querySelectorAll('.pkg-veh')).map(v => { const i = v.querySelector('img'); return i ? i.getAttribute('src').replace(/.*img\/vehicles\//, '').replace(/\?.*/, '') : 'none'; }));
      if (mode.unavailable) t(n + ': the vehicles show real pictures', pics[1] === 'moto.png' && pics[2] === 'car.png' && pics[3] === 'minibus.png', JSON.stringify(pics));
      else t(n + ': the vehicles show real pictures', pics[0] === 'moto.png' && pics[1] === 'car.png', JSON.stringify(pics));
      const off = await p.evaluate(() => [].slice.call(document.querySelectorAll('.pkg-veh.off')).map(v => ({ tag: v.tagName, txt: v.innerText.replace(/\s+/g, ' ') })));
      if (mode.unavailable) {
        t(n + ': two vehicles are greyed out, not buttons', off.length === 2 && off.every(o => o.tag === 'DIV'), JSON.stringify(off));
        t(n + ': the reasons are shown (weight and size)', /5 kg/.test(off[0].txt) && /small/i.test(off[1].txt), JSON.stringify(off));
        await p.evaluate(() => { const o = document.querySelector('.pkg-veh.off'); o.click(); }); await p.waitForTimeout(200);
        t(n + ': tapping a greyed vehicle selects nothing', await p.evaluate(() => !document.querySelector('.pkg-veh.on')));
      } else t(n + ': no greyed list from an older server and no error', off.length === 0);
      await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/pkg_vehicles_' + (mode.unavailable ? 'new' : 'old') + (wallet ? '_w' : '') + '.png' });
      await p.evaluate(() => pkgVehicle('moto')); await p.evaluate(() => pkgNext()); await p.waitForTimeout(500);
      // step 4: payment choices
      const pays = await p.evaluate(() => [].slice.call(document.querySelectorAll('.pkg-pay')).map(x => ({ t: x.querySelector('.pkg-pay-t').textContent, s: x.querySelector('.pkg-pay-s').textContent, off: x.classList.contains('off'), tag: x.tagName })));
      const titles = pays.map(x => x.t);
      t(n + ': all six payment choices are shown', JSON.stringify(titles) === JSON.stringify(['Cash', 'Telebirr', 'Etel Pay wallet', 'Pay online', 'Abyssinia Bank', 'Commercial Bank of Ethiopia']), JSON.stringify(titles));
      const w = pays[2];
      if (wallet) t(n + ': wallet can be chosen when Etel Pay is live', !w.off && w.tag === 'BUTTON', JSON.stringify(w));
      else t(n + ': wallet says Coming soon and cannot be chosen', w.off && /Coming soon/.test(w.s) && w.tag === 'DIV', JSON.stringify(w));
      t(n + ': CBE is greyed (no account set) and Abyssinia can be chosen', pays[5].off && !pays[4].off, JSON.stringify([pays[4], pays[5]]));
      await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/pkg_pay_' + (mode.unavailable ? 'new' : 'old') + (wallet ? '_w' : '') + '.png' });
      if (mode.unavailable && !wallet) {
        // Telebirr
        await p.evaluate(() => pkgPay('telebirr')); await p.waitForTimeout(150);
        t('Telebirr: asks for the Telebirr phone', await p.evaluate(() => !!document.getElementById('pkg-f-tb_phone')));
        await p.evaluate(() => pkgSubmit()); await p.waitForTimeout(300);
        t('Telebirr: no phone, no booking', !calls.some(c => c.act === 'customer_package_create') && /Telebirr/.test(await p.evaluate(() => document.getElementById('pkg-err').textContent)));
        await setf('tb_phone', '0911000001'); await p.evaluate(() => pkgSubmit()); await p.waitForTimeout(800);
        const tb = calls.find(c => c.act === 'initiate_telebirr_payment'), cr = calls.find(c => c.act === 'customer_package_create');
        t('Telebirr: books with payment_method telebirr', cr && cr.body.payment_method === 'telebirr', JSON.stringify(cr && cr.body.payment_method));
        t('Telebirr: records the payment (order, amount, phone)', tb && tb.body.order_id === 77 && tb.body.amount === '120.00' && tb.body.phone === '0911000001', JSON.stringify(tb && tb.body));
        t('Telebirr: the done screen tells the customer to dial *150#', /\*150#/.test(await p.evaluate(() => document.getElementById('pkg-scroll').innerText)));
        await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/pkg_done_tb.png' });
        // Abyssinia: a second booking
        calls.length = 0;
        await p.evaluate(() => { pkgOpen(); }); await p.waitForTimeout(500);
        await setf('pickup.address', 'Piassa shop'); await setf('drop.address', 'Tabor home');
        if (zone) { await setf('pickup.zone', zone); await setf('drop.zone', zone); }
        await setf('sender_name', 'Sender'); await setf('sender_phone', '0911000001'); await setf('recipient_name', 'Rita'); await setf('recipient_phone', '0911223344');
        await p.evaluate(() => pkgNext()); await p.evaluate(() => pkgSize('small')); await setf('weight', '8'); await setf('desc', 'Books'); await p.evaluate(() => { const c = document.getElementById('pkg-f-ack'); c.checked = true; c.dispatchEvent(new Event('change', { bubbles: true })); });
        await p.evaluate(() => pkgNext()); await p.waitForTimeout(700); await p.evaluate(() => pkgVehicle('moto')); await p.evaluate(() => pkgNext()); await p.waitForTimeout(500);
        await p.evaluate(() => pkgPay('abysinia')); await p.waitForTimeout(200);
        t('Abyssinia: shows the account number and a reference box', await p.evaluate(() => /1234567890/.test(document.querySelector('.pkg-payx').innerText) && !!document.getElementById('pkg-f-bank_ref')));
        await p.evaluate(() => pkgSubmit()); await p.waitForTimeout(300);
        t('Abyssinia: no reference, no booking', !calls.some(c => c.act === 'customer_package_create'));
        await setf('bank_ref', 'FT123456'); await p.evaluate(() => pkgSubmit()); await p.waitForTimeout(800);
        const bk = calls.find(c => c.act === 'confirm_bank_payment');
        t('Abyssinia: records the transfer (order, bank, amount, reference)', bk && bk.body.order_id === 77 && bk.body.bank === 'abysinia' && bk.body.amount === '120.00' && bk.body.reference_no === 'FT123456', JSON.stringify(bk && bk.body));
        const cr2 = calls.find(c => c.act === 'customer_package_create');
        t('Abyssinia: books with payment_method abysinia', cr2 && cr2.body.payment_method === 'abysinia');
      }
      t(n + ': no page errors', errs.length === 0, errs.join('|'));
      await ctx.close();
    }
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

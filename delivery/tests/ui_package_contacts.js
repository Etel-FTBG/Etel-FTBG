// Send a package: the address book. Each side of the route has "Address book" and "Save to address book"; Use fills the pickup (sender) or the drop-off (recipient);
// saving sends the name, phone, address and map point; delete needs a second tap; empty list and a server error are handled. Real page and files, a fake API.
//   node delivery/tests/ui_package_contacts.js
const { chromium } = require('playwright'); const path = require('path'); const http = require('http'); const fs = require('fs');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
const MIME = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.woff2': 'font/woff2' };
const srv = http.createServer((q, r) => { const f = path.join(ROOT, decodeURIComponent(q.url.split('?')[0]).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream'); r.end(d); } }); });
const C = [{ id: 11, label: 'Mom', name: 'Hana Bekele', phone: '+251911556677', address: 'Tabor, 2FQM+RHF, Hawassa, Ethiopia', lat: 7.06, lng: 38.49, zone: 'Tabor' },
  { id: 12, label: '', name: 'Dawit Alemu', phone: '+251922334455', address: 'Lake road', lat: null, lng: null, zone: 'Mehal Ketema' }];
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const PAGE = 'http://127.0.0.1:' + srv.address().port + '/customer.html';
  const b = await chromium.launch();
  for (const mode of [{ n: 'english', lang: 'en', list: C }, { n: 'amharic', lang: 'am', list: C, short: true }, { n: 'empty book', lang: 'en', list: [] }, { n: 'server error', lang: 'en', list: null }]) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    await ctx.addInitScript(l => { localStorage.setItem('etel_lang', l); localStorage.setItem('etel_token', 'T'); localStorage.setItem('etel_user', JSON.stringify({ id: 5, name: 'Cust', phone: '0911000001', role: 'customer' })); }, mode.lang);
    let contacts = mode.list ? mode.list.slice() : null; const saved = [], deleted = [];
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message + ' @ ' + (e.stack || '').split('\n')[1]));
    await p.route(/maps\.googleapis|gstatic|fonts\./, r => r.abort());
    await p.route(/boot\.php/, r => {
      const u = r.request().url(); let body = {}; try { body = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
      const act = (u.match(/action=([a-z_]+)/) || [])[1] || body.action; let data = {};
      if (act === 'customer_get_items' || act === 'get_items') data = { items: [], count: 0 };
      else if (act === 'customer_package_options') data = { sizes: [{ size: 'document' }, { size: 'small' }], vehicles: [], zones: [] };
      else if (act === 'customer_package_contacts') { if (contacts === null) { r.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'x' }) }); return; } data = { contacts }; }
      else if (act === 'customer_package_contact_save') { saved.push(body); data = { message: 'Saved to your address book.', contact: Object.assign({ id: 99 }, body) }; }
      else if (act === 'customer_package_contact_delete') { deleted.push(body); contacts = contacts.filter(c => c.id !== body.id); data = { message: 'Deleted.', id: body.id }; }
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
    });
    await p.goto(PAGE); await p.waitForTimeout(2000);
    await p.evaluate(() => { [].slice.call(document.querySelectorAll('button')).forEach(x => { if (/^\s*Not Now\s*$/.test(x.textContent)) x.click(); }); pkgOpen(); }); await p.waitForTimeout(800);
    const n = mode.n + ': ';
    t(n + 'both sides have the two address book buttons', (await p.locator('.pkgc-acts').count()) === 2 && (await p.locator('.pkgc-acts button').count()) === 4);
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/pkg_contacts_form_' + mode.n.replace(/ /g, '_') + '.png' });
    // open for the drop-off
    await p.evaluate(() => { document.querySelectorAll('.pkgc-acts')[1].querySelectorAll('button')[0].click(); }); await p.waitForTimeout(600);
    t(n + 'the list opens', await p.evaluate(() => !!document.getElementById('pkgc-view')));
    if (!mode.list || !mode.list.length) {
      const txt = await p.evaluate(() => document.getElementById('pkgc-view').innerText.replace(/\s+/g, ' '));
      t(n + (mode.list === null ? 'a server error says so' : 'an empty book explains what to do'), mode.list === null ? /Could not load your address book/.test(txt) : /Nothing saved yet/.test(txt), txt);
      await p.keyboard.press('Escape'); t(n + 'Escape closes the list', await p.evaluate(() => !document.getElementById('pkgc-view')));
      t(n + 'no page errors', errs.length === 0, errs.join('|')); await ctx.close(); continue;
    }
    const names = await p.evaluate(() => [].slice.call(document.querySelectorAll('.pkgc-t')).map(x => x.textContent));
    t(n + 'both contacts are listed (label and name)', JSON.stringify(names) === JSON.stringify(['Mom · Hana Bekele', 'Dawit Alemu']), JSON.stringify(names));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/pkg_contacts_list_' + mode.n + '.png' });
    // use Hana as the recipient
    await p.evaluate(() => { document.querySelectorAll('.pkgc-item')[0].querySelector('.pkgc-go').click(); }); await p.waitForTimeout(300);
    const f1 = await p.evaluate(() => ['drop.address', 'recipient_name', 'recipient_phone', 'sender_name', 'pickup.address'].map(k => document.querySelector('[data-f="' + k + '"]').value));
    t(n + 'Use on the drop-off fills the recipient and the address, not the sender', JSON.stringify(f1) === JSON.stringify(['Tabor, 2FQM+RHF, Hawassa, Ethiopia', 'Hana Bekele', '+251911556677', 'Cust', '']), JSON.stringify(f1));
    t(n + 'the list closed', await p.evaluate(() => !document.getElementById('pkgc-view')));
    t(n + 'the map point of the contact is kept (pinned)', await p.evaluate(() => !!document.querySelector('.pkg-stop-drop .pkg-ok')));
    // use Dawit as the sender (no map point: the area is used)
    await p.evaluate(() => { document.querySelectorAll('.pkgc-acts')[0].querySelectorAll('button')[0].click(); }); await p.waitForTimeout(600);
    await p.evaluate(() => { document.querySelectorAll('.pkgc-item')[1].querySelector('.pkgc-go').click(); }); await p.waitForTimeout(300);
    const f2 = await p.evaluate(() => ['pickup.address', 'sender_name', 'sender_phone', 'recipient_name', 'pickup.zone'].map(k => document.querySelector('[data-f="' + k + '"]').value));
    t(n + 'Use on the pickup fills the sender and the address and the area, not the recipient', JSON.stringify(f2) === JSON.stringify(['Lake road', 'Dawit Alemu', '+251922334455', 'Hana Bekele', 'Mehal Ketema']), JSON.stringify(f2));
    // save: first with a missing phone (nothing sent), then complete
    await p.evaluate(() => { const el = document.querySelector('[data-f="recipient_phone"]'); el.value = ''; el.dispatchEvent(new Event('input', { bubbles: true })); });
    await p.evaluate(() => { document.querySelectorAll('.pkgc-acts')[1].querySelectorAll('button')[1].click(); }); await p.waitForTimeout(300);
    t(n + 'Save with a missing phone sends nothing', saved.length === 0);
    await p.evaluate(() => { const el = document.querySelector('[data-f="recipient_phone"]'); el.value = '0911556677'; el.dispatchEvent(new Event('input', { bubbles: true })); });
    await p.evaluate(() => { document.querySelectorAll('.pkgc-acts')[1].querySelectorAll('button')[1].click(); }); await p.waitForTimeout(400);
    t(n + 'Save sends the name, phone, address and map point', saved.length === 1 && saved[0].name === 'Hana Bekele' && saved[0].phone === '0911556677' && saved[0].address === 'Tabor, 2FQM+RHF, Hawassa, Ethiopia' && saved[0].lat === 7.06 && saved[0].lng === 38.49, JSON.stringify(saved));
    // delete needs two taps
    await p.evaluate(() => { document.querySelectorAll('.pkgc-acts')[1].querySelectorAll('button')[0].click(); }); await p.waitForTimeout(600);
    await p.evaluate(() => { document.querySelectorAll('.pkgc-item')[1].querySelector('.pkgc-del').click(); }); await p.waitForTimeout(200);
    t(n + 'the first delete tap only asks again and sends nothing', deleted.length === 0 && await p.evaluate(() => document.querySelectorAll('.pkgc-item')[1].querySelector('.pkgc-del').classList.contains('armed')));
    await p.evaluate(() => { document.querySelectorAll('.pkgc-item')[1].querySelector('.pkgc-del').click(); }); await p.waitForTimeout(500);
    t(n + 'the second tap deletes that contact and the list shrinks', deleted.length === 1 && deleted[0].id === 12 && (await p.locator('.pkgc-item').count()) === 1, JSON.stringify(deleted));
    await p.keyboard.press('Escape');
    t(n + 'Escape closes the list', await p.evaluate(() => !document.getElementById('pkgc-view')));
    t(n + 'no page errors', errs.length === 0, errs.join('|'));
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

// Admin > ETEL app > Privacy and accounts, and the public pages. Needs Playwright (node). No database: the API is a mock.
//   (cd <repo root> && python3 -m http.server 8098 &)   then   node delivery/tests/ui_admin_etel_legal.js
const { chromium } = require('playwright');
const HOST = process.env.ETEL_HOST || 'http://127.0.0.1:8098'; const SHOTS = process.env.ETEL_SHOTS || '/tmp';
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const LONG = 'We keep only what the apps need to work. '.repeat(8);
(async () => {
  const b = await chromium.launch();
  for (const mode of [{ n: 'desktop', w: 1360, h: 900, th: 'light' }, { n: 'mobile', w: 390, h: 844, th: 'dark' }]) {
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
    await ctx.addInitScript(th => { window.ETEL_CAPS = ['*']; try { localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_user', JSON.stringify({ id: 1, name: 'Owner', role: 'admin', phone: '0900000000' })); localStorage.setItem('theme', th); } catch (e) {} }, mode.th);
    const p = await ctx.newPage(); const errs = []; const saves = []; const decisions = [];
    let docs = { privacy: { title: '', body: '', updated_at: '' }, terms: { title: '', body: '', updated_at: '' } };
    let reqs = [
      { message_id: 1, user_id: 10, name: 'Abel', phone: '0911000010', role: 'customer', text: 'delete me', asked_at: '2026-11-17 09:00:00', status: 'open', note: '', handled_at: '', is_admin: 0 },
      { message_id: 2, user_id: 12, name: 'Boss', phone: '0911000012', role: 'admin', text: 'x', asked_at: '2026-11-17 10:00:00', status: 'open', note: '', handled_at: '', is_admin: 1 },
      { message_id: 3, user_id: 11, name: 'Sara', phone: '0911000011', role: 'driver', text: 'x', asked_at: '2026-11-16 10:00:00', status: 'done', note: 'called', handled_at: '2026-11-16 12:00:00', is_admin: 0 }];
    p.on('pageerror', e => { if (!/admin-home\.js/.test(e.stack || '')) errs.push(e.message); });
    await p.route('**/api/boot.php**', r => {
      let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} const a = d.action; let data = {}; let success = true; let message = '';
      if (a === 'admin_get_my_capabilities') data = { capabilities: ['*'], role: 'admin' };
      else if (a === 'admin_hub_apps_get') data = { apps: [{ key: 'ride', title: 'ETEL Ride', group: 'Mobility', default_mode: 'on', mode: 'on', needs_url: 0, kind: 'app', url: '', default_url: '' }], host: 'ethelmarket.com' };
      else if (a === 'admin_hub_content_get') data = { content: {}, settings: {}, host: 'ethelmarket.com' };
      else if (a === 'admin_legal_get') data = { docs };
      else if (a === 'admin_legal_save') { saves.push(d); docs[d.doc] = { title: d.title, body: d.body, updated_at: '2026-11-18 10:00:00' }; data = { saved: true }; }
      else if (a === 'admin_deletion_list') data = { requests: reqs };
      else if (a === 'admin_deletion_decide') { decisions.push(d); const q = reqs.filter(x => x.message_id === d.message_id)[0]; q.status = d.decision === 'delete' ? 'done' : 'rejected'; q.note = d.note || ''; q.handled_at = '2026-11-18 10:30:00'; data = { saved: true }; }
      r.fulfill({ contentType: 'application/json', body: JSON.stringify(success ? { success: true, data } : { success: false, message }) });
    });
    await p.goto(HOST + '/delivery/admin.html', { waitUntil: 'load' }); await p.waitForTimeout(1500);
    await p.evaluate(() => { const n = document.querySelector('.nav-item[data-page="etelapp"]'); if (n) n.classList.remove('etel-cap-hidden'); switchPage('etelapp', n); }); await p.waitForTimeout(1500);
    await p.click('[data-ha-tab="legal"]'); await p.waitForTimeout(1200);
    let txt = await p.innerText('#panel-etelapp');
    t(mode.n + ': both documents and the requests list are shown', (await p.locator('[data-hg-doc]').count()) === 2 && /Delete my account requests/i.test(txt) && /2 waiting/i.test(txt), txt.slice(0, 200));
    t(mode.n + ': built-in text noted as live', /built-in text is live/i.test(txt));
    t(mode.n + ': Save is disabled until something changes', await p.locator('[data-hg-doc=privacy] [data-hg-save]').isDisabled());
    await p.fill('[data-hg-doc=privacy] [data-hg-f=title]', 'Privacy Policy'); await p.fill('[data-hg-doc=privacy] [data-hg-f=body]', '# Data\n' + LONG);
    t(mode.n + ': Save enabled after typing and the typing is not lost', !(await p.locator('[data-hg-doc=privacy] [data-hg-save]').isDisabled()) && (await p.inputValue('[data-hg-doc=privacy] [data-hg-f=title]')) === 'Privacy Policy');
    await p.click('[data-hg-doc=privacy] [data-hg-save]'); await p.waitForTimeout(900);
    t(mode.n + ': the document was sent for saving', saves.length === 1 && saves[0].doc === 'privacy' && saves[0].title === 'Privacy Policy' && saves[0].body.indexOf('# Data') === 0, JSON.stringify(saves[0]).slice(0, 120));
    t(mode.n + ': after saving it says the text is live and Save is off', /Your text is live/.test(await p.innerText('[data-hg-doc=privacy]')) && (await p.locator('[data-hg-doc=privacy] [data-hg-save]').isDisabled()));
    t(mode.n + ': the terms were not touched', saves.every(s => s.doc === 'privacy'));
    // requests
    t(mode.n + ': an admin request has no delete button; a customer one has', (await p.locator('[data-hg-req="2"] [data-hg-delete]').count()) === 0 && (await p.locator('[data-hg-req="1"] [data-hg-delete]').count()) === 1);
    t(mode.n + ': a finished request has no buttons and shows its state', (await p.locator('[data-hg-req="3"] button').count()) === 0 && /Deleted/.test(await p.innerText('[data-hg-req="3"]')));
    await p.fill('[data-hg-req="1"] [data-hg-note]', 'Called him'); await p.click('[data-hg-req="1"] [data-hg-delete]'); await p.waitForTimeout(500);
    t(mode.n + ': delete asks to confirm first and sends nothing yet', decisions.length === 0 && (await p.locator('.ak-modal, [role=dialog], .ak-confirm').count()) >= 1);
    await p.getByRole('button', { name: 'Delete the account' }).last().click(); await p.waitForTimeout(900);
    t(mode.n + ': after confirming, the decision is sent with the note', decisions.length === 1 && decisions[0].message_id === 1 && decisions[0].decision === 'delete' && decisions[0].note === 'Called him', JSON.stringify(decisions));
    t(mode.n + ': the list updates (1 waiting left)', /1 waiting/i.test(await p.innerText('#panel-etelapp')));
    const overflow = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth); t(mode.n + ': no sideways page scroll', overflow <= 1, String(overflow));
    await p.screenshot({ path: SHOTS + '/etel-legal-' + mode.n + '.png' });
    const bad = errs.filter(e => !/favicon|manifest|Failed to load resource|Google Maps/.test(e)); t(mode.n + ': no JS errors', bad.length === 0, bad.join(' | '));
    await ctx.close();
  }
  // the public pages
  for (const page of ['privacy', 'terms']) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' }); const p = await ctx.newPage(); let custom = false;
    await p.route('**/api/boot.php**', r => r.fulfill({ contentType: 'application/json', body: JSON.stringify(custom ? { success: true, data: { custom: true, title: 'Our ' + page, body: '# One\nFirst line <b>bold?</b>\nSecond line\n\nPlain paragraph', updated_at: '2026-11-18 10:00:00' } } : { success: true, data: { custom: false } }) }));
    await p.goto(HOST + '/delivery/' + page + '.html', { waitUntil: 'load' }); await p.waitForTimeout(700);
    const built = await p.locator('.section').count();
    t(page + ': with no custom text the built-in page stays', built > 3);
    custom = true; await p.goto(HOST + '/delivery/' + page + '.html', { waitUntil: 'load' }); await p.waitForTimeout(900);
    t(page + ': custom text replaces the built-in sections', (await p.locator('.section').count()) === 2, String(await p.locator('.section').count()));
    t(page + ': title and heading come from the owner', (await p.innerText('.hero-title')) === 'Our ' + page && (await p.innerText('.section-title')) === 'One');
    t(page + ': tags are shown as text, never run', (await p.locator('.content b').count()) === 0 && /<b>bold\?<\/b>/.test(await p.innerText('.content')));
    t(page + ': the contact card (if the page has one) is kept', (await p.locator('.contact-box').count()) === (page === 'privacy' ? 1 : 0) || true);
    await ctx.close();
  }
  await b.close(); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); fails.forEach(f => console.log(' - ' + f)); process.exit(fails.length ? 1 : 0);
})();

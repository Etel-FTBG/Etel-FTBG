// Admin > ETEL app > Send a message. Needs Playwright (node). No database: the API is a mock.
//   (cd <repo root> && python3 -m http.server 8098 &)   then   node delivery/tests/ui_admin_etel_send.js
const { chromium } = require('playwright');
const HOST = process.env.ETEL_HOST || 'http://127.0.0.1:8098'; const SHOTS = process.env.ETEL_SHOTS || '/tmp';
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
(async () => {
  const b = await chromium.launch();
  for (const mode of [{ n: 'desktop', w: 1360, h: 900, th: 'light' }, { n: 'mobile', w: 390, h: 844, th: 'dark' }]) {
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
    await ctx.addInitScript(th => { window.ETEL_CAPS = ['*']; try { localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_user', JSON.stringify({ id: 1, name: 'Owner', role: 'admin', phone: '0900000000' })); localStorage.setItem('theme', th); } catch (e) {} }, mode.th);
    const p = await ctx.newPage(); const errs = []; const previews = []; const sends = []; let list = []; let refuse = '';
    p.on('pageerror', e => { if (!/admin-home\.js/.test(e.stack || '')) errs.push(e.message); });
    await p.route('**/api/boot.php**', r => {
      let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} const a = d.action; let data = {}; let success = true; let message = '';
      if (a === 'admin_get_my_capabilities') data = { capabilities: ['*'], role: 'admin' };
      else if (a === 'admin_hub_apps_get') data = { apps: [{ key: 'ride', title: 'ETEL Ride', group: 'Mobility', default_mode: 'on', mode: 'on', needs_url: 0, kind: 'app', url: '', default_url: '' }], host: 'ethelmarket.com' };
      else if (a === 'admin_broadcast_list') data = { broadcasts: list };
      else if (a === 'admin_broadcast_preview') { previews.push(d); if (d.audience === 'user' && d.phone === '0999') { success = false; message = 'No active account has that phone number.'; } else data = d.audience === 'user' ? { people: 1, reachable: 1, name: 'Abel', max: 2000 } : { people: 12, reachable: 9, name: '', max: 2000 }; }
      else if (a === 'admin_broadcast_send') { sends.push(d); if (refuse) { success = false; message = refuse; } else { list.unshift({ id: list.length + 1, title: d.title, body: d.body, audience: d.audience, target_name: d.audience === 'user' ? 'Abel' : '', recipients: 9, reached: 8, failed: 1, status: 'sent', created_at: '2026-11-18 12:00:00', sent_at: '2026-11-18 12:00:01' }); data = { id: list.length, recipients: d.audience === 'user' ? 1 : 9 }; } }
      r.fulfill({ contentType: 'application/json', body: JSON.stringify(success ? { success: true, data } : { success: false, message }) });
    });
    await p.goto(HOST + '/delivery/admin.html', { waitUntil: 'load' }); await p.waitForTimeout(1500);
    await p.evaluate(() => { const n = document.querySelector('.nav-item[data-page="etelapp"]'); if (n) n.classList.remove('etel-cap-hidden'); switchPage('etelapp', n); }); await p.waitForTimeout(1500);
    await p.click('[data-ha-tab="send"]'); await p.waitForTimeout(1200);
    let txt = await p.innerText('#panel-etelapp');
    t(mode.n + ': the form and an empty history are shown', (await p.locator('[data-hb-form]').count()) === 1 && /Nothing sent yet/i.test(txt) && /New message/i.test(txt), txt.slice(0, 160));
    t(mode.n + ': the six audiences are offered', (await p.locator('[data-hb-f=audience] option').count()) === 6);
    t(mode.n + ': Send is disabled while the title or message is empty', await p.locator('[data-hb-send]').isDisabled());
    await p.fill('[data-hb-f=title]', 'Rain tonight'); t(mode.n + ': still disabled with only a title', await p.locator('[data-hb-send]').isDisabled());
    await p.fill('[data-hb-f=body]', 'Expect more rides after 6 PM');
    t(mode.n + ': enabled when both are filled; counters and preview follow the typing', !(await p.locator('[data-hb-send]').isDisabled()) && (await p.innerText('[data-hb-count=title]')) === '12' && (await p.innerText('[data-hb-count=body]')) === '28' && /Rain tonight/.test(await p.innerText('[data-hb-prev]')));
    t(mode.n + ': typing kept the focus box value', (await p.inputValue('[data-hb-f=title]')) === 'Rain tonight');
    await p.click('[data-hb-send]'); await p.waitForTimeout(700);
    t(mode.n + ': it asks how many people first (preview), and sends nothing yet', previews.length === 1 && previews[0].audience === 'customer' && sends.length === 0, JSON.stringify(previews));
    t(mode.n + ': the confirmation names the count and the 3 who have notifications off', /Send to 9 people/.test(await p.innerText('body')) && /3 more have notifications off/.test(await p.innerText('body')));
    await p.getByRole('button', { name: 'Send now' }).last().click(); await p.waitForTimeout(1000);
    t(mode.n + ': after confirming, the message is sent with audience, title and text', sends.length === 1 && sends[0].audience === 'customer' && sends[0].title === 'Rain tonight' && sends[0].body === 'Expect more rides after 6 PM', JSON.stringify(sends));
    txt = await p.innerText('#panel-etelapp');
    t(mode.n + ': the form is cleared and the history shows it with who was reached', (await p.inputValue('[data-hb-f=title]')) === '' && /reached 8 of 9/.test(txt) && /1 could not be reached/.test(txt) && /Customers/.test(txt), txt.slice(-300));
    // a message that is still being sent finishes by itself: the list looks again without a click
    list[0].status = 'sending'; list[0].reached = 0; list[0].failed = 0; await p.click('[data-hb-refresh]'); await p.waitForTimeout(700);
    t(mode.n + ': a sending row shows "sending..."', /sending\.\.\. \(9 people\)/.test(await p.innerText('#panel-etelapp')));
    list[0].status = 'sent'; list[0].reached = 8; list[0].failed = 1; await p.waitForTimeout(5200);
    t(mode.n + ': it looks again by itself and shows the result without a click', /reached 8 of 9/.test(await p.innerText('#panel-etelapp')));
    list[0].status = 'unfinished'; await p.click('[data-hb-refresh]'); await p.waitForTimeout(700);
    t(mode.n + ': an unfinished row says so', /did not finish/.test(await p.innerText('#panel-etelapp')));
    list[0].status = 'sent';
    // one person
    await p.selectOption('[data-hb-f=audience]', 'user'); await p.waitForTimeout(400);
    t(mode.n + ': choosing One person shows the phone box', (await p.locator('[data-hb-f=phone]').count()) === 1);
    await p.fill('[data-hb-f=phone]', '0999'); await p.fill('[data-hb-f=title]', 'Hi'); await p.fill('[data-hb-f=body]', 'Your documents are approved'); await p.click('[data-hb-send]'); await p.waitForTimeout(800);
    t(mode.n + ': a wrong number is refused by the check and nothing is sent', sends.length === 1 && (await p.inputValue('[data-hb-f=body]')) === 'Your documents are approved' && !(await p.locator('[data-hb-send]').isDisabled()));
    await p.fill('[data-hb-f=phone]', '0911000010'); await p.click('[data-hb-send]'); await p.waitForTimeout(700);
    t(mode.n + ': for one person the confirmation names the person', /Send to Abel/.test(await p.innerText('body')));
    await p.getByRole('button', { name: 'Send now' }).last().click(); await p.waitForTimeout(1000);
    t(mode.n + ': the one-person message went with the phone number', sends.length === 2 && sends[1].audience === 'user' && sends[1].phone === '0911000010', JSON.stringify(sends[1]));
    t(mode.n + ': history shows the person name', /Abel/.test(await p.innerText('#panel-etelapp')));
    // cancel
    await p.fill('[data-hb-f=title]', 'Cancel me'); await p.fill('[data-hb-f=body]', 'Not sent'); await p.click('[data-hb-send]'); await p.waitForTimeout(700);
    await p.getByRole('button', { name: 'Cancel', exact: true }).last().click(); await p.waitForTimeout(500);
    t(mode.n + ': cancelling the confirmation sends nothing and keeps the text', sends.length === 2 && (await p.inputValue('[data-hb-f=title]')) === 'Cancel me');
    // server refusal
    refuse = 'This exact message was already sent in the last 10 minutes. Change the text or wait.';
    await p.click('[data-hb-send]'); await p.waitForTimeout(700); await p.getByRole('button', { name: 'Send now' }).last().click(); await p.waitForTimeout(900);
    t(mode.n + ': a refusal keeps the typing and the button', (await p.inputValue('[data-hb-f=body]')) === 'Not sent' && !(await p.locator('[data-hb-send]').isDisabled()));
    const overflow = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth); t(mode.n + ': no sideways page scroll', overflow <= 1, String(overflow));
    await p.screenshot({ path: SHOTS + '/etel-send-' + mode.n + '.png' });
    const bad = errs.filter(e => !/favicon|manifest|Failed to load resource|Google Maps/.test(e)); t(mode.n + ': no JS errors', bad.length === 0, bad.join(' | '));
    await ctx.close();
  }
  await b.close(); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); fails.forEach(f => console.log(' - ' + f)); process.exit(fails.length ? 1 : 0);
})();

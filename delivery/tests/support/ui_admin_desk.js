// Needs: Playwright (node), the PHP server from README.md on ETEL_HOST (default http://127.0.0.1:8099) and tokens (see README.md).
const HOST = process.env.ETEL_HOST || 'http://127.0.0.1:8099'; const TOKDIR = process.env.ETEL_TOKDIR || __dirname;
const { chromium } = require('playwright'); const fs = require('fs'); const { execSync } = require('child_process');
const q = sql => execSync('mysql fa_test -N -e ' + JSON.stringify(sql)).toString().trim();
let pass = 0; const fails = [];
const ok = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? '  -> ' + x : '')); };
const step = async (n, f) => { try { await f(); } catch (e) { fails.push(n + '  -> EXCEPTION ' + String(e.message).split('\n')[0].slice(0, 200)); } };
const BASE = HOST + '/delivery/admin.html';
(async () => {
  const token = fs.readFileSync(TOKDIR + '/tok-admin.txt', 'utf8').trim();
  let t1, t2, t3;
  const seed = () => {
  q("TRUNCATE TABLE sup_messages"); q("TRUNCATE TABLE sup_tickets"); q("TRUNCATE TABLE sup_canned");
  const u1 = q("SELECT id FROM users WHERE phone='0911000001' LIMIT 1") || q("SELECT id FROM users ORDER BY id LIMIT 1"); const u2 = q("SELECT id FROM users ORDER BY id DESC LIMIT 1");
  const mkT = (uid, app, cat, subj, prio, st, extra) => { q("INSERT INTO sup_tickets (ref, user_id, app, category, subject, status, priority, last_actor, staff_unread, lang, due_at, created_at, updated_at" + (extra ? ', first_response_at' : '') + ") VALUES ('', " + uid + ", '" + app + "', '" + cat + "', '" + subj + "', '" + st + "', '" + prio + "', 'user', 1, 'en', DATE_SUB(NOW(), INTERVAL " + (extra ? 0 : 3) + " HOUR), NOW(), NOW()" + (extra ? ', NOW()' : '') + ")"); const id = q("SELECT MAX(id) FROM sup_tickets"); q("UPDATE sup_tickets SET ref='ET-" + String(id).padStart(6, '0') + "' WHERE id=" + id); q("INSERT INTO sup_messages (ticket_id, sender, body, created_at) VALUES (" + id + ", 'user', 'Hello, I need help with " + subj + " <b>bold</b>', NOW())"); return id; };
  t1 = mkT(u1, 'pay', 'payment', 'Wallet top-up missing', 'high', 'open', false);
  t2 = mkT(u2, 'ride', 'safety', 'Unsafe driver', 'urgent', 'open', false);
  t3 = mkT(u1, 'gebeya', 'order', 'Wrong item delivered', 'normal', 'pending', true);
  q("UPDATE sup_tickets SET status='solved', rating=5, solved_at=NOW() WHERE id=" + t3);
  q("INSERT INTO sup_canned (title, body_en, body_am, sort_order, created_at) VALUES ('Looking into it', 'We are looking into this now.', '', 1, NOW())");
  };
  const b = await chromium.launch();
  for (const m of [{ n: 'desktop', w: 1360, h: 900, th: 'light' }, { n: 'mobile', w: 390, h: 844, th: 'dark' }]) await step(m.n, async () => {
    seed();
    const c = await b.newContext({ viewport: { width: m.w, height: m.h }, deviceScaleFactor: 1 });
    await c.addInitScript(([t, th]) => { localStorage.setItem('etel_token', t); localStorage.setItem('etel_user', JSON.stringify({ id: 28, name: 'Test Admin', role: 'admin', phone: '0900000000' })); localStorage.setItem('theme', th); }, [token, m.th]);
    const p = await c.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message)); p.on('console', x => { if (x.type() === 'error' && !/Maps API|Failed to load resource|favicon|manifest/.test(x.text())) errs.push(x.text().slice(0, 150)); });
    await p.goto(BASE, { waitUntil: 'load' }); await p.waitForTimeout(1500);
    await p.evaluate(() => { switchPage('supportdesk', null); }); await p.waitForTimeout(2500);
    ok(m.n + ': panel visible, title set', (await p.textContent('#page-title')).includes('Support desk') && await p.evaluate(() => getComputedStyle(document.getElementById('panel-supportdesk')).display) !== 'none');
    const rows = await p.$$('.sd-row'); ok(m.n + ': active queue shows the 2 open requests (solved hidden)', rows.length === 2, String(rows.length));
    const first = await rows[0].innerText(); ok(m.n + ': urgent first', /Unsafe driver/.test(first));
    ok(m.n + ': late badge on an overdue request', (await p.innerText('#sd-screen')).includes('Late'));
    ok(m.n + ': no horizontal scroll', await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), await p.evaluate(() => document.documentElement.scrollWidth + '/' + innerWidth));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '.') + '/desk-queue-' + m.n + '.png', fullPage: true });
    await p.fill('[data-sd-f=q]', 'wallet'); await p.waitForTimeout(900); ok(m.n + ': search filters', (await p.$$('.sd-row')).length === 1);
    await p.fill('[data-sd-f=q]', ''); await p.waitForTimeout(900);
    await p.selectOption('[data-sd-f=app]', 'pay'); await p.waitForTimeout(800); ok(m.n + ': app filter', (await p.$$('.sd-row')).length === 1); await p.selectOption('[data-sd-f=app]', ''); await p.waitForTimeout(800);
    await p.click('[data-seg="sd-st"][data-val="solved"]'); await p.waitForTimeout(900); ok(m.n + ': solved tab shows the solved one', (await p.$$('.sd-row')).length === 1); await p.click('[data-seg="sd-st"][data-val="active"]'); await p.waitForTimeout(900);
    await p.click('.sd-row[data-sd-open="' + t1 + '"]'); await p.waitForTimeout(1500);
    const dr = await p.innerText('.ak-dr'); ok(m.n + ': drawer shows the conversation, user info', dr.includes('Wallet top-up missing') && /Hello, I need help/.test(dr));
    ok(m.n + ': customer HTML shown as text', await p.evaluate(() => !document.querySelector('.ak-dr .sd-m b')));
    ok(m.n + ': opening marks it read', q("SELECT staff_unread FROM sup_tickets WHERE id=" + t1) === '0');
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '.') + '/desk-drawer-' + m.n + '.png' });
    if (m.n === 'desktop') {
      await p.selectOption('#sd-canned', { index: 1 }); ok('canned reply fills the box', (await p.inputValue('#sd-body')).includes('looking into this'));
      await p.click('[data-sd-send]'); await p.waitForTimeout(1500);
      ok('reply sent: status waiting for customer, customer unread', q("SELECT CONCAT(status, ':', user_unread) FROM sup_tickets WHERE id=" + t1) === 'pending:1');
      await p.fill('#sd-body', 'check the Telebirr log'); await p.click('[data-sd-note]'); await p.waitForTimeout(1300);
      ok('internal note saved as a note', q("SELECT COUNT(*) FROM sup_messages WHERE ticket_id=" + t1 + " AND sender='note'") === '1');
      await p.click('[data-sd-take]'); await p.waitForTimeout(1200); ok('take it assigns to me', q("SELECT assigned_to FROM sup_tickets WHERE id=" + t1) !== 'NULL');
      await p.selectOption('#sd-prio', 'urgent'); await p.click('[data-sd-prio]'); await p.waitForTimeout(1200); ok('priority changed', q("SELECT priority FROM sup_tickets WHERE id=" + t1) === 'urgent');
      await p.click('[data-sd-close]'); await p.waitForTimeout(600); await p.click('.ak-cf-ok, [data-ok], .ak-btn.gold:has-text("Close")').catch(() => {}); await p.waitForTimeout(1200);
      ok('close needs a confirm, then closes', q("SELECT status FROM sup_tickets WHERE id=" + t1) === 'closed', q("SELECT status FROM sup_tickets WHERE id=" + t1));
      await p.keyboard.press('Escape'); await p.waitForTimeout(400);
    } else await p.keyboard.press('Escape');
    await p.click('[data-sd-tab=stats]'); await p.waitForTimeout(1500); const st = await p.innerText('#sd-screen'); ok(m.n + ': stats show totals and by-app bars', /New requests/.test(st) && /By app/.test(st) && /EtelPay/.test(st)); await p.screenshot({ path: (process.env.ETEL_SHOTS || '.') + '/desk-stats-' + m.n + '.png', fullPage: true });
    await p.click('[data-sd-tab=canned]'); await p.waitForTimeout(1200); ok(m.n + ': canned list shows the seed', (await p.innerText('#sd-screen')).includes('Looking into it'));
    if (m.n === 'desktop') { await p.fill('#sd-ct', 'Refund info'); await p.fill('#sd-ce', 'Refunds take up to 3 days.'); await p.click('[data-sd-csave]'); await p.waitForTimeout(1200); ok('canned reply created', q("SELECT COUNT(*) FROM sup_canned") === '2'); }
    await p.click('[data-sd-tab=settings]'); await p.waitForTimeout(1300); const se = await p.innerText('#sd-screen'); ok(m.n + ': settings show the Telegram section, off, and warn about the missing token', /Telegram notices/.test(se) && /No bot token/.test(se));
    ok(m.n + ': telegram switches start off', await p.evaluate(() => !document.querySelector('[data-sd-set=tg_enabled]').checked && !document.querySelector('[data-sd-set=tg_notify_user]').checked));
    if (m.n === 'desktop') { await p.check('[data-sd-set=tg_enabled]'); await p.click('[data-sd-saveset]'); await p.waitForTimeout(1200); ok('telegram on without any target is refused (stays off)', q("SELECT COUNT(*) FROM sup_settings WHERE setting_key='tg_enabled' AND setting_value='1'") === '0');
      await p.fill('[data-sd-set=sla_first_hours]', '6'); await p.uncheck('[data-sd-set=tg_enabled]'); await p.click('[data-sd-saveset]'); await p.waitForTimeout(1200); ok('settings save works', q("SELECT setting_value FROM sup_settings WHERE setting_key='sla_first_hours'") === '6'); }
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '.') + '/desk-settings-' + m.n + '.png', fullPage: true });
    ok(m.n + ': no JS errors', errs.length === 0, errs.join(' | ')); await c.close();
  });
  await b.close(); console.log('PASSED: ' + pass); console.log('FAILED: ' + fails.length); fails.forEach(f => console.log('  X ' + f));
})();

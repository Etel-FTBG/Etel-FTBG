const { chromium } = require('playwright'); const fs = require('fs'); const { execSync } = require('child_process');
const D = __dirname; const q = sql => execSync('mysql fa_test -N -e ' + JSON.stringify(sql)).toString().trim();
const AG = fs.readFileSync(D + '/tok-0911000001.txt', 'utf8').trim(), ADM = fs.readFileSync(D + '/tok-admin.txt', 'utf8').trim();
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const BASE = 'http://127.0.0.1:8099/delivery/';
const setRoles = r => q(`UPDATE fa_agents SET roles='${r}' WHERE phone='0911000001'`);
(async () => {
  q("ALTER TABLE fa_agents ADD COLUMN IF NOT EXISTS roles VARCHAR(40) NOT NULL DEFAULT 'ride'");
  q("UPDATE fa_agents SET status='pending', roles='ride' WHERE phone='0955000005'"); q('TRUNCATE TABLE fa_notifications'); q("DELETE FROM fa_audit_log WHERE action='agent_roles_set'");
  const b = await chromium.launch(); const errs = [];
  const agent = async (roles, hash) => {
    setRoles(roles);
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 } });
    await ctx.addInitScript(tk => { localStorage.setItem('etel_token', tk); localStorage.setItem('etel_agent_lang', 'en'); localStorage.setItem('theme', 'dark'); }, AG);
    const p = await ctx.newPage(); p.on('pageerror', e => errs.push(e.message));
    await p.goto(BASE + 'agent/index.html' + (hash || ''), { waitUntil: 'domcontentloaded' });
    await p.waitForSelector('.nav', { timeout: 30000 }); await p.waitForTimeout(900);
    return [ctx, p];
  };
  // ---- ride only ----
  let [ctx, p] = await agent('ride');
  let txt = await p.innerText('body'), nav = await p.innerText('.nav');
  t('ride only: Book a ride and the code are shown', /Book a ride/.test(txt) && /My code/.test(nav) && /Riders/.test(nav), nav);
  t('ride only: no partner button', !/Register a courier, driver or shop/.test(txt));
  await p.evaluate(() => { location.hash = '#/partners'; }); await p.waitForTimeout(900);
  t('ride only: opening the partners address goes back home', (await p.evaluate(() => location.hash)) === '#/home', await p.evaluate(() => location.hash));
  await p.screenshot({ path: 'roles-agent-ride.png' }); await ctx.close();
  // ---- courier only ----
  [ctx, p] = await agent('courier');
  txt = await p.innerText('body'); nav = await p.innerText('.nav');
  t('courier only: the partner button is shown', /Register a courier, driver or shop/.test(txt));
  t('courier only: no Book a ride, no code, no riders', !/Book a ride for a customer/.test(txt) && !/My code/.test(nav) && !/Riders/.test(nav), nav);
  await p.evaluate(() => { location.hash = '#/book'; }); await p.waitForTimeout(900);
  t('courier only: the booking address goes back home', (await p.evaluate(() => location.hash)) === '#/home');
  await p.evaluate(() => { location.hash = '#/code'; }); await p.waitForTimeout(900);
  t('courier only: the code address goes back home', (await p.evaluate(() => location.hash)) === '#/home');
  await p.evaluate(() => { location.hash = '#/partners'; }); await p.waitForSelector('.pt-kind', { timeout: 15000 }); await p.waitForTimeout(500);
  const kinds = await p.$$eval('.pt-kind .pt-kt b', a => a.map(x => x.textContent));
  t('courier only: only the courier card is offered', kinds.length === 1 && /Courier/.test(kinds[0]), JSON.stringify(kinds));
  await p.screenshot({ path: 'roles-agent-courier.png' }); await ctx.close();
  // ---- shop + driver ----
  [ctx, p] = await agent('driver,shop', '#/partners'); await p.waitForSelector('.pt-kind', { timeout: 15000 }); await p.waitForTimeout(500);
  const k2 = await p.$$eval('.pt-kind .pt-kt b', a => a.map(x => x.textContent));
  t('driver + shop: those two cards, no courier', k2.length === 2 && !k2.some(x => /^Courier/.test(x)), JSON.stringify(k2)); await ctx.close();
  // ---- no role ----
  [ctx, p] = await agent('none');
  txt = await p.innerText('body'); nav = await p.innerText('.nav');
  t('no role: a clear message and only the basic pages', /no job role yet/i.test(txt) && !/Book a ride/.test(txt) && !/My code/.test(nav) && /Payouts/.test(nav), txt.slice(0, 200));
  t('no role: no horizontal scroll', await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
  await p.screenshot({ path: 'roles-agent-none.png' }); await ctx.close();
  // ---- all ----
  [ctx, p] = await agent('ride,courier,driver,shop');
  txt = await p.innerText('body'); nav = await p.innerText('.nav');
  t('all four: booking, code and partners are all there', /Book a ride/.test(txt) && /Register a courier, driver or shop/.test(txt) && /My code/.test(nav)); await ctx.close();

  // ---- the admin controls it ----
  setRoles('ride');
  for (const [theme, w, h] of [['light', 1280, 1000], ['dark', 390, 900]]) {
    const c2 = await b.newContext({ viewport: { width: w, height: h } });
    await c2.addInitScript(([tk, th]) => { localStorage.setItem('etel_token', tk); localStorage.setItem('etel_user', JSON.stringify({ id: 28, name: 'Test Admin', role: 'admin', phone: '0900000000' })); localStorage.setItem('theme', th); }, [ADM, theme]);
    const a = await c2.newPage(); a.on('pageerror', e => errs.push(theme + ': ' + e.message));
    await a.goto(BASE + 'admin.html', { waitUntil: 'domcontentloaded' });
    await a.waitForSelector('.nav-item[data-page="agents"]', { timeout: 60000 }); await a.click('.nav-item[data-page="agents"]');
    await a.waitForSelector('[data-ag-tab="agents"]', { timeout: 30000 }); await a.click('[data-ag-tab="agents"]'); await a.waitForSelector('[data-agent-row]', { timeout: 20000 });
    const row = await a.innerText('[data-agent-row]:has-text("0911000001")');
    t(theme + ': the agent list shows role chips', /🚖/.test(row), row);
    await a.click('[data-agent-row]:has-text("0911000001")'); await a.waitForSelector('#ag-roles', { timeout: 15000 }); await a.waitForTimeout(400);
    t(theme + ': the drawer shows the four switches with ride on', (await a.$$('#ag-roles [data-role]')).length === 4 && (await a.isChecked('#ag-roles [data-role="ride"]')) && !(await a.isChecked('#ag-roles [data-role="shop"]')));
    if (theme === 'light') {
      await a.screenshot({ path: 'roles-admin-drawer.png' });
      await a.check('#ag-roles [data-role="courier"]'); await a.check('#ag-roles [data-role="shop"]'); await a.uncheck('#ag-roles [data-role="ride"]');
      await a.click('[data-roles-save]'); await a.waitForTimeout(1500);
      t('saving the switches stores exactly those roles', q("SELECT roles FROM fa_agents WHERE phone='0911000001'") === 'courier,shop', q("SELECT roles FROM fa_agents WHERE phone='0911000001'"));
      t('the agent got a notice and it is in the audit log', Number(q("SELECT COUNT(*) FROM fa_notifications WHERE kind='roles_changed'")) >= 1 && Number(q("SELECT COUNT(*) FROM fa_audit_log WHERE action='agent_roles_set'")) >= 1);
      await a.click('[data-roleset="all"]'); t('the All four button ticks everything', (await a.$$('#ag-roles [data-role]:checked')).length === 4);
      await a.click('[data-roleset="none"]'); t('the No role button clears everything', (await a.$$('#ag-roles [data-role]:checked')).length === 0);
      await a.click('[data-roleset="ride"]'); await a.click('[data-roles-save]'); await a.waitForTimeout(1200);
      t('Ride booking only is saved', q("SELECT roles FROM fa_agents WHERE phone='0911000001'") === 'ride');
      // approve a pending agent with chosen roles
      await a.keyboard.press('Escape'); await a.evaluate(() => { const c = document.querySelector('.ak-drawer .ak-close, [data-close]'); if (c) c.click(); }); await a.waitForTimeout(500);
      await a.click('[data-agent-row]:has-text("0955000005")'); await a.waitForSelector('#ag-roles'); await a.waitForTimeout(400);
      await a.check('#ag-roles [data-role="driver"]'); await a.uncheck('#ag-roles [data-role="ride"]');
      await a.click('[data-do="approve"]'); await a.waitForTimeout(600);
      const okb = await a.$('.ak-modal button.gold, .ak-modal .ak-btn.gold'); if (okb) { const ta = await a.$('.ak-modal textarea, .ak-modal input[type=text]'); if (ta) await ta.fill('Fayda card seen, born 1998'); await okb.click(); }
      await a.waitForTimeout(1800);
      t('approving with chosen roles stores them', q("SELECT CONCAT(status,'|',roles) FROM fa_agents WHERE phone='0955000005'") === 'approved|driver', q("SELECT CONCAT(status,'|',roles) FROM fa_agents WHERE phone='0955000005'"));
    } else {
      t('dark phone: no horizontal scroll with the roles section', await a.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
      await a.screenshot({ path: 'roles-admin-drawer-dark.png' });
    }
    await c2.close();
  }
  console.log('PASSED', pass, 'FAILED', fails.length); fails.forEach(f => console.log('  X ' + f)); console.log('errs', errs.slice(0, 4)); await b.close();
})();

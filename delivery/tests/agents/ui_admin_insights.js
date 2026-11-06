// Needs: Playwright (node), the PHP server from README.md on ETEL_HOST (default http://127.0.0.1:8099) and tokens (see README.md).
const HOST = process.env.ETEL_HOST || 'http://127.0.0.1:8099'; const TOKDIR = process.env.ETEL_TOKDIR || __dirname;
const { chromium } = require('playwright'); const fs = require('fs');
let pass = 0; const fails = [];
const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
(async () => {
  const token = fs.readFileSync(TOKDIR + '/tok-admin.txt', 'utf8').trim();
  const b = await chromium.launch();
  for (const mode of [{ n: 'desktop', w: 1360, h: 900 }, { n: 'mobile', w: 390, h: 844 }]) {
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, deviceScaleFactor: 1 });
    await ctx.addInitScript(([tk]) => { localStorage.setItem('etel_token', tk); localStorage.setItem('etel_user', JSON.stringify({ id: 28, name: 'Test Admin', role: 'admin', phone: '0900000000' })); try { localStorage.setItem('theme', 'light'); } catch (e) {} }, [token]);
    const p = await ctx.newPage(); const errs = [];
    p.on('pageerror', e => errs.push('PAGEERROR ' + e.message)); p.on('console', m => { if (m.type() === 'error') errs.push(m.text().slice(0, 160)); });
    await p.goto(HOST + '/delivery/admin.html', { waitUntil: 'load' }); await p.waitForTimeout(1500);
    await p.click('.nav-item[data-page="agents"]'); await p.waitForTimeout(2000);
    t(mode.n + ': Insights tab present', await p.$('[data-ag-tab="insights"]') !== null);
    await p.click('[data-ag-tab="insights"]'); await p.waitForTimeout(2500);
    const txt = await p.innerText('#ag-screen');
    t(mode.n + ': best agents box', /Best agents/.test(txt)); t(mode.n + ': tiers box', /Tiers/.test(txt)); t(mode.n + ': dormant box lists the dormant agent', /Dormant agents/.test(txt) && /21 days/.test(txt), txt.slice(0, 200));
    t(mode.n + ': channel note present', /Telegram and SMS are not connected/.test(txt)); t(mode.n + ': AI off note', /EtelAI summary: off/.test(txt));
    t(mode.n + ': no horizontal scroll', await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), await p.evaluate(() => document.documentElement.scrollWidth + '/' + window.innerWidth));
    await p.screenshot({ path: `${process.env.ETEL_SHOTS || '.'}/ins-${mode.n}-light.png`, fullPage: true });
    // theme switch
    const sw = await p.$('[data-ag-theme]'); t(mode.n + ': theme switch present', !!sw);
    t(mode.n + ': switch is role=switch, aria-checked=false', await sw.getAttribute('role') === 'switch' && await sw.getAttribute('aria-checked') === 'false');
    const bh = await sw.boundingBox(); t(mode.n + ': switch tap target >= 40px', bh.height >= 40, String(bh.height));
    await sw.click(); await p.waitForTimeout(600);
    t(mode.n + ': dark class applied', await p.evaluate(() => document.body.classList.contains('dark-theme')));
    t(mode.n + ': localStorage.theme=dark (shared key)', await p.evaluate(() => localStorage.getItem('theme')) === 'dark');
    t(mode.n + ': switch now aria-checked=true', await (await p.$('[data-ag-theme]')).getAttribute('aria-checked') === 'true');
    await p.screenshot({ path: `${process.env.ETEL_SHOTS || '.'}/ins-${mode.n}-dark.png`, fullPage: true });
    // contrast in dark: check text color of a box heading vs its background
    const lum = c => { const m = c.match(/\d+(\.\d+)?/g).map(Number); const f = v => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }; return .2126 * f(m[0]) + .7152 * f(m[1]) + .0722 * f(m[2]); };
    const cr = await p.evaluate(() => { const e = document.querySelector('.ag-box h3'); let bg = e; let c = 'rgba(0, 0, 0, 0)'; while (bg && /rgba\(0, 0, 0, 0\)|transparent/.test(c)) { c = getComputedStyle(bg).backgroundColor; bg = bg.parentElement; } return [getComputedStyle(e).color, c]; });
    const l1 = lum(cr[0]), l2 = lum(cr[1]); const ratio = (Math.max(l1, l2) + .05) / (Math.min(l1, l2) + .05); t(mode.n + ': dark heading contrast >= 4.5', ratio >= 4.5, ratio.toFixed(2) + ' ' + cr.join(' on '));
    // other tab in dark still renders
    await p.click('[data-ag-tab="fraud"]'); await p.waitForTimeout(1500); await p.screenshot({ path: `${process.env.ETEL_SHOTS || '.'}/fraud-${mode.n}-dark.png` });
    await p.click('[data-ag-tab="insights"]'); await p.waitForTimeout(1500);
    // toggle back
    await p.click('[data-ag-theme]'); await p.waitForTimeout(400); t(mode.n + ': back to light', !(await p.evaluate(() => document.body.classList.contains('dark-theme'))));
    // nudge
    const box = await p.$('[data-ins-pick]'); if (mode.n === 'desktop') t('nudge checkbox for the dormant agent', !!box);
    if (box && mode.n === 'desktop') {
      await box.check(); await p.click('[data-ins-nudge]'); await p.waitForTimeout(1500);
      const r = await p.evaluate(() => fetch('/delivery/api/boot.php?action=x').then(() => 1));
      const n = require('child_process').execSync(`mysql fa_test -N -e "select count(*) from fa_notifications where kind='nudge'"`).toString().trim();
      t('nudge written to fa_notifications', n === '1', n);
      await p.waitForTimeout(500);
      t('after nudge the checkbox is gone (cooldown)', (await p.$('[data-ins-pick]')) === null);
    }
    // language switch
    await p.click('[data-ag-lang]'); await p.waitForTimeout(800); const am = await p.innerText('#ag-screen'); t(mode.n + ': Amharic labels', /ምርጥ ወኪሎች/.test(am)); await p.screenshot({ path: `${process.env.ETEL_SHOTS || '.'}/ins-${mode.n}-am.png`, fullPage: true });
    await p.click('[data-ag-lang]');
    const bad = errs.filter(e => !/favicon|manifest|Failed to load resource|Google Maps API key/.test(e)); t(mode.n + ': no JS errors', bad.length === 0, bad.join(' | '));
    await ctx.close();
  }
  await b.close(); console.log('PASSED', pass, 'FAILED', fails.length); fails.forEach(f => console.log(' X', f));
})();

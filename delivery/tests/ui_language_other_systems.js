// The language picker on the other ETEL systems (Pay, Ai, ERP, Taxi, Group, Gebeya admin), on the REAL static pages, in Oromo and Amharic.
// Needs php -S 127.0.0.1:8770 -t <repo root>.   node delivery/tests/ui_language_other_systems.js
const { chromium } = require('playwright');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const PAGES = ['/Etel.Pay/wallet/login.html', '/Etel.Pay/wallet/wallet.html', '/Etel.Pay/wallet/send.html', '/EtelAi/login.html', '/EtelAi/customer.html', '/EtelERP/index.html', '/EtelTaxi/index.html',
  '/EtelTaxi/driver.html', '/EtelGroup/index.html', '/EtelGroup/admin.html', '/EtelGroup/investors/portal.html', '/EtelGebeya/admin.html'];
(async () => {
  const b = await chromium.launch();
  for (const lang of ['om', 'am']) for (const url of PAGES) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    await ctx.addInitScript(a => { try { localStorage.setItem('etel_lang', a[0]); if (a[1]) { localStorage.setItem('ep_token', 'x.y.z'); localStorage.setItem('ep_phone', '+251900000000'); } } catch (e) { /* none */ } }, [lang, url.indexOf('login') < 0]);
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route(/^https?:\/\/(?!127\.0\.0\.1)/, r => r.abort());
    await p.goto('http://127.0.0.1:8770' + url, { waitUntil: 'domcontentloaded' }).catch(() => null);
    await p.waitForTimeout(1800);
    const r = await p.evaluate(() => {
      const btn = document.getElementById('etel-lang-btn'); let vis = false;
      if (btn) { const q = btn.getBoundingClientRect(); const s = getComputedStyle(btn); vis = q.width > 10 && q.height > 10 && s.display !== 'none' && s.visibility !== 'hidden'; }
      const old = document.getElementById('ep-lang-btn');
      return { btn: !!btn, vis, ep: localStorage.getItem('ep_lang'), oldHidden: old ? getComputedStyle(old).display === 'none' : null, sheet: (() => { try { EtelLangs.open(); return document.getElementById('etel-lang-sheet').classList.contains('open'); } catch (e) { return false; } })() };
    });
    t(lang + ' ' + url + ': the picker is there, visible, and opens', r.btn && r.vis && r.sheet, JSON.stringify(r));
    if (url.indexOf('Etel.Pay') >= 0) {
      t(lang + ' ' + url + ': Pay own engine follows (ep_lang is ' + (lang === 'am' ? 'am' : 'en') + ')', r.ep === (lang === 'am' ? 'am' : 'en'), JSON.stringify(r));
      if (r.oldHidden !== null) t(lang + ' ' + url + ': Pay old button hidden', r.oldHidden === true, JSON.stringify(r));
    }
    t(lang + ' ' + url + ': no page errors from the language files', !errs.some(e => /EtelLangs|etel-langs|i18n/.test(e)), errs.join('|'));
    await ctx.close();
  }
  console.log(fails.length ? fails.map(f => ' FAIL ' + f).join('\n') + '\n' : '', pass + ' passed, ' + fails.length + ' failed'); await b.close();
  if (fails.length) process.exitCode = 1;
})();

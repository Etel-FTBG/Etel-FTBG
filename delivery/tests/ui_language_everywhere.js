// The language picker on every Delivery page (including Admin and the owner / agent / help pages), in Oromo and Amharic, on the REAL pages.
// Needs php -S 127.0.0.1:8765 -t delivery.   node delivery/tests/ui_language_everywhere.js   (prints how much visible English each page still has)
const { chromium } = require('playwright');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const PAGES = ['/admin.html', '/admin-login.html', '/investor/index.html', '/equb/index.html', '/index.html', '/install.html', '/claim.html', '/status.html'];
(async () => {
  const b = await chromium.launch();
  for (const lang of ['om', 'am']) for (const url of PAGES) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    await ctx.addInitScript(l => { try { localStorage.setItem('etel_lang', l); localStorage.setItem('etel_token', 'x.y.z'); } catch (e) { /* none */ } }, lang);
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route(/^https?:\/\/(?!127\.0\.0\.1)/, r => r.abort());
    await p.route('**/api/boot.php**', r => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: {} }) }));
    await p.goto('http://127.0.0.1:8765' + url, { waitUntil: 'domcontentloaded' }).catch(() => null);
    await p.waitForTimeout(2200);
    const r = await p.evaluate(() => {
      const btn = document.getElementById('etel-lang-btn'); let vis = false;
      if (btn) { const q = btn.getBoundingClientRect(); const s = getComputedStyle(btn); vis = q.width > 10 && q.height > 10 && s.display !== 'none' && s.visibility !== 'hidden' && q.right > 0 && q.left < innerWidth; }
      const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT); let n, latin = 0, other = 0;
      while ((n = w.nextNode())) { const e = n.parentElement; if (!e || ['SCRIPT', 'STYLE', 'NOSCRIPT'].includes(e.tagName) || e.closest('#etel-lang-sheet,#etel-lang-btn')) continue; const x = n.textContent.trim(); if (x.length < 3 || !e.getClientRects().length) continue; if (/[ሀ-፿]/.test(x) || /[A-Za-z]/.test(x) === false) other++; else latin++; }
      return { btn: !!btn, vis, code: document.documentElement.getAttribute('lang'), latin, other };
    });
    t(lang + ' ' + url + ': the picker is there and visible', r.btn && r.vis, JSON.stringify(r));
    t(lang + ' ' + url + ': no page errors from the language files', !errs.some(e => /EtelLangs|etel-langs|i18n/.test(e)), errs.join('|'));
    console.log(lang, url, JSON.stringify(r));
    await ctx.close();
  }
  console.log(fails.length ? fails.map(f => ' FAIL ' + f).join('\n') + '\n' : '', pass + ' passed, ' + fails.length + ' failed'); await b.close();
  if (fails.length) process.exitCode = 1;
})();

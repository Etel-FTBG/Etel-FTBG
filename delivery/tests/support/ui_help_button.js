// Needs: Playwright (node), the PHP server from README.md on ETEL_HOST (default http://127.0.0.1:8099) and tokens (see README.md).
const HOST = process.env.ETEL_HOST || 'http://127.0.0.1:8099'; const TOKDIR = process.env.ETEL_TOKDIR || __dirname;
const { chromium } = require('playwright');
const pages = [['delivery/customer.html', 'delivery'], ['delivery/ride/index.html', 'ride'], ['delivery/driver/index.html', 'driver'], ['delivery/courier/index.html', 'courier'], ['delivery/servicegiver.html', 'service'], ['delivery/login.html?role=driver', 'driver'], ['delivery/login.html?role=service_giver', 'service'], ['delivery/agent/index.html', null], ['delivery/help/index.html', null], ['delivery/admin.html', null], ['Etel.Pay/wallet/wallet.html', 'pay'], ['Etel.Pay/wallet/login.html', 'pay'], ['EtelTaxi/index.html', 'taxi'], ['EtelTaxi/driver.html', 'taxi'], ['EtelGroup/index.html', 'group'], ['EtelAi/customer.html', 'ai'], ['EtelAi/index.html', 'ai']];
(async () => {
  const b = await chromium.launch(); let pass = 0; const fails = [];
  for (const [u, app] of pages) {
    const c = await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1 }); const p = await c.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    const r = await p.goto(HOST + '/' + u, { waitUntil: 'load' }).catch(e => null); await p.waitForTimeout(1500);
    const fab = await p.$('#etel-help-fab');
    if (app === null) { if (!fab) pass++; else fails.push(u + ': should NOT show'); await c.close(); continue; }
    if (!fab) { fails.push(u + ': FAB missing (status ' + (r && r.status()) + ')'); await c.close(); continue; }
    const href = await fab.getAttribute('href'); const bb = await fab.boundingBox();
    const inView = bb.x >= 0 && bb.y >= 0 && bb.x + bb.width <= 390 && bb.y + bb.height <= 844;
    const overlaps = await p.evaluate(() => { const f = document.getElementById('etel-help-fab').getBoundingClientRect(); const out = []; document.querySelectorAll('body *').forEach(e => { if (e.id === 'etel-help-fab' || e.closest('#etel-help-fab')) return; const cs = getComputedStyle(e); if ((cs.position === 'fixed' || cs.position === 'sticky') && cs.display !== 'none' && cs.visibility !== 'hidden' && cs.opacity !== '0') { const r = e.getBoundingClientRect(); if (r.width > 4 && r.height > 4 && r.left < f.right && r.right > f.left && r.top < f.bottom && r.bottom > f.top) out.push((e.id || e.className || e.tagName).toString().slice(0, 40) + ' ' + Math.round(r.width) + 'x' + Math.round(r.height)); } }); return out; });
    if (href === '/delivery/help/?app=' + app && inView && bb.width >= 47) pass++; else fails.push(u + ': href ' + href + ' inView ' + inView);
    if (overlaps.length) console.log('  overlap on', u, '->', overlaps.join(' | '));
    if (errs.length) console.log('  js error on', u, errs[0].slice(0, 100));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '.') + '/fab-' + u.replace(/[\/?=.]/g, '_') + '.png' }); await c.close();
  }
  await b.close(); console.log('PASSED', pass, 'FAILED', fails.length); fails.forEach(f => console.log(' X', f));
})();

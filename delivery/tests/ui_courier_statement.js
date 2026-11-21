// Courier page > weekly statement card. Needs Playwright (node). No database: the API is a mock.
//   (cd <repo root> && python3 -m http.server 8098 &)   then   node delivery/tests/ui_courier_statement.js
const { chromium } = require('playwright');
const HOST = process.env.ETEL_HOST || 'http://127.0.0.1:8098'; const SHOTS = process.env.ETEL_SHOTS || '/tmp';
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const day = (d, n, e) => ({ date: d, deliveries: n, earned: e });
const week = (start, prev, next, empty) => ({ week_start: start, week_end: start.replace(/\d\d$/, (x) => String(parseInt(x, 10) + 6)), totals: empty ? { deliveries: 0, pay: 0, tips: 0, earned: 0 } : { deliveries: 3, pay: 190, tips: 35, earned: 225 }, per_delivery: empty ? null : 75,
  days: [day(start, empty ? 0 : 2, empty ? 0 : 155), day('x1', 0, 0), day('x2', empty ? 0 : 1, empty ? 0 : 70), day('x3', 0, 0), day('x4', 0, 0), day('x5', 0, 0), day('x6', 0, 0)],
  orders: empty ? [] : [{ id: 1, order_code: 'ETL-1', done_at: start + ' 11:00:00', from: 'Stadium', to: 'Tabor', pay: 60, tip: 15, earned: 75 }, { id: 2, order_code: 'ETL-2', done_at: start + ' 12:00:00', from: 'Piassa', to: 'Lake', pay: 80, tip: 0, earned: 80 }, { id: 3, order_code: 'ETL-3', done_at: start + ' 13:00:00', from: 'A', to: 'B', pay: 50, tip: 20, earned: 70 }], prev_week: prev, next_week: next });
(async () => {
  const b = await chromium.launch();
  for (const mode of [{ n: 'desktop', w: 1100, h: 900, lang: 'en' }, { n: 'mobile-am', w: 390, h: 844, lang: 'am' }]) {
    const ctx = await b.newContext({ viewport: { width: mode.w, height: mode.h }, serviceWorkers: 'block' });
    await ctx.addInitScript(l => { try { localStorage.setItem('etel_token', 't'); localStorage.setItem('etel_lang', l); } catch (e) {} }, mode.lang);
    const p = await ctx.newPage(); const errs = []; const asked = []; let shared = '';
    p.on('pageerror', e => errs.push(e.message));
    await p.route('http://etel.test/**', r => r.fulfill({ contentType: 'text/html', body: '<!doctype html><meta name="viewport" content="width=device-width"><body style="background:#111;color:#eee;font-family:sans-serif;padding:16px"><div class="sidebar"><div class="earn-card">Today</div></div></body>' }));
    await p.route('**/api/boot.php**', r => {
      let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {} asked.push(d);
      const w = d.week_start || '2026-11-16';
      const data = w === '2026-11-16' ? week('2026-11-16', '2026-11-09', null) : week('2026-11-09', null, '2026-11-16', true);
      r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
    });
    await p.goto('http://etel.test/', { waitUntil: 'load' });
    await p.addScriptTag({ content: require('fs').readFileSync(require('path').join(__dirname, '..', 'js', 'courier-statement.js'), 'utf8') }); await p.waitForSelector('#cst-card', { timeout: 15000 }); await p.waitForTimeout(800);
    let txt = await p.innerText('#cst-card');
    const am = mode.lang === 'am';
    t(mode.n + ': the card sits right under the earnings box', await p.evaluate(() => document.querySelector('.earn-card').nextElementSibling.id === 'cst-card'));
    t(mode.n + ': closed, it shows the week, the count and the total', (am ? /ሳምንታዊ የገቢ መግለጫ/ : /Weekly statement/).test(txt) && /16 Nov - 22 Nov/.test(txt) && /3 (deliveries|አቅርቦቶች)/.test(txt) && /ETB 225\.00/.test(txt), txt);
    t(mode.n + ': it asked for this week first (no week sent)', asked.length === 1 && asked[0].action === 'courier_get_statement' && !asked[0].week_start, JSON.stringify(asked));
    t(mode.n + ': closed, the details are hidden', !/ETL-1/.test(txt));
    await p.click('[data-cst=toggle]'); txt = await p.innerText('#cst-card');
    t(mode.n + ': open, it shows pay, tips, total and per delivery', /ETB 190\.00/.test(txt) && /ETB 35\.00/.test(txt) && /ETB 75\.00/.test(txt), txt);
    t(mode.n + ': every delivery is listed with its code, route and tip', /ETL-1/.test(txt) && /Stadium\s*>\s*Tabor/.test(txt) && /ETL-3/.test(txt) && /ETB 15\.00/.test(txt));
    t(mode.n + ': seven day bars, the best day is full width', (await p.locator('.cst-day').count()) === 7 && (await p.locator('.cst-day u').first().getAttribute('style')).includes('100%'));
    t(mode.n + ': the next-week arrow is off on this week, previous is on', await p.locator('[data-cst=next]').isDisabled() && !(await p.locator('[data-cst=prev]').isDisabled()));
    await p.screenshot({ path: SHOTS + '/etel-courier-statement-' + mode.n + '.png', fullPage: true });
    await p.click('[data-cst=prev]'); await p.waitForTimeout(500); txt = await p.innerText('#cst-card');
    t(mode.n + ': previous week asks for that Monday and shows the empty message', asked[asked.length - 1].week_start === '2026-11-09' && (am ? /ምንም አቅርቦት የለም/ : /No deliveries this week/).test(txt) && /ETB 0\.00/.test(txt), JSON.stringify(asked[asked.length - 1]));
    t(mode.n + ': on the old week the previous arrow is off, next is on', await p.locator('[data-cst=prev]').isDisabled() && !(await p.locator('[data-cst=next]').isDisabled()));
    await p.click('[data-cst=next]'); await p.waitForTimeout(500);
    t(mode.n + ': next goes back to this week', asked[asked.length - 1].week_start === '2026-11-16' && /ETL-1/.test(await p.innerText('#cst-card')));
    await p.evaluate(() => { window.__sh = ''; navigator.share = (o) => { window.__sh = o.text; return Promise.resolve(); }; });
    await p.click('[data-cst=share]'); shared = await p.evaluate(() => window.__sh);
    t(mode.n + ': Share sends the week as text with totals and days', /ETB 225\.00/.test(shared) && /ETB 190\.00/.test(shared) && /Mon 16 Nov: /.test(shared) && shared.split('\n').length >= 10, shared);
    t(mode.n + ': no page errors', errs.length === 0, errs.join('|'));
    t(mode.n + ': no sideways scroll', await p.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1));
    await ctx.close();
  }
  await b.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

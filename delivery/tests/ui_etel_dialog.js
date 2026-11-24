// Every app: the browser's own "The page at https://... says" box is replaced by the ETEL dialog (no web address, branded, clear action button). The tap is
// paused and replayed after OK, so existing confirm() calls need no change. Real script, plain test page.   node delivery/tests/ui_etel_dialog.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
const HTML = `<!doctype html><html><body>
<button id="b_rm" onclick="if(!confirm('Remove this address?')) return; window.rm=(window.rm||0)+1">Remove</button>
<button id="b_del" onclick="doDeliver()">Deliver</button>
<button id="b_two" onclick="two()">Two</button>
<button id="b_out" onclick="if(confirm('Logout?')) window.out=(window.out||0)+1">Logout</button>
<button id="b_acc" onclick="accept(7,'ETD-1')">Accept</button>
<button id="b_late" onclick="setTimeout(function(){window.lateAns=confirm('From a timer?')},900)">Late</button>
<script>
async function doDeliver(){ if(!confirm('Request delivery confirmation from the customer for order ETD-F23A5E?\\n\\nThey will see your request in their app and must confirm receipt and payment.')) return; window.delivered=(window.delivered||0)+1; }
async function accept(id,code){ if(!confirm('Accept job '+code+'?')) return; window.accepted=(window.accepted||0)+1; }
function two(){ if(!confirm('Your cart has items from "A". Adding from "B" will clear it. Continue?')) return; if(!confirm('"B" is closed right now. Order anyway?')) return; window.twoDone=(window.twoDone||0)+1; }
</script><script src="/js/etel-dialog.js"></script></body></html>`;
const srv = http.createServer((q, r) => { const u = q.url.split('?')[0]; if (u === '/t.html') { r.setHeader('Content-Type', 'text/html; charset=utf-8'); return r.end(HTML); } const f = path.join(ROOT, decodeURIComponent(u).replace(/\.\./g, '')); fs.readFile(f, (e, d) => { if (e) { r.statusCode = 404; r.end(); } else { r.setHeader('Content-Type', 'application/javascript; charset=utf-8'); r.end(d); } }); });
process.on('unhandledRejection', e => { console.log('FAILED so far:\n' + fails.join('\n')); console.log(String(e).slice(0, 200)); process.exit(1); });
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const PAGE = 'http://127.0.0.1:' + srv.address().port + '/t.html';
  const b = await chromium.launch();
  for (const lang of ['en', 'am']) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' }); await ctx.addInitScript(l => { try { localStorage.setItem('etel_lang', l); } catch (e) {} }, lang);
    const p = await ctx.newPage(); const errs = []; const native = []; p.on('pageerror', e => errs.push(e.message)); p.on('dialog', d => { native.push(d.message()); d.accept(); });
    await p.goto(PAGE); await p.waitForTimeout(300); const am = lang === 'am', n = lang;
    const dlg = () => p.evaluate(() => { const c = document.querySelector('.ed-card'); return c ? { text: c.innerText, danger: c.classList.contains('danger'), yes: c.querySelector('.ed-yes').innerText, no: c.querySelector('.ed-no').innerText, active: document.activeElement.className } : null; });
    await p.click('#b_rm'); await p.waitForTimeout(150); let d = await dlg();
    t(n + ' 1 a tap that would open the browser box opens the ETEL dialog instead', !!d && native.length === 0, JSON.stringify(native));
    t(n + ' 1 the action did not run yet', (await p.evaluate(() => window.rm || 0)) === 0);
    t(n + ' 1 it has the ETEL brand, the question, a Remove button (red, danger) and Cancel', d && /ETEL/.test(d.text) && /Remove this address/.test(d.text) && d.danger && (am ? /አስወግድ/ : /Remove/).test(d.yes) && (am ? /ተው/ : /Cancel/).test(d.no), JSON.stringify(d));
    t(n + ' 1 a dangerous action starts on Cancel (not on the red button)', d && /ed-no/.test(d.active), d && d.active);
    t(n + ' 1 no web address anywhere in it', d && !/https?:|ethelmarket|127\.0\.0\.1|page at/i.test(d.text));
    await p.click('.ed-no'); await p.waitForTimeout(150);
    t(n + ' 2 Cancel: the dialog closes and the action never ran', !(await dlg()) && (await p.evaluate(() => window.rm || 0)) === 0);
    await p.click('#b_rm'); await p.waitForTimeout(150); await p.keyboard.press('Escape'); await p.waitForTimeout(100);
    t(n + ' 2 Escape cancels too', !(await dlg()) && (await p.evaluate(() => window.rm || 0)) === 0);
    await p.click('#b_rm'); await p.waitForTimeout(150); await p.click('.ed-yes'); await p.waitForTimeout(200);
    t(n + ' 3 OK: the action ran exactly once', (await p.evaluate(() => window.rm)) === 1 && !(await dlg()));
    await p.click('#b_rm'); await p.waitForTimeout(150);
    t(n + ' 3 the next real tap asks again (the yes was not kept)', !!(await dlg()));
    await p.click('.ed-no'); await p.waitForTimeout(100);
    await p.click('#b_del'); await p.waitForTimeout(150); d = await dlg();
    t(n + ' 4 courier text: the question is the title and the details are below it', d && /Request delivery confirmation from the customer for order ETD-F23A5E\?/.test(d.text) && /must confirm receipt and payment/.test(d.text) && !d.danger && (am ? /አረጋግጥ|ጥያቄ ላክ/ : /Send request|Confirm/).test(d.yes), JSON.stringify(d));
    await p.screenshot({ path: (process.env.ETEL_SHOTS || '/tmp') + '/etel_dialog_' + n + '.png' });
    await p.click('.ed-yes'); await p.waitForTimeout(200);
    t(n + ' 4 OK runs the async action once', (await p.evaluate(() => window.delivered)) === 1);
    await p.click('#b_acc'); await p.waitForTimeout(150); d = await dlg();
    t(n + ' 5 Accept job has its own button and is not red', d && !d.danger && (am ? /ሥራውን ተቀበል/ : /Accept job/).test(d.yes), JSON.stringify(d));
    await p.keyboard.press('Enter'); await p.waitForTimeout(200);
    t(n + ' 5 Enter confirms (the button has focus)', (await p.evaluate(() => window.accepted)) === 1);
    await p.click('#b_two'); await p.waitForTimeout(150); t(n + ' 6 two questions in a row: the first one shows', !!(await dlg()) && /cart has items/.test((await dlg()).text));
    await p.click('.ed-yes'); await p.waitForTimeout(250); d = await dlg();
    t(n + ' 6 ... then the second one (not the first again)', d && /closed right now/.test(d.text), JSON.stringify(d));
    await p.click('.ed-yes'); await p.waitForTimeout(250);
    t(n + ' 6 after both yes the action ran once', (await p.evaluate(() => window.twoDone)) === 1 && !(await dlg()));
    await p.click('#b_out'); await p.waitForTimeout(150); d = await dlg();
    t(n + ' 7 logout says Log out', d && (am ? /ውጣ/ : /Log out/).test(d.yes) && d.danger);
    await p.click('.ed-yes'); await p.waitForTimeout(200); t(n + ' 7 ... and logs out once', (await p.evaluate(() => window.out)) === 1);
    await p.click('#b_late'); await p.waitForTimeout(1300);
    t(n + ' 8 a confirm that does not come from a tap still works (browser box fallback)', native.length === 1 && /From a timer/.test(native[0]) && (await p.evaluate(() => window.lateAns)) === true, JSON.stringify(native));
    t(n + ' 9 no page errors', errs.length === 0, errs.join('|')); await ctx.close();
  }
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
})();

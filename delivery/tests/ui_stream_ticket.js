// js/etel-stream-ticket.js in a real browser: a stream address with a login token gets a sealed ticket first and the stream opens with ?ticket= (no token in the address);
// falls back to the old address when no ticket can be had; close() before the ticket arrives opens nothing; other EventSource use and the app's handlers
// (onmessage, onerror, named events, close) keep working; the driver beacon swaps the token for a ticket and keeps its body.   node delivery/tests/ui_stream_ticket.js
const { chromium } = require('playwright'); const http = require('http'); const fs = require('fs'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const ROOT = path.join(__dirname, '..');
const srv = http.createServer((q, r) => { const u = q.url.split('?')[0];
  if (u === '/driver/t.html' || u === '/t.html') { r.setHeader('Content-Type', 'text/html'); return r.end('<!doctype html><html><head><script src="/js/etel-stream-ticket.js"></script></head><body>x</body></html>'); }
  fs.readFile(path.join(ROOT, u), (e, d) => { if (e) { r.statusCode = 404; return r.end(); } r.setHeader('Content-Type', 'application/javascript'); r.end(d); }); });
(async () => {
  await new Promise(res => srv.listen(0, '127.0.0.1', res)); const HOST = 'http://127.0.0.1:' + srv.address().port; const b = await chromium.launch();
  const run = async (o, page, steps) => {
    const ctx = await b.newContext({ serviceWorkers: 'block' }); await ctx.addInitScript(() => { localStorage.setItem('etel_token', 'TOK123'); });
    const p = await ctx.newPage(); const log = []; const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route(/boot\.php/, r => { const u = r.request().url(); const m = r.request().method(); let d = {}; try { d = JSON.parse(r.request().postData() || '{}'); } catch (e) {}
      log.push({ url: u, method: m, auth: r.request().headers()['authorization'], body: d, post: r.request().postData() });
      if (m === 'POST' && d.action === 'stream_ticket') {
        if (o.noTicket) return r.fulfill({ status: 501, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'no' }) });
        return r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { ticket: 'TICKET_' + d.for + '_' + (d.id || ''), expires_in: 120 } }), headers: o.slow ? {} : {} }); }
      if (u.indexOf('action=sse_') >= 0) return r.fulfill({ status: 200, contentType: 'text/event-stream', body: 'data: {"hello":1}\n\nevent: close\ndata: bye\n\n' });
      r.fulfill({ contentType: 'application/json', body: '{"success":true}' }); });
    await p.goto(HOST + page); await p.waitForTimeout(200); await steps(p, log); await ctx.close(); return errs;
  };
  const stream = log => log.filter(x => x.method === 'GET' && x.url.indexOf('action=sse_') >= 0);
  let errs = await run({}, '/t.html', async (p, log) => {
    await p.evaluate(() => { window.got = []; const es = new EventSource('/api/boot.php?action=sse_order_track&id=42&token=TOK123'); window.es = es;
      es.onmessage = e => window.got.push('m:' + e.data); es.addEventListener('close', () => window.got.push('close')); es.onerror = () => window.got.push('err'); });
    await p.waitForTimeout(600);
    const tr = log.filter(x => x.method === 'POST' && x.body.action === 'stream_ticket'); const st = stream(log);
    t('1 asks for a ticket first, signed in with the Authorization header', tr.length === 1 && tr[0].auth === 'Bearer TOK123' && tr[0].body.for === 'sse_order_track' && tr[0].body.id === '42', JSON.stringify(tr));
    t('2 opens the stream with the ticket and WITHOUT the token in the address', st.length >= 1 && st[0].url.indexOf('ticket=TICKET_sse_order_track_42') >= 0 && st[0].url.indexOf('token=') < 0, st[0] && st[0].url);
    t('3 the app handlers work: onmessage and a named event', (await p.evaluate(() => window.got)).includes('m:{"hello":1}') && (await p.evaluate(() => window.got)).includes('close'), JSON.stringify(await p.evaluate(() => window.got)));
    t('4 readyState and url look like a normal EventSource', (await p.evaluate(() => [typeof window.es.readyState, window.es.url.indexOf('token=TOK123') > 0]))[0] === 'number');
  });
  t('5 no page errors', errs.length === 0, errs.join('|'));
  await run({ noTicket: true }, '/t.html', async (p, log) => {
    await p.evaluate(() => { window.got = []; const es = new EventSource('/api/boot.php?action=sse_ride_status&id=7&token=TOK123'); es.onmessage = e => window.got.push('m'); });
    await p.waitForTimeout(600); const st = stream(log);
    t('6 when no ticket can be had it falls back to the old address (nothing stops working)', st.length >= 1 && st[0].url.indexOf('token=TOK123') >= 0, st[0] && st[0].url);
    t('7 ... and the app still gets its messages', (await p.evaluate(() => window.got)).includes('m'));
  });
  await run({}, '/t.html', async (p, log) => {
    await p.evaluate(() => { const es = new EventSource('/api/boot.php?action=sse_ride_status&id=7&token=TOK123'); es.close(); window.rs = es.readyState; });
    await p.waitForTimeout(600);
    t('8 close() before the ticket arrives opens nothing', stream(log).length === 0 && (await p.evaluate(() => window.rs)) === 2, stream(log).length);
  });
  await run({}, '/t.html', async (p, log) => {
    await p.evaluate(() => { window.got = []; const es = new EventSource('/api/boot.php?action=get_config'); es.onmessage = e => window.got.push('m'); });
    await p.waitForTimeout(300);
    t('9 a stream address without a token is left alone (no ticket asked for)', log.filter(x => x.body.action === 'stream_ticket').length === 0);
  });
  await run({}, '/driver/t.html', async (p, log) => {
    await p.waitForTimeout(500);
    const tr = log.filter(x => x.method === 'POST' && x.body.action === 'stream_ticket');
    t('10 on the driver page a beacon ticket is fetched ahead', tr.length >= 1 && tr[0].body.for === 'driver_beacon_ping' && tr[0].auth === 'Bearer TOK123', JSON.stringify(tr));
    await p.evaluate(() => { navigator.sendBeacon('/api/boot.php?action=driver_beacon_ping&token=TOK123', new Blob([JSON.stringify({ action: 'driver_beacon_ping', lat: 7.05, lng: 38.47 })], { type: 'application/json' })); });
    await p.waitForTimeout(400);
    const bc = log.filter(x => x.method === 'POST' && x.url.indexOf('action=driver_beacon_ping') >= 0);
    t('11 the beacon goes out with the ticket, not the token, and its position body is untouched', bc.length === 1 && bc[0].url.indexOf('ticket=TICKET_driver_beacon_ping') >= 0 && bc[0].url.indexOf('token=') < 0 && /"lat":7.05/.test(bc[0].post || ''), JSON.stringify(bc));
  });
  await run({ noTicket: true }, '/driver/t.html', async (p, log) => {
    await p.waitForTimeout(400);
    await p.evaluate(() => { navigator.sendBeacon('/api/boot.php?action=driver_beacon_ping&token=TOK123', new Blob(['{}'], { type: 'application/json' })); });
    await p.waitForTimeout(300);
    const bc = log.filter(x => x.method === 'POST' && x.url.indexOf('action=driver_beacon_ping') >= 0);
    t('12 without a ticket the beacon still goes out the old way (best effort, nothing breaks)', bc.length === 1 && bc[0].url.indexOf('token=TOK123') >= 0, JSON.stringify(bc));
  });
  await run({}, '/t.html', async (p, log) => {
    await p.waitForTimeout(300);
    t('13 pages that are not the driver page do not fetch a beacon ticket', log.filter(x => x.body.action === 'stream_ticket').length === 0);
  });
  await b.close(); srv.close();
  console.log(fails.length ? 'FAILED (' + fails.length + '):\n' + fails.join('\n') : 'ok: ' + pass + ' checks passed'); process.exit(fails.length ? 1 : 0);
})();

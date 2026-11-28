// Rider (ETEL Ride) map: looks and works like the driver map: Google's own colourful map with place names in light theme, the carbon map in dark,
// the Map / Hybrid switch, the + - zoom buttons, taps on shop icons still set the pickup point. Real js/ride-theme.js, a FAKE Google Maps that records setOptions.
// Needs Playwright (node).   node delivery/tests/ui_ride_map_like_driver.js
const { chromium } = require('playwright'); const path = require('path');
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const SCRIPT = path.join(__dirname, '..', 'js', 'ride-theme.js');
(async () => {
  const b = await chromium.launch();
  for (const theme of ['light', 'dark']) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    await ctx.addInitScript(th => { try { localStorage.setItem('etel_ride_theme', th); localStorage.setItem('etel_theme', th); } catch (e) {} }, theme);
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.route('http://rider.test/', r => r.fulfill({ contentType: 'text/html', body: '<!doctype html><html><head></head><body><div class="ride-header-user"></div></body></html>' }));
    await p.goto('http://rider.test/');
    await p.evaluate(() => {
      window.__opts = [];
      window.EtelUI = { mapStyles: l => (l ? [{ id: 'LIGHT-OLD' }] : [{ id: 'DARK' }]), icon: () => '' };
      window.RIDE = { map: { setOptions: o => window.__opts.push(o) } };
      window.google = { maps: { ControlPosition: { TOP_RIGHT: 3, RIGHT_CENTER: 15 }, MapTypeControlStyle: { HORIZONTAL_BAR: 1 } } };
    });
    // the page keeps its own key: find which key ride-theme.js reads, then set it
    const key = (require('fs').readFileSync(SCRIPT, 'utf8').match(/KEY\s*=\s*'([^']+)'/) || [])[1];
    await p.evaluate(([k, th]) => { try { localStorage.setItem(k, th); } catch (e) {} }, [key, theme]);
    await p.addScriptTag({ path: SCRIPT });
    await p.waitForTimeout(300);
    const o = await p.evaluate(() => window.__opts[window.__opts.length - 1]);
    t(theme + ': the map got new options', !!o); if (process.env.DBG) console.log(theme, JSON.stringify(o));
    t(theme + ': Map / Hybrid and zoom options are set', !!(o && o.mapTypeControlOptions && o.zoomControlOptions));
    if (o && o.mapTypeControlOptions && o.zoomControlOptions) {
      t(theme + ': Map / Hybrid switch is on', o.mapTypeControl === true && JSON.stringify(o.mapTypeControlOptions.mapTypeIds) === '["roadmap","hybrid"]', JSON.stringify(o.mapTypeControlOptions));
      t(theme + ': Map / Hybrid switch is top right', o.mapTypeControlOptions.position === 3);
      t(theme + ': + - zoom buttons are on', o.zoomControl === true && o.zoomControlOptions.position === 15);
      t(theme + ': a tap on a shop icon does not open Google card', o.clickableIcons === false);
      t(theme + ': one finger moves the map', o.gestureHandling === 'greedy');
      if (theme === 'light') t('light: Google colourful map (no hiding of names or places)', Array.isArray(o.styles) && o.styles.length === 0, JSON.stringify(o.styles));
      else t('dark: carbon map kept', o.styles && o.styles[0] && o.styles[0].id === 'DARK');
    }
    t(theme + ': no page errors', errs.length === 0, errs.join('|'));
    await ctx.close();
  }
  await b.close();
  console.log(pass + ' passed, ' + fails.length + ' failed'); fails.forEach(f => console.log(' FAIL ' + f)); process.exit(fails.length ? 1 : 0);
})();

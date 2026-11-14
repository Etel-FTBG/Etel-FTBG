const pptxgen = require('pptxgenjs');
const React = require('react');
const ReactDOMServer = require('react-dom/server');
const sharp = require('sharp');
const fa = require('react-icons/fa');
const SKILL = require('fs').readdirSync('/root/.claude/skills/synced').map(d => '/root/.claude/skills/synced/' + d + '/pptx').find(p => require('fs').existsSync(p + '/scripts/apply_theme.js'));
const { applyTheme } = require(SKILL + '/scripts/apply_theme.js');

const THEME = {
  name: 'Etel Navy Gold', headFontFace: process.env.DECK_LANG === 'am' ? 'Noto Sans Ethiopic' : 'Cambria', bodyFontFace: process.env.DECK_LANG === 'am' ? 'Noto Sans Ethiopic' : 'Calibri',
  colors: { dk1: '0B1B3F', lt1: 'FFFFFF', dk2: '0A1D4D', lt2: 'EEF2FB', accent1: '1368E8', accent2: 'F6C22B', accent3: '12337A', accent4: '2E9E6B', accent5: 'C9D8FF', accent6: 'D99A06', hlink: '1368E8', folHlink: '12337A' }
};
const NAVY = '0A1D4D', DEEP = '061230', GOLD = 'F6C22B', BLUE = '1368E8', ICE = 'C9D8FF', MUTED = '4B5B82', GREEN = '2E9E6B', GRID = 'D5DDF0';

const LANG = process.env.DECK_LANG || 'en';
const TR = LANG === 'am' ? require('./am.js') : {};
const MISS = new Set();
const ETH = /[\u1200-\u137F]/, PLAIN = /^[\d\s,.%|x\u00d7ETB+=>\/()\-]*$/;
function tr(s) {
  if (LANG !== 'am' || typeof s !== 'string' || ETH.test(s) || PLAIN.test(s)) return s;
  if (TR[s] !== undefined) return TR[s];
  MISS.add(s); return s;
}
const AM = LANG === 'am';
const pres = new pptxgen();
pres.layout = 'LAYOUT_WIDE'; // 13.33 x 7.5
const _addSlide = pres.addSlide.bind(pres);
pres.addSlide = function (o) {
  const s = _addSlide(o);
  const at = s.addText.bind(s); s.addText = (t, op) => at(tr(t), op);
  const an = s.addNotes.bind(s); s.addNotes = t => an(tr(t));
  const ac = s.addChart.bind(s);
  s.addChart = (type, data, op) => { data.forEach(d => { d.name = tr(d.name); d.labels = d.labels.map(tr); }); if (op.title) op.title = tr(op.title); if (op.catAxisTitle) op.catAxisTitle = tr(op.catAxisTitle); return ac(type, data, op); };
  return s;
};
pres.theme = { headFontFace: THEME.headFontFace, bodyFontFace: THEME.bodyFontFace };
pres.title = tr('Etel Delivery & Ride - Investor presentation');
pres.author = 'Etel Family Technology and Business Group';
pres.company = 'Etel Family Technology and Business Group';
const C = pres.SchemeColor;
const W = 13.33, H = 7.5;
const LOGO = 'logo-etel.png', SEAL = 'logo-master.png';

pres.defineSlideMaster({
  title: 'COVER', background: { color: C.text2 },
  objects: [
    { placeholder: { options: { name: 'title', type: 'title', x: 0.9, y: 2.35, w: 7.4, h: 1.7, fontSize: 52, bold: true, color: C.background1, align: 'left', valign: 'bottom', margin: 0 }, text: '' } },
    { placeholder: { options: { name: 'body', type: 'body', x: 0.9, y: 4.25, w: 7.0, h: 1.2, fontSize: 20, color: C.accent5, align: 'left', valign: 'top', margin: 0 }, text: '' } }
  ]
});
pres.defineSlideMaster({
  title: 'CONTENT', background: { color: C.background1 },
  objects: [
    { placeholder: { options: { name: 'title', type: 'title', x: 0.6, y: 0.4, w: 12.13, h: 0.9, fontSize: 32, bold: true, color: C.text2, align: 'left', valign: 'middle', margin: 0 }, text: '' } },
    { image: { path: LOGO, x: 0.6, y: 6.95, w: 0.34, h: 0.34 } },
    { text: { text: tr('Etel Delivery & Ride  |  Investor presentation 2026'), options: { x: 1.05, y: 6.95, w: 6, h: 0.34, fontSize: 10, color: C.text2, valign: 'middle', margin: 0 } } }
  ],
  slideNumber: { x: 12.0, y: 6.95, w: 0.73, h: 0.34, fontSize: 10, color: C.text2, align: 'right' }
});
pres.defineSlideMaster({
  title: 'DARK', background: { color: C.text2 },
  objects: [
    { placeholder: { options: { name: 'title', type: 'title', x: 0.6, y: 0.4, w: 12.13, h: 0.9, fontSize: 32, bold: true, color: C.background1, align: 'left', valign: 'middle', margin: 0 }, text: '' } },
    { image: { path: LOGO, x: 0.6, y: 6.95, w: 0.34, h: 0.34 } },
    { text: { text: tr('Etel Delivery & Ride  |  Investor presentation 2026'), options: { x: 1.05, y: 6.95, w: 6, h: 0.34, fontSize: 10, color: C.accent5, valign: 'middle', margin: 0 } } }
  ],
  slideNumber: { x: 12.0, y: 6.95, w: 0.73, h: 0.34, fontSize: 10, color: C.accent5, align: 'right' }
});

async function icon(name, hex, size = 256) {
  const svg = ReactDOMServer.renderToStaticMarkup(React.createElement(fa[name], { color: '#' + hex, size: String(size) }));
  const buf = await sharp(Buffer.from(svg)).png().toBuffer();
  return 'image/png;base64,' + buf.toString('base64');
}
const shadow = () => ({ type: 'outer', color: '0A1D4D', blur: 8, offset: 2, angle: 90, opacity: 0.12 });

function card(s, x, y, w, h, name, fill) {
  s.addShape(pres.shapes.ROUNDED_RECTANGLE, { x, y, w, h, rectRadius: 0.12, fill: { color: fill || C.background2 }, line: { color: fill || C.background2, width: 0 }, shadow: shadow(), objectName: name });
}
async function iconCircle(s, name, x, y, d, circleHex, iconHex) {
  s.addShape(pres.shapes.OVAL, { x, y, w: d, h: d, fill: { color: circleHex }, line: { color: circleHex, width: 0 }, objectName: name + ' circle' });
  const p = d * 0.5;
  s.addImage({ data: await icon(name, iconHex), x: x + (d - p) / 2, y: y + (d - p) / 2, w: p, h: p, altText: name.replace('Fa', '') + ' icon' });
}
function rings(s, cx, cy, radii, hex, transp) {
  radii.forEach((r, i) => s.addShape(pres.shapes.OVAL, { x: cx - r, y: cy - r, w: 2 * r, h: 2 * r, fill: { color: hex, transparency: 100 }, line: { color: hex, width: 1.25, transparency: transp + i * 6 }, objectName: 'ring ' + (i + 1) }));
}
function T(s, text, o) { s.addText(text, Object.assign({ isTextBox: true, margin: 0, valign: 'top' }, o)); }
const lead = (s, text, y = 1.3) => T(s, text, { x: 0.6, y, w: 12.13, h: 0.6, fontSize: 18, color: C.text1 });

(async () => {
  // 1 COVER
  let s = pres.addSlide({ masterName: 'COVER' });
  rings(s, 10.2, 3.75, [3.0, 2.6, 2.2], GOLD, 55);
  s.addShape(pres.shapes.OVAL, { x: 8.2, y: 1.75, w: 4.0, h: 4.0, fill: { color: DEEP }, line: { color: GOLD, width: 2 }, objectName: 'logo disc' });
  s.addImage({ path: LOGO, x: 8.45, y: 2.0, w: 3.5, h: 3.5, altText: 'Etel logo' });
  T(s, 'INVESTOR PRESENTATION  |  2026', { x: 0.9, y: 1.7, w: 7, h: 0.4, fontSize: 14, color: GOLD, bold: true, charSpacing: 3 });
  s.addText('Etel Delivery & Ride', { placeholder: 'title' });
  s.addText('Rides and deliveries for Hawassa, built and run by Etel Family Technology and Business Group.', { placeholder: 'body' });
  s.addShape(pres.shapes.ROUNDED_RECTANGLE, { x: 0.9, y: 5.75, w: 4.3, h: 0.5, rectRadius: 0.25, fill: { color: GOLD }, line: { color: GOLD, width: 0 }, objectName: 'cover chip' });
  T(s, 'A staged, profit-share investment', { x: 0.9, y: 5.75, w: 4.3, h: 0.5, fontSize: 15, bold: true, color: C.text2, align: 'center', valign: 'middle' });
  s.addNotes('Welcome. In 20 minutes: the problem, the product that already works, how investors earn, the staged raise, the launch plan, where the money goes, and how we protect you. One sentence only on this slide: Etel Delivery & Ride is a profit-share investment in a platform that already runs. Keep the tone calm and factual.');

  // 2 PROBLEM
  s = pres.addSlide({ masterName: 'CONTENT' });
  s.addText('Hawassa still moves on phone calls, cash and guesswork', { placeholder: 'title' });
  lead(s, 'Three groups lose time and trust every day.');
  const prob = [['FaUsers', 'Riders', 'Often do not know the fare or the driver before they get in.'], ['FaRoute', 'Drivers and couriers', 'Lose time waiting for work and have no record of what they earned.'], ['FaStore', 'Businesses', 'Cannot see where a delivery is, or prove that it arrived.']];
  for (let i = 0; i < 3; i++) {
    const x = 0.6 + i * 4.1;
    card(s, x, 2.2, 3.93, 3.4, 'problem card ' + (i + 1));
    await iconCircle(s, prob[i][0], x + 0.4, 2.6, 0.95, NAVY, GOLD);
    T(s, prob[i][1], { x: x + 0.4, y: 3.8, w: 3.1, h: 0.8, fontSize: AM ? 20 : 24, bold: true, color: C.text2 });
    T(s, prob[i][2], { x: x + 0.4, y: AM ? 4.7 : 4.45, w: 3.1, h: 1.4, fontSize: 16, color: C.text1 });
  }
  s.addNotes('Use one Hawassa example per group: a rider who called three drivers for a price, a driver waiting at a stand with no earnings record, a shop that cannot prove a parcel arrived. Keep it human, not statistical. Do not quote numbers we have not measured.');

  // 3 PRODUCT
  s = pres.addSlide({ masterName: 'CONTENT' });
  s.addText('One platform, five apps, one control room', { placeholder: 'title' });
  lead(s, 'Built and already working. Offer the live demo on your phone here.');
  const apps = [['FaMobileAlt', 'Rider app', 'Fare shown before the trip, trip PIN, SOS, scheduled rides, receipts.'], ['FaCar', 'Driver app', 'Requests, hot areas, earnings, shifts, printed receipts.'], ['FaTruck', 'Courier and delivery', 'Orders, parcels and tracking for shops and customers.'], ['FaStore', 'Service givers', 'Local businesses take orders and payouts on the platform.'], ['FaUserShield', 'Admin control room', 'Pricing, payouts, 2FA, system health, support in one place.']];
  for (let i = 0; i < 5; i++) {
    const x = 0.6 + i * 2.46;
    card(s, x, 2.15, 2.3, 3.55, 'app card ' + (i + 1));
    await iconCircle(s, apps[i][0], x + 0.25, 2.4, 0.8, i === 4 ? GOLD : NAVY, i === 4 ? NAVY : GOLD);
    T(s, apps[i][1], { x: x + 0.25, y: 3.4, w: 1.85, h: 0.65, fontSize: 18, bold: true, color: C.text2 });
    T(s, apps[i][2], { x: x + 0.25, y: 4.1, w: 1.85, h: 1.5, fontSize: 14, color: C.text1 });
  }
  s.addShape(pres.shapes.ROUNDED_RECTANGLE, { x: 0.6, y: 5.95, w: 12.13, h: 0.65, rectRadius: 0.12, fill: { color: C.text2 }, line: { color: C.text2, width: 0 }, objectName: 'tariff strip' });
  T(s, 'Transparent tariffs for moto, bajaj, qute, car, minibus and electric car.', { x: 0.9, y: 5.95, w: 11.5, h: 0.65, fontSize: 16, color: C.background1, valign: 'middle' });
  s.addNotes('This is the slide where trust is built. Open the rider app, show a fare before the trip, then the driver app earnings screen, then the admin control room. Everything shown here is built and running, not a mock-up. If Demo Mode is on, say so.');

  // 4 BUSINESS MODEL
  s = pres.addSlide({ masterName: 'CONTENT' });
  s.addText('Etel earns a commission on every trip and order', { placeholder: 'title' });
  lead(s, 'Net profit is what is left after running costs. Investors share 20% of it.');
  const flow = [['Platform earns', 'Commission on rides and orders', false], ['Running costs', 'Staff, rent, fees, contingency', false], ['Net profit', 'For each period', true], ['Investor pool', '20% of net profit', true]];
  const fw = 2.6, gap = 0.58;
  for (let i = 0; i < 4; i++) {
    const x = 0.6 + i * (fw + gap);
    card(s, x, 2.3, fw, 1.9, 'flow ' + (i + 1), flow[i][2] ? C.text2 : C.background2);
    T(s, flow[i][0], { x: x + 0.25, y: 2.5, w: fw - 0.5, h: AM ? 0.8 : 0.5, fontSize: AM ? 17 : 22, bold: true, color: flow[i][2] ? C.accent2 : C.text2 });
    T(s, flow[i][1], { x: x + 0.25, y: AM ? 3.35 : 3.2, w: fw - 0.5, h: 0.8, fontSize: 15, color: flow[i][2] ? C.accent5 : C.text1 });
    if (i < 3) T(s, ['-', '=', '>'][i], { x: x + fw, y: 2.85, w: gap, h: 0.6, fontSize: 30, bold: true, color: C.accent6, align: 'center', valign: 'middle' });
  }
  const st = [['No profit', 'no payout, nothing owed back'], ['Every period', 'a statement and your own portal login'], ['Read-only', 'trip numbers come straight from the platform']];
  for (let i = 0; i < 3; i++) {
    const x = 0.6 + i * 4.1;
    T(s, st[i][0], { x, y: 4.75, w: 3.9, h: 0.8, fontSize: 36, bold: true, color: C.accent1 });
    T(s, st[i][1], { x, y: 5.55, w: 3.8, h: 0.8, fontSize: 16, color: C.text1 });
  }
  s.addNotes('Draw the four boxes slowly: income minus costs equals net profit, and 20% of net profit goes into the investor pool. The point to land: investors share profit, never losses. If there is no profit in a period, nothing is paid and nothing is owed back.');

  // 5 HOW INVESTORS EARN
  s = pres.addSlide({ masterName: 'CONTENT' });
  s.addText('The 2.5x boost pays you back faster', { placeholder: 'title' });
  const bs = [['2.5x', 'Boost until you are paid back'], ['1x', 'Standard share after payback'], ['100 shares', '= 2.5% of net profit boosted, 1% after']];
  for (let i = 0; i < 3; i++) {
    const y = 1.5 + i * 1.65;
    card(s, 0.6, y, 4.9, 1.45, 'boost card ' + (i + 1), i === 0 ? C.text2 : C.background2);
    T(s, bs[i][0], { x: 0.9, y: y + 0.18, w: 4.3, h: 0.75, fontSize: i === 2 ? 32 : 44, bold: true, color: i === 0 ? C.accent2 : C.accent1, valign: 'middle' });
    T(s, bs[i][1], { x: 0.9, y: y + 0.9, w: 4.3, h: 0.45, fontSize: 15, color: i === 0 ? C.accent5 : C.text1 });
  }
  s.addChart(pres.charts.BAR, [
    { name: 'Standard share', labels: ['100 shares', '200 shares', '400 shares'], values: [1000, 2000, 4000] },
    { name: 'Boosted until paid back', labels: ['100 shares', '200 shares', '400 shares'], values: [2500, 5000, 10000] }
  ], { x: 5.85, y: 1.45, w: 6.9, h: 4.75, barDir: 'col', barGrouping: 'clustered', barGapWidthPct: 60, chartColors: [BLUE, GOLD], showTitle: true, title: 'Payout in one period (ETB), net profit ETB 100,000', titleFontSize: 15, titleColor: NAVY, titleFontFace: '+mn-lt',
    showValue: true, dataLabelPosition: 'outEnd', dataLabelFontSize: 12, dataLabelColor: '0B1B3F', dataLabelFontFace: '+mn-lt', dataLabelFormatCode: '#,##0',
    catAxisLabelColor: MUTED, valAxisLabelColor: MUTED, catAxisLabelFontFace: '+mn-lt', valAxisLabelFontFace: '+mn-lt', catAxisLabelFontSize: 12, valAxisLabelFontSize: 11, valAxisLabelFormatCode: '#,##0',
    valGridLine: { color: GRID, size: 0.75 }, catGridLine: { style: 'none' }, showLegend: true, legendPos: 'b', legendFontFace: '+mn-lt', legendFontSize: 12, legendColor: MUTED });
  T(s, 'Pool = 20% of ETB 100,000 = ETB 20,000. Your part = your shares / 2,000. Illustration, not a forecast.', { x: 0.6, y: 6.4, w: 12.13, h: 0.35, fontSize: 12, color: MUTED });
  s.addNotes('Worked example: net profit ETB 100,000, pool ETB 20,000. A holder of 100 shares owns 5% of the pool: ETB 1,000 at the standard rate, ETB 2,500 while boosted. Once they have received back what they paid, the boost ends and they continue at the standard rate. Rule of thumb: 100 shares is 2.5% of net profit while boosted and 1% after payback.');

  // 6 UNSOLD BONUS
  s = pres.addSlide({ masterName: 'CONTENT' });
  s.addText('Early investors earn extra while shares are unsold', { placeholder: 'title' });
  const us = [['1', 'Unsold shares would earn the boosted rate.'], ['2', 'Half of that goes to the investors who already hold shares, in proportion to their shares.'], ['3', 'The other half stays with the Company. The bonus shrinks as shares sell and ends at 2,000 sold.']];
  for (let i = 0; i < 3; i++) {
    const y = 1.5 + i * 1.5;
    s.addShape(pres.shapes.OVAL, { x: 0.6, y: y + 0.1, w: 0.7, h: 0.7, fill: { color: C.text2 }, line: { color: C.text2, width: 0 }, objectName: 'step ' + us[i][0] });
    T(s, us[i][0], { x: 0.6, y: y + 0.1, w: 0.7, h: 0.7, fontSize: 22, bold: true, color: C.accent2, align: 'center', valign: 'middle' });
    T(s, us[i][1], { x: 1.55, y, w: 3.6, h: 1.3, fontSize: 16, color: C.text1 });
  }
  s.addChart(pres.charts.BAR, [
    { name: 'Ordinary boosted share', labels: ['400 sold (Stage 1)', '1,000 sold (Stages 1-2)', '2,000 sold (all stages)'], values: [2500, 2500, 2500] },
    { name: 'Unsold-share bonus', labels: ['400 sold (Stage 1)', '1,000 sold (Stages 1-2)', '2,000 sold (all stages)'], values: [5000, 1250, 0] }
  ], { x: 5.5, y: 1.4, w: 7.25, h: 4.85, barDir: 'col', barGrouping: 'stacked', barGapWidthPct: 55, chartColors: [BLUE, GOLD], showTitle: true, title: 'A holder of 100 shares: ETB per period at ETB 100,000 net profit', titleFontSize: 15, titleColor: NAVY, titleFontFace: '+mn-lt',
    showValue: true, dataLabelPosition: 'ctr', dataLabelFontSize: 13, dataLabelColor: 'FFFFFF', dataLabelFontFace: '+mn-lt', dataLabelFormatCode: '#,##0;-#,##0;;',
    catAxisLabelColor: MUTED, valAxisLabelColor: MUTED, catAxisLabelFontFace: '+mn-lt', valAxisLabelFontFace: '+mn-lt', catAxisLabelFontSize: 12, valAxisLabelFontSize: 11, valAxisLabelFormatCode: '#,##0',
    valGridLine: { color: GRID, size: 0.75 }, catGridLine: { style: 'none' }, showLegend: true, legendPos: 'b', legendFontFace: '+mn-lt', legendFontSize: 12, legendColor: MUTED });
  T(s, 'With only Stage 1 sold, a 100-share holder earns ETB 7,500 (2,500 + 5,000). Stated in the Agreement. Illustration, not a promise.', { x: 0.6, y: 6.4, w: 12.13, h: 0.35, fontSize: 12, color: MUTED });
  s.addNotes('This is the reward for being early, and it is written into the Agreement (draft clause on Unsold Profit Shares) and on the deal sheet. Example: net profit ETB 100,000, 400 of 2,000 shares sold. Unsold amount = 20,000 x 1,600/2,000 x 2.5 = 40,000. Half (20,000) is shared among the holders. A holder of 100 of the 400 sold shares receives 5,000 on top of the 2,500 ordinary boosted share: 7,500 in total. At 1,000 sold the bonus falls to 1,250; at 2,000 sold it is zero. Say plainly that this is an illustration and that profit is not guaranteed.');

  // 7 STAGES
  s = pres.addSlide({ masterName: 'CONTENT' });
  s.addText('The raise in three stages: the price rises as we prove it', { placeholder: 'title' });
  lead(s, 'Early investors take the most risk and pay the lowest price. A share is a profit-share right, not company ownership.');
  const stg = [['Stage 1', 'ETB 1,000', '400 shares', 'ETB 400,000', 'Open now', true], ['Stage 2', 'ETB 1,500', '600 shares', 'ETB 900,000', 'Opens at 400 trips a day', false], ['Stage 3', 'ETB 2,000', '1,000 shares', 'ETB 2,000,000', 'Opens at 1,000 trips a day', false]];
  for (let i = 0; i < 3; i++) {
    const x = 0.6 + i * 4.1, on = stg[i][5];
    card(s, x, 2.2, 3.93, 3.15, 'stage card ' + (i + 1), on ? C.text2 : C.background2);
    T(s, stg[i][0], { x: x + 0.35, y: 2.45, w: 3.2, h: 0.4, fontSize: 18, bold: true, color: on ? C.accent2 : C.accent1 });
    T(s, stg[i][1], { x: x + 0.35, y: 2.9, w: 3.2, h: 0.8, fontSize: 40, bold: true, color: on ? C.background1 : C.text2 });
    T(s, tr('per share') + '  |  ' + tr(stg[i][2]), { x: x + 0.35, y: 3.75, w: 3.2, h: 0.4, fontSize: 15, color: on ? C.accent5 : C.text1 });
    T(s, tr('Raises') + ' ' + stg[i][3], { x: x + 0.35, y: 4.2, w: 3.2, h: 0.4, fontSize: 15, bold: true, color: on ? C.background1 : C.text2 });
    T(s, stg[i][4], { x: x + 0.35, y: 4.7, w: 3.2, h: 0.4, fontSize: 14, bold: true, color: on ? C.accent2 : C.accent6 });
  }
  // proportional pool bar
  const bx = 0.6, bw = 12.13, tot = 2000, bars = [[400, NAVY, 'FFFFFF'], [600, BLUE, 'FFFFFF'], [1000, ICE, NAVY]];
  let acc = 0;
  bars.forEach((b, i) => { const w = bw * b[0] / tot; s.addShape(pres.shapes.RECTANGLE, { x: bx + bw * acc / tot, y: 5.7, w, h: 0.55, fill: { color: b[1] }, line: { color: 'FFFFFF', width: 1.5 }, objectName: 'pool segment ' + (i + 1) }); T(s, b[0].toLocaleString('en-US') + ' ' + tr('shares'), { x: bx + bw * acc / tot, y: 5.7, w, h: 0.55, fontSize: 14, bold: true, color: b[2], align: 'center', valign: 'middle' }); acc += b[0]; });
  T(s, 'The investor pool: 2,000 shares = 20% of net profit', { x: 0.6, y: 6.35, w: 12.13, h: 0.35, fontSize: 13, color: MUTED });
  s.addNotes('Stage 1 is open now and the cheapest: ETB 1,000 a share, 400 shares, ETB 400,000. Stage 2 (600 shares at ETB 1,500) opens when the platform reaches 400 trips a day. Stage 3 (1,000 shares at ETB 2,000) opens at 1,000 trips a day. The pool is 2,000 shares, which is 20% of net profit in total. Every share earns the same slice of profit, so a later investor pays more for the same slice. That is the reward for early risk. Repeat: profit-share rights, not company ownership.');

  // 8 ILLUSTRATION
  s = pres.addSlide({ masterName: 'CONTENT' });
  s.addText('What it could look like: break-even is a volume question', { placeholder: 'title' });
  s.addChart(pres.charts.LINE, [
    { name: "Today's costs (ETB 17,667 a month)", labels: ['100', '400', '800', '1,500'], values: [333, 54333, 126333, 252333] },
    { name: 'Full team (assumed ETB 150,000 a month)', labels: ['100', '400', '800', '1,500'], values: [-132000, -78000, -6000, 120000] }
  ], { x: 0.6, y: 1.35, w: 8.1, h: 5.0, chartColors: [BLUE, GOLD], lineSize: 3, lineDataSymbolSize: 9, showTitle: true, title: 'Net profit per month (ETB) by trips a day', titleFontSize: 15, titleColor: NAVY, titleFontFace: '+mn-lt',
    showValue: true, dataLabelPosition: 't', dataLabelFontSize: 11, dataLabelColor: '0B1B3F', dataLabelFontFace: '+mn-lt', dataLabelFormatCode: '#,##0',
    catAxisLabelColor: MUTED, valAxisLabelColor: MUTED, catAxisLabelFontFace: '+mn-lt', valAxisLabelFontFace: '+mn-lt', catAxisLabelFontSize: 12, valAxisLabelFontSize: 11, valAxisLabelFormatCode: '#,##0',
    showCatAxisTitle: true, catAxisTitle: 'Trips a day', catAxisTitleColor: MUTED, catAxisTitleFontSize: 12, catAxisTitleFontFace: '+mn-lt',
    valGridLine: { color: GRID, size: 0.75 }, catGridLine: { style: 'none' }, showLegend: true, legendPos: 'b', legendFontFace: '+mn-lt', legendFontSize: 12, legendColor: MUTED });
  card(s, 9.05, 1.45, 3.68, 2.3, 'break-even lean', C.text2);
  T(s, '99', { x: 9.35, y: 1.6, w: 3.1, h: 1.0, fontSize: 60, bold: true, color: C.accent2 });
  T(s, "trips a day to break even on today's costs", { x: 9.35, y: 2.7, w: 3.1, h: 0.9, fontSize: 15, color: C.accent5 });
  card(s, 9.05, 3.95, 3.68, 2.3, 'break-even full', C.background2);
  T(s, '834', { x: 9.35, y: 4.1, w: 3.1, h: 1.0, fontSize: 60, bold: true, color: C.accent1 });
  T(s, 'trips a day to break even with a full team', { x: 9.35, y: 5.2, w: 3.1, h: 0.9, fontSize: 15, color: C.text1 });
  T(s, 'Illustrations, not forecasts. Assumed average fare ETB 120; Etel earns 5% = ETB 6 a trip. Today\'s costs: rent 6,000 + customer service 10,000 + 20,000 a year / 12. Full team is an assumption.', { x: 0.6, y: 6.45, w: 12.13, h: 0.45, fontSize: 11, color: MUTED });
  s.addNotes('Say "illustrations, not forecasts" out loud. With today\'s lean costs the platform breaks even at about 99 trips a day. With a full team costing an assumed ETB 150,000 a month it needs about 834 trips a day. The upside is volume. Be honest on payback: at 1,500 trips a day with a full team, 100 shares earn about ETB 3,000 a month on the pitch calculation, roughly 33 months to pay back, before any unsold-share bonus. This is a growth investment, not a quick payback. Never promise a date. Replace the assumed fare and full-team cost with real numbers when you have them.');

  // 9 LAUNCH PLAN
  s = pres.addSlide({ masterName: 'DARK' });
  s.addText('The launch plan: growth first, profit after', { placeholder: 'title' });
  T(s, 'The raise pays to grow the platform. No investor payouts during the launch period.', { x: 0.6, y: 1.3, w: 12.13, h: 0.5, fontSize: 18, color: C.accent5 });
  const lp = [['FaRocket', 'Days 1-60', 'Launch', 'New drivers pay 0% commission. Bonuses for drivers, discounts for riders. The raise pays for it.'], ['FaPercent', 'Days 61-90', 'Step up', 'Commission returns in steps: 2% for 30 days, then the standard 5%.'], ['FaCoins', 'After launch', 'First payout', 'Once there is profit, investors share it with the 2.5x boost until paid back.'], ['FaEye', 'Throughout', 'Open books', 'A monthly spending report and the launch budget in your investor portal.']];
  for (let i = 0; i < 4; i++) {
    const x = 0.6 + i * 3.06;
    card(s, x, 2.15, 2.9, 3.35, 'launch card ' + (i + 1), DEEP);
    await iconCircle(s, lp[i][0], x + 0.3, 2.4, 0.8, GOLD, NAVY);
    T(s, lp[i][1], { x: x + 0.3, y: 3.3, w: 2.4, h: 0.35, fontSize: 13, bold: true, color: C.accent2 });
    T(s, lp[i][2], { x: x + 0.3, y: 3.65, w: 2.4, h: 0.5, fontSize: AM ? 18 : 24, bold: true, color: C.background1 });
    T(s, lp[i][3], { x: x + 0.3, y: 4.2, w: 2.4, h: 1.25, fontSize: 14, color: C.accent5 });
  }
  s.addShape(pres.shapes.ROUNDED_RECTANGLE, { x: 0.6, y: 5.8, w: 12.13, h: 0.85, rectRadius: 0.12, fill: { color: GOLD }, line: { color: GOLD, width: 0 }, objectName: 'launch rule banner' });
  T(s, 'The launch ends after at most 90 days, or earlier when the 4-week average reaches 150 trips a day. Final terms are in the signed Agreement.', { x: 0.9, y: 5.8, w: 11.5, h: 0.85, fontSize: 16, bold: true, color: C.text2, valign: 'middle' });
  s.addNotes('Explain why: paying profit out while still buying growth would starve the launch. The launch ends at most 90 days after it starts (60 days at 0% commission for new drivers, then 30 days at 2%), or earlier when the 4-week average reaches 150 trips a day. The platform is built so that this rule is set in one place and shown identically to investors in the deal sheet, the pitch and the portal. Terms are final only in the signed Agreement; have your lawyer confirm the wording.');

  // 10 USE OF FUNDS
  s = pres.addSlide({ masterName: 'CONTENT' });
  s.addText('Where the Stage 1 raise goes: mostly growth', { placeholder: 'title' });
  const funds = [['Reserve', 26, 104000, '6 months of running costs, as a safety net', NAVY], ['Marketing', 25, 100000, 'Posters, social media, launch events in Hawassa', BLUE], ['Promotion', 15, 60000, 'Launch discounts and promo codes for riders', 'C58A00'], ['Materials', 12, 48000, 'Branding, uniforms, stickers, printed material', GREEN], ['Bonuses', 12, 48000, 'For drivers and couriers who reach their goals', '8A5E00'], ['Referral rewards', 10, 40000, 'For people who bring in new drivers and riders', '5B7FD6']];
  s.addChart(pres.charts.DOUGHNUT, [{ name: 'Use of funds', labels: funds.map(f => f[0]), values: funds.map(f => f[1]) }], { x: 0.6, y: 1.4, w: 4.9, h: 4.9, holeSize: 58, chartColors: funds.map(f => f[4]), showLegend: false, showPercent: true, showValue: false, showLabel: false, dataLabelColor: 'FFFFFF', dataLabelFontSize: 13, dataLabelFontFace: '+mn-lt', dataBorder: { pt: 2, color: 'FFFFFF' } });
  T(s, 'ETB 400,000', { x: 1.85, y: 3.35, w: 2.4, h: 0.5, fontSize: 24, bold: true, color: C.text2, align: 'center' });
  T(s, 'Stage 1', { x: 1.85, y: 3.85, w: 2.4, h: 0.35, fontSize: 14, color: MUTED, align: 'center' });
  funds.forEach((f, i) => {
    const y = 1.45 + i * 0.8;
    s.addShape(pres.shapes.OVAL, { x: 6.0, y: y + 0.12, w: 0.28, h: 0.28, fill: { color: f[4] }, line: { color: f[4], width: 0 }, objectName: 'legend ' + f[0] });
    T(s, f[0], { x: 6.45, y, w: 2.5, h: 0.4, fontSize: 18, bold: true, color: C.text2 });
    T(s, f[3], { x: 6.45, y: y + 0.38, w: 4.2, h: 0.35, fontSize: 12, color: MUTED });
    T(s, 'ETB ' + f[2].toLocaleString('en-US'), { x: 10.3, y, w: 1.6, h: 0.4, fontSize: 18, bold: true, color: C.text2, align: 'right' });
    T(s, f[1] + '%', { x: 11.95, y, w: 0.78, h: 0.4, fontSize: 16, color: MUTED, align: 'right' });
  });
  T(s, 'Proposed split, to be confirmed. Growth spending comes from the raise, not from the profit that is shared.', { x: 0.6, y: 6.4, w: 12.13, h: 0.35, fontSize: 12, color: MUTED });
  s.addNotes('Mostly growth, plus a six-month reserve as a safety net. Staff is not funded from the raise. Spending is tracked line by line against its budget, and investors see it in their portal. The split shown is a proposal and must be confirmed. The launch spending is not deducted from the profit that is shared.');

  // 11 CONTROLS
  s = pres.addSlide({ masterName: 'CONTENT' });
  s.addText('Growth spending is capped and tracked', { placeholder: 'title' });
  lead(s, 'Bonuses and referral rewards are built into the platform with hard limits. Nothing is paid until the admin switches it on.');
  const ct = [['FaWallet', 'Monthly budget cap', 'An award that does not fit in what is left of the month is skipped.'], ['FaClock', 'Daily trip bonus', 'Tiers such as 5 trips and 8 trips, paid once per tier per day.'], ['FaUserFriends', 'Referral reward', 'Paid when a referred driver completes qualifying trips from at least 3 different riders.'], ['FaBalanceScale', 'Anti-farming rules', 'Minimum fare and distance, a limit per rider per day, a per-driver monthly maximum.'], ['FaBan', 'Void and audit', 'Every award is a record. Admin can void one and the money is taken back.'], ['FaChartLine', 'Live in the portal', 'Promotion, bonus and referral spending are counted into the launch budget automatically.']];
  for (let i = 0; i < 6; i++) {
    const col = i % 3, row = Math.floor(i / 3), x = 0.6 + col * 4.1, y = 2.3 + row * 2.15;
    card(s, x, y, 3.93, 1.95, 'control card ' + (i + 1));
    await iconCircle(s, ct[i][0], x + 0.3, y + 0.3, 0.7, NAVY, GOLD);
    T(s, ct[i][1], { x: x + 1.2, y: y + 0.3, w: 2.55, h: 0.7, fontSize: 18, bold: true, color: C.text2, valign: 'middle' });
    T(s, ct[i][2], { x: x + 0.3, y: y + 1.1, w: 3.4, h: 0.8, fontSize: 13, color: C.text1 });
  }
  s.addNotes('This is what makes the launch budget safe to give to a growth team. Walk through the caps: a monthly budget ceiling, a per-driver maximum, minimum fare and distance, a limit of trips counted per rider per day, and at least 3 different riders before a referral reward. Every award is a record that an admin can void. The investor portal reads the spending automatically. Bonuses are announced to drivers in writing; the app does not notify them.');

  // 12 PROTECTION
  s = pres.addSlide({ masterName: 'CONTENT' });
  s.addText('How we protect you', { placeholder: 'title' });
  const pr = [['FaCoins', 'Profit only', 'You share profit, never losses. No profit, no payout, nothing owed back.'], ['FaEye', 'See every number', 'A statement each period and your own investor portal login.'], ['FaDatabase', 'Read-only data link', 'Trip numbers come straight from the platform through a read-only connection.'], ['FaFileSignature', 'Signed agreement', 'A signed Profit-Share Investment Agreement and a signed deal sheet.'], ['FaExclamationTriangle', 'Honest risks', 'Profit is not guaranteed and you may receive less than you paid.']];
  for (let i = 0; i < 5; i++) {
    const y = 1.5 + i * 1.04;
    card(s, 0.6, y, 12.13, 0.9, 'protect row ' + (i + 1));
    await iconCircle(s, pr[i][0], 0.85, y + 0.12, 0.66, NAVY, GOLD);
    T(s, pr[i][1], { x: 1.8, y, w: 3.3, h: 0.9, fontSize: 20, bold: true, color: C.text2, valign: 'middle' });
    T(s, pr[i][2], { x: 5.2, y, w: 7.3, h: 0.9, fontSize: 16, color: C.text1, valign: 'middle' });
  }
  s.addNotes('Five protections, in plain words. The data link is read-only, so Etel cannot edit the numbers investors see. Every payout period produces a statement. The deal sheet is a signed record with a fingerprint. Finish with honest risks on the next slide: we would rather say it than have an investor find it out later.');

  // 13 RISKS
  s = pres.addSlide({ masterName: 'CONTENT' });
  s.addText('Risks, stated plainly', { placeholder: 'title' });
  lead(s, 'This is a growth investment in an early-stage company. Please read this slide carefully.');
  const rk = [['Profit is not guaranteed', 'If there is no profit, there is no payout. You may receive less than you paid.'], ['Payback depends on volume', 'In our illustration at 1,500 trips a day with a full team, 100 shares earn about ETB 3,000 a month: roughly 33 months to pay back, before any unsold-share bonus.'], ['Launch delays payouts', 'No profit is paid during the launch period: at most 90 days, or until 150 trips a day.'], ['Assumptions can change', 'Commission, fares, costs and trips a day in this deck are assumptions, not forecasts.']];
  for (let i = 0; i < 4; i++) {
    const col = i % 2, row = Math.floor(i / 2), x = 0.6 + col * 6.15, y = 2.2 + row * 2.2;
    card(s, x, y, 5.98, 1.95, 'risk card ' + (i + 1), i === 0 ? C.text2 : C.background2);
    T(s, rk[i][0], { x: x + 0.35, y: y + 0.25, w: 5.3, h: 0.5, fontSize: 22, bold: true, color: i === 0 ? C.accent2 : C.text2 });
    T(s, rk[i][1], { x: x + 0.35, y: y + 0.85, w: 5.3, h: 1.0, fontSize: 15, color: i === 0 ? C.accent5 : C.text1 });
  }
  s.addNotes('Do not rush this slide. Say each of the four in your own words. Never say guaranteed, safe, or that investors will double their money. Never give a payback date. Investors who hear the risks from you are more likely to trust the rest.');

  // 14 NEXT STEPS
  s = pres.addSlide({ masterName: 'DARK' });
  s.addText('Next steps', { placeholder: 'title' });
  rings(s, 10.6, 3.9, [2.55, 2.15, 1.75], GOLD, 60);
  s.addImage({ path: SEAL, x: 9.1, y: 2.5, w: 3.0, h: 3.0, altText: 'Etel Group master seal' });
  const ns = ['Read the deal sheet', 'Choose how many Profit Shares', 'Sign the deal sheet and the Agreement', 'Receive your investor portal login'];
  ns.forEach((n, i) => {
    const y = 1.6 + i * 1.12;
    s.addShape(pres.shapes.OVAL, { x: 0.6, y, w: 0.8, h: 0.8, fill: { color: GOLD }, line: { color: GOLD, width: 0 }, objectName: 'next step ' + (i + 1) });
    T(s, String(i + 1), { x: 0.6, y, w: 0.8, h: 0.8, fontSize: 26, bold: true, color: C.text2, align: 'center', valign: 'middle' });
    T(s, n, { x: 1.7, y, w: 6.8, h: 0.8, fontSize: 24, color: C.background1, valign: 'middle' });
  });
  s.addShape(pres.shapes.ROUNDED_RECTANGLE, { x: 0.6, y: 6.15, w: 4.6, h: 0.55, rectRadius: 0.27, fill: { color: GOLD }, line: { color: GOLD, width: 0 }, objectName: 'cta' });
  T(s, 'Open the deal sheet:  ethelmarket.com', { x: 0.6, y: 6.15, w: 4.6, h: 0.55, fontSize: 15, bold: true, color: C.text2, align: 'center', valign: 'middle' });
  T(s, 'Delivery & Ride is the first Etel company to raise money. The other companies in the group are separate businesses and are not part of this deal.', { x: 5.5, y: 6.1, w: 3.6, h: 0.7, fontSize: 11, color: C.accent5 });
  s.addNotes('Close with the four steps and invite questions. Remind investors: Delivery & Ride is the only company in this deal. Offer the live demo and the deal sheet. Add your phone number and contact details to this slide before the meeting; they are intentionally left out.');

  const OUT = LANG === 'am' ? 'ETEL_Investor_Presentation_AM.pptx' : 'ETEL_Investor_Presentation.pptx';
  await pres.writeFile({ fileName: OUT });
  await applyTheme(OUT, THEME);
  if (MISS.size) { console.log('MISSING TRANSLATIONS:'); console.log(JSON.stringify([...MISS], null, 1)); }
  console.log('built');
})().catch(e => { console.error(e); process.exit(1); });

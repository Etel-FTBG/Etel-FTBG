// Reads the Amharic dictionaries the Delivery apps already have (English text -> Amharic) and prints them as one JSON map.
//   node tools/i18n/extract_am.js > /tmp/am.json        (used once to fill the am column of strings.tsv; kept so it can be repeated)
const fs = require('fs'), path = require('path');
const J = path.join(__dirname, '..', '..', 'delivery', 'js');
function literal(src, startMarker) {
  const i = src.indexOf(startMarker); if (i < 0) return {};
  let j = src.indexOf('{', i), d = 0, q = '', k;
  for (k = j; k < src.length; k++) {
    const c = src[k];
    if (q) { if (c === '\\') k++; else if (c === q) q = ''; continue; }
    if (c === '"' || c === "'" || c === '`') { q = c; continue; }
    if (c === '{') d++; else if (c === '}') { d--; if (!d) break; }
  }
  try { return new Function('return ' + src.slice(j, k + 1))(); } catch (e) { return {}; }
}
const out = {};
for (const [f, m] of [['ride-i18n.js', 'var AM = {'], ['driver-i18n.js', 'var AM = {'], ['courier-i18n.js', 'var EXACT = {']]) {
  const o = literal(fs.readFileSync(path.join(J, f), 'utf8'), m);
  for (const k of Object.keys(o)) if (typeof o[k] === 'string' && k.trim() && !(k in out)) out[k] = o[k];
}
// key tables: en: {key: 'English'}, am: {key: 'Amharic'} side by side (core.js, servicegiver-i18n.js, login.html)
const ROOT = path.join(__dirname, '..', '..', 'delivery');
for (const f of [path.join(J, 'core.js'), path.join(J, 'servicegiver-i18n.js'), path.join(ROOT, 'login.html')]) {
  const src = fs.readFileSync(f, 'utf8'); let pos = 0;
  for (;;) {
    const m = /\ben: *\{/.exec(src.slice(pos)); if (!m) break;
    const at = pos + m.index, en = literal(src.slice(at), 'en: {');
    const rest = src.slice(at + 5), m2 = /\bam: *\{/.exec(rest);
    pos = at + 5;
    if (!m2) continue;
    const am = literal(rest.slice(m2.index), 'am: {');
    for (const k of Object.keys(en)) if (typeof en[k] === 'string' && typeof am[k] === 'string' && en[k].trim() && !(en[k] in out)) out[en[k]] = am[k];
  }
}
process.stdout.write(JSON.stringify(out));

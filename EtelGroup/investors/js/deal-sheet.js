/* ============================================================
   Investor Deal Sheet - shared pieces (words in English and Amharic, and the filled-in sheet).
   Used by deal.html (the investor fills and signs) and admin.html (the Company reviews and prints).
   The Amharic is a first draft: a native speaker should review it before it goes to investors.
   ============================================================ */
(function () {
'use strict';
var TXT = {
  en: {
    company: 'Etel Family Technology and Business Group', title: 'Investor Deal Sheet', sub: 'Profit-share partnership · Hawassa · 2026',
    h1: 'How you earn', pe: 'Platform earns', pe_s: 'Commission on rides and orders', rc: 'Running costs', rc_s: 'Staff, rent, fees, contingency',
    np: 'Net profit', np_s: 'For each period', ip: 'Investor pool', ip_s: '20 % of net profit',
    s1b: 'Profit Shares in the pool', s1s: 'Your part = your shares ÷ 2,000. The price per share rises by stage.',
    s2b: 'Boost until paid back', s2s: 'Holding the whole pool then equals 50 % of net profit.',
    s3b: 'Then the standard share', s3s: 'You keep sharing after payback, to the end date.',
    h2: 'Your deal', f_name: 'Full legal name', f_id: 'ID / passport / registration no.', f_phone: 'Phone', f_email: 'Email (optional)',
    f_units: 'Profit Shares you want to buy', f_amt: 'Amount (ETB) = units × price per share', f_pct: 'Share of pool (%) = units ÷ 2,000', f_close: 'Preferred closing date',
    f_payout: 'How often you want to be paid', monthly: 'Monthly', quarterly: 'Quarterly', other: 'Other', f_days: 'Paid within (days after the period)', f_bank: 'Bank account for payouts',
    open_now: 'Profit Shares open now', calc_h: 'What this could look like', calc_p: 'On a net profit of ETB 100,000 in one period you would receive',
    calc_std: 'standard', calc_boost: 'boosted, until you are paid back',
    h3: 'A worked example', ex_h: 'Net profit ETB 100,000 · Pool (20 %) ETB 20,000', std: 'Standard', boost: 'Boosted',
    r1: '100 Profit Shares (5 % of pool)', r2: '400 Profit Shares (20 % of pool)', r3: '2,000 Profit Shares (whole pool)',
    h4: 'Good to know', g1b: 'Profit only, never losses', g1s: 'No profit, no payout, and nothing owed back.',
    g2b: 'No ownership, no vote', g2s: 'A Profit Share is a right to profit, not a share in the Company.', g3b: 'See every number', g3s: 'A statement each period and your own portal login.',
    h5: 'Sign', agree: 'I confirm that the details above are true, that the funds come from a lawful source, and that I have read the Profit-Share Investment Agreement. I understand that profit is not guaranteed and that I may receive less than I paid.',
    sig_name: 'Type your full name to sign', sig_draw: 'Draw your signature here', sig_clear: 'Clear', submit: 'Sign and submit', sending: 'Sending…',
    ok_h: 'Thank you. Your deal sheet is signed.', ok_p: 'The Company will review your sheet and contact you. Keep your reference.', ref: 'Reference', print: 'Print or save as PDF', blank: 'Download the blank form (PDF)', another: 'Fill another sheet',
    closed: 'No Profit Shares are open at the moment. Please contact the Company.', lang: 'EN',
    sh_deal: 'Deal', sh_investor: 'Investor', sh_company: 'For the Company', sh_signed: 'Signed', sh_hash: 'Document fingerprint', stamp: 'Official stamp', units_word: 'Profit Shares',
    fine: 'Summary of the Profit-Share Investment Agreement · the full Agreement and Schedules apply', per_monthly: 'Monthly', per_quarterly: 'Quarterly', per_other: 'Other', days: 'days', none: '-',
    st_h: 'The raise in stages', st_p: 'The price per share rises as the business proves itself. Early investors take more risk and get the lowest price.',
    st_stage: 'Stage', st_price: 'Price per share', st_shares: 'Shares', st_status: 'Status', st_open: 'Open now', st_opens: 'Opens at {n} trips a day', st_sold: 'Sold out', st_locked: 'Opens later', st_your: 'You are buying in Stage',
    next_h: 'The Etel Group', live_this: 'Live · this deal', self_line: 'Rides and deliveries', next_p: 'Delivery & Ride is the first company to raise money. The other companies in the group are separate businesses and are not part of this deal.', live_word: 'Live', soon_word: 'Coming soon',
    demo_banner: 'DEMO · sample figures · nothing you enter here is saved', demo_ok: 'This was a preview in demo mode. Nothing was saved.', theme_btn: 'Light / dark',
    sigd: 'Signature & date', sh_status: 'Status', sh_electronic: 'Signed electronically. A signed printed copy may also be required.'
  },
  am: {
      "company": "ኢትኤል ቤተሰብ ቴክኖሎጂ እና ቢዝነስ ግሩፕ",
      "title": "የባለሀብት የስምምነት ሉህ",
      "sub": "የትርፍ ተካፋይነት አጋርነት · ሀዋሳ · 2026",
      "h1": "እንዴት ያገኛሉ",
      "pe": "መድረኩ የሚያገኘው",
      "pe_s": "ከጉዞና ከትዕዛዝ የሚገኝ ኮሚሽን",
      "rc": "የሥራ ማስኬጃ ወጪ",
      "rc_s": "ደመወዝ፣ ኪራይ፣ የክፍያ ክፍያዎች፣ ተጠባባቂ",
      "np": "የተጣራ ትርፍ",
      "np_s": "ለእያንዳንዱ ወቅት",
      "ip": "የባለሀብቶች ድርሻ",
      "ip_s": "20 % የተጣራ ትርፍ",
      "s1b": "በጋራ ድርሻ ውስጥ ያሉ የትርፍ ድርሻዎች",
      "s1s": "ድርሻዎ = የድርሻዎ ብዛት ÷ 2,000። የአንድ ድርሻ ዋጋ በደረጃ ይጨምራል።",
      "s2b": "እስኪመለስልዎ ድረስ ጭማሪ",
      "s2s": "ሙሉውን ድርሻ ቢይዙ ከተጣራ ትርፍ 50 % ይሆናል።",
      "s3b": "ከዚያ በኋላ መደበኛ ድርሻ",
      "s3s": "ገንዘብዎ ከተመለሰ በኋላ እስከ ስምምነቱ ማብቂያ መካፈልዎን ይቀጥላሉ።",
      "h2": "የእርስዎ ስምምነት",
      "f_name": "ሙሉ ሕጋዊ ስም",
      "f_id": "የመታወቂያ / ፓስፖርት / የምዝገባ ቁጥር",
      "f_phone": "ስልክ",
      "f_email": "ኢሜይል (አማራጭ)",
      "f_units": "የሚገዙት የትርፍ ድርሻዎች ብዛት",
      "f_amt": "መጠን (ብር) = ብዛት × የአንድ ድርሻ ዋጋ",
      "f_pct": "የጋራ ድርሻው (%) = ብዛት ÷ 2,000",
      "f_close": "የሚፈልጉት የመዝጊያ ቀን",
      "f_payout": "የክፍያ ድግግሞሽ",
      "monthly": "ወርሃዊ",
      "quarterly": "የሩብ ዓመት",
      "other": "ሌላ",
      "f_days": "ከወቅቱ ማብቂያ በኋላ በስንት ቀናት ውስጥ ይከፈልዎ",
      "f_bank": "ክፍያ የሚተላለፍበት የባንክ ሂሳብ",
      "open_now": "አሁን የተከፈቱ የትርፍ ድርሻዎች",
      "calc_h": "ይህ እንዴት ሊሆን ይችላል",
      "calc_p": "በአንድ ወቅት ውስጥ የተጣራ ትርፍ ETB 100,000 ቢሆን ይቀበላሉ",
      "calc_std": "መደበኛ",
      "calc_boost": "ጭማሪ ያለው (እስኪመለስልዎ ድረስ)",
      "h3": "ምሳሌ",
      "ex_h": "የተጣራ ትርፍ ETB 100,000 · የባለሀብቶች ድርሻ (20 %) ETB 20,000",
      "std": "መደበኛ",
      "boost": "ጭማሪ ያለው",
      "r1": "100 የትርፍ ድርሻ (የድርሻው 5 %)",
      "r2": "400 የትርፍ ድርሻ (የድርሻው 20 %)",
      "r3": "2,000 የትርፍ ድርሻ (ሙሉው)",
      "h4": "ማወቅ ያለብዎት",
      "g1b": "ትርፍ ብቻ፣ ኪሳራ በጭራሽ",
      "g1s": "ትርፍ ከሌለ ክፍያ የለም፤ የሚመለስም ዕዳ የለም።",
      "g2b": "ባለቤትነት የለም፣ ድምጽ የለም",
      "g2s": "የትርፍ ድርሻ የትርፍ መብት ነው እንጂ የኩባንያው ድርሻ አይደለም።",
      "g3b": "ሁሉንም ቁጥር ይመልከቱ",
      "g3s": "ለእያንዳንዱ ወቅት መግለጫ እና የራስዎ የፖርታል መግቢያ ያገኛሉ።",
      "h5": "ይፈርሙ",
      "agree": "ከላይ የተጻፉት ዝርዝሮች እውነት መሆናቸውን፣ ገንዘቡ ከሕጋዊ ምንጭ መሆኑን፣ እንዲሁም የትርፍ ተካፋይነት ኢንቨስትመንት ስምምነቱን ማንበቤን አረጋግጣለሁ። ትርፍ እንደማይረጋገጥና ከከፈልኩት ያነሰ ልቀበል እንደምችል ተረድቻለሁ።",
      "sig_name": "ለመፈረም ሙሉ ስምዎን ይተይቡ",
      "sig_draw": "ፊርማዎን እዚህ ይሳሉ",
      "sig_clear": "አጥፋ",
      "submit": "ይፈርሙ እና ያስገቡ",
      "sending": "በመላክ ላይ…",
      "ok_h": "እናመሰግናለን። የስምምነት ሉህዎ ተፈርሟል።",
      "ok_p": "ኩባንያው ሉህዎን ይመረምራል፤ ያነጋግርዎታል። ማጣቀሻዎን ያስቀምጡ።",
      "ref": "ማጣቀሻ",
      "print": "አትም ወይም እንደ PDF አስቀምጥ",
      "blank": "ባዶ ፎርም (PDF) ያውርዱ",
      "another": "ሌላ ሉህ ይሙሉ",
      "closed": "አሁን የተከፈተ የትርፍ ድርሻ የለም። እባክዎን ኩባንያውን ያነጋግሩ።",
      "lang": "አማ",
      "sh_deal": "ስምምነት",
      "sh_investor": "ባለሀብት",
      "sh_company": "ለኩባንያው",
      "sh_signed": "የተፈረመበት",
      "sh_hash": "የሰነድ ማረጋገጫ ቁጥር",
      "stamp": "ይፋዊ ማኅተም",
      "units_word": "የትርፍ ድርሻዎች",
      "fine": "የትርፍ ተካፋይነት ኢንቨስትመንት ስምምነት ማጠቃለያ · ሙሉው ስምምነትና አባሪዎች ተፈጻሚ ናቸው",
      "per_monthly": "ወርሃዊ",
      "per_quarterly": "የሩብ ዓመት",
      "per_other": "ሌላ",
      "days": "ቀናት",
      "none": "-",
      "sh_status": "ሁኔታ",
      "sh_electronic": "በኤሌክትሮኒክ ተፈርሟል። የታተመና የተፈረመ ቅጂም ሊያስፈልግ ይችላል።",
      "st_h": "ገንዘቡ በደረጃ ይሰበሰባል",
      "st_p": "ንግዱ እየተረጋገጠ ሲሄድ የአንድ ድርሻ ዋጋ ይጨምራል። ቀድመው የሚገቡ ባለሀብቶች ተጨማሪ አደጋ ይወስዳሉ፤ ዝቅተኛውንም ዋጋ ያገኛሉ።",
      "st_stage": "ደረጃ",
      "st_price": "የአንድ ድርሻ ዋጋ",
      "st_shares": "ድርሻዎች",
      "st_status": "ሁኔታ",
      "st_open": "አሁን ክፍት",
      "st_opens": "በቀን {n} ጉዞ ሲደርስ ይከፈታል",
      "st_sold": "ተሽጧል",
      "st_locked": "በኋላ ይከፈታል",
      "st_your": "የሚገዙት በደረጃ",
      "next_h": "ኢትኤል ግሩፕ",
      "live_this": "ሥራ ላይ · ይህ ስምምነት",
      "self_line": "ጉዞና ዕቃ ማመላለሻ",
      "next_p": "ዴሊቨሪ እና ራይድ ገንዘብ የሚሰበስብ የመጀመሪያው ኩባንያ ነው። በግሩፑ ውስጥ ያሉ ሌሎች ኩባንያዎች የተለያዩ ንግዶች ናቸው፤ የዚህ ስምምነት አካል አይደሉም።",
      "live_word": "ሥራ ላይ",
      "soon_word": "በቅርቡ",
      "demo_banner": "ማሳያ · የናሙና ቁጥሮች · እዚህ የሚያስገቡት ምንም አይቀመጥም",
      "demo_ok": "ይህ በማሳያ ሁኔታ የተደረገ ቅድመ እይታ ነበር። ምንም አልተቀመጠም።",
      "theme_btn": "ብርሃን / ጨለማ",
      "sigd": "ፊርማ እና ቀን"
  }
};
function fmt(str, o) { return String(str).replace(/\{(\w+)\}/g, function (m, k) { return o && o[k] != null ? o[k] : m; }); }
function T(lang) { return TXT[lang] || TXT.en; }
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
function num(n) { return Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 2 }); }

// What an investor of $units Profit Shares gets from a net profit (standard and boosted), same rules as the distribution engine.
function payout(units, profit, cfg) {
  var pool = profit * (cfg.pool_percent / 100), part = units / cfg.pool_units;
  return { std: pool * part, boost: pool * part * cfg.boost };
}

// The filled-in sheet (one A4 page), used on screen, in the admin and for printing.
function renderSheet(d, lang, cfg) {
  var t = T(lang || d.lang), c = cfg || { pool_units: 2000, pool_percent: 20, boost: 2.5 };
  var p = payout(Number(d.units), 100000, c);
  var period = { monthly: t.per_monthly, quarterly: t.per_quarterly, other: t.per_other }[d.payout_period] || t.per_other;
  var pct = Number(d.pool_share_pct != null ? d.pool_share_pct : Number(d.units) / c.pool_units * 100);
  function row(k, v) { return '<tr><th>' + k + '</th><td>' + v + '</td></tr>'; }
  return '<article class="dl-sheet' + ((lang || d.lang) === 'am' ? ' dl-am' : '') + '">' +
    '<header class="dl-band"><img src="img/logo-etel.webp" alt=""><div><div class="dl-e">' + esc(t.company) + '</div><h1>' + esc(t.title) + '</h1><div class="dl-s">' + esc(t.sub) + '</div></div><img src="img/logo-master.webp" alt=""></header>' +
    '<div class="dl-refline"><b>' + esc(t.ref) + ' ' + esc(d.ref_code) + '</b><span>' + esc(t.sh_signed) + ' ' + esc(d.signed_at) + '</span></div>' +
    '<section><h2>' + esc(t.h2) + '</h2><table class="dl-tbl">' +
      row(esc(t.f_name), esc(d.investor_name)) + row(esc(t.f_id), esc(d.id_number)) + row(esc(t.f_phone) + ' · ' + esc(t.f_email), esc(d.phone) + (d.email ? ' · ' + esc(d.email) : '')) +
      row(esc(t.f_units), '<b>' + num(d.units) + '</b> ' + esc(t.units_word)) + row(esc(t.f_amt) + (d.unit_price ? ' (ETB ' + num(d.unit_price) + ')' : ''), '<b>ETB ' + num(d.amount) + '</b>') +
      row(esc(t.f_pct), '<b>' + pct.toFixed(2) + ' %</b>') + row(esc(t.f_close), esc(d.closing_date || t.none)) +
      row(esc(t.f_payout), esc(period) + (Number(d.payout_days) ? ' · ' + esc(d.payout_days) + ' ' + esc(t.days) : '')) + row(esc(t.f_bank), esc(d.bank_account || t.none)) +
    '</table></section>' +
    '<section><h2>' + esc(t.calc_h) + '</h2><p>' + esc(t.calc_p) + ': <b>ETB ' + num(p.std) + '</b> (' + esc(t.calc_std) + ') · <b>ETB ' + num(p.boost) + '</b> (' + esc(t.calc_boost) + ').</p>' +
    '<ul class="dl-pts"><li><b>' + esc(t.g1b) + '.</b> ' + esc(t.g1s) + '</li><li><b>' + esc(t.g2b) + '.</b> ' + esc(t.g2s) + '</li><li><b>' + esc(t.g3b) + '.</b> ' + esc(t.g3s) + '</li></ul></section>' +
    '<section class="dl-sign"><div class="dl-sbox"><div class="dl-r">' + esc(t.sh_investor) + '</div>' + (d.signature_png ? '<img class="dl-sigimg" src="' + esc(d.signature_png) + '" alt="">' : '<div class="dl-sigimg"></div>') +
      '<div class="dl-l"></div><div class="dl-n">' + esc(d.signature_name) + ' · ' + esc(d.signed_at) + '</div></div>' +
      '<div class="dl-sbox"><div class="dl-r">' + esc(t.sh_company) + '</div><div class="dl-sigimg"></div><div class="dl-l"></div><div class="dl-n">' + esc(t.sigd || '') + '</div><div class="dl-stamp">' + esc(t.stamp) + '</div></div></section>' +
    '<footer class="dl-fine"><div>' + esc(t.sh_electronic) + '</div><div>' + esc(t.sh_hash) + ': <code>' + esc(d.doc_hash || '') + '</code></div><div>' + esc(t.fine) + '</div></footer>' +
    '</article>';
}

// Opens the sheet in a clean window and prints it (the browser can save it as a PDF).
function printSheet(html, lang) {
  var w = window.open('', '_blank');
  if (!w) { alert('Allow pop-ups to print.'); return; }
  var css = new URL('css/deal-r3.css', location.href).href;
  w.document.write('<!doctype html><html lang="' + (lang || 'en') + '"><head><meta charset="utf-8"><title>ETEL Investor Deal Sheet</title><base href="' + new URL('./', location.href).href + '"><link rel="stylesheet" href="' + css + '"></head><body class="dl-printpage">' + html + '</body></html>');
  w.document.close();
  w.onload = function () { setTimeout(function () { w.focus(); w.print(); }, 350); };
}

window.EtelDeal = { fmt: fmt, T: T, TXT: TXT, esc: esc, num: num, payout: payout, renderSheet: renderSheet, printSheet: printSheet };
})();

/* Investor presentation (pitch.html). English and Amharic, light and dark, prints to PDF (one slide per page).
   Numbers come from deal_pitch (stages, companies, traction). In demo mode the traction is a labelled sample.
   The Amharic is a first draft: a native speaker should review it. */
(function () {
'use strict';
var D = window.EtelDeal, $ = function (id) { return document.getElementById(id); };
var API = 'api/boot.php';
var cfg = { unit_price: 1000, stage: 1, stages: [], companies: [], boost: 2.5, pool_units: 2000, pool_percent: 20, commission_rate: 0.05, traction: { mode: 'hidden' } };
var lang = 'en';
try { lang = (new URLSearchParams(location.search).get('lang') || localStorage.getItem('etel_deal_lang') || 'en') === 'am' ? 'am' : 'en'; } catch (e) { /* keep en */ }

var TX = {
  en: {
    print: 'Save as PDF', demo: 'DEMO · sample figures · not real results',
    k_cover: 'Investor presentation · 2026', c_title: 'Etel Delivery & Ride', c_sub: 'Rides and deliveries for Hawassa, built and run by Etel Family Technology and Business Group.', c_line: 'A staged, profit-share investment',
    p_h: 'The problem', p_lead: 'Moving people and goods in Hawassa still depends on phone calls, cash and guesswork.',
    p1b: 'Riders', p1s: 'Often do not know the fare or the driver before they get in.', p2b: 'Drivers and couriers', p2s: 'Lose time waiting for work and have no record of what they earned.', p3b: 'Businesses', p3s: 'Cannot see where a delivery is or prove that it arrived.',
    s_h: 'The product', s_lead: 'One platform, five apps, one control room. Built and already working.',
    a1: 'Rider app', a1s: 'Fare shown before the trip, trip PIN, SOS, scheduled rides, receipts.', a2: 'Driver app', a2s: 'Requests, hot areas, earnings, shifts, printed receipts.', a3: 'Courier and delivery', a3s: 'Orders, parcels and tracking for shops and customers.', a4: 'Service givers', a4s: 'Local businesses take orders and payouts on the platform.', a5: 'Admin control room', a5s: 'Pricing, payouts, 2FA, system health, support, all in one place.',
    s_t: 'Transparent tariffs for moto, bajaj, qute, car, minibus and electric car.',
    t_h: 'Traction', t_demo: 'Sample figures for demonstration', t_avg: 'trips a day, 4-week average', t_total: 'trips in 28 days', t_hidden: 'We share live traction numbers in the meeting.', t_real: 'Real figures, from the live platform', t_note: 'Updated automatically from the platform.',
    e_h: 'How investors earn', e1: 'Platform earns', e1s: 'Commission on rides and orders', e2: 'Running costs', e2s: 'Staff, rent, fees, contingency', e3: 'Net profit', e3s: 'For each period', e4: 'Investor pool', e4s: '20 % of net profit',
    e_b1: '2.5×', e_b1s: 'Boost until you are paid back', e_b2: '1×', e_b2s: 'Standard share after payback', e_b3: 'Profit only', e_b3s: 'No profit, no payout, nothing owed back',
    e_ex: 'Example: net profit ETB 100,000 in a period', e_c1: 'Your shares', e_c2: 'Standard', e_c3: 'Boosted',
    r_h: 'The raise, in stages', r_lead: 'The price per share rises as the business proves itself. Early investors take more risk and get the lowest price.', r_stage: 'Stage', r_price: 'Price per share', r_shares: 'Shares', r_raise: 'Raises', r_pct: 'Of company', r_open: 'Opens', r_now: 'Open now', r_at: '{n} trips a day', r_sold: 'Sold out', r_total: 'All stages', r_note: 'Profit-share rights, not company ownership. The pool is 20 % of the company in total.',
    i_h: 'What it could look like', i_lead: 'Illustrations, not forecasts. Assumed average fare ETB {fare}; company revenue ETB {rev} per trip; two running-cost plans.', i_trips: 'Trips a day', i_rev: 'Company revenue / month', i_lean: "Net profit, today's costs", i_full: 'Net profit, full team', i_pay: 'Per {u} shares / month, boosted (full team)',
    i_be: 'Break-even', i_be_l: "Today's costs (ETB {c} a month)", i_be_f: 'Full team (ETB {c} a month)', i_trips_day: 'trips a day', i_note: "Today's costs: office rent ETB 6,000 and customer service ETB 10,000 a month, plus ETB 20,000 a year spread over 12 months. Full team: an assumed ETB {full} a month. Profit is shared only when positive.",
    l_h: 'The launch plan', l_lead: 'The raise pays to grow first. Profit is shared after the launch period.',
    l1: 'Launch', l1s: 'New drivers pay no commission for their first 60 days (proposed), with bonuses for drivers and discounts on first trips. The raise pays for it.', l2: 'Step up', l2s: 'Commission returns in steps (proposed): 2% for the next 30 days, then the standard 5%.', l3: 'First payout', l3s: 'Once there is profit, investors share it, with the 2.5× boost until they are paid back.', l4: 'Open books', l4s: 'A monthly spending report, and the launch budget in your investor portal.',
    l_note: 'The launch period ends after at most {d} days, or earlier when {n} trips a day are reached over 4 weeks. Final terms are in the signed agreement.', l_note_nogoal: 'The launch period ends after at most {d} days. Final terms are in the signed agreement.', l_until: 'Launch period until {d}.',
    u_h: 'Use of funds (proposed)', u_lead: 'The raise pays for growth. Running costs stay small.', u_item: 'Item', u_share: 'Share', u_amt: 'Amount (Stage 1)', u_note: 'Proposed split of the Stage 1 raise. To be confirmed.',
    u1: 'Reserve', u1s: 'Six months of running costs, as a safety net.', u2: 'Marketing', u2s: 'Posters, social media and launch events in Hawassa.', u3: 'Promotion', u3s: 'Launch discounts and promo codes for riders.', u4: 'Bonuses', u4s: 'Bonuses for drivers and couriers who reach their goals.', u5: 'Referral rewards', u5s: 'Rewards for people who bring in new drivers and riders.', u6: 'Materials', u6s: 'Branding, uniforms, stickers and printed material.',
    g_h: 'The Etel Group', g_lead: 'Delivery & Ride is the first company to raise money. The others are separate businesses and are not part of this deal.', g_live: 'Live', g_soon: 'Coming soon', g_this: 'Live · this deal', g_self: 'Rides and deliveries',
    f_h: 'How we protect you', f1: 'Profit only', f1s: 'You share profit, never losses.', f2: 'See every number', f2s: 'A statement each period and your own portal login.', f3: 'Read-only data link', f3s: 'Trip numbers come straight from the platform through a read-only connection.', f4: 'Signed agreement', f4s: 'A signed Profit-Share Investment Agreement and a signed deal sheet.', f5: 'Honest risks', f5s: 'Profit is not guaranteed and you may receive less than you paid.',
    n_h: 'Next steps', n1: 'Read the deal sheet', n2: 'Choose how many Profit Shares', n3: 'Sign the deal sheet and the agreement', n4: 'Receive your portal login', n_cta: 'Open the deal sheet', n_site: 'ethelmarket.com'
  },
  am: {
    print: 'እንደ PDF አስቀምጥ', demo: 'ማሳያ · የናሙና ቁጥሮች · እውነተኛ ውጤት አይደለም',
    k_cover: 'የባለሀብቶች አቀራረብ · 2026', c_title: 'ኢትኤል ዴሊቨሪ እና ራይድ', c_sub: 'ለሀዋሳ የጉዞና የዕቃ ማመላለሻ አገልግሎት፤ በኢትኤል ቤተሰብ ቴክኖሎጂ እና ቢዝነስ ግሩፕ የተሰራና የሚመራ።', c_line: 'በደረጃ የሚሰበሰብ የትርፍ ተካፋይነት ኢንቨስትመንት',
    p_h: 'ችግሩ', p_lead: 'በሀዋሳ ሰውና ዕቃ ማመላለስ አሁንም በስልክ ጥሪ፣ በጥሬ ገንዘብና በግምት ላይ የተመሰረተ ነው።',
    p1b: 'ተሳፋሪዎች', p1s: 'ከመግባታቸው በፊት ዋጋውንና አሽከርካሪውን ብዙ ጊዜ አያውቁም።', p2b: 'አሽከርካሪዎችና ተላላኪዎች', p2s: 'ሥራ በመጠበቅ ጊዜ ያባክናሉ፤ ያገኙትንም የሚመዘግብ የለም።', p3b: 'ንግዶች', p3s: 'ዕቃው የት እንዳለ ማየትም ሆነ መድረሱን ማረጋገጥ አይችሉም።',
    s_h: 'ምርቱ', s_lead: 'አንድ መድረክ፣ አምስት መተግበሪያዎች፣ አንድ መቆጣጠሪያ ክፍል። ተሰርቶ ሥራ ላይ ነው።',
    a1: 'የተሳፋሪ መተግበሪያ', a1s: 'ከጉዞ በፊት ዋጋ፣ የጉዞ ፒን፣ SOS፣ የቀጠሮ ጉዞ፣ ደረሰኝ።', a2: 'የአሽከርካሪ መተግበሪያ', a2s: 'ጥያቄዎች፣ የሥራ ብዛት ያለባቸው አካባቢዎች፣ ገቢ፣ ፈረቃ፣ የታተመ ደረሰኝ።', a3: 'ተላላኪና ዴሊቨሪ', a3s: 'ለሱቆችና ለደንበኞች ትዕዛዝ፣ እሽግና ክትትል።', a4: 'አገልግሎት ሰጪዎች', a4s: 'የአካባቢ ንግዶች ትዕዛዝና ክፍያ በመድረኩ ይቀበላሉ።', a5: 'የአስተዳዳሪ መቆጣጠሪያ', a5s: 'ዋጋ፣ ክፍያ፣ 2FA፣ የሲስተም ጤና፣ ድጋፍ በአንድ ቦታ።',
    s_t: 'ለሞተር፣ ባጃጅ፣ ኩቴ፣ መኪና፣ ሚኒባስና ኤሌክትሪክ መኪና ግልጽ ታሪፍ።',
    t_h: 'እስካሁን የተገኘው', t_demo: 'ለማሳያ የተዘጋጁ የናሙና ቁጥሮች', t_avg: 'በቀን ጉዞ፣ የ4 ሳምንት አማካይ', t_total: 'በ28 ቀናት ጉዞ', t_hidden: 'ወቅታዊ ቁጥሮችን በስብሰባው ላይ እናካፍላለን።', t_real: 'እውነተኛ ቁጥሮች ከመድረኩ', t_note: 'ከመድረኩ በራስ-ሰር ይዘምናል።',
    e_h: 'ባለሀብቶች እንዴት ያገኛሉ', e1: 'መድረኩ የሚያገኘው', e1s: 'ከጉዞና ከትዕዛዝ የሚገኝ ኮሚሽን', e2: 'የሥራ ማስኬጃ ወጪ', e2s: 'ደመወዝ፣ ኪራይ፣ የክፍያ ክፍያዎች፣ ተጠባባቂ', e3: 'የተጣራ ትርፍ', e3s: 'ለእያንዳንዱ ወቅት', e4: 'የባለሀብቶች ድርሻ', e4s: '20 % የተጣራ ትርፍ',
    e_b1: '2.5×', e_b1s: 'እስኪመለስልዎ ድረስ ጭማሪ', e_b2: '1×', e_b2s: 'ከተመለሰ በኋላ መደበኛ ድርሻ', e_b3: 'ትርፍ ብቻ', e_b3s: 'ትርፍ ከሌለ ክፍያ የለም፤ የሚመለስም ዕዳ የለም',
    e_ex: 'ምሳሌ፦ በአንድ ወቅት የተጣራ ትርፍ ETB 100,000', e_c1: 'ድርሻዎች', e_c2: 'መደበኛ', e_c3: 'ጭማሪ ያለው',
    r_h: 'ገንዘቡ በደረጃ ይሰበሰባል', r_lead: 'ንግዱ እየተረጋገጠ ሲሄድ የአንድ ድርሻ ዋጋ ይጨምራል። ቀድመው የሚገቡ ባለሀብቶች ተጨማሪ አደጋ ይወስዳሉ፤ ዝቅተኛውንም ዋጋ ያገኛሉ።', r_stage: 'ደረጃ', r_price: 'የአንድ ድርሻ ዋጋ', r_shares: 'ድርሻዎች', r_raise: 'የሚሰበሰብ', r_pct: 'ከኩባንያው', r_open: 'ሲከፈት', r_now: 'አሁን ክፍት', r_at: 'በቀን {n} ጉዞ', r_sold: 'ተሽጧል', r_total: 'ሁሉም ደረጃዎች', r_note: 'የትርፍ መብት እንጂ የኩባንያ ባለቤትነት አይደለም። ጠቅላላ የባለሀብቶች ድርሻ ከኩባንያው 20 % ነው።',
    i_h: 'ምን ሊመስል ይችላል', i_lead: 'ምሳሌዎች እንጂ ትንበያዎች አይደሉም። የተገመተ አማካይ ዋጋ ETB {fare}፤ የኩባንያ ገቢ በጉዞ ETB {rev}፤ ሁለት የወጪ እቅዶች።', i_trips: 'በቀን ጉዞ', i_rev: 'የኩባንያ ገቢ / ወር', i_lean: 'የተጣራ ትርፍ፣ የዛሬ ወጪ', i_full: 'የተጣራ ትርፍ፣ ሙሉ ቡድን', i_pay: 'ለ{u} ድርሻ / ወር፣ ጭማሪ ያለው (ሙሉ ቡድን)',
    i_be: 'ወጪን የሚሸፍነው', i_be_l: 'የዛሬ ወጪ (በወር ETB {c})', i_be_f: 'ሙሉ ቡድን (በወር ETB {c})', i_trips_day: 'በቀን ጉዞ', i_note: 'የዛሬ ወጪ፦ የቢሮ ኪራይ በወር ETB 6,000፣ የደንበኞች አገልግሎት በወር ETB 10,000፣ እና በዓመት ETB 20,000 በ12 ወራት ተከፍሎ። ሙሉ ቡድን፦ የተገመተ በወር ETB {full}። ትርፍ የሚከፈለው ትርፍ ሲኖር ብቻ ነው።',
    l_h: 'የማስጀመሪያ እቅድ', l_lead: 'የተሰበሰበው ገንዘብ በመጀመሪያ ንግዱን ለማሳደግ ይውላል። ትርፍ የሚካፈለው ከማስጀመሪያ ወቅት በኋላ ነው።',
    l1: 'ማስጀመር', l1s: 'አዳዲስ አሽከርካሪዎች ለመጀመሪያዎቹ 60 ቀናት ኮሚሽን አይከፍሉም (የታቀደ)፤ ለአሽከርካሪዎች ቦነስ፣ ለመጀመሪያ ጉዞዎች ቅናሽ። ወጪውን የተሰበሰበው ገንዘብ ይሸፍናል።', l2: 'ደረጃ በደረጃ መመለስ', l2s: 'ኮሚሽን በደረጃ ይመለሳል (የታቀደ)፦ ለቀጣዮቹ 30 ቀናት 2%፣ ከዚያ መደበኛው 5%።', l3: 'የመጀመሪያ ክፍያ', l3s: 'ትርፍ ሲኖር ባለሀብቶች ይካፈላሉ፤ እስኪመለስላቸው ድረስ 2.5× ጭማሪ ጋር።', l4: 'ግልጽ መዝገብ', l4s: 'ወርሃዊ የወጪ ሪፖርት፣ እና የማስጀመሪያ በጀት በባለሀብት ፖርታልዎ ውስጥ።',
    l_note: 'የማስጀመሪያ ወቅት ቢበዛ ከ{d} ቀናት በኋላ፣ ወይም በ4 ሳምንት አማካይ በቀን {n} ጉዞ ሲደርስ ያበቃል። የመጨረሻ ውሎች በተፈረመው ስምምነት ውስጥ ናቸው።', l_note_nogoal: 'የማስጀመሪያ ወቅት ቢበዛ ከ{d} ቀናት በኋላ ያበቃል። የመጨረሻ ውሎች በተፈረመው ስምምነት ውስጥ ናቸው።', l_until: 'የማስጀመሪያ ወቅቱ እስከ {d} ነው።',
    u_h: 'የገንዘብ አጠቃቀም (የታቀደ)', u_lead: 'የተሰበሰበው ገንዘብ ንግዱን ለማሳደግ ይውላል። የዕለት ተዕለት ወጪ ትንሽ ነው።', u_item: 'ዓይነት', u_share: 'ድርሻ', u_amt: 'መጠን (ደረጃ 1)', u_note: 'የደረጃ 1 የታቀደ ክፍፍል። ይረጋገጣል።',
    u1: 'ተጠባባቂ', u1s: 'የስድስት ወር የሥራ ማስኬጃ ወጪ፣ እንደ ደህንነት መከታ።', u2: 'ማስታወቂያ', u2s: 'ፖስተሮች፣ ማኅበራዊ ሚዲያና በሀዋሳ የማስጀመሪያ ዝግጅቶች።', u3: 'ማስተዋወቂያ', u3s: 'ለተሳፋሪዎች የማስጀመሪያ ቅናሾችና የቅናሽ ኮዶች።', u4: 'ቦነስ', u4s: 'ግባቸውን ለሚያሳኩ አሽከርካሪዎችና ተላላኪዎች ቦነስ።', u5: 'የሪፈራል ሽልማት', u5s: 'አዳዲስ አሽከርካሪዎችንና ተሳፋሪዎችን ለሚያመጡ ሰዎች ሽልማት።', u6: 'ቁሳቁስ', u6s: 'ብራንዲንግ፣ ዩኒፎርም፣ ስቲከሮችና የታተሙ ቁሳቁሶች።',
    g_h: 'ኢትኤል ግሩፕ', g_lead: 'ዴሊቨሪ እና ራይድ ገንዘብ የሚሰበስብ የመጀመሪያው ኩባንያ ነው። ሌሎቹ የተለያዩ ንግዶች ናቸው፤ የዚህ ስምምነት አካል አይደሉም።', g_live: 'ሥራ ላይ', g_soon: 'በቅርቡ', g_this: 'ሥራ ላይ · ይህ ስምምነት', g_self: 'ጉዞና ዕቃ ማመላለሻ',
    f_h: 'እንዴት እንጠብቅዎታለን', f1: 'ትርፍ ብቻ', f1s: 'ትርፍ ይካፈላሉ እንጂ ኪሳራ አይሸከሙም።', f2: 'ሁሉንም ቁጥር ይመልከቱ', f2s: 'ለእያንዳንዱ ወቅት መግለጫና የራስዎ የፖርታል መግቢያ።', f3: 'ንባብ-ብቻ የሆነ የመረጃ ግንኙነት', f3s: 'የጉዞ ቁጥሮች በቀጥታ ከመድረኩ በንባብ-ብቻ ግንኙነት ይመጣሉ።', f4: 'የተፈረመ ስምምነት', f4s: 'የተፈረመ የትርፍ ተካፋይነት ኢንቨስትመንት ስምምነትና የተፈረመ የስምምነት ሉህ።', f5: 'ታማኝ የአደጋ መግለጫ', f5s: 'ትርፍ የተረጋገጠ አይደለም፤ ከከፈሉት ያነሰ ሊቀበሉ ይችላሉ።',
    n_h: 'ቀጣይ እርምጃዎች', n1: 'የስምምነት ሉሁን ያንብቡ', n2: 'የሚፈልጉትን የትርፍ ድርሻ ብዛት ይምረጡ', n3: 'የስምምነት ሉሁንና ስምምነቱን ይፈርሙ', n4: 'የፖርታል መግቢያዎን ይቀበሉ', n_cta: 'የስምምነት ሉሁን ክፈት', n_site: 'ethelmarket.com'
  }
};
var FARE = 120, LEAN = Math.round(6000 + 10000 + 20000 / 12), FULL = 150000, UNITS = 100;
function t() { return TX[lang]; }
function e(s) { return D.esc(s); }
function f(s, o) { return D.fmt(s, o); }
function money(n) { return 'ETB ' + D.num(Math.round(n)); }
function slide(cls, inner) { return '<section class="pt-slide ' + (cls || '') + '"><div class="pt-in">' + inner + '</div></section>'; }
var SLIDE_N = 0;
function head(k, h, lead) { SLIDE_N++; k = (SLIDE_N < 10 ? '0' : '') + SLIDE_N; return '<div class="pt-k">' + e(k) + '</div><h2>' + e(h) + '</h2>' + (lead ? '<p class="pt-lead">' + e(lead) + '</p>' : ''); }

function sCover() { var x = t(); return slide('pt-cover', '<div class="pt-logos"><img src="img/logo-etel.webp" alt="ETEL"><img src="img/logo-master.webp" alt="ETEL master seal"></div><div class="pt-k">' + e(x.k_cover) + '</div><h1>' + e(x.c_title) + '</h1><p class="pt-lead">' + e(x.c_sub) + '</p><div class="pt-chip">' + e(x.c_line) + '</div>'); }
function sProblem() { var x = t(); return slide('', head('', x.p_h, x.p_lead) + '<div class="pt-3">' + [1, 2, 3].map(function (i) { return '<div class="pt-card"><b>' + e(x['p' + i + 'b']) + '</b><span>' + e(x['p' + i + 's']) + '</span></div>'; }).join('') + '</div>'); }
function sProduct() { var x = t(); return slide('', head('', x.s_h, x.s_lead) + '<div class="pt-5">' + [1, 2, 3, 4, 5].map(function (i) { return '<div class="pt-card"><b>' + e(x['a' + i]) + '</b><span>' + e(x['a' + i + 's']) + '</span></div>'; }).join('') + '</div><p class="pt-note">' + e(x.s_t) + '</p>'); }
function sTraction() {
  var x = t(), tr = cfg.traction || { mode: 'hidden' }, body;
  if (tr.mode === 'hidden') body = '<div class="pt-big pt-soft">' + e(x.t_hidden) + '</div>';
  else body = '<div class="pt-2"><div class="pt-stat"><big>' + D.num(tr.avg_per_day) + '</big><span>' + e(x.t_avg) + '</span></div><div class="pt-stat"><big>' + D.num(tr.total_trips) + '</big><span>' + e(x.t_total) + '</span></div></div><p class="pt-note">' + e(tr.mode === 'demo' ? x.t_demo : x.t_real + '. ' + x.t_note) + '</p>';
  return slide('', head('', x.t_h) + body);
}
function sEarn() {
  var x = t(), rows = [100, 400, 2000].map(function (u) { var p = D.payout(u, 100000, cfg); return '<tr><td>' + D.num(u) + '</td><td>' + money(p.std) + '</td><td>' + money(p.boost) + '</td></tr>'; }).join('');
  return slide('', head('', x.e_h) + '<div class="pt-flow">' + [1, 2, 3, 4].map(function (i) { return '<div class="pt-b' + (i > 2 ? ' pt-key' : '') + '"><b>' + e(x['e' + i]) + '</b><span>' + e(x['e' + i + 's']) + '</span></div>' + (i < 4 ? '<i>' + (i === 1 ? '&minus;' : i === 2 ? '=' : '&rarr;') + '</i>' : ''); }).join('') + '</div>' +
    '<div class="pt-3">' + [1, 2, 3].map(function (i) { return '<div class="pt-card"><big>' + e(x['e_b' + i]) + '</big><span>' + e(x['e_b' + i + 's']) + '</span></div>'; }).join('') + '</div>' +
    '<h3>' + e(x.e_ex) + '</h3><div class="pt-scroll"><table class="pt-tbl"><tr><th>' + e(x.e_c1) + '</th><th>' + e(x.e_c2) + '</th><th>' + e(x.e_c3) + '</th></tr>' + rows + '</table></div>');
}
function sRaise() {
  var x = t(), st = cfg.stages || [], sh = 0, rs = 0;
  var rows = st.map(function (s) { sh += s.shares_total; rs += s.raise; var status = s.unlocked ? (s.remaining > 0 ? x.r_now : x.r_sold) : (s.unlock_trips ? f(x.r_at, { n: D.num(s.unlock_trips) }) : '-'); return '<tr' + (s.stage === cfg.stage ? ' class="pt-now"' : '') + '><td>' + s.stage + '</td><td>' + money(s.price) + '</td><td>' + D.num(s.shares_total) + '</td><td>' + money(s.raise) + '</td><td>' + s.company_pct + ' %</td><td>' + e(status) + '</td></tr>'; }).join('');
  return slide('', head('', x.r_h, x.r_lead) + '<div class="pt-scroll"><table class="pt-tbl"><tr><th>' + e(x.r_stage) + '</th><th>' + e(x.r_price) + '</th><th>' + e(x.r_shares) + '</th><th>' + e(x.r_raise) + '</th><th>' + e(x.r_pct) + '</th><th>' + e(x.r_open) + '</th></tr>' + rows +
    '<tr class="pt-sum"><td>' + e(x.r_total) + '</td><td></td><td>' + D.num(sh) + '</td><td>' + money(rs) + '</td><td>' + (sh / 100).toFixed(1) + ' %</td><td></td></tr></table></div><p class="pt-note">' + e(x.r_note) + '</p>');
}
function sLaunch() {
  var x = t(), l = cfg.launch || {};
  var extra = l.active && l.until ? ' ' + f(x.l_until, { d: l.until }) : '';
  var days = l.max_days || 90, goal = l.trips_goal == null ? 150 : l.trips_goal;
  var note = goal > 0 ? f(x.l_note, { d: days, n: D.num(goal) }) : f(x.l_note_nogoal, { d: days });
  return slide('', head('', x.l_h, x.l_lead) + '<div class="pt-4">' + [1, 2, 3, 4].map(function (i) { return '<div class="pt-card"><big>' + i + '</big><b>' + e(x['l' + i]) + '</b><span>' + e(x['l' + i + 's']) + '</span></div>'; }).join('') + '</div><p class="pt-note">' + e(note + extra) + '</p>');
}
function sScen() {
  var x = t(), rate = cfg.commission_rate || 0.05, revTrip = FARE * rate;
  function net(trips, cost) { return trips * 30 * revTrip - cost; }
  function pay(trips) { var n = Math.max(0, net(trips, FULL)); return n * (cfg.pool_percent / 100) * (UNITS / cfg.pool_units) * cfg.boost; }
  var rows = [100, 400, 800, 1500].map(function (tp) { return '<tr><td>' + D.num(tp) + '</td><td>' + money(tp * 30 * revTrip) + '</td><td>' + money(net(tp, LEAN)) + '</td><td>' + money(net(tp, FULL)) + '</td><td>' + money(pay(tp)) + '</td></tr>'; }).join('');
  var be = function (c) { return Math.ceil(c / (revTrip * 30)); };
  return slide('', head('', x.i_h, f(x.i_lead, { fare: FARE, rev: D.num(revTrip) })) + '<div class="pt-scroll"><table class="pt-tbl"><tr><th>' + e(x.i_trips) + '</th><th>' + e(x.i_rev) + '</th><th>' + e(x.i_lean) + '</th><th>' + e(x.i_full) + '</th><th>' + e(f(x.i_pay, { u: UNITS })) + '</th></tr>' + rows + '</table></div>' +
    '<div class="pt-2"><div class="pt-stat"><big>' + D.num(be(LEAN)) + '</big><span>' + e(f(x.i_be_l, { c: D.num(LEAN) })) + ' &middot; ' + e(x.i_trips_day) + '</span></div><div class="pt-stat"><big>' + D.num(be(FULL)) + '</big><span>' + e(f(x.i_be_f, { c: D.num(FULL) })) + ' &middot; ' + e(x.i_trips_day) + '</span></div></div>' +
    '<p class="pt-note">' + e(f(x.i_note, { full: D.num(FULL) })) + '</p>');
}
var SPLIT = [26, 25, 15, 12, 10, 12];
function sFunds() {
  var x = t(), st1 = (cfg.stages || [])[0], base = st1 ? st1.raise : 400000;
  var rows = SPLIT.map(function (pc, i) { return '<tr><td><b>' + e(x['u' + (i + 1)]) + '</b><br><small>' + e(x['u' + (i + 1) + 's']) + '</small></td><td>' + pc + ' %</td><td>' + money(base * pc / 100) + '</td></tr>'; }).join('');
  return slide('', head('', x.u_h, x.u_lead) + '<div class="pt-scroll"><table class="pt-tbl pt-fund"><tr><th>' + e(x.u_item) + '</th><th>' + e(x.u_share) + '</th><th>' + e(x.u_amt) + '</th></tr>' + rows + '</table></div><p class="pt-note">' + e(x.u_note) + '</p>');
}
function sGroup() {
  var x = t(), cs = cfg.companies || [];
  return slide('', head('', x.g_h, x.g_lead) + '<div class="pt-cos"><div class="pt-co pt-live pt-this"><b>Etel Delivery &amp; Ride</b><span>' + e(x.g_self) + '</span><i>' + e(x.g_this) + '</i></div>' + cs.map(function (c) { var line = c.tagline && c.tagline.toLowerCase() !== 'coming soon' ? c.tagline : ''; return '<div class="pt-co' + (c.live ? ' pt-live' : '') + '"><b>' + e(c.name) + '</b>' + (line ? '<span>' + e(line) + '</span>' : '') + '<i>' + e(c.live ? x.g_live : x.g_soon) + '</i></div>'; }).join('') + '</div>');
}
function sSafe() { var x = t(); return slide('', head('', x.f_h) + '<div class="pt-5">' + [1, 2, 3, 4, 5].map(function (i) { return '<div class="pt-card"><b>' + e(x['f' + i]) + '</b><span>' + e(x['f' + i + 's']) + '</span></div>'; }).join('') + '</div>'); }
function sNext() { var x = t(); return slide('pt-cover', head('', x.n_h) + '<ol class="pt-steps">' + [1, 2, 3, 4].map(function (i) { return '<li>' + e(x['n' + i]) + '</li>'; }).join('') + '</ol><a class="pt-cta" href="deal.html?lang=' + lang + '">' + e(x.n_cta) + '</a><div class="pt-note">' + e(x.n_site) + '</div>'); }

function paint() {
  var x = t(); SLIDE_N = 0;
  document.documentElement.lang = lang;
  document.body.classList.toggle('pt-am', lang === 'am');
  $('pt-print').textContent = x.print;
  var d = $('pt-demo'); d.hidden = !cfg.demo_mode; d.textContent = x.demo;
  Array.prototype.forEach.call(document.querySelectorAll('.pt-lang button'), function (b) { b.classList.toggle('on', b.getAttribute('data-lang') === lang); });
  $('pt-main').innerHTML = sCover() + sProblem() + sProduct() + sTraction() + sEarn() + sRaise() + sLaunch() + sScen() + sFunds() + sGroup() + sSafe() + sNext();
}
function setTheme(m) { document.documentElement.setAttribute('data-theme', m); try { localStorage.setItem('theme', m); } catch (er) { /* not saved */ } }
(function () { var m = 'dark'; try { m = localStorage.getItem('theme') === 'light' ? 'light' : 'dark'; } catch (er) { /* keep dark */ } document.documentElement.setAttribute('data-theme', m); })();
$('pt-theme').addEventListener('click', function () { setTheme(document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light'); });
$('pt-print').addEventListener('click', function () { window.print(); });
Array.prototype.forEach.call(document.querySelectorAll('.pt-lang button'), function (b) { b.addEventListener('click', function () { lang = b.getAttribute('data-lang'); try { localStorage.setItem('etel_deal_lang', lang); } catch (er) { /* not saved */ } paint(); }); });
paint();
fetch(API + '?action=deal_pitch').then(function (r) { return r.json(); }).then(function (res) { if (res && res.ok && res.data) { cfg = res.data; paint(); } }).catch(function () { /* keep defaults */ });
})();

// ── AUTH GUARD ──────────────────────────────────────────────────
// Was requireRole(['customer']) only - the ACTUAL, definitive root
// cause of the "driver/courier/service_giver accounts get bounced
// straight back to login.html with no error" bug chased for a very
// long time across login.html, AuthController.php, OrderController.php,
// sw.js, and multiple cache-clearing rounds - none of which were wrong
// to fix, but none of which were the culprit either. This one line, at
// the very top of customer.js (loaded via its OWN <script> tag near
// the bottom of customer.html - easy to miss since customer.html's
// <head> only shows customer-bundle.js/customer-bundle-extra.js),
// runs immediately with no auth-gate wrapper at all and enforces a
// STRICTER, OPPOSITE policy from what was later added at login and in
// OrderController.php: only 'customer' role could ever pass. Since
// core.js's _requireRoleRedirect() has no 'customer' entry in its own
// role->redirect map, a rejection here falls through to a bare
// redirect to login.html with zero query params - explaining why
// nothing ever showed up as a 401/403 in the Network tab (this never
// even calls the API) and why it was identical in every browser/cache
// state (it's deliberate, always-present source code, not anything
// cached). Widened to match the same "any EtelID can use Customer"
// policy already implemented everywhere else, rather than removing
// the guard outright - still blocks a genuinely logged-out visitor.
if (!requireRole(['customer','courier','driver','service_giver','admin'])) throw new Error('Access denied');
const user = Auth.getUser();
if (user) document.getElementById('current-location').textContent = user.name || 'My Account';

// Customer app is dark by default; the saved light/dark choice (set by the
// floating toggle in core.js) always wins. Applied here first so there is no
// flash, core.js then keeps the toggle icon in step with the same value.
window.ETEL_THEME_DEFAULT = 'dark';
(function () {
    var t = null;
    try { t = localStorage.getItem('theme'); } catch (e) {}
    document.body.classList.toggle('dark-theme', t !== 'light');
})();

// Keep the sticky search bar directly under the header whatever the header's
// real height is (display-size controller, wrapped text, big phone fonts).
// A fixed 64px offset let a taller header slide over the search bar.
(function () {
    var hdr = document.querySelector('.app-header');
    if (!hdr) return;
    function sync() {
        document.documentElement.style.setProperty('--app-header-h', Math.round(hdr.getBoundingClientRect().height) + 'px');
    }
    sync();
    if (window.ResizeObserver) { new ResizeObserver(sync).observe(hdr); }
    window.addEventListener('resize', sync);
    window.addEventListener('load', sync);
})();

// ── LANGUAGE (English / Amharic) ─────────────────────────────────
ETEL_I18N.register('customer', {
    en: {
        nav_home:'Home', nav_favorites:'Favorites', nav_orders:'Orders', nav_messages:'Messages', nav_profile:'Profile',
        shop_open:'Open', shop_closed:'Closed', shop_fee_from:'Delivery from ETB', closed_warn:'appears closed right now. You can still order, but it may wait until they open. Continue?',
        trk_title:'Your order', trk_pending:'Order placed', trk_assigned:'Courier assigned', trk_picked_up:'Picked up', trk_in_transit:'On the way', trk_track:'Track', trk_more:'more', menu_all:'All', menu_popular:'🔥 Popular', menu_deals:'🏷️ Deals', menu_quick:'⚡ Quick', pop_badge:'🔥 Popular', promo_have:'Have a promo code?', promo_ph:'Enter code', promo_apply:'Apply', promo_remove:'Remove', promo_discount:'Discount', promo_saved:'You save', rate_title:'Rate your order', rate_overall:'Overall', rate_food:'Order quality', rate_courier:'Courier', rate_tags:'What stood out?', rate_comment:'Tell us more (optional)…', rate_submit:'Submit rating', rate_pick:'Please choose an overall rating', rate_thanks:'Thanks for your review!', rate_yours:'Your rating', rate_reply:'Reply from the shop', tag_fast_delivery:'⚡ Fast delivery', tag_great_taste:'😋 Great taste', tag_well_packed:'📦 Well packed', tag_friendly_courier:'🙂 Friendly courier', tag_good_value:'💰 Good value', tag_late:'🐢 Late', tag_cold:'🥶 Arrived cold', tag_wrong_item:'❌ Wrong item', pts_title:'Your points', pts_use:'Use my points', pts_off:'Not using points', pts_need:'Earn {n} more points to start spending them', pts_applied:'⭐ Points', pts_how:'How it works', pts_earn:'Earn 1 point for every ETB 10 of items on delivered orders — double on Saturdays and Sundays.', pts_spend:'Spend 10 points = ETB 1 off your items at checkout, once you have 100 points.', pts_recent:'Recent activity', pts_none:'No activity yet — your first delivered order earns points.', pts_worth:'worth', chat_btn:'💬 Chat with the shop & courier', chat_btn_short:'💬 Chat', push_on:'🔔 Turn on order alerts', push_enabled:'Order alerts are on', push_blocked:'Notifications are blocked in your browser settings', reorder_btn:'Order again', reorder_home:'Hungry again?', reorder_none:'Those items are no longer available', reorder_added:'added to your cart at today\'s prices', reorder_skipped:'unavailable, skipped', reorder_fail:'Could not load that order', cart_view:'View cart →', cart_items_one:'item', cart_items_many:'items', filter_none:'No matches for these filters', filter_clear:'Clear filters',
        greet_morning:'Good morning', greet_afternoon:'Good afternoon', greet_evening:'Good evening', greet_sub:'What would you like today?',
        svc_food:'Food', svc_groceries:'Groceries', svc_pharmacy:'Pharmacy', svc_ride:'Ride', shop_min:'min', shop_from:'From', shop_items:'items', shop_no_rating:'New',
        search_placeholder:'Search restaurants, food, cuisines…',
        quick_ride_title:'ETEL Ride', quick_ride_sub:'From ETB 120',
        quick_hail_title:'Street Hail', quick_hail_sub:'Instant pickup',
        quick_driver_title:'Be a Driver', quick_driver_sub:'Earn with ETEL',
        section_restaurants:'Restaurants & Shops', see_all:'See all →',
        section_items:'💎 Available Items', view_all:'View all →',
        footer_partner:'Become a Partner', footer_courier:'Become a Courier',
        footer_contact:'Contact', footer_privacy:'Privacy',
        orders_title:'📦 My Orders', orders_refresh:'Refresh →',
        scan_qr_btn:"📷 Scan Courier's QR to Confirm Delivery",
        payments_title:'💳 Wallet & Payments',
        cart_title:'My Cart',
        courier_qr_title:"Scan Courier's QR Code", cancel_btn:'Cancel',
        lbl_subtotal:'Subtotal', lbl_delivery_fee:'Delivery Fee', lbl_service_charge:'Service Charge', lbl_total:'Total',
        lbl_tip:'Courier tip', tip_title:'Tip your courier', tip_sub:'100% of your tip goes to the courier.', tip_none:'No tip', tip_custom:'Custom', tip_custom_ph:'Amount in ETB',
        lbl_delivery_address:'Delivery Address *', lbl_recipient_name:'Recipient Name *', lbl_recipient_phone:'Recipient Phone *',
        lbl_notes:'Notes (optional)', lbl_payment_method:'Payment Method', btn_place_order:'Place Order',
        ph_address:'Search address, shop, landmark…', ph_full_name:'Full name', ph_notes:'Special instructions…',
        pay_telebirr_sub:'Recommended · Instant', pay_wallet_sub:'Ethiopian Wallet',
        pay_cash:'Cash', pay_cash_sub:'Pay courier on delivery', pay_bank_sub:'Bank transfer',
        filter_trending:'Trending', filter_top_rated:'Top Rated', filter_fast:'Fast', filter_budget:'Budget', filter_new:'New',
        cat_pizza:'Pizza', cat_burgers:'Burgers', cat_noodles:'Noodles', cat_ethiopian:'Ethiopian', cat_healthy:'Healthy', cat_sushi:'Sushi', cat_coffee:'Coffee', cat_bakery:'Bakery',
        promo_limited:'LIMITED', promo_50off:'50% OFF', promo_first_order:'First Order Premium',
        promo_today:'TODAY', promo_free_delivery:'FREE DELIVERY', promo_orders_above:'Orders above ETB 500',
        promo_weekend:'WEEKEND', promo_2x_points:'2× POINTS', promo_weekend_special:'Weekend Special',
        shops_count:'shops'
    },
    am: {
        nav_home:'ቤት', nav_favorites:'ተወዳጆች', nav_orders:'ትዕዛዞች', nav_messages:'መልዕክቶች', nav_profile:'መገለጫ',
        shop_open:'ክፍት', shop_closed:'ዝግ', shop_fee_from:'ማድረሻ ከ ETB', closed_warn:'አሁን ዝግ ይመስላል። አሁንም ማዘዝ ይችላሉ፤ እስኪከፈት ሊዘገይ ይችላል። ይቀጥሉ?',
        trk_title:'ትዕዛዝዎ', trk_pending:'ትዕዛዝ ተቀብሏል', trk_assigned:'ሹፌር ተመድቧል', trk_picked_up:'ተነስቷል', trk_in_transit:'በመንገድ ላይ', trk_track:'ተከታተል', trk_more:'ተጨማሪ', menu_all:'ሁሉም', menu_popular:'ተወዳጅ', menu_deals:'🏷️ ቅናሽ', menu_quick:'ፈጣን', pop_badge:'🔥 ተወዳጅ', promo_have:'የቅናሽ ኮድ አለዎት?', promo_ph:'ኮድ ያስገቡ', promo_apply:'ተግብር', promo_remove:'አስወግድ', promo_discount:'ቅናሽ', promo_saved:'ይቆጥባሉ', rate_title:'ትዕዛዝዎን ይገምግሙ', rate_overall:'አጠቃላይ', rate_food:'የትዕዛዝ ጥራት', rate_courier:'ሹፌር', rate_tags:'ምን ጎልቶ ታየ?', rate_comment:'ተጨማሪ ይንገሩን (አማራጭ)…', rate_submit:'ግምገማ ላክ', rate_pick:'እባክዎ አጠቃላይ ደረጃ ይምረጡ', rate_thanks:'ለግምገማዎ እናመሰግናለን!', rate_yours:'የእርስዎ ደረጃ', rate_reply:'የሱቁ መልስ', tag_fast_delivery:'⚡ ፈጣን ማድረሻ', tag_great_taste:'😋 ጣፋጭ', tag_well_packed:'📦 በደንብ የታሸገ', tag_friendly_courier:'🙂 ተግባቢ ሹፌር', tag_good_value:'💰 ጥሩ ዋጋ', tag_late:'🐢 ዘግይቷል', tag_cold:'🥶 ቀዝቅዞ ደረሰ', tag_wrong_item:'❌ የተሳሳተ ዕቃ', pts_title:'ነጥቦችዎ', pts_use:'ነጥቤን ተጠቀም', pts_off:'ነጥብ አልተጠቀምኩም', pts_need:'ለመጠቀም ተጨማሪ {n} ነጥቦች ያስፈልጋሉ', pts_applied:'⭐ ነጥቦች', pts_how:'እንዴት ይሰራል', pts_earn:'በደረሰ ትዕዛዝ ለእያንዳንዱ ETB 10 ዕቃ 1 ነጥብ ያገኛሉ — ቅዳሜና እሁድ እጥፍ።', pts_spend:'100 ነጥብ ሲኖርዎት 10 ነጥብ = ETB 1 ቅናሽ።', pts_recent:'የቅርብ ጊዜ እንቅስቃሴ', pts_none:'እስካሁን እንቅስቃሴ የለም — የመጀመሪያ የደረሰ ትዕዛዝዎ ነጥብ ያስገኛል።', pts_worth:'ዋጋ', chat_btn:'💬 ከሱቁ እና ከሹፌሩ ጋር ይወያዩ', chat_btn_short:'💬 ውይይት', push_on:'🔔 የትዕዛዝ ማሳወቂያ አብራ', push_enabled:'የትዕዛዝ ማሳወቂያ በርቷል', push_blocked:'ማሳወቂያዎች በአሳሽ ቅንብር ታግደዋል', reorder_btn:'እንደገና አዝዝ', reorder_home:'እንደገና ይራብዎታል?', reorder_none:'እነዚህ ዕቃዎች አሁን አይገኙም', reorder_added:'በዛሬ ዋጋ ወደ ጋሪዎ ተጨምረዋል', reorder_skipped:'አይገኝም፣ ተዘሏል', reorder_fail:'ትዕዛዙን መጫን አልተቻለም', cart_view:'ጋሪ ይመልከቱ →', cart_items_one:'ዕቃ', cart_items_many:'ዕቃዎች', filter_none:'ለእነዚህ ማጣሪያዎች ውጤት የለም', filter_clear:'ማጣሪያ አጽዳ',
        greet_morning:'እንደምን አደሩ', greet_afternoon:'እንደምን ዋሉ', greet_evening:'እንደምን አመሹ', greet_sub:'ዛሬ ምን ይፈልጋሉ?',
        svc_food:'ምግብ', svc_groceries:'ግሮሰሪ', svc_pharmacy:'ፋርማሲ', svc_ride:'ጉዞ', shop_min:'ደቂቃ', shop_from:'ከ', shop_items:'ዕቃዎች', shop_no_rating:'አዲስ',
        search_placeholder:'ምግብ ቤቶችን፣ ምግብን ይፈልጉ…',
        quick_ride_title:'ኢቴል ጉዞ', quick_ride_sub:'ከ ETB 120 ጀምሮ',
        quick_hail_title:'የመንገድ ጥሪ', quick_hail_sub:'ፈጣን መነሳት',
        quick_driver_title:'ሹፌር ይሁኑ', quick_driver_sub:'ከኢቴል ጋር ያግኙ',
        section_restaurants:'ምግብ ቤቶች እና ሱቆች', see_all:'ሁሉንም ይመልከቱ →',
        section_items:'💎 የሚገኙ እቃዎች', view_all:'ሁሉንም ይመልከቱ →',
        footer_partner:'አጋር ይሁኑ', footer_courier:'ተላላኪ ይሁኑ',
        footer_contact:'ያግኙን', footer_privacy:'ግላዊነት',
        orders_title:'📦 የእኔ ትዕዛዞች', orders_refresh:'አድስ →',
        scan_qr_btn:'📷 ማድረሻን ለማረጋገጥ የተላላኪውን QR ይቃኙ',
        payments_title:'💳 ቦርሳ እና ክፍያዎች',
        cart_title:'የእኔ ጋሪ',
        courier_qr_title:'የተላላኪውን QR ኮድ ይቃኙ', cancel_btn:'ይቅር',
        lbl_subtotal:'ንዑስ ድምር', lbl_delivery_fee:'የማድረስ ክፍያ', lbl_service_charge:'የአገልግሎት ክፍያ', lbl_total:'ጠቅላላ',
        lbl_tip:'የአስረካቢ ጉርሻ', tip_title:'ለአስረካቢው ጉርሻ ይስጡ', tip_sub:'ጉርሻዎ 100% ለአስረካቢው ይሄዳል።', tip_none:'ጉርሻ የለም', tip_custom:'ሌላ መጠን', tip_custom_ph:'መጠን በብር',
        lbl_delivery_address:'የማድረሻ አድራሻ *', lbl_recipient_name:'የተቀባይ ስም *', lbl_recipient_phone:'የተቀባይ ስልክ *',
        lbl_notes:'ማስታወሻ (አማራጭ)', lbl_payment_method:'የክፍያ ዘዴ', btn_place_order:'ትዕዛዝ ያስገቡ',
        ph_address:'አድራሻ፣ ሱቅ፣ ምልክት ይፈልጉ…', ph_full_name:'ሙሉ ስም', ph_notes:'ልዩ መመሪያዎች…',
        pay_telebirr_sub:'የሚመከር · ፈጣን', pay_wallet_sub:'የኢትዮጵያ ቦርሳ',
        pay_cash:'ጥሬ ገንዘብ', pay_cash_sub:'ለተላላኪው በማድረስ ላይ ይክፈሉ', pay_bank_sub:'የባንክ ዝውውር',
        filter_trending:'🔥 ተወዳጅ', filter_top_rated:'ከፍተኛ ደረጃ', filter_fast:'⚡ ፈጣን', filter_budget:'ርካሽ', filter_new:'አዲስ',
        cat_pizza:'ፒዛ', cat_burgers:'በርገር', cat_noodles:'ኑድልስ', cat_ethiopian:'ኢትዮጵያዊ', cat_healthy:'ጤናማ', cat_sushi:'ሱሺ', cat_coffee:'ቡና', cat_bakery:'ዳቦ ቤት',
        promo_limited:'የተወሰነ', promo_50off:'50% ቅናሽ', promo_first_order:'የመጀመሪያ ትዕዛዝ ፕሪሚየም',
        promo_today:'ዛሬ', promo_free_delivery:'ነፃ ማድረስ', promo_orders_above:'ከ ETB 500 በላይ ትዕዛዞች',
        promo_weekend:'ቅዳሜ እሁድ', promo_2x_points:'2× ነጥቦች', promo_weekend_special:'የቅዳሜ እሁድ ልዩ',
        shops_count:'ሱቆች'
    }
});
ETEL_I18N.init();
function toggleCustomerLanguage() {
    const next = ETEL_I18N.toggle();
    // Static [data-i18n] elements are already re-applied by toggle(); dynamic
    // checkout HTML (cart drawer) and the shop-count label are built from JS
    // template strings, not data-i18n attributes, so they only pick up the
    // new language on their next render - force both now if visible/loaded.
    if (cart.length && document.getElementById('cart-overlay') && document.getElementById('cart-overlay').style.display === 'block') {
        renderCartDrawer();
    }
    if (itemsCache.length) { renderSGScroll(itemsCache); renderItemsGrid(itemsCache); }
    if (typeof renderHomeGreeting === 'function') renderHomeGreeting();
    showToast(next === 'am' ? 'ቋንቋ: አማርኛ' : 'Language: English');
}

// ── STATE ───────────────────────────────────────────────────────
let cart = [];       // [{item_id, name, price, quantity, sg_id, sg_name}]
let itemsCache = [];
let _sgOnline = {};        // service_giver_id -> true/false (open) or null (unknown)
let _closedWarned = {};    // shops we already warned about this session

// Etel Pay is a real, fully-built wallet payment integration, but is
// being held back from customers until government approval comes
// through - shown as a disabled "Coming Soon" option everywhere it
// would otherwise appear until an admin flips etelpay_live (see
// AdminController.php's admin_toggle_feature action).
let etelPayLive = false;
async function loadFeatureFlags() {
    try {
        const res = await apiGet('get_feature_flags', {});
        if (res.success) etelPayLive = !!res.data.etelpay_live;
    } catch (e) { /* default to not-live (safer) if this fails to load */ }
}
let currentView = 'home';

// ── HELPERS ─────────────────────────────────────────────────────
function apiGet(action, params) {
    if (!params) params = {};
    const qs = Object.entries(params).map(function(e){ return e[0]+'='+encodeURIComponent(e[1]); }).join('&');
    return fetch(ETEL.api + '?action=' + action + (qs ? '&'+qs : ''), {
        headers: {'Authorization': 'Bearer ' + Auth.getToken()}
    }).then(function(r){ return r.json(); });
}
function fmtDate(ts) {
    if (!ts) return '—';
    const d = new Date(ts);
    return d.toLocaleDateString('en-GB',{day:'2-digit',month:'short'}) + ' ' + d.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'});
}

// ── VIEW SWITCHING ──────────────────────────────────────────────
function switchCategory(cat) {
    currentView = cat;
    const sidebarIcons = ['home','search','orders','favorites','payments'];
    document.querySelectorAll('.sidebar-item').forEach(function(el, i) {
        el.classList.toggle('active', sidebarIcons[i] === cat);
    });
    const navMap = ['home','favorites','orders','messages','profile'];
    document.querySelectorAll('.nav-item').forEach(function(el, i) {
        el.classList.toggle('active', navMap[i] === cat);
    });
    document.getElementById('view-home').style.display     = cat === 'home'     ? 'block' : 'none';
    document.getElementById('view-orders').style.display   = cat === 'orders'   ? 'block' : 'none';
    const viewIds = {payments:'view-payments', favorites:'view-favorites', messages:'view-messages', addresses:'view-addresses', referrals:'view-referrals', settings:'view-settings', notifications:'view-notifications'};
    Object.keys(viewIds).forEach(function(key) {
        const el = document.getElementById(viewIds[key]);
        if (el) el.style.display = cat === key ? 'block' : 'none';
    });
    if (cat === 'orders')   loadOrders();
    else if (cat === 'payments') loadPayments();
    else if (cat === 'favorites') loadFavorites();
    else if (cat === 'messages') loadMessages();
    else if (cat === 'addresses') loadAddresses();
    else if (cat === 'referrals') loadReferralInfo();
    else if (cat === 'settings') loadSettingsView();
    else if (cat === 'notifications') loadNotifications();
    else if (cat === 'home' && !itemsCache.length) loadItems();
    if (cat === 'home') loadHomeTracker();
}

// Light / dark switch (Profile menu). The page used to carry a floating sun/moon button from core.js;
// it covered shop cards, so the toggle lives in Profile now (ETEL_NO_THEME_FAB is set in customer.html).
function customerToggleTheme() {
    var dark = !document.body.classList.contains('dark-theme');
    document.body.classList.toggle('dark-theme', dark);
    try { localStorage.setItem('theme', dark ? 'dark' : 'light'); } catch (e) {}
    if (typeof showToast === 'function') showToast(dark ? 'Dark mode' : 'Light mode');
}

// Refresh (Profile menu; there is no floating button any more)
// re-fetches whichever view is currently open. loadItems() normally
// only runs on 'home' if itemsCache is still empty (avoids a
// redundant fetch on first load) - a manual refresh needs to bypass
// that and force a real re-fetch regardless of cache state.
async function refreshCurrentView() {
    const btn = document.getElementById('cust-refresh-btn');
    if (btn) btn.classList.add('spinning');
    try {
        switch (currentView) {
            case 'orders':     await loadOrders(); break;
            case 'payments':   await loadPayments(); break;
            case 'favorites':  await loadFavorites(); break;
            case 'messages':   await loadMessages(); break;
            case 'addresses':  await loadAddresses(); break;
            case 'referrals':  await loadReferralInfo(); break;
            case 'settings':   await loadSettingsView(); break;
            default:           await loadItems();
        }
        if (typeof showToast === 'function') showToast('Refreshed');
    } catch (e) {
        if (typeof showToast === 'function') showToast('Could not refresh - check your connection', 'err');
    } finally {
        if (btn) setTimeout(() => btn.classList.remove('spinning'), 400);
    }
}

// ── LOAD ITEMS (public endpoint) ────────────────────────────────
async function loadItems(category) {
    document.getElementById('sg-scroll').innerHTML = '<div class="skeleton-shop"></div><div class="skeleton-shop"></div><div class="skeleton-shop"></div>';
    document.getElementById('items-grid').innerHTML = '<div class="skeleton-item"></div><div class="skeleton-item"></div><div class="skeleton-item"></div><div class="skeleton-item"></div>';
    const params = {};
    if (category) params.category = category;
    const res = await apiGet('customer_get_items', params);
    if (!res.success) {
        document.getElementById('items-grid').innerHTML = '<div style="padding:20px;color:#e63946;font-size:13px;grid-column:1/-1">Failed to load items. Check your connection and refresh.</div>';
        return;
    }
    itemsCache = res.data.items;
    renderSGScroll(itemsCache);
    renderItemsGrid(itemsCache);
}

// Shop-card icons (line icons, inherit colour)
const RC_ICO = function (d) { return '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + d + '</svg>'; };
const RC_CLOCK = RC_ICO('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>');
const RC_BIKE  = RC_ICO('<circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17l3-7h5l4 7M9 10l-1-3H6M14 10l-1-3"/>');
const RC_STAR  = '<svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true"><path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.8-5.2 2.8 1-5.8L3.5 9.7l5.9-.9z"/></svg>';
const RC_HEART = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20s-7-4.4-9-9.2C1.8 7.6 3.7 4.5 7 4.5c2 0 3.4 1.1 5 3 1.6-1.9 3-3 5-3 3.3 0 5.2 3.1 4 6.3-2 4.8-9 9.2-9 9.2z"/></svg>';

function renderSGScroll(items) {
    const bySG = {};
    items.forEach(function(item) {
        let sg = bySG[item.service_giver_id];
        if (!sg) {
            sg = bySG[item.service_giver_id] = {id:item.service_giver_id, name:item.business_name||'Shop', zone:item.zone||'', type:item.service_type||'', count:0, img:'', cover:'', logo:'', online:null, minPrice:Infinity, minPrep:Infinity, rating:0};
        }
        sg.count++;
        if (!sg.img && item.image_url) sg.img = item.image_url;          // fallback cover: first real item photo
        if (!sg.cover && item.cover_url) sg.cover = item.cover_url;      // the shop's own cover photo wins
        if (!sg.logo && item.logo_url) sg.logo = item.logo_url;
        if (item.is_online !== undefined && item.is_online !== null) sg.online = (parseInt(item.is_online, 10) === 1);
        const pr = parseFloat(item.price); if (pr > 0 && pr < sg.minPrice) sg.minPrice = pr;
        const pt = parseInt(item.preparation_time, 10); if (pt > 0 && pt < sg.minPrep) sg.minPrep = pt;
        const rt = parseFloat(item.rating); if (rt > sg.rating) sg.rating = rt;
    });
    let sgs = Object.values(bySG);
    // Remember who is open (for the add-to-cart warning) and, when at least one shop is open,
    // list open shops first. If none/unknown are open the original order is kept so a shop that
    // simply never flipped its switch is never buried.
    _sgOnline = {};
    sgs.forEach(function(g){ _sgOnline[g.id] = g.online; });
    if (sgs.some(function(g){ return g.online === true; })) {
        sgs = sgs.map(function(g, i){ return {g: g, i: i}; })
                 .sort(function(a, b){ return ((b.g.online === true) - (a.g.online === true)) || (a.i - b.i); })
                 .map(function(x){ return x.g; });
    }
    const emoji  = {restaurant:'🍽️',grocery:'🛒',pharmacy:'💊',laundry:'👕',delivery:'📦',cafe:'☕',fastfood:'🍔',electronics:'📱',bakery:'🧁',general:'🏪',shop:'🏪',market:'🛍️',food:'🍽️',drink:'🥤',snack:'🍿'};
    const bgMap  = {restaurant:'sg-bg-restaurant',grocery:'sg-bg-grocery',pharmacy:'sg-bg-pharmacy',cafe:'sg-bg-cafe',fastfood:'sg-bg-fastfood',laundry:'sg-bg-laundry',general:'sg-bg-restaurant',default:'sg-bg-default'};
    const sgCount = document.getElementById('sg-count');
    if (sgCount) sgCount.textContent = sgs.length + ' ' + ETEL_I18N.t('shops_count');
    if (!sgs.length) {
        document.getElementById('sg-scroll').innerHTML = '<div style="padding:30px;color:rgba(240,237,216,.4);text-align:center;font-size:13px;font-style:italic">No shops available yet</div>';
        return;
    }
    const minFee = Math.min.apply(null, Object.keys(HAWASSA_FEES).map(function(z){ return HAWASSA_FEES[z].fee; }));
    const tOpen = ETEL_I18N.t('shop_open'), tClosed = ETEL_I18N.t('shop_closed'), tFee = ETEL_I18N.t('shop_fee_from');
    const tMin = ETEL_I18N.t('shop_min'), tFrom = ETEL_I18N.t('shop_from'), tItems = ETEL_I18N.t('shop_items'), tNew = ETEL_I18N.t('shop_no_rating');
    document.getElementById('sg-scroll').innerHTML = sgs.map(function(sg) {
        const ic = emoji[sg.type]||'🏪';
        const bg = bgMap[sg.type]||'sg-bg-default';
        // Honest, data-backed facts only: rating and prep time come from the items API;
        // travel time is a flat +10..20 min estimate on top of the shop's own prep time.
        const eta = isFinite(sg.minPrep) ? '<span class="rc-fact">' + RC_CLOCK + (sg.minPrep + 10) + '–' + (sg.minPrep + 20) + ' ' + escapeHtml(tMin) + '</span>' : '';
        const rate = sg.rating > 0 ? '<span class="rc-rating">' + RC_STAR + sg.rating.toFixed(1) + '</span>' : '<span class="rc-rating rc-rating-new">' + escapeHtml(tNew) + '</span>';
        const coverSrc = sg.cover || sg.img;
        const cover = coverSrc
            ? '<span class="rc-ph">' + ic + '</span><img class="rc-cover" src="' + escUrl(coverSrc) + '" alt="" loading="lazy" onerror="this.onerror=null;this.style.display=\'none\'">'
            : '<span class="rc-ph rc-ph-solo">' + ic + '</span>';
        const from = isFinite(sg.minPrice) ? escapeHtml(tFrom) + ' ETB ' + Math.round(sg.minPrice) + ' · ' : '';
        // Only a closed shop gets a label on the photo; an open shop needs no badge.
        const closedBadge = sg.online === false ? '<span class="rc-closed-badge">' + escapeHtml(tClosed) + '</span>' : '';
        return '<div class="restaurant-card' + (sg.online === false ? ' rc-closed' : '') + '" onclick="openShopDetail('+sg.id+')">' +
            '<div class="restaurant-card-image ' + bg + '">' + cover + '<span class="rc-shade"></span>' + closedBadge +
                '<button class="rc-fav" onclick="event.stopPropagation();toggleFavorite('+sg.id+', this)" aria-label="Favorite">' + RC_HEART + '</button>' +
            '</div>' +
            '<div class="restaurant-card-body' + (sg.logo ? ' has-logo' : '') + '">' +
                (sg.logo ? '<img class="rc-logo" src="' + escUrl(sg.logo) + '" alt="" loading="lazy" onerror="this.parentNode.classList.remove(\'has-logo\');this.remove()">' : '') +
                '<div class="rc-top"><div class="restaurant-card-name">' + escapeHtml(sg.name) + '</div>' + rate + '</div>' +
                '<div class="restaurant-card-meta">' + escapeHtml(sg.type||'Shop') + ' · ' + escapeHtml(sg.zone||'Hawassa') + '</div>' +
                '<div class="rc-facts">' + eta + '<span class="rc-fact">' + RC_BIKE + escapeHtml(tFee) + ' ' + minFee + '</span></div>' +
                '<div class="restaurant-card-info">' + from + sg.count + ' ' + escapeHtml(tItems) + '</div>' +
            '</div></div>';
    }).join('');
}

// ── LIVE ORDER TRACKER (home) ───────────────────────────────────
// Shows the newest in-progress order with a progress bar and a Track button,
// refreshed every 45 s while the home view is open. Uses the same
// customer_get_orders data as the Orders tab; silent if it can't load.
// ── ORDER AGAIN — rebuilds the cart from a past order at TODAY's prices via
// customer_reorder_items (server re-checks price / availability / shop status).
async function reorderOrder(orderId) {
    let res;
    try { res = await apiGet('customer_reorder_items', {order_id: orderId}); } catch (e) { res = null; }
    if (!res || !res.success) { showToast(ETEL_I18N.t('reorder_fail'), 'err'); return; }
    const lines = res.data.items || [];
    const ok = lines.filter(function(l){ return l.orderable && l.service_giver_id; });
    if (!ok.length) { showToast(ETEL_I18N.t('reorder_none'), 'err'); return; }
    const sgId = ok[0].service_giver_id, sgName = ok[0].business_name || '';
    const same = ok.filter(function(l){ return l.service_giver_id === sgId; });
    const skipped = lines.length - same.length;
    if (cart.length && cart[0].sg_id !== sgId) {
        if (!confirm('Your cart has items from "' + cart[0].sg_name + '". Order again from "' + sgName + '" will clear it. Continue?')) return;
    }
    if (ok[0].is_online === false && !_closedWarned[sgId]) {
        if (!confirm('"' + sgName + '" ' + ETEL_I18N.t('closed_warn'))) return;
        _closedWarned[sgId] = true;
    }
    _sgOnline[sgId] = ok[0].is_online;
    cart = same.map(function(l){ return {item_id: l.item_id, name: l.name, price: parseFloat(l.price), quantity: l.quantity, sg_id: sgId, sg_name: sgName}; });
    updateCartCount();
    showToast(same.length + ' ' + ETEL_I18N.t('reorder_added') + (skipped > 0 ? ' · ' + skipped + ' ' + ETEL_I18N.t('reorder_skipped') : ''));
    toggleCart();
}

// ── ORDER ALERTS (Web Push) — the server pushes a notification when a shop starts
// preparing, a courier is assigned, the order is on its way, delivered or cancelled
// (api/lib/order_notify.php). This only needs the browser subscription saved against
// the signed-in account; it is a no-op where push isn't supported or not allowed.
function _etelB64ToU8(b64) {
    const pad = '='.repeat((4 - b64.length % 4) % 4);
    const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    const out = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
}
async function subscribeCustomerPush() {
    try {
        if (!Auth.getToken() || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return false;
        if (Notification.permission !== 'granted') return false;
        const reg = await navigator.serviceWorker.ready;
        let sub = await reg.pushManager.getSubscription();
        if (!sub) {
            const k = await fetch(ETEL.api + '?action=get_vapid_public_key').then(function(r){ return r.json(); });
            if (!k || !k.success || !k.data || !k.data.publicKey) return false;
            sub = await reg.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: _etelB64ToU8(k.data.publicKey)});
        }
        const res = await api('save_push_subscription', {subscription: sub.toJSON()});
        return !!(res && res.success);
    } catch (e) { console.warn('[push] customer subscribe failed', e); return false; }
}
// Called from a tap (needed for the permission prompt): asks if not yet asked, then subscribes.
async function enableOrderPush() {
    if (!('Notification' in window)) return false;
    if (Notification.permission === 'denied') { showToast(ETEL_I18N.t('push_blocked'), 'err'); return false; }
    if (Notification.permission === 'default') { try { await Notification.requestPermission(); } catch (e) {} }
    const ok = await subscribeCustomerPush();
    if (ok) { showToast(ETEL_I18N.t('push_enabled')); loadHomeTracker(); }
    return ok;
}

const _TRK_STEPS = ['pending','assigned','picked_up','in_transit'];
async function loadHomeTracker() {
    const box = document.getElementById('home-order-tracker');
    if (!box) return;
    try {
        if (!Auth.getToken()) return;
        const res = await apiGet('customer_get_orders', {});
        if (!res || !res.success) return;
        const active = (res.data.orders || []).filter(function(o){ return _TRK_STEPS.indexOf(o.status) !== -1; });
        if (!active.length) {
            const last = (res.data.orders || []).find(function(o){ return o.status === 'delivered'; });
            if (!last) { box.style.display = 'none'; box.innerHTML = ''; return; }
            box.innerHTML =
                '<div class="trk-top"><span class="trk-ico">🍽️</span>' +
                '<div class="trk-text"><div class="trk-title">' + escapeHtml(ETEL_I18N.t('reorder_home')) + '</div>' +
                '<div class="trk-status">' + escapeHtml(last.order_code || '') + ' · ETB ' + parseFloat(last.fare_total || 0).toFixed(0) + '</div></div>' +
                '<button type="button" class="trk-btn" onclick="reorderOrder(' + parseInt(last.id, 10) + ')">' + escapeHtml(ETEL_I18N.t('reorder_btn')) + '</button></div>';
            box.style.display = 'block';
            return;
        }
        const o = active[0];
        const step = _TRK_STEPS.indexOf(o.status);
        const pct = Math.round((step + 1) / (_TRK_STEPS.length + 1) * 100);
        const more = active.length > 1 ? ' · +' + (active.length - 1) + ' ' + ETEL_I18N.t('trk_more') : '';
        box.innerHTML =
            '<div class="trk-top"><span class="trk-ico">🛵</span>' +
            '<div class="trk-text"><div class="trk-title">' + escapeHtml(ETEL_I18N.t('trk_title')) + ' · ' + escapeHtml(o.order_code || '') + escapeHtml(more) + '</div>' +
            '<div class="trk-status">' + escapeHtml(ETEL_I18N.t('trk_' + o.status)) + '</div></div>' +
            '<button type="button" class="trk-btn" onclick="openTrackOrder(' + parseInt(o.id, 10) + ')">' + escapeHtml(ETEL_I18N.t('trk_track')) + '</button></div>' +
            '<div class="trk-bar"><span style="width:' + pct + '%"></span></div>' +
            (('Notification' in window && Notification.permission === 'default') ? '<button type="button" class="trk-push" onclick="enableOrderPush()">' + escapeHtml(ETEL_I18N.t('push_on')) + '</button>' : '');
        box.style.display = 'block';
    } catch (e) { /* tracker is decoration - never break the home page */ }
}
setInterval(function() { if (currentView === 'home' && document.visibilityState === 'visible') loadHomeTracker(); }, 45000);

// Greeting line on the home view: time of day + first name (updates on language change).
function renderHomeGreeting() {
    const el = document.getElementById('home-greeting-hi');
    if (!el) return;
    const h = new Date().getHours();
    const key = h < 12 ? 'greet_morning' : (h < 18 ? 'greet_afternoon' : 'greet_evening');
    let first = '';
    try { const u = Auth.getUser(); if (u && u.name) first = String(u.name).trim().split(/\s+/)[0]; } catch (e) {}
    el.textContent = ETEL_I18N.t(key) + (first ? ', ' + first : '') + ' 👋';
}

function filterBySG(sgId) {
    const filtered = itemsCache.filter(function(i){ return i.service_giver_id == sgId; });
    renderItemsGrid(filtered);
    const grid = document.getElementById('items-grid');
    if (grid) grid.scrollIntoView({behavior:'smooth'});
}

// ── SHOP DETAIL (Menu / Info tabs) — real items + real service_giver
// fields already present on each item row (business_name, zone,
// service_type, rating) from customer_get_items; no new endpoint. ──
let _shopDetailSgId = null;
let _shopDetailReviewsLoaded = false;
function openShopDetail(sgId) {
    const items = itemsCache.filter(function(i){ return i.service_giver_id == sgId; });
    if (!items.length) { filterBySG(sgId); return; }
    _shopDetailSgId = sgId;
    _shopDetailReviewsLoaded = false;
    const first = items[0];
    const emoji  = {restaurant:'🍽️',grocery:'🛒',pharmacy:'💊',laundry:'👕',cafe:'☕',fastfood:'🍔',bakery:'🧁',general:'🏪'};
    const ic = emoji[first.service_type] || '🏪';
    document.getElementById('shop-detail-name').textContent = first.business_name || 'Shop';
    const rating = parseFloat(first.rating) || 0;
    document.getElementById('shop-detail-meta').textContent =
        (rating ? '⭐ ' + rating.toFixed(1) + ' · ' : '') + (first.zone || 'Hawassa');
    const heroCover = first.cover_url || '';
    const heroLogo = first.logo_url || '';
    const heroPhoto = heroCover || (items.find(function(i){ return i.image_url; }) || {}).image_url || '';
    document.getElementById('shop-detail-hero').innerHTML = heroPhoto
        ? '<div style="position:absolute;inset:0;background:radial-gradient(circle at 30% 30%,rgba(212,175,55,.18),transparent 65%),var(--dark-2)"></div>' +
          '<span style="font-size:56px;position:relative;opacity:.4">' + ic + '</span>' +
          '<img src="' + escUrl(heroPhoto) + '" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover" onerror="this.onerror=null;this.style.display=\'none\'">' +
          '<span class="rc-shade"></span>' +
          (heroLogo ? '<img class="hero-logo" src="' + escUrl(heroLogo) + '" alt="" onerror="this.remove()">' : '')
        : '<div style="position:absolute;inset:0;background:radial-gradient(circle at 30% 30%,rgba(212,175,55,.18),transparent 65%),var(--dark-2)"></div>' +
          '<span style="font-size:56px;position:relative;filter:drop-shadow(0 4px 14px rgba(212,175,55,.35))">' + ic + '</span>' +
          (heroLogo ? '<img class="hero-logo" src="' + escUrl(heroLogo) + '" alt="" onerror="this.remove()">' : '');
    _shopMenuItems = items; _shopMenuFilter = 'all';
    renderShopMenuChips();
    renderShopMenu();
    document.getElementById('shop-detail-info-panel').innerHTML =
        '<div style="background:var(--glass-2);border:1px solid var(--glass-border);border-radius:16px;padding:18px;display:flex;flex-direction:column;gap:12px">' +
        infoRow('🏷️', 'Type', first.service_type || 'General') +
        infoRow('📍', 'Zone', first.zone || 'Hawassa') +
        infoRow('⭐', 'Rating', rating ? rating.toFixed(1) + ' / 5' : 'No ratings yet') +
        infoRow('📦', 'Menu items', String(items.length)) +
        '</div>';
    switchShopDetailTab('menu');
    document.getElementById('view-shop-detail').style.display = 'block';
}
// ── SHOP MENU FILTER CHIPS (sticky under the tabs): All / Popular / Deals / Quick
// plus one chip per item category when the shop sells more than one kind.
let _shopMenuItems = [], _shopMenuFilter = 'all';
function _shopMenuMatch(i, key) {
    if (key === 'all') return true;
    if (key === 'popular') return parseInt(i.times_ordered, 10) >= 3;
    if (key === 'deals') return i.original_price && parseFloat(i.original_price) > parseFloat(i.price);
    if (key === 'quick') return parseInt(i.preparation_time, 10) > 0 && parseInt(i.preparation_time, 10) <= 20;
    if (key.indexOf('cat:') === 0) return i.category === key.slice(4);
    return true;
}
function renderShopMenuChips() {
    const box = document.getElementById('shop-menu-chips');
    if (!box) return;
    const defs = [['all', ETEL_I18N.t('menu_all')], ['popular', ETEL_I18N.t('menu_popular')], ['deals', ETEL_I18N.t('menu_deals')], ['quick', ETEL_I18N.t('menu_quick')]];
    const cats = [];
    _shopMenuItems.forEach(function(i){ if (i.category && cats.indexOf(i.category) === -1) cats.push(i.category); });
    if (cats.length > 1) cats.forEach(function(c){ defs.push(['cat:' + c, (catEmoji[c] || '🍴') + ' ' + c.charAt(0).toUpperCase() + c.slice(1)]); });
    const shown = defs.filter(function(d){ return d[0] === 'all' || _shopMenuItems.some(function(i){ return _shopMenuMatch(i, d[0]); }); });
    box.style.display = shown.length > 1 ? 'flex' : 'none';
    box.innerHTML = shown.map(function(d) {
        return '<button type="button" class="shop-chip' + (d[0] === _shopMenuFilter ? ' active' : '') + '" onclick="setShopMenuFilter(\'' + d[0] + '\')">' + escapeHtml(d[1]) + '</button>';
    }).join('');
}
function renderShopMenu() {
    const list = _shopMenuItems.filter(function(i){ return _shopMenuMatch(i, _shopMenuFilter); });
    renderItemsGrid(list, 'shop-detail-menu-panel');
}
function setShopMenuFilter(key) { _shopMenuFilter = key; renderShopMenuChips(); renderShopMenu(); }
function infoRow(icon, label, value) {
    return '<div style="display:flex;justify-content:space-between;align-items:center;font-size:13px">' +
        '<span style="color:var(--text-muted)">' + icon + ' ' + label + '</span>' +
        '<span style="color:var(--text);font-weight:700">' + escapeHtml(value) + '</span></div>';
}
function switchShopDetailTab(tab) {
    document.getElementById('shop-tab-menu').classList.toggle('active', tab === 'menu');
    document.getElementById('shop-tab-reviews').classList.toggle('active', tab === 'reviews');
    document.getElementById('shop-tab-info').classList.toggle('active', tab === 'info');
    const _chips = document.getElementById('shop-menu-chips');
    if (_chips) _chips.style.visibility = tab === 'menu' ? 'visible' : 'hidden', _chips.style.height = tab === 'menu' ? '' : '0';
    document.getElementById('shop-detail-menu-panel').style.display = tab === 'menu' ? 'grid' : 'none';
    document.getElementById('shop-detail-reviews-panel').style.display = tab === 'reviews' ? 'block' : 'none';
    document.getElementById('shop-detail-info-panel').style.display = tab === 'info' ? 'block' : 'none';
    if (tab === 'reviews' && !_shopDetailReviewsLoaded) loadShopReviews(_shopDetailSgId);
}
function closeShopDetail() { document.getElementById('view-shop-detail').style.display = 'none'; }

// ── SHOP REVIEWS — real get_shop_reviews action (added alongside this
// feature) scoped to orders actually assigned to this service_giver_id.
async function loadShopReviews(sgId) {
    const el = document.getElementById('shop-detail-reviews-panel');
    if (!el || !sgId) return;
    el.innerHTML = UI.loading('Loading reviews...');
    const res = await apiGet('get_shop_reviews', {service_giver_id: sgId, limit: 20});
    _shopDetailReviewsLoaded = true;
    if (!res || !res.success) {
        el.innerHTML = '<div style="padding:20px;text-align:center;color:rgba(247,241,223,.5);font-size:13px">Could not load reviews.</div>';
        return;
    }
    const reviews = res.data.reviews || [];
    const avg = res.data.average || 0;
    const total = res.data.total || 0;
    let html = '<div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;padding:14px;background:var(--glass-2);border:1px solid var(--glass-border);border-radius:14px">' +
        '<div style="font-size:28px;font-weight:800;color:#F0CA58">' + (avg ? avg.toFixed(1) : '—') + '</div>' +
        '<div><div style="color:#F0CA58;font-size:13px">' + '★'.repeat(Math.round(avg)) + '☆'.repeat(5 - Math.round(avg)) + '</div>' +
        '<div style="font-size:11px;color:rgba(247,241,223,.5);margin-top:2px">' + total + (total === 1 ? ' review' : ' reviews') + '</div></div></div>';
    if (!reviews.length) {
        html += '<div style="padding:30px 10px;text-align:center;color:rgba(247,241,223,.4);font-size:13px">No reviews yet for this shop.</div>';
    } else {
        html += reviews.map(function(r) {
            return '<div style="padding:14px;margin-bottom:10px;background:rgba(255,255,255,.03);border:1px solid rgba(212,175,55,.12);border-radius:12px">' +
                '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">' +
                '<span style="color:#F0CA58;font-size:13px">' + '★'.repeat(r.stars) + '☆'.repeat(5 - r.stars) + '</span>' +
                '<span style="font-size:10.5px;color:rgba(247,241,223,.4)">' + fmtDate(r.rated_at) + '</span></div>' +
                (r.tags ? '<div style="display:flex;flex-wrap:wrap;gap:5px;margin-bottom:6px">' + String(r.tags).split(',').map(function(k){ return '<span style="padding:3px 8px;border-radius:999px;font-size:10.5px;font-weight:700;background:rgba(212,175,55,.14);color:var(--text)">' + escapeHtml(ETEL_I18N.t('tag_' + k)) + '</span>'; }).join('') + '</div>' : '') +
                (r.comment ? '<div style="font-size:12.5px;color:rgba(247,241,223,.75);margin-bottom:4px">' + escapeHtml(r.comment) + '</div>' : '') +
                '<div style="font-size:11px;color:rgba(247,241,223,.4)">— ' + escapeHtml(r.reviewer_first_name || 'Customer') + '</div>' +
                (r.shop_reply ? '<div style="margin-top:9px;padding:9px 11px;border-left:3px solid #D4AF37;background:rgba(212,175,55,.08);border-radius:0 8px 8px 0;font-size:12.5px"><div style="font-size:10.5px;font-weight:800;opacity:.7;margin-bottom:3px">' + escapeHtml(ETEL_I18N.t('rate_reply')) + '</div>' + escapeHtml(r.shop_reply) + '</div>' : '') + '</div>';
        }).join('');
    }
    el.innerHTML = html;
}

// ── CATEGORY STOREFRONT (Groceries / Pharmacy) — real customer_get_items
// call filtered by the real item.category column. ──
let _categoryItems = [];
async function openCategoryView(cat, label, icon) {
    document.getElementById('category-title').textContent = label;
    document.getElementById('category-icon').textContent = icon;
    document.getElementById('category-search').value = '';
    document.getElementById('category-items-grid').innerHTML = UI.loading('Loading ' + label + '…');
    document.getElementById('view-category').style.display = 'block';
    const res = await apiGet('customer_get_items', {category: cat});
    _categoryItems = (res && res.success) ? res.data.items : [];
    if (!res || !res.success) {
        document.getElementById('category-items-grid').innerHTML =
            '<div style="padding:30px;color:rgba(247,241,223,.55);text-align:center;font-size:13px;grid-column:1/-1">Could not load ' + label + '. Check your connection.</div>';
        return;
    }
    renderItemsGrid(_categoryItems, 'category-items-grid');
}
function filterCategoryView(q) {
    q = (q || '').toLowerCase();
    const filtered = !q ? _categoryItems : _categoryItems.filter(function(i) {
        return (i.name || '').toLowerCase().indexOf(q) !== -1 || (i.business_name || '').toLowerCase().indexOf(q) !== -1;
    });
    renderItemsGrid(filtered, 'category-items-grid');
}
function closeCategoryView() { document.getElementById('view-category').style.display = 'none'; }

// ── TRACK ORDER — real track_order polling + a real Google Map using
// couriers.last_lat/last_lng (written by courier_update_location) and
// orders.delivery_lat/delivery_lng (written at checkout). Both columns
// already existed and were already populated; track_order just never
// selected/exposed them until this change. ──
let _trackOrderId = null;
let _trackPollTimer = null;
let _trackGMap = null, _trackDestMarker = null, _trackCourierMarker = null;

async function openTrackOrder(orderId) {
    _trackOrderId = orderId;
    document.getElementById('view-track-order').style.display = 'block';
    document.getElementById('track-map').innerHTML = 'Loading map…';
    await refreshTrackOrder();
    if (_trackPollTimer) clearInterval(_trackPollTimer);
    _trackPollTimer = setInterval(refreshTrackOrder, 8000);
}
function closeTrackOrder() {
    document.getElementById('view-track-order').style.display = 'none';
    if (_trackPollTimer) { clearInterval(_trackPollTimer); _trackPollTimer = null; }
    _trackOrderId = null; _trackGMap = null; _trackDestMarker = null; _trackCourierMarker = null;
}
async function refreshTrackOrder() {
    if (!_trackOrderId) return;
    const res = await apiGet('track_order', {order_id: _trackOrderId});
    if (!res || !res.success) return;
    const o = res.data.order, timeline = res.data.timeline || [];
    document.getElementById('track-order-code').textContent = o.order_code || ('Order #' + _trackOrderId);
    document.getElementById('track-order-status').textContent = (o.status || '').replace('_', ' ');
    document.getElementById('track-timeline').innerHTML =
        '<div style="background:var(--glass-2);border:1px solid var(--glass-border);border-radius:16px;padding:16px">' +
        timeline.map(function(t, i) {
            const color = t.done ? '#D4AF37' : 'var(--glass-border)';
            return '<div style="display:flex;align-items:center;gap:12px' + (i < timeline.length - 1 ? ';margin-bottom:14px' : '') + '">' +
                '<div style="width:12px;height:12px;border-radius:50%;background:' + color + ';flex-shrink:0;box-shadow:' + (t.current ? '0 0 10px rgba(212,175,55,.6)' : 'none') + '"></div>' +
                '<span style="font-size:13px;color:' + (t.done ? 'var(--text)' : 'var(--text-dim)') + ';font-weight:' + (t.current ? '700' : '500') + '">' + escapeHtml(t.label) + '</span></div>';
        }).join('') + '</div>';
    document.getElementById('track-courier-card').innerHTML = o.courier_name
        ? '<div style="display:flex;align-items:center;gap:12px;padding:14px;background:var(--glass-2);border:1px solid var(--glass-border);border-radius:14px;margin-top:14px">' +
          '<div style="width:44px;height:44px;border-radius:50%;background:rgba(212,175,55,.15);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0">🛵</div>' +
          '<div style="flex:1;min-width:0"><div style="font-size:13px;font-weight:700;color:var(--text)">' + escapeHtml(o.courier_name) + '</div>' +
          '<div style="font-size:11px;color:var(--text-muted)">' + escapeHtml(o.courier_vehicle || 'Courier') + (parseFloat(o.courier_avg_rating) > 0 ? ' · ⭐ ' + parseFloat(o.courier_avg_rating).toFixed(1) : '') + '</div></div>' +
          (o.courier_phone ? '<a href="tel:' + escapeHtml(o.courier_phone) + '" style="width:38px;height:38px;border-radius:50%;background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.3);display:flex;align-items:center;justify-content:center;font-size:16px;text-decoration:none;flex-shrink:0">📞</a>' : '') +
          '</div>'
        : '';
    renderTrackEta(o);
    renderTrackChat(o);
    updateTrackMap(o);
}
// Chat button on the tracking screen (the chat itself is js/order-chat.js).
// Open for active orders, and for a while after delivery; closed once cancelled.
function renderTrackChat(o) {
    const row = document.getElementById('track-chat-row');
    if (!row) return;
    if (!window.EtelOrderChat || o.status === 'cancelled') { row.innerHTML = ''; return; }
    if (!row.firstChild) {
        row.innerHTML = '<button type="button" class="trk-chat" onclick="EtelOrderChat.open(' + parseInt(_trackOrderId, 10) + ')">' + escapeHtml(ETEL_I18N.t('chat_btn')) + ' <span class="trk-chat-badge" style="display:none"></span></button>';
    }
    EtelOrderChat.unread(parseInt(_trackOrderId, 10)).then(function(n) {
        const b = row.querySelector('.trk-chat-badge');
        if (b) { b.textContent = n; b.style.display = n > 0 ? 'inline-flex' : 'none'; }
    });
}
// Honest ETA: only when the courier is on the road AND both coordinates are known.
// Straight-line distance at ~20 km/h city pace (+2 min handover), clearly shown as approximate.
function renderTrackEta(o) {
    const el = document.getElementById('track-eta');
    if (!el) return;
    const st = o.status || '';
    if (st === 'delivered') { el.innerHTML = '<div class="trk-eta-main">✅ Delivered</div>'; return; }
    if (st === 'cancelled') { el.innerHTML = '<div class="trk-eta-main">Order cancelled</div>'; return; }
    const onRoad = (st === 'picked_up' || st === 'in_transit');
    if (onRoad && o.courier_lat && o.courier_lng && o.delivery_lat && o.delivery_lng) {
        const R = 6371, rad = Math.PI / 180;
        const dLat = (o.delivery_lat - o.courier_lat) * rad, dLng = (o.delivery_lng - o.courier_lng) * rad;
        const h = Math.sin(dLat/2)*Math.sin(dLat/2) + Math.cos(o.courier_lat*rad)*Math.cos(o.delivery_lat*rad)*Math.sin(dLng/2)*Math.sin(dLng/2);
        const km = 2 * R * Math.asin(Math.min(1, Math.sqrt(h)));
        const mins = Math.max(1, Math.round(km / 20 * 60) + 2);
        el.innerHTML = '<div class="trk-eta-main">🛵 Arriving in about ' + mins + ' min</div><div class="trk-eta-sub">' + km.toFixed(1) + ' km away · approximate</div>';
    } else if (onRoad) {
        el.innerHTML = '<div class="trk-eta-main">🛵 On the way to you</div>';
    } else {
        const label = {pending:'Waiting for the shop to confirm', assigned:'Shop is confirming your order', preparing:'Your order is being prepared', accepted:'Ready — waiting for the courier'}[st] || 'Processing your order';
        el.innerHTML = '<div class="trk-eta-main">' + label + '</div>';
    }
}
function updateTrackMap(o) {
    const mapEl = document.getElementById('track-map');
    if (!mapEl) return;
    if (!(window.google && window.google.maps)) { mapEl.textContent = 'Map unavailable (still loading Google Maps)'; return; }
    const hasDest = o.delivery_lat && o.delivery_lng;
    const hasCourier = o.courier_lat && o.courier_lng;
    if (!hasDest && !hasCourier) { mapEl.textContent = 'Location not available for this order yet.'; return; }
    if (!_trackGMap) {
        mapEl.textContent = '';
        _trackGMap = new google.maps.Map(mapEl, {
            zoom: 14,
            center: hasDest ? {lat: +o.delivery_lat, lng: +o.delivery_lng} : {lat: +o.courier_lat, lng: +o.courier_lng},
            disableDefaultUI: true, zoomControl: true,
            styles: document.body.classList.contains('dark-theme') ? [{elementType: 'geometry', stylers: [{color: '#15151B'}]}, {elementType: 'labels.text.fill', stylers: [{color: '#9D998D'}]}, {elementType: 'labels.text.stroke', stylers: [{color: '#07070A'}]}] : []
        });
    }
    const bounds = new google.maps.LatLngBounds();
    if (hasDest) {
        const pos = {lat: +o.delivery_lat, lng: +o.delivery_lng};
        if (!_trackDestMarker) _trackDestMarker = new google.maps.Marker({map: _trackGMap, position: pos, label: {text: '🏠', fontSize: '18px'}});
        else _trackDestMarker.setPosition(pos);
        bounds.extend(pos);
    }
    if (hasCourier) {
        const pos = {lat: +o.courier_lat, lng: +o.courier_lng};
        if (!_trackCourierMarker) _trackCourierMarker = new google.maps.Marker({map: _trackGMap, position: pos, label: {text: '🛵', fontSize: '18px'}});
        else _trackCourierMarker.setPosition(pos);
        bounds.extend(pos);
    }
    if (hasDest && hasCourier) _trackGMap.fitBounds(bounds, 40);
}

// ── FILTER PILLS (Trending / Top Rated / Fast / Budget / New) ──────
// These were previously pure decoration with no click handler at all.
// Trending = default order already returned by the API (rating DESC).
// The rest are simple client-side sorts/filters over the same
// itemsCache the grid already has - no new API call needed.
// Sort pills and cuisine chips now combine: pick "Fast" AND "Coffee" and you get
// fast coffee. Tapping the active cuisine chip again clears it.
const _flt = {sort: 'trending', cuisine: null};
function applySortFilter(type, btn) {
    _flt.sort = type;
    document.querySelectorAll('.filter-pill').forEach(function(b){ b.classList.remove('active'); });
    if (btn) btn.classList.add('active');
    applyFilters();
}
function applyFilters() {
    let list = itemsCache.slice(); // copy - never mutate the cache itself
    if (_flt.cuisine) {
        const keywords = CUISINE_KEYWORDS[_flt.cuisine] || [];
        list = list.filter(function(item) {
            const hay = ((item.name||'') + ' ' + (item.name_am||'') + ' ' + (item.category||'')).toLowerCase();
            return keywords.some(function(k){ return hay.indexOf(k) !== -1; });
        });
    }
    const type = _flt.sort;
    if (type === 'top_rated') {
        list.sort(function(a,b){ return (parseFloat(b.rating)||0) - (parseFloat(a.rating)||0); });
    } else if (type === 'fast') {
        list.sort(function(a,b){ return (parseInt(a.preparation_time)||99) - (parseInt(b.preparation_time)||99); });
    } else if (type === 'budget') {
        list.sort(function(a,b){ return parseFloat(a.price) - parseFloat(b.price); });
    } else if (type === 'new') {
        list.sort(function(a,b){ return b.id - a.id; });
    }
    // 'trending' (default) - leave in the API's own order, already rating-first.
    if (!list.length && itemsCache.length) {
        const empty = '<div class="filter-empty">' + escapeHtml(ETEL_I18N.t('filter_none')) +
            ' <button type="button" onclick="clearFilters()">' + escapeHtml(ETEL_I18N.t('filter_clear')) + '</button></div>';
        document.getElementById('items-grid').innerHTML = empty;
        document.getElementById('sg-scroll').innerHTML = '';
        const c = document.getElementById('sg-count'); if (c) c.textContent = '';
        return;
    }
    renderItemsGrid(list);
    renderSGScroll(list);
}
function clearFilters() {
    _flt.sort = 'trending'; _flt.cuisine = null;
    document.querySelectorAll('.filter-pill').forEach(function(b, i){ b.classList.toggle('active', i === 0); });
    document.querySelectorAll('.cat-chip').forEach(function(b){ b.classList.remove('active'); });
    applyFilters();
}

// ── CATEGORY CHIPS (Pizza / Burgers / Noodles / etc.) ───────────────
// There's no dedicated "cuisine" field in the data model (items only
// have category: food/grocery/package/pharmacy/flower) - these chips
// are cuisine-style labels from the original template, so filtering by
// keyword match against the item's own name/category text is the most
// honest thing to do with the data that actually exists, rather than
// pretending there's a real cuisine taxonomy behind them.
const CUISINE_KEYWORDS = {
    pizza: ['pizza'], burgers: ['burger'], noodles: ['noodle','pasta','spaghetti'],
    ethiopian: ['injera','wot','tibs','kitfo','firfir','shiro','doro','ayib','beyaynet'],
    healthy: ['salad','avocado','juice','healthy'], sushi: ['sushi'],
    coffee: ['coffee','buna','macchiato','latte'], bakery: ['bread','bakery','croissant','muffin','cake','pastry'],
};
function applyCuisineFilter(key, btn) {
    const wasActive = btn && btn.classList.contains('active');
    document.querySelectorAll('.cat-chip').forEach(function(b){ b.classList.remove('active'); });
    if (wasActive) { _flt.cuisine = null; }
    else { _flt.cuisine = key; if (btn) btn.classList.add('active'); }
    applyFilters();
}

const catEmoji = {food:'🍽️',grocery:'🛒',pharmacy:'💊',laundry:'👕',delivery:'📦'};
function renderItemsGrid(items, targetId) {
    const grid = document.getElementById(targetId || 'items-grid');
    if (!grid) return;
    if (!items.length) {
        grid.innerHTML = '<div style="padding:30px;color:rgba(247,241,223,.55);text-align:center;font-size:13px;grid-column:1/-1">No items found</div>';
        return;
    }
    const catIcons = {food:'🍽️',grocery:'🛒',pharmacy:'💊',laundry:'👕',delivery:'📦',beverage:'🥤',bakery:'🧁',snack:'🍿',meat:'🥩',default:'🍴'};
    grid.innerHTML = items.map(function(item) {
        const discounted = item.original_price && parseFloat(item.original_price) > parseFloat(item.price);
        const ic = catIcons[item.category] || catEmoji[item.category] || catIcons.default;
        const imgHtml = item.image_url
            ? '<span style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:38px;opacity:.4">' + ic + '</span>'
              + '<img loading="lazy" decoding="async" src="' + escUrl(item.image_url) + '" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover" onerror="this.onerror=null;this.style.display=\'none\'">'
            : '<span style="font-size:52px;filter:drop-shadow(0 4px 12px rgba(212,175,55,.3))">' + ic + '</span>';
        const displayName = (ETEL_I18N.current() === 'am' && item.name_am) ? item.name_am : (item.name || '');
        // Stripped (not just apostrophe-escaped): this string ends up both
        // inside a JS string literal AND inside an HTML onclick="..."
        // attribute below, so a quote or angle bracket in an item/shop name
        // could otherwise break out of either context.
        const safeN = displayName.replace(/[<>"'\\]/g, '');
        const safeB = (item.business_name||'').replace(/[<>"'\\]/g, '');
        return '<div class="offer-card" onclick="openItemDetail(' + item.id + ')" style="cursor:pointer">' +
            '<div class="offer-card-image" style="position:relative">' + imgHtml + '</div>' +
            (discounted ? '<div style="position:absolute;top:10px;left:10px;background:#EF4444;color:#fff;font-size:10px;font-weight:700;padding:2px 7px;border-radius:6px">SALE</div>' : '') +
            (parseInt(item.times_ordered, 10) >= 3 ? '<div class="pop-badge">' + escapeHtml(ETEL_I18N.t('pop_badge')) + '</div>' : '') +
            '<div class="offer-card-body">' +
                '<div class="offer-card-name">' + escapeHtml(displayName) + ((item.quality === 'special' || item.quality === 'premium') ? ' <span style="font-size:10px;font-weight:800;text-transform:uppercase;color:#D4AF37;letter-spacing:.04em">' + (item.quality === 'premium' ? '★ Premium' : 'Special') + '</span>' : '') + '</div>' +
                '<div class="offer-card-shop">' + escapeHtml(item.business_name||'') + (item.portion ? ' · ' + escapeHtml(item.portion) : '') + (item.preparation_time ? ' · ⏱ ' + parseInt(item.preparation_time, 10) + ' min' : '') + '</div>' +
                '<div class="offer-card-footer">' +
                    '<div class="offer-card-price">ETB ' + parseFloat(item.price).toFixed(0) + '</div>' +
                    '<button class="add-btn" onclick="event.stopPropagation();addToCart('+item.id+',\''+safeN+'\','+item.price+','+item.service_giver_id+',\''+safeB+'\')">+</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    }).join('');
}

// ── CART ────────────────────────────────────────────────────────
function addToCart(itemId, name, price, sgId, sgName) {
    if (_sgOnline[sgId] === false && !_closedWarned[sgId]) {
        if (!confirm('"' + sgName + '" ' + ETEL_I18N.t('closed_warn'))) return;
        _closedWarned[sgId] = true;
    }
    if (cart.length && cart[0].sg_id !== sgId) {
        if (!confirm('Your cart has items from "' + cart[0].sg_name + '". Adding from "' + sgName + '" will clear it. Continue?')) return;
        cart = [];
    }
    const existing = cart.find(function(c){ return c.item_id === itemId; });
    if (existing) { existing.quantity++; }
    else { cart.push({item_id:itemId, name:name, price:parseFloat(price), quantity:1, sg_id:sgId, sg_name:sgName}); }
    updateCartCount();
    showToast('Added: ' + name);
}

// Same cart logic as addToCart, but adds a specific quantity in one go
// with a single toast - used by the item detail modal's quantity
// stepper so choosing "3" doesn't fire three separate "Added" toasts.
function addToCartWithQty(itemId, name, price, sgId, sgName, qty) {
    if (_sgOnline[sgId] === false && !_closedWarned[sgId]) {
        if (!confirm('"' + sgName + '" ' + ETEL_I18N.t('closed_warn'))) return;
        _closedWarned[sgId] = true;
    }
    qty = Math.max(1, parseInt(qty, 10) || 1);
    if (cart.length && cart[0].sg_id !== sgId) {
        if (!confirm('Your cart has items from "' + cart[0].sg_name + '". Adding from "' + sgName + '" will clear it. Continue?')) return;
        cart = [];
    }
    const existing = cart.find(function(c){ return c.item_id === itemId; });
    if (existing) { existing.quantity += qty; }
    else { cart.push({item_id:itemId, name:name, price:parseFloat(price), quantity:qty, sg_id:sgId, sg_name:sgName}); }
    updateCartCount();
    showToast('Added ' + qty + '× ' + name);
}

// ── ITEM DETAIL MODAL ──────────────────────────────────────────
let _detailQty = 1;
function openItemDetail(itemId) {
    const item = itemsCache.find(function(i){ return i.id === itemId; });
    if (!item) return;
    _detailQty = 1;

    const displayName = (ETEL_I18N.current() === 'am' && item.name_am) ? item.name_am : (item.name || '');
    const displayDesc = (ETEL_I18N.current() === 'am' && item.description_am) ? item.description_am : (item.description || '');
    const discounted = item.original_price && parseFloat(item.original_price) > parseFloat(item.price);
    const ic = (catEmoji[item.category]) || '🍴';
    const imgHtml = item.image_url
        ? '<img loading="lazy" decoding="async" src="' + escUrl(item.image_url) + '" alt="" style="width:100%;height:100%;object-fit:cover" onerror="this.onerror=null;this.style.display=\'none\'">'
        : '<span style="font-size:80px">' + ic + '</span>';
    const stock = parseInt(item.stock_quantity, 10);
    const available = item.is_available == 1 && (isNaN(stock) || stock > 0);
    const safeN = displayName.replace(/[<>"'\\]/g, '');
    const safeB = (item.business_name || '').replace(/[<>"'\\]/g, '');

    let modal = document.getElementById('item-detail-overlay');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'item-detail-overlay';
        modal.style.cssText = 'display:none;position:fixed;inset:0;z-index:9000;background:rgba(6,6,8,.82);backdrop-filter:blur(4px);align-items:flex-end;justify-content:center;';
        modal.onclick = function(e) { if (e.target === modal) closeItemDetail(); };
        document.body.appendChild(modal);
    }

    modal.innerHTML =
        '<div style="background:var(--dark-2);width:100%;max-width:480px;max-height:88vh;overflow-y:auto;' +
             'border-radius:20px 20px 0 0;border:1px solid var(--glass-border);position:relative;">' +
            '<button onclick="closeItemDetail()" style="position:absolute;top:14px;right:14px;z-index:2;' +
                'background:rgba(6,6,8,.6);color:var(--text);border:none;width:32px;height:32px;border-radius:50%;' +
                'font-size:16px;cursor:pointer;">✕</button>' +
            '<div style="width:100%;height:220px;background:var(--dark-3);display:flex;align-items:center;justify-content:center;' +
                 'border-radius:20px 20px 0 0;overflow:hidden;">' + imgHtml + '</div>' +
            (discounted ? '<div style="position:absolute;top:14px;left:14px;background:var(--red);color:#fff;' +
                'font-size:11px;font-weight:700;padding:3px 9px;border-radius:6px;">SALE</div>' : '') +
            '<div style="padding:20px;">' +
                '<div style="font-family:var(--f-d,serif);font-size:22px;color:var(--text);margin-bottom:4px;">' + escapeHtml(displayName) + '</div>' +
                '<div style="color:var(--text-muted);font-size:13px;margin-bottom:10px;">' + escapeHtml(item.business_name || '') +
                    (item.zone ? ' · ' + item.zone : '') +
                    (item.rating > 0 ? ' · ⭐ ' + parseFloat(item.rating).toFixed(1) : '') +
                '</div>' +
                '<div style="display:flex;align-items:baseline;gap:10px;margin-bottom:14px;">' +
                    '<span style="font-size:24px;font-weight:700;color:var(--gold);">ETB ' + parseFloat(item.price).toFixed(0) + '</span>' +
                    (discounted ? '<span style="font-size:15px;color:var(--text-dim);text-decoration:line-through;">ETB ' + parseFloat(item.original_price).toFixed(0) + '</span>' : '') +
                '</div>' +
                (displayDesc ? '<div style="color:var(--text-muted);font-size:13.5px;line-height:1.6;margin-bottom:16px;">' + escapeHtml(displayDesc) + '</div>'
                             : '<div style="color:var(--text-dim);font-size:12.5px;font-style:italic;margin-bottom:16px;">No description provided yet.</div>') +
                '<div style="display:flex;gap:16px;margin-bottom:20px;font-size:12px;color:var(--text-muted);">' +
                    (item.preparation_time ? '<div>⏱ ~' + item.preparation_time + ' min</div>' : '') +
                    (!available ? '<div style="color:var(--red);font-weight:600;">Currently unavailable</div>' : '') +
                '</div>' +
                (available ?
                    '<div style="display:flex;align-items:center;gap:14px;margin-bottom:16px;">' +
                        '<button onclick="_detailQtyChange(-1)" style="width:36px;height:36px;border-radius:50%;border:1px solid var(--glass-border);' +
                            'background:var(--glass);color:var(--text);font-size:18px;cursor:pointer;">−</button>' +
                        '<span id="detail-qty" style="font-size:16px;font-weight:600;color:var(--text);min-width:20px;text-align:center;">1</span>' +
                        '<button onclick="_detailQtyChange(1)" style="width:36px;height:36px;border-radius:50%;border:1px solid var(--glass-border);' +
                            'background:var(--glass);color:var(--text);font-size:18px;cursor:pointer;">+</button>' +
                    '</div>' +
                    '<button onclick="addToCartWithQty(' + item.id + ',\'' + safeN + '\',' + item.price + ',' + item.service_giver_id + ',\'' + safeB + '\',_detailQty);closeItemDetail();" ' +
                        'style="width:100%;padding:14px;border:none;border-radius:12px;background:var(--gold-grad);' +
                        'color:var(--dark);font-weight:700;font-size:15px;cursor:pointer;">Add to Cart</button>'
                    : '') +
            '</div>' +
        '</div>';

    modal.style.display = 'flex';
}
function _detailQtyChange(delta) {
    _detailQty = Math.max(1, _detailQty + delta);
    const el = document.getElementById('detail-qty');
    if (el) el.textContent = _detailQty;
}
function closeItemDetail() {
    const modal = document.getElementById('item-detail-overlay');
    if (modal) modal.style.display = 'none';
}
function updateCartCount() {
    const n = cart.reduce(function(s,c){ return s+c.quantity; }, 0);
    document.getElementById('cart-count').textContent = n;
    // Floating cart bar: visible only while the cart has something in it.
    const bar = document.getElementById('cart-bar');
    if (bar) {
        const total = cart.reduce(function(s,c){ return s + c.price*c.quantity; }, 0);
        document.getElementById('cart-bar-count').textContent = n;
        document.getElementById('cart-bar-items').textContent = n + ' ' + ETEL_I18N.t(n === 1 ? 'cart_items_one' : 'cart_items_many');
        document.getElementById('cart-bar-total').textContent = 'ETB ' + Math.round(total);
        bar.hidden = n === 0;
        document.body.classList.toggle('has-cartbar', n > 0);
        if (n > 0) { bar.classList.remove('bump'); void bar.offsetWidth; bar.classList.add('bump'); }
    }
}
function toggleCart() {
    if (!cart.length) { showToast('Cart is empty — add some items first', 'err'); return; }
    renderCartDrawer();
    loadLoyalty().then(function(d){
        // Re-render only if nothing has been typed yet (a re-render would blank the form fields).
        const typed = ['co-name','co-phone','co-notes'].some(function(i){ const e = document.getElementById(i); return e && e.value; });
        if (d && !typed && document.getElementById('cart-overlay').style.display === 'block') renderCartDrawer();
    });
    document.getElementById('cart-overlay').style.display = 'block';
    loadDeliveryTiers();
    loadBankAccounts();
}
function closeCart() { document.getElementById('cart-overlay').style.display = 'none'; }

// ── HAWASSA ZONE DELIVERY FEES ─────────────────────────────────
const HAWASSA_FEES = {
    'Mehal Ketema':          {fee:50,  time:20},
    'Tabor':                 {fee:50,  time:20},
    'Addis Ketema':          {fee:60,  time:25},
    'Haik Dar':              {fee:70,  time:30},
    'Tulu Dimtu':            {fee:70,  time:30},
    'Misrak':                {fee:65,  time:28},
    'Referral':              {fee:55,  time:22},
    'Hawela Teklehaimanot':  {fee:75,  time:35},
    'Dore Bafana':           {fee:80,  time:35},
    'Stadium':               {fee:45,  time:15},
    'Liqe Mender':           {fee:50,  time:18},
    'University':            {fee:60,  time:25},
};
const SERVICE_CHARGE_PCT = 5;
const HAWASSA_ZONE_COORDS = {
    'Stadium':               { lat: 7.0606, lng: 38.4775 },
    'Mehal Ketema':          { lat: 7.0559, lng: 38.4762 },
    'Tabor':                 { lat: 7.0400, lng: 38.4900 },
    'Liqe Mender':           { lat: 7.0700, lng: 38.4800 },
    'Referral':              { lat: 7.0490, lng: 38.4680 },
    'Addis Ketema':          { lat: 7.0450, lng: 38.4750 },
    'University':            { lat: 7.0620, lng: 38.5110 },
    'Misrak':                { lat: 7.0800, lng: 38.4850 },
    'Haik Dar':              { lat: 7.0350, lng: 38.5000 },
    'Tulu Dimtu':            { lat: 7.1000, lng: 38.5000 },
    'Hawela Teklehaimanot':  { lat: 7.0650, lng: 38.4900 },
    'Dore Bafana':           { lat: 7.1200, lng: 38.4700 }
};
function getNearestHawassaZone(lat, lng) {
    var best = 'Stadium', bestD = Infinity;
    for (var z in HAWASSA_ZONE_COORDS) {
        var c = HAWASSA_ZONE_COORDS[z];
        var d = (lat-c.lat)*(lat-c.lat) + (lng-c.lng)*(lng-c.lng);
        if (d < bestD) { bestD = d; best = z; }
    }
    return best;
}
let selectedZone = '';
let _deliveryAddr = '';
let _deliveryAC = null;
let _deliveryPhotoUrl = ''; // optional gate/entrance photo, uploaded via api/upload.php
let _scheduleEnabled = false; // "Schedule for later" toggle in the cart drawer
let selectedPayment = 'cash';
// Delivery vehicle tier (moto / car / express cargo). Fetched from the
// server (admin-editable) on first checkout open; this hardcoded list is
// only a fallback if that fetch fails, so the picker still renders.
let selectedTier = 'moto';
let _deliveryTiers = [
    { tier_key: 'moto',          label: 'Delivery by moto', icon: '🏍️', multiplier: 1.00, eta_delta_min: 0 },
    { tier_key: 'car',           label: 'Delivery by car',  icon: '🚗', multiplier: 1.12, eta_delta_min: -2 },
    { tier_key: 'express_cargo', label: 'Express Cargo',    icon: '🚚', multiplier: 1.60, eta_delta_min: 5 }
];
let _deliveryTiersLoaded = false;
async function loadDeliveryTiers() {
    if (_deliveryTiersLoaded) return;
    _deliveryTiersLoaded = true; // only try once per page load, even on failure
    const res = await api('get_delivery_tiers', {});
    if (res && res.success && res.data && Array.isArray(res.data.tiers) && res.data.tiers.length) {
        _deliveryTiers = res.data.tiers;
        renderCartDrawer();
    }
}
function getTierMultiplier(tierKey) {
    const t = _deliveryTiers.find(function(x){ return x.tier_key === tierKey; });
    return t ? Number(t.multiplier) : 1.00;
}

// Bank transfer accounts (Abysinia/CBE) - fetched from the server rather
// than hardcoded, since a hardcoded account number can only ever be fixed
// by a code deploy and a customer has no way to know if it's stale.
// Deliberately NO hardcoded fallback list here (unlike delivery tiers) -
// if the fetch fails or returns nothing, bank transfer shows "currently
// unavailable" rather than ever falling back to a fake account number.
let _bankAccounts = [];
let _bankAccountsLoaded = false;
async function loadBankAccounts() {
    if (_bankAccountsLoaded) return;
    _bankAccountsLoaded = true;
    const res = await api('get_bank_accounts', {});
    if (res && res.success && res.data && Array.isArray(res.data.accounts)) {
        _bankAccounts = res.data.accounts;
        if (selectedPayment === 'abysinia' || selectedPayment === 'cbe') updateBankForm(selectedPayment);
    }
}
let currentOrderId = 0;
let currentPaymentId = 0;
let telebirrPollTimer = null;

function getDeliveryFee(zone) {
    const baseFee = HAWASSA_FEES[zone] ? HAWASSA_FEES[zone].fee : 50;
    return Math.round(baseFee * getTierMultiplier(selectedTier));
}
function getEstimatedTime(zone) {
    const baseTime = HAWASSA_FEES[zone] ? HAWASSA_FEES[zone].time : 25;
    const t = _deliveryTiers.find(function(x){ return x.tier_key === selectedTier; });
    const delta = t ? Number(t.eta_delta_min) : 0;
    return Math.max(3, baseTime + delta);
}

// ── PROMO CODE at checkout. The discount shown here is a PREVIEW from
// customer_validate_promo; the server recomputes it from the real basket when
// the order is placed, so this number can never be used to change the price.
let _promo = null;          // {code, discount}
let _promoBasketKey = '';
function _promoBasket() {
    const subtotal = cart.reduce(function(s,c){ return s + c.price*c.quantity; }, 0);
    return {subtotal: subtotal, fee: getDeliveryFee(selectedZone)};
}
async function applyPromo(codeIn) {
    const inp = document.getElementById('co-promo-input');
    const code = String(codeIn || (inp && inp.value) || '').trim();
    const msg = document.getElementById('co-promo-msg');
    if (!code) { if (msg) { msg.style.color = '#e63946'; msg.textContent = ETEL_I18N.t('promo_ph'); } return; }
    const b = _promoBasket();
    let res = null;
    try { res = await api('customer_validate_promo', {code: code, subtotal: b.subtotal, delivery_fee: b.fee}); } catch (e) { res = null; }
    if (res && res.success) {
        _promo = {code: res.data.code, discount: parseFloat(res.data.discount) || 0, type: res.data.type || ''};
        _promoBasketKey = b.subtotal + '|' + b.fee;
        renderCartDrawer();
    } else {
        _promo = null;
        if (msg) { msg.style.color = '#e63946'; msg.textContent = (res && res.message) || 'Could not apply that code'; }
    }
}
function removePromo() { _promo = null; _promoBasketKey = ''; renderCartDrawer(); }
// Basket or delivery fee changed after the code was applied -> re-check quietly.
async function _promoRecheck() {
    if (!_promo) return;
    const b = _promoBasket();
    const key = b.subtotal + '|' + b.fee;
    if (key === _promoBasketKey) return;
    _promoBasketKey = key;
    let res = null;
    try { res = await api('customer_validate_promo', {code: _promo.code, subtotal: b.subtotal, delivery_fee: b.fee}); } catch (e) { return; }
    if (res && res.success) _promo.discount = parseFloat(res.data.discount) || 0;
    else { showToast((res && res.message) || 'Promo code removed', 'err'); _promo = null; }
    renderCartDrawer();
}
function promoCardTap(code) {
    if (!cart.length) { showToast('Add items first, then apply ' + code); return; }
    toggleCart();
    setTimeout(function(){ applyPromo(code); }, 120);
}

// ── LOYALTY POINTS ──────────────────────────────────────────────
// Rules live on the server (api/lib/loyalty.php): earn 1 point per ETB 10 of items on a delivered
// order (x2 on weekends); 10 points = ETB 1 off items, needs 100 points. The checkout box only
// PREVIEWS - the server recomputes the real amount when the order is placed.
let _loyalty = null;       // last customer_loyalty reply
let _usePoints = false;    // "Use my points" switch in the cart
async function loadLoyalty() {
    try {
        if (!Auth.getToken()) return null;
        const res = await apiGet('customer_loyalty', {});
        if (res && res.success) { _loyalty = res.data; return _loyalty; }
    } catch (e) {}
    return _loyalty;
}
async function fillProfileStats() {
    const d = await loadLoyalty();
    if (!d) return;
    const set = function(id, v) { const el = document.getElementById(id); if (el) el.textContent = v; };
    set('pf-orders', String(d.orders));
    set('pf-points', Number(d.points).toLocaleString());
    set('pf-saved', 'ETB ' + Math.round(d.saved_etb).toLocaleString());
}
// How many points the basket could use right now (mirrors etel_loyalty_redeem_plan on the server).
function pointsPlanFor(capEtb) {
    if (!_loyalty) return 0;
    const bal = parseInt(_loyalty.points, 10) || 0, min = parseInt(_loyalty.min_redeem, 10) || 100, step = parseInt(_loyalty.step, 10) || 10;
    if (bal < min) return 0;
    let n = Math.min(bal, Math.floor(Math.max(0, capEtb) * step));
    n -= n % step;
    return n >= step ? n : 0;
}
function togglePointsUse(on) { _usePoints = !!on; renderCartDrawer(); }

// Courier tip (100% to the courier, never discounted). _tip is what the customer chose; the server re-checks it (max ETB 500).
let _tip = 0;
let _tipCustomOpen = false;
let TIP_PRESETS = [10, 20, 30, 50];   // replaced by the admin's buttons (Admin > Pricing > Courier pay) as soon as they load
let TIP_MAX = 500;
let _tipOptionsLoaded = false;
function loadTipOptions() {
    if (_tipOptionsLoaded) return;
    _tipOptionsLoaded = true;
    api('get_tip_options', {}).then(function (r) {
        if (!r || !r.success || !r.data || !r.data.presets || !r.data.presets.length) return;
        TIP_PRESETS = r.data.presets.map(Number).filter(function (v) { return v > 0; });
        TIP_MAX = Number(r.data.max) || 500;
        if (_tip > TIP_MAX) _tip = TIP_MAX;
        renderCartDrawer();
    }).catch(function () { _tipOptionsLoaded = false; });
}
function cleanTip(v) { v = parseFloat(v); return (isFinite(v) && v > 0) ? Math.min(Math.round(v * 100) / 100, TIP_MAX) : 0; }
function setTip(v) { _tip = cleanTip(v); _tipCustomOpen = false; renderCartDrawer(); }
function openTipCustom() { _tipCustomOpen = true; renderCartDrawer(); setTimeout(function () { var i = document.getElementById('co-tip-input'); if (i) i.focus(); }, 0); }
function applyTipCustom() { var i = document.getElementById('co-tip-input'); _tip = cleanTip(i ? i.value : 0); _tipCustomOpen = false; renderCartDrawer(); }
function tipBlockHtml() {
    if (TIP_MAX <= 0) return '';   // the admin switched tips off
    loadTipOptions();
    const t = function (k) { return escapeHtml(ETEL_I18N.t(k)); };
    const chip = function (label, active, onclick) {
        return '<button type="button" onclick="' + onclick + '" style="flex:1 1 0;min-width:64px;padding:10px 6px;border-radius:12px;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;border:1.5px solid ' +
            (active ? '#D4AF37' : 'rgba(212,175,55,.3)') + ';background:' + (active ? '#D4AF37' : 'rgba(212,175,55,.08)') + ';color:' + (active ? '#14141f' : '#F0CA58') + '">' + label + '</button>';
    };
    const isPreset = TIP_PRESETS.indexOf(_tip) !== -1;
    let chips = chip(t('tip_none'), _tip === 0 && !_tipCustomOpen, 'setTip(0)') +
        TIP_PRESETS.map(function (v) { return chip('ETB ' + v, _tip === v && !_tipCustomOpen, 'setTip(' + v + ')'); }).join('') +
        chip(_tip > 0 && !isPreset && !_tipCustomOpen ? 'ETB ' + _tip : t('tip_custom'), (_tip > 0 && !isPreset) || _tipCustomOpen, 'openTipCustom()');
    return '<div class="co-tip" style="margin-top:14px;padding:12px;border:1.5px solid rgba(212,175,55,.3);border-radius:14px;background:rgba(212,175,55,.05)">' +
        '<div style="font-size:13.5px;font-weight:800;color:#F7F1DF">🏍️ ' + t('tip_title') + '</div>' +
        '<div style="font-size:11.5px;color:rgba(247,241,223,.6);margin:2px 0 10px">' + t('tip_sub') + '</div>' +
        '<div style="display:flex;flex-wrap:wrap;gap:8px">' + chips + '</div>' +
        (_tipCustomOpen ? '<div style="display:flex;gap:8px;margin-top:10px"><input id="co-tip-input" type="number" inputmode="decimal" min="0" max="' + TIP_MAX + '" step="1" placeholder="' + escapeHtml(ETEL_I18N.t('tip_custom_ph')) + '" value="' + (_tip > 0 && !isPreset ? _tip : '') + '" style="flex:1;min-width:0;padding:10px;border:1.5px solid rgba(212,175,55,.35);border-radius:10px;background:rgba(255,255,255,.04);color:#F7F1DF;font-size:14px">' +
            '<button type="button" onclick="applyTipCustom()" style="padding:10px 16px;border:none;border-radius:10px;background:#D4AF37;color:#14141f;font-weight:800;cursor:pointer">OK</button></div>' : '') +
        '</div>';
}
async function openPointsSheet() {
    const d = await loadLoyalty();
    if (!d) { showToast('Could not load your points', 'err'); return; }
    const t = function(k) { return escapeHtml(ETEL_I18N.t(k)); };
    const kindLabel = {earn:'Earned', redeem:'Used', refund:'Refunded', adjust:'Adjusted'};
    const rows = (d.history || []).map(function(h) {
        const p = parseInt(h.points, 10);
        return '<div style="display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:13px"><span>' + escapeHtml(kindLabel[h.kind] || h.kind) +
            ' <span style="opacity:.5;font-size:11px">' + escapeHtml(fmtDate(h.created_at)) + '</span></span><b style="color:' + (p >= 0 ? '#2ECC71' : '#F0CA58') + '">' + (p >= 0 ? '+' : '') + p + '</b></div>';
    }).join('');
    custShowModal(
        '<div style="font-size:17px;font-weight:800;color:#D4AF37;margin-bottom:6px">⭐ ' + t('pts_title') + '</div>' +
        '<div style="font-size:34px;font-weight:800;color:#FFD700">' + Number(d.points).toLocaleString() + '</div>' +
        '<div style="font-size:12.5px;opacity:.7;margin-bottom:16px">' + t('pts_worth') + ' ETB ' + Number(d.value_etb).toFixed(0) + '</div>' +
        '<div style="font-size:12px;font-weight:700;margin-bottom:6px">' + t('pts_how') + '</div>' +
        '<div style="font-size:12.5px;opacity:.8;line-height:1.55;margin-bottom:16px">' + t('pts_earn') + '<br>' + t('pts_spend') + '</div>' +
        '<div style="font-size:12px;font-weight:700;margin-bottom:4px">' + t('pts_recent') + '</div>' +
        (rows || '<div style="font-size:12.5px;opacity:.6;padding:8px 0">' + t('pts_none') + '</div>'));
}

function renderCartDrawer() {
    // Any full re-render of this drawer (tier selection, photo upload, etc.)
    // used to reset the address input back to the stale _deliveryAddr value,
    // silently discarding text the person typed but hadn't yet picked from
    // the Google autocomplete dropdown - _deliveryAddr only updated via that
    // dropdown's place_changed callback, never from plain typing. Capture
    // whatever's actually in the box first so re-renders can't lose it.
    var _addrInput = document.getElementById('co-address');
    if (_addrInput && _addrInput.value.trim()) _deliveryAddr = _addrInput.value;
    const subtotal = cart.reduce(function(s,c){ return s + c.price*c.quantity; }, 0);
    const deliveryFee = getDeliveryFee(selectedZone);
    const serviceCharge = Math.round(subtotal * SERVICE_CHARGE_PCT / 100 * 100) / 100;
    const promoDisc = _promo ? Math.min(_promo.discount, subtotal + deliveryFee) : 0;
    const promoItemDisc = (_promo && _promo.type !== 'free_delivery') ? promoDisc : 0;
    const pointsCap = Math.max(0, subtotal - promoItemDisc);
    const pointsAvail = pointsPlanFor(pointsCap);
    const pointsUsed = _usePoints ? pointsAvail : 0;
    const pointsDisc = pointsUsed / ((_loyalty && parseInt(_loyalty.step, 10)) || 10);
    const tipAmt = _tip;
    const grand = subtotal + deliveryFee + serviceCharge - promoDisc - pointsDisc + tipAmt;
    document.getElementById('cart-items').innerHTML = cart.map(function(c, i) {
        return '<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06)">' +
            '<div style="flex:1"><div style="font-weight:600;font-size:13px;color:#F7F1DF">' + escapeHtml(c.name) + '</div>' +
            '<div style="font-size:11px;color:rgba(247,241,223,.55)">ETB ' + c.price.toFixed(2) + ' each</div></div>' +
            '<div style="display:flex;align-items:center;gap:8px">' +
            '<button onclick="changeQty(' + i + ',-1)" style="width:26px;height:26px;border:1.5px solid #ddd;border-radius:50%;background:#fff;cursor:pointer;font-size:14px">-</button>' +
            '<span style="font-weight:700;font-size:14px;min-width:18px;text-align:center">' + c.quantity + '</span>' +
            '<button onclick="changeQty(' + i + ',1)" style="width:26px;height:26px;border:none;border-radius:50%;background:#D4AF37;color:#fff;cursor:pointer;font-size:14px">+</button>' +
            '</div><div style="margin-left:10px;font-weight:700;color:#F7F1DF;font-size:13px;min-width:70px;text-align:right">ETB ' + (c.price*c.quantity).toFixed(2) + '</div></div>';
    }).join('');
    const estTime = getEstimatedTime(selectedZone);
    document.getElementById('cart-total').innerHTML =
        '<div style="border-top:2px solid rgba(212,175,55,.15);padding-top:14px;margin-top:12px">' +
        '<div style="display:flex;justify-content:space-between;font-size:13px;color:rgba(247,241,223,.55);margin-bottom:6px"><span>' + ETEL_I18N.t('lbl_subtotal') + '</span><span>ETB ' + subtotal.toFixed(2) + '</span></div>' +
        '<div style="display:flex;justify-content:space-between;font-size:13px;color:rgba(247,241,223,.55);margin-bottom:6px"><span>' + ETEL_I18N.t('lbl_delivery_fee') + '' + (selectedZone ? ' (' + estTime + ' min)' : '') + '</span><span>ETB ' + deliveryFee.toFixed(2) + '</span></div>' +
        '<div style="display:flex;justify-content:space-between;font-size:13px;color:rgba(247,241,223,.55);margin-bottom:12px"><span>' + ETEL_I18N.t('lbl_service_charge') + ' (' + SERVICE_CHARGE_PCT + '%)</span><span>ETB ' + serviceCharge.toFixed(2) + '</span></div>' +
        (pointsDisc > 0 ? '<div class="co-discount" style="display:flex;justify-content:space-between;font-size:13px;font-weight:700;color:#2ECC71;margin:-6px 0 12px"><span>' + escapeHtml(ETEL_I18N.t('pts_applied')) + ' (' + pointsUsed + ')</span><span>−ETB ' + pointsDisc.toFixed(2) + '</span></div>' : '') +
        (promoDisc > 0 ? '<div class="co-discount" style="display:flex;justify-content:space-between;font-size:13px;font-weight:700;color:#2ECC71;margin:-6px 0 12px"><span>🏷️ ' + escapeHtml(_promo.code) + ' <button type="button" onclick="removePromo()" style="background:none;border:none;color:inherit;text-decoration:underline;font-size:11px;cursor:pointer;padding:0 0 0 6px">' + escapeHtml(ETEL_I18N.t('promo_remove')) + '</button></span><span>−ETB ' + promoDisc.toFixed(2) + '</span></div>' : '') +
        (tipAmt > 0 ? '<div style="display:flex;justify-content:space-between;font-size:13px;color:rgba(247,241,223,.55);margin:-6px 0 12px"><span>' + ETEL_I18N.t('lbl_tip') + '</span><span>ETB ' + tipAmt.toFixed(2) + '</span></div>' : '') +
        '<div style="display:flex;justify-content:space-between;font-size:16px;font-weight:700;color:#F7F1DF"><span>' + ETEL_I18N.t('lbl_total') + '</span><span>ETB ' + grand.toFixed(2) + '</span></div></div>';
    document.getElementById('cart-total').insertAdjacentHTML('beforeend', tipBlockHtml());

    if (!_promo) {
        document.getElementById('cart-total').insertAdjacentHTML('beforeend',
            '<div class="co-promo" style="margin-top:14px"><div style="display:flex;gap:8px">' +
            '<input type="text" id="co-promo-input" autocomplete="off" autocapitalize="characters" placeholder="🏷️ ' + escapeHtml(ETEL_I18N.t('promo_have')) + '" style="flex:1;min-width:0;padding:10px;border:1.5px solid rgba(212,175,55,.25);border-radius:8px;font-size:13px;text-transform:uppercase;box-sizing:border-box;background:rgba(255,255,255,.04);color:inherit" onkeydown="if(event.key===\'Enter\'){event.preventDefault();applyPromo();}">' +
            '<button type="button" onclick="applyPromo()" style="padding:10px 16px;background:rgba(212,175,55,.15);border:1.5px solid rgba(212,175,55,.45);border-radius:8px;color:#F0CA58;font-size:12.5px;font-weight:800;cursor:pointer">' + escapeHtml(ETEL_I18N.t('promo_apply')) + '</button></div>' +
            '<div id="co-promo-msg" style="font-size:11.5px;margin-top:6px;min-height:14px"></div></div>');
    }
    if (_loyalty && (pointsAvail > 0 || (_loyalty.points > 0 && _loyalty.points < _loyalty.min_redeem))) {
        const factor = (parseInt(_loyalty.step, 10) || 10);
        document.getElementById('cart-total').insertAdjacentHTML('beforeend', pointsAvail > 0
            ? '<label class="co-points" style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:12px;padding:11px 12px;border:1.5px solid rgba(212,175,55,.35);border-radius:10px;cursor:pointer;font-size:13px;font-weight:700">' +
              '<span>⭐ ' + escapeHtml(ETEL_I18N.t('pts_use')) + ' <span style="font-weight:600;opacity:.7">(' + pointsAvail + ' = −ETB ' + (pointsAvail / factor).toFixed(0) + ')</span></span>' +
              '<input type="checkbox" ' + (_usePoints ? 'checked' : '') + ' onchange="togglePointsUse(this.checked)" style="width:20px;height:20px;accent-color:#D4AF37"></label>'
            : '<div class="co-points" style="margin-top:12px;font-size:12px;opacity:.65">⭐ ' + escapeHtml(ETEL_I18N.t('pts_need').replace('{n}', String(_loyalty.min_redeem - _loyalty.points))) + '</div>');
    }
    setTimeout(_promoRecheck, 0);

    var zoneHint = selectedZone
        ? '📍 ' + selectedZone + ' · ETB ' + deliveryFee.toFixed(0) + ' delivery (' + estTime + ' min)'
        : 'Zone auto-detected from your address';

    _deliveryAC = null; // reset so initDeliveryAutocomplete re-binds to new DOM input

    document.getElementById('cart-checkout').innerHTML =
        '<div style="margin-top:16px">' +
        '<!-- DELIVERY TIER (moto / car / express cargo) -->' +
        '<label style="display:block;font-size:11px;font-weight:700;color:rgba(247,241,223,.55);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px">🚚 Delivery Speed</label>' +
        '<div style="display:grid;grid-template-columns:repeat(' + _deliveryTiers.length + ',1fr);gap:8px;margin-bottom:16px">' +
        _deliveryTiers.map(function(t) { return tierOptionBtn(t, selectedZone); }).join('') +
        '</div>' +
        '<!-- SCHEDULE FOR LATER: real schedule_order/get_scheduled_orders backend action -->' +
        '<div style="display:flex;align-items:center;justify-content:space-between;padding:12px;background:rgba(255,255,255,.03);border:1px solid var(--glass-border);border-radius:12px;margin-bottom:14px">' +
        '<div><div style="font-size:13px;font-weight:700;color:var(--text)">🕐 Schedule for later</div><div style="font-size:11px;color:rgba(247,241,223,.5);margin-top:2px">Choose a date &amp; time for this order</div></div>' +
        '<label style="position:relative;display:inline-block;width:44px;height:24px;flex-shrink:0">' +
        '<input type="checkbox" id="co-schedule-toggle" onchange="toggleScheduleDelivery(this.checked)" style="opacity:0;width:0;height:0" ' + (_scheduleEnabled ? 'checked' : '') + '>' +
        '<span style="position:absolute;inset:0;background:' + (_scheduleEnabled ? '#D4AF37' : 'rgba(255,255,255,.15)') + ';border-radius:24px;transition:.2s"></span>' +
        '<span style="position:absolute;top:3px;left:' + (_scheduleEnabled ? '23px' : '3px') + ';width:18px;height:18px;background:#fff;border-radius:50%;transition:.2s"></span>' +
        '</label></div>' +
        '<div id="co-schedule-fields" style="display:' + (_scheduleEnabled ? 'grid' : 'none') + ';grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px">' +
        '<div><label style="display:block;font-size:10.5px;font-weight:700;color:rgba(247,241,223,.5);margin-bottom:4px;text-transform:uppercase">Date</label>' +
        '<input type="date" id="co-schedule-date" min="' + new Date().toISOString().slice(0,10) + '" style="width:100%;padding:10px;border:1.5px solid rgba(212,175,55,.25);border-radius:8px;font-size:13px;box-sizing:border-box;background:rgba(255,255,255,.03);color:var(--text)"></div>' +
        '<div><label style="display:block;font-size:10.5px;font-weight:700;color:rgba(247,241,223,.5);margin-bottom:4px;text-transform:uppercase">Time</label>' +
        '<input type="time" id="co-schedule-time" style="width:100%;padding:10px;border:1.5px solid rgba(212,175,55,.25);border-radius:8px;font-size:13px;box-sizing:border-box;background:rgba(255,255,255,.03);color:var(--text)"></div>' +
        '</div>' +
        '<!-- DELIVERY ADDRESS (Google Places Autocomplete) -->' +
        '<label style="display:block;font-size:11px;font-weight:700;color:rgba(247,241,223,.55);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px">' + ETEL_I18N.t('lbl_delivery_address') + '</label>' +
        '<div style="display:flex;gap:8px;margin-bottom:4px">' +
        '<input type="text" id="co-address" autocomplete="off" placeholder="' + ETEL_I18N.t('ph_address') + '" value="' + _deliveryAddr.replace(/"/g,'&quot;') + '" style="flex:1;padding:10px;border:1.5px solid rgba(212,175,55,.25);border-radius:8px;font-size:13px;box-sizing:border-box;background:rgba(255,255,255,.03);color:var(--text)">' +
        '<button onclick="getMyLocation()" id="gps-btn" title="Use my current location" style="padding:10px 12px;background:#D4AF37;color:#fff;border:none;border-radius:8px;font-size:18px;cursor:pointer;white-space:nowrap">📍</button>' +
        '</div>' +
        '<div id="co-zone-hint" style="font-size:11px;color:' + (selectedZone?'#2ECC71':'#aaa') + ';margin-bottom:12px;padding:0 2px">' + zoneHint + '</div>' +
        '<!-- OPTIONAL GATE/ENTRANCE PHOTO -->' +
        '<input type="file" id="co-photo-file" accept="image/*" capture="environment" style="display:none" onchange="uploadDeliveryPhoto(this)">' +
        '<div id="co-photo-row" style="margin-bottom:14px">' +
        (_deliveryPhotoUrl
            ? '<div style="display:flex;align-items:center;gap:8px"><img src="' + escUrl(_deliveryPhotoUrl) + '" style="width:44px;height:44px;object-fit:cover;border-radius:8px;border:1px solid rgba(212,175,55,.25)"><span style="font-size:12px;color:#2ECC71;flex:1">Photo added</span><button onclick="removeDeliveryPhoto()" style="padding:6px 10px;background:rgba(255,255,255,.06);border:none;border-radius:8px;font-size:11px;cursor:pointer;color:rgba(247,241,223,.4)">Remove</button></div>'
            : '<button onclick="document.getElementById(\'co-photo-file\').click()" style="width:100%;padding:9px;background:rgba(255,255,255,.03);border:1.5px dashed rgba(212,175,55,.3);border-radius:8px;font-size:12px;color:#F0CA58;cursor:pointer">📷 Add a photo of your gate/entrance (optional — helps the courier find you)</button>') +
        '</div>' +
        '<!-- RECIPIENT -->' +
        '<label style="display:block;font-size:11px;font-weight:700;color:rgba(247,241,223,.55);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px">' + ETEL_I18N.t('lbl_recipient_name') + '</label>' +
        '<input type="text" id="co-name" placeholder="' + ETEL_I18N.t('ph_full_name') + '" style="width:100%;padding:10px;border:1.5px solid rgba(212,175,55,.25);border-radius:8px;font-size:13px;margin-bottom:12px;box-sizing:border-box;background:rgba(255,255,255,.03);color:var(--text)">' +
        '<label style="display:block;font-size:11px;font-weight:700;color:rgba(247,241,223,.55);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px">' + ETEL_I18N.t('lbl_recipient_phone') + '</label>' +
        '<input type="tel" id="co-phone" placeholder="+251911000000" style="width:100%;padding:10px;border:1.5px solid rgba(212,175,55,.25);border-radius:8px;font-size:13px;margin-bottom:12px;box-sizing:border-box;background:rgba(255,255,255,.03);color:var(--text)">' +
        '<label style="display:block;font-size:11px;font-weight:700;color:rgba(247,241,223,.55);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px">' + ETEL_I18N.t('lbl_notes') + '</label>' +
        '<input type="text" id="co-notes" placeholder="' + ETEL_I18N.t('ph_notes') + '" style="width:100%;padding:10px;border:1.5px solid rgba(212,175,55,.25);border-radius:8px;font-size:13px;margin-bottom:16px;box-sizing:border-box;background:rgba(255,255,255,.03);color:var(--text)">' +
        '<!-- PAYMENT METHOD -->' +
        '<label style="display:block;font-size:11px;font-weight:700;color:rgba(247,241,223,.55);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px">💳 ' + ETEL_I18N.t('lbl_payment_method') + '</label>' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px">' +
        paymentOptionBtn('telebirr', '📱 Telebirr', ETEL_I18N.t('pay_telebirr_sub')) +
        paymentOptionBtn('wallet',   '💰 ኢE Pay',   ETEL_I18N.t('pay_wallet_sub'), !etelPayLive) +
        paymentOptionBtn('chapa',    '💳 Chapa',    'Telebirr, CBE, bank or card') +
        paymentOptionBtn('cash',     '💵 ' + ETEL_I18N.t('pay_cash'), ETEL_I18N.t('pay_cash_sub')) +
        paymentOptionBtn('abysinia', '🏦 Abysinia', ETEL_I18N.t('pay_bank_sub')) +
        paymentOptionBtn('cbe',      '🏛️ CBE',      ETEL_I18N.t('pay_bank_sub')) +
        '</div>' +
        '<div id="wallet-info" style="display:none;background:rgba(201,169,97,.1);padding:14px;border-radius:10px;margin-bottom:14px;border-left:4px solid #D4AF37">' +
        '<p style="font-size:12px;font-weight:600;margin-bottom:4px;color:#C8960C">💰 ኢE Pay — Ethiopian Wallet</p>' +
        '<p style="font-size:12px;color:#e8e2d4;margin:0">You\'ll confirm and pay with your ኢE Pay PIN when your order arrives — instant and cashless, just like handing over cash but nothing to carry.</p>' +
        '</div>' +
        '<div id="chapa-info" style="display:none;background:rgba(16,185,129,.1);padding:14px;border-radius:10px;margin-bottom:14px;border-left:4px solid #10B981">' +
        '<p style="font-size:12px;font-weight:600;margin-bottom:4px;color:#10B981">💳 Chapa — pay online now</p>' +
        '<p style="font-size:12px;color:#e8e2d4;margin:0">You\'ll be taken to Chapa\'s secure page to pay with Telebirr, CBE Birr, a bank or a card. Your order is confirmed once payment goes through.</p>' +
        '</div>' +
        '<div id="telebirr-form" style="display:none;background:rgba(51,149,255,.1);padding:14px;border-radius:10px;margin-bottom:14px;border-left:4px solid #3395FF;color:#e8e2d4">' +
        '<p style="font-size:12px;font-weight:600;margin-bottom:8px;color:#1565c0">📱 Telebirr USSD Payment</p>' +
        '<ol style="font-size:12px;color:#e8e2d4;margin:0 0 10px 18px;padding:0">' +
        '<li>Enter your Telebirr phone number below</li>' +
        '<li>Click "Pay with Telebirr"</li>' +
        '<li>Dial <strong>*150#</strong> on your phone</li>' +
        '<li>Follow menu to confirm payment</li>' +
        '</ol>' +
        '<div style="display:flex;gap:0">' +
        '<span style="background:rgba(255,255,255,.08);padding:10px 10px;border:1px solid rgba(255,255,255,.15);border-radius:8px 0 0 8px;font-weight:700;font-size:13px;color:#e8e2d4">+251</span>' +
        '<input type="tel" id="tb-phone" placeholder="911234567" maxlength="9" style="flex:1;padding:10px;border:1px solid rgba(212,175,55,.25);border-left:none;border-radius:0 8px 8px 0;font-size:13px">' +
        '</div>' +
        '</div>' +
        '<div id="bank-form" style="display:none;background:rgba(230,126,34,.1);padding:14px;border-radius:10px;margin-bottom:14px;border-left:4px solid #E67E22;color:#e8e2d4">' +
        '<p id="bank-name" style="font-weight:700;font-size:13px;margin-bottom:6px">🏦 Bank Transfer</p>' +
        '<p style="font-size:12px;color:#e8e2d4;margin-bottom:6px">Account: <strong id="bank-acct-name">ETEL Hawassa</strong></p>' +
        '<p id="bank-acct" style="font-size:12px;color:#e8e2d4;margin-bottom:10px">Account No: <strong>1234567890</strong></p>' +
        '<label style="font-size:11px;font-weight:700;color:rgba(247,241,223,.55);display:block;margin-bottom:4px">Bank Reference No. *</label>' +
        '<input type="text" id="bank-ref" placeholder="Enter reference number after transfer" style="width:100%;padding:10px;border:1px solid rgba(212,175,55,.25);border-radius:8px;font-size:13px;box-sizing:border-box">' +
        '</div>' +
        '<div id="co-msg" style="font-size:13px;margin-bottom:10px;display:none"></div>' +
        '<div id="telebirr-status" style="display:none;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);padding:14px;border-radius:10px;margin-bottom:14px;text-align:center">' +
        '<p style="color:#4ADE80;font-weight:700;margin-bottom:6px">✅ Payment Initiated!</p>' +
        '<p style="font-size:13px;color:#4ADE80;margin-bottom:4px">Dial <strong>*150#</strong> on your phone</p>' +
        '<p id="tb-poll-msg" style="font-size:12px;color:rgba(247,241,223,.55)">Waiting for confirmation… <span id="tb-counter">120</span>s</p>' +
        '</div>' +
        '<button onclick="handleCheckout()" id="checkout-btn" style="width:100%;padding:14px;background:linear-gradient(135deg,#D4AF37,#C8960C);color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer">' + ETEL_I18N.t('btn_place_order') + ' — ETB ' + grand.toFixed(2) + '</button>' +
        '</div>';

    // Restore payment selection UI state
    highlightPaymentBtn(selectedPayment);
    if (selectedPayment === 'telebirr') document.getElementById('telebirr-form').style.display = 'block';
    if (selectedPayment === 'wallet') document.getElementById('wallet-info').style.display = 'block';
    if (selectedPayment === 'chapa') document.getElementById('chapa-info').style.display = 'block';
    if (selectedPayment === 'abysinia' || selectedPayment === 'cbe') {
        document.getElementById('bank-form').style.display = 'block';
        updateBankForm(selectedPayment);
    }
    // Bind Google Places Autocomplete to the address input
    setTimeout(initDeliveryAutocomplete, 80);
}

// Build a Google Maps LatLngBounds box of roughly radiusKm around a point,
// used to scope address search to the user's actual area instead of
// searching the whole country. ~0.009 degrees latitude per km is a
// reasonable flat-earth approximation at this scale.
function _latLngBoundsAround(lat, lng, radiusKm) {
    if (typeof google === 'undefined' || !google.maps) return null;
    var dLat = radiusKm * 0.009;
    var dLng = radiusKm * 0.009 / Math.cos(lat * Math.PI / 180);
    return new google.maps.LatLngBounds(
        { lat: lat - dLat, lng: lng - dLng },
        { lat: lat + dLat, lng: lng + dLng }
    );
}

// Re-center the address search around the user's actual GPS position once
// we know it (called from getMyLocation()'s success handler) - this is
// what makes search scoped correctly no matter which city the user is
// actually in, not just Hawassa specifically.
function _biasAutocompleteToLocation(lat, lng) {
    if (!_deliveryAC || typeof google === 'undefined' || !google.maps) return;
    var bounds = _latLngBoundsAround(lat, lng, 20);
    if (bounds) _deliveryAC.setBounds(bounds);
}

function initDeliveryAutocomplete() {
    var input = document.getElementById('co-address');
    if (!input || _deliveryAC) return;

    // If Google Places is not available, add zone picker below the input
    if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
        var existingPicker = document.getElementById('co-zone-picker');
        if (existingPicker) return;
        var zones = ['Mehal Ketema','Tabor','Addis Ketema','Haik Dar','Tulu Dimtu','Misrak','Referral','Hawela Teklehaimanot','Dore Bafana','Stadium','Liqe Mender','University'];
        var sel = document.createElement('select');
        sel.id = 'co-zone-picker';
        sel.style.cssText = 'width:100%;padding:10px 12px;border:1px solid rgba(212,175,55,.25);border-radius:8px;font-size:13px;margin-top:6px;background:rgba(255,255,255,.04);color:#F7F1DF;font-family:inherit;cursor:pointer';
        sel.innerHTML = '<option value="">— Select delivery zone —</option>' + zones.map(function(z){ return '<option value="'+z+'">'+z+'</option>'; }).join('');
        sel.onchange = function() {
            if (sel.value) {
                selectedZone = sel.value;
                if (!input.value) input.value = sel.value;
                var hint = document.getElementById('co-zone-hint');
                if (hint) {
                    var fee = getDeliveryFee(selectedZone);
                    var tm  = getEstimatedTime(selectedZone);
                    hint.textContent = '📍 ' + selectedZone + ' · ETB ' + fee + ' delivery (' + tm + ' min)';
                    hint.style.color = '#2ECC71';
                }
            }
        };
        if (input.parentNode) input.parentNode.insertBefore(sel, input.nextSibling);
        return;
    }
    // Bias results to the user's actual area instead of searching all of
    // Ethiopia. Default bias centers on Hawassa (the primary service area)
    // until we know exactly where the user is; _biasAutocompleteToLocation
    // re-centers this on their real GPS position once available, which
    // naturally handles anyone outside Hawassa too (e.g. Addis Ababa) since
    // it centers on wherever they actually are, not a hardcoded city.
    var HAWASSA_LAT = 7.0504, HAWASSA_LNG = 38.4955;
    _deliveryAC = new google.maps.places.Autocomplete(input, {
        componentRestrictions: { country: 'et' },
        fields: ['geometry', 'formatted_address', 'name'],
        bounds: _latLngBoundsAround(HAWASSA_LAT, HAWASSA_LNG, 25),
        strictBounds: true
    });
    _deliveryAC.addListener('place_changed', function() {
        var place = _deliveryAC.getPlace();
        if (!place || !place.geometry) return;
        _deliveryLat  = place.geometry.location.lat();
        _deliveryLng  = place.geometry.location.lng();
        _deliveryAddr = place.formatted_address || place.name || input.value;
        selectedZone  = getNearestHawassaZone(_deliveryLat, _deliveryLng);
        renderCartDrawer();
        // Re-fill the input after re-render
        setTimeout(function() {
            var inp = document.getElementById('co-address');
            if (inp) inp.value = _deliveryAddr;
        }, 10);
    });
}

function tierOptionBtn(tier, zone) {
    const active = selectedTier === tier.tier_key;
    const fee = Math.round((HAWASSA_FEES[zone] ? HAWASSA_FEES[zone].fee : 50) * Number(tier.multiplier));
    const baseTime = HAWASSA_FEES[zone] ? HAWASSA_FEES[zone].time : 25;
    const eta = Math.max(3, baseTime + Number(tier.eta_delta_min));
    return '<button data-tier="'+tier.tier_key+'" onclick="selectTier(\''+tier.tier_key+'\')" style="padding:10px 6px;border:2px solid '+(active?'#D4AF37':'rgba(212,175,55,.18)')+';border-radius:10px;background:'+(active?'linear-gradient(145deg,rgba(212,175,55,.22),rgba(212,175,55,.08))':'rgba(255,255,255,.03)')+';box-shadow:'+(active?'0 0 16px rgba(212,175,55,.35)':'none')+';cursor:pointer;text-align:center;transition:all .2s">' +
        '<span style="display:block;font-size:20px;margin-bottom:2px">'+(tier.icon||'📦')+'</span>' +
        '<span style="display:block;font-weight:700;font-size:11px;color:'+(active?'#F0CA58':'#F7F1DF')+'">'+tier.label+'</span>' +
        '<span style="display:block;font-size:10px;color:rgba(247,241,223,.55);margin-top:2px">'+eta+' min · ETB '+fee+'</span>' +
        '</button>';
}

function selectTier(tierKey) {
    selectedTier = tierKey;
    renderCartDrawer();
}

function toggleScheduleDelivery(checked) {
    _scheduleEnabled = checked;
    renderCartDrawer();
}

function paymentOptionBtn(method, label, sub, disabled) {
    if (disabled) {
        return '<button disabled data-pay="'+method+'" title="Pending government approval" ' +
            'style="padding:10px 8px;border:2px dashed rgba(247,241,223,.2);border-radius:10px;background:rgba(255,255,255,.02);cursor:not-allowed;text-align:left;opacity:.55">' +
            '<span style="display:block;font-weight:700;font-size:12px;color:rgba(247,241,223,.5)">'+label+' 🔜</span>' +
            '<span style="display:block;font-size:10px;color:rgba(247,241,223,.35);margin-top:2px">Coming soon</span>' +
            '</button>';
    }
    const active = selectedPayment === method;
    return '<button data-pay="'+method+'" onclick="selectPayment(\''+method+'\')" style="padding:10px 8px;border:2px solid '+(active?'#D4AF37':'rgba(212,175,55,.18)')+';border-radius:10px;background:'+(active?'linear-gradient(145deg,rgba(212,175,55,.22),rgba(212,175,55,.08))':'rgba(255,255,255,.03)')+';box-shadow:'+(active?'0 0 16px rgba(212,175,55,.35)':'none')+';cursor:pointer;text-align:left;transition:all .2s">' +
        '<span style="display:block;font-weight:700;font-size:12px;color:'+(active?'#F0CA58':'#F7F1DF')+'">'+label+'</span>' +
        '<span style="display:block;font-size:10px;color:rgba(247,241,223,.55);margin-top:2px">'+sub+'</span>' +
        '</button>';
}

function highlightPaymentBtn(method) {
    document.querySelectorAll('[data-pay]').forEach(function(b) {
        const active = b.dataset.pay === method;
        b.style.borderColor = active ? '#D4AF37' : 'rgba(212,175,55,.18)';
        b.style.background  = active ? 'linear-gradient(145deg,rgba(212,175,55,.22),rgba(212,175,55,.08))' : 'rgba(255,255,255,.03)';
        b.style.boxShadow   = active ? '0 0 16px rgba(212,175,55,.35)' : 'none';
    });
}

function selectPayment(method) {
    selectedPayment = method;
    highlightPaymentBtn(method);
    document.getElementById('telebirr-form').style.display = method === 'telebirr' ? 'block' : 'none';
    document.getElementById('bank-form').style.display     = (method === 'abysinia' || method === 'cbe') ? 'block' : 'none';
    document.getElementById('wallet-info').style.display   = method === 'wallet' ? 'block' : 'none';
    document.getElementById('chapa-info').style.display    = method === 'chapa' ? 'block' : 'none';
    if (method === 'abysinia' || method === 'cbe') updateBankForm(method);
}

function updateBankForm(method) {
    const form = document.getElementById('bank-form');
    if (!form) return;
    const acct = _bankAccounts.find(function(a){ return a.bank_key === method; });
    const nameEl = document.getElementById('bank-name');
    const acctNameEl = document.getElementById('bank-acct-name');
    const acctNumEl = document.getElementById('bank-acct');
    const refInput = document.getElementById('bank-ref');
    const label = method === 'abysinia' ? '🏦' : '🏛️';
    if (acct) {
        if (nameEl) nameEl.textContent = label + ' ' + acct.display_name;
        if (acctNameEl) acctNameEl.textContent = acct.account_name;
        if (acctNumEl) acctNumEl.innerHTML = 'Account No: <strong>' + acct.account_number.replace(/</g,'&lt;') + '</strong>';
        if (refInput) refInput.disabled = false;
    } else {
        // No hardcoded fallback on purpose - showing a made-up account
        // number here is exactly the bug this replaced. If the fetch
        // hasn't finished yet this just shows "Loading…" briefly; if it
        // genuinely has no data for this bank, tell the person plainly
        // instead of guessing at a number.
        const stillLoading = !_bankAccountsLoaded || (_bankAccountsLoaded && !_bankAccounts.length);
        if (nameEl) nameEl.textContent = label + ' Bank Transfer';
        if (acctNameEl) acctNameEl.textContent = stillLoading ? 'Loading…' : 'Currently unavailable';
        if (acctNumEl) acctNumEl.innerHTML = stillLoading ? 'Loading account details…' : 'This payment method is temporarily unavailable — please choose Cash or Telebirr.';
        if (refInput) refInput.disabled = !stillLoading ? true : false;
    }
}

function onZoneChange(zone) {
    selectedZone = zone;
    renderCartDrawer();
}

async function uploadDeliveryPhoto(fileInput) {
    if (!fileInput.files || !fileInput.files[0]) return;
    showCoMsg('Uploading photo…', '#888');
    const uploadBase = (typeof ETEL !== 'undefined' && ETEL.api) ? ETEL.api.replace('boot.php', 'upload.php') : 'api/upload.php';
    const fd = new FormData();
    fd.append('image', fileInput.files[0]);
    try {
        const res = await fetch(uploadBase, { method: 'POST', headers: { 'Authorization': 'Bearer ' + Auth.getToken() }, body: fd });
        const data = await res.json();
        if (data.success) {
            _deliveryPhotoUrl = data.data.url;
            document.getElementById('co-msg').style.display = 'none';
            renderCartDrawer();
        } else {
            showCoMsg('Photo upload failed: ' + (data.message || 'unknown error'));
        }
    } catch (e) {
        showCoMsg('Photo upload error — check your connection.');
    }
}

function removeDeliveryPhoto() {
    _deliveryPhotoUrl = '';
    renderCartDrawer();
}

function changeQty(idx, delta) {
    cart[idx].quantity += delta;
    if (cart[idx].quantity <= 0) cart.splice(idx, 1);
    updateCartCount();
    if (!cart.length) { closeCart(); return; }
    renderCartDrawer();
}

// Stores GPS coords captured via the 📍 button — sent with the order
let _deliveryLat = null, _deliveryLng = null;

async function getMyLocation() {
    const btn   = document.getElementById('gps-btn');
    const input = document.getElementById('co-address');
    if (!btn || !input) return;
    if (!navigator.geolocation) { showToast('Geolocation not supported on this device', 'err'); return; }
    btn.textContent = '⏳';
    btn.disabled = true;

    function onSuccess(pos) {
        const lat = pos.coords.latitude;
        const lon = pos.coords.longitude;
        _deliveryLat = lat;
        _deliveryLng = lon;
        selectedZone = getNearestHawassaZone(lat, lon);
        _biasAutocompleteToLocation(lat, lon);
        (async () => {
            try {
                const r = await fetch(
                    'https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat + '&lon=' + lon,
                    { headers: { 'Accept-Language': 'en', 'User-Agent': 'ETEL-Delivery/1.0' } }
                );
                const data = await r.json();
                const a = data.address || {};
                const parts = [
                    a.road || a.pedestrian || a.footway || a.street,
                    a.suburb || a.neighbourhood || a.quarter,
                    a.city || a.town || a.village || a.county
                ].filter(Boolean);
                _deliveryAddr = parts.join(', ') || data.display_name || (lat.toFixed(5) + ', ' + lon.toFixed(5));
                input.value = _deliveryAddr;
            } catch (_) {
                _deliveryAddr = lat.toFixed(5) + ', ' + lon.toFixed(5);
                input.value = _deliveryAddr;
            }
            btn.textContent = '✓';
            btn.style.background = '#2ecc71';
            btn.disabled = false;
            setTimeout(function() { btn.textContent = '📍'; btn.style.background = '#D4AF37'; }, 3000);
            const hint = document.getElementById('co-zone-hint');
            if (hint) {
                const fee = getDeliveryFee(selectedZone);
                const tm  = getEstimatedTime(selectedZone);
                hint.textContent = '📍 ' + selectedZone + ' · ETB ' + fee + ' delivery (' + tm + ' min)';
                hint.style.color = '#2ECC71';
            }
        })();
    }

    function onFinalError(err) {
        btn.textContent = '📍';
        btn.disabled = false;
        const msgs = { 1: 'Location permission denied. Allow it in browser settings.', 2: 'Location unavailable. Try again.', 3: 'Location request timed out — try moving somewhere with a clearer signal, or enter your address manually.' };
        // Surface the browser's own diagnostic detail alongside our generic
        // message - both attempts (high accuracy AND the network-based
        // fallback) failing identically is not explained by weak GPS signal
        // alone, and guessing further without this detail wastes another
        // round trip. err.message is the browser/OS's own reason, and
        // window.self !== window.top flags a case Permissions-Policy would
        // actually block (geolocation=(self) in .htaccess denies any
        // cross-origin iframe embedding this page).
        const diag = ' [code ' + err.code + (err.message ? ': ' + err.message : '') + (window.self !== window.top ? ', embedded' : '') + ']';
        showToast((msgs[err.code] || 'Could not get location') + diag, 'err');
        console.error('[ETEL] geolocation failed', { code: err.code, message: err.message, embedded: window.self !== window.top, isSecureContext: window.isSecureContext });
        if (navigator.permissions && navigator.permissions.query) {
            navigator.permissions.query({ name: 'geolocation' }).then(function (status) {
                console.error('[ETEL] geolocation permission state:', status.state);
            }).catch(function () {});
        }
    }

    // Try a high-accuracy GPS fix first (best result), but GPS can
    // genuinely take longer than 10s indoors or with a weak signal, or
    // fail outright with POSITION_UNAVAILABLE (code 2) when the device
    // can't get a satellite fix at all - very common on mobile data
    // indoors/urban canyon, where a much faster network/cell-tower fix
    // can still succeed even though GPS could not. Only skip the retry
    // on permission denied (code 1), which a lower-accuracy request
    // would fail identically.
    navigator.geolocation.getCurrentPosition(
        onSuccess,
        function (err) {
            if (err.code === 1) { onFinalError(err); return; } // permission denied - retrying won't help
            navigator.geolocation.getCurrentPosition(
                onSuccess,
                onFinalError,
                { timeout: 15000, maximumAge: 120000, enableHighAccuracy: false }
            );
        },
        { timeout: 8000, maximumAge: 60000, enableHighAccuracy: true }
    );
}

async function handleCheckout() {
    const address  = (document.getElementById('co-address') ? document.getElementById('co-address').value : '').trim();
    // Auto-detect zone from typed address text if not yet set
    if (!selectedZone && address) selectedZone = 'Stadium';
    const zone     = selectedZone || 'Stadium';
    const name     = (document.getElementById('co-name') ? document.getElementById('co-name').value : '').trim();
    const phone    = (document.getElementById('co-phone') ? document.getElementById('co-phone').value : '').trim();
    const rawNotes = (document.getElementById('co-notes') ? document.getElementById('co-notes').value : '').trim();
    const notes    = address ? ('Address: ' + address + (rawNotes ? '\n' + rawNotes : '')) : rawNotes;

    if (!address) { showCoMsg('Please enter a delivery address or tap 📍 to use your location.'); return; }
    if (!name)  { showCoMsg('Recipient name is required.'); return; }
    if (!phone) { showCoMsg('Recipient phone is required.'); return; }

    if (_scheduleEnabled && _promo) { showCoMsg('Promo codes can\'t be used on scheduled orders - remove the code or turn off "Schedule for later".'); return; }
    if (_scheduleEnabled && selectedPayment === 'chapa') { showCoMsg('Chapa can\'t be used for scheduled orders yet - choose another payment method or turn off "Schedule for later".'); return; }
    if (_scheduleEnabled) {
        const d = (document.getElementById('co-schedule-date') || {}).value;
        const t = (document.getElementById('co-schedule-time') || {}).value;
        if (!d || !t) { showCoMsg('Pick a date and time for the scheduled delivery.'); return; }
        const scheduledAt = d + ' ' + t + ':00';
        if (new Date(scheduledAt) <= new Date()) { showCoMsg('Scheduled time must be in the future.'); return; }
        await scheduleOrderFromCart(zone, name, phone, notes, scheduledAt);
        return;
    }

    if (selectedPayment === 'telebirr') {
        const tbPhone = (document.getElementById('tb-phone') ? document.getElementById('tb-phone').value : '').trim();
        if (!tbPhone || tbPhone.length < 9) { showCoMsg('Enter valid Telebirr phone number (9 digits).'); return; }
        await placeOrderThenPay(zone, name, phone, notes, tbPhone);
    } else if (selectedPayment === 'chapa') {
        await placeOrderThenChapa(zone, name, phone, notes);
    } else if (selectedPayment === 'abysinia' || selectedPayment === 'cbe') {
        const acctAvailable = _bankAccounts.some(function(a){ return a.bank_key === selectedPayment; });
        if (!acctAvailable) { showCoMsg('This bank transfer option is currently unavailable — please choose Cash or Telebirr.'); return; }
        const ref = (document.getElementById('bank-ref') ? document.getElementById('bank-ref').value : '').trim();
        if (!ref) { showCoMsg('Please enter bank reference number after making the transfer.'); return; }
        await placeOrderThenBank(zone, name, phone, notes, ref);
    } else {
        await placeOrder(zone, name, phone, notes);
    }
}

async function placeOrder(zone, name, phone, notes) {
    showCoMsg('Placing order…', '#888');
    const btn = document.getElementById('checkout-btn');
    if (btn) btn.disabled = true;
    // Retry up to 2 times on network failure
    let res = null;
    for (let attempt = 1; attempt <= 2; attempt++) {
        res = await api('customer_create_order', {
            promo_code: _promo ? _promo.code : '',
            use_points: _usePoints ? 1 : 0, tip: _tip,
            items: cart.map(function(c){ return {item_id:c.item_id, quantity:c.quantity}; }),
            to_zone: zone, recipient_name: name, recipient_phone: phone, notes: notes,
            payment_method: selectedPayment,
            delivery_tier: selectedTier,
        delivery_photo_url: _deliveryPhotoUrl,
        delivery_lat: _deliveryLat, delivery_lng: _deliveryLng
        });
        if (res && (res.success || (res.message && !res.message.includes('reach')))) break;
        if (attempt < 2) { await new Promise(function(r){ setTimeout(r, 1500); }); showCoMsg('Retrying…', '#888'); }
    }
    if (btn) btn.disabled = false;
    if (res && res.success) {
        currentOrderId = res.data.order_id;
        _promo = null; _usePoints = false; _tip = 0; _tipCustomOpen = false; _loyalty = null; cart = []; selectedZone = ''; _deliveryAddr = ''; _deliveryLat = null; _deliveryLng = null; _deliveryAC = null; _deliveryPhotoUrl = '';
        updateCartCount();
        closeCart();
        showToast('Order placed! Track at: /track?code=' + res.data.order_code, 'ok');
        setTimeout(function(){ switchCategory('orders'); }, 1200);
    } else {
        showCoMsg((res && res.message) || 'Order failed. Please try again.');
    }
}

// Real schedule_order backend action (existing before this change, just
// never wired to any UI) - stores the current cart as an order_template
// JSON blob, dispatched later by the existing cron_dispatch_scheduled.php
// job. No fabricated scheduling behavior; it's the real backend flow.
async function loadScheduledOrders() {
    const el = document.getElementById('scheduled-orders-section');
    if (!el) return;
    const res = await apiGet('get_scheduled_orders', {});
    const list = (res && res.success && res.data && res.data.scheduled) || [];
    if (!list.length) { el.style.display = 'none'; el.innerHTML = ''; return; }
    el.style.display = 'block';
    el.innerHTML = '<div style="font-size:13px;font-weight:700;color:var(--text);margin-bottom:8px">🕐 Scheduled Deliveries</div>' +
        list.map(function(s) {
            return '<div style="display:flex;justify-content:space-between;align-items:center;padding:12px;margin-bottom:8px;background:rgba(212,175,55,.08);border:1px solid rgba(212,175,55,.25);border-radius:12px">' +
                '<div><div style="font-size:13px;font-weight:700;color:#F0CA58">' + fmtDate(s.scheduled_at) + '</div>' +
                '<div style="font-size:11px;color:rgba(247,241,223,.5);margin-top:2px">' + (s.repeat_daily ? 'Repeats daily' : 'One-time') + '</div></div>' +
                '<button onclick="cancelScheduledOrder(' + s.id + ')" style="padding:7px 12px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#FCA5A5;border-radius:8px;font-size:11px;font-weight:700;cursor:pointer">Cancel</button></div>';
        }).join('');
}
async function cancelScheduledOrder(id) {
    if (!confirm('Cancel this scheduled delivery?')) return;
    const res = await api('cancel_scheduled_order', {scheduled_id: id});
    if (res && res.success) { showToast('Scheduled order cancelled'); loadScheduledOrders(); }
    else { showToast((res && res.message) || 'Could not cancel', 'err'); }
}
async function scheduleOrderFromCart(zone, name, phone, notes, scheduledAt) {
    showCoMsg('Scheduling order…', '#888');
    const btn = document.getElementById('checkout-btn');
    if (btn) btn.disabled = true;
    const template = {
        items: cart.map(function(c){ return {item_id:c.item_id, quantity:c.quantity}; }),
        to_zone: zone, recipient_name: name, recipient_phone: phone, notes: notes,
        payment_method: selectedPayment, delivery_tier: selectedTier,
        delivery_photo_url: _deliveryPhotoUrl, delivery_lat: _deliveryLat, delivery_lng: _deliveryLng
    };
    const res = await api('schedule_order', {scheduled_at: scheduledAt, order_template: template});
    if (btn) btn.disabled = false;
    if (res && res.success) {
        _promo = null; _usePoints = false; _tip = 0; _tipCustomOpen = false; _loyalty = null; cart = []; selectedZone = ''; _deliveryAddr = ''; _deliveryLat = null; _deliveryLng = null; _deliveryAC = null; _deliveryPhotoUrl = ''; _scheduleEnabled = false;
        updateCartCount();
        closeCart();
        showToast('Order scheduled for ' + scheduledAt, 'ok');
        setTimeout(function(){ switchCategory('orders'); }, 1000);
    } else {
        showCoMsg((res && res.message) || 'Could not schedule order. Please try again.');
    }
}

async function placeOrderThenPay(zone, name, phone, notes, tbPhone) {
    showCoMsg('Creating order…', '#888');
    const btn = document.getElementById('checkout-btn');
    if (btn) btn.disabled = true;

    const orderRes = await api('customer_create_order', {
            promo_code: _promo ? _promo.code : '',
            use_points: _usePoints ? 1 : 0, tip: _tip,
        items: cart.map(function(c){ return {item_id:c.item_id, quantity:c.quantity}; }),
        to_zone: zone, recipient_name: name, recipient_phone: phone, notes: notes,
        payment_method: 'telebirr',
        delivery_tier: selectedTier,
        delivery_photo_url: _deliveryPhotoUrl,
        delivery_lat: _deliveryLat, delivery_lng: _deliveryLng
    });

    if (!orderRes || !orderRes.success) {
        if (btn) btn.disabled = false;
        showCoMsg((orderRes && orderRes.message) || 'Order failed. Please try again.');
        return;
    }

    currentOrderId = orderRes.data.order_id;
    const total = orderRes.data.total;
    const fullPhone = '+251' + tbPhone.replace(/^0/, '');

    showCoMsg('Initiating Telebirr payment…', '#888');

    const payRes = await api('initiate_telebirr_payment', {
        order_id: currentOrderId,
        amount:   total,
        phone:    fullPhone,
    });

    if (btn) btn.disabled = false;

    if (payRes && payRes.success) {
        currentPaymentId = payRes.data.payment_id;
        document.getElementById('telebirr-status').style.display = 'block';
        document.getElementById('telebirr-form').style.display   = 'none';
        document.getElementById('co-msg').style.display          = 'none';
        if (btn) btn.style.display = 'none';
        startTelebirrPolling(currentPaymentId, orderRes.data.order_code);
        _promo = null; _usePoints = false; _tip = 0; _tipCustomOpen = false; _loyalty = null; cart = []; selectedZone = ''; _deliveryAddr = ''; _deliveryLat = null; _deliveryLng = null; _deliveryAC = null; _deliveryPhotoUrl = '';
        updateCartCount();
    } else {
        showCoMsg('Telebirr error: ' + ((payRes && payRes.message) || 'Unknown error. Try Cash payment.'));
    }
}

// Chapa: create the order, then hand the customer to Chapa's hosted page.
// chapa-return.php verifies the payment and sends them back to this app.
async function placeOrderThenChapa(zone, name, phone, notes) {
    showCoMsg('Creating order…', '#888');
    const btn = document.getElementById('checkout-btn');
    if (btn) btn.disabled = true;

    const orderRes = await api('customer_create_order', {
        promo_code: _promo ? _promo.code : '',
        use_points: _usePoints ? 1 : 0, tip: _tip,
        items: cart.map(function(c){ return {item_id:c.item_id, quantity:c.quantity}; }),
        to_zone: zone, recipient_name: name, recipient_phone: phone, notes: notes,
        payment_method: 'chapa',
        delivery_tier: selectedTier,
        delivery_photo_url: _deliveryPhotoUrl,
        delivery_lat: _deliveryLat, delivery_lng: _deliveryLng
    });
    if (!orderRes || !orderRes.success) {
        if (btn) btn.disabled = false;
        showCoMsg((orderRes && orderRes.message) || 'Order failed. Please try again.');
        return;
    }

    currentOrderId = orderRes.data.order_id;
    const orderCode = orderRes.data.order_code;
    // The order exists now, so the cart is spent whatever happens next.
    _promo = null; _usePoints = false; _tip = 0; _tipCustomOpen = false; _loyalty = null; cart = []; selectedZone = ''; _deliveryAddr = ''; _deliveryLat = null; _deliveryLng = null; _deliveryAC = null; _deliveryPhotoUrl = '';
    updateCartCount();

    showCoMsg('Opening Chapa…', '#888');
    const payRes = await api('chapa_init', { kind: 'order', id: currentOrderId });
    if (payRes && payRes.success && payRes.data && payRes.data.checkout_url) {
        window.location.href = payRes.data.checkout_url;
        return;
    }
    if (btn) btn.disabled = false;
    closeCart();
    showToast('Order ' + orderCode + ' placed, but Chapa could not start: ' + ((payRes && payRes.message) || 'try again') + '. You can pay when it arrives.', 'err');
    setTimeout(function(){ switchCategory('orders'); }, 1200);
}

function startTelebirrPolling(paymentId, orderCode) {
    let attempts = 0;
    const maxAttempts = 24; // 24 × 5s = 120s
    const counter = document.getElementById('tb-counter');

    if (telebirrPollTimer) clearInterval(telebirrPollTimer);
    telebirrPollTimer = setInterval(async function() {
        attempts++;
        if (counter) counter.textContent = Math.max(0, 120 - attempts * 5);

        const res = await api('check_telebirr_payment', { payment_id: paymentId });
        if (res && res.success && res.data && res.data.status === 'completed') {
            clearInterval(telebirrPollTimer);
            showToast('✅ Payment confirmed! Order ' + orderCode + ' is active.');
            closeCart();
            setTimeout(function(){ switchCategory('orders'); }, 1500);
        } else if (attempts >= maxAttempts) {
            clearInterval(telebirrPollTimer);
            document.getElementById('tb-poll-msg').textContent = 'Timeout. Please check orders or contact support.';
            closeCart();
            showToast('Order placed: ' + orderCode + '. Payment pending — dial *150# to complete.', 'err');
            switchCategory('orders');
        }
    }, 5000);
}

async function placeOrderThenBank(zone, name, phone, notes, reference) {
    showCoMsg('Creating order…', '#888');
    const btn = document.getElementById('checkout-btn');
    if (btn) btn.disabled = true;

    const orderRes = await api('customer_create_order', {
            promo_code: _promo ? _promo.code : '',
            use_points: _usePoints ? 1 : 0, tip: _tip,
        items: cart.map(function(c){ return {item_id:c.item_id, quantity:c.quantity}; }),
        to_zone: zone, recipient_name: name, recipient_phone: phone, notes: notes,
        payment_method: selectedPayment,
        delivery_tier: selectedTier,
        delivery_photo_url: _deliveryPhotoUrl,
        delivery_lat: _deliveryLat, delivery_lng: _deliveryLng
    });

    if (!orderRes || !orderRes.success) {
        if (btn) btn.disabled = false;
        showCoMsg((orderRes && orderRes.message) || 'Order failed.');
        return;
    }

    currentOrderId = orderRes.data.order_id;
    const bankRes = await api('confirm_bank_payment', {
        order_id: currentOrderId,
        bank: selectedPayment,
        amount: orderRes.data.total,
        reference_no: reference,
    });

    if (btn) btn.disabled = false;
    _promo = null; _usePoints = false; _tip = 0; _tipCustomOpen = false; _loyalty = null; cart = []; selectedZone = ''; _deliveryAddr = ''; _deliveryLat = null; _deliveryLng = null; _deliveryAC = null; _deliveryPhotoUrl = '';
    updateCartCount();
    closeCart();
    if (bankRes && bankRes.success) {
        showToast('Order placed! Ref: ' + reference + '. Verified within 1-2 hours.');
    } else {
        // Order itself was created fine - only the payment-reference
        // record failed to save. Don't tell the customer "verified
        // within 1-2 hours" when there's nothing on file to verify
        // against; support needs the real reference number instead.
        showToast('Order placed, but we could not record your payment reference (' +
            ((bankRes && bankRes.message) || 'unknown error') +
            '). Please contact support with reference: ' + reference, 'err');
    }
    setTimeout(function(){ switchCategory('orders'); }, 1500);
}
function showCoMsg(text, color) {
    const msg = document.getElementById('co-msg');
    if (!msg) return;
    msg.style.display = 'block';
    msg.style.color = color || '#e63946';
    msg.textContent = text;
}

// ── ORDERS ──────────────────────────────────────────────────────
async function loadOrders() {
    document.getElementById('orders-list').innerHTML = UI.loading('Loading your orders...');
    checkDeliveryConfirmations(); // Check for pending delivery confirmation requests
    loadScheduledOrders();
    const res = await apiGet('customer_get_orders', {});
    if (!res.success) {
        document.getElementById('orders-list').innerHTML = '<div style="padding:20px;color:#e63946;font-size:13px">Failed to load orders.</div>';
        return;
    }
    const orders = res.data.orders;
    if (!orders.length) {
        document.getElementById('orders-list').innerHTML =
            '<div style="text-align:center;padding:60px 20px;color:rgba(247,241,223,.55)">' +
            '<div style="font-size:48px;margin-bottom:16px">📦</div>' +
            '<div style="font-size:16px;font-weight:600;color:rgba(247,241,223,.55);margin-bottom:8px">No orders yet</div>' +
            '<div style="font-size:13px">Start browsing to place your first order!</div>' +
            '<button onclick="switchCategory(\'home\')" style="margin-top:20px;padding:12px 24px;background:#D4AF37;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer">Browse Items</button>' +
            '</div>';
        return;
    }
    const sc = {pending:'#FF7A45',assigned:'#4ECDC4',picked_up:'#9B59B6',in_transit:'#9B59B6',delivered:'#2ECC71',cancelled:'#E63946'};
    document.getElementById('orders-list').innerHTML = orders.map(function(o) {
        const col = sc[o.status] || '#999';
        const isDelivered = o.status === 'delivered';
        const progressSteps = ['pending','assigned','picked_up','in_transit','delivered'];
        const curStep = progressSteps.indexOf(o.status);
        const progressPct = curStep < 0 ? 0 : Math.round((curStep + 1) / progressSteps.length * 100);
        const statusLabel = {'pending':'Order placed','assigned':'Assigned','picked_up':'Picked up','in_transit':'On the way','delivered':'Delivered','cancelled':'Cancelled'}[o.status] || o.status;
        const payBadge = o.payment_method ? '<span style="padding:2px 7px;border-radius:4px;font-size:10px;background:rgba(255,255,255,.1);color:#e8e2d4;margin-left:6px">' + o.payment_method.toUpperCase() + '</span>' : '';
        return '<div style="background:rgba(255,255,255,.03);border-radius:14px;padding:16px;margin-bottom:14px;box-shadow:0 2px 10px rgba(0,0,0,.07)">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">' +
            '<div><span style="font-weight:700;font-size:14px;color:#F7F1DF">' + o.order_code + '</span>' + payBadge + '</div>' +
            '<span style="padding:4px 10px;border-radius:6px;font-size:11px;font-weight:700;background:' + col + '22;color:' + col + '">' + statusLabel.toUpperCase() + '</span></div>' +
            '<div style="font-size:12px;color:rgba(247,241,223,.55);margin-bottom:8px">📍 ' + escapeHtml(o.to_zone||'—') + ' · 👤 ' + escapeHtml(o.recipient_name||'—') + '</div>' +
            (o.status !== 'cancelled' && o.status !== 'delivered' ? '<div style="margin-bottom:10px"><div style="background:rgba(255,255,255,.1);border-radius:6px;height:5px;overflow:hidden"><div style="background:linear-gradient(90deg,#D4AF37,#C8960C);height:100%;width:'+progressPct+'%;transition:width .4s"></div></div><div style="font-size:10px;color:rgba(247,241,223,.55);margin-top:3px">' + statusLabel + '</div></div>' : '') +
            '<div style="display:flex;justify-content:space-between;align-items:center;font-size:12px;color:rgba(247,241,223,.55)">' +
            '<span>' + fmtDate(o.placed_at) + '</span>' +
            '<span style="font-weight:700;color:#D4AF37;font-size:14px">ETB ' + parseFloat(o.fare_total||0).toFixed(2) + '</span></div>' +
            (o.status !== 'cancelled' && o.status !== 'delivered' ? '<button onclick="openTrackOrder('+o.id+')" style="margin-top:10px;width:100%;padding:8px;background:rgba(212,175,55,.12);border:1.5px solid rgba(212,175,55,.35);border-radius:8px;color:#F0CA58;font-size:12px;font-weight:700;cursor:pointer">🛵 Track Order</button>' : '') +
            (o.status !== 'cancelled' && o.status !== 'delivered' && window.EtelOrderChat ? '<button onclick="EtelOrderChat.open('+o.id+')" style="margin-top:8px;width:100%;padding:8px;background:transparent;border:1.5px solid rgba(212,175,55,.35);border-radius:8px;color:#F0CA58;font-size:12px;font-weight:700;cursor:pointer">' + escapeHtml(ETEL_I18N.t('chat_btn_short')) + '</button>' : '') +
            ((isDelivered || o.status === 'cancelled') ? '<button onclick="reorderOrder('+o.id+')" class="reorder-btn" style="margin-top:10px;width:100%;padding:9px;background:linear-gradient(135deg,#F0CA58,#D4AF37);border:none;border-radius:8px;color:#1a1400;font-size:12.5px;font-weight:800;cursor:pointer">🔁 ' + escapeHtml(ETEL_I18N.t('reorder_btn')) + '</button>' : '') +
            (isDelivered ? (o.my_rating ? '<div style="margin-top:10px;text-align:center;font-size:12.5px;color:#F0CA58">' + '★'.repeat(o.my_rating) + '☆'.repeat(5 - o.my_rating) + ' <span style="opacity:.7">' + escapeHtml(ETEL_I18N.t('rate_yours')) + '</span></div>' : '<button onclick="openRating('+o.id+')" style="margin-top:10px;width:100%;padding:8px;background:#fff;border:1.5px solid #D4AF37;border-radius:8px;color:#D4AF37;font-size:12px;font-weight:700;cursor:pointer">⭐ Rate this order</button>') : '') +
            (o.status !== 'cancelled' ? '<button onclick="openOrderIssue('+o.id+',\''+o.order_code+'\')" style="margin-top:8px;width:100%;padding:8px;background:#fff;border:1.5px solid #B8B0A0;border-radius:8px;color:rgba(247,241,223,.5);font-size:12px;font-weight:700;cursor:pointer">⚠️ Report an issue</button>' : '') +
            '</div>';
    }).join('');
}

// ── COURIER QR SCAN (fast path to the delivery-confirmation banner) ──
// The scan never auto-confirms payment itself -- cash still requires the
// checkbox, wallet still requires the PIN -- it just jumps the customer
// straight to their real pending confirmation instead of them hunting for it.
var _custQrScanning = false;
function _custQrExtractOrderId(text) {
    try {
        var obj = JSON.parse(text);
        if (obj && obj.order_id) return parseInt(obj.order_id);
    } catch (e) {}
    var m = String(text).match(/order_id["\s:=]+(\d+)/) || String(text).match(/(\d{2,})/);
    return m ? parseInt(m[1]) : null;
}

async function openCourierQRScanner() {
    document.getElementById('courier-qr-overlay').style.display = 'flex';
    document.getElementById('courier-qr-result').textContent = '';
    var video = document.getElementById('courier-qr-video');
    var canvas = document.getElementById('courier-qr-canvas');
    var ctx = canvas.getContext('2d', { willReadFrequently: true });

    try {
        var stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
        video.srcObject = stream;
        await video.play();
    } catch (e) {
        document.getElementById('courier-qr-result').textContent = cameraAccessMessage(e);
        return;
    }

    _custQrScanning = true;
    requestAnimationFrame(_custQrTick);

    function _custQrTick() {
        if (!_custQrScanning) return;
        if (video.readyState === video.HAVE_ENOUGH_DATA) {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
            var code = window.jsQR ? jsQR(img.data, img.width, img.height) : null;
            if (code && code.data) {
                _custQrScanning = false;
                (video.srcObject.getTracks() || []).forEach(function (t) { t.stop(); });
                _custQrHandleDecoded(code.data);
                return;
            }
        }
        requestAnimationFrame(_custQrTick);
    }
}

function closeCourierQRScanner() {
    _custQrScanning = false;
    var video = document.getElementById('courier-qr-video');
    if (video.srcObject) (video.srcObject.getTracks() || []).forEach(function (t) { t.stop(); });
    document.getElementById('courier-qr-overlay').style.display = 'none';
}

async function _custQrHandleDecoded(text) {
    var resultEl = document.getElementById('courier-qr-result');
    var orderId = _custQrExtractOrderId(text);
    if (!orderId) {
        resultEl.textContent = '❌ Could not read an order from that QR code.';
        return;
    }
    resultEl.textContent = 'Looking up order #' + orderId + '…';
    await checkDeliveryConfirmations();
    var banner = document.getElementById('cust-delivery-banner');
    var found = banner && banner.style.display !== 'none' && banner.innerHTML.indexOf('cust-pay-conf-' + orderId) !== -1
        || (banner && banner.innerHTML.indexOf('wallet-pay-status-' + orderId) !== -1);
    closeCourierQRScanner();
    switchCategory('orders');
    if (found) {
        showToast('Order found — confirm below to complete it.');
        setTimeout(function () { var b = document.getElementById('cust-delivery-banner'); if (b) b.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 300);
    } else {
        showToast('Order not ready to confirm yet — ask your courier to try again in a moment.', 'err');
    }
}

// ── DELIVERY CONFIRMATION (customer confirms courier delivery) ────
var _lastConfs = [];
var _confirmPayMethod = {}; // orderId -> 'cash' | 'wallet', chosen at the confirmation moment

async function checkDeliveryConfirmations() {
    const banner = document.getElementById('cust-delivery-banner');
    if (!banner) return;
    try {
        const res = await apiGet('customer_pending_confirmations', {});
        if (!res.success || !res.data.confirmations || !res.data.confirmations.length) {
            banner.style.display = 'none';
            _lastConfs = [];
            return;
        }
        _lastConfs = res.data.confirmations;
        renderConfirmBanner();
    } catch(e) { /* ignore */ }
}

function renderConfirmBanner() {
    const banner = document.getElementById('cust-delivery-banner');
    if (!banner || !_lastConfs.length) return;
    banner.style.display = 'block';
    banner.innerHTML = _lastConfs.map(function(c) {
        if (!(c.order_id in _confirmPayMethod)) {
            _confirmPayMethod[c.order_id] = (c.payment_method === 'wallet') ? 'wallet' : 'cash';
        }
        return renderConfirmCard(c);
    }).join('');
}

function renderConfirmCard(c) {
    const orderId = c.order_id;
    const method  = _confirmPayMethod[orderId] || 'cash';
    const fare    = parseFloat(c.fare_total || 0);

    const methodToggle =
        '<div style="display:flex;gap:6px;margin-bottom:12px">' +
        '<button onclick="setConfirmPayMethod(' + orderId + ',\'cash\')" style="flex:1;padding:8px;border-radius:8px;border:1.5px solid ' + (method === 'cash' ? '#fff' : 'rgba(255,255,255,.35)') + ';background:' + (method === 'cash' ? 'rgba(255,255,255,.22)' : 'transparent') + ';color:#fff;font-size:12px;font-weight:700;cursor:pointer">💵 Cash</button>' +
        '<button onclick="setConfirmPayMethod(' + orderId + ',\'wallet\')" style="flex:1;padding:8px;border-radius:8px;border:1.5px solid ' + (method === 'wallet' ? '#fff' : 'rgba(255,255,255,.35)') + ';background:' + (method === 'wallet' ? 'rgba(255,255,255,.22)' : 'transparent') + ';color:#fff;font-size:12px;font-weight:700;cursor:pointer">💰 ኢE Pay</button>' +
        '</div>';

    const actionBlock = method === 'wallet'
        ? '<button onclick="customerPayWalletOrder(' + orderId + ',' + fare + ')" style="width:100%;padding:10px;background:rgba(255,255,255,.92);color:#27ae60;border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer">💰 Pay ETB ' + fare.toFixed(2) + ' with ኢE Pay</button>'
        : '<div style="background:rgba(255,255,255,.2);border-radius:8px;padding:10px 12px;margin-bottom:12px;font-size:12px">' +
          '<label style="display:flex;align-items:center;gap:8px;cursor:pointer">' +
          '<input type="checkbox" id="cust-pay-conf-' + orderId + '" style="width:16px;height:16px;cursor:pointer">' +
          '<span>I confirm I paid <strong>ETB ' + fare.toFixed(2) + '</strong> cash to the courier</span>' +
          '</label></div>' +
          '<button onclick="customerConfirmDelivery(' + orderId + ',1)" style="width:100%;padding:10px;background:rgba(255,255,255,.92);color:#27ae60;border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer">✅ Confirm I Received My Order</button>';

    return '<div style="background:linear-gradient(135deg,#2ECC71,#27ae60);color:#fff;border-radius:16px;padding:20px;margin-bottom:12px;box-shadow:0 4px 20px rgba(46,204,113,.35)">' +
        '<div style="font-size:14px;font-weight:700;margin-bottom:4px">🚪 Your order has been delivered!</div>' +
        '<div style="font-size:13px;opacity:.9;margin-bottom:2px">Order <strong>' + escapeHtml(c.order_code) + '</strong> · ETB ' + fare.toFixed(2) + '</div>' +
        '<div style="font-size:12px;opacity:.8;margin-bottom:14px">Courier: ' + escapeHtml(c.courier_name || 'Courier') + '</div>' +
        methodToggle +
        '<div id="wallet-pay-status-' + orderId + '" style="font-size:12px;margin-bottom:8px;min-height:14px"></div>' +
        actionBlock +
        '</div>';
}

function setConfirmPayMethod(orderId, method) {
    _confirmPayMethod[orderId] = method;
    renderConfirmBanner();
}

async function customerConfirmDelivery(orderId, hasCashPayment) {
    let paymentConfirmed = 0;
    if (hasCashPayment) {
        const cb = document.getElementById('cust-pay-conf-' + orderId);
        if (cb && !cb.checked) {
            showToast('Please confirm cash payment to proceed', 'err');
            return;
        }
        paymentConfirmed = 1;
    } else {
        paymentConfirmed = 1; // Non-cash payments are auto-confirmed
    }
    const res = await api('customer_confirm_delivery', { order_id: orderId, payment_confirmed: paymentConfirmed, confirm_payment_method: 'cash' });
    if (res && res.success) {
        const conf = _lastConfs.find(function(c){ return c.order_id === orderId; });
        document.getElementById('cust-delivery-banner').style.display = 'none';
        delete _confirmPayMethod[orderId];
        loadOrders();
        showOrderDeliveredScreen(orderId, conf);
    } else {
        showToast((res && res.message) || 'Confirmation failed', 'err');
    }
}

// ── ORDER DELIVERED — full-screen success state, shown from the same
// real confirmation data already fetched above (order_code, fare_total,
// courier_name) - no fabricated order content. ──
function showOrderDeliveredScreen(orderId, conf) {
    const fare = conf ? parseFloat(conf.fare_total || 0) : 0;
    document.getElementById('order-delivered-summary').innerHTML =
        infoRow('🧾', 'Order', (conf && conf.order_code) || ('#' + orderId)) +
        (conf && conf.courier_name ? infoRow('🛵', 'Courier', conf.courier_name) : '') +
        infoRow('💰', 'Total', 'ETB ' + fare.toFixed(2));
    const rateBtn = document.getElementById('order-delivered-rate-btn');
    rateBtn.onclick = function() { closeOrderDeliveredScreen(); openRating(orderId); };
    document.getElementById('view-order-delivered').style.display = 'flex';
}
function closeOrderDeliveredScreen() { document.getElementById('view-order-delivered').style.display = 'none'; }

// Etel Pay's own live API -- the customer's PIN goes straight from this
// browser to Etel Pay, never through delivery's backend.
const ETEL_PAY_API = 'https://ethelmarket.com/Etel.Pay/wallet/api/v2_verify.php';

async function customerPayWalletOrder(orderId, fareTotal) {
    const statusEl = document.getElementById('wallet-pay-status-' + orderId);

    /* If a previous attempt for this order already moved real money but
       failed to confirm, do NOT send a second transfer -- retry confirming
       the transfer that already happened instead. Only cleared once
       confirmation truly succeeds. */
    const pendingKey = 'etel_pending_wallet_tx_order_' + orderId;
    const pendingTx = localStorage.getItem(pendingKey);
    if (pendingTx) {
        if (statusEl) statusEl.textContent = 'Re-checking your previous payment…';
        try {
            const res = await api('customer_confirm_delivery', { order_id: orderId, payment_confirmed: 1, confirm_payment_method: 'wallet', wallet_tx_ref: pendingTx });
            if (res && res.success) {
                localStorage.removeItem(pendingKey);
                showToast('Paid ETB ' + parseFloat(fareTotal).toFixed(2) + ' with ኢE Pay — Thank you! 🎉');
                document.getElementById('cust-delivery-banner').style.display = 'none';
                delete _confirmPayMethod[orderId];
                loadOrders();
            } else {
                // Do NOT clear pendingKey -- the transfer already happened.
                throw new Error((res && res.message) || 'Payment could not be confirmed. Your money is safe — contact support.');
            }
        } catch (err) {
            if (statusEl) statusEl.textContent = '';
            showToast(err.message || 'Could not confirm payment. Tap Pay again to retry — you will not be charged twice.', 'err');
        }
        return;
    }

    let user = {};
    try { user = JSON.parse(localStorage.getItem('etel_user') || '{}'); } catch (e) {}
    const phone = user.phone || '';
    if (!phone) { showToast('Could not find your phone number.', 'err'); return; }

    const pin = prompt('Enter your ኢE Pay PIN to pay ETB ' + parseFloat(fareTotal).toFixed(2));
    if (!pin) return;

    if (statusEl) statusEl.textContent = 'Looking up payment account…';
    try {
        // Merchant phone is resolved live from Etel-ID's company admin
        // link, not a hardcoded number -- change it by editing that one
        // row in Etel-ID, not by redeploying this file.
        const merchRes = await apiGet('get_etelpay_merchant', {});
        if (!(merchRes.success || merchRes.ok) || !merchRes.data || !merchRes.data.phone) {
            throw new Error((merchRes.data && merchRes.data.message) || merchRes.error || 'Etel Pay is not set up yet for this app.');
        }
        const merchantPhone = merchRes.data.phone;

        if (statusEl) statusEl.textContent = 'Connecting to ኢE Pay…';
        const loginRes = await fetch(ETEL_PAY_API + '?action=ep_login_etelid', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'ep_login_etelid', phone: phone, pin: pin }),
        }).then(r => r.json());
        if (!(loginRes.success || loginRes.ok) || !loginRes.data || !loginRes.data.token) {
            throw new Error((loginRes.data && loginRes.data.message) || loginRes.error || 'ኢE Pay sign-in failed. Check your PIN.');
        }

        if (statusEl) statusEl.textContent = 'Sending payment…';
        const sendRes = await fetch(ETEL_PAY_API + '?action=ep_send', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + loginRes.data.token },
            body: JSON.stringify({ action: 'ep_send', receiver: merchantPhone, amount: fareTotal, currency: 'ETB', note: 'ETEL Delivery order #' + orderId }),
        }).then(r => r.json());
        if (!(sendRes.success || sendRes.ok) || !sendRes.data || !sendRes.data.tx_ref) {
            throw new Error((sendRes.data && sendRes.data.message) || sendRes.error || 'ኢE Pay transfer failed.');
        }
        /* Real money has now left the wallet. Remember the tx_ref BEFORE
           attempting to confirm it -- if confirmation fails or the network
           drops right here, the next attempt must retry confirming THIS
           transfer, never send a second one. */
        localStorage.setItem(pendingKey, sendRes.data.tx_ref);

        if (statusEl) statusEl.textContent = 'Confirming with ETEL Delivery…';
        const res = await api('customer_confirm_delivery', { order_id: orderId, payment_confirmed: 1, confirm_payment_method: 'wallet', wallet_tx_ref: sendRes.data.tx_ref });
        if (res && res.success) {
            localStorage.removeItem(pendingKey);
            showToast('Paid ETB ' + parseFloat(fareTotal).toFixed(2) + ' with ኢE Pay — Thank you! 🎉');
            document.getElementById('cust-delivery-banner').style.display = 'none';
            delete _confirmPayMethod[orderId];
            loadOrders();
        } else {
            // Do NOT clear pendingKey -- the transfer already happened.
            throw new Error((res && res.message) || 'Payment could not be confirmed. Your money is safe — contact support.');
        }
    } catch (err) {
        if (statusEl) statusEl.textContent = '';
        showToast(err.message || 'ኢE Pay payment failed.', 'err');
    }
}

/* Order tracking SSE — connects when customer opens order detail */
function startOrderSSE(orderId) {
    if (window._orderSSE) { window._orderSSE.close(); window._orderSSE = null; }
    var token = (window.ETEL && ETEL.auth && ETEL.auth.token) ? ETEL.auth.token : (localStorage.getItem('etel_token') || '');
    var url = (typeof API_BASE !== 'undefined' ? API_BASE : 'api/boot.php') +
              '?action=sse_order_track&id=' + orderId + '&token=' + encodeURIComponent(token);
    try {
        var es = new EventSource(url);
        window._orderSSE = es;
        es.onmessage = function(e) {
            try {
                var d = JSON.parse(e.data);
                /* Update order status badge if visible */
                var badge = document.getElementById('order-status-' + orderId);
                if (badge && d.status) badge.textContent = d.status;
                /* Update courier location on map if tracking map open */
                if (d.courier_lat && window._trackMap) {
                    var pos = { lat: d.courier_lat, lng: d.courier_lng };
                    if (window._courierMarker) {
                        window._courierMarker.setPosition(pos);
                    }
                }
                if (d.status === 'delivered' || d.status === 'cancelled') {
                    es.close(); window._orderSSE = null;
                    checkDeliveryConfirmations();
                }
            } catch(ex) {}
        };
        es.onerror = function() {
            es.close(); window._orderSSE = null;
        };
    } catch(ex) {}
}

/* Fallback: poll every 12s when on orders page (SSE handles active tracking) */
setInterval(function() {
    var ordersView = document.getElementById('view-orders');
    if (ordersView && ordersView.style.display !== 'none') {
        checkDeliveryConfirmations();
    }
}, 12000);

// ── ORDER RATING ─────────────────────────────────────────────────
// ── RATE AN ORDER: overall (required) + order quality + courier (optional), quick tags, comment.
// Tag keys are whitelisted on the server (api/lib/reviews.php).
const REVIEW_TAGS_POS = ['fast_delivery', 'great_taste', 'well_packed', 'friendly_courier', 'good_value'];
const REVIEW_TAGS_NEG = ['late', 'cold', 'wrong_item'];
let _rate = {overall: 0, food: 0, courier: 0, tags: []};
function _rateStarRow(key, label, required) {
    return '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">' +
        '<span style="font-size:13px;font-weight:700;color:var(--text)">' + escapeHtml(label) + (required ? '' : ' <span style="font-weight:500;opacity:.5;font-size:11px">(optional)</span>') + '</span>' +
        '<span class="rate-stars" data-row="' + key + '" style="display:flex;gap:4px;font-size:26px">' +
        [1,2,3,4,5].map(function(n){ return '<span role="button" onclick="setRateStar(\'' + key + '\',' + n + ')" data-n="' + n + '" style="cursor:pointer;opacity:.28;transition:opacity .15s">⭐</span>'; }).join('') +
        '</span></div>';
}
function openRating(orderId) {
    _rate = {overall: 0, food: 0, courier: 0, tags: []};
    const tagBtn = function(k) {
        return '<button type="button" class="rate-tag" data-tag="' + k + '" onclick="toggleRateTag(\'' + k + '\')" style="padding:7px 11px;border-radius:999px;border:1.5px solid rgba(212,175,55,.3);background:transparent;color:var(--text);font-size:12px;font-weight:600;cursor:pointer">' + escapeHtml(ETEL_I18N.t('tag_' + k)) + '</button>';
    };
    openModal(
        '<h3 style="margin:0 0 14px;color:var(--text)">⭐ ' + escapeHtml(ETEL_I18N.t('rate_title')) + '</h3>' +
        _rateStarRow('overall', ETEL_I18N.t('rate_overall'), true) +
        _rateStarRow('food', ETEL_I18N.t('rate_food'), false) +
        _rateStarRow('courier', ETEL_I18N.t('rate_courier'), false) +
        '<div style="font-size:12px;font-weight:700;margin:12px 0 8px;color:var(--text)">' + escapeHtml(ETEL_I18N.t('rate_tags')) + '</div>' +
        '<div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px">' + REVIEW_TAGS_POS.concat(REVIEW_TAGS_NEG).map(tagBtn).join('') + '</div>' +
        '<textarea id="rating-comment" maxlength="500" placeholder="' + escapeHtml(ETEL_I18N.t('rate_comment')) + '" style="width:100%;padding:10px;border:1.5px solid rgba(212,175,55,.25);border-radius:8px;font-size:13px;min-height:64px;resize:none;box-sizing:border-box;margin-bottom:12px"></textarea>' +
        '<button onclick="submitRating(' + orderId + ')" style="width:100%;padding:12px;background:#D4AF37;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer">' + escapeHtml(ETEL_I18N.t('rate_submit')) + '</button>'
    );
}
function setRateStar(key, n) {
    _rate[key] = (_rate[key] === n && key !== 'overall') ? 0 : n;   // tap the same star again to clear an optional row
    document.querySelectorAll('.rate-stars[data-row="' + key + '"] [data-n]').forEach(function(el) {
        el.style.opacity = parseInt(el.dataset.n, 10) <= _rate[key] ? '1' : '.28';
    });
}
function toggleRateTag(k) {
    const i = _rate.tags.indexOf(k);
    if (i === -1) _rate.tags.push(k); else _rate.tags.splice(i, 1);
    const btn = document.querySelector('.rate-tag[data-tag="' + k + '"]');
    if (btn) { const on = i === -1; btn.style.background = on ? 'rgba(212,175,55,.25)' : 'transparent'; btn.style.borderColor = on ? '#D4AF37' : 'rgba(212,175,55,.3)'; }
}
async function submitRating(orderId) {
    if (!_rate.overall) { showToast(ETEL_I18N.t('rate_pick'), 'err'); return; }
    const comment = (document.getElementById('rating-comment') || {}).value || '';
    const res = await api('rate_order', {order_id: orderId, stars: _rate.overall, food_rating: _rate.food || null, courier_rating: _rate.courier || null, tags: _rate.tags, comment: comment.trim()});
    if (res && res.success) {
        showToast(ETEL_I18N.t('rate_thanks'));
        closeModal();
        _rate = {overall: 0, food: 0, courier: 0, tags: []};
        if (currentView === 'orders') loadOrders();
    } else {
        showToast((res && res.message) || 'Rating failed', 'err');
    }
}

// ── ORDER ISSUE / DISPUTE REPORT ───────────────────────────────────
const ORDER_ISSUE_CATEGORIES = [
    ['item_missing', 'Item missing from order'],
    ['item_damaged', 'Item arrived damaged'],
    ['wrong_item', 'Wrong item delivered'],
    ['late_delivery', 'Delivery was very late'],
    ['never_arrived', 'Order never arrived'],
    ['courier_behavior', 'Courier behavior'],
    ['other', 'Other']
];
function openOrderIssue(orderId, orderCode) {
    openModal(
        '<h3 style="margin:0 0 16px;color:#F7F1DF">⚠️ Report an Issue</h3>' +
        '<p style="font-size:13px;color:rgba(247,241,223,.55);margin-bottom:14px">Order ' + orderCode + '</p>' +
        '<select id="oi-category" style="width:100%;padding:10px;border:1.5px solid rgba(212,175,55,.25);border-radius:8px;font-size:13px;box-sizing:border-box;margin-bottom:10px">' +
        ORDER_ISSUE_CATEGORIES.map(function(c) { return '<option value="'+c[0]+'">'+c[1]+'</option>'; }).join('') +
        '</select>' +
        '<textarea id="oi-details" placeholder="Describe what happened…" style="width:100%;padding:10px;border:1.5px solid rgba(212,175,55,.25);border-radius:8px;font-size:13px;min-height:80px;resize:none;box-sizing:border-box;margin-bottom:14px"></textarea>' +
        '<button onclick="submitOrderIssue('+orderId+')" style="width:100%;padding:12px;background:#D4AF37;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer">Submit Report</button>'
    );
}
async function submitOrderIssue(orderId) {
    const category = document.getElementById('oi-category').value;
    const details = document.getElementById('oi-details').value.trim();
    if (!details) { showToast('Please describe what happened', 'err'); return; }
    const res = await api('submit_order_issue', { order_id: orderId, category: category, details: details });
    if (res && res.success) {
        showToast('Thanks — we\'ll review this order issue.');
        closeModal();
    } else {
        showToast((res && res.message) || 'Could not submit report', 'err');
    }
}

// ── WALLET / PAYMENTS VIEW ────────────────────────────────────────
async function loadPayments() {
    const el = document.getElementById('view-payments');
    if (!el) return;
    el.innerHTML = UI.loading('Loading wallet...');
    await loadFeatureFlags(); // re-check in case an admin just flipped it live

    if (!etelPayLive) {
        el.innerHTML =
            '<div style="background:linear-gradient(135deg,#3B3526,#1E1B12);border-radius:16px;padding:24px;margin:16px;color:#fffdf5;text-align:center">' +
            '<div style="font-size:13px;opacity:.85;margin-bottom:8px">💰 ኢE Pay — Ethiopian Wallet</div>' +
            '<div style="font-size:22px;font-weight:700;margin-bottom:10px">🔜 Coming Soon</div>' +
            '<div style="font-size:12.5px;opacity:.9;line-height:1.55">ኢE Pay is built and ready, but is currently pending government approval before it can go live. Cash, Telebirr, and bank transfer are all available now.</div>' +
            '</div>';
        return;
    }

    const res = await api('customer_wallet_history', {});
    const orders = (res && res.success && res.data && res.data.orders) ? res.data.orders : [];
    const totalPaid = orders.reduce(function(sum, o) { return sum + parseFloat(o.fare_total || 0); }, 0);
    el.innerHTML =
        '<div style="background:linear-gradient(135deg,#D4AF37,#C8960C);border-radius:16px;padding:24px;margin:16px;color:#fff;text-align:center">' +
        '<div style="font-size:13px;opacity:.85;margin-bottom:8px">💰 ኢE Pay — Ethiopian Wallet</div>' +
        '<div style="font-size:12.5px;opacity:.9;margin-bottom:16px;line-height:1.55">Pay for orders instantly and cashlessly. Choose ኢE Pay at checkout, or right when your order arrives at the door.</div>' +
        '<a href="https://ethelmarket.com/Etel.Pay/wallet/wallet.html" target="_blank" rel="noopener" style="display:inline-block;padding:11px 22px;background:#fff;color:#C8960C;border-radius:10px;font-size:13px;font-weight:700;text-decoration:none">Open ኢE Pay Wallet ↗</a>' +
        '</div>' +
        '<div style="padding:0 16px 16px">' +
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">' +
        '<h3 style="font-size:14px;color:var(--text,#F7F1DF);margin:0">Paid with ኢE Pay</h3>' +
        (orders.length ? '<span style="font-size:12px;color:rgba(247,241,223,.55)">Total: ETB ' + totalPaid.toFixed(2) + '</span>' : '') +
        '</div>' +
        (orders.length ? orders.map(function(o) {
            return '<div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06)">' +
                '<div><div style="font-size:13px;font-weight:600;color:#F7F1DF">' + o.order_code + '</div>' +
                '<div style="font-size:11px;color:rgba(247,241,223,.55)">' + fmtDate(o.delivered_at || o.placed_at) + '</div></div>' +
                '<span style="font-weight:700;font-size:14px;color:#2ecc71">−ETB ' + parseFloat(o.fare_total).toFixed(2) + '</span></div>';
        }).join('') : '<div style="color:rgba(247,241,223,.55);font-size:13px;padding:20px 0;text-align:center">No ኢE Pay payments yet.<br>Choose ኢE Pay at checkout or when your order arrives.</div>') +
        '</div>';
}

// ── LIGHTWEIGHT LOCAL MODAL (Messages / Addresses forms) ─────────
// customer.html has no #modal container or .modal-box CSS for the
// shared openModal()/closeModal() in core.js to target - that call
// silently no-ops here (the same is true of the pre-existing rating
// modal at submitRating() above, a separate latent issue). Rather than
// touch the shared modal system other pages depend on, this is a
// small self-contained overlay, styled to match the permissions
// onboarding sheet already used elsewhere in this file.
function custShowModal(bodyHtml) {
    custCloseModal();
    const bg = document.createElement('div');
    bg.id = 'cust-modal-bg';
    bg.style.cssText = 'position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:rgba(5,5,10,.7);backdrop-filter:blur(4px);padding:20px';
    bg.addEventListener('click', function(e) { if (e.target === bg) custCloseModal(); });
    const box = document.createElement('div');
    box.style.cssText = 'width:min(440px,100%);max-height:85vh;overflow-y:auto;background:linear-gradient(180deg,#171006,#0a0703);border:1px solid rgba(212,175,55,.25);border-radius:18px;padding:22px;color:#F5EDD8;position:relative;font-family:"Poppins",sans-serif';
    box.innerHTML = bodyHtml + '<button onclick="custCloseModal()" style="position:absolute;top:14px;right:14px;background:none;border:none;color:rgba(245,237,216,.5);font-size:20px;cursor:pointer;line-height:1">✕</button>';
    bg.appendChild(box);
    document.body.appendChild(bg);
}
function custCloseModal() {
    const bg = document.getElementById('cust-modal-bg');
    if (bg) bg.remove();
}

// ── FAVORITES VIEW ──────────────────────────────────────────────
async function loadFavorites() {
    const el = document.getElementById('favorites-list');
    if (!el) return;
    el.innerHTML = UI.loading('Loading your favorites...');
    const res = await apiGet('customer_get_favorites', {});
    if (!res.success) { el.innerHTML = '<div class="empty-state"><div class="empty-icon">⚠️</div><div class="empty-text">Could not load favorites.</div></div>'; return; }
    const favs = res.data.favorites || [];
    if (!favs.length) {
        el.innerHTML = '<div class="empty-state"><div class="empty-icon">⭐</div><div class="empty-text">No favorites yet.<br>Tap the ⭐ on any shop to save it here.</div></div>';
        return;
    }
    const emoji = {restaurant:'🍽️',grocery:'🛒',pharmacy:'💊',laundry:'👕',delivery:'📦',cafe:'☕',fastfood:'🍔',electronics:'📱',bakery:'🧁',general:'🏪'};
    el.innerHTML = favs.map(function(f) {
        const ic = emoji[f.service_type] || '🏪';
        return '<div style="display:flex;align-items:center;gap:14px;padding:14px;margin-bottom:10px;background:rgba(255,255,255,.04);border:1px solid rgba(212,175,55,.15);border-radius:14px;cursor:pointer" onclick="filterBySGAndGoHome(' + f.service_giver_id + ')">' +
            '<div style="width:46px;height:46px;border-radius:12px;background:rgba(255,215,0,.08);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0">' + ic + '</div>' +
            '<div style="flex:1;min-width:0"><div style="font-weight:700;font-size:14px">' + (f.business_name || 'Shop') + '</div>' +
            '<div style="font-size:12px;color:rgba(240,237,216,.5)">' + (f.service_type || '') + ' · ' + (f.zone || 'Hawassa') + '</div></div>' +
            '<button onclick="event.stopPropagation();toggleFavorite(' + f.service_giver_id + ', this)" style="background:none;border:none;font-size:20px;cursor:pointer;color:#FFD700">★</button>' +
        '</div>';
    }).join('');
}
async function toggleFavorite(sgId, btnEl) {
    const res = await api('customer_toggle_favorite', { service_giver_id: sgId });
    if (res && res.success) {
        if (btnEl && btnEl.classList) btnEl.classList.toggle('on', !!res.data.favorited);
        showToast(res.data.favorited ? 'Added to favorites ⭐' : 'Removed from favorites');
        if (currentView === 'favorites') loadFavorites();
    } else {
        showToast((res && res.message) || 'Could not update favorites', 'err');
    }
}
function filterBySGAndGoHome(sgId) {
    switchCategory('home');
    if (itemsCache.length) openShopDetail(sgId);
}

// ── MESSAGES VIEW ───────────────────────────────────────────────
async function loadMessages() {
    const el = document.getElementById('messages-list');
    if (!el) return;
    el.innerHTML = UI.loading('Loading messages...');
    const res = await apiGet('customer_get_messages', {});
    if (!res.success) { el.innerHTML = '<div class="empty-state"><div class="empty-icon">⚠️</div><div class="empty-text">Could not load messages.</div></div>'; return; }
    const msgs = res.data.messages || [];
    if (!msgs.length) {
        el.innerHTML = '<div class="empty-state"><div class="empty-icon">💬</div><div class="empty-text">No messages yet.<br>Order updates and support replies will show up here.</div></div>';
        return;
    }
    const senderIcon = {system:'🔔', support:'🎧', customer:'🧑'};
    el.innerHTML = msgs.map(function(m) {
        return '<div style="display:flex;gap:12px;padding:14px;margin-bottom:10px;background:rgba(255,255,255,.04);border:1px solid rgba(212,175,55,.12);border-radius:14px">' +
            '<div style="font-size:20px;flex-shrink:0">' + (senderIcon[m.sender] || '💬') + '</div>' +
            '<div style="flex:1;min-width:0">' +
            '<div style="display:flex;justify-content:space-between;gap:8px"><div style="font-weight:700;font-size:13.5px">' + escapeHtml(m.subject || 'Message') + '</div>' +
            '<div style="font-size:11px;color:rgba(240,237,216,.4);white-space:nowrap">' + fmtDate(m.created_at) + '</div></div>' +
            '<div style="font-size:13px;color:rgba(240,237,216,.75);margin-top:3px">' + escapeHtml(m.body || '') + '</div></div>' +
        '</div>';
    }).join('');
}
function openContactSupport() {
    custShowModal(contactSupportHtml());
}
function contactSupportHtml() {
    return '<div style="padding:4px">' +
        '<div style="font-weight:700;font-size:15px;margin-bottom:12px">✉️ Contact Support</div>' +
        '<label style="display:block;font-weight:600;margin-bottom:6px;font-size:13px">Subject</label>' +
        '<input id="support-subject" placeholder="What\'s this about?" style="width:100%;padding:10px;border:1.5px solid rgba(212,175,55,.3);background:rgba(0,0,0,.25);color:#fff;border-radius:8px;font-size:13px;margin-bottom:12px;box-sizing:border-box">' +
        '<label style="display:block;font-weight:600;margin-bottom:6px;font-size:13px">Message</label>' +
        '<textarea id="support-body" placeholder="Tell us what\'s going on…" style="width:100%;padding:10px;border:1.5px solid rgba(212,175,55,.3);background:rgba(0,0,0,.25);color:#fff;border-radius:8px;font-size:13px;min-height:100px;resize:none;box-sizing:border-box;margin-bottom:14px"></textarea>' +
        '<button onclick="submitSupportMessage()" style="width:100%;padding:12px;background:#D4AF37;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer">Send to Support</button>' +
    '</div>';
}
function showContactSupportFallback() {
    // Fallback if this build's UI helper has no generic modal - a plain
    // prompt still gets a real message saved, just with less polish.
    const body = prompt('What can we help with?');
    if (body && body.trim()) {
        api('customer_send_message', { subject: 'Support request', body: body.trim() }).then(function(res) {
            if (res && res.success) { showToast('Message sent to support'); if (currentView === 'messages') loadMessages(); }
            else showToast((res && res.message) || 'Could not send message', 'err');
        });
    }
}
async function submitSupportMessage() {
    const subject = (document.getElementById('support-subject') || {}).value || 'Support request';
    const body = (document.getElementById('support-body') || {}).value || '';
    if (!body.trim()) { showToast('Please write a message', 'err'); return; }
    const res = await api('customer_send_message', { subject: subject.trim(), body: body.trim() });
    if (res && res.success) {
        showToast('Message sent to support ✓');
        custCloseModal();
        if (currentView === 'messages') loadMessages();
    } else {
        showToast((res && res.message) || 'Could not send message', 'err');
    }
}

// ── SAVED ADDRESSES VIEW ────────────────────────────────────────
async function loadAddresses() {
    const el = document.getElementById('addresses-list');
    if (!el) return;
    el.innerHTML = UI.loading('Loading your addresses...');
    const res = await apiGet('customer_get_addresses', {});
    if (!res.success) { el.innerHTML = '<div class="empty-state"><div class="empty-icon">⚠️</div><div class="empty-text">Could not load addresses.</div></div>'; return; }
    const addrs = res.data.addresses || [];
    if (!addrs.length) {
        el.innerHTML = '<div class="empty-state"><div class="empty-icon">📍</div><div class="empty-text">No saved addresses yet.<br>Add one for faster checkout.</div></div>';
        return;
    }
    el.innerHTML = addrs.map(function(a) {
        return '<div style="display:flex;align-items:flex-start;gap:12px;padding:14px;margin-bottom:10px;background:rgba(255,255,255,.04);border:1px solid rgba(212,175,55,.15);border-radius:14px">' +
            '<div style="font-size:20px;flex-shrink:0">' + (a.is_default ? '🏠' : '📍') + '</div>' +
            '<div style="flex:1;min-width:0">' +
            '<div style="font-weight:700;font-size:14px">' + escapeHtml(a.label) + (a.is_default ? ' <span style="font-size:10px;color:#FFD700;font-weight:700">· DEFAULT</span>' : '') + '</div>' +
            '<div style="font-size:13px;color:rgba(240,237,216,.7);margin-top:2px">' + escapeHtml(a.address_line) + '</div>' +
            (a.zone ? '<div style="font-size:11.5px;color:rgba(240,237,216,.4);margin-top:2px">' + a.zone + '</div>' : '') +
            '</div>' +
            '<div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0">' +
            '<button onclick="openEditAddressForm(' + a.id + ')" style="background:none;border:1px solid rgba(212,175,55,.3);color:#D4AF37;border-radius:8px;padding:5px 10px;font-size:11px;cursor:pointer">Edit</button>' +
            '<button onclick="deleteAddress(' + a.id + ')" style="background:none;border:1px solid rgba(239,68,68,.3);color:#FCA5A5;border-radius:8px;padding:5px 10px;font-size:11px;cursor:pointer">Delete</button>' +
            '</div>' +
        '</div>';
    }).join('');
}
function addressFormHtml(existing) {
    const a = existing || {};
    return '<div style="padding:4px">' +
        '<div style="font-weight:700;font-size:15px;margin-bottom:12px">' + (a.id ? '📍 Edit Address' : '📍 Add Address') + '</div>' +
        '<input type="hidden" id="addr-id" value="' + (a.id || '') + '">' +
        '<label style="display:block;font-weight:600;margin-bottom:6px;font-size:13px">Label</label>' +
        '<input id="addr-label" placeholder="Home, Work, etc." value="' + escapeHtml(a.label || '') + '" style="width:100%;padding:10px;border:1.5px solid rgba(212,175,55,.3);background:rgba(0,0,0,.25);color:#fff;border-radius:8px;font-size:13px;margin-bottom:12px;box-sizing:border-box">' +
        '<label style="display:block;font-weight:600;margin-bottom:6px;font-size:13px">Address</label>' +
        '<textarea id="addr-line" placeholder="Building, street, landmark…" style="width:100%;padding:10px;border:1.5px solid rgba(212,175,55,.3);background:rgba(0,0,0,.25);color:#fff;border-radius:8px;font-size:13px;min-height:70px;resize:none;box-sizing:border-box;margin-bottom:12px">' + (a.address_line || '') + '</textarea>' +
        '<label style="display:block;font-weight:600;margin-bottom:6px;font-size:13px">Zone (optional)</label>' +
        '<input id="addr-zone" placeholder="e.g. Tabor" value="' + (a.zone || '') + '" style="width:100%;padding:10px;border:1.5px solid rgba(212,175,55,.3);background:rgba(0,0,0,.25);color:#fff;border-radius:8px;font-size:13px;margin-bottom:12px;box-sizing:border-box">' +
        '<label style="display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:14px;cursor:pointer"><input type="checkbox" id="addr-default"' + (a.is_default ? ' checked' : '') + '> Set as default address</label>' +
        '<button onclick="submitAddressForm()" style="width:100%;padding:12px;background:#D4AF37;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer">Save Address</button>' +
    '</div>';
}
function openAddAddressForm() {
    custShowModal(addressFormHtml(null));
}
async function openEditAddressForm(id) {
    const res = await apiGet('customer_get_addresses', {});
    if (!res.success) return;
    const a = (res.data.addresses || []).find(function(x) { return x.id == id; });
    if (!a) { showToast('Address not found', 'err'); return; }
    custShowModal(addressFormHtml(a));
}
async function submitAddressForm() {
    const id = (document.getElementById('addr-id') || {}).value;
    const label = (document.getElementById('addr-label') || {}).value || 'Home';
    const line = (document.getElementById('addr-line') || {}).value || '';
    const zone = (document.getElementById('addr-zone') || {}).value || '';
    const isDefault = (document.getElementById('addr-default') || {}).checked || false;
    if (!line.trim()) { showToast('Please enter an address', 'err'); return; }
    const payload = { label: label.trim(), address_line: line.trim(), zone: zone.trim(), is_default: isDefault };
    const res = id ? await api('customer_update_address', Object.assign({ id: parseInt(id, 10) }, payload))
                   : await api('customer_add_address', payload);
    if (res && res.success) {
        showToast('Address saved ✓');
        custCloseModal();
        loadAddresses();
    } else {
        showToast((res && res.message) || 'Could not save address', 'err');
    }
}
async function deleteAddress(id) {
    if (!confirm('Remove this address?')) return;
    const res = await api('customer_delete_address', { id: id });
    if (res && res.success) { showToast('Address removed'); loadAddresses(); }
    else showToast((res && res.message) || 'Could not delete address', 'err');
}

// ── REFERRAL & REWARDS VIEW ─────────────────────────────────────
async function loadReferralInfo() {
    const el = document.getElementById('referrals-content');
    if (!el) return;
    el.innerHTML = UI.loading('Loading your referral info...');
    const res = await apiGet('customer_get_referral_info', {});
    if (!res.success) { el.innerHTML = '<div class="empty-state"><div class="empty-icon">⚠️</div><div class="empty-text">Could not load referral info.</div></div>'; return; }
    const d = res.data;
    const link = window.location.origin + window.location.pathname.replace(/customer\.html$/, '') + 'login.html?ref=' + encodeURIComponent(d.referral_code);
    el.innerHTML =
        '<div style="padding:20px;background:linear-gradient(160deg,#1C1400,#241A00);border:1px solid rgba(212,175,55,.25);border-radius:16px;margin-bottom:16px;text-align:center">' +
        '<div style="font-size:13px;color:rgba(255,248,231,.6);margin-bottom:8px">Your referral code</div>' +
        '<div style="font-size:26px;font-weight:800;color:#FFD700;letter-spacing:.06em;margin-bottom:14px">' + d.referral_code + '</div>' +
        '<button onclick="copyReferralLink(\'' + link.replace(/'/g, "\\'") + '\')" style="padding:10px 18px;background:linear-gradient(135deg,#FFD700,#D4AF37);color:#1a1206;border:none;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer">📋 Copy Invite Link</button>' +
        '</div>' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">' +
        '<div style="text-align:center;padding:14px;background:rgba(255,215,0,.06);border:1px solid rgba(255,215,0,.1);border-radius:12px"><div style="font-size:20px;font-weight:800;color:#FFD700">' + d.referred_count + '</div><div style="font-size:11px;color:rgba(255,248,231,.5);margin-top:4px">Friends Joined</div></div>' +
        '<div style="text-align:center;padding:14px;background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.15);border-radius:12px"><div style="font-size:20px;font-weight:800;color:#22C55E">ETB ' + d.total_reward_etb + '</div><div style="font-size:11px;color:rgba(255,248,231,.5);margin-top:4px">Earned</div></div>' +
        '</div>' +
        '<div style="font-size:12.5px;color:rgba(240,237,216,.55);text-align:center;padding:0 10px">Share your code — you get <b>ETB ' + d.reward_per_referral_etb + '</b> in credit for every friend who signs up and completes their first order.</div>';
}
function copyReferralLink(link) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(link).then(function() { showToast('Invite link copied ✓'); }, function() { promptCopyFallback(link); });
    } else { promptCopyFallback(link); }
}
function promptCopyFallback(link) { prompt('Copy your invite link:', link); }

// ── NOTIFICATIONS VIEW ──────────────────────────────────────────
// get_my_notifications is real and already returns { unread, notifications }
// from the backend - it just has no producer wired up yet (no
// notifications table/writer exists), so it honestly returns an empty
// list rather than fake sample rows. This view reflects that truthfully.
async function loadNotifications() {
    const el = document.getElementById('notifications-list');
    if (!el) return;
    el.innerHTML = UI.loading('Loading notifications...');
    const res = await apiGet('get_my_notifications', {});
    if (!res || !res.success) {
        el.innerHTML = '<div class="empty-state"><div class="empty-icon">⚠️</div><div class="empty-text">Could not load notifications.</div></div>';
        return;
    }
    const list = (res.data && res.data.notifications) || [];
    if (!list.length) {
        el.innerHTML = '<div class="empty-state"><div class="empty-icon">🔔</div><div class="empty-text">No notifications yet</div>' +
            '<div style="font-size:12px;color:rgba(247,241,223,.4);margin-top:6px">Order updates and offers will show up here</div></div>';
        return;
    }
    const iconFor = {order_placed:'📦', order_preparing:'👨‍🍳', courier_assigned:'🛵', order_delivered:'✅', order_cancelled:'❌', promotion:'🎁'};
    el.innerHTML = list.map(function(n) {
        const ic = iconFor[n.type] || '🔔';
        return '<div style="display:flex;gap:12px;padding:14px;margin-bottom:10px;background:rgba(255,255,255,.04);border:1px solid rgba(212,175,55,.15);border-radius:14px">' +
            '<div style="width:38px;height:38px;flex-shrink:0;border-radius:10px;background:rgba(212,175,55,.1);display:flex;align-items:center;justify-content:center;font-size:17px">' + ic + '</div>' +
            '<div style="flex:1;min-width:0">' +
            '<div style="font-size:13px;font-weight:700;color:var(--text)">' + escapeHtml(n.title || 'Update') + '</div>' +
            '<div style="font-size:12px;color:rgba(247,241,223,.55);margin-top:2px">' + escapeHtml(n.body || '') + '</div>' +
            '<div style="font-size:10.5px;color:rgba(247,241,223,.35);margin-top:6px">' + fmtDate(n.created_at) + '</div>' +
            '</div></div>';
    }).join('');
}

// ── SETTINGS & PRIVACY VIEW ─────────────────────────────────────
async function loadSettingsView() {
    const el = document.getElementById('settings-content');
    if (!el) return;
    el.innerHTML = UI.loading('Loading settings...');
    const res = await apiGet('customer_get_settings', {});
    const s = (res && res.success) ? res.data : { notif_order_updates: true, notif_promotions: true, language: 'en' };
    el.innerHTML =
        '<div style="padding:16px;background:rgba(255,255,255,.04);border:1px solid rgba(212,175,55,.15);border-radius:14px;margin-bottom:14px">' +
        '<div style="font-weight:700;font-size:14px;margin-bottom:12px">🔔 Notifications</div>' +
        '<label style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;font-size:13px;cursor:pointer">Order updates <input type="checkbox" id="set-notif-orders" onchange="saveSettingToggle(\'notif_order_updates\', this.checked)"' + (s.notif_order_updates ? ' checked' : '') + '></label>' +
        '<label style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;font-size:13px;cursor:pointer">Promotions &amp; offers <input type="checkbox" id="set-notif-promos" onchange="saveSettingToggle(\'notif_promotions\', this.checked)"' + (s.notif_promotions ? ' checked' : '') + '></label>' +
        '</div>' +
        '<div style="padding:16px;background:rgba(255,255,255,.04);border:1px solid rgba(212,175,55,.15);border-radius:14px;margin-bottom:14px">' +
        '<div style="font-weight:700;font-size:14px;margin-bottom:12px">🔒 Change Password</div>' +
        '<input type="password" id="set-cur-pw" placeholder="Current password" style="width:100%;padding:10px;border:1.5px solid rgba(212,175,55,.3);background:rgba(0,0,0,.2);color:#fff;border-radius:8px;font-size:13px;margin-bottom:10px;box-sizing:border-box">' +
        '<input type="password" id="set-new-pw" placeholder="New password (min 8 characters)" style="width:100%;padding:10px;border:1.5px solid rgba(212,175,55,.3);background:rgba(0,0,0,.2);color:#fff;border-radius:8px;font-size:13px;margin-bottom:12px;box-sizing:border-box">' +
        '<button onclick="submitCustomerPasswordChange()" style="width:100%;padding:11px;background:linear-gradient(135deg,#FFD700,#D4AF37);color:#1a1206;border:none;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer">Update Password</button>' +
        '<div id="set-pw-msg" style="margin-top:10px;font-size:12.5px"></div>' +
        '</div>' +
        '<div style="padding:16px;background:rgba(255,255,255,.04);border:1px solid rgba(212,175,55,.15);border-radius:14px">' +
        '<div style="font-weight:700;font-size:14px;margin-bottom:10px">🔐 Privacy</div>' +
        '<div style="font-size:12.5px;color:rgba(240,237,216,.55);margin-bottom:12px">Request a copy of your data or ask us to delete your account. We\'ll follow up by message within a few business days.</div>' +
        '<button onclick="requestPrivacyAction(\'export\')" style="padding:9px 14px;border:1px solid rgba(212,175,55,.3);background:none;color:#D4AF37;border-radius:8px;font-size:12.5px;cursor:pointer;margin-right:8px">Request My Data</button>' +
        '<button onclick="requestPrivacyAction(\'delete\')" style="padding:9px 14px;border:1px solid rgba(239,68,68,.3);background:none;color:#FCA5A5;border-radius:8px;font-size:12.5px;cursor:pointer">Delete My Account</button>' +
        '</div>';
}
async function saveSettingToggle(key, value) {
    const payload = {}; payload[key] = value;
    const res = await api('customer_update_settings', payload);
    if (!res || !res.success) showToast('Could not save preference', 'err');
    if (key === 'notif_order_updates' && value) enableOrderPush();
}
async function submitCustomerPasswordChange() {
    const cur = (document.getElementById('set-cur-pw') || {}).value || '';
    const nw = (document.getElementById('set-new-pw') || {}).value || '';
    const msg = document.getElementById('set-pw-msg');
    if (!cur || !nw) { if (msg) { msg.style.color = '#e63946'; msg.textContent = 'Both fields are required.'; } return; }
    if (nw.length < 8) { if (msg) { msg.style.color = '#e63946'; msg.textContent = 'New password must be at least 8 characters.'; } return; }
    if (msg) { msg.style.color = 'rgba(240,237,216,.6)'; msg.textContent = 'Updating…'; }
    const res = await api('change_password', { current_password: cur, new_password: nw });
    if (res && res.success) {
        if (res.data && res.data.require_relogin) {
            if (msg) { msg.style.color = '#22C55E'; msg.textContent = 'Password updated. Please log in again…'; }
            showToast('Password updated - please log in again');
            setTimeout(() => { ETEL.auth.logout(); }, 1500);
            return;
        }
        if (res.data && res.data.token) localStorage.setItem('etel_token', res.data.token);
        if (msg) { msg.style.color = '#22C55E'; msg.textContent = 'Password updated ✓'; }
        showToast('Password updated');
        document.getElementById('set-cur-pw').value = '';
        document.getElementById('set-new-pw').value = '';
    } else {
        if (msg) { msg.style.color = '#e63946'; msg.textContent = (res && res.message) || 'Could not update password.'; }
    }
}
async function requestPrivacyAction(kind) {
    const subject = kind === 'delete' ? 'Account deletion request' : 'Data export request';
    const body = kind === 'delete'
        ? 'I would like to permanently delete my Etel Delivery account and associated data.'
        : 'I would like a copy of my personal data held by Etel Delivery.';
    if (kind === 'delete' && !confirm('This will send a request to permanently delete your account. Continue?')) return;
    const res = await api('customer_send_message', { subject: subject, body: body });
    if (res && res.success) showToast('Request sent — we\'ll follow up soon');
    else showToast((res && res.message) || 'Could not send request', 'err');
}

// ── PROFILE ─────────────────────────────────────────────────────
function toggleProfile() {
    const drop = document.getElementById('profile-drop');
    if (drop.style.display !== 'block') {
        const u = user || {};
        const initials = (u.name||'U').split(' ').map(function(w){return w[0];}).join('').toUpperCase().slice(0,2);
        document.getElementById('profile-info').innerHTML = `
<div style="font-family:'Poppins',sans-serif;padding-bottom:24px">

  <!-- ── HERO HEADER ── -->
  <div class="prof-hero" style="position:relative;padding:24px 22px 22px;background:linear-gradient(160deg,#0E0A00 0%,#1C1400 55%,#241A00 100%);overflow:hidden">
    <div style="position:absolute;inset:0;background:radial-gradient(ellipse 100% 80% at 50% 0%,rgba(255,215,0,.16),transparent 65%);pointer-events:none"></div>
    <div style="position:absolute;bottom:-20px;right:-20px;width:120px;height:120px;border-radius:50%;background:radial-gradient(circle,rgba(255,215,0,.08),transparent 70%);pointer-events:none"></div>

    <!-- Avatar + Info -->
    <div style="display:flex;align-items:center;gap:16px;position:relative">
      <div style="position:relative;flex-shrink:0">
        <div style="width:68px;height:68px;border-radius:50%;background:linear-gradient(135deg,#FFE55C 0%,#FFD700 50%,#C8960C 100%);display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:800;color:#0A0800;letter-spacing:-.02em;box-shadow:0 0 0 3px rgba(255,215,0,.2),0 0 0 6px rgba(255,215,0,.08),0 10px 30px rgba(255,215,0,.3)">
          ${initials}
        </div>
        <div style="position:absolute;bottom:2px;right:2px;width:14px;height:14px;border-radius:50%;background:#22C55E;border:2px solid #0E0A00;box-shadow:0 0 6px rgba(34,197,94,.6)"></div>
      </div>
      <div style="flex:1;min-width:0">
        <div style="font-size:16px;font-weight:700;color:#FFF8E7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;letter-spacing:-.01em">${escapeHtml(u.name||'Guest')}</div>
        <div style="font-size:12px;color:rgba(255,248,231,.45);margin-top:3px;letter-spacing:.01em">${escapeHtml(u.phone||'No phone')}</div>
        <div style="margin-top:9px;display:inline-flex;align-items:center;gap:5px;padding:4px 10px;background:rgba(255,215,0,.1);border:1px solid rgba(255,215,0,.22);border-radius:20px">
          <span style="font-size:9px">⭐</span>
          <span style="font-size:10px;font-weight:700;color:#FFD700;letter-spacing:.06em">PREMIUM MEMBER</span>
        </div>
      </div>
    </div>

    <!-- Stats -->
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-top:20px;position:relative">
      ${[['Orders','0','#FFD700','pf-orders'],['Points','0','#FFD700','pf-points'],['Saved','ETB 0','#22C55E','pf-saved']].map(function(s){return `
      <div ${s[3]==='pf-points'?'onclick="closeProfile();openPointsSheet()" role="button" ':''}style="text-align:center;padding:12px 8px;background:rgba(255,215,0,.06);border:1px solid rgba(255,215,0,.1);border-radius:12px;${s[3]==='pf-points'?'cursor:pointer':''}">
        <div id="${s[3]}" style="font-size:17px;font-weight:800;color:${s[2]};line-height:1">${s[1]}</div>
        <div style="font-size:10px;color:rgba(255,248,231,.38);margin-top:4px;font-weight:500">${s[0]}</div>
      </div>`;}).join('')}
    </div>
  </div>

  <!-- ── SECTION DIVIDER ── -->
  <div style="height:1px;background:linear-gradient(90deg,transparent,rgba(255,215,0,.12),transparent);margin:0 0 6px"></div>

  <!-- ── MENU ── -->
  <div style="padding:8px 14px;display:flex;flex-direction:column;gap:1px">
    ${[
      ['📦','My Orders','Track and view all orders','orders'],
      ['💳','Wallet & Payments','Balance and transactions','payments'],
      ['⭐','My Favorites','Saved restaurants','favorites'],
      ['📍','Saved Addresses','Manage your locations','addresses'],
      ['🔔','Notifications','Order updates & offers','notifications'],
      ['🎁','Referral & Rewards','Earn ETB with friends','referrals'],
      ['⚙️','Settings & Privacy','Account preferences','settings'],
      ['🔠','Display Size','Text, icons, buttons & logos','__display'],
      ['🛡️','Two-step sign-in','A code from your phone when you sign in','__2fa'],
      ['🌓','Light / Dark mode','Switch the app appearance','__theme'],
      ['🔄','Refresh app','Reload shops, orders and prices','__refresh'],
    ].map(function(m){
      var onclick = m[3] === '__display' ? "closeProfile();EtelDisplay.open()"
                  : m[3] === '__2fa' ? "closeProfile();Etel2FASettings.open()"
                  : m[3] === '__theme' ? "customerToggleTheme();closeProfile()"
                  : m[3] === '__refresh' ? "closeProfile();refreshCurrentView()"
                  : (m[3] ? "closeProfile();switchCategory('"+m[3]+"')" : '');
      return `<button onclick="${onclick}" style="display:flex;align-items:center;gap:14px;padding:12px 10px;border-radius:12px;background:transparent;border:none;cursor:${m[3]?'pointer':'default'};width:100%;text-align:left;font-family:'Poppins',sans-serif;transition:background .15s" onmouseover="this.style.background='rgba(255,215,0,.07)'" onmouseout="this.style.background='transparent'">
        <div style="width:38px;height:38px;border-radius:10px;background:rgba(255,215,0,.08);border:1px solid rgba(255,215,0,.12);display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0">${m[0]}</div>
        <div style="flex:1;min-width:0">
          <div style="font-size:13px;font-weight:600;color:#FFF8E7;line-height:1.3">${m[1]}</div>
          <div style="font-size:11px;color:rgba(255,248,231,.35);margin-top:2px">${m[2]}</div>
        </div>
        ${m[3]?'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="rgba(255,215,0,0.3)" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>':''}
      </button>`;
    }).join('')}
    <a data-etel-apk data-etel-apk-display="flex" href="#" style="display:none;align-items:center;gap:14px;padding:12px 10px;border-radius:12px;background:rgba(255,215,0,.07);border:1px solid rgba(255,215,0,.16);cursor:pointer;width:100%;text-align:left;font-family:'Poppins',sans-serif;text-decoration:none;box-sizing:border-box;margin-top:4px">
        <div style="width:38px;height:38px;border-radius:10px;background:rgba(255,215,0,.08);border:1px solid rgba(255,215,0,.12);display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0">📲</div>
        <div style="flex:1;min-width:0">
          <div style="font-size:13px;font-weight:600;color:#FFD700;line-height:1.3">Download the ETEL app</div>
          <div style="font-size:11px;color:rgba(255,248,231,.35);margin-top:2px">Faster, and opens even with a weak connection</div>
        </div>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="rgba(255,215,0,0.3)" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
      </a>
    ${u.role === 'admin' ? `
    <a href="admin.html" style="display:flex;align-items:center;gap:14px;padding:12px 10px;border-radius:12px;background:rgba(255,215,0,.07);border:1px solid rgba(255,215,0,.16);cursor:pointer;width:100%;text-align:left;font-family:'Poppins',sans-serif;text-decoration:none;box-sizing:border-box;margin-top:4px">
        <div style="width:38px;height:38px;border-radius:10px;background:rgba(255,215,0,.08);border:1px solid rgba(255,215,0,.12);display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0">🛡️</div>
        <div style="flex:1;min-width:0">
          <div style="font-size:13px;font-weight:600;color:#FFD700;line-height:1.3">Admin Dashboard</div>
          <div style="font-size:11px;color:rgba(255,248,231,.35);margin-top:2px">You're signed in as admin</div>
        </div>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="rgba(255,215,0,0.3)" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
      </a>` : ''}
  </div>

  <!-- ── DIVIDER ── -->
  <div style="height:1px;background:linear-gradient(90deg,transparent,rgba(255,215,0,.1),transparent);margin:8px 14px"></div>

  <!-- ── LOGOUT ── -->
  <div style="padding:4px 14px">
    <button onclick="doLogout()" class="prof-logout" style="width:100%;padding:13px;border-radius:12px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.18);color:#FCA5A5;font-size:13px;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;display:flex;align-items:center;justify-content:center;gap:10px;transition:all .2s;letter-spacing:.01em" onmouseover="this.style.background='rgba(239,68,68,.16)'" onmouseout="this.style.background='rgba(239,68,68,.08)'">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Sign Out
    </button>
  </div>

  <!-- ── APP VERSION ── -->
  <div style="text-align:center;padding:20px 14px 8px;font-size:10px;color:rgba(255,248,231,.18);letter-spacing:.05em">ETEL DELIVERY v3.7 · ሐዋሳ</div>
</div>`;
        drop.style.display = 'block';
        fillProfileStats();
    } else {
        drop.style.display = 'none';
    }
}
function closeProfile() { document.getElementById('profile-drop').style.display = 'none'; }
function doLogout() { if (confirm('Logout?')) Auth.logout(); }

// ── SEARCH ──────────────────────────────────────────────────────
document.getElementById('search-input').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    if (currentView !== 'home') switchCategory('home');
    if (!q) { renderItemsGrid(itemsCache); return; }
    renderItemsGrid(itemsCache.filter(function(i){
        return (i.name||'').toLowerCase().includes(q) || (i.business_name||'').toLowerCase().includes(q);
    }));
});
document.getElementById('search-input').addEventListener('focus', function() { this.style.transform='translateY(-2px)'; });
document.getElementById('search-input').addEventListener('blur',  function() { this.style.transform='translateY(0)'; });

// ── FILTER PILLS ─────────────────────────────────────────────────
document.querySelectorAll('.filter-pill').forEach(function(pill) {
    pill.addEventListener('click', function() {
        document.querySelectorAll('.filter-pill').forEach(function(p){ p.classList.remove('active'); });
        this.classList.add('active');
    });
});

// ── CATEGORY TABS ────────────────────────────────────────────────
const catFilter = ['food','food','food','food','food','food'];
document.querySelectorAll('.category-tab').forEach(function(tab, i) {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.category-tab').forEach(function(t){ t.classList.remove('active'); });
        this.classList.add('active');
        if (!itemsCache.length) return;
        const txt = this.textContent.trim().toLowerCase().replace(/[^a-z]/g,'');
        const serviceMap = {pizza:'food',burgers:'food',noodles:'food',traditional:'food',healthy:'food',sushi:'food'};
        const cat = serviceMap[txt] || '';
        renderItemsGrid(cat ? itemsCache.filter(function(it){ return (it.category||'') === cat; }) : itemsCache);
    });
});

// ── TOAST ────────────────────────────────────────────────────────
function showToast(msg, type) {
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:' + (document.body.classList.contains('has-cartbar') ? '148px' : '80px') + ';left:50%;transform:translateX(-50%);z-index:999;padding:12px 20px;background:' + (type==='err'?'#e63946':'#2ecc71') + ';color:#fff;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.2);white-space:nowrap;max-width:90vw;text-align:center';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function(){ t.remove(); }, 3000);
}

// ── FIRST-OPEN PERMISSIONS PROMPT ───────────────────────────────────
// Only geolocation and notifications are real, separately-requestable
// browser permissions - there's no such thing as a standalone "phone"
// permission (tel: links just work, no permission needed), and camera
// access can't be pre-requested outside of an actual camera-using
// feature (it gets requested automatically the moment the QR scanner
// opens). Shown once, tied to a real explanation, not blindly on every
// app open - requesting permissions with zero context is a recognized
// anti-pattern that browsers may silently suppress and that trains
// people to reflexively deny.
function maybeShowPermissionsOnboarding() {
    try { if (localStorage.getItem('etel_customer_onboarded_v1')) return; } catch (e) { return; }
    if (!document.getElementById('cart-drawer') && !document.body) return;
    var bg = document.createElement('div');
    bg.id = 'perm-onboard-bg';
    bg.style.cssText = 'position:fixed;inset:0;z-index:99999;display:flex;align-items:flex-end;justify-content:center;background:rgba(5,5,10,.7);backdrop-filter:blur(4px)';
    bg.innerHTML =
        '<div style="width:min(460px,100vw);background:linear-gradient(180deg,#171006,#0a0703);border:1px solid rgba(212,175,55,.25);border-bottom:none;border-radius:20px 20px 0 0;padding:24px 22px max(22px,env(safe-area-inset-bottom,22px));color:#F5EDD8;font-family:inherit">' +
            '<div style="font-size:17px;font-weight:800;color:#D4AF37;margin-bottom:14px">Set up ETEL Delivery</div>' +
            '<div style="display:flex;gap:12px;margin-bottom:14px"><div style="font-size:20px">📍</div><div><div style="font-weight:700;font-size:13.5px">Location</div><div style="font-size:12px;color:rgba(245,237,216,.65)">Auto-fill your delivery address and show accurate fees for your zone</div></div></div>' +
            '<div style="display:flex;gap:12px;margin-bottom:14px"><div style="font-size:20px">🔔</div><div><div style="font-weight:700;font-size:13.5px">Notifications</div><div style="font-size:12px;color:rgba(245,237,216,.65)">Know the moment your order is confirmed, picked up, and on the way</div></div></div>' +
            '<div style="font-size:11px;color:rgba(245,237,216,.4);margin-bottom:16px">Camera access is asked for automatically if you ever scan a QR code — nothing to set up now. There\'s no separate "phone" permission needed either; calling a courier just opens your phone\'s dialer directly.</div>' +
            '<button id="perm-onboard-allow" style="display:block;width:100%;padding:13px;border:none;border-radius:12px;background:linear-gradient(135deg,#FFD700,#D4AF37);color:#1a1206;font-weight:700;font-size:13px;cursor:pointer;margin-bottom:8px">Allow &amp; Continue</button>' +
            '<button id="perm-onboard-skip" style="display:block;width:100%;padding:12px;border:1px solid rgba(255,255,255,.15);background:transparent;color:#F5EDD8;border-radius:12px;font-weight:700;font-size:13px;cursor:pointer">Not Now</button>' +
        '</div>';
    document.body.appendChild(bg);

    function dismiss() {
        try { localStorage.setItem('etel_customer_onboarded_v1', '1'); } catch (e) {}
        bg.remove();
    }
    document.getElementById('perm-onboard-skip').addEventListener('click', dismiss);
    document.getElementById('perm-onboard-allow').addEventListener('click', function () {
        // Both requests stem from this same tap (a real user gesture),
        // which is what browsers actually require - they don't need to
        // be split across separate individual clicks.
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function (pos) {
                _deliveryLat = pos.coords.latitude;
                _deliveryLng = pos.coords.longitude;
                selectedZone = getNearestHawassaZone(_deliveryLat, _deliveryLng);
                _biasAutocompleteToLocation(_deliveryLat, _deliveryLng);
            }, function () { /* denied or unavailable - fine, they can still use the pin button later */ }, { timeout: 8000, enableHighAccuracy: true });
        }
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission().then(subscribeCustomerPush).catch(function () {});
        }
        dismiss();
    });
}

// ── INIT ─────────────────────────────────────────────────────────
renderHomeGreeting();
loadHomeTracker();
loadItems();
loadFeatureFlags();
maybeShowPermissionsOnboarding();
subscribeCustomerPush();
// Notification tap -> customer.html?order=ID opens that order's live tracking.
(function () {
    try {
        const id = parseInt(new URLSearchParams(location.search).get('order'), 10);
        if (id > 0 && Auth.getToken()) {
            const rate = new URLSearchParams(location.search).get('rate') === '1';
            const chat = new URLSearchParams(location.search).get('chat') === '1';
            setTimeout(function () { if (chat && window.EtelOrderChat) EtelOrderChat.open(id); else if (rate) openRating(id); else openTrackOrder(id); }, 600);
        }
    } catch (e) {}
})();

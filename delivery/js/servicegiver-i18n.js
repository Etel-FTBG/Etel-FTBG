/* Service giver (shop) app: English / Amharic. Uses the shared ETEL_I18N from core.js (same switch and storage key as the customer app).
 * Static text carries data-i18n="key"; text built in servicegiver.js asks sgT('key'). sgLang(lang) switches and redraws what is on screen. */
ETEL_I18N.register('servicegiver', {
    en: {
        sg_dashboard: 'Dashboard', sg_orders: 'Orders', sg_services: 'My Services', sg_qr: 'QR Scanner', sg_analytics: 'Analytics', sg_reviews: 'Reviews', sg_payments: 'Payments',
        sg_settings: 'Settings', sg_2fa: 'Two-step sign-in', sg_delete: 'Delete my account', sg_display: 'Display Size', sg_staff: 'Staff & Roles',
        sg_provider: 'Service Provider', sg_logout: 'Logout', sg_loading: 'Loading...', sg_logo_sub: 'Service Dashboard', sg_lang: 'Language',
        sg_welcome: 'Welcome Back', sg_welcome_sub: "Here's what's happening with your business today",
        sg_kpi_orders: "📦 Today's Orders", sg_kpi_done: '✅ Completed', sg_kpi_earn: '💰 Total Earnings', sg_kpi_rating: '⭐ Rating',
        sg_recent: '📋 Recent Orders', sg_viewall: 'View All', sg_no_orders: 'No orders yet', sg_orders_title: 'Orders Management', sg_orders_sub: 'Track and manage all your orders',
        sg_orders_loading: 'Loading orders...',
        sg_vs_yesterday: 'vs yesterday', sg_vs_week: 'vs last week', sg_vs_month: 'vs last month', sg_nothing_cmp: 'nothing to compare yet', sg_no_data: 'no data yet',
        sg_st_pending: '⏳ Pending', sg_st_preparing: '🍳 Preparing', sg_st_accepted: '✅ Accepted', sg_st_ready: '📦 Ready for Pickup', sg_st_rejected: '🚫 Rejected',
        sg_st_assigned: '🚴 Courier On Way', sg_st_picked_up: '🚚 Picked Up', sg_st_in_transit: '🚚 In Transit', sg_st_delivered: '✅ Delivered', sg_st_cancelled: '❌ Cancelled', sg_st_unknown: 'unknown',
        sg_start_prep: '🍳 Start Preparing', sg_done_prep: '✅ Done Preparing — Notify Courier & Customer', sg_reprint: '🖨️ Reprint Kitchen Ticket', sg_decline: '✖ Decline order', sg_from: 'From: ',
        sg_dec_title: 'Decline this order?', sg_dec_hint: 'The customer is told right away and gets their money back if they already paid. Pick the reason.',
        sg_dec_note: 'Add a note for the customer (optional)', sg_dec_go: 'Decline order', sg_dec_keep: 'Keep the order',
        sg_dec_out_of_stock: 'An item is out of stock', sg_dec_too_busy: 'Too busy right now', sg_dec_closing: 'We are closing', sg_dec_other: 'Another reason',
        sg_online: '● Online — accepting orders', sg_offline: '○ Offline — tap to go online', sg_based_on: 'Based on', sg_reviews_lc: 'reviews'
    },
    am: {
        sg_dashboard: 'ዳሽቦርድ', sg_orders: 'ትዕዛዞች', sg_services: 'የእኔ አገልግሎቶች', sg_qr: 'QR ስካነር', sg_analytics: 'ትንታኔ', sg_reviews: 'ግምገማዎች', sg_payments: 'ክፍያዎች',
        sg_settings: 'ቅንብሮች', sg_2fa: 'ባለ ሁለት ደረጃ መግቢያ', sg_delete: 'አካውንቴን ሰርዝ', sg_display: 'የማሳያ መጠን', sg_staff: 'ሠራተኞች እና ሚናዎች',
        sg_provider: 'አገልግሎት ሰጪ', sg_logout: 'ውጣ', sg_loading: 'በመጫን ላይ...', sg_logo_sub: 'የአገልግሎት ዳሽቦርድ', sg_lang: 'ቋንቋ',
        sg_welcome: 'እንኳን ደህና መጡ', sg_welcome_sub: 'ዛሬ በንግድዎ ላይ እየሆነ ያለው ይህ ነው',
        sg_kpi_orders: '📦 የዛሬ ትዕዛዞች', sg_kpi_done: '✅ የተጠናቀቁ', sg_kpi_earn: '💰 ጠቅላላ ገቢ', sg_kpi_rating: '⭐ ደረጃ',
        sg_recent: '📋 የቅርብ ጊዜ ትዕዛዞች', sg_viewall: 'ሁሉንም ተመልከት', sg_no_orders: 'እስካሁን ትዕዛዝ የለም', sg_orders_title: 'የትዕዛዝ አስተዳደር', sg_orders_sub: 'ሁሉንም ትዕዛዞችዎን ይከታተሉ እና ያስተዳድሩ',
        sg_orders_loading: 'ትዕዛዞች በመጫን ላይ...',
        sg_vs_yesterday: 'ከትናንት ጋር', sg_vs_week: 'ከባለፈው ሳምንት ጋር', sg_vs_month: 'ከባለፈው ወር ጋር', sg_nothing_cmp: 'ለማነጻጸር ገና ምንም የለም', sg_no_data: 'ገና መረጃ የለም',
        sg_st_pending: '⏳ በመጠባበቅ ላይ', sg_st_preparing: '🍳 በዝግጅት ላይ', sg_st_accepted: '✅ ተቀብሏል', sg_st_ready: '📦 ለመውሰድ ዝግጁ', sg_st_rejected: '🚫 ውድቅ ተደርጓል',
        sg_st_assigned: '🚴 ሹፌር በመንገድ ላይ', sg_st_picked_up: '🚚 ተወስዷል', sg_st_in_transit: '🚚 በጉዞ ላይ', sg_st_delivered: '✅ ደርሷል', sg_st_cancelled: '❌ ተሰርዟል', sg_st_unknown: 'ያልታወቀ',
        sg_start_prep: '🍳 ዝግጅት ጀምር', sg_done_prep: '✅ ዝግጅት ተጠናቅቋል — ሹፌሩን እና ደንበኛውን አሳውቅ', sg_reprint: '🖨️ የኩሽና ትኬት እንደገና አትም', sg_decline: '✖ ትዕዛዙን ውድቅ አድርግ', sg_from: 'ከ፡ ',
        sg_dec_title: 'ይህን ትዕዛዝ ውድቅ ያደርጋሉ?', sg_dec_hint: 'ደንበኛው ወዲያውኑ ይነገረዋል፤ ቀድሞ ከከፈለም ገንዘቡ ይመለስለታል። ምክንያቱን ይምረጡ።',
        sg_dec_note: 'ለደንበኛው ማስታወሻ ያክሉ (አማራጭ)', sg_dec_go: 'ትዕዛዙን ውድቅ አድርግ', sg_dec_keep: 'ትዕዛዙን አቆይ',
        sg_dec_out_of_stock: 'አንድ ዕቃ አልቋል', sg_dec_too_busy: 'አሁን በጣም ተጨናንቀናል', sg_dec_closing: 'እየዘጋን ነው', sg_dec_other: 'ሌላ ምክንያት',
        sg_online: '● በመስመር ላይ — ትዕዛዝ እየተቀበሉ ነው', sg_offline: '○ ከመስመር ውጭ — ለመግባት ይንኩ', sg_based_on: 'በ', sg_reviews_lc: 'ግምገማዎች ላይ የተመሠረተ'
    }
});
function sgT(k) { return ETEL_I18N.t(k); }
function sgSwitchLang(lang) {
    if (ETEL_I18N.current() !== lang) ETEL_I18N.apply(lang);
    Array.prototype.forEach.call(document.querySelectorAll('.sg-lang-b'), function (b) {
        var on = b.getAttribute('data-lang') === lang;
        b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    // redraw the parts that are built from script
    try {
        if (typeof updateOnlineStatusUI === 'function') updateOnlineStatusUI();
        if (window._sgOrders) { renderRecentOrders(window._sgOrders.slice(0, 5)); renderAllOrders(window._sgOrders); }
        if (typeof loadDashboardData === 'function' && document.getElementById('page-dashboard') && document.getElementById('page-dashboard').style.display !== 'none') loadDashboardData();
    } catch (e) {}
}
ETEL_I18N.init();
document.addEventListener('DOMContentLoaded', function () { try { sgSwitchLang(ETEL_I18N.current()); } catch (e) {} });

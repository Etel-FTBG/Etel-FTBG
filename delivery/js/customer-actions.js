/* Every action the customer page may run from a data-act attribute (see etel-actions.js).
   Add a name here when new markup uses it; tests/ci/check_inline_handlers.py fails if one is missing. */
function scrollToId(id) {
    var el = document.getElementById(id);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
EtelActions.expose([
    "applyCuisineFilter",
    "applySortFilter",
    "closeCart",
    "closeCategoryView",
    "closeCourierQRScanner",
    "closeOrderDeliveredScreen",
    "closeProfile",
    "closeShopDetail",
    "closeTrackOrder",
    "filterCategoryView",
    "loadFavorites",
    "loadItems",
    "loadNotifications",
    "loadOrders",
    "openAddAddressForm",
    "openCategoryView",
    "openContactSupport",
    "openCourierQRScanner",
    "pkgBack",
    "pkgClose",
    "pkgDone",
    "pkgGps",
    "pkgNext",
    "pkgOpen",
    "pkgPay",
    "pkgRetry",
    "pkgSaved",
    "pkgSize",
    "pkgSubmit",
    "pkgTrack",
    "pkgVehicle",
    "pkgWeight",
    "promoCardTap",
    "scrollToId",
    "switchCategory",
    "switchShopDetailTab",
    "toggleCart",
    "toggleCustomerLanguage",
    "toggleProfile"
]);

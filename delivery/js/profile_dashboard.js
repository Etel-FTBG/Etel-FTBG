/* ═══════════════════════════════════════════════════════════════════
   ETEL GROUP — Universal Profile Dashboard
   Works across: customer.html, driver.html, servicegiver.html,
                 courier.html, ride.html
   Drop in after core.js. Call: ProfileDash.open()
   ═══════════════════════════════════════════════════════════════════ */

const ProfileDash = (function () {

    /* ── CSS (injected once) ──────────────────────────────────────── */
    const CSS = `
    #pd-overlay {
        display:none;position:fixed;inset:0;z-index:8000;
        background:rgba(0,0,0,.72);backdrop-filter:blur(6px);
        justify-content:flex-end;align-items:stretch;
    }
    #pd-overlay.pd-open { display:flex; }
    #pd-sheet {
        width:min(420px,100vw);height:100%;
        background:linear-gradient(175deg,#1a1206 0%,#0d0c09 60%,#0a0d10 100%);
        border-left:1px solid rgba(212,175,55,.18);
        display:flex;flex-direction:column;
        transform:translateX(100%);
        transition:transform .36s cubic-bezier(.22,1,.36,1);
        overflow:hidden;
        box-shadow:-24px 0 80px rgba(0,0,0,.5);
    }
    #pd-overlay.pd-open #pd-sheet { transform:translateX(0); }

    /* ── header ── */
    #pd-header {
        padding:env(safe-area-inset-top,0px) 0 0;
        flex-shrink:0;
    }
    .pd-topbar {
        display:flex;align-items:center;justify-content:space-between;
        padding:16px 20px 12px;
        border-bottom:1px solid rgba(212,175,55,.1);
    }
    .pd-logo { font-size:18px;font-weight:800;color:#D4AF37;letter-spacing:.06em; }
    .pd-close {
        width:36px;height:36px;border-radius:50%;
        border:1px solid rgba(255,255,255,.15);
        background:rgba(255,255,255,.06);
        color:#9A9AAE;font-size:18px;
        display:flex;align-items:center;justify-content:center;
        cursor:pointer;transition:all .2s;
    }
    .pd-close:hover { background:rgba(255,255,255,.12);color:#fff; }

    /* ── avatar block ── */
    .pd-avatar-block {
        padding:28px 20px 20px;
        display:flex;align-items:center;gap:16px;
        background:linear-gradient(135deg,rgba(212,175,55,.07) 0%,transparent 60%);
    }
    .pd-avatar {
        width:72px;height:72px;border-radius:50%;
        border:2.5px solid rgba(212,175,55,.5);
        background:linear-gradient(135deg,#2a1f00,#1a1206);
        display:flex;align-items:center;justify-content:center;
        font-size:28px;flex-shrink:0;position:relative;
        box-shadow:0 0 0 6px rgba(212,175,55,.08);
    }
    .pd-online-dot {
        position:absolute;bottom:3px;right:3px;
        width:12px;height:12px;border-radius:50%;
        background:#2ECC71;border:2px solid #0d0c09;
    }
    .pd-name { font-size:18px;font-weight:800;color:#F5EAC8;line-height:1.15; }
    .pd-phone { font-size:12px;color:#9A9AAE;margin-top:3px; }
    .pd-role-badge {
        display:inline-flex;align-items:center;gap:5px;
        margin-top:8px;padding:3px 10px;border-radius:999px;
        background:rgba(212,175,55,.15);border:1px solid rgba(212,175,55,.3);
        color:#D4AF37;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;
    }
    .pd-id { font-size:11px;color:#6B6B7E;margin-top:4px;font-family:monospace; }

    /* ── stat cards ── */
    .pd-stats {
        display:grid;grid-template-columns:repeat(3,1fr);gap:8px;
        padding:0 20px 20px;
    }
    .pd-stat {
        background:rgba(255,255,255,.04);
        border:1px solid rgba(255,255,255,.08);
        border-radius:12px;padding:12px 8px;text-align:center;
    }
    .pd-stat-val {
        font-size:20px;font-weight:800;color:#E5C76B;line-height:1;
    }
    .pd-stat-label { font-size:10px;color:#6B6B7E;margin-top:4px;font-weight:600; }

    /* ── earnings banner ── */
    .pd-earnings {
        margin:0 20px 20px;padding:16px 18px;
        background:linear-gradient(135deg,#C8960C 0%,#D4AF37 50%,#C9A227 100%);
        border-radius:14px;display:flex;align-items:center;gap:14px;
        box-shadow:0 8px 24px rgba(200,150,12,.25);
    }
    .pd-earnings-icon { font-size:28px;flex-shrink:0; }
    .pd-earnings-label { font-size:11px;font-weight:700;opacity:.8;color:#4a3200; }
    .pd-earnings-val { font-size:22px;font-weight:900;color:#1a0d00;line-height:1.1; }

    /* ── scrollable body ── */
    #pd-body { flex:1;overflow-y:auto;padding-bottom:env(safe-area-inset-bottom,24px); }
    #pd-body::-webkit-scrollbar { width:3px; }
    #pd-body::-webkit-scrollbar-track { background:transparent; }
    #pd-body::-webkit-scrollbar-thumb { background:rgba(212,175,55,.2);border-radius:2px; }

    /* ── section / rows ── */
    .pd-section { margin:0 20px 20px; }
    .pd-section-title {
        font-size:10px;font-weight:800;color:#6B6B7E;
        letter-spacing:.12em;text-transform:uppercase;
        padding:0 0 10px;margin-bottom:0;
    }
    .pd-rows {
        background:rgba(255,255,255,.03);
        border:1px solid rgba(255,255,255,.08);
        border-radius:14px;overflow:hidden;
    }
    .pd-row {
        display:flex;align-items:center;gap:12px;
        padding:14px 16px;
        border-bottom:1px solid rgba(255,255,255,.06);
        cursor:pointer;transition:background .15s;
    }
    .pd-row:last-child { border-bottom:none; }
    .pd-row:hover { background:rgba(212,175,55,.05); }
    .pd-row:active { background:rgba(212,175,55,.1); }
    .pd-row-icon {
        width:36px;height:36px;border-radius:10px;
        background:rgba(255,255,255,.06);
        display:flex;align-items:center;justify-content:center;
        font-size:16px;flex-shrink:0;
    }
    .pd-row-body { flex:1;min-width:0; }
    .pd-row-label { font-size:13px;font-weight:600;color:#EDEDF5; }
    .pd-row-sub { font-size:11px;color:#6B6B7E;margin-top:2px;
        white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
    .pd-row-right { color:#4B4B5E;font-size:18px;margin-left:4px; }
    .pd-row-right.pd-pill {
        padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;
        background:rgba(212,175,55,.15);border:1px solid rgba(212,175,55,.25);
        color:#D4AF37;
    }

    /* ── status pill variants ── */
    .pd-pill-green { background:rgba(46,204,113,.15)!important;border-color:rgba(46,204,113,.3)!important;color:#2ECC71!important; }
    .pd-pill-red   { background:rgba(230,57,70,.15)!important;border-color:rgba(230,57,70,.3)!important;color:#E74C3C!important; }
    .pd-pill-amber { background:rgba(241,196,15,.15)!important;border-color:rgba(241,196,15,.3)!important;color:#F1C40F!important; }

    /* ── logout button ── */
    .pd-logout {
        margin:0 20px 20px;padding:14px;
        background:rgba(230,57,70,.1);
        border:1px solid rgba(230,57,70,.3);
        border-radius:12px;color:#E74C3C;
        font-size:14px;font-weight:700;
        cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;
        transition:all .2s;width:calc(100% - 40px);
    }
    .pd-logout:hover { background:rgba(230,57,70,.18); }

    /* ── loading / error states ── */
    .pd-loading {
        text-align:center;padding:40px 20px;
        color:#6B6B7E;font-size:13px;
    }
    .pd-loading-spinner {
        width:32px;height:32px;border-radius:50%;
        border:3px solid rgba(212,175,55,.15);
        border-top-color:#D4AF37;
        animation:pd-spin .8s linear infinite;
        margin:0 auto 16px;
    }
    @keyframes pd-spin { to { transform:rotate(360deg); } }

    @media (max-width:420px) {
        #pd-sheet { width:100vw;border-left:none; }
    }
    `;

    /* ── helpers ──────────────────────────────────────────────────── */
    function injectCSS() {
        if (document.getElementById('pd-css')) return;
        const s = document.createElement('style');
        s.id = 'pd-css';
        s.textContent = CSS;
        document.head.appendChild(s);
    }

    function injectHTML() {
        if (document.getElementById('pd-overlay')) return;
        const d = document.createElement('div');
        d.id = 'pd-overlay';
        d.innerHTML =
            '<div id="pd-sheet">' +
                '<div id="pd-header">' +
                    '<div class="pd-topbar">' +
                        '<div class="pd-logo">ኢE Profile</div>' +
                        '<button class="pd-close" onclick="ProfileDash.close()">✕</button>' +
                    '</div>' +
                '</div>' +
                '<div id="pd-body"><div class="pd-loading">' +
                    '<div class="pd-loading-spinner"></div>Loading your profile…' +
                '</div></div>' +
            '</div>';
        d.addEventListener('click', function (e) {
            if (e.target === d) ProfileDash.close();
        });
        document.body.appendChild(d);
    }

    function esc(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function fmt(n, prefix) {
        prefix = prefix || 'ETB ';
        return prefix + (parseFloat(n) || 0).toLocaleString('en-US', {minimumFractionDigits:0, maximumFractionDigits:0});
    }

    function row(icon, label, sub, right, rightClass, onclick) {
        return '<div class="pd-row"' + (onclick ? ' onclick="' + onclick + '"' : '') + '>' +
            '<div class="pd-row-icon">' + icon + '</div>' +
            '<div class="pd-row-body">' +
                '<div class="pd-row-label">' + esc(label) + '</div>' +
                (sub ? '<div class="pd-row-sub">' + esc(sub) + '</div>' : '') +
            '</div>' +
            (right ? '<div class="pd-row-right ' + (rightClass||'') + '">' + esc(right) + '</div>' : '<div class="pd-row-right">›</div>') +
        '</div>';
    }

    function section(title, rows) {
        return '<div class="pd-section"><div class="pd-section-title">' + title + '</div>' +
            '<div class="pd-rows">' + rows + '</div></div>';
    }

    function avatarBlock(emoji, name, phone, roleLabel, roleColor, extraId, isOnline) {
        roleColor = roleColor || '';
        return '<div class="pd-avatar-block">' +
            '<div class="pd-avatar">' + emoji + (isOnline ? '<div class="pd-online-dot"></div>' : '') + '</div>' +
            '<div>' +
                '<div class="pd-name">' + esc(name || 'Member') + '</div>' +
                '<div class="pd-phone">' + esc(phone || '') + '</div>' +
                '<div class="pd-role-badge ' + roleColor + '">' + roleLabel + '</div>' +
                (extraId ? '<div class="pd-id">' + esc(extraId) + '</div>' : '') +
            '</div>' +
        '</div>';
    }

    function stats(items) {
        return '<div class="pd-stats">' +
            items.map(function(s) {
                return '<div class="pd-stat"><div class="pd-stat-val">' + esc(s[0]) + '</div>' +
                    '<div class="pd-stat-label">' + esc(s[1]) + '</div></div>';
            }).join('') +
        '</div>';
    }

    function earnings(icon, label, value) {
        return '<div class="pd-earnings">' +
            '<div class="pd-earnings-icon">' + icon + '</div>' +
            '<div>' +
                '<div class="pd-earnings-label">' + esc(label) + '</div>' +
                '<div class="pd-earnings-val">' + esc(value) + '</div>' +
            '</div>' +
        '</div>';
    }

    function logoutBtn() {
        return '<button class="pd-logout" onclick="ProfileDash._logout()">🚪 Sign Out</button>';
    }

    /* ── role-specific renderers ──────────────────────────────────── */
    function renderCustomer(user, orders) {
        orders = orders || [];
        var total = orders.length;
        var delivered = orders.filter(function(o){ return o.status === 'delivered'; }).length;
        var spent = orders.reduce(function(s,o){ return s + parseFloat(o.fare_total||0); }, 0);
        return avatarBlock('👤', user.name, user.phone, '🛒 Customer', '', user.member_id || '') +
            stats([
                [total, 'Orders'],
                [delivered, 'Delivered'],
                ['ETB '+Math.round(spent), 'Spent']
            ]) +
            section('Account', [
                row('📦', 'My Orders', total + ' orders placed', null, null, "ProfileDash.close();switchCategory('orders')"),
                row('💳', 'Payments', 'Wallet & top-up', '›', '', "ProfileDash.close();switchCategory('payments')"),
                row('📍', 'Saved Addresses', 'Manage pickup spots', '›', '', "ProfileDash._savedLocations()"),
                row('🔔', 'Notifications', 'Delivery updates', '›', '', "ProfileDash._notifSettings()"),
            ].join('')) +
            section('Support', [
                row('💬', 'Help & Support', 'Chat with ETEL team', '›', '', "window.open('https://t.me/EtelGroupSupport','_blank')"),
                row('📄', 'Terms of Service', 'Service agreement', '›', '', "ProfileDash.close();window.location.href='terms.html'"),
                row('🔒', 'Privacy Policy', 'How we use your data', '›', '', "ProfileDash.close();window.location.href='privacy.html'"),
            ].join('')) +
            logoutBtn();
    }

    function renderDriver(driver, user) {
        driver = driver || {};
        var rating = parseFloat(driver.average_rating || driver.rating || 0).toFixed(1);
        var trips   = driver.total_trips || driver.trips_completed || 0;
        var online  = driver.is_online || false;
        var statusLabel = online ? 'Online' : 'Offline';
        var statusClass = online ? 'pd-pill-green' : 'pd-pill-red';
        return avatarBlock('🚗', user.name || driver.name, user.phone || driver.phone,
            '🚗 Driver', '', driver.driver_code || '', online) +
            stats([
                [trips, 'Trips'],
                ['⭐ '+rating, 'Rating'],
                ['ETB '+(driver.total_earnings ? Math.round(driver.total_earnings) : 0), 'Earned']
            ]) +
            (driver.total_earnings ?
                earnings('💰', 'Total Earnings', 'ETB ' + Math.round(driver.total_earnings).toLocaleString()) : '') +
            section('Status & Vehicle', [
                row('🟢', 'Driver Status', statusLabel, statusLabel, statusClass),
                row('🚙', 'Vehicle', (driver.vehicle_make||'') + ' · ' + (driver.vehicle_plate||'Not set'), '›'),
                row('🪪', 'License', driver.license_number || 'Not provided', '›'),
                row('🛺', 'Vehicle Type', driver.vehicle_type || '—', driver.vehicle_type || '—', 'pd-pill'),
            ].join('')) +
            section('Earnings', [
                row('📊', 'Earnings History', 'View all trips & fares', '›'),
                row('🏦', 'Payout Settings', 'Bank / mobile money', '›'),
                row('🎯', 'Daily Goals', trips + ' trips this period', '›'),
            ].join('')) +
            section('Support', [
                row('📋', 'Driver Guidelines', 'ETEL service standards', '›', '', "ProfileDash.close();window.location.href='terms.html'"),
                row('💬', 'Support', 'Get help from the team', '›', '', "window.open('https://t.me/EtelGroupSupport','_blank')"),
                row('🔒', 'Privacy Policy', 'How we use your data', '›', '', "ProfileDash.close();window.location.href='privacy.html'"),
            ].join('')) +
            logoutBtn();
    }

    function renderServiceGiver(sg, user) {
        sg   = sg   || {};
        user = user || {};
        var rating   = parseFloat(sg.average_rating || 0).toFixed(1);
        var statusLabel = sg.status === 'verified' ? 'Verified' : (sg.status || 'Pending');
        var statusClass = sg.status === 'verified' ? 'pd-pill-green' :
                          sg.status === 'suspended' ? 'pd-pill-red' : 'pd-pill-amber';
        var online = sg.is_online;
        return avatarBlock('🏪', sg.business_name || user.name, user.phone,
            '🏪 Partner Shop', '', sg.service_giver_code || '', online) +
            stats([
                [sg.total_deliveries || 0, 'Orders'],
                ['⭐ ' + rating, 'Rating'],
                [(sg.total_earnings ? 'ETB '+Math.round(sg.total_earnings) : '—'), 'Earned']
            ]) +
            (sg.total_earnings ?
                earnings('💵', 'Partner Earnings', 'ETB ' + Math.round(sg.total_earnings).toLocaleString()) : '') +
            section('Business', [
                row('✅', 'Account Status', statusLabel, statusLabel, statusClass),
                row('🍽️', 'Service Type', sg.service_type || '—', sg.service_type || '—', 'pd-pill'),
                row('📍', 'Zone', sg.zone || '—', null),
                row('📦', 'My Items', 'Manage your catalogue', '›'),
            ].join('')) +
            section('Performance', [
                row('📊', 'Order History', (sg.total_deliveries||0) + ' total orders', '›'),
                row('⭐', 'Reviews', (sg.rating_count||0) + ' ratings received', '›'),
                row('💳', 'Payouts', 'Earnings & settlements', '›'),
            ].join('')) +
            section('Support', [
                row('📋', 'Partner Guidelines', 'ETEL partner standards', '›'),
                row('💬', 'Contact Support', 'Get help from the team', '›'),
            ].join('')) +
            logoutBtn();
    }

    function renderCourier(courier, user) {
        courier = courier || {};
        user    = user    || {};
        var rating = parseFloat(courier.average_rating || 0).toFixed(1);
        var statusLabel = courier.status === 'verified' ? 'Active' : (courier.status || 'Pending');
        var statusClass = courier.status === 'verified' ? 'pd-pill-green' :
                          courier.status === 'suspended' ? 'pd-pill-red' : 'pd-pill-amber';
        return avatarBlock('🏍️', courier.full_name || user.name, user.phone || courier.phone,
            '🏍️ Courier', '', courier.courier_code || '') +
            stats([
                [courier.completed_deliveries || 0, 'Delivered'],
                ['⭐ ' + rating, 'Rating'],
                [(courier.total_earnings ? 'ETB '+Math.round(courier.total_earnings) : '—'), 'Earned']
            ]) +
            (courier.total_earnings ?
                earnings('💸', 'Courier Earnings', 'ETB ' + Math.round(courier.total_earnings).toLocaleString()) : '') +
            section('Profile', [
                row('✅', 'Status', statusLabel, statusLabel, statusClass),
                row('🏍️', 'Vehicle Plate', courier.vehicle_plate || 'Not set', '›'),
                row('📍', 'Zones Covered', courier.zone || 'All zones', '›'),
                row('🪪', 'Documents', 'License & ID', '›'),
            ].join('')) +
            section('Activity', [
                row('📦', 'Delivery History', (courier.completed_deliveries||0) + ' completed', '›'),
                row('💳', 'Earnings & Payouts', 'View payment history', '›'),
            ].join('')) +
            logoutBtn();
    }

    function renderRidePassenger(user, rides) {
        rides = rides || [];
        var total    = rides.length;
        var spent    = rides.reduce(function(s,r){ return s + parseFloat(r.fare_final||r.fare||0); }, 0);
        var avgFare  = total ? Math.round(spent / total) : 0;
        return avatarBlock('🧑', user.name, user.phone, '🚗 Rider', '', user.member_id || '') +
            stats([
                [total, 'Rides'],
                [(avgFare ? 'ETB '+avgFare : '—'), 'Avg Fare'],
                [(spent ? 'ETB '+Math.round(spent) : '—'), 'Spent']
            ]) +
            section('Ride Account', [
                row('🚗', 'Book a Ride', 'Bajaj, Moto, Car', '›', '', "ProfileDash.close();switchRideTab('book')"),
                row('📋', 'Ride History', total + ' trips', '›', '', "ProfileDash.close();switchRideTab('history')"),
                row('📍', 'Saved Locations', 'Quick pickup spots', '›', '', "ProfileDash._savedLocations()"),
                row('💳', 'Payment Method', 'Cash / ETEL Pay', '›'),
            ].join('')) +
            section('Support', [
                row('💬', 'Help', 'Contact ETEL Ride', '›', '', "window.open('https://t.me/EtelGroupSupport','_blank')"),
                row('📄', 'Terms', 'Service agreement', '›', '', "ProfileDash.close();window.location.href='terms.html'"),
                row('🔒', 'Privacy', 'How we use your data', '›', '', "ProfileDash.close();window.location.href='privacy.html'"),
            ].join('')) +
            logoutBtn();
    }

    /* ── detect role ──────────────────────────────────────────────── */
    function detectRole() {
        var user = (typeof Auth !== 'undefined') ? Auth.getUser() : null;
        if (!user) return 'unknown';
        var role = user.role || '';
        if (role === 'service_giver' || role === 'sg') return 'service_giver';
        if (role === 'courier') return 'courier';
        if (role === 'driver') return 'driver';
        // On ride.html the role is 'customer' but it's a ride context
        var path = window.location.pathname;
        if (path.indexOf('ride') > -1 && role === 'customer') return 'ride_customer';
        return 'customer';
    }

    /* ── api call wrapper ─────────────────────────────────────────── */
    function pdFetch(action, params) {
        params = params || {};
        var ETEL_API = (typeof ETEL !== 'undefined' && ETEL.api) ? ETEL.api : 'api/boot.php';
        var token    = (typeof Auth !== 'undefined') ? Auth.getToken() : '';
        var qs = Object.keys(params).map(function(k){ return k+'='+encodeURIComponent(params[k]); }).join('&');
        return fetch(ETEL_API + '?action=' + action + (qs ? '&'+qs : ''), {
            headers: { 'Authorization': 'Bearer ' + token }
        }).then(function(r){ return r.json(); });
    }

    /* ── load & render ────────────────────────────────────────────── */
    function load() {
        var role = detectRole();
        var user = (typeof Auth !== 'undefined') ? Auth.getUser() : null;
        var body = document.getElementById('pd-body');

        function set(html) { body.innerHTML = html; }

        if (role === 'customer') {
            pdFetch('customer_get_orders').then(function(res) {
                var orders = (res.success && res.data && res.data.orders) ? res.data.orders : [];
                set(renderCustomer(user, orders));
            }).catch(function(){ set(renderCustomer(user, [])); });
        } else if (role === 'ride_customer') {
            pdFetch('passenger_get_ride_history').then(function(res) {
                var rides = (res.success && res.data && res.data.rides) ? res.data.rides : [];
                set(renderRidePassenger(user, rides));
            }).catch(function(){ set(renderRidePassenger(user, [])); });
        } else if (role === 'driver') {
            pdFetch('driver_get_profile').then(function(res) {
                var driver = (res.success && res.data) ? (res.data.driver || {}) : {};
                set(renderDriver(driver, user));
            }).catch(function(){ set(renderDriver({}, user)); });
        } else if (role === 'service_giver') {
            pdFetch('sg_profile').then(function(res) {
                var sg  = (res.success && res.data) ? (res.data.service_giver || {}) : {};
                var usr = (res.success && res.data) ? (res.data.user || user) : user;
                set(renderServiceGiver(sg, usr));
            }).catch(function(){ set(renderServiceGiver({}, user)); });
        } else if (role === 'courier') {
            pdFetch('sg_profile').then(function(res) {
                var sg  = (res.success && res.data) ? (res.data.service_giver || {}) : {};
                set(renderCourier(sg, user));
            }).catch(function(){ set(renderCourier({}, user)); });
        } else {
            set('<div class="pd-loading">Profile unavailable</div>');
        }
    }

    /* ── public API ───────────────────────────────────────────────── */
    return {
        open: function () {
            injectCSS();
            injectHTML();
            document.getElementById('pd-overlay').classList.add('pd-open');
            document.body.style.overflow = 'hidden';
            load();
        },
        close: function () {
            var el = document.getElementById('pd-overlay');
            if (!el) return;
            var sheet = document.getElementById('pd-sheet');
            sheet.style.transform = 'translateX(100%)';
            setTimeout(function () {
                el.classList.remove('pd-open');
                document.body.style.overflow = '';
                sheet.style.transform = '';
            }, 360);
        },
        _logout: function () {
            if (confirm('Sign out of your ETEL account?')) {
                if (typeof Auth !== 'undefined') Auth.logout();
            }
        },

        /* ── Saved Locations overlay ── */
        _savedLocations: function () {
            var body = document.getElementById('pd-body');
            if (!body) return;
            body.innerHTML = '<div class="pd-loading"><div class="pd-loading-spinner"></div>Loading saved locations…</div>';
            pdFetch('get_saved_locations').then(function (res) {
                var locs = (res.success && res.data && res.data.locations) ? res.data.locations : [];
                var html = '<div class="pd-section"><div class="pd-section-title">📍 Saved Locations</div>';
                if (locs.length) {
                    html += '<div class="pd-rows">' + locs.map(function (l) {
                        return '<div class="pd-row">' +
                            '<div class="pd-row-icon">' + (l.icon || '📍') + '</div>' +
                            '<div class="pd-row-body"><div class="pd-row-label">' + esc(l.label) + '</div>' +
                            '<div class="pd-row-sub">' + esc(l.address) + '</div></div>' +
                            '<div onclick="ProfileDash._deleteLocation(' + l.id + ',this)" style="padding:6px 10px;border-radius:8px;background:rgba(230,57,70,.12);border:1px solid rgba(230,57,70,.3);color:#E74C3C;font-size:11px;cursor:pointer;flex-shrink:0">Delete</div>' +
                        '</div>';
                    }).join('') + '</div>';
                } else {
                    html += '<div style="text-align:center;padding:24px;color:#6B6B7E;font-size:13px">No saved locations yet</div>';
                }
                html += '</div>' +
                    '<div class="pd-section"><div class="pd-section-title">Add New</div>' +
                    '<div style="display:flex;flex-direction:column;gap:10px">' +
                    '<input id="sl-label" placeholder="Label (e.g. Home, Work)" style="background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:11px 14px;color:#EDEDF5;font-size:13px;font-family:Outfit,sans-serif">' +
                    '<input id="sl-addr"  placeholder="Address" style="background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:11px 14px;color:#EDEDF5;font-size:13px;font-family:Outfit,sans-serif">' +
                    '<div style="display:flex;gap:8px">' +
                    ['🏠 Home','🏢 Work','🏥 Hospital','🏫 School','📍 Other'].map(function(ic){
                        var parts = ic.split(' '); return '<button onclick="this.parentNode.querySelectorAll(\'button\').forEach(b=>b.style.background=\'rgba(255,255,255,.06)\');this.style.background=\'rgba(212,175,55,.2)\';document.getElementById(\'sl-icon\').value=\'' + parts[0] + '\'" style="padding:6px 10px;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);color:#EDEDF5;font-size:12px;cursor:pointer;font-family:Outfit,sans-serif">' + ic + '</button>';
                    }).join('') +
                    '</div>' +
                    '<input id="sl-icon" type="hidden" value="📍">' +
                    '<button onclick="ProfileDash._addLocation()" style="padding:13px;border-radius:10px;background:linear-gradient(135deg,#C8960C,#D4AF37);color:#0d0d0d;border:none;font-size:14px;font-weight:700;cursor:pointer;font-family:Outfit,sans-serif">Save Location</button>' +
                    '</div></div>';
                body.innerHTML = html;
            }).catch(function(){
                body.innerHTML = '<div class="pd-loading">Could not load locations</div>';
            });
        },
        _addLocation: function () {
            var label = (document.getElementById('sl-label') || {}).value || '';
            var addr  = (document.getElementById('sl-addr')  || {}).value || '';
            var icon  = (document.getElementById('sl-icon')  || {}).value || '📍';
            if (!label.trim() || !addr.trim()) { alert('Please enter a label and address'); return; }
            pdFetch('save_location', {label:label, address:addr, icon:icon}).then(function(res){
                if (res.success) ProfileDash._savedLocations();
                else alert(res.message || 'Could not save');
            });
        },
        _deleteLocation: function (id) {
            if (!confirm('Delete this saved location?')) return;
            pdFetch('delete_saved_location', {id:id}).then(function(){ ProfileDash._savedLocations(); });
        },

        /* ── Notification settings stub ── */
        _notifSettings: function () {
            var body = document.getElementById('pd-body');
            if (!body) return;
            body.innerHTML =
                '<div class="pd-section"><div class="pd-section-title">🔔 Notifications</div>' +
                '<div class="pd-rows">' +
                '<div class="pd-row"><div class="pd-row-icon">📦</div><div class="pd-row-body"><div class="pd-row-label">Order updates</div><div class="pd-row-sub">When your order is assigned, picked up, delivered</div></div><div class="pd-row-right pd-pill pd-pill-green">ON</div></div>' +
                '<div class="pd-row"><div class="pd-row-icon">🚗</div><div class="pd-row-body"><div class="pd-row-label">Ride status</div><div class="pd-row-sub">Driver accepted, arrived, trip complete</div></div><div class="pd-row-right pd-pill pd-pill-green">ON</div></div>' +
                '<div class="pd-row"><div class="pd-row-icon">💳</div><div class="pd-row-body"><div class="pd-row-label">Wallet alerts</div><div class="pd-row-sub">Top-up confirmations and deductions</div></div><div class="pd-row-right pd-pill pd-pill-green">ON</div></div>' +
                '<div class="pd-row"><div class="pd-row-icon">🎉</div><div class="pd-row-body"><div class="pd-row-label">Promotions</div><div class="pd-row-sub">Offers and seasonal deals</div></div><div class="pd-row-right pd-pill">OFF</div></div>' +
                '</div></div>' +
                '<div class="pd-section"><div style="font-size:12px;color:#6B6B7E;line-height:1.6">Notifications are delivered via Telegram to your registered number. To manage Telegram notifications, use <strong style="color:#D4AF37">@EtelDeliveryRideBot</strong> and type /settings.</div></div>';
        },

        /* ── SOS alert trigger (call from ride/driver UI) ── */
        sos: function (rideId, lat, lng, type) {
            type = type || 'other';
            if (!confirm('🚨 Send SOS alert to ETEL safety team?\n\nThis will share your current location. Only use in a real emergency.')) return;
            pdFetch('sos_alert', {ride_id: rideId||0, lat: lat||0, lng: lng||0, type: type}).then(function(res){
                if (res.success) {
                    alert('✅ SOS alert sent! Our safety team has been notified. If in immediate danger, also call 911.');
                } else {
                    alert('Could not send SOS — please call 911 directly.');
                }
            }).catch(function(){
                alert('Could not send SOS — please call 911 directly.');
            });
        }
    };
}());

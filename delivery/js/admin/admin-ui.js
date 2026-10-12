// js/admin/admin-ui.js
// Extracted from admin.js

let currentPage = 'dashboard';

// ─── PANEL SWITCHING ──────────────────────────────────────────
function switchPage(page, el) {
    currentPage = page;
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    if (el) el.classList.add('active');
    document.querySelectorAll('.panel').forEach(p => p.style.display = 'none');
    const panel = document.getElementById('panel-' + page);
    if (panel) panel.style.display = 'block';

    const titles = {
        dashboard:'Dashboard Overview', orders:'Orders Management',
        users:'Users Management', couriers:'Couriers Management',
        servicegivers:'Service Givers', items:'Items & Products',
        rides:'Rides Management', ridepay:'Ride Payments', pricing:'Pricing (Fares & Zones)', promos:'Promo codes', orderchats:'Order chats', menucat:'Menu catalog',
        reports:'Analytics & Reports', telebirr:'Payments', updater:'File Updater', settings:'Settings', roles:'Roles & Permissions', support:'Support Inbox', launch:'Launch Readiness', documents:'Documents'
    };
    document.getElementById('page-title').textContent = titles[page] || page;

    // Context buttons
    const ab = document.getElementById('action-buttons');
    if (page === 'orders') {
        ab.innerHTML = '<button class="btn btn-secondary" onclick="exportCSV(ordersData,[\'order_code\',\'from_zone\',\'to_zone\',\'status\',\'fare_total\',\'placed_at\'],\'orders\')">📥 Export CSV</button>' +
                       '<button class="btn btn-primary" onclick="switchPage(\'users\',null);loadUsers()">➕ New User</button>';
    } else if (page === 'users') {
        ab.innerHTML = '<button class="btn btn-secondary" onclick="exportCSV(usersData,[\'id\',\'name\',\'phone\',\'role\',\'created_at\'],\'users\')">📥 Export CSV</button>' +
                       '<button class="btn btn-primary" onclick="openModal(\'new-user\')">➕ New User</button>';
    } else if (page === 'couriers') {
        ab.innerHTML = '<button class="btn btn-secondary" onclick="exportCSV(couriersData,[\'courier_code\',\'name\',\'phone\',\'vehicle_type\',\'zone\',\'status\',\'rating\'],\'couriers\')">📥 Export CSV</button>' +
                       '<button class="btn btn-primary" onclick="openModal(\'new-courier\')">➕ Register Courier</button>';
    } else if (page === 'servicegivers') {
        ab.innerHTML = '<button class="btn btn-secondary" onclick="exportCSV(sgData,[\'service_giver_code\',\'business_name\',\'name\',\'phone\',\'service_type\',\'zone\',\'status\'],\'service_givers\')">📥 Export CSV</button>' +
                       '<button class="btn btn-primary" onclick="openModal(\'new-sg\')">➕ New Service Giver</button>';
    } else if (page === 'items') {
        ab.innerHTML = '<button class="btn btn-primary" onclick="openAdminAddItem()">➕ Add Item</button>';
    } else if (page === 'rides') {
        ab.innerHTML = '<button class="btn btn-secondary" onclick="ridesExport()">📥 Export CSV</button>' +
                       '<button class="btn btn-primary" onclick="ridesLoad()">🔄 Refresh</button>';
    } else if (page === 'ridepay') {
        ab.innerHTML = '<button class="btn btn-primary" onclick="ridePaymentsLoad()">🔄 Refresh</button>';
    } else if (page === 'updater') {
        ab.innerHTML = '';
        if(typeof UPD !== 'undefined') UPD.init();
    } else if (page === 'pricing') {
        ab.innerHTML = '<button class="btn btn-primary" onclick="ETELPricing.loadPricing()">🔄 Refresh</button>';
    } else if (page === 'roles') {
        ab.innerHTML = '';
        if (typeof loadRolesPanel === 'function') loadRolesPanel();
    } else if (page === 'equity') {
        ab.innerHTML = '';
        if (typeof loadEquityPanel === 'function') loadEquityPanel();
    } else if (page === 'platform-econ') {
        ab.innerHTML = '';
        if (typeof loadPlatformEconomics === 'function') loadPlatformEconomics();
    } else if (page === 'equb') {
        ab.innerHTML = '';
        if (typeof loadEqubAdminPanel === 'function') loadEqubAdminPanel();
    } else if (page === 'finance') {
        ab.innerHTML = '';
        if (typeof loadFinanceHub === 'function') loadFinanceHub();
    } else if (page === 'payroll') {
        ab.innerHTML = '';
        if (typeof loadPayrollPanel === 'function') loadPayrollPanel();
    } else if (page === 'business-plan') {
        ab.innerHTML = '';
        if (typeof loadBusinessPlanPanel === 'function') loadBusinessPlanPanel();
    } else if (page === 'danger') {
        ab.innerHTML = '';
        if (typeof loadDangerZone === 'function') loadDangerZone();
    } else if (page === 'support') {
        ab.innerHTML = '<button class="btn btn-primary" onclick="loadSupportInbox()">🔄 Refresh</button>';
    } else if (page === 'launch') {
        ab.innerHTML = '';
    } else if (page === 'documents') {
        ab.innerHTML = '<button class="btn btn-primary" onclick="openDocsUploadModal()">⬆️ Upload Document</button>';
    } else if (page === 'menucat') {
        ab.innerHTML = '<button class="btn btn-primary" onclick="mcEdit(0)">➕ New dish</button>';
    } else if (page === 'telebirr') {
        ab.innerHTML = '<button class="btn btn-primary" onclick="loadTelebirrPayments();if(typeof loadBankPayments===\'function\')loadBankPayments();if(window.loadChapaPayments)loadChapaPayments();if(window.loadCashReconcile)loadCashReconcile()">🔄 Refresh</button>';
    } else if (page === 'dashboard') {
        ab.innerHTML = '<button class="btn btn-primary" onclick="openModal(\'new-user\')">➕ New User</button>';
    } else {
        // Pages with no action of their own (promos, reports, settings, ...) get no buttons instead of a
        // misleading Export CSV / New User pair.
        ab.innerHTML = '';
    }

    // Scroll active tab into view on mobile (sidebar is fixed, need scrollLeft approach)
    if (el) {
        setTimeout(function () {
            try {
                var sidebar = document.querySelector('.sidebar');
                if (sidebar && window.innerWidth <= 768) {
                    // Calculate scroll position to center the element
                    var elLeft = el.offsetLeft;
                    var elWidth = el.offsetWidth;
                    var sbWidth = sidebar.offsetWidth;
                    var target = elLeft - (sbWidth / 2) + (elWidth / 2);
                    sidebar.scrollTo({ left: Math.max(0, target), behavior: 'smooth' });
                }
            } catch(e) {}
        }, 60);
    }

    // Load data for each page
    if (page === 'dashboard' && typeof loadDashboard === 'function') loadDashboard();
    else if (page === 'orders' && typeof loadOrders === 'function') loadOrders();
    else if (page === 'users' && typeof loadUsers === 'function') loadUsers();
    else if (page === 'couriers' && typeof loadCouriers === 'function') loadCouriers();
    else if (page === 'servicegivers' && typeof loadServiceGivers === 'function') loadServiceGivers();
    else if (page === 'items' && typeof loadItems === 'function') loadItems();
    else if (page === 'reports' && typeof loadReports === 'function') loadReports();
    else if (page === 'rides' && typeof ridesLoad === 'function') { ridesLoad(); if (typeof growthLoad === 'function') growthLoad(); }
    else if (page === 'pricing' && typeof ETELPricing !== 'undefined') ETELPricing.loadPricing();
    else if (page === 'telebirr' && typeof loadTelebirrPayments === 'function') {
        loadTelebirrPayments();
        if (typeof loadBankPayments === 'function') loadBankPayments();
        if (typeof loadPaymentsReport === 'function') loadPaymentsReport();
        if (typeof loadPayoutRequests === 'function') loadPayoutRequests();
        if (typeof loadPaymentLogosEditor === 'function') loadPaymentLogosEditor();
    }
    else if (page === 'settings' && typeof loadSettings === 'function') { loadSettings(); if (typeof loadEtelPayPanel === 'function') loadEtelPayPanel(); }
    else if (page === 'support' && typeof loadSupportInbox === 'function') loadSupportInbox();
    else if (page === 'launch' && typeof loadLaunchChecklist === 'function') loadLaunchChecklist();
    else if (page === 'documents' && typeof loadDocumentsPanel === 'function') loadDocumentsPanel();
}

// Auto-refresh confirmations every 15s when admin is on orders page
setInterval(function() {
    if (typeof currentPage !== 'undefined' && currentPage === 'orders' && typeof loadConfirmations === 'function') {
        loadConfirmations();
    }
}, 15000);

// ─── MODALS ───────────────────────────────────────────────────
// Sizes #modal-box to the actual device viewport (not a fixed vw/vh
// guess) for modals that opt into 'fullscreen'. Reads window.innerWidth/
// innerHeight - the real, current layout viewport for THIS device/
// orientation, including mobile browsers where the address bar shows/
// hides and changes the usable height. Re-measures on resize/orientation
// change while the modal is open (phone rotation, keyboard opening) and
// tears the listener down when the modal closes so it never leaks.
let _modalFullscreenResizeHandler = null;
function _sizeModalToViewport(box) {
    const w = window.innerWidth;
    const h = window.innerHeight;
    box.style.width = (w - 16) + 'px';
    box.style.maxWidth = (w - 16) + 'px';
    box.style.height = (h - 16) + 'px';
    box.style.maxHeight = (h - 16) + 'px';
}

function _setModalFullscreenLayout(box, content, on) {
    if (!box || !content) return;
    box.style.display = on ? 'flex' : 'block';
    box.style.flexDirection = on ? 'column' : '';
    box.style.overflow = on ? 'hidden' : 'visible';
    box.style.overflowY = on ? 'hidden' : 'auto';
    content.style.display = on ? 'flex' : 'block';
    content.style.flexDirection = on ? 'column' : '';
    content.style.minHeight = on ? '0' : '';
    content.style.flex = on ? '1' : '';
    content.style.overflow = on ? 'hidden' : 'visible';
}

// True edge-to-edge fullscreen, toggled by a button inside a modal that
// opted into {fullscreen:true}. The 'fullscreen' openModal option above
// still leaves an 8px margin + rounded corners (a large windowed card
// sized to the device); this instead pins #modal-box to the device's
// actual screen edges with zero margin/radius, detected the same way
// (window.innerWidth/innerHeight, re-measured on resize/rotation via the
// existing _modalFullscreenResizeHandler) so it fills whatever screen it
// is opened on. isDocsModalTrueFullscreen tracks state; closeModal()
// resets it so the next modal never opens already-edge-to-edge.
let isDocsModalTrueFullscreen = false;
function toggleModalTrueFullscreen() {
    const box = document.getElementById('modal-box');
    const btn = document.getElementById('modal-fullscreen-btn');
    if (!box) return;
    isDocsModalTrueFullscreen = !isDocsModalTrueFullscreen;
    if (isDocsModalTrueFullscreen) {
        box.style.position = 'fixed';
        box.style.top = '0';
        box.style.left = '0';
        box.style.right = '0';
        box.style.bottom = '0';
        box.style.width = '100vw';
        box.style.height = '100dvh';
        box.style.maxWidth = '100vw';
        box.style.maxHeight = '100dvh';
        box.style.margin = '0';
        box.style.borderRadius = '0';
    } else {
        box.style.position = 'relative';
        box.style.top = '';
        box.style.left = '';
        box.style.right = '';
        box.style.bottom = '';
        box.style.borderRadius = '16px';
        _sizeModalToViewport(box);
    }
    if (btn) btn.textContent = isDocsModalTrueFullscreen ? '⤡ Exit Full Screen' : '⛶ Full Screen';
}

function openModal(type, customHtml, opts) {
    const overlay = document.getElementById('modal-overlay');
    const content = document.getElementById('modal-content');
    const box = document.getElementById('modal-box');
    if (!overlay || !content) return;
    overlay.style.display = 'flex';
    // Some modals (e.g. the Documents viewer) need more room than the
    // default 480px card to show a full-page preview readably on a
    // phone. Every call to openModal() (fullscreen or not) explicitly
    // sets/clears #modal-box's sizing, so an override never leaks into
    // the next, unrelated modal that opens.
    if (_modalFullscreenResizeHandler) {
        window.removeEventListener('resize', _modalFullscreenResizeHandler);
        window.removeEventListener('orientationchange', _modalFullscreenResizeHandler);
        _modalFullscreenResizeHandler = null;
    }
    isDocsModalTrueFullscreen = false;
    if (box) {
        box.style.position = 'relative';
        box.style.top = '';
        box.style.left = '';
        box.style.right = '';
        box.style.bottom = '';
        box.style.margin = '';
        box.style.borderRadius = '16px';
        if (opts && opts.fullscreen) {
            _sizeModalToViewport(box);
            _setModalFullscreenLayout(box, content, true);
            _modalFullscreenResizeHandler = () => _sizeModalToViewport(box);
            window.addEventListener('resize', _modalFullscreenResizeHandler);
            window.addEventListener('orientationchange', _modalFullscreenResizeHandler);
        } else {
            box.style.width = '100%';
            box.style.maxWidth = '480px';
            box.style.height = 'auto';
            box.style.maxHeight = '90vh';
            _setModalFullscreenLayout(box, content, false);
        }
    }
    if (type === 'custom') { content.innerHTML = customHtml; return; }

    if (type === 'new-user') {
        content.innerHTML = `<div class="modal-title">➕ Create New User</div>
            <div class="modal-field"><label>Full Name *</label><input type="text" id="mu-name" placeholder="Dawit Tesfaye"></div>
            <div class="modal-field"><label>Phone *</label><input type="tel" id="mu-phone" placeholder="+251911000000"></div>
            <div class="modal-field"><label>Password * (min 6)</label><input type="password" id="mu-pw" placeholder="Password"></div>
            <div class="modal-field"><label>Role</label>
                <select id="mu-role">
                    <option value="customer">Customer</option>
                    <option value="courier">Courier</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="modal-msg" id="mu-msg"></div>
            <button class="modal-submit" onclick="submitNewUser()">Create User</button>`;
    } else if (type === 'new-courier') {
        content.innerHTML = `<div class="modal-title">🚗 Register Courier</div>
            <p style="color:#888;font-size:13px;margin-bottom:16px">Creates a user account with courier role and a courier profile.</p>
            <div class="modal-field"><label>Full Name *</label><input type="text" id="mc-name" placeholder="Dawit Tesfaye"></div>
            <div class="modal-field"><label>Phone *</label><input type="tel" id="mc-phone" placeholder="+251911000000"></div>
            <div class="modal-field"><label>Password * (min 6)</label><input type="password" id="mc-pw" placeholder="Password"></div>
            <div class="modal-field"><label>Vehicle Type</label>
                <select id="mc-vehicle"><option value="motorcycle">Motorcycle</option><option value="bicycle">Bicycle</option><option value="car">Car</option><option value="on_foot">On Foot</option></select>
            </div>
            <div class="modal-field"><label>Home Zone</label>
                <select id="mc-zone">` + (typeof ETEL !== 'undefined' ? ETEL.zones.map(z=>`<option value="${z}">${z}</option>`).join('') : '') + `</select>
            </div>
            <div class="modal-msg" id="mc-msg"></div>
            <button class="modal-submit" onclick="submitNewCourier()">Register Courier</button>`;
    } else if (type === 'new-sg') {
        content.innerHTML = `<div class="modal-title">🏪 New Service Giver</div>
            <div class="modal-field"><label>Full Name *</label><input type="text" id="sg-name" placeholder="Kebede Alemu"></div>
            <div class="modal-field"><label>Phone *</label><input type="tel" id="sg-phone" placeholder="+251911000000"></div>
            <div class="modal-field"><label>Password * (min 6)</label><input type="password" id="sg-pw" placeholder="Password"></div>
            <div class="modal-field"><label>Business Name</label><input type="text" id="sg-biz" placeholder="My Restaurant"></div>
            <div class="modal-field"><label>Service Type</label>
                <select id="sg-stype"><option value="restaurant">Restaurant</option><option value="grocery">Grocery</option><option value="pharmacy">Pharmacy</option><option value="laundry">Laundry</option><option value="delivery">Delivery</option></select>
            </div>
            <div class="modal-field"><label>Address / Description</label><input type="text" id="sg-addr" placeholder="e.g. Near Stadium, next to Bank"></div>
            <div class="modal-field">
                <label style="font-size:13px;font-weight:700;color:#1a1a2e;margin-bottom:6px;display:block">Location Pin *</label>
                <div id="sg-map" style="width:100%;height:200px;background:#eee;border-radius:8px;border:1.5px solid #e0e0e0;margin-bottom:8px"></div>
                <div style="font-size:11px;color:#888;margin-top:4px">Drag marker to set exact location</div>
                <input type="hidden" id="sg-lat"><input type="hidden" id="sg-lng">
            </div>
            <div class="modal-field"><label>ID Number</label><input type="text" id="sg-idnum" placeholder="Optional"></div>
            <div class="modal-msg" id="sg-msg"></div>
            <button class="modal-submit" onclick="submitNewSG()">Register Partner</button>`;
            if (typeof initSGMap === 'function') setTimeout(initSGMap, 100);
    }
}

function closeModal() {
    const o = document.getElementById('modal-overlay');
    if (o) o.style.display = 'none';
    if (_modalFullscreenResizeHandler) {
        window.removeEventListener('resize', _modalFullscreenResizeHandler);
        window.removeEventListener('orientationchange', _modalFullscreenResizeHandler);
        _modalFullscreenResizeHandler = null;
    }
    isDocsModalTrueFullscreen = false;
}

function toast(msg, type='info') {
    const c = document.getElementById('admin-toasts');
    if (!c) return;
    const t = document.createElement('div');
    t.className = 'driver-toast ' + type;
    t.textContent = msg;
    c.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}

// Ensure globally accessible
window.currentPage = currentPage;
window.switchPage = switchPage;
window.openModal = openModal;
window.closeModal = closeModal;
window.toast = toast;

// Single floating refresh button (see #admin-refresh-btn in admin.html).
// Re-calls switchPage() with whatever page is already active, reusing
// its existing exhaustive per-page load dispatch above rather than
// duplicating a second mapping that would drift out of sync with it
// over time.
async function refreshCurrentPage() {
    const btn = document.getElementById('admin-refresh-btn');
    if (btn) btn.classList.add('spinning');
    try {
        const activeNav = document.querySelector('.nav-item.active');
        switchPage(currentPage, activeNav);
        if (typeof toast === 'function') toast('Refreshed', 'info');
    } catch (e) {
        if (typeof toast === 'function') toast('Could not refresh - check your connection', 'error');
    } finally {
        if (btn) setTimeout(() => btn.classList.remove('spinning'), 400);
    }
}
window.refreshCurrentPage = refreshCurrentPage;

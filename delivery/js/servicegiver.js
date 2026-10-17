        // Auth guard — redirect non-service-giver users
        if (!requireRole(['service_giver'])) { throw new Error('Access denied'); }

        // Button/field actions without inline JavaScript (js/etel-actions.js; the allow-list is js/servicegiver-actions.js).
        function _ea(name, args, opts) { return EtelActions.attrs(name, args, opts); }

        let currentUser = null;
        let currentServiceGiver = null;
        let currentStaffRole = 'owner'; // 'owner' | 'staff' - set from sg_profile, see applyStaffRoleUI()
        let currentRoleName = 'Owner'; // the business's own custom name for this role, e.g. "Kitchen"
        let currentCapabilities = ['*']; // this account's granted capabilities within its own business

        window.addEventListener('DOMContentLoaded', async () => {
            if (!Auth.getToken()) { Auth.logout(); return; }
            await loadUserProfile();
            await loadDashboardData();
            setInterval(loadDashboardData, 30000);
        });

        async function apiCall(action, data) {
            if (!data) data = {};
            try {
                const response = await fetch(ETEL.api, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + Auth.getToken()
                    },
                    body: JSON.stringify(Object.assign({ action }, data))
                });
                const result = await response.json();
                if (!result.success) {
                    if (response.status === 401) { Auth.logout(); return null; }
                    showNotification('Error: ' + result.message, 'error');
                    return null;
                }
                return result.data;
            } catch (error) {
                console.error('Fetch error:', error);
                return null;
            }
        }

        async function loadUserProfile() {
            const data = await apiCall('sg_profile');
            if (!data) {
                showNotification(sgT('sg_d_err_profile'), 'error');
                return;
            }
            currentUser = data.user || {};
            currentServiceGiver = data.service_giver || {};
            currentStaffRole = data.staff_role || 'owner';
            currentRoleName = data.role_name || 'Owner';
            currentCapabilities = data.capabilities || ['*'];
            applyStaffRoleUI();
            const displayName = currentServiceGiver.business_name || currentUser.name || 'My Business';
            const initials = displayName.charAt(0).toUpperCase();
            // Helper: set element text/value only if the element exists (never throw)
            const setText = function(id, val){ var el = document.getElementById(id); if (el) el.textContent = val; };
            const setVal  = function(id, val){ var el = document.getElementById(id); if (el) el.value = val; };
            setText('user-avatar', initials);
            setText('profile-avatar', initials);
            setText('user-card-name', displayName);
            setText('profile-name', displayName);
            setVal('settings-business-name', currentServiceGiver.business_name || '');
            setVal('settings-business-type', currentServiceGiver.service_type || '');
            setVal('settings-zone', currentServiceGiver.zone || '');
            setVal('settings-cbe-account', currentServiceGiver.cbe_account || '');
            setVal('settings-cbe-account-name', currentServiceGiver.cbe_account_name || '');
            setVal('settings-abysinia-account', currentServiceGiver.abysinia_account || '');
            setVal('settings-abysinia-account-name', currentServiceGiver.abysinia_account_name || '');
            setVal('settings-telebirr-number', currentServiceGiver.telebirr_number || '');
            setVal('settings-telebirr-account-name', currentServiceGiver.telebirr_account_name || '');
            const logoPreview = document.getElementById('settings-logo-preview');
            const logoFallback = document.getElementById('settings-logo-fallback');
            if (logoPreview) {
                if (currentServiceGiver.logo_url) {
                    logoPreview.src = currentServiceGiver.logo_url; logoPreview.style.display = 'block';
                    if (logoFallback) logoFallback.style.display = 'none';
                } else {
                    logoPreview.style.display = 'none';
                    if (logoFallback) logoFallback.style.display = 'flex';
                }
            }
            // Premium profile header - business name, type pill, and a
            // status badge that actually reflects real backend states
            // (pending/verified/suspended), not a static "Active" label.
            setText('settings-header-name', currentServiceGiver.business_name || 'Your Business');
            const typeLabels = {}; ['restaurant','pharmacy','grocery','electronics','retail','cafe','fastfood','laundry','fuel','custom'].forEach(function (k) { typeLabels[k] = sgT('sg_d_ty_' + k); });
            setText('settings-header-type', typeLabels[currentServiceGiver.service_type] || currentServiceGiver.service_type || '—');
            const statusEl = document.getElementById('settings-header-status');
            if (statusEl) {
                const statusStyles = {
                    verified:  { bg: 'rgba(46,204,113,.15)',  border: 'rgba(46,204,113,.3)',  color: '#2ecc71', label: sgT('sg_d_vf') },
                    pending:   { bg: 'rgba(212,175,55,.15)',  border: 'rgba(212,175,55,.3)',  color: '#D4AF37', label: sgT('sg_d_pv') },
                    suspended: { bg: 'rgba(231,76,60,.15)',   border: 'rgba(231,76,60,.3)',   color: '#e74c3c', label: sgT('sg_d_sp') },
                    inactive:  { bg: 'rgba(154,154,174,.15)', border: 'rgba(154,154,174,.3)', color: '#9A9AAE', label: sgT('sg_d_in') },
                };
                const s = statusStyles[currentServiceGiver.status] || statusStyles.pending;
                statusEl.style.background = s.bg; statusEl.style.border = '1px solid ' + s.border; statusEl.style.color = s.color;
                statusEl.textContent = s.label;
            }
            const cbeStatusEl = document.getElementById('settings-cbe-status');
            if (cbeStatusEl) {
                if (currentServiceGiver.cbe_account) { cbeStatusEl.textContent = sgT('sg_d_acc_active'); cbeStatusEl.style.color = '#2ecc71'; }
                else { cbeStatusEl.textContent = sgT('sg_d_acc_none'); cbeStatusEl.style.color = '#9A9AAE'; }
            }
            const abysiniaStatusEl = document.getElementById('settings-abysinia-status');
            if (abysiniaStatusEl) {
                if (currentServiceGiver.abysinia_account) { abysiniaStatusEl.textContent = sgT('sg_d_acc_active'); abysiniaStatusEl.style.color = '#2ecc71'; }
                else { abysiniaStatusEl.textContent = sgT('sg_d_acc_none'); abysiniaStatusEl.style.color = '#9A9AAE'; }
            }
            const telebirrStatusEl = document.getElementById('settings-telebirr-status');
            if (telebirrStatusEl) {
                if (currentServiceGiver.telebirr_number) { telebirrStatusEl.textContent = sgT('sg_d_acc_active'); telebirrStatusEl.style.color = '#2ecc71'; }
                else { telebirrStatusEl.textContent = sgT('sg_d_acc_none'); telebirrStatusEl.style.color = '#9A9AAE'; }
            }
            applyPaymentMethodLogos();
            checkPendingPayoutRequest();
            if (typeof showSettingsCover === 'function') showSettingsCover(currentServiceGiver.cover_url || '');
            updateOnlineStatusUI();
            renderSgProfilePanel();
        }

        // Uses a real uploaded logo image (set by the founder in the
        // admin panel) in place of the custom-drawn badge, the moment
        // one exists for that method - falls back to the badge's
        // existing emoji/gradient otherwise, so nothing looks broken
        // before any logos are uploaded.
        let paymentLogosLoaded = false;
        async function applyPaymentMethodLogos() {
            if (paymentLogosLoaded) return;
            const data = await apiCall('get_payment_method_logos');
            if (!data) return;
            paymentLogosLoaded = true;
            const logos = data.logos || {};
            Object.keys(logos).forEach(key => {
                const el = document.getElementById('pm-logo-' + key);
                if (el && logos[key]) {
                    el.innerHTML = '';
                    el.style.background = '#fff';
                    const img = document.createElement('img');
                    img.src = logos[key];
                    img.style.cssText = 'width:100%;height:100%;object-fit:contain;padding:4px';
                    el.appendChild(img);
                }
            });
        }

        // Any payout method change (bank account, payout bank, Telebirr
        // number) needs the founder's approval before it takes effect -
        // the business keeps using its OLD details until then. This
        // checks whether one is already sitting in that queue and shows
        // it honestly instead of the form quietly looking "saved" with
        // nothing actually having changed live yet.
        async function checkPendingPayoutRequest() {
            const data = await apiCall('sg_get_pending_payout_request');
            const banner = document.getElementById('settings-payout-pending-banner');
            if (!data || !data.pending) {
                if (banner) banner.style.display = 'none';
                return;
            }
            const p = data.pending;
            if (banner) {
                banner.style.display = 'block';
                banner.innerHTML = '⏳ <b>Awaiting admin approval</b> — submitted ' + new Date(p.requested_at).toLocaleString() +
                    '. Your current payout details keep working until this is reviewed.';
            }
            const cbeStatusEl = document.getElementById('settings-cbe-status');
            const abysiniaStatusEl = document.getElementById('settings-abysinia-status');
            const telebirrStatusEl = document.getElementById('settings-telebirr-status');
            if (p.new_cbe_account !== null && cbeStatusEl) { cbeStatusEl.textContent = sgT('sg_d_acc_pending'); cbeStatusEl.style.color = '#D4AF37'; }
            if (p.new_abysinia_account !== null && abysiniaStatusEl) { abysiniaStatusEl.textContent = sgT('sg_d_acc_pending'); abysiniaStatusEl.style.color = '#D4AF37'; }
            if (p.new_telebirr_number !== null && telebirrStatusEl) { telebirrStatusEl.textContent = sgT('sg_d_acc_pending'); telebirrStatusEl.style.color = '#D4AF37'; }
        }

        // Hides owner-only settings cards/buttons for any non-owner login via
        // the body.role-cashier CSS rules (see <style> block) - kept as
        // 'role-cashier' for backward compatibility with those existing CSS
        // rules, even though non-owner logins are now called "staff", not
        // just "cashier". This is a UI convenience only - every restricted
        // action is also enforced server-side (sg_require_owner /
        // sg_require_capability in ServiceGiverController.php), so a staff
        // login can't just re-show a hidden button with devtools and get
        // through. On top of that binary owner/non-owner split, elements
        // tagged data-sg-cap="capability" (or a comma-separated list, any
        // one is enough) are individually shown only if this account's
        // granted capabilities actually include it - e.g. the "Staff &
        // Roles" nav item only appears for staff.manage.
        function applyStaffRoleUI() {
            document.body.classList.toggle('role-cashier', currentStaffRole !== 'owner');
            const caps = currentCapabilities || [];
            const hasCap = (c) => caps.indexOf('*') !== -1 || caps.indexOf(c) !== -1;
            document.querySelectorAll('[data-sg-cap]').forEach((el) => {
                const required = el.getAttribute('data-sg-cap').split(',').map((s) => s.trim());
                el.style.display = required.some(hasCap) ? '' : 'none';
            });
            // Inverse variant: shown only when the capability is ABSENT -
            // e.g. an explanatory note for staff who can view something
            // but not change it, hidden once they actually can.
            document.querySelectorAll('[data-sg-cap-inverse]').forEach((el) => {
                const required = el.getAttribute('data-sg-cap-inverse').split(',').map((s) => s.trim());
                el.style.display = required.some(hasCap) ? 'none' : '';
            });
            renderMyRoleCard();
        }

        // "My Role" card - a self-service view of exactly what this login
        // can do, shown to staff so they don't have to guess or ask the
        // owner why a button is missing. Owners already implicitly have
        // full access, so the card stays hidden for them - it would just
        // be visual clutter with nothing useful to say ("you can do
        // everything" isn't information anyone needs repeated to them).
        let myRoleLoaded = false;
        async function renderMyRoleCard() {
            const card = document.getElementById('sg-my-role-card');
            if (!card) return;
            if (currentStaffRole === 'owner') { card.style.display = 'none'; return; }
            card.style.display = 'block';
            if (myRoleLoaded) return;
            myRoleLoaded = true;
            const data = await apiCall('sg_my_role');
            if (!data) return;
            const nameEl = document.getElementById('sg-my-role-name');
            const subEl = document.getElementById('sg-my-role-sub');
            const capsEl = document.getElementById('sg-my-role-caps');
            if (nameEl) nameEl.textContent = (data.name || 'You') + ' — ' + data.role_name + ' at ' + (data.business_name || 'this business');
            if (subEl) subEl.textContent = (data.phone ? data.phone + ' · ' : '') + data.capability_count + ' of ' + data.catalog_count + ' possible permissions granted to this role';
            if (capsEl) {
                capsEl.innerHTML = data.capabilities.length
                    ? data.capabilities.map((c) => '<span style="background:rgba(212,175,55,.12);border:1px solid rgba(212,175,55,.25);color:#D4AF37;padding:4px 10px;border-radius:20px;font-size:11.5px;font-weight:600">✓ ' + escapeHtml(c.label) + '</span>').join('')
                    : '<span style="color:var(--text-faint,#9A9AAE);font-size:12.5px">No permissions granted yet - ask the owner to assign you a role.</span>';
            }
        }

        // Single source of truth for the online/offline indicator, shown in
        // two places (header pill + profile side panel). The header pill
        // used to be static HTML ("● Online", always, regardless of the
        // real is_online value) - sg_set_online has existed and worked in
        // the backend the whole time, nothing in the UI ever called it.
        function updateOnlineStatusUI() {
            const online = !!(currentServiceGiver && parseInt(currentServiceGiver.is_online, 10) === 1);
            const statusEl = document.getElementById('profile-status');
            if (statusEl) {
                statusEl.textContent = online ? sgT('sg_online') : sgT('sg_offline');
                statusEl.style.color = online ? 'var(--success, #2ecc71)' : 'var(--text-faint, #9A9AAE)';
            }
            const dot = document.getElementById('sgp-dot');
            if (dot) dot.style.background = online ? 'var(--success)' : 'var(--text-faint)';
        }

        async function toggleSgOnline(e) {
            if (e) e.stopPropagation(); // don't also trigger the profile-panel opener it sits inside
            const wasOnline = !!(currentServiceGiver && parseInt(currentServiceGiver.is_online, 10) === 1);
            const nextOnline = wasOnline ? 0 : 1;
            const statusEl = document.getElementById('profile-status');
            if (statusEl) statusEl.textContent = 'Updating…';
            const data = await apiCall('sg_set_online', { is_online: nextOnline });
            if (!data) { updateOnlineStatusUI(); return; } // apiCall already toasted the error; restore previous text
            currentServiceGiver.is_online = data.is_online;
            updateOnlineStatusUI();
            showNotification(sgT(data.is_online ? 'sg_d_online' : 'sg_d_offline'), 'success');
        }

        // Opening hours: the server only lets customers order inside them (and only while the online switch is on).
        async function saveSgHours() {
            const o = (document.getElementById('sgp-opens') || {}).value || '';
            const c = (document.getElementById('sgp-closes') || {}).value || '';
            if (!o || !c) { showNotification(sgT('sg_d_hours_both'), 'error'); return; }
            const data = await apiCall('sg_set_hours', { opens_at: o, closes_at: c });
            if (!data) return;
            currentServiceGiver.opens_at = data.opens_at; currentServiceGiver.closes_at = data.closes_at;
            showNotification(sgT('sg_d_hours_saved').replace('{a}', data.opens_at).replace('{b}', data.closes_at), 'success');
        }
        async function clearSgHours() {
            const data = await apiCall('sg_set_hours', { opens_at: '', closes_at: '' });
            if (!data) return;
            currentServiceGiver.opens_at = null; currentServiceGiver.closes_at = null;
            const hOpen = document.getElementById('sgp-opens'), hClose = document.getElementById('sgp-closes');
            if (hOpen) hOpen.value = ''; if (hClose) hClose.value = '';
            showNotification(sgT('sg_d_hours_cleared'), 'success');
        }

        function renderSgProfilePanel() {
            const setText = function(id, val){ var el = document.getElementById(id); if (el) el.textContent = val; };
            const sg = currentServiceGiver || {};
            const usr = currentUser || {};
            const displayName = sg.business_name || usr.name || 'My Business';
            setText('sgp-avatar', displayName.charAt(0).toUpperCase());
            setText('sgp-name', displayName);
            const roleLabel = currentStaffRole === 'cashier' ? 'Cashier' : 'Owner';
            setText('sgp-owner', usr.name ? (roleLabel + ': ' + usr.name) : '');
            const typeLabels = { restaurant: '🍽️ RESTAURANT', pharmacy: '💊 PHARMACY', retail: '🛍️ RETAIL STORE', grocery: '🛒 GROCERY', other: '🏪 VERIFIED BUSINESS' };
            setText('sgp-type-badge', typeLabels[sg.service_type] || '🏪 VERIFIED BUSINESS');
            setText('sgp-type', sg.service_type ? (sg.service_type.charAt(0).toUpperCase() + sg.service_type.slice(1)) : 'Not set');
            setText('sgp-zone', sg.zone || sg.address || 'Not set');
            setText('sgp-code', sg.service_giver_code || '—');
            setText('sgp-stat-orders', sg.total_deliveries || sg.completed_deliveries || 0);
            const rating = parseFloat(sg.average_rating || 0);
            setText('sgp-stat-rating', rating > 0 ? rating.toFixed(1) + ' ⭐' : 'New');
            // Cashiers don't see the business's money - sg_profile already
            // strips total_earnings from the response for them, so this
            // shows an explicit "hidden" dash rather than a misleading "ETB 0".
            setText('sgp-stat-revenue', currentStaffRole === 'cashier' ? '—' : 'ETB ' + Math.round(parseFloat(sg.total_earnings || 0)).toLocaleString());
            const dot = document.getElementById('sgp-dot');
            if (dot) dot.style.background = sg.is_online ? 'var(--success)' : 'var(--text-faint)';
            const hOpen = document.getElementById('sgp-opens'), hClose = document.getElementById('sgp-closes');
            if (hOpen) hOpen.value = sg.opens_at ? String(sg.opens_at).slice(0, 5) : '';
            if (hClose) hClose.value = sg.closes_at ? String(sg.closes_at).slice(0, 5) : '';
        }

        function toggleSgProfile() {
            const overlay = document.getElementById('sg-profile-overlay');
            const panel = document.getElementById('sg-profile-panel');
            if (!overlay || !panel) return;
            const isOpen = overlay.style.display === 'block';
            if (isOpen) {
                panel.style.transform = 'translateX(100%)';
                setTimeout(function () { overlay.style.display = 'none'; }, 340);
                return;
            }
            renderSgProfilePanel();
            overlay.style.display = 'block';
            requestAnimationFrame(function () {
                requestAnimationFrame(function () { panel.style.transform = 'translateX(0)'; });
            });
        }

        async function loadDashboardData() {
            const res = await apiCall('sg_orders');
            if (!res) return;
            const orders = res.assignments || res.orders || (Array.isArray(res) ? res : []);
            // Real numbers only (demo or live): every figure and every
            // "compared with" line comes from the shop's own orders. When
            // there is nothing to compare with, the line says so instead
            // of showing an invented percentage.
            const orderTs = o => { const t = o.placed_at || o.assigned_at || o.created_at; const d = t ? new Date(String(t).replace(' ', 'T')) : null; return d && !isNaN(d) ? d : null; };
            const now = new Date();
            const dayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            const dayMs = 86400000;
            const inRange = (o, from, to) => { const d = orderTs(o); return !!d && d >= from && d < to; };
            const delivered = orders.filter(o => o.status === 'delivered');
            const todayOrders = orders.filter(o => inRange(o, dayStart, new Date(dayStart.getTime() + dayMs))).length;
            const yesterdayOrders = orders.filter(o => inRange(o, new Date(dayStart.getTime() - dayMs), dayStart)).length;
            const weekFrom = new Date(dayStart.getTime() - 6 * dayMs);
            const prevWeekFrom = new Date(weekFrom.getTime() - 7 * dayMs);
            const doneWeek = delivered.filter(o => inRange(o, weekFrom, new Date(dayStart.getTime() + dayMs))).length;
            const donePrevWeek = delivered.filter(o => inRange(o, prevWeekFrom, weekFrom)).length;
            const monthFrom = new Date(now.getFullYear(), now.getMonth(), 1);
            const prevMonthFrom = new Date(now.getFullYear(), now.getMonth() - 1, 1);
            const sumFare = list => list.reduce((sum, o) => sum + parseFloat(o.fare_total || 0), 0);
            const earnMonth = sumFare(delivered.filter(o => inRange(o, monthFrom, new Date(dayStart.getTime() + dayMs))));
            const earnPrevMonth = sumFare(delivered.filter(o => inRange(o, prevMonthFrom, monthFrom)));
            const completed = delivered.length;
            const totalEarnings = sumFare(delivered);
            const setChange = (id, label, cur, prev) => {
                const el = document.getElementById(id); if (!el) return;
                el.classList.remove('down', 'kpi-flat');
                if (!prev) { el.classList.add('kpi-flat'); el.textContent = sgT(label) + ': ' + sgT(cur ? 'sg_nothing_cmp' : 'sg_no_data'); return; }
                const pct = Math.round((cur - prev) / prev * 100);
                if (pct < 0) el.classList.add('down'); else if (pct === 0) el.classList.add('kpi-flat');
                el.textContent = (pct > 0 ? '\u2191 ' : pct < 0 ? '\u2193 ' : '') + Math.abs(pct) + '% ' + sgT(label).toLowerCase();
            };
            setChange('kpi-today-change', 'sg_vs_yesterday', todayOrders, yesterdayOrders);
            setChange('kpi-completed-change', 'sg_vs_week', doneWeek, donePrevWeek);
            setChange('kpi-earnings-change', 'sg_vs_month', earnMonth, earnPrevMonth);
            document.getElementById('kpi-today-orders').textContent = todayOrders;
            document.getElementById('kpi-completed').textContent = completed;
            // Cashiers see order counts but not the earnings total - this
            // KPI is computed client-side from order fare_total, which
            // sg_orders itself doesn't redact (cashiers legitimately see
            // per-order prices to do their job), so the aggregate has to
            // be hidden here specifically rather than at the API layer.
            document.getElementById('kpi-earnings').textContent = currentStaffRole === 'cashier' ? '—' : 'Br ' + totalEarnings.toFixed(2);
            if (currentServiceGiver) {
                document.getElementById('kpi-rating').textContent = parseFloat(currentServiceGiver.average_rating || currentServiceGiver.rating || 0).toFixed(1);
                document.getElementById('kpi-reviews').textContent = currentServiceGiver.rating_count || 0;
            }
            document.getElementById('orders-badge').textContent = orders.filter(o => o.status !== 'delivered').length;
            window._sgOrders = orders;
            renderRecentOrders(orders.slice(0, 5));
            renderAllOrders(orders);
        }

        function renderRecentOrders(orders) {
            const container = document.getElementById('recent-orders-container');
            if (!orders || orders.length === 0) {
                container.innerHTML = '<p style="color: #B8B0A0; text-align: center; padding: 40px 20px;">'+sgT('sg_no_orders')+'</p>';
                return;
            }
            try {
                container.innerHTML = orders.map(order =>
                    '<div style="padding:14px 0;border-bottom:1px solid var(--border-light);display:flex;justify-content:space-between;align-items:center">' +
                        '<div>' +
                            '<div style="font-weight:600;color:var(--text-dark);font-size:13px">' + escapeHtml(order.order_code || ('#' + order.order_id)) + '</div>' +
                            '<div style="font-size:11px;color:#B8B0A0;margin-top:3px">' + escapeHtml(order.from_zone || '') + (order.to_zone ? ' → ' + escapeHtml(order.to_zone) : '') + '</div>' +
                        '</div>' +
                        '<div style="text-align:right">' +
                            '<div style="font-weight:700;color:var(--primary)">Br ' + parseFloat(order.fare_total||0).toFixed(2) + '</div>' +
                            '<div style="margin-top:4px">' + getStatusBadge(order.status) + '</div>' +
                        '</div>' +
                    '</div>'
                ).join('');
            } catch(e) {
                container.innerHTML = '<p style="color:#B8B0A0;text-align:center;padding:20px">'+sgT('sg_no_orders')+'</p>';
            }
        }

        function renderAllOrders(orders) {
            const container = document.getElementById('orders-container');
            if (!orders || orders.length === 0) {
                container.innerHTML = '<p style="color: #B8B0A0; text-align: center; padding: 40px 20px;">'+sgT('sg_no_orders')+'</p>';
                return;
            }
            try {
                container.innerHTML = orders.map(function(order) {
                    const status = order.status || 'pending';
                    const orderId = order.order_id || order.id;
                    let actionBtn = '';
                    if (status === 'pending') {
                        actionBtn = '<button ' + _ea('sgUpdateStatus', [orderId, 'preparing']) + ' ' +
                            'style="margin-top:10px;width:100%;padding:9px;background:var(--primary);color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer">' +
                            sgT('sg_start_prep') + '</button>';
                    } else if (status === 'preparing') {
                        actionBtn = '<button ' + _ea('sgUpdateStatus', [orderId, 'accepted']) + ' ' +
                            'style="margin-top:10px;width:100%;padding:9px;background:#2ECC71;color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer">' +
                            escapeHtml(sgT('sg_done_prep')) + '</button>' +
                            // Owner-only - hidden for cashier via the .owner-only-btn CSS
                            // rule (applyStaffRoleUI); the backend enforces this too, so
                            // this is a UI convenience, not the actual security boundary.
                            '<button class="owner-only-btn" ' + _ea('sgReprintTicket', [orderId]) + ' ' +
                            'style="margin-top:6px;width:100%;padding:8px;background:none;color:var(--primary);border:1px solid var(--primary);border-radius:8px;font-size:12px;font-weight:700;cursor:pointer">' +
                            escapeHtml(sgT('sg_reprint')) + '</button>';
                    }
                    if (status === 'pending' || status === 'preparing') {
                        actionBtn += '<button class="btn-decline" ' + _ea('openDecline', [orderId]) + '>' + escapeHtml(sgT('sg_decline')) + '</button>';
                    }
                    return '<div style="padding:16px;border-bottom:1px solid var(--border-light);">' +
                        '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px">' +
                            '<div style="font-weight:700;color:var(--text-dark);">' + escapeHtml(order.order_code || ('#' + orderId)) + '</div>' +
                            '<div>' + getStatusBadge(status) + '</div>' +
                        '</div>' +
                        '<div style="font-size:12px;color:#B8B0A0;">' +
                            (order.from_zone ? sgT('sg_from') + escapeHtml(order.from_zone) + ' → ' : '') + escapeHtml(order.to_zone || '') +
                            (order.recipient_name ? ' · ' + escapeHtml(order.recipient_name) : '') +
                        '</div>' +
                        '<div style="font-size:13px;font-weight:700;color:var(--primary);margin-top:4px">Br ' + parseFloat(order.fare_total||0).toFixed(2) + '</div>' +
                        actionBtn +
                    '</div>';
                }).join('');
            } catch(e) {
                container.innerHTML = UI.error(sgT('sg_d_orders_err'));
            }
        }

        // Decline an order the shop cannot fill (sold out, too busy, closing). Same server rule as everywhere:
        // only before the courier picks it up; the server frees the courier, tells the customer and flags a refund.
        var _declineId = 0;
        var DECLINE_REASONS = [
            ['out_of_stock', 'An item is out of stock'],
            ['too_busy', 'Too busy right now'],
            ['closing', 'We are closing'],
            ['other', 'Another reason']
        ];
        function openDecline(orderId) {
            _declineId = orderId;
            document.getElementById('decline-reasons').innerHTML = DECLINE_REASONS.map(function (r) {
                return '<label class="decline-opt"><input type="radio" name="decline-reason" value="' + r[0] + '" ' + _ea('declinePick', [], { event: 'change' }) + '> ' + escapeHtml(sgT('sg_dec_' + r[0])) + '</label>';
            }).join('');
            document.getElementById('decline-note').value = '';
            document.getElementById('decline-msg').style.display = 'none';
            document.getElementById('decline-go').disabled = false;
            document.getElementById('decline-overlay').classList.add('show');
        }
        function declinePick() {
            Array.prototype.forEach.call(document.querySelectorAll('.decline-opt'), function (l) {
                l.classList.toggle('on', l.querySelector('input').checked);
            });
        }
        function closeDecline() {
            document.getElementById('decline-overlay').classList.remove('show');
            _declineId = 0;
        }
        async function submitDecline() {
            var chosen = document.querySelector('input[name="decline-reason"]:checked');
            var msg = document.getElementById('decline-msg');
            if (!chosen) { msg.textContent = 'Please choose a reason.'; msg.style.display = 'block'; return; }
            var btn = document.getElementById('decline-go');
            btn.disabled = true;
            var res = await apiCall('sg_reject_order', { order_id: _declineId, reason: chosen.value, note: document.getElementById('decline-note').value });
            if (!res) { btn.disabled = false; return; } // apiCall already showed the server message
            closeDecline();
            showNotification(sgT(res.refund_due ? 'sg_d_declined_refund' : 'sg_d_declined'), 'success');
            loadOrders();
        }

        async function sgUpdateStatus(orderId, newStatus) {
            const labels = { preparing: sgT('sg_d_mk_preparing'), accepted: sgT('sg_d_mk_accepted') };
            const res = await apiCall('sg_update_order_status', { order_id: orderId, new_status: newStatus });
            if (!res) return; // apiCall shows error notification on failure
            showNotification(sgT('sg_d_marked').replace('{s}', labels[newStatus] || newStatus), 'success');
            // Reflect the change immediately from what we already know,
            // rather than waiting on a full re-fetch that could momentarily
            // show a stale status on a slow connection.
            if (window._sgOrders) {
                var found = window._sgOrders.find(function (o) { return (o.order_id || o.id) === orderId; });
                if (found) found.status = newStatus;
                renderAllOrders(window._sgOrders);
            }
            if (newStatus === 'preparing' && res.ticket) {
                printKitchenTicket(res.ticket);
            }
            loadOrders();
        }

        // Opens a small, print-only window with a plain kitchen ticket -
        // just what the person actually cooking needs (what to make, how
        // many, any notes), not the customer-facing order chrome. Uses
        // window.print() rather than a PDF/download so it goes straight
        // to whatever printer is already set up at the till, same as a
        // normal receipt printer workflow.
        async function sgReprintTicket(orderId) {
            const res = await apiCall('sg_get_kitchen_ticket', { order_id: orderId });
            if (!res) return; // apiCall shows error notification on failure
            printKitchenTicket(res.ticket);
        }

        // Tries the shop's own directly-connected Bluetooth thermal printer
        // first (see BLEPrinter below) - no third-party bridge app, no
        // injected ads, and it's branded with *this* service giver's own
        // business name, not the platform's. Prints two slips: a kitchen
        // copy (what to make) and a delivery slip (pricing + recipient
        // info, for the courier to hand the customer). Falls back to the
        // old browser-print-dialog ticket only if BLE printing isn't
        // available (unsupported browser, no printer paired, user cancels
        // the pairing prompt, etc.) so the feature still works either way.
        async function printKitchenTicket(ticket) {
            const businessName = (currentServiceGiver && currentServiceGiver.business_name) || (currentUser && currentUser.name) || 'Kitchen';
            if (BLEPrinter.isSupported()) {
                try {
                    await BLEPrinter.printOrderTickets(ticket, businessName);
                    return;
                } catch (e) {
                    console.error('BLE print failed, falling back to browser print dialog:', e);
                    showNotification('Direct printer failed (' + e.message + ') - opening print dialog instead.', 'error');
                }
            }
            printKitchenTicketWindow(ticket, businessName);
        }

        // Shared header/footer markup for both printed slips below.
        // isReprint stamps a "*** REPRINT ***" banner - set from the
        // backend's sg_get_kitchen_ticket response (owner-only reprint
        // path), never on the original auto-print when prep starts.
        function slipHeaderHtml(businessName, title, isReprint) {
            const businessLogoUrl = (currentServiceGiver && currentServiceGiver.logo_url) || '';
            return (businessLogoUrl ? '<img class="header-logo" src="' + escapeHtml(businessLogoUrl) + '">' : '') +
                '<div class="company-name">' + escapeHtml(businessName) + '</div>' +
                '<hr class="rule-solid">' +
                '<h1>' + escapeHtml(title) + '</h1>' +
                (isReprint ? '<div class="reprint-stamp">*** REPRINT ***</div>' : '');
        }
        function slipFooterHtml() {
            // Popup is about:blank, so relative paths won't resolve - build
            // an absolute URL against this page's own location instead.
            const platformLogoUrl = new URL('assets/images/etel-delivery-coin-crop.png', window.location.href).href;
            return '<hr class="rule-dash">' +
                '<div class="footer">' +
                '<img class="footer-logo" src="' + escapeHtml(platformLogoUrl) + '">' +
                '<div class="footer-brand">ETEL DELIVERY</div>' +
                '</div>';
        }

        function kitchenSlipHtml(ticket, businessName) {
            const itemRows = (ticket.items || []).map(function (it) {
                return '<tr><td class="qty">' + (it.quantity || 1) + '×</td><td>' + escapeHtml(it.item_name || '') + '</td></tr>';
            }).join('') || '<tr><td colspan="2" style="color:#666">No itemized items on this order.</td></tr>';
            const placedTime = ticket.placed_at ? new Date(ticket.placed_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';

            return '<div class="slip">' +
                slipHeaderHtml(businessName, 'KITCHEN TICKET', ticket.is_reprint) +
                '<div class="meta">' + escapeHtml(ticket.order_code || '') + (placedTime ? ' &middot; ' + placedTime : '') + '</div>' +
                '<hr class="rule-dash">' +
                '<table>' + itemRows + '</table>' +
                (ticket.recipient_name ? '<hr class="rule-dash"><div class="section-label">For</div><div class="recipient-name">' + escapeHtml(ticket.recipient_name) + '</div>' : '') +
                (ticket.notes ? '<div class="notes">Note: ' + escapeHtml(ticket.notes) + '</div>' : '') +
                slipFooterHtml() +
                '</div>';
        }

        // Courier's copy to hand the customer: pricing + delivery route +
        // recipient contact, no kitchen prep details.
        // A "line-item" row is a name/leader/price flex line (like a paper
        // receipt: "3x Beyaynet ·········· Br 510.00"), used for both the
        // ordered items and the fee breakdown below them.
        function lineItemHtml(label, priceStr, opts) {
            opts = opts || {};
            return '<div class="line-item' + (opts.cls ? ' ' + opts.cls : '') + '">' +
                '<span class="label">' + escapeHtml(label) + '</span>' +
                '<span class="leader"></span>' +
                '<span class="price">' + escapeHtml(priceStr) + '</span>' +
                '</div>';
        }

        function deliverySlipHtml(ticket, businessName) {
            const itemRows = (ticket.items || []).map(function (it) {
                const price = parseFloat(it.subtotal != null ? it.subtotal : (it.unit_price || 0) * (it.quantity || 1));
                return lineItemHtml((it.quantity || 1) + 'x ' + (it.item_name || ''), 'Br ' + price.toFixed(2));
            }).join('') || '<div style="color:#666">No itemized items on this order.</div>';
            const placedTime = ticket.placed_at ? new Date(ticket.placed_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
            const route = (ticket.from_zone || ticket.to_zone) ? (escapeHtml(ticket.from_zone || '') + (ticket.to_zone ? ' &rarr; ' + escapeHtml(ticket.to_zone) : '')) : '';

            const feeRows = [
                ticket.subtotal != null ? lineItemHtml('Subtotal', 'Br ' + parseFloat(ticket.subtotal).toFixed(2), { cls: 'fee-row' }) : '',
                ticket.delivery_fee != null ? lineItemHtml('Delivery Fee', 'Br ' + parseFloat(ticket.delivery_fee).toFixed(2), { cls: 'fee-row' }) : '',
                ticket.service_charge != null ? lineItemHtml('Service Fee', 'Br ' + parseFloat(ticket.service_charge).toFixed(2), { cls: 'fee-row' }) : ''
            ].join('');

            return '<div class="slip">' +
                slipHeaderHtml(businessName, 'DELIVERY RECEIPT', ticket.is_reprint) +
                '<div class="meta">' + escapeHtml(ticket.order_code || '') + (placedTime ? ' &middot; ' + placedTime : '') + '</div>' +
                (route ? '<div class="meta">' + route + '</div>' : '') +
                '<hr class="rule-dash">' +
                itemRows +
                (feeRows ? '<hr class="rule-dash">' + feeRows : '') +
                (ticket.fare_total != null ? '<hr class="rule-dash"><div class="total">TOTAL: Br ' + parseFloat(ticket.fare_total).toFixed(2) + '</div>' : '') +
                ((ticket.recipient_name || ticket.recipient_phone) ?
                    '<hr class="rule-dash">' +
                    (ticket.recipient_name ? '<div class="section-label">For</div><div class="recipient-name">' + escapeHtml(ticket.recipient_name) + '</div>' : '') +
                    (ticket.recipient_phone ? '<div class="recipient-phone">' + escapeHtml(ticket.recipient_phone) + '</div>' : '')
                    : '') +
                '<div class="thanks">Thank you for your order!</div>' +
                slipFooterHtml() +
                '</div>';
        }

        function printKitchenTicketWindow(ticket, businessName) {
            const html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Order ' + escapeHtml(ticket.order_code || '') + '</title>' +
                '<style>' +
                '  @page { size: 80mm auto; margin: 4mm; }' +
                '  body { font-family: "Courier New", monospace; width: 72mm; margin: 0; color: #000; }' +
                '  .slip { page-break-after: always; }' +
                '  .header-logo { display: block; max-width: 90px; max-height: 90px; margin: 0 auto 4px; object-fit: contain; }' +
                '  .company-name { text-align: center; font-size: 14px; font-weight: 900; letter-spacing: 2px; text-transform: uppercase; margin-top: 2px; }' +
                '  .rule-solid { border: none; border-top: 3px solid #000; margin: 8px 0; }' +
                '  .rule-dash { border: none; border-top: 2px dashed #000; margin: 8px 0; }' +
                '  h1 { font-size: 18px; margin: 0 0 2px; text-align: center; letter-spacing: 2px; font-weight: 900; }' +
                '  .reprint-stamp { text-align: center; font-size: 13px; font-weight: 900; letter-spacing: 2px; color: #000; border: 2px solid #000; padding: 3px 0; margin: 6px 0; }' +
                '  .meta { font-size: 12px; font-weight: bold; text-align: center; margin-bottom: 4px; }' +
                '  table { width: 100%; border-collapse: collapse; }' +
                '  td { padding: 5px 2px; vertical-align: top; font-size: 19px; font-weight: 900; line-height: 1.25; }' +
                '  .qty { width: 40px; }' +
                '  .price { text-align: right; font-size: 14px; }' +
                '  .line-item { display: flex; align-items: baseline; font-size: 15px; font-weight: 900; margin: 5px 0; }' +
                '  .line-item .label { white-space: nowrap; }' +
                '  .line-item .leader { flex: 1; border-bottom: 2px dotted #000; margin: 0 5px 3px; min-width: 8px; }' +
                '  .line-item .price { white-space: nowrap; }' +
                '  .line-item.fee-row { font-size: 12px; font-weight: 700; margin: 3px 0; }' +
                '  .section-label { font-size: 10px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 2px; }' +
                '  .recipient-name { font-size: 17px; font-weight: 900; margin-bottom: 4px; }' +
                '  .recipient-phone { font-size: 14px; font-weight: bold; margin-bottom: 4px; }' +
                '  .total { font-size: 20px; font-weight: 900; text-align: center; }' +
                '  .thanks { text-align: center; font-size: 12px; font-weight: bold; margin-top: 10px; }' +
                '  .notes { font-size: 13px; font-weight: bold; margin-top: 6px; padding: 6px 7px; border: 2px solid #000; }' +
                '  .footer { text-align: center; margin-top: 10px; }' +
                '  .footer-logo { display: block; max-width: 26px; max-height: 26px; margin: 0 auto 3px; object-fit: contain; opacity: .85; }' +
                '  .footer-brand { font-size: 9px; font-weight: 700; letter-spacing: 1px; color: #555; }' +
                '</style></head><body>' +
                kitchenSlipHtml(ticket, businessName) +
                deliverySlipHtml(ticket, businessName) +
                '<script>window.onload = function () { window.print(); };<\/script>' +
                '</body></html>';

            const win = window.open('', '_blank', 'width=380,height=600');
            if (!win) {
                showNotification('Please allow pop-ups to print the kitchen ticket.', 'error');
                return;
            }
            win.document.write(html);
            win.document.close();
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str == null ? '' : String(str);
            return div.innerHTML;
        }

        async function loadOrders() {
            const container = document.getElementById('orders-container');
            container.innerHTML = UI.loading(sgT('sg_orders_loading'));
            const res = await apiCall('sg_orders');
            if (!res) {
                container.innerHTML = '<p style="color:#E63946;text-align:center;padding:20px">Failed to load orders. <button ' + _ea('loadOrders') + ' style="background:none;border:none;color:var(--primary);cursor:pointer;font-weight:700">Retry</button></p>';
                return;
            }
            const orders = res.orders || res.assignments || [];
            window._sgOrders = orders;
            renderAllOrders(orders);
            checkPickupConfirmations(); // Check for pending pickup requests on page load
        }

        function getStatusBadge(status) {
            const badges = {
                'pending':    '<span style="padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;background:#FF7A4522;color:#FF7A45">' + sgT('sg_st_pending') + '</span>',
                'preparing':  '<span style="padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;background:#4ECDC422;color:#2AA59D">' + sgT('sg_st_preparing') + '</span>',
                'accepted':   '<span style="padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;background:#C9A96122;color:#8B6E3A">' + sgT('sg_st_accepted') + '</span>',
                'ready':      '<span style="padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;background:#C9A96122;color:#8B6E3A">' + sgT('sg_st_ready') + '</span>',
                'rejected':   '<span style="padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;background:#E6394622;color:#E63946">' + sgT('sg_st_rejected') + '</span>',
                'assigned':   '<span style="padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;background:#9B59B622;color:#9B59B6">' + sgT('sg_st_assigned') + '</span>',
                'picked_up':  '<span style="padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;background:#E9760022;color:#E97600">' + sgT('sg_st_picked_up') + '</span>',
                'in_transit': '<span style="padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;background:#E9760022;color:#E97600">' + sgT('sg_st_in_transit') + '</span>',
                'delivered':  '<span style="padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;background:#2ECC7122;color:#27AE60">' + sgT('sg_st_delivered') + '</span>',
                'cancelled':  '<span style="padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;background:#E6394622;color:#E63946">' + sgT('sg_st_cancelled') + '</span>'
            };
            return badges[status] || '<span style="padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;background:rgba(255,255,255,.1);color:#B8B0A0">' + (status ? escapeHtml(status) : sgT('sg_st_unknown')) + '</span>';
        }

        // ── PICKUP CONFIRMATION POLLING ────────────────────────────────
        async function checkPickupConfirmations() {
            const banner = document.getElementById('sg-pickup-banner');
            if (!banner) return;
            try {
                const res = await apiCall('sg_pending_confirmations');
                if (!res || !res.confirmations || !res.confirmations.length) {
                    banner.style.display = 'none';
                    return;
                }
                const confs = res.confirmations;
                banner.style.display = 'block';
                banner.innerHTML = confs.map(function(c) {
                    const ago = Math.floor((Date.now() - new Date(c.requested_at).getTime()) / 60000);
                    return '<div style="background:linear-gradient(135deg,#9B59B6,#8e44ad);color:#fff;border-radius:14px;padding:18px 20px;margin-bottom:10px;box-shadow:0 4px 20px rgba(155,89,182,.35)">' +
                        '<div style="display:flex;align-items:flex-start;gap:14px">' +
                        '<div style="font-size:32px;line-height:1">🛵</div>' +
                        '<div style="flex:1">' +
                        '<div style="font-size:14px;font-weight:700;margin-bottom:2px">Courier is at your shop!</div>' +
                        '<div style="font-size:13px;opacity:.9;margin-bottom:2px"><strong>' + escapeHtml(c.courier_name || 'Courier') + '</strong> is waiting to pick up order <strong>' + escapeHtml(c.order_code || '') + '</strong></div>' +
                        '<div style="font-size:11px;opacity:.7">Waiting ' + (ago < 1 ? 'just now' : ago + ' min ago') + ' · ETB ' + parseFloat(c.fare_total||0).toFixed(2) + '</div>' +
                        '<div style="margin-top:12px;display:flex;gap:8px">' +
                        '<button ' + _ea('sgConfirmPickup', [c.order_id]) + ' style="padding:9px 20px;background:#fff;color:#8e44ad;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;flex:1">✅ Confirm Pickup</button>' +
                        '</div></div></div></div>';
                }).join('');
            } catch(e) { /* ignore polling errors */ }
        }

        async function sgConfirmPickup(orderId) {
            if (!confirm(sgT('sg_d_confirm_pickup'))) return;
            const res = await apiCall('sg_confirm_pickup', { order_id: orderId });
            if (res) {
                showNotification(sgT('sg_d_pickup_ok'), 'success');
                checkPickupConfirmations();
                loadOrders();
            }
        }

        // Poll every 6 seconds regardless of which page is active
        setInterval(checkPickupConfirmations, 6000);
        // Also show banner on dashboard page
        window.addEventListener('DOMContentLoaded', function() {
            setTimeout(checkPickupConfirmations, 1500);
        });

        /* ── Mobile sidebar toggle ─────────────────────────────── */
        function toggleSidebar() {
            const s = document.getElementById('sidebar');
            const o = document.getElementById('sidebar-overlay');
            const open = s.classList.toggle('open');
            o.classList.toggle('active', open);
            document.body.style.overflow = open ? 'hidden' : '';
        }
        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebar-overlay').classList.remove('active');
            document.body.style.overflow = '';
        }

        let currentSgPage = 'dashboard';
        function switchPage(pageName) {
            currentSgPage = pageName;
            document.querySelectorAll('[id^="page-"]').forEach(p => p.style.display = 'none');
            document.getElementById('page-' + pageName).style.display = 'block';
            document.querySelectorAll('.menu-item').forEach(item => item.classList.remove('active'));
            event.target.closest('.menu-item')?.classList.add('active');
            if (pageName === 'services') loadServices();
            if (pageName === 'orders') loadOrders();
            if (pageName === 'payments') loadSGFinancialReport();
            if (pageName === 'analytics') loadSalesReport();
            if (pageName === 'reviews') loadSgReviews();
            if (pageName === 'staff' && currentStaffRole === 'owner') { loadRoles(); loadStaff(); }
            closeSidebar();
        }

        // Single floating refresh button (see #sg-refresh-btn in the
        // HTML) re-runs whichever page is currently open, in case
        // live/polled data ever stalls without the person noticing.
        // Falls back to just refreshing dashboard+profile data for
        // pages with no dedicated loader of their own (analytics,
        // qr-scanner - a live camera view, nothing to "refresh").
        async function refreshCurrentPage() {
            const btn = document.getElementById('sg-refresh-btn');
            if (btn) btn.classList.add('spinning');
            try {
                switch (currentSgPage) {
                    case 'orders':   await loadOrders(); break;
                    case 'services': await loadServices(); break;
                    case 'payments': await loadSGFinancialReport(); break;
                    case 'analytics': await loadSalesReport(); break;
                    case 'reviews': await loadSgReviews(); break;
                    case 'staff':
                        if (currentStaffRole === 'owner') { await loadRoles(); await loadStaff(); }
                        break;
                    case 'settings':
                        await loadUserProfile();
                        break;
                    default:
                        await loadDashboardData();
                }
                await loadUserProfile();
                showNotification(sgT('sg_d_refreshed'), 'success');
            } catch (e) {
                showNotification(sgT('sg_d_refresh_fail'), 'error');
            } finally {
                if (btn) setTimeout(() => btn.classList.remove('spinning'), 400);
            }
        }

        // ── SERVICES PAGE ────────────────────────────────────────
        let servicesCache = [];

        async function loadServices() {
            document.getElementById('services-container').innerHTML = UI.loading(sgT('sg_it_loading2'));
            const data = await apiCall('sg_get_my_items');
            if (!data) return;
            servicesCache = data.items || [];
            renderServices(servicesCache);
        }

        function renderServices(items) {
            const c = document.getElementById('services-container');
            if (!items.length) {
                c.innerHTML = '<p style="color:#B8B0A0;text-align:center;padding:40px 20px">' + escapeHtml(sgT('sg_it_empty')) + '</p>';
                return;
            }
            const caps = currentCapabilities || [];
            const canManageMenu = caps.indexOf('*') !== -1 || caps.indexOf('menu.manage') !== -1;
            c.innerHTML = `
            <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:13px">
                <thead>
                    <tr style="border-bottom:2px solid #e0e0e0;text-align:left">
                        <th style="padding:10px 8px;color:#B8B0A0;font-weight:600">${sgT('sg_th_image')}</th>
                        <th style="padding:10px 8px;color:#B8B0A0;font-weight:600">${sgT('sg_th_name')}</th>
                        <th style="padding:10px 8px;color:#B8B0A0;font-weight:600">${sgT('sg_th_cat')}</th>
                        <th style="padding:10px 8px;color:#B8B0A0;font-weight:600">${sgT('sg_th_price')}</th>
                        <th style="padding:10px 8px;color:#B8B0A0;font-weight:600">${sgT('sg_th_stock')}</th>
                        <th style="padding:10px 8px;color:#B8B0A0;font-weight:600">${sgT('sg_th_status')}</th>
                        ${canManageMenu ? '<th style="padding:10px 8px;color:#B8B0A0;font-weight:600">' + sgT('sg_th_actions') + '</th>' : ''}
                    </tr>
                </thead>
                <tbody>
                ${items.map(it => `
                    <tr class="sg-item-row" style="border-bottom:1px solid #f0f0f0;transition:background .2s">
                        <td style="padding:10px 8px">
                            ${it.image_url ? `<img src="${escUrl(it.image_url)}" style="width:48px;height:48px;object-fit:cover;border-radius:8px;border:1px solid #e0e0e0">` : '<div style="width:48px;height:48px;background:rgba(255,255,255,.06);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:20px">🛍️</div>'}
                        </td>
                        <td style="padding:10px 8px;font-weight:600;color:#1a1a2e">${escapeHtml(it.name)}${it.quality ? ' <span style="font-size:10px;font-weight:800;text-transform:uppercase;color:#D4AF37">' + escapeHtml(it.quality) + '</span>' : ''}${it.portion ? '<div style="font-size:11px;font-weight:500;color:#B8B0A0">' + escapeHtml(it.portion) + '</div>' : ''}</td>
                        <td style="padding:10px 8px"><span style="background:rgba(212,175,55,.15);color:#D4AF37;border:1px solid rgba(212,175,55,.3);padding:3px 8px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase">${it.category}</span></td>
                        <td style="padding:10px 8px;font-weight:600">ETB ${parseFloat(it.price).toFixed(2)}</td>
                        <td style="padding:10px 8px">${it.stock_quantity}</td>
                        <td style="padding:10px 8px">
                            <label style="display:flex;align-items:center;gap:6px;cursor:${canManageMenu ? 'pointer' : 'default'}">
                                <input type="checkbox" ${it.is_available=='1'||it.is_available===true?'checked':''} ${canManageMenu ? `${_ea('toggleItem', [it.id, EtelActions.CHECKED], { event: 'change' })}` : 'disabled'} style="width:16px;height:16px;cursor:${canManageMenu ? 'pointer' : 'not-allowed'}">
                                <span style="font-size:12px;color:${it.is_available=='1'||it.is_available===true?'#2ecc71':'#aaa'}">${it.is_available=='1'||it.is_available===true?sgT('sg_it_available'):sgT('sg_it_hidden')}</span>
                            </label>
                            ${(it.is_available=='1'||it.is_available===true) && canManageMenu ? (it.sold_out == 1
                                ? `<button ${_ea('setSoldOut', [it.id, 0])} style="margin-top:5px;padding:3px 9px;background:#fff3cd;color:#7a5b00;border:1px solid #e6c85a;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap">${sgT('sg_it_undo')}</button>`
                                : `<button ${_ea('setSoldOut', [it.id, 1])} style="margin-top:5px;padding:3px 9px;background:transparent;color:#7a5b00;border:1px solid #e6c85a;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;white-space:nowrap">${sgT('sg_it_soldout')}</button>`) : ''}
                        </td>
                        ${canManageMenu ? `
                        <td style="padding:10px 8px;display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                            <button ${_ea('openEditItemModal', [it.id])} style="padding:5px 12px;background:#C9A961;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer">${sgT('sg_it_edit')}</button>
                            <button data-name="${escapeHtml(it.name)}" ${_ea('deleteItemFromButton', [it.id, EtelActions.EL])} style="padding:5px 12px;background:#e63946;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer">${sgT('sg_it_del')}</button>
                        </td>` : ''}
                    </tr>`).join('')}
                </tbody>
            </table>
            </div>`;
        }


        // ── MENU CATALOG PICKER ──────────────────────────────────
        // Shared dishes: tick what you sell, type only your price (see api/lib/menu_catalog.php).
        const CAT_LABELS = { food: '🍽️ Food', grocery: '🛒 Grocery', pharmacy: '💊 Pharmacy', package: '📦 Package', flower: '💐 Flowers' };
        let catState = { rows: [], picked: {}, packs: {}, cat: 'food', pack: '', sel: {} };

        function catAttr(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

        async function openCatalog() {
            document.getElementById('catalog-overlay').style.display = 'flex';
            document.getElementById('cat-list').innerHTML = '<p class="empty-msg">Loading catalog…</p>';
            document.getElementById('cat-msg').style.display = 'none';
            document.getElementById('cat-search').value = '';
            const data = await apiCall('sg_catalog_list');
            if (!data) { closeCatalog(); return; }
            const present = {};
            (data.catalog || []).forEach(r => { present[r.category] = 1; });
            catState = { rows: data.catalog || [], picked: data.picked || {}, packs: data.packs || {}, cat: present.food ? 'food' : (Object.keys(present)[0] || 'food'), pack: '', sel: {} };
            catalogRender();
        }

        function closeCatalog() { document.getElementById('catalog-overlay').style.display = 'none'; }

        function catalogSetCat(c) { catState.cat = c; catState.pack = ''; catalogRender(); }
        function catalogSetPack(pk) { catState.pack = (catState.pack === pk) ? '' : pk; catalogRender(); }

        // Tick every dish of the current filter that the shop doesn't sell yet (price still to be typed).
        function catalogTickAll() {
            catalogVisible().forEach(r => { if (!catState.picked[r.id] && !catState.sel[r.id]) catState.sel[r.id] = { price: '', quality: '' }; });
            catalogRender();
        }

        function catalogVisible() {
            const q = document.getElementById('cat-search').value.trim().toLowerCase();
            return catState.rows.filter(r => {
                if (r.category !== catState.cat) return false;
                if (catState.pack && (',' + (r.packs || '') + ',').indexOf(',' + catState.pack + ',') === -1) return false;
                if (q && (r.name || '').toLowerCase().indexOf(q) === -1 && (r.name_am || '').indexOf(q) === -1) return false;
                return true;
            });
        }

        function catalogRender() {
            const cats = {};
            catState.rows.forEach(r => { cats[r.category] = 1; });
            document.getElementById('cat-tabs').innerHTML = Object.keys(cats).map(c =>
                `<button type="button" class="cat-chip${c === catState.cat ? ' on' : ''}" ${_ea('catalogSetCat', [c])}>${CAT_LABELS[c] || c}</button>`).join('');
            const packNames = Object.keys(catState.packs);
            document.getElementById('cat-packs').innerHTML = packNames.length
                ? '<span class="cat-qs">Quick start:</span>' + packNames.map(pk =>
                    `<button type="button" class="cat-chip${pk === catState.pack ? ' on' : ''}" ${_ea('catalogSetPack', [pk])}>${catAttr(pk.replace(/_/g, ' '))} · ${catState.packs[pk]}</button>`).join('') +
                  (catState.pack ? '<button type="button" class="cat-chip"' + _ea('catalogTickAll') + '>✓ Tick all shown</button>' : '')
                : '';
            const rows = catalogVisible();
            if (!rows.length) { document.getElementById('cat-list').innerHTML = '<p class="empty-msg" style="padding:24px;text-align:center">No dishes match.</p>'; catalogCount(); return; }
            let html = '', last = null;
            rows.forEach(r => {
                const grp = r.cuisine || 'Other';
                if (grp !== last) { html += `<div class="cat-group">${escapeHtml(grp)}</div>`; last = grp; }
                const sold = !!catState.picked[r.id];
                const sel = catState.sel[r.id];
                const img = r.image_url ? `<img class="cat-img" src="${catAttr(r.image_url)}" alt="" loading="lazy">` : '<div class="cat-img">🍽️</div>';
                html += `<div class="cat-row${sold ? ' sold' : ''}" data-id="${r.id}">
                    <input type="checkbox" ${sold ? 'checked disabled' : ''} ${sel ? 'checked' : ''} ${_ea('catalogToggle', [r.id, EtelActions.CHECKED], { event: 'change' })}>
                    ${img}
                    <div class="cat-name">${escapeHtml(r.name)}${r.name_am ? '<small>' + escapeHtml(r.name_am) + '</small>' : ''}${sold ? '<span class="cat-tag">✓ On your menu</span>' : ''}</div>
                    ${sold ? '' : `<select class="field-input cat-q" ${sel ? '' : 'disabled'} ${_ea('catalogField', [r.id, 'quality', EtelActions.VALUE], { event: 'change' })}>
                        <option value=""${sel && !sel.quality ? ' selected' : ''}>Quality</option>
                        <option value="regular"${sel && sel.quality === 'regular' ? ' selected' : ''}>Regular</option>
                        <option value="special"${sel && sel.quality === 'special' ? ' selected' : ''}>Special</option>
                        <option value="premium"${sel && sel.quality === 'premium' ? ' selected' : ''}>Premium</option></select>
                    <input type="number" class="field-input cat-price" min="0" step="0.01" placeholder="Price ETB" value="${sel ? catAttr(sel.price) : ''}" ${sel ? '' : 'disabled'} ${_ea('catalogField', [r.id, 'price', EtelActions.VALUE], { event: 'input' })}>`}
                </div>`;
            });
            document.getElementById('cat-list').innerHTML = html;
            catalogCount();
        }

        function catalogToggle(id, on) {
            if (on) catState.sel[id] = catState.sel[id] || { price: '', quality: '' }; else delete catState.sel[id];
            const row = document.querySelector('#cat-list .cat-row[data-id="' + id + '"]');
            if (row) {
                row.querySelectorAll('select,input[type=number]').forEach(e => { e.disabled = !on; });
                if (on) { const pr = row.querySelector('.cat-price'); if (pr) pr.focus(); }
            }
            catalogCount();
        }

        function catalogField(id, field, val) {
            if (!catState.sel[id]) return;
            catState.sel[id][field] = val;
            if (field === 'price') {
                const row = document.querySelector('#cat-list .cat-row[data-id="' + id + '"] .cat-price');
                if (row) row.classList.remove('bad');
            }
            catalogCount();
        }

        function catalogCount() {
            const n = Object.keys(catState.sel).length;
            const btn = document.getElementById('cat-submit');
            btn.disabled = n === 0;
            btn.textContent = n ? sgT('sg_d_cat_add').replace('{n}', n) : sgT('sg_d_cat_tick');
        }

        async function catalogSubmit() {
            const msg = document.getElementById('cat-msg');
            const ids = Object.keys(catState.sel);
            let bad = 0;
            ids.forEach(id => {
                const pr = parseFloat(catState.sel[id].price);
                if (!(pr > 0)) {
                    bad++;
                    const el = document.querySelector('#cat-list .cat-row[data-id="' + id + '"] .cat-price');
                    if (el) el.classList.add('bad');
                }
            });
            if (bad) {
                msg.style.cssText = 'display:block;background:#fde;color:#a00;padding:8px 12px;border-radius:6px;font-size:13px;margin-bottom:10px';
                msg.textContent = 'Enter a price for every ticked dish (' + bad + ' missing). Un-tick the ones you don\'t sell.';
                return;
            }
            msg.style.display = 'none';
            const btn = document.getElementById('cat-submit');
            btn.disabled = true; btn.textContent = sgT('sg_d_cat_adding');
            const picks = ids.map(id => ({ catalog_id: parseInt(id), price: parseFloat(catState.sel[id].price), quality: catState.sel[id].quality || '' }));
            const data = await apiCall('sg_catalog_add', { picks });
            if (!data) { catalogCount(); return; }
            if (data.failed && data.failed.length) {
                showNotification('Could not add ' + data.failed.length + ' dish(es): ' + data.failed[0].error, 'error');
                if (!data.added) { catalogCount(); return; }
            }
            showNotification(sgT('sg_d_cat_added').replace('{n}', data.added) + (data.skipped && data.skipped.length ? sgT('sg_d_cat_skipped').replace('{n}', data.skipped.length) : ''), 'success');
            closeCatalog();
            loadServices();
        }


        // ── MENU CSV IMPORT ──────────────────────────────────────
        // A shop uploads its whole menu from a spreadsheet saved as CSV. Dishes whose name matches the shared
        // catalog are linked to it; the rest become custom items (api/lib/menu_catalog.php: etel_catalog_import_rows).
        function csvParse(text) {
            text = String(text || '').replace(/^﻿/, '');
            const first = text.split(/\r?\n/, 1)[0] || '';
            const counts = { ',': (first.match(/,/g) || []).length, ';': (first.match(/;/g) || []).length, '\t': (first.match(/\t/g) || []).length };
            const d = counts[';'] > counts[','] && counts[';'] >= counts['\t'] ? ';' : (counts['\t'] > counts[','] ? '\t' : ',');
            const rows = []; let row = [], cur = '', q = false;
            for (let i = 0; i < text.length; i++) {
                const ch = text[i];
                if (q) {
                    if (ch === '"') { if (text[i + 1] === '"') { cur += '"'; i++; } else q = false; }
                    else cur += ch;
                } else if (ch === '"') q = true;
                else if (ch === d) { row.push(cur); cur = ''; }
                else if (ch === '\n' || ch === '\r') { if (ch === '\r' && text[i + 1] === '\n') i++; row.push(cur); cur = ''; if (row.some(c => c.trim() !== '')) rows.push(row); row = []; }
                else cur += ch;
            }
            row.push(cur); if (row.some(c => c.trim() !== '')) rows.push(row);
            return rows;
        }

        // Turn parsed CSV rows into [{name, price, ...}] using the header row. Returns {rows, error}.
        function csvToMenuRows(table) {
            if (table.length < 2) return { rows: [], error: 'The file needs a header row and at least one dish.' };
            const alias = { name: ['name', 'dish', 'item', 'title', 'ስም'], price: ['price', 'cost', 'amount', 'ዋጋ'], quality: ['quality'], portion: ['portion', 'size'], category: ['category', 'type'], name_am: ['name_am', 'amharic', 'amharic name', 'አማርኛ'] };
            const head = table[0].map(h => h.trim().toLowerCase());
            const idx = {};
            Object.keys(alias).forEach(k => { idx[k] = head.findIndex(h => alias[k].indexOf(h) !== -1); });
            if (idx.name < 0 || idx.price < 0) return { rows: [], error: 'The first row must have "name" and "price" columns. Download the CSV template to see the format.' };
            const rows = table.slice(1).map(r => {
                const o = {};
                Object.keys(idx).forEach(k => { if (idx[k] >= 0) o[k] = (r[idx[k]] || '').trim(); });
                if (o.price != null) o.price = String(o.price).replace(/[^0-9.,]/g, '').replace(/,(?=\d{3}\b)/g, '').replace(',', '.');
                return o;
            });
            return { rows, error: '' };
        }

        function downloadMenuTemplate() {
            const csv = 'name,price,quality,portion,category,name_am\r\n' +
                'Shiro,90,regular,,food,ሽሮ\r\n' +
                'Doro Wot,220,special,Large,food,ዶሮ ወጥ\r\n' +
                'Macchiato,35,,,food,ማኪያቶ\r\n';
            const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob); a.download = 'etel-menu-template.csv';
            document.body.appendChild(a); a.click();
            setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
        }

        async function importMenuCsv(input) {
            const file = input.files && input.files[0];
            input.value = '';
            if (!file) return;
            let text;
            try { text = await file.text(); } catch (e) { showNotification('Could not read that file', 'error'); return; }
            const parsed = csvToMenuRows(csvParse(text));
            if (parsed.error) { showNotification(parsed.error, 'error'); return; }
            if (parsed.rows.length > 300) { showNotification('Please import at most 300 dishes at a time (this file has ' + parsed.rows.length + ').', 'error'); return; }
            if (!confirm('Import ' + parsed.rows.length + ' dish' + (parsed.rows.length === 1 ? '' : 'es') + ' from "' + file.name + '"?\n\nDishes already on your menu are skipped.')) return;
            const data = await apiCall('sg_catalog_import', { rows: parsed.rows });
            if (!data) return;
            let msg = 'Imported ' + (data.catalog + data.custom) + ' dishes (' + data.catalog + ' matched the catalog, ' + data.custom + ' custom)';
            if (data.skipped) msg += ', ' + data.skipped + ' already on your menu';
            showNotification(msg, 'success');
            const problems = (data.invalid || []).map(x => 'Row ' + (x.row + 1) + ': ' + x.reason).concat((data.failed || []).map(x => 'Row ' + (x.row + 1) + ': ' + x.error));
            if (problems.length) alert('Some rows were not imported:\n\n' + problems.slice(0, 10).join('\n') + (problems.length > 10 ? '\n…and ' + (problems.length - 10) + ' more' : ''));
            loadServices();
        }

        function openAddItemModal() {
            document.getElementById('item-edit-id').value = '';
            document.getElementById('item-modal-title').textContent = sgT('sg_d_item_add');
            document.getElementById('item-name').value = '';
            document.getElementById('item-name-am').value = '';
            document.getElementById('item-desc').value = '';
            document.getElementById('item-desc-am').value = '';
            document.getElementById('item-category').value = 'food';
            document.getElementById('item-quality').value = '';
            document.getElementById('item-portion').value = '';
            document.getElementById('item-suggest').checked = false;
            document.getElementById('item-suggest-row').style.display = '';
            document.getElementById('item-price').value = '';
            document.getElementById('item-orig-price').value = '';
            document.getElementById('item-stock').value = '100';
            document.getElementById('item-prep').value = '30';
            document.getElementById('item-image-url').value = '';
            document.getElementById('item-image-preview').style.display = 'none';
            document.getElementById('item-modal-msg').style.display = 'none';
            const ov = document.getElementById('item-modal-overlay');
            ov.style.display = 'flex';
        }

        function openEditItemModal(id) {
            const it = servicesCache.find(x => x.id == id);
            if (!it) return;
            document.getElementById('item-edit-id').value = it.id;
            document.getElementById('item-modal-title').textContent = sgT('sg_d_item_edit');
            document.getElementById('item-name').value = it.name || '';
            document.getElementById('item-name-am').value = it.name_am || '';
            document.getElementById('item-desc').value = it.description || '';
            document.getElementById('item-desc-am').value = it.description_am || '';
            document.getElementById('item-category').value = it.category || 'food';
            document.getElementById('item-quality').value = it.quality || '';
            document.getElementById('item-portion').value = it.portion || '';
            document.getElementById('item-suggest').checked = false;
            document.getElementById('item-suggest-row').style.display = 'none';
            document.getElementById('item-price').value = it.price || '';
            document.getElementById('item-orig-price').value = it.original_price || '';
            document.getElementById('item-stock').value = it.stock_quantity || 0;
            document.getElementById('item-prep').value = it.preparation_time || 30;
            document.getElementById('item-image-url').value = it.image_url || '';
            const prev = document.getElementById('item-image-preview');
            if (it.image_url) { prev.src = it.image_url; prev.style.display = 'block'; } else prev.style.display = 'none';
            document.getElementById('item-modal-msg').style.display = 'none';
            document.getElementById('item-modal-overlay').style.display = 'flex';
        }

        function closeItemModal() {
            document.getElementById('item-modal-overlay').style.display = 'none';
        }

        function previewItemImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = e => {
                    const prev = document.getElementById('item-image-preview');
                    prev.src = e.target.result;
                    prev.style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        async function submitItem() {
            const editId   = document.getElementById('item-edit-id').value;
            const name     = document.getElementById('item-name').value.trim();
            const nameAm   = document.getElementById('item-name-am').value.trim();
            const desc     = document.getElementById('item-desc').value.trim();
            const descAm   = document.getElementById('item-desc-am').value.trim();
            const category = document.getElementById('item-category').value;
            const price    = parseFloat(document.getElementById('item-price').value);
            const origP    = parseFloat(document.getElementById('item-orig-price').value) || price;
            const stock    = parseInt(document.getElementById('item-stock').value) || 0;
            const prep     = parseInt(document.getElementById('item-prep').value) || 30;
            const msgEl    = document.getElementById('item-modal-msg');

            if (!name) { msgEl.className=''; msgEl.style.cssText='display:block;background:#fde;color:#a00;padding:8px 12px;border-radius:6px;font-size:13px'; msgEl.textContent='Item name is required'; return; }
            if (!price || price <= 0) { msgEl.style.cssText='display:block;background:#fde;color:#a00;padding:8px 12px;border-radius:6px;font-size:13px'; msgEl.textContent='Valid price is required'; return; }

            msgEl.style.cssText='display:block;background:#e8f4fd;color:#0066aa;padding:8px 12px;border-radius:6px;font-size:13px';
            msgEl.textContent = 'Saving…';

            // Upload image if a file is selected
            let image_url = document.getElementById('item-image-url').value.trim();
            const fileInput = document.getElementById('item-image-file');
            if (fileInput.files && fileInput.files[0]) {
                const uploadBase = ETEL.api.replace('boot.php','upload.php');
                const fd = new FormData();
                fd.append('image', fileInput.files[0]);
                try {
                    const ur = await fetch(uploadBase, { method:'POST', headers:{'Authorization':'Bearer '+Auth.getToken()}, body:fd });
                    const uj = await ur.json();
                    if (uj.success) image_url = uj.data.url;
                    else { msgEl.style.cssText='display:block;background:#fde;color:#a00;padding:8px 12px;border-radius:6px;font-size:13px'; msgEl.textContent='Image upload failed: '+uj.message; return; }
                } catch(e) { msgEl.style.cssText='display:block;background:#fde;color:#a00;padding:8px 12px;border-radius:6px;font-size:13px'; msgEl.textContent='Image upload error'; return; }
            }

            const payload = { name, name_am:nameAm, description:desc, description_am:descAm, category, price, original_price:origP, stock_quantity:stock, preparation_time:prep, image_url,
                             quality: document.getElementById('item-quality').value, portion: document.getElementById('item-portion').value.trim() };
            if (!editId && document.getElementById('item-suggest').checked) payload.suggest_catalog = 1;
            let data;
            if (editId) {
                payload.item_id = parseInt(editId);
                data = await apiCall('sg_update_item', payload);
            } else {
                data = await apiCall('sg_add_item', payload);
            }

            if (data) {
                msgEl.style.cssText='display:block;background:#d4edda;color:#155724;padding:8px 12px;border-radius:6px;font-size:13px';
                msgEl.textContent = editId ? '✓ Item updated!' : '✓ Item added!';
                setTimeout(() => { closeItemModal(); loadServices(); }, 900);
            }
        }

        // "Sold out today": customers see it as unavailable until midnight, then it comes back by itself.
        async function setSoldOut(id, soldOut) {
            const data = await apiCall('sg_sold_out', { item_id: id, sold_out: soldOut ? 1 : 0 });
            if (data) showNotification(sgT(soldOut ? 'sg_d_soldout_on' : 'sg_d_soldout_off'), 'success');
            await loadServices();
        }

        async function toggleItem(id, checked) {
            await apiCall('sg_update_item', { item_id: id, is_available: checked ? 1 : 0 });
            await loadServices();
        }

        async function deleteItem(id, name) {
            if (!confirm(sgT('sg_d_confirm_delete').replace('{n}', name))) return;
            const data = await apiCall('sg_delete_item', { item_id: id });
            if (data) { showNotification(sgT('sg_d_item_deleted'), 'success'); loadServices(); }
        }

        function previewSettingsLogo(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = e => {
                    const prev = document.getElementById('settings-logo-preview');
                    const fallback = document.getElementById('settings-logo-fallback');
                    prev.src = e.target.result;
                    prev.style.display = 'block';
                    if (fallback) fallback.style.display = 'none';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // ── Cover photo (wide banner shown on the customer app's shop card) ──
        let _coverRemoved = false;
        function showSettingsCover(url) {
            const img = document.getElementById('settings-cover-preview');
            const empty = document.getElementById('settings-cover-empty');
            const rm = document.getElementById('settings-cover-remove');
            if (!img) return;
            if (url) { img.src = url; img.style.display = 'block'; if (empty) empty.style.display = 'none'; if (rm) rm.style.display = 'inline-flex'; }
            else { img.removeAttribute('src'); img.style.display = 'none'; if (empty) empty.style.display = 'block'; if (rm) rm.style.display = 'none'; }
        }
        function previewSettingsCover(input) {
            if (input.files && input.files[0]) {
                _coverRemoved = false;
                const reader = new FileReader();
                reader.onload = e => showSettingsCover(e.target.result);
                reader.readAsDataURL(input.files[0]);
            }
        }
        function removeSettingsCover() {
            _coverRemoved = true;
            const f = document.getElementById('settings-cover-file'); if (f) f.value = '';
            showSettingsCover('');
        }

        async function saveBusinessSettings() {
            const payload = {
                business_name: document.getElementById('settings-business-name').value,
                service_type: document.getElementById('settings-business-type').value,
                zone: document.getElementById('settings-zone').value
            };

            const fileInput = document.getElementById('settings-logo-file');
            if (fileInput && fileInput.files && fileInput.files[0]) {
                const uploadBase = ETEL.api.replace('boot.php', 'upload.php');
                const fd = new FormData();
                fd.append('image', fileInput.files[0]);
                try {
                    const ur = await fetch(uploadBase, { method: 'POST', headers: { 'Authorization': 'Bearer ' + Auth.getToken() }, body: fd });
                    const uj = await ur.json();
                    if (uj.success) payload.logo_url = uj.data.url;
                    else { showNotification('Logo upload failed: ' + uj.message, 'error'); return; }
                } catch (e) { showNotification('Logo upload error', 'error'); return; }
            }

            const coverInput = document.getElementById('settings-cover-file');
            if (coverInput && coverInput.files && coverInput.files[0]) {
                const uploadBase2 = ETEL.api.replace('boot.php', 'upload.php');
                const fd2 = new FormData();
                fd2.append('image', coverInput.files[0]);
                fd2.append('type', 'shop_cover');
                try {
                    const ur2 = await fetch(uploadBase2, { method: 'POST', headers: { 'Authorization': 'Bearer ' + Auth.getToken() }, body: fd2 });
                    const uj2 = await ur2.json();
                    if (uj2.success) payload.cover_url = uj2.data.url;
                    else { showNotification('Cover upload failed: ' + uj2.message, 'error'); return; }
                } catch (e) { showNotification('Cover upload error', 'error'); return; }
            } else if (_coverRemoved) {
                payload.cover_url = '';
            }

            const data = await apiCall('sg_update_profile', payload);
            if (data) {
                _coverRemoved = false;
                if (coverInput) coverInput.value = '';
                showNotification(sgT('sg_d_settings_saved'), 'success');
                await loadUserProfile();
            }
        }

        async function saveCbeAccount() {
            const data = await apiCall('sg_update_profile', {
                cbe_account: document.getElementById('settings-cbe-account').value,
                cbe_account_name: document.getElementById('settings-cbe-account-name').value
            });
            if (data) {
                showNotification(sgT(data.payout_pending ? 'sg_d_pending_admin' : 'sg_d_cbe_ok'), 'success');
                await loadUserProfile();
            }
        }

        async function saveAbysiniaAccount() {
            const data = await apiCall('sg_update_profile', {
                abysinia_account: document.getElementById('settings-abysinia-account').value,
                abysinia_account_name: document.getElementById('settings-abysinia-account-name').value
            });
            if (data) {
                showNotification(sgT(data.payout_pending ? 'sg_d_pending_admin' : 'sg_d_aby_ok'), 'success');
                await loadUserProfile();
            }
        }

        async function saveTelebirrNumber() {
            const errEl = document.getElementById('settings-telebirr-err');
            const raw = document.getElementById('settings-telebirr-number').value.trim();
            if (errEl) { errEl.style.display = 'none'; errEl.textContent = ''; }
            // Basic Ethiopian mobile format only - this is NOT a real
            // Telebirr integration (no API/merchant credentials exist for
            // this app - see the platform admin's Telebirr Payments tab
            // for the manual-confirmation system that actually processes
            // customer payments). This just catches an obviously wrong
            // number before it gets saved as a payout contact.
            const digitsOnly = raw.replace(/[^0-9+]/g, '');
            const valid = /^(\+2519\d{8}|09\d{8})$/.test(digitsOnly);
            if (raw && !valid) {
                if (errEl) { errEl.style.display = 'block'; errEl.textContent = 'Enter a valid Ethiopian mobile number (e.g. 0912345678).'; }
                return;
            }
            const data = await apiCall('sg_update_profile', {
                telebirr_number: digitsOnly,
                telebirr_account_name: document.getElementById('settings-telebirr-account-name').value
            });
            if (data) {
                showNotification(sgT(data.payout_pending ? 'sg_d_pending_admin' : 'sg_d_tele_ok'), 'success');
                await loadUserProfile();
            }
        }

        // ── FINANCIAL REPORT (payments.view capability) ──────────────────
        // ─── CUSTOMER REVIEWS (read + reply) ───────────────────────────
        const SG_TAG_LABEL = { fast_delivery:'⚡ Fast delivery', great_taste:'😋 Great taste', well_packed:'📦 Well packed', friendly_courier:'🙂 Friendly courier',
            good_value:'💰 Good value', late:'🐢 Late', cold:'🥶 Arrived cold', wrong_item:'❌ Wrong item' };
        async function loadSgReviews() {
            const body = document.getElementById('sg-reviews-body');
            if (!body) return;
            const d = await apiCall('sg_reviews', {});
            if (!d) { body.innerHTML = '<p style="color:#e74c3c">' + sgT('sg_rv_fail') + '</p>'; return; }
            const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            const badge = document.getElementById('sg-reviews-badge');
            if (badge) { badge.textContent = d.unreplied || 0; badge.style.display = d.unreplied ? 'inline-block' : 'none'; }
            const stars = n => '★'.repeat(n) + '☆'.repeat(5 - n);
            let html = '<div class="sr-kpis">' +
                '<div class="sr-kpi"><span>' + sgT('sg_rv_avg') + '</span><b>' + (d.total ? d.average.toFixed(1) : '—') + '</b><em class="sr-flat rv-stars">' + (d.total ? stars(Math.round(d.average)) : '') + '</em></div>' +
                '<div class="sr-kpi"><span>' + sgT('sg_rv_reviews') + '</span><b>' + d.total + '</b><em class="sr-flat">' + sgT('sg_rv_all_time') + '</em></div>' +
                '<div class="sr-kpi"><span>' + sgT('sg_rv_need') + '</span><b>' + d.unreplied + '</b><em class="sr-flat">' + sgT(d.unreplied ? 'sg_rv_reply_build' : 'sg_rv_answered') + '</em></div></div>';
            if (!(d.reviews || []).length) {
                html += '<div style="padding:24px;color:var(--text-dim);text-align:center">' + sgT('sg_rv_none') + '</div>';
            }
            (d.reviews || []).forEach(r => {
                const when = r.rated_at ? new Date(r.rated_at.replace(' ', 'T')).toLocaleDateString() : '';
                html += '<div class="rv-card" id="rv-' + r.id + '">' +
                    '<div class="rv-top"><span class="rv-stars">' + stars(r.stars) + '</span><span style="color:var(--text-faint);font-size:11px">' + esc(r.order_code) + ' · ' + esc(when) + '</span></div>' +
                    (r.tags ? '<div>' + String(r.tags).split(',').map(k => '<span class="rv-tag">' + esc(sgT('sg_tag_' + k) !== 'sg_tag_' + k ? sgT('sg_tag_' + k) : (SG_TAG_LABEL[k] || k)) + '</span>').join('') + '</div>' : '') +
                    (r.comment ? '<div style="font-size:13px;color:var(--text);margin-bottom:4px">' + esc(r.comment) + '</div>' : '') +
                    '<div style="font-size:11px;color:var(--text-faint)">— ' + esc(r.reviewer_first_name || sgT('sg_rv_customer')) + '</div>' +
                    (r.shop_reply ? '<div class="rv-reply"><b style="font-size:10.5px;opacity:.7">' + sgT('sg_rv_your_reply') + '</b><br>' + esc(r.shop_reply) + '</div>' : '') +
                    (d.can_reply ? '<div class="rv-form"><textarea id="rv-text-' + r.id + '" maxlength="300" placeholder="' + (r.shop_reply ? sgT('sg_rv_edit_ph') : sgT('sg_rv_write_ph')) + '">' + esc(r.shop_reply || '') + '</textarea>' +
                        '<button type="button" ' + _ea('replyToReview', [r.id]) + '>' + (r.shop_reply ? sgT('sg_rv_update') : sgT('sg_rv_reply')) + '</button></div>' : '') +
                    '</div>';
            });
            body.innerHTML = html;
        }
        async function replyToReview(id) {
            const ta = document.getElementById('rv-text-' + id);
            const text = ta ? ta.value.trim() : '';
            if (!text) { showNotification(sgT('sg_rv_write_first'), 'error'); return; }
            const res = await apiCall('sg_reply_review', { rating_id: id, reply: text });
            if (res) { showNotification(sgT('sg_rv_sent'), 'success'); loadSgReviews(); }
        }

        // ─── SALES REPORT ───────────────────────────────────────────
        // Live from sg_sales_report. Revenue = item sales of DELIVERED orders only
        // (delivery fee and service charge are not the shop's money); orders / hours /
        // top items count every order that wasn't cancelled.
        async function loadSalesReport(days) {
            const body = document.getElementById('sales-report-body');
            if (!body) return;
            days = days || parseInt((document.querySelector('#sr-range .sr-pill.active') || {}).dataset?.days || '7', 10);
            document.querySelectorAll('#sr-range .sr-pill').forEach(b => b.classList.toggle('active', parseInt(b.dataset.days, 10) === days));
            body.innerHTML = '<div style="padding:20px;color:var(--text-dim)">' + sgT('sg_loading_dots') + '</div>';
            const d = await apiCall('sg_sales_report', { days });
            if (!d) { body.innerHTML = '<p style="color:#e74c3c">' + sgT('sg_sr_fail') + '</p>'; return; }
            const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            const cur = d.current || {}, prev = d.previous || {};
            const label = days === 1 ? sgT('sg_yesterday') : sgT('sg_prev_days').replace('{n}', days);
            const delta = (a, b) => {
                if (a == null || b == null) return '';
                if (!b && !a) return '<em class="sr-flat">' + sgT('sg_no_change') + '</em>';
                if (!b) return '<em class="sr-up">' + sgT('sg_new_vs').replace('{l}', label) + '</em>';
                const p = Math.round((a - b) / b * 100);
                return p === 0 ? '<em class="sr-flat">' + sgT('sg_same_as').replace('{l}', label) + '</em>'
                    : '<em class="' + (p > 0 ? 'sr-up">▲ ' : 'sr-down">▼ ') + sgT('sg_pct_vs').replace('{p}', Math.abs(p)).replace('{l}', label) + '</em>';
            };
            const money = v => v == null ? '—' : 'ETB ' + Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 });
            const avg = (cur.delivered && cur.revenue != null) ? cur.revenue / cur.delivered : null;
            let html = '<div class="sr-kpis">' +
                '<div class="sr-kpi"><span>' + sgT('sg_sr_orders') + '</span><b>' + (cur.orders || 0) + '</b>' + delta(cur.orders, prev.orders) + '</div>' +
                '<div class="sr-kpi"><span>' + sgT('sg_sr_delivered') + '</span><b>' + (cur.delivered || 0) + '</b><em class="sr-flat">' + (cur.cancelled || 0) + ' ' + sgT('sg_sr_cancelled') + '</em></div>' +
                (d.show_money ? '<div class="sr-kpi"><span>' + sgT('sg_sr_sales') + '</span><b>' + money(cur.revenue) + '</b>' + delta(cur.revenue, prev.revenue) + '</div>' +
                    '<div class="sr-kpi"><span>' + sgT('sg_sr_avg') + '</span><b>' + money(avg) + '</b><em class="sr-flat">' + sgT('sg_sr_delivered_orders') + '</em></div>' : '') +
                '</div>';

            // daily bars (fill empty days so the chart is honest)
            const byDate = {}; (d.daily || []).forEach(r => { byDate[r.date] = r; });
            const dayList = [];
            for (let i = days - 1; i >= 0; i--) { const dt = new Date(); dt.setDate(dt.getDate() - i); dayList.push(dt.toISOString().slice(0, 10)); }
            if (days > 1) {
                const vals = dayList.map(k => byDate[k] ? (d.show_money && byDate[k].revenue != null ? byDate[k].revenue : byDate[k].orders) : 0);
                const max = Math.max.apply(null, vals.concat([1]));
                html += '<div class="sr-card"><h4>' + sgT(d.show_money ? 'sg_sr_sales_day' : 'sg_sr_orders_day') + '</h4><div class="sr-bars">' +
                    vals.map((v, i) => '<div class="sr-bar" title="' + dayList[i] + ': ' + (d.show_money ? money(v) : v + ' ' + sgT('sg_sr_orders_n')) + '" style="height:' + Math.max(2, Math.round(v / max * 100)) + '%"></div>').join('') +
                    '</div><div class="sr-axis"><span>' + dayList[0].slice(5) + '</span><span>' + dayList[dayList.length - 1].slice(5) + '</span></div></div>';
            }

            // busy hours
            const hrs = d.hours || [];
            const hmax = Math.max.apply(null, hrs.concat([0]));
            if (hmax > 0) {
                const peak = hrs.indexOf(hmax);
                const fmt = h => (h % 12 === 0 ? 12 : h % 12) + (h < 12 ? ' AM' : ' PM');
                html += '<div class="sr-card"><h4>' + sgT('sg_sr_busy') + ' <span style="font-weight:600;color:var(--text-dim)">· ' + sgT('sg_sr_busiest') + ' ' + fmt(peak) + '</span></h4><div class="sr-bars">' +
                    hrs.map((v, i) => '<div class="sr-bar' + (i === peak ? ' peak' : '') + '" title="' + fmt(i) + ': ' + v + ' ' + sgT('sg_sr_orders_n') + '" style="height:' + Math.max(2, Math.round(v / hmax * 100)) + '%"></div>').join('') +
                    '</div><div class="sr-axis"><span>12 AM</span><span>6 AM</span><span>12 PM</span><span>6 PM</span><span>11 PM</span></div></div>';
            }

            // top items
            const top = d.top_items || [];
            html += '<div class="sr-card"><h4>' + sgT('sg_sr_top') + '</h4>' + (top.length ? top.map((t, i) =>
                '<div class="sr-top"><i>' + (i + 1) + '</i><span class="nm">' + esc(t.name) + '</span><span class="q">' + t.qty + ' ' + sgT('sg_sr_sold') + (t.revenue != null ? ' · ' + money(t.revenue) : '') + '</span></div>').join('')
                : '<div style="color:var(--text-dim);font-size:13px">' + sgT('sg_sr_none') + '</div>') + '</div>';
            body.innerHTML = html;
        }

        async function loadSGFinancialReport() {
            const body = document.getElementById('sg-financial-report-body');
            const rangeSel = document.getElementById('sg-fr-range');
            if (!body) return;
            body.innerHTML = sgT('sg_loading_dots');
            const days = rangeSel ? rangeSel.value : '30';
            const data = await apiCall('sg_financial_report', { days });
            if (!data) { body.innerHTML = '<p style="color:#e74c3c">' + sgT('sg_fin_fail') + '</p>'; return; }

            const methodLabel = { cash: sgT('sg_m_cash'), telebirr: sgT('sg_m_telebirr'), abysinia: sgT('sg_m_abysinia'), cbe: sgT('sg_m_cbe'), wallet: sgT('sg_m_wallet') };
            const cap = (label, value, sub) => `<div style="background:rgba(255,255,255,.05);border:1px solid rgba(212,175,55,.15);border-radius:10px;padding:12px;text-align:center">
                <div style="font-size:17px;font-weight:800;color:#D4AF37">${value}</div>
                <div style="font-size:10.5px;color:#9A9AAE;margin-top:2px;text-transform:uppercase">${label}</div>
                ${sub ? `<div style="font-size:10px;color:#8a7f66;margin-top:2px">${sub}</div>` : ''}
            </div>`;

            const methods = data.by_method || {};
            const methodCards = Object.keys(methods).map(m => {
                const s = methods[m];
                return cap(methodLabel[m] || m,
                    'ETB ' + s.paid_total.toFixed(0),
                    s.paid_count + ' ' + sgT('sg_fin_paid') + (s.unpaid_count ? ` · ${s.unpaid_count} ${sgT('sg_fin_unpaid')} (ETB ${s.unpaid_total.toFixed(0)})` : ''));
            }).join('');

            body.innerHTML = `
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:14px">
                    ${cap(sgT('sg_fin_confirmed'), 'ETB ' + data.paid_total.toFixed(0))}
                    ${cap(sgT('sg_fin_awaiting'), 'ETB ' + data.unpaid_total.toFixed(0))}
                </div>
                ${methodCards ? `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px">${methodCards}</div>`
                              : '<p style="color:#9A9AAE;font-size:13px;text-align:center;padding:16px">' + sgT('sg_sr_none') + '</p>'}
            `;
        }

        // ── STAFF & ROLES MANAGEMENT (owner-only) ────────────────────────
        let rolesCache = [];

        async function loadRoles() {
            const listEl = document.getElementById('sg-roles-list');
            if (!listEl) return;
            listEl.innerHTML = sgT('sg_loading_dots');
            const data = await apiCall('sg_list_roles');
            if (!data) { listEl.innerHTML = '<p style="color:#B8B0A0">' + sgT('sg_d_roles_fail') + '</p>'; return; }
            rolesCache = data.roles || [];
            renderCapabilityCheckboxes(data.capability_catalog || {});
            renderRolesList(rolesCache);
            populateStaffRoleSelect(rolesCache);
        }

        function renderCapabilityCheckboxes(catalog) {
            const box = document.getElementById('role-cap-checkboxes');
            if (!box) return;
            box.innerHTML = Object.keys(catalog).map((key) => {
                return '<label style="display:flex;align-items:flex-start;gap:8px;font-size:12.5px;cursor:pointer">' +
                    '<input type="checkbox" value="' + escapeHtml(key) + '" class="role-cap-checkbox" style="margin-top:2px">' +
                    '<span><b>' + escapeHtml(key) + '</b><br><span style="color:#B8B0A0">' + escapeHtml(catalog[key]) + '</span></span>' +
                '</label>';
            }).join('');
        }

        function renderRolesList(roles) {
            const listEl = document.getElementById('sg-roles-list');
            if (!listEl) return;
            if (!roles.length) {
                listEl.innerHTML = '<p style="color:#B8B0A0;font-size:13px">' + sgT('sg_d_no_roles') + '</p>';
                return;
            }
            listEl.innerHTML = roles.map((r) => {
                const caps = (r.capabilities || []).join(', ');
                return '<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border-light)">' +
                    '<div>' +
                        '<div style="font-weight:700">' + escapeHtml(r.role_name) + ' <span style="font-weight:400;color:#B8B0A0;font-size:12px">' + sgT('sg_d_staff_count').replace('{n}', r.staff_count || 0) + '</span></div>' +
                        '<div style="font-size:11.5px;color:#B8B0A0">' + escapeHtml(caps) + '</div>' +
                    '</div>' +
                    '<div style="display:flex;gap:6px">' +
                        '<button class="btn" style="padding:6px 12px;font-size:12px;background:none;border:1px solid var(--border-light)" ' + _ea('editRoleUI', [r.id]) + '>' + sgT('sg_d_edit') + '</button>' +
                        '<button class="btn" style="padding:6px 12px;font-size:12px;background:none;border:1px solid var(--border-light);color:#E63946" ' + _ea('deleteRoleUI', [r.id]) + '>' + sgT('sg_d_delete') + '</button>' +
                    '</div>' +
                '</div>';
            }).join('');
        }

        function editRoleUI(roleId) {
            const role = rolesCache.find((r) => r.id === roleId);
            if (!role) return;
            document.getElementById('role-edit-id').value = role.id;
            document.getElementById('role-name').value = role.role_name;
            document.querySelectorAll('.role-cap-checkbox').forEach((cb) => {
                cb.checked = (role.capabilities || []).indexOf(cb.value) !== -1;
            });
            document.getElementById('role-cancel-btn').style.display = '';
            document.getElementById('role-name').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function cancelEditRoleUI() {
            document.getElementById('role-edit-id').value = '';
            document.getElementById('role-name').value = '';
            document.querySelectorAll('.role-cap-checkbox').forEach((cb) => { cb.checked = false; });
            document.getElementById('role-cancel-btn').style.display = 'none';
        }

        async function saveRoleUI() {
            const roleId = document.getElementById('role-edit-id').value;
            const roleName = document.getElementById('role-name').value.trim();
            const capabilities = Array.from(document.querySelectorAll('.role-cap-checkbox:checked')).map((cb) => cb.value);
            const msgEl = document.getElementById('role-msg');
            if (msgEl) { msgEl.style.color = ''; msgEl.textContent = ''; }
            if (!roleName) { if (msgEl) { msgEl.style.color = '#E63946'; msgEl.textContent = sgT('sg_d_role_name_req'); } return; }
            if (!capabilities.length) { if (msgEl) { msgEl.style.color = '#E63946'; msgEl.textContent = sgT('sg_d_role_cap_req'); } return; }

            const payload = { role_name: roleName, capabilities };
            if (roleId) payload.role_id = Number(roleId);
            const data = await apiCall('sg_save_role', payload);
            if (data) {
                showNotification(sgT(roleId ? 'sg_d_role_updated' : 'sg_d_role_created'), 'success');
                cancelEditRoleUI();
                loadRoles();
            }
        }

        async function deleteRoleUI(roleId) {
            if (!confirm(sgT('sg_d_confirm_role_del'))) return;
            const data = await apiCall('sg_delete_role', { role_id: roleId });
            if (data) {
                showNotification(sgT('sg_d_role_deleted'), 'success');
                loadRoles();
            }
        }

        function populateStaffRoleSelect(roles) {
            const sel = document.getElementById('staff-role-select');
            if (!sel) return;
            const current = sel.value;
            sel.innerHTML = '<option value="">' + escapeHtml(sgT('sg_d_no_role_long')) + '</option>' +
                roles.map((r) => '<option value="' + r.id + '">' + escapeHtml(r.role_name) + '</option>').join('');
            sel.value = current;
        }

        async function loadStaff() {
            const listEl = document.getElementById('sg-staff-list');
            if (!listEl) return;
            listEl.innerHTML = sgT('sg_loading_dots');
            const data = await apiCall('sg_get_staff');
            if (!data) { listEl.innerHTML = '<p style="color:#B8B0A0">' + sgT('sg_d_staff_fail') + '</p>'; return; }
            renderStaffList(data.staff || []);
        }

        function renderStaffList(staff) {
            const listEl = document.getElementById('sg-staff-list');
            if (!listEl) return;
            if (!staff.length) {
                listEl.innerHTML = '<p style="color:#B8B0A0;font-size:13px">' + sgT('sg_d_no_staff') + '</p>';
                return;
            }
            const roleOptions = (selectedRoleId) => '<option value="">' + escapeHtml(sgT('sg_d_no_role')) + '</option>' +
                rolesCache.map((r) => '<option value="' + r.id + '"' + (String(r.id) === String(selectedRoleId) ? ' selected' : '') + '>' + escapeHtml(r.role_name) + '</option>').join('');
            listEl.innerHTML = staff.map(function (s) {
                const active = !!parseInt(s.active, 10);
                return '<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border-light);gap:10px;flex-wrap:wrap">' +
                    '<div>' +
                        '<div style="font-weight:700">' + escapeHtml(s.name || '') + '</div>' +
                        '<div style="font-size:12px;color:#B8B0A0">' + escapeHtml(s.phone || '') + ' · ' + escapeHtml(s.role_name || sgT('sg_d_cashier')) + ' · ' + sgT(active ? 'sg_d_active' : 'sg_d_deactivated') + '</div>' +
                    '</div>' +
                    '<div style="display:flex;gap:6px;align-items:center">' +
                        '<select class="field-input" style="padding:6px 8px;font-size:12px;width:auto" ' + _ea('reassignStaffRoleUI', [s.id, EtelActions.VALUE], { event: 'change' }) + '>' + roleOptions(s.role_id) + '</select>' +
                        '<button class="btn" style="padding:6px 12px;font-size:12px;background:none;border:1px solid var(--border-light);color:' + (active ? '#E63946' : '#2ECC71') + '" ' + _ea('toggleStaffUI', [s.id, active ? 0 : 1]) + '>' +
                            sgT(active ? 'sg_d_deactivate' : 'sg_d_reactivate') +
                        '</button>' +
                    '</div>' +
                '</div>';
            }).join('');
        }

        async function reassignStaffRoleUI(staffId, roleId) {
            const data = await apiCall('sg_update_staff_role', { staff_id: staffId, role_id: roleId ? Number(roleId) : 0 });
            if (data) showNotification(sgT('sg_d_role_updated'), 'success');
        }

        async function addCashierUI() {
            const name = document.getElementById('staff-name').value.trim();
            const phone = document.getElementById('staff-phone').value.trim();
            const password = document.getElementById('staff-password').value;
            const roleSel = document.getElementById('staff-role-select');
            const roleId = roleSel && roleSel.value ? Number(roleSel.value) : 0;
            const msgEl = document.getElementById('staff-msg');
            if (msgEl) { msgEl.style.color = ''; msgEl.textContent = ''; }

            if (!name || !phone) {
                if (msgEl) { msgEl.style.color = '#E63946'; msgEl.textContent = sgT('sg_d_staff_req'); }
                return;
            }
            // Password is only required if this phone turns out to have no
            // EtelID yet - the backend checks that and tells us so if it's
            // missing, rather than the form guessing ahead of time.
            const payload = { name, phone };
            if (password) payload.password = password;
            if (roleId) payload.role_id = roleId;
            const data = await apiCall('sg_add_staff', payload);
            if (data) {
                showNotification(sgT(data.linked_existing ? 'sg_d_staff_linked' : 'sg_d_staff_new'), 'success');
                document.getElementById('staff-name').value = '';
                document.getElementById('staff-phone').value = '';
                document.getElementById('staff-password').value = '';
                if (roleSel) roleSel.value = '';
                loadStaff();
            }
        }

        async function toggleStaffUI(staffId, nextActive) {
            const data = await apiCall('sg_remove_staff', { staff_id: staffId, active: nextActive });
            if (data) {
                showNotification(sgT(nextActive ? 'sg_d_staff_react' : 'sg_d_staff_deact'), 'success');
                loadStaff();
            }
        }

        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 16px 24px;
                background: ${type === 'success' ? 'var(--accent-green)' : 'var(--accent-red)'};
                color: white;
                border-radius: 10px;
                font-weight: 600;
                z-index: 10001;
                animation: slideIn 0.3s ease;
            `;
            notification.textContent = message;
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 3000);
        }

        function logout() {
            Auth.logout();
        }

        async function startQRScanner() {
            const video = document.getElementById('qr-video');
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                video.srcObject = stream;
            } catch (error) {
                showNotification(cameraAccessMessage(error), 'error');
            }
        }

        // ── Direct Bluetooth (BLE) thermal printer support ──────────────
        // Talks straight to a paired 58mm ESC/POS printer (e.g. P58E) over
        // Web Bluetooth, bypassing bridge apps like RawBT entirely - those
        // inject their own ad/watermark text into every print job, which
        // is exactly what this replaces. Only works in Chrome-family
        // browsers over HTTPS; printKitchenTicket() above falls back to
        // the old window.print() dialog automatically if unsupported or if
        // the printer isn't reachable.
        //
        // The printer's exact GATT service/characteristic UUIDs vary by
        // manufacturer. GATT_CANDIDATES lists every pattern known to work
        // on the printers we've tested (P58E confirmed working across all
        // three) - connect() walks the device's services and just uses
        // whichever writable characteristic it finds first.
        const BLEPrinter = (function () {
            const GATT_CANDIDATES = [
                '000018f0-0000-1000-8000-00805f9b34fb', // common clone "printer service"
                '49535343-fe7d-4ae5-8fa9-9fafd205e455', // ISSC/Microchip transparent UART
                'e7810a71-73ae-499d-8c15-faa9aef0c3f2'  // seen on some P58E units
            ];
            const DEVICE_ID_KEY = 'etel_sg_printer_device_id';
            let cachedDevice = null;
            let cachedChar = null;

            function isSupported() {
                return !!(navigator.bluetooth);
            }

            async function pickDevice() {
                // Try to reconnect to a previously-granted device without a
                // new picker prompt first (Chrome's persistent Bluetooth
                // permissions) - only falls back to requestDevice() (which
                // needs a user gesture, fine since this always runs from a
                // click) when that isn't available or finds nothing.
                if (navigator.bluetooth.getDevices) {
                    try {
                        const known = await navigator.bluetooth.getDevices();
                        const savedId = localStorage.getItem(DEVICE_ID_KEY);
                        const match = known.find(d => d.id === savedId) || known.find(d => (d.name || '').toUpperCase().indexOf('P58E') !== -1);
                        if (match) return match;
                    } catch (e) { /* fall through to picker */ }
                }
                const device = await navigator.bluetooth.requestDevice({
                    acceptAllDevices: true,
                    optionalServices: GATT_CANDIDATES
                });
                localStorage.setItem(DEVICE_ID_KEY, device.id);
                return device;
            }

            async function findWritableCharacteristic(server) {
                const services = await server.getPrimaryServices();
                for (const svc of services) {
                    const chars = await svc.getCharacteristics();
                    for (const ch of chars) {
                        if (ch.properties && (ch.properties.write || ch.properties.writeWithoutResponse)) {
                            return ch;
                        }
                    }
                }
                return null;
            }

            async function connect() {
                if (cachedChar && cachedDevice && cachedDevice.gatt.connected) return cachedChar;
                const device = cachedDevice || await pickDevice();
                const server = await device.gatt.connect();
                const char = await findWritableCharacteristic(server);
                if (!char) throw new Error('Printer has no writable Bluetooth channel');
                cachedDevice = device;
                cachedChar = char;
                return char;
            }

            async function sendBytes(bytes) {
                const char = await connect();
                const chunkSize = 20; // conservative default BLE MTU
                for (let i = 0; i < bytes.length; i += chunkSize) {
                    const chunk = bytes.slice(i, i + chunkSize);
                    if (char.properties.writeWithoutResponse) {
                        await char.writeValueWithoutResponse(chunk);
                    } else {
                        await char.writeValue(chunk);
                    }
                    // Cheap BLE thermal printers have a small receive buffer -
                    // pacing writes avoids dropped bytes on larger payloads
                    // like the raster logo images below (plain text tickets
                    // never hit enough chunks for this to be noticeable).
                    await new Promise(r => setTimeout(r, 8));
                }
            }

            // ESC/POS byte builder --------------------------------------
            function esc(bytes, ...vals) { vals.forEach(v => bytes.push(v)); }
            function text(bytes, str) { bytes.push(...new TextEncoder().encode(str)); }
            function append(bytes, arr) { for (let i = 0; i < arr.length; i++) bytes.push(arr[i]); }

            // "3x Beyaynet ------------ Br 510.00" - left label and right
            // price on one line, connected by a dash leader that fills
            // whatever space is left. Printer is 32 chars/line at normal
            // size; if the label alone is already close to that, fall back
            // to a single separating space instead of a negative-length
            // dash run.
            function padLine(left, right, width) {
                width = width || 32;
                const gap = width - left.length - right.length - 2; // 2 = the spaces around the dashes
                if (gap < 1) return left + ' ' + right;
                return left + ' ' + '-'.repeat(gap) + ' ' + right;
            }

            // Converts an image URL into an ESC/POS "GS v 0" raster bitmap:
            // draws it to a canvas, thresholds to 1-bit black/white, and
            // packs 8 pixels per byte (MSB first) as the format requires.
            // Width is rounded up to a multiple of 8 since the format only
            // addresses whole bytes per row.
            function imageUrlToRasterBytes(url, maxWidthPx) {
                return new Promise((resolve, reject) => {
                    const img = new Image();
                    img.crossOrigin = 'anonymous';
                    img.onload = () => {
                        try {
                            const scale = Math.min(1, maxWidthPx / img.naturalWidth);
                            const rawW = Math.max(8, Math.round(img.naturalWidth * scale));
                            const w = Math.ceil(rawW / 8) * 8;
                            const h = Math.max(1, Math.round(img.naturalHeight * scale));

                            const canvas = document.createElement('canvas');
                            canvas.width = w; canvas.height = h;
                            const ctx = canvas.getContext('2d');
                            ctx.fillStyle = '#fff';
                            ctx.fillRect(0, 0, w, h); // flatten transparency onto white before thresholding
                            ctx.drawImage(img, 0, 0, w, h);
                            const px = ctx.getImageData(0, 0, w, h).data;

                            // Photographic/gradient logos (like the gold coin
                            // mark) turn into a solid black blob under a flat
                            // threshold - most of a gold gradient sits below
                            // any single cutoff. Floyd-Steinberg dithering
                            // diffuses each pixel's rounding error into its
                            // neighbors, so mid-tones render as a dot pattern
                            // that the eye reads as gray - this is what makes
                            // detail (the drone, text, wing shading) actually
                            // survive the drop to 1-bit at print resolution.
                            const gray = new Float32Array(w * h);
                            for (let y = 0; y < h; y++) {
                                for (let x = 0; x < w; x++) {
                                    const i = (y * w + x) * 4;
                                    const lum = 0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2];
                                    // Transparent pixels are already flattened to white by
                                    // the fillRect above, but alpha-blend explicitly too in
                                    // case of partial transparency (soft edges).
                                    const a = px[i + 3] / 255;
                                    gray[y * w + x] = lum * a + 255 * (1 - a);
                                }
                            }

                            const widthBytes = w / 8;
                            const bitmap = new Uint8Array(widthBytes * h);
                            for (let y = 0; y < h; y++) {
                                for (let x = 0; x < w; x++) {
                                    const idx = y * w + x;
                                    const old = gray[idx];
                                    const isBlack = old < 128;
                                    if (isBlack) bitmap[y * widthBytes + (x >> 3)] |= (0x80 >> (x & 7));
                                    const err = old - (isBlack ? 0 : 255);
                                    if (x + 1 < w) gray[idx + 1] += err * 7 / 16;
                                    if (y + 1 < h) {
                                        if (x > 0) gray[idx - 1 + w] += err * 3 / 16;
                                        gray[idx + w] += err * 5 / 16;
                                        if (x + 1 < w) gray[idx + 1 + w] += err * 1 / 16;
                                    }
                                }
                            }

                            const header = [0x1D, 0x76, 0x30, 0x00, widthBytes & 0xFF, (widthBytes >> 8) & 0xFF, h & 0xFF, (h >> 8) & 0xFF];
                            const out = new Uint8Array(header.length + bitmap.length);
                            out.set(header, 0);
                            out.set(bitmap, header.length);
                            resolve(out);
                        } catch (e) { reject(e); }
                    };
                    img.onerror = () => reject(new Error('Could not load logo image'));
                    img.src = url;
                });
            }

            const DASH = '--------------------------------\n';

            // Shared by both slips: business's own logo (if uploaded) + bold
            // business name at normal width - see note below on why not
            // double-width. `title` is the line under the name identifying
            // which slip this is ("KITCHEN TICKET" vs "DELIVERY RECEIPT").
            async function writeHeader(bytes, businessName, title, isReprint) {
                esc(bytes, 0x1B, 0x61, 0x01);        // center align
                const businessLogoUrl = (currentServiceGiver && currentServiceGiver.logo_url) || '';
                if (businessLogoUrl) {
                    try {
                        append(bytes, await imageUrlToRasterBytes(businessLogoUrl, 240));
                        text(bytes, '\n');
                    } catch (e) { console.error('Business logo print skipped:', e); }
                }
                // Font A (default) fits ~32 chars/line - fine for most
                // names, but a long one like "ETEL Technology and Business
                // Group" (35 chars) still wraps and breaks a word mid-way
                // ("GRO" / "UP"). Font B is the printer's condensed
                // typeface (~42 chars/line on this hardware) - only switch
                // to it when the name actually needs the extra room, so
                // short names still get the bigger, more legible Font A.
                const nameUpper = (businessName || 'ETEL DELIVERY').toUpperCase();
                const useFontB = nameUpper.length > 32;
                esc(bytes, 0x1B, 0x45, 0x01);        // bold on
                if (useFontB) esc(bytes, 0x1B, 0x4D, 0x01); // Font B
                text(bytes, nameUpper + '\n');
                if (useFontB) esc(bytes, 0x1B, 0x4D, 0x00); // back to Font A
                text(bytes, title + '\n');
                esc(bytes, 0x1B, 0x45, 0x00);        // bold off
                if (isReprint) {
                    esc(bytes, 0x1B, 0x45, 0x01);    // bold on
                    text(bytes, '*** REPRINT ***\n');
                    esc(bytes, 0x1B, 0x45, 0x00);    // bold off
                }
                text(bytes, DASH);
            }

            // Shared by both slips: small ETEL Delivery platform mark - the
            // platform's own branding stays secondary/small, business
            // branding stays the primary identity on the printed slip.
            async function writeFooter(bytes) {
                esc(bytes, 0x1B, 0x61, 0x01);        // center align
                try {
                    const platformLogoUrl = new URL('assets/images/etel-delivery-coin-crop.png', window.location.href).href;
                    append(bytes, await imageUrlToRasterBytes(platformLogoUrl, 160));
                    text(bytes, '\n');
                } catch (e) { console.error('Platform logo print skipped:', e); }
                text(bytes, 'ETEL DELIVERY\n');
                text(bytes, '\n\n\n');
                esc(bytes, 0x1D, 0x56, 0x00);        // cut (ignored harmlessly if no cutter)
            }

            // Kitchen copy: what to make, for whoever is cooking/packing -
            // no pricing, just items and any prep notes.
            async function buildKitchenTicketBytes(ticket, businessName) {
                const bytes = [];
                esc(bytes, 0x1B, 0x40);             // init
                await writeHeader(bytes, businessName, 'KITCHEN TICKET', ticket.is_reprint);

                esc(bytes, 0x1B, 0x61, 0x00);        // left align
                const placedTime = ticket.placed_at ? new Date(ticket.placed_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
                text(bytes, (ticket.order_code || '') + (placedTime ? '  ' + placedTime : '') + '\n');
                text(bytes, DASH);

                esc(bytes, 0x1D, 0x21, 0x01);        // double height only - readable, still fits width
                esc(bytes, 0x1B, 0x45, 0x01);        // bold on
                const items = ticket.items || [];
                if (items.length) {
                    items.forEach(it => text(bytes, (it.quantity || 1) + 'x ' + (it.item_name || '') + '\n'));
                } else {
                    text(bytes, 'No itemized items\n');
                }
                esc(bytes, 0x1D, 0x21, 0x00);        // normal size
                esc(bytes, 0x1B, 0x45, 0x00);        // bold off

                if (ticket.recipient_name) {
                    text(bytes, DASH);
                    esc(bytes, 0x1B, 0x45, 0x01);
                    text(bytes, 'FOR: ' + ticket.recipient_name + '\n');
                    esc(bytes, 0x1B, 0x45, 0x00);
                }
                if (ticket.notes) {
                    text(bytes, DASH);
                    text(bytes, 'NOTE: ' + ticket.notes + '\n');
                }
                text(bytes, DASH);
                await writeFooter(bytes);
                return new Uint8Array(bytes);
            }

            // Delivery slip: the courier's copy to hand to the customer -
            // pricing, delivery route, and recipient contact, no kitchen
            // prep details.
            async function buildDeliverySlipBytes(ticket, businessName) {
                const bytes = [];
                esc(bytes, 0x1B, 0x40);             // init
                await writeHeader(bytes, businessName, 'DELIVERY RECEIPT', ticket.is_reprint);

                esc(bytes, 0x1B, 0x61, 0x00);        // left align
                const placedTime = ticket.placed_at ? new Date(ticket.placed_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
                text(bytes, (ticket.order_code || '') + (placedTime ? '  ' + placedTime : '') + '\n');
                if (ticket.from_zone || ticket.to_zone) {
                    text(bytes, (ticket.from_zone || '') + (ticket.to_zone ? ' -> ' + ticket.to_zone : '') + '\n');
                }
                text(bytes, DASH);

                const items = ticket.items || [];
                if (items.length) {
                    items.forEach(it => {
                        const price = parseFloat(it.subtotal != null ? it.subtotal : (it.unit_price || 0) * (it.quantity || 1));
                        const left = (it.quantity || 1) + 'x ' + (it.item_name || '');
                        const right = 'Br ' + price.toFixed(2);
                        text(bytes, padLine(left, right, 32) + '\n');
                    });
                } else {
                    text(bytes, 'No itemized items\n');
                }
                text(bytes, DASH);

                // Subtotal + delivery + service fee breakdown, so the
                // customer can see what the total is made of, not just the
                // final number.
                const hasFeeBreakdown = ticket.subtotal != null || ticket.delivery_fee != null || ticket.service_charge != null;
                if (hasFeeBreakdown) {
                    if (ticket.subtotal != null) text(bytes, padLine('Subtotal', 'Br ' + parseFloat(ticket.subtotal).toFixed(2), 32) + '\n');
                    if (ticket.delivery_fee != null) text(bytes, padLine('Delivery Fee', 'Br ' + parseFloat(ticket.delivery_fee).toFixed(2), 32) + '\n');
                    if (ticket.service_charge != null) text(bytes, padLine('Service Fee', 'Br ' + parseFloat(ticket.service_charge).toFixed(2), 32) + '\n');
                    text(bytes, DASH);
                }

                if (ticket.fare_total != null) {
                    esc(bytes, 0x1D, 0x21, 0x01);    // double height
                    esc(bytes, 0x1B, 0x45, 0x01);    // bold on
                    text(bytes, 'TOTAL: Br ' + parseFloat(ticket.fare_total).toFixed(2) + '\n');
                    esc(bytes, 0x1D, 0x21, 0x00);
                    esc(bytes, 0x1B, 0x45, 0x00);    // bold off
                    text(bytes, DASH);
                }

                if (ticket.recipient_name || ticket.recipient_phone) {
                    esc(bytes, 0x1B, 0x45, 0x01);
                    if (ticket.recipient_name) text(bytes, 'FOR: ' + ticket.recipient_name + '\n');
                    if (ticket.recipient_phone) text(bytes, 'TEL: ' + ticket.recipient_phone + '\n');
                    esc(bytes, 0x1B, 0x45, 0x00);
                    text(bytes, DASH);
                }

                esc(bytes, 0x1B, 0x61, 0x01);        // center align
                text(bytes, 'Thank you for your order!\n');
                text(bytes, DASH);
                await writeFooter(bytes);
                return new Uint8Array(bytes);
            }

            // Prints both slips back-to-back in one connection: kitchen copy
            // first (for prep), then the delivery slip (for the courier to
            // hand the customer).
            async function printOrderTickets(ticket, businessName) {
                await sendBytes(await buildKitchenTicketBytes(ticket, businessName));
                await sendBytes(await buildDeliverySlipBytes(ticket, businessName));
            }

            function forget() {
                cachedDevice = null;
                cachedChar = null;
                localStorage.removeItem(DEVICE_ID_KEY);
            }

            return { isSupported, connect, printOrderTickets, forget };
        })();

        // Settings-page handlers - each runs directly from its own button
        // click (a fresh user gesture), which is required for the Bluetooth
        // device picker on first-ever pairing. Once paired, BLEPrinter
        // reuses the connection silently from printKitchenTicket().
        async function connectPrinterUI() {
            const statusEl = document.getElementById('printer-status');
            if (!BLEPrinter.isSupported()) {
                if (statusEl) { statusEl.textContent = '✕ This browser does not support direct printer connections (use Chrome)'; }
                showNotification(sgT('sg_d_print_chrome'), 'error');
                return;
            }
            if (statusEl) statusEl.textContent = 'Connecting…';
            try {
                await BLEPrinter.connect();
                if (statusEl) statusEl.textContent = '● Printer connected';
                showNotification(sgT('sg_d_print_ok'), 'success');
            } catch (e) {
                if (statusEl) statusEl.textContent = '○ Not connected';
                showNotification('Could not connect: ' + e.message, 'error');
            }
        }

        function forgetPrinterUI() {
            BLEPrinter.forget();
            const statusEl = document.getElementById('printer-status');
            if (statusEl) statusEl.textContent = '○ Not connected';
            showNotification(sgT('sg_d_print_forgot'), 'success');
        }
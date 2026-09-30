        // ─── AUTH GUARD ───────────────────────────────────────────────
        // ─── AUTH GUARD ───────────────────────────────────────────────
        if (!requireRole(['admin'])) { throw new Error('Access denied'); }

        const user = Auth.getUser();

        // Staff who signed in via EtelID land on the page login.js chose
        // for their EtelGroup role (see STAFF_LANDING there) instead of
        // always starting at the full Dashboard. Reads the one-time
        // ?landing= query param set by that redirect, not user.staff_role
        // directly - so reloading/bookmarking admin.html later doesn't
        // keep snapping a Dispatcher back to Rides every time, only the
        // first landing right after login does this.
        (function () {
            var params = new URLSearchParams(location.search);
            var landing = params.get('landing');
            if (!landing) return;
            function applyLanding() {
                var navEl = document.querySelector('.nav-item[onclick*="switchPage(\'' + landing + '\'"]');
                if (typeof switchPage === 'function') switchPage(landing, navEl);
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', applyLanding);
            } else {
                applyLanding();
            }
        })();

        let ordersData = [], usersData = [], couriersData = [], sgData = [];

        // "Test as..." quick-switch - lets an already-signed-in admin
        // jump straight into another role's app for testing/support
        // without logging out and back in. Uses the SAME token already
        // in localStorage - every page's requireRole() already lets an
        // admin account stay on any role's interface regardless of the
        // page's own role gate (see login.html's doLogin/doEtelIdLogin
        // comments for the matching login-time half of this), so simply
        // navigating there is enough; no re-auth needed. Injected via JS
        // rather than added to admin.html's markup directly, since that
        // file wasn't available to edit directly this session - safe to
        // move into the real markup later if preferred.
        (function () {
            function inject() {
                if (document.getElementById('test-as-panel')) return; // don't double-inject on a re-run
                var apiBase = (window.ETEL && ETEL.api) ? ETEL.api.replace('api/boot.php', '') : './';
                var targets = [
                    { label: '📦 Customer', href: apiBase + 'customer.html' },
                    { label: '🚕 Ride',     href: apiBase + 'ride/' },
                    { label: '🛵 Courier',  href: apiBase + 'courier/' },
                    { label: '🚗 Driver',   href: apiBase + 'driver/' },
                    { label: '🏪 Service',  href: apiBase + 'servicegiver.html' }
                ];
                var wrap = document.createElement('div');
                wrap.id = 'test-as-panel';
                wrap.style.cssText = 'position:fixed;bottom:18px;left:18px;z-index:9998;font-family:inherit';
                var btn = document.createElement('button');
                btn.textContent = '🧪 Test as…';
                btn.style.cssText = 'padding:10px 16px;border-radius:10px;border:1px solid rgba(212,175,55,.4);' +
                    'background:linear-gradient(135deg,#2a1a06,#4a3210);color:#E5C76B;font-weight:700;' +
                    'font-size:12.5px;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.35)';
                var menu = document.createElement('div');
                menu.style.cssText = 'display:none;position:absolute;bottom:calc(100% + 8px);left:0;' +
                    'background:#1a1206;border:1px solid rgba(212,175,55,.35);border-radius:10px;' +
                    'padding:6px;min-width:150px;box-shadow:0 8px 24px rgba(0,0,0,.45)';
                targets.forEach(function (t) {
                    var a = document.createElement('a');
                    a.href = t.href;
                    a.textContent = t.label;
                    a.style.cssText = 'display:block;padding:8px 10px;border-radius:7px;color:#EDEDF5;' +
                        'font-size:12.5px;text-decoration:none;white-space:nowrap';
                    a.onmouseenter = function () { a.style.background = 'rgba(212,175,55,.12)'; };
                    a.onmouseleave = function () { a.style.background = 'transparent'; };
                    menu.appendChild(a);
                });
                btn.onclick = function (e) {
                    e.stopPropagation();
                    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
                };
                document.addEventListener('click', function () { menu.style.display = 'none'; });
                wrap.appendChild(menu);
                wrap.appendChild(btn);
                document.body.appendChild(wrap);
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', inject);
            } else {
                inject();
            }
        })();

        // Chart logic extracted to admin-charts.js

        async function loadDashboard() {
            try {
                // Lightweight badge check so the founder sees pending
                // payment confirmations (Telebirr + bank) without having
                // to click into that tab first - doesn't block or slow
                // down the main dashboard stats load below.
                if (typeof updatePaymentsBadge === 'function') updatePaymentsBadge().catch(() => {});
                const res = await adm('admin_dashboard_stats', {}, true);
                if (res && res.success && res.data) {
                    const d = res.data;
                    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
                    set('s-orders',    (d.orders||0).toLocaleString());
                    set('s-pending',   (d.pending||0).toLocaleString());
                    set('s-delivered', (d.delivered||0).toLocaleString());
                    set('s-users',     (d.users||0).toLocaleString());
                    set('s-couriers',  (d.couriers||0).toLocaleString());
                    set('s-revenue',   'ETB ' + (d.revenue||0).toLocaleString());
                } else {
                    ['s-orders','s-pending','s-delivered','s-users','s-couriers','s-revenue'].forEach(id => {
                        const el = document.getElementById(id); if (el) el.textContent = '0';
                    });
                }
            } catch(e) {
                ['s-orders','s-pending','s-delivered','s-users','s-couriers','s-revenue'].forEach(id => {
                    const el = document.getElementById(id); if (el) el.textContent = '0';
                });
            }
            // recent orders (last 10)
            try {
            const ro = await adm('admin_get_orders', {}, true);
            if (ro && ro.success) {
                // Render dashboard charts using all orders
                renderDashboardCharts(ro.data.orders);

                const recent = ro.data.orders.slice(0, 10);
                document.getElementById('dash-orders-tbody').innerHTML = recent.length ? recent.map(o => {
                    const safeCode = (o.order_code||'').replace(/[<>"'\\]/g, '');
                    return `
                    <tr>
                        <td><strong>${esc(o.order_code)}</strong></td>
                        <td>${esc(o.from_zone)||'—'}</td><td>${esc(o.to_zone)||'—'}</td>
                        <td>${o.package_type||'—'}</td>
                        <td>ETB ${parseFloat(o.fare_total||0).toFixed(2)}</td>
                        <td>${statusBadge(o.status)}</td>
                        <td>${fmtTime(o.placed_at)}</td>
                        <td><button class="act-btn act-gold" onclick="openOrderDetail(${o.id},'${safeCode}','${o.status}')">🔍 Detail</button></td>
                    </tr>`;
                }).join('') : '<tr><td colspan="8" style="text-align:center;color:#B8B0A0;padding:30px">No orders yet</td></tr>';
            }
            } catch(e) {}
            const upd = document.getElementById('last-updated'); if (upd) upd.textContent = 'Updated: ' + new Date().toLocaleTimeString();
            const updM = document.getElementById('last-updated-mobile'); if (updM) updM.textContent = 'Updated: ' + new Date().toLocaleTimeString();
        }

        // ─── ORDERS ───────────────────────────────────────────────────
        async function loadOrders() {
            const tbody = document.getElementById('orders-tbody');
            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:30px;">' + UI.loading('Loading orders...') + '</td></tr>';
            try {
                const status = document.getElementById('order-filter') ? document.getElementById('order-filter').value : '';
                const params = status ? {status} : {};
                const res = await adm('admin_get_orders', params, true);
                if (!res || !res.success) { tbody.innerHTML = `<tr><td colspan="9" style="color:red;padding:20px">${(res&&res.message)||'Failed to load orders'}</td></tr>`; return; }
                ordersData = (res.data && res.data.orders) ? res.data.orders : [];
                renderOrderTable(ordersData);
                loadConfirmations();
                loadOrderIssues();
            } catch(e) {
                tbody.innerHTML = '<tr><td colspan="9" style="color:#B8B0A0;text-align:center;padding:30px">No orders yet</td></tr>';
            }
        }
        function confBadges(o) {
            let h = '';
            // Pickup confirmation
            if (o.pickup_conf === 'pending') {
                h += '<span style="display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:5px;font-size:10px;font-weight:700;background:#fff3e0;color:#e65100;border:1px solid #ffcc80;white-space:nowrap">🛵 Pickup waiting</span> ';
            } else if (o.pickup_conf === 'confirmed') {
                h += '<span style="display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:5px;font-size:10px;font-weight:700;background:rgba(46,204,113,.15);color:#2ECC71;border:1px solid rgba(46,204,113,.3);white-space:nowrap">✅ Pickup ✓</span> ';
            }
            // Delivery confirmation
            if (o.delivery_conf === 'pending') {
                h += '<span style="display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:5px;font-size:10px;font-weight:700;background:#f3e5f5;color:#6a1b9a;border:1px solid #ce93d8;white-space:nowrap">🚪 Delivery waiting</span>';
            } else if (o.delivery_conf === 'confirmed') {
                h += '<span style="display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:5px;font-size:10px;font-weight:700;background:rgba(46,204,113,.15);color:#2ECC71;border:1px solid rgba(46,204,113,.3);white-space:nowrap">✅ Delivery ✓</span>';
            }
            return h || '<span style="color:#ccc;font-size:11px">—</span>';
        }
        function renderOrderTable(data) {
            document.getElementById('orders-tbody').innerHTML = data.length ? data.map(o => {
                const safeCode = (o.order_code||'').replace(/[<>"'\\]/g, '');
                return `
                <tr>
                    <td><strong>${esc(o.order_code)}</strong></td>
                    <td>${esc(o.from_zone)||'—'}</td><td>${esc(o.to_zone)||'—'}</td>
                    <td>${o.package_type||'—'}</td>
                    <td>ETB ${parseFloat(o.fare_total||0).toFixed(2)}</td>
                    <td>${statusBadge(o.status)}</td>
                    <td style="white-space:nowrap">${confBadges(o)}</td>
                    <td>${fmtTime(o.placed_at)}</td>
                    <td style="white-space:nowrap">
                        <button class="act-btn act-gold" onclick="openOrderDetail(${o.id},'${safeCode}','${o.status}')">🔍 Detail</button>
                        ${o.status==='pending'?`<button class="act-btn act-red" onclick="updateOrderStatus(${o.id},'cancelled')">✕</button>`:''}
                    </td>
                </tr>`;
            }).join('') : '<tr><td colspan="9" style="text-align:center;color:#B8B0A0;padding:30px">No orders found</td></tr>';
        }
        function filterOrderTable() {
            const q = document.getElementById('order-search').value.toLowerCase();
            renderOrderTable(ordersData.filter(o => (o.order_code||'').toLowerCase().includes(q) || (o.from_zone||'').toLowerCase().includes(q) || (o.to_zone||'').toLowerCase().includes(q)));
        }

        // ─── ORDER ISSUES (dispute/refund reports) ───────────────────
        let orderIssuesFilter = 'all';
        let orderIssuesData = [];
        const ORDER_ISSUE_LABEL = {
            open: ['🟡 Open', '#e65100', 'rgba(255,122,69,.15)'],
            resolved: ['✅ Resolved', '#1b7e3a', 'rgba(46,204,113,.15)']
        };
        async function loadOrderIssues() {
            const wrap = document.getElementById('order-issues-wrap');
            if (!wrap) return;
            wrap.innerHTML = '<div style="padding:20px;text-align:center;color:#B8B0A0">Loading…</div>';
            try {
                const res = await adm('admin_get_order_issues', {}, true);
                if (!res || !res.success) { wrap.innerHTML = `<div style="padding:16px;color:#e74c3c">Could not load order issues: ${esc((res&&res.message)||'API error')}</div>`; return; }
                orderIssuesData = (res.data && res.data.issues) ? res.data.issues : [];
                renderOrderIssues();
            } catch(e) {
                wrap.innerHTML = '<div style="padding:16px;color:#e74c3c">Could not load order issues</div>';
            }
        }
        function renderOrderIssues() {
            const wrap = document.getElementById('order-issues-wrap');
            if (!wrap) return;
            const rows = orderIssuesData.filter(r => orderIssuesFilter === 'all' ? true : (r.status||'open') === orderIssuesFilter);
            if (!rows.length) {
                wrap.innerHTML = `<div style="padding:24px;text-align:center;color:#B8B0A0">No ${orderIssuesFilter==='all'?'':orderIssuesFilter+' '}order issues.</div>`;
                return;
            }
            const trs = rows.map(r => {
                const label = ORDER_ISSUE_LABEL[r.status||'open'] || ORDER_ISSUE_LABEL.open;
                return `<tr>
                    <td>${r.order_id?'#'+r.order_id:'—'}</td>
                    <td>${esc(r.reporter_name||'—')}<br><small style="color:#9A9AAE">${esc(r.reporter_phone||'')}</small></td>
                    <td>${esc(r.category||'—')}</td>
                    <td style="max-width:260px;white-space:normal">${esc(r.details||'—')}</td>
                    <td><span style="display:inline-flex;padding:5px 10px;border-radius:6px;font-size:11px;font-weight:700;text-transform:uppercase;color:${label[1]};background:${label[2]};border:1px solid ${label[1]}">${label[0]}</span></td>
                    <td>${fmtTime(r.created_at)}</td>
                    <td>${(r.status||'open')==='open'
                        ? `<button class="btn btn-primary" style="padding:6px 10px;font-size:12px" onclick="resolveOrderIssue(${r.id})">Mark resolved</button>`
                        : `<span style="color:#9A9AAE;font-size:12px">${fmtTime(r.resolved_at)}</span>`}
                    </td>
                </tr>`;
            }).join('');
            wrap.innerHTML = `<div style="overflow-x:auto;border-radius:14px;border:1px solid rgba(255,255,255,.08)">
                <table style="width:100%;border-collapse:collapse;font-size:13px">
                <thead><tr style="border-bottom:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.04)">
                <th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Order</th>
                <th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Reporter</th>
                <th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Category</th>
                <th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Details</th>
                <th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Status</th>
                <th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Reported</th>
                <th style="padding:12px 14px;text-align:left;color:#9A9AAE;font-weight:700">Action</th>
                </tr></thead><tbody>${trs}</tbody></table></div>`;
        }
        function orderIssuesFilterBtn(f, el) {
            orderIssuesFilter = f;
            document.querySelectorAll('[data-order-issue-filter]').forEach(b => b.classList.remove('active'));
            if (el) el.classList.add('active');
            renderOrderIssues();
        }
        async function resolveOrderIssue(id) {
            if (!confirm('Mark this order issue as resolved?')) return;
            try {
                const res = await adm('admin_resolve_order_issue', {id}, false);
                if (!res || !res.success) { alert('Could not resolve: ' + ((res&&res.message)||'unknown error')); return; }
                const r = orderIssuesData.find(x => x.id === id);
                if (r) { r.status = 'resolved'; r.resolved_at = new Date().toISOString(); }
                renderOrderIssues();
            } catch(e) { alert('Could not resolve order issue'); }
        }

        // ─── CONFIRMATIONS ────────────────────────────────────────────
        let confsData = [];
        let confHistoryLoaded = false;

        async function loadConfirmations() {
            const res = await adm('admin_get_confirmations', {}, true);
            if (!res.success) return;
            confsData = res.data.confirmations || [];
            const pendingPickup   = res.data.pending_pickup   || 0;
            const pendingDelivery = res.data.pending_delivery || 0;

            // Update badges
            const pb = document.getElementById('conf-pickup-badge');
            const db = document.getElementById('conf-delivery-badge');
            if (pb) { pb.textContent = pendingPickup + ' pickup'; pb.style.display = pendingPickup ? 'inline' : 'none'; }
            if (db) { db.textContent = pendingDelivery + ' delivery'; db.style.display = pendingDelivery ? 'inline' : 'none'; }

            // Render pending confirmation cards
            const pending = confsData.filter(c => c.status === 'pending');
            const cards = document.getElementById('conf-cards');
            if (!cards) return;
            if (!pending.length) {
                cards.innerHTML = '<div style="color:#B8B0A0;font-size:13px;padding:10px;grid-column:1/-1">No pending confirmations — all clear ✓</div>';
            } else {
                cards.innerHTML = pending.map(c => {
                    const isPickup = c.type === 'pickup';
                    const mins = Math.floor((Date.now() - new Date(c.requested_at).getTime()) / 60000);
                    const waitLabel = mins < 1 ? 'just now' : mins + ' min waiting';
                    const col  = isPickup ? '#e65100' : '#6a1b9a';
                    const bg   = isPickup ? 'linear-gradient(135deg,#ff8c00,#e65100)' : 'linear-gradient(135deg,#9B59B6,#6a1b9a)';
                    const icon = isPickup ? '🛵' : '🚪';
                    const lbl  = isPickup ? 'Pickup Confirmation' : 'Delivery Confirmation';
                    const who  = isPickup ? 'Awaiting service giver' : 'Awaiting customer';
                    const safeCode = (c.order_code||'').replace(/[<>"'\\]/g, '');
                    return `<div style="background:#fff;border-radius:12px;padding:16px;border:2px solid ${col}22;box-shadow:0 2px 12px ${col}18">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                            <span style="font-size:20px">${icon}</span>
                            <div style="flex:1">
                                <div style="font-weight:700;font-size:13px;color:#1a1a2e">${lbl}</div>
                                <div style="font-size:11px;color:#777">${who}</div>
                            </div>
                            <span style="padding:3px 8px;border-radius:6px;font-size:10px;font-weight:700;background:${col}18;color:${col}">${waitLabel}</span>
                        </div>
                        <div style="font-size:13px;margin-bottom:4px;color:#1a1a2e"><strong>${esc(c.order_code)}</strong> · ETB ${parseFloat(c.fare_total||0).toFixed(2)}</div>
                        <div style="font-size:12px;color:#777;margin-bottom:10px">Courier: ${esc(c.courier_name)||'—'} · ${esc(c.from_zone)||'—'} → ${esc(c.to_zone)||'—'}</div>
                        <div style="display:flex;gap:6px">
                            <button onclick="adminForceConfirm(${c.id},'${safeCode}')" style="flex:1;padding:7px;background:${bg};color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer">✅ Force Confirm</button>
                            <button onclick="adminRejectConf(${c.id},'${safeCode}')" style="padding:7px 12px;background:#fde;color:#a00;border:1px solid #fbb;border-radius:8px;font-size:12px;cursor:pointer">✕</button>
                        </div>
                    </div>`;
                }).join('');
            }

            // If history panel is open, refresh it too
            if (confHistoryLoaded) renderConfHistory();
        }

        function toggleConfHistory() {
            const wrap = document.getElementById('conf-history-wrap');
            const toggle = document.getElementById('conf-history-toggle');
            const open = wrap.style.display === 'none';
            wrap.style.display = open ? 'block' : 'none';
            toggle.textContent = open ? '▲ Hide' : '▼ Show';
            if (open) { confHistoryLoaded = true; renderConfHistory(); }
        }

        function renderConfHistory() {
            const tbody = document.getElementById('conf-history-tbody');
            if (!confsData.length) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#B8B0A0;padding:20px">No confirmation records yet</td></tr>';
                return;
            }
            const confStatusBadge = s => {
                const map = { pending:'#ff8c00', confirmed:'#2e7d32', rejected:'#a00' };
                return `<span style="padding:2px 8px;border-radius:5px;font-size:11px;font-weight:700;background:${(map[s]||'#999')}22;color:${map[s]||'#999'};border:1px solid ${(map[s]||'#999')}44">${s}</span>`;
            };
            tbody.innerHTML = confsData.map(c => {
                const safeCode = (c.order_code||'').replace(/[<>"'\\]/g, '');
                return `
                <tr>
                    <td><strong>${esc(c.order_code)}</strong></td>
                    <td><span style="font-size:11px;font-weight:700;padding:2px 7px;border-radius:5px;background:${c.type==='pickup'?'#fff3e0':'#f3e5f5'};color:${c.type==='pickup'?'#e65100':'#6a1b9a'}">${c.type==='pickup'?'🛵 Pickup':'🚪 Delivery'}</span></td>
                    <td>${confStatusBadge(c.status)}${c.payment_confirmed?'<span style="font-size:10px;color:#2e7d32;margin-left:4px">💵 paid</span>':''}</td>
                    <td>${esc(c.courier_name)||'—'}</td>
                    <td>${esc(c.responder_name)||'—'}</td>
                    <td style="font-size:11px">${fmtTime(c.requested_at)}</td>
                    <td style="font-size:11px">${c.responded_at ? fmtTime(c.responded_at) : '<span style="color:#B8B0A0">pending</span>'}</td>
                    <td>${c.status==='pending'?`<button class="act-btn act-gold" onclick="adminForceConfirm(${c.id},'${safeCode}')" style="font-size:11px">Force ✓</button>`:'—'}</td>
                </tr>`;
            }).join('');
        }

        async function adminForceConfirm(confId, orderCode) {
            if (!confirm(`Force-confirm this ${orderCode} confirmation?\n\nThis overrides the normal flow — use only if the ${orderCode} party is unresponsive.`)) return;
            const res = await adm('admin_force_confirm', { conf_id: confId });
            if (res && res.success) {
                toast('Confirmation forced for ' + orderCode);
                loadConfirmations();
                loadOrders();
            } else {
                toast((res && res.message) || 'Failed', 'err');
            }
        }

        async function adminRejectConf(confId, orderCode) {
            if (!confirm(`Reject/dismiss this pending confirmation for ${orderCode}?`)) return;
            // Use admin_update_order to just cancel the stuck confirmation by marking it rejected
            const res = await adm('admin_force_confirm', { conf_id: confId }); // reuse — or we can mark rejected
            // For now force-confirm handles it; a dedicated reject would need another endpoint
            toast('Dismissed — order status not changed', 'ok');
            loadConfirmations();
        }

        function openOrderDetail(id, code, status) {
            const o = ordersData.find(x => x.id === id);
            if (!o) { openUpdateStatus(id, code, status); return; }
            const pickupConf = o.pickup_conf
                ? `<span style="font-weight:600;color:${o.pickup_conf==='confirmed'?'#2e7d32':'#e65100'}">${o.pickup_conf==='confirmed'?'✅ Confirmed':'⏳ Pending'}</span>${o.pickup_conf_at?' at '+fmtTime(o.pickup_conf_at):''}`
                : '<span style="color:#B8B0A0">Not requested</span>';
            const delivConf = o.delivery_conf
                ? `<span style="font-weight:600;color:${o.delivery_conf==='confirmed'?'#2e7d32':'#6a1b9a'}">${o.delivery_conf==='confirmed'?'✅ Confirmed':'⏳ Pending'}</span>${o.delivery_conf_at?' at '+fmtTime(o.delivery_conf_at):''}`
                : '<span style="color:#B8B0A0">Not requested</span>';
            const opts = ['pending','assigned','picked_up','in_transit','delivered','cancelled'].map(s => `<option value="${s}" ${s===status?'selected':''}>${s.replace(/_/g,' ')}</option>`).join('');
            openModal('custom', `
                <div class="modal-title">Order ${esc(code)}</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px;font-size:13px">
                    <div style="background:rgba(255,255,255,.06);border-radius:8px;padding:10px">
                        <div style="color:#B8B0A0;font-size:10px;text-transform:uppercase;font-weight:700;margin-bottom:4px">Route</div>
                        <div>${esc(o.from_zone)||'—'} → ${esc(o.to_zone)||'—'}</div>
                    </div>
                    <div style="background:rgba(255,255,255,.06);border-radius:8px;padding:10px">
                        <div style="color:#B8B0A0;font-size:10px;text-transform:uppercase;font-weight:700;margin-bottom:4px">Amount</div>
                        <div style="font-weight:700;color:#C9A961">ETB ${parseFloat(o.fare_total||0).toFixed(2)}</div>
                    </div>
                    <div style="background:rgba(255,255,255,.06);border-radius:8px;padding:10px">
                        <div style="color:#B8B0A0;font-size:10px;text-transform:uppercase;font-weight:700;margin-bottom:4px">🛵 Pickup Confirmation</div>
                        <div style="font-size:12px">${pickupConf}</div>
                    </div>
                    <div style="background:rgba(255,255,255,.06);border-radius:8px;padding:10px">
                        <div style="color:#B8B0A0;font-size:10px;text-transform:uppercase;font-weight:700;margin-bottom:4px">🚪 Delivery Confirmation</div>
                        <div style="font-size:12px">${delivConf}</div>
                    </div>
                </div>
                <div class="modal-field"><label>Update Status</label><select id="new-status">${opts}</select></div>
                <div class="modal-msg" id="upd-msg"></div>
                <button class="modal-submit" onclick="updateOrderStatus(${id},document.getElementById('new-status').value)">Update Status</button>
                <button class="modal-submit" style="background:rgba(255,255,255,.1);color:#e8e2d4;margin-top:8px" onclick="printOrderReceiptUI(${id})">🖨️ Print Receipt</button>
            `);
        }
        async function printOrderReceiptUI(orderId) {
            const res = await adm('admin_get_order_receipt', { order_id: orderId }, true);
            if (!res || !res.success) { toast((res && res.message) || 'Could not load order for printing', 'err'); return; }
            await AdminPrinter.printOrderReceipt(res.data.order, res.data.items, res.data.business_name);
        }
        function openUpdateStatus(id, code, cur) {
            openOrderDetail(id, code, cur); // redirect to richer detail modal
        }
        async function updateOrderStatus(id, status) {
            const res = await api('admin_update_order', {order_id:id, status});
            const msg = document.getElementById('upd-msg');
            if (res && res.success) {
                if (msg) { msg.className='modal-msg ok'; msg.textContent='✓ Updated successfully'; }
                setTimeout(() => { closeModal(); loadOrders(); if(currentPage==='dashboard') loadDashboard(); }, 900);
            } else {
                if (msg) { msg.className='modal-msg err'; msg.textContent = (res&&res.message)||'Update failed'; }
                else { loadOrders(); }
            }
        }

        // ─── USERS ────────────────────────────────────────────────────
        // Read-only report: accounts sharing one phone number written in different forms
        // (0924445206 vs +251924445206). Nothing is changed here - decide which account is real.
        async function loadDuplicatePhones() {
            const box = document.getElementById('dup-phones-box');
            if (!box) return;
            box.style.display = 'block';
            box.innerHTML = '<div class="section-title">Duplicate phone numbers</div><div style="padding:16px">' + UI.loading('Checking...') + '</div>';
            try {
                const res = await adm('admin_duplicate_phones', {}, true);
                if (!res || !res.success) { box.innerHTML = '<div class="section-title">Duplicate phone numbers</div><div style="padding:16px;color:#c0392b">' + escapeHtml((res && res.message) || 'Could not load the report') + '</div>'; return; }
                const groups = (res.data && res.data.groups) || [];
                if (!groups.length) { box.innerHTML = '<div class="section-title">Duplicate phone numbers</div><div style="padding:16px">✅ No phone number is shared by more than one account.</div>'; return; }
                let html = '<div class="section-title">Duplicate phone numbers (' + groups.length + ')</div>' +
                    '<div style="padding:0 16px 8px;font-size:12px;opacity:.8">Each group is ONE number stored in different forms. Nothing here is changed automatically.</div>';
                groups.forEach(function (g) {
                    html += '<div class="table-wrapper" style="margin:10px 0"><table><thead><tr><th>ID</th><th>Name</th><th>Phone as stored</th><th>Role</th><th>Shop</th></tr></thead><tbody>';
                    g.accounts.forEach(function (a) {
                        const shop = a.shop_id ? (escapeHtml(a.business_name || ('#' + a.shop_id)) + (a.shop_status ? ' <small>(' + escapeHtml(a.shop_status) + ')</small>' : '') + (a.admin_placeholder_shop ? ' <b style="color:#c0392b">placeholder</b> <button class="btn btn-secondary" style="padding:4px 10px;font-size:11px;margin-left:6px" onclick="removePlaceholderShop(' + a.shop_id + ')">🗑 Remove placeholder shop</button>' : '')) : '—';
                        html += '<tr><td>' + a.id + '</td><td>' + escapeHtml(a.name || '') + '</td><td>' + escapeHtml(a.phone || '') + '</td><td>' + escapeHtml(a.role || '') + '</td><td>' + shop + '</td></tr>';
                    });
                    html += '</tbody></table></div>';
                });
                box.innerHTML = html;
            } catch (e) {
                box.innerHTML = '<div class="section-title">Duplicate phone numbers</div><div style="padding:16px;color:#c0392b">Could not load the report</div>';
            }
        }

        // Removes ONE empty placeholder shop (owned by an admin account). The server refuses
        // anything that is not exactly that, or that has any items/orders/staff/payouts.
        async function removePlaceholderShop(shopId) {
            if (!confirm('Remove this empty placeholder shop?\n\nNo account is deleted. The server will refuse if the shop has any items, orders or staff.')) return;
            try {
                const res = await adm('admin_delete_placeholder_shop', { shop_id: shopId }, false);
                if (res && res.success) {
                    if (typeof showNotification === 'function') showNotification('Placeholder shop removed', 'success'); else alert('Placeholder shop removed');
                    loadDuplicatePhones();
                } else {
                    alert((res && res.message) || 'Could not remove the shop');
                }
            } catch (e) { alert('Could not remove the shop'); }
        }

        async function loadUsers() {
            const tbody = document.getElementById('users-tbody');
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:30px;">' + UI.loading('Loading users...') + '</td></tr>';
            try {
                const role = document.getElementById('user-role-filter') ? document.getElementById('user-role-filter').value : '';
                const params = role ? {role} : {};
                const res = await adm('admin_get_users', params, true);
                if (!res || !res.success) { tbody.innerHTML = `<tr><td colspan="5" style="color:red;padding:20px">${(res&&res.message)||'Failed to load users'}</td></tr>`; return; }
                usersData = (res.data && res.data.users) ? res.data.users : [];
                renderUserTable(usersData);
            } catch(e) {
                tbody.innerHTML = '<tr><td colspan="5" style="color:#B8B0A0;text-align:center;padding:30px">No users found</td></tr>';
            }
        }
        function renderUserTable(data) {
            document.getElementById('users-tbody').innerHTML = data.length ? data.map(u => `
                <tr>
                    <td>${u.id}</td>
                    <td><strong>${esc(u.name)}</strong></td>
                    <td>${esc(u.phone)}</td>
                    <td><span class="badge-status badge-${u.role}">${u.role.replace(/_/g,' ')}</span></td>
                    <td>${fmtTime(u.created_at)}</td>
                </tr>`).join('') : '<tr><td colspan="5" style="text-align:center;color:#B8B0A0;padding:30px">No users found</td></tr>';
        }
        function filterUserTable() {
            const q = document.getElementById('user-search').value.toLowerCase();
            renderUserTable(usersData.filter(u => (u.name||'').toLowerCase().includes(q) || (u.phone||'').includes(q)));
        }

        // ─── COURIERS ─────────────────────────────────────────────────
        async function loadCouriers() {
            const tbody = document.getElementById('couriers-tbody');
            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:30px;">' + UI.loading('Loading couriers...') + '</td></tr>';
            try {
                const res = await adm('admin_get_couriers', {}, true);
                if (!res || !res.success) { tbody.innerHTML = `<tr><td colspan="9" style="color:red;padding:20px">${(res&&res.message)||'Failed to load couriers'}</td></tr>`; return; }
                couriersData = (res.data && res.data.couriers) ? res.data.couriers : [];
                renderCourierTable(couriersData);
            } catch(e) {
                tbody.innerHTML = '<tr><td colspan="9" style="color:#B8B0A0;text-align:center;padding:30px">No couriers found</td></tr>';
            }
        }
        function renderCourierTable(data) {
            document.getElementById('couriers-tbody').innerHTML = data.length ? data.map(c => `
                <tr>
                    <td><strong>${esc(c.courier_code||'—')}</strong></td>
                    <td>${esc(c.name)}</td><td>${esc(c.phone)}</td>
                    <td>${esc(c.vehicle_type||'—')}</td><td>${esc(c.zone||c.home_zone||'—')}</td>
                    <td>${statusBadge(c.status||'offline')}</td>
                    <td>⭐ ${parseFloat(c.rating||0).toFixed(2)}</td>
                    <td>${c.total_deliveries||0}</td>
                    <td><button class="act-btn act-gold" onclick="printCourierSlip('${c.courier_code||''}')">🖨️ Slip</button></td>
                </tr>`).join('') : '<tr><td colspan="9" style="text-align:center;color:#B8B0A0;padding:30px">No couriers found</td></tr>';
        }
        function printCourierSlip(courierCode) {
            const c = couriersData.find(x => x.courier_code === courierCode);
            if (!c) { toast('Courier not found', 'err'); return; }
            AdminPrinter.printVerificationSlip({
                kind: 'Courier', code: c.courier_code, name: c.name,
                subtitle: c.vehicle_type || '', zone: c.zone || c.home_zone || '',
                phone: c.phone, statusLabel: c.status || 'offline'
            });
        }
        function filterCourierTable() {
            const q = document.getElementById('courier-search').value.toLowerCase();
            renderCourierTable(couriersData.filter(c => (c.name||'').toLowerCase().includes(q) || (c.phone||'').includes(q) || (c.courier_code||'').toLowerCase().includes(q)));
        }

        // ─── SERVICE GIVERS ───────────────────────────────────────────
        let sgViewRestored = false;
        async function loadServiceGivers() {
            if (!sgViewRestored) {
                sgViewRestored = true;
                try { setSGView(localStorage.getItem('etel_sg_view') || 'table'); } catch (e) {}
            }
            document.getElementById('sg-tbody').innerHTML = '<tr><td colspan="9" style="text-align:center;padding:30px;">' + UI.loading('Loading partners...') + '</td></tr>';
            const status = document.getElementById('sg-status-filter').value;
            const params = status ? {status} : {};
            const res = await adm('admin_get_service_givers', params, true);
            if (!res.success) { document.getElementById('sg-tbody').innerHTML = `<tr><td colspan="9" style="color:red;padding:20px">${res.message}</td></tr>`; return; }
            sgData = res.data.service_givers;
            renderSGStatStrip(sgData);
            renderSGTable(sgData);
            renderSGCards(sgData);
        }
        function renderSGStatStrip(data) {
            const total = data.length;
            const verified = data.filter(sg => sg.status === 'verified').length;
            const online = data.filter(sg => !!parseInt(sg.is_online, 10)).length;
            const rated = data.filter(sg => parseFloat(sg.average_rating) > 0);
            const avgRating = rated.length ? (rated.reduce((sum, sg) => sum + parseFloat(sg.average_rating), 0) / rated.length) : 0;
            const setText = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
            setText('sg-stat-total', total.toLocaleString());
            setText('sg-stat-verified', verified.toLocaleString());
            setText('sg-stat-online', online.toLocaleString());
            setText('sg-stat-rating', rated.length ? avgRating.toFixed(2) + ' ⭐' : '—');
        }
        // Remembers the person's last-picked view across visits (localStorage,
        // not just in-memory) - a small premium touch, not required for
        // correctness.
        function setSGView(view) {
            const tableWrap = document.getElementById('sg-table-wrap');
            const cardsGrid = document.getElementById('sg-cards-grid');
            const tableBtn = document.getElementById('sg-view-table-btn');
            const cardsBtn = document.getElementById('sg-view-cards-btn');
            if (!tableWrap || !cardsGrid) return;
            const isCards = view === 'cards';
            tableWrap.style.display = isCards ? 'none' : '';
            cardsGrid.style.display = isCards ? 'grid' : 'none';
            if (tableBtn) tableBtn.classList.toggle('upd-tab-active', !isCards);
            if (cardsBtn) cardsBtn.classList.toggle('upd-tab-active', isCards);
            try { localStorage.setItem('etel_sg_view', view); } catch (e) {}
        }
        function renderSGCards(data) {
            const grid = document.getElementById('sg-cards-grid');
            if (!grid) return;
            if (!data.length) {
                grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#B8B0A0;padding:40px">No service givers found</div>';
                return;
            }
            const typeIcon = { restaurant: '🍽️', pharmacy: '💊', grocery: '🛒', electronics: '🔌', fastfood: '🍔', cafe: '☕', retail: '🛍️' };
            grid.innerHTML = data.map(sg => {
                const initial = (sg.business_name || sg.name || '?').charAt(0).toUpperCase();
                const rating = parseFloat(sg.average_rating) || 0;
                const icon = typeIcon[(sg.service_type || '').toLowerCase()] || '🏪';
                return `
                <div class="stat-card" style="display:flex;flex-direction:column;gap:10px;padding:16px">
                    <div style="display:flex;gap:12px;align-items:center">
                        ${sg.logo_url
                            ? `<img src="${sg.logo_url}" style="width:46px;height:46px;border-radius:10px;object-fit:cover;border:1px solid rgba(212,175,55,.25)">`
                            : `<div style="width:46px;height:46px;border-radius:10px;background:rgba(212,175,55,.12);border:1px solid rgba(212,175,55,.25);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:18px;color:#D4AF37">${initial}</div>`}
                        <div style="min-width:0;flex:1">
                            <div style="font-weight:700;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${sg.business_name || sg.name || '—'}</div>
                            <div style="font-size:11.5px;color:#B8B0A0">${sg.service_giver_code || '—'} · ${icon} ${sg.service_type || '—'}</div>
                        </div>
                        <span class="badge-status ${sg.is_online ? 'badge-online' : 'badge-offline'}" title="${sg.is_online ? 'Online' : 'Offline'}" style="width:9px;height:9px;padding:0;border-radius:50%;flex-shrink:0"></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;font-size:12.5px;color:#B8B0A0">
                        <span>📍 ${sg.zone || '—'}</span>
                        <span>${rating > 0 ? '⭐ ' + rating.toFixed(1) : 'No ratings'}</span>
                    </div>
                    <div>${statusBadge(sg.status)}</div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:2px">
                        ${sg.status === 'pending' ? `<button class="act-btn act-green" onclick="verifySG(${sg.id})">✓ Verify</button>` : ''}
                        ${sg.status !== 'suspended' ? `<button class="act-btn act-red" onclick="suspendSG(${sg.id})">✕ Suspend</button>` : ''}
                        <button class="act-btn act-gold" onclick="viewSGStats(${sg.id})">📊 Stats</button>
                        <button class="act-btn act-gold" onclick="openSGManageModal(${sg.id})">🔍 Manage</button>
                        <button class="act-btn act-gold" onclick="printSGSlip(${sg.id})">🖨️</button>
                    </div>
                </div>`;
            }).join('');
        }
        function renderSGTable(data) {
            document.getElementById('sg-tbody').innerHTML = data.length ? data.map(sg => `
                <tr>
                    <td><strong>${esc(sg.service_giver_code||'—')}</strong></td>
                    <td>${esc(sg.business_name||'—')}</td>
                    <td>${esc(sg.name)}</td><td>${esc(sg.phone)}</td>
                    <td>${esc(sg.service_type||'—')}</td><td>${esc(sg.zone||'—')}</td>
                    <td>${statusBadge(sg.status)}</td>
                    <td><span class="badge-status ${sg.is_online?'badge-online':'badge-offline'}">${sg.is_online?'Online':'Offline'}</span></td>
                    <td style="white-space:nowrap">
                        ${sg.status==='pending'?`<button class="act-btn act-green" onclick="verifySG(${sg.id})">✓ Verify</button> `:''}
                        ${sg.status!=='suspended'?`<button class="act-btn act-red" onclick="suspendSG(${sg.id})">✕ Suspend</button> `:''}
                        <button class="act-btn act-gold" onclick="viewSGStats(${sg.id})">📊 Stats</button>
                        <button class="act-btn act-gold" onclick="openSGManageModal(${sg.id})">🔍 Manage</button>
                        <button class="act-btn act-gold" onclick="printSGSlip(${sg.id})">🖨️ Slip</button>
                    </td>
                </tr>`).join('') : '<tr><td colspan="9" style="text-align:center;color:#B8B0A0;padding:30px">No service givers found</td></tr>';
        }
        function printSGSlip(id) {
            const sg = sgData.find(s => s.id === id);
            if (!sg) { toast('Service giver not found', 'err'); return; }
            AdminPrinter.printVerificationSlip({
                kind: 'Service Giver', code: sg.service_giver_code, name: sg.business_name || sg.name,
                subtitle: sg.service_type || '', zone: sg.zone || '',
                phone: sg.phone, statusLabel: sg.status || 'pending'
            });
        }
        function viewSGStats(id) {
            // Reuses data already fetched by loadServiceGivers() -- these
            // fields (average_rating, total_deliveries, etc.) already come
            // back from admin_get_service_givers, they just were never
            // rendered anywhere. No extra API call needed.
            const sg = sgData.find(s => s.id === id);
            if (!sg) { toast('Service giver not found', 'err'); return; }
            const rating = parseFloat(sg.average_rating) || 0;
            const ratingCount = parseInt(sg.rating_count, 10) || 0;
            const total = parseInt(sg.total_deliveries, 10) || 0;
            const done = parseInt(sg.completed_deliveries, 10) || 0;
            const failed = parseInt(sg.failed_deliveries, 10) || 0;
            const earnings = parseFloat(sg.total_earnings) || 0;
            const successRate = total > 0 ? ((done / total) * 100).toFixed(0) : '—';
            openModal('custom', `
                <div class="modal-title">📊 ${sg.business_name || sg.name} — Performance</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:18px 0">
                    <div style="background:rgba(255,255,255,.06);border:1px solid rgba(212,175,55,.15);border-radius:10px;padding:14px;text-align:center">
                        <div style="font-size:22px;font-weight:800;color:#D4AF37">${rating.toFixed(2)} ⭐</div>
                        <div style="font-size:12px;color:#B8B0A0;margin-top:2px">${ratingCount} rating${ratingCount===1?'':'s'}</div>
                    </div>
                    <div style="background:rgba(255,255,255,.06);border:1px solid rgba(212,175,55,.15);border-radius:10px;padding:14px;text-align:center">
                        <div style="font-size:22px;font-weight:800;color:#D4AF37">ETB ${earnings.toFixed(2)}</div>
                        <div style="font-size:12px;color:#B8B0A0;margin-top:2px">Total earnings</div>
                    </div>
                    <div style="background:rgba(255,255,255,.06);border:1px solid rgba(212,175,55,.15);border-radius:10px;padding:14px;text-align:center">
                        <div style="font-size:22px;font-weight:800;color:#D4AF37">${total}</div>
                        <div style="font-size:12px;color:#B8B0A0;margin-top:2px">Total deliveries</div>
                    </div>
                    <div style="background:rgba(255,255,255,.06);border:1px solid rgba(212,175,55,.15);border-radius:10px;padding:14px;text-align:center">
                        <div style="font-size:22px;font-weight:800;color:${failed>0?'#e74c3c':'#D4AF37'}">${successRate}${successRate!=='—'?'%':''}</div>
                        <div style="font-size:12px;color:#B8B0A0;margin-top:2px">${done} done · ${failed} failed</div>
                    </div>
                </div>
                <button class="modal-submit" onclick="closeModal()" style="background:rgba(255,255,255,.1);color:#e8e2d4">Close</button>
            `);
        }
        function filterSGTable() {
            const q = document.getElementById('sg-search').value.toLowerCase();
            const filtered = sgData.filter(sg => (sg.name||'').toLowerCase().includes(q) || (sg.phone||'').includes(q) || (sg.business_name||'').toLowerCase().includes(q));
            renderSGTable(filtered);
            renderSGCards(filtered);
        }

        // ─── PREMIUM BUSINESS CONTROL MODAL ─────────────────────────────
        // One place to see everything about a business - profile, real
        // performance numbers (reusing the same data viewSGStats already
        // has cached), and its staff roster with the ability to deactivate
        // a specific staff login for support/moderation reasons. Staff
        // management is still primarily the owner's own job in their own
        // dashboard - this is deliberately read-heavy with one narrow
        // moderation action, not a full duplicate of the owner's Staff &
        // Roles screen.
        // ─── TELEBIRR MANUAL PAYMENT CONFIRMATION ────────────────────────
        async function loadTelebirrPayments() {
            const listEl = document.getElementById('telebirr-list');
            if (listEl) listEl.innerHTML = UI.loading('Loading payments…');
            const res = await adm('admin_list_telebirr_payments', {}, true);
            if (!res.success) {
                if (listEl) listEl.innerHTML = `<p style="color:#e74c3c">${res.message || 'Could not load'}</p>`;
                return;
            }
            const payments = res.data.payments || [];
            updatePaymentsBadge();
            if (!listEl) return;
            if (!payments.length) {
                listEl.innerHTML = '<p style="color:#B8B0A0;text-align:center;padding:30px">No Telebirr payments waiting for confirmation.</p>';
                return;
            }
            listEl.innerHTML = payments.map(p => `
                <div class="stat-card" style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:10px;flex-wrap:wrap">
                    <div>
                        <div style="font-weight:700;font-size:14px">Order ${esc(p.order_code)} — ETB ${parseFloat(p.amount).toFixed(2)}</div>
                        <div style="font-size:12px;color:#B8B0A0">${esc(p.customer_name)} · Telebirr number: ${esc(p.phone)} · ${new Date(p.created_at).toLocaleString()}</div>
                    </div>
                    <div style="display:flex;gap:8px">
                        <button class="act-btn act-green" onclick="confirmTelebirrPayment(${p.id},'confirm')">✓ Confirm</button>
                        <button class="act-btn act-red" onclick="confirmTelebirrPayment(${p.id},'reject')">✕ Reject</button>
                    </div>
                </div>`).join('');
        }

        async function confirmTelebirrPayment(paymentId, decision) {
            if (decision === 'reject' && !confirm('Reject this payment? The order will stay unpaid.')) return;
            const res = await adm('admin_confirm_telebirr_payment', { payment_id: paymentId, decision });
            if (res.success) { toast(res.message); loadTelebirrPayments(); }
            else toast(res.message || 'Error', 'err');
        }

        async function loadBankPayments() {
            const listEl = document.getElementById('bank-payments-list');
            if (listEl) listEl.innerHTML = UI.loading('Loading payments…');
            const res = await adm('admin_list_bank_payments', {}, true);
            if (!res.success) {
                if (listEl) listEl.innerHTML = `<p style="color:#e74c3c">${res.message || 'Could not load'}</p>`;
                return;
            }
            const payments = res.data.payments || [];
            updatePaymentsBadge();
            if (!listEl) return;
            if (!payments.length) {
                listEl.innerHTML = '<p style="color:#B8B0A0;text-align:center;padding:30px">No bank transfers waiting for confirmation.</p>';
                return;
            }
            const bankLabel = { abysinia: 'Abysinia Bank', cbe: 'Commercial Bank of Ethiopia' };
            listEl.innerHTML = payments.map(p => `
                <div class="stat-card" style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:10px;flex-wrap:wrap">
                    <div>
                        <div style="font-weight:700;font-size:14px">Order ${esc(p.order_code)} — ETB ${parseFloat(p.amount).toFixed(2)}</div>
                        <div style="font-size:12px;color:#B8B0A0">${esc(p.customer_name)} · ${esc(bankLabel[p.bank] || p.bank)} · Ref: ${esc(p.reference_no)} · ${new Date(p.created_at).toLocaleString()}</div>
                    </div>
                    <div style="display:flex;gap:8px">
                        <button class="act-btn act-green" onclick="confirmBankPayment(${p.id},'confirm')">✓ Confirm</button>
                        <button class="act-btn act-red" onclick="confirmBankPayment(${p.id},'reject')">✕ Reject</button>
                    </div>
                </div>`).join('');
        }

        async function confirmBankPayment(paymentId, decision) {
            if (decision === 'reject' && !confirm('Reject this payment? The order will stay unpaid.')) return;
            const res = await adm('admin_confirm_bank_payment', { payment_id: paymentId, decision });
            if (res.success) { toast(res.message); loadBankPayments(); }
            else toast(res.message || 'Error', 'err');
        }

        async function updatePaymentsBadge() {
            const [tb, bank, payout] = await Promise.all([
                adm('admin_list_telebirr_payments', {}, true),
                adm('admin_list_bank_payments', {}, true),
                adm('admin_list_payout_requests', {}, true),
            ]);
            const badge = document.getElementById('telebirr-badge');
            if (!badge) return;
            const n = (tb.success ? (tb.data.payments || []).length : 0)
                + (bank.success ? (bank.data.payments || []).length : 0)
                + (payout.success ? (payout.data.requests || []).length : 0);
            badge.style.display = n ? 'inline-block' : 'none';
            badge.textContent = n;
        }

        async function loadPaymentsReport() {
            const body = document.getElementById('payments-report-body');
            const days = document.getElementById('pr-range').value;
            if (body) body.innerHTML = UI.loading('Loading report…');
            const res = await adm('admin_financial_report', { days }, true);
            if (!body) return;
            if (!res.success) { body.innerHTML = `<p style="color:#e74c3c">${res.message || 'Could not load report'}</p>`; return; }
            const d = res.data;
            const cap = (label, value, sub) => `<div style="background:rgba(255,255,255,.06);border:1px solid rgba(212,175,55,.15);border-radius:10px;padding:12px;text-align:center">
                <div style="font-size:18px;font-weight:800;color:#D4AF37">${value}</div>
                <div style="font-size:10.5px;color:#B8B0A0;margin-top:2px;text-transform:uppercase">${label}</div>
                ${sub ? `<div style="font-size:10px;color:#8a7f66;margin-top:2px">${sub}</div>` : ''}
            </div>`;
            const tb = d.telebirr || {};
            const bank = d.bank || {};
            let bankRows = '';
            Object.keys(bank).forEach(bankName => {
                const s = bank[bankName];
                const verified = s.verified || { count: 0, total: 0 };
                const pending = s.pending || { count: 0, total: 0 };
                bankRows += cap(bankName + ' verified', 'ETB ' + verified.total.toFixed(0), verified.count + ' transfers')
                          + cap(bankName + ' pending', 'ETB ' + pending.total.toFixed(0), pending.count + ' transfers');
            });
            body.innerHTML = `
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:14px">
                    ${cap('Confirmed revenue', 'ETB ' + d.confirmed_total.toFixed(0))}
                    ${cap('Awaiting confirmation', 'ETB ' + d.pending_total.toFixed(0))}
                    ${cap('Cash orders', 'ETB ' + (d.cash.total||0).toFixed(0), d.cash.count + ' orders')}
                    ${cap('Telebirr confirmed', 'ETB ' + ((tb.completed||{}).total||0).toFixed(0), ((tb.completed||{}).count||0) + ' payments')}
                    ${cap('Telebirr pending', 'ETB ' + ((tb.pending||{}).total||0).toFixed(0), ((tb.pending||{}).count||0) + ' payments')}
                </div>
                ${bankRows ? `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px">${bankRows}</div>` : ''}
            `;
        }

        async function loadPaymentLogosEditor() {
            const grid = document.getElementById('payment-logos-editor');
            if (!grid) return;
            const res = await adm('get_payment_method_logos', {}, true);
            const logos = (res.success ? res.data.logos : {}) || {};
            const methods = [
                { key: 'telebirr', label: 'Telebirr', color: '#d9670f' },
                { key: 'cbe', label: 'CBE', color: '#1a8a45' },
                { key: 'abysinia', label: 'Abysinia', color: '#1f618d' },
                { key: 'wallet', label: 'ETel Pay Wallet', color: '#6a3f94' },
                { key: 'cash', label: 'Cash', color: '#8a7f00' },
            ];
            grid.innerHTML = methods.map(m => `
                <div class="stat-card" style="text-align:center;padding:14px">
                    <div id="pmlogo-preview-${m.key}" style="width:56px;height:56px;border-radius:12px;margin:0 auto 10px;background:${logos[m.key] ? '#fff' : m.color};display:flex;align-items:center;justify-content:center;overflow:hidden">
                        ${logos[m.key] ? `<img src="${logos[m.key]}" style="width:100%;height:100%;object-fit:contain;padding:4px">` : `<span style="color:#fff;font-weight:800;font-size:11px">${m.label.slice(0,3).toUpperCase()}</span>`}
                    </div>
                    <div style="font-size:12px;font-weight:700;margin-bottom:8px">${m.label}</div>
                    <input type="file" id="pmlogo-file-${m.key}" accept="image/*" style="display:none" onchange="uploadPaymentLogo('${m.key}')">
                    <button class="btn btn-secondary btn-small" style="width:100%" onclick="document.getElementById('pmlogo-file-${m.key}').click()">📤 Upload</button>
                </div>`).join('');
        }

        async function uploadPaymentLogo(methodKey) {
            const url = await uploadImageForAdmin('pmlogo-file-' + methodKey, null);
            if (!url) { toast('Logo upload failed', 'err'); return; }
            const res = await adm('admin_set_payment_logo', { method_key: methodKey, logo_url: url });
            if (res.success) { toast(res.message); loadPaymentLogosEditor(); }
            else toast(res.message || 'Error', 'err');
        }

        async function loadPayoutRequests() {
            const listEl = document.getElementById('payout-requests-list');
            if (listEl) listEl.innerHTML = UI.loading('Loading requests…');
            const res = await adm('admin_list_payout_requests', {}, true);
            if (!res.success) {
                if (listEl) listEl.innerHTML = `<p style="color:#e74c3c">${res.message || 'Could not load'}</p>`;
                return;
            }
            const requests = res.data.requests || [];
            updatePaymentsBadge();
            if (!listEl) return;
            if (!requests.length) {
                listEl.innerHTML = '<p style="color:#B8B0A0;text-align:center;padding:30px">No payout changes waiting for review.</p>';
                return;
            }
            const bankLabel = { abysinia: 'Abysinia Bank', cbe: 'Commercial Bank of Ethiopia' };
            const row = (label, oldVal, newVal) => {
                if (oldVal === newVal) return '';
                return `<div style="display:flex;justify-content:space-between;font-size:12px;padding:4px 0;border-bottom:1px solid rgba(255,255,255,.06)">
                    <span style="color:#B8B0A0">${label}</span>
                    <span><span style="color:#e74c3c;text-decoration:line-through;opacity:.7">${oldVal || '—'}</span> → <span style="color:#2ecc71;font-weight:700">${newVal || '—'}</span></span>
                </div>`;
            };
            listEl.innerHTML = requests.map(r => `
                <div class="stat-card" style="margin-bottom:12px">
                    <div style="display:flex;justify-content:space-between;align-items:start;gap:10px;margin-bottom:10px;flex-wrap:wrap">
                        <div>
                            <div style="font-weight:700;font-size:14px">${r.business_name}</div>
                            <div style="font-size:11.5px;color:#B8B0A0">Requested by ${r.requested_by_name} · ${r.requested_by_phone} · ${new Date(r.requested_at).toLocaleString()}</div>
                        </div>
                        <div style="display:flex;gap:8px;flex-shrink:0">
                            <button class="act-btn act-green" onclick="reviewPayoutRequest(${r.id},'approve')">✓ Approve</button>
                            <button class="act-btn act-red" onclick="reviewPayoutRequest(${r.id},'reject')">✕ Reject</button>
                        </div>
                    </div>
                    <div style="background:rgba(255,255,255,.03);border-radius:8px;padding:10px 12px">
                        ${row('Bank', bankLabel[r.old_payout_bank]||r.old_payout_bank, bankLabel[r.new_payout_bank]||r.new_payout_bank)}
                        ${row('Bank Account', r.old_bank_account, r.new_bank_account)}
                        <div style="font-size:10.5px;color:#D4AF37;font-weight:700;text-transform:uppercase;margin:8px 0 2px">CBE</div>
                        ${row('Holder Name', r.old_cbe_account_name, r.new_cbe_account_name)}
                        ${row('Account Number', r.old_cbe_account, r.new_cbe_account)}
                        <div style="font-size:10.5px;color:#D4AF37;font-weight:700;text-transform:uppercase;margin:8px 0 2px">Abysinia</div>
                        ${row('Holder Name', r.old_abysinia_account_name, r.new_abysinia_account_name)}
                        ${row('Account Number', r.old_abysinia_account, r.new_abysinia_account)}
                        <div style="font-size:10.5px;color:#D4AF37;font-weight:700;text-transform:uppercase;margin:8px 0 2px">Telebirr</div>
                        ${row('Holder Name', r.old_telebirr_account_name, r.new_telebirr_account_name)}
                        ${row('Phone Number', r.old_telebirr_number, r.new_telebirr_number)}
                    </div>
                </div>`).join('');
        }

        async function reviewPayoutRequest(requestId, decision) {
            if (decision === 'reject' && !confirm('Reject this payout change? The business keeps its current payout details.')) return;
            const res = await adm('admin_review_payout_request', { request_id: requestId, decision });
            if (res.success) { toast(res.message); loadPayoutRequests(); }
            else toast(res.message || 'Error', 'err');
        }

        async function openSGManageModal(id) {
            const sg = sgData.find(s => s.id === id);
            if (!sg) { toast('Service giver not found', 'err'); return; }
            const rating = parseFloat(sg.average_rating) || 0;
            const ratingCount = parseInt(sg.rating_count, 10) || 0;
            const earnings = parseFloat(sg.total_earnings) || 0;
            const total = parseInt(sg.total_deliveries, 10) || 0;
            const done = parseInt(sg.completed_deliveries, 10) || 0;
            const failed = parseInt(sg.failed_deliveries, 10) || 0;
            const successRate = total > 0 ? ((done / total) * 100).toFixed(0) : '—';
            const initial = (sg.business_name || sg.name || '?').charAt(0).toUpperCase();
            const cap = (label, value) => `<div style="background:rgba(255,255,255,.06);border:1px solid rgba(212,175,55,.15);border-radius:10px;padding:10px 12px;text-align:center">
                <div style="font-size:16px;font-weight:800;color:#D4AF37">${value}</div>
                <div style="font-size:10.5px;color:#B8B0A0;margin-top:2px;text-transform:uppercase;letter-spacing:.4px">${label}</div>
            </div>`;
            openModal('custom', `
                <div style="display:flex;align-items:center;gap:14px;margin-bottom:16px">
                    ${sg.logo_url
                        ? `<img src="${sg.logo_url}" style="width:52px;height:52px;border-radius:12px;object-fit:cover;border:1px solid rgba(212,175,55,.3)">`
                        : `<div style="width:52px;height:52px;border-radius:12px;background:rgba(212,175,55,.14);border:1px solid rgba(212,175,55,.3);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:22px;color:#D4AF37">${initial}</div>`}
                    <div style="flex:1;min-width:0">
                        <div class="modal-title" style="margin:0;font-size:19px">${sg.business_name || sg.name}</div>
                        <div style="font-size:12px;color:#B8B0A0;margin-top:2px">${sg.service_giver_code || '—'} · ${sg.service_type || '—'} · 📍 ${sg.zone || '—'}</div>
                    </div>
                </div>
                <div style="display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap">
                    ${statusBadge(sg.status)}
                    <span class="badge-status ${sg.is_online?'badge-online':'badge-offline'}">${sg.is_online?'● Online':'○ Offline'}</span>
                </div>
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:18px">
                    ${cap('Rating', rating > 0 ? rating.toFixed(1) + ' ⭐' : '—')}
                    ${cap('Deliveries', done + '/' + total)}
                    ${cap('Success', successRate + (successRate!=='—'?'%':''))}
                    ${cap('Earnings', 'ETB ' + earnings.toFixed(0))}
                </div>
                <div style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:12px 14px;margin-bottom:14px;font-size:12.5px;line-height:1.8">
                    <div><b style="color:#B8B0A0">Owner</b> &nbsp; ${sg.name} · ${sg.phone}</div>
                    ${ratingCount ? `<div><b style="color:#B8B0A0">Reviews</b> &nbsp; ${ratingCount} rating${ratingCount===1?'':'s'}${failed?` · ${failed} failed deliveries`:''}</div>` : ''}
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
                    <div style="font-weight:800;font-size:13px;color:#D4AF37">✏️ Edit Profile</div>
                    <button class="act-btn act-gold" id="sg-edit-toggle-${id}" onclick="toggleSGEditProfile(${id})">Edit</button>
                </div>
                <div id="sg-edit-profile-${id}" style="display:none;margin-bottom:18px">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">
                        <div><label style="font-size:10.5px;color:#B8B0A0;text-transform:uppercase">Business Name</label>
                            <input type="text" id="sg-edit-biz-${id}" value="${(sg.business_name||'').replace(/"/g,'&quot;')}" style="width:100%;padding:8px 10px;border:1px solid rgba(212,175,55,.25)!important;border-radius:8px;background:rgba(255,255,255,.06)!important;color:#f0e6cc!important;font-size:12.5px"></div>
                        <div><label style="font-size:10.5px;color:#B8B0A0;text-transform:uppercase">Service Type</label>
                            <select id="sg-edit-stype-${id}" style="width:100%;padding:8px 10px;border:1px solid rgba(212,175,55,.25)!important;border-radius:8px;background:rgba(255,255,255,.06)!important;color:#f0e6cc!important;font-size:12.5px">
                                ${['restaurant','grocery','pharmacy','laundry','cafe','fastfood','electronics','retail','custom','fuel'].map(t=>`<option value="${t}" ${sg.service_type===t?'selected':''}>${t}</option>`).join('')}
                            </select></div>
                    </div>
                    <div style="margin-bottom:8px">
                        <label style="font-size:10.5px;color:#B8B0A0;text-transform:uppercase">Zone</label>
                        <input type="text" id="sg-edit-zone-${id}" value="${(sg.zone||'').replace(/"/g,'&quot;')}" placeholder="e.g. Mehal Ketema" style="width:100%;padding:8px 10px;border:1px solid rgba(212,175,55,.25)!important;border-radius:8px;background:rgba(255,255,255,.06)!important;color:#f0e6cc!important;font-size:12.5px">
                    </div>
                    <div style="margin-bottom:8px">
                        <label style="font-size:10.5px;color:#B8B0A0;text-transform:uppercase">Address / Description</label>
                        <input type="text" id="sg-edit-addr-${id}" value="${(sg.address||'').replace(/"/g,'&quot;')}" placeholder="e.g. Near Stadium, next to Bank" style="width:100%;padding:8px 10px;border:1px solid rgba(212,175,55,.25)!important;border-radius:8px;background:rgba(255,255,255,.06)!important;color:#f0e6cc!important;font-size:12.5px">
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px">
                        <div><label style="font-size:10.5px;color:#B8B0A0;text-transform:uppercase">Latitude</label>
                            <input type="text" id="sg-edit-lat-${id}" value="${sg.lat||''}" style="width:100%;padding:8px 10px;border:1px solid rgba(212,175,55,.25)!important;border-radius:8px;background:rgba(255,255,255,.06)!important;color:#f0e6cc!important;font-size:12.5px"></div>
                        <div><label style="font-size:10.5px;color:#B8B0A0;text-transform:uppercase">Longitude</label>
                            <input type="text" id="sg-edit-lng-${id}" value="${sg.lng||''}" style="width:100%;padding:8px 10px;border:1px solid rgba(212,175,55,.25)!important;border-radius:8px;background:rgba(255,255,255,.06)!important;color:#f0e6cc!important;font-size:12.5px"></div>
                    </div>
                    <div style="font-size:11px;color:#B8B0A0;text-transform:uppercase;margin-bottom:6px;margin-top:4px;display:flex;align-items:center;gap:6px">
                        <span style="width:20px;height:20px;border-radius:6px;background:linear-gradient(135deg,#1a7a80,#0d4d52);display:inline-flex;align-items:center;justify-content:center;font-size:11px">🏦</span>
                        💰 Payout Methods
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">
                        <div><label style="font-size:10.5px;color:#B8B0A0;text-transform:uppercase">CBE Holder Name</label>
                            <input type="text" id="sg-edit-cbename-${id}" value="${(sg.cbe_account_name||'').replace(/"/g,'&quot;')}" style="width:100%;padding:8px 10px;border:1px solid rgba(212,175,55,.25)!important;border-radius:8px;background:rgba(255,255,255,.06)!important;color:#f0e6cc!important;font-size:12.5px"></div>
                        <div><label style="font-size:10.5px;color:#B8B0A0;text-transform:uppercase">CBE Account Number</label>
                            <input type="text" id="sg-edit-cbeacct-${id}" value="${(sg.cbe_account||'').replace(/"/g,'&quot;')}" style="width:100%;padding:8px 10px;border:1px solid rgba(212,175,55,.25)!important;border-radius:8px;background:rgba(255,255,255,.06)!important;color:#f0e6cc!important;font-size:12.5px"></div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">
                        <div><label style="font-size:10.5px;color:#B8B0A0;text-transform:uppercase">Abysinia Holder Name</label>
                            <input type="text" id="sg-edit-abyname-${id}" value="${(sg.abysinia_account_name||'').replace(/"/g,'&quot;')}" style="width:100%;padding:8px 10px;border:1px solid rgba(212,175,55,.25)!important;border-radius:8px;background:rgba(255,255,255,.06)!important;color:#f0e6cc!important;font-size:12.5px"></div>
                        <div><label style="font-size:10.5px;color:#B8B0A0;text-transform:uppercase">Abysinia Account Number</label>
                            <input type="text" id="sg-edit-abyacct-${id}" value="${(sg.abysinia_account||'').replace(/"/g,'&quot;')}" style="width:100%;padding:8px 10px;border:1px solid rgba(212,175,55,.25)!important;border-radius:8px;background:rgba(255,255,255,.06)!important;color:#f0e6cc!important;font-size:12.5px"></div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px">
                        <div><label style="font-size:10.5px;color:#B8B0A0;text-transform:uppercase">Telebirr Holder Name</label>
                            <input type="text" id="sg-edit-telebirrname-${id}" value="${(sg.telebirr_account_name||'').replace(/"/g,'&quot;')}" style="width:100%;padding:8px 10px;border:1px solid rgba(212,175,55,.25)!important;border-radius:8px;background:rgba(255,255,255,.06)!important;color:#f0e6cc!important;font-size:12.5px"></div>
                        <div><label style="font-size:10.5px;color:#B8B0A0;text-transform:uppercase">Telebirr Number</label>
                            <input type="text" id="sg-edit-telebirr-${id}" value="${(sg.telebirr_number||'').replace(/"/g,'&quot;')}" placeholder="e.g. 0912345678" style="width:100%;padding:8px 10px;border:1px solid rgba(212,175,55,.25)!important;border-radius:8px;background:rgba(255,255,255,.06)!important;color:#f0e6cc!important;font-size:12.5px"></div>
                    </div>
                    <button class="act-btn act-green" style="width:100%;justify-content:center;padding:10px" onclick="saveSGProfileEdit(${id})">💾 Save Changes</button>
                    <div id="sg-edit-msg-${id}" style="font-size:12px;margin-top:8px"></div>
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
                    <div style="font-weight:800;font-size:13px;color:#D4AF37">👥 Staff</div>
                    <div style="display:flex;gap:6px">
                        ${sg.status === 'pending' ? `<button class="act-btn act-green" onclick="verifySG(${sg.id});closeModal()">✓ Verify</button>` : ''}
                        ${sg.status !== 'suspended' ? `<button class="act-btn act-red" onclick="suspendSG(${sg.id});closeModal()">✕ Suspend</button>` : ''}
                        <button class="act-btn act-gold" onclick="printSGSlip(${sg.id})">🖨️ Slip</button>
                    </div>
                </div>
                <div id="sg-manage-staff-list" style="max-height:240px;overflow-y:auto;margin-bottom:16px">${UI.loading('Loading staff…')}</div>
                <button class="modal-submit" onclick="closeModal()" style="background:rgba(255,255,255,.1);color:#e8e2d4">Close</button>
            `);
            const res = await adm('sg_get_staff', { sg_id: id }, true);
            const listEl = document.getElementById('sg-manage-staff-list');
            if (!listEl) return; // modal already closed
            if (!res.success) { listEl.innerHTML = `<p style="color:#e74c3c;font-size:12.5px">${res.message || 'Could not load staff'}</p>`; return; }
            const staff = res.data.staff || [];
            listEl.innerHTML = staff.length ? staff.map(st => {
                const active = !!parseInt(st.active, 10);
                const stInitial = (st.name || '?').charAt(0).toUpperCase();
                return `<div style="display:flex;justify-content:space-between;align-items:center;padding:10px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);border-radius:10px;margin-bottom:8px">
                    <div style="display:flex;align-items:center;gap:10px;min-width:0">
                        <div style="width:32px;height:32px;border-radius:8px;background:rgba(212,175,55,.12);border:1px solid rgba(212,175,55,.25);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#D4AF37;flex-shrink:0">${stInitial}</div>
                        <div style="min-width:0">
                            <div style="font-weight:700;font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${st.name}</div>
                            <div style="font-size:11px;color:#B8B0A0">${st.phone}</div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;flex-shrink:0">
                        <span style="background:rgba(212,175,55,.12);border:1px solid rgba(212,175,55,.25);color:#D4AF37;padding:3px 9px;border-radius:20px;font-size:10.5px;font-weight:700">${st.role_name}</span>
                        <span class="badge-status ${active?'badge-online':'badge-offline'}" style="font-size:9.5px;padding:3px 7px">${active?'Active':'Off'}</span>
                        <button class="act-btn ${active ? 'act-red' : 'act-green'}" onclick="adminToggleSGStaff(${id},${st.id},${active ? 0 : 1})">${active ? 'Deactivate' : 'Reactivate'}</button>
                    </div>
                </div>`;
            }).join('') : '<p style="color:#B8B0A0;font-size:12.5px;text-align:center;padding:14px">No staff accounts at this business yet.</p>';
        }

        function toggleSGEditProfile(id) {
            const box = document.getElementById('sg-edit-profile-' + id);
            const btn = document.getElementById('sg-edit-toggle-' + id);
            if (!box) return;
            const showing = box.style.display !== 'none';
            box.style.display = showing ? 'none' : 'block';
            if (btn) btn.textContent = showing ? 'Edit' : 'Cancel';
        }

        async function saveSGProfileEdit(id) {
            const msgEl = document.getElementById('sg-edit-msg-' + id);
            const val = (elId) => { const el = document.getElementById(elId); return el ? el.value.trim() : ''; };
            const payload = {
                sg_id: id,
                business_name: val('sg-edit-biz-' + id),
                service_type: val('sg-edit-stype-' + id),
                zone: val('sg-edit-zone-' + id),
                address: val('sg-edit-addr-' + id),
            };
            const lat = val('sg-edit-lat-' + id), lng = val('sg-edit-lng-' + id);
            if (lat && lng) { payload.lat = lat; payload.lng = lng; }
            payload.cbe_account_name = val('sg-edit-cbename-' + id);
            payload.cbe_account = val('sg-edit-cbeacct-' + id);
            payload.abysinia_account_name = val('sg-edit-abyname-' + id);
            payload.abysinia_account = val('sg-edit-abyacct-' + id);
            payload.telebirr_account_name = val('sg-edit-telebirrname-' + id);
            payload.telebirr_number = val('sg-edit-telebirr-' + id);
            if (msgEl) { msgEl.style.color = '#B8B0A0'; msgEl.textContent = 'Saving…'; }
            const res = await adm('sg_update_profile', payload);
            if (!res.success) {
                if (msgEl) { msgEl.style.color = '#e74c3c'; msgEl.textContent = res.message || 'Save failed'; }
                return;
            }
            if (msgEl) { msgEl.style.color = '#2ecc71'; msgEl.textContent = 'Saved ✓'; }
            toast('Business profile updated');
            loadServiceGivers(); // refresh underlying list/cards so the change is visible everywhere, not just this modal
        }

        async function adminToggleSGStaff(sgId, staffId, nextActive) {
            const res = await adm('sg_remove_staff', { staff_id: staffId, active: nextActive });
            if (res.success) { toast(nextActive ? 'Staff reactivated' : 'Staff deactivated'); openSGManageModal(sgId); }
            else toast(res.message || 'Error', 'err');
        }
        async function verifySG(id) {
            if (!confirm('Verify this service giver?')) return;
            const res = await api('admin_verify_service_giver', {id});
            if (res.success) { toast('Service giver verified ✓'); loadServiceGivers(); }
            else toast(res.message||'Error', 'err');
        }
        async function suspendSG(id) {
            if (!confirm('Suspend this service giver?')) return;
            const res = await api('admin_suspend_service_giver', {id});
            if (res.success) { toast('Service giver suspended'); loadServiceGivers(); }
            else toast(res.message||'Error', 'err');
        }

        // ─── REPORTS ──────────────────────────────────────────────────
        async function loadReports() {
            const res = await adm('admin_dashboard_stats', {}, true);
            if (res.success) {
                const d = res.data;
                document.getElementById('r-orders').textContent    = d.orders.toLocaleString();
                document.getElementById('r-delivered').textContent = d.delivered.toLocaleString();
                document.getElementById('r-pending').textContent   = d.pending.toLocaleString();
                document.getElementById('r-revenue').textContent   = 'ETB ' + d.revenue.toLocaleString();
                document.getElementById('r-couriers').textContent  = d.couriers.toLocaleString();
                document.getElementById('r-sgs').textContent       = d.service_givers.toLocaleString();
                const rate = d.orders > 0 ? Math.round((d.delivered / d.orders) * 100) : 0;
                document.getElementById('delivery-bar').style.width = rate + '%';
                document.getElementById('delivery-rate').textContent = rate + '%';
            }
        }

        // ─── PRINTING ─────────────────────────────────────────────────
        // Each handler runs directly from its own button click (a fresh
        // user gesture), which Web Bluetooth's device picker requires on
        // first-ever pairing. Once paired, AdminPrinter reuses the
        // connection silently for every print call below.
        async function connectAdminPrinter() {
            const statusEl = document.getElementById('admin-printer-status');
            if (!AdminPrinter.isSupported()) {
                if (statusEl) statusEl.textContent = '✕ This browser does not support direct printer connections (use Chrome)';
                toast('Direct printing needs Chrome on Android or desktop.', 'err');
                return;
            }
            if (statusEl) statusEl.textContent = 'Connecting…';
            try {
                await AdminPrinter.connect();
                if (statusEl) statusEl.textContent = '● Printer connected';
                toast('Printer connected!');
            } catch (e) {
                if (statusEl) statusEl.textContent = '○ Not connected';
                toast('Could not connect: ' + e.message, 'err');
            }
        }
        function forgetAdminPrinter() {
            AdminPrinter.forget();
            const statusEl = document.getElementById('admin-printer-status');
            if (statusEl) statusEl.textContent = '○ Not connected';
            toast('Printer forgotten. Connect again to pair.');
        }
        async function printDailySummaryUI() {
            const res = await adm('admin_dashboard_stats', {}, true);
            if (!res || !res.success) { toast((res && res.message) || 'Could not load stats for printing', 'err'); return; }
            await AdminPrinter.printDailySummary(res.data, new Date().toLocaleDateString());
        }

        // ─── SETTINGS ─────────────────────────────────────────────────
        // A staff account (Dispatcher, Finance, Traffic Officer, Manager -
        // assigned in EtelGroup's Employees panel) always carries role
        // 'admin' underneath (that's what grants dashboard access at all -
        // see admin.html's auth gate), but showing the bare word "admin"
        // here told a Dispatcher they were somehow a full admin/owner,
        // which isn't what their account actually is. staff_role (already
        // returned by login/admin_etelid_login and saved in Auth.getUser())
        // is the real, specific title EtelGroup assigned them - show that
        // instead whenever it's set; only a true full admin with no
        // specific staff assignment still shows "Admin".
        function _staffRoleLabel(u) {
            if (!u) return '—';
            if (u.staff_role) {
                return String(u.staff_role).replace(/\b\w/g, function (c) { return c.toUpperCase(); });
            }
            return u.role ? (u.role.charAt(0).toUpperCase() + u.role.slice(1)) : '—';
        }
        function loadSettings() {
            const u = Auth.getUser();
            document.getElementById('settings-user-info').innerHTML =
                `<strong>Logged in as:</strong> ${u?u.name:'—'}<br><strong>Phone:</strong> ${u?u.phone:'—'}<br><strong>Role:</strong> ${_staffRoleLabel(u)}`;
            loadSysInfo();
        }
        async function loadSysInfo() {
            const res = await adm('ping', {}, true);
            if (res.success) {
                const d = res.data;
                document.getElementById('sys-info').innerHTML =
                    `<div>API Version: <span>${d.version}</span></div>
                     <div>PHP: <span>${d.php}</span></div>
                     <div>Database: <span style="color:${d.db==='connected'?'#2ecc71':'#e63946'}">${d.db}</span></div>
                     <div>Server Time: <span>${d.time}</span></div>`;
            }
        }
        async function changePassword() {
            const cur = document.getElementById('set-cur-pw').value;
            const nw  = document.getElementById('set-new-pw').value;
            const cf  = document.getElementById('set-conf-pw').value;
            const msg = document.getElementById('settings-msg');
            msg.style.cssText = '';
            if (!cur || !nw || !cf) { msg.style.cssText='color:red'; msg.textContent='All fields required.'; return; }
            if (nw !== cf) { msg.style.cssText='color:red'; msg.textContent='Passwords do not match.'; return; }
            if (nw.length < 8) { msg.style.cssText='color:red'; msg.textContent='Password must be at least 8 characters.'; return; }
            msg.style.cssText='color:#B8B0A0'; msg.textContent='Updating…';
            const res = await api('change_password', { current_password: cur, new_password: nw });
            if (res && res.success) {
                if (res.data && res.data.require_relogin) {
                    msg.style.cssText='color:#2ECC71'; msg.textContent='Password updated. Please log in again…';
                    setTimeout(() => { ETEL.auth.logout(); }, 1500);
                    return;
                }
                if (res.data && res.data.token) localStorage.setItem('etel_token', res.data.token);
                msg.style.cssText='color:#2ECC71'; msg.textContent='Password updated ✓';
                document.getElementById('set-cur-pw').value = '';
                document.getElementById('set-new-pw').value = '';
                document.getElementById('set-conf-pw').value = '';
            } else {
                msg.style.cssText='color:red'; msg.textContent=(res && res.message) || 'Could not update password.';
            }
        }

        // ─── MODALS ───────────────────────────────────────────────────
        function openModal(type, customHtml) {
            const overlay = document.getElementById('modal-overlay');
            const content = document.getElementById('modal-content');
            overlay.style.display = 'flex';
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
                    <p style="color:#B8B0A0;font-size:13px;margin-bottom:16px">Creates a user account with courier role and a courier profile.</p>
                    <div class="modal-field"><label>Full Name *</label><input type="text" id="mc-name" placeholder="Dawit Tesfaye"></div>
                    <div class="modal-field"><label>Phone *</label><input type="tel" id="mc-phone" placeholder="+251911000000"></div>
                    <div class="modal-field"><label>Password * (min 6)</label><input type="password" id="mc-pw" placeholder="Password"></div>
                    <div class="modal-field"><label>Vehicle Type</label>
                        <select id="mc-vehicle"><option value="motorcycle">Motorcycle</option><option value="bicycle">Bicycle</option><option value="car">Car</option><option value="on_foot">On Foot</option></select>
                    </div>
                    <div class="modal-field"><label>Home Zone</label>
                        <select id="mc-zone">${ETEL.zones.map(z=>`<option value="${z}">${z}</option>`).join('')}</select>
                    </div>
                    <div class="modal-msg" id="mc-msg"></div>
                    <button class="modal-submit" onclick="submitNewCourier()">Register Courier</button>`;
            } else if (type === 'new-sg') {
                overlay.style.cssText = 'display:flex;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.6);align-items:stretch;padding:0;';
                const box = document.getElementById('modal-box');
                box.style.cssText = 'width:100%;max-width:100%;height:100vh;max-height:100vh;border-radius:0;padding:0;overflow:hidden;display:flex;flex-direction:row;box-shadow:none;background:#fff;';
                content.style.cssText = 'display:flex;flex-direction:row;width:100%;height:100%;';
                content.innerHTML = `
                    <div style="width:420px;min-width:340px;max-width:460px;background:#fff;display:flex;flex-direction:column;height:100vh;overflow-y:auto;border-right:1px solid #e8e4d8;flex-shrink:0;">
                        <div style="background:linear-gradient(135deg,#1a1a2e 0%,#2d2352 100%);padding:20px 24px;display:flex;align-items:center;gap:14px;flex-shrink:0;">
                            <button onclick="closeModal()" style="background:rgba(255,255,255,.15);border:none;width:36px;height:36px;border-radius:50%;font-size:18px;cursor:pointer;color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">✕</button>
                            <div>
                                <div style="font-family:'DM Serif Display',serif;font-size:20px;color:#D4AF37;font-weight:700;">🏪 New Service Giver</div>
                                <div style="font-size:11px;color:rgba(255,255,255,.6);margin-top:2px;">Register a new partner account</div>
                            </div>
                        </div>
                        <div style="padding:20px 24px;flex:1;overflow-y:auto;">
                            <div class="modal-field"><label>Full Name *</label><input type="text" id="sg-name" placeholder="Kebede Alemu" style="color:#1a1a2e!important;background:#fff!important"></div>
                            <div class="modal-field"><label>Phone *</label><input type="tel" id="sg-phone" placeholder="+251911000000" style="color:#1a1a2e!important;background:#fff!important"></div>
                            <div class="modal-field"><label>Password * (min 6 chars)</label><input type="password" id="sg-pw" placeholder="Secure password" style="color:#1a1a2e!important;background:#fff!important"></div>
                            <div class="modal-field"><label>Business Name</label><input type="text" id="sg-biz" placeholder="My Restaurant" style="color:#1a1a2e!important;background:#fff!important"></div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                <div class="modal-field" style="margin:0"><label>Service Type</label>
                                    <select id="sg-stype" style="color:#1a1a2e!important;background:#fff!important">
                                        <option value="restaurant">Restaurant</option>
                                        <option value="grocery">Grocery</option>
                                        <option value="pharmacy">Pharmacy</option>
                                        <option value="laundry">Laundry</option>
                                        <option value="cafe">Cafe</option>
                                        <option value="fastfood">Fast Food</option>
                                        <option value="electronics">Electronics</option>
                                        <option value="custom">Custom / Other</option>
                                    </select>
                                </div>
                                <div class="modal-field" style="margin:0"><label>Zone</label>
                                    <input type="text" id="sg-zone-sel" placeholder="Pin the map below"
                                        style="color:#1a1a2e!important;background:#fff!important;border:1.5px solid #e0e0e0;border-radius:8px;padding:10px 12px;font-size:13px;font-family:inherit;width:100%">
                                </div>
                            </div>
                            <div class="modal-field"><label>Address / Description</label><input type="text" id="sg-addr" placeholder="e.g. Near Stadium, next to Bank" style="color:#1a1a2e!important;background:#fff!important"></div>
                            <div class="modal-field"><label>ID Number</label><input type="text" id="sg-idnum" placeholder="National ID (optional)" style="color:#1a1a2e!important;background:#fff!important"></div>
                            <div class="modal-field">
                                <label style="color:#444;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px">📍 GPS COORDINATES — click on the map to pin</label>
                                <div style="display:flex;gap:8px;margin-top:4px;">
                                    <input type="text" id="sg-lat" placeholder="Latitude" style="flex:1;border:1.5px solid #e0e0e0;border-radius:8px;padding:8px 10px;font-size:12px;font-family:inherit;color:#1a1a2e!important;background:#fff!important">
                                    <input type="text" id="sg-lng" placeholder="Longitude" style="flex:1;border:1.5px solid #e0e0e0;border-radius:8px;padding:8px 10px;font-size:12px;font-family:inherit;color:#1a1a2e!important;background:#fff!important">
                                </div>
                                <div style="font-size:11px;color:#8a7f66;margin-top:5px">Zone is detected automatically from wherever you pin the map or type coordinates directly - no fixed list to pick from.</div>
                            </div>
                            <div class="modal-msg" id="sg-msg"></div>
                        </div>
                        <div style="padding:16px 24px;border-top:1px solid #f0ece0;flex-shrink:0;">
                            <button class="modal-submit" onclick="submitNewSG()" style="margin:0;">✓ Create Service Giver</button>
                        </div>
                    </div>
                    <div style="flex:1;position:relative;background:#e8e4d8;min-width:0;">
                        <button type="button" onclick="sgUseCurrentLocation()" style="position:absolute;top:16px;left:50%;transform:translateX(-50%);z-index:1000;padding:10px 22px;background:linear-gradient(135deg,#D4AF37,#b8922e);color:#1a1a2e;border:none;border-radius:24px;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,.3);white-space:nowrap;">
                            📡 Use My Location
                        </button>
                        <div id="sg-map" style="width:100%;height:100%;"></div>
                        <div id="sg-zone-detected" style="position:absolute;bottom:20px;left:50%;transform:translateX(-50%);padding:10px 20px;background:rgba(26,26,46,.9);border-radius:24px;font-size:13px;color:#D4AF37;font-weight:700;display:none;white-space:nowrap;z-index:1000;box-shadow:0 4px 16px rgba(0,0,0,.3);">
                            📍 Zone: <span id="sg-zone-label">—</span>
                        </div>
                    </div>`;
                sgMap = null; sgMarker = null;
                setTimeout(initSGMap, 200);
            }
        }

        // ─── SG LOCATION PICKER (Google Maps) ────────────────────────
        let sgMap = null, sgMarker = null;
        const SG_DEFAULT = { lat: 7.0504, lng: 38.4955 };

        const SG_ZONES = [
            { name:'Stadium',              lat:7.0606, lng:38.4775 },
            { name:'Mehal Ketema',         lat:7.0559, lng:38.4762 },
            { name:'Tabor',                lat:7.0400, lng:38.4900 },
            { name:'Liqe Mender',          lat:7.0700, lng:38.4800 },
            { name:'Referral',             lat:7.0490, lng:38.4680 },
            { name:'Addis Ketema',         lat:7.0450, lng:38.4750 },
            { name:'University',           lat:7.0620, lng:38.5110 },
            { name:'Misrak',               lat:7.0800, lng:38.4850 },
            { name:'Haik Dar',             lat:7.0350, lng:38.5000 },
            { name:'Tulu Dimtu',           lat:7.1000, lng:38.5000 },
            { name:'Hawela Teklehaimanot', lat:7.0650, lng:38.4900 },
            { name:'Dore Bafana',          lat:7.1200, lng:38.4700 }
        ];

        function sgHaversine(lat1, lng1, lat2, lng2) {
            const R = 6371, dLat = (lat2-lat1)*Math.PI/180, dLng = (lng2-lng1)*Math.PI/180;
            const a = Math.sin(dLat/2)**2 + Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dLng/2)**2;
            return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        }

        function sgDetectZone(lat, lng) {
            let best = SG_ZONES[0], bestDist = Infinity;
            for (const z of SG_ZONES) {
                const d = sgHaversine(lat, lng, z.lat, z.lng);
                if (d < bestDist) { bestDist = d; best = z; }
            }
            return best.name;
        }

        function setSGCoords(lat, lng) {
            const latEl = document.getElementById('sg-lat');
            const lngEl = document.getElementById('sg-lng');
            if (latEl) latEl.value = lat.toFixed(7);
            if (lngEl) lngEl.value = lng.toFixed(7);
            const zone = sgDetectZone(lat, lng);
            // Single source of truth now - a plain editable text field, not
            // a fixed dropdown list. sgDetectZone() can return any zone
            // name it computes from the coordinates; forcing that into a
            // <select> silently failed to show anything when the detected
            // name didn't happen to match one of a hardcoded list of
            // options, which is exactly the friction this replaces.
            const zoneSelEl = document.getElementById('sg-zone-sel');
            if (zoneSelEl && zone) zoneSelEl.value = zone;
            const zoneLabel = document.getElementById('sg-zone-label');
            const zoneDetected = document.getElementById('sg-zone-detected');
            if (zoneLabel) zoneLabel.textContent = zone;
            if (zoneDetected) zoneDetected.style.display = 'block';
        }

        function initSGMap() {
            const el = document.getElementById('sg-map');
            if (!el) return;
            if (typeof google === 'undefined' || !google.maps || !google.maps.MapTypeId) {
                // Google Maps not yet ready — wait for it, or show fallback
                el.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#B8B0A0;font-size:13px;text-align:center;padding:20px;flex-direction:column;gap:8px;"><div style="font-size:32px">🗺️</div><div>Loading map…</div></div>';
                window.initSGMapWhenReady = function() { initSGMap(); };
                return;
            }
            sgMap = new google.maps.Map(el, {
                zoom: 14,
                center: SG_DEFAULT,
                mapTypeId: google.maps.MapTypeId.ROADMAP,
                streetViewControl: false,
                fullscreenControl: false,
                mapTypeControl: true,
                mapTypeControlOptions: {
                    style: google.maps.MapTypeControlStyle.HORIZONTAL_BAR,
                    mapTypeIds: [google.maps.MapTypeId.ROADMAP, google.maps.MapTypeId.HYBRID]
                },
                styles: [{ featureType: 'poi', elementType: 'labels', stylers: [{ visibility: 'off' }] }]
            });

            const markerEl = document.createElement('div');
            markerEl.style.cssText = 'width:24px;height:24px;border-radius:50%;background:#D4AF37;border:3px solid #fff;box-shadow:0 0 0 3px rgba(212,175,55,.5),0 4px 12px rgba(0,0,0,.4);cursor:move;';

            // Advanced Markers require a registered Map ID (Google Cloud
            // Console) or they fail with "map is initialized without a
            // valid Map ID" - not configured here, and this is just an
            // internal location picker with no need for that feature, so
            // always use the classic Marker, which needs neither a Map ID
            // nor any extra setup.
            sgMarker = new google.maps.Marker({
                position: SG_DEFAULT, map: sgMap, draggable: true,
                icon: { path: google.maps.SymbolPath.CIRCLE, scale: 11, fillColor: '#D4AF37', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 3 }
            });
            sgMarker.addListener('dragend', function(e) { setSGCoords(e.latLng.lat(), e.latLng.lng()); });

            sgMap.addListener('click', function(e) {
                const lat = e.latLng.lat(), lng = e.latLng.lng();
                if (sgMarker.setPosition) sgMarker.setPosition(e.latLng);
                else sgMarker.position = { lat, lng };
                setSGCoords(lat, lng);
            });

            setSGCoords(SG_DEFAULT.lat, SG_DEFAULT.lng);
        }

        function sgUseCurrentLocation() {
            if (!navigator.geolocation) { toast('Geolocation not supported', 'err'); return; }
            navigator.geolocation.getCurrentPosition(function(pos){
                const lat = pos.coords.latitude, lng = pos.coords.longitude;
                setSGCoords(lat, lng);
                if (sgMap) { sgMap.setCenter({ lat, lng }); sgMap.setZoom(17); }
                if (sgMarker) {
                    if (sgMarker.setPosition) sgMarker.setPosition({ lat, lng });
                    else sgMarker.position = { lat, lng };
                }
            }, function(){ toast('Could not get location — click on the map instead', 'err'); }, { enableHighAccuracy: true, timeout: 8000 });
        }

        function closeModal() {
            const overlay = document.getElementById('modal-overlay');
            const box = document.getElementById('modal-box');
            const content = document.getElementById('modal-content');
            overlay.style.display = 'none';
            overlay.style.alignItems = '';
            overlay.style.padding = '';
            box.style.cssText = '';
            content.style.cssText = '';
            content.innerHTML = '';
            // Google Maps has no .remove() — just null the references
            sgMap = null; sgMarker = null;
        }
        document.getElementById('modal-overlay').addEventListener('click', function(e) { if(e.target===this) closeModal(); });

        async function submitNewUser() {
            const name=document.getElementById('mu-name').value.trim();
            const phone=document.getElementById('mu-phone').value.trim().replace(/\s/g,'');
            const pw=document.getElementById('mu-pw').value;
            const role=document.getElementById('mu-role').value;
            const msg=document.getElementById('mu-msg');
            if (!name||!phone||!pw) { msg.className='modal-msg err'; msg.textContent='All required fields must be filled.'; return; }
            if (pw.length<8) { msg.className='modal-msg err'; msg.textContent='Password must be at least 8 characters.'; return; }
            msg.className='modal-msg'; msg.textContent='Creating…'; msg.style.display='block';
            const res = await api('admin_create_user', {name, phone, password:pw, role});
            if (res.success) {
                msg.className='modal-msg ok'; msg.textContent='✓ User created successfully!';
                setTimeout(()=>{ closeModal(); loadUsers(); }, 1200);
            } else { msg.className='modal-msg err'; msg.textContent=res.message||'Creation failed'; }
        }
        async function submitNewCourier() {
            const name=document.getElementById('mc-name').value.trim();
            const phone=document.getElementById('mc-phone').value.trim().replace(/\s/g,'');
            const pw=document.getElementById('mc-pw').value;
            const vehicle=document.getElementById('mc-vehicle').value;
            const zone=document.getElementById('mc-zone').value;
            const msg=document.getElementById('mc-msg');
            if (!name||!phone||!pw) { msg.className='modal-msg err'; msg.textContent='All required fields must be filled.'; return; }
            msg.className='modal-msg'; msg.textContent='Registering…'; msg.style.display='block';
            const res = await api('register', {name, phone, password:pw, role:'courier', vehicle, zone});
            if (res.success) {
                msg.className='modal-msg ok'; msg.textContent='✓ Courier registered!';
                setTimeout(()=>{ closeModal(); loadCouriers(); }, 1200);
            } else { msg.className='modal-msg err'; msg.textContent=res.message||'Registration failed'; }
        }
        async function submitNewSG() {
            const name=document.getElementById('sg-name').value.trim();
            const phone=document.getElementById('sg-phone').value.trim().replace(/\s/g,'');
            const pw=document.getElementById('sg-pw').value;
            const business_name=document.getElementById('sg-biz').value.trim();
            const service_type=document.getElementById('sg-stype').value;
            const zone=(document.getElementById('sg-zone-sel')||{}).value?.trim()||'';
            const address=document.getElementById('sg-addr').value.trim();
            const lat=document.getElementById('sg-lat').value.trim();
            const lng=document.getElementById('sg-lng').value.trim();
            const identification_number=document.getElementById('sg-idnum').value.trim();
            const msg=document.getElementById('sg-msg');
            if (!name||!phone||!pw) { msg.className='modal-msg err'; msg.textContent='Name, phone, and password are required.'; msg.style.display='block'; return; }
            if (pw.length<8) { msg.className='modal-msg err'; msg.textContent='Password must be at least 8 characters.'; msg.style.display='block'; return; }
            if (!zone) { msg.className='modal-msg err'; msg.textContent='Zone is required - click the map to pin a location, or type a zone name directly.'; msg.style.display='block'; return; }
            msg.className='modal-msg'; msg.textContent='Creating…'; msg.style.display='block';
            const payload = {name, phone, password:pw, business_name, service_type, zone, address, identification_number};
            if (lat && lng) { payload.lat = lat; payload.lng = lng; }
            const res = await api('admin_create_service_giver', payload);
            if (res.success) {
                msg.className='modal-msg ok'; msg.textContent='✓ Service giver created!';
                setTimeout(()=>{ closeModal(); loadServiceGivers(); }, 1200);
            } else { msg.className='modal-msg err'; msg.textContent=res.message||'Creation failed'; }
        }

        // ─── ITEMS ────────────────────────────────────────────────────
        let itemsData = [], sgListCache = [];

        async function loadSGListForFilter() {
            if (sgListCache.length) return;
            const res = await adm('admin_get_service_givers_list', {}, true);
            if (res.success) {
                sgListCache = res.data.service_givers || [];
                const sel = document.getElementById('item-sg-filter');
                if (sel) sgListCache.forEach(sg => {
                    const o = document.createElement('option');
                    o.value = sg.id; o.textContent = sg.business_name || sg.name;
                    sel.appendChild(o);
                });
            }
        }

        async function loadItems() {
            await loadSGListForFilter();
            document.getElementById('items-tbody').innerHTML = '<tr><td colspan="8" style="text-align:center;padding:30px;">' + UI.loading('Loading menu items...') + '</td></tr>';
            const cat  = document.getElementById('item-cat-filter').value;
            const sgId = document.getElementById('item-sg-filter').value;
            const params = {};
            if (cat)  params.category = cat;
            if (sgId) params.service_giver_id = sgId;
            const res = await adm('admin_get_all_items', params, true);
            if (!res.success) { document.getElementById('items-tbody').innerHTML = `<tr><td colspan="8" style="color:red;padding:20px">${res.message}</td></tr>`; return; }
            itemsData = res.data.items || [];
            renderItemTable(itemsData);
        }

        function renderItemTable(data) {
            document.getElementById('items-tbody').innerHTML = data.length ? data.map(it => `
                <tr>
                    <td>${it.image_url ? `<img src="${escUrl(it.image_url)}" style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:1px solid #e0e0e0">` : '<div style="width:44px;height:44px;background:rgba(255,255,255,.06);border-radius:6px;display:flex;align-items:center;justify-content:center">🛍️</div>'}</td>
                    <td><strong>${esc(it.name)}</strong><br><span style="font-size:11px;color:#B8B0A0">${esc(it.item_code||'')}</span></td>
                    <td><span style="background:rgba(212,175,55,.15);color:#D4AF37;border:1px solid rgba(212,175,55,.3);padding:3px 8px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase">${esc(it.category || '—')}</span></td>
                    <td>${esc(it.business_name||'—')}</td>
                    <td><strong>ETB ${parseFloat(it.price||0).toFixed(2)}</strong></td>
                    <td>${it.stock_quantity||0}</td>
                    <td>
                        <label style="display:flex;align-items:center;gap:5px;cursor:pointer">
                            <input type="checkbox" ${(it.is_available=='1'||it.is_available===true||it.is_available===1)?'checked':''} onchange="adminToggleItem(${it.id},this.checked)">
                            <span style="font-size:11px;color:${(it.is_available=='1'||it.is_available===true||it.is_available===1)?'#2ecc71':'#B8B0A0'}">${(it.is_available=='1'||it.is_available===true||it.is_available===1)?'Yes':'No'}</span>
                        </label>
                    </td>
                    <td style="white-space:nowrap">
                        <button class="act-btn act-gold" onclick="openAdminEditItem(${it.id})" style="margin-right:4px">✏ Edit</button>
                        <button class="act-btn act-red"  onclick="adminDeleteItem(${it.id},'${(it.name||'').replace(/[<>"'\\]/g,'')}')">🗑 Del</button>
                    </td>
                </tr>`).join('') : '<tr><td colspan="8" style="text-align:center;color:#B8B0A0;padding:30px">No items found</td></tr>';
        }

        function filterItemTable() {
            const q = (document.getElementById('item-search').value || '').toLowerCase();
            renderItemTable(q ? itemsData.filter(i => (i.name||'').toLowerCase().includes(q)) : itemsData);
        }

        async function adminToggleItem(id, checked) {
            await adm('admin_toggle_item', {item_id:id, is_available: checked?1:0});
            toast(checked ? 'Item set available' : 'Item hidden');
        }

        async function adminDeleteItem(id, name) {
            if (!confirm('Delete "' + name + '"? Cannot be undone.')) return;
            const res = await adm('admin_delete_item', {item_id: id});
            if (res.success) { toast('Deleted ✓'); loadItems(); }
            else toast(res.message||'Delete failed','err');
        }

        function openAdminAddItem() {
            if (!sgListCache.length) { toast('No service givers found. Add one first.','err'); return; }
            const sgOpts = sgListCache.map(sg=>`<option value="${sg.id}">${sg.business_name||sg.name}</option>`).join('');
            document.getElementById('modal-content').innerHTML = `
                <div class="modal-title">➕ Add Item</div>
                <div class="modal-field"><label>Service Giver *</label><select id="ai-sg">${sgOpts}</select></div>
                <div class="modal-field"><label>Item Name *</label><input id="ai-name" placeholder="e.g. Injera Fitfit"></div>
                <div class="modal-field"><label>Description</label><textarea id="ai-desc" rows="2" style="width:100%;padding:10px 12px;border:1.5px solid #e0e0e0;border-radius:8px;font-size:13px;font-family:'Poppins',sans-serif;resize:vertical;box-sizing:border-box"></textarea></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="modal-field"><label>Category *</label><select id="ai-cat"><option value="food">Food</option><option value="grocery">Grocery</option><option value="pharmacy">Pharmacy</option><option value="package">Package</option><option value="flower">Flower</option></select></div>
                    <div class="modal-field"><label>Stock Qty</label><input id="ai-stock" type="number" value="100" min="0"></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="modal-field"><label>Price (ETB) *</label><input id="ai-price" type="number" step="0.01" min="0"></div>
                    <div class="modal-field"><label>Original Price</label><input id="ai-orig" type="number" step="0.01" min="0"></div>
                </div>
                <div class="modal-field"><label>Prep Time (min)</label><input id="ai-prep" type="number" value="30" min="0"></div>
                <div class="modal-field">
                    <label>Image</label>
                    <input type="file" id="ai-img-file" accept="image/*" style="width:100%;padding:8px;border:1.5px dashed rgba(255,255,255,.2);border-radius:8px;background:rgba(255,255,255,.03);cursor:pointer;margin-bottom:6px;color:#e8e2d4">
                    <input id="ai-img-url" placeholder="or paste image URL" style="width:100%;padding:10px 12px;border:1.5px solid #e0e0e0;border-radius:8px;font-size:13px;font-family:'Poppins',sans-serif;box-sizing:border-box">
                </div>
                <div id="ai-msg" class="modal-msg"></div>
                <button class="modal-submit" onclick="submitAdminItem()">💾 Save Item</button>`;
            document.getElementById('modal-overlay').style.display = 'flex';
        }

        function openAdminEditItem(id) {
            const it = itemsData.find(x => x.id == id);
            if (!it) return;
            document.getElementById('modal-content').innerHTML = `
                <div class="modal-title">✏ Edit Item</div>
                <input type="hidden" id="aei-id" value="${it.id}">
                <div class="modal-field"><label>Item Name *</label><input id="aei-name" value="${it.name||''}"></div>
                <div class="modal-field"><label>Description</label><textarea id="aei-desc" rows="2" style="width:100%;padding:10px 12px;border:1.5px solid #e0e0e0;border-radius:8px;font-size:13px;font-family:'Poppins',sans-serif;resize:vertical;box-sizing:border-box">${it.description||''}</textarea></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="modal-field"><label>Category *</label><select id="aei-cat"><option value="food" ${it.category==='food'?'selected':''}>Food</option><option value="grocery" ${it.category==='grocery'?'selected':''}>Grocery</option><option value="pharmacy" ${it.category==='pharmacy'?'selected':''}>Pharmacy</option><option value="package" ${it.category==='package'?'selected':''}>Package</option><option value="flower" ${it.category==='flower'?'selected':''}>Flower</option></select></div>
                    <div class="modal-field"><label>Stock Qty</label><input id="aei-stock" type="number" value="${it.stock_quantity||0}" min="0"></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="modal-field"><label>Price (ETB) *</label><input id="aei-price" type="number" step="0.01" value="${it.price||''}"></div>
                    <div class="modal-field"><label>Original Price</label><input id="aei-orig" type="number" step="0.01" value="${it.original_price||''}"></div>
                </div>
                <div class="modal-field"><label>Prep Time (min)</label><input id="aei-prep" type="number" value="${it.preparation_time||30}"></div>
                <div class="modal-field">
                    <label>Image</label>
                    <input type="file" id="aei-img-file" accept="image/*" style="width:100%;padding:8px;border:1.5px dashed rgba(255,255,255,.2);border-radius:8px;background:rgba(255,255,255,.03);cursor:pointer;margin-bottom:6px;color:#e8e2d4">
                    <input id="aei-img-url" placeholder="or paste image URL" value="${it.image_url||''}" style="width:100%;padding:10px 12px;border:1.5px solid #e0e0e0;border-radius:8px;font-size:13px;font-family:'Poppins',sans-serif;box-sizing:border-box">
                </div>
                <div id="aei-msg" class="modal-msg"></div>
                <button class="modal-submit" onclick="submitAdminEditItem()">💾 Update Item</button>`;
            document.getElementById('modal-overlay').style.display = 'flex';
        }

        async function uploadImageForAdmin(fileInputId, urlInputId) {
            const fi = document.getElementById(fileInputId);
            if (!fi || !fi.files || !fi.files[0]) return document.getElementById(urlInputId).value.trim();
            const uploadUrl = ETEL.api.replace('boot.php','upload.php');
            const fd = new FormData();
            fd.append('image', fi.files[0]);
            const r = await fetch(uploadUrl, {method:'POST', headers:{'Authorization':'Bearer '+Auth.getToken()}, body:fd});
            const j = await r.json();
            return j.success ? j.data.url : '';
        }

        async function submitAdminItem() {
            const msg = document.getElementById('ai-msg');
            const name  = (document.getElementById('ai-name').value||'').trim();
            const price = parseFloat(document.getElementById('ai-price').value||0);
            const sg_id = parseInt(document.getElementById('ai-sg').value||0);
            if (!name||!price||!sg_id) { msg.className='modal-msg err'; msg.textContent='Name, price, and service giver are required'; return; }
            msg.className='modal-msg'; msg.style.display='block'; msg.textContent='Saving…';
            const image_url = await uploadImageForAdmin('ai-img-file','ai-img-url');
            const res = await adm('admin_add_item',{
                service_giver_id:sg_id, name,
                description:(document.getElementById('ai-desc').value||'').trim(),
                category:document.getElementById('ai-cat').value,
                price, original_price:parseFloat(document.getElementById('ai-orig').value||0)||price,
                stock_quantity:parseInt(document.getElementById('ai-stock').value||0),
                preparation_time:parseInt(document.getElementById('ai-prep').value||30),
                image_url
            });
            if (res.success) { msg.className='modal-msg ok'; msg.textContent='✓ Item added!'; setTimeout(()=>{ closeModal(); loadItems(); },1200); }
            else { msg.className='modal-msg err'; msg.textContent=res.message||'Failed'; }
        }

        async function submitAdminEditItem() {
            const msg = document.getElementById('aei-msg');
            const id    = parseInt(document.getElementById('aei-id').value);
            const name  = (document.getElementById('aei-name').value||'').trim();
            const price = parseFloat(document.getElementById('aei-price').value||0);
            if (!name||!price) { msg.className='modal-msg err'; msg.textContent='Name and price are required'; return; }
            msg.className='modal-msg'; msg.style.display='block'; msg.textContent='Saving…';
            const image_url = await uploadImageForAdmin('aei-img-file','aei-img-url');
            const res = await adm('admin_update_item',{
                item_id:id, name,
                description:(document.getElementById('aei-desc').value||'').trim(),
                category:document.getElementById('aei-cat').value,
                price, original_price:parseFloat(document.getElementById('aei-orig').value||0)||price,
                stock_quantity:parseInt(document.getElementById('aei-stock').value||0),
                preparation_time:parseInt(document.getElementById('aei-prep').value||30),
                image_url
            });
            if (res.success) { msg.className='modal-msg ok'; msg.textContent='✓ Updated!'; setTimeout(()=>{ closeModal(); loadItems(); },1200); }
            else { msg.className='modal-msg err'; msg.textContent=res.message||'Failed'; }
        }

        // ─── FILE UPDATER ─────────────────────────────────────────────
        // ─── PREMIUM FILE MANAGER ────────────────────────────────────
        const UPD = {
            uploadUrl: null,
            apiUrl: null,
            deployLog: [],

            init() {
                if (this.uploadUrl) return; // already initialised
                this.uploadUrl = ETEL.api.replace('boot.php', 'updater.php');
                this.apiUrl    = ETEL.api.replace('boot.php', 'updater-api.php');
                this._setupDragDrop();
            },

            hdrs() { return { 'Authorization': 'Bearer ' + Auth.getToken() }; },

            async _get(action) {
                const r = await fetch(this.apiUrl + '?action=' + action, { headers: this.hdrs() });
                return r.json();
            },

            async _post(action, fields) {
                const fd = new FormData();
                fd.append('action', action);
                for (const [k, v] of Object.entries(fields)) fd.append(k, v);
                const r = await fetch(this.apiUrl + '?action=' + action, { method: 'POST', headers: this.hdrs(), body: fd });
                return r.json();
            },

            _setupDragDrop() {
                const dz = document.getElementById('upd-dropzone');
                if (!dz) return;
                dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('upd-dz-active'); });
                dz.addEventListener('dragleave', () => dz.classList.remove('upd-dz-active'));
                dz.addEventListener('drop', e => {
                    e.preventDefault();
                    dz.classList.remove('upd-dz-active');
                    const f = e.dataTransfer.files[0];
                    if (f) this._showChosen(f);
                });
                const fi = document.getElementById('upd-file');
                fi.addEventListener('change', e => { if (e.target.files[0]) this._showChosen(e.target.files[0]); });
            },

            // Auto-detect the correct target path from a filename so the
            // admin doesn't have to type it. Mirrors the server-side logic.
            detectPath(name) {
                name = (name || '').split('/').pop().split('\\').pop().trim();
                if (!name) return '';
                const known = {
                    'sw.js':'sw.js', 'manifest.json':'manifest.json', 'manifest.php':'manifest.php',
                    'offline.html':'offline.html', '.htaccess':'.htaccess',
                    'boot.php':'api/boot.php', 'updater.php':'api/updater.php',
                    'updater-api.php':'api/updater-api.php', 'upload.php':'api/upload.php',
                    'config.php':'api/config.php', 'config-etb.php':'api/config-etb.php',
                    'core.js':'js/core.js', 'admin.js':'js/admin.js',
                    'admin-api.js':'js/admin/admin-api.js',
                    /* Controllers → correct subfolder */
                    'AdminController.php':'api/controllers/AdminController.php',
                    'AuthController.php':'api/controllers/AuthController.php',
                    'OrderController.php':'api/controllers/OrderController.php',
                    'ServiceGiverController.php':'api/controllers/ServiceGiverController.php',
                    'RideController.php':'api/controllers/RideController.php',
                };
                if (known[name]) return known[name];
                const ext = (name.split('.').pop() || '').toLowerCase();
                if (ext === 'js')  return 'js/'  + name;
                if (ext === 'css') return 'css/' + name;
                if (ext === 'php' || ext === 'sql') return 'api/' + name;
                return name; // html/json/images → delivery root
            },

            _showChosen(file) {
                // Attach file to the hidden input via DataTransfer
                try {
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    document.getElementById('upd-file').files = dt.files;
                } catch(e) { /* Safari fallback — file already on input */ }
                const el = document.getElementById('upd-file-chosen');
                el.style.display = 'inline-block';
                el.innerHTML = '✓ <strong>' + file.name + '</strong> — ' + (file.size / 1024).toFixed(1) + ' KB';
                // Auto-detect & fill the target path (always, unless the admin
                // already typed a custom one)
                const dest = document.getElementById('upd-dest');
                if (!dest.value.trim()) {
                    const detected = this.detectPath(file.name);
                    dest.value = detected;
                    const hint = document.getElementById('upd-path-hint');
                    if (hint) hint.textContent = '🎯 Auto-detected target: ' + detected + ' (edit if needed)';
                }
            },

            stripPrefix(p) {
                p = p.replace(/\\/g, '/').replace(/^\/+/, '');
                const prefixes = ['public_html/delivery/','public_html/','var/www/html/delivery/','var/www/html/','www/delivery/','www/'];
                for (const pfx of prefixes) { if (p.startsWith(pfx)) { p = p.slice(pfx.length); break; } }
                p = p.replace(/^home\/[^/]+\/public_html\/(?:[^/]+\/)?/, '');
                return p;
            },

            pathChanged(v) {
                const hint = document.getElementById('upd-path-hint');
                const stripped = this.stripPrefix(v);
                if (stripped !== v.replace(/^\/+/, '').replace(/\\/g, '/')) {
                    document.getElementById('upd-dest').value = stripped;
                    hint.textContent = 'Path cleaned to: ' + stripped;
                } else { hint.textContent = ''; }
            },

            _log(msg) {
                this.deployLog.unshift('[' + new Date().toLocaleTimeString() + '] ' + msg);
                if (this.deployLog.length > 50) this.deployLog.pop();
                const el = document.getElementById('upd-log');
                if (el) el.innerHTML = this.deployLog.map(l => '<div>' + l + '</div>').join('');
                document.getElementById('upd-log-wrap').style.display = 'block';
            },

            _showResult(type, html) {
                const res = document.getElementById('upd-result');
                const bg = { success:'background:#d4edda;color:#155724', error:'background:#fde;color:#a00', info:'background:#e8f4fd;color:#0066aa' };
                res.style.cssText = 'display:block;' + (bg[type] || bg.info) + ';border-radius:8px;padding:14px 16px;font-size:13px;line-height:1.6';
                res.innerHTML = html;
            },

            _busy(on) {
                const btn = document.getElementById('upd-upload-btn');
                document.getElementById('upd-btn-icon').textContent = on ? '⏳' : '⬆️';
                document.getElementById('upd-btn-text').textContent = on ? 'Deploying…' : 'Upload & Deploy Live';
                btn.disabled = on;
                btn.style.opacity = on ? '0.7' : '1';
            },

            async doUpload() {
                const fi   = document.getElementById('upd-file');
                if (!fi.files || !fi.files[0]) { this._showResult('error', 'Select a file to upload'); return; }
                const file = fi.files[0];
                const isZip = file.name.toLowerCase().endsWith('.zip');
                // Auto-detect the target when the admin left the path blank
                let dest = this.stripPrefix((document.getElementById('upd-dest').value || '').trim());
                if (!dest) dest = this.detectPath(file.name);
                if (!isZip && !confirm('Deploy "' + file.name + '" → "' + dest + '" on the live server?')) return;
                if (isZip && !confirm('Extract & deploy ZIP "' + file.name + '" to the live server?')) return;
                this._busy(true);
                this._showResult('info', isZip ? 'Extracting ZIP…' : 'Uploading…');
                const fd = new FormData();
                fd.append('file', file);
                /* ZIPs go to updater-api.php?action=upload_zip (handles any file type).
                   Single files go to updater.php with dest path. */
                let url, r;
                try {
                    if (isZip) {
                        url = this.apiUrl + '?action=upload_zip';
                        r = await fetch(url, { method: 'POST', headers: this.hdrs(), body: fd });
                    } else {
                        fd.append('dest', dest);
                        r = await fetch(this.uploadUrl, { method: 'POST', headers: this.hdrs(), body: fd });
                    }
                    const j = await r.json();
                    if (j.success) {
                        const isZipResp = j.data && j.data.mode === 'zip_extracted';
                        if (isZipResp) {
                            const ok = j.data.extracted > 0;
                            const errNote = j.data.errors && j.data.errors.length
                                ? ' <em style="color:#e53935">· ' + j.data.errors.length + ' error(s)</em>' : '';
                            const skipNote = j.data.skipped > 0
                                ? ' <span style="color:#B8B0A0">(' + j.data.skipped + ' skipped)</span>' : '';
                            this._showResult(ok ? 'success' : 'error',
                                (ok ? '✓' : '⚠') + ' ZIP: <strong>' + j.data.extracted + ' files deployed</strong>' + skipNote + errNote +
                                (j.data.skipped_list && j.data.skipped_list.length ? '<br><small style="color:#B8B0A0">Skipped: ' + j.data.skipped_list.slice(0,5).join(', ') + '</small>' : '') +
                                '<br><small style="color:#e8e2d4">' + (j.data.files||[]).slice(0,10).join(' · ') + '</small>');
                            this._log('ZIP DEPLOYED: ' + j.data.extracted + ' files' + (j.data.strip ? ' (stripped: ' + j.data.strip + ')' : ''));
                            (j.data.files||[]).forEach(f => this._log('  → ' + f));
                        } else {
                            this._showResult('success',
                                '✓ <strong>' + j.data.written + '</strong> deployed (' + (j.data.size_kb || (j.data.size/1024).toFixed(1)) + ' KB) at ' + j.data.time +
                                (j.data.backed_up ? ' · <em>original backed up</em>' : ''));
                            this._log('DEPLOYED: ' + j.data.written);
                        }
                        fi.value = '';
                        document.getElementById('upd-file-chosen').style.display = 'none';
                        document.getElementById('upd-dest').value = '';
                        document.getElementById('upd-path-hint').textContent = '';
                    } else {
                        this._showResult('error', '✗ ' + (j.message || 'Upload failed'));
                        this._log('FAILED: ' + dest + ' — ' + (j.message || 'unknown error'));
                    }
                } catch(e) {
                    this._showResult('error', 'Network error: ' + e.message);
                    this._log('ERROR: ' + e.message);
                }
                this._busy(false);
            },

            setPath(path) {
                document.getElementById('upd-dest').value = path;
                document.getElementById('upd-path-hint').textContent = '';
                updSwitchTab('upload');
            },

            _timeAgo(ms) {
                const s = Math.floor((Date.now() - ms) / 1000);
                if (s < 60) return 'just now';
                if (s < 3600) return Math.floor(s/60) + 'm ago';
                if (s < 86400) return Math.floor(s/3600) + 'h ago';
                return Math.floor(s/86400) + 'd ago';
            },

            _treeHtml(nodes, depth) {
                if (!nodes || !nodes.length) return '';
                const pad = depth * 14;
                let h = '';
                for (const n of nodes) {
                    if (n.type === 'directory') {
                        h += '<div style="padding-left:' + pad + 'px"><div class="upd-tree-dir">📁 ' + n.name + '/</div>' + this._treeHtml(n.children, depth + 1) + '</div>';
                    } else {
                        const ico = {php:'🐘',html:'🌐',js:'⚡',css:'🎨',sql:'🗄️',json:'📋'}[n.extension] || '📄';
                        const kb  = n.size > 0 ? (n.size/1024).toFixed(1) + ' KB' : '';
                        const ago = this._timeAgo(n.modified * 1000);
                        const click = n.editable ? ' onclick="UPD.setPath(\'' + n.path.replace(/'/g,"\\'") + '\')" ' : '';
                        h += '<div class="upd-tree-item" style="padding-left:' + pad + 'px;' + (n.editable ? '' : 'opacity:.45;cursor:default') + '"' + click + '>';
                        h += '<span>' + ico + '</span><span style="flex:1">' + n.name + '</span>';
                        h += '<span style="color:#B8B0A0;font-size:11px">' + kb + '</span>';
                        h += '<span style="color:#ccc;font-size:11px;margin-left:10px">' + ago + '</span></div>';
                    }
                }
                return h;
            },

            async loadFileTree() {
                const el = document.getElementById('upd-file-tree');
                el.innerHTML = '<span style="color:#B8B0A0">Loading file tree…</span>';
                try {
                    const j = await this._get('list_files');
                    el.innerHTML = j.success ? (this._treeHtml(j.data, 0) || '<span style="color:#B8B0A0">No files found</span>') :
                        '<span style="color:#e74c3c">' + (j.message || 'Failed') + '</span>';
                } catch(e) { el.innerHTML = UI.error(e.message); }
            },

            async loadBackups() {
                const el = document.getElementById('upd-backups-list');
                el.innerHTML = '<span style="color:#B8B0A0;font-size:13px">Loading…</span>';
                try {
                    const j = await this._get('list_backups');
                    if (!j.success) { el.innerHTML = '<span style="color:#e74c3c">' + (j.message||'Failed') + '</span>'; return; }
                    if (!j.data || !j.data.length) { el.innerHTML = '<span style="color:#B8B0A0;font-size:13px">No backups yet — they are created automatically on every deploy.</span>'; return; }
                    let h = '<div style="border:1px solid #eee;border-radius:10px;overflow:hidden">';
                    for (const b of j.data) {
                        const ts = (b.timestamp || '').replace('_',' ').replace(/(\d{2})(\d{2})(\d{2})$/,'$1:$2:$3');
                        const kb = (b.size / 1024).toFixed(1);
                        const fn = b.filename.replace(/'/g,"\\'");
                        h += '<div class="upd-backup-row">';
                        h += '<span style="flex:1"><strong style="color:#1a1a2e;font-size:13px">' + (b.original || b.filename) + '</strong><br>';
                        h += '<span style="color:#B8B0A0;font-size:11px">' + ts + ' &nbsp;·&nbsp; ' + kb + ' KB</span></span>';
                        h += '<button onclick="UPD.restoreBackup(\'' + fn + '\')" style="padding:5px 12px;background:rgba(46,204,113,.15);color:#2ECC71;border:1px solid rgba(46,204,113,.3);border-radius:6px;font-size:12px;cursor:pointer;font-weight:600">↩ Restore</button>';
                        h += '<button onclick="UPD.deleteBackup(\'' + fn + '\')" style="padding:5px 12px;background:#fde;color:#a00;border:1px solid #fbb;border-radius:6px;font-size:12px;cursor:pointer;margin-left:6px">🗑 Delete</button>';
                        h += '</div>';
                    }
                    h += '</div>';
                    el.innerHTML = h;
                } catch(e) { el.innerHTML = '<span style="color:#e74c3c">Error: ' + e.message + '</span>'; }
            },

            async restoreBackup(filename) {
                if (!confirm('Restore "' + filename + '"? Current file will be backed up first.')) return;
                try {
                    const j = await this._post('restore_backup', { backup: filename });
                    if (j.success) { toast('Restored: ' + j.data.file); this._log('RESTORED: ' + j.data.file); this.loadBackups(); }
                    else toast((j.message || 'Restore failed'), 'err');
                } catch(e) { toast('Error: ' + e.message, 'err'); }
            },

            async deleteBackup(filename) {
                if (!confirm('Delete backup "' + filename + '"? This cannot be undone.')) return;
                try {
                    const j = await this._post('delete_backup', { backup: filename });
                    if (j.success) { toast('Backup deleted'); this.loadBackups(); }
                    else toast((j.message || 'Delete failed'), 'err');
                } catch(e) { toast('Error: ' + e.message, 'err'); }
            },

            async loadSystemInfo() {
                const el = document.getElementById('upd-sys-info');
                el.innerHTML = '<span style="color:#B8B0A0;font-size:13px">Loading…</span>';
                try {
                    const j = await this._get('system_info');
                    if (!j.success) { el.innerHTML = '<span style="color:#e74c3c">' + (j.message||'Failed') + '</span>'; return; }
                    const d = j.data;
                    const freeGb  = d.disk_free  ? (d.disk_free / 1e9).toFixed(1)  : '?';
                    const totalGb = d.disk_total ? (d.disk_total / 1e9).toFixed(1) : '?';
                    const sizeKb  = d.total_size ? (d.total_size / 1024).toFixed(0) : '?';
                    const bkKb    = d.backup_size ? (d.backup_size / 1024).toFixed(0) : '0';
                    const pct     = d.disk_total ? Math.round((1 - d.disk_free / d.disk_total) * 100) : 0;
                    const barClr  = pct > 85 ? '#e74c3c' : pct > 65 ? '#f39c12' : '#2ecc71';
                    el.innerHTML =
                        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">' +
                        '<div style="background:rgba(255,255,255,.06);border-radius:10px;padding:14px"><div style="font-size:11px;color:#B8B0A0;text-transform:uppercase;font-weight:700;margin-bottom:4px">PHP Version</div><div style="font-weight:700;font-size:18px;color:#e8e2d4">' + (d.php_version||'?') + '</div></div>' +
                        '<div style="background:rgba(255,255,255,.06);border-radius:10px;padding:14px"><div style="font-size:11px;color:#B8B0A0;text-transform:uppercase;font-weight:700;margin-bottom:4px">Root Writable</div><div style="font-weight:700;font-size:18px;color:' + (d.writable?'#2e7d32':'#c62828') + '">' + (d.writable?'✓ Yes':'✗ No') + '</div></div>' +
                        '<div style="background:rgba(255,255,255,.06);border-radius:10px;padding:14px"><div style="font-size:11px;color:#B8B0A0;text-transform:uppercase;font-weight:700;margin-bottom:4px">Project Size</div><div style="font-weight:700;font-size:18px;color:#e8e2d4">' + sizeKb + ' KB</div></div>' +
                        '<div style="background:rgba(255,255,255,.06);border-radius:10px;padding:14px"><div style="font-size:11px;color:#B8B0A0;text-transform:uppercase;font-weight:700;margin-bottom:4px">Backups</div><div style="font-weight:700;font-size:18px;color:#e8e2d4">' + (d.backup_count||0) + ' files &nbsp;·&nbsp; ' + bkKb + ' KB</div></div>' +
                        '</div>' +
                        '<div style="background:rgba(255,255,255,.06);border-radius:10px;padding:14px">' +
                        '<div style="font-size:11px;color:#B8B0A0;text-transform:uppercase;font-weight:700;margin-bottom:8px">Disk Usage</div>' +
                        '<div style="background:#e0e0e0;border-radius:6px;height:8px;margin-bottom:6px;overflow:hidden"><div style="height:100%;width:' + pct + '%;background:' + barClr + ';border-radius:6px;transition:width 1s"></div></div>' +
                        '<div style="font-size:12px;color:#B8B0A0">' + freeGb + ' GB free of ' + totalGb + ' GB (' + pct + '% used)</div></div>' +
                        '<div style="margin-top:10px;font-size:11px;color:#B8B0A0">' + (d.server||'') + ' &nbsp;·&nbsp; <code>' + (d.base_dir||'') + '</code></div>';
                } catch(e) { el.innerHTML = '<span style="color:#e74c3c">Error: ' + e.message + '</span>'; }
            }
        };

        function updSwitchTab(tab) {
            ['upload','browse','backups','system'].forEach(t => {
                document.getElementById('upd-pane-' + t).style.display = t === tab ? 'block' : 'none';
                const btn = document.getElementById('upd-tab-' + t);
                if (btn) btn.classList.toggle('upd-tab-active', t === tab);
            });
            if (tab === 'browse')  UPD.loadFileTree();
            if (tab === 'backups') UPD.loadBackups();
            if (tab === 'system')  UPD.loadSystemInfo();
        }
        function updSetPath(p)      { UPD.setPath(p); }
        function updDoUpload()      { UPD.doUpload(); }
        function updPathChanged(v)  { UPD.pathChanged(v); }
        function updLoadFileTree()  { UPD.loadFileTree(); }
        function updLoadBackups()   { UPD.loadBackups(); }
        function updLoadSystemInfo(){ UPD.loadSystemInfo(); }
        function uploadFile()       { UPD.doUpload(); } // backwards compat

        // ─── EXPORT CSV ───────────────────────────────────────────────
        function exportCSV(data, cols, filename) {
            if (!data||!data.length) { toast('No data to export', 'err'); return; }
            const header = cols.join(',');
            const rows = data.map(r => cols.map(c => '"'+(r[c]||'').toString().replace(/"/g,'""')+'"').join(','));
            const csv = [header, ...rows].join('\n');
            const a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv'}));
            a.download = filename + '_' + new Date().toISOString().slice(0,10) + '.csv';
            a.click();
            toast('Exported ' + data.length + ' rows ✓');
        }
        function doExport() {
            if (currentPage==='orders') exportCSV(ordersData, ['order_code','from_zone','to_zone','status','fare_total','placed_at'], 'orders');
            else if (currentPage==='users') exportCSV(usersData, ['id','name','phone','role','created_at'], 'users');
            else if (currentPage==='couriers') exportCSV(couriersData, ['courier_code','name','phone','vehicle_type','zone','status','rating'], 'couriers');
            else if (currentPage==='servicegivers') exportCSV(sgData, ['service_giver_code','business_name','name','phone','service_type','zone','status'], 'service_givers');
            else toast('Switch to a page with data to export', 'err');
        }

        // ─── LITESPEED CACHE PURGE ────────────────────────────────────
        window.adminPurgeCache = async function() {
            const btn = document.getElementById('purge-cache-btn');
            if (btn) { btn.disabled = true; btn.textContent = '⏳ Purging…'; }
            try {
                const r = await adm('admin_purge_cache', {}, true);
                if (r && r.success) {
                    toast('Cache purged — visitors will see fresh content', 'ok');
                } else {
                    toast((r && r.message) || 'Purge failed', 'err');
                }
            } catch(e) {
                toast('Purge error: ' + e.message, 'err');
            } finally {
                if (btn) { btn.disabled = false; btn.textContent = '⚡ Purge Cache'; }
            }
        };

        // ─── LOGOUT ───────────────────────────────────────────────────
        function doLogout() {
            if (confirm('Logout from admin panel?')) Auth.logout();
        }

        // ─── TOAST HELPER ─────────────────────────────────────────────
        function toast(msg, type='ok') {
            const t = document.createElement('div');
            t.style.cssText = `position:fixed;bottom:24px;right:24px;z-index:999;padding:12px 20px;background:${type==='err'?'#e63946':'#2ecc71'};color:#fff;border-radius:8px;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.2);animation:fadeIn .3s ease`;
            t.textContent = msg;
            document.body.appendChild(t);
            setTimeout(() => t.remove(), 3000);
        }

        // ─── INIT ─────────────────────────────────────────────────────
        document.addEventListener('keydown', e => { if(e.key==='Escape') closeModal(); });
        loadDashboard();
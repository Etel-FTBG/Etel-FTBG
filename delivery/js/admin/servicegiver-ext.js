// ═════════════════════════════════════════════════════════════════
    // ADMIN SERVICE GIVER MANAGEMENT JAVASCRIPT
    // ═════════════════════════════════════════════════════════════════

    let adminZones = [];
    let currentSGId = null;

    // ─── INITIALIZATION ───────────────────────────────────────────
    function initServiceGiverAdmin() {
        // Load zones for dropdowns
        loadZonesForAdmin();
        // Load initial service giver list
        loadServiceGiverList();
        // Load pending orders for assignment
        loadPendingOrders();
        // Load verified service givers for assignment
        loadVerifiedServiceGivers();
    }

    async function loadZonesForAdmin() {
        try {
            const res = await fetch('api/boot.php?action=get_zones', {
                headers: { 'Authorization': 'Bearer ' + Auth.getToken() }
            });
            const data = await res.json();
            adminZones = data.data || [];

            // Populate zone selects
            const selects = document.querySelectorAll('#sgZoneSelect');
            selects.forEach(select => {
                select.innerHTML = '<option value="">-- Select Zone --</option>';
                adminZones.forEach(zone => {
                    const opt = document.createElement('option');
                    opt.value = zone.name;
                    opt.textContent = zone.name;
                    select.appendChild(opt);
                });
            });
        } catch (err) {
            console.error('Load zones error:', err);
        }
    }

    // ─── TAB SWITCHING ────────────────────────────────────────────
    function switchAdminTab(tabName) {
        // Hide all tabs
        document.querySelectorAll('.admin-tab').forEach(t => t.style.display = 'none');
        // Hide active buttons
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        // Show target tab
        document.getElementById('tab-' + tabName).style.display = 'block';
        // Mark button active
        event.target.classList.add('active');

        // Load data for tab
        if (tabName === 'sg-list') loadServiceGiverList();
        if (tabName === 'sg-assign') {
            loadPendingOrders();
            loadVerifiedServiceGivers();
        }
    }

    // ─── LIST SERVICE GIVERS ────────────────────────────────────
    async function loadServiceGiverList() {
        try {
            const res = await fetch('api/boot.php?action=get_service_givers&limit=100', {
                headers: { 'Authorization': 'Bearer ' + Auth.getToken() }
            });
            const data = await res.json();

            if (!data.success) {
                showAlert('sgTableBody', data.error || 'Failed to load', 'error');
                return;
            }

            const sgs = (data.data && data.data.service_givers) ? data.data.service_givers : (Array.isArray(data.data) ? data.data : []);
            const tbody = document.getElementById('sgTableBody');
            if (!tbody) return;

            // Update stats
            const elTotal = document.getElementById('sgTotalCount');
            const elVerified = document.getElementById('sgVerifiedCount');
            const elOnline = document.getElementById('sgOnlineCount');
            if (elTotal) elTotal.textContent = sgs.length;
            if (elVerified) elVerified.textContent = sgs.filter(s => s.status === 'verified').length;
            if (elOnline) elOnline.textContent = sgs.filter(s => s.is_online === 1).length;

            if (!sgs.length) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; color: #999;">No service givers registered</td></tr>';
                return;
            }

            sgs.forEach(sg => {
                const row = document.createElement('tr');
                const displayName = sg.name || sg.business_name || (sg.first_name ? sg.first_name + ' ' + sg.last_name : '-');
                const safeStatus = escapeHtml(sg.status || '').replace(/[^a-zA-Z0-9_-]/g, '');
                row.innerHTML = `
                    <td>${escapeHtml(sg.service_giver_code || sg.service_giver_id || '-')}</td>
                    <td>${escapeHtml(displayName)}</td>
                    <td>${escapeHtml(sg.phone)}</td>
                    <td>${escapeHtml(sg.service_type || '-')}</td>
                    <td>${escapeHtml(sg.vehicle_type || '-')}</td>
                    <td><span class="status-badge status-${safeStatus}">${escapeHtml(sg.status)}</span></td>
                    <td>${(parseFloat(sg.average_rating) || 0).toFixed(2)} ⭐</td>
                    <td>${escapeHtml(sg.completed_deliveries || 0)}</td>
                    <td>
                        <div class="table-actions">
                            <button class="btn-secondary" onclick="viewSGDetail(${parseInt(sg.id, 10) || 0})">
                                View
                            </button>
                        </div>
                    </td>
                `;
                tbody.appendChild(row);
            });
        } catch (err) {
            console.error('List SG error:', err);
            showAlert('sgTableBody', 'Network error', 'error');
        }
    }

    // ─── VIEW DETAILS MODAL ──────────────────────────────────────
    async function viewSGDetail(sgId) {
        try {
            const res = await fetch(`api/boot.php?action=admin_get_service_giver&id=${sgId}`, {
                headers: { 'Authorization': 'Bearer ' + Auth.getToken() }
            });
            const data = await res.json();

            if (!data.success) {
                alert('Failed to load details');
                return;
            }

            const sg = data.data;
            currentSGId = sgId;

            const content = document.getElementById('sgDetailContent');
            const detailName = sg.name || sg.business_name || (sg.first_name ? sg.first_name + ' ' + sg.last_name : '-');
            const detailSafeStatus = escapeHtml(sg.status || '').replace(/[^a-zA-Z0-9_-]/g, '');
            content.innerHTML = `
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <div>
                        <strong>ID:</strong> ${parseInt(sg.id, 10) || 0}
                    </div>
                    <div>
                        <strong>Code:</strong> ${escapeHtml(sg.service_giver_id)}
                    </div>
                    <div>
                        <strong>Name:</strong> ${escapeHtml(detailName)}
                    </div>
                    <div>
                        <strong>Phone:</strong> ${escapeHtml(sg.phone)}
                    </div>
                    <div>
                        <strong>Email:</strong> ${escapeHtml(sg.email)}
                    </div>
                    <div>
                        <strong>Service Type:</strong> ${escapeHtml(sg.service_type)}
                    </div>
                    <div>
                        <strong>Vehicle:</strong> ${escapeHtml(sg.vehicle_type)} ${sg.vehicle_plate ? '- ' + escapeHtml(sg.vehicle_plate) : ''}
                    </div>
                    <div>
                        <strong>Status:</strong> <span class="status-badge status-${detailSafeStatus}">${escapeHtml(sg.status)}</span>
                    </div>
                    <div>
                        <strong>Rating:</strong> ${(parseFloat(sg.average_rating) || 0).toFixed(2)} ⭐
                    </div>
                    <div>
                        <strong>Deliveries:</strong> ${escapeHtml(sg.completed_deliveries)}
                    </div>
                    <div>
                        <strong>Earnings:</strong> ETB ${(parseFloat(sg.total_earnings) || 0).toFixed(2)}
                    </div>
                    <div>
                        <strong>Registered:</strong> ${escapeHtml(sg.created_at)}
                    </div>
                </div>
            `;

            // Show/hide buttons based on status
            const verifyBtn = document.getElementById('sgVerifyBtn');
            const suspendBtn = document.getElementById('sgSuspendBtn');

            if (sg.status === 'pending') {
                verifyBtn.style.display = 'block';
                suspendBtn.textContent = '❌ Reject';
            } else if (sg.status === 'verified') {
                verifyBtn.style.display = 'none';
                suspendBtn.textContent = '🔒 Suspend';
            } else if (sg.status === 'suspended') {
                verifyBtn.textContent = '✅ Unsuspend';
                verifyBtn.style.display = 'block';
                suspendBtn.style.display = 'none';
            }

            document.getElementById('sgDetailTitle').textContent = sg.name || sg.business_name || (sg.first_name ? sg.first_name + ' ' + sg.last_name : 'Service Giver');
            document.getElementById('sgDetailModal').classList.add('active');
        } catch (err) {
            console.error('View SG error:', err);
            alert('Error loading details');
        }
    }

    function closeSGModal() {
        document.getElementById('sgDetailModal').classList.remove('active');
        currentSGId = null;
    }

    // ─── VERIFY SERVICE GIVER ──────────────────────────────────
    async function verifySG() {
        if (!confirm('Verify this service giver account?')) return;

        try {
            const res = await fetch(`api/boot.php?action=admin_update_service_giver_status&id=${currentSGId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + Auth.getToken()
                },
                body: JSON.stringify({ status: 'verified' })
            });

            const data = await res.json();
            if (data.success) {
                alert('Service giver verified!');
                closeSGModal();
                loadServiceGiverList();
            } else {
                alert('Error: ' + data.error);
            }
        } catch (err) {
            console.error('Verify error:', err);
            alert('Network error');
        }
    }

    // ─── SUSPEND SERVICE GIVER ──────────────────────────────────
    async function suspendSG() {
        if (!confirm('Suspend this service giver?')) return;

        try {
            const res = await fetch(`api/boot.php?action=admin_update_service_giver_status&id=${currentSGId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + Auth.getToken()
                },
                body: JSON.stringify({ status: 'suspended' })
            });

            const data = await res.json();
            if (data.success) {
                alert('Service giver suspended!');
                closeSGModal();
                loadServiceGiverList();
            } else {
                alert('Error: ' + data.error);
            }
        } catch (err) {
            console.error('Suspend error:', err);
            alert('Network error');
        }
    }

    // ─── REGISTER NEW SERVICE GIVER ────────────────────────────
    async function handleRegisterServiceGiver(e) {
        e.preventDefault();
        const formData = new FormData(e.target);
        const phone = formData.get('phone').replace(/[^0-9+]/g, '');

        const alertDiv = document.getElementById('sgRegisterAlert');
        alertDiv.innerHTML = '';

        try {
            // Step 1: Create user
            const userRes = await fetch('api/boot.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + Auth.getToken()
                },
                body: JSON.stringify({
                    action: 'admin_create_user',
                    phone: phone,
                    password: formData.get('password'),
                    role: 'service_giver',
                    name: formData.get('business_name')
                })
            });

            const userData = await userRes.json();
            if (!userData.success) {
                showAlert('sgRegisterAlert', userData.message || 'User creation failed', 'error');
                return;
            }

            const userId = userData.data.user_id;

            // Step 2: Create service giver profile
            const sgRes = await fetch('api/boot.php?action=admin_create_service_giver', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + Auth.getToken()
                },
                body: JSON.stringify({
                    first_name: formData.get('business_name'),
                    last_name: '',
                    email: phone + '@etel.local',
                    phone: phone,
                    service_type: formData.get('service_type'),
                    vehicle_type: 'on_foot',
                    identification_number: formData.get('identification_number'),
                    address: formData.get('address'),
                    city: 'Addis Ababa',
                    status: 'pending'
                })
            });

            const sgData = await sgRes.json();
            if (!sgData.success) {
                showAlert('sgRegisterAlert', sgData.error || 'Profile creation failed', 'error');
                return;
            }

            showAlert('sgRegisterAlert', 'Service giver registered successfully! Status: Pending Verification', 'success');
            e.target.reset();
            loadServiceGiverList();

            // Auto-switch to list tab
            setTimeout(() => {
                document.getElementById('tab-sg-list').style.display = 'block';
                document.getElementById('tab-sg-register').style.display = 'none';
                document.querySelectorAll('.tab-btn')[0].classList.add('active');
                document.querySelectorAll('.tab-btn')[1].classList.remove('active');
            }, 2000);
        } catch (err) {
            console.error('Register error:', err);
            showAlert('sgRegisterAlert', 'Network error: ' + err.message, 'error');
        }
    }

    // ─── ASSIGN ORDERS ────────────────────────────────────────
    async function loadPendingOrders() {
        try {
            const res = await fetch('api/boot.php?action=get_orders&status=pending', {
                headers: { 'Authorization': 'Bearer ' + Auth.getToken() }
            });
            const data = await res.json();

            if (!data.success) return;

            const orders = data.data || [];
            const select = document.getElementById('pendingOrderSelect');
            if (!select) return;
            select.innerHTML = '<option value="">-- Select Order --</option>';

            orders.forEach(order => {
                const opt = document.createElement('option');
                opt.value = order.id;
                opt.dataset.order = JSON.stringify(order);
                opt.textContent = `${order.order_code} - ${order.from_zone} → ${order.to_zone}`;
                select.appendChild(opt);
            });

            // Add change listener for preview
            select.addEventListener('change', showOrderPreview);
        } catch (err) {
            console.error('Load pending orders error:', err);
        }
    }

    async function loadVerifiedServiceGivers() {
        try {
            const res = await fetch('api/boot.php?action=get_service_givers&status=verified', {
                headers: { 'Authorization': 'Bearer ' + Auth.getToken() }
            });
            const data = await res.json();

            if (!data.success) return;

            const sgs = (data.data && data.data.service_givers) ? data.data.service_givers : (Array.isArray(data.data) ? data.data : []);
            const select = document.getElementById('serviceGiverSelect');
            if (!select) return;
            select.innerHTML = '<option value="">-- Select Service Giver --</option>';

            sgs.forEach(sg => {
                const opt = document.createElement('option');
                opt.value = sg.id;
                opt.textContent = `${sg.name || sg.business_name || (sg.first_name ? sg.first_name+' '+sg.last_name : 'SG-'+sg.id)} (${sg.phone})`;
                select.appendChild(opt);
            });
        } catch (err) {
            console.error('Load SGs error:', err);
        }
    }

    function showOrderPreview() {
        const select = document.getElementById('pendingOrderSelect');
        const preview = document.getElementById('orderPreview');
        const content = document.getElementById('orderPreviewContent');

        if (!select.value) {
            preview.style.display = 'none';
            return;
        }

        const order = JSON.parse(select.options[select.selectedIndex].dataset.order);
        content.innerHTML = `
            <div>Code: ${escapeHtml(order.order_code)}</div>
            <div>From: ${escapeHtml(order.from_zone)} → To: ${escapeHtml(order.to_zone)}</div>
            <div>Type: ${escapeHtml(order.package_type)} | Fare: ETB ${parseFloat(order.fare_total) || 0}</div>
            <div>Recipient: ${escapeHtml(order.recipient_name)} · ${escapeHtml(order.recipient_phone)}</div>
        `;
        preview.style.display = 'block';
    }

    async function handleAssignOrder(e) {
        e.preventDefault();
        const orderId = document.getElementById('pendingOrderSelect').value;
        const sgId = document.getElementById('serviceGiverSelect').value;

        if (!orderId || !sgId) {
            showAlert('sgAssignAlert', 'Please select order and service giver', 'error');
            return;
        }

        try {
            const res = await fetch('api/boot.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + Auth.getToken()
                },
                body: JSON.stringify({
                    action: 'admin_assign_service_giver',
                    order_id: parseInt(orderId),
                    service_giver_id: parseInt(sgId)
                })
            });

            const data = await res.json();
            if (!data.success) {
                showAlert('sgAssignAlert', data.message || 'Assignment failed', 'error');
                return;
            }

            showAlert('sgAssignAlert', 'Order assigned successfully!', 'success');
            e.target.reset();
            document.getElementById('orderPreview').style.display = 'none';
            loadPendingOrders();
        } catch (err) {
            console.error('Assign error:', err);
            showAlert('sgAssignAlert', 'Network error', 'error');
        }
    }

    // ─── UTILITIES ────────────────────────────────────────────
    function showAlert(elementId, message, type) {
        const el = document.getElementById(elementId);
        if (!el) { console.warn('showAlert: target #' + elementId + ' not found (probably a different tab is active) - ' + message); return; }
        el.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
    }

    // Initialize on page load
    window.addEventListener('load', () => {
        if (document.getElementById('tab-sg-list')) {
            initServiceGiverAdmin();
        }
    });
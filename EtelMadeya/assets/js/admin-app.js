// ═══════════════════════════════════════════════════════════════════
// ETEL MADEYA - ADMIN PANEL JAVASCRIPT
// ═══════════════════════════════════════════════════════════════════

let refreshInterval = null;
let clockInterval = null;
let controllersCache = [];
let vehicleTypesCache = [];

// ─── INITIALIZATION ─────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    checkSession();
});

// ─── AUTHENTICATION ─────────────────────────────────────────────────
async function checkSession() {
    try {
        const response = await fetch(`${API}?action=check_session`);
        const data = await response.json();

        if (data.logged_in) {
            currentAdmin = data.admin;
            showMainPanel();
        } else {
            showLoginScreen();
        }
    } catch (error) {
        showLoginScreen();
    }
}

function showLoginScreen() {
    document.getElementById('loginScreen').style.display = 'flex';
    document.getElementById('mainPanel').style.display = 'none';
}

function showMainPanel() {
    document.getElementById('loginScreen').style.display = 'none';
    document.getElementById('mainPanel').style.display = 'block';

    document.getElementById('adminName').textContent = currentAdmin.full_name;

    startClock();
    loadDashboard();

    // Auto-refresh dashboard every 60 seconds
    refreshInterval = setInterval(() => {
        if (document.querySelector('#dashboardTab.active')) {
            loadDashboard();
        }
    }, 60000);
}

function startClock() {
    const dateEl = document.getElementById('amHdrDate');
    const timeEl = document.getElementById('amHdrTime');
    if (!dateEl || !timeEl) return;
    const tick = () => {
        const now = new Date();
        dateEl.textContent = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
        timeEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    };
    tick();
    if (clockInterval) clearInterval(clockInterval);
    clockInterval = setInterval(tick, 1000);
}

async function handleEtelIDLogin(event) {
    event.preventDefault();

    const identifier = document.getElementById('etelidIdentifier').value;
    const password    = document.getElementById('etelidPassword').value;
    const errorDiv    = document.getElementById('loginError');
    const submitBtn   = event.target.querySelector('button[type="submit"]');
    const originalLabel = submitBtn.textContent;

    const formData = new FormData();
    formData.append('action', 'admin_etelid_login');
    formData.append('identifier', identifier);
    formData.append('password', password);

    submitBtn.disabled = true;
    submitBtn.textContent = 'Signing in…';

    try {
        const response = await fetch(API, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            currentAdmin = data.admin;
            showMainPanel();
        } else {
            errorDiv.textContent = data.message || 'EtelID sign-in failed';
            errorDiv.style.display = 'block';
        }
    } catch (error) {
        errorDiv.textContent = 'Connection error. Please try again.';
        errorDiv.style.display = 'block';
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalLabel;
    }
}

async function handleLogout() {
    if (!confirm('Are you sure you want to logout?')) return;

    try {
        await fetch(`${API}?action=logout`, { method: 'POST' });
        if (refreshInterval) clearInterval(refreshInterval);
        if (clockInterval) clearInterval(clockInterval);
        location.reload();
    } catch (error) {
        location.reload();
    }
}

// ─── TAB NAVIGATION ─────────────────────────────────────────────────
function showTab(tabName) {
    // Update nav items
    document.querySelectorAll('.am-tab').forEach(item => {
        item.classList.remove('active');
        if (item.dataset.tab === tabName) {
            item.classList.add('active');
        }
    });

    // Update tab content
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    document.getElementById(tabName + 'Tab').classList.add('active');

    // Load tab data
    switch(tabName) {
        case 'dashboard':
            loadDashboard();
            break;
        case 'controllers':
            loadControllers();
            break;
        case 'vehicles':
            loadVehicleTypes();
            break;
        case 'queue':
            loadQueueMonitor();
            break;
        case 'reports':
            // Reports loaded on demand
            break;
        case 'settings':
            loadSettings();
            break;
    }
}

// ─── DASHBOARD ──────────────────────────────────────────────────────
async function loadDashboard() {
    try {
        const response = await fetch(`${API}?action=get_dashboard_stats`);
        const data = await response.json();

        if (data.success) {
            // Update stats (thousand separators - a queue can realistically
            // hit 4+ digits and "5624" is harder to scan at a glance than "5,624")
            document.getElementById('statControllers').textContent = Number(data.stats.controllers).toLocaleString();
            document.getElementById('statQueued').textContent = Number(data.stats.total_queued).toLocaleString();
            document.getElementById('statServedToday').textContent = Number(data.stats.served_today).toLocaleString();
            document.getElementById('statVehicleTypes').textContent = Number(data.stats.vehicle_types).toLocaleString();

            // Display recent activity
            const activityDiv = document.getElementById('recentActivity');
            if (data.activity.length === 0) {
                activityDiv.innerHTML = '<p class="empty-state">No recent activity</p>';
            } else {
                activityDiv.innerHTML = data.activity.map(a => `
                    <div class="activity-item">
                        <strong>${escHtml(a.full_name)}</strong> ${escHtml(a.action)}
                        <br><small>${escHtml(new Date(a.created_at).toLocaleString())}</small>
                    </div>
                `).join('');
            }

            // Display controller status
            const statusDiv = document.getElementById('controllerStatus');
            if (data.controllers.length === 0) {
                statusDiv.innerHTML = '<p class="empty-state">No controllers</p>';
            } else {
                statusDiv.innerHTML = data.controllers.map(c => `
                    <div class="controller-item">
                        <strong>${escHtml(c.station_name)}</strong>
                        <br>${escHtml(c.full_name)}
                        <br><small>Queued: ${Number(c.queued_count).toLocaleString()}</small>
                    </div>
                `).join('');
            }
        }
    } catch (error) {
        console.error('Error loading dashboard:', error);
    }
}

// ─── CONTROLLERS ────────────────────────────────────────────────────
async function loadControllers() {
    try {
        const response = await fetch(`${API}?action=get_controllers`);
        const data = await response.json();

        if (data.success) {
            displayControllers(data.controllers);
        }
    } catch (error) {
        console.error('Error loading controllers:', error);
    }
}

function displayControllers(controllers) {
    controllersCache = controllers;
    const tbody = document.getElementById('controllersTableBody');

    if (controllers.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="empty-state">No controllers found</td></tr>';
        return;
    }

    tbody.innerHTML = controllers.map(c => `
        <tr>
            <td>${c.id}</td>
            <td><strong>${escHtml(c.username)}</strong></td>
            <td>${escHtml(c.full_name)}</td>
            <td>${escHtml(c.station_name)}</td>
            <td>${escHtml(c.phone || '-')}</td>
            <td><span class="status-badge status-${c.is_active ? 'active' : 'inactive'}">${c.is_active ? 'Active' : 'Inactive'}</span></td>
            <td>${c.last_login ? escHtml(new Date(c.last_login).toLocaleString()) : 'Never'}</td>
            <td>
                <button onclick="editController(${c.id})" class="action-btn action-btn-serve">${ICONS.edit} Edit</button>
                <button onclick="deleteController(${c.id}, this)" class="action-btn action-btn-wait">${ICONS.delete} Delete</button>
            </td>
        </tr>
    `).join('');
}

function showAddController() {
    const modal = `
        <div class="modal" style="display:block">
            <div class="modal-content am-modal">
                <div class="modal-header">
                    <h2>Add Controller</h2>
                    <button onclick="closeModal()" class="close-btn">${ICONS.close}</button>
                </div>
                <form onsubmit="handleAddController(event)">
                    <p class="am-note">
                        Sign-in uses EtelID, not a local password. This controller can access the
                        Controller Panel once their EtelID phone number matches the Phone field below.
                    </p>
                    <div class="form-group">
                        <label for="ctrlUsername">Username *</label>
                        <input type="text" id="ctrlUsername" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="ctrlFullName">Full Name *</label>
                        <input type="text" id="ctrlFullName" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="ctrlPhone">Phone (must match their EtelID) *</label>
                        <input type="tel" id="ctrlPhone" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="ctrlStation">Station Name</label>
                        <input type="text" id="ctrlStation" class="form-control" value="Etel Madeya Gas Station">
                    </div>
                    <div class="modal-actions">
                        <button type="button" onclick="closeModal()" class="btn-secondary">Cancel</button>
                        <button type="submit" class="btn-primary">Add Controller</button>
                    </div>
                </form>
            </div>
        </div>
    `;

    document.getElementById('modalContainer').innerHTML = modal;
    document.getElementById('modalOverlay').style.display = 'block';
}

async function handleAddController(event) {
    event.preventDefault();

    const submitBtn = event.target.querySelector('button[type="submit"]');
    const originalLabel = submitBtn.textContent;

    const formData = new FormData();
    formData.append('action', 'create_controller');
    formData.append('username', document.getElementById('ctrlUsername').value);
    formData.append('full_name', document.getElementById('ctrlFullName').value);
    formData.append('phone', document.getElementById('ctrlPhone').value);
    formData.append('station_name', document.getElementById('ctrlStation').value);

    submitBtn.disabled = true;
    submitBtn.textContent = 'Adding…';

    try {
        const response = await fetch(API, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            showNotification(ICONS.success + ' Controller added successfully', 'success');
            closeModal();
            loadControllers();
        } else {
            showNotification(ICONS.error + ' ' + escHtml(data.message || 'Failed to add controller'), 'error');
        }
    } catch (error) {
        showNotification(ICONS.error + ' Error adding controller', 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalLabel;
    }
}

function editController(id) {
    const c = controllersCache.find(x => x.id == id);
    if (!c) { showNotification(ICONS.error + ' Controller not found - refresh and try again', 'error'); return; }

    const modal = `
        <div class="modal" style="display:block">
            <div class="modal-content am-modal">
                <div class="modal-header">
                    <h2>Edit Controller</h2>
                    <button onclick="closeModal()" class="close-btn">${ICONS.close}</button>
                </div>
                <form onsubmit="handleEditController(event, ${c.id})">
                    <p class="am-note">
                        Sign-in uses EtelID, not a local password. Access depends on the Phone field below
                        matching their EtelID phone number.
                    </p>
                    <div class="form-group">
                        <label for="editCtrlUsername">Username</label>
                        <input type="text" id="editCtrlUsername" value="${escHtml(c.username)}" class="form-control" disabled>
                    </div>
                    <div class="form-group">
                        <label for="editCtrlFullName">Full Name *</label>
                        <input type="text" id="editCtrlFullName" class="form-control" value="${escHtml(c.full_name)}" required>
                    </div>
                    <div class="form-group">
                        <label for="editCtrlPhone">Phone (must match their EtelID) *</label>
                        <input type="tel" id="editCtrlPhone" class="form-control" value="${escHtml(c.phone || '')}" required>
                    </div>
                    <div class="form-group">
                        <label for="editCtrlStation">Station Name</label>
                        <input type="text" id="editCtrlStation" class="form-control" value="${escHtml(c.station_name || '')}">
                    </div>
                    <div class="form-group">
                        <label class="am-check-label" for="editCtrlActive">
                            <input type="checkbox" id="editCtrlActive" ${c.is_active ? 'checked' : ''}>
                            Active
                        </label>
                    </div>
                    <div class="modal-actions">
                        <button type="button" onclick="closeModal()" class="btn-secondary">Cancel</button>
                        <button type="submit" class="btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    `;

    document.getElementById('modalContainer').innerHTML = modal;
    document.getElementById('modalOverlay').style.display = 'block';
}

async function handleEditController(event, id) {
    event.preventDefault();

    const submitBtn = event.target.querySelector('button[type="submit"]');
    const originalLabel = submitBtn.textContent;

    const formData = new FormData();
    formData.append('action', 'update_controller');
    formData.append('id', id);
    formData.append('full_name', document.getElementById('editCtrlFullName').value);
    formData.append('phone', document.getElementById('editCtrlPhone').value);
    formData.append('station_name', document.getElementById('editCtrlStation').value);
    formData.append('is_active', document.getElementById('editCtrlActive').checked ? 1 : 0);

    submitBtn.disabled = true;
    submitBtn.textContent = 'Saving…';

    try {
        const response = await fetch(API, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            showNotification(ICONS.success + ' Controller updated', 'success');
            closeModal();
            loadControllers();
        } else {
            showNotification(ICONS.error + ' ' + escHtml(data.message || 'Failed to update controller'), 'error');
        }
    } catch (error) {
        showNotification(ICONS.error + ' Error updating controller', 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalLabel;
    }
}

async function deleteController(id, btn) {
    if (!confirm('Are you sure you want to delete this controller? Their queue will not be reassigned automatically.')) return;

    const originalLabel = btn ? btn.textContent : null;
    if (btn) { btn.disabled = true; btn.textContent = ' Deleting…'; }

    const formData = new FormData();
    formData.append('action', 'delete_controller');
    formData.append('id', id);

    try {
        const response = await fetch(API, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            showNotification(ICONS.success + ' Controller deleted', 'success');
            loadControllers();
        } else {
            showNotification(ICONS.error + ' ' + escHtml(data.message || 'Failed to delete'), 'error');
        }
    } catch (error) {
        showNotification(ICONS.error + ' Error deleting controller', 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = originalLabel; }
    }
}

// ─── VEHICLE TYPES ──────────────────────────────────────────────────
async function loadVehicleTypes() {
    try {
        const response = await fetch(`${API}?action=get_vehicle_types`);
        const data = await response.json();

        if (data.success) {
            displayVehicleTypes(data.types);
        }
    } catch (error) {
        console.error('Error loading vehicle types:', error);
    }
}

function displayVehicleTypes(types) {
    vehicleTypesCache = types;
    const tbody = document.getElementById('vehiclesTableBody');

    if (types.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="empty-state">No vehicle types found</td></tr>';
        return;
    }

    tbody.innerHTML = types.map(t => `
        <tr>
            <td>${t.id}</td>
            <td style="font-size:22px">${escHtml(t.icon)}</td>
            <td><strong>${escHtml(t.name)}</strong></td>
            <td>${escHtml(t.name_am)}</td>
            <td>${t.sort_order}</td>
            <td><span class="status-badge status-${t.is_active ? 'active' : 'inactive'}">${t.is_active ? 'Active' : 'Inactive'}</span></td>
            <td>
                <button onclick="editVehicleType(${t.id})" class="action-btn action-btn-serve">${ICONS.edit} Edit</button>
                <button onclick="deleteVehicleType(${t.id}, this)" class="action-btn action-btn-wait">${ICONS.delete} Delete</button>
            </td>
        </tr>
    `).join('');
}

function showAddVehicleType() {
    const modal = `
        <div class="modal" style="display:block">
            <div class="modal-content am-modal">
                <div class="modal-header">
                    <h2>Add Vehicle Type</h2>
                    <button onclick="closeModal()" class="close-btn">${ICONS.close}</button>
                </div>
                <form onsubmit="handleAddVehicleType(event)">
                    <div class="form-group">
                        <label for="addVtIcon">Icon (emoji) *</label>
                        <input type="text" id="addVtIcon" class="form-control" placeholder="e.g. 🏍️" required>
                    </div>
                    <div class="form-group">
                        <label for="addVtName">Name *</label>
                        <input type="text" id="addVtName" class="form-control" placeholder="e.g. Motorcycle" required>
                    </div>
                    <div class="form-group">
                        <label for="addVtNameAm">Name (Amharic)</label>
                        <input type="text" id="addVtNameAm" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="addVtSortOrder">Sort Order</label>
                        <input type="number" id="addVtSortOrder" class="form-control" value="0">
                    </div>
                    <div class="modal-actions">
                        <button type="button" onclick="closeModal()" class="btn-secondary">Cancel</button>
                        <button type="submit" class="btn-primary">Add Vehicle Type</button>
                    </div>
                </form>
            </div>
        </div>
    `;

    document.getElementById('modalContainer').innerHTML = modal;
    document.getElementById('modalOverlay').style.display = 'block';
}

async function handleAddVehicleType(event) {
    event.preventDefault();

    const submitBtn = event.target.querySelector('button[type="submit"]');
    const originalLabel = submitBtn.textContent;

    const formData = new FormData();
    formData.append('action', 'create_vehicle_type');
    formData.append('icon', document.getElementById('addVtIcon').value);
    formData.append('name', document.getElementById('addVtName').value);
    formData.append('name_am', document.getElementById('addVtNameAm').value);
    formData.append('sort_order', document.getElementById('addVtSortOrder').value);

    submitBtn.disabled = true;
    submitBtn.textContent = 'Adding…';

    try {
        const response = await fetch(API, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            showNotification(ICONS.success + ' Vehicle type added', 'success');
            closeModal();
            loadVehicleTypes();
        } else {
            showNotification(ICONS.error + ' ' + escHtml(data.message || 'Failed to add vehicle type'), 'error');
        }
    } catch (error) {
        showNotification(ICONS.error + ' Error adding vehicle type', 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalLabel;
    }
}

function editVehicleType(id) {
    const t = vehicleTypesCache.find(x => x.id == id);
    if (!t) { showNotification(ICONS.error + ' Vehicle type not found - refresh and try again', 'error'); return; }

    const modal = `
        <div class="modal" style="display:block">
            <div class="modal-content am-modal">
                <div class="modal-header">
                    <h2>Edit Vehicle Type</h2>
                    <button onclick="closeModal()" class="close-btn">${ICONS.close}</button>
                </div>
                <form onsubmit="handleEditVehicleType(event, ${t.id})">
                    <div class="form-group">
                        <label for="editVtIcon">Icon (emoji) *</label>
                        <input type="text" id="editVtIcon" class="form-control" value="${escHtml(t.icon)}" required>
                    </div>
                    <div class="form-group">
                        <label for="editVtName">Name *</label>
                        <input type="text" id="editVtName" class="form-control" value="${escHtml(t.name)}" required>
                    </div>
                    <div class="form-group">
                        <label for="editVtNameAm">Name (Amharic)</label>
                        <input type="text" id="editVtNameAm" class="form-control" value="${escHtml(t.name_am || '')}">
                    </div>
                    <div class="form-group">
                        <label for="editVtSortOrder">Sort Order</label>
                        <input type="number" id="editVtSortOrder" class="form-control" value="${t.sort_order}">
                    </div>
                    <div class="form-group">
                        <label class="am-check-label" for="editVtActive">
                            <input type="checkbox" id="editVtActive" ${t.is_active ? 'checked' : ''}>
                            Active
                        </label>
                    </div>
                    <div class="modal-actions">
                        <button type="button" onclick="closeModal()" class="btn-secondary">Cancel</button>
                        <button type="submit" class="btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    `;

    document.getElementById('modalContainer').innerHTML = modal;
    document.getElementById('modalOverlay').style.display = 'block';
}

async function handleEditVehicleType(event, id) {
    event.preventDefault();

    const submitBtn = event.target.querySelector('button[type="submit"]');
    const originalLabel = submitBtn.textContent;

    const formData = new FormData();
    formData.append('action', 'update_vehicle_type');
    formData.append('id', id);
    formData.append('icon', document.getElementById('editVtIcon').value);
    formData.append('name', document.getElementById('editVtName').value);
    formData.append('name_am', document.getElementById('editVtNameAm').value);
    formData.append('sort_order', document.getElementById('editVtSortOrder').value);
    formData.append('is_active', document.getElementById('editVtActive').checked ? 1 : 0);

    submitBtn.disabled = true;
    submitBtn.textContent = 'Saving…';

    try {
        const response = await fetch(API, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            showNotification(ICONS.success + ' Vehicle type updated', 'success');
            closeModal();
            loadVehicleTypes();
        } else {
            showNotification(ICONS.error + ' ' + escHtml(data.message || 'Failed to update vehicle type'), 'error');
        }
    } catch (error) {
        showNotification(ICONS.error + ' Error updating vehicle type', 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalLabel;
    }
}

async function deleteVehicleType(id, btn) {
    if (!confirm('Are you sure you want to delete this vehicle type? Vehicles already queued under it are not affected.')) return;

    const originalLabel = btn ? btn.textContent : null;
    if (btn) { btn.disabled = true; btn.textContent = ' Deleting…'; }

    const formData = new FormData();
    formData.append('action', 'delete_vehicle_type');
    formData.append('id', id);

    try {
        const response = await fetch(API, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            showNotification(ICONS.success + ' Vehicle type deleted', 'success');
            loadVehicleTypes();
        } else {
            showNotification(ICONS.error + ' ' + escHtml(data.message || 'Failed to delete vehicle type'), 'error');
        }
    } catch (error) {
        showNotification(ICONS.error + ' Error deleting vehicle type', 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = originalLabel; }
    }
}

// ─── QUEUE MONITOR ──────────────────────────────────────────────────
async function loadQueueMonitor() {
    try {
        const response = await fetch(`${API}?action=get_queue_monitor`);
        const data = await response.json();

        if (data.success) {
            displayQueueMonitor(data.queue);
        }
    } catch (error) {
        console.error('Error loading queue monitor:', error);
    }
}

function displayQueueMonitor(queue) {
    const container = document.getElementById('queueMonitor');

    if (queue.length === 0) {
        container.innerHTML = '<p class="empty-state">No vehicles in queue</p>';
        return;
    }

    container.innerHTML = queue.map(q => `
        <div class="queue-monitor-item">
            <div class="queue-monitor-header">
                <h3>${escHtml(q.station_name)}</h3>
                <span class="queue-count">${q.vehicles.length} vehicles</span>
            </div>
            <div class="queue-vehicles">
                ${q.vehicles.map(v => `
                    <div class="vehicle-card">
                        <strong>${escHtml(v.plate_number)}</strong><br>
                        ${escHtml(v.icon)} ${escHtml(v.vehicle_type_name)}<br>
                        Position: #${v.position}
                    </div>
                `).join('')}
            </div>
        </div>
    `).join('');
}

// ─── REPORTS ────────────────────────────────────────────────────────
async function loadReports() {
    const date = document.getElementById('reportDate').value;

    try {
        const response = await fetch(`${API}?action=get_reports&date=${date}`);
        const data = await response.json();

        if (data.success) {
            displayReports(data.reports, data.stats);
        }
    } catch (error) {
        console.error('Error loading reports:', error);
    }
}

function displayReports(reports, stats) {
    const container = document.getElementById('reportsContent');

    container.innerHTML = `
        <div class="report-summary">
            <div class="report-stat">
                <div class="report-stat-value">${stats.total_served}</div>
                <div class="report-stat-label">Total Served</div>
            </div>
            <div class="report-stat">
                <div class="report-stat-value">${stats.total_fuel_gallons.toFixed(2)}</div>
                <div class="report-stat-label">Gallons</div>
            </div>
            <div class="report-stat">
                <div class="report-stat-value">${stats.total_fuel_liters.toFixed(2)}</div>
                <div class="report-stat-label">Liters</div>
            </div>
        </div>

        <div class="table-container" style="margin-top:16px">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Plate</th>
                        <th>Vehicle Type</th>
                        <th>Station</th>
                        <th>Fuel (Gallons)</th>
                        <th>Fuel (Liters)</th>
                        <th>Served At</th>
                    </tr>
                </thead>
                <tbody>
                    ${reports.length === 0 ? '<tr><td colspan="6" class="empty-state">No reports for this date</td></tr>' :
                        reports.map(r => `
                            <tr>
                                <td><strong>${escHtml(r.plate_number)}</strong></td>
                                <td>${escHtml(r.vehicle_type_name)}</td>
                                <td>${escHtml(r.station_name)}</td>
                                <td>${r.fuel_amount_gallons}</td>
                                <td>${r.fuel_amount_liters}</td>
                                <td>${escHtml(new Date(r.served_at).toLocaleString())}</td>
                            </tr>
                        `).join('')
                    }
                </tbody>
            </table>
        </div>
    `;
}

// ─── SETTINGS ───────────────────────────────────────────────────────
async function loadSettings() {
    try {
        const response = await fetch(`${API}?action=get_settings`);
        const data = await response.json();

        if (data.success) {
            const s = data.settings;
            document.getElementById('settingStationName').value = s.station_name || '';
            document.getElementById('settingStationNameAm').value = s.station_name_am || '';
            document.getElementById('settingPhone').value = s.phone || '';
            document.getElementById('settingEmail').value = s.email || '';
            document.getElementById('settingAlertThreshold').value = s.alert_threshold || 30;
            document.getElementById('settingMaxQueueSize').value = s.max_queue_size || 500;
            document.getElementById('settingEnableVip').checked = s.enable_vip_queue == '1';
            document.getElementById('settingEnableWaiting').checked = s.enable_waiting_queue == '1';
        }
    } catch (error) {
        console.error('Error loading settings:', error);
    }
}

async function saveSettings(event, type) {
    event.preventDefault();

    const submitBtn = event.target.querySelector('button[type="submit"]');
    const originalLabel = submitBtn.textContent;

    const formData = new FormData();
    formData.append('action', 'update_settings');

    if (type === 'general') {
        formData.append('settings[station_name]', document.getElementById('settingStationName').value);
        formData.append('settings[station_name_am]', document.getElementById('settingStationNameAm').value);
        formData.append('settings[phone]', document.getElementById('settingPhone').value);
        formData.append('settings[email]', document.getElementById('settingEmail').value);
    } else if (type === 'queue') {
        formData.append('settings[alert_threshold]', document.getElementById('settingAlertThreshold').value);
        formData.append('settings[max_queue_size]', document.getElementById('settingMaxQueueSize').value);
        formData.append('settings[enable_vip_queue]', document.getElementById('settingEnableVip').checked ? '1' : '0');
        formData.append('settings[enable_waiting_queue]', document.getElementById('settingEnableWaiting').checked ? '1' : '0');
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Saving…';

    try {
        const response = await fetch(API, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            showNotification(ICONS.success + ' Settings saved successfully', 'success');
        } else {
            showNotification(ICONS.error + ' ' + escHtml(data.message || 'Failed to save settings'), 'error');
        }
    } catch (error) {
        showNotification(ICONS.error + ' Error saving settings', 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalLabel;
    }
}

// ─── UTILITIES ──────────────────────────────────────────────────────
function closeModal() {
    document.getElementById('modalOverlay').style.display = 'none';
    document.getElementById('modalContainer').innerHTML = '';
}

function escHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// Kept for parity with controller-app.js's fix for the same class of bug:
// safe-escapes a value for embedding inside a single-quoted inline
// onclick="fn('...')" attribute string. Not currently needed here (every
// inline onclick in this file only ever embeds a numeric id), but kept
// available for any future handler that embeds a server-controlled string.
function jsStr(s) {
    return "'" + String(s == null ? '' : s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
}

function showNotification(message, type = 'success') {
    const notification = document.getElementById('notification');
    notification.innerHTML = message;
    notification.className = `notification am-toast ${type}`;
    notification.style.display = 'block';

    setTimeout(() => {
        notification.style.display = 'none';
    }, 5000);
}

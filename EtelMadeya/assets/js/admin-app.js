// ═══════════════════════════════════════════════════════════════════
// ETEL MADEYA - ADMIN PANEL JAVASCRIPT
// ═══════════════════════════════════════════════════════════════════

let refreshInterval = null;

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
    document.getElementById('mainPanel').style.display = 'flex';
    
    document.getElementById('adminName').textContent = currentAdmin.full_name;
    
    loadDashboard();
    
    // Auto-refresh dashboard every 60 seconds
    refreshInterval = setInterval(() => {
        if (document.querySelector('#dashboardTab.active')) {
            loadDashboard();
        }
    }, 60000);
}

async function handleLogin(event) {
    event.preventDefault();
    
    const username = document.getElementById('username').value;
    const password = document.getElementById('password').value;
    const errorDiv = document.getElementById('loginError');
    
    const formData = new FormData();
    formData.append('action', 'login');
    formData.append('username', username);
    formData.append('password', password);
    
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
            errorDiv.textContent = data.message || 'Login failed';
            errorDiv.style.display = 'block';
        }
    } catch (error) {
        errorDiv.textContent = 'Connection error. Please try again.';
        errorDiv.style.display = 'block';
    }
}

async function handleLogout() {
    if (!confirm('Are you sure you want to logout?')) return;
    
    try {
        await fetch(`${API}?action=logout`, { method: 'POST' });
        if (refreshInterval) clearInterval(refreshInterval);
        location.reload();
    } catch (error) {
        location.reload();
    }
}

// ─── TAB NAVIGATION ─────────────────────────────────────────────────
function showTab(tabName) {
    // Update nav items
    document.querySelectorAll('.nav-item').forEach(item => {
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
            // Update stats
            document.getElementById('statControllers').textContent = data.stats.controllers;
            document.getElementById('statQueued').textContent = data.stats.total_queued;
            document.getElementById('statServedToday').textContent = data.stats.served_today;
            document.getElementById('statVehicleTypes').textContent = data.stats.vehicle_types;
            
            // Display recent activity
            const activityDiv = document.getElementById('recentActivity');
            if (data.activity.length === 0) {
                activityDiv.innerHTML = '<p class="empty-state">No recent activity</p>';
            } else {
                activityDiv.innerHTML = data.activity.map(a => `
                    <div class="activity-item">
                        <strong>${a.full_name}</strong> ${a.action}
                        <br><small>${new Date(a.created_at).toLocaleString()}</small>
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
                        <strong>${c.station_name}</strong>
                        <br>${c.full_name}
                        <br><small>Queued: ${c.queued_count}</small>
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
    const tbody = document.getElementById('controllersTableBody');
    
    if (controllers.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="empty-state">No controllers found</td></tr>';
        return;
    }
    
    tbody.innerHTML = controllers.map(c => `
        <tr>
            <td>${c.id}</td>
            <td><strong>${c.username}</strong></td>
            <td>${c.full_name}</td>
            <td>${c.station_name}</td>
            <td>${c.phone || '-'}</td>
            <td><span class="status-badge status-${c.is_active ? 'active' : 'inactive'}">${c.is_active ? 'Active' : 'Inactive'}</span></td>
            <td>${c.last_login ? new Date(c.last_login).toLocaleString() : 'Never'}</td>
            <td>
                <button onclick="editController(${c.id})" class="action-btn action-btn-serve">✏️ Edit</button>
                <button onclick="deleteController(${c.id})" class="action-btn action-btn-wait">🗑️ Delete</button>
            </td>
        </tr>
    `).join('');
}

function showAddController() {
    const modal = `
        <div class="modal" style="display:block">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Add Controller</h2>
                    <button onclick="closeModal()" class="close-btn">✕</button>
                </div>
                <form onsubmit="handleAddController(event)">
                    <div style="padding: 30px;">
                        <div class="form-group">
                            <label>Username *</label>
                            <input type="text" id="ctrlUsername" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Password *</label>
                            <input type="password" id="ctrlPassword" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" id="ctrlFullName" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="tel" id="ctrlPhone" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Station Name</label>
                            <input type="text" id="ctrlStation" class="form-control" value="Etel Madeya Gas Station">
                        </div>
                        <div class="modal-actions">
                            <button type="button" onclick="closeModal()" class="btn-secondary">Cancel</button>
                            <button type="submit" class="btn-primary">Add Controller</button>
                        </div>
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
    
    const formData = new FormData();
    formData.append('action', 'create_controller');
    formData.append('username', document.getElementById('ctrlUsername').value);
    formData.append('password', document.getElementById('ctrlPassword').value);
    formData.append('full_name', document.getElementById('ctrlFullName').value);
    formData.append('phone', document.getElementById('ctrlPhone').value);
    formData.append('station_name', document.getElementById('ctrlStation').value);
    
    try {
        const response = await fetch(API, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            showNotification('✅ Controller added successfully', 'success');
            closeModal();
            loadControllers();
        } else {
            showNotification('❌ ' + (data.message || 'Failed to add controller'), 'error');
        }
    } catch (error) {
        showNotification('❌ Error adding controller', 'error');
    }
}

async function deleteController(id) {
    if (!confirm('Are you sure you want to delete this controller?')) return;
    
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
            showNotification('✅ Controller deleted', 'success');
            loadControllers();
        } else {
            showNotification('❌ ' + (data.message || 'Failed to delete'), 'error');
        }
    } catch (error) {
        showNotification('❌ Error deleting controller', 'error');
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
    const tbody = document.getElementById('vehiclesTableBody');
    
    if (types.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="empty-state">No vehicle types found</td></tr>';
        return;
    }
    
    tbody.innerHTML = types.map(t => `
        <tr>
            <td>${t.id}</td>
            <td style="font-size:24px">${t.icon}</td>
            <td><strong>${t.name}</strong></td>
            <td>${t.name_am}</td>
            <td>${t.sort_order}</td>
            <td><span class="status-badge status-${t.is_active ? 'active' : 'inactive'}">${t.is_active ? 'Active' : 'Inactive'}</span></td>
            <td>
                <button onclick="editVehicleType(${t.id})" class="action-btn action-btn-serve">✏️ Edit</button>
                <button onclick="deleteVehicleType(${t.id})" class="action-btn action-btn-wait">🗑️ Delete</button>
            </td>
        </tr>
    `).join('');
}

function showAddVehicleType() {
    // Similar to showAddController - implement as needed
    showNotification('Feature coming soon', 'success');
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
                <h3>${q.station_name}</h3>
                <span class="queue-count">${q.vehicles.length} vehicles</span>
            </div>
            <div class="queue-vehicles">
                ${q.vehicles.map(v => `
                    <div class="vehicle-card">
                        <strong>${v.plate_number}</strong><br>
                        ${v.icon} ${v.vehicle_type_name}<br>
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
            <h3>Summary</h3>
            <div class="report-stats">
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
        </div>
        
        <div class="table-container" style="margin-top:20px">
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
                                <td><strong>${r.plate_number}</strong></td>
                                <td>${r.vehicle_type_name}</td>
                                <td>${r.station_name}</td>
                                <td>${r.fuel_amount_gallons}</td>
                                <td>${r.fuel_amount_liters}</td>
                                <td>${new Date(r.served_at).toLocaleString()}</td>
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
    
    try {
        const response = await fetch(API, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            showNotification('✅ Settings saved successfully', 'success');
        } else {
            showNotification('❌ ' + (data.message || 'Failed to save settings'), 'error');
        }
    } catch (error) {
        showNotification('❌ Error saving settings', 'error');
    }
}

// ─── UTILITIES ──────────────────────────────────────────────────────
function closeModal() {
    document.getElementById('modalOverlay').style.display = 'none';
    document.getElementById('modalContainer').innerHTML = '';
}

function showNotification(message, type = 'success') {
    const notification = document.getElementById('notification');
    notification.textContent = message;
    notification.className = `notification ${type}`;
    notification.style.display = 'block';
    
    setTimeout(() => {
        notification.style.display = 'none';
    }, 5000);
}

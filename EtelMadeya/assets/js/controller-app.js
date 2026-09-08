// ═══════════════════════════════════════════════════════════════════
// ETEL MADEYA - CONTROLLER PANEL JAVASCRIPT
// ═══════════════════════════════════════════════════════════════════

let refreshInterval = null;

// ─── PAGINATION STATE ───────────────────────────────────────────────
let currentQueue = [];      // Full queue from server (after filters)
let currentPage = 1;        // Active page
let pageSize = 50;          // Rows per page (50 / 100 / 200 / 'all')
let batchPages = [];        // Array of arrays — each sub-array is one import batch

// ─── INITIALIZATION ─────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    checkSession();
});

// ─── AUTHENTICATION ─────────────────────────────────────────────────
async function checkSession() {
    try {
        const response = await fetch(`${API}&action=check_session`);
        const data = await response.json();
        
        if (data.logged_in) {
            currentController = data.controller;
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
    
    document.getElementById('controllerName').textContent = currentController.full_name;
    document.getElementById('stationName').textContent = currentController.station_name;
    
    loadVehicleTypes();
    loadStats();
    loadQueue();
    
    // Auto-refresh every 30 seconds
    refreshInterval = setInterval(() => {
        loadStats();
        loadQueue();
    }, 30000);
}

async function handleLogin(event) {
    event.preventDefault();
    
    const username = document.getElementById('username').value;
    const password = document.getElementById('password').value;
    const errorDiv = document.getElementById('loginError');
    
    const formData = new FormData();
    formData.append('action', 'controller_login');
    formData.append('username', username);
    formData.append('password', password);
    
    try {
        const response = await fetch(API, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            currentController = data.controller;
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

function toggleEtelIDLogin() {
    const loginForm = document.getElementById('loginForm');
    const etelidForm = document.getElementById('etelidLoginForm');
    const showBtn = document.getElementById('showEtelIDLoginBtn');
    const divider = document.querySelector('.login-divider');
    const isShowingEtelID = etelidForm.style.display !== 'none';

    etelidForm.style.display = isShowingEtelID ? 'none' : 'block';
    loginForm.style.display  = isShowingEtelID ? 'block' : 'none';
    showBtn.style.display    = isShowingEtelID ? 'block' : 'none';
    if (divider) divider.style.display = isShowingEtelID ? 'flex' : 'none';
    document.getElementById('loginError').style.display = 'none';
}

async function handleEtelIDLogin(event) {
    event.preventDefault();

    const identifier = document.getElementById('etelidIdentifier').value;
    const password    = document.getElementById('etelidPassword').value;
    const errorDiv    = document.getElementById('loginError');

    const formData = new FormData();
    formData.append('action', 'controller_etelid_login');
    formData.append('identifier', identifier);
    formData.append('password', password);

    try {
        const response = await fetch(API, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            currentController = data.controller;
            showMainPanel();
        } else {
            errorDiv.textContent = data.message || 'EtelID sign-in failed';
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
        await fetch(`${API}&action=controller_logout`, { method: 'POST' });
        if (refreshInterval) clearInterval(refreshInterval);
        location.reload();
    } catch (error) {
        location.reload();
    }
}

// ─── DATA LOADING ───────────────────────────────────────────────────
async function loadVehicleTypes() {
    try {
        const response = await fetch(`${API}&action=get_vehicle_types`);
        const data = await response.json();
        
        if (data.success) {
            vehicleTypes = data.types;
            populateVehicleTypeSelects();
        }
    } catch (error) {
        console.error('Error loading vehicle types:', error);
    }
}

function populateVehicleTypeSelects() {
    const selects = ['vehicleTypeFilter', 'addVehicleType', 'bulkVehicleType'];
    
    selects.forEach(selectId => {
        const select = document.getElementById(selectId);
        if (!select) return;
        
        if (selectId === 'vehicleTypeFilter') {
            select.innerHTML = '<option value="0">All Types</option>';
        } else {
            select.innerHTML = '<option value="">Select type...</option>';
        }
        
        vehicleTypes.forEach(type => {
            const option = document.createElement('option');
            option.value = type.id;
            option.textContent = `${type.icon} ${type.name} (${type.name_am})`;
            select.appendChild(option);
        });
    });
}

async function loadStats() {
    try {
        const response = await fetch(`${API}&action=get_stats`);
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('statQueued').textContent = data.stats.total_queued;
            document.getElementById('statServed').textContent = data.stats.total_served_today;
            document.getElementById('statVip').textContent = data.stats.vip_count;
            document.getElementById('statWaiting').textContent = data.stats.waiting_count;
        }
    } catch (error) {
        console.error('Error loading stats:', error);
    }
}

async function loadQueue(resetPage) {
    const vehicleTypeId = document.getElementById('vehicleTypeFilter').value;
    const queueType = document.getElementById('queueTypeFilter').value;

    // Reset to page 1 when filters change or when explicitly requested
    if (resetPage === true) {
        currentPage = 1;
    }

    try {
        const response = await fetch(`${API}&action=get_queue&vehicle_type_id=${vehicleTypeId}&queue_type=${queueType}`);
        const data = await response.json();

        if (data.success) {
            currentQueue = data.queue || [];
            renderCurrentPage();
        }
    } catch (error) {
        console.error('Error loading queue:', error);
    }
}

// ─── PAGINATION ─────────────────────────────────────────────────────

// Build batch pages: group queue items by batch_id, each batch = one page
function buildBatchPages() {
    batchPages = [];
    if (currentQueue.length === 0) return;

    let currentBatch = currentQueue[0].batch_id;
    let page = [];
    for (const item of currentQueue) {
        if (item.batch_id !== currentBatch) {
            // New batch → start a new page
            batchPages.push(page);
            page = [];
            currentBatch = item.batch_id;
        }
        page.push(item);
    }
    if (page.length > 0) batchPages.push(page);
}

function getTotalPages() {
    if (pageSize === 'all') return 1;
    // Use batch-based pages if batches exist
    if (batchPages.length > 0) return batchPages.length;
    return Math.max(1, Math.ceil(currentQueue.length / pageSize));
}

function getPageSlice() {
    if (pageSize === 'all') return currentQueue;
    // Use batch-based pages
    if (batchPages.length > 0) {
        const idx = Math.max(0, currentPage - 1);
        return batchPages[idx] || [];
    }
    const start = (currentPage - 1) * pageSize;
    return currentQueue.slice(start, start + pageSize);
}

function renderCurrentPage() {
    // Rebuild batch pages from current queue data
    buildBatchPages();
    
    // Clamp current page in case data shrank (e.g. vehicles served)
    const totalPages = getTotalPages();
    if (currentPage > totalPages) currentPage = totalPages;
    if (currentPage < 1) currentPage = 1;

    displayQueue(getPageSlice());
    renderPaginationBar();
}

function changePageSize() {
    const val = document.getElementById('pageSizeSelect').value;
    pageSize = (val === 'all') ? 'all' : parseInt(val, 10);
    currentPage = 1;
    renderCurrentPage();
}

function goToPage(page) {
    const totalPages = getTotalPages();
    if (page < 1 || page > totalPages) return;
    currentPage = page;
    renderCurrentPage();
    // Scroll table into view so the controller sees the new page
    document.querySelector('.queue-container').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function renderPaginationBar() {
    const bar = document.getElementById('paginationBar');
    const info = document.getElementById('paginationInfo');
    const controls = document.getElementById('paginationControls');
    const total = currentQueue.length;

    // Hide bar entirely when there's nothing or one short page
    if (total === 0) {
        bar.style.display = 'none';
        return;
    }

    bar.style.display = 'flex';

    // Info: "Showing 1–50 of 234 vehicles"
    if (pageSize === 'all') {
        info.textContent = `Showing all ${total} vehicle${total === 1 ? '' : 's'}`;
    } else if (batchPages.length > 0) {
        const pageItems = batchPages[currentPage - 1] || [];
        const batchCount = pageItems.length;
        info.textContent = `Import ${currentPage} of ${batchPages.length} — ${batchCount} vehicle${batchCount === 1 ? '' : 's'} (${total} total)`;
    } else {
        const start = (currentPage - 1) * pageSize + 1;
        const end = Math.min(currentPage * pageSize, total);
        info.textContent = `Showing ${start}–${end} of ${total} vehicles`;
    }

    // Controls: only show if more than one page
    const totalPages = getTotalPages();
    if (totalPages <= 1) {
        controls.innerHTML = '';
        return;
    }

    controls.innerHTML = buildPageButtons(currentPage, totalPages);
}

function buildPageButtons(current, total) {
    const parts = [];

    // « First and ‹ Prev
    parts.push(pageBtn('«', 1, current === 1, 'First page'));
    parts.push(pageBtn('‹', current - 1, current === 1, 'Previous page'));

    // Build the visible page-number window
    // Strategy: always show first, last, current, and 2 neighbours of current
    // Use ellipsis for gaps
    const windowSize = 2; // pages on each side of current
    const pages = new Set([1, total, current]);
    for (let i = 1; i <= windowSize; i++) {
        if (current - i >= 1) pages.add(current - i);
        if (current + i <= total) pages.add(current + i);
    }
    const sorted = Array.from(pages).sort((a, b) => a - b);

    let prev = 0;
    sorted.forEach(p => {
        if (p - prev > 1) {
            parts.push('<span class="page-ellipsis">…</span>');
        }
        parts.push(numberBtn(p, p === current));
        prev = p;
    });

    // Next › and Last »
    parts.push(pageBtn('›', current + 1, current === total, 'Next page'));
    parts.push(pageBtn('»', total, current === total, 'Last page'));

    return parts.join('');
}

function pageBtn(label, page, disabled, title) {
    const cls = 'page-btn page-nav' + (disabled ? ' disabled' : '');
    const onclick = disabled ? '' : `onclick="goToPage(${page})"`;
    return `<button class="${cls}" ${onclick} title="${title}" ${disabled ? 'disabled' : ''}>${label}</button>`;
}

function numberBtn(page, active) {
    const cls = 'page-btn page-number' + (active ? ' active' : '');
    return `<button class="${cls}" onclick="goToPage(${page})">${page}</button>`;
}

function displayQueue(queue) {
    const tbody = document.getElementById('queueTableBody');

    if (!queue || queue.length === 0) {
        const msg = currentQueue.length === 0
            ? 'No vehicles in queue'
            : 'No vehicles on this page';
        tbody.innerHTML = `<tr><td colspan="8" class="empty-state">${msg}</td></tr>`;
        return;
    }
    
    tbody.innerHTML = queue.map(v => `
        <tr>
            <td><strong>#${v.display_position || v.position}</strong></td>
            <td><strong>${v.plate_number}</strong></td>
            <td>${v.icon} ${v.vehicle_type_name}</td>
            <td><span class="badge badge-${v.queue_type}">${v.queue_type.toUpperCase()}</span></td>
            <td>Tier ${v.priority_tier}</td>
            <td>${v.token}</td>
            <td>${new Date(v.registered_at).toLocaleTimeString()}</td>
            <td>
                <button onclick="showServeModal(${v.id}, '${v.plate_number}', '${v.vehicle_type_name}')" class="action-btn action-btn-serve">✅ Serve</button>
                ${v.queue_type !== 'vip' ? `<button onclick="moveToVip(${v.id})" class="action-btn action-btn-vip">⭐ VIP</button>` : ''}
                ${v.queue_type !== 'waiting' ? `<button onclick="moveToWaiting(${v.id})" class="action-btn action-btn-wait">⏳ Wait</button>` : ''}
            </td>
        </tr>
    `).join('');
}

// ─── QUEUE ACTIONS ──────────────────────────────────────────────────
function showAddVehicle() {
    document.getElementById('modalOverlay').style.display = 'block';
    document.getElementById('addVehicleModal').style.display = 'block';
}

function showBulkImport() {
    document.getElementById('modalOverlay').style.display = 'block';
    document.getElementById('bulkImportModal').style.display = 'block';
}

function showServeModal(queueId, plate, type) {
    document.getElementById('serveQueueId').value = queueId;
    document.getElementById('servePlate').textContent = plate;
    document.getElementById('serveType').textContent = type;
    document.getElementById('modalOverlay').style.display = 'block';
    document.getElementById('serveModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('modalOverlay').style.display = 'none';
    document.querySelectorAll('.modal').forEach(modal => {
        modal.style.display = 'none';
    });
}

async function handleAddVehicle(event) {
    event.preventDefault();
    
    const formData = new FormData();
    formData.append('action', 'register');
    formData.append('plate_number', document.getElementById('addPlate').value.trim());
    formData.append('vehicle_type_id', document.getElementById('addVehicleType').value);
    formData.append('driver_name', document.getElementById('addDriverName').value.trim());
    formData.append('driver_phone', document.getElementById('addDriverPhone').value.trim());
    formData.append('controller_id', currentController.id);
    
    try {
        const response = await fetch(API, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            showNotification('✅ Vehicle added successfully', 'success');
            closeModal();
            document.getElementById('addVehicleForm').reset();
            loadStats();
            loadQueue();
        } else {
            showNotification('❌ ' + (data.message || 'Failed to add vehicle'), 'error');
        }
    } catch (error) {
        showNotification('❌ Error adding vehicle', 'error');
    }
}

async function handleBulkImport(event) {
    event.preventDefault();
    
    const formData = new FormData();
    formData.append('action', 'bulk_import');
    formData.append('plates', document.getElementById('bulkPlates').value);
    formData.append('vehicle_type_id', document.getElementById('bulkVehicleType').value);
    
    try {
        const response = await fetch(API, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            showNotification(`✅ Imported: ${data.imported}, Skipped: ${data.skipped}`, 'success');
            closeModal();
            document.getElementById('bulkImportForm').reset();
            loadStats();
            loadQueue(true);
        } else {
            showNotification('❌ ' + (data.message || 'Import failed'), 'error');
        }
    } catch (error) {
        showNotification('❌ Error importing vehicles', 'error');
    }
}

async function handleServe(event) {
    event.preventDefault();
    
    const formData = new FormData();
    formData.append('action', 'serve_vehicle');
    formData.append('queue_id', document.getElementById('serveQueueId').value);
    formData.append('fuel_gallons', document.getElementById('serveFuelGallons').value || 0);
    formData.append('fuel_liters', document.getElementById('serveFuelLiters').value || 0);
    
    try {
        const response = await fetch(API, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            showNotification('✅ Vehicle served successfully', 'success');
            closeModal();
            document.getElementById('serveForm').reset();
            loadStats();
            loadQueue();
        } else {
            showNotification('❌ ' + (data.message || 'Failed to serve vehicle'), 'error');
        }
    } catch (error) {
        showNotification('❌ Error serving vehicle', 'error');
    }
}

async function moveToVip(queueId) {
    if (!confirm('Move this vehicle to VIP queue?')) return;
    
    const formData = new FormData();
    formData.append('action', 'move_to_vip');
    formData.append('queue_id', queueId);
    
    try {
        const response = await fetch(API, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            showNotification('✅ Moved to VIP queue', 'success');
            loadStats();
            loadQueue();
        } else {
            showNotification('❌ ' + (data.message || 'Failed to move vehicle'), 'error');
        }
    } catch (error) {
        showNotification('❌ Error moving vehicle', 'error');
    }
}

async function moveToWaiting(queueId) {
    if (!confirm('Move this vehicle to waiting queue?')) return;
    
    const formData = new FormData();
    formData.append('action', 'move_to_waiting');
    formData.append('queue_id', queueId);
    
    try {
        const response = await fetch(API, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            showNotification('✅ Moved to waiting queue', 'success');
            loadStats();
            loadQueue();
        } else {
            showNotification('❌ ' + (data.message || 'Failed to move vehicle'), 'error');
        }
    } catch (error) {
        showNotification('❌ Error moving vehicle', 'error');
    }
}

// ─── NOTIFICATION ───────────────────────────────────────────────────
function showNotification(message, type = 'success') {
    const notification = document.getElementById('notification');
    notification.textContent = message;
    notification.className = `notification ${type}`;
    notification.style.display = 'block';
    
    setTimeout(() => {
        notification.style.display = 'none';
    }, 5000);
}

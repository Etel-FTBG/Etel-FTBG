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
    initReport();

    // Auto-refresh every 30 seconds
    refreshInterval = setInterval(() => {
        loadStats();
        loadQueue();
    }, 30000);
}

async function handleEtelIDLogin(event) {
    event.preventDefault();

    const identifier = document.getElementById('etelidIdentifier').value;
    const password    = document.getElementById('etelidPassword').value;
    const errorDiv    = document.getElementById('loginError');
    const submitBtn   = event.target.querySelector('button[type="submit"]');
    const originalLabel = submitBtn.textContent;

    const formData = new FormData();
    formData.append('action', 'controller_etelid_login');
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
            currentController = data.controller;
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
                <button onclick="showServeModal(${v.id}, '${v.plate_number}', '${v.vehicle_type_name}')" class="action-btn action-btn-serve">${ICONS.serve} Serve</button>
                ${v.queue_type !== 'vip' ? `<button onclick="moveToVip(${v.id}, this)" class="action-btn action-btn-vip">${ICONS.vip} VIP</button>` : ''}
                ${v.queue_type !== 'waiting' ? `<button onclick="moveToWaiting(${v.id}, this)" class="action-btn action-btn-wait">${ICONS.wait} Wait</button>` : ''}
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

    const submitBtn = event.target.querySelector('button[type="submit"]');
    const originalLabel = submitBtn.textContent;

    const formData = new FormData();
    formData.append('action', 'register');
    formData.append('plate_number', document.getElementById('addPlate').value.trim());
    formData.append('vehicle_type_id', document.getElementById('addVehicleType').value);
    formData.append('driver_name', document.getElementById('addDriverName').value.trim());
    formData.append('driver_phone', document.getElementById('addDriverPhone').value.trim());
    formData.append('controller_id', currentController.id);

    submitBtn.disabled = true;
    submitBtn.textContent = 'Adding…';

    try {
        const response = await fetch(API, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            showNotification(ICONS.success + ' Vehicle added successfully', 'success');
            closeModal();
            document.getElementById('addVehicleForm').reset();
            loadStats();
            loadQueue();
        } else {
            showNotification(ICONS.error + ' ' + escHtml(data.message || 'Failed to add vehicle'), 'error');
        }
    } catch (error) {
        showNotification(ICONS.error + ' Error adding vehicle', 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalLabel;
    }
}

async function handleBulkImport(event) {
    event.preventDefault();

    const submitBtn = event.target.querySelector('button[type="submit"]');
    const originalLabel = submitBtn.textContent;

    const formData = new FormData();
    formData.append('action', 'bulk_import');
    formData.append('plates', document.getElementById('bulkPlates').value);
    formData.append('vehicle_type_id', document.getElementById('bulkVehicleType').value);

    submitBtn.disabled = true;
    submitBtn.textContent = 'Importing…';

    try {
        const response = await fetch(API, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            showNotification(ICONS.success + ` Imported: ${data.imported}, Skipped: ${data.skipped}`, 'success');
            closeModal();
            document.getElementById('bulkImportForm').reset();
            loadStats();
            loadQueue(true);
        } else {
            showNotification(ICONS.error + ' ' + escHtml(data.message || 'Import failed'), 'error');
        }
    } catch (error) {
        showNotification(ICONS.error + ' Error importing vehicles', 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalLabel;
    }
}

async function handleServe(event) {
    event.preventDefault();

    const submitBtn = event.target.querySelector('button[type="submit"]');
    const originalLabel = submitBtn.textContent;
    const gallons = parseFloat(document.getElementById('serveFuelGallons').value) || 0;
    const liters = parseFloat(document.getElementById('serveFuelLiters').value) || 0;

    if (gallons <= 0 && liters <= 0) {
        showNotification(ICONS.error + ' Enter the fuel amount served (gallons or liters)', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'serve_vehicle');
    formData.append('queue_id', document.getElementById('serveQueueId').value);
    formData.append('fuel_gallons', gallons);
    formData.append('fuel_liters', liters);

    submitBtn.disabled = true;
    submitBtn.textContent = 'Serving…';

    try {
        const response = await fetch(API, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            showNotification(ICONS.success + ' Vehicle served successfully', 'success');
            closeModal();
            document.getElementById('serveForm').reset();
            loadStats();
            loadQueue();
        } else {
            showNotification(ICONS.error + ' ' + escHtml(data.message || 'Failed to serve vehicle'), 'error');
        }
    } catch (error) {
        showNotification(ICONS.error + ' Error serving vehicle', 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalLabel;
    }
}

async function moveToVip(queueId, btn) {
    if (!confirm('Move this vehicle to VIP queue?')) return;

    const originalLabel = btn ? btn.textContent : null;
    if (btn) { btn.disabled = true; btn.textContent = ICONS.wait + ' Moving…'; }

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
            showNotification(ICONS.success + ' Moved to VIP queue', 'success');
            loadStats();
            loadQueue();
        } else {
            showNotification(ICONS.error + ' ' + escHtml(data.message || 'Failed to move vehicle'), 'error');
        }
    } catch (error) {
        showNotification(ICONS.error + ' Error moving vehicle', 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = originalLabel; }
    }
}

async function moveToWaiting(queueId, btn) {
    if (!confirm('Move this vehicle to waiting queue?')) return;

    const originalLabel = btn ? btn.textContent : null;
    if (btn) { btn.disabled = true; btn.textContent = ICONS.wait + ' Moving…'; }

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
            showNotification(ICONS.success + ' Moved to waiting queue', 'success');
            loadStats();
            loadQueue();
        } else {
            showNotification(ICONS.error + ' ' + escHtml(data.message || 'Failed to move vehicle'), 'error');
        }
    } catch (error) {
        showNotification(ICONS.error + ' Error moving vehicle', 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = originalLabel; }
    }
}

// ─── TAB SWITCHING ──────────────────────────────────────────────────
function switchTab(tab, btn) {
    document.querySelectorAll('.ctrl-tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.ctrl-tab').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    if (btn) btn.classList.add('active');
    if (tab === 'report') loadReport();
    if (tab === 'import') initImportVehicleTypes();
}

// ─── QUEUE REPORT ───────────────────────────────────────────────────
let reportRows = [];

function initReport() {
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('reportDate').value = today;
}

async function loadReport() {
    const date = document.getElementById('reportDate').value || new Date().toISOString().split('T')[0];
    const tbody = document.getElementById('reportTableBody');
    tbody.innerHTML = '<tr><td colspan="6" class="empty-state">Loading...</td></tr>';
    document.getElementById('reportMatchAlert').style.display = 'none';

    try {
        const res = await fetch(`${API}&action=get_report&date=${date}`);
        const data = await res.json();
        if (!data.success) { tbody.innerHTML = '<tr><td colspan="6" class="empty-state">Failed to load report.</td></tr>'; return; }
        reportRows = data.report || [];
        document.getElementById('reportCount').textContent = reportRows.length + ' served';
        renderReport(reportRows);
    } catch(e) {
        tbody.innerHTML = '<tr><td colspan="6" class="empty-state">Error loading report.</td></tr>';
    }
}

function renderReport(rows) {
    const tbody = document.getElementById('reportTableBody');
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="empty-state">No served vehicles for this date.</td></tr>';
        return;
    }
    tbody.innerHTML = rows.map((r, i) => {
        const time = r.served_at ? new Date(r.served_at.replace(' ', 'T')).toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit'}) : '--';
        const typeName = (r.vehicle_type_icon ? r.vehicle_type_icon + ' ' : '') + (r.vehicle_type_name || r.vehicle_type_id || '--');
        const parity = r.plate_parity === 'even' ? '🔵 Even' : r.plate_parity === 'odd' ? '🟠 Odd' : '--';
        return `<tr data-plate="${(r.plate_number||'').toUpperCase()}">
            <td style="color:#888;font-size:12px;">${i+1}</td>
            <td><span class="report-plate">${r.plate_number||'--'}</span></td>
            <td style="font-size:13px;color:#ccc;">${typeName}</td>
            <td style="font-size:13px;">${parity}</td>
            <td style="font-family:monospace;color:var(--gold);font-size:13px;">${r.token||'--'}</td>
            <td style="font-size:13px;color:#aaa;">${time}</td>
        </tr>`;
    }).join('');
}

function filterReport() {
    const q = (document.getElementById('reportSearch').value || '').toUpperCase().trim();
    if (!q) { renderReport(reportRows); document.getElementById('reportMatchAlert').style.display = 'none'; return; }
    const filtered = reportRows.filter(r => (r.plate_number || '').toUpperCase().includes(q));
    renderReport(filtered);
    const alert = document.getElementById('reportMatchAlert');
    if (filtered.length) {
        alert.style.display = 'block';
        document.getElementById('reportMatchText').textContent = filtered.length + ' match(es) found — this plate was already served today!';
    } else {
        alert.style.display = 'none';
    }
}

function clearReportSearch() {
    document.getElementById('reportSearch').value = '';
    document.getElementById('reportMatchAlert').style.display = 'none';
    renderReport(reportRows);
}

// ─── IMPORT & CHECK TAB ─────────────────────────────────────────────
let impParsedPlates = [];   // all plates extracted from files
let impCleanPlates  = [];   // clean plates after server check
let impFilesData    = [];   // { name, plates[] } per file

function initImportVehicleTypes() {
    const sel = document.getElementById('impVehicleType');
    if (sel.options.length > 1) return; // already populated
    sel.innerHTML = '<option value="">Select vehicle type...</option>';
    vehicleTypes.forEach(t => {
        const o = document.createElement('option');
        o.value = t.id;
        o.textContent = `${t.icon} ${t.name}`;
        sel.appendChild(o);
    });
}

// ── Drag & drop handlers ──
function impDragOver(e) {
    e.preventDefault();
    document.getElementById('impDropzone').classList.add('drag-over');
}
function impDragLeave(e) {
    document.getElementById('impDropzone').classList.remove('drag-over');
}
function impDrop(e) {
    e.preventDefault();
    document.getElementById('impDropzone').classList.remove('drag-over');
    impFilesChosen(e.dataTransfer.files);
}

// ── File parsing entry point ──
async function impFilesChosen(fileList) {
    if (!fileList || !fileList.length) return;
    impFilesData = [];
    impParsedPlates = [];
    impCleanPlates  = [];
    document.getElementById('impResults').style.display = 'none';
    document.getElementById('impImportFb').style.display = 'none';

    const fileListEl = document.getElementById('impFileList');
    fileListEl.style.display = 'block';
    fileListEl.innerHTML = '<div style="font-size:11px;color:#888;margin-bottom:8px;letter-spacing:1px;text-transform:uppercase;">Reading files…</div>';

    for (const file of fileList) {
        if (file.name.toLowerCase().endsWith('.zip')) {
            await parseZip(file);
        } else if (file.name.toLowerCase().endsWith('.txt')) {
            await parseTxt(file, file.name);
        }
    }

    // Build combined plate list
    impFilesData.forEach(f => { impParsedPlates.push(...f.plates); });

    renderFileList();
    if (impParsedPlates.length) {
        document.getElementById('impControls').style.display = 'block';
    }
}

async function parseTxt(file, name) {
    const text = await file.text();
    const plates = extractPlates(text);
    impFilesData.push({ name, plates });
}

async function parseZip(file) {
    try {
        const zip = await JSZip.loadAsync(file);
        const txtFiles = Object.values(zip.files).filter(f => !f.dir && f.name.toLowerCase().endsWith('.txt'));
        for (const zf of txtFiles) {
            const text = await zf.async('text');
            const plates = extractPlates(text);
            impFilesData.push({ name: zf.name, plates });
        }
    } catch(e) {
        console.error('ZIP parse error', e);
    }
}

function extractPlates(text) {
    return text.split(/[\r\n,;]+/)
        .map(l => l.replace(/[^A-Z0-9\-]/gi, '').toUpperCase().trim())
        .filter(p => p.length >= 2);
}

function renderFileList() {
    const el = document.getElementById('impFileList');
    if (!impFilesData.length) { el.style.display = 'none'; return; }
    el.innerHTML = impFilesData.map(f => `
        <div class="imp-file-item">
            <span class="imp-file-icon">${f.name.endsWith('.txt') || !f.name.includes('.') ? ICONS.doc : ICONS.folder}</span>
            <span class="imp-file-name">${f.name}</span>
            <span class="imp-file-count">${f.plates.length} plates</span>
        </div>`).join('') +
        `<div style="font-size:12px;color:var(--gold);margin-top:8px;font-weight:700;">
            Total: ${impParsedPlates.length} plate numbers found
        </div>`;
}

// ── Run duplicate check via API ──
async function runImportCheck() {
    const vtype = document.getElementById('impVehicleType').value;
    if (!vtype) { showNotification(ICONS.error + ' Please select a vehicle type first', 'error'); return; }
    if (!impParsedPlates.length) { showNotification(ICONS.error + ' No plates loaded yet', 'error'); return; }

    const btn = document.querySelector('.imp-check-btn');
    btn.textContent = ICONS.wait + ' Checking…'; btn.disabled = true;

    try {
        const fd = new FormData();
        fd.append('action', 'check_import');
        fd.append('plates', impParsedPlates.join('\n'));

        const res = await fetch(API, { method: 'POST', body: fd });
        const data = await res.json();

        if (!data.success) { showNotification(ICONS.error + ' ' + escHtml(data.message), 'error'); return; }
        impCleanPlates = data.clean || [];
        renderCheckResults(data);
    } catch(e) {
        showNotification(ICONS.error + ' Error checking duplicates', 'error');
    } finally {
        btn.textContent = ICONS.search + ' Check for Duplicates'; btn.disabled = false;
    }
}

function renderCheckResults(data) {
    // Summary cards
    document.getElementById('impTotal').textContent   = data.total;
    document.getElementById('impClean').textContent   = data.clean.length;
    document.getElementById('impInFile').textContent  = data.in_file.length;
    document.getElementById('impInQueue').textContent = data.in_queue.length;
    document.getElementById('impServed').textContent  = data.in_report.length;

    // Build duplicate rows
    const dups = [];
    const inFileSet  = new Set(data.in_file.map(p => p.toUpperCase()));
    const inQueueSet = new Set(data.in_queue.map(p => p.toUpperCase()));
    const inRepSet   = new Set(data.in_report.map(p => p.toUpperCase()));

    // Collect all flagged plates once each
    const flagged = new Map();
    data.in_file.forEach(p  => { const u = p.toUpperCase(); if(!flagged.has(u)) flagged.set(u, []); flagged.get(u).push('infile'); });
    data.in_queue.forEach(p => { const u = p.toUpperCase(); if(!flagged.has(u)) flagged.set(u, []); flagged.get(u).push('inqueue'); });
    data.in_report.forEach(p=> { const u = p.toUpperCase(); if(!flagged.has(u)) flagged.set(u, []); flagged.get(u).push('served'); });

    const dupSection = document.getElementById('impDupSection');
    if (flagged.size) {
        dupSection.style.display = 'block';
        let i = 1;
        const rows = Array.from(flagged.entries()).map(([plate, reasons]) => {
            const badges = reasons.map(r => {
                if (r === 'infile')  return '<span class="imp-badge imp-badge-infile">Dup in File</span>';
                if (r === 'inqueue') return '<span class="imp-badge imp-badge-inqueue">In Queue</span>';
                return '<span class="imp-badge imp-badge-served">Served Today</span>';
            }).join(' ');
            const detail = reasons.map(r => {
                if (r === 'infile')  return 'Appears more than once in the uploaded file(s)';
                if (r === 'inqueue') return 'Already registered in the active queue';
                return 'Already served today';
            }).join('; ');
            return `<tr>
                <td style="color:#888;font-size:12px;">${i++}</td>
                <td><span class="imp-dup-plate" style="color:${reasons.includes('served') ? '#ef4444' : reasons.includes('inqueue') ? '#facc15' : '#fb923c'}">${plate}</span></td>
                <td>${badges}</td>
                <td style="font-size:12px;color:#aaa;">${detail}</td>
            </tr>`;
        });
        document.getElementById('impDupBody').innerHTML = rows.join('');
    } else {
        dupSection.style.display = 'none';
    }

    // Clean plates
    const cleanSection = document.getElementById('impCleanSection');
    if (impCleanPlates.length) {
        cleanSection.style.display = 'block';
        document.getElementById('impImportCount').textContent = impCleanPlates.length;
        document.getElementById('impCleanList').innerHTML =
            impCleanPlates.map(p => `<span class="imp-clean-chip">${p}</span>`).join('');
    } else {
        cleanSection.style.display = 'none';
    }

    document.getElementById('impResults').style.display = 'block';
}

// ── Do the actual import of clean plates ──
async function doImportClean() {
    if (!impCleanPlates.length) return;
    const vtype = document.getElementById('impVehicleType').value;
    if (!vtype) { showNotification(ICONS.error + ' Select a vehicle type first', 'error'); return; }

    const btn = document.getElementById('impImportBtn');
    btn.disabled = true; btn.textContent = ICONS.wait + ' Importing…';
    const fb = document.getElementById('impImportFb');
    fb.style.display = 'none';

    try {
        const fd = new FormData();
        fd.append('action', 'bulk_import');
        fd.append('plates', impCleanPlates.join('\n'));
        fd.append('vehicle_type_id', vtype);

        const res = await fetch(API, { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            fb.className = 'imp-import-fb ok';
            fb.textContent = ICONS.success + ` Import complete — ${data.imported} added, ${data.skipped} skipped.`;
            fb.style.display = 'block';
            showNotification(ICONS.success + ` Imported ${data.imported} vehicles`, 'success');
            loadStats();
            loadQueue();
            impCleanPlates = [];
            document.getElementById('impImportCount').textContent = '0';
            document.getElementById('impCleanList').innerHTML = '';
            document.getElementById('impCleanSection').style.display = 'none';
        } else {
            fb.className = 'imp-import-fb err';
            fb.textContent = ICONS.error + ' ' + (data.message || 'Import failed');
            fb.style.display = 'block';
        }
    } catch(e) {
        fb.className = 'imp-import-fb err';
        fb.textContent = ICONS.error + ' Connection error';
        fb.style.display = 'block';
    } finally {
        btn.disabled = false;
        btn.innerHTML = `${ICONS.upload} Import <span id="impImportCount">${impCleanPlates.length}</span> Plates into Queue`;
    }
}

function clearImport() {
    impParsedPlates = []; impCleanPlates = []; impFilesData = [];
    document.getElementById('impFileList').style.display = 'none';
    document.getElementById('impFileList').innerHTML = '';
    document.getElementById('impControls').style.display = 'none';
    document.getElementById('impResults').style.display = 'none';
    document.getElementById('impImportFb').style.display = 'none';
    document.getElementById('impFileInput').value = '';
}


// ─── NOTIFICATION ───────────────────────────────────────────────────
function escHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function showNotification(message, type = 'success') {
    const notification = document.getElementById('notification');
    notification.innerHTML = message;
    notification.className = `notification ${type}`;
    notification.style.display = 'block';
    
    setTimeout(() => {
        notification.style.display = 'none';
    }, 5000);
}

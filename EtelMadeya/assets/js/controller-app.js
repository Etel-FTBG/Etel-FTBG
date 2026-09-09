// ═══════════════════════════════════════════════════════════════════
// ETEL MADEYA - CONTROLLER PANEL JAVASCRIPT
// ═══════════════════════════════════════════════════════════════════

let refreshInterval = null;
let clockInterval = null;
let activeTab = 'register';
let selectedVehicleTypeId = null;

// ─── PAGINATION STATE (Active Queue, Register tab) ─────────────────
let currentQueue = [];      // Full "normal" queue from server
let displayedQueue = [];    // currentQueue after the search filter
let queueSearchTerm = '';
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
    startClock();

    // Auto-refresh every 30 seconds — refresh stats plus whatever tab is active
    if (refreshInterval) clearInterval(refreshInterval);
    refreshInterval = setInterval(() => {
        refreshActiveTabData();
    }, 30000);
}

function startClock() {
    const dateEl = document.getElementById('mcHdrDate');
    const timeEl = document.getElementById('mcHdrTime');
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
        if (clockInterval) clearInterval(clockInterval);
        location.reload();
    } catch (error) {
        location.reload();
    }
}

// ─── REFRESH HELPER ─────────────────────────────────────────────────
function refreshActiveTabData() {
    loadStats();
    if (activeTab === 'register') loadQueue();
    else if (activeTab === 'waiting') loadWaitingQueue();
    else if (activeTab === 'vip') loadVipQueue();
}

// ─── TAB SWITCHING ──────────────────────────────────────────────────
function mcSwitchTab(tab, btn) {
    document.querySelectorAll('#mainPanel .mc-tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('#mainPanel .mc-tab').forEach(b => b.classList.remove('active'));
    const panel = document.getElementById('tab-' + tab);
    if (panel) panel.classList.add('active');
    if (btn) btn.classList.add('active');
    activeTab = tab;

    if (tab === 'register') loadQueue();
    else if (tab === 'waiting') loadWaitingQueue();
    else if (tab === 'vip') loadVipQueue();
    else if (tab === 'report') loadReport();
    else if (tab === 'duplicates') initImportVehicleTypes();
}

// ─── DATA LOADING ───────────────────────────────────────────────────
async function loadVehicleTypes() {
    try {
        const response = await fetch(`${API}&action=get_vehicle_types`);
        const data = await response.json();

        if (data.success) {
            vehicleTypes = data.types || [];
            renderVtypeGrid();
            populateVehicleTypeSelects();
            const cfgTypes = document.getElementById('cfgTypesLbl');
            if (cfgTypes) cfgTypes.textContent = vehicleTypes.length + ' active';
        }
    } catch (error) {
        console.error('Error loading vehicle types:', error);
    }
}

function renderVtypeGrid() {
    const grid = document.getElementById('vtypeGrid');
    if (!grid) return;
    if (!vehicleTypes.length) {
        grid.innerHTML = '<div style="grid-column:1/-1;color:var(--mt);font-size:12px;">No vehicle types configured.</div>';
        return;
    }
    // .allowed comes from get_vehicle_types when a controller is logged
    // in (undefined = not restricted, treat as allowed) - types this
    // station isn't assigned today are shown greyed-out rather than
    // hidden, so the controller can see what's unavailable and why.
    grid.innerHTML = vehicleTypes.map(t => {
        const allowed = t.allowed !== false;
        const cls = 'vt-btn' + (allowed ? '' : ' disabled-type');
        const click = allowed ? `onclick="selectVehicleType(${t.id}, this)"` : '';
        const title = allowed ? '' : 'title="Not served at this station today"';
        return `
        <button type="button" class="${cls}" data-id="${t.id}" ${click} ${title}>
            <span class="vt-ic">${escHtml(t.icon || '')}</span>${escHtml(t.name || '')}
        </button>
    `;
    }).join('');
    // Auto-select the first ALLOWED type so registration starts on a
    // usable type rather than one that will just get rejected server-side.
    if (!selectedVehicleTypeId) {
        const firstAllowed = vehicleTypes.find(t => t.allowed !== false);
        if (firstAllowed) {
            selectVehicleType(firstAllowed.id, grid.querySelector(`.vt-btn[data-id="${firstAllowed.id}"]`));
        }
    }
}

function selectVehicleType(id, btn) {
    selectedVehicleTypeId = id;
    document.querySelectorAll('#vtypeGrid .vt-btn').forEach(b => b.classList.remove('sel'));
    if (btn) btn.classList.add('sel');
}

// Client-side mirror of the server's isOddPlate() - last digit odd/even.
// Purely a UX hint; the server re-checks this on submit regardless.
function platePartyFromString(plate) {
    const m = String(plate || '').match(/(\d)(?!.*\d)/);
    if (!m) return null;
    return (parseInt(m[1], 10) % 2 !== 0) ? 'odd' : 'even';
}

function updateRegParityHint() {
    const hintEl = document.getElementById('regParHint');
    if (!hintEl) return;

    const rule = (currentController && currentController.odd_even_rule) || 'all';
    const plate = document.getElementById('regPlate').value;
    const parity = platePartyFromString(plate);

    if (rule === 'all' || !plate || parity === null) {
        hintEl.innerHTML = '&nbsp;';
        return;
    }
    const needLabel = rule === 'odd' ? 'Odd' : 'Even';
    const plateLabel = parity === 'odd' ? 'Odd' : 'Even';
    if (parity === rule) {
        hintEl.innerHTML = `<b style="color:var(--gn)">${plateLabel} plate</b> - accepted today (${needLabel} only)`;
    } else {
        hintEl.innerHTML = `<b style="color:var(--rd)">${plateLabel} plate</b> - this station only accepts ${needLabel} plates today`;
    }
}

function populateVehicleTypeSelects() {
    const selects = ['bulkVehicleType', 'impVehicleType', 'vipVehicleType'];

    selects.forEach(selectId => {
        const select = document.getElementById(selectId);
        if (!select) return;

        select.innerHTML = '<option value="">Select type...</option>';

        vehicleTypes.forEach(type => {
            const option = document.createElement('option');
            option.value = type.id;
            option.textContent = `${type.icon} ${type.name} (${type.name_am || ''})`;
            select.appendChild(option);
        });
    });
}

async function loadStats() {
    try {
        const response = await fetch(`${API}&action=get_stats`);
        const data = await response.json();

        if (!data.success) return;
        const s = data.stats || {};

        document.getElementById('statQueued').textContent = s.total_queued || 0;
        document.getElementById('statServed').textContent = s.total_served_today || 0;
        document.getElementById('statTotalToday').textContent = (parseInt(s.total_queued || 0, 10) + parseInt(s.total_served_today || 0, 10));

        const oddEvenEl = document.getElementById('statOddEven');
        const cfgParityEl = document.getElementById('cfgParityLbl');
        const rule = s.odd_even_rule || '';
        let parityLabel = '—', parityClass = 'both';
        if (rule === 'even') { parityLabel = 'Even Only'; parityClass = 'even'; }
        else if (rule === 'odd') { parityLabel = 'Odd Only'; parityClass = 'odd'; }
        else if (rule === 'both' || rule === 'all' || rule === '') { parityLabel = 'Both'; parityClass = 'both'; }
        if (oddEvenEl) oddEvenEl.textContent = parityClass === 'even' ? 'EVEN' : (parityClass === 'odd' ? 'ODD' : 'BOTH');
        if (cfgParityEl) {
            cfgParityEl.innerHTML = `<span class="cfg-par-badge ${parityClass}">${escHtml(parityLabel)}</span>`;
        }

        const tabWaiting = document.getElementById('tabCountWaiting');
        const tabVip = document.getElementById('tabCountVip');
        if (tabWaiting) tabWaiting.textContent = s.waiting_count ? `(${s.waiting_count})` : '';
        if (tabVip) tabVip.textContent = s.vip_count ? `(${s.vip_count})` : '';

        renderFuelGauge(s);
    } catch (error) {
        console.error('Error loading stats:', error);
    }
}

function renderFuelGauge(s) {
    const remEl = document.getElementById('statFuelRem');
    const barEl = document.getElementById('statFuelBar');
    const pctEl = document.getElementById('statFuelPct');
    if (!remEl || !barEl || !pctEl) return;

    const allocL = parseFloat(s.liters_allocated || 0);
    const usedL = parseFloat(s.liters_used || 0);
    const allocG = parseFloat(s.fuel_allocated || 0);
    const usedG = parseFloat(s.fuel_used || 0);

    let remainLabel = '—', pct = 0, pctLabel = '—%';

    if (allocL > 0) {
        const remain = Math.max(0, allocL - usedL);
        pct = Math.min(100, (usedL / allocL) * 100);
        remainLabel = remain.toFixed(0) + ' L';
        pctLabel = Math.round(pct) + '%';
    } else if (allocG > 0) {
        const remain = Math.max(0, allocG - usedG);
        pct = Math.min(100, (usedG / allocG) * 100);
        remainLabel = remain.toFixed(1) + ' gal';
        pctLabel = Math.round(pct) + '%';
    }

    remEl.textContent = remainLabel;
    barEl.style.width = pct + '%';
    pctEl.textContent = pctLabel;
}

// ─── ACTIVE QUEUE (Register tab, normal queue_type) ─────────────────
async function loadQueue(resetPage) {
    const vehicleTypeId = document.getElementById('vehicleTypeFilter').value;

    if (resetPage === true) {
        currentPage = 1;
    }

    try {
        const response = await fetch(`${API}&action=get_queue&vehicle_type_id=${vehicleTypeId}&queue_type=normal`);
        const data = await response.json();

        if (data.success) {
            currentQueue = data.queue || [];
            const countEl = document.getElementById('queueCount');
            if (countEl) countEl.textContent = currentQueue.length + ' vehicles';
            applyQueueSearch();
            renderCurrentPage();
        }
    } catch (error) {
        console.error('Error loading queue:', error);
    }
}

function applyQueueSearch() {
    const q = queueSearchTerm.trim().toUpperCase();
    displayedQueue = q ? currentQueue.filter(v => (v.plate_number || '').toUpperCase().includes(q)) : currentQueue;
}

function searchQueueRealtime() {
    queueSearchTerm = document.getElementById('queueSearchInput').value || '';
    currentPage = 1;
    applyQueueSearch();
    renderCurrentPage();
}

function clearQueueSearch() {
    document.getElementById('queueSearchInput').value = '';
    queueSearchTerm = '';
    currentPage = 1;
    applyQueueSearch();
    renderCurrentPage();
}

// ─── PAGINATION ─────────────────────────────────────────────────────

// Build batch pages: group queue items by batch_id, each batch = one page
function buildBatchPages() {
    batchPages = [];
    if (displayedQueue.length === 0) return;

    let currentBatch = displayedQueue[0].batch_id;
    let page = [];
    for (const item of displayedQueue) {
        if (item.batch_id !== currentBatch) {
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
    if (batchPages.length > 0) return batchPages.length;
    return Math.max(1, Math.ceil(displayedQueue.length / pageSize));
}

function getPageSlice() {
    if (pageSize === 'all') return displayedQueue;
    if (batchPages.length > 0) {
        const idx = Math.max(0, currentPage - 1);
        return batchPages[idx] || [];
    }
    const start = (currentPage - 1) * pageSize;
    return displayedQueue.slice(start, start + pageSize);
}

function renderCurrentPage() {
    buildBatchPages();

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
    const wrap = document.querySelector('#tab-register .mc-tbl-wrap');
    if (wrap) wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function renderPaginationBar() {
    const bar = document.getElementById('paginationBar');
    const info = document.getElementById('paginationInfo');
    const controls = document.getElementById('paginationControls');
    const total = displayedQueue.length;

    if (total === 0) {
        bar.style.display = 'none';
        return;
    }

    bar.style.display = 'flex';

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

    const totalPages = getTotalPages();
    if (totalPages <= 1) {
        controls.innerHTML = '';
        return;
    }

    controls.innerHTML = buildPageButtons(currentPage, totalPages);
}

function buildPageButtons(current, total) {
    const parts = [];

    parts.push(pageBtn('«', 1, current === 1, 'First page'));
    parts.push(pageBtn('‹', current - 1, current === 1, 'Previous page'));

    const windowSize = 2;
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

function parityBadge(parity) {
    if (parity === 'even') return '<span class="par-badge even">Even</span>';
    if (parity === 'odd') return '<span class="par-badge odd">Odd</span>';
    return '<span style="color:var(--mt)">—</span>';
}

function displayQueue(queue) {
    const tbody = document.getElementById('queueTableBody');

    if (!queue || queue.length === 0) {
        const msg = displayedQueue.length === 0
            ? (currentQueue.length === 0 ? 'No vehicles in queue' : 'No vehicles match your search')
            : 'No vehicles on this page';
        tbody.innerHTML = `<tr><td colspan="7" class="eq"><p>${escHtml(msg)}</p></td></tr>`;
        return;
    }

    tbody.innerHTML = queue.map(v => `
        <tr class="qr">
            <td class="q-pos">#${escHtml(v.display_position || v.position)}</td>
            <td class="q-pl">${escHtml(v.plate_number)}</td>
            <td class="q-tp">${escHtml(v.icon || '')} ${escHtml(v.vehicle_type_name)}</td>
            <td>${parityBadge(v.plate_parity)}</td>
            <td class="q-tk">${escHtml(v.token)}</td>
            <td class="q-tm">${new Date(v.registered_at).toLocaleTimeString()}</td>
            <td>
                <button type="button" onclick="showServeModal(${v.id}, ${jsStr(v.plate_number)}, ${jsStr(v.vehicle_type_name)})" class="srv-btn">${ICONS.serve} Serve</button>
                <button type="button" onclick="moveToVip(${v.id}, this)" class="action-btn action-btn-vip">${ICONS.vip} VIP</button>
                <button type="button" onclick="moveToWaiting(${v.id}, this)" class="action-btn action-btn-wait">${ICONS.wait} Wait</button>
            </td>
        </tr>
    `).join('');
}

// Safely embed a server string as a single-quoted JS string literal inside
// an inline onclick="" attribute (attribute value is itself HTML-escaped).
function jsStr(s) {
    return "'" + String(s == null ? '' : s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
}

// ─── WAITING QUEUE TAB ───────────────────────────────────────────────
async function loadWaitingQueue() {
    const tbody = document.getElementById('waitingTableBody');
    try {
        const response = await fetch(`${API}&action=get_queue&vehicle_type_id=0&queue_type=waiting`);
        const data = await response.json();
        if (!data.success) { tbody.innerHTML = '<tr><td colspan="7" class="eq"><p>Failed to load waiting queue</p></td></tr>'; return; }

        const list = data.queue || [];
        const countEl = document.getElementById('waitingCount');
        if (countEl) countEl.textContent = list.length + ' waiting';

        if (!list.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="eq"><p>No vehicles waiting</p></td></tr>';
            return;
        }

        tbody.innerHTML = list.map((v, i) => `
            <tr class="qr waiting-row">
                <td class="q-pos">#${i + 1}</td>
                <td class="q-pl">${escHtml(v.plate_number)}</td>
                <td class="q-tp">${escHtml(v.icon || '')} ${escHtml(v.vehicle_type_name)}</td>
                <td>${parityBadge(v.plate_parity)}</td>
                <td class="q-tk">${escHtml(v.token)}</td>
                <td class="q-tm">${new Date(v.registered_at).toLocaleTimeString()}</td>
                <td><button type="button" onclick="showServeModal(${v.id}, ${jsStr(v.plate_number)}, ${jsStr(v.vehicle_type_name)})" class="srv-btn">${ICONS.serve} Serve</button></td>
            </tr>
        `).join('');
    } catch (error) {
        tbody.innerHTML = '<tr><td colspan="7" class="eq"><p>Error loading waiting queue</p></td></tr>';
    }
}

// ─── VIP QUEUE TAB ───────────────────────────────────────────────────
async function loadVipQueue() {
    const tbody = document.getElementById('vipTableBody');
    try {
        const response = await fetch(`${API}&action=get_queue&vehicle_type_id=0&queue_type=vip`);
        const data = await response.json();
        if (!data.success) { tbody.innerHTML = '<tr><td colspan="7" class="eq"><p>Failed to load VIP queue</p></td></tr>'; return; }

        const list = data.queue || [];
        const countEl = document.getElementById('vipCount');
        if (countEl) countEl.textContent = list.length + ' VIP';

        if (!list.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="eq"><p>No VIP vehicles</p></td></tr>';
            return;
        }

        tbody.innerHTML = list.map((v, i) => `
            <tr class="qr vip-row">
                <td class="q-pos">#${i + 1}</td>
                <td class="q-pl">${escHtml(v.plate_number)}</td>
                <td class="q-tp">${escHtml(v.icon || '')} ${escHtml(v.vehicle_type_name)}</td>
                <td>${parityBadge(v.plate_parity)}</td>
                <td class="q-tk">${escHtml(v.token)}</td>
                <td class="q-tm">${new Date(v.registered_at).toLocaleTimeString()}</td>
                <td><button type="button" onclick="showServeModal(${v.id}, ${jsStr(v.plate_number)}, ${jsStr(v.vehicle_type_name)})" class="srv-btn">${ICONS.serve} Serve</button></td>
            </tr>
        `).join('');
    } catch (error) {
        tbody.innerHTML = '<tr><td colspan="7" class="eq"><p>Error loading VIP queue</p></td></tr>';
    }
}

async function handleVipRegister() {
    const plateInput = document.getElementById('vipPlate');
    const plate = plateInput.value.trim();
    const vtypeId = document.getElementById('vipVehicleType').value;
    const btn = document.getElementById('vipRegBtn');

    if (!plate) { showVipFb('Enter a plate number', 'err'); return; }
    if (!vtypeId) { showVipFb('Select a vehicle type', 'err'); return; }

    btn.disabled = true;
    const originalLabel = btn.textContent;
    btn.textContent = 'Adding…';

    try {
        const fd = new FormData();
        fd.append('action', 'register');
        fd.append('plate_number', plate);
        fd.append('vehicle_type_id', vtypeId);
        fd.append('driver_name', '');
        fd.append('driver_phone', '');
        fd.append('controller_id', currentController.id);

        const res = await fetch(API, { method: 'POST', body: fd });
        const data = await res.json();

        if (!data.success) { showVipFb(data.message || 'Failed to register vehicle', 'err'); return; }

        const queueId = data.data && data.data.id;
        if (queueId) {
            const fd2 = new FormData();
            fd2.append('action', 'move_to_vip');
            fd2.append('queue_id', queueId);
            const res2 = await fetch(API, { method: 'POST', body: fd2 });
            const data2 = await res2.json();
            if (!data2.success) {
                showVipFb('Registered, but could not move to VIP: ' + (data2.message || ''), 'err');
                loadStats(); loadQueue();
                return;
            }
        }

        showVipFb('Added to VIP queue — Token ' + (data.data ? data.data.token : ''), 'ok');
        plateInput.value = '';
        loadStats();
        loadVipQueue();
    } catch (error) {
        showVipFb('Connection error', 'err');
    } finally {
        btn.disabled = false;
        btn.textContent = originalLabel;
    }
}

function showVipFb(msg, type) {
    const fb = document.getElementById('vipFb');
    const fbMsg = document.getElementById('vipFbMsg');
    fb.className = 'vip-fb show ' + type;
    fbMsg.textContent = msg;
}

// ─── REGISTER VEHICLE (Register tab) ────────────────────────────────
async function handleRegisterVehicle() {
    const plateInput = document.getElementById('regPlate');
    const plate = plateInput.value.trim();
    const btn = document.getElementById('mcRegBtn');

    if (!plate) { showMcFb('Enter a plate number', 'err'); return; }
    if (!selectedVehicleTypeId) { showMcFb('Select a vehicle type', 'err'); return; }

    btn.disabled = true;
    const originalLabel = btn.textContent;
    btn.textContent = 'Registering…';

    try {
        const fd = new FormData();
        fd.append('action', 'register');
        fd.append('plate_number', plate);
        fd.append('vehicle_type_id', selectedVehicleTypeId);
        fd.append('driver_name', document.getElementById('regDriverName').value.trim());
        fd.append('driver_phone', document.getElementById('regDriverPhone').value.trim());
        fd.append('controller_id', currentController.id);

        const res = await fetch(API, { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            showMcFb('Registered successfully', 'ok', data.data ? data.data.token : '');
            plateInput.value = '';
            document.getElementById('regDriverName').value = '';
            document.getElementById('regDriverPhone').value = '';
            loadStats();
            loadQueue();
        } else {
            showMcFb(data.message || 'Failed to register vehicle', 'err');
        }
    } catch (error) {
        showMcFb('Connection error while registering vehicle', 'err');
    } finally {
        btn.disabled = false;
        btn.textContent = originalLabel;
    }
}

function showMcFb(msg, type, token) {
    const fb = document.getElementById('mcFb');
    const msgEl = document.getElementById('mcFbMsg');
    const tkEl = document.getElementById('mcFbTk');
    fb.className = 'mc-fb show ' + type;
    msgEl.textContent = msg;
    tkEl.textContent = token ? ('Token: ' + token) : '';
}

// ─── QUEUE ACTIONS (shared by Register / Waiting / VIP tabs) ────────
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
            refreshActiveTabData();
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
            refreshActiveTabData();
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

    const originalLabel = btn ? btn.innerHTML : null;
    if (btn) { btn.disabled = true; btn.innerHTML = ICONS.wait + ' Moving…'; }

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
            refreshActiveTabData();
        } else {
            showNotification(ICONS.error + ' ' + escHtml(data.message || 'Failed to move vehicle'), 'error');
        }
    } catch (error) {
        showNotification(ICONS.error + ' Error moving vehicle', 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = originalLabel; }
    }
}

async function moveToWaiting(queueId, btn) {
    if (!confirm('Move this vehicle to waiting queue?')) return;

    const originalLabel = btn ? btn.innerHTML : null;
    if (btn) { btn.disabled = true; btn.innerHTML = ICONS.wait + ' Moving…'; }

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
            refreshActiveTabData();
        } else {
            showNotification(ICONS.error + ' ' + escHtml(data.message || 'Failed to move vehicle'), 'error');
        }
    } catch (error) {
        showNotification(ICONS.error + ' Error moving vehicle', 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = originalLabel; }
    }
}

// ─── QUEUE REPORT (served vehicles) ──────────────────────────────────
let reportRows = [];

function initReport() {
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('reportDate').value = today;
}

async function loadReport() {
    const date = document.getElementById('reportDate').value || new Date().toISOString().split('T')[0];
    const tbody = document.getElementById('reportTableBody');
    tbody.innerHTML = '<tr><td colspan="6" class="eq"><p>Loading...</p></td></tr>';
    document.getElementById('reportMatchAlert').style.display = 'none';

    try {
        const res = await fetch(`${API}&action=get_report&date=${date}`);
        const data = await res.json();
        if (!data.success) { tbody.innerHTML = '<tr><td colspan="6" class="eq"><p>Failed to load report.</p></td></tr>'; return; }
        reportRows = data.report || [];
        document.getElementById('reportCount').textContent = reportRows.length + ' served';
        renderReport(reportRows);
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="6" class="eq"><p>Error loading report.</p></td></tr>';
    }
}

function renderReport(rows) {
    const tbody = document.getElementById('reportTableBody');
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="eq"><p>No served vehicles for this date.</p></td></tr>';
        return;
    }
    tbody.innerHTML = rows.map((r, i) => {
        const time = r.served_at ? new Date(r.served_at.replace(' ', 'T')).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : '--';
        const typeName = (r.vehicle_type_icon ? r.vehicle_type_icon + ' ' : '') + (r.vehicle_type_name || r.vehicle_type_id || '--');
        return `<tr class="qr" data-plate="${escHtml((r.plate_number || '').toUpperCase())}">
            <td class="q-pos">${i + 1}</td>
            <td class="q-pl">${escHtml(r.plate_number || '--')}</td>
            <td class="q-tp">${escHtml(typeName)}</td>
            <td>${parityBadge(r.plate_parity)}</td>
            <td class="q-tk">${escHtml(r.token || '--')}</td>
            <td class="q-tm">${escHtml(time)}</td>
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

// ─── DUPLICATES TAB (Import & Check) ─────────────────────────────────
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
    fileListEl.innerHTML = '<div style="font-size:11px;color:var(--mt);margin-bottom:8px;letter-spacing:1px;text-transform:uppercase;">Reading files…</div>';

    for (const file of fileList) {
        if (file.name.toLowerCase().endsWith('.zip')) {
            await parseZip(file);
        } else if (file.name.toLowerCase().endsWith('.txt')) {
            await parseTxt(file, file.name);
        }
    }

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
    } catch (e) {
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
            <span class="imp-file-name">${escHtml(f.name)}</span>
            <span class="imp-file-count">${f.plates.length} plates</span>
        </div>`).join('') +
        `<div style="font-size:12px;color:var(--f);margin-top:8px;font-weight:700;">
            Total: ${impParsedPlates.length} plate numbers found
        </div>`;
}

// ── Run duplicate check via API ──
async function runImportCheck() {
    const vtype = document.getElementById('impVehicleType').value;
    if (!vtype) { showNotification(ICONS.error + ' Please select a vehicle type first', 'error'); return; }
    if (!impParsedPlates.length) { showNotification(ICONS.error + ' No plates loaded yet', 'error'); return; }

    const btn = document.querySelector('.imp-check-btn');
    btn.textContent = 'Checking…'; btn.disabled = true;

    try {
        const fd = new FormData();
        fd.append('action', 'check_import');
        fd.append('plates', impParsedPlates.join('\n'));

        const res = await fetch(API, { method: 'POST', body: fd });
        const data = await res.json();

        if (!data.success) { showNotification(ICONS.error + ' ' + escHtml(data.message), 'error'); return; }
        impCleanPlates = data.clean || [];
        renderCheckResults(data);
    } catch (e) {
        showNotification(ICONS.error + ' Error checking duplicates', 'error');
    } finally {
        btn.textContent = 'Check for Duplicates'; btn.disabled = false;
    }
}

function renderCheckResults(data) {
    document.getElementById('impTotal').textContent   = data.total;
    document.getElementById('impClean').textContent   = data.clean.length;
    document.getElementById('impInFile').textContent  = data.in_file.length;
    document.getElementById('impInQueue').textContent = data.in_queue.length;
    document.getElementById('impServed').textContent  = data.in_report.length;

    const flagged = new Map();
    data.in_file.forEach(p  => { const u = p.toUpperCase(); if (!flagged.has(u)) flagged.set(u, []); flagged.get(u).push('infile'); });
    data.in_queue.forEach(p => { const u = p.toUpperCase(); if (!flagged.has(u)) flagged.set(u, []); flagged.get(u).push('inqueue'); });
    data.in_report.forEach(p => { const u = p.toUpperCase(); if (!flagged.has(u)) flagged.set(u, []); flagged.get(u).push('served'); });

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
                <td style="color:var(--mt);font-size:12px;">${i++}</td>
                <td><span class="imp-dup-plate">${escHtml(plate)}</span></td>
                <td>${badges}</td>
                <td style="font-size:12px;color:var(--mt);">${escHtml(detail)}</td>
            </tr>`;
        });
        document.getElementById('impDupBody').innerHTML = rows.join('');
    } else {
        dupSection.style.display = 'none';
    }

    const cleanSection = document.getElementById('impCleanSection');
    if (impCleanPlates.length) {
        cleanSection.style.display = 'block';
        document.getElementById('impImportCount').textContent = impCleanPlates.length;
        document.getElementById('impCleanList').innerHTML =
            impCleanPlates.map(p => `<span class="imp-clean-chip">${escHtml(p)}</span>`).join('');
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
    btn.disabled = true; btn.textContent = 'Importing…';
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
            fb.textContent = `Import complete — ${data.imported} added, ${data.skipped} skipped.`;
            fb.style.display = 'block';
            showNotification(ICONS.success + ` Imported ${data.imported} vehicles`, 'success');
            refreshActiveTabData();
            impCleanPlates = [];
            document.getElementById('impImportCount').textContent = '0';
            document.getElementById('impCleanList').innerHTML = '';
            document.getElementById('impCleanSection').style.display = 'none';
        } else {
            fb.className = 'imp-import-fb err';
            fb.textContent = data.message || 'Import failed';
            fb.style.display = 'block';
        }
    } catch (e) {
        fb.className = 'imp-import-fb err';
        fb.textContent = 'Connection error';
        fb.style.display = 'block';
    } finally {
        btn.disabled = false;
        btn.innerHTML = `Import <span id="impImportCount">${impCleanPlates.length}</span> Plates into Queue`;
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
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function showNotification(message, type = 'success') {
    const notification = document.getElementById('notification');
    notification.innerHTML = message;
    notification.className = `notification mc-toast ${type}`;
    notification.style.display = 'flex';

    setTimeout(() => {
        notification.style.display = 'none';
    }, 5000);
}

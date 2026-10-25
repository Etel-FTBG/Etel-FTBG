// js/admin/admin-growth-charts.js
// Trips & Drivers growth chart for the Rides admin panel — daily / weekly /
// monthly / yearly views, fed by the admin_ride_growth backend action.
// Fetched once per panel visit; period switching just re-renders from the
// cached response (no refetch), since the server already returns all four
// granularities together.

let rideGrowthChart = null;
let _growthCache = null;
let _growthPeriod = 'daily';
let _growthChartType = 'combo'; // 'combo' | 'bar' | 'line'

async function growthLoad() {
    const titleEl = document.getElementById('growth-chart-title');
    // Live driver map (used by Phone Dispatch) was previously only ever
    // initialized inside this function's growth-chart success callback
    // below - meaning any role without analytics.view (e.g. Dispatcher,
    // who has no business need for the growth chart but absolutely needs
    // the live map to actually dispatch) got a permanently stuck
    // "Loading map..." with no way to fix it, unrelated to any capability
    // gating. Calling it here, unconditionally, decouples the two.
    pdInitMapIfNeeded();
    // Requires analytics.view server-side (admin_ride_growth). Awaits
    // the shared caps promise (admin-roles.js) instead of reading
    // window.ETEL_CAPS directly - Rides is the default landing tab, so
    // this can run before that async capabilities fetch resolves, and
    // reading the plain variable raced it and saw null every time.
    const caps = window.ETEL_CAPS || await window.ETEL_CAPS_PROMISE;
    if (caps.indexOf('*') === -1 && caps.indexOf('analytics.view') === -1) {
        if (titleEl) titleEl.textContent = '📈 Trips & Drivers Growth — unavailable';
        return;
    }
    const token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
    const apiUrl = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
    fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
        body: JSON.stringify({ action: 'admin_ride_growth' }),
    })
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                if (titleEl) titleEl.textContent = '📈 Trips & Drivers Growth — unavailable';
                return;
            }
            _growthCache = d.data;
            const totals = _growthCache.totals || {};
            const tEl = document.getElementById('growth-total-trips');
            const dEl = document.getElementById('growth-total-drivers');
            const gEl = document.getElementById('growth-gross-fares');
            const cEl = document.getElementById('growth-projected-commission');
            if (tEl) tEl.textContent = (totals.trips_all_time || 0).toLocaleString();
            if (dEl) dEl.textContent = (totals.drivers_all_time || 0).toLocaleString();
            if (gEl) gEl.textContent = 'ETB ' + (totals.gross_fares_all_time || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            if (cEl) cEl.textContent = 'ETB ' + (totals.projected_commission_5pct || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            growthRenderChart(_growthPeriod);
            growthLoadDriversForTopup();
            growthLoadPendingTopups();
            growthLoadSuspendedDrivers();
            pdRenderVtypeCards();
        })
        .catch(() => {
            if (titleEl) titleEl.textContent = '📈 Trips & Drivers Growth — failed to load';
        });
}

function growthSetPeriod(period, btnEl) {
    _growthPeriod = period;
    document.querySelectorAll('#growth-btn-daily, #growth-btn-weekly, #growth-btn-monthly, #growth-btn-yearly, #growth-btn-custom')
        .forEach(b => b.classList.remove('active'));
    if (btnEl) btnEl.classList.add('active');
    const labelMap = { daily: 'Daily', weekly: 'Weekly', monthly: 'Monthly', yearly: 'Yearly' };
    const titleEl = document.getElementById('growth-chart-title');
    if (titleEl) titleEl.textContent = '📈 Trips & Drivers Growth — ' + labelMap[period];
    if (_growthCache) growthRenderChart(period);
}

// 📅 Custom date range: unlike the four fixed windows above (which all come
// back together in the initial growthLoad() fetch), an arbitrary range has
// to be requested specifically - there's no way to pre-aggregate every
// possible range a user might pick. So this makes its own dedicated
// request, passing date_from/date_to, and caches the result the same way
// as the rest of _growthCache so growthRenderChart('custom') can read it
// like any other period.
function growthApplyCustomRange() {
    const fromEl = document.getElementById('growth-date-from');
    const toEl = document.getElementById('growth-date-to');
    const from = fromEl ? fromEl.value : '';
    const to = toEl ? toEl.value : '';
    if (!from || !to) {
        alert('Pick both a from and to date first.');
        return;
    }
    if (from > to) {
        alert('The "from" date must be before the "to" date.');
        return;
    }
    const titleEl = document.getElementById('growth-chart-title');
    if (titleEl) titleEl.textContent = '📈 Trips & Drivers Growth — Loading ' + from + ' to ' + to + '…';

    const token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
    const apiUrl = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
    fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
        body: JSON.stringify({ action: 'admin_ride_growth', date_from: from, date_to: to }),
    })
        .then(r => r.json())
        .then(d => {
            if (!d.success || !_growthCache) {
                if (titleEl) titleEl.textContent = '📈 Trips & Drivers Growth — custom range unavailable';
                return;
            }
            // Merge just the custom-range keys into the existing cache,
            // keeping the daily/weekly/monthly/yearly data already loaded.
            _growthCache.trips_custom = d.data.trips_custom;
            _growthCache.drivers_custom = d.data.drivers_custom;
            _growthPeriod = 'custom';
            document.querySelectorAll('#growth-btn-daily, #growth-btn-weekly, #growth-btn-monthly, #growth-btn-yearly, #growth-btn-custom')
                .forEach(b => b.classList.remove('active'));
            const customBtn = document.getElementById('growth-btn-custom');
            if (customBtn) customBtn.classList.add('active');
            if (titleEl) titleEl.textContent = '📈 Trips & Drivers Growth — ' + from + ' to ' + to;
            growthRenderChart('custom');
        })
        .catch(() => {
            if (titleEl) titleEl.textContent = '📈 Trips & Drivers Growth — custom range failed to load';
        });
}

function growthClearCustomRange() {
    const fromEl = document.getElementById('growth-date-from');
    const toEl = document.getElementById('growth-date-to');
    if (fromEl) fromEl.value = '';
    if (toEl) toEl.value = '';
    growthSetPeriod('daily', document.getElementById('growth-btn-daily'));
}

// One-time backfill: recomputes commission_owed for every driver from
// their actual historical completed cash rides. Safe to run more than
// once (it SETS the value from source data each time, not increment).
// Wallet rides are deliberately untouched server-side - see the PHP
// action's own comment for why. Confirms first since this is a real
// financial action, not just a display refresh.
function growthBackfillCashCommission() {
    if (!confirm('This recalculates the 5% commission owed on all historical CASH rides and updates each driver\'s balance. Wallet rides are not affected. Continue?')) {
        return;
    }
    const token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
    const apiUrl = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
    fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
        body: JSON.stringify({ action: 'admin_backfill_cash_commission' }),
    })
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                alert('Backfill failed: ' + (d.message || 'unknown error'));
                return;
            }
            alert('Done. Updated ' + d.data.updated_drivers + ' driver(s), total commission tracked: ETB '
                + d.data.total_commission_backfilled.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
            growthLoad();
        })
        .catch(() => alert('Backfill failed: network error.'));
}

// Driver search-and-pick for the manual top-up form. Replaces the old
// "load every driver into a <select>" approach (which didn't scale and
// gave admins nothing to filter by) with a debounced type-ahead against
// admin_get_drivers's own 'q' param — matches name or phone, digit-
// normalized server-side. The chosen driver's id/name are kept in
// _growthTopupSelectedDriver rather than in the DOM, since the search
// input's value is display text (name + phone), not the id.
let _growthTopupSelectedDriver = null; // { id, name, phone }
let _growthDriverSearchTimer = null;
let _growthDriverSearchSeq = 0;

function growthLoadDriversForTopup() {
    // Kept as a no-op entry point (called from growthLoad()) so nothing
    // upstream needs to change — the search box loads results on demand
    // instead of pre-fetching the full driver list up front.
    _growthTopupSelectedDriver = null;
}

function growthDriverSearchInput() {
    const input = document.getElementById('growth-topup-driver-search');
    const results = document.getElementById('growth-topup-driver-results');
    if (!input || !results) return;
    // Typing again after a driver was already picked means they're
    // choosing someone else — clear the stale selection so Top Up can't
    // silently fire against the previous driver.
    _growthTopupSelectedDriver = null;
    const q = input.value.trim();
    clearTimeout(_growthDriverSearchTimer);
    if (!q) {
        results.style.display = 'none';
        results.innerHTML = '';
        return;
    }
    _growthDriverSearchTimer = setTimeout(() => _growthDriverSearchRun(q), 300);
}

function _growthDriverSearchRun(q) {
    const results = document.getElementById('growth-topup-driver-results');
    if (!results) return;
    const seq = ++_growthDriverSearchSeq;
    results.style.display = 'block';
    results.innerHTML = '<div style="padding:10px 12px;font-size:12px;color:#9A9AAE">Searching…</div>';
    const token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
    const apiUrl = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
    fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
        body: JSON.stringify({ action: 'admin_get_drivers', q }),
    })
        .then(r => r.json())
        .then(d => {
            // A slower, earlier request can resolve after a newer one —
            // ignore it so results don't flicker back to a stale query.
            if (seq !== _growthDriverSearchSeq) return;
            if (!d.success || !d.data || !d.data.drivers || !d.data.drivers.length) {
                results.innerHTML = '<div style="padding:10px 12px;font-size:12px;color:#9A9AAE">No drivers matched "' + q.replace(/</g, '&lt;') + '"</div>';
                return;
            }
            results.innerHTML = d.data.drivers.slice(0, 20)
                // Show the account with real activity (trips completed)
                // first - when two accounts share a phone/name, this is
                // almost always the one that's actually being used, and
                // burying it below a phantom/duplicate 0-trip entry is
                // exactly what caused a top-up to land on the wrong
                // account before.
                .sort((a, b) => (parseInt(b.total_rides || 0, 10)) - (parseInt(a.total_rides || 0, 10)))
                .map(dr => {
                const name = (dr.name || ('Driver #' + dr.id)).replace(/</g, '&lt;');
                const phone = (dr.phone || '').replace(/</g, '&lt;');
                const bal = parseFloat(dr.etel_money_balance || 0).toFixed(2);
                const trips = parseInt(dr.total_rides || 0, 10);
                return `<div class="growth-driver-result" data-id="${dr.id}" data-name="${name}" data-phone="${phone}"
                    style="padding:8px 12px;font-size:12.5px;color:#EDEDF5;cursor:pointer;border-bottom:1px solid rgba(255,255,255,.06)"
                    onmousedown="growthPickDriver(this)">
                    <div style="font-weight:700">${name} <span style="font-weight:400;color:#6B6455;font-size:10.5px">· id ${dr.id}</span></div>
                    <div style="font-size:11px;color:#9A9AAE">${phone} · ${trips} trip${trips === 1 ? '' : 's'} · ኢE ETB ${bal}</div>
                </div>`;
            }).join('');
            if (d.data.drivers.length > 1) {
                results.innerHTML += '<div style="padding:8px 12px;font-size:10.5px;color:#E5C76B;background:rgba(212,175,55,.08)">⚠ Multiple accounts match — check trip count and id before picking one.</div>';
            }
        })
        .catch(() => {
            if (seq !== _growthDriverSearchSeq) return;
            results.innerHTML = '<div style="padding:10px 12px;font-size:12px;color:#E24B4A">Search failed — network error</div>';
        });
}

// onmousedown (not onclick) so this fires before the search input's own
// blur handler could otherwise hide the results list first.
function growthPickDriver(el) {
    const id = el.getAttribute('data-id');
    const name = el.getAttribute('data-name');
    const phone = el.getAttribute('data-phone');
    _growthTopupSelectedDriver = { id, name, phone };
    const input = document.getElementById('growth-topup-driver-search');
    const results = document.getElementById('growth-topup-driver-results');
    if (input) input.value = name + (phone ? ' — ' + phone : '');
    if (results) { results.style.display = 'none'; results.innerHTML = ''; }
}

document.addEventListener('click', (e) => {
    const picker = document.getElementById('growth-topup-driver-picker');
    const results = document.getElementById('growth-topup-driver-results');
    if (picker && results && !picker.contains(e.target)) {
        results.style.display = 'none';
    }
});

function growthTopupDriver() {
    const amtEl = document.getElementById('growth-topup-amount');
    const noteEl = document.getElementById('growth-topup-note');
    const driverId = _growthTopupSelectedDriver ? _growthTopupSelectedDriver.id : '';
    const amount = amtEl ? parseFloat(amtEl.value) : NaN;
    if (!driverId) { alert('Search for and select a driver first.'); return; }
    if (!amount || amount <= 0) { alert('Enter a valid amount.'); return; }
    if (!confirm('Add ETB ' + amount.toFixed(2) + ' to ' + (_growthTopupSelectedDriver.name || 'this driver') + '\'s ኢE balance? This is for cash/transfer received directly, not an Etel Pay payment.')) {
        return;
    }
    const token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
    const apiUrl = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
    fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
        body: JSON.stringify({ action: 'admin_topup_etel_money', driver_id: driverId, amount, note: noteEl ? noteEl.value : '' }),
    })
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                alert('Top-up failed: ' + (d.message || 'unknown error'));
                return;
            }
            alert('Done. New balance: ETB ' + d.data.etel_money_balance.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
            const searchEl = document.getElementById('growth-topup-driver-search');
            if (amtEl) amtEl.value = '';
            if (noteEl) noteEl.value = '';
            if (searchEl) searchEl.value = '';
            _growthTopupSelectedDriver = null;
        })
        .catch(() => alert('Top-up failed: network error.'));
}

// Driver-initiated Etel Pay top-ups sit here awaiting explicit admin
// approval before they touch etel_money_balance - see the PHP action's
// own comment for why a verified Etel Pay transaction isn't
// automatically the same thing as an approved credit to this specific
// ledger. Loaded alongside the driver dropdown on every growthLoad().
function growthLoadPendingTopups() {
    const wrap = document.getElementById('growth-pending-topups-wrap');
    const list = document.getElementById('growth-pending-topups-list');
    if (!wrap || !list) return;
    const token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
    const apiUrl = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
    fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
        body: JSON.stringify({ action: 'admin_get_pending_etel_topups' }),
    })
        .then(r => r.json())
        .then(d => {
            if (!d.success || !d.data || !d.data.pending || !d.data.pending.length) {
                wrap.style.display = 'none';
                list.innerHTML = '';
                return;
            }
            wrap.style.display = 'block';
            const sourceLabel = { etelpay: '🅴 ኢE Pay', manual_receipt: '🧾 Bank / Cash' };
            list.innerHTML = d.data.pending.map(p => {
                const isManual = p.source === 'manual_receipt';
                const refLine = isManual
                    ? (p.note ? `<span style="color:#6B6455;font-size:10px">note: ${String(p.note).replace(/</g, '&lt;')}</span>` : '')
                    : `<span style="color:#6B6455;font-size:10px">tx: ${p.tx_ref}</span>`;
                const photo = p.receipt_image_url
                    ? `<a href="${p.receipt_image_url}" target="_blank" title="View receipt" style="display:inline-block">
                        <img src="${p.receipt_image_url}" alt="receipt" style="width:34px;height:34px;object-fit:cover;border-radius:6px;border:1px solid rgba(212,175,55,.4)">
                       </a>`
                    : '';
                return `
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:8px 10px;border-radius:8px;background:rgba(212,175,55,.08);border:1px solid rgba(212,175,55,.25);margin-bottom:6px;font-size:12px">
                    ${photo}
                    <span style="color:#EDEDF5;font-weight:700">${p.driver_name || ('Driver #' + p.user_id)}</span>
                    <span style="color:#9A9AAE">${p.driver_phone || ''}</span>
                    <span style="color:#E5C76B;font-weight:700">ETB ${parseFloat(p.amount).toFixed(2)}</span>
                    <span style="color:${isManual ? '#D4AF37' : '#4ECDC4'};font-size:10.5px;font-weight:700">${sourceLabel[p.source] || p.source}</span>
                    ${refLine}
                    <span style="color:#6B6455;font-size:10px">${p.created_at}</span>
                    <button style="padding:4px 10px;border-radius:6px;border:none;background:rgba(61,220,132,.2);color:#3DDC84;font-size:11px;cursor:pointer" onclick="growthApproveTopup(${p.id})">Approve</button>
                    <button style="padding:4px 10px;border-radius:6px;border:none;background:rgba(226,75,74,.15);color:#E24B4A;font-size:11px;cursor:pointer" onclick="growthRejectTopup(${p.id})">Reject</button>
                </div>`;
            }).join('');
        })
        .catch(() => { wrap.style.display = 'none'; });
}

function growthLoadSuspendedDrivers() {
    const wrap = document.getElementById('suspended-drivers-wrap');
    const list = document.getElementById('suspended-drivers-list');
    if (!wrap || !list) return;
    const token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
    const apiUrl = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
    fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
        body: JSON.stringify({ action: 'admin_get_suspended_drivers' }),
    })
        .then(r => r.json())
        .then(d => {
            if (!d.success || !d.data || !d.data.suspended || !d.data.suspended.length) {
                wrap.style.display = 'none';
                list.innerHTML = '';
                return;
            }
            wrap.style.display = 'block';
            list.innerHTML = d.data.suspended.map(s => `
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:8px 10px;border-radius:8px;background:rgba(226,75,74,.08);border:1px solid rgba(226,75,74,.3);margin-bottom:6px;font-size:12px">
                    <span style="color:#EDEDF5;font-weight:700">${s.name || ('Driver #' + s.user_id)}</span>
                    <span style="color:#9A9AAE">${s.phone || ''}</span>
                    <span style="color:#FF8A80;font-size:11px">${(s.suspended_reason || '').replace(/</g, '&lt;')}</span>
                    ${s.latest_issue_id ? `<span style="color:#6B6455;font-size:10px">issue #${s.latest_issue_id}</span>` : ''}
                    <button style="padding:4px 10px;border-radius:6px;border:none;background:rgba(61,220,132,.2);color:#3DDC84;font-size:11px;cursor:pointer" onclick="growthResolveSuspension(${s.user_id},'reinstate')">Reinstate</button>
                    <button style="padding:4px 10px;border-radius:6px;border:none;background:rgba(226,75,74,.2);color:#E24B4A;font-size:11px;cursor:pointer" onclick="growthResolveSuspension(${s.user_id},'remove')">Confirm removal</button>
                </div>`).join('');
        })
        .catch(() => { wrap.style.display = 'none'; });
}

function growthResolveSuspension(driverId, decision) {
    const verb = decision === 'reinstate' ? 'reinstate this driver' : 'confirm removal (keep them suspended)';
    if (!confirm('Are you sure you want to ' + verb + '?')) return;
    const token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
    const apiUrl = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
    fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
        body: JSON.stringify({ action: 'admin_resolve_suspension', driver_id: driverId, decision }),
    })
        .then(r => r.json())
        .then(d => {
            if (!d.success) { alert('Failed: ' + (d.message || 'unknown error')); return; }
            growthLoadSuspendedDrivers();
        })
        .catch(() => alert('Failed: network error.'));
}

const PD_VTYPES = [
    { id: 'bajaj', label: 'Bajaj', icon: '🛺' },
    { id: 'qute', label: 'Qute', icon: '🚙' },
    { id: 'moto', label: 'Moto', icon: '🛵' },
    { id: 'car', label: 'Car', icon: '🚗' },
    { id: 'minibus', label: 'Minibus', icon: '🚐' },
];
let _pdMode = 'open';

let _pdMap = null;
let _pdMarkers = [];
let _pdCustomerMarker = null;
let _pdGeocoder = null;
let _pdRefreshTimer = null;
let _pdMapInitStarted = false;
let _pdAddressDebounce = null;
let _pdLastPoint = null;

function pdInitMapIfNeeded() {
    if (_pdMapInitStarted) return;
    _pdMapInitStarted = true;
    if (typeof loadGoogleMaps !== 'function') {
        const loadingEl = document.getElementById('pd-map-loading');
        if (loadingEl) loadingEl.textContent = 'Map unavailable on this page.';
        return;
    }
    window.pdMapCallback = function () {
        const el = document.getElementById('pd-map');
        const loadingEl = document.getElementById('pd-map-loading');
        if (!el || typeof google === 'undefined' || !google.maps) return;
        _pdMap = new google.maps.Map(el, {
            center: { lat: 7.0621, lng: 38.4761 }, // Hawassa
            zoom: 13,
            disableDefaultUI: true,
            zoomControl: true,
        });
        if (loadingEl) loadingEl.style.display = 'none';
        _pdGeocoder = new google.maps.Geocoder();
        pdRefreshDrivers();
        _pdRefreshTimer = setInterval(() => pdRefreshDrivers(_pdLastPoint), 20000);
    };
    loadGoogleMaps('places', 'pdMapCallback');
}

function pdRefreshDrivers(point) {
    if (!_pdMap) return;
    const token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
    const apiUrl = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
    const body = { action: 'admin_get_live_drivers' };
    if (point) { body.lat = point.lat; body.lng = point.lng; }
    fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
        body: JSON.stringify(body),
    })
        .then(r => r.json())
        .then(d => {
            if (!d.success || !d.data) return;
            _pdMarkers.forEach(m => m.setMap(null));
            _pdMarkers = [];
            const drivers = d.data.drivers || [];
            const countEl = document.getElementById('pd-driver-count');
            drivers.forEach(dr => {
                const nearby = point ? dr.distance_km <= 8 : true;
                // the vehicle's own picture when there is one for its type, else the dot
                const art = (window.EtelVehicleArt && EtelVehicleArt.marker) ? EtelVehicleArt.marker(dr.vehicle_type, { size: 48 }) : null;
                const marker = new google.maps.Marker({
                    position: { lat: dr.lat, lng: dr.lng },
                    map: _pdMap,
                    title: (dr.name || 'Driver') + ' — ' + (dr.vehicle_type || '') + (dr.distance_km != null ? ' · ' + dr.distance_km + ' km' : ''),
                    opacity: nearby ? 1 : 0.35,
                    icon: art || {
                        path: google.maps.SymbolPath.CIRCLE,
                        fillColor: nearby ? '#4ECDC4' : '#6B6455',
                        fillOpacity: 1,
                        strokeColor: '#fff',
                        strokeWeight: 2,
                        scale: 8,
                    },
                });
                _pdMarkers.push(marker);
            });
            if (countEl) {
                if (point) {
                    const nearCount = drivers.filter(dr => dr.distance_km <= 8).length;
                    countEl.textContent = `📍 ${nearCount} driver${nearCount === 1 ? '' : 's'} within 8km of this address (${drivers.length} online total)`;
                } else {
                    countEl.textContent = `📍 Showing all ${drivers.length} online driver${drivers.length === 1 ? '' : 's'}`;
                }
            }
        })
        .catch(() => {});
}

function pdAddressInput() {
    clearTimeout(_pdAddressDebounce);
    const val = document.getElementById('pd-address').value.trim();
    if (!val) {
        _pdLastPoint = null;
        if (_pdCustomerMarker) { _pdCustomerMarker.setMap(null); _pdCustomerMarker = null; }
        pdRefreshDrivers();
        return;
    }
    _pdAddressDebounce = setTimeout(() => {
        if (!_pdGeocoder) { pdInitMapIfNeeded(); return; }
        _pdGeocoder.geocode({ address: val + ', Hawassa, Ethiopia', region: 'ET' }, (results, status) => {
            if (status !== 'OK' || !results || !results[0]) return;
            const loc = results[0].geometry.location;
            const point = { lat: loc.lat(), lng: loc.lng() };
            _pdLastPoint = point;
            if (_pdMap) { _pdMap.panTo(point); _pdMap.setZoom(14); }
            if (_pdCustomerMarker) _pdCustomerMarker.setMap(null);
            _pdCustomerMarker = new google.maps.Marker({
                position: point,
                map: _pdMap,
                title: 'Customer location',
                icon: { path: google.maps.SymbolPath.BACKWARD_CLOSED_ARROW, fillColor: '#E5C76B', fillOpacity: 1, strokeColor: '#1a1a2e', strokeWeight: 1.5, scale: 7, rotation: 180 },
            });
            const noteEl = document.getElementById('pd-note');
            if (noteEl && !noteEl.value) noteEl.value = results[0].formatted_address;
            pdRefreshDrivers(point);
        });
    }, 600);
}

function pdRenderVtypeCards() {
    const wrap = document.getElementById('pd-vtype-cards');
    if (!wrap || wrap.dataset.built) return;
    wrap.dataset.built = '1';
    const pic = v => (window.EtelVehicleArt && EtelVehicleArt.ready(v.id)) ? EtelVehicleArt.html(v.id, 64, 44) : v.icon;
    wrap.innerHTML = PD_VTYPES.map((v, i) => `
        <div class="pd-vtype-card" data-vtype="${v.id}" onclick="pdSelectVtype('${v.id}')"
            style="text-align:center;padding:10px 4px;border-radius:10px;cursor:pointer;border:1px solid ${i === 0 ? 'rgba(78,205,196,.55)' : 'rgba(255,255,255,.12)'};background:${i === 0 ? 'rgba(78,205,196,.14)' : 'rgba(255,255,255,.03)'}">
            <div class="pd-vtype-icon" style="font-size:20px;height:44px;display:flex;align-items:center;justify-content:center">${pic(v)}</div>
            <div style="font-size:10.5px;font-weight:700;color:${i === 0 ? '#7FE8DC' : '#9A9AAE'};margin-top:3px">${v.label}</div>
        </div>`).join('');
}

// The 3D vehicle pictures (js/etel-vehicle-art.js) load after the page; swap them in for the emoji as they arrive.
function pdPaintVtypeArt() {
    document.querySelectorAll('.pd-vtype-card').forEach(card => {
        const id = card.getAttribute('data-vtype'), v = PD_VTYPES.find(x => x.id === id), icon = card.querySelector('.pd-vtype-icon');
        if (!v || !icon) return;
        icon.innerHTML = (window.EtelVehicleArt && EtelVehicleArt.ready(id)) ? EtelVehicleArt.html(id, 64, 44) : v.icon;
    });
}
let _pdArtTimer = null;
document.addEventListener('etel:vehicle-art', () => {
    pdPaintVtypeArt();
    // markers drawn before the pictures arrived are dots: redraw them once the pictures are in
    clearTimeout(_pdArtTimer);
    _pdArtTimer = setTimeout(() => { if (typeof _pdMap !== 'undefined' && _pdMap) pdRefreshDrivers(_pdLastPoint || undefined); }, 700);
});

function pdSelectVtype(id) {
    document.getElementById('pd-vtype').value = id;
    document.querySelectorAll('.pd-vtype-card').forEach(card => {
        const active = card.getAttribute('data-vtype') === id;
        card.style.border = active ? '1px solid rgba(78,205,196,.55)' : '1px solid rgba(255,255,255,.12)';
        card.style.background = active ? 'rgba(78,205,196,.14)' : 'rgba(255,255,255,.03)';
        card.querySelector('div:last-child').style.color = active ? '#7FE8DC' : '#9A9AAE';
    });
}

function pdSetMode(mode) {
    _pdMode = mode;
    const openBtn = document.getElementById('pd-mode-open');
    const fixedBtn = document.getElementById('pd-mode-fixed');
    const kmWrap = document.getElementById('pd-km-wrap');
    if (mode === 'open') {
        openBtn.style.border = '1px solid rgba(78,205,196,.55)'; openBtn.style.background = 'rgba(78,205,196,.16)'; openBtn.style.color = '#7FE8DC';
        fixedBtn.style.border = '1px solid rgba(255,255,255,.14)'; fixedBtn.style.background = 'rgba(255,255,255,.03)'; fixedBtn.style.color = '#9A9AAE';
        kmWrap.style.display = 'none';
        document.getElementById('pd-km').value = '';
    } else {
        fixedBtn.style.border = '1px solid rgba(78,205,196,.55)'; fixedBtn.style.background = 'rgba(78,205,196,.16)'; fixedBtn.style.color = '#7FE8DC';
        openBtn.style.border = '1px solid rgba(255,255,255,.14)'; openBtn.style.background = 'rgba(255,255,255,.03)'; openBtn.style.color = '#9A9AAE';
        kmWrap.style.display = 'block';
    }
}

function pdCheckCaller() {
    const el = document.getElementById('pd-phone');
    const statusEl = document.getElementById('pd-caller-status');
    const digits = el.value.replace(/[^0-9]/g, '');
    if (!digits) { statusEl.textContent = 'Phone number'; statusEl.style.color = '#6B6455'; return; }
    if (digits.length >= 9) { statusEl.textContent = '✓ Looks valid'; statusEl.style.color = '#3DDC84'; }
    else { statusEl.textContent = digits.length + '/9 digits'; statusEl.style.color = '#E5C76B'; }
}

function phoneDispatchBook() {
    const phone = document.getElementById('pd-phone').value.trim();
    const name = document.getElementById('pd-name').value.trim();
    const vtype = document.getElementById('pd-vtype').value;
    const kmRaw = document.getElementById('pd-km').value;
    const note = document.getElementById('pd-note').value.trim();
    const statusEl = document.getElementById('pd-status');
    const btn = document.getElementById('pd-submit-btn');
    if (!phone) { statusEl.textContent = '⚠ Enter the caller\'s phone number.'; statusEl.style.color = '#FF8A80'; return; }
    if (_pdMode === 'fixed' && (!kmRaw || parseFloat(kmRaw) <= 0)) { statusEl.textContent = '⚠ Enter a distance, or switch to Open Trip.'; statusEl.style.color = '#FF8A80'; return; }
    const km = _pdMode === 'fixed' ? parseFloat(kmRaw) : 0;
    statusEl.textContent = 'Creating ride…';
    statusEl.style.color = '#E5C76B';
    if (btn) { btn.disabled = true; btn.style.opacity = '.6'; }
    const token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
    const apiUrl = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
    fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
        body: JSON.stringify({
            action: 'admin_phone_book_ride',
            phone, name, vehicle_type: vtype,
            km: km || 0, open: _pdMode === 'open' ? 1 : 0,
            pickup_note: note,
        }),
    })
        .then(r => r.json())
        .then(d => {
            if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
            if (!d.success) { statusEl.textContent = '⚠ ' + (d.message || 'Failed'); statusEl.style.color = '#FF8A80'; return; }
            statusEl.innerHTML = '✅ Ride #' + d.data.ride_id + ' created — searching for a driver.';
            statusEl.style.color = '#3DDC84';
            document.getElementById('pd-phone').value = '';
            document.getElementById('pd-name').value = '';
            document.getElementById('pd-note').value = '';
            document.getElementById('pd-address').value = '';
            _pdLastPoint = null;
            if (_pdCustomerMarker) { _pdCustomerMarker.setMap(null); _pdCustomerMarker = null; }
            pdRefreshDrivers();
            pdCheckCaller();
            pdSetMode('open');
            pdSelectVtype('bajaj');
            if (typeof ridesLoad === 'function') ridesLoad();
        })
        .catch(() => { if (btn) { btn.disabled = false; btn.style.opacity = '1'; } statusEl.textContent = '⚠ Network error.'; statusEl.style.color = '#FF8A80'; });
}

function growthApproveTopup(id) {
    if (!confirm('Approve this top-up and credit the driver\'s ኢE balance now?')) return;
    const token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
    const apiUrl = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
    fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
        body: JSON.stringify({ action: 'admin_approve_etel_topup', id }),
    })
        .then(r => r.json())
        .then(d => {
            if (!d.success) { alert('Approve failed: ' + (d.message || 'unknown error')); return; }
            alert('Approved. New balance: ETB ' + d.data.etel_money_balance.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
            growthLoadPendingTopups();
            growthLoadDriversForTopup();
        })
        .catch(() => alert('Approve failed: network error.'));
}

function growthRejectTopup(id) {
    const reason = prompt('Reason for rejecting this top-up (optional):') || '';
    const token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
    const apiUrl = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
    fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
        body: JSON.stringify({ action: 'admin_reject_etel_topup', id, reason }),
    })
        .then(r => r.json())
        .then(d => {
            if (!d.success) { alert('Reject failed: ' + (d.message || 'unknown error')); return; }
            growthLoadPendingTopups();
        })
        .catch(() => alert('Reject failed: network error.'));
}

// Trips and drivers are aggregated independently server-side (GROUP BY only
// returns periods that actually have rows), so a week/month/year with trips
// but zero new driver signups (or vice versa) won't appear in both arrays.
// Merge on the period label and zero-fill whichever side is missing it, so
// both lines share the same x-axis instead of silently misaligning.
function _growthAlign(tripsArr, driversArr) {
    const tMap = {}, dMap = {};
    (tripsArr || []).forEach(r => { tMap[r.period] = parseInt(r.count, 10) || 0; });
    (driversArr || []).forEach(r => { dMap[r.period] = parseInt(r.count, 10) || 0; });
    const labels = Array.from(new Set([...Object.keys(tMap), ...Object.keys(dMap)])).sort();
    return {
        labels,
        trips: labels.map(l => tMap[l] || 0),
        drivers: labels.map(l => dMap[l] || 0),
    };
}

function growthSetChartType(type, btnEl) {
    _growthChartType = type;
    document.querySelectorAll('#growth-view-combo, #growth-view-bar, #growth-view-line')
        .forEach(b => b.classList.remove('active'));
    if (btnEl) btnEl.classList.add('active');
    growthRenderChart(_growthPeriod);
}

// Builds the two dataset configs for whichever view is currently selected.
// 'combo' (default) keeps trips as bars and drivers as a clean line, which
// reads best at low volumes. 'bar' groups both as bars side by side. 'line'
// draws both as straight (untensioned) lines - no smoothing, since curve
// interpolation overshoots badly on sparse low-integer data like this.
function _growthBuildDatasets(trips, drivers) {
    if (_growthChartType === 'bar') {
        return [
            { type: 'bar', label: 'Completed Trips', data: trips, backgroundColor: 'rgba(212, 175, 55, 0.65)', borderColor: '#D4AF37', borderWidth: 1, borderRadius: 3, yAxisID: 'y-trips' },
            { type: 'bar', label: 'Drivers Onboarded', data: drivers, backgroundColor: 'rgba(61, 220, 132, 0.65)', borderColor: '#3DDC84', borderWidth: 1, borderRadius: 3, yAxisID: 'y-drivers' },
        ];
    }
    if (_growthChartType === 'line') {
        return [
            { type: 'line', label: 'Completed Trips', data: trips, borderColor: '#D4AF37', backgroundColor: '#D4AF37', yAxisID: 'y-trips', tension: 0, fill: false, pointRadius: 3, pointBackgroundColor: '#D4AF37' },
            { type: 'line', label: 'Drivers Onboarded', data: drivers, borderColor: '#3DDC84', backgroundColor: '#3DDC84', yAxisID: 'y-drivers', tension: 0, fill: false, pointRadius: 3, pointBackgroundColor: '#3DDC84' },
        ];
    }
    // combo (default)
    return [
        { type: 'bar', label: 'Completed Trips', data: trips, backgroundColor: 'rgba(212, 175, 55, 0.55)', borderColor: '#D4AF37', borderWidth: 1, borderRadius: 3, yAxisID: 'y-trips', order: 2 },
        { type: 'line', label: 'Drivers Onboarded', data: drivers, borderColor: '#3DDC84', backgroundColor: '#3DDC84', yAxisID: 'y-drivers', tension: 0, fill: false, pointRadius: 3, pointBackgroundColor: '#3DDC84', order: 1 },
    ];
}

function growthRenderChart(period) {
    if (typeof Chart === 'undefined' || !_growthCache) return;
    const key = ['daily', 'monthly', 'yearly', 'custom'].includes(period) ? period : 'weekly';
    const { labels, trips, drivers } = _growthAlign(
        _growthCache['trips_' + key],
        _growthCache['drivers_' + key]
    );

    const canvas = document.getElementById('chart-ride-growth');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    if (rideGrowthChart) rideGrowthChart.destroy();

    if (!labels.length) {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        return;
    }

    rideGrowthChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: _growthBuildDatasets(trips, drivers),
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { labels: { color: '#EDEDF5', font: { size: 11 } } },
            },
            scales: {
                x: { ticks: { color: '#9A9AAE', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,.05)' } },
                'y-trips': {
                    position: 'left',
                    beginAtZero: true,
                    ticks: { color: '#D4AF37', font: { size: 10 } },
                    grid: { color: 'rgba(255,255,255,.05)' },
                    title: { display: true, text: 'Trips', color: '#D4AF37', font: { size: 10 } },
                },
                'y-drivers': {
                    position: 'right',
                    beginAtZero: true,
                    ticks: { color: '#3DDC84', font: { size: 10 }, precision: 0 },
                    grid: { drawOnChartArea: false },
                    title: { display: true, text: 'Drivers', color: '#3DDC84', font: { size: 10 } },
                },
            },
        },
    });
}

// js/admin-etelpay.js
// Etel Pay live-toggle + per-user override panel (Settings tab).
// The backend flag/toggle (get_feature_flags / admin_toggle_feature)
// already existed and worked - this file is purely the missing admin
// UI to actually flip it, plus the newer per-user override endpoints
// (admin_list_etelpay_overrides / admin_set_etelpay_override /
// admin_remove_etelpay_override).

let etelPayOverridesCache = [];

async function loadEtelPayPanel() {
    await loadEtelPayGlobalFlag();
    await loadEtelPayOverrides();
}

async function loadEtelPayGlobalFlag() {
    const toggle = document.getElementById('etelpay-live-toggle');
    const label  = document.getElementById('etelpay-live-label');
    const meta   = document.getElementById('etelpay-live-meta');
    if (!toggle) return;
    try {
        const res = await adm('get_feature_flags', {}, true);
        if (res && res.success) {
            const live = !!res.data.etelpay_live;
            toggle.checked = live;
            label.textContent = live ? 'Live for everyone' : 'Coming Soon (hidden)';
            label.style.color = live ? '#2ECC71' : '#B8B0A0';
            if (res.data.etelpay_live_updated_at) {
                meta.textContent = 'Last changed ' + fmtTime(res.data.etelpay_live_updated_at) +
                    (res.data.etelpay_live_updated_by ? ' by ' + res.data.etelpay_live_updated_by : '');
            } else {
                meta.textContent = '';
            }
        } else {
            label.textContent = 'Could not load status';
        }
    } catch (e) {
        label.textContent = 'Could not load status';
    }
}

async function toggleEtelPayLive(nextValue) {
    const label = document.getElementById('etelpay-live-label');
    label.textContent = 'Saving…';
    label.style.color = '#B8B0A0';
    try {
        const res = await adm('admin_toggle_feature', { flag: 'etelpay_live', value: nextValue }, false);
        if (res && res.success) {
            await loadEtelPayGlobalFlag();
        } else {
            document.getElementById('etelpay-live-toggle').checked = !nextValue;
            alert((res && res.message) || 'Could not update Etel Pay status');
            await loadEtelPayGlobalFlag();
        }
    } catch (e) {
        document.getElementById('etelpay-live-toggle').checked = !nextValue;
        alert('Network error updating Etel Pay status');
    }
}

async function loadEtelPayOverrides() {
    const tbody = document.getElementById('etelpay-overrides-tbody');
    if (!tbody) return;
    if (window.ETEL_RIDE_ONLY) { tbody.innerHTML = '<tr><td colspan="5" style="color:#B8B0A0">Not available for your role</td></tr>'; return; }
    tbody.innerHTML = '<tr><td colspan="5" style="color:#B8B0A0">Loading…</td></tr>';
    try {
        const res = await adm('admin_list_etelpay_overrides', {}, true);
        if (!res || !res.success) { tbody.innerHTML = '<tr><td colspan="5" style="color:#e74c3c">Could not load overrides</td></tr>'; return; }
        etelPayOverridesCache = res.data.overrides || [];
        renderEtelPayOverrides();
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="5" style="color:#e74c3c">Could not load overrides</td></tr>';
    }
}

function renderEtelPayOverrides() {
    const tbody = document.getElementById('etelpay-overrides-tbody');
    if (!tbody) return;
    if (!etelPayOverridesCache.length) {
        tbody.innerHTML = '<tr><td colspan="5" style="color:#B8B0A0">No per-user overrides set - everyone follows the global switch above.</td></tr>';
        return;
    }
    tbody.innerHTML = etelPayOverridesCache.map(function(o) {
        const statusHtml = o.allowed
            ? '<span style="color:#2ECC71;font-weight:700">✅ Granted</span>'
            : '<span style="color:#e74c3c;font-weight:700">🚫 Denied</span>';
        return '<tr>' +
            '<td>' + (o.name || '—') + '</td>' +
            '<td>' + (o.phone || '—') + '</td>' +
            '<td>' + statusHtml + '</td>' +
            '<td>' + fmtTime(o.updated_at) + (o.updated_by ? ' <span style="color:#B8B0A0">(' + o.updated_by + ')</span>' : '') + '</td>' +
            '<td><button class="btn btn-secondary" style="padding:5px 10px;font-size:11px" onclick="etelPayRemoveOverride(' + o.user_id + ')">Remove</button></td>' +
        '</tr>';
    }).join('');
}

async function etelPaySetOverride(allowed) {
    const input = document.getElementById('etelpay-override-phone');
    const phone = (input.value || '').trim();
    if (!phone) { alert('Enter a member phone number first'); return; }
    try {
        const res = await adm('admin_set_etelpay_override', { phone: phone, value: allowed }, false);
        if (res && res.success) {
            input.value = '';
            await loadEtelPayOverrides();
        } else {
            alert((res && res.message) || 'Could not save override - check the phone number is registered');
        }
    } catch (e) {
        alert('Network error saving override');
    }
}

async function etelPayRemoveOverride(userId) {
    if (!confirm('Remove this override? The member will revert to the global Etel Pay switch.')) return;
    try {
        const res = await adm('admin_remove_etelpay_override', { user_id: userId }, false);
        if (res && res.success) await loadEtelPayOverrides();
        else alert((res && res.message) || 'Could not remove override');
    } catch (e) {
        alert('Network error removing override');
    }
}

// Loaded from switchPage('settings') in js/admin/admin-ui.js, alongside
// the existing loadSettings() - avoids an unnecessary API call on every
// admin page load for admins who never open Settings.

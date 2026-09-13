// js/admin/admin-api.js
// Extracted from admin.js

async function adm(action, params={}, isGet=false) {
    if (isGet) {
        const qs = Object.entries(params).map(([k,v])=>k+'='+encodeURIComponent(v)).join('&');
        const url = ETEL.api + '?action=' + action + (qs ? '&'+qs : '');
        const r = await fetch(url, {headers:{'Authorization':'Bearer '+Auth.getToken()}});
        const text = await r.text();
        try { return JSON.parse(text); }
        catch(e) { return {success:false, message:'Server error (non-JSON): ' + text.slice(0,120)}; }
    }
    return api(action, params); // Requires api() from core.js
}

function fmtTime(ts) {
    if (!ts) return '—';
    const d = new Date(ts);
    return d.toLocaleDateString('en-GB',{day:'2-digit',month:'short'}) + ' ' + d.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'});
}

function statusBadge(s) {
    return `<span class="badge-status badge-${s||'pending'}">${(s||'pending').replace(/_/g,' ')}</span>`;
}

window.adm = adm;
window.fmtTime = fmtTime;
window.statusBadge = statusBadge;

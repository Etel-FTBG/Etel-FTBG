// js/admin-announcements.js
// Settings > Driver Announcements panel: posts to driver_announcements
// (visible in the driver app's Chats tab) and now also pushes a real
// notification to every registered driver at the same time, since the
// passive in-app list alone was easy to never see. Uses the same adm()
// helper and escapeHtml()/fmtTime() conventions as admin-support.js.

async function postDriverAnnouncement() {
    const title = document.getElementById('announce-title').value.trim();
    const body = document.getElementById('announce-body').value.trim();
    const category = document.getElementById('announce-category').value;
    const audEl = document.getElementById('announce-audience');
    const audience = audEl ? audEl.value : 'drivers';
    const reportEl = document.getElementById('announce-post-report');
    if (!title || !body) {
        reportEl.innerHTML = '<div style="color:#e74c3c">Title and message body are both required.</div>';
        return;
    }
    reportEl.innerHTML = '<div style="color:#B8B0A0">Posting…</div>';
    try {
        const res = await adm('admin_create_announcement', { title: title, body: body, category: category, audience: audience });
        if (res && res.success) {
            reportEl.innerHTML = '<div style="color:#27ae60">✅ Posted and pushed to the selected audience.</div>';
            document.getElementById('announce-title').value = '';
            document.getElementById('announce-body').value = '';
            loadDriverAnnouncements();
        } else {
            reportEl.innerHTML = '<div style="color:#e74c3c">' + escapeHtml((res && res.data && res.data.message) || (res && res.message) || 'Could not post announcement') + '</div>';
        }
    } catch (e) {
        reportEl.innerHTML = '<div style="color:#e74c3c">Network error - could not post announcement.</div>';
    }
}

async function loadDriverAnnouncements() {
    const listEl = document.getElementById('announce-list');
    listEl.innerHTML = 'Loading…';
    try {
        const res = await adm('admin_get_announcements', {}, true);
        const rows = (res && res.success && res.data && res.data.announcements) ? res.data.announcements : [];
        if (!rows.length) {
            listEl.innerHTML = 'No announcements posted yet.';
            return;
        }
        const catLabel = { news: 'News', notification: 'Notification', partner: 'Partner' };
        listEl.innerHTML = rows.map(function (r) {
            const cat = (catLabel[r.category] || r.category) + ' · ' + ({ riders: 'Riders', all: 'Drivers + Riders' }[r.audience] || 'Drivers');
            const activeBadge = parseInt(r.active, 10)
                ? '<span style="color:#27ae60">Active</span>'
                : '<span style="color:#B8B0A0">Removed</span>';
            return '<div style="border-top:1px solid rgba(212,175,55,.1);padding:10px 0">' +
                '<div style="font-weight:700;font-size:13.5px">' + escapeHtml(r.title) + '</div>' +
                '<div style="font-size:12px;color:#B8B0A0;margin:3px 0">' + escapeHtml(r.body) + '</div>' +
                '<div style="font-size:11px;color:#B8B0A0">' + cat + ' · ' + fmtTime(r.created_at) + ' · ' + activeBadge + '</div>' +
                '</div>';
        }).join('');
    } catch (e) {
        listEl.innerHTML = '<span style="color:#e74c3c">Could not load past announcements.</span>';
    }
}

// js/admin-support.js
// Support Inbox panel: reads/replies to messages sent via the "Contact
// Support" composer in Delivery/Ride's Settings & Privacy screens
// (customer_messages table). Previously these messages had nowhere to
// be read from - this is that missing inbox.

let supportThreadsCache = [];
let supportOpenUserId = null;

async function loadSupportInbox() {
    const tbody = document.getElementById('support-threads-tbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="3" style="color:#B8B0A0">Loading…</td></tr>';
    const unreadOnly = document.getElementById('support-unread-only').checked;
    try {
        const res = await adm('admin_list_support_threads', { unread_only: unreadOnly ? 1 : 0 }, true);
        if (!res || !res.success) { tbody.innerHTML = '<tr><td colspan="3" style="color:#e74c3c">Could not load messages</td></tr>'; return; }
        supportThreadsCache = res.data.threads || [];
        renderSupportThreads();
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="3" style="color:#e74c3c">Could not load messages</td></tr>';
    }
}

function renderSupportThreads() {
    const tbody = document.getElementById('support-threads-tbody');
    if (!tbody) return;
    if (!supportThreadsCache.length) {
        tbody.innerHTML = '<tr><td colspan="3" style="color:#B8B0A0">No support messages yet.</td></tr>';
        return;
    }
    tbody.innerHTML = supportThreadsCache.map(function(t) {
        const unread = parseInt(t.unread_count || 0, 10);
        const preview = (t.last_body || '').slice(0, 46) + ((t.last_body || '').length > 46 ? '…' : '');
        const fromSupport = t.last_sender === 'support';
        return '<tr style="cursor:pointer' + (supportOpenUserId == t.user_id ? ';background:rgba(212,175,55,.08)' : '') + '" onclick="openSupportThread(' + t.user_id + ')">' +
            '<td><div style="font-weight:700">' + (t.name || 'Unknown') + (unread ? ' <span style="background:#e74c3c;color:#fff;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:700">' + unread + '</span>' : '') + '</div><div style="font-size:11px;color:#B8B0A0">' + (t.phone || '') + '</div></td>' +
            '<td style="font-size:12.5px;color:' + (fromSupport ? '#B8B0A0' : '#1a1a2e') + '">' + (fromSupport ? '↩ ' : '') + escapeHtml(preview) + '</td>' +
            '<td style="font-size:11px;color:#B8B0A0;white-space:nowrap">' + fmtTime(t.last_message_at) + '</td>' +
        '</tr>';
    }).join('');
}

async function openSupportThread(userId) {
    supportOpenUserId = userId;
    renderSupportThreads();
    const panel = document.getElementById('support-thread-panel');
    panel.innerHTML = '<div style="color:#B8B0A0;text-align:center;padding:40px 10px">Loading conversation…</div>';
    try {
        const res = await adm('admin_get_support_thread', { user_id: userId }, true);
        if (!res || !res.success) { panel.innerHTML = '<div style="color:#e74c3c">Could not load thread</div>'; return; }
        const msgs = res.data.messages || [];
        const user = res.data.user || {};
        const bubbles = msgs.map(function(m) {
            const fromSupport = m.sender === 'support';
            const align = fromSupport ? 'flex-end' : 'flex-start';
            const bg = fromSupport ? 'linear-gradient(135deg,#D4AF37,#C8960C)' : 'rgba(255,255,255,.06)';
            const color = fromSupport ? '#1a1206' : '#1a1a2e';
            return '<div style="display:flex;justify-content:' + align + ';margin-bottom:10px">' +
                '<div style="max-width:75%;padding:10px 13px;border-radius:14px;background:' + bg + ';color:' + color + '">' +
                (m.subject ? '<div style="font-weight:700;font-size:12.5px;margin-bottom:3px">' + escapeHtml(m.subject) + '</div>' : '') +
                '<div style="font-size:13px;white-space:pre-wrap">' + escapeHtml(m.body) + '</div>' +
                '<div style="font-size:10px;opacity:.6;margin-top:4px">' + fmtTime(m.created_at) + '</div>' +
                '</div></div>';
        }).join('') || '<div style="color:#B8B0A0;text-align:center;padding:20px">No messages</div>';
        panel.innerHTML =
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid rgba(212,175,55,.15)">' +
            '<div><div style="font-weight:800;font-size:15px">' + (user.name || 'Unknown') + '</div><div style="font-size:12px;color:#B8B0A0">' + (user.phone || '') + '</div></div>' +
            '</div>' +
            '<div style="max-height:360px;overflow-y:auto;margin-bottom:14px;padding-right:4px">' + bubbles + '</div>' +
            '<textarea id="support-reply-box" placeholder="Type a reply…" style="width:100%;padding:11px;border:1.5px solid #e0e0e0;border-radius:10px;font-size:13px;min-height:70px;resize:vertical;box-sizing:border-box;margin-bottom:10px;font-family:inherit"></textarea>' +
            '<button class="btn btn-primary" onclick="sendSupportReply(' + userId + ')">Send Reply</button>' +
            '<div id="support-reply-msg" style="margin-top:8px;font-size:12.5px"></div>';
        loadSupportInbox(); // refresh unread badges in the list now that this thread was read
    } catch (e) {
        panel.innerHTML = '<div style="color:#e74c3c">Could not load thread</div>';
    }
}

async function sendSupportReply(userId) {
    const box = document.getElementById('support-reply-box');
    const msg = document.getElementById('support-reply-msg');
    const text = (box.value || '').trim();
    if (!text) { msg.style.color = '#e74c3c'; msg.textContent = 'Write a reply first.'; return; }
    msg.style.color = '#B8B0A0'; msg.textContent = 'Sending…';
    try {
        const res = await adm('admin_reply_support_message', { user_id: userId, body: text }, false);
        if (res && res.success) {
            box.value = '';
            msg.style.color = '#2ECC71'; msg.textContent = 'Sent ✓';
            openSupportThread(userId);
        } else {
            msg.style.color = '#e74c3c'; msg.textContent = (res && res.message) || 'Could not send reply';
        }
    } catch (e) {
        msg.style.color = '#e74c3c'; msg.textContent = 'Network error sending reply';
    }
}

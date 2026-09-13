/* ═══════════════════════════════════════════════════════════════
   ETEL Ride — In-App Chat  v2.0
   Works in ride.html (passenger) and driver.html (driver).
   Call: RideChat.open(rideId, myRole [, otherPartyName])
         myRole = 'passenger' | 'driver'

   What's new in v2.0:
     - Message clustering with a small avatar on the first bubble
       of each run, inline per-bubble time + read receipts
     - Real read receipts (sent ✓ / read ✓✓ in gold) backed by
       ride_messages.read_at, auto-marked on fetch
     - Typing indicator backed by a tiny ride_typing ping table
     - Optimistic send: message appears instantly, shows a pending
       clock, flips to sent/read, or to a tap-to-retry failed state
     - Day dividers, smart auto-scroll (never yanks you away from
       history you're reading), a "new messages" jump pill
     - Drag-down-to-dismiss on the sheet handle
     - prefers-reduced-motion respected; polling backs off quietly
       on repeated network failures instead of erroring loudly

   Public API is unchanged on purpose — ride.html / driver.html /
   driver.js call RideChat.open/close/send exactly as before.
   ═══════════════════════════════════════════════════════════════ */

const RideChat = (function () {

    const CSS = `
    #rc-overlay {
        display:none;position:fixed;inset:0;z-index:9000;
        background:rgba(5,5,10,.72);backdrop-filter:blur(6px);
        align-items:flex-end;justify-content:center;
    }
    #rc-overlay.rc-open { display:flex; }

    #rc-panel {
        width:min(460px,100vw);
        height:min(86vh,720px);
        background:linear-gradient(180deg,#14141f 0%,#0b0b13 100%);
        border:1px solid rgba(212,175,55,.16);
        border-bottom:none;
        border-radius:24px 24px 0 0;
        display:flex;flex-direction:column;
        transform:translateY(100%);
        transition:transform .38s cubic-bezier(.22,1,.36,1);
        overflow:hidden;
        box-shadow:0 -20px 60px -20px rgba(0,0,0,.6);
    }
    #rc-overlay.rc-open #rc-panel { transform:translateY(0); }

    #rc-handle-wrap { display:flex;justify-content:center;padding:9px 0 4px;cursor:grab;touch-action:none;flex-shrink:0; }
    #rc-handle-wrap:active { cursor:grabbing; }
    #rc-handle { width:36px;height:4px;border-radius:3px;background:rgba(255,255,255,.18); }

    /* Header */
    #rc-head {
        display:flex;align-items:center;gap:11px;
        padding:2px 16px 13px;
        border-bottom:1px solid rgba(255,255,255,.07);
        flex-shrink:0;
    }
    #rc-head-avatar {
        width:40px;height:40px;border-radius:50%;flex-shrink:0;
        background:linear-gradient(135deg,#C8960C,#D4AF37);
        display:flex;align-items:center;justify-content:center;
        font-family:'Sora',sans-serif;font-weight:700;font-size:15px;color:#1a1206;
        border:2px solid rgba(212,175,55,.35);
        box-shadow:0 6px 16px -6px rgba(212,175,55,.5);
    }
    #rc-head-info { min-width:0;flex:1; }
    #rc-head-name {
        font-family:'Sora',sans-serif;font-size:15px;font-weight:700;color:#EDEDF5;
        white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    }
    #rc-head-sub {
        font-size:11.5px;color:#8A8AA8;margin-top:2px;
        display:flex;align-items:center;gap:5px;
        font-family:'Outfit',sans-serif;
        white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    }
    #rc-head-dot {
        width:6px;height:6px;border-radius:50%;background:#4ECDC4;flex-shrink:0;
        box-shadow:0 0 0 3px rgba(78,205,196,.18);
    }
    @media (prefers-reduced-motion: no-preference) {
        #rc-head-dot.rc-live { animation: rcBreathe 2.2s ease-in-out infinite; }
    }
    @keyframes rcBreathe { 0%,100%{opacity:1} 50%{opacity:.4} }
    #rc-head-sub.rc-typing { color:#4ECDC4;font-weight:600; }
    #rc-close {
        margin-left:auto;width:32px;height:32px;border-radius:50%;flex-shrink:0;
        border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);
        color:#9A9AAE;font-size:15px;cursor:pointer;display:flex;
        align-items:center;justify-content:center;transition:background .15s;
    }
    #rc-close:active { background:rgba(255,255,255,.14); }

    /* Reconnecting banner */
    #rc-conn {
        display:none;flex-shrink:0;text-align:center;padding:6px;
        font-size:11px;font-weight:600;color:#E5C76B;background:rgba(212,175,55,.09);
        font-family:'Outfit',sans-serif;letter-spacing:.02em;
    }
    #rc-conn.rc-show { display:block; }

    /* Message area */
    #rc-messages {
        flex:1;overflow-y:auto;padding:14px 14px 6px;
        display:flex;flex-direction:column;gap:2px;position:relative;
        scroll-behavior:smooth;
    }
    #rc-messages::-webkit-scrollbar{width:3px}
    #rc-messages::-webkit-scrollbar-thumb{background:rgba(212,175,55,.2);border-radius:2px}

    /* Day divider */
    .rc-day {
        align-self:center;font-size:10.5px;font-weight:700;letter-spacing:.06em;
        text-transform:uppercase;color:#6B6E82;background:rgba(255,255,255,.05);
        padding:4px 12px;border-radius:999px;margin:12px 0 8px;
        font-family:'Outfit',sans-serif;
    }

    /* Rows / clustering */
    .rc-row { display:flex;align-items:flex-end;gap:7px;margin-top:2px;max-width:100%; }
    .rc-row.rc-cluster-start { margin-top:12px; }
    .rc-row.mine { justify-content:flex-end; }
    .rc-row.theirs { justify-content:flex-start; }

    .rc-avatar-sm {
        width:24px;height:24px;border-radius:50%;flex-shrink:0;
        display:flex;align-items:center;justify-content:center;
        font-family:'Sora',sans-serif;font-weight:700;font-size:10.5px;color:#0b0b13;
        background:linear-gradient(135deg,#4ECDC4,#3AA79F);
        margin-bottom:1px;
    }
    .rc-avatar-sm.rc-spacer { visibility:hidden; }

    .rc-col { display:flex;flex-direction:column;max-width:78%;min-width:0; }
    .rc-row.mine .rc-col { align-items:flex-end;max-width:82%; }
    .rc-sender {
        font-size:10.5px;font-weight:700;color:#8A8AA8;margin:0 2px 3px;
        font-family:'Outfit',sans-serif;
    }

    .rc-bubble {
        position:relative;padding:8px 54px 8px 13px;border-radius:17px;
        font-size:13.5px;line-height:1.42;word-break:break-word;
        font-family:'Outfit',sans-serif;white-space:pre-wrap;
    }
    .rc-row.mine .rc-bubble {
        background:linear-gradient(135deg,#C8960C,#D4AF37);
        color:#1a1206;border-bottom-right-radius:5px;
    }
    .rc-row.theirs .rc-bubble {
        background:#1b1c28;border:1px solid rgba(255,255,255,.07);
        color:#EDEDF5;border-bottom-left-radius:5px;
        padding-right:46px;
    }

    .rc-meta {
        position:absolute;right:10px;bottom:6px;display:flex;align-items:center;gap:3px;
        font-size:9.5px;line-height:1;white-space:nowrap;
    }
    .rc-row.mine .rc-meta { color:rgba(26,18,6,.62); }
    .rc-row.theirs .rc-meta { color:#6B6E82; }
    .rc-ticks { display:inline-flex;align-items:center; }
    .rc-ticks svg { display:block; }
    .rc-ticks.rc-read { color:#D4AF37; }
    .rc-row.mine .rc-ticks.rc-read { color:#7A5A00; }
    .rc-ticks.rc-pending { opacity:.55; }
    .rc-ticks.rc-failed { color:#E63946; }

    .rc-row.rc-mine-failed .rc-bubble { cursor:pointer;opacity:.72; }
    .rc-retry-hint {
        font-size:10px;color:#E63946;margin:2px 2px 0;text-align:right;
        font-family:'Outfit',sans-serif;font-weight:600;
    }

    @media (prefers-reduced-motion: no-preference) {
        .rc-row.rc-enter { animation: rcRowIn .28s cubic-bezier(.22,1,.36,1); }
    }
    @keyframes rcRowIn { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:none} }

    /* Typing indicator */
    #rc-typing-row { display:none;align-items:flex-end;gap:7px;margin-top:10px; }
    #rc-typing-row.rc-show { display:flex; }
    .rc-typing-bubble {
        background:#1b1c28;border:1px solid rgba(255,255,255,.07);
        border-radius:17px;border-bottom-left-radius:5px;
        padding:11px 14px;display:flex;gap:4px;align-items:center;
    }
    .rc-typing-dot {
        width:6px;height:6px;border-radius:50%;background:#8A8AA8;
    }
    @media (prefers-reduced-motion: no-preference) {
        .rc-typing-dot { animation: rcTyping 1.1s ease-in-out infinite; }
        .rc-typing-dot:nth-child(2) { animation-delay:.15s; }
        .rc-typing-dot:nth-child(3) { animation-delay:.3s; }
    }
    @keyframes rcTyping { 0%,60%,100%{transform:translateY(0);opacity:.5} 30%{transform:translateY(-3px);opacity:1} }

    /* Jump-to-latest pill */
    #rc-jump {
        position:absolute;left:50%;bottom:10px;transform:translateX(-50%) translateY(8px);
        z-index:5;
        display:flex;align-items:center;gap:6px;padding:7px 14px;border-radius:999px;
        background:linear-gradient(135deg,#C8960C,#D4AF37);color:#1a1206;
        font-size:12px;font-weight:700;font-family:'Outfit',sans-serif;
        border:none;cursor:pointer;box-shadow:0 8px 20px -8px rgba(212,175,55,.7);
        opacity:0;pointer-events:none;transition:all .22s cubic-bezier(.22,1,.36,1);
    }
    #rc-jump.rc-show { opacity:1;pointer-events:auto;transform:translateX(-50%) translateY(0); }

    /* Quick replies */
    #rc-quick {
        padding:8px 14px;display:flex;gap:6px;overflow-x:auto;
        scrollbar-width:none;flex-shrink:0;
        border-top:1px solid rgba(255,255,255,.06);
    }
    #rc-quick::-webkit-scrollbar{display:none}
    .rc-quick-btn {
        white-space:nowrap;padding:7px 13px;border-radius:999px;
        border:1px solid rgba(212,175,55,.28);background:rgba(212,175,55,.07);
        color:#E5C76B;font-size:12px;font-weight:600;cursor:pointer;
        font-family:'Outfit',sans-serif;transition:all .15s;flex-shrink:0;
    }
    .rc-quick-btn:active{background:rgba(212,175,55,.2);transform:scale(.96)}

    /* Input bar */
    #rc-input-bar {
        display:flex;align-items:flex-end;gap:9px;
        padding:10px 14px;padding-bottom:max(10px, env(safe-area-inset-bottom,10px));
        border-top:1px solid rgba(255,255,255,.08);flex-shrink:0;
        background:rgba(0,0,0,.22);
    }
    #rc-input-wrap { flex:1;position:relative; }
    #rc-input {
        width:100%;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);
        border-radius:20px;padding:10px 16px;color:#EDEDF5;font-size:13.5px;
        font-family:'Outfit',sans-serif;outline:none;resize:none;
        max-height:100px;line-height:1.4;transition:border-color .15s;
        display:block;box-sizing:border-box;
    }
    #rc-input:focus{border-color:rgba(212,175,55,.45)}
    #rc-input::placeholder{color:#4B4F5E}
    #rc-count {
        position:absolute;right:14px;bottom:-15px;font-size:9.5px;color:#E63946;
        font-family:'Outfit',sans-serif;font-weight:600;display:none;
    }
    #rc-count.rc-show{display:block}
    #rc-send {
        width:42px;height:42px;border-radius:50%;flex-shrink:0;
        background:linear-gradient(135deg,#C8960C,#D4AF37);
        border:none;color:#1a1206;cursor:pointer;
        display:flex;align-items:center;justify-content:center;
        transition:all .15s;
    }
    #rc-send svg { width:18px;height:18px; }
    #rc-send:active{transform:scale(.92)}
    #rc-send:disabled{opacity:.35;cursor:default}

    /* Empty / loading */
    .rc-empty {
        flex:1;display:flex;flex-direction:column;align-items:center;
        justify-content:center;color:#4B4F5E;text-align:center;padding:20px;
        font-family:'Outfit',sans-serif;
    }
    .rc-empty-icon{font-size:38px;margin-bottom:10px;opacity:.8}
    .rc-empty-label{font-size:13.5px;font-weight:700;color:#8A8AA8}
    .rc-empty-sub{font-size:11.5px;margin-top:5px;line-height:1.5;max-width:240px}
    `;

    const QUICK_PASSENGER = [
        '👋 On my way', '📍 I\'m here', '5 minutes please', 'Where are you?',
        'OK, ready', 'Thank you!', 'Can you call me?'
    ];
    const QUICK_DRIVER = [
        '👋 On my way', '📍 Arrived', '2 minutes', 'I\'m waiting',
        'Turn right', 'I can see you', 'Thank you!'
    ];

    const TICK_ONE =
        '<svg viewBox="0 0 16 16" width="12" height="12" fill="none"><path d="M3 8.2l3 3L13 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    const TICK_TWO =
        '<svg viewBox="0 0 20 16" width="15" height="12" fill="none"><path d="M1 8.2l3 3L11 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 8.2l3 3L18 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    const TICK_CLOCK =
        '<svg viewBox="0 0 16 16" width="11" height="11" fill="none"><circle cx="8" cy="8" r="6.2" stroke="currentColor" stroke-width="1.4"/><path d="M8 4.8V8l2.4 1.4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>';
    const TICK_FAIL =
        '<svg viewBox="0 0 16 16" width="12" height="12" fill="none"><circle cx="8" cy="8" r="6.2" stroke="currentColor" stroke-width="1.4"/><path d="M8 5v3.6M8 10.6h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
    const SEND_ICON =
        '<svg viewBox="0 0 24 24" fill="none"><path d="M4 12L20 4L13 20L11 13L4 12Z" fill="currentColor"/></svg>';

    var _rideId = null, _role = null, _otherName = '', _pollTimer = null, _sending = false;
    var _seen = {};              // msg id -> { readAt, role }
    var _pendingTemp = {};       // tempId -> message body, for outgoing messages not yet ack'd
    var _lastRole = null, _lastDayKey = null;
    var _pendingSeq = 0;
    var _failStreak = 0;
    var _atBottom = true;
    var _newCount = 0;
    var _typingPingAt = 0;
    var _lastTypingShown = false;

    function injectCSS() {
        if (document.getElementById('rc-css')) return;
        var s = document.createElement('style'); s.id = 'rc-css'; s.textContent = CSS;
        document.head.appendChild(s);
    }

    function injectHTML() {
        if (document.getElementById('rc-overlay')) return;
        var d = document.createElement('div'); d.id = 'rc-overlay';
        d.innerHTML =
            '<div id="rc-panel">' +
                '<div id="rc-handle-wrap"><div id="rc-handle"></div></div>' +
                '<div id="rc-head">' +
                    '<div id="rc-head-avatar">💬</div>' +
                    '<div id="rc-head-info">' +
                        '<div id="rc-head-name">Chat</div>' +
                        '<div id="rc-head-sub"><span id="rc-head-dot"></span><span id="rc-head-sub-text">Active ride</span></div>' +
                    '</div>' +
                    '<button id="rc-close" onclick="RideChat.close()" aria-label="Close chat">✕</button>' +
                '</div>' +
                '<div id="rc-conn">Reconnecting…</div>' +
                '<div id="rc-messages" role="log" aria-live="polite">' +
                    '<div class="rc-empty"><div class="rc-empty-icon">💬</div><div class="rc-empty-label">Start the conversation</div><div class="rc-empty-sub">Messages are only visible during this ride and stay between you two</div></div>' +
                    '<div id="rc-typing-row"><div class="rc-avatar-sm rc-spacer"></div><div class="rc-typing-bubble"><div class="rc-typing-dot"></div><div class="rc-typing-dot"></div><div class="rc-typing-dot"></div></div></div>' +
                    '<button id="rc-jump" onclick="RideChat._jump()">↓ <span id="rc-jump-label">New message</span></button>' +
                '</div>' +
                '<div id="rc-quick"></div>' +
                '<div id="rc-input-bar">' +
                    '<div id="rc-input-wrap">' +
                        '<textarea id="rc-input" placeholder="Type a message…" rows="1" maxlength="500" oninput="RideChat._input(this)" onkeydown="RideChat._key(event)"></textarea>' +
                        '<div id="rc-count">500</div>' +
                    '</div>' +
                    '<button id="rc-send" onclick="RideChat.send()" aria-label="Send">' + SEND_ICON + '</button>' +
                '</div>' +
            '</div>';
        d.addEventListener('click', function (e) { if (e.target === d) RideChat.close(); });
        document.body.appendChild(d);

        var msgs = document.getElementById('rc-messages');
        msgs.addEventListener('scroll', function () {
            _atBottom = (msgs.scrollHeight - msgs.scrollTop - msgs.clientHeight) < 110;
            if (_atBottom) hideJump();
        });

        initDrag();
    }

    function initDrag() {
        var handle = document.getElementById('rc-handle-wrap');
        var panel = document.getElementById('rc-panel');
        if (!handle || !panel) return;
        var startY = 0, curY = 0, dragging = false;

        function down(e) {
            dragging = true; startY = curY = pointY(e);
            panel.style.transition = 'none';
        }
        function move(e) {
            if (!dragging) return;
            curY = pointY(e);
            var dy = Math.max(0, curY - startY);
            panel.style.transform = 'translateY(' + dy + 'px)';
        }
        function up() {
            if (!dragging) return;
            dragging = false;
            panel.style.transition = '';
            var dy = Math.max(0, curY - startY);
            if (dy > 110) { RideChat.close(); }
            else { panel.style.transform = ''; }
        }
        function pointY(e) { return (e.touches && e.touches[0]) ? e.touches[0].clientY : e.clientY; }

        handle.addEventListener('touchstart', down, { passive: true });
        handle.addEventListener('touchmove', move, { passive: true });
        handle.addEventListener('touchend', up);
        handle.addEventListener('mousedown', down);
        window.addEventListener('mousemove', move);
        window.addEventListener('mouseup', up);
    }

    function esc(s) { return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
    function initials(name) {
        var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
        if (!parts.length) return '?';
        return (parts[0][0] + (parts[1] ? parts[1][0] : '')).toUpperCase();
    }
    function vibrate(ms) { try { if (navigator.vibrate) navigator.vibrate(ms); } catch (e) {} }

    function fmtTime(ts) {
        if (!ts) return '';
        var d = new Date(ts.replace(' ', 'T') + 'Z');
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    function dayKey(ts) {
        if (!ts) return '';
        return ts.slice(0, 10);
    }
    function dayLabel(ts) {
        var d = new Date(ts.replace(' ', 'T') + 'Z');
        var now = new Date();
        var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        var that = new Date(d.getFullYear(), d.getMonth(), d.getDate());
        var diffDays = Math.round((today - that) / 86400000);
        if (diffDays === 0) return 'Today';
        if (diffDays === 1) return 'Yesterday';
        return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    }

    function getAPI() {
        return (typeof ETEL !== 'undefined' && ETEL.api) ? ETEL.api : 'api/boot.php';
    }
    function getToken() {
        return (typeof Auth !== 'undefined') ? (Auth.getToken ? Auth.getToken() : '') : '';
    }

    function setHeadPresence(text, live) {
        var sub = document.getElementById('rc-head-sub-text');
        var dot = document.getElementById('rc-head-dot');
        var wrap = document.getElementById('rc-head-sub');
        if (sub) sub.textContent = text;
        if (dot) dot.classList.toggle('rc-live', !!live);
        if (wrap) wrap.classList.toggle('rc-typing', !!live && text.indexOf('typing') >= 0);
    }

    function showConn(show) {
        var el = document.getElementById('rc-conn');
        if (el) el.classList.toggle('rc-show', !!show);
    }

    function hideJump() {
        var j = document.getElementById('rc-jump');
        if (j) j.classList.remove('rc-show');
        _newCount = 0;
    }
    function showJump() {
        _newCount++;
        var j = document.getElementById('rc-jump');
        var label = document.getElementById('rc-jump-label');
        if (label) label.textContent = _newCount === 1 ? 'New message' : (_newCount + ' new messages');
        if (j) j.classList.add('rc-show');
    }

    function scrollToBottom(smooth) {
        var el = document.getElementById('rc-messages');
        if (!el) return;
        if (smooth === false) { el.scrollTop = el.scrollHeight; }
        else { el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' }); }
        _atBottom = true;
        hideJump();
    }

    function clearEmpty() {
        var el = document.getElementById('rc-messages');
        var empty = el && el.querySelector('.rc-empty');
        if (empty) empty.remove();
    }

    function ticksHTML(state) {
        // state: 'pending' | 'sent' | 'read' | 'failed'
        if (state === 'pending') return '<span class="rc-ticks rc-pending">' + TICK_CLOCK + '</span>';
        if (state === 'failed')  return '<span class="rc-ticks rc-failed">' + TICK_FAIL + '</span>';
        if (state === 'read')    return '<span class="rc-ticks rc-read">' + TICK_TWO + '</span>';
        return '<span class="rc-ticks">' + TICK_ONE + '</span>';
    }

    function buildRow(id, mine, senderName, body, ts, state, clusterStart) {
        var row = document.createElement('div');
        row.className = 'rc-row ' + (mine ? 'mine' : 'theirs') + (clusterStart ? ' rc-cluster-start' : '') + ' rc-enter';
        row.id = 'rcm-' + id;

        var metaTime = ts ? fmtTime(ts) : '';
        var metaHTML = '<span class="rc-meta"><span class="rc-time">' + esc(metaTime) + '</span>' +
            (mine ? '<span class="rc-tick-slot">' + ticksHTML(state) + '</span>' : '') + '</span>';

        if (!mine) {
            row.innerHTML =
                '<div class="rc-avatar-sm' + (clusterStart ? '' : ' rc-spacer') + '">' + esc(initials(senderName)) + '</div>' +
                '<div class="rc-col">' +
                    (clusterStart ? '<div class="rc-sender">' + esc(senderName || 'Them') + '</div>' : '') +
                    '<div class="rc-bubble">' + esc(body) + metaHTML + '</div>' +
                '</div>';
        } else {
            row.innerHTML = '<div class="rc-col"><div class="rc-bubble">' + esc(body) + metaHTML + '</div></div>';
            if (state === 'failed') {
                row.classList.add('rc-mine-failed');
                row.onclick = function () { RideChat._retry(id); };
            }
        }
        return row;
    }

    function ensureDayDivider(ts) {
        var key = dayKey(ts);
        if (key === _lastDayKey) return;
        _lastDayKey = key;
        _lastRole = null; // force a fresh cluster after a day break
        var el = document.getElementById('rc-messages');
        var div = document.createElement('div');
        div.className = 'rc-day';
        div.textContent = dayLabel(ts);
        var typingRow = document.getElementById('rc-typing-row');
        el.insertBefore(div, typingRow);
    }

    function insertRow(row) {
        var el = document.getElementById('rc-messages');
        var typingRow = document.getElementById('rc-typing-row');
        el.insertBefore(row, typingRow);
    }

    function appendServerMessage(m) {
        clearEmpty();
        ensureDayDivider(m.sent_at);
        var mine = (m.sender_role === _role);
        var clusterStart = (_lastRole !== m.sender_role);
        _lastRole = m.sender_role;

        if (!mine && m.sender_name) _otherName = m.sender_name;
        if (!mine) updateHeaderIdentity();

        var state = mine ? (m.read_at ? 'read' : 'sent') : null;
        var row = buildRow(m.id, mine, m.sender_name, m.body, m.sent_at, state, clusterStart);
        insertRow(row);
        _seen[String(m.id)] = { readAt: m.read_at || null, role: m.sender_role };

        if (mine) {
            scrollToBottom(false);
        } else {
            if (_atBottom) { scrollToBottom(true); vibrate(12); }
            else { showJump(); vibrate(12); }
        }
    }

    function updateReadState(m) {
        var key = String(m.id);
        var prev = _seen[key];
        if (!prev || prev.readAt === m.read_at) return;
        prev.readAt = m.read_at;
        if (m.sender_role !== _role) return; // only my own messages show ticks
        var row = document.getElementById('rcm-' + m.id);
        if (!row) return;
        var slot = row.querySelector('.rc-tick-slot');
        if (slot) slot.innerHTML = ticksHTML(m.read_at ? 'read' : 'sent');
    }

    function updateHeaderIdentity() {
        var av = document.getElementById('rc-head-avatar');
        var nm = document.getElementById('rc-head-name');
        if (!_otherName) return;
        if (nm) nm.textContent = _otherName;
        if (av) av.textContent = initials(_otherName);
    }

    function setTyping(on) {
        var row = document.getElementById('rc-typing-row');
        if (row) row.classList.toggle('rc-show', !!on);
        if (on !== _lastTypingShown) {
            setHeadPresence(on ? (_role === 'driver' ? 'Passenger is typing…' : 'Driver is typing…') : 'Active ride', on);
            _lastTypingShown = on;
        }
        if (on && _atBottom) scrollToBottom(true);
    }

    function adoptPending(m) {
        for (var tempId in _pendingTemp) {
            if (_pendingTemp[tempId] === m.body) {
                var el = document.getElementById('rcm-' + tempId);
                delete _pendingTemp[tempId];
                if (!el) return true; // already resolved by send()'s own response
                el.id = 'rcm-' + m.id;
                el.onclick = null;
                el.classList.remove('rc-mine-failed');
                var slot = el.querySelector('.rc-tick-slot');
                if (slot) slot.innerHTML = ticksHTML(m.read_at ? 'read' : 'sent');
                var hint = el.parentNode && el.parentNode.querySelector('.rc-retry-hint');
                if (hint) hint.remove();
                _seen[String(m.id)] = { readAt: m.read_at || null, role: m.sender_role };
                return true;
            }
        }
        return false;
    }

    function poll() {
        if (!_rideId) return;
        fetch(getAPI() + '?action=get_ride_messages&ride_id=' + _rideId, {
            headers: { 'Authorization': 'Bearer ' + getToken() }
        }).then(function (r) { return r.json(); }).then(function (d) {
            _failStreak = 0; showConn(false);
            if (!(d.success || d.ok) || !d.data) return;
            (d.data.messages || []).forEach(function (m) {
                var key = String(m.id);
                if (_seen[key]) { updateReadState(m); return; }
                if (m.sender_role === _role && adoptPending(m)) return;
                appendServerMessage(m);
            });
            setTyping(!!d.data.other_typing);
            // If this poll came back successfully but the conversation is
            // still genuinely empty (no messages ever sent), the panel's
            // initial "Loading..." placeholder (set in open(), below)
            // never gets replaced - it's only ever swapped out by
            // appendServerMessage() when an actual message arrives. Without
            // this, any ride chat with zero messages sits on "Loading..."
            // forever even though the fetch itself succeeded fine.
            var msgBox = document.getElementById('rc-messages');
            if (msgBox && !msgBox.querySelector('.rc-row')) {
                var emptyEl = msgBox.querySelector('.rc-empty');
                if (emptyEl) {
                    emptyEl.innerHTML = '<div class="rc-empty-icon">\ud83d\udcac</div>' +
                        '<div class="rc-empty-label">Start the conversation</div>' +
                        '<div class="rc-empty-sub">Messages are only visible during this ride and stay between you two</div>';
                }
            }
        }).catch(function () {
            _failStreak++;
            if (_failStreak >= 2) showConn(true);
        });
    }

    function pingTyping() {
        if (!_rideId) return;
        var now = Date.now();
        if (now - _typingPingAt < 2000) return;
        _typingPingAt = now;
        fetch(getAPI() + '?action=ping_ride_typing', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + getToken() },
            body: JSON.stringify({ ride_id: _rideId })
        }).catch(function () {});
    }

    function renderQuick() {
        var el = document.getElementById('rc-quick');
        if (!el) return;
        var list = (_role === 'driver') ? QUICK_DRIVER : QUICK_PASSENGER;
        el.innerHTML = list.map(function (q) {
            return '<button class="rc-quick-btn" onclick="RideChat._quick(\'' + esc(q).replace(/'/g, "\\'") + '\')">' + esc(q) + '</button>';
        }).join('');
    }

    function markFailed(tempId, text, elArg) {
        var el = elArg || document.getElementById('rcm-' + tempId);
        if (!el) return;
        el.setAttribute('data-body', text);
        el.classList.add('rc-mine-failed');
        var slot = el.querySelector('.rc-tick-slot');
        if (slot) slot.innerHTML = ticksHTML('failed');
        el.onclick = function () { RideChat._retry(tempId); };
        if (!el.parentNode.querySelector('.rc-retry-hint')) {
            var hint = document.createElement('div');
            hint.className = 'rc-retry-hint';
            hint.textContent = 'Not sent · tap to retry';
            el.parentNode.appendChild(hint);
        }
    }

    return {
        open: function (rideId, role, otherPartyName) {
            injectCSS(); injectHTML();
            _rideId = rideId; _role = role; _otherName = otherPartyName || '';
            _seen = {}; _pendingTemp = {}; _lastRole = null; _lastDayKey = null; _failStreak = 0; _newCount = 0; _atBottom = true;

            var nameEl = document.getElementById('rc-head-name');
            var avEl = document.getElementById('rc-head-avatar');
            if (_otherName) {
                if (nameEl) nameEl.textContent = _otherName;
                if (avEl) avEl.textContent = initials(_otherName);
            } else {
                if (nameEl) nameEl.textContent = role === 'driver' ? 'Passenger' : 'Driver';
                if (avEl) avEl.textContent = '💬';
            }
            setHeadPresence('Ride #' + rideId, false);
            showConn(false);

            var msgs = document.getElementById('rc-messages');
            if (msgs) {
                Array.prototype.slice.call(msgs.querySelectorAll('.rc-row, .rc-day')).forEach(function (n) { n.remove(); });
                clearEmpty();
                msgs.insertBefore(
                    (function () { var e = document.createElement('div'); e.className = 'rc-empty'; e.innerHTML = '<div class="rc-empty-icon">💬</div><div class="rc-empty-label">Loading…</div>'; return e; })(),
                    msgs.firstChild
                );
            }
            var jump = document.getElementById('rc-jump');
            if (jump) jump.classList.remove('rc-show');
            var typingRow = document.getElementById('rc-typing-row');
            if (typingRow) typingRow.classList.remove('rc-show');

            renderQuick();

            document.getElementById('rc-overlay').classList.add('rc-open');
            document.body.style.overflow = 'hidden';

            poll();
            _pollTimer = setInterval(poll, 3000);

            setTimeout(function () {
                var inp = document.getElementById('rc-input');
                if (inp) inp.focus();
            }, 380);
        },

        close: function () {
            clearInterval(_pollTimer); _pollTimer = null;
            var ol = document.getElementById('rc-overlay');
            var panel = document.getElementById('rc-panel');
            if (!ol) return;
            panel.style.transform = 'translateY(100%)';
            setTimeout(function () {
                ol.classList.remove('rc-open');
                document.body.style.overflow = '';
                panel.style.transform = '';
            }, 380);
        },

        send: function () {
            if (_sending) return;
            var inp = document.getElementById('rc-input');
            if (!inp) return;
            var text = inp.value.trim();
            if (!text || !_rideId) return;
            _sending = true;
            var btn = document.getElementById('rc-send');
            if (btn) btn.disabled = true;
            inp.value = '';
            RideChat._input(inp);

            var tempId = 'p' + (++_pendingSeq);
            var nowIso = new Date().toISOString().slice(0, 19).replace('T', ' ');
            clearEmpty();
            ensureDayDivider(nowIso);
            var clusterStart = (_lastRole !== _role);
            _lastRole = _role;
            var row = buildRow(tempId, true, 'You', text, nowIso, 'pending', clusterStart);
            insertRow(row);
            _pendingTemp[tempId] = text;
            scrollToBottom(false);
            vibrate(10);

            fetch(getAPI() + '?action=send_ride_message', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + getToken() },
                body: JSON.stringify({ ride_id: _rideId, body: text })
            }).then(function (r) { return r.json(); }).then(function (d) {
                delete _pendingTemp[tempId];
                var el = document.getElementById('rcm-' + tempId);
                if (!el) return; // poll() already adopted this bubble under its real id
                if (d.success && d.data) {
                    el.id = 'rcm-' + d.data.id;
                    var slot = el.querySelector('.rc-tick-slot');
                    if (slot) slot.innerHTML = ticksHTML('sent');
                    var timeEl = el.querySelector('.rc-time');
                    if (timeEl) timeEl.textContent = fmtTime(d.data.sent_at);
                    _seen[String(d.data.id)] = { readAt: null, role: _role };
                    el.onclick = null;
                    el.classList.remove('rc-mine-failed');
                } else {
                    markFailed(tempId, text, el);
                }
            }).catch(function () {
                delete _pendingTemp[tempId];
                markFailed(tempId, text);
            }).finally(function () {
                _sending = false;
                if (btn) btn.disabled = false;
            });
        },

        _retry: function (tempId) {
            var el = document.getElementById('rcm-' + tempId);
            if (!el) return;
            var text = el.getAttribute('data-body');
            if (!text) return;
            var slot = el.querySelector('.rc-tick-slot');
            if (slot) slot.innerHTML = ticksHTML('pending');
            el.classList.remove('rc-mine-failed');
            var hint = el.parentNode.querySelector('.rc-retry-hint');
            if (hint) hint.remove();
            el.onclick = null;
            _pendingTemp[tempId] = text;

            fetch(getAPI() + '?action=send_ride_message', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + getToken() },
                body: JSON.stringify({ ride_id: _rideId, body: text })
            }).then(function (r) { return r.json(); }).then(function (d) {
                delete _pendingTemp[tempId];
                var liveEl = document.getElementById('rcm-' + tempId);
                if (!liveEl) return; // poll() already adopted this bubble
                if (d.success && d.data) {
                    liveEl.id = 'rcm-' + d.data.id;
                    var s2 = liveEl.querySelector('.rc-tick-slot');
                    if (s2) s2.innerHTML = ticksHTML('sent');
                    _seen[String(d.data.id)] = { readAt: null, role: _role };
                } else {
                    markFailed(tempId, text, liveEl);
                }
            }).catch(function () {
                delete _pendingTemp[tempId];
                markFailed(tempId, text, document.getElementById('rcm-' + tempId));
            });
        },

        _quick: function (text) {
            var inp = document.getElementById('rc-input');
            if (inp) { inp.value = text; RideChat._input(inp); inp.focus(); }
        },

        _jump: function () { scrollToBottom(true); },

        _input: function (el) {
            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight, 100) + 'px';
            var count = document.getElementById('rc-count');
            if (count) {
                var len = el.value.length;
                if (len > 400) { count.textContent = len + '/500'; count.classList.add('rc-show'); }
                else { count.classList.remove('rc-show'); }
            }
            if (el.value.trim()) pingTyping();
        },

        _key: function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); RideChat.send(); }
        }
    };
}());

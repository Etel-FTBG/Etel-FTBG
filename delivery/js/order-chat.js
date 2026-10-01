/* ETEL — Order chat (customer · shop · courier)
   One group chat per order. Self-contained on purpose (own styles, own fetch) so it can be added to
   the customer, courier and shop apps without touching their versioned bundles.

   <script src="js/order-chat.js" data-mode="customer"></script>   customer app (no floating button)
   <script src="../js/order-chat.js" data-mode="layer" data-fab="1"></script>   courier / shop apps:
        a floating 💬 button + inbox of the person's open chats (shown only while they have any)

   API:  EtelOrderChat.open(orderId)   EtelOrderChat.openInbox()   EtelOrderChat.unread(orderId) -> Promise<number>
   data-mode: "customer" = light unless body.dark-theme;  "layer" = dark unless body.etel-light            */
(function () {
  if (window.EtelOrderChat) return;
  var script = document.currentScript;
  var MODE = (script && script.getAttribute('data-mode')) || 'layer';
  var WANT_FAB = !!(script && script.getAttribute('data-fab'));

  var TXT = {
    en: { chat: 'Chat', inbox: 'Your chats', empty: 'No open chats right now.', hint: 'Chats open while an order is active.', placeholder: 'Write a message…', send: 'Send',
          closed: 'This chat is closed.', noMsgs: 'No messages yet — say hello 👋', you: 'You', back: 'Back', loadErr: 'Could not load the chat.', sendErr: 'Could not send.', who: 'Customer, shop and courier can all see this chat.' },
    am: { chat: 'ውይይት', inbox: 'ውይይቶችዎ', empty: 'አሁን ክፍት ውይይት የለም።', hint: 'ትዕዛዝ ሲኖር ውይይት ክፍት ነው።', placeholder: 'መልእክት ይጻፉ…', send: 'ላክ',
          closed: 'ይህ ውይይት ተዘግቷል።', noMsgs: 'እስካሁን መልእክት የለም — ሰላም ይበሉ 👋', you: 'እርስዎ', back: 'ተመለስ', loadErr: 'ውይይቱን መጫን አልተቻለም።', sendErr: 'መላክ አልተቻለም።', who: 'ደንበኛ፣ ሱቅ እና ሹፌር ይህን ውይይት ማየት ይችላሉ።' }
  };
  var QUICK = {
    en: { customer: ["📍 I'm at the address", '📞 Please call me', '⏳ Give me 5 minutes', '👍 Thanks!'],
          courier:  ["🛵 I'm on my way", "📍 I've arrived", '📞 Please call me', '🕒 Running a few minutes late'],
          shop:     ['✅ We are preparing your order', '😕 An item is unavailable — substitute?', '📦 Your order is ready'], support: [] },
    am: { customer: ['📍 አድራሻው ላይ ነኝ', '📞 እባክዎ ይደውሉልኝ', '⏳ 5 ደቂቃ ይስጡኝ', '👍 አመሰግናለሁ!'], courier: [], shop: [], support: [] }
  };
  function lang() { try { return (window.ETEL_I18N && ETEL_I18N.current && ETEL_I18N.current() === 'am' && MODE === 'customer') ? 'am' : 'en'; } catch (e) { return 'en'; } }
  function t(k) { return TXT[lang()][k] || TXT.en[k] || k; }

  function apiUrl() {
    if (window.ETEL && ETEL.api) return ETEL.api;
    var path = location.pathname, base = path.replace(/\/delivery\/.*$/, '/delivery/');
    if (base === path) base = '/delivery/';
    return base + 'api/boot.php';
  }
  function token() { try { return (window.Auth && Auth.getToken) ? Auth.getToken() : localStorage.getItem('etel_token'); } catch (e) { return null; } }
  function call(action, data) {
    var body = Object.assign({ action: action }, data || {});
    return fetch(apiUrl(), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); }).catch(function () { return { success: false, message: 'Network error' }; });
  }
  function isLight() {
    var b = document.body;
    return MODE === 'customer' ? !b.classList.contains('dark-theme') : b.classList.contains('etel-light');
  }

  var css = '' +
    '#eoc-overlay{position:fixed;inset:0;z-index:100000;display:none;align-items:flex-end;justify-content:center;background:rgba(5,5,10,.6);backdrop-filter:blur(3px);font-family:"Poppins",system-ui,sans-serif}' +
    '#eoc-overlay.open{display:flex}' +
    '.eoc-sheet{width:min(480px,100%);height:min(88vh,720px);background:#12100a;color:#F5EDD8;border:1px solid rgba(212,175,55,.25);border-bottom:none;border-radius:20px 20px 0 0;display:flex;flex-direction:column;overflow:hidden}' +
    '.eoc-head{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid rgba(212,175,55,.18)}' +
    '.eoc-head b{font-size:15px}.eoc-head small{display:block;font-size:11px;opacity:.6;margin-top:1px}' +
    '.eoc-x{margin-left:auto;background:none;border:none;color:inherit;font-size:20px;cursor:pointer;opacity:.7}' +
    '.eoc-body{flex:1;overflow-y:auto;padding:14px 14px 6px;display:flex;flex-direction:column;gap:8px}' +
    '.eoc-note{font-size:11px;opacity:.55;text-align:center;padding:4px 10px}' +
    '.eoc-msg{max-width:82%;padding:8px 11px;border-radius:14px;font-size:13.5px;line-height:1.4;word-wrap:break-word;white-space:pre-wrap;background:rgba(255,255,255,.08)}' +
    '.eoc-msg.mine{align-self:flex-end;background:linear-gradient(135deg,#F0CA58,#D4AF37);color:#1a1400;border-bottom-right-radius:4px}' +
    '.eoc-msg:not(.mine){align-self:flex-start;border-bottom-left-radius:4px}' +
    '.eoc-who{display:block;font-size:10.5px;font-weight:800;opacity:.7;margin-bottom:2px;letter-spacing:.02em}' +
    '.eoc-time{display:block;font-size:10px;opacity:.55;margin-top:3px;text-align:right}' +
    '.eoc-quick{display:flex;gap:6px;overflow-x:auto;padding:6px 12px 2px;scrollbar-width:none}.eoc-quick::-webkit-scrollbar{display:none}' +
    '.eoc-quick button{flex:0 0 auto;padding:6px 11px;border-radius:999px;border:1px solid rgba(212,175,55,.4);background:transparent;color:inherit;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap}' +
    '.eoc-form{display:flex;gap:8px;padding:10px 12px calc(10px + env(safe-area-inset-bottom));border-top:1px solid rgba(212,175,55,.18)}' +
    '.eoc-form textarea{flex:1;resize:none;height:42px;max-height:100px;padding:10px 12px;border-radius:12px;border:1px solid rgba(212,175,55,.3);background:rgba(255,255,255,.06);color:inherit;font:inherit;font-size:14px}' +
    '.eoc-form button{padding:0 16px;border:none;border-radius:12px;background:linear-gradient(135deg,#F0CA58,#D4AF37);color:#1a1400;font-weight:800;cursor:pointer}' +
    '.eoc-form button:disabled,.eoc-form textarea:disabled{opacity:.45;cursor:not-allowed}' +
    '.eoc-row{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:12px;border:1px solid rgba(212,175,55,.22);background:rgba(255,255,255,.04);cursor:pointer}' +
    '.eoc-row .t{flex:1;min-width:0}.eoc-row .t b{font-size:13.5px}.eoc-row .t span{display:block;font-size:12px;opacity:.65;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}' +
    '.eoc-badge{min-width:20px;height:20px;padding:0 6px;border-radius:10px;background:#E63946;color:#fff;font-size:11px;font-weight:800;display:inline-flex;align-items:center;justify-content:center}' +
    '#eoc-fab{position:fixed;right:16px;bottom:92px;z-index:99990;width:52px;height:52px;border-radius:50%;border:none;background:linear-gradient(135deg,#F0CA58,#D4AF37);color:#1a1400;font-size:24px;cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.4);display:none;align-items:center;justify-content:center}' +
    '#eoc-fab .eoc-badge{position:absolute;top:-4px;right:-4px}' +
    '#eoc-overlay.light .eoc-sheet{background:#FFFFFF;color:#141108;border-color:rgba(20,17,8,.14)}' +
    '#eoc-overlay.light .eoc-head{border-color:rgba(20,17,8,.1)}#eoc-overlay.light .eoc-form{border-color:rgba(20,17,8,.1)}' +
    '#eoc-overlay.light .eoc-msg:not(.mine){background:#F1EEE4}' +
    '#eoc-overlay.light .eoc-form textarea{background:#FFFFFF;border-color:rgba(20,17,8,.25)}' +
    '#eoc-overlay.light .eoc-quick button{border-color:rgba(160,120,20,.5);color:#5C4000}' +
    '#eoc-overlay.light .eoc-row{background:#FAF8F2;border-color:rgba(20,17,8,.12)}';
  function injectCss() {
    if (document.getElementById('eoc-css')) return;
    var st = document.createElement('style'); st.id = 'eoc-css'; st.textContent = css; document.head.appendChild(st);
  }

  var overlay, sheet, pollTimer = null, cur = null;
  function ensureOverlay() {
    injectCss();
    if (overlay) return;
    overlay = document.createElement('div'); overlay.id = 'eoc-overlay';
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    sheet = document.createElement('div'); sheet.className = 'eoc-sheet';
    overlay.appendChild(sheet); document.body.appendChild(overlay);
  }
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  function show() { ensureOverlay(); overlay.classList.toggle('light', isLight()); overlay.classList.add('open'); }
  function close() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    cur = null;
    if (overlay) overlay.classList.remove('open');
    refreshFab();
  }
  function hhmm(ts) { try { var d = new Date(String(ts).replace(' ', 'T')); return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); } catch (e) { return ''; } }

  // ── thread ────────────────────────────────────────────────
  function open(orderId) {
    show(); sheet.innerHTML = '';
    var head = el('div', 'eoc-head'), ttl = el('div'); var b = el('b', null, '💬 ' + t('chat')); var sm = el('small', null, '');
    ttl.appendChild(b); ttl.appendChild(sm);
    var x = el('button', 'eoc-x', '✕'); x.setAttribute('aria-label', t('back')); x.onclick = close;
    head.appendChild(ttl); head.appendChild(x);
    var body = el('div', 'eoc-body'); var quick = el('div', 'eoc-quick');
    var form = el('div', 'eoc-form'), ta = el('textarea'); ta.placeholder = t('placeholder'); ta.maxLength = 500;
    var send = el('button', null, t('send')); form.appendChild(ta); form.appendChild(send);
    sheet.appendChild(head); sheet.appendChild(body); sheet.appendChild(quick); sheet.appendChild(form);
    body.appendChild(el('div', 'eoc-note', t('who')));
    cur = { id: orderId, last: 0, role: null, canSend: true, empty: true };
    var mine = cur;

    function setCanSend(ok) {
      mine.canSend = ok; ta.disabled = !ok; send.disabled = !ok;
      if (!ok && !body.querySelector('.eoc-closed')) body.appendChild(el('div', 'eoc-note eoc-closed', t('closed')));
    }
    function addMsgs(list) {
      var nearBottom = body.scrollHeight - body.scrollTop - body.clientHeight < 80;
      list.forEach(function (m) {
        if (m.id <= mine.last) return;
        if (mine.empty) { var n = body.querySelector('.eoc-empty'); if (n) n.remove(); mine.empty = false; }
        var d = el('div', 'eoc-msg' + (m.mine ? ' mine' : ''));
        if (!m.mine) d.appendChild(el('span', 'eoc-who', m.label));
        d.appendChild(document.createTextNode(m.body));
        d.appendChild(el('span', 'eoc-time', hhmm(m.created_at)));
        body.appendChild(d); mine.last = Math.max(mine.last, m.id);
      });
      if (list.length && (nearBottom || mine.last === list[list.length - 1].id)) body.scrollTop = body.scrollHeight;
    }
    function load(first) {
      return call('order_chat_get', { order_id: orderId, after_id: mine.last }).then(function (res) {
        if (cur !== mine) return;
        if (!res || !res.success) { if (first) body.appendChild(el('div', 'eoc-note', (res && res.message) || t('loadErr'))); return; }
        var d = res.data;
        sm.textContent = d.order.code;
        if (first) {
          mine.role = d.my_role;
          var qs = (QUICK[lang()] && QUICK[lang()][d.my_role]) || [];
          qs.forEach(function (q) { var qb = el('button', null, q); qb.type = 'button'; qb.onclick = function () { ta.value = q; doSend(); }; quick.appendChild(qb); });
          if (!d.messages.length) body.appendChild(el('div', 'eoc-note eoc-empty', t('noMsgs')));
          else mine.empty = false;
        }
        addMsgs(d.messages); setCanSend(!!d.can_send);
      });
    }
    function doSend() {
      var text = ta.value.trim(); if (!text || !mine.canSend) return;
      send.disabled = true; ta.value = '';
      call('order_chat_send', { order_id: orderId, body: text }).then(function (res) {
        send.disabled = !mine.canSend;
        if (!res || !res.success) { ta.value = text; var n = el('div', 'eoc-note', (res && res.message) || t('sendErr')); body.appendChild(n); setTimeout(function () { n.remove(); }, 4000); return; }
        load(false);
      });
    }
    send.onclick = doSend;
    ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); doSend(); } });
    load(true);
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(function () { if (document.visibilityState === 'visible' && cur === mine) load(false); }, 4000);
  }

  // ── inbox ─────────────────────────────────────────────────
  function openInbox() {
    show(); if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } cur = null;
    sheet.innerHTML = '';
    var head = el('div', 'eoc-head'); head.appendChild(el('b', null, '💬 ' + t('inbox')));
    var x = el('button', 'eoc-x', '✕'); x.onclick = close; head.appendChild(x);
    var body = el('div', 'eoc-body'); sheet.appendChild(head); sheet.appendChild(body);
    body.appendChild(el('div', 'eoc-note', '…'));
    call('order_chat_inbox', {}).then(function (res) {
      body.innerHTML = '';
      var chats = (res && res.success && res.data.chats) || [];
      if (!chats.length) { body.appendChild(el('div', 'eoc-note', t('empty'))); body.appendChild(el('div', 'eoc-note', t('hint'))); return; }
      chats.forEach(function (c) {
        var row = el('div', 'eoc-row'), tx = el('div', 't');
        tx.appendChild(el('b', null, c.order_code + ' · ' + String(c.status).replace('_', ' ')));
        tx.appendChild(el('span', null, c.last ? c.last.body : t('noMsgs')));
        row.appendChild(tx);
        if (c.unread > 0) row.appendChild(el('span', 'eoc-badge', String(c.unread)));
        row.onclick = function () { open(c.order_id); };
        body.appendChild(row);
      });
    });
  }

  // ── floating inbox button (courier / shop apps) ───────────
  var fab = null, fabTimer = null;
  function refreshFab() {
    if (!WANT_FAB || !token()) return;
    call('order_chat_inbox', {}).then(function (res) {
      if (!res || !res.success) return;
      var n = res.data.chats.length, unread = res.data.unread_total;
      if (!fab) {
        injectCss(); fab = el('button'); fab.id = 'eoc-fab'; fab.type = 'button'; fab.setAttribute('aria-label', t('inbox')); fab.textContent = '💬';
        fab.onclick = openInbox; document.body.appendChild(fab);
      }
      fab.style.display = n > 0 ? 'flex' : 'none';
      var bd = fab.querySelector('.eoc-badge');
      if (unread > 0) { if (!bd) { bd = el('span', 'eoc-badge'); fab.appendChild(bd); } bd.textContent = String(unread); }
      else if (bd) bd.remove();
    });
  }
  if (WANT_FAB) {
    var startFab = function () { refreshFab(); fabTimer = setInterval(function () { if (document.visibilityState === 'visible' && !(overlay && overlay.classList.contains('open'))) refreshFab(); }, 30000); };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', startFab); else startFab();
  }

  window.EtelOrderChat = {
    open: open, openInbox: openInbox, close: close,
    unread: function (orderId) { return call('order_chat_inbox', {}).then(function (res) { var n = 0; if (res && res.success) res.data.chats.forEach(function (c) { if (c.order_id === orderId) n = c.unread; }); return n; }); }
  };
})();

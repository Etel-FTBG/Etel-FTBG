// ============================================================
// ETEL AI — Embeddable Cross-Company Widget  v1.0
// Drop this ONE script tag into any Etel company page:
//
//   <script src="https://ethelmarket.com/EtelAi/etel-ai-widget.js"
//           data-project="gebeya"></script>
//
// It renders a floating "Etel AI" bubble. Clicking it opens a chat
// panel. If the visitor is already signed in with Etel-ID on this
// site (etelid_auth in localStorage — shared across every
// ethelmarket.com/* page since localStorage is per-origin, not
// per-path), the widget picks up their session automatically and
// the AI replies with awareness of which Etel companies they
// belong to.
// ============================================================

(() => {
  const _script  = document.currentScript || document.querySelector('script[src*="etel-ai-widget"]');
  const _project = _script?.dataset?.project || document.title || 'unknown';
  const _api     = _script?.dataset?.api || 'https://ethelmarket.com/EtelAi/api/chat.php';
  const KEY      = 'etelid_auth';

  const _getMember = () => {
    try {
      const d = JSON.parse(localStorage.getItem(KEY) || 'null');
      return d?.member || null;
    } catch { return null; }
  };
  const _getToken = () => {
    try { return JSON.parse(localStorage.getItem(KEY) || 'null')?.token || null; }
    catch { return null; }
  };

  const styles = `
    #etelai-bubble{position:fixed;bottom:22px;right:22px;width:58px;height:58px;border-radius:50%;
      background:linear-gradient(135deg,#C9A227,#FFD700);box-shadow:0 4px 24px rgba(212,175,55,.4);
      display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:99997;
      font-size:26px;transition:transform .15s;}
    #etelai-bubble:hover{transform:scale(1.06);}
    #etelai-panel{position:fixed;bottom:90px;right:22px;width:340px;max-width:92vw;height:460px;
      max-height:70vh;background:linear-gradient(150deg,#0d0a00 0%,#1a1200 100%);
      border:1px solid rgba(212,175,55,.5);border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.6);
      display:none;flex-direction:column;z-index:99998;font-family:monospace;overflow:hidden;}
    #etelai-panel.open{display:flex;}
    #etelai-head{padding:12px 14px;border-bottom:1px solid rgba(212,175,55,.2);display:flex;
      align-items:center;gap:8px;color:#FFD700;font-weight:800;font-size:13px;}
    #etelai-head small{color:#8B6914;font-weight:400;font-size:10px;margin-left:auto;}
    #etelai-msgs{flex:1;overflow-y:auto;padding:10px 12px;display:flex;flex-direction:column;gap:8px;}
    .etelai-msg{font-size:12px;line-height:1.4;padding:8px 10px;border-radius:8px;max-width:85%;}
    .etelai-msg.user{align-self:flex-end;background:rgba(212,175,55,.15);color:#FFF8E7;}
    .etelai-msg.ai{align-self:flex-start;background:rgba(255,255,255,.04);color:#e8e2d4;}
    .etelai-msg.sys{align-self:center;color:#8B6914;font-size:10px;}
    #etelai-inputrow{display:flex;gap:6px;padding:10px;border-top:1px solid rgba(212,175,55,.2);}
    #etelai-input{flex:1;background:rgba(255,215,0,.04);border:1px solid rgba(212,175,55,.25);
      border-radius:7px;color:#FFF8E7;font-size:12px;padding:8px 10px;font-family:inherit;outline:none;}
    #etelai-send{background:linear-gradient(135deg,#C9A227,#FFD700);border:none;border-radius:7px;
      color:#0d0800;font-weight:800;padding:0 14px;cursor:pointer;font-size:13px;}
    #etelai-send:disabled{opacity:.5;cursor:not-allowed;}
    .etelai-close{position:absolute;top:10px;right:12px;background:none;border:none;
      color:rgba(212,175,55,.5);font-size:16px;cursor:pointer;}
  `;

  const injectStyles = () => {
    if (document.getElementById('etelai-styles')) return;
    const s = document.createElement('style');
    s.id = 'etelai-styles';
    s.textContent = styles;
    document.head.appendChild(s);
  };

  let history = [];

  const addMsg = (role, text) => {
    const msgs = document.getElementById('etelai-msgs');
    const el = document.createElement('div');
    el.className = 'etelai-msg ' + (role === 'user' ? 'user' : role === 'sys' ? 'sys' : 'ai');
    el.textContent = text;
    msgs.appendChild(el);
    msgs.scrollTop = msgs.scrollHeight;
  };

  const send = async () => {
    const input = document.getElementById('etelai-input');
    const btn   = document.getElementById('etelai-send');
    const text  = input.value.trim();
    if (!text) return;
    addMsg('user', text);
    history.push({ role: 'user', content: text });
    input.value = '';
    btn.disabled = true;
    addMsg('sys', 'Thinking…');
    const msgs = document.getElementById('etelai-msgs');

    try {
      const res = await fetch(_api, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          message: text,
          project: _project,
          token: _getToken(),
          history: history.slice(-10),
        }),
      });
      const data = await res.json();
      msgs.lastChild.remove(); // remove "Thinking…"
      if (data.ok) {
        addMsg('ai', data.data.reply);
        history.push({ role: 'assistant', content: data.data.reply });
      } else {
        addMsg('sys', '❌ ' + (data.error || 'Something went wrong.'));
      }
    } catch {
      msgs.lastChild.remove();
      addMsg('sys', '❌ Connection error. Please try again.');
    }
    btn.disabled = false;
    input.focus();
  };

  const open = () => {
    injectStyles();
    let panel = document.getElementById('etelai-panel');
    if (!panel) {
      panel = document.createElement('div');
      panel.id = 'etelai-panel';
      const member = _getMember();
      const greeting = member
        ? `Hi ${member.full_name?.split(' ')[0] || 'there'}! I'm ETEL AI — ask me anything about your Etel companies.`
        : `Hi! I'm ETEL AI. Sign in with Etel-ID on this page for personalized help, or just ask me a question.`;
      panel.innerHTML = `
        <button class="etelai-close" onclick="document.getElementById('etelai-panel').classList.remove('open')">✕</button>
        <div id="etelai-head">🤖 ETEL AI <small>${_project}</small></div>
        <div id="etelai-msgs"></div>
        <div id="etelai-inputrow">
          <input id="etelai-input" type="text" placeholder="Ask ETEL AI…">
          <button id="etelai-send">➤</button>
        </div>`;
      document.body.appendChild(panel);
      addMsg('sys', greeting);
      document.getElementById('etelai-send').onclick = send;
      document.getElementById('etelai-input').addEventListener('keydown', e => {
        if (e.key === 'Enter') send();
      });
    }
    panel.classList.toggle('open');
  };

  const init = () => {
    injectStyles();
    const bubble = document.createElement('div');
    bubble.id = 'etelai-bubble';
    bubble.textContent = '🤖';
    bubble.title = 'Chat with ETEL AI';
    bubble.onclick = open;
    document.body.appendChild(bubble);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

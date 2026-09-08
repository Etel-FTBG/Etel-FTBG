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
      background:linear-gradient(135deg,#C8960C,#D4AF37);box-shadow:0 4px 24px rgba(212,175,55,.45);
      display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:99997;
      font-size:26px;transition:transform .15s;}
    #etelai-bubble:hover{transform:scale(1.06);}
    #etelai-panel{position:fixed;bottom:90px;right:22px;width:350px;max-width:92vw;height:480px;
      max-height:72vh;background:linear-gradient(165deg,#130d05 0%,#0a0703 100%);
      border:1px solid rgba(212,175,55,.35);border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.65);
      display:none;flex-direction:column;z-index:99998;overflow:hidden;
      font-family:'Segoe UI',system-ui,-apple-system,sans-serif;}
    #etelai-panel::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;
      background:linear-gradient(90deg,#5A3A08,#C8922A,#D4AF37,#C8922A,#5A3A08);}
    #etelai-panel::after{content:'';position:absolute;top:0;bottom:0;right:14px;width:2px;
      background:linear-gradient(180deg,transparent,rgba(212,175,55,.28),transparent);pointer-events:none;}
    #etelai-panel.open{display:flex;}
    #etelai-head{padding:13px 15px;border-bottom:1px solid rgba(212,175,55,.2);display:flex;
      align-items:center;gap:9px;color:#F2CC6B;font-weight:800;font-size:14px;letter-spacing:.4px;}
    #etelai-head-logo{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;
      border-radius:50%;background:linear-gradient(135deg,#C8960C,#D4AF37);color:#0d0d0d;
      font-size:11px;font-weight:800;}
    #etelai-head small{color:#8B6914;font-weight:400;font-size:10px;margin-left:auto;
      font-family:'JetBrains Mono',monospace;}
    #etelai-msgs{flex:1;overflow-y:auto;padding:12px 14px;display:flex;flex-direction:column;gap:9px;}
    .etelai-msg{font-size:12.5px;line-height:1.5;padding:9px 12px;border-radius:11px;max-width:86%;
      word-wrap:break-word;}
    .etelai-msg.user{align-self:flex-end;background:linear-gradient(135deg,rgba(200,150,12,.22),rgba(212,175,55,.14));
      border:1px solid rgba(212,175,55,.25);color:#FFF8E7;border-bottom-right-radius:4px;}
    .etelai-msg.ai{align-self:flex-start;background:rgba(255,255,255,.045);
      border:1px solid rgba(255,255,255,.07);color:#e8e2d4;border-bottom-left-radius:4px;}
    .etelai-msg.sys{align-self:center;color:#A07820;font-size:10.5px;text-align:center;max-width:94%;}
    #etelai-inputrow{display:flex;gap:7px;padding:11px;border-top:1px solid rgba(212,175,55,.2);
      background:rgba(0,0,0,.25);}
    #etelai-input{flex:1;background:rgba(255,215,0,.045);border:1px solid rgba(212,175,55,.25);
      border-radius:9px;color:#FFF8E7;font-size:12.5px;padding:9px 11px;font-family:inherit;outline:none;
      transition:border-color .15s;}
    #etelai-input:focus{border-color:rgba(212,175,55,.55);}
    #etelai-send{background:linear-gradient(135deg,#C8960C,#D4AF37);border:none;border-radius:9px;
      color:#0d0d0d;font-weight:800;padding:0 15px;cursor:pointer;font-size:14px;}
    #etelai-send:disabled{opacity:.5;cursor:not-allowed;}
    .etelai-close{position:absolute;top:11px;right:24px;background:none;border:none;
      color:rgba(212,175,55,.5);font-size:16px;cursor:pointer;z-index:2;}
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
    addMsg('sys', '· · ·');
    const msgs = document.getElementById('etelai-msgs');

    try {
      const res = await fetch(_api, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          message: text,
          project: _project,
          token: _getToken(),
          history: history.slice(-10), // used server-side only for signed-out visitors
        }),
      });
      const data = await res.json();
      msgs.lastChild.remove(); // remove typing indicator
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

  // Restore the member's recent conversation from the server so the
  // assistant picks up where they left off - on any device, any Etel
  // page - instead of greeting them as a stranger every time.
  const restoreHistory = async () => {
    const token = _getToken();
    if (!token) return false;
    try {
      const res  = await fetch(_api + '?action=history', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token, project: _project }),
      });
      const data = await res.json();
      if (data.ok && data.data.messages && data.data.messages.length) {
        for (const m of data.data.messages) {
          addMsg(m.role === 'user' ? 'user' : 'ai', m.content);
          history.push({ role: m.role, content: m.content });
        }
        addMsg('sys', '— continuing from your last conversation —');
        return true;
      }
    } catch { /* fall through to fresh greeting */ }
    return false;
  };

  const open = async () => {
    injectStyles();
    let panel = document.getElementById('etelai-panel');
    if (!panel) {
      panel = document.createElement('div');
      panel.id = 'etelai-panel';
      const member = _getMember();
      panel.innerHTML = `
        <button class="etelai-close" onclick="document.getElementById('etelai-panel').classList.remove('open')">✕</button>
        <div id="etelai-head"><span id="etelai-head-logo">ኢE</span> ETEL AI <small>${_project}</small></div>
        <div id="etelai-msgs"></div>
        <div id="etelai-inputrow">
          <input id="etelai-input" type="text" placeholder="Ask ETEL AI… / ኢቴል AI ይጠይቁ…">
          <button id="etelai-send">➤</button>
        </div>`;
      document.body.appendChild(panel);
      document.getElementById('etelai-send').onclick = send;
      document.getElementById('etelai-input').addEventListener('keydown', e => {
        if (e.key === 'Enter') send();
      });
      panel.classList.add('open');

      const restored = await restoreHistory();
      if (!restored) {
        const greeting = member
          ? `Welcome back, ${member.full_name?.split(' ')[0] || 'there'}! I'm ETEL AI — I remember our conversations, so just pick up where we left off. Tip: say "remember ..." and I'll keep it in mind for next time.`
          : `Hi! I'm ETEL AI. Sign in with Etel-ID for personalized help that remembers you — or just ask me a question.`;
        addMsg('sys', greeting);
      }
      return;
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

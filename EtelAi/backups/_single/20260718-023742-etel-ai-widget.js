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
  const _modelsApi = _api.replace(/chat\.php$/, 'models.php');
  const KEY      = 'etelid_auth';
  const MODEL_KEY = 'etelai_model_pref';

  let _models        = [];
  let _defaultEffort = 'Medium';
  let _selected       = null;   // { modelId, effort }

  const _loadModelPref = () => {
    try { return JSON.parse(localStorage.getItem(MODEL_KEY) || 'null'); } catch { return null; }
  };
  const _saveModelPref = () => {
    try { localStorage.setItem(MODEL_KEY, JSON.stringify(_selected)); } catch {}
  };

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

    /* Model / effort picker - Claude-style dropdown, data-driven not hardcoded */
    #etelai-picker-row{display:flex;align-items:center;gap:6px;padding:7px 11px 0;position:relative;}
    #etelai-model-pill{display:flex;align-items:center;gap:5px;background:rgba(212,175,55,.08);
      border:1px solid rgba(212,175,55,.22);border-radius:7px;color:#D4AF37;font-size:10.5px;
      font-weight:700;padding:4px 9px;cursor:pointer;font-family:'JetBrains Mono',monospace;
      max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    #etelai-model-pill svg{width:9px;height:9px;flex-shrink:0;}
    #etelai-model-dd{position:absolute;bottom:32px;left:11px;width:250px;max-height:320px;overflow-y:auto;
      background:linear-gradient(165deg,#1a1408 0%,#100c04 100%);border:1px solid rgba(212,175,55,.3);
      border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,.6);display:none;z-index:5;padding:6px;}
    #etelai-model-dd.open{display:block;}
    .etelai-dd-group{font-size:9.5px;color:#8B6914;font-weight:700;text-transform:uppercase;
      letter-spacing:.5px;padding:8px 8px 4px;}
    .etelai-dd-opt{display:flex;align-items:flex-start;gap:8px;padding:7px 8px;border-radius:8px;
      cursor:pointer;}
    .etelai-dd-opt:hover{background:rgba(212,175,55,.08);}
    .etelai-dd-opt.disabled{opacity:.4;cursor:not-allowed;}
    .etelai-dd-check{width:13px;flex-shrink:0;margin-top:2px;color:#D4AF37;}
    .etelai-dd-name{font-size:12px;font-weight:700;color:#F5EAC8;}
    .etelai-dd-desc{font-size:10px;color:#8a8578;margin-top:1px;}
    .etelai-dd-sep{height:1px;background:rgba(212,175,55,.12);margin:6px 4px;}
    .etelai-dd-effort-row{display:flex;gap:4px;flex-wrap:wrap;padding:6px 8px 8px;}
    .etelai-effort-btn{font-size:10px;font-weight:700;padding:4px 9px;border-radius:6px;cursor:pointer;
      background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);color:#A8A8B8;}
    .etelai-effort-btn.active{background:linear-gradient(135deg,#C8960C,#D4AF37);color:#0d0d0d;
      border-color:transparent;}
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

  const loadModels = async () => {
    try {
      const res  = await fetch(_modelsApi);
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'models.php error');
      _models        = data.data.models || [];
      _defaultEffort = data.data.default_effort || 'Medium';
    } catch {
      // Degrade honestly rather than pretend a picker exists - the chat
      // still works, chat.php just falls back to its own default model.
      document.getElementById('etelai-model-pill-label').textContent = 'Default model';
      document.getElementById('etelai-model-pill').disabled = true;
      return;
    }
    const pref = _loadModelPref();
    const preferred = pref && _models.find(m => m.id === pref.modelId);
    const fallback   = _models.find(m => m.default) || _models[0];
    const chosen     = preferred || fallback;
    _selected = {
      modelId: chosen ? chosen.id : null,
      effort:  (pref && pref.effort) || _defaultEffort,
    };
    renderPickerLabel();
    renderPickerDropdown();
  };

  const renderPickerLabel = () => {
    const m = _models.find(x => x.id === _selected?.modelId);
    const label = document.getElementById('etelai-model-pill-label');
    if (label) label.textContent = m ? `${m.label} · ${_selected.effort}` : 'Choose model';
  };

  const renderPickerDropdown = () => {
    const dd = document.getElementById('etelai-model-dd');
    if (!dd) return;
    const groups = {};
    for (const m of _models) (groups[m.group || 'Models'] ??= []).push(m);

    let html = '';
    for (const [group, list] of Object.entries(groups)) {
      html += `<div class="etelai-dd-group">${group}</div>`;
      for (const m of list) {
        const checked  = m.id === _selected?.modelId;
        const disabled = m.available === false;
        html += `
          <div class="etelai-dd-opt${disabled ? ' disabled' : ''}" ${disabled ? '' : `onclick="window.__etelaiSelectModel('${m.id}')"`}>
            <span class="etelai-dd-check">${checked ? '✓' : ''}</span>
            <div>
              <div class="etelai-dd-name">${m.label}</div>
              <div class="etelai-dd-desc">${disabled ? 'Not configured yet — ' : ''}${m.desc || ''}</div>
            </div>
          </div>`;
      }
    }
    const curModel = _models.find(x => x.id === _selected?.modelId);
    const levels = curModel?.effort_levels ? Object.keys(curModel.effort_levels) : [];
    if (levels.length) {
      html += `<div class="etelai-dd-sep"></div><div class="etelai-dd-group">Effort</div>`;
      html += `<div class="etelai-dd-effort-row">` + levels.map(lv =>
        `<button class="etelai-effort-btn${lv === _selected?.effort ? ' active' : ''}" onclick="window.__etelaiSelectEffort('${lv}')">${lv}</button>`
      ).join('') + `</div>`;
    }
    dd.innerHTML = html;
  };

  window.__etelaiTogglePicker = () => {
    document.getElementById('etelai-model-dd')?.classList.toggle('open');
  };
  window.__etelaiSelectModel = (modelId) => {
    const m = _models.find(x => x.id === modelId);
    if (!m) return;
    const levels = m.effort_levels ? Object.keys(m.effort_levels) : [];
    _selected = { modelId, effort: levels.includes(_selected?.effort) ? _selected.effort : (levels[0] || _defaultEffort) };
    _saveModelPref();
    renderPickerLabel();
    renderPickerDropdown();
  };
  window.__etelaiSelectEffort = (effort) => {
    _selected = { ..._selected, effort };
    _saveModelPref();
    renderPickerLabel();
    renderPickerDropdown();
    document.getElementById('etelai-model-dd')?.classList.remove('open');
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
          model: _selected?.modelId || undefined,
          effort: _selected?.effort || undefined,
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
        <div id="etelai-picker-row">
          <button id="etelai-model-pill" onclick="window.__etelaiTogglePicker()">
            <span id="etelai-model-pill-label">Loading models…</span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
          <div id="etelai-model-dd"></div>
        </div>
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
      loadModels();

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
    document.addEventListener('click', (e) => {
      const dd = document.getElementById('etelai-model-dd');
      const pill = document.getElementById('etelai-model-pill');
      if (dd?.classList.contains('open') && !dd.contains(e.target) && e.target !== pill && !pill?.contains(e.target)) {
        dd.classList.remove('open');
      }
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

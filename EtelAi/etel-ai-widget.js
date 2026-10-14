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
  // Real cross-app SSO key - set by Delivery/Ride's Auth.save() after an
  // EtelID login (Etelid_sessions table token, same mechanism the EtelID
  // portal's own login uses). The old 'etelid_auth' key was never
  // actually written by anything - dead on arrival.
  const KEY      = 'etel_sso';

  // ---- COMING SOON switch --------------------------------------------------
  // true  = every Etel page shows the ETEL AI bubble with a "Soon" tag; tapping it opens a
  //         "Coming Soon" card. No chat, no location lookup, no calls to the AI server.
  // false = the normal chat widget. (Staff can test the live chat on any page by adding
  //         ?etelai=live to the address.)
  const COMING_SOON = true;
  const _live = !COMING_SOON || /[?&]etelai=live\b/.test(location.search);

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

  let _lastKnownPosition = null; // best-effort, only if the person allows it
  let _micRecognition = null;
  let _isListening = false;

  // Voice input: tap the mic, speak, and it sends automatically once
  // done - no need to review/edit the text first, matching how someone
  // would rather talk than type. Uses the browser's built-in Web Speech
  // API (same approach already working in the ETEL Agent OS interface),
  // so no server cost and no extra API key needed.
  function toggleMic() {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    const micBtn = document.getElementById('etelai-mic');
    if (!SR) { alert('Voice input is not supported in this browser. Try Chrome or Edge.'); return; }
    if (_isListening) { _micRecognition && _micRecognition.stop(); return; }

    _micRecognition = new SR();
    _micRecognition.continuous = false;
    _micRecognition.interimResults = true;
    // Amharic speech recognition isn't reliably supported across
    // browsers yet - defaults to English, but respects the device's own
    // language setting if it's already Amharic.
    _micRecognition.lang = (navigator.language || '').toLowerCase().startsWith('am') ? 'am-ET' : 'en-US';

    _micRecognition.onstart = () => {
      _isListening = true;
      if (micBtn) { micBtn.classList.add('listening'); micBtn.title = 'Listening… tap to stop'; }
    };
    _micRecognition.onresult = (e) => {
      let interim = '', final = '';
      for (const r of e.results) {
        if (r.isFinal) final += r[0].transcript;
        else interim += r[0].transcript;
      }
      const inp = document.getElementById('etelai-input');
      if (inp) inp.value = final || interim;
    };
    _micRecognition.onend = () => {
      _isListening = false;
      if (micBtn) { micBtn.classList.remove('listening'); micBtn.title = 'Voice input'; }
      const inp = document.getElementById('etelai-input');
      // Auto-send the moment recognition finishes - this is the whole
      // point of voice input here: speak, done, no extra tap needed.
      if (inp && inp.value.trim()) setTimeout(() => send(), 150);
    };
    _micRecognition.onerror = (e) => {
      _isListening = false;
      if (micBtn) { micBtn.classList.remove('listening'); micBtn.title = 'Voice input'; }
      // Previously silent - the person would tap the mic, see nothing
      // happen, and have no idea whether it was denied permission, found
      // no mic, or just didn't catch any speech. Now says what happened.
      let msg = '';
      if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
        msg = 'Microphone access is blocked. Please allow microphone permission for this site in your browser settings, then try again.';
      } else if (e.error === 'no-speech') {
        msg = "Didn't catch that - tap the mic and try speaking again.";
      } else if (e.error === 'audio-capture') {
        msg = 'No microphone was found on this device.';
      } else if (e.error === 'network') {
        msg = 'Voice recognition needs an internet connection - please check your connection and try again.';
      } else {
        msg = 'Voice input had a problem (' + e.error + '). Please try again or type instead.';
      }
      addMsg('sys', msg);
    };
    try {
      _micRecognition.start();
    } catch (e) {
      // A site-wide Permissions-Policy header can block the microphone
      // feature entirely for the document - start() then throws
      // SYNCHRONOUSLY, before onerror/onstart ever run, so without this
      // catch the tap just silently did nothing with no explanation.
      _isListening = false;
      if (micBtn) micBtn.classList.remove('listening');
      addMsg('sys', 'Voice input is currently disabled by a site security setting (Permissions-Policy). This needs to be fixed at the server level.');
    }
  }

  let _locationPromise = null;
  const _tryGetLocation = () => {
    if (!navigator.geolocation) return Promise.resolve(null);
    // Reuse any already-in-flight request instead of starting a second
    // one every time this is called (init, panel open, after each send).
    if (_locationPromise) return _locationPromise;
    _locationPromise = new Promise((resolve) => {
      navigator.geolocation.getCurrentPosition(
        (pos) => {
          _lastKnownPosition = { lat: pos.coords.latitude, lng: pos.coords.longitude };
          _locationPromise = null;
          resolve(_lastKnownPosition);
        },
        () => { _locationPromise = null; resolve(null); /* denied or unavailable */ },
        { maximumAge: 60000, timeout: 8000 }
      );
    });
    return _locationPromise;
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
    #etelai-mic{background:rgba(255,255,255,.045);border:1px solid rgba(212,175,55,.25);border-radius:9px;
      color:#D4AF37;padding:0 12px;cursor:pointer;font-size:14px;flex-shrink:0;transition:all .15s;}
    #etelai-mic:hover{border-color:rgba(212,175,55,.5);}
    #etelai-mic.listening{background:rgba(220,38,38,.15);border-color:#dc2626;color:#ff6b6b;
      animation:etelaiMicPulse 1s ease-in-out infinite;}
    @keyframes etelaiMicPulse{0%,100%{opacity:1;}50%{opacity:.55;}}
    .etelai-close{position:absolute;top:11px;right:24px;background:none;border:none;
      color:rgba(212,175,55,.5);font-size:16px;cursor:pointer;z-index:2;}
    #etelai-clear{position:absolute;top:9px;right:52px;background:none;border:none;
      color:rgba(212,175,55,.5);font-size:14px;cursor:pointer;z-index:2;padding:4px;}
    #etelai-clear:hover{color:rgba(212,175,55,.9);}
    #etelai-bubble .etelai-soon{position:absolute;top:-6px;right:-8px;background:#0a0703;color:#D4AF37;
      border:1px solid rgba(212,175,55,.7);border-radius:9px;font:700 9px/1 system-ui,sans-serif;
      letter-spacing:.06em;text-transform:uppercase;padding:3px 6px;box-shadow:0 2px 8px rgba(0,0,0,.4);}
    #etelai-soon-card{position:fixed;bottom:90px;right:22px;width:300px;max-width:92vw;box-sizing:border-box;
      background:linear-gradient(165deg,#130d05 0%,#0a0703 100%);border:1px solid rgba(212,175,55,.35);
      border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.65);z-index:99998;padding:22px 20px 20px;
      text-align:center;color:#f3e7c0;font-family:system-ui,-apple-system,sans-serif;display:none;}
    #etelai-soon-card.open{display:block;}
    #etelai-soon-card .etelai-soon-logo{width:54px;height:54px;border-radius:50%;margin:0 auto 12px;
      background:linear-gradient(135deg,#C8960C,#D4AF37);display:flex;align-items:center;justify-content:center;
      font-size:26px;}
    #etelai-soon-card h3{margin:0 0 4px;font-size:17px;color:#D4AF37;letter-spacing:.02em;}
    #etelai-soon-card .etelai-soon-tag{display:inline-block;margin:2px 0 10px;padding:3px 10px;border-radius:10px;
      border:1px solid rgba(212,175,55,.6);color:#D4AF37;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;}
    #etelai-soon-card p{margin:0 0 6px;font-size:13px;line-height:1.5;color:rgba(243,231,192,.85);}
    #etelai-soon-card .etelai-soon-x{position:absolute;top:8px;right:12px;background:none;border:none;
      color:rgba(212,175,55,.6);font-size:16px;cursor:pointer;}
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

    // If we don't have a position cached yet (e.g. this is the very
    // first message and the page-load location request hasn't
    // resolved), wait briefly for one now rather than sending with an
    // empty pickup and having the AI wrongly conclude location is
    // unavailable when it's actually just still loading.
    if (!_lastKnownPosition) {
      await _tryGetLocation();
    }

    try {
      const res = await fetch(_api, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          message: text,
          project: _project,
          token: _getToken(),
          history: history.slice(-10), // used server-side only for signed-out visitors
          pickup_lat: _lastKnownPosition?.lat,
          pickup_lng: _lastKnownPosition?.lng,
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
    _tryGetLocation(); // refresh in the background for next time
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
    _tryGetLocation(); // kick off/reuse a fetch now, well before the first message is likely sent
    let panel = document.getElementById('etelai-panel');
    if (!panel) {
      panel = document.createElement('div');
      panel.id = 'etelai-panel';
      const member = _getMember();
      panel.innerHTML = `
        <button class="etelai-close" onclick="document.getElementById('etelai-panel').classList.remove('open')">✕</button>
        <button id="etelai-clear" title="Start a fresh chat">🗑️</button>
        <div id="etelai-head"><span id="etelai-head-logo">ኢE</span> ETEL AI <small>${_project}</small></div>
        <div id="etelai-msgs"></div>
        <div id="etelai-inputrow">
          <input id="etelai-input" type="text" placeholder="Ask ETEL AI… / ኢቴል AI ይጠይቁ…">
          <button id="etelai-mic" title="Voice input">🎤</button>
          <button id="etelai-send">➤</button>
        </div>`;
      document.body.appendChild(panel);
      document.getElementById('etelai-send').onclick = send;
      document.getElementById('etelai-mic').onclick = toggleMic;
      document.getElementById('etelai-clear').onclick = clearChat;
      document.getElementById('etelai-input').addEventListener('keydown', e => {
        if (e.key === 'Enter') send();
      });
      panel.classList.add('open');

      const restored = await restoreHistory();
      if (!restored) showFreshGreeting();
      return;
    }
    panel.classList.toggle('open');
  };

  function showFreshGreeting() {
    const member = _getMember();
    const greeting = member
      ? `Welcome back, ${member.full_name?.split(' ')[0] || 'there'}! I'm ETEL AI — I remember our conversations, so just pick up where we left off. Tip: say "remember ..." and I'll keep it in mind for next time.`
      : `Hi! I'm ETEL AI. Sign in with Etel-ID for personalized help that remembers you — or just ask me a question.`;
    addMsg('sys', greeting);
  }

  // "Start a fresh chat" - wipes this member's saved conversation history
  // server-side (not their remembered facts/preferences, just the
  // back-and-forth) and resets the panel, since reopening the widget was
  // always showing the old conversation with no way to clear it.
  async function clearChat() {
    if (!confirm('Start a fresh chat? This clears your saved conversation history (not things you asked ETEL AI to remember).')) return;
    const token = _getToken();
    if (token) {
      try {
        await fetch(_api, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'clear_history', token, project: _project }),
        });
      } catch { /* clear the visible chat regardless of network hiccups */ }
    }
    history.length = 0;
    const msgs = document.getElementById('etelai-msgs');
    if (msgs) msgs.innerHTML = '';
    showFreshGreeting();
  }

  const openSoon = () => {
    let card = document.getElementById('etelai-soon-card');
    if (!card) {
      card = document.createElement('div');
      card.id = 'etelai-soon-card';
      card.innerHTML = `
        <button class="etelai-soon-x" aria-label="Close" onclick="document.getElementById('etelai-soon-card').classList.remove('open')">✕</button>
        <div class="etelai-soon-logo">🤖</div>
        <h3>ETEL AI</h3>
        <div class="etelai-soon-tag">Coming Soon</div>
        <p>Your smart ETEL assistant is on the way. We are putting the finishing touches on it.</p>
        <p lang="am">ብልሁ የኢቴል ረዳትዎ በቅርቡ ይመጣል።</p>`;
      document.body.appendChild(card);
    }
    card.classList.toggle('open');
  };

  const init = () => {
    injectStyles();
    const bubble = document.createElement('div');
    bubble.id = 'etelai-bubble';
    bubble.textContent = '🤖';
    if (_live) {
      _tryGetLocation();
      bubble.title = 'Chat with ETEL AI';
      bubble.onclick = open;
    } else {
      bubble.title = 'ETEL AI - Coming Soon';
      bubble.setAttribute('role', 'button');
      bubble.setAttribute('aria-label', 'ETEL AI - Coming Soon');
      const tag = document.createElement('span');
      tag.className = 'etelai-soon';
      tag.textContent = 'Soon';
      bubble.appendChild(tag);
      bubble.style.position = 'fixed';
      bubble.onclick = openSoon;
    }
    document.body.appendChild(bubble);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

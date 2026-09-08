/* ============================================================
   ETEL AI — customer.html chat logic
   ============================================================ */

const chatWindow = document.getElementById('chat-window');
let history = [];
let lastKnownPosition = null; // { lat, lng } - best-effort, only if the person allows it

function tryGetLocation() {
  if (!navigator.geolocation) return;
  navigator.geolocation.getCurrentPosition(
    (pos) => { lastKnownPosition = { lat: pos.coords.latitude, lng: pos.coords.longitude }; },
    () => { /* denied or unavailable - ride booking just won't work until they allow it */ },
    { maximumAge: 60000, timeout: 8000 }
  );
}

function addMsg(role, text) {
  const el = document.createElement('div');
  el.className = 'msg ' + role;
  el.textContent = text;
  chatWindow.appendChild(el);
  chatWindow.scrollTop = chatWindow.scrollHeight;
  return el;
}

async function restoreHistory() {
  const token = etelaiGetToken();
  if (!token) return false;
  const res = await etelaiApi('chat.php?action=history', { token, project: 'customer-page' });
  if (res.ok && res.data.messages?.length) {
    for (const m of res.data.messages) {
      addMsg(m.role === 'user' ? 'user' : 'ai', m.content);
      history.push({ role: m.role, content: m.content });
    }
    addMsg('sys', '— continuing from your last conversation —');
    return true;
  }
  return false;
}

async function send() {
  const input = document.getElementById('msg-input');
  const btn = document.getElementById('send-btn');
  const text = input.value.trim();
  if (!text) return;
  addMsg('user', text);
  history.push({ role: 'user', content: text });
  input.value = '';
  btn.disabled = true;
  const thinking = addMsg('sys', '· · ·');

  const res = await etelaiApi('chat.php', {
    message: text,
    project: 'customer-page',
    token: etelaiGetToken(),
    history: history.slice(-10),
    pickup_lat: lastKnownPosition?.lat,
    pickup_lng: lastKnownPosition?.lng,
  });

  thinking.remove();
  if (res.ok) {
    addMsg('ai', res.data.reply);
    history.push({ role: 'assistant', content: res.data.reply });
  } else {
    addMsg('sys', '❌ ' + res.error);
  }
  btn.disabled = false;
  input.focus();
  tryGetLocation(); // refresh in the background for next time
}

function initMemberChip() {
  const member = etelaiGetMember();
  const chip = document.getElementById('member-chip');
  chip.textContent = member?.full_name ? member.full_name : 'Not signed in';
}

(async function init() {
  initMemberChip();
  tryGetLocation();
  document.getElementById('send-btn').onclick = send;
  document.getElementById('msg-input').addEventListener('keydown', e => {
    if (e.key === 'Enter') send();
  });

  const restored = await restoreHistory();
  if (!restored) {
    const member = etelaiGetMember();
    addMsg('sys', member
      ? `Welcome back, ${member.full_name?.split(' ')[0] || 'there'}! Say "remember ..." and I'll keep it in mind for next time.`
      : 'Hi! Sign in with Etel-ID for personalized help that remembers you across every Etel company.');
  }
})();

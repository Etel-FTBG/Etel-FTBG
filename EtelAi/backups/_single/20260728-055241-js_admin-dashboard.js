/* ============================================================
   ETEL AI — admin.html dashboard logic
   ============================================================ */

function switchPanel(panelId, btn) {
  document.querySelectorAll('.panel').forEach(p => p.style.display = 'none');
  document.getElementById(panelId).style.display = 'block';
  document.querySelectorAll('.side-link').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  if (panelId === 'panel-memory') loadStats();
}

async function loadProviders() {
  const wrap = document.getElementById('provider-list');
  wrap.innerHTML = '<div style="padding:14px;color:var(--text-dim);font-size:12.5px;">Loading...</div>';
  const res = await etelaiApiGet('providers.php');
  if (!res.ok) {
    wrap.innerHTML = '<div style="padding:14px;color:var(--red);font-size:12.5px;">' + res.error + '</div>';
    return;
  }
  wrap.innerHTML = res.data.providers.map(p => `
    <div class="provider-row">
      <span class="provider-name">${p.name}</span>
      <div class="toggle ${p.enabled ? 'on' : ''}" data-id="${p.id}" onclick="onToggleProvider(this)">
        <div class="knob"></div>
      </div>
    </div>
  `).join('');
}

async function onToggleProvider(el) {
  const id = el.dataset.id;
  const nextEnabled = !el.classList.contains('on');
  const msgEl = document.getElementById('provider-msg');
  // Already proved admin identity by having a valid EtelID session that
  // got past this dashboard's own gate - reuse that token instead of a
  // second, separately-typed secret.
  const token = etelaiGetToken();
  if (!token) { msgEl.textContent = 'Please sign in again to change providers.'; msgEl.className = 'panel-msg err'; return; }

  const res = await etelaiApi('providers.php', { token, id, enabled: nextEnabled });
  if (res.ok) {
    el.classList.toggle('on', nextEnabled);
    msgEl.textContent = `${id} is now ${nextEnabled ? 'ON' : 'OFF'}.`;
    msgEl.className = 'panel-msg ok';
  } else {
    msgEl.textContent = 'Could not save: ' + res.error;
    msgEl.className = 'panel-msg err';
  }
}

async function loadStats() {
  const wrap = document.getElementById('stats-grid');
  wrap.innerHTML = '<div style="padding:14px;color:var(--text-dim);font-size:12.5px;">Loading...</div>';
  const token = etelaiGetToken();
  if (!token) { wrap.innerHTML = '<div style="padding:14px;color:var(--red);font-size:12.5px;">Please sign in again.</div>'; return; }

  const res = await etelaiApi('stats.php', { token });
  if (!res.ok) {
    wrap.innerHTML = '<div style="padding:14px;color:var(--red);font-size:12.5px;">' + res.error + '</div>';
    return;
  }
  const s = res.data;
  if (!s.memory_installed) {
    wrap.innerHTML = '<div class="card stat-card" style="grid-column:1/-1;">' +
      '<div style="font-size:13px;color:var(--text);">Memory tables are not installed yet.</div>' +
      '<div style="font-size:11.5px;color:var(--text-dim);margin-top:6px;">Run <code>api/memory-install.php?key=YOUR_ADMIN_KEY</code> once.</div></div>';
    return;
  }
  wrap.innerHTML = `
    <div class="card stat-card"><div class="stat-value">${s.members_remembered}</div><div class="stat-label">Members remembered</div></div>
    <div class="card stat-card"><div class="stat-value">${s.total_facts}</div><div class="stat-label">Saved facts</div></div>
    <div class="card stat-card"><div class="stat-value">${s.messages_last_7d}</div><div class="stat-label">Messages, last 7 days</div></div>
  `;
}

document.querySelectorAll('.side-link').forEach(btn => {
  btn.addEventListener('click', () => switchPanel(btn.dataset.panel, btn));
});

loadProviders();

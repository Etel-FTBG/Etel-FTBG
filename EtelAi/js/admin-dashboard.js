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

function keyStatusLabel(k) {
  if (k.status === 'cooldown') {
    const until = k.cooldown_until ? new Date(k.cooldown_until + 'Z').toLocaleString() : '';
    return 'Cooldown' + (until ? ' until ' + until : '');
  }
  if (k.status === 'disabled') return 'Disabled';
  return 'Active';
}

async function loadProviders() {
  const wrap = document.getElementById('provider-list');
  wrap.innerHTML = '<div style="padding:14px;color:var(--text-dim);font-size:12.5px;">Loading...</div>';
  const res = await etelaiApiGet('providers.php');
  if (!res.ok) {
    wrap.innerHTML = '<div style="padding:14px;color:var(--red);font-size:12.5px;">' + res.error +
      '<br><a href="#" onclick="EtelIDAuth.showModal({onSuccess:()=>location.reload()});return false;" style="color:var(--gold);font-weight:700;">Sign in again &rarr;</a></div>';
    return;
  }
  wrap.innerHTML = res.data.providers.map(p => `
    <div class="provider-row">
      <span class="provider-name">${p.name}</span>
      <div class="toggle ${p.enabled ? 'on' : ''}" data-id="${p.id}" onclick="onToggleProvider(this)">
        <div class="knob"></div>
      </div>
    </div>
    <div class="key-list">
      ${p.keys.map(k => `
        <div class="key-row">
          <div class="key-row-main">
            <div class="key-row-label">${k.label} <span style="color:var(--text-dim);font-weight:400;">· ${k.model || ''}</span></div>
            <div class="key-row-sub">${k.masked_key || ''}${k.base ? ' · ' + k.base : ''}${k.last_error ? ' — ' + k.last_error.slice(0, 60) : ''}</div>
            <div class="key-row-sub" id="test-result-${p.id}-${k.id}"></div>
          </div>
          <span class="key-status-pill ${k.status}">${keyStatusLabel(k)}</span>
          <button class="key-row-del" style="color:var(--teal, #2a9d8f);" data-provider="${p.id}" data-key="${k.id}" onclick="onTestKey(this)" title="Test this key right now">🔌</button>
          <button class="key-row-del" style="color:var(--gold);" data-provider="${p.id}" data-key="${k.id}" onclick="onEditModel(this)" title="Edit model">✎</button>
          <div class="toggle ${k.enabled ? 'on' : ''}" style="transform:scale(.8)" data-provider="${p.id}" data-key="${k.id}" onclick="onToggleKey(this)">
            <div class="knob"></div>
          </div>
          <button class="key-row-del" data-provider="${p.id}" data-key="${k.id}" onclick="onRemoveKey(this)" title="Remove key">✕</button>
        </div>
      `).join('') || '<div style="padding:10px 0;color:var(--text-dim);font-size:11.5px;">No keys added yet.</div>'}
      <div class="add-key-form">
        <input type="text" placeholder="Label (e.g. Free key #2)" data-f="label" data-provider="${p.id}">
        <input type="text" placeholder="Model (e.g. ${p.id === 'gemini' ? 'gemini-flash-latest' : p.id === 'kimi' ? 'kimi-k2-0711-preview' : 'tinyllama'})" data-f="model" data-provider="${p.id}">
        <input type="text" class="wide" placeholder="Paste API key here" data-f="key" data-provider="${p.id}">
        ${p.id !== 'gemini' ? '<input type="text" class="wide" placeholder="Base URL (e.g. your tunnel URL + /v1)" data-f="base" data-provider="' + p.id + '">' : ''}
        <button class="add-key-btn" data-provider="${p.id}" onclick="onAddKey(this)">+ Add Key</button>
      </div>
    </div>
  `).join('');

  // Show/prefill the OmniRoute quick-update card only when there's
  // actually an omniroute key to update - no point showing it otherwise.
  const orCard = document.getElementById('omniroute-quick-card');
  const orFound = findOmniRouteKey(res.data.providers);
  if (orFound) {
    orCard.style.display = 'block';
    const urlEl = document.getElementById('or-quick-url');
    if (document.activeElement !== urlEl) urlEl.value = orFound.key.base || '';
    const statusEl = document.getElementById('or-quick-status');
    statusEl.textContent = orFound.key.enabled ? '' : '(disabled)';
  } else {
    orCard.style.display = 'none';
  }
}

async function onToggleProvider(el) {
  const id = el.dataset.id;
  const nextEnabled = !el.classList.contains('on');
  const msgEl = document.getElementById('provider-msg');
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

async function onToggleKey(el) {
  const providerId = el.dataset.provider;
  const keyId = el.dataset.key;
  const nextEnabled = !el.classList.contains('on');
  const msgEl = document.getElementById('provider-msg');
  const token = etelaiGetToken();
  if (!token) { msgEl.textContent = 'Please sign in again.'; msgEl.className = 'panel-msg err'; return; }

  const res = await etelaiApi('providers.php', { token, action: 'toggle_key', provider_id: providerId, key_id: keyId, enabled: nextEnabled });
  if (res.ok) {
    loadProviders();
    msgEl.textContent = 'Key updated.';
    msgEl.className = 'panel-msg ok';
  } else {
    msgEl.textContent = 'Could not save: ' + res.error;
    msgEl.className = 'panel-msg err';
  }
}

async function onRemoveKey(el) {
  const providerId = el.dataset.provider;
  const keyId = el.dataset.key;
  if (!confirm('Remove this key? This cannot be undone.')) return;
  const msgEl = document.getElementById('provider-msg');
  const token = etelaiGetToken();
  if (!token) { msgEl.textContent = 'Please sign in again.'; msgEl.className = 'panel-msg err'; return; }

  const res = await etelaiApi('providers.php', { token, action: 'remove_key', provider_id: providerId, key_id: keyId });
  if (res.ok) {
    loadProviders();
    msgEl.textContent = 'Key removed.';
    msgEl.className = 'panel-msg ok';
  } else {
    msgEl.textContent = 'Could not remove: ' + res.error;
    msgEl.className = 'panel-msg err';
  }
}

async function onEditModel(el) {
  const providerId = el.dataset.provider;
  const keyId = el.dataset.key;
  const newModel = prompt('New model name (e.g. gemini-flash-lite-latest, or an OmniRoute combo name):');
  if (!newModel || !newModel.trim()) return;

  const body = { action: 'update_key', provider_id: providerId, key_id: keyId, model: newModel.trim() };
  // Base URL only makes sense for non-Gemini providers (Gemini calls its
  // own native API directly, no configurable endpoint) - ask separately
  // so a plain model-only edit (the common case) isn't slowed down by an
  // extra prompt every time.
  if (providerId !== 'gemini') {
    const newBase = prompt('Base URL (leave blank to keep it as-is, e.g. your OmniRoute tunnel + /v1):');
    if (newBase !== null && newBase.trim() !== '') body.base = newBase.trim();
  }

  const msgEl = document.getElementById('provider-msg');
  const token = etelaiGetToken();
  if (!token) { msgEl.textContent = 'Please sign in again.'; msgEl.className = 'panel-msg err'; return; }

  body.token = token;
  const res = await etelaiApi('providers.php', body);
  if (res.ok) {
    loadProviders();
    msgEl.textContent = 'Updated.';
    msgEl.className = 'panel-msg ok';
  } else {
    msgEl.textContent = 'Could not update: ' + res.error;
    msgEl.className = 'panel-msg err';
  }
}

async function onTestKey(el) {
  const providerId = el.dataset.provider;
  const keyId = el.dataset.key;
  const resultEl = document.getElementById(`test-result-${providerId}-${keyId}`);
  const token = etelaiGetToken();
  if (!token) { resultEl.textContent = 'Please sign in again.'; resultEl.style.color = 'var(--red)'; return; }

  el.disabled = true;
  resultEl.style.color = 'var(--text-dim)';
  resultEl.textContent = 'Testing…';
  const res = await etelaiApi('providers.php', { token, action: 'test_key', provider_id: providerId, key_id: keyId });
  el.disabled = false;

  if (!res.ok) { resultEl.style.color = 'var(--red)'; resultEl.textContent = 'Test failed: ' + res.error; return; }
  const d = res.data;
  if (d.success) {
    resultEl.style.color = 'var(--teal, #2a9d8f)';
    resultEl.textContent = `✓ Working — "${d.reply}" in ${d.latency_ms}ms` + (d.model_used ? ` (${d.model_used})` : '');
  } else {
    resultEl.style.color = 'var(--red)';
    resultEl.textContent = `✗ ${d.message}` + (d.latency_ms ? ` (${d.latency_ms}ms)` : '');
  }
}

// The OmniRoute quick-update card shown at the top of this panel is
// hardcoded to the first key on the 'omniroute' provider - there's
// realistically only ever one (the local-PC tunnel), and keeping this
// dead simple (one URL field, one button) is the whole point: paste
// the new tunnel URL after a restart, click once, done - no cPanel.
function findOmniRouteKey(providers) {
  const p = (providers || []).find(x => x.id === 'omniroute');
  if (!p || !p.keys || !p.keys.length) return null;
  return { provider: p, key: p.keys[0] };
}

async function onOmniRouteQuickUpdate() {
  const urlEl = document.getElementById('or-quick-url');
  const resultEl = document.getElementById('or-quick-result');
  const newUrl = urlEl.value.trim();
  if (!newUrl) { resultEl.style.color = 'var(--red)'; resultEl.textContent = 'Paste the tunnel URL first.'; return; }

  const token = etelaiGetToken();
  if (!token) { resultEl.style.color = 'var(--red)'; resultEl.textContent = 'Please sign in again.'; return; }

  const res = await etelaiApiGet('providers.php');
  const found = res.ok ? findOmniRouteKey(res.data.providers) : null;
  if (!found) { resultEl.style.color = 'var(--red)'; resultEl.textContent = 'No OmniRoute key configured yet - add one below first.'; return; }

  resultEl.style.color = 'var(--text-dim)';
  resultEl.textContent = 'Saving…';
  const saveRes = await etelaiApi('providers.php', {
    token, action: 'update_key', provider_id: 'omniroute', key_id: found.key.id, base: newUrl,
  });
  if (!saveRes.ok) { resultEl.style.color = 'var(--red)'; resultEl.textContent = 'Could not save: ' + saveRes.error; return; }

  resultEl.style.color = 'var(--text-dim)';
  resultEl.textContent = 'Saved - testing now…';
  const testRes = await etelaiApi('providers.php', { token, action: 'test_key', provider_id: 'omniroute', key_id: found.key.id });
  if (testRes.ok && testRes.data.success) {
    resultEl.style.color = 'var(--teal, #2a9d8f)';
    resultEl.textContent = `✓ Updated and working — "${testRes.data.reply}" in ${testRes.data.latency_ms}ms`;
  } else {
    resultEl.style.color = 'var(--red)';
    resultEl.textContent = '✗ Saved, but test failed: ' + (testRes.ok ? testRes.data.message : testRes.error);
  }
  loadProviders();
}

async function onAddKey(btn) {
  const providerId = btn.dataset.provider;
  const get = (f) => {
    const el = document.querySelector(`input[data-provider="${providerId}"][data-f="${f}"]`);
    return el ? el.value.trim() : '';
  };
  const label = get('label'), model = get('model'), key = get('key'), base = get('base');
  const msgEl = document.getElementById('provider-msg');
  if (!label || !model || !key) { msgEl.textContent = 'Label, model, and key are all required.'; msgEl.className = 'panel-msg err'; return; }

  const token = etelaiGetToken();
  if (!token) { msgEl.textContent = 'Please sign in again.'; msgEl.className = 'panel-msg err'; return; }

  btn.disabled = true; btn.textContent = 'Adding...';
  const res = await etelaiApi('providers.php', { token, action: 'add_key', provider_id: providerId, label, model, key, base });
  btn.disabled = false; btn.textContent = '+ Add Key';
  if (res.ok) {
    loadProviders();
    msgEl.textContent = 'Key added.';
    msgEl.className = 'panel-msg ok';
  } else {
    msgEl.textContent = 'Could not add key: ' + res.error;
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

// No session at all -> use the same full-page sign-in gate the rest of
// Etel AI already uses (EtelIDAuth), not a separate login page. gate()
// returns the member if already signed in (checking its own key, then
// falling back to the shared etel_sso key), or replaces the page with
// the sign-in screen and returns null.
const _gateMember = (typeof EtelIDAuth !== 'undefined')
  ? EtelIDAuth.gate({
      title: 'ETEL AI Admin',
      subtitle: 'Sign in to manage providers',
      onSuccess: () => location.reload(),
    })
  : null;

if (_gateMember || (typeof EtelIDAuth === 'undefined' && etelaiGetToken())) {
  loadProviders();
}

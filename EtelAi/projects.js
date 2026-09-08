// ETEL OS — Projects Integration Layer v1.0
// Central hub connecting all Etel projects via REST API

const PROJECTS = {
  // Per-project API keys are stored in localStorage via openProjectConfig()
  // No shared key is hardcoded here — each project's key is set by the admin in the Projects panel

  registry: [
    {
      id: 'etel-id',
      name: 'Etel-ID',
      nameAm: 'ኢቴሌ መታወቂያ',
      icon: '🪪',
      color: '#FFD700',
      apiFile: 'wp-content/plugins/etel-id-v3/etel-os.php',
      defaultUrl: 'https://ethelmarket.com',
    },
    {
      id: 'delivery',
      name: 'Etel Delivery',
      nameAm: 'ኢቴሌ ዴሊቨሪ',
      icon: '🚚',
      color: '#F59E0B',
      apiFile: 'api/etel-os.php',
      defaultUrl: '',
    },
    {
      id: 'pay',
      name: 'Etel Pay',
      nameAm: 'ኢቴሌ ፔይ',
      icon: '💳',
      color: '#10B981',
      apiFile: 'etel-os.php',
      defaultUrl: '',
    },
    {
      id: 'gebeya',
      name: 'Etel Gebeya',
      nameAm: 'ኢቴሌ ገበያ',
      icon: '🛒',
      color: '#8B5CF6',
      apiFile: 'api/etel-os.php',
      defaultUrl: '',
    },
    {
      id: 'ride',
      name: 'Etel Ride',
      nameAm: 'ኢቴሌ ራይድ',
      icon: '🚖',
      color: '#EF4444',
      apiFile: 'api/etel-os.php',
      defaultUrl: '',
    },
    {
      id: 'group',
      name: 'Etel Group',
      nameAm: 'ኢቴሌ ቡድን',
      icon: '🏢',
      color: '#06B6D4',
      apiFile: 'etel-os.php',
      defaultUrl: '',
    },
    {
      id: 'gas-queue',
      name: 'Madeya Gas Queue',
      nameAm: 'ማደያ ጋዝ ሰልፍ',
      icon: '⛽',
      color: '#F97316',
      apiFile: 'etel-os.php',
      defaultUrl: '',
    },
  ],

  getConfigs() {
    return JSON.parse(localStorage.getItem('etel_project_configs') || '{}');
  },

  saveConfig(id, cfg) {
    const all = this.getConfigs();
    all[id] = { ...all[id], ...cfg };
    localStorage.setItem('etel_project_configs', JSON.stringify(all));
  },

  getProject(id) {
    const def = this.registry.find(p => p.id === id);
    if (!def) return null;
    const cfg = this.getConfigs()[id] || {};
    return { ...def, baseUrl: cfg.baseUrl || '', apiKey: cfg.apiKey || '', status: cfg.status || 'unconfigured', lastPing: cfg.lastPing || null };
  },

  getAll() {
    return this.registry.map(def => this.getProject(def.id));
  },

  // ── Brain routing — map an Etel-ID company_slug to a PROJECTS entry ──
  // etelid_companies.company_slug values aren't guaranteed to match our
  // registry ids 1:1 (e.g. 'etel-delivery' vs 'delivery'), so normalize
  // by stripping a leading etel-/etel_ prefix before matching.
  slugAliases: {
    'etel-market': 'gebeya', 'market': 'gebeya', 'etel-gebeya': 'gebeya',
    'etel-id': 'etel-id',
    'etel-ai': 'etel-ai',
  },

  resolveBySlug(slug) {
    if (!slug) return null;
    const s = String(slug).toLowerCase().trim();
    if (this.slugAliases[s]) return this.getProject(this.slugAliases[s]);
    const bare = s.replace(/^etel[-_]/, '');
    let p = this.getProject(s) || this.getProject(bare);
    if (p) return p;
    p = this.registry.find(r => r.id === s || r.id === bare);
    return p ? this.getProject(p.id) : null;
  },

  // ── Role → deep link suffix, shared convention across Etel projects ──
  roleDeepLink(role) {
    const r = String(role || '').toLowerCase();
    if (['admin', 'creator', 'owner'].includes(r)) return '/admin';
    if (r === 'vendor') return '/vendor-panel';
    if (r === 'driver') return '/driver';
    return '/account';
  },

  // ── Build the cross-company nav for a logged-in Etel-ID member ──
  // Returns [{slug, name, icon, color, role, url, configured}]
  companyLinksFor(member) {
    const companies = member?.companies || [];
    return companies.map(c => {
      const p = this.resolveBySlug(c.company_slug);
      const role = c.role || 'Member';
      const base = p?.baseUrl || p?.defaultUrl || '';
      return {
        slug: c.company_slug,
        name: c.company_name || p?.name || c.company_slug,
        icon: p?.icon || '🏢',
        color: p?.color || '#8B6914',
        role,
        url: base ? base.replace(/\/$/, '') + this.roleDeepLink(role) : '',
        configured: !!base,
      };
    });
  },

  async call(id, action, method = 'GET', body = null) {
    const p = this.getProject(id);
    if (!p || !p.baseUrl) throw new Error('Not configured');
    const url = p.baseUrl.replace(/\/$/, '') + '/' + p.apiFile + '?action=' + action;
    const opts = { method, headers: { 'Content-Type': 'application/json', 'X-Etel-Key': p.apiKey } };
    if (body) opts.body = JSON.stringify(body);
    const res = await fetch(url, opts);
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return res.json();
  },

  async ping(id) {
    try {
      const r = await this.call(id, 'status');
      const status = r.ok ? 'online' : 'error';
      this.saveConfig(id, { status, lastPing: Date.now() });
      return status;
    } catch {
      const p = this.getProject(id);
      const newStatus = p.baseUrl ? 'offline' : 'unconfigured';
      this.saveConfig(id, { status: newStatus, lastPing: Date.now() });
      return newStatus;
    }
  },

  async getStats(id) {
    try {
      return await this.call(id, 'stats');
    } catch { return null; }
  },

  async pingAll() {
    return Promise.all(this.registry.map(p => this.ping(p.id)));
  },

  // AI tool: ask any project a question via natural language proxy
  async aiQuery(id, prompt) {
    try {
      return await this.call(id, 'ai_query', 'POST', { prompt });
    } catch (e) { return { ok: false, error: e.message }; }
  },
};

// ── PROJECTS UI ──

async function renderProjectsView() {
  const container = document.getElementById('projects-view');
  if (!container) return;
  const projects = PROJECTS.getAll();

  const onlineCount = projects.filter(p => p.status === 'online').length;
  const configuredCount = projects.filter(p => p.baseUrl).length;

  container.innerHTML = `
    <div style="padding:20px;max-width:960px;margin:0 auto;width:100%;box-sizing:border-box;">

      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;flex-wrap:wrap;gap:10px;">
        <div>
          <div style="font-family:var(--font-display);font-size:13px;letter-spacing:0.12em;color:var(--gold);margin-bottom:3px;">ETEL PROJECTS</div>
          <div style="font-size:11px;color:var(--tx3);">${onlineCount} online · ${configuredCount} configured · ${projects.length} total</div>
        </div>
        <div style="display:flex;gap:8px;">
          <button onclick="pingAllProjects()" id="refresh-all-btn" style="padding:7px 14px;border-radius:5px;background:var(--bg3);border:1px solid var(--line2);color:var(--tx2);font-size:10px;cursor:pointer;font-family:var(--font-display);letter-spacing:0.06em;">↻ REFRESH ALL</button>
          <button onclick="openAiProjectQuery()" style="padding:7px 14px;border-radius:5px;background:rgba(255,215,0,0.08);border:1px solid var(--gold-dim);color:var(--gold);font-size:10px;cursor:pointer;font-family:var(--font-display);letter-spacing:0.06em;">✦ ASK AI</button>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:14px;margin-top:18px;">
        ${projects.map(p => renderProjectCard(p)).join('')}
      </div>

      <div style="margin-top:20px;padding:14px 16px;background:var(--bg2);border:1px solid var(--line);border-radius:8px;">
        <div style="font-size:10px;font-family:var(--font-display);letter-spacing:0.08em;color:var(--tx3);margin-bottom:8px;">QUICK ACTIONS</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <button onclick="quickAction('delivery','get_orders')" style="padding:5px 12px;border-radius:4px;background:var(--bg3);border:1px solid var(--line2);color:var(--tx2);font-size:10px;cursor:pointer;">🚚 View Deliveries</button>
          <button onclick="quickAction('pay','get_transactions')" style="padding:5px 12px;border-radius:4px;background:var(--bg3);border:1px solid var(--line2);color:var(--tx2);font-size:10px;cursor:pointer;">💳 Transactions</button>
          <button onclick="quickAction('gebeya','get_orders')" style="padding:5px 12px;border-radius:4px;background:var(--bg3);border:1px solid var(--line2);color:var(--tx2);font-size:10px;cursor:pointer;">🛒 Gebeya Orders</button>
          <button onclick="quickAction('ride','get_rides')" style="padding:5px 12px;border-radius:4px;background:var(--bg3);border:1px solid var(--line2);color:var(--tx2);font-size:10px;cursor:pointer;">🚖 Active Rides</button>
          <button onclick="quickAction('gas-queue','get_queue')" style="padding:5px 12px;border-radius:4px;background:var(--bg3);border:1px solid var(--line2);color:var(--tx2);font-size:10px;cursor:pointer;">⛽ Gas Queue</button>
        </div>
      </div>

    </div>`;
}

function renderProjectCard(p) {
  const statusColor = { online:'#00ff99', offline:'#ff4466', error:'#f59e0b', unconfigured:'#5ab4d4' }[p.status] || '#5ab4d4';
  const statusLabel = { online:'ONLINE', offline:'OFFLINE', error:'ERROR', unconfigured:'SETUP NEEDED' }[p.status] || 'SETUP NEEDED';
  const lastPingText = p.lastPing ? new Date(p.lastPing).toLocaleTimeString() : '—';

  return `
    <div style="background:var(--bg2);border:1px solid var(--line);border-radius:10px;padding:16px;position:relative;overflow:hidden;" id="proj-card-${p.id}">
      <div style="position:absolute;top:0;left:0;width:3px;height:100%;background:${p.color};border-radius:10px 0 0 10px;"></div>

      <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:12px;">
        <div style="display:flex;align-items:center;gap:10px;">
          <div style="font-size:24px;">${p.icon}</div>
          <div>
            <div style="font-size:13px;font-weight:600;color:var(--tx);">${p.name}</div>
            <div style="font-size:10px;color:var(--tx3);font-family:var(--font-am);">${p.nameAm}</div>
          </div>
        </div>
        <div style="text-align:right;">
          <div style="display:flex;align-items:center;gap:5px;font-size:9px;font-family:var(--font-display);letter-spacing:0.08em;color:${statusColor};justify-content:flex-end;">
            <div style="width:6px;height:6px;border-radius:50%;background:${statusColor};${p.status==='online'?'animation:pulse 2s infinite':''}"></div>
            ${statusLabel}
          </div>
          <div style="font-size:9px;color:var(--tx3);margin-top:3px;">${lastPingText}</div>
        </div>
      </div>

      <div id="proj-stats-${p.id}" style="min-height:36px;margin-bottom:12px;">
        ${p.baseUrl
          ? `<div style="font-size:10px;color:var(--tx3);">${p.status==='online'?'Fetching stats...':'Click ↻ to test connection'}</div>`
          : `<div style="font-size:10px;color:var(--tx3);padding:8px;background:rgba(255,215,0,0.04);border:1px dashed rgba(255,215,0,0.15);border-radius:6px;">No URL configured — click CONFIGURE to connect</div>`
        }
      </div>

      <div style="display:flex;gap:8px;">
        <button onclick="openProjectConfig('${p.id}')" style="flex:1;padding:6px;border-radius:5px;background:var(--bg3);border:1px solid var(--line2);color:var(--tx2);font-size:10px;cursor:pointer;font-family:var(--font-display);letter-spacing:0.05em;">⚙ CONFIGURE</button>
        ${p.baseUrl ? `<button onclick="pingAndLoadProject('${p.id}')" style="padding:6px 12px;border-radius:5px;background:var(--bg3);border:1px solid var(--line2);color:var(--tx2);font-size:10px;cursor:pointer;" title="Refresh">↻</button>` : ''}
        ${p.status==='online' ? `<button onclick="openProjectDetail('${p.id}')" style="padding:6px 12px;border-radius:5px;background:rgba(255,215,0,0.08);border:1px solid var(--gold-dim);color:var(--gold);font-size:10px;cursor:pointer;">→</button>` : ''}
      </div>
    </div>`;
}

async function pingAndLoadProject(id) {
  const statsEl = document.getElementById('proj-stats-' + id);
  if (statsEl) statsEl.innerHTML = '<div style="font-size:10px;color:var(--tx3);">Connecting...</div>';

  const status = await PROJECTS.ping(id);
  await renderProjectsView();

  if (status === 'online') {
    const res = await PROJECTS.getStats(id);
    const el = document.getElementById('proj-stats-' + id);
    if (el && res && res.data) {
      const entries = Object.entries(res.data).slice(0, 4);
      el.innerHTML = entries.map(([k, v]) =>
        `<div style="display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px solid var(--line);font-size:10px;">
          <span style="color:var(--tx3);">${k.replace(/_/g,' ')}</span>
          <span style="color:var(--tx);font-weight:600;">${v ?? '—'}</span>
        </div>`
      ).join('');
    }
  }
}

async function pingAllProjects() {
  const btn = document.getElementById('refresh-all-btn');
  if (btn) { btn.textContent = '↻ PINGING...'; btn.disabled = true; }

  await PROJECTS.pingAll();
  await renderProjectsView();

  // Load stats for all online projects
  const online = PROJECTS.getAll().filter(p => p.status === 'online');
  await Promise.all(online.map(p => pingAndLoadProject(p.id)));
}

function openProjectConfig(id) {
  const p = PROJECTS.getProject(id);
  const overlay = document.createElement('div');
  overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:9999;display:flex;align-items:center;justify-content:center;';
  overlay.innerHTML = `
    <div style="background:var(--bg2);border:1px solid var(--line2);border-radius:12px;padding:24px;width:380px;max-width:92vw;box-shadow:0 20px 60px rgba(0,0,0,0.5);">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
        <span style="font-size:20px;">${p.icon}</span>
        <div style="font-family:var(--font-display);font-size:12px;color:var(--gold);letter-spacing:0.1em;">${p.name.toUpperCase()}</div>
      </div>
      <div style="font-size:11px;color:var(--tx3);margin-bottom:18px;">Connect this project to ETEL OS</div>

      <label style="font-size:10px;color:var(--tx3);font-family:var(--font-display);letter-spacing:0.06em;display:block;margin-bottom:5px;">BASE URL</label>
      <input id="cfg-url-${id}" value="${p.baseUrl || p.defaultUrl || ''}" placeholder="https://ethelmarket.com/YourProjectFolder"
        style="width:100%;padding:9px 10px;background:var(--bg3);border:1px solid var(--line2);border-radius:6px;color:var(--tx);font-size:12px;margin-bottom:12px;box-sizing:border-box;outline:none;font-family:monospace;">

      <label style="font-size:10px;color:var(--tx3);font-family:var(--font-display);letter-spacing:0.06em;display:block;margin-bottom:5px;">API KEY</label>
      <input id="cfg-key-${id}" type="password" value="${p.apiKey || PROJECTS.apiKey}" placeholder="Your secret ETEL API key"
        style="width:100%;padding:9px 10px;background:var(--bg3);border:1px solid var(--line2);border-radius:6px;color:var(--tx);font-size:12px;margin-bottom:6px;box-sizing:border-box;outline:none;font-family:monospace;">
      <div style="font-size:9px;color:var(--tx3);margin-bottom:18px;">Set this in <code style="background:var(--bg3);padding:1px 4px;border-radius:3px;">etel-os.php</code> → <code style="background:var(--bg3);padding:1px 4px;border-radius:3px;">ETEL_API_KEY</code></div>

      <div style="display:flex;gap:8px;">
        <button onclick="saveProjectConfig('${id}')" style="flex:1;padding:9px;border-radius:6px;background:var(--gold);color:#000;font-weight:700;font-size:11px;border:none;cursor:pointer;font-family:var(--font-display);letter-spacing:0.05em;" id="cfg-save-${id}">SAVE & TEST</button>
        <button onclick="this.closest('[style*=fixed]').remove()" style="padding:9px 14px;border-radius:6px;background:var(--bg3);color:var(--tx2);font-size:11px;border:1px solid var(--line2);cursor:pointer;">Cancel</button>
      </div>
      <div id="cfg-result-${id}" style="margin-top:10px;font-size:10px;text-align:center;"></div>
    </div>`;
  document.body.appendChild(overlay);
  overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
  document.getElementById('cfg-url-' + id).focus();
}

async function saveProjectConfig(id) {
  const url = document.getElementById('cfg-url-' + id).value.trim();
  const key = document.getElementById('cfg-key-' + id).value.trim();
  const btn = document.getElementById('cfg-save-' + id);
  const result = document.getElementById('cfg-result-' + id);

  PROJECTS.saveConfig(id, { baseUrl: url, apiKey: key });
  btn.textContent = 'TESTING...';
  btn.disabled = true;

  const status = await PROJECTS.ping(id);
  if (status === 'online') {
    result.style.color = '#00ff99';
    result.textContent = '✓ Connected successfully!';
    setTimeout(() => {
      document.querySelector('[style*="fixed"]')?.remove();
      renderProjectsView();
      pingAndLoadProject(id);
    }, 1000);
  } else {
    result.style.color = '#ff4466';
    result.textContent = status === 'unconfigured' ? '⚠ No URL provided' : '✗ Could not connect — check URL and key';
    btn.textContent = 'SAVE & TEST';
    btn.disabled = false;
  }
}

function openProjectDetail(id) {
  const p = PROJECTS.getProject(id);
  // Switch to chat and inject a project context prompt
  switchView('chat', document.querySelector('.nav-item'));
  setTimeout(() => {
    const input = document.getElementById('msg-input') || document.querySelector('textarea');
    if (input) {
      input.value = `Show me the current status and key stats for ${p.name} (${p.nameAm})`;
      input.focus();
    }
  }, 200);
}

async function quickAction(id, action) {
  const p = PROJECTS.getProject(id);
  if (!p.baseUrl) {
    openProjectConfig(id);
    return;
  }
  try {
    const res = await PROJECTS.call(id, action);
    showProjectResult(p.name, action, res);
  } catch (e) {
    showProjectResult(p.name, action, { ok: false, error: e.message });
  }
}

function showProjectResult(name, action, data) {
  const overlay = document.createElement('div');
  overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:9999;display:flex;align-items:center;justify-content:center;';
  overlay.innerHTML = `
    <div style="background:var(--bg2);border:1px solid var(--line2);border-radius:12px;padding:20px;width:460px;max-width:92vw;max-height:70vh;overflow-y:auto;">
      <div style="font-family:var(--font-display);font-size:11px;color:var(--gold);letter-spacing:0.1em;margin-bottom:12px;">${name} — ${action.replace(/_/g,' ').toUpperCase()}</div>
      <pre style="font-size:11px;color:var(--tx2);white-space:pre-wrap;word-break:break-all;font-family:var(--font-hud);background:var(--bg3);padding:12px;border-radius:6px;border:1px solid var(--line);">${JSON.stringify(data, null, 2)}</pre>
      <button onclick="this.closest('[style*=fixed]').remove()" style="margin-top:12px;padding:7px 16px;border-radius:5px;background:var(--bg3);border:1px solid var(--line2);color:var(--tx2);font-size:10px;cursor:pointer;width:100%;">CLOSE</button>
    </div>`;
  document.body.appendChild(overlay);
  overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
}

function openAiProjectQuery() {
  switchView('chat', document.querySelector('.nav-item'));
  const projects = PROJECTS.getAll().filter(p => p.status === 'online');
  const list = projects.length ? projects.map(p => p.name).join(', ') : 'no projects online yet';
  setTimeout(() => {
    const input = document.getElementById('msg-input') || document.querySelector('textarea');
    if (input) {
      input.value = `Give me a summary of all connected projects. Online: ${list}`;
      input.focus();
    }
  }, 200);
}

window.PROJECTS = PROJECTS;

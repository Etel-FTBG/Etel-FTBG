/* ============================================================
   Etel Group super-page — Admin dashboard JS
   ============================================================ */

const EGA = { apiBase: 'EtelGroup/api/boot.php' };

function egaGetToken() { return localStorage.getItem('ega_token') || ''; }
function egaSetToken(t) { localStorage.setItem('ega_token', t); }
function egaClearToken() { localStorage.removeItem('ega_token'); }

function egaEsc(s) {
  return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, c => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
}

async function egaApi(action, body = {}) {
  const token = egaGetToken();
  let res;
  try {
    res = await fetch(EGA.apiBase + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
      body: JSON.stringify(body),
    });
  } catch (e) {
    return { ok: false, error: 'Network error - check your connection' };
  }
  let data;
  try { data = await res.json(); } catch (e) { return { ok: false, error: 'Unexpected server response' }; }
  if (res.status === 401) {
    egaClearToken();
    showLogin();
  }
  return data;
}

// ---------- login (EtelID SSO) ----------
async function doLogin() {
  const identifier = document.getElementById('login-identifier').value.trim();
  const password = document.getElementById('login-password').value;
  const errEl = document.getElementById('login-err');
  const btn = document.getElementById('login-btn');
  errEl.textContent = '';
  if (!identifier || !password) { errEl.textContent = 'Enter your EtelID phone/identifier and password.'; return; }
  btn.disabled = true; btn.textContent = 'Signing in...';
  const res = await egaApi('admin_login', { identifier, password });
  btn.disabled = false; btn.textContent = 'Sign In with EtelID';
  if (!res.ok) { errEl.textContent = res.error || 'Login failed'; return; }
  egaSetToken(res.data.token);
  if (res.data.member) {
    try { localStorage.setItem('ega_admin_name', res.data.member.full_name || ''); } catch (e) {}
  }
  showShell();
}

function showLogin() {
  document.getElementById('login-screen').style.display = 'flex';
  document.getElementById('shell').style.display = 'none';
}
function showShell() {
  document.getElementById('login-screen').style.display = 'none';
  document.getElementById('shell').style.display = 'block';
  loadDashboard();
}
function doLogout() {
  egaClearToken();
  showLogin();
}

// ---------- dashboard ----------
let EGA_CHART = null;

async function loadDashboard() {
  const [statsRes, trendRes] = await Promise.all([
    egaApi('admin_get_stats'),
    egaApi('admin_get_message_trend'),
  ]);
  if (statsRes.ok) {
    const d = statsRes.data;
    document.getElementById('stat-total').textContent = d.total;
    document.getElementById('stat-new').textContent = d.new;
    document.getElementById('stat-replied').textContent = d.replied;
    document.getElementById('stat-week').textContent = d.this_week;
  }
  if (trendRes.ok) renderTrendChart(trendRes.data.days);
  loadMessages();
}

function renderTrendChart(days) {
  const ctx = document.getElementById('trend-chart').getContext('2d');
  const labels = days.map(d => d.date.slice(5));
  const counts = days.map(d => d.count);
  if (EGA_CHART) EGA_CHART.destroy();
  EGA_CHART = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: 'Messages',
        data: counts,
        borderColor: '#D4AF37',
        backgroundColor: 'rgba(212,175,55,.15)',
        fill: true,
        tension: 0.35,
        pointRadius: 2,
      }],
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { display: false }, ticks: { color: '#a89a7a', font: { size: 10 } } },
        y: { beginAtZero: true, ticks: { color: '#a89a7a', font: { size: 10 }, precision: 0 }, grid: { color: 'rgba(212,175,55,.08)' } },
      },
    },
  });
}

// ---------- messages list ----------
let EGA_STATUS_FILTER = 'all';
let EGA_PAGE = 1;
let EGA_MESSAGES = [];

function setStatusFilter(status, btn) {
  EGA_STATUS_FILTER = status;
  EGA_PAGE = 1;
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  btn.classList.add('active');
  loadMessages();
}

let egaSearchTimer = null;
function onSearchInput() {
  clearTimeout(egaSearchTimer);
  egaSearchTimer = setTimeout(() => { EGA_PAGE = 1; loadMessages(); }, 300);
}

async function loadMessages() {
  const q = document.getElementById('search-input').value.trim();
  const res = await egaApi('admin_list_messages', { status: EGA_STATUS_FILTER, q, page: EGA_PAGE });
  const tbody = document.getElementById('msg-tbody');
  if (!res.ok) { tbody.innerHTML = `<tr><td colspan="4" class="empty-row">${egaEsc(res.error)}</td></tr>`; return; }
  EGA_MESSAGES = res.data.messages;
  if (!EGA_MESSAGES.length) {
    tbody.innerHTML = '<tr><td colspan="4" class="empty-row">No messages found.</td></tr>';
  } else {
    tbody.innerHTML = EGA_MESSAGES.map((m, i) => `
      <tr onclick="openMessage(${m.id})">
        <td><strong>${egaEsc(m.name)}</strong><br><span style="color:var(--text-dim);font-size:11.5px">${egaEsc(m.email)}</span></td>
        <td>${egaEsc(m.subject || '-')}${m.company ? '<br><span style="color:var(--text-dim);font-size:11.5px">' + egaEsc(m.company) + '</span>' : ''}</td>
        <td style="color:var(--text-dim)">${egaEsc(m.message_preview)}${m.message_preview.length >= 140 ? '…' : ''}</td>
        <td style="white-space:nowrap">
          <span class="pill ${m.status}">${m.status}</span><br>
          <span style="color:var(--text-faint);font-size:11px">${egaEsc(m.created_at)}</span>
        </td>
      </tr>
    `).join('');
  }
  renderPagination(res.data.page, res.data.total_pages);
}

function renderPagination(page, totalPages) {
  const el = document.getElementById('pagination');
  if (totalPages <= 1) { el.innerHTML = ''; return; }
  let html = `<button ${page <= 1 ? 'disabled' : ''} onclick="gotoPage(${page - 1})">‹ Prev</button>`;
  for (let p = 1; p <= totalPages; p++) {
    html += `<button class="${p === page ? 'active' : ''}" onclick="gotoPage(${p})">${p}</button>`;
  }
  html += `<button ${page >= totalPages ? 'disabled' : ''} onclick="gotoPage(${page + 1})">Next ›</button>`;
  el.innerHTML = html;
}
function gotoPage(p) { EGA_PAGE = p; loadMessages(); }

// ---------- message detail modal ----------
async function openMessage(id) {
  const res = await egaApi('admin_get_message', { id });
  if (!res.ok) return alert(res.error || 'Could not load message');
  const m = res.data.message;
  document.getElementById('modal-name').textContent = m.name;
  document.getElementById('modal-meta').textContent = `${m.email} · ${m.created_at}`;
  document.getElementById('modal-subject').textContent = m.subject || '(no subject)';
  document.getElementById('modal-company').textContent = m.company || '—';
  document.getElementById('modal-body').textContent = m.message;
  document.getElementById('modal-bg').dataset.id = m.id;
  document.getElementById('modal-bg').dataset.status = m.status;
  document.getElementById('modal-bg').classList.add('open');
  loadMessages(); // refresh list to reflect new/read state change
}
function closeModal() { document.getElementById('modal-bg').classList.remove('open'); }

async function markReplied() {
  const id = document.getElementById('modal-bg').dataset.id;
  await egaApi('admin_update_message_status', { id, status: 'replied' });
  closeModal();
  loadMessages();
}
async function deleteMessage() {
  const id = document.getElementById('modal-bg').dataset.id;
  if (!confirm('Delete this message permanently?')) return;
  await egaApi('admin_delete_message', { id });
  closeModal();
  loadMessages();
}

function exportCsv() {
  const token = egaGetToken();
  window.open(EGA.apiBase + '?action=admin_export_messages_csv&token=' + encodeURIComponent(token), '_blank');
}

// ---------- init ----------
(function init() {
  if (egaGetToken()) showShell();
  else showLogin();

  ['login-identifier', 'login-password'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });
  });
})();

// ---------- tab switching ----------
function switchTab(name) {
  document.getElementById('panel-dashboard').style.display = name === 'dashboard' ? 'block' : 'none';
  document.getElementById('panel-companies').style.display = name === 'companies' ? 'block' : 'none';
  document.getElementById('panel-employees').style.display = name === 'employees' ? 'block' : 'none';
  document.getElementById('panel-activity').style.display = name === 'activity' ? 'block' : 'none';
  document.getElementById('tab-panel-finance').style.display = name === 'finance' ? 'block' : 'none';
  document.getElementById('tab-nav-dashboard').classList.toggle('active', name === 'dashboard');
  document.getElementById('tab-nav-companies').classList.toggle('active', name === 'companies');
  document.getElementById('tab-nav-employees').classList.toggle('active', name === 'employees');
  document.getElementById('tab-nav-activity').classList.toggle('active', name === 'activity');
  document.getElementById('tab-nav-finance').classList.toggle('active', name === 'finance');
  if (name === 'companies') loadCompanies();
  if (name === 'employees') loadEmployeesTab();
  if (name === 'activity') loadActivityTab(1);
  if (name === 'finance') loadFinanceTab();
}

// ---------- Finance & Planning (native, not an iframe) ----------
// Pulls in delivery admin's own JS controllers (admin-equity.js etc.) and
// CSS bundles on demand, the first time this tab opens, and renders the
// real Equity/Platform Economics/Payroll/Business Plan panels straight
// into #finance-native-root. This sidesteps every same-origin edge case
// the earlier iframe approach kept hitting (delivery's clean-URL redirect
// dropping the CSP override on its intermediate hop, then the INSA audit
// lockdown hiding the panel inside the framed document too) by never
// loading delivery/admin.html or its lockdown at all - only its
// controllers and API.

function egaLoadCss(href) {
  return new Promise(function (resolve) {
    var link = document.createElement('link');
    link.rel = 'stylesheet'; link.href = href;
    link.onload = resolve; link.onerror = resolve; // non-fatal - panels still function unstyled
    document.head.appendChild(link);
  });
}
function egaLoadScript(src) {
  return new Promise(function (resolve, reject) {
    var s = document.createElement('script');
    s.src = src; s.onload = resolve; s.onerror = reject;
    document.head.appendChild(s);
  });
}

let EGA_FIN_ASSETS_LOADED = false;
async function loadFinanceTab() {
  if (!EGA_FIN_ASSETS_LOADED) {
    EGA_FIN_ASSETS_LOADED = true;
    await Promise.all([
      egaLoadCss('../delivery/css/admin-group1.min.css'),
      egaLoadCss('../delivery/css/admin-group2.min.css'),
    ]);
    await egaLoadScript('../delivery/js/core.js');
    // core.js's ETEL.api auto-detects the API base from a "/delivery/"
    // marker in the CURRENT PAGE's own URL - that marker isn't present
    // here (we're on EtelGroup/admin.html), so it would otherwise fall
    // back to this page's own directory. Point it at delivery's API
    // directly instead.
    // Bare identifier, not window.ETEL: core.js declares "const ETEL = {...}"
    // at its top level - a classic <script> tag's top-level const/let never
    // becomes a window property (only var/function declarations do), even
    // though it IS visible as a plain global to every script tag loaded
    // after it in the same document. window.ETEL would stay undefined here.
    ETEL.api = window.location.protocol + '//' + window.location.host + '/delivery/api/boot.php';
    await Promise.all([
      egaLoadScript('../delivery/js/admin-equity.js'),
      egaLoadScript('../delivery/js/admin-platform-economics.js'),
      egaLoadScript('../delivery/js/admin-payroll.js'),
      egaLoadScript('../delivery/js/admin-business-plan.js'),
    ]);
    await egaLoadScript('../delivery/js/admin-finance-hub.js');
  }
  egaRenderFinanceGate();
}

// Deliberately separate from EtelGroup's own ega_token: these panels call
// delivery's API directly with delivery's own bearer-token scheme, so a
// Delivery admin session is required. Reuses one already in localStorage
// (etel_token - same origin as delivery, so a direct delivery admin login
// in this same browser is picked up automatically) if present; otherwise
// prompts for delivery admin credentials right here, once.
function egaRenderFinanceGate() {
  var root = document.getElementById('finance-native-root');
  if (typeof Auth !== 'undefined' && Auth.isLoggedIn() && Auth.getUser() && Auth.getUser().role === 'admin') {
    egaRenderFinanceHub();
    return;
  }
  root.innerHTML =
    '<div style="max-width:360px">' +
      '<p style="font-size:13px;color:var(--text-dim);margin-bottom:14px">' +
        'This tab pulls live data straight from Delivery admin - sign in with a Delivery admin account to view it. ' +
        'This is separate from your EtelGroup admin login.</p>' +
      '<input type="text" id="fin-login-phone" placeholder="Delivery admin phone" style="width:100%;margin-bottom:8px">' +
      '<input type="password" id="fin-login-pw" placeholder="Password" style="width:100%;margin-bottom:8px">' +
      '<div id="fin-login-err" style="color:#e57373;font-size:12px;margin-bottom:8px"></div>' +
      '<button class="btn btn-primary" onclick="egaFinanceDeliveryLogin()">Sign In</button>' +
    '</div>';
}

async function egaFinanceDeliveryLogin() {
  var phone = document.getElementById('fin-login-phone').value.trim();
  var password = document.getElementById('fin-login-pw').value;
  var errEl = document.getElementById('fin-login-err');
  errEl.textContent = '';
  if (!phone || !password) { errEl.textContent = 'Enter phone and password.'; return; }
  var res = await api('login', { phone: phone, password: password });
  if (!res.success) { errEl.textContent = res.message || 'Login failed'; return; }
  if (res.data.user.role !== 'admin') { errEl.textContent = 'This account is not a Delivery admin.'; return; }
  Auth.save(res.data);
  egaRenderFinanceGate();
}

// The exact markup for each sub-panel, copied verbatim from
// delivery/admin.html (#panel-equity / #panel-platform-econ /
// #panel-payroll / #panel-business-plan) - their load functions
// (loadEquityPanel etc., from the scripts loaded above) fill these
// .data-section containers completely unchanged from how they work on
// delivery admin's own page; admin-finance-hub.js's loadFinanceHub()
// moves these real DOM nodes into #panel-finance's sub-tab layout the
// same way it does there.
const EGA_FINANCE_PANELS_HTML =
  '<div class="panel" id="panel-equity" style="display:none">' +
    '<div class="data-section eq-shell">' +
      '<div style="display:flex;align-items:center;gap:14px;margin-bottom:6px">' +
        '<div style="width:46px;height:46px;border-radius:14px;flex:none;background:linear-gradient(135deg,#C8960C,#F2CC6B 45%,#8a5e14 100%);display:flex;align-items:center;justify-content:center;font-family:\'Syne\',sans-serif;font-weight:800;font-size:19px;color:#100b02;box-shadow:0 10px 22px rgba(0,0,0,.4),0 2px 0 rgba(255,255,255,.5) inset">E</div>' +
        '<div class="section-title" style="margin:0">📈 Equity &amp; Investors</div>' +
      '</div>' +
      '<p style="font-size:13px;color:#B8B0A0;margin-bottom:16px;max-width:640px">' +
        'Etel Group\'s investor cap table, round settings, and profit-distribution tool. ' +
        'Visible only to roles granted the <span class="mono">equity.view</span> capability ' +
        'in the Roles panel — everyone else never sees this tab.</p>' +
      '<div style="display:flex;gap:10px;margin-bottom:22px;flex-wrap:wrap">' +
        '<button class="eq-btn" onclick="openEquitySettingsModal()">⚙️ Round Settings</button>' +
        '<button class="eq-btn" onclick="openEquityScenarioModal()">🔬 What-If Scenario</button>' +
        '<button class="eq-btn" onclick="exportEquityInvestorsCsv()">📥 Export CSV</button>' +
        '<button class="eq-btn primary" onclick="openEquityInvestorEdit(null)">➕ Add Investor</button>' +
      '</div>' +
      '<div id="equity-dashboard-body" style="margin-bottom:8px"></div>' +
      '<div class="section-title" style="font-size:15px;margin-top:6px">Investors</div>' +
      '<div id="equity-investors-list" style="margin-bottom:8px"></div>' +
      '<div class="section-title" style="font-size:15px;margin-top:6px">Profit Distribution</div>' +
      '<div id="equity-profit-body"></div>' +
    '</div>' +
  '</div>' +
  '<div class="panel" id="panel-platform-econ" style="display:none">' +
    '<div class="data-section eq-shell">' +
      '<div class="section-title">📊 Platform Economics</div>' +
      '<p style="font-size:13px;color:#B8B0A0;margin-bottom:16px;max-width:660px">' +
        'Real 5% commission revenue from completed rides, weighed against real cost ' +
        'assumptions you enter below. The revenue side is always live data — the cost ' +
        'side is only as accurate as what you fill in. Gated by ' +
        '<span class="mono">platform_economics.view</span>/<span class="mono">.manage</span>.</p>' +
      '<div id="pe-body"></div>' +
    '</div>' +
  '</div>' +
  '<div class="panel" id="panel-payroll" style="display:none">' +
    '<div class="data-section"></div>' +
  '</div>' +
  '<div class="panel" id="panel-business-plan" style="display:none">' +
    '<div class="data-section"></div>' +
  '</div>';

let EGA_FIN_HUB_RENDERED = false;
function egaRenderFinanceHub() {
  var root = document.getElementById('finance-native-root');
  if (!EGA_FIN_HUB_RENDERED) {
    EGA_FIN_HUB_RENDERED = true;
    root.innerHTML =
      '<div class="panel" id="panel-finance" style="display:block"><div class="data-section"></div></div>' +
      EGA_FINANCE_PANELS_HTML;
  }
  if (typeof loadFinanceHub === 'function') loadFinanceHub();
}

// ---------- companies CRUD ----------
let EGA_COMPANIES = [];

async function loadCompanies() {
  const tbody = document.getElementById('company-tbody');
  const res = await egaApi('admin_list_companies');
  if (!res.ok) { tbody.innerHTML = `<tr><td colspan="5" class="empty-row">${egaEsc(res.error)}</td></tr>`; return; }
  EGA_COMPANIES = res.data.companies;
  if (!EGA_COMPANIES.length) { tbody.innerHTML = '<tr><td colspan="5" class="empty-row">No companies yet.</td></tr>'; return; }
  tbody.innerHTML = EGA_COMPANIES.map(c => `
    <tr>
      <td>${c.sort_order}</td>
      <td><strong>${egaEsc(c.name)}</strong><br><span style="color:var(--text-dim);font-size:11.5px">${egaEsc(c.tagline)}</span></td>
      <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${egaEsc(c.url) || '<span style="color:var(--text-faint)">—</span>'}</td>
      <td><span class="pill ${c.status === 'live' ? 'replied' : 'new'}">${c.status === 'live' ? 'Live' : 'Coming Soon'}</span></td>
      <td style="white-space:nowrap">
        <button class="btn btn-outline" style="padding:6px 10px;font-size:11.5px" onclick="openCompanyForm(${c.id})">Edit</button>
        <button class="btn btn-danger" style="padding:6px 10px;font-size:11.5px" onclick="deleteCompany(${c.id})">Delete</button>
      </td>
    </tr>
  `).join('');
}

let EGA_ID_COMPANIES = null; // EtelID's canonical slug registry, loaded once and cached

async function loadIdCompanies() {
  if (EGA_ID_COMPANIES) return EGA_ID_COMPANIES;
  const res = await egaApi('admin_list_id_companies');
  EGA_ID_COMPANIES = res.ok ? res.data.companies : [];
  return EGA_ID_COMPANIES;
}

function renderSlugOptions(currentSlug) {
  const select = document.getElementById('cf-slug');
  const hint = document.getElementById('cf-slug-hint');
  const known = EGA_ID_COMPANIES || [];
  const opts = known.map(c => `<option value="${egaEsc(c.slug)}">${egaEsc(c.name)} (${egaEsc(c.slug)})</option>`);
  const isKnown = currentSlug && known.some(c => c.slug === currentSlug);
  if (currentSlug && !isKnown) {
    opts.unshift(`<option value="${egaEsc(currentSlug)}">${egaEsc(currentSlug)} (not recognized by EtelID)</option>`);
  }
  select.innerHTML = '<option value="">Select a company…</option>' + opts.join('');
  select.value = currentSlug || '';
  hint.textContent = !currentSlug
    ? ''
    : isKnown
      ? ''
      : '⚠ This slug isn\'t in EtelID\'s registry - employee role assignment for it will silently fail. Pick a recognized slug, or have EtelID add this company first.';
  select.onchange = () => {
    const v = select.value;
    const known2 = EGA_ID_COMPANIES || [];
    hint.textContent = (v && !known2.some(c => c.slug === v))
      ? '⚠ Not in EtelID\'s registry - employee roles for this company will silently fail to save.'
      : '';
  };
}

async function openCompanyForm(id) {
  const c = id ? EGA_COMPANIES.find(x => x.id === id) : null;
  document.getElementById('company-form-title').textContent = c ? 'Edit Company' : 'Add Company';
  document.getElementById('cf-id').value = c ? c.id : '';
  document.getElementById('cf-name').value = c ? c.name : '';
  await loadIdCompanies();
  renderSlugOptions(c ? c.slug : '');
  document.getElementById('cf-url').value = c ? c.url : '';
  document.getElementById('cf-tagline').value = c ? c.tagline : '';
  document.getElementById('cf-tagline-am').value = c ? c.tagline_am : '';
  document.getElementById('cf-color').value = c ? c.dot_color : '#D4AF37';
  document.getElementById('cf-icon').value = c ? c.icon_key : 'generic';
  document.getElementById('cf-status').value = c ? c.status : 'coming_soon';
  document.getElementById('cf-sort').value = c ? c.sort_order : (EGA_COMPANIES.length + 1) * 10;
  document.getElementById('cf-tags').value = c ? c.tags : '';
  document.getElementById('cf-desc-short').value = c ? c.description_short : '';
  document.getElementById('cf-desc-long').value = c ? c.description_long : '';
  document.getElementById('cf-logo').value = c && c.logo_path ? c.logo_path : '';
  renderLogoPreview(c && c.logo_path ? c.logo_path : '');
  const uploadStatusEl = document.getElementById('cf-logo-upload-status');
  if (uploadStatusEl) uploadStatusEl.textContent = '';
  const uploadFileEl = document.getElementById('cf-logo-file');
  if (uploadFileEl) uploadFileEl.value = '';
  document.getElementById('company-modal-bg').classList.add('open');
}
function closeCompanyForm() { document.getElementById('company-modal-bg').classList.remove('open'); }

function renderLogoPreview(url) {
  const el = document.getElementById('cf-logo-preview');
  if (!el) return; // Element missing (e.g. stale cached HTML mid-deploy) - don't crash the caller over a cosmetic preview.
  if (!url) { el.innerHTML = ''; return; }
  el.innerHTML = `<img src="${egaEsc(url)}" alt="Logo preview" style="width:56px;height:56px;object-fit:cover;border-radius:50%;border:1px solid rgba(212,175,55,.3)" onerror="this.style.display='none'">`;
}

// Uploads a logo image file straight from the admin's computer, rather
// than requiring them to already have it hosted somewhere and know the
// URL. This is a real multipart/form-data POST (not JSON like every
// other admin action here), so it can't reuse egaApi() - it's a
// separate, single-purpose endpoint (api/upload_logo.php) for exactly
// this reason. On success, fills the same #cf-logo text field the
// manual-URL path already used, so saveCompany() needs no changes at
// all - both paths converge on that one field.
async function uploadCompanyLogo(inputEl) {
  const file = inputEl.files && inputEl.files[0];
  if (!file) return;
  const statusEl = document.getElementById('cf-logo-upload-status') || { style: {}, textContent: '' };
  const slug = document.getElementById('cf-slug').value.trim();
  if (!slug) {
    statusEl.style.color = '#e57373';
    statusEl.textContent = 'Enter a slug above first, so the uploaded file has a name to save as.';
    inputEl.value = '';
    return;
  }
  statusEl.style.color = '#999';
  statusEl.textContent = 'Uploading…';
  const form = new FormData();
  form.append('logo', file);
  form.append('slug', slug);
  try {
    const res = await fetch('EtelGroup/api/upload_logo.php', {
      method: 'POST',
      headers: { 'Authorization': 'Bearer ' + egaGetToken() },
      body: form,
    });
    const data = await res.json();
    if (res.status === 401) { egaClearToken(); showLogin(); return; }
    if (!data.ok) { statusEl.style.color = '#e57373'; statusEl.textContent = data.error || 'Upload failed'; return; }
    document.getElementById('cf-logo').value = data.data.url;
    renderLogoPreview(data.data.url);
    statusEl.style.color = '#8bc34a';
    statusEl.textContent = 'Uploaded ✓ — will be saved when you click "Save Company" below.';
  } catch (e) {
    statusEl.style.color = '#e57373';
    statusEl.textContent = 'Network error during upload - please try again.';
  } finally {
    inputEl.value = '';
  }
}

async function saveCompany() {
  const id = document.getElementById('cf-id').value;
  const payload = {
    name: document.getElementById('cf-name').value.trim(),
    slug: document.getElementById('cf-slug').value.trim(),
    url: document.getElementById('cf-url').value.trim(),
    tagline: document.getElementById('cf-tagline').value.trim(),
    tagline_am: document.getElementById('cf-tagline-am').value.trim(),
    dot_color: document.getElementById('cf-color').value,
    icon_key: document.getElementById('cf-icon').value,
    status: document.getElementById('cf-status').value,
    sort_order: parseInt(document.getElementById('cf-sort').value, 10) || 0,
    tags: document.getElementById('cf-tags').value.trim(),
    description_short: document.getElementById('cf-desc-short').value.trim(),
    description_long: document.getElementById('cf-desc-long').value.trim(),
    logo_path: document.getElementById('cf-logo').value.trim(),
  };
  if (!payload.name || !payload.slug) { alert('Name and slug are required.'); return; }
  const res = id
    ? await egaApi('admin_update_company', Object.assign({ id: parseInt(id, 10) }, payload))
    : await egaApi('admin_create_company', payload);
  if (!res.ok) { alert(res.error || 'Could not save company'); return; }
  closeCompanyForm();
  loadCompanies();
}

async function deleteCompany(id) {
  if (!confirm('Delete this company? This removes it from the public site immediately.')) return;
  await egaApi('admin_delete_company', { id });
  loadCompanies();
}

// ---------- employee roles ----------
let EGA_EMP_SELECTED_SLUG = '';

async function loadEmployeesTab() {
  const select = document.getElementById('emp-company-select');
  // Reuse the companies list if already loaded (Companies tab visited),
  // otherwise fetch it fresh - either way it's the same source of truth
  // as the public site's company grid.
  if (!EGA_COMPANIES.length) {
    const res = await egaApi('admin_list_companies');
    if (res.ok) EGA_COMPANIES = res.data.companies;
  }
  if (!EGA_COMPANIES.length) {
    select.innerHTML = '<option value="">No companies yet - add one in the Companies tab first</option>';
    return;
  }
  const prevSlug = EGA_EMP_SELECTED_SLUG || select.value;
  select.innerHTML = EGA_COMPANIES.map(c => `<option value="${egaEsc(c.slug)}">${egaEsc(c.name)}</option>`).join('');
  if (prevSlug && EGA_COMPANIES.some(c => c.slug === prevSlug)) select.value = prevSlug;
  loadEmployeeRoles();
}

async function loadEmployeeRoles() {
  const select = document.getElementById('emp-company-select');
  const slug = select.value;
  EGA_EMP_SELECTED_SLUG = slug;
  const tbody = document.getElementById('employees-tbody');
  if (!slug) { tbody.innerHTML = '<tr><td colspan="6" class="empty-row">Select a company above</td></tr>'; return; }
  tbody.innerHTML = '<tr><td colspan="6" class="empty-row">Loading...</td></tr>';
  const res = await egaApi('admin_list_employee_roles', { company_slug: slug });
  if (!res.ok) { tbody.innerHTML = `<tr><td colspan="6" class="empty-row">${egaEsc(res.error)}</td></tr>`; return; }
  const roles = res.data.roles;
  if (!roles.length) { tbody.innerHTML = '<tr><td colspan="6" class="empty-row">No one has a role here yet.</td></tr>'; return; }
  tbody.innerHTML = roles.map(r => `
    <tr>
      <td><strong>${egaEsc(r.full_name)}</strong></td>
      <td style="color:var(--text-dim)">${egaEsc(r.etel_id)}</td>
      <td style="color:var(--text-dim)">${egaEsc(r.phone)}</td>
      <td><span class="pill replied">${egaEsc(r.role_at)}</span></td>
      <td style="color:var(--text-faint);font-size:11.5px">${egaEsc(r.linked_at)}</td>
      <td><button class="btn btn-danger" style="padding:6px 10px;font-size:11.5px" onclick="removeEmployeeRole(${r.id})">Remove</button></td>
    </tr>
  `).join('');
}

async function addEmployeeRole() {
  const slug = document.getElementById('emp-company-select').value;
  const identifier = document.getElementById('emp-identifier').value.trim();
  const role = document.getElementById('emp-role').value.trim();
  const errEl = document.getElementById('emp-form-err');
  errEl.textContent = '';
  if (!slug) { errEl.textContent = 'Select a company first.'; return; }
  if (!identifier) { errEl.textContent = "Enter the employee's phone or EtelID."; return; }
  if (!role) { errEl.textContent = 'Choose or type a role.'; return; }
  const res = await egaApi('admin_set_employee_role', { company_slug: slug, identifier, role_at: role });
  if (!res.ok) { errEl.textContent = res.error || 'Could not assign role'; return; }
  document.getElementById('emp-identifier').value = '';
  document.getElementById('emp-role').value = '';
  loadEmployeeRoles();
}

async function removeEmployeeRole(id) {
  if (!confirm('Remove this role? They will lose this access immediately.')) return;
  await egaApi('admin_remove_employee_role', { id, company_slug: EGA_EMP_SELECTED_SLUG });
  loadEmployeeRoles();
}

// ---------- activity log ----------
let EGA_ACTIVITY_PAGE = 1;

const EGA_ACTIVITY_LABELS = {
  login: 'Signed in',
  message_status_updated: 'Updated message status',
  message_deleted: 'Deleted message',
  company_created: 'Added company',
  company_updated: 'Updated company',
  company_deleted: 'Deleted company',
  companies_reordered: 'Reordered companies',
  employee_role_set: 'Assigned employee role',
  employee_role_removed: 'Removed employee role',
};

async function loadActivityTab(page) {
  EGA_ACTIVITY_PAGE = page || EGA_ACTIVITY_PAGE;
  const tbody = document.getElementById('activity-tbody');
  tbody.innerHTML = '<tr><td colspan="4" class="empty-row">Loading...</td></tr>';
  const res = await egaApi('admin_list_activity', { page: EGA_ACTIVITY_PAGE });
  if (!res.ok) { tbody.innerHTML = `<tr><td colspan="4" class="empty-row">${egaEsc(res.error)}</td></tr>`; return; }
  const entries = res.data.entries;
  if (!entries.length) {
    tbody.innerHTML = '<tr><td colspan="4" class="empty-row">No activity recorded yet.</td></tr>';
  } else {
    tbody.innerHTML = entries.map(e => `
      <tr>
        <td style="color:var(--text-faint);font-size:11.5px;white-space:nowrap">${egaEsc(e.created_at)}</td>
        <td><strong>${egaEsc(e.actor_name) || '<span style=\"color:var(--text-faint)\">Unknown</span>'}</strong>${e.actor_etel_id ? '<br><span style="color:var(--text-dim);font-size:11.5px">' + egaEsc(e.actor_etel_id) + '</span>' : ''}</td>
        <td><span class="pill replied">${egaEsc(EGA_ACTIVITY_LABELS[e.action] || e.action)}</span></td>
        <td style="color:var(--text-dim)">${egaEsc(e.detail)}</td>
      </tr>
    `).join('');
  }
  renderActivityPagination(res.data.page, res.data.total_pages);
}

function renderActivityPagination(page, totalPages) {
  const el = document.getElementById('activity-pagination');
  if (totalPages <= 1) { el.innerHTML = ''; return; }
  let html = `<button ${page <= 1 ? 'disabled' : ''} onclick="loadActivityTab(${page - 1})">‹ Prev</button>`;
  for (let p = 1; p <= totalPages; p++) {
    html += `<button class="${p === page ? 'active' : ''}" onclick="loadActivityTab(${p})">${p}</button>`;
  }
  html += `<button ${page >= totalPages ? 'disabled' : ''} onclick="loadActivityTab(${page + 1})">Next ›</button>`;
  el.innerHTML = html;
}

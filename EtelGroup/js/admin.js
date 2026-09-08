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
  document.getElementById('tab-nav-dashboard').classList.toggle('active', name === 'dashboard');
  document.getElementById('tab-nav-companies').classList.toggle('active', name === 'companies');
  document.getElementById('tab-nav-employees').classList.toggle('active', name === 'employees');
  if (name === 'companies') loadCompanies();
  if (name === 'employees') loadEmployeesTab();
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

function openCompanyForm(id) {
  const c = id ? EGA_COMPANIES.find(x => x.id === id) : null;
  document.getElementById('company-form-title').textContent = c ? 'Edit Company' : 'Add Company';
  document.getElementById('cf-id').value = c ? c.id : '';
  document.getElementById('cf-name').value = c ? c.name : '';
  document.getElementById('cf-slug').value = c ? c.slug : '';
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
  await egaApi('admin_remove_employee_role', { id });
  loadEmployeeRoles();
}

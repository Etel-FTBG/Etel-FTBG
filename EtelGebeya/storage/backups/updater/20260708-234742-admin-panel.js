/* ═══════════════════════════════════════════════════════════
   ETEL GEBEYA ADMIN — JavaScript  (external file)
   Update this file alone for all JS changes — no need to
   re-upload the full admin.html (100KB+).
   ═══════════════════════════════════════════════════════════ */

const API     = 'api/admin.php';
const UPD_API = 'updater.php';
let   TOKEN   = localStorage.getItem('eg_admin_token') || '';
let   currentPanel = 'dashboard';
const pageState = {};

// ── AUTH ────────────────────────────────────────────────────
async function doLogin() {
  const pass = document.getElementById('login-pass').value.trim();
  if (!pass) return;
  const r = await fetch(API + '?action=login', {method:'POST',body:new URLSearchParams({password:pass})});
  const d = await r.json();
  if (d.ok) {
    TOKEN = d.data.token;
    localStorage.setItem('eg_admin_token', TOKEN);
    document.getElementById('login-screen').style.display = 'none';
    document.getElementById('app').style.display = 'flex';
    init();
  } else {
    document.getElementById('login-err').textContent = '❌ ' + (d.error || 'Wrong password');
  }
}

function doLogout() {
  localStorage.removeItem('eg_admin_token');
  location.reload();
}

// ── INIT ────────────────────────────────────────────────────
function init() {
  if (TOKEN) {
    document.getElementById('login-screen').style.display = 'none';
    document.getElementById('app').style.display = 'flex';
    loadDashboard();
    loadBadges();
    setInterval(loadBadges, 60000);
    setInterval(updateClock, 1000);
    updateClock();
  }
}

function updateClock() {
  const now = new Date();
  document.getElementById('h-clock').textContent =
    now.toLocaleTimeString('en-ET',{hour:'2-digit',minute:'2-digit',second:'2-digit'}) + ' EAT';
}

// ── API HELPER ──────────────────────────────────────────────
async function api(action, params={}, method='GET') {
  const authParam = '&auth=' + encodeURIComponent(TOKEN);
  const url = API + '?action=' + action + authParam + (method==='GET' ? '&'+new URLSearchParams(params) : '');
  const opts = { headers: {'X-Auth': TOKEN} };
  if (method === 'POST') {
    opts.method = 'POST';
    const fd = new URLSearchParams({auth: TOKEN, ...params});
    opts.body = fd;
  }
  try {
    const r = await fetch(url, opts);
    return await r.json();
  } catch(e) {
    return {ok:false, error: e.message};
  }
}

// ── PANEL SWITCHING ─────────────────────────────────────────
const panelMeta = {
  dashboard:    {title:'Dashboard Overview',        sub:'Real-time marketplace intelligence',  actions:'<button class="btn btn-secondary" onclick="loadDashboard()">🔄 Refresh</button>'},
  products:     {title:'Products',                  sub:'Manage catalog & approvals',           actions:'<button class="btn btn-secondary" onclick="loadProducts()">🔄 Refresh</button>'},
  categories:   {title:'Categories',                sub:'Shop browse structure',                actions:'<button class="btn btn-primary" onclick="openCatModal()">➕ New Category</button>'},
  vendors:      {title:'Vendors & Sellers',         sub:'Marketplace seller management',        actions:'<button class="btn btn-secondary" onclick="loadVendors()">🔄 Refresh</button>'},
  orders:       {title:'Orders',                    sub:'Customer purchase management',         actions:'<button class="btn btn-primary" onclick="exportCSV(\'orders\')">📥 Export</button>'},
  reviews:      {title:'Reviews',                   sub:'Product review moderation',            actions:'<button class="btn btn-secondary" onclick="loadReviews()">🔄 Refresh</button>'},
  disputes:     {title:'Disputes',                  sub:'Order dispute resolution',             actions:'<button class="btn btn-secondary" onclick="loadDisputes()">🔄 Refresh</button>'},
  transactions: {title:'Transactions',              sub:'Payment transaction history',          actions:'<button class="btn btn-primary" onclick="exportCSV(\'transactions\')">📥 Export</button>'},
  payouts:      {title:'Vendor Payouts',            sub:'Seller payout request management',     actions:'<button class="btn btn-secondary" onclick="loadPayouts()">🔄 Refresh</button>'},
  coupons:      {title:'Coupons & Discounts',       sub:'Promotional code management',          actions:'<button class="btn btn-primary" onclick="openCouponModal()">➕ New Coupon</button>'},
  users:        {title:'Users',                     sub:'Customer account management',          actions:'<button class="btn btn-primary" onclick="exportCSV(\'users\')">📥 Export</button>'},
  couriers:     {title:'Couriers',                  sub:'Delivery agent management',            actions:'<button class="btn btn-secondary" onclick="loadCouriers()">🔄 Refresh</button>'},
  updater:      {title:'File Updater',              sub:'Deploy files without SSH access',      actions:''},
  diagnostics:  {title:'System Diagnostics',        sub:'Terminal health console — admin only', actions:'<button class="btn btn-primary" onclick="runDiagnostics()">▶ Run All Checks</button><button class="btn btn-secondary" onclick="clearDiagConsole()" style="margin-left:.5rem">🗑 Clear</button>'},
  settings:     {title:'Site Settings',             sub:'Configure marketplace options',        actions:'<button class="btn btn-primary" onclick="saveSettings()">💾 Save Settings</button>'},
};

function switchPanel(name, el) {
  document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
  el.classList.add('active');
  document.querySelectorAll('.panel').forEach(p=>p.classList.remove('active'));
  document.getElementById('panel-'+name).classList.add('active');
  currentPanel = name;
  const m = panelMeta[name] || {};
  document.getElementById('ct-title').textContent = m.title || name;
  document.getElementById('ct-sub').textContent   = m.sub   || '';
  document.getElementById('ct-actions').innerHTML = m.actions || '';

  const loaders = {
    dashboard: loadDashboard, products: loadProducts, categories: loadCategories,
    vendors: loadVendors, orders: loadOrders, reviews: loadReviews,
    disputes: loadDisputes, transactions: loadTransactions, payouts: loadPayouts,
    coupons: loadCoupons, users: loadUsers, couriers: loadCouriers,
    settings: loadSettings, updater: loadUpdaterSystem, diagnostics: () => {},
  };
  if (loaders[name]) loaders[name]();
}

// ── BADGES ─────────────────────────────────────────────────
async function loadBadges() {
  const r = await api('badges');
  if (!r.ok) return;
  const d = r.data;
  setBadge('nb-products', d.products_pending);
  setBadge('nb-vendors',  d.vendors_pending);
  setBadge('nb-payouts',  d.payouts_pending);
  setBadge('nb-reviews',  d.reviews_pending);
  setBadge('nb-disputes', d.disputes_open);
}
function setBadge(id, n) {
  const el = document.getElementById(id);
  if (!el) return;
  if (n > 0) { el.textContent = n; el.style.display = 'flex'; }
  else el.style.display = 'none';
}

// ── DIAGNOSTICS ─────────────────────────────────────────────
const DIAG_API = 'api/diagnostics.php';
const STATUS_ICON  = {pass:'<span style="color:#22c55e">✓</span>',fail:'<span style="color:#ef4444">✗</span>',warn:'<span style="color:#f59e0b">⚠</span>',info:'<span style="color:#60a5fa">ℹ</span>'};
const STATUS_COLOR = {pass:'#22c55e',fail:'#ef4444',warn:'#f59e0b',info:'#60a5fa'};

function clearDiagConsole() {
  document.getElementById('diag-lines').innerHTML = '<span style="color:rgba(212,175,55,.4)">Console cleared. Press ▶ Run All Checks to start…</span>';
  document.getElementById('diag-summary').textContent = '';
  document.getElementById('diag-cursor').style.display = 'none';
}

function diagPrint(html) {
  const el = document.getElementById('diag-lines');
  el.insertAdjacentHTML('beforeend', html);
  document.getElementById('diag-console').scrollTop = 99999;
}

function diagLine(text) { diagPrint(`<div>${text}</div>`); }

function diagCheckLine(check) {
  const icon  = STATUS_ICON[check.status]  || 'ℹ';
  const color = STATUS_COLOR[check.status] || '#b0b8c8';
  const name  = `<span style="color:#e2e8f0;min-width:200px;display:inline-block">${escH(check.name)}</span>`;
  const msg   = `<span style="color:${color}">${escH(check.msg)}</span>`;
  const line  = document.createElement('div');
  line.style.cssText = 'padding:2px 0;cursor:pointer;border-radius:4px;transition:background .15s';
  line.innerHTML = `${icon} ${name} ${msg}`;
  if (check.data) {
    line.title = 'Double-click to expand data';
    line.addEventListener('dblclick', () => {
      const ex = line.nextElementSibling;
      if (ex && ex.classList.contains('diag-expand')) { ex.remove(); return; }
      const expand = document.createElement('div');
      expand.className = 'diag-expand';
      expand.style.cssText = 'margin:2px 0 4px 2rem;padding:.5rem .75rem;background:rgba(255,255,255,.04);border-left:2px solid rgba(212,175,55,.3);border-radius:0 6px 6px 0;white-space:pre-wrap;font-size:.72rem;color:#94a3b8;max-height:200px;overflow-y:auto';
      expand.textContent = JSON.stringify(check.data, null, 2);
      line.after(expand);
    });
  }
  document.getElementById('diag-lines').appendChild(line);
  document.getElementById('diag-console').scrollTop = 99999;
}

function escH(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

async function runDiagnostics() {
  const btn = document.getElementById('diag-run-btn');
  btn.disabled = true; btn.textContent = '⏳ Running…';
  document.getElementById('diag-cursor').style.display = 'block';
  document.getElementById('diag-lines').innerHTML = '';
  document.getElementById('diag-summary').textContent = '';

  const ts = new Date().toLocaleString('en-ET');
  diagLine(`<span style="color:rgba(212,175,55,.7)">▶ ETEL GEBEYA Diagnostic Run — ${ts}</span>`);
  diagLine(`<span style="color:#475569">${'─'.repeat(70)}</span>`);

  let data;
  try {
    const resp = await fetch(`${DIAG_API}?check=all&auth=${encodeURIComponent(TOKEN)}`, {headers:{'X-Auth':TOKEN}});
    if (!resp.ok) {
      diagLine(`<span style="color:#ef4444">✗ HTTP ${resp.status} — ${resp.status===401?'Unauthorized, re-login':'Server error'}</span>`);
      btn.disabled=false; btn.textContent='▶ Run All Checks';
      document.getElementById('diag-cursor').style.display='none';
      return;
    }
    data = await resp.json();
  } catch(ex) {
    diagLine(`<span style="color:#ef4444">✗ Network error: ${escH(ex.message)}</span>`);
    btn.disabled=false; btn.textContent='▶ Run All Checks';
    document.getElementById('diag-cursor').style.display='none';
    return;
  }

  if (!data.ok) {
    diagLine(`<span style="color:#ef4444">✗ API error: ${escH(data.error||'unknown')}</span>`);
    btn.disabled=false; btn.textContent='▶ Run All Checks';
    document.getElementById('diag-cursor').style.display='none';
    return;
  }

  const checks  = data.data?.checks    || [];
  const summary = data.data?.summary   || {};
  const server  = data.data?.server    || {};
  const elapsed = data.data?.elapsed_ms|| 0;
  const ranAt   = data.data?.ran_at    || ts;

  diagLine(`<span style="color:#475569">Server: ${escH(server.server)} · PHP ${escH(server.php)} · OS: ${escH(server.os)}</span>`);
  diagLine(`<span style="color:#475569">${'─'.repeat(70)}</span>`);
  checks.forEach(c => diagCheckLine(c));
  diagLine(`<span style="color:#475569">${'─'.repeat(70)}</span>`);
  diagLine(`<span style="color:#e2e8f0">Summary: </span><span style="color:#22c55e">✓ ${summary.pass} PASS</span>  <span style="color:${summary.fail>0?'#ef4444':'#22c55e'}">✗ ${summary.fail} FAIL</span>  <span style="color:${summary.warn>0?'#f59e0b':'#22c55e'}">⚠ ${summary.warn} WARN</span>  <span style="color:#60a5fa">ℹ ${summary.info} INFO</span>`);
  diagLine(`<span style="color:#475569">Completed in ${elapsed}ms at ${ranAt}</span>`);
  diagLine(`<span style="color:rgba(212,175,55,.4)">Double-click any line to expand raw data.</span>`);

  document.getElementById('diag-summary').innerHTML =
    `<span style="color:${summary.fail===0?'#22c55e':'#ef4444'};font-weight:700">${summary.fail===0?'✓ All critical checks passed':'✗ '+summary.fail+' check(s) failed'}</span>`+
    (summary.warn>0?` · <span style="color:#f59e0b">${summary.warn} warning(s)</span>`:'');

  btn.disabled=false; btn.textContent='▶ Run All Checks';
  document.getElementById('diag-cursor').style.display='none';
}

// ── DASHBOARD ───────────────────────────────────────────────
async function loadDashboard() {
  const r = await api('stats');
  if (!r.ok) { toast('Failed to load dashboard: '+r.error,'err'); return; }
  const d = r.data;
  document.getElementById('dash-stats').innerHTML = `
    ${sc('📦','Total Orders',  fmt(d.orders),  `${d.pending} pending`)}
    ${sc('✅','Delivered',    fmt(d.delivered),'This lifetime')}
    ${sc('💰','Revenue (ETB)',fmtMoney(d.revenue),`Today: ${fmtMoney(d.revenue_today)}`,'ok')}
    ${sc('🛍️','Products',    fmt(d.products), `${d.products_pending} awaiting`)}
    ${sc('🏪','Vendors',     fmt(d.sellers),  `${d.sellers_pending} pending`,'warn')}
    ${sc('👥','Users',       fmt(d.users),    'Registered')}
    ${sc('🚗','Couriers',    fmt(d.couriers), 'Active')}
    ${sc('⭐','Reviews',     fmt(d.reviews_pending),'Awaiting moderation','warn')}
  `;
  const rows = d.recent_orders || [];
  document.getElementById('dash-orders-tbody').innerHTML = rows.length ? rows.map(o=>`
    <tr>
      <td><span style="font-family:var(--fm);color:var(--g3)">${e(o.code||o.id)}</span></td>
      <td>${e(o.customer||o.full_name||'Guest')}</td>
      <td style="font-weight:700">Br ${fmtMoney(o.total)}</td>
      <td>${pill(o.status)}</td>
      <td><span style="font-size:.75rem;color:var(--text-dim)">${e(o.payment_method||'—')}</span></td>
      <td style="font-size:.78rem;color:var(--text-dim)">${fmtDate(o.created_at||o.placed_at)}</td>
      <td><button class="btn btn-sm btn-secondary" onclick="viewOrder(${o.id})">👁 View</button></td>
    </tr>`).join('') : `<tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-muted)">No orders yet</td></tr>`;
  await Promise.all([loadChartRevenue(), loadChartStatus(), loadChartCats()]);
}

function sc(icon, label, value, sub='', type='') {
  const subCol = type==='ok'?'var(--ok)':type==='warn'?'var(--warn)':'var(--text-muted)';
  return `<div class="stat-card"><div class="sc-icon">${icon}</div><div class="sc-label">${label}</div><div class="sc-value">${value}</div><div class="sc-sub" style="color:${subCol}">${sub}</div></div>`;
}

// ── CHARTS ──────────────────────────────────────────────────
let charts = {};
async function loadChartRevenue() {
  const r = await api('chart_revenue'); if (!r.ok) return;
  const labels = r.data.map(d=>d.d), rev = r.data.map(d=>parseFloat(d.rev||0));
  const ctx = document.getElementById('chart-revenue').getContext('2d');
  if (charts.revenue) charts.revenue.destroy();
  charts.revenue = new Chart(ctx, {type:'line',data:{labels,datasets:[{label:'Revenue (ETB)',data:rev,fill:true,borderColor:'#D4AF37',backgroundColor:'rgba(212,175,55,.12)',tension:.4,pointRadius:3,pointBackgroundColor:'#D4AF37'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{color:'#F7EFD9'}}},scales:{x:{ticks:{color:'#9A8A6A',maxTicksLimit:10},grid:{color:'rgba(212,175,55,.08)'}},y:{ticks:{color:'#9A8A6A'},grid:{color:'rgba(212,175,55,.08)'}}}}});
}
async function loadChartStatus() {
  const r = await api('chart_orders'); if (!r.ok) return;
  const colors = {delivered:'#10B981',pending:'#F59E0B',cancelled:'#EF4444',processing:'#3B82F6',shipped:'#8B5CF6'};
  const ctx = document.getElementById('chart-status').getContext('2d');
  if (charts.status) charts.status.destroy();
  charts.status = new Chart(ctx, {type:'doughnut',data:{labels:r.data.map(d=>d.status),datasets:[{data:r.data.map(d=>d.cnt),backgroundColor:r.data.map(d=>colors[d.status]||'#6B7280'),borderWidth:2,borderColor:'#130d05'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'right',labels:{color:'#F7EFD9',padding:10,font:{size:11}}}}}});
}
async function loadChartCats() {
  const r = await api('chart_categories'); if (!r.ok) return;
  const ctx = document.getElementById('chart-cats').getContext('2d');
  if (charts.cats) charts.cats.destroy();
  charts.cats = new Chart(ctx, {type:'bar',data:{labels:r.data.map(d=>d.cat),datasets:[{data:r.data.map(d=>d.cnt),label:'Products',backgroundColor:'rgba(212,175,55,.6)',borderColor:'#D4AF37',borderWidth:1,borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{ticks:{color:'#9A8A6A',font:{size:10}},grid:{display:false}},y:{ticks:{color:'#9A8A6A'},grid:{color:'rgba(212,175,55,.08)'}}}}});
}

// ── PRODUCTS ────────────────────────────────────────────────
let prodPage = 1;
async function loadProducts() {
  const search = document.getElementById('prod-search')?.value || '';
  const status = document.getElementById('prod-status')?.value || '';
  const r = await api('products',{search,status,page:prodPage});
  if (!r.ok) { toast(r.error,'err'); return; }
  const {total,rows} = r.data;
  document.getElementById('products-tbody').innerHTML = rows.length ? rows.map(p=>`
    <tr>
      <td><div style="font-weight:700;color:var(--g1)">${e(p.name_en)}</div><div style="font-size:.72rem;font-family:var(--fm);color:var(--text-muted)">${e(p.sku||'')}</div></td>
      <td style="font-size:.8rem">${e(p.vendor||'—')}</td>
      <td style="font-size:.78rem;color:var(--text-dim)">${e(p.cat_name||'—')}</td>
      <td style="font-weight:700;color:var(--g3)">Br ${fmtMoney(p.price)}</td>
      <td style="text-align:center">${p.stock_qty}</td>
      <td>${pill(p.status)}</td>
      <td style="text-align:center">${p.is_featured?'⭐':''}</td>
      <td>
        ${p.status==='pending'?`<button class="btn btn-sm btn-success" onclick="productAction(${p.id},'published')">✅</button> <button class="btn btn-sm btn-danger" onclick="productReject(${p.id})">❌</button>`:''}
        <button class="btn btn-sm btn-secondary" onclick="productToggleFeatured(${p.id},${p.is_featured?0:1})">${p.is_featured?'★':'☆'}</button>
        <button class="btn btn-sm btn-danger" onclick="if(confirm('Delete?'))productAction(${p.id},'delete')">🗑</button>
      </td>
    </tr>`).join('') : `<tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-muted)">No products found</td></tr>`;
  renderPag('products-pag', total, prodPage, 25, p=>{prodPage=p;loadProducts();});
}
async function productAction(id, status) {
  if (status==='delete') { const r=await api('delete_product',{id},'POST'); toast(r.ok?'Product deleted':'Error: '+r.error,r.ok?'ok':'err'); if(r.ok)loadProducts(); return; }
  const r = await api('product_action',{id,status},'POST');
  toast(r.ok?`Product ${status}`:'Error: '+r.error, r.ok?'ok':'err');
  if (r.ok) { loadProducts(); loadBadges(); }
}
function productReject(id) {
  openModal(`<h3>❌ Reject Product</h3><div class="form-group"><label>Rejection Reason</label><textarea id="rej-reason" rows="3" placeholder="Tell the vendor why…"></textarea></div><div class="form-actions"><button class="btn btn-danger" onclick="doRejectProduct(${id})">Reject</button></div>`);
}
async function doRejectProduct(id) {
  const reason = document.getElementById('rej-reason').value;
  const r = await api('product_action',{id,status:'rejected',reason},'POST');
  toast(r.ok?'Product rejected':'Error: '+r.error, r.ok?'ok':'err');
  if (r.ok) { closeModal(); loadProducts(); loadBadges(); }
}
async function productToggleFeatured(id, featured) { await api('product_featured',{id,featured},'POST'); loadProducts(); }

// ── CATEGORIES ──────────────────────────────────────────────
async function loadCategories() {
  const r = await api('categories'); if (!r.ok) { toast(r.error,'err'); return; }
  document.getElementById('cats-tbody').innerHTML = r.data.map(c=>`
    <tr>
      <td style="font-weight:700">${e(c.name_en)}</td>
      <td style="font-family:'Noto Sans Ethiopic',sans-serif">${e(c.name_am||'')}</td>
      <td style="font-size:.78rem;color:var(--text-dim)">${e(c.parent_name||'—')}</td>
      <td><span style="font-family:var(--fm);font-size:.72rem;color:var(--text-muted)">${e(c.slug)}</span></td>
      <td style="text-align:center">${c.product_count||0}</td>
      <td style="text-align:center">${c.sort_order}</td>
      <td style="text-align:center">${c.is_active?'✅':'❌'}</td>
      <td>
        <button class="btn btn-sm btn-secondary" onclick="openCatModal(${c.id},'${e(c.name_en)}','${e(c.name_am||'')}','${e(c.slug)}','${e(c.icon||'')}',${c.parent_id||0},${c.sort_order},${c.is_active})">✏️</button>
        <button class="btn btn-sm btn-danger" onclick="deleteCategory(${c.id})">🗑</button>
      </td>
    </tr>`).join('');
}
function openCatModal(id=0,name_en='',name_am='',slug='',icon='',parent_id=0,sort=0,active=1) {
  openModal(`<h3>${id?'✏️ Edit':'➕ New'} Category</h3>
    <div class="form-row"><div class="form-group"><label>Name (English)</label><input id="cat-en" value="${e(name_en)}"></div><div class="form-group"><label>Name (አማርኛ)</label><input id="cat-am" value="${e(name_am)}"></div></div>
    <div class="form-row"><div class="form-group"><label>Slug</label><input id="cat-slug" value="${e(slug)}"></div><div class="form-group"><label>Icon class</label><input id="cat-icon" value="${e(icon)}"></div></div>
    <div class="form-row"><div class="form-group"><label>Sort Order</label><input id="cat-sort" type="number" value="${sort}"></div><div class="form-group"><label>Active</label><select id="cat-active"><option value="1" ${active?'selected':''}>Yes</option><option value="0" ${!active?'selected':''}>No</option></select></div></div>
    <div class="form-actions"><button class="btn btn-primary" onclick="saveCategory(${id})">💾 Save</button></div>`);
}
async function saveCategory(id) {
  const r = await api('save_category',{id,name_en:v('cat-en'),name_am:v('cat-am'),slug:v('cat-slug'),icon:v('cat-icon'),sort_order:v('cat-sort'),is_active:v('cat-active')},'POST');
  toast(r.ok?'Category saved':'Error: '+r.error, r.ok?'ok':'err');
  if (r.ok) { closeModal(); loadCategories(); }
}
async function deleteCategory(id) {
  if (!confirm('Delete this category?')) return;
  const r = await api('delete_category',{id},'POST');
  toast(r.ok?'Deleted':'Error: '+r.error, r.ok?'ok':'err');
  if (r.ok) loadCategories();
}

// ── VENDORS ─────────────────────────────────────────────────
let vendorPage = 1;
async function loadVendors() {
  const search = document.getElementById('vendor-search')?.value||'';
  const status = document.getElementById('vendor-status')?.value||'';
  const r = await api('vendors',{search,status,page:vendorPage}); if (!r.ok) { toast(r.error,'err'); return; }
  const {total,rows} = r.data;
  document.getElementById('vendors-tbody').innerHTML = rows.length ? rows.map(v=>`
    <tr>
      <td><div style="font-weight:700">${e(v.shop_name)}</div></td>
      <td>${e(v.full_name||'')}</td>
      <td style="font-family:var(--fm);font-size:.78rem">${e(v.phone||'—')}</td>
      <td>${pill(v.status)}</td>
      <td><span style="font-family:var(--fm);font-size:.8rem">${v.commission_pct||10}%</span><button class="btn btn-sm btn-secondary" style="margin-left:4px" onclick="editCommission(${v.id},${v.commission_pct||10})">✏️</button></td>
      <td><span class="stars">${'★'.repeat(Math.round(v.rating_avg||0))}${'☆'.repeat(5-Math.round(v.rating_avg||0))}</span> ${parseFloat(v.rating_avg||0).toFixed(1)}</td>
      <td style="font-size:.78rem;color:var(--text-dim)">${fmtDate(v.created_at||v.joined)}</td>
      <td>
        ${v.status==='pending'?`<button class="btn btn-sm btn-success" onclick="vendorAction(${v.id},'approved')">✅ Approve</button> `:''}
        ${v.status==='active'?`<button class="btn btn-sm btn-warn" onclick="vendorAction(${v.id},'suspended')">🚫 Suspend</button> `:''}
        ${v.status==='suspended'?`<button class="btn btn-sm btn-success" onclick="vendorAction(${v.id},'approved')">✅ Restore</button> `:''}
        ${v.status==='pending'?`<button class="btn btn-sm btn-danger" onclick="vendorAction(${v.id},'rejected')">❌</button>`:''}
      </td>
    </tr>`).join('') : `<tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-muted)">No vendors found</td></tr>`;
  renderPag('vendors-pag', total, vendorPage, 25, p=>{vendorPage=p;loadVendors();});
}
async function vendorAction(id, status) {
  const r = await api('vendor_action',{id,status},'POST');
  toast(r.ok?`Vendor ${status}`:'Error: '+r.error, r.ok?'ok':'err');
  if (r.ok) { loadVendors(); loadBadges(); }
}
function editCommission(id, current) {
  openModal(`<h3>💹 Edit Commission Rate</h3><div class="form-group"><label>Commission %</label><input id="comm-pct" type="number" min="0" max="50" step="0.5" value="${current}"></div><div class="form-actions"><button class="btn btn-primary" onclick="saveCommission(${id})">💾 Save</button></div>`);
}
async function saveCommission(id) {
  const pct = document.getElementById('comm-pct').value;
  const r = await api('vendor_commission',{id,pct},'POST');
  toast(r.ok?`Commission updated to ${pct}%`:'Error: '+r.error, r.ok?'ok':'err');
  if (r.ok) { closeModal(); loadVendors(); }
}

// ── ORDERS ──────────────────────────────────────────────────
let orderPage = 1;
async function loadOrders() {
  const search = document.getElementById('order-search')?.value||'';
  const status = document.getElementById('order-status')?.value||'';
  const r = await api('orders',{search,status,page:orderPage}); if (!r.ok) { toast(r.error,'err'); return; }
  const {total,rows} = r.data;
  document.getElementById('orders-tbody').innerHTML = rows.length ? rows.map(o=>`
    <tr>
      <td><span style="font-family:var(--fm);color:var(--g3)">${e(o.code||o.id)}</span></td>
      <td>${e(o.customer||o.full_name||'Guest')}</td>
      <td style="text-align:center">${o.item_count||'—'}</td>
      <td style="font-weight:700">Br ${fmtMoney(o.total)}</td>
      <td style="font-size:.75rem">${e(o.payment_method||'—')}</td>
      <td>${pill(o.status)}</td>
      <td style="font-size:.78rem;color:var(--text-dim)">${fmtDate(o.created_at||o.placed_at)}</td>
      <td>
        <button class="btn btn-sm btn-secondary" onclick="viewOrder(${o.id})">👁 Details</button>
        <button class="btn btn-sm btn-secondary" onclick="changeOrderStatus(${o.id},'${o.status}')">✏️</button>
      </td>
    </tr>`).join('') : `<tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-muted)">No orders found</td></tr>`;
  renderPag('orders-pag', total, orderPage, 25, p=>{orderPage=p;loadOrders();});
}
async function viewOrder(id) {
  const r = await api('order_items',{id}); if (!r.ok) { toast(r.error,'err'); return; }
  const {order:o, items} = r.data;
  openModal(`<h3>📦 Order ${e(o?.code||id)}</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:1rem;font-size:.83rem">
      <div><span style="color:var(--text-dim)">Customer:</span> ${e(o?.full_name||'')}</div>
      <div><span style="color:var(--text-dim)">Phone:</span> ${e(o?.phone||'—')}</div>
      <div><span style="color:var(--text-dim)">Status:</span> ${pill(o?.status||'')}</div>
      <div><span style="color:var(--text-dim)">Payment:</span> ${e(o?.payment_method||'—')}</div>
      <div><span style="color:var(--text-dim)">Total:</span> <strong style="color:var(--g3)">Br ${fmtMoney(o?.total)}</strong></div>
      <div><span style="color:var(--text-dim)">Date:</span> ${fmtDate(o?.created_at||o?.placed_at)}</div>
    </div>
    <div style="overflow-x:auto"><table><thead><tr><th>Product</th><th>Vendor</th><th>Qty</th><th>Price</th><th>Line Total</th></tr></thead>
    <tbody>${items.map(i=>`<tr><td>${e(i.name)}</td><td>${e(i.vendor||'')}</td><td>${i.qty}</td><td>Br ${fmtMoney(i.price)}</td><td>Br ${fmtMoney(i.line_total)}</td></tr>`).join('')}</tbody></table></div>`);
}
function changeOrderStatus(id, current) {
  const opts = ['new','confirmed','processing','packed','shipped','out_for_delivery','delivered','cancelled','returned','disputed'].map(s=>`<option value="${s}"${s===current?' selected':''}>${s}</option>`).join('');
  openModal(`<h3>✏️ Update Order Status</h3><div class="form-group"><label>New Status</label><select id="new-order-status">${opts}</select></div><div class="form-actions"><button class="btn btn-primary" onclick="doOrderStatus(${id})">💾 Update</button></div>`);
}
async function doOrderStatus(id) {
  const status = document.getElementById('new-order-status').value;
  const r = await api('order_status',{id,status},'POST');
  toast(r.ok?'Order updated':'Error: '+r.error, r.ok?'ok':'err');
  if (r.ok) { closeModal(); loadOrders(); }
}

// ── REVIEWS ─────────────────────────────────────────────────
let reviewPage = 1;
async function loadReviews() {
  const status = document.getElementById('review-status')?.value||'';
  const r = await api('reviews',{status,page:reviewPage}); if (!r.ok) { toast(r.error,'err'); return; }
  const {total,rows} = r.data;
  document.getElementById('reviews-tbody').innerHTML = rows.length ? rows.map(rv=>`
    <tr>
      <td style="font-size:.8rem">${e(rv.product_name||'—')}</td>
      <td>${e(rv.reviewer||'—')}</td>
      <td><span class="stars">${'★'.repeat(rv.rating)}${'☆'.repeat(5-rv.rating)}</span></td>
      <td style="font-size:.8rem;font-weight:700">${e(rv.title||'')}</td>
      <td style="font-size:.78rem;color:var(--text-dim);max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${e(rv.body||'')}</td>
      <td>${pill(rv.status)}</td>
      <td style="font-size:.78rem;color:var(--text-dim)">${fmtDate(rv.created_at)}</td>
      <td>
        ${rv.status!=='approved'?`<button class="btn btn-sm btn-success" onclick="reviewAction(${rv.id},'approved')">✅</button> `:''}
        ${rv.status!=='rejected'?`<button class="btn btn-sm btn-danger" onclick="reviewAction(${rv.id},'rejected')">❌</button>`:''}
      </td>
    </tr>`).join('') : `<tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-muted)">No reviews</td></tr>`;
  renderPag('reviews-pag', total, reviewPage, 25, p=>{reviewPage=p;loadReviews();});
}
async function reviewAction(id, status) {
  const r = await api('review_action',{id,status},'POST');
  toast(r.ok?`Review ${status}`:'Error: '+r.error, r.ok?'ok':'err');
  if (r.ok) { loadReviews(); loadBadges(); }
}

// ── DISPUTES ────────────────────────────────────────────────
async function loadDisputes() {
  const r = await api('disputes'); if (!r.ok) { toast(r.error,'err'); return; }
  document.getElementById('disputes-tbody').innerHTML = r.data.rows?.length ? r.data.rows.map(d=>`
    <tr>
      <td style="font-family:var(--fm);color:var(--g3)">${e(d.order_code||d.order_id)}</td>
      <td>${e(d.customer||'—')}</td>
      <td style="font-size:.8rem">${e(d.reason||'—')}</td>
      <td>${pill(d.status)}</td>
      <td style="font-size:.78rem;color:var(--text-dim)">${fmtDate(d.created_at)}</td>
      <td><button class="btn btn-sm btn-secondary">👁 View</button></td>
    </tr>`).join('') : `<tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--text-muted)">No disputes</td></tr>`;
}

// ── TRANSACTIONS ────────────────────────────────────────────
let txnPage = 1;
async function loadTransactions() {
  const status = document.getElementById('txn-status')?.value||'';
  const r = await api('transactions',{status,page:txnPage}); if (!r.ok) { toast(r.error,'err'); return; }
  const {total,rows} = r.data;
  document.getElementById('txn-tbody').innerHTML = rows.length ? rows.map(t=>`
    <tr>
      <td style="font-family:var(--fm);font-size:.8rem;color:var(--g3)">${e(t.order_code||t.order_id||'—')}</td>
      <td>${e(t.customer||'—')}</td>
      <td style="font-weight:700;color:var(--g3)">Br ${fmtMoney(t.amount)}</td>
      <td style="font-size:.8rem">${e(t.method||'—')}</td>
      <td style="font-family:var(--fm);font-size:.75rem;color:var(--text-muted)">${e(t.txn_ref||'—')}</td>
      <td>${pill(t.status)}</td>
      <td style="font-size:.78rem;color:var(--text-dim)">${fmtDate(t.created_at)}</td>
    </tr>`).join('') : `<tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-muted)">No transactions</td></tr>`;
  renderPag('txn-pag', total, txnPage, 25, p=>{txnPage=p;loadTransactions();});
}

// ── PAYOUTS ─────────────────────────────────────────────────
let payoutPage = 1;
async function loadPayouts() {
  const status = document.getElementById('payout-status')?.value||'';
  const r = await api('payouts',{status,page:payoutPage}); if (!r.ok) { toast(r.error,'err'); return; }
  const {total,rows} = r.data;
  document.getElementById('payouts-tbody').innerHTML = rows.length ? rows.map(p=>`
    <tr>
      <td style="font-weight:700">${e(p.shop_name||'—')}</td>
      <td>${e(p.full_name||'—')}</td>
      <td style="font-weight:700;color:var(--g3)">Br ${fmtMoney(p.amount)}</td>
      <td style="font-size:.8rem">${e(p.method||'—')}</td>
      <td>${pill(p.status)}</td>
      <td style="font-size:.78rem;color:var(--text-dim)">${fmtDate(p.created_at)}</td>
      <td>
        ${p.status==='pending'?`<button class="btn btn-sm btn-success" onclick="payoutAction(${p.id},'approved')">✅ Approve</button> <button class="btn btn-sm btn-danger" onclick="payoutAction(${p.id},'rejected')">❌</button>`:''}
        ${p.status==='approved'?`<button class="btn btn-sm btn-primary" onclick="payoutAction(${p.id},'paid')">💸 Mark Paid</button>`:''}
      </td>
    </tr>`).join('') : `<tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-muted)">No payout requests</td></tr>`;
  renderPag('payouts-pag', total, payoutPage, 25, p=>{payoutPage=p;loadPayouts();});
}
async function payoutAction(id, status) {
  const r = await api('payout_action',{id,status},'POST');
  toast(r.ok?`Payout ${status}`:'Error: '+r.error, r.ok?'ok':'err');
  if (r.ok) { loadPayouts(); loadBadges(); }
}

// ── COUPONS ─────────────────────────────────────────────────
async function loadCoupons() {
  const r = await api('coupons'); if (!r.ok) { toast(r.error,'err'); return; }
  document.getElementById('coupons-tbody').innerHTML = r.data.length ? r.data.map(c=>`
    <tr>
      <td style="font-family:var(--fm);font-weight:800;color:var(--g3)">${e(c.code)}</td>
      <td>${e(c.type||'percent')}</td>
      <td style="font-weight:700">${c.type==='percent'?c.value+'%':'Br '+fmtMoney(c.value)}</td>
      <td>Br ${fmtMoney(c.min_order||0)}</td>
      <td style="text-align:center">${c.used_count||0} / ${c.max_uses||'∞'}</td>
      <td style="font-size:.78rem;color:var(--text-dim)">${c.expires_at?fmtDate(c.expires_at):'Never'}</td>
      <td style="text-align:center">${c.is_active?'✅':'❌'}</td>
      <td>
        <button class="btn btn-sm btn-secondary" onclick="openCouponModal(${c.id},'${e(c.code)}','${e(c.type||'percent')}',${c.value},${c.min_order||0},${c.max_uses||100},'${c.expires_at||''}',${c.is_active})">✏️</button>
        <button class="btn btn-sm btn-danger" onclick="deleteCoupon(${c.id})">🗑</button>
      </td>
    </tr>`).join('') : `<tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-muted)">No coupons yet</td></tr>`;
}
function openCouponModal(id=0,code='',type='percent',value=0,min_order=0,max_uses=100,expires='',active=1) {
  openModal(`<h3>${id?'✏️ Edit':'➕ New'} Coupon</h3>
    <div class="form-row"><div class="form-group"><label>Code</label><input id="cp-code" value="${e(code)}" style="text-transform:uppercase"></div><div class="form-group"><label>Type</label><select id="cp-type"><option value="percent"${type==='percent'?' selected':''}>Percent %</option><option value="fixed"${type==='fixed'?' selected':''}>Fixed Amount</option></select></div></div>
    <div class="form-row"><div class="form-group"><label>Value</label><input id="cp-value" type="number" value="${value}"></div><div class="form-group"><label>Min Order (ETB)</label><input id="cp-min" type="number" value="${min_order}"></div></div>
    <div class="form-row"><div class="form-group"><label>Max Uses</label><input id="cp-max" type="number" value="${max_uses}"></div><div class="form-group"><label>Expires At</label><input id="cp-exp" type="date" value="${expires}"></div></div>
    <div class="form-group"><label>Active</label><select id="cp-active"><option value="1"${active?' selected':''}>Yes</option><option value="0"${!active?' selected':''}>No</option></select></div>
    <div class="form-actions"><button class="btn btn-primary" onclick="saveCoupon(${id})">💾 Save</button></div>`);
}
async function saveCoupon(id) {
  const r = await api('save_coupon',{id,code:v('cp-code'),type:v('cp-type'),value:v('cp-value'),min_order:v('cp-min'),max_uses:v('cp-max'),expires_at:v('cp-exp'),is_active:v('cp-active')},'POST');
  toast(r.ok?'Coupon saved':'Error: '+r.error, r.ok?'ok':'err');
  if (r.ok) { closeModal(); loadCoupons(); }
}
async function deleteCoupon(id) {
  if (!confirm('Delete coupon?')) return;
  const r = await api('delete_coupon',{id},'POST');
  toast(r.ok?'Deleted':'Error: '+r.error, r.ok?'ok':'err');
  if (r.ok) loadCoupons();
}

// ── USERS ───────────────────────────────────────────────────
let userPage = 1;
async function loadUsers() {
  const search = document.getElementById('user-search')?.value||'';
  const r = await api('users',{search,page:userPage}); if (!r.ok) { toast(r.error,'err'); return; }
  const {total,rows} = r.data;
  document.getElementById('users-tbody').innerHTML = rows.length ? rows.map(u=>`
    <tr>
      <td style="font-family:var(--fm);color:var(--text-muted)">#${u.id}</td>
      <td style="font-weight:700">${e(u.full_name)}</td>
      <td style="font-size:.8rem">${e(u.email)}</td>
      <td style="font-family:var(--fm);font-size:.8rem">${e(u.phone||'—')}</td>
      <td style="text-align:center;font-size:.8rem">${e(u.locale||'en').toUpperCase()}</td>
      <td>${pill(u.status||'active')}</td>
      <td style="font-size:.78rem;color:var(--text-dim)">${fmtDate(u.created_at)}</td>
      <td>${u.status!=='banned'?`<button class="btn btn-sm btn-danger" onclick="userAction(${u.id},'banned')">🚫 Ban</button>`:`<button class="btn btn-sm btn-success" onclick="userAction(${u.id},'active')">✅ Restore</button>`}</td>
    </tr>`).join('') : `<tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-muted)">No users found</td></tr>`;
  renderPag('users-pag', total, userPage, 25, p=>{userPage=p;loadUsers();});
}
async function userAction(id, status) {
  const r = await api('user_status',{id,status},'POST');
  toast(r.ok?`User ${status}`:'Error: '+r.error, r.ok?'ok':'err');
  if (r.ok) loadUsers();
}

// ── COURIERS ────────────────────────────────────────────────
async function loadCouriers() {
  const r = await api('couriers'); if (!r.ok) { toast(r.error,'err'); return; }
  document.getElementById('couriers-tbody').innerHTML = r.data.length ? r.data.map(c=>`
    <tr>
      <td style="font-weight:700">${e(c.full_name||'')}</td>
      <td style="font-family:var(--fm);font-size:.8rem">${e(c.phone||'—')}</td>
      <td>${e(c.vehicle_type||'—')}</td>
      <td>${e(c.zone||'—')}</td>
      <td>${pill(c.status||'active')}</td>
      <td style="text-align:center">${c.total_deliveries||0}</td>
    </tr>`).join('') : `<tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--text-muted)">No couriers</td></tr>`;
}

// ── FILE UPDATER ─────────────────────────────────────────────

// Known file → path map for auto-detection
const PATH_MAP = {
  'admin.html':                    'admin.html',
  'admin-panel.js':                'admin-panel.js',
  'updater.php':                   'updater.php',
  'health-monitor.js':             'health-monitor.js',
  'bootstrap.php':                 'bootstrap.php',
  '.htaccess':                     '.htaccess',
  // api/
  'admin.php':                     'api/admin.php',
  'config.php':                    'api/config.php',
  'diagnostics.php':               'api/diagnostics.php',
  // app/Core
  'Auth.php':                      'app/Core/Auth.php',
  'Database.php':                  'app/Core/Database.php',
  'Router.php':                    'app/Core/Router.php',
  'View.php':                      'app/Core/View.php',
  'EtelIDClient.php':              'app/Core/EtelIDClient.php',
  // app/Controllers
  'AuthController.php':            'app/Controllers/AuthController.php',
  'HomeController.php':            'app/Controllers/HomeController.php',
  'ProductController.php':         'app/Controllers/ProductController.php',
  'CartController.php':            'app/Controllers/CartController.php',
  'CustomerController.php':        'app/Controllers/CustomerController.php',
  'VendorController.php':          'app/Controllers/VendorController.php',
  'OrderController.php':           'app/Controllers/OrderController.php',
  // config
  'app.php':                       'config/app.php',
  // public
  'index.php':                     'public/index.php',
};

function guessPath(filename) {
  if (PATH_MAP[filename]) return PATH_MAP[filename];
  // Fallback heuristics
  const lower = filename.toLowerCase();
  if (lower.endsWith('controller.php')) return 'app/Controllers/' + filename;
  if (lower === 'login.php' || lower === 'register.php') return 'app/Views/storefront/' + filename;
  if (lower.endsWith('.php') && (lower.includes('controller') || lower.includes('model'))) return 'app/' + filename;
  return filename; // root fallback
}

function updTab(name, el) {
  document.querySelectorAll('.upd-tab').forEach(t=>t.classList.remove('active'));
  el.classList.add('active');
  document.querySelectorAll('.upd-pane').forEach(p=>p.classList.remove('active'));
  document.getElementById('upd-'+name).classList.add('active');
  if (name==='browse') loadFileTree();
  if (name==='system') loadUpdaterSystem();
}

let updHistory = [];
let updQueue   = []; // [{file, path}]

function handleDrop(ev) {
  ev.preventDefault();
  document.getElementById('upd-drop').classList.remove('drag-over');
  handleFileSelect(ev.dataTransfer.files);
}

function handleFileSelect(files) {
  for (const file of Array.from(files)) {
    const guessed = guessPath(file.name);
    updQueue.push({ file, path: guessed, id: Date.now() + Math.random() });
  }
  renderQueue();
}

function renderQueue() {
  const wrap = document.getElementById('upd-queue');
  const list = document.getElementById('upd-queue-list');
  if (!updQueue.length) { wrap.style.display='none'; return; }
  wrap.style.display = 'block';
  list.innerHTML = updQueue.map((item, idx) => `
    <div id="upd-qitem-${idx}" style="display:flex;align-items:center;gap:.6rem;padding:.55rem .65rem;
      background:rgba(0,0,0,.25);border:1px solid rgba(212,175,55,.1);border-radius:8px;margin-bottom:.4rem;flex-wrap:wrap">
      <span style="font-size:.9rem">${item.file.name.endsWith('.zip')?'🗜️':'📄'}</span>
      <span style="font-family:var(--fm);font-size:.8rem;color:var(--text);min-width:120px;flex-shrink:0">${e(item.file.name)}</span>
      <span style="color:var(--text-dim);font-size:.75rem;flex-shrink:0">→</span>
      <input type="text" value="${e(item.path)}"
        style="flex:1;min-width:180px;padding:.3rem .6rem;background:rgba(0,0,0,.35);border:1px solid rgba(212,175,55,.2);
               border-radius:6px;color:#e2e8f0;font-family:var(--fm);font-size:.78rem"
        onchange="updQueue[${idx}].path=this.value"
        oninput="updQueue[${idx}].path=this.value"
        title="Deploy path relative to EtelGebeya/ root">
      <span style="font-size:.7rem;color:var(--text-muted);flex-shrink:0">${humanFileSize(item.file.size)}</span>
      <button onclick="updQueue.splice(${idx},1);renderQueue()"
        style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);color:#ef4444;
               border-radius:6px;padding:.25rem .5rem;font-size:.75rem;cursor:pointer;flex-shrink:0">✕</button>
    </div>`).join('');
}

function updClearQueue() { updQueue=[]; renderQueue(); }

function humanFileSize(bytes) {
  if (bytes>=1048576) return (bytes/1048576).toFixed(1)+' MB';
  if (bytes>=1024)    return (bytes/1024).toFixed(1)+' KB';
  return bytes+' B';
}

async function updDeployAll() {
  if (!updQueue.length) return;
  const btn = document.getElementById('upd-deploy-btn');
  btn.disabled = true; btn.textContent = '⏳ Deploying…';
  const snapshot = [...updQueue];
  updQueue = [];
  renderQueue();
  for (const item of snapshot) await uploadFile(item.file, item.path);
  btn.disabled = false; btn.textContent = '🚀 Deploy All';
}

async function uploadFile(file, deployPath) {
  const prog  = document.getElementById('upd-progress');
  const bar   = document.getElementById('upd-bar');
  const pct   = document.getElementById('upd-progress-pct');
  const pfile = document.getElementById('upd-progress-file');
  prog.style.display = 'block';
  pfile.textContent = '📤 ' + deployPath;
  pct.textContent = '0%'; bar.style.width = '0%';

  const fd = new FormData();
  fd.append('auth', TOKEN); fd.append('action', 'upload');
  fd.append('file', file); fd.append('path', deployPath);
  const xhr = new XMLHttpRequest();
  xhr.open('POST', UPD_API);
  xhr.setRequestHeader('X-Auth', TOKEN);
  xhr.upload.onprogress = ev => {
    if (!ev.lengthComputable) return;
    const p = Math.round((ev.loaded/ev.total)*100);
    bar.style.width = p+'%'; pct.textContent = p+'%';
  };
  await new Promise(resolve => {
    xhr.onload = () => {
      prog.style.display = 'none';
      try {
        const d = JSON.parse(xhr.responseText);
        if (d.ok) {
          toast('✅ ' + deployPath, 'ok');
          updHistory.unshift({name: deployPath, time: new Date().toLocaleTimeString(), ok: true});
        } else {
          toast('❌ ' + deployPath + ': ' + d.error, 'err');
          updHistory.unshift({name: deployPath, time: new Date().toLocaleTimeString(), ok: false, err: d.error});
        }
      } catch(ex) { toast('Upload error: ' + file.name, 'err'); }
      renderUpdHistory();
      resolve();
    };
    xhr.onerror = () => { prog.style.display='none'; toast('Network error: '+file.name,'err'); resolve(); };
    xhr.send(fd);
  });
}

function renderUpdHistory() {
  const el = document.getElementById('upd-history');
  if (!updHistory.length) { el.textContent='No uploads yet this session.'; return; }
  el.innerHTML = updHistory.slice(0,10).map(h=>`
    <div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid rgba(212,175,55,.08)">
      <span>${h.ok?'✅':'❌'}</span>
      <span style="flex:1;font-family:var(--fm)">${e(h.name)}</span>
      <span style="color:var(--text-muted);font-size:.75rem">${h.time}</span>
    </div>`).join('');
}

async function loadFileTree() {
  const el = document.getElementById('upd-file-list');
  el.innerHTML = '<div style="color:var(--text-dim);font-size:.8rem">Loading…</div>';
  const r = await fetch(UPD_API+'?action=browse&auth='+encodeURIComponent(TOKEN), {headers:{'X-Auth':TOKEN}});
  const d = await r.json();
  if (!d.ok) { el.textContent = d.error||'Failed'; return; }
  el.innerHTML = `<div class="file-tree">${(d.data||[]).map(f=>`
    <div class="file-tree-item">
      <span>${f.is_dir?'📁':'📄'}</span>
      <span style="flex:1">${e(f.name)}</span>
      <span class="file-ext">${e(f.ext||'dir')}</span>
      <span style="color:var(--text-muted);font-size:.72rem">${e(f.size||'')}</span>
      <span style="color:var(--text-muted);font-size:.72rem;margin-left:8px">${e(f.modified||'')}</span>
    </div>`).join('')}</div>`;
}

async function loadUpdaterSystem() {
  const r = await fetch(UPD_API+'?action=status&auth='+encodeURIComponent(TOKEN), {headers:{'X-Auth':TOKEN}});
  const d = await r.json();
  if (!d.ok) { document.getElementById('upd-sys-grid').textContent = d.error||'Error'; return; }
  const s = d.data||{};
  document.getElementById('upd-sys-grid').innerHTML =
    Object.entries(s.files||{}).map(([name,info])=>`
      <div class="sys-info-card">
        <div class="sys-info-label">${e(name)}</div>
        <div class="sys-info-val" style="font-size:.85rem;margin-top:2px">${info.exists?'✅':'❌'} ${e(info.size||'')}</div>
        <div style="font-size:.7rem;color:var(--text-muted);margin-top:2px;font-family:var(--fm)">${e(info.modified||'')}</div>
      </div>`).join('') +
    `<div class="sys-info-card"><div class="sys-info-label">PHP Version</div><div class="sys-info-val">${e(s.php_version||'—')}</div></div>
     <div class="sys-info-card"><div class="sys-info-label">DB Tables</div><div class="sys-info-val">${s.db_tables||'—'}</div></div>
     <div class="sys-info-card"><div class="sys-info-label">DB Size</div><div class="sys-info-val">${e(s.db_size||'—')}</div></div>`;
}

// ── SETTINGS ────────────────────────────────────────────────
async function loadSettings() {
  const r = await api('settings'); if (!r.ok) { toast(r.error,'err'); return; }
  const s = r.data;
  const field = (key, label, type='text') =>
    `<div class="form-group"><label>${label}</label><input id="s-${key}" name="${key}" type="${type}" value="${e(s[key]||'')}"></div>`;
  document.getElementById('settings-form-wrap').innerHTML = `
    <div class="form-row">${field('site.name','Site Name (EN)')}${field('site.name_am','Site Name (አማርኛ)')}</div>
    <div class="form-row">${field('site.tagline.en','Tagline (EN)')}${field('site.tagline.am','Tagline (አማርኛ)')}</div>
    <div class="form-row">${field('site.commission','Default Commission %')}${field('site.currency','Currency Code')}</div>
    <div class="form-row">${field('mail.support','Support Email')}${field('site.contact_phone','Support Phone')}</div>
    <div class="form-row">
      <div class="form-group"><label>COD Enabled</label><select id="s-site.cod_enabled" name="site.cod_enabled"><option value="1"${s['site.cod_enabled']=='1'?' selected':''}>Yes</option><option value="0"${s['site.cod_enabled']!='1'?' selected':''}>No</option></select></div>
      <div class="form-group"><label>Chapa Enabled</label><select id="s-site.chapa_enabled" name="site.chapa_enabled"><option value="1"${s['site.chapa_enabled']=='1'?' selected':''}>Yes</option><option value="0"${s['site.chapa_enabled']!='1'?' selected':''}>No</option></select></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Telebirr Enabled</label><select id="s-site.telebirr_enabled" name="site.telebirr_enabled"><option value="1"${s['site.telebirr_enabled']=='1'?' selected':''}>Yes</option><option value="0"${s['site.telebirr_enabled']!='1'?' selected':''}>No</option></select></div>
      <div class="form-group"><label>Maintenance Mode</label><select id="s-maintenance.mode" name="maintenance.mode"><option value="0"${s['maintenance.mode']!='1'?' selected':''}>Off</option><option value="1"${s['maintenance.mode']=='1'?' selected':''}>ON (site hidden)</option></select></div>
    </div>`;
}
async function saveSettings() {
  const inputs = document.querySelectorAll('#settings-form-wrap input, #settings-form-wrap select');
  const data = {action:'save_settings', auth:TOKEN};
  inputs.forEach(el=>{ if(el.name) data[el.name]=el.value; });
  const r = await fetch(API, {method:'POST', body:new URLSearchParams(data)});
  const d = await r.json();
  toast(d.ok?`✅ ${d.data?.saved||0} settings saved`:'Error: '+d.error, d.ok?'ok':'err');
}

async function grantAdminAccess() {
  const phone = (document.getElementById('grant-phone')?.value || '').trim();
  const res   = document.getElementById('grant-result');
  if (!res) return;
  if (!phone) { res.innerHTML = '<span style="color:#f59e0b">Enter your phone number first.</span>'; return; }
  if (!TOKEN) { res.innerHTML = '<span style="color:#ef4444">Not logged in to admin panel.</span>'; return; }
  res.innerHTML = '<span style="color:#94a3b8">Processing…</span>';
  try {
    const r = await fetch(API + '?action=grant_admin', {
      method: 'POST',
      body: new URLSearchParams({ auth: TOKEN, phone })
    });
    const text = await r.text();
    let d;
    try { d = JSON.parse(text); } catch(e) {
      res.innerHTML = '<span style="color:#ef4444">Server error: ' + text.slice(0,120) + '</span>';
      return;
    }
    if (d.ok) {
      res.innerHTML = '<span style="color:#22c55e">✅ Admin role granted for ' + phone + '. Sign out and log in again via Etel-ID.</span>';
    } else {
      res.innerHTML = '<span style="color:#ef4444">❌ ' + (d.error || 'Failed') + '</span>';
    }
  } catch(e) {
    res.innerHTML = '<span style="color:#ef4444">Network error: ' + e.message + '</span>';
  }
}

// ── HELPERS ─────────────────────────────────────────────────
function e(str) { if(str==null)return''; return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function v(id)  { return document.getElementById(id)?.value||''; }
function fmt(n) { return Number(n||0).toLocaleString(); }
function fmtMoney(n) { return Number(n||0).toLocaleString('en-ET',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function fmtDate(s) { if(!s)return'—'; try{return new Date(s).toLocaleString('en-ET',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});}catch{return s;} }

const pillColors = {published:'published',approved:'approved',paid:'paid',delivered:'delivered',active:'active',pending:'pending',processing:'processing',shipped:'shipped',rejected:'rejected',cancelled:'cancelled',failed:'failed',banned:'banned',suspended:'suspended',draft:'draft',archived:'draft'};
function pill(s) { s=(s||'').toLowerCase(); return `<span class="pill pill-${pillColors[s]||'draft'}">${s}</span>`; }

function renderPag(id, total, page, limit, cb) {
  const pages=Math.ceil(total/limit), el=document.getElementById(id);
  if (!el) return;
  if (pages<=1){el.innerHTML='';return;}
  let html=`<span class="pag-info">Page ${page} of ${pages} · ${fmt(total)} total</span>`;
  const from=Math.max(1,page-2),to=Math.min(pages,page+2);
  if(page>1)html+=`<button class="pag-btn" onclick="(${cb.toString()})(${page-1})">‹</button>`;
  for(let p=from;p<=to;p++)html+=`<button class="pag-btn${p===page?' active':''}" onclick="(${cb.toString()})(${p})">${p}</button>`;
  if(page<pages)html+=`<button class="pag-btn" onclick="(${cb.toString()})(${page+1})">›</button>`;
  el.innerHTML=html;
}

function openModal(html) { document.getElementById('modal-body').innerHTML=html; document.getElementById('modal').classList.add('open'); }
function closeModal()    { document.getElementById('modal').classList.remove('open'); }

function toast(msg, type='info') {
  const wrap=document.getElementById('toast-wrap'), el=document.createElement('div');
  el.className=`toast toast-${type}`; el.textContent=msg;
  wrap.appendChild(el); setTimeout(()=>el.remove(),3500);
}

function debounce(fn, delay) { let t; return(...args)=>{clearTimeout(t);t=setTimeout(()=>fn(...args),delay);}; }
function exportCSV(type) { toast(`📥 Export for ${type} — connect to backend export endpoint`,'info'); }

// ── FLOATING HEALTH MONITOR ──────────────────────────────────
// Persistent mini-diagnostic button visible on all admin pages
(function initHealthMonitor() {
  const CSS = `
    #hm-btn{position:fixed;bottom:22px;right:22px;z-index:9999;width:44px;height:44px;border-radius:50%;
      border:2px solid var(--gold,#D4A017);background:rgba(10,10,15,.92);cursor:pointer;display:flex;
      align-items:center;justify-content:center;font-size:20px;box-shadow:0 2px 12px rgba(0,0,0,.5);
      transition:transform .2s;user-select:none;}
    #hm-btn:hover{transform:scale(1.12);}
    #hm-btn.hm-ok{border-color:#22c55e;}
    #hm-btn.hm-warn{border-color:#f59e0b;}
    #hm-btn.hm-fail{border-color:#ef4444;}
    #hm-btn .hm-pulse{position:absolute;inset:-4px;border-radius:50%;animation:hm-ring 2s ease-out infinite;}
    #hm-btn.hm-fail .hm-pulse{border:2px solid #ef4444;}
    #hm-btn.hm-warn .hm-pulse{border:2px solid #f59e0b;}
    @keyframes hm-ring{0%{opacity:.7;transform:scale(1)}100%{opacity:0;transform:scale(1.5)}}
    #hm-panel{position:fixed;bottom:76px;right:22px;z-index:9998;width:340px;max-height:480px;
      overflow-y:auto;background:rgba(10,10,15,.97);border:1px solid var(--gold,#D4A017);
      border-radius:12px;padding:14px;box-shadow:0 8px 32px rgba(0,0,0,.6);
      font-family:'JetBrains Mono',monospace;font-size:11px;color:#e2e8f0;display:none;}
    #hm-panel.open{display:block;}
    #hm-panel h3{margin:0 0 10px;font-size:13px;color:var(--gold,#D4A017);display:flex;align-items:center;gap:6px;}
    #hm-panel h3 span{margin-left:auto;font-size:10px;color:#64748b;cursor:pointer;}
    .hm-row{display:flex;align-items:center;gap:8px;padding:4px 0;border-bottom:1px solid rgba(255,255,255,.04);}
    .hm-row:last-child{border:none;}
    .hm-icon{font-size:13px;flex-shrink:0;}
    .hm-name{flex:1;color:#94a3b8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .hm-msg{font-size:10px;color:#64748b;max-width:160px;text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    #hm-summary{display:flex;gap:8px;margin-bottom:10px;font-size:11px;}
    #hm-summary span{padding:2px 8px;border-radius:20px;font-weight:700;}
    .hm-s-pass{background:rgba(34,197,94,.15);color:#22c55e;}
    .hm-s-fail{background:rgba(239,68,68,.15);color:#ef4444;}
    .hm-s-warn{background:rgba(245,158,11,.15);color:#f59e0b;}
    .hm-s-info{background:rgba(100,116,139,.15);color:#94a3b8;}
    #hm-footer{margin-top:10px;display:flex;gap:6px;}
    #hm-footer button{flex:1;padding:6px;border-radius:6px;border:1px solid rgba(255,255,255,.1);
      background:rgba(255,255,255,.05);color:#e2e8f0;cursor:pointer;font-size:10px;}
    #hm-footer button:hover{background:rgba(212,160,23,.15);border-color:var(--gold,#D4A017);}
    #hm-page-errors{margin-top:10px;border-top:1px solid rgba(255,255,255,.06);padding-top:8px;}
    #hm-page-errors h4{margin:0 0 6px;font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.05em;}
    .hm-err-row{padding:3px 0;font-size:10px;color:#fca5a5;word-break:break-all;}
  `;
  const style = document.createElement('style');
  style.textContent = CSS;
  document.head.appendChild(style);

  const btn = document.createElement('div');
  btn.id = 'hm-btn';
  btn.innerHTML = '<div class="hm-pulse"></div>🔬';
  btn.title = 'Health Monitor';

  const panel = document.createElement('div');
  panel.id = 'hm-panel';
  panel.innerHTML = `
    <h3>🔬 Health Monitor <span id="hm-close">✕</span></h3>
    <div id="hm-summary"></div>
    <div id="hm-checks"></div>
    <div id="hm-page-errors" style="display:none">
      <h4>Page Errors</h4>
      <div id="hm-err-list"></div>
    </div>
    <div id="hm-footer">
      <button onclick="hmRefresh()">↺ Refresh</button>
      <button onclick="switchPanel('diagnostics', document.querySelector('[data-panel=diagnostics]'))">Full Diagnostics</button>
    </div>`;

  document.body.appendChild(btn);
  document.body.appendChild(panel);

  // Capture JS errors on this page
  const pageErrors = [];
  window.addEventListener('error', ev => {
    pageErrors.push(ev.message + (ev.filename ? ' @ ' + ev.filename.split('/').pop() + ':' + ev.lineno : ''));
    hmUpdatePageErrors();
  });
  window.addEventListener('unhandledrejection', ev => {
    pageErrors.push('Unhandled Promise: ' + String(ev.reason).slice(0,120));
    hmUpdatePageErrors();
  });

  function hmUpdatePageErrors() {
    if (!pageErrors.length) return;
    const el = document.getElementById('hm-page-errors');
    const list = document.getElementById('hm-err-list');
    if (el) { el.style.display = 'block'; }
    if (list) list.innerHTML = pageErrors.slice(-8).map(e => `<div class="hm-err-row">⚠ ${e}</div>`).join('');
    // Flash button red on new error
    btn.classList.remove('hm-ok','hm-warn');
    btn.classList.add('hm-fail');
  }

  let panelOpen = false;
  let lastData = null;
  let polling = null;

  btn.addEventListener('click', () => {
    panelOpen = !panelOpen;
    panel.classList.toggle('open', panelOpen);
    if (panelOpen && !lastData) hmRefresh();
    if (panelOpen) {
      if (!polling) polling = setInterval(hmRefresh, 30000);
    } else {
      clearInterval(polling); polling = null;
    }
  });

  document.getElementById('hm-close').addEventListener('click', (ev) => {
    ev.stopPropagation();
    panelOpen = false;
    panel.classList.remove('open');
    clearInterval(polling); polling = null;
  });

  window.hmRefresh = async function() {
    const checksEl = document.getElementById('hm-checks');
    const summaryEl = document.getElementById('hm-summary');
    if (!TOKEN) { if (checksEl) checksEl.textContent = 'Not logged in'; return; }
    if (checksEl) checksEl.innerHTML = '<div style="color:#64748b;padding:8px 0">Running checks…</div>';
    try {
      const r = await fetch('api/diagnostics.php?check=all&auth='+encodeURIComponent(TOKEN));
      const d = await r.json();
      if (!d.ok) { if (checksEl) checksEl.textContent = d.error||'Error'; return; }
      lastData = d.data;
      const {checks, summary, elapsed_ms} = d.data;

      // Update button state
      btn.classList.remove('hm-ok','hm-warn','hm-fail');
      if (summary.fail > 0) btn.classList.add('hm-fail');
      else if (summary.warn > 0) btn.classList.add('hm-warn');
      else btn.classList.add('hm-ok');
      btn.innerHTML = '<div class="hm-pulse"></div>' + (summary.fail>0?'❌':summary.warn>0?'⚠️':'✅');

      if (summaryEl) summaryEl.innerHTML =
        `<span class="hm-s-pass">✓ ${summary.pass}</span>
         ${summary.fail?`<span class="hm-s-fail">✗ ${summary.fail}</span>`:''}
         ${summary.warn?`<span class="hm-s-warn">⚠ ${summary.warn}</span>`:''}
         <span class="hm-s-info">i ${summary.info}</span>
         <span style="margin-left:auto;color:#64748b">${elapsed_ms}ms</span>`;

      const icon = s => s==='pass'?'✅':s==='fail'?'❌':s==='warn'?'⚠️':'ℹ️';
      if (checksEl) checksEl.innerHTML = checks.map(c=>`
        <div class="hm-row">
          <span class="hm-icon">${icon(c.status)}</span>
          <span class="hm-name">${c.name}</span>
          <span class="hm-msg" title="${c.msg}">${c.msg}</span>
        </div>`).join('');

      // Add page errors section
      if (pageErrors.length) hmUpdatePageErrors();
    } catch(err) {
      if (checksEl) checksEl.textContent = 'Fetch failed: ' + err.message;
      btn.classList.add('hm-fail');
    }
  };

  // Auto-run 5 seconds after login (non-blocking)
  setTimeout(() => {
    if (TOKEN && document.getElementById('app')?.style.display !== 'none') {
      hmRefresh().then(() => {
        // Show badge on button if issues found
      });
    }
  }, 5000);
})();

// ── STARTUP ──────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('login-pass').addEventListener('keydown', e => { if(e.key==='Enter') doLogin(); });
  if (TOKEN) init();
  else document.getElementById('login-screen').style.display = 'flex';
});

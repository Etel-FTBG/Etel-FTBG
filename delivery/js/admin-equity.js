/* ============================================================
   ETEL Delivery — Equity & Investors panel (Premium)
   Founder-only (gated by 'equity.view'/'equity.manage' capabilities).
   Visual language ported from the standalone premium mockup: 3D glass
   cards, gold gradients, animated conic-gradient ownership donut,
   premium badges/buttons - scoped under #panel-equity so nothing here
   leaks into the rest of admin.html's styling.
   Data comes from the real API (EquityController.php), not localStorage.
   ============================================================ */

let EQUITY_SETTINGS = { target_amount: 500000, investor_equity_pct: 20, total_shares: 10000, founder_capital_contributed: 0 };
let EQUITY_INVESTORS = [];
let EQUITY_EDIT_ID = null;

function eqFmt(n) {
  return 'ETB ' + Math.round(Number(n) || 0).toLocaleString();
}
function eqPct(n) {
  return (Math.round((Number(n) || 0) * 100) / 100).toFixed(2) + '%';
}
function eqEsc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}
function eqToast(msg, isErr) {
  let t = document.getElementById('eq-toast');
  if (!t) {
    t = document.createElement('div');
    t.id = 'eq-toast';
    t.className = 'eq-toast';
    document.body.appendChild(t);
  }
  t.textContent = msg;
  t.style.background = isErr ? 'linear-gradient(135deg,#ff5d6c,#ff9aa4)' : 'linear-gradient(135deg,#C8960C,#F2CC6B)';
  t.classList.add('show');
  clearTimeout(t._hideTimer);
  t._hideTimer = setTimeout(() => t.classList.remove('show'), 2600);
}

/* ---------- Scoped premium CSS (injected once) ---------- */
function ensureEquityPremiumCss() {
  if (document.getElementById('eq-premium-css')) return;
  const css = document.createElement('style');
  css.id = 'eq-premium-css';
  css.textContent = `
    @import url('https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=JetBrains+Mono:wght@400;500;700&display=swap');
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan){
      --eq-carbon-1:#030205; --eq-gold-1:#C8960C; --eq-gold-2:#D4AF37; --eq-gold-light:#F2CC6B;
      --eq-text-hi:#f8f1e2; --eq-text-mid:#cabf9f; --eq-text-dim:#8a7f66;
      --eq-green:#3fce7a; --eq-red:#ff5d6c; --eq-blue:#5aa9ff;
      position:relative;
    }
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-shell{
      background:
        radial-gradient(ellipse 1100px 700px at 12% -10%, rgba(212,175,55,.16), transparent 60%),
        radial-gradient(ellipse 800px 600px at 105% 5%, rgba(90,169,255,.06), transparent 55%);
      border-radius:24px; padding:4px; position:relative;
    }
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) h2{font-family:'Syne',sans-serif;}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-card{
      position:relative;
      background:linear-gradient(165deg, rgba(24,17,8,.94) 0%, rgba(8,5,2,.97) 100%);
      border:1px solid rgba(212,175,55,.35); border-radius:20px; padding:22px; overflow:hidden;
      box-shadow:0 24px 50px -12px rgba(0,0,0,.55), 0 1px 0 rgba(255,255,255,.06) inset;
      transition:box-shadow .25s ease; margin-bottom:20px;
    }
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-card:hover{ box-shadow:0 32px 64px -14px rgba(0,0,0,.65), 0 0 36px -12px rgba(212,175,55,.25); }
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-card::before{
      content:""; position:absolute; top:0; left:0; right:0; height:3px;
      background:linear-gradient(90deg, transparent, var(--eq-gold-2), var(--eq-gold-light), var(--eq-gold-2), transparent);
      box-shadow:0 0 14px rgba(242,204,107,.55);
    }
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-card-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;gap:10px;flex-wrap:wrap;}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-card-head h2{font-size:16.5px;color:var(--eq-gold-light);text-shadow:0 0 18px rgba(242,204,107,.25);margin:0;}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-tag{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--eq-text-dim);font-weight:700;border:1px solid rgba(212,175,55,.3);padding:4px 11px;border-radius:20px;background:rgba(212,175,55,.05);}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-grid{display:grid;gap:16px;grid-template-columns:repeat(4,1fr);}
    @media(max-width:1050px){:is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-grid{grid-template-columns:repeat(2,1fr);}}
    @media(max-width:560px){:is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-grid{grid-template-columns:1fr;}}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-kpi .eq-icon{
      width:36px;height:36px;border-radius:11px;margin-bottom:10px;
      background:linear-gradient(135deg, rgba(212,175,55,.2), rgba(212,175,55,.02));
      border:1px solid rgba(212,175,55,.25); display:flex;align-items:center;justify-content:center;font-size:16px;
      box-shadow:0 6px 14px rgba(0,0,0,.3), inset 0 1px 0 rgba(255,255,255,.08);
    }
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-kpi .eq-label{font-size:10.5px;text-transform:uppercase;letter-spacing:.07em;color:var(--eq-text-dim);font-weight:700;}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-kpi .eq-value{
      font-family:'JetBrains Mono',monospace;font-size:24px;font-weight:700;margin-top:6px;
      background:linear-gradient(135deg,#F2CC6B,#C8960C); -webkit-background-clip:text;background-clip:text;color:transparent;
      filter:drop-shadow(0 0 14px rgba(212,175,55,.22));
    }
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-kpi .eq-sub{font-size:11.5px;color:var(--eq-text-mid);margin-top:6px;}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-kpi .eq-bar{height:6px;border-radius:8px;background:rgba(255,255,255,.05);margin-top:12px;overflow:hidden;box-shadow:inset 0 1px 4px rgba(0,0,0,.5);}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-kpi .eq-bar>i{display:block;height:100%;background:linear-gradient(90deg,#C8960C,#F2CC6B);border-radius:8px;box-shadow:0 0 10px rgba(242,204,107,.5);transition:width .6s cubic-bezier(.2,.8,.2,1);}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) table{width:100%;border-collapse:collapse;font-size:13px;}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) th{text-align:left;padding:10px;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--eq-text-dim);border-bottom:1px solid rgba(212,175,55,.25);white-space:nowrap;font-weight:700;}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) td{padding:11px 10px;border-bottom:1px solid rgba(255,255,255,.04);color:var(--eq-text-mid);}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) tr:hover td{background:rgba(212,175,55,.04);}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) td.hi{color:var(--eq-text-hi);font-weight:700;}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) td.mono{font-family:'JetBrains Mono',monospace;}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-badge{padding:4px 11px;border-radius:20px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.03em;box-shadow:0 2px 6px rgba(0,0,0,.25);display:inline-block;}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-badge.paid,:is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-badge.signed{background:rgba(63,206,122,.15);color:var(--eq-green);border:1px solid rgba(63,206,122,.3);}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-badge.pending,:is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-badge.sent{background:rgba(255,196,0,.15);color:#ffce54;border:1px solid rgba(255,196,0,.3);}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-badge.unpaid{background:rgba(255,93,108,.15);color:var(--eq-red);border:1px solid rgba(255,93,108,.3);}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-badge.draft{background:rgba(255,255,255,.08);color:var(--eq-text-mid);border:1px solid rgba(255,255,255,.15);}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-btn{font-weight:700;font-size:13px;padding:11px 18px;border-radius:12px;border:1px solid rgba(255,255,255,.08);
      background:linear-gradient(160deg, rgba(255,255,255,.06), rgba(255,255,255,.01)); color:var(--eq-text-hi);cursor:pointer;
      transition:all .18s cubic-bezier(.2,.8,.2,1); box-shadow:0 6px 12px rgba(0,0,0,.25);}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-btn:hover{transform:translateY(-2px);box-shadow:0 10px 20px rgba(0,0,0,.35);}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-btn.primary{background:linear-gradient(135deg,#C8960C,#F2CC6B);color:#0d0d0d;border:none;box-shadow:0 10px 22px rgba(200,150,12,.35), 0 2px 0 rgba(255,255,255,.5) inset;}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-btn.primary:hover{filter:brightness(1.06);transform:translateY(-3px);}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-btn.danger{border-color:rgba(255,93,108,.35);color:#ff9aa4;}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-donut-wrap{display:flex;align-items:center;gap:24px;flex-wrap:wrap;}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-donut{position:relative;width:170px;height:170px;border-radius:50%;flex:none;
      transform-style:preserve-3d; transform:rotateX(52deg); filter:drop-shadow(0 20px 26px rgba(0,0,0,.5));
      animation:eqSpin3d 26s linear infinite;}
    @keyframes eqSpin3d{from{transform:rotateX(52deg) rotateZ(0deg);}to{transform:rotateX(52deg) rotateZ(360deg);}}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-donut .eq-ring{position:absolute;inset:0;border-radius:50%;}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-donut .eq-hole{position:absolute;inset:27%;border-radius:50%;
      background:radial-gradient(circle at 35% 30%, #1c1608, #0a0703 75%); box-shadow:inset 0 5px 12px rgba(0,0,0,.6), 0 0 0 1px rgba(255,255,255,.05);}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-donut-label{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;transform:rotateX(-52deg);}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-legend{display:flex;flex-direction:column;gap:9px;font-size:12.5px;color:var(--eq-text-mid);}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-legend .eq-dot{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:8px;vertical-align:middle;box-shadow:0 0 7px currentColor;}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-split{display:flex;height:32px;border-radius:9px;overflow:hidden;margin:14px 0 0;border:1px solid rgba(0,0,0,.4);box-shadow:0 6px 16px rgba(0,0,0,.4), inset 0 1px 0 rgba(255,255,255,.1);}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-split>div{display:flex;align-items:center;justify-content:center;font-family:'JetBrains Mono',monospace;font-size:11px;font-weight:800;color:#0d0d0d;position:relative;overflow:hidden;}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-split>div::after{content:"";position:absolute;inset:0;background:linear-gradient(180deg, rgba(255,255,255,.35), transparent 60%);}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-empty{text-align:center;padding:36px 18px;color:var(--eq-text-dim);}
    :is(#panel-equity, #panel-platform-econ, #panel-equb, #panel-couriers, #panel-payroll, #panel-business-plan) .eq-empty .eq-emoji{font-size:32px;display:block;margin-bottom:10px;opacity:.6;}
    .eq-modal-form label{display:block;font-size:11px;color:#b8ab88;text-transform:uppercase;letter-spacing:.06em;font-weight:700;margin-bottom:6px;}
    .eq-modal-form input,.eq-modal-form select,.eq-modal-form textarea{
      width:100%;padding:11px 13px;border-radius:11px;background:linear-gradient(160deg, rgba(255,255,255,.05), rgba(255,255,255,.01));
      border:1px solid rgba(212,175,55,.3);color:#f8f1e2;font-size:13.5px;margin-bottom:13px;box-sizing:border-box;box-shadow:inset 0 2px 5px rgba(0,0,0,.3);}
    .eq-modal-form input:focus,.eq-modal-form select:focus{outline:none;border-color:#D4AF37;box-shadow:0 0 0 3px rgba(212,175,55,.15);}
    .eq-toast{position:fixed;bottom:26px;right:26px;color:#0d0d0d;padding:13px 20px;border-radius:13px;font-weight:700;font-size:13px;
      box-shadow:0 14px 34px rgba(0,0,0,.5), 0 2px 0 rgba(255,255,255,.5) inset;z-index:99999;
      transform:translateY(20px) scale(.95);opacity:0;transition:all .3s cubic-bezier(.2,.8,.2,1);pointer-events:none;}
    .eq-toast.show{transform:translateY(0) scale(1);opacity:1;}
  `;
  document.head.appendChild(css);
}

/* Build a conic-gradient donut ring from {label, value, color} slices */
function openEquityBreakevenModal(id) {
  const inv = EQUITY_INVESTORS.find(i => i.id === id);
  if (!inv) return;
  const target = EQUITY_SETTINGS.target_amount, pct = EQUITY_SETTINGS.investor_equity_pct;
  const amt = Number(inv.amount_invested);
  const ownership = target > 0 ? (amt / target) * pct : 0;
  openModal('custom', eqModalWrap(`
      <h2 style="margin-bottom:4px;color:#F2CC6B;font-family:'Syne',sans-serif">📈 Breakeven — ${eqEsc(inv.name)}</h2>
      <div style="font-size:11px;color:#8a7f66;font-family:'JetBrains Mono',monospace;margin-bottom:14px">${eqEsc(inv.investor_code)} · Invested ${eqFmt(amt)} · Owns ${eqPct(ownership)}</div>
      <div style="font-size:12px;color:#8a7f66;margin-bottom:16px;line-height:1.5">
        Assumes the company distributes the same profit figure every period (e.g. monthly or quarterly —
        whatever period you actually plan to run distributions on). This is a projection based on a number
        you supply, not a promise or guarantee of future profit.
      </div>
      <label>Assumed company profit per period (ETB)</label>
      <input id="eq-be-profit" type="number" min="1" placeholder="e.g. 50000" oninput="renderEquityBreakeven(${id})">
      <div id="eq-be-output"></div>
  `));
}

function renderEquityBreakeven(id) {
  const out = document.getElementById('eq-be-output');
  if (!out) return;
  const inv = EQUITY_INVESTORS.find(i => i.id === id);
  const profitPerPeriod = Number(document.getElementById('eq-be-profit').value) || 0;
  if (!profitPerPeriod || !inv) { out.innerHTML = ''; return; }
  const target = EQUITY_SETTINGS.target_amount, pct = EQUITY_SETTINGS.investor_equity_pct;
  const amt = Number(inv.amount_invested);
  const ownership = target > 0 ? (amt / target) * pct : 0;
  const sharePerPeriod = profitPerPeriod * (ownership / 100);
  if (sharePerPeriod <= 0) {
    out.innerHTML = `<div style="color:#ff9aa4;font-size:12.5px;margin-top:8px">At this profit level, ${eqEsc(inv.name)}'s share per period would be zero.</div>`;
    return;
  }
  const periodsNeeded = Math.ceil(amt / sharePerPeriod);
  const totalCompanyProfitNeeded = periodsNeeded * profitPerPeriod;
  out.innerHTML = `
    <div class="eq-grid" style="grid-template-columns:1fr 1fr;margin:14px 0">
      <div class="eq-card eq-kpi" style="margin-bottom:0"><div class="eq-label">Their Share / Period</div><div class="eq-value" style="font-size:19px">${eqFmt(sharePerPeriod)}</div></div>
      <div class="eq-card eq-kpi" style="margin-bottom:0"><div class="eq-label">Periods To Break Even</div><div class="eq-value" style="font-size:19px">${periodsNeeded}</div></div>
    </div>
    <div style="font-size:12.5px;color:#cabf9f;line-height:1.6">
      At ${eqFmt(profitPerPeriod)} company profit every period, ${eqEsc(inv.name)} recovers their
      ${eqFmt(amt)} investment after about <b style="color:#F2CC6B">${periodsNeeded} period${periodsNeeded===1?'':'s'}</b> —
      meaning the company needs to generate roughly <b style="color:#F2CC6B">${eqFmt(totalCompanyProfitNeeded)}</b> in
      cumulative profit (across those periods) before this investor has made their money back.
      Anything beyond that point is their actual return on top of the original investment.
    </div>`;
}

function eqDonutRing(slices) {
  const total = slices.reduce((s, x) => s + x.value, 0) || 1;
  let acc = 0;
  const stops = slices.map(s => {
    const from = (acc / total) * 360; acc += s.value;
    const to = (acc / total) * 360;
    return `${s.color} ${from}deg ${to}deg`;
  }).join(', ');
  return `conic-gradient(${stops})`;
}

async function loadEquityPanel() {
  ensureEquityPremiumCss();
  const host = document.getElementById('panel-equity');
  if (host && !host.querySelector('.eq-shell-marker')) {
    // wrap existing static markup (buttons row etc.) so the shell
    // background glow renders behind everything without touching the
    // buttons/section-title markup already in admin.html
  }
  await Promise.all([loadEquityDashboard(), loadEquityInvestorsList()]);
  renderEquityProfitTools();
}

/* ---------- Dashboard ---------- */

async function loadEquityDashboard() {
  const el = document.getElementById('equity-dashboard-body');
  if (!el) return;
  el.innerHTML = '<div class="eq-empty">Loading…</div>';
  const res = await api('equity_get_dashboard');
  if (!res.success) {
    el.innerHTML = `<div class="eq-card" style="color:#ff9aa4">${eqEsc(res.message || 'Could not load equity dashboard')}</div>`;
    return;
  }
  EQUITY_SETTINGS = res.data.settings;
  const d = res.data;
  const founderPct = Math.max(0, 100 - d.equity_issued_pct);
  const donutSlices = [
    { label: 'Founder', value: founderPct, color: '#C8960C' },
    ...d.cap_table.map((r, i) => ({ label: r.name, value: r.ownership_pct, color: i % 2 === 0 ? '#F2CC6B' : '#5aa9ff' })),
  ];
  el.innerHTML = `
    <div class="eq-grid" style="margin-bottom:18px">
      <div class="eq-card eq-kpi"><div class="eq-icon">💰</div><div class="eq-label">Total Raised</div><div class="eq-value">${eqFmt(d.total_committed)}</div>
        <div class="eq-sub">of ${eqFmt(d.settings.target_amount)} target</div>
        <div class="eq-bar"><i style="width:${Math.min(100, (d.total_committed / d.settings.target_amount) * 100).toFixed(1)}%"></i></div></div>
      <div class="eq-card eq-kpi"><div class="eq-icon">📊</div><div class="eq-label">Equity Issued</div><div class="eq-value">${eqPct(d.equity_issued_pct)}</div>
        <div class="eq-sub">of ${eqPct(d.settings.investor_equity_pct)} investor pool</div></div>
      <div class="eq-card eq-kpi"><div class="eq-icon">🧑‍💼</div><div class="eq-label">Investors</div><div class="eq-value">${d.investor_count}</div>
        <div class="eq-sub">Remaining: ${eqFmt(d.remaining_to_raise)}</div></div>
      <div class="eq-card eq-kpi"><div class="eq-icon">🪙</div><div class="eq-label">Price / Share</div><div class="eq-value">${eqFmt(d.price_per_share)}</div>
        <div class="eq-sub">${d.settings.total_shares.toLocaleString()} total shares</div></div>
    </div>
    <div class="eq-card" style="margin-bottom:18px;border-left:3px solid #6a5a2a">
      <div class="eq-card-head"><h2>👤 Founder Capital</h2><span class="eq-tag" style="opacity:.75">Not part of the investor pool</span></div>
      <div style="padding:4px 2px 2px;color:#B8B0A0;font-size:13px;line-height:1.5">
        Cash the founder has personally put into the company - kept separate on
        purpose. It never counts toward "Total Raised" above, never affects
        Equity Issued or Price/Share, and issuing it real founder shares
        requires an accountant to value it properly against the company's
        actual capitalization - this is a record-keeping figure, not a cap-table entry.
      </div>
      <div class="eq-value" style="margin-top:8px;font-size:22px">${eqFmt(d.settings.founder_capital_contributed || 0)}</div>
    </div>
    <div class="eq-card">
      <div class="eq-card-head"><h2>Ownership Split</h2><span class="eq-tag">Founder retains ${founderPct.toFixed(1)}%</span></div>
      <div class="eq-donut-wrap">
        <div class="eq-donut" style="background:${eqDonutRing(donutSlices)}">
          <div class="eq-hole"></div>
          <div class="eq-donut-label"><div style="font-family:'JetBrains Mono',monospace;font-weight:700;font-size:15px;color:#F2CC6B">${eqPct(d.equity_issued_pct)}</div><div style="font-size:9px;color:#8a7f66;text-transform:uppercase;letter-spacing:.05em">Issued</div></div>
        </div>
        <div class="eq-legend">
          ${donutSlices.map(s => `<div><span class="eq-dot" style="background:${s.color};color:${s.color}"></span>${eqEsc(s.label)} — ${eqPct(s.value)}</div>`).join('')}
        </div>
      </div>
      <div class="eq-split">
        <div style="width:${founderPct}%;background:linear-gradient(135deg,#C8960C,#8a5e14)">Founder ${founderPct.toFixed(0)}%</div>
        <div style="width:${d.equity_issued_pct}%;background:linear-gradient(135deg,#F2CC6B,#C8960C)">${d.equity_issued_pct >= 4 ? 'Investors ' + d.equity_issued_pct.toFixed(1) + '%' : ''}</div>
      </div>
      <div style="margin-top:18px;overflow-x:auto">
        <table><thead><tr><th>Holder</th><th>Amount</th><th>Ownership %</th><th>Shares</th></tr></thead><tbody>
          <tr><td class="hi">Founder</td><td class="mono">—</td><td class="mono">${founderPct.toFixed(2)}%</td><td class="mono">${(d.settings.total_shares - d.cap_table.reduce((s,r)=>s+r.shares,0)).toFixed(0)}</td></tr>
          ${d.cap_table.map(r => `<tr><td>${eqEsc(r.name)}</td><td class="mono">${eqFmt(r.amount_invested)}</td><td class="mono">${eqPct(r.ownership_pct)}</td><td class="mono">${r.shares.toFixed(2)}</td></tr>`).join('') || '<tr><td colspan="4" style="color:#8a7f66;text-align:center;padding:16px">No investors yet</td></tr>'}
        </tbody></table>
      </div>
    </div>
    <div class="eq-card">
      <div class="eq-card-head"><h2>💰 Unsold Share Cash-Bonus Calculator</h2><span class="eq-tag">PREVIEW ONLY — not a real transaction</span></div>
      <div style="font-size:12.5px;color:#8a7f66;line-height:1.6;margin-bottom:16px">
        A formula, not an equity grant — this doesn't create any new shareholders, change anyone's real ownership %, or touch anything outside the ${eqPct(d.settings.investor_equity_pct)} investor pool. It's the ETB value of the pool's still-unsold shares (Remaining to Raise — already shown above), split the way you described: 50% as a cash-equivalent bonus pool for employees/shareholders, 50% staying with the company. Nothing here is recorded or paid out automatically.
      </div>
      <div class="eq-grid" style="margin-bottom:0">
        <div class="eq-card eq-kpi" style="background:rgba(255,255,255,.02)">
          <div class="eq-icon">🎯</div><div class="eq-label">Unsold share value</div>
          <div class="eq-value">${eqFmt(d.remaining_to_raise)}</div>
          <div class="eq-sub">${eqPct(d.settings.investor_equity_pct - d.equity_issued_pct)} of the company, still unsold</div>
        </div>
        <div class="eq-card eq-kpi" style="background:rgba(212,175,55,.06);border-color:rgba(212,175,55,.3)">
          <div class="eq-icon">🎁</div><div class="eq-label">Employee & shareholder bonus pool (50%)</div>
          <div class="eq-value">${eqFmt(d.remaining_to_raise / 2)}</div>
          <div class="eq-sub">Cash-equivalent — divide among people however you decide</div>
        </div>
        <div class="eq-card eq-kpi" style="background:rgba(255,255,255,.02)">
          <div class="eq-icon">🏢</div><div class="eq-label">Remains with company (50%)</div>
          <div class="eq-value">${eqFmt(d.remaining_to_raise / 2)}</div>
          <div class="eq-sub">Not distributed</div>
        </div>
      </div>
      ${d.cap_table.length === 0 ? `
      <div style="margin-top:16px;padding:14px;border:1px dashed rgba(212,175,55,.25);border-radius:10px;color:#8a7f66;font-size:12.5px;text-align:center">
        No investors have actually closed yet, so there's no one to split the ${eqFmt(d.remaining_to_raise / 2)} bonus pool among. This section fills in automatically as soon as a real investor is added below.
      </div>` : `
      <div style="margin-top:16px;overflow-x:auto">
        <div style="font-size:12.5px;color:#8a7f66;margin-bottom:10px">
          Bonus pool split by each investor's share of what's <em>actually issued</em> (${eqPct(d.equity_issued_pct)}) — not the full 20% pool, since the unsold portion has no owner to pay a bonus to.
        </div>
        <table><thead><tr><th>Investor</th><th>Share of issued equity</th><th>Bonus share</th></tr></thead>
          <tbody>
            ${d.cap_table.map(inv => {
              const shareOfIssued = d.equity_issued_pct > 0 ? (inv.ownership_pct / d.equity_issued_pct) : 0;
              const investorBonus = (d.remaining_to_raise / 2) * shareOfIssued;
              return `<tr><td>${eqEsc(inv.name)}</td><td class="mono">${eqPct(shareOfIssued * 100)} of issued</td><td class="mono">${eqFmt(investorBonus)}</td></tr>`;
            }).join('')}
          </tbody>
        </table>
      </div>`}
      <div style="font-size:11.5px;color:#8a7f66;margin-top:14px;padding-top:14px;border-top:1px solid rgba(212,175,55,.12)">
        ⚠ Turning any part of the bonus pool into <em>real</em> equity (rather than a cash-equivalent payout) is a different, bigger step - it typically needs a formal agreement (vesting terms, an ESOP-style structure) and has its own tax treatment. This calculator deliberately only handles the cash-bonus version discussed - it does not create, transfer, or record any share ownership.
      </div>
    </div>`;
}

/* ---------- Round settings ---------- */

// The site-wide #modal-box is hardcoded background:#fff (fine for other
// admin forms, invisible for our light gold/cream text). Rather than
// touching that shared element, wrap our content in a dark full-bleed
// panel that covers the white padding via negative margin.
function eqModalWrap(innerHtml) {
  return `<div class="eq-modal-form" style="margin:-32px;padding:28px;border-radius:16px;
    background:linear-gradient(165deg, #1c1408 0%, #0a0703 100%);
    border:1px solid rgba(212,175,55,.35);
    box-shadow:0 20px 50px rgba(0,0,0,.5) inset;">${innerHtml}</div>`;
}

function openEquityScenarioModal() {
  const cur = EQUITY_SETTINGS;
  openModal('custom', eqModalWrap(`
      <h2 style="margin-bottom:4px;color:#F2CC6B;font-family:'Syne',sans-serif">🔬 What-If Scenario</h2>
      <div style="font-size:12px;color:#8a7f66;margin-bottom:16px;line-height:1.5">
        Preview only — nothing is saved until you tap "Apply as Real Settings" below.
        Shows how a different target raise / equity pool would change everyone's ownership
        vs. what they actually have right now.
      </div>
      <div style="display:flex;gap:10px">
        <div style="flex:1"><label>Scenario target raise (ETB)</label>
          <input id="eq-sc-target" type="number" min="1" value="${cur.target_amount}" oninput="renderEquityScenario()"></div>
        <div style="flex:1"><label>Scenario equity pool (%)</label>
          <input id="eq-sc-pct" type="number" min="0.01" max="100" step="0.01" value="${cur.investor_equity_pct}" oninput="renderEquityScenario()"></div>
      </div>
      <div id="eq-scenario-output"></div>
      <button class="eq-btn primary" style="width:100%;margin-top:6px" onclick="applyEquityScenario()">✅ Apply as Real Settings</button>
  `));
  renderEquityScenario();
}

function renderEquityScenario() {
  const out = document.getElementById('eq-scenario-output');
  if (!out) return;
  const newTarget = Number(document.getElementById('eq-sc-target').value) || 0;
  const newPct = Number(document.getElementById('eq-sc-pct').value) || 0;
  const curTarget = EQUITY_SETTINGS.target_amount, curPct = EQUITY_SETTINGS.investor_equity_pct;
  const impliedValuation = newPct > 0 ? newTarget / (newPct / 100) : 0;
  const curValuation = curPct > 0 ? curTarget / (curPct / 100) : 0;
  const valuationDelta = curValuation > 0 ? ((impliedValuation - curValuation) / curValuation) * 100 : 0;

  const rows = EQUITY_INVESTORS.map(inv => {
    const amt = Number(inv.amount_invested);
    const oldOwn = curTarget > 0 ? (amt / curTarget) * curPct : 0;
    const newOwn = newTarget > 0 ? (amt / newTarget) * newPct : 0;
    const diff = newOwn - oldOwn;
    return `<tr><td>${eqEsc(inv.name)}</td><td class="mono">${eqPct(oldOwn)}</td><td class="mono">${eqPct(newOwn)}</td>
      <td class="mono" style="color:${diff < -0.0001 ? '#ff5d6c' : diff > 0.0001 ? '#3fce7a' : '#8a7f66'}">${diff >= 0 ? '+' : ''}${eqPct(diff)}</td></tr>`;
  }).join('') || '<tr><td colspan="4" style="text-align:center;color:#8a7f66;padding:12px">No investors yet</td></tr>';

  const anyDiluted = EQUITY_INVESTORS.some(inv => {
    const amt = Number(inv.amount_invested);
    const oldOwn = curTarget > 0 ? (amt / curTarget) * curPct : 0;
    const newOwn = newTarget > 0 ? (amt / newTarget) * newPct : 0;
    return newOwn < oldOwn - 0.0001;
  });

  out.innerHTML = `
    <div class="eq-grid" style="grid-template-columns:1fr 1fr;margin:14px 0">
      <div class="eq-card eq-kpi" style="margin-bottom:0"><div class="eq-label">Current Valuation</div><div class="eq-value" style="font-size:19px">${eqFmt(curValuation)}</div></div>
      <div class="eq-card eq-kpi" style="margin-bottom:0"><div class="eq-label">Scenario Valuation</div><div class="eq-value" style="font-size:19px">${eqFmt(impliedValuation)}</div>
        <div class="eq-sub" style="color:${valuationDelta >= 0 ? '#3fce7a' : '#ff5d6c'}">${valuationDelta >= 0 ? '+' : ''}${valuationDelta.toFixed(1)}% vs current</div></div>
    </div>
    ${anyDiluted ? `<div style="background:linear-gradient(135deg, rgba(255,93,108,.1), rgba(255,93,108,.03));border:1px solid rgba(255,93,108,.35);border-radius:12px;padding:12px 14px;font-size:12.5px;color:#ffb3ba;margin-bottom:14px">
      ⚠️ This scenario reduces existing investors' ownership % below what they currently have. Changing terms after money is already in is a real fairness/legal issue, not just a display change — talk to existing investors before applying this.</div>` : ''}
    <div style="overflow-x:auto"><table><thead><tr><th>Investor</th><th>Current %</th><th>Scenario %</th><th>Change</th></tr></thead><tbody>${rows}</tbody></table></div>
  `;
}

async function applyEquityScenario() {
  const newTarget = Number(document.getElementById('eq-sc-target').value);
  const newPct = Number(document.getElementById('eq-sc-pct').value);
  if (!newTarget || !newPct) { eqToast('Enter valid numbers first', true); return; }
  const anyDiluted = EQUITY_INVESTORS.some(inv => {
    const amt = Number(inv.amount_invested);
    const oldOwn = EQUITY_SETTINGS.target_amount > 0 ? (amt / EQUITY_SETTINGS.target_amount) * EQUITY_SETTINGS.investor_equity_pct : 0;
    const newOwn = newTarget > 0 ? (amt / newTarget) * newPct : 0;
    return newOwn < oldOwn - 0.0001;
  });
  if (anyDiluted && !confirm('This reduces one or more existing investors\' ownership %. Are you sure you want to apply this to the real, live settings?')) return;
  const res = await api('equity_update_settings', { target_amount: newTarget, investor_equity_pct: newPct, total_shares: EQUITY_SETTINGS.total_shares });
  if (!res.success) { eqToast(res.message || 'Could not save', true); return; }
  closeModal();
  eqToast('✓ Settings updated from scenario');
  loadEquityPanel();
}

function openEquitySettingsModal() {
  // price_per_share isn't stored anywhere - it's always target /
  // (total_shares * pct/100), same formula EquityController.php uses.
  // Computed here just to pre-fill the field; the actual math source
  // of truth stays the backend.
  const startPrice = (EQUITY_SETTINGS.total_shares > 0 && EQUITY_SETTINGS.investor_equity_pct > 0)
    ? EQUITY_SETTINGS.target_amount / (EQUITY_SETTINGS.total_shares * EQUITY_SETTINGS.investor_equity_pct / 100)
    : 0;

  openModal('custom', eqModalWrap(`
      <h2 style="margin-bottom:16px;color:#F2CC6B;font-family:'Syne',sans-serif">⚙️ Round Settings</h2>
      <label>Target raise (ETB)</label>
      <input id="eq-s-target" type="number" min="1" value="${EQUITY_SETTINGS.target_amount}" oninput="eqSyncFromShares()">
      <label>Investor equity pool (%)</label>
      <input id="eq-s-pct" type="number" min="0.01" max="100" step="0.01" value="${EQUITY_SETTINGS.investor_equity_pct}" oninput="eqSyncFromShares()">
      <label>Total shares</label>
      <input id="eq-s-shares" type="number" min="1" value="${EQUITY_SETTINGS.total_shares}" oninput="eqSyncFromShares()">
      <label>Price per share (ETB) <span style="font-weight:400;color:#8a7f66;font-size:11px">— editing this recalculates Total shares, not Target or Investor %</span></label>
      <input id="eq-s-price" type="number" min="0.01" step="0.01" value="${startPrice.toFixed(2)}" oninput="eqSyncFromPrice()">
      <hr style="border-color:#3a3220;margin:16px 0">
      <label>Founder capital contributed (ETB) <span style="font-weight:400;color:#8a7f66;font-size:11px">— record-keeping only, never affects the investor math above</span></label>
      <input id="eq-s-founder-capital" type="number" min="0" step="0.01" value="${EQUITY_SETTINGS.founder_capital_contributed || 0}">
      <button class="eq-btn primary" style="width:100%;margin-top:6px" onclick="saveEquitySettings()">💾 Save</button>
  `));
}

/* Keeps Price/Share and Total Shares in sync with each other, always
 * treating Target and Investor % as the two fixed inputs - this is
 * the same "Option A" approach discussed with the founder directly:
 * editing price this way never silently changes what % of the
 * company an existing investor's dollar amount represents (that's
 * amount/target*pct, independent of total_shares/price entirely) -
 * only the share-count convention used to represent that % changes. */
function eqSyncFromShares() {
  const target = Number(document.getElementById('eq-s-target').value) || 0;
  const pct = Number(document.getElementById('eq-s-pct').value) || 0;
  const shares = Number(document.getElementById('eq-s-shares').value) || 0;
  const priceEl = document.getElementById('eq-s-price');
  if (!priceEl) return;
  const pool = shares * (pct / 100);
  priceEl.value = pool > 0 ? (target / pool).toFixed(2) : '0.00';
}
function eqSyncFromPrice() {
  const target = Number(document.getElementById('eq-s-target').value) || 0;
  const pct = Number(document.getElementById('eq-s-pct').value) || 0;
  const price = Number(document.getElementById('eq-s-price').value) || 0;
  const sharesEl = document.getElementById('eq-s-shares');
  if (!sharesEl || price <= 0 || pct <= 0) return;
  const pool = target / price;
  sharesEl.value = Math.round(pool / (pct / 100));
}

let EQUITY_SETTINGS_SAVE_IN_FLIGHT = false;
async function saveEquitySettings() {
  if (EQUITY_SETTINGS_SAVE_IN_FLIGHT) return;
  const target_amount = Number(document.getElementById('eq-s-target').value);
  const investor_equity_pct = Number(document.getElementById('eq-s-pct').value);
  const total_shares = Number(document.getElementById('eq-s-shares').value);
  const fcEl = document.getElementById('eq-s-founder-capital');
  const founder_capital_contributed = fcEl ? Number(fcEl.value) || 0 : undefined;
  const btn = document.querySelector('.eq-modal-form .eq-btn.primary');
  EQUITY_SETTINGS_SAVE_IN_FLIGHT = true;
  if (btn) { btn.disabled = true; btn.style.opacity = '.6'; btn.textContent = 'Saving…'; }
  try {
    const payload = { target_amount, investor_equity_pct, total_shares };
    if (founder_capital_contributed !== undefined) payload.founder_capital_contributed = founder_capital_contributed;
    const res = await api('equity_update_settings', payload);
    if (!res.success) { eqToast(res.message || 'Could not save', true); return; }
    closeModal();
    eqToast('✓ Round settings saved');
    loadEquityPanel();
  } finally {
    EQUITY_SETTINGS_SAVE_IN_FLIGHT = false;
  }
}

/* ---------- Investors CRUD ---------- */

async function loadEquityInvestorsList() {
  const el = document.getElementById('equity-investors-list');
  if (!el) return;
  el.innerHTML = '<div class="eq-empty">Loading…</div>';
  const res = await api('equity_list_investors');
  if (!res.success) {
    el.innerHTML = `<div class="eq-card" style="color:#ff9aa4">${eqEsc(res.message || 'Could not load investors')}</div>`;
    return;
  }
  EQUITY_INVESTORS = res.data.investors;
  if (!EQUITY_INVESTORS.length) {
    el.innerHTML = '<div class="eq-card eq-empty"><span class="eq-emoji">🧑‍💼</span>No investors yet. Add one above.</div>';
    return;
  }
  const target = EQUITY_SETTINGS.target_amount, pct = EQUITY_SETTINGS.investor_equity_pct;
  el.innerHTML = `<div class="eq-card" style="overflow-x:auto"><table><thead><tr><th>Name</th><th>Amount</th><th>Ownership%</th><th>Payment</th><th>Agreement</th><th>Receipt</th><th>Actions</th></tr></thead><tbody>
    ${EQUITY_INVESTORS.map(inv => {
      const own = target > 0 ? (Number(inv.amount_invested) / target) * pct : 0;
      return `<tr>
        <td class="hi">${eqEsc(inv.name)}<div style="font-size:11px;color:#8a7f66">${eqEsc(inv.investor_code || '')}${inv.contact ? ' · ' + eqEsc(inv.contact) : ''}</div></td>
        <td class="mono">${eqFmt(inv.amount_invested)}</td>
        <td class="mono">${eqPct(own)}</td>
        <td><span class="eq-badge ${eqEsc(inv.payment_status)}">${eqEsc(inv.payment_status)}</span></td>
        <td><span class="eq-badge ${eqEsc(inv.agreement_status)}">${eqEsc(inv.agreement_status)}</span></td>
        <td class="mono">${eqEsc(inv.receipt_number || '')}</td>
        <td style="white-space:nowrap">
          <button class="eq-btn" style="padding:7px 12px;font-size:12px" onclick="openInvestorPortalLink('${inv.access_token}')" title="Open this investor's own view">🔗</button>
          <button class="eq-btn" style="padding:7px 12px;font-size:12px" onclick="openEquityBreakevenModal(${inv.id})">📈</button>
          <button class="eq-btn" style="padding:7px 12px;font-size:12px" onclick="openEquityInvestorEdit(${inv.id})">✏️</button>
          <button class="eq-btn danger" style="padding:7px 12px;font-size:12px" onclick="deleteEquityInvestor(${inv.id},'${eqEsc(inv.name).replace(/'/g,"\\'")}')">🗑</button>
        </td>
      </tr>`;
    }).join('')}
  </tbody></table></div>`;
}

function openEquityInvestorEdit(id) {
  const inv = id ? EQUITY_INVESTORS.find(i => i.id === id) : null;
  EQUITY_EDIT_ID = id || null;
  const portalUrl = inv ? investorPortalUrl(inv.access_token) : '';
  openModal('custom', eqModalWrap(`
      <h2 style="margin-bottom:4px;color:#F2CC6B;font-family:'Syne',sans-serif">${inv ? '✏️ Edit' : '➕ Add'} Investor</h2>
      ${inv ? `<div style="font-size:11px;color:#8a7f66;font-family:'JetBrains Mono',monospace;margin-bottom:14px">${eqEsc(inv.investor_code)}</div>` : '<div style="margin-bottom:14px"></div>'}
      <label>Name</label>
      <input id="eq-i-name" value="${eqEsc(inv ? inv.name : '')}">
      <label>Contact</label>
      <input id="eq-i-contact" value="${eqEsc(inv ? inv.contact : '')}">
      <label>Amount invested (ETB)</label>
      <input id="eq-i-amount" type="number" min="1" value="${inv ? inv.amount_invested : ''}">
      <div style="display:flex;gap:10px">
        <div style="flex:1"><label>Payment</label>
          <select id="eq-i-payment">${['unpaid','pending','paid'].map(v => `<option value="${v}" ${inv && inv.payment_status===v?'selected':''}>${v}</option>`).join('')}</select></div>
        <div style="flex:1"><label>Agreement</label>
          <select id="eq-i-agreement">${['draft','sent','signed'].map(v => `<option value="${v}" ${inv && inv.agreement_status===v?'selected':''}>${v}</option>`).join('')}</select></div>
      </div>
      <label>Receipt number</label>
      <input id="eq-i-receipt" value="${eqEsc(inv ? inv.receipt_number : '')}">
      <label>Notes</label>
      <textarea id="eq-i-notes" style="min-height:60px">${eqEsc(inv ? inv.notes : '')}</textarea>
      ${inv ? `
      <div style="border-top:1px solid rgba(212,175,55,.2);margin-top:6px;padding-top:14px">
        <label>EtelID access (open any time by logging in — no link needed)</label>
        ${inv.user_id ? `
        <div style="display:flex;align-items:center;justify-content:space-between;background:rgba(63,206,122,.08);border:1px solid rgba(63,206,122,.3);border-radius:10px;padding:10px 13px;margin-bottom:14px">
          <div style="font-size:13px;color:#cabf9f">✅ Linked to <b style="color:#f8f1e2">${eqEsc(inv.linked_user_name || '')}</b><div style="font-size:11px;color:#8a7f66">${eqEsc(inv.linked_user_phone || '')}</div></div>
          <button type="button" class="eq-btn danger" style="padding:7px 12px;font-size:12px" onclick="unlinkInvestorUser(${inv.id})">Unlink</button>
        </div>` : `
        <div style="display:flex;gap:8px;margin-bottom:14px">
          <input id="eq-i-link-phone" placeholder="Investor's EtelID phone number" style="flex:1">
          <button type="button" class="eq-btn" style="padding:0 14px" onclick="linkInvestorUser(${inv.id})">🔗 Link</button>
        </div>`}
      </div>
      <div style="border-top:1px solid rgba(212,175,55,.2);margin-top:6px;padding-top:14px">
        <label>Fallback: shareable link (for investors without an EtelID)</label>
        <div style="display:flex;gap:8px;margin-bottom:14px">
          <input id="eq-i-portal-url" readonly value="${eqEsc(portalUrl)}" style="flex:1;font-size:11.5px">
          <button type="button" class="eq-btn" style="padding:0 14px" onclick="copyInvestorPortalLink()">📋 Copy</button>
          <button type="button" class="eq-btn" style="padding:0 14px" onclick="regenerateInvestorToken(${inv.id})">🔄 New link</button>
        </div>
        <label style="margin-bottom:8px">What this investor can see in their own view</label>
        <label style="display:flex;align-items:center;gap:8px;font-weight:400;text-transform:none;letter-spacing:0;font-size:13px;color:#cabf9f;margin-bottom:8px">
          <input type="checkbox" id="eq-i-can-captable" ${inv.can_view_captable ? 'checked' : ''} style="width:auto;margin:0"> Full cap table (everyone's ownership %, not just their own)
        </label>
        <label style="display:flex;align-items:center;gap:8px;font-weight:400;text-transform:none;letter-spacing:0;font-size:13px;color:#cabf9f;margin-bottom:8px">
          <input type="checkbox" id="eq-i-can-distributions" ${inv.can_view_distributions ? 'checked' : ''} style="width:auto;margin:0"> Their own profit distribution history
        </label>
        <label style="display:flex;align-items:center;gap:8px;font-weight:400;text-transform:none;letter-spacing:0;font-size:13px;color:#cabf9f;margin-bottom:14px">
          <input type="checkbox" id="eq-i-can-growth" ${inv.can_view_growth_planner ? 'checked' : ''} style="width:auto;margin:0"> Growth & Goal Planner (business-sensitive - off by default)
        </label>
      </div>` : ''}
      <button class="eq-btn primary" style="width:100%;margin-top:6px" onclick="saveEquityInvestor()">💾 Save</button>
  `));
}

function investorPortalUrl(token) {
  return window.location.origin + window.location.pathname.replace(/\/admin.*$/, '') + '/investor/?token=' + encodeURIComponent(token);
}

function openInvestorPortalLink(token) {
  window.open(investorPortalUrl(token), '_blank');
}

function copyInvestorPortalLink() {
  const input = document.getElementById('eq-i-portal-url');
  input.select();
  navigator.clipboard ? navigator.clipboard.writeText(input.value).then(() => eqToast('✓ Link copied')) : document.execCommand('copy');
}

async function linkInvestorUser(investorId) {
  const phoneEl = document.getElementById('eq-i-link-phone');
  const phone = phoneEl ? phoneEl.value.trim() : '';
  if (!phone) { eqToast('Enter the investor\'s phone number', true); return; }
  const res = await api('equity_link_investor_user', { investor_id: investorId, phone });
  if (!res.success) { eqToast(res.message || 'Could not link', true); return; }
  eqToast('✓ ' + res.message);
  await loadEquityInvestorsList();
  openEquityInvestorEdit(investorId);
}

async function unlinkInvestorUser(investorId) {
  if (!confirm('Remove this investor\'s EtelID access? They can still use their shareable link if one exists.')) return;
  const res = await api('equity_unlink_investor_user', { investor_id: investorId });
  if (!res.success) { eqToast(res.message || 'Could not unlink', true); return; }
  eqToast('✓ EtelID unlinked');
  await loadEquityInvestorsList();
  openEquityInvestorEdit(investorId);
}

async function regenerateInvestorToken(id) {
  if (!confirm('This invalidates the investor\'s current link immediately. Continue?')) return;
  const res = await api('equity_regenerate_investor_token', { id });
  if (!res.success) { eqToast(res.message || 'Could not regenerate', true); return; }
  eqToast('✓ New link generated');
  await loadEquityInvestorsList();
  openEquityInvestorEdit(id);
}

let EQUITY_SAVE_IN_FLIGHT = false;
async function saveEquityInvestor() {
  if (EQUITY_SAVE_IN_FLIGHT) return; // guards against double-tap/double-submit on a slow connection
  const payload = {
    name: document.getElementById('eq-i-name').value.trim(),
    contact: document.getElementById('eq-i-contact').value.trim(),
    amount_invested: Number(document.getElementById('eq-i-amount').value),
    payment_status: document.getElementById('eq-i-payment').value,
    agreement_status: document.getElementById('eq-i-agreement').value,
    receipt_number: document.getElementById('eq-i-receipt').value.trim(),
    notes: document.getElementById('eq-i-notes').value.trim(),
  };
  if (!payload.name || !payload.amount_invested) { eqToast('Enter a name and a valid amount', true); return; }
  const action = EQUITY_EDIT_ID ? 'equity_update_investor' : 'equity_add_investor';
  if (EQUITY_EDIT_ID) {
    payload.id = EQUITY_EDIT_ID;
    const capEl = document.getElementById('eq-i-can-captable');
    const distEl = document.getElementById('eq-i-can-distributions');
    const growthEl = document.getElementById('eq-i-can-growth');
    if (capEl) payload.can_view_captable = capEl.checked;
    if (distEl) payload.can_view_distributions = distEl.checked;
    if (growthEl) payload.can_view_growth_planner = growthEl.checked;
  }
  const btn = document.querySelector('.eq-modal-form .eq-btn.primary');
  EQUITY_SAVE_IN_FLIGHT = true;
  if (btn) { btn.disabled = true; btn.style.opacity = '.6'; btn.style.cursor = 'default'; btn.textContent = 'Saving…'; }
  try {
    const res = await api(action, payload);
    if (!res.success) { eqToast(res.message || 'Could not save', true); return; }
    closeModal();
    eqToast(EQUITY_EDIT_ID ? '✓ Investor updated' : '✓ Investor added');
    loadEquityPanel();
  } finally {
    EQUITY_SAVE_IN_FLIGHT = false;
  }
}

async function deleteEquityInvestor(id, name) {
  if (!confirm(`Remove investor "${name}"? This cannot be undone.`)) return;
  const res = await api('equity_delete_investor', { id });
  if (!res.success) { eqToast(res.message || 'Could not delete', true); return; }
  eqToast('Investor removed');
  loadEquityPanel();
}

function exportEquityInvestorsCsv() {
  const token = Auth.getToken();
  window.open(ETEL.api + '?action=equity_export_investors_csv&token=' + encodeURIComponent(token), '_blank');
}

/* ---------- Profit distribution ---------- */

function renderEquityProfitTools() {
  const el = document.getElementById('equity-profit-body');
  if (!el) return;
  el.innerHTML = `
    <div class="eq-card">
      <div class="eq-card-head"><h2>📅 Profit Target Planner</h2><span class="eq-tag">Preview only</span></div>
      <div style="font-size:12px;color:#8a7f66;margin-bottom:16px;line-height:1.5">
        Pick a target raise scenario and how often you plan to run distributions, then enter a profit
        assumption for that period. This shows the full breakdown that assumption implies — it does not
        know your real order/ride volume or margins, since that depends on your actual business, not
        anything this tool can see. If you give it your average profit per completed order/ride below,
        it will also work out roughly how many you'd need in that period.
      </div>
      <label>Target raise scenario (ETB)</label>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
        ${[100000, 300000, 500000, 1000000].map(v => `<button class="eq-btn" style="flex:1;min-width:110px" onclick="document.getElementById('eq-plan-target').value=${v};renderEquityProfitPlan()">${eqFmt(v)}</button>`).join('')}
      </div>
      <div style="display:flex;gap:10px">
        <div style="flex:1"><label>Target raise (ETB)</label>
          <input id="eq-plan-target" type="number" min="1" value="${EQUITY_SETTINGS.target_amount}" oninput="renderEquityProfitPlan()"></div>
        <div style="flex:1"><label>Distribution period</label>
          <select id="eq-plan-period" onchange="renderEquityProfitPlan()">
            <option value="1">Daily</option>
            <option value="7">Weekly</option>
            <option value="30" selected>Monthly</option>
            <option value="365">Yearly</option>
          </select></div>
      </div>
      <label>Assumed company profit per period (ETB)</label>
      <input id="eq-plan-profit" type="number" min="0" placeholder="e.g. 50000" oninput="renderEquityProfitPlan()">
      <label>Optional: average profit per completed order/ride (ETB) — if you know it</label>
      <input id="eq-plan-per-unit" type="number" min="0" placeholder="e.g. 25" oninput="renderEquityProfitPlan()">
      <div id="eq-plan-output"></div>
    </div>
    <div class="eq-card">
      <div class="eq-card-head"><h2>💸 Distribute Profit</h2></div>
      <label style="display:block;font-size:11px;color:#8a7f66;text-transform:uppercase;letter-spacing:.06em;font-weight:700;margin-bottom:6px">Total profit for this period (ETB)</label>
      <div style="display:flex;gap:10px;margin-bottom:14px">
        <input id="eq-profit-input" type="number" min="0" placeholder="e.g. 50000" style="flex:1;padding:11px 13px;border-radius:11px;background:linear-gradient(160deg, rgba(255,255,255,.05), rgba(255,255,255,.01));border:1px solid rgba(212,175,55,.3);color:#f8f1e2;font-size:14px;box-shadow:inset 0 2px 5px rgba(0,0,0,.3)" oninput="previewEquityDistribution()">
        <button class="eq-btn" onclick="previewEquityDistribution()">Preview</button>
      </div>
      <div id="eq-profit-preview"></div>
      <button class="eq-btn primary" style="margin-top:14px;width:100%" onclick="commitEquityDistribution()">✅ Record This Distribution</button>
    </div>
    <div class="eq-card">
      <div class="eq-card-head"><h2>📜 Distribution History</h2></div>
      <div id="eq-dist-history">Loading…</div>
    </div>`;
  loadEquityDistributionHistory();
}

function renderEquityProfitPlan() {
  const out = document.getElementById('eq-plan-output');
  if (!out) return;
  const target = Number(document.getElementById('eq-plan-target').value) || 0;
  const periodDays = Number(document.getElementById('eq-plan-period').value) || 30;
  const profitPerPeriod = Number(document.getElementById('eq-plan-profit').value) || 0;
  const perUnit = Number(document.getElementById('eq-plan-per-unit').value) || 0;
  if (!target || !profitPerPeriod) { out.innerHTML = ''; return; }
  const pct = EQUITY_SETTINGS.investor_equity_pct;
  const investorPool = profitPerPeriod * (pct / 100);
  const founderPool = profitPerPeriod - investorPool;
  const company = founderPool * 0.30, charity = founderPool * 0.10, personal = founderPool * 0.40;
  const periodsPerYear = 365 / periodDays;
  const annualProfit = profitPerPeriod * periodsPerYear;
  const periodLabel = periodDays === 1 ? 'day' : periodDays === 7 ? 'week' : periodDays === 30 ? 'month' : 'year';

  const investorRows = EQUITY_INVESTORS.map(inv => {
    const amt = Number(inv.amount_invested);
    const ownership = target > 0 ? (amt / target) * pct : 0;
    const share = investorPool * (target > 0 ? amt / target : 0);
    const periodsToBreakeven = share > 0 ? Math.ceil(amt / share) : null;
    return `<tr><td>${eqEsc(inv.name)}</td><td class="mono">${eqPct(ownership)}</td><td class="mono hi">${eqFmt(share)}</td>
      <td class="mono">${periodsToBreakeven ? periodsToBreakeven + ' ' + periodLabel + (periodsToBreakeven===1?'':'s') : '—'}</td></tr>`;
  }).join('') || '<tr><td colspan="4" style="text-align:center;color:#8a7f66;padding:12px">No investors yet</td></tr>';

  let unitsHtml = '';
  if (perUnit > 0) {
    const unitsNeeded = Math.ceil(profitPerPeriod / perUnit);
    const unitsPerDay = (unitsNeeded / periodDays).toFixed(1);
    unitsHtml = `<div style="background:linear-gradient(135deg, rgba(90,169,255,.08), rgba(90,169,255,.02));border:1px solid rgba(90,169,255,.25);border-radius:12px;padding:14px 16px;font-size:12.5px;color:#b9d6ff;margin-top:14px;line-height:1.6">
      At ${eqFmt(perUnit)} average profit per order/ride, hitting ${eqFmt(profitPerPeriod)} profit this ${periodLabel}
      needs roughly <b>${unitsNeeded.toLocaleString()} completed orders/rides</b> (~${unitsPerDay}/day over ${periodDays} days).
      This is only as accurate as the per-unit profit figure you entered — it's not something this tool measured.
    </div>`;
  }

  out.innerHTML = `
    <div class="eq-grid" style="margin:14px 0">
      <div class="eq-card eq-kpi" style="margin-bottom:0"><div class="eq-label">Annualized Profit</div><div class="eq-value" style="font-size:19px">${eqFmt(annualProfit)}</div>
        <div class="eq-sub">at this ${periodLabel}ly rate</div></div>
      <div class="eq-card eq-kpi" style="margin-bottom:0"><div class="eq-label">Investor Pool (${pct}%)</div><div class="eq-value" style="font-size:19px">${eqFmt(investorPool)}</div>
        <div class="eq-sub">per ${periodLabel}</div></div>
      <div class="eq-card eq-kpi" style="margin-bottom:0"><div class="eq-label">Company (30%)</div><div class="eq-value" style="font-size:19px">${eqFmt(company)}</div></div>
      <div class="eq-card eq-kpi" style="margin-bottom:0"><div class="eq-label">Charity + Personal</div><div class="eq-value" style="font-size:19px">${eqFmt(charity + personal)}</div>
        <div class="eq-sub">10% + 40% of founder's 80%</div></div>
    </div>
    ${unitsHtml}
    <div style="overflow-x:auto;margin-top:14px"><table><thead><tr><th>Investor</th><th>Ownership</th><th>Share / ${periodLabel}</th><th>Breakeven</th></tr></thead><tbody>${investorRows}</tbody></table></div>
  `;
}



async function previewEquityDistribution() {
  const profit = Number(document.getElementById('eq-profit-input').value) || 0;
  const preview = document.getElementById('eq-profit-preview');
  if (!profit) { preview.innerHTML = ''; return; }
  const res = await api('equity_preview_distribution', { profit_amount: profit });
  if (!res.success) { preview.innerHTML = `<div style="color:#ff9aa4">${eqEsc(res.message)}</div>`; return; }
  const d = res.data;
  preview.innerHTML = `
    <div class="eq-grid" style="margin-bottom:12px">
      <div class="eq-card eq-kpi" style="margin-bottom:0"><div class="eq-label">Investor Pool (${EQUITY_SETTINGS.investor_equity_pct}%)</div><div class="eq-value">${eqFmt(d.investor_pool)}</div></div>
      <div class="eq-card eq-kpi" style="margin-bottom:0"><div class="eq-label">Company (30%)</div><div class="eq-value">${eqFmt(d.company_amount)}</div></div>
      <div class="eq-card eq-kpi" style="margin-bottom:0"><div class="eq-label">Charity (10%)</div><div class="eq-value">${eqFmt(d.charity_amount)}</div></div>
      <div class="eq-card eq-kpi" style="margin-bottom:0"><div class="eq-label">Personal (40%)</div><div class="eq-value">${eqFmt(d.personal_amount)}</div></div>
    </div>
    <div style="overflow-x:auto"><table><thead><tr><th>Investor</th><th>Ownership%</th><th>Profit Share</th></tr></thead><tbody>
      ${d.investor_shares.map(r => `<tr><td>${eqEsc(r.name)}</td><td class="mono">${eqPct(r.ownership_pct)}</td><td class="mono hi">${eqFmt(r.profit_share)}</td></tr>`).join('') || '<tr><td colspan="3" style="text-align:center;color:#8a7f66;padding:12px">No investors yet</td></tr>'}
    </tbody></table></div>`;
}

let EQUITY_DIST_IN_FLIGHT = false;
async function commitEquityDistribution() {
  if (EQUITY_DIST_IN_FLIGHT) return;
  const profit = Number(document.getElementById('eq-profit-input').value) || 0;
  if (!profit) { eqToast('Enter a profit amount first', true); return; }
  if (!confirm(`Record a profit distribution of ${eqFmt(profit)}? This is saved permanently to the distribution history.`)) return;
  const btn = document.querySelector('#equity-profit-body .eq-btn.primary');
  EQUITY_DIST_IN_FLIGHT = true;
  if (btn) { btn.disabled = true; btn.style.opacity = '.6'; btn.textContent = 'Recording…'; }
  try {
    const res = await api('equity_distribute_profit', { profit_amount: profit });
    if (!res.success) { eqToast(res.message || 'Could not record distribution', true); return; }
    document.getElementById('eq-profit-input').value = '';
    document.getElementById('eq-profit-preview').innerHTML = '';
    eqToast('✓ Distribution recorded');
    loadEquityDistributionHistory();
  } finally {
    EQUITY_DIST_IN_FLIGHT = false;
    if (btn) { btn.disabled = false; btn.style.opacity = ''; btn.textContent = '✅ Record This Distribution'; }
  }
}

async function loadEquityDistributionHistory() {
  const el = document.getElementById('eq-dist-history');
  if (!el) return;
  const res = await api('equity_list_distributions');
  if (!res.success) { el.innerHTML = `<div style="color:#ff9aa4">${eqEsc(res.message)}</div>`; return; }
  const rows = res.data.distributions;
  if (!rows.length) { el.innerHTML = '<div class="eq-empty"><span class="eq-emoji">📜</span>No distributions recorded yet.</div>'; return; }
  el.innerHTML = `<div style="overflow-x:auto"><table><thead><tr><th>Date</th><th>Profit</th><th>Investor Pool</th><th>Company</th><th>Charity</th><th>Personal</th></tr></thead><tbody>
    ${rows.map(r => `<tr><td>${eqEsc((r.created_at||'').slice(0,10))}</td><td class="mono hi">${eqFmt(r.profit_amount)}</td><td class="mono">${eqFmt(r.investor_pool)}</td><td class="mono">${eqFmt(r.company_amount)}</td><td class="mono">${eqFmt(r.charity_amount)}</td><td class="mono">${eqFmt(r.personal_amount)}</td></tr>`).join('')}
  </tbody></table></div>`;
}

// ── Hide this tab entirely for anyone without equity.view ──
(async function hideEquityNavIfNoCapability() {
  try {
    const res = await api('admin_get_my_capabilities');
    if (!res.success) return;
    const caps = res.data.capabilities || [];
    if (caps.includes('*') || caps.includes('equity.view')) return;
    const nav = document.getElementById('nav-item-equity');
    if (nav) nav.style.display = 'none';
  } catch (e) { /* fail open on network hiccup - server-side gating is the real boundary */ }
})();

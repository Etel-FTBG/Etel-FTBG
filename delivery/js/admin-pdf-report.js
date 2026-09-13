/* ============================================================
   ETEL Admin — Financial Snapshot PDF Report
   Generated client-side (jsPDF) when clicking "Use This Month's
   Revenue in Business Plan". Combines the exact growth scenario just
   computed with REAL fetched data - actual saved cost assumptions
   (Platform Economics), actual fundraising settings (Equity), and
   the actual saved hiring plan (Business Plan) - never a duplicated
   or guessed copy of any of it. Reuses bpPayrollCostFor() from
   admin-business-plan.js so the payroll math can never drift from
   what that tab itself shows.
   ============================================================ */

function pdfFmt(n) { return 'ETB ' + Math.round(Number(n) || 0).toLocaleString(); }

async function generateFinancialSnapshotPDF(scenario) {
  if (typeof window.jspdf === 'undefined') {
    alert('PDF library did not load - check your internet connection and try again.');
    return;
  }
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({ unit: 'pt', format: 'a4' });
  const pageWidth = doc.internal.pageSize.getWidth();
  const marginX = 48;
  let y = 56;

  function ensureRoom(needed) {
    if (y + needed > 780) { doc.addPage(); y = 56; }
  }
  function heading(text) {
    ensureRoom(30);
    doc.setFont('helvetica', 'bold'); doc.setFontSize(13); doc.setTextColor(20, 20, 20);
    doc.text(text, marginX, y);
    y += 8;
    doc.setDrawColor(200, 160, 40); doc.setLineWidth(1);
    doc.line(marginX, y, pageWidth - marginX, y);
    y += 18;
  }
  function row(label, value, opts) {
    ensureRoom(16);
    doc.setFont('helvetica', opts && opts.bold ? 'bold' : 'normal');
    doc.setFontSize(10); doc.setTextColor(60, 60, 60);
    doc.text(label, marginX, y);
    doc.setTextColor(20, 20, 20);
    doc.text(String(value), pageWidth - marginX, y, { align: 'right' });
    y += 15;
  }
  function note(text) {
    ensureRoom(28);
    doc.setFont('helvetica', 'italic'); doc.setFontSize(8.5); doc.setTextColor(120, 120, 120);
    const lines = doc.splitTextToSize(text, pageWidth - marginX * 2);
    doc.text(lines, marginX, y);
    y += lines.length * 11 + 8;
  }

  // ── Header ──
  doc.setFont('helvetica', 'bold'); doc.setFontSize(18); doc.setTextColor(20, 20, 20);
  doc.text('Etel Group — Financial Planning Snapshot', marginX, y);
  y += 18;
  doc.setFont('helvetica', 'normal'); doc.setFontSize(9.5); doc.setTextColor(120, 120, 120);
  doc.text('Generated ' + new Date().toLocaleString(), marginX, y);
  y += 10;
  doc.setDrawColor(180, 180, 180); doc.setLineWidth(0.5);
  doc.line(marginX, y, pageWidth - marginX, y);
  y += 24;
  note('ASSUMPTION-BASED — this entire report is a projection from the Growth & Goal Planner scenario below. It does not know your real order volume, driver activity, or churn. Compare it against real activity as it comes in.');

  // ── Section 1: Growth Scenario ──
  heading('1. Growth Assumption Used');
  const inp = scenario.inputs;
  row('Registered drivers', inp.drivers);
  row('Trips / driver / day', inp.tripsPerDriver);
  row('Avg distance / trip', inp.km + ' km');
  row('Base fare + per-km rate', pdfFmt(inp.base) + ' + ' + pdfFmt(inp.perKm) + '/km');
  row('Wait time / rate', inp.waitMin + ' min × ' + pdfFmt(inp.waitRate) + '/min');
  row('Peak/surge share × multiplier', inp.surgeSharePct + '% × ' + inp.surgeMult + '×');
  row('Blended average fare (all-in)', pdfFmt(inp.avgFare), { bold: true });
  y += 6;
  const monthRow = scenario.rows.find(r => r.label === 'Month');
  row('Monthly completed trips (projected)', Math.round(monthRow.trips).toLocaleString());
  row('Monthly gross fares (projected)', pdfFmt(monthRow.gross));
  row('Monthly 5% commission revenue (projected)', pdfFmt(monthRow.commission), { bold: true });

  // ── Section 2: Equity ──
  heading('2. Equity & Fundraising (Real Data)');
  let equitySettings = null, equityDashboard = null;
  try {
    const [settingsRes, dashRes] = await Promise.all([api('equity_get_settings'), api('equity_get_dashboard')]);
    if (settingsRes.success) equitySettings = settingsRes.data.settings;
    if (dashRes.success) equityDashboard = dashRes.data;
  } catch (e) {}
  if (equitySettings) {
    row('Fundraising target', pdfFmt(equitySettings.target_amount));
    row('Investor equity pool', Number(equitySettings.investor_equity_pct).toFixed(2) + '%');
    if (equityDashboard && equityDashboard.price_per_share !== undefined) {
      row('Price per share', pdfFmt(equityDashboard.price_per_share));
    }
    if (equityDashboard && equityDashboard.total_committed !== undefined) {
      row('Total raised so far', pdfFmt(equityDashboard.total_committed));
      row('Remaining to target', pdfFmt(equityDashboard.remaining_to_raise));
    }
    row('Monthly investor pool at this scenario', pdfFmt(monthRow.investorPool));
    row('Founder split — Company (30%) / Charity (10%) / Personal (40%)',
      pdfFmt(monthRow.company) + ' / ' + pdfFmt(monthRow.charity) + ' / ' + pdfFmt(monthRow.personal));
  } else {
    note('Could not load Equity settings — this section is incomplete.');
  }

  // ── Section 3: Platform Economics Costs ──
  heading('3. Platform Economics — Real Saved Costs');
  let platformCosts = null;
  try {
    const costsRes = await api('platform_economics_get_costs');
    if (costsRes.success) platformCosts = costsRes.data.costs;
  } catch (e) {}
  let monthlyFixedCosts = 0;
  if (platformCosts) {
    row('Payment processing', Number(platformCosts.payment_processing_pct).toFixed(2) + '% of gross fares');
    row('Customer acquisition / month', pdfFmt(platformCosts.monthly_customer_acquisition));
    row('Hosting / month', pdfFmt(platformCosts.monthly_hosting_cost));
    row('Support / month', pdfFmt(platformCosts.monthly_support_cost));
    row('Driver incentives / month', pdfFmt(platformCosts.monthly_incentive_budget));
    row('Refunds estimate / month', pdfFmt(platformCosts.monthly_refunds_estimate));
    row('Admin overhead / month', pdfFmt(platformCosts.monthly_admin_overhead));
    monthlyFixedCosts = Number(platformCosts.monthly_customer_acquisition) + Number(platformCosts.monthly_hosting_cost)
      + Number(platformCosts.monthly_support_cost) + Number(platformCosts.monthly_incentive_budget)
      + Number(platformCosts.monthly_refunds_estimate) + Number(platformCosts.monthly_admin_overhead);
    row('Total fixed operational costs / month', pdfFmt(monthlyFixedCosts), { bold: true });
  } else {
    note('Could not load Platform Economics costs — this section is incomplete.');
  }

  // ── Section 4: Hiring Plan ──
  heading('4. Business Plan — Real Saved Hiring Plan');
  let hires = [];
  try {
    const hiresRes = await api('planning_list_hires');
    if (hiresRes.success) hires = hiresRes.data.hires;
  } catch (e) {}
  let totalPayrollCost = 0;
  if (hires.length) {
    hires.forEach(h => {
      const costEach = typeof bpPayrollCostFor === 'function' ? bpPayrollCostFor(Number(h.monthly_salary)) : Number(h.monthly_salary);
      const costAll = costEach * Number(h.headcount);
      totalPayrollCost += costAll;
      row(h.role_name + ' × ' + h.headcount, pdfFmt(costAll));
    });
    row('Total monthly payroll cost (true cost, incl. pension)', pdfFmt(totalPayrollCost), { bold: true });
  } else {
    note('No hiring plan saved yet — payroll cost in the conclusion below is ETB 0. Add roles in Business Plan → Hiring Plan for an accurate figure.');
  }

  // ── Section 5: Conclusion ──
  heading('5. Conclusion — Does This Scenario Work?');
  const totalMonthlyCost = totalPayrollCost + monthlyFixedCosts;
  const netMonthly = monthRow.commission - totalMonthlyCost;
  row('Monthly commission revenue (this scenario)', pdfFmt(monthRow.commission));
  row('Monthly payroll + operational costs', pdfFmt(totalMonthlyCost));
  row('Net monthly position', (netMonthly >= 0 ? '+' : '') + pdfFmt(netMonthly), { bold: true });
  y += 6;
  if (netMonthly >= 0) {
    note('At this scenario, revenue covers your saved hiring plan and operational costs — this configuration is self-sustaining at this volume.');
  } else if (equitySettings) {
    const sixMonthGap = Math.abs(netMonthly) * 6;
    note('At this scenario, costs exceed revenue by ' + pdfFmt(Math.abs(netMonthly)) + '/month. Covering 6 months of that gap needs approximately '
      + pdfFmt(sixMonthGap) + '. Your current fundraising target is ' + pdfFmt(equitySettings.target_amount)
      + (Number(equitySettings.target_amount) >= sixMonthGap ? ' — which covers this.' : ' — which falls short by ' + pdfFmt(sixMonthGap - Number(equitySettings.target_amount)) + '.'));
  } else {
    note('At this scenario, costs exceed revenue by ' + pdfFmt(Math.abs(netMonthly)) + '/month.');
  }

  note('This report reflects assumptions and saved configuration at the moment it was generated. Regenerate it as real numbers come in from Platform Economics, or as your hiring plan and fundraising round change.');

  doc.save('etel-financial-snapshot-' + new Date().toISOString().slice(0, 10) + '.pdf');
}

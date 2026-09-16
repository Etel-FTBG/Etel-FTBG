/* ETEL Admin — One-click code audit (Settings tab)
   Calls admin_run_code_audit (see api/audit_agent.php), which scans this
   project's own files server-side and runs the same AI audit the manual
   "zip + upload to EtelGebeya/audit" tool runs - no download/upload loop.
   Renders a compact version of the same report shape those manual JSON
   uploads use (categories/bugs/recommendations), so it reads the same
   way whichever path produced it. */
(function(){
'use strict';

var lastReport = null;

function api(action, body, ok, fail){
  var token = typeof Auth !== 'undefined' ? Auth.getToken() : '';
  var apiUrl = (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php';
  var payload = Object.assign({action:action}, body||{});
  fetch(apiUrl, {method:'POST', headers:{'Content-Type':'application/json','Authorization':'Bearer '+token}, body:JSON.stringify(payload)})
    .then(function(r){ return r.json(); })
    .then(function(d){ if (d.success) ok(d.data); else if (fail) fail(d.message||'Error'); })
    .catch(function(e){ if (fail) fail(e.message); });
}

function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function toast(msg, type){
  if (typeof window.toast === 'function') { window.toast(msg, type); return; }
  console.log('[audit]', msg);
}

var CAT_LABELS = {
  security:'Security', code_quality:'Code Quality', frontend_design:'Frontend / Design',
  backend_architecture:'Backend Architecture', performance:'Performance',
  ethiopian_market:'Ethiopian Market Fit', premium_levelness:'Premium Levelness',
  deployment_readiness:'Deployment Readiness'
};

function gradeColor(g){
  if (!g) return '#999';
  var c = g[0];
  return c === 'S' ? '#D4AF37' : c === 'A' ? '#2ECC71' : c === 'B' ? '#3395FF' : c === 'C' ? '#E67E22' : '#E74C3C';
}
function sevColor(s){
  return s === 'critical' ? '#E74C3C' : s === 'high' ? '#E67E22' : s === 'medium' ? '#D4AF37' : '#3395FF';
}

function runCodeAudit(){
  var btn = document.getElementById('audit-run-btn');
  var dlBtn = document.getElementById('audit-download-btn');
  var wrap = document.getElementById('audit-report');
  var provider = (document.getElementById('audit-provider')||{}).value || 'gemini';
  if (!wrap) return;

  btn.disabled = true;
  btn.textContent = '⏳ Auditing… (20-60s)';
  dlBtn.style.display = 'none';
  wrap.innerHTML = '<div style="padding:24px;color:#B8B0A0;text-align:center">Scanning project files and running the audit — this can take up to a minute…</div>';

  api('admin_run_code_audit', { provider: provider }, function(report){
    lastReport = report;
    btn.disabled = false;
    btn.textContent = '▶️ Run Audit Now';
    dlBtn.style.display = 'inline-block';
    renderReport(report);
    toast('Audit complete — score ' + (report.overall_score != null ? report.overall_score : '?'), 'ok');
  }, function(msg){
    btn.disabled = false;
    btn.textContent = '▶️ Run Audit Now';
    wrap.innerHTML = '<div style="padding:16px;color:#E63946;background:rgba(230,57,70,.1);border:1px solid rgba(230,57,70,.3);border-radius:8px">' + esc(msg) + '</div>';
    toast('Audit failed: ' + msg, 'err');
  });
}

function toggleAuditSettings(){
  var box = document.getElementById('audit-settings-box');
  if (!box) return;
  var showing = box.style.display !== 'none';
  if (showing) { box.style.display = 'none'; return; }
  box.style.display = 'block';
  document.getElementById('audit-set-gemini-key').value = 'Loading…';
  api('admin_get_audit_settings', {}, function(cfg){
    document.getElementById('audit-set-gemini-key').value = cfg.gemini_key || '';
    document.getElementById('audit-set-openrouter-key').value = cfg.openrouter_key || '';
    document.getElementById('audit-set-openrouter-model').value = cfg.openrouter_model || 'anthropic/claude-3.5-sonnet';
    document.getElementById('audit-set-provider').value = cfg.default_provider || 'gemini';
  }, function(msg){
    document.getElementById('audit-set-gemini-key').value = '';
    toast('Could not load audit settings: ' + msg, 'err');
  });
}
function saveAuditSettings(){
  var geminiKey = (document.getElementById('audit-set-gemini-key').value || '').trim();
  var openrouterKey = (document.getElementById('audit-set-openrouter-key').value || '').trim();
  var openrouterModel = (document.getElementById('audit-set-openrouter-model').value || '').trim();
  var provider = document.getElementById('audit-set-provider').value;
  if (provider === 'gemini' && !geminiKey) { toast('Gemini API key is required when Gemini is the default provider', 'err'); return; }
  if (provider === 'openrouter' && !openrouterKey) { toast('OpenRouter API key is required when OpenRouter is the default provider', 'err'); return; }
  api('admin_save_audit_settings', {
    gemini_key: geminiKey, openrouter_key: openrouterKey,
    openrouter_model: openrouterModel, default_provider: provider
  }, function(){
    toast('Audit settings saved', 'ok');
  }, function(msg){
    toast('Save failed: ' + msg, 'err');
  });
}

function renderReport(r){
  var wrap = document.getElementById('audit-report');
  if (!wrap) return;

  var cats = r.categories || {};
  var catCards = Object.keys(CAT_LABELS).map(function(key){
    var c = cats[key];
    if (!c) return '';
    var issues = (c.issues || []).map(function(i){
      return '<div style="margin-top:6px;padding:8px 10px;background:rgba(255,255,255,.05);border-left:3px solid ' + sevColor(i.severity) + ';border-radius:4px">' +
        '<div style="font-size:11px;font-weight:700;text-transform:uppercase;color:' + sevColor(i.severity) + '">' + esc(i.severity) + ' — ' + esc(i.title) + '</div>' +
        '<div style="font-size:12px;color:#e8e2d4;margin-top:2px">' + esc(i.detail) + '</div></div>';
    }).join('');
    return '<div style="border:1px solid rgba(212,175,55,.15);border-radius:10px;padding:14px;margin-bottom:10px">' +
      '<div style="display:flex;justify-content:space-between;align-items:center">' +
        '<div style="font-weight:700;font-size:13px;color:#e8e2d4">' + esc(CAT_LABELS[key]) + '</div>' +
        '<div style="font-weight:800;font-size:13px;color:' + gradeColor(c.grade) + '">' + esc(c.grade) + ' (' + esc(c.score) + ')</div>' +
      '</div>' + (issues || '<div style="font-size:12px;color:#B8B0A0;margin-top:6px">No issues flagged</div>') +
    '</div>';
  }).join('');

  var bugs = (r.bugs || []).map(function(b){
    return '<div style="border:1px solid rgba(212,175,55,.15);border-radius:10px;padding:14px;margin-bottom:10px">' +
      '<div style="font-size:11px;font-weight:700;text-transform:uppercase;color:' + sevColor(b.severity) + '">' + esc(b.severity) + '</div>' +
      '<div style="font-weight:700;font-size:13px;margin:4px 0;color:#e8e2d4">' + esc(b.title) + '</div>' +
      '<div style="font-size:12px;color:#B8B0A0;margin-bottom:6px">' + esc(b.file) + (b.line ? ':' + esc(b.line) : '') + '</div>' +
      '<div style="font-size:12px;color:#e8e2d4;margin-bottom:6px">' + esc(b.description) + '</div>' +
      (b.fix ? '<div style="font-size:12px;color:#2ECC71"><strong>Fix:</strong> ' + esc(b.fix) + '</div>' : '') +
    '</div>';
  }).join('') || '<div style="font-size:13px;color:#B8B0A0">No bugs found</div>';

  var recs = (r.recommendations || []).map(function(rec){
    return '<div style="border:1px solid rgba(212,175,55,.15);border-radius:10px;padding:14px;margin-bottom:10px">' +
      '<div style="display:flex;gap:8px;align-items:center;margin-bottom:4px">' +
        '<span style="font-size:10px;font-weight:700;text-transform:uppercase;padding:2px 8px;border-radius:100px;background:rgba(255,255,255,.1);color:#e8e2d4">' + esc(rec.priority) + '</span>' +
        '<span style="font-weight:700;font-size:13px;color:#e8e2d4">' + esc(rec.title) + '</span>' +
      '</div>' +
      '<div style="font-size:12px;color:#e8e2d4">' + esc(rec.description) + '</div>' +
      '<div style="font-size:11px;color:#B8B0A0;margin-top:4px">Effort: ' + esc(rec.effort) + '</div>' +
    '</div>';
  }).join('') || '<div style="font-size:13px;color:#B8B0A0">No recommendations</div>';

  wrap.innerHTML =
    '<div style="border:1px solid rgba(212,175,55,.25);background:rgba(255,255,255,.04);border-radius:12px;padding:20px;margin-bottom:20px">' +
      '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">' +
        '<div>' +
          '<div style="font-size:11px;color:#B8B0A0;text-transform:uppercase;letter-spacing:.08em">Overall Score</div>' +
          '<div style="font-size:32px;font-weight:800;color:' + gradeColor(r.overall_grade) + '">' + esc(r.overall_score) + ' <span style="font-size:18px">(' + esc(r.overall_grade) + ')</span></div>' +
        '</div>' +
        '<div style="font-size:11px;color:#B8B0A0">Audited ' + esc(r._audited_at || '') + '</div>' +
      '</div>' +
      '<p style="font-size:13px;color:#e8e2d4;margin-top:10px">' + esc(r.executive_summary) + '</p>' +
    '</div>' +
    '<div class="section-title" style="margin-bottom:10px">Categories</div>' + catCards +
    '<div class="section-title" style="margin:20px 0 10px">Bugs (' + (r.bugs||[]).length + ')</div>' + bugs +
    '<div class="section-title" style="margin:20px 0 10px">Recommendations (' + (r.recommendations||[]).length + ')</div>' + recs +
    /* Footer spacer - without this, Recommendations (the last section) has
       nowhere to scroll to once a phone browser's own bottom bar/toolbar
       overlaps it. Same fix as the Updater tab's "Deploy Multiple Files"
       scroll issue. */
    '<div style="height:calc(140px + env(safe-area-inset-bottom, 0px))"></div>';
}

function downloadAuditReport(){
  if (!lastReport) return;
  var blob = new Blob([JSON.stringify(lastReport, null, 2)], {type:'application/json'});
  var url = URL.createObjectURL(blob);
  var a = document.createElement('a');
  a.href = url;
  a.download = 'etel-audit-delivery-' + new Date().toISOString().slice(0,10) + '.json';
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

window.runCodeAudit = runCodeAudit;
window.downloadAuditReport = downloadAuditReport;
window.toggleAuditSettings = toggleAuditSettings;
window.saveAuditSettings = saveAuditSettings;
})();

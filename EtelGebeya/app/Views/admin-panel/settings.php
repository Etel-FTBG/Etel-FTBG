<?php
use App\Core\View;
require __DIR__.'/_layout.php';
$pageTitle = ucfirst('settings');
ob_start();
?>
<style>
.ag-bar-title{font-size:18px;font-weight:800;color:var(--dark-text);margin-bottom:16px}
.ag-card{background:var(--dark-card);border:1px solid var(--dark-border);border-radius:var(--r-md);overflow:hidden}
.ag-tbl{width:100%;border-collapse:collapse}
.ag-tbl th{background:rgba(255,255,255,.03);padding:10px 14px;text-align:left;font-size:10px;font-weight:700;color:var(--dark-muted);text-transform:uppercase;letter-spacing:.8px;border-bottom:1px solid var(--dark-border);white-space:nowrap}
.ag-tbl td{padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);font-size:12.5px;color:var(--dark-muted);vertical-align:middle}
.ag-tbl tr:last-child td{border-bottom:none}
.ag-tbl tr:hover td{background:rgba(255,255,255,.02)}
.flash-success{background:rgba(46,204,113,.12);border:1px solid rgba(46,204,113,.25);color:var(--green);padding:11px 15px;border-radius:var(--r-sm);margin-bottom:16px;font-size:13px}
.flash-error{background:rgba(231,76,60,.12);border:1px solid rgba(231,76,60,.25);color:var(--red);padding:11px 15px;border-radius:var(--r-sm);margin-bottom:16px;font-size:13px}
.badge{display:inline-block;padding:3px 8px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;white-space:nowrap}
.badge-paid,.badge-active,.badge-admin{background:rgba(46,204,113,.15);color:var(--green);border:1px solid rgba(46,204,113,.25)}
.badge-unpaid,.badge-customer{background:rgba(212,175,55,.12);color:var(--gold);border:1px solid rgba(212,175,55,.2)}
.badge-pending{background:rgba(96,180,255,.15);color:var(--blue);border:1px solid rgba(96,180,255,.25)}
.badge-vendor{background:rgba(155,89,182,.15);color:var(--purple);border:1px solid rgba(155,89,182,.25)}
.badge-courier{background:rgba(78,205,196,.15);color:var(--teal);border:1px solid rgba(78,205,196,.25)}
.ag-inp{background:rgba(255,255,255,.05);border:1px solid var(--dark-border);color:var(--dark-text);font-family:inherit;font-size:12.5px;padding:8px 12px;border-radius:var(--r-sm);outline:none;width:100%;max-width:340px}
.ag-inp:focus{border-color:var(--gold)}
.ag-sel{background:rgba(255,255,255,.05);border:1px solid var(--dark-border);color:var(--dark-text);font-family:inherit;font-size:12.5px;padding:8px 12px;border-radius:var(--r-sm);cursor:pointer;outline:none}
.ag-sel option{background:#0A0A1A}
.btn-xs{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:6px;font-size:11.5px;font-weight:700;cursor:pointer;border:none;transition:opacity .12s;text-decoration:none}
.btn-gold-xs{background:linear-gradient(135deg,var(--gold-br),var(--gold));color:#1A1A2E}
.btn-ghost-xs{background:rgba(212,175,55,.1);color:var(--gold);border:1px solid rgba(212,175,55,.2)}
.btn-xs:hover{opacity:.8}
.ag-pagination{display:flex;gap:5px;align-items:center;justify-content:center;padding:14px;border-top:1px solid var(--dark-border)}
.pg-link{padding:5px 11px;border-radius:6px;border:1px solid var(--dark-border);color:var(--dark-muted);font-size:12px;text-decoration:none}
.pg-link:hover,.pg-link.cur{background:rgba(212,175,55,.12);color:var(--gold);border-color:rgba(212,175,55,.3)}
.empty-s{text-align:center;padding:48px 20px;color:var(--dark-muted);font-size:13px}
</style>

<?php if (!empty($flash)): ?>
  <div class="flash-<?= e($flash['type'] ?? 'success') ?>"><?= e($flash['msg'] ?? '') ?></div>
<?php endif; ?>

<div class="ag-bar-title"><?= ucfirst('settings') ?></div>


<div class="ag-card" style="padding:24px;max-width:640px">
  <div style="font-size:14px;font-weight:700;color:var(--dark-text);margin-bottom:18px">Platform Settings</div>
  <form method="POST" action="<?= View::url('/admin/settings') ?>">
    <?= csrf() ?>
    <div style="display:grid;gap:14px">
      <div>
        <label style="font-size:11px;color:var(--dark-muted);display:block;margin-bottom:5px;font-weight:700">App Name</label>
        <input type="text" name="app_name" class="ag-inp" value="<?= e($cfg['app']['name'] ?? 'Etel Gebeya') ?>" style="max-width:none;width:100%">
      </div>
      <div>
        <label style="font-size:11px;color:var(--dark-muted);display:block;margin-bottom:5px;font-weight:700">App URL</label>
        <input type="text" name="app_url" class="ag-inp" value="<?= e($cfg['app']['url'] ?? '') ?>" style="max-width:none;width:100%">
      </div>
      <div>
        <label style="font-size:11px;color:var(--dark-muted);display:block;margin-bottom:5px;font-weight:700">Debug Mode</label>
        <select name="app_debug" class="ag-sel">
          <option value="0" <?= empty($cfg['app']['debug'])?'selected':'' ?>>Off (production)</option>
          <option value="1" <?= !empty($cfg['app']['debug'])?'selected':'' ?>>On (development)</option>
        </select>
      </div>
      <div style="padding-top:8px;border-top:1px solid var(--dark-border)">
        <p style="font-size:11.5px;color:var(--dark-muted);margin-bottom:12px">
          Settings are stored in <code style="background:rgba(255,255,255,.06);padding:2px 6px;border-radius:4px;font-size:10.5px">config/app.php</code>.
          File-write access may be limited on shared hosting -- changes applied here take effect on next deploy.
        </p>
        <button type="submit" class="btn-xs btn-gold-xs" style="padding:8px 20px;font-size:13px">Save Settings</button>
      </div>
    </div>
  </form>
</div>

<div class="ag-card" style="padding:24px;max-width:820px;margin-top:20px">
  <div style="font-size:14px;font-weight:700;color:var(--dark-text);margin-bottom:8px">🔍 Code Audit</div>
  <p style="font-size:11.5px;color:var(--dark-muted);margin-bottom:14px;max-width:600px">
    Scans this project's own files on the server and runs the same AI audit as the manual
    "zip it, upload it to EtelGebeya/audit" tool - no download/upload needed. Takes 20-60 seconds.
  </p>
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px">
    <select id="ag-audit-provider" class="ag-sel">
      <option value="gemini">Gemini</option>
      <option value="openrouter">OpenRouter</option>
    </select>
    <button type="button" class="btn-xs btn-gold-xs" id="ag-audit-run" style="padding:8px 20px;font-size:13px" onclick="agRunAudit()">▶️ Run Audit Now</button>
    <button type="button" class="btn-xs btn-ghost-xs" id="ag-audit-download" style="padding:8px 20px;font-size:13px;display:none" onclick="agDownloadAudit()">📥 Download JSON</button>
    <button type="button" class="btn-xs btn-ghost-xs" style="padding:8px 20px;font-size:13px" onclick="agToggleAuditSettings()">⚙️ API Settings</button>
  </div>
  <div id="ag-audit-settings-box" style="display:none;margin-bottom:16px;padding:14px;background:rgba(255,255,255,.03);border:1px solid var(--dark-border);border-radius:8px">
    <div style="font-size:11.5px;color:var(--dark-muted);margin-bottom:10px">"Run Audit Now" calls Gemini or OpenRouter directly with the key below - no shared proxy, no code deploy needed to rotate a key.</div>
    <div style="display:flex;flex-direction:column;gap:8px;max-width:520px">
      <div>
        <label style="font-size:11px;color:var(--dark-muted);display:block;margin-bottom:3px">Gemini API Key</label>
        <input type="text" id="ag-audit-set-gemini-key" class="ag-inp" placeholder="AIza..." style="max-width:none">
      </div>
      <div>
        <label style="font-size:11px;color:var(--dark-muted);display:block;margin-bottom:3px">OpenRouter API Key</label>
        <input type="text" id="ag-audit-set-openrouter-key" class="ag-inp" placeholder="sk-or-..." style="max-width:none">
      </div>
      <div>
        <label style="font-size:11px;color:var(--dark-muted);display:block;margin-bottom:3px">OpenRouter Model</label>
        <input type="text" id="ag-audit-set-openrouter-model" class="ag-inp" placeholder="anthropic/claude-3.5-sonnet" style="max-width:none">
      </div>
      <div>
        <label style="font-size:11px;color:var(--dark-muted);display:block;margin-bottom:3px">Default Provider</label>
        <select id="ag-audit-set-provider" class="ag-sel" style="width:180px">
          <option value="gemini">Gemini</option>
          <option value="openrouter">OpenRouter</option>
        </select>
      </div>
      <div style="margin-top:6px">
        <button type="button" class="btn-xs btn-gold-xs" style="padding:7px 14px" onclick="agSaveAuditSettings()">💾 Save Settings</button>
      </div>
    </div>
  </div>
  <div id="ag-audit-report"></div>
</div>

<script>
(function(){
  var lastReport = null;
  var CAT_LABELS = {
    security:'Security', code_quality:'Code Quality', frontend_design:'Frontend / Design',
    backend_architecture:'Backend Architecture', performance:'Performance',
    ethiopian_market:'Ethiopian Market Fit', premium_levelness:'Premium Levelness',
    deployment_readiness:'Deployment Readiness'
  };
  function esc(s){ var d=document.createElement('div'); d.textContent=String(s==null?'':s); return d.innerHTML; }
  function gradeColor(g){
    if (!g) return '#999';
    var c = g[0];
    return c==='S' ? 'var(--gold)' : c==='A' ? 'var(--green)' : c==='B' ? 'var(--blue)' : c==='C' ? '#E67E22' : 'var(--red)';
  }
  function sevColor(s){ return s==='critical'?'var(--red)':s==='high'?'#E67E22':s==='medium'?'var(--gold)':'var(--blue)'; }

  window.agRunAudit = function(){
    var btn = document.getElementById('ag-audit-run');
    var dl  = document.getElementById('ag-audit-download');
    var wrap = document.getElementById('ag-audit-report');
    var provider = document.getElementById('ag-audit-provider').value;
    btn.disabled = true; btn.textContent = '⏳ Auditing… (20-60s)';
    dl.style.display = 'none';
    wrap.innerHTML = '<div style="padding:20px;color:var(--dark-muted);text-align:center;font-size:12.5px">Scanning project files and running the audit - this can take up to a minute…</div>';

    fetch('<?= View::url('/admin/audit/run') ?>', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: 'provider=' + encodeURIComponent(provider)
    }).then(function(r){ return r.json(); }).then(function(d){
      btn.disabled = false; btn.textContent = '▶️ Run Audit Now';
      if (!d.ok) { wrap.innerHTML = '<div style="padding:14px;color:var(--red);background:rgba(231,76,60,.1);border-radius:8px;font-size:12.5px">' + esc(d.message||'Audit failed') + '</div>'; return; }
      lastReport = d.report;
      dl.style.display = 'inline-flex';
      renderReport(d.report);
    }).catch(function(e){
      btn.disabled = false; btn.textContent = '▶️ Run Audit Now';
      wrap.innerHTML = '<div style="padding:14px;color:var(--red);background:rgba(231,76,60,.1);border-radius:8px;font-size:12.5px">' + esc(e.message) + '</div>';
    });
  };

  window.agToggleAuditSettings = function(){
    var box = document.getElementById('ag-audit-settings-box');
    if (!box) return;
    var showing = box.style.display !== 'none';
    if (showing) { box.style.display = 'none'; return; }
    box.style.display = 'block';
    document.getElementById('ag-audit-set-gemini-key').value = 'Loading…';
    fetch('<?= View::url('/admin/audit/settings') ?>').then(function(r){ return r.json(); }).then(function(d){
      if (!d.ok) { document.getElementById('ag-audit-set-gemini-key').value = ''; return; }
      document.getElementById('ag-audit-set-gemini-key').value = d.gemini_key || '';
      document.getElementById('ag-audit-set-openrouter-key').value = d.openrouter_key || '';
      document.getElementById('ag-audit-set-openrouter-model').value = d.openrouter_model || 'anthropic/claude-3.5-sonnet';
      document.getElementById('ag-audit-set-provider').value = d.default_provider || 'gemini';
    }).catch(function(){
      document.getElementById('ag-audit-set-gemini-key').value = '';
    });
  };

  window.agSaveAuditSettings = function(){
    var geminiKey = (document.getElementById('ag-audit-set-gemini-key').value || '').trim();
    var openrouterKey = (document.getElementById('ag-audit-set-openrouter-key').value || '').trim();
    var openrouterModel = (document.getElementById('ag-audit-set-openrouter-model').value || '').trim();
    var provider = document.getElementById('ag-audit-set-provider').value;
    if (provider === 'gemini' && !geminiKey) { alert('Gemini API key is required when Gemini is the default provider'); return; }
    if (provider === 'openrouter' && !openrouterKey) { alert('OpenRouter API key is required when OpenRouter is the default provider'); return; }
    fetch('<?= View::url('/admin/audit/settings') ?>', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: 'gemini_key=' + encodeURIComponent(geminiKey) +
            '&openrouter_key=' + encodeURIComponent(openrouterKey) +
            '&openrouter_model=' + encodeURIComponent(openrouterModel) +
            '&default_provider=' + encodeURIComponent(provider)
    }).then(function(r){ return r.json(); }).then(function(d){
      alert(d.ok ? 'Audit settings saved' : ('Save failed: ' + (d.message||'')));
    }).catch(function(e){ alert('Save failed: ' + e.message); });
  };

  function renderReport(r){
    var wrap = document.getElementById('ag-audit-report');
    var cats = r.categories || {};
    var catCards = Object.keys(CAT_LABELS).map(function(key){
      var c = cats[key]; if (!c) return '';
      var issues = (c.issues||[]).map(function(i){
        return '<div style="margin-top:6px;padding:8px 10px;background:rgba(255,255,255,.03);border-left:3px solid '+sevColor(i.severity)+';border-radius:4px">' +
          '<div style="font-size:10.5px;font-weight:700;text-transform:uppercase;color:'+sevColor(i.severity)+'">'+esc(i.severity)+' - '+esc(i.title)+'</div>' +
          '<div style="font-size:11.5px;color:var(--dark-muted);margin-top:2px">'+esc(i.detail)+'</div></div>';
      }).join('');
      return '<div style="border:1px solid var(--dark-border);border-radius:8px;padding:12px;margin-bottom:8px">' +
        '<div style="display:flex;justify-content:space-between;align-items:center">' +
          '<div style="font-weight:700;font-size:12.5px;color:var(--dark-text)">'+esc(CAT_LABELS[key])+'</div>' +
          '<div style="font-weight:800;font-size:12.5px;color:'+gradeColor(c.grade)+'">'+esc(c.grade)+' ('+esc(c.score)+')</div>' +
        '</div>' + (issues || '<div style="font-size:11.5px;color:var(--dark-muted);margin-top:4px">No issues flagged</div>') + '</div>';
    }).join('');

    var bugs = (r.bugs||[]).map(function(b){
      return '<div style="border:1px solid var(--dark-border);border-radius:8px;padding:12px;margin-bottom:8px">' +
        '<div style="font-size:10.5px;font-weight:700;text-transform:uppercase;color:'+sevColor(b.severity)+'">'+esc(b.severity)+'</div>' +
        '<div style="font-weight:700;font-size:12.5px;margin:4px 0;color:var(--dark-text)">'+esc(b.title)+'</div>' +
        '<div style="font-size:11px;color:var(--dark-muted);margin-bottom:4px">'+esc(b.file)+(b.line?':'+esc(b.line):'')+'</div>' +
        '<div style="font-size:11.5px;color:var(--dark-muted);margin-bottom:4px">'+esc(b.description)+'</div>' +
        (b.fix ? '<div style="font-size:11.5px;color:var(--green)"><strong>Fix:</strong> '+esc(b.fix)+'</div>' : '') + '</div>';
    }).join('') || '<div style="font-size:12px;color:var(--dark-muted)">No bugs found</div>';

    var recs = (r.recommendations||[]).map(function(rec){
      return '<div style="border:1px solid var(--dark-border);border-radius:8px;padding:12px;margin-bottom:8px">' +
        '<div style="display:flex;gap:8px;align-items:center;margin-bottom:4px">' +
          '<span style="font-size:9.5px;font-weight:700;text-transform:uppercase;padding:2px 8px;border-radius:100px;background:rgba(255,255,255,.06);color:var(--dark-muted)">'+esc(rec.priority)+'</span>' +
          '<span style="font-weight:700;font-size:12.5px;color:var(--dark-text)">'+esc(rec.title)+'</span></div>' +
        '<div style="font-size:11.5px;color:var(--dark-muted)">'+esc(rec.description)+'</div>' +
        '<div style="font-size:10.5px;color:var(--dark-muted);opacity:.7;margin-top:4px">Effort: '+esc(rec.effort)+'</div></div>';
    }).join('') || '<div style="font-size:12px;color:var(--dark-muted)">No recommendations</div>';

    wrap.innerHTML =
      '<div style="border:1px solid var(--dark-border);background:rgba(212,175,55,.05);border-radius:10px;padding:18px;margin-bottom:18px">' +
        '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">' +
          '<div><div style="font-size:10px;color:var(--dark-muted);text-transform:uppercase;letter-spacing:.08em">Overall Score</div>' +
          '<div style="font-size:28px;font-weight:800;color:'+gradeColor(r.overall_grade)+'">'+esc(r.overall_score)+' <span style="font-size:16px">('+esc(r.overall_grade)+')</span></div></div>' +
          '<div style="font-size:10.5px;color:var(--dark-muted)">Audited '+esc(r._audited_at||'')+'</div>' +
        '</div><p style="font-size:12px;color:var(--dark-muted);margin-top:8px">'+esc(r.executive_summary)+'</p></div>' +
      '<div style="font-size:12px;font-weight:700;color:var(--dark-text);margin-bottom:8px">Categories</div>' + catCards +
      '<div style="font-size:12px;font-weight:700;color:var(--dark-text);margin:16px 0 8px">Bugs ('+(r.bugs||[]).length+')</div>' + bugs +
      '<div style="font-size:12px;font-weight:700;color:var(--dark-text);margin:16px 0 8px">Recommendations ('+(r.recommendations||[]).length+')</div>' + recs;
  }

  window.agDownloadAudit = function(){
    if (!lastReport) return;
    var blob = new Blob([JSON.stringify(lastReport, null, 2)], {type:'application/json'});
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url; a.download = 'etel-audit-gebeya-' + new Date().toISOString().slice(0,10) + '.json';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    URL.revokeObjectURL(url);
  };
})();
</script>

<?php
$__content = ob_get_clean();
echo View::capture('_layouts/dark', array_merge(get_defined_vars(), [
    '__content' => $__content,
    'navItems'  => $navItems,
    'role'      => 'admin',
    'pageTitle' => $pageTitle,
]));

<?php
/**
 * Etel-ID Premium — Admin Updater Page v1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

$logo_src  = defined('ETELID_LOGO_B64') ? ETELID_LOGO_B64 : ETELID_URL . 'public/images/etel-logo.png';
$patch_log = EtelID_Updater::get_log();
$nonce     = wp_create_nonce( 'etelid_patch_upload' );
?>
<div class="eid-admin-page">
  <div class="eid-topnav">
    <div class="eid-nav-brand">
      <img src="<?php echo esc_attr($logo_src); ?>" alt="" class="eid-nav-logo-img">
      <div class="eid-nav-title">
        <span class="eid-nav-title-main">Etel-ID Premium</span>
        <span class="eid-nav-title-sub">ኢትኤል ቡድን · Plugin Updater</span>
      </div>
    </div>
    <nav class="eid-nav-links">
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id')); ?>"             class="eid-nav-link">Members</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-issue')); ?>"       class="eid-nav-link">Issue ID</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-settings')); ?>"    class="eid-nav-link">Settings</a>
      <a href="<?php echo esc_url(admin_url('admin.php?page=etel-id-updater')); ?>"     class="eid-nav-link active">🔄 Updater</a>
    </nav>
  </div>

  <div class="eid-content">
    <div class="eid-page-header">
      <div>
        <div class="eid-page-title">🔄 Plugin Updater</div>
        <div class="eid-page-subtitle">Apply patch updates without going through File Manager — just upload and done.</div>
      </div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--g5);background:rgba(212,175,55,.1);border:1px solid rgba(212,175,55,.25);padding:8px 16px;border-radius:8px;">
        Current Version: <strong style="color:var(--ink);"><?php echo ETELID_VERSION; ?></strong>
      </div>
    </div>

    <!-- Upload zone -->
    <div class="eid-panel" style="margin-bottom:24px;">
      <div class="eid-panel-header">
        <h3>📦 Upload Patch</h3>
      </div>
      <div class="eid-panel-body">

        <!-- Drop zone -->
        <div id="patch-drop-zone"
          style="border:2px dashed rgba(212,175,55,.4);border-radius:14px;padding:40px 20px;text-align:center;cursor:pointer;transition:all .2s;background:rgba(212,175,55,.04);margin-bottom:20px;"
          onclick="document.getElementById('patch-file-input').click()"
          ondragover="event.preventDefault();this.style.borderColor='var(--g4)';this.style.background='rgba(212,175,55,.1)';"
          ondragleave="this.style.borderColor='rgba(212,175,55,.4)';this.style.background='rgba(212,175,55,.04)';"
          ondrop="handleDrop(event)">
          <div style="font-size:48px;margin-bottom:12px;">📦</div>
          <div style="font-family:'Syne',sans-serif;font-size:16px;font-weight:800;color:var(--ink);margin-bottom:6px;">Drop patch ZIP here or click to select</div>
          <div style="font-size:13px;color:var(--ink3);">Only <code style="background:rgba(212,175,55,.12);padding:2px 6px;border-radius:4px;">.zip</code> patch files from Claude are accepted</div>
          <div id="patch-file-name" style="margin-top:14px;font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--g5);display:none;"></div>
        </div>

        <input type="file" id="patch-file-input" accept=".zip" style="display:none;" onchange="handleFileSelect(this)">

        <!-- Patch info preview (shown after file selected) -->
        <div id="patch-preview" style="display:none;background:rgba(212,175,55,.07);border:1.5px solid rgba(212,175,55,.3);border-radius:12px;padding:16px 20px;margin-bottom:16px;">
          <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:800;color:var(--ink);margin-bottom:10px;">📋 Patch Contents</div>
          <div id="patch-preview-content"></div>
        </div>

        <!-- Progress bar -->
        <div id="patch-progress-wrap" style="display:none;margin-bottom:16px;">
          <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
            <span style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--ink2);" id="patch-progress-label">Applying patch…</span>
            <span style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--g5);" id="patch-progress-pct">0%</span>
          </div>
          <div style="height:6px;background:rgba(212,175,55,.15);border-radius:3px;overflow:hidden;">
            <div id="patch-progress-bar" style="height:100%;width:0%;background:linear-gradient(90deg,var(--g5),var(--g3));border-radius:3px;transition:width .4s ease;"></div>
          </div>
        </div>

        <!-- Result message -->
        <div id="patch-result" style="display:none;border-radius:10px;padding:14px 18px;margin-bottom:16px;"></div>

        <!-- Applied files list -->
        <div id="patch-files-list" style="display:none;margin-bottom:16px;">
          <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--g5);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:8px;">Files Updated</div>
          <div id="patch-files-content" style="display:flex;flex-direction:column;gap:4px;"></div>
        </div>

        <!-- Apply button -->
        <div style="display:flex;gap:12px;align-items:center;">
          <button id="patch-apply-btn" class="eid-btn eid-btn-gold" style="padding:12px 28px;font-size:15px;" onclick="applyPatch()" disabled>
            ⚡ Apply Patch
          </button>
          <button class="eid-btn eid-btn-outline" onclick="clearPatch()">✕ Clear</button>
          <div style="margin-left:auto;font-size:12px;color:var(--ink3);">
            Backup copies of replaced files are saved automatically.
          </div>
        </div>

      </div>
    </div>

    <!-- Patch History -->
    <div class="eid-panel">
      <div class="eid-panel-header">
        <h3>📜 Patch History</h3>
        <?php if ($patch_log): ?>
        <button class="eid-btn eid-btn-outline eid-btn-sm" onclick="if(confirm('Clear patch history?')) location.href='?page=etel-id-updater&clear_log=1&_wpnonce=<?php echo wp_create_nonce('etelid_clear_log'); ?>'">
          Clear History
        </button>
        <?php endif; ?>
      </div>
      <div class="eid-panel-body" style="padding:0;">

        <?php if ( empty($patch_log) ) : ?>
        <div style="padding:40px;text-align:center;color:var(--ink3);">
          <div style="font-size:32px;margin-bottom:10px;">📭</div>
          <div>No patches applied yet. When you receive a patch from Claude, upload it here.</div>
        </div>

        <?php else : ?>
        <?php foreach ($patch_log as $i => $entry) : ?>
        <div style="padding:16px 20px;border-bottom:1px solid rgba(212,175,55,.1);<?php echo $i===0?'background:rgba(34,197,94,.04);':'' ?>">
          <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:8px;">
            <span style="font-family:'Syne',sans-serif;font-size:13px;font-weight:800;color:var(--ink);">
              <?php echo $i === 0 ? '✅ ' : ''; ?>v<?php echo esc_html((isset($entry['new_version']) ? $entry['new_version'] : '—')); ?>
            </span>
            <span style="font-family:'JetBrains Mono',monospace;font-size:10px;background:rgba(212,175,55,.12);color:var(--g6);padding:2px 10px;border-radius:4px;">
              Patch <?php echo esc_html((isset($entry['patch_version']) ? $entry['patch_version'] : '—')); ?>
            </span>
            <?php if (!empty($entry['description'])): ?>
            <span style="font-size:12px;color:var(--ink3);"><?php echo esc_html($entry['description']); ?></span>
            <?php endif; ?>
            <span style="margin-left:auto;font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--g5);">
              <?php echo esc_html((isset($entry['applied_at']) ? $entry['applied_at'] : '—')); ?> by <?php echo esc_html((isset($entry['applied_by']) ? $entry['applied_by'] : '—')); ?>
            </span>
          </div>
          <div style="display:flex;flex-wrap:wrap;gap:5px;">
            <?php foreach ((array)(isset($entry['files_applied']) ? $entry['files_applied'] : array()) as $f): ?>
            <span style="font-family:'JetBrains Mono',monospace;font-size:9px;background:rgba(34,197,94,.1);color:#166534;border:1px solid rgba(34,197,94,.2);padding:2px 8px;border-radius:4px;">
              ✓ <?php echo esc_html($f); ?>
            </span>
            <?php endforeach; ?>
            <?php foreach ((array)(isset($entry['files_failed'])  ? $entry['files_failed']  : array()) as $f): ?>
            <span style="font-family:'JetBrains Mono',monospace;font-size:9px;background:rgba(239,68,68,.1);color:#991b1b;border:1px solid rgba(239,68,68,.2);padding:2px 8px;border-radius:4px;">
              ✗ <?php echo esc_html($f); ?>
            </span>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

      </div>
    </div>

    <!-- How it works info box -->
    <div style="background:rgba(212,175,55,.06);border:1px solid rgba(212,175,55,.2);border-radius:12px;padding:20px 24px;margin-top:20px;">
      <div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:800;color:var(--ink);margin-bottom:12px;">ℹ How Patch Updates Work</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;">
        <?php
        $steps = array(
          array('1','Request a change','Tell Claude what you want changed (bug fix, new feature, styling update).'),
          array('2','Receive patch ZIP','Claude gives you a small .zip containing only the changed files + a manifest.'),
          array('3','Upload here','Drop the patch ZIP on this page and click Apply Patch.'),
          array('4','Done instantly','The updater replaces only the changed files. No file manager, no deactivation needed.'),
        );
        foreach ($steps as $s) : ?>
        <div style="display:flex;gap:12px;">
          <div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--g5),var(--g7));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-size:13px;font-weight:800;color:var(--g1);flex-shrink:0;">
            <?php echo $s[0]; ?>
          </div>
          <div>
            <div style="font-family:'Syne',sans-serif;font-size:12px;font-weight:800;color:var(--ink);margin-bottom:3px;"><?php echo esc_html($s[1]); ?></div>
            <div style="font-size:12px;color:var(--ink3);line-height:1.5;"><?php echo esc_html($s[2]); ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div><!-- /eid-content -->
</div><!-- /eid-admin-page -->

<script>
var _patchNonce = '<?php echo esc_js($nonce); ?>';
var _ajaxUrl    = '<?php echo esc_js(admin_url("admin-ajax.php")); ?>';
var _patchFile  = null;

function handleFileSelect(input) {
  if (input.files && input.files[0]) {
    _patchFile = input.files[0];
    showFileReady(_patchFile);
  }
}

function handleDrop(e) {
  e.preventDefault();
  var zone = document.getElementById('patch-drop-zone');
  zone.style.borderColor = 'rgba(212,175,55,.4)';
  zone.style.background  = 'rgba(212,175,55,.04)';
  if (e.dataTransfer.files && e.dataTransfer.files[0]) {
    _patchFile = e.dataTransfer.files[0];
    showFileReady(_patchFile);
  }
}

function showFileReady(file) {
  var nameEl = document.getElementById('patch-file-name');
  nameEl.textContent = '📦 ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
  nameEl.style.display = 'block';
  document.getElementById('patch-apply-btn').disabled = false;
  document.getElementById('patch-result').style.display = 'none';
  document.getElementById('patch-files-list').style.display = 'none';
  /* Read manifest from ZIP to preview */
  readManifestFromZip(file);
}

function readManifestFromZip(file) {
  /* Use FileReader to read the zip and extract manifest for preview */
  var preview = document.getElementById('patch-preview');
  var content = document.getElementById('patch-preview-content');
  /* Simple filename display — full manifest reading needs JSZip */
  content.innerHTML = '<div style="font-family:\'JetBrains Mono\',monospace;font-size:11px;color:var(--ink2);">File: ' + file.name + '<br>Size: ' + (file.size/1024).toFixed(1) + ' KB<br><span style="color:var(--g5);">Manifest will be read server-side on Apply.</span></div>';
  preview.style.display = 'block';
}

function clearPatch() {
  _patchFile = null;
  document.getElementById('patch-file-input').value = '';
  document.getElementById('patch-file-name').style.display = 'none';
  document.getElementById('patch-apply-btn').disabled = true;
  document.getElementById('patch-preview').style.display = 'none';
  document.getElementById('patch-result').style.display = 'none';
  document.getElementById('patch-files-list').style.display = 'none';
  document.getElementById('patch-progress-wrap').style.display = 'none';
}

function applyPatch() {
  if (!_patchFile) return;

  var btn      = document.getElementById('patch-apply-btn');
  var progress = document.getElementById('patch-progress-wrap');
  var bar      = document.getElementById('patch-progress-bar');
  var pct      = document.getElementById('patch-progress-pct');
  var label    = document.getElementById('patch-progress-label');
  var result   = document.getElementById('patch-result');
  var filesList= document.getElementById('patch-files-list');
  var filesContent = document.getElementById('patch-files-content');

  btn.disabled = true;
  btn.textContent = '⏳ Applying…';
  progress.style.display = 'block';
  result.style.display = 'none';
  filesList.style.display = 'none';

  /* Animate progress bar */
  var prog = 0;
  var ticker = setInterval(function() {
    prog = Math.min(prog + Math.random() * 15, 85);
    bar.style.width = prog + '%';
    pct.textContent = Math.round(prog) + '%';
  }, 200);

  var formData = new FormData();
  formData.append('action', 'etelid_apply_patch');
  formData.append('nonce', _patchNonce);
  formData.append('patch_zip', _patchFile);

  fetch(_ajaxUrl, { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      clearInterval(ticker);
      bar.style.width = '100%';
      pct.textContent = '100%';
      label.textContent = 'Complete!';

      setTimeout(function() {
        progress.style.display = 'none';

        result.style.display = 'block';
        if (res.success) {
          var allGood = !res.data.failed || res.data.failed.length === 0;
          result.style.background = allGood ? 'rgba(34,197,94,.1)'  : 'rgba(245,158,11,.1)';
          result.style.border     = allGood ? '1px solid rgba(34,197,94,.3)' : '1px solid rgba(245,158,11,.3)';
          result.style.color      = allGood ? '#166534' : '#92400e';
          result.innerHTML = '<strong style="font-family:\'Syne\',sans-serif;font-size:14px;">' + (allGood ? '✅' : '⚠️') + ' ' + res.data.message + '</strong>';
          if (res.data.version) {
            result.innerHTML += '<div style="font-family:\'JetBrains Mono\',monospace;font-size:10px;margin-top:6px;opacity:.7;">New version: ' + res.data.version + '</div>';
          }

          /* Show files */
          var html = '';
          if (res.data.applied && res.data.applied.length) {
            res.data.applied.forEach(function(f) {
              html += '<div style="display:flex;align-items:center;gap:8px;padding:6px 10px;background:rgba(34,197,94,.07);border:1px solid rgba(34,197,94,.15);border-radius:6px;">'
                + '<span style="color:#22c55e;font-size:14px;">✓</span>'
                + '<span style="font-family:\'JetBrains Mono\',monospace;font-size:11px;color:var(--ink2);">' + f + '</span>'
                + '</div>';
            });
          }
          if (res.data.failed && res.data.failed.length) {
            res.data.failed.forEach(function(f) {
              html += '<div style="display:flex;align-items:center;gap:8px;padding:6px 10px;background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.15);border-radius:6px;">'
                + '<span style="color:#ef4444;font-size:14px;">✗</span>'
                + '<span style="font-family:\'JetBrains Mono\',monospace;font-size:11px;color:var(--ink2);">' + f + '</span>'
                + '</div>';
            });
          }
          if (html) {
            filesContent.innerHTML = html;
            filesList.style.display = 'block';
          }

          /* Auto-reload after 2s on full success */
          if (allGood) {
            result.innerHTML += '<div style="font-size:12px;margin-top:8px;opacity:.7;">Reloading page in 3 seconds…</div>';
            setTimeout(function() { location.reload(); }, 3000);
          }

        } else {
          result.style.background = 'rgba(239,68,68,.1)';
          result.style.border     = '1px solid rgba(239,68,68,.3)';
          result.style.color      = '#991b1b';
          result.innerHTML = '<strong>❌ ' + (res.data && res.data.message ? res.data.message : 'Unknown error') + '</strong>';
        }

        btn.disabled = false;
        btn.textContent = '⚡ Apply Patch';
      }, 400);
    })
    .catch(function(err) {
      clearInterval(ticker);
      progress.style.display = 'none';
      result.style.display = 'block';
      result.style.background = 'rgba(239,68,68,.1)';
      result.style.border     = '1px solid rgba(239,68,68,.3)';
      result.style.color      = '#991b1b';
      result.innerHTML = '<strong>❌ Network error: ' + err.message + '</strong>';
      btn.disabled = false;
      btn.textContent = '⚡ Apply Patch';
    });
}
</script>

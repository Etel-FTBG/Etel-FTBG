/* ════════════════════════════════════════════════════════════════
   ETEL Admin — Updater extension: ZIP deploy + multi-file deploy
   Load after admin.js. Injects new sections into the upload tab.
   ════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  function ready(fn) { if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

  ready(function () {
    /* Wait for the updater pane to exist (switchPage may not have rendered it yet) */
    var tries = 0;
    (function inject() {
      var pane = document.getElementById('upd-pane-upload');
      if (!pane) { if (tries++ < 40) setTimeout(inject, 300); return; }
      if (document.getElementById('upd-ext-wrap')) return; /* already injected */

      var wrap = document.createElement('div');
      wrap.id = 'upd-ext-wrap';
      /* Some mobile browsers pin their own tab-strip/toolbar over the bottom
         of the viewport. Without extra bottom room here, the last section
         (Deploy Multiple Files -> Choose Files) can end up permanently
         stuck under that overlay with no way to scroll it clear. */
      wrap.style.paddingBottom = 'calc(140px + env(safe-area-inset-bottom, 0px))';
      wrap.innerHTML = [
        /* ── divider ── */
        '<hr style="border:none;border-top:2px dashed #e0e0e0;margin:28px 0 22px">',

        /* ── ZIP deploy ── */
        '<div style="margin-bottom:28px">',
          '<div style="font-size:14px;font-weight:700;color:#e8e2d4;margin-bottom:10px">🗜️ Deploy ZIP File</div>',
          '<div style="background:rgba(46,204,113,.1);border:1px solid rgba(46,204,113,.3);border-radius:8px;padding:11px 15px;margin-bottom:14px;font-size:12.5px;color:#5DDBA0">',
            '✅ Upload any ZIP and all files are <strong>automatically extracted</strong> to the correct folders. ',
            'Existing files are backed up first. Leading folder (e.g. <em>delivery_final/</em>) is stripped automatically.',
          '</div>',
          '<div id="upd-zip-drop" ',
               'style="border:2.5px dashed #a5d6a7;border-radius:12px;padding:28px;text-align:center;cursor:pointer;',
                      'background:rgba(255,255,255,.03);transition:border-color .2s;margin-bottom:13px" ',
               'onclick="document.getElementById(\'upd-zip-file\').click()">',
            '<div style="font-size:34px;margin-bottom:7px">📦</div>',
            '<div style="font-weight:600;color:#e8e2d4;font-size:13px">Drop ZIP here or click to select</div>',
            '<div id="upd-zip-name" style="margin-top:8px;color:#2e7d32;font-weight:600;font-size:13px;min-height:18px"></div>',
          '</div>',
          '<input type="file" id="upd-zip-file" accept=".zip" style="display:none">',
          '<button onclick="updExtZipDeploy()" ',
                  'style="padding:12px 28px;background:linear-gradient(135deg,#2e7d32,#43a047);',
                         'color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:700;',
                         'cursor:pointer;transition:filter .2s" ',
                  'onmouseover="this.style.filter=\'brightness(1.08)\'" onmouseout="this.style.filter=\'\'">',
            '🚀 Extract &amp; Deploy ZIP',
          '</button>',
          '<div id="upd-zip-result" style="margin-top:13px;font-size:13px;display:none;border-radius:8px;padding:12px 16px;line-height:1.7"></div>',
        '</div>',

        /* ── divider ── */
        '<hr style="border:none;border-top:2px dashed #e0e0e0;margin:0 0 22px">',

        /* ── Multi-file deploy ── */
        '<div>',
          '<div style="font-size:14px;font-weight:700;color:#e8e2d4;margin-bottom:10px">📋 Deploy Multiple Files</div>',
          '<div style="background:rgba(51,149,255,.1);border:1px solid rgba(51,149,255,.3);border-radius:8px;padding:11px 15px;margin-bottom:14px;font-size:12.5px;color:#7CB8FF">',
            '💡 Select several files — paths are <strong>auto-detected</strong> from filenames. ',
            'Edit any path before deploying.',
          '</div>',
          '<input type="file" id="upd-multi-file" multiple ',
                 'accept=".html,.php,.js,.css,.json,.txt,.sql,.svg,.png,.jpg,.jpeg,.gif,.webp,.ico" ',
                 'style="margin-bottom:13px;font-size:13px" ',
                 'onchange="updExtShowMulti(this)">',
          '<div id="upd-multi-list" style="margin-bottom:14px"></div>',
          '<button id="upd-multi-btn" onclick="updExtMultiDeploy()" ',
                  'style="display:none;padding:12px 28px;background:linear-gradient(135deg,#1565c0,#1976d2);',
                         'color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:700;',
                         'cursor:pointer;transition:filter .2s" ',
                  'onmouseover="this.style.filter=\'brightness(1.1)\'" onmouseout="this.style.filter=\'\'">',
            '🚀 Deploy All Selected Files',
          '</button>',
          '<div id="upd-multi-result" style="margin-top:13px;font-size:13px;display:none;border-radius:8px;padding:12px 16px;line-height:1.7"></div>',
        '</div>',
      ].join('');

      pane.appendChild(wrap);
      wireZipDrop();
    })();
  });

  /* ── ZIP drag-drop ── */
  function wireZipDrop() {
    var drop = document.getElementById('upd-zip-drop');
    if (!drop) return;
    drop.addEventListener('dragover',  function (e) { e.preventDefault(); drop.style.borderColor = '#43a047'; });
    drop.addEventListener('dragleave', function ()  { drop.style.borderColor = '#a5d6a7'; });
    drop.addEventListener('drop',      function (e) {
      e.preventDefault(); drop.style.borderColor = '#a5d6a7';
      var f = e.dataTransfer && e.dataTransfer.files[0];
      if (f && f.name.toLowerCase().endsWith('.zip')) {
        /* Transfer to file input via DataTransfer */
        try {
          var dt = new DataTransfer(); dt.items.add(f);
          document.getElementById('upd-zip-file').files = dt.files;
        } catch (ex) {}
        document.getElementById('upd-zip-name').textContent = f.name + ' (' + Math.round(f.size / 1024) + ' KB)';
      }
    });
    document.getElementById('upd-zip-file').addEventListener('change', function () {
      var f = this.files[0];
      document.getElementById('upd-zip-name').textContent = f ? f.name + ' (' + Math.round(f.size / 1024) + ' KB)' : '';
    });
  }

  /* ── Path auto-detect (mirrors server logic) ── */
  var KNOWN_PATHS = {
    'sw.js':'sw.js','manifest.json':'manifest.json','manifest.php':'manifest.php',
    'offline.html':'offline.html','.htaccess':'.htaccess','boot.php':'api/boot.php',
    'updater.php':'api/updater.php','updater-api.php':'api/updater-api.php',
    'upload.php':'api/upload.php','config.php':'api/config.php','config-etb.php':'api/config-etb.php'
  };
  function detectPath(name) {
    var base = name.split('/').pop();
    if (KNOWN_PATHS[base]) return KNOWN_PATHS[base];
    var ext = (base.split('.').pop() || '').toLowerCase();
    var map = { js:'js/', css:'css/', php:'api/', sql:'api/' };
    return (map[ext] || '') + base;
  }

  /* ── API helpers ── */
  function apiUrl() {
    var base = (window.ETEL && ETEL.api) ? ETEL.api : '/delivery/api/boot.php';
    return base.replace('boot.php', 'updater-api.php');
  }
  function token() {
    try { return Auth.getToken ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); } catch (e) { return ''; }
  }

  /* ── Result renderer ── */
  function showResult(id, ok, html) {
    var el = document.getElementById(id); if (!el) return;
    el.style.display = 'block';
    el.style.background = ok ? '#e8f5e9' : '#ffebee';
    el.style.border = '1px solid ' + (ok ? '#a5d6a7' : '#ef9a9a');
    el.style.color   = ok ? '#1b5e20' : '#b71c1c';
    el.innerHTML = html;
  }

  /* ── ZIP deploy ── */
  window.updExtZipDeploy = function () {
    var fi   = document.getElementById('upd-zip-file');
    var file = fi && fi.files && fi.files[0];
    if (!file) { showResult('upd-zip-result', false, '❌ Please select a ZIP file first.'); return; }

    var btn = document.querySelector('[onclick="updExtZipDeploy()"]');
    if (btn) btn.textContent = '⏳ Extracting & deploying…';

    var fd = new FormData();
    fd.append('file', file);

    fetch(apiUrl() + '?action=upload_zip', {
      method: 'POST', headers: { 'Authorization': 'Bearer ' + token() }, body: fd
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.success) {
          var ok = d.data.extracted > 0;
          var html = '<b>' + (ok ? '✅' : '⚠️') + ' ' + d.data.extracted + ' files deployed</b>';
          html += ' <span style="color:#B8B0A0">· ' + (d.data.cd_count||'?') + ' entries in ZIP · strip: "' + (d.data.strip||'none') + '"</span>';
          if (d.data.skipped > 0) html += '<br><span style="color:#e65100">⚠️ ' + d.data.skipped + ' skipped</span>';
          if (d.data.skipped_list && d.data.skipped_list.length) html += '<br><small style="color:#B8B0A0">' + d.data.skipped_list.join('<br>') + '</small>';
          if (d.data.errors && d.data.errors.length) html += '<br><span style="color:#e53935">❌ ' + d.data.errors.length + ' errors: ' + d.data.errors.join(', ') + '</span>';
          if (d.data.files && d.data.files.length) html += '<br><small style="color:#1b5e20;line-height:1.9">' + d.data.files.join('<br>') + '</small>';
          showResult('upd-zip-result', ok, html);
          if (ok) { fi.value = ''; document.getElementById('upd-zip-name').textContent = ''; }
        } else {
          showResult('upd-zip-result', false, '❌ ' + (d.message || 'Deploy failed'));
        }
      })
      .catch(function (e) { showResult('upd-zip-result', false, '❌ Network error: ' + e.message); })
      .finally(function () { if (btn) btn.textContent = '🚀 Extract & Deploy ZIP'; });
  };

  /* ── Multi-file: show list ── */
  window.updExtShowMulti = function (inp) {
    var files = Array.from(inp.files || []);
    var list  = document.getElementById('upd-multi-list');
    var btn   = document.getElementById('upd-multi-btn');
    document.getElementById('upd-multi-result').style.display = 'none';

    if (!files.length) { list.innerHTML = ''; btn.style.display = 'none'; return; }

    list.innerHTML =
      '<div style="font-size:11px;font-weight:700;color:#B8B0A0;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">' +
      files.length + ' file' + (files.length > 1 ? 's' : '') + ' — edit target paths if needed</div>' +
      files.map(function (f, i) {
        return '<div style="display:flex;align-items:center;gap:8px;margin-bottom:7px">' +
          '<span style="font-size:12px;color:#e8e2d4;min-width:130px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + f.name + '">' + f.name + '</span>' +
          '<span style="color:#8a8378;font-size:13px">→</span>' +
          '<input type="text" class="upd-mpath" data-i="' + i + '" value="' + detectPath(f.name) + '" ' +
                 'style="flex:1;padding:5px 10px;border:1.5px solid #ddd;border-radius:7px;font-size:12px;font-family:monospace;min-width:0">' +
          '</div>';
      }).join('');
    btn.style.display = 'inline-block';
  };

  /* ── Multi-file: deploy ── */
  window.updExtMultiDeploy = function () {
    var fi    = document.getElementById('upd-multi-file');
    var files = fi && fi.files;
    if (!files || !files.length) return;

    var pathInputs = document.querySelectorAll('.upd-mpath');
    var fd = new FormData();
    var added = 0;

    Array.from(files).forEach(function (f, i) {
      var path = pathInputs[i] ? pathInputs[i].value.trim() : detectPath(f.name);
      if (path) { fd.append('files[]', f); fd.append('paths[]', path); added++; }
    });

    if (!added) { showResult('upd-multi-result', false, '❌ No files with valid paths.'); return; }

    var btn = document.getElementById('upd-multi-btn');
    if (btn) btn.textContent = '⏳ Deploying ' + added + ' file' + (added > 1 ? 's' : '') + '…';

    fetch(apiUrl() + '?action=upload_multiple', {
      method: 'POST', headers: { 'Authorization': 'Bearer ' + token() }, body: fd
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.success) {
          var html = '<b>✅ ' + d.data.uploaded + ' of ' + d.data.total + ' files deployed!</b>';
          if (d.data.errors && d.data.errors.length) html += '<br><span style="color:#e53935">⚠️ Failed: ' + d.data.errors.join(', ') + '</span>';
          html += '<br><small style="color:#B8B0A0;line-height:1.9">' + d.data.files.map(function (f) { return f.file + ' (' + f.size_kb + ' KB)'; }).join('<br>') + '</small>';
          showResult('upd-multi-result', true, html);
          fi.value = '';
          document.getElementById('upd-multi-list').innerHTML = '';
          if (btn) btn.style.display = 'none';
        } else {
          showResult('upd-multi-result', false, '❌ ' + (d.message || 'Deploy failed'));
        }
      })
      .catch(function (e) { showResult('upd-multi-result', false, '❌ Network error: ' + e.message); })
      .finally(function () { if (btn && btn.style.display !== 'none') btn.textContent = '🚀 Deploy All Selected Files'; });
  };

})();

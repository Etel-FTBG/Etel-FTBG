/* ============================================================
   ETEL Delivery — Admin Documents library
   Browse/view/edit/version PDFs, Office files (docx/pptx/xlsx/etc),
   Markdown, XML/.rels, and other reference documents from the admin
   dashboard. Binary upload/replace/download go through the standalone
   documents_upload.php / documents_download.php (boot.php can't parse
   multipart bodies or stream raw bytes); everything else is a normal
   JSON action via DocumentsController.php.
   ============================================================ */

const DOCS_TEXT_EXT = ['md','markdown','txt','xml','rels','csv','json','yml','yaml','html','htm','css','js'];
const DOCS_ICONS = {
  pdf:'📕', doc:'📘', docx:'📘', ppt:'📙', pptx:'📙', xls:'📗', xlsx:'📗',
  rtf:'📄', odt:'📘', odp:'📙', ods:'📗',
  md:'📝', markdown:'📝', txt:'📄', xml:'🔧', rels:'🔧', csv:'📊', json:'🔧',
  yml:'🔧', yaml:'🔧', html:'🌐', htm:'🌐', css:'🎨', js:'⚙️',
};

let DOCS_CACHE = [];
let DOCS_SEARCH_TIMER = null;

function docsIconFor(ext) { return DOCS_ICONS[(ext || '').toLowerCase()] || '📎'; }

function docsFormatSize(bytes) {
  bytes = parseInt(bytes, 10) || 0;
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
}

function docsDebouncedReload() {
  clearTimeout(DOCS_SEARCH_TIMER);
  DOCS_SEARCH_TIMER = setTimeout(loadDocumentsPanel, 350);
}

function docsAuthHeader() {
  return { 'Authorization': 'Bearer ' + (typeof Auth !== 'undefined' ? Auth.getToken() : '') };
}

function docsUploadUrl() {
  return (typeof ETEL !== 'undefined' && ETEL.api) ? ETEL.api.replace('boot.php', 'documents_upload.php') : 'api/documents_upload.php';
}
function docsDownloadUrl(id, opts) {
  opts = opts || {};
  const base = (typeof ETEL !== 'undefined' && ETEL.api) ? ETEL.api.replace('boot.php', 'documents_download.php') : 'api/documents_download.php';
  let url = base + '?document_id=' + encodeURIComponent(id);
  if (opts.versionId) url += '&version_id=' + encodeURIComponent(opts.versionId);
  if (opts.attachment) url += '&disposition=attachment';
  return url;
}

async function loadDocumentsPanel() {
  const tbody = document.getElementById('docs-tbody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="8" style="color:#B8B0A0">Loading…</td></tr>';
  const search = (document.getElementById('docs-search') || {}).value || '';
  const category = (document.getElementById('docs-category-filter') || {}).value || 'all';
  const res = await api('admin_docs_list', { search, category });
  if (!res.success) {
    tbody.innerHTML = `<tr><td colspan="8" style="color:#e57373">${esc(res.message || 'Could not load documents')}</td></tr>`;
    return;
  }
  DOCS_CACHE = res.data.documents || [];
  if (!DOCS_CACHE.length) {
    tbody.innerHTML = '<tr><td colspan="8" style="color:#B8B0A0;text-align:center;padding:30px">No documents yet. Upload one or create a text file above.</td></tr>';
    return;
  }
  tbody.innerHTML = DOCS_CACHE.map(d => `
    <tr>
      <td style="font-size:18px">${docsIconFor(d.file_ext)}</td>
      <td>
        <strong style="cursor:pointer;color:#D4AF37" onclick="openDocsViewModal(${d.id})">${esc(d.title)}</strong>
        <br><span style="font-size:11px;color:#9A9AAE">${esc(d.original_filename)}</span>
      </td>
      <td><span style="text-transform:uppercase;font-size:11px;font-weight:700;color:#B8B0A0">${esc(d.file_ext)}</span></td>
      <td>${esc(d.category || 'other')}</td>
      <td>${docsFormatSize(d.file_size)}</td>
      <td>v${parseInt(d.current_version, 10) || 1}</td>
      <td style="font-size:12px;color:#B8B0A0">${fmtTime ? fmtTime(d.updated_at) : esc(d.updated_at || '')}</td>
      <td style="white-space:nowrap">
        <button class="act-btn act-gold" onclick="openDocsViewModal(${d.id})" title="View / Edit">👁</button>
        <button class="act-btn" onclick="docsDownload(${d.id})" title="Download">⬇️</button>
        <button class="act-btn act-red" onclick="docsDelete(${d.id}, '${esc(d.title).replace(/'/g, "\\'")}')" title="Delete">🗑</button>
      </td>
    </tr>`).join('');
}

/* ─── Upload (new document) ─────────────────────────────────── */
function openDocsUploadModal() {
  openModal('custom', `
    <div class="modal-title">⬆️ Upload Document</div>
    <div class="modal-field"><label>Title *</label><input type="text" id="docu-title" placeholder="e.g. INSA Audit Package"></div>
    <div class="modal-field"><label>Category</label>
      <select id="docu-category">
        <option value="other">Other</option>
        <option value="legal">Legal</option>
        <option value="technical">Technical</option>
        <option value="audit">Audit / Compliance</option>
        <option value="finance">Finance</option>
      </select>
    </div>
    <div class="modal-field"><label>Description</label><input type="text" id="docu-desc" placeholder="Optional notes about this document"></div>
    <div class="modal-field"><label>File *</label><input type="file" id="docu-file"></div>
    <p style="font-size:11px;color:#9A9AAE;margin-bottom:12px">PDF, Word/Excel/PowerPoint (old and new formats), OpenDocument, Markdown, TXT, XML, .rels, CSV, JSON, YAML, HTML, CSS, JS. Max 20 MB.</p>
    <div class="modal-msg" id="docu-msg"></div>
    <button class="modal-submit" onclick="docsSubmitUpload()">Upload</button>
  `);
}

async function docsSubmitUpload() {
  const title = document.getElementById('docu-title').value.trim();
  const category = document.getElementById('docu-category').value;
  const description = document.getElementById('docu-desc').value.trim();
  const fileInput = document.getElementById('docu-file');
  const msg = document.getElementById('docu-msg');
  const file = fileInput && fileInput.files[0];
  if (!title) { msg.textContent = 'Title is required'; msg.style.color = '#e57373'; return; }
  if (!file) { msg.textContent = 'Choose a file'; msg.style.color = '#e57373'; return; }

  msg.textContent = 'Uploading…'; msg.style.color = '#D4AF37';
  const fd = new FormData();
  fd.append('action', 'upload');
  fd.append('file', file);
  fd.append('title', title);
  fd.append('category', category);
  fd.append('description', description);
  try {
    const r = await fetch(docsUploadUrl(), { method: 'POST', headers: docsAuthHeader(), body: fd });
    const res = await r.json();
    if (res.success) {
      if (typeof toast === 'function') toast('Document uploaded', 'ok');
      closeModal();
      loadDocumentsPanel();
    } else {
      msg.textContent = res.message || 'Upload failed'; msg.style.color = '#e57373';
    }
  } catch (err) {
    msg.textContent = 'Network error: ' + (err && err.message ? err.message : 'unknown'); msg.style.color = '#e57373';
  }
}

/* ─── New text file (no upload needed) ──────────────────────── */
function openDocsNewTextModal() {
  openModal('custom', `
    <div class="modal-title">📝 New Text File</div>
    <div class="modal-field"><label>Title *</label><input type="text" id="docn-title" placeholder="e.g. Release Notes"></div>
    <div class="modal-field"><label>Filename * (sets the file type)</label><input type="text" id="docn-filename" placeholder="e.g. release-notes.md"></div>
    <div class="modal-field"><label>Category</label>
      <select id="docn-category">
        <option value="other">Other</option>
        <option value="legal">Legal</option>
        <option value="technical">Technical</option>
        <option value="audit">Audit / Compliance</option>
        <option value="finance">Finance</option>
      </select>
    </div>
    <div class="modal-field"><label>Description</label><input type="text" id="docn-desc" placeholder="Optional"></div>
    <div class="modal-field">
      <label>Content</label>
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
        <input type="file" id="docn-file-loader" style="font-size:12px" onchange="docsLoadTextFile(this)">
        <span style="font-size:11px;color:#9A9AAE">Loads a local text file's content below instead of typing/pasting it. For PDF/Word/Excel/PowerPoint use "Upload Document" instead.</span>
      </div>
      <textarea id="docn-content" rows="12" style="width:100%;font-family:Consolas,Monaco,monospace;font-size:12.5px;padding:10px;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.05);color:#EDEDF5"></textarea>
    </div>
    <div class="modal-msg" id="docn-msg"></div>
    <button class="modal-submit" onclick="docsSubmitNewText()">Create</button>
  `);
}

// Reads a locally-picked file straight into the New Text File modal's
// Content box (and auto-fills filename/title if still empty) instead of
// requiring the admin to open the file elsewhere and paste its content
// in by hand. Text types only - readAsText() on a binary file (docx,
// pdf, etc) would just produce garbage, so those are rejected here with
// a pointer to the real Upload Document flow instead.
function docsLoadTextFile(input) {
  const file = input.files && input.files[0];
  if (!file) return;
  const ext = (file.name.split('.').pop() || '').toLowerCase();
  if (!DOCS_TEXT_EXT.includes(ext)) {
    input.value = '';
    if (typeof toast === 'function') {
      toast('".' + ext + '" is not a plain-text type - use "Upload Document" instead for PDF/Word/Excel/PowerPoint files', 'err');
    }
    return;
  }
  const reader = new FileReader();
  reader.onload = e => {
    const contentEl = document.getElementById('docn-content');
    const filenameEl = document.getElementById('docn-filename');
    const titleEl = document.getElementById('docn-title');
    if (contentEl) contentEl.value = e.target.result;
    if (filenameEl && !filenameEl.value.trim()) filenameEl.value = file.name;
    if (titleEl && !titleEl.value.trim()) {
      const base = file.name.replace(/\.[^.]+$/, '');
      titleEl.value = base.replace(/[-_]+/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    }
    if (typeof toast === 'function') toast('Loaded ' + file.name, 'ok');
  };
  reader.onerror = () => { if (typeof toast === 'function') toast('Could not read that file', 'err'); };
  reader.readAsText(file);
}

async function docsSubmitNewText() {
  const title = document.getElementById('docn-title').value.trim();
  const filename = document.getElementById('docn-filename').value.trim();
  const category = document.getElementById('docn-category').value;
  const description = document.getElementById('docn-desc').value.trim();
  const content = document.getElementById('docn-content').value;
  const msg = document.getElementById('docn-msg');
  if (!title || !filename) { msg.textContent = 'Title and filename are required'; msg.style.color = '#e57373'; return; }
  const res = await api('admin_docs_create_text', { title, filename, category, description, content });
  if (res.success) {
    if (typeof toast === 'function') toast('Document created', 'ok');
    closeModal();
    loadDocumentsPanel();
  } else {
    msg.textContent = res.message || 'Could not create document'; msg.style.color = '#e57373';
  }
}

/* ─── View / Edit ────────────────────────────────────────────── */
async function openDocsViewModal(id) {
  const res = await api('admin_docs_get', { document_id: id });
  if (!res.success) { if (typeof toast === 'function') toast(res.message || 'Could not load document', 'err'); return; }
  const doc = res.data.document;
  const ext = (doc.file_ext || '').toLowerCase();

  openModal('custom', `
    <div class="modal-title">${docsIconFor(ext)} ${esc(doc.title)}</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;font-size:12px;color:#9A9AAE">
      <span>${esc(doc.original_filename)}</span> · <span>${docsFormatSize(doc.file_size)}</span> ·
      <span>v${doc.current_version}</span> · <span>by ${esc(doc.uploaded_by_name || 'unknown')}</span>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
      <button class="btn btn-secondary" onclick="docsDownload(${doc.id})">⬇️ Download</button>
      <button class="btn btn-secondary" onclick="openDocsReplaceModal(${doc.id})">🔁 Replace</button>
      <button class="btn btn-secondary" onclick="openDocsHistoryModal(${doc.id})">🕓 History (${doc.versions.length})</button>
      <button class="btn btn-secondary" onclick="openDocsMetaModal(${doc.id})">✏️ Edit Info</button>
    </div>
    <div id="docs-viewer-body" style="border:1px solid rgba(255,255,255,.1);border-radius:10px;min-height:200px;max-height:60vh;overflow:auto;background:#fff">
      <div style="padding:30px;text-align:center;color:#888">Loading preview…</div>
    </div>
  `);
  docsRenderViewer(doc);
}

async function docsRenderViewer(doc) {
  const body = document.getElementById('docs-viewer-body');
  if (!body) return;
  const ext = (doc.file_ext || '').toLowerCase();

  if (doc.is_text_editable) {
    const content = doc.content != null ? doc.content : '(file too large to load inline - use Download)';
    body.style.background = '#1a1a1a';
    body.innerHTML = `
      <div style="display:flex;gap:6px;padding:8px;border-bottom:1px solid rgba(255,255,255,.1)">
        <button class="btn btn-secondary" style="font-size:11px;padding:4px 10px" onclick="docsToggleMdPreview(${doc.id})" id="docs-md-toggle-btn" ${ext === 'md' || ext === 'markdown' ? '' : 'style="display:none"'}>👁 Preview</button>
        <span style="flex:1"></span>
        <button class="btn btn-primary" style="font-size:11px;padding:4px 10px" onclick="docsSaveText(${doc.id})">💾 Save (new version)</button>
      </div>
      <textarea id="docs-edit-textarea" data-ext="${ext}" style="width:100%;min-height:420px;border:none;outline:none;resize:vertical;font-family:Consolas,Monaco,monospace;font-size:12.5px;padding:14px;background:#1a1a1a;color:#EDEDF5;box-sizing:border-box">${esc(content)}</textarea>
      <div id="docs-md-preview" style="display:none;padding:20px;background:#fff;color:#111"></div>
    `;
    return;
  }

  if (ext === 'pdf') {
    body.innerHTML = '<div style="padding:30px;text-align:center;color:#888">Loading PDF…</div>';
    try {
      const r = await fetch(docsDownloadUrl(doc.id), { headers: docsAuthHeader() });
      if (!r.ok) throw new Error('HTTP ' + r.status);
      const blob = await r.blob();
      const url = URL.createObjectURL(blob);
      body.innerHTML = `<iframe src="${url}" style="width:100%;height:65vh;border:none;display:block"></iframe>`;
    } catch (err) {
      body.innerHTML = `<div style="padding:30px;text-align:center;color:#c0392b">Could not load PDF preview (${esc(err.message || 'error')}). Use Download instead.</div>`;
    }
    return;
  }

  if (ext === 'docx' && typeof mammoth !== 'undefined') {
    body.innerHTML = '<div style="padding:30px;text-align:center;color:#888">Rendering preview…</div>';
    try {
      const r = await fetch(docsDownloadUrl(doc.id), { headers: docsAuthHeader() });
      if (!r.ok) throw new Error('HTTP ' + r.status);
      const buf = await r.arrayBuffer();
      const result = await mammoth.convertToHtml({ arrayBuffer: buf });
      // mammoth carries over the source document's own run/theme colors as
      // inline styles (e.g. a template's light-gray placeholder-text
      // styling), which can land unreadably light against this preview's
      // white background. Force consistent, readable text color here -
      // this is a rendering-only override for the preview pane, it does
      // not touch the underlying file. Scoped by class + !important since
      // mammoth's inline `style="color:..."` on individual spans would
      // otherwise beat plain inherited color.
      body.innerHTML = `
        <style>
          .docs-mammoth-preview, .docs-mammoth-preview * { color:#1a1a2e !important; background-color:transparent !important; }
          .docs-mammoth-preview a { color:#0645AD !important; text-decoration:underline; }
          .docs-mammoth-preview table { border-collapse:collapse; width:100%; margin:10px 0; }
          .docs-mammoth-preview td, .docs-mammoth-preview th { border:1px solid #ddd; padding:6px 8px; }
        </style>
        <div class="docs-mammoth-preview" style="padding:24px;max-width:780px;margin:0 auto;line-height:1.6">${result.value}</div>` +
        (result.messages && result.messages.length ? `<div style="padding:0 24px 16px;font-size:11px;color:#999">Note: this is a read-only preview - some formatting/images may not render exactly as in Word. Download for the original file.</div>` : '');
    } catch (err) {
      body.innerHTML = `<div style="padding:30px;text-align:center;color:#c0392b">Could not render DOCX preview (${esc(err.message || 'error')}). Use Download instead.</div>`;
    }
    return;
  }

  if (ext === 'pptx' && typeof JSZip !== 'undefined') {
    body.innerHTML = '<div style="padding:30px;text-align:center;color:#888">Extracting slide text…</div>';
    try {
      const r = await fetch(docsDownloadUrl(doc.id), { headers: docsAuthHeader() });
      if (!r.ok) throw new Error('HTTP ' + r.status);
      const buf = await r.arrayBuffer();
      const zip = await JSZip.loadAsync(buf);
      const slideFiles = Object.keys(zip.files)
        .filter(name => /^ppt\/slides\/slide\d+\.xml$/.test(name))
        .sort((a, b) => {
          const na = parseInt(a.match(/slide(\d+)\.xml/)[1], 10);
          const nb = parseInt(b.match(/slide(\d+)\.xml/)[1], 10);
          return na - nb;
        });
      if (!slideFiles.length) throw new Error('No slides found');
      let html = '<div style="padding:20px;color:#111"><p style="font-size:12px;color:#888;margin-bottom:16px">Text-only preview (formatting, images, and layout are not shown) - download for the full deck.</p>';
      for (let i = 0; i < slideFiles.length; i++) {
        const xml = await zip.file(slideFiles[i]).async('text');
        const texts = (xml.match(/<a:t>([^<]*)<\/a:t>/g) || []).map(m => m.replace(/<a:t>|<\/a:t>/g, ''));
        html += `<div style="border:1px solid #ddd;border-radius:8px;padding:14px;margin-bottom:12px">
          <div style="font-weight:700;font-size:12px;color:#999;margin-bottom:8px">Slide ${i + 1}</div>
          ${texts.length ? texts.map(t => `<div>${esc(t)}</div>`).join('') : '<div style="color:#aaa">(no text on this slide)</div>'}
        </div>`;
      }
      html += '</div>';
      body.innerHTML = html;
    } catch (err) {
      body.innerHTML = `<div style="padding:30px;text-align:center;color:#c0392b">Could not extract slide text (${esc(err.message || 'error')}). Use Download instead.</div>`;
    }
    return;
  }

  body.innerHTML = `<div style="padding:40px;text-align:center;color:#888">No inline preview available for .${esc(ext)} files.<br><br><button class="btn btn-primary" onclick="docsDownload(${doc.id})">⬇️ Download to view</button></div>`;
}

function docsToggleMdPreview(id) {
  const textarea = document.getElementById('docs-edit-textarea');
  const preview = document.getElementById('docs-md-preview');
  const btn = document.getElementById('docs-md-toggle-btn');
  if (!textarea || !preview) return;
  if (preview.style.display === 'none') {
    preview.innerHTML = docsSimpleMarkdownToHtml(textarea.value);
    preview.style.display = 'block';
    textarea.style.display = 'none';
    btn.textContent = '📝 Edit';
  } else {
    preview.style.display = 'none';
    textarea.style.display = 'block';
    btn.textContent = '👁 Preview';
  }
}

// Minimal, dependency-free Markdown -> HTML for the read-only preview
// toggle - covers headers, bold/italic, inline+block code, links, and
// lists, which is what the documents actually in this library use
// (see docs/INSA_FINDINGS.md). Not a full CommonMark implementation.
function docsSimpleMarkdownToHtml(md) {
  let html = esc(md);
  html = html.replace(/```([\s\S]*?)```/g, (m, code) => `<pre style="background:#f4f4f4;padding:10px;border-radius:6px;overflow-x:auto"><code>${code}</code></pre>`);
  html = html.replace(/`([^`]+)`/g, '<code style="background:#f4f4f4;padding:1px 5px;border-radius:4px">$1</code>');
  html = html.replace(/^### (.*)$/gm, '<h3>$1</h3>');
  html = html.replace(/^## (.*)$/gm, '<h2>$1</h2>');
  html = html.replace(/^# (.*)$/gm, '<h1>$1</h1>');
  html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  html = html.replace(/(?<!\*)\*([^*]+)\*(?!\*)/g, '<em>$1</em>');
  html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
  html = html.replace(/^- (.*)$/gm, '<li>$1</li>');
  html = html.replace(/(<li>.*<\/li>\n?)+/g, m => `<ul>${m}</ul>`);
  html = html.split(/\n{2,}/).map(block => (/^<(h1|h2|h3|ul|pre)/.test(block.trim()) ? block : `<p>${block}</p>`)).join('\n');
  return html;
}

async function docsSaveText(id) {
  const textarea = document.getElementById('docs-edit-textarea');
  if (!textarea) return;
  const note = prompt('Optional note about this change:') || '';
  const res = await api('admin_docs_update_text', { document_id: id, content: textarea.value, change_note: note });
  if (res.success) {
    if (typeof toast === 'function') toast('Saved as version ' + res.data.version, 'ok');
    loadDocumentsPanel();
    const fresh = await api('admin_docs_get', { document_id: id });
    if (fresh.success) {
      document.querySelector('.modal-title').textContent = docsIconFor(fresh.data.document.file_ext) + ' ' + fresh.data.document.title;
    }
  } else {
    if (typeof toast === 'function') toast(res.message || 'Save failed', 'err');
  }
}

/* ─── Metadata edit ──────────────────────────────────────────── */
function openDocsMetaModal(id) {
  const doc = DOCS_CACHE.find(d => d.id === id);
  openModal('custom', `
    <div class="modal-title">✏️ Edit Info</div>
    <div class="modal-field"><label>Title</label><input type="text" id="docm-title" value="${esc(doc ? doc.title : '')}"></div>
    <div class="modal-field"><label>Category</label>
      <select id="docm-category">
        ${['other','legal','technical','audit','finance'].map(c => `<option value="${c}" ${doc && doc.category === c ? 'selected' : ''}>${c.charAt(0).toUpperCase() + c.slice(1)}</option>`).join('')}
      </select>
    </div>
    <div class="modal-field"><label>Description</label><input type="text" id="docm-desc" value="${esc(doc ? (doc.description || '') : '')}"></div>
    <div class="modal-msg" id="docm-msg"></div>
    <button class="modal-submit" onclick="docsSaveMeta(${id})">Save</button>
  `);
}

async function docsSaveMeta(id) {
  const title = document.getElementById('docm-title').value.trim();
  const category = document.getElementById('docm-category').value;
  const description = document.getElementById('docm-desc').value.trim();
  const msg = document.getElementById('docm-msg');
  const res = await api('admin_docs_update_meta', { document_id: id, title, category, description });
  if (res.success) {
    if (typeof toast === 'function') toast('Updated', 'ok');
    closeModal();
    loadDocumentsPanel();
  } else {
    msg.textContent = res.message || 'Update failed'; msg.style.color = '#e57373';
  }
}

/* ─── Replace (new binary/text version via upload) ──────────── */
function openDocsReplaceModal(id) {
  openModal('custom', `
    <div class="modal-title">🔁 Replace Document</div>
    <p style="font-size:12px;color:#9A9AAE;margin-bottom:12px">Uploads a new version. The current version is kept in History and can be restored.</p>
    <div class="modal-field"><label>New file *</label><input type="file" id="docr-file"></div>
    <div class="modal-field"><label>Change note</label><input type="text" id="docr-note" placeholder="What changed?"></div>
    <div class="modal-msg" id="docr-msg"></div>
    <button class="modal-submit" onclick="docsSubmitReplace(${id})">Replace</button>
  `);
}

async function docsSubmitReplace(id) {
  const fileInput = document.getElementById('docr-file');
  const note = document.getElementById('docr-note').value.trim();
  const msg = document.getElementById('docr-msg');
  const file = fileInput && fileInput.files[0];
  if (!file) { msg.textContent = 'Choose a file'; msg.style.color = '#e57373'; return; }
  msg.textContent = 'Uploading…'; msg.style.color = '#D4AF37';
  const fd = new FormData();
  fd.append('action', 'replace');
  fd.append('file', file);
  fd.append('document_id', id);
  fd.append('change_note', note);
  try {
    const r = await fetch(docsUploadUrl(), { method: 'POST', headers: docsAuthHeader(), body: fd });
    const res = await r.json();
    if (res.success) {
      if (typeof toast === 'function') toast('Replaced with version ' + res.data.version, 'ok');
      closeModal();
      loadDocumentsPanel();
    } else {
      msg.textContent = res.message || 'Replace failed'; msg.style.color = '#e57373';
    }
  } catch (err) {
    msg.textContent = 'Network error: ' + (err && err.message ? err.message : 'unknown'); msg.style.color = '#e57373';
  }
}

/* ─── Version history ───────────────────────────────────────── */
async function openDocsHistoryModal(id) {
  const res = await api('admin_docs_get', { document_id: id });
  if (!res.success) { if (typeof toast === 'function') toast(res.message || 'Could not load history', 'err'); return; }
  const doc = res.data.document;
  openModal('custom', `
    <div class="modal-title">🕓 Version History — ${esc(doc.title)}</div>
    <div style="max-height:50vh;overflow-y:auto">
      ${doc.versions.map(v => `
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.08)">
          <div>
            <strong>v${v.version_number}</strong> ${v.version_number == doc.current_version ? '<span style="color:#2ECC71;font-size:11px">(current)</span>' : ''}
            <div style="font-size:11px;color:#9A9AAE">${esc(v.change_note || '')} — ${docsFormatSize(v.file_size)} — by ${esc(v.uploaded_by_name || 'unknown')} — ${fmtTime ? fmtTime(v.created_at) : esc(v.created_at || '')}</div>
          </div>
          <div style="white-space:nowrap">
            <button class="act-btn" onclick="docsDownload(${doc.id}, ${v.id})" title="Download this version">⬇️</button>
            ${v.version_number != doc.current_version ? `<button class="act-btn act-gold" onclick="docsRestoreVersion(${doc.id}, ${v.id})" title="Restore this version">↩️</button>` : ''}
          </div>
        </div>
      `).join('')}
    </div>
  `);
}

async function docsRestoreVersion(id, versionId) {
  if (!confirm('Restore this version as the new current version?')) return;
  const res = await api('admin_docs_restore_version', { document_id: id, version_id: versionId });
  if (res.success) {
    if (typeof toast === 'function') toast('Restored', 'ok');
    loadDocumentsPanel();
    openDocsHistoryModal(id);
  } else {
    if (typeof toast === 'function') toast(res.message || 'Restore failed', 'err');
  }
}

/* ─── Download / Delete ─────────────────────────────────────── */
async function docsDownload(id, versionId) {
  try {
    const r = await fetch(docsDownloadUrl(id, { versionId, attachment: true }), { headers: docsAuthHeader() });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    const blob = await r.blob();
    const disposition = r.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="([^"]+)"/);
    const filename = match ? match[1] : 'document';
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    setTimeout(() => URL.revokeObjectURL(url), 4000);
  } catch (err) {
    if (typeof toast === 'function') toast('Download failed: ' + (err && err.message ? err.message : 'unknown'), 'err');
  }
}

async function docsDelete(id, title) {
  if (!confirm(`Delete "${title}"? It can be recovered by an admin from the database if needed, but will disappear from this list.`)) return;
  const res = await api('admin_docs_delete', { document_id: id });
  if (res.success) {
    if (typeof toast === 'function') toast('Deleted', 'ok');
    loadDocumentsPanel();
  } else {
    if (typeof toast === 'function') toast(res.message || 'Delete failed', 'err');
  }
}

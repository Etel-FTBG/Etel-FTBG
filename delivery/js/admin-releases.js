/* ════════════════════════════════════════════════════════════════
   ETEL Admin — Releases panel  v1.0
   ════════════════════════════════════════════════════════════════
   Canary release management: set stable/canary version per app,
   add/remove specific canary testers, promote canary → stable, and
   roll back if something slips through. Talks to the five
   admin_* release actions in AdminController.php.

   Include on admin.html as its own tab (see integration note at the
   bottom of this file for the nav button + container markup this
   expects: #page-releases holding #releases-root).
   ════════════════════════════════════════════════════════════════ */

var RELEASES = { channels: [], searchResults: {} };

function releasesApi(action, params, onOk, onErr) {
    var token = (typeof Auth !== 'undefined' && Auth.getToken) ? Auth.getToken() : localStorage.getItem('etel_token');
    var base = (typeof ETEL !== 'undefined' && ETEL.api) ? ETEL.api : 'api/boot.php';
    fetch(base, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
        body: JSON.stringify(Object.assign({ action: action }, params))
    })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d && d.success) { if (onOk) onOk(d.data); }
            else { if (onErr) onErr(d && d.message ? d.message : 'Request failed'); }
        })
        .catch(function (e) { if (onErr) onErr(String(e)); });
}

function loadReleasesPanel() {
    var root = document.getElementById('releases-root');
    if (!root) return;
    root.innerHTML = '<div style="padding:20px;color:var(--text-dim,#999)">Loading release channels…</div>';
    releasesApi('admin_list_release_channels', {}, function (data) {
        RELEASES.channels = data.channels || [];
        renderReleasesPanel();
    }, function (err) {
        root.innerHTML = '<div style="padding:20px;color:#FF7043">Failed to load: ' + escapeHtmlR(err) + '</div>';
    });
}

function escapeHtmlR(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

function renderReleasesPanel() {
    var root = document.getElementById('releases-root');
    if (!root) return;
    var html = '';
    RELEASES.channels.forEach(function (ch) {
        var testers = ch.canary_testers || [];
        html += '<div class="rel-card" style="background:#12121a;border:1px solid rgba(212,175,55,.25);' +
            'border-radius:14px;padding:18px;margin-bottom:16px">';
        html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">' +
            '<div style="font-size:17px;font-weight:800;color:#F5E7B8;text-transform:capitalize">' + escapeHtmlR(ch.app_name) + '</div>' +
            '</div>';

        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">';

        html += '<div style="background:rgba(212,175,55,.08);border-radius:10px;padding:12px">' +
            '<div style="font-size:11px;color:#999;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Stable (everyone)</div>' +
            '<div style="font-size:20px;font-weight:800;color:#D4AF37;margin-bottom:8px">' + escapeHtmlR(ch.stable_version) + '</div>' +
            '<div style="display:flex;gap:6px">' +
            '<input type="text" id="rel-stable-input-' + ch.app_name + '" placeholder="new version label" ' +
            'style="flex:1;background:#0a0a12;border:1px solid rgba(255,255,255,.15);border-radius:8px;padding:8px;color:#fff;font-size:12.5px">' +
            '<button onclick="setReleaseChannel(\'' + ch.app_name + '\',\'stable\')" ' +
            'style="background:linear-gradient(135deg,#FFE9A8,#D4AF37);color:#1a1a2e;border:none;border-radius:8px;padding:8px 12px;font-weight:700;font-size:12px;cursor:pointer">Set</button>' +
            '</div>' +
            (ch.previous_stable_version ? '<div style="margin-top:8px"><button onclick="rollbackStable(\'' + ch.app_name + '\')" ' +
                'style="background:rgba(255,112,67,.15);border:1px solid rgba(255,112,67,.4);color:#FF7043;border-radius:8px;padding:6px 10px;font-size:11.5px;cursor:pointer">' +
                '↩ Rollback to ' + escapeHtmlR(ch.previous_stable_version) + '</button></div>' : '') +
            '</div>';

        html += '<div style="background:rgba(96,180,255,.08);border-radius:10px;padding:12px">' +
            '<div style="font-size:11px;color:#999;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Canary (testers only)</div>' +
            '<div style="font-size:20px;font-weight:800;color:#60B4FF;margin-bottom:8px">' + (ch.canary_version ? escapeHtmlR(ch.canary_version) : '— none —') + '</div>' +
            '<div style="display:flex;gap:6px">' +
            '<input type="text" id="rel-canary-input-' + ch.app_name + '" placeholder="new version label" ' +
            'style="flex:1;background:#0a0a12;border:1px solid rgba(255,255,255,.15);border-radius:8px;padding:8px;color:#fff;font-size:12.5px">' +
            '<button onclick="setReleaseChannel(\'' + ch.app_name + '\',\'canary\')" ' +
            'style="background:linear-gradient(135deg,#8FD0FF,#60B4FF);color:#0a0a12;border:none;border-radius:8px;padding:8px 12px;font-weight:700;font-size:12px;cursor:pointer">Set</button>' +
            '</div>' +
            '<div style="display:flex;gap:6px;margin-top:8px">' +
            (ch.canary_version ? '<button onclick="promoteCanary(\'' + ch.app_name + '\')" ' +
                'style="flex:1;background:rgba(76,175,80,.15);border:1px solid rgba(76,175,80,.4);color:#8BC34A;border-radius:8px;padding:7px;font-size:11.5px;font-weight:700;cursor:pointer">🚀 Promote to everyone</button>' +
                '<button onclick="setReleaseChannelClear(\'' + ch.app_name + '\')" ' +
                'style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);color:#ccc;border-radius:8px;padding:7px 10px;font-size:11.5px;cursor:pointer">Clear</button>' : '') +
            '</div>' +
            '</div>';

        html += '</div>';

        html += '<div style="font-size:12px;color:#999;margin-bottom:6px">Canary testers (' + testers.length + ')</div>';
        html += '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px">';
        if (!testers.length) html += '<div style="font-size:12px;color:#666">No testers added yet.</div>';
        testers.forEach(function (t) {
            html += '<div style="background:rgba(255,255,255,.06);border-radius:20px;padding:5px 6px 5px 12px;' +
                'display:flex;align-items:center;gap:8px;font-size:12px;color:#ddd">' +
                escapeHtmlR(t.name || t.phone) +
                '<button onclick="removeCanaryTester(\'' + ch.app_name + '\',' + t.user_id + ')" ' +
                'style="background:rgba(255,112,67,.2);border:none;color:#FF7043;border-radius:50%;width:18px;height:18px;font-size:12px;cursor:pointer;line-height:1">×</button>' +
                '</div>';
        });
        html += '</div>';

        html += '<div style="display:flex;gap:6px;position:relative">' +
            '<input type="text" id="rel-tester-search-' + ch.app_name + '" placeholder="Search name or phone to add a tester…" ' +
            'oninput="searchCanaryCandidates(\'' + ch.app_name + '\', this.value)" ' +
            'style="flex:1;background:#0a0a12;border:1px solid rgba(255,255,255,.15);border-radius:8px;padding:8px;color:#fff;font-size:12.5px">' +
            '</div>' +
            '<div id="rel-tester-results-' + ch.app_name + '" style="margin-top:6px"></div>';

        html += '</div>';
    });
    root.innerHTML = html || '<div style="padding:20px;color:#999">No apps found.</div>';
}

function setReleaseChannel(app, channelType) {
    var input = document.getElementById('rel-' + channelType + '-input-' + app);
    // Lowercased here too, not just server-side - version folders are
    // always created lowercase and the live filesystem is case-sensitive,
    // so "V3" typed with a stray capital would silently 404 and fall back
    // to the old version with no visible error at all. Showing the
    // corrected lowercase value in the confirm dialog makes it obvious
    // up front what's actually about to be set, rather than relying on
    // the backend's own normalization to quietly save the day.
    var version = input ? input.value.trim().toLowerCase() : '';
    if (!version) { alert('Enter a version label first (must match an uploaded js/releases/' + app + '/{version}/ folder).'); return; }
    if (!confirm('Set ' + channelType + ' for "' + app + '" to "' + version + '"?\n\nMake sure you have already uploaded js/releases/' + app + '/' + version + '/ via the Updater before doing this - this only points to it, it does not upload anything.')) return;
    releasesApi('admin_set_release_channel', { app: app, channel: channelType, version: version }, function () {
        loadReleasesPanel();
    }, function (err) { alert('Failed: ' + err); });
}

function setReleaseChannelClear(app) {
    if (!confirm('Turn off canary for "' + app + '"? Testers will fall back to stable until you set a new canary version.')) return;
    releasesApi('admin_set_release_channel', { app: app, channel: 'canary', version: '' }, function () {
        loadReleasesPanel();
    }, function (err) { alert('Failed: ' + err); });
}

function promoteCanary(app) {
    if (!confirm('Promote the current canary build for "' + app + '" to STABLE for all users?\n\nOnly do this after confirming canary testers hit no bugs.')) return;
    releasesApi('admin_promote_canary_to_stable', { app: app }, function () {
        loadReleasesPanel();
    }, function (err) { alert('Failed: ' + err); });
}

function rollbackStable(app) {
    if (!confirm('Roll back stable for "' + app + '" to the previous version? This affects every user on their next update-banner tap or page load (not force-pushed mid-session).')) return;
    releasesApi('admin_rollback_stable', { app: app }, function () {
        loadReleasesPanel();
    }, function (err) { alert('Failed: ' + err); });
}

function removeCanaryTester(app, userId) {
    releasesApi('admin_remove_canary_tester', { app: app, user_id: userId }, function () {
        loadReleasesPanel();
    }, function (err) { alert('Failed: ' + err); });
}

var _releasesSearchTimers = {};
function searchCanaryCandidates(app, q) {
    clearTimeout(_releasesSearchTimers[app]);
    var resultsBox = document.getElementById('rel-tester-results-' + app);
    if (!resultsBox) return;
    if (!q || q.trim().length < 2) { resultsBox.innerHTML = ''; return; }
    _releasesSearchTimers[app] = setTimeout(function () {
        releasesApi('admin_search_canary_candidates', { q: q.trim() }, function (data) {
            var users = data.users || [];
            if (!users.length) { resultsBox.innerHTML = '<div style="font-size:12px;color:#666;padding:4px">No matches.</div>'; return; }
            resultsBox.innerHTML = users.map(function (u) {
                return '<div onclick="addCanaryTester(\'' + app + '\',' + u.id + ',\'' + escapeHtmlR(u.name).replace(/'/g, "\\'") + '\')" ' +
                    'style="padding:8px 10px;background:#0a0a12;border-radius:8px;margin-bottom:4px;cursor:pointer;font-size:12.5px;color:#ddd;' +
                    'display:flex;justify-content:space-between">' +
                    '<span>' + escapeHtmlR(u.name) + ' <span style="color:#777">(' + escapeHtmlR(u.role) + ')</span></span>' +
                    '<span style="color:#999">' + escapeHtmlR(u.phone) + '</span></div>';
            }).join('');
        }, function () { resultsBox.innerHTML = ''; });
    }, 300);
}

function addCanaryTester(app, userId, name) {
    releasesApi('admin_add_canary_tester', { app: app, user_id: userId }, function () {
        var searchInput = document.getElementById('rel-tester-search-' + app);
        if (searchInput) searchInput.value = '';
        var resultsBox = document.getElementById('rel-tester-results-' + app);
        if (resultsBox) resultsBox.innerHTML = '';
        loadReleasesPanel();
    }, function (err) { alert('Failed to add ' + name + ': ' + err); });
}

/* ── Integration note ──────────────────────────────────────────────
   Add a nav tab next to the existing ones (Couriers / Service Givers /
   Items / Rides / Pricing / Reports / Updater):

     <button class="nav-tab" data-page="releases" onclick="switchAdminPage('releases')">
       🚀 Releases
     </button>

   And a page container alongside the other <div id="page-...">
   sections, then call loadReleasesPanel() when that tab is opened
   (wherever switchAdminPage() dispatches per-tab init calls, add a
   case for 'releases' -> loadReleasesPanel()):

     <div class="admin-page" id="page-releases" style="display:none">
       <h2>Releases</h2>
       <div id="releases-root"></div>
     </div>

   Include this file after admin.js:
     <script src="../js/admin-releases.js"></script>
   ────────────────────────────────────────────────────────────────── */

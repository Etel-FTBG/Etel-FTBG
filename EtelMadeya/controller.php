<?php require_once __DIR__ . '/includes/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Controller Panel - Etel Madeya</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@300;400;500;600;700&family=Cormorant+Garamond:wght@500;600;700&family=Rajdhani:wght@400;500;600;700&family=Barlow+Condensed:wght@400;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/controller-style.css">
    <link rel="stylesheet" href="assets/css/etel-premium.css">
    <link rel="stylesheet" href="assets/css/controller-mainpanel.css">
    <link rel="icon" href="assets/img/etel-logo.png">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
</head>
<body>
    <div id="app">
        <!-- Login Screen -->
        <div id="loginScreen" class="login-screen">
            <div class="login-orb login-orb-1"></div>
            <div class="login-orb login-orb-2"></div>
            <div class="login-box login-box-etelid">
                <div class="login-logo-ring">
                    <img src="assets/img/etel-logo.png" alt="Etel Madeya" class="login-logo">
                </div>
                <h1>Controller Panel</h1>
                <p class="login-subtitle">የኢትል ማደያ የቁጥጥር ፓነል</p>
                <div class="login-badge">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l8 4v6c0 5-3.4 8.4-8 10-4.6-1.6-8-5-8-10V6l8-4z"/></svg>
                    <span>Secured by EtelID</span>
                </div>

                <form id="etelidLoginForm" onsubmit="handleEtelIDLogin(event)">
                    <div class="form-group form-group-icon">
                        <label for="etelidIdentifier">EtelID, FIN, or Phone</label>
                        <svg class="input-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4.4 3.6-8 8-8s8 3.6 8 8"/></svg>
                        <input type="text" id="etelidIdentifier" required placeholder="EG-... or +251 9XX XXX XXX" autocomplete="username">
                    </div>
                    <div class="form-group form-group-icon">
                        <label for="etelidPassword">EtelID Password</label>
                        <svg class="input-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="11" width="16" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                        <input type="password" id="etelidPassword" required autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn-primary btn-block">Sign in with EtelID</button>
                </form>
                <div id="loginError" class="error-message" style="display:none"></div>

                <p class="login-footer-note">
                    Don't have an EtelID? <a href="https://ethelmarket.com/EtelId/" target="_blank" rel="noopener">Register at the EtelID portal</a>
                </p>
            </div>
        </div>

        <!-- Main Panel -->
        <div id="mainPanel" style="display:none">
            <!-- Header -->
            <header class="mc-hdr">
                <div class="mc-hdr-l">
                    <img src="assets/img/etel-logo.png" alt="Logo" class="mc-hdr-logo">
                    <div>
                        <div class="mc-hdr-name">Etel Madeya</div>
                        <div class="mc-hdr-station" id="stationName">Gas Station</div>
                    </div>
                </div>
                <div class="mc-hdr-r">
                    <span class="mc-hdr-ctrl"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 4v6c0 5-3.4 8.4-8 10-4.6-1.6-8-5-8-10V6l8-4z"/></svg><span id="controllerName">Controller</span></span>
                    <div class="mc-hdr-time"><span id="mcHdrDate"></span><b id="mcHdrTime"></b></div>
                    <button onclick="handleLogout()" class="btn-logout">Logout</button>
                </div>
            </header>

            <!-- Today's Rules config bar -->
            <div class="mc-config-bar">
                <span>Today's Rules:</span>
                <span>Parity: <b id="cfgParityLbl">&mdash;</b></span>
                <span>Vehicle Types: <b id="cfgTypesLbl">&mdash;</b></span>
            </div>

            <!-- Stats Bar (5 columns) -->
            <div class="mc-stats">
                <div class="sc f"><b id="statQueued">0</b><span>In Queue</span></div>
                <div class="sc g"><b id="statServed">0</b><span>Served Today</span></div>
                <div class="sc b"><b id="statOddEven">&mdash;</b><span>Odd / Even</span></div>
                <div class="sc r"><b id="statTotalToday">0</b><span>Total Today</span></div>
                <div class="sc sc-fuel">
                    <div class="sc-fuel-top">
                        <span class="sc-fuel-lbl"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="22" x2="15" y2="22"/><line x1="4" y1="9" x2="14" y2="9"/><path d="M4 22V4a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v18"/><path d="M14 13h2a2 2 0 0 1 2 2v3a2 2 0 0 0 2 2v0a2 2 0 0 0 2-2V9l-3-3"/></svg>Fuel</span>
                        <b id="statFuelRem" class="sc-fuel-val">&mdash;</b>
                    </div>
                    <div class="sc-fuel-track"><div id="statFuelBar" class="sc-fuel-bar"></div></div>
                    <div class="sc-fuel-bottom"><span>Remaining</span><span id="statFuelPct">&mdash;%</span></div>
                </div>
            </div>

            <!-- Tab Navigation -->
            <nav class="mc-tab-nav">
                <button class="mc-tab active" data-tab="register" onclick="mcSwitchTab('register', this)">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                    <span>Register</span>
                </button>
                <button class="mc-tab" data-tab="waiting" onclick="mcSwitchTab('waiting', this)">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
                    <span>Waiting <span class="mc-tab-count" id="tabCountWaiting"></span></span>
                </button>
                <button class="mc-tab" data-tab="vip" onclick="mcSwitchTab('vip', this)">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1Z"/></svg>
                    <span>VIP <span class="mc-tab-count" id="tabCountVip"></span></span>
                </button>
                <button class="mc-tab" data-tab="report" onclick="mcSwitchTab('report', this)">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M9 13h6"/><path d="M9 17h6"/></svg>
                    <span>Report</span>
                </button>
                <button class="mc-tab" data-tab="duplicates" onclick="mcSwitchTab('duplicates', this)">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg>
                    <span>Duplicates</span>
                </button>
            </nav>

            <!-- Tab: Register Vehicle + Active Queue -->
            <div id="tab-register" class="mc-tab-panel active">
                <div class="mc-body">
                    <!-- Register panel -->
                    <div class="pnl">
                        <div class="pnl-h"><h3>Register Vehicle</h3></div>
                        <div class="pnl-b">
                            <div class="plate-wrap">
                                <input type="text" id="regPlate" class="mc-plate-inp" placeholder="PLATE NUMBER" maxlength="20" autocomplete="off" oninput="updateRegParityHint()">
                                <div class="pc tl"></div><div class="pc tr"></div><div class="pc bl"></div><div class="pc br"></div>
                            </div>
                            <div class="parity-hint" id="regParHint">&nbsp;</div>

                            <div class="vtype-label">Vehicle Type</div>
                            <div id="vtypeGrid" class="vtypes"><!-- filled by JS --></div>

                            <div class="mc-field">
                                <label for="regDriverName">Driver Name (optional)</label>
                                <input type="text" id="regDriverName" placeholder="Optional">
                            </div>
                            <div class="mc-field">
                                <label for="regDriverPhone">Driver Phone (optional)</label>
                                <input type="tel" id="regDriverPhone" placeholder="Optional">
                            </div>

                            <button type="button" id="mcRegBtn" class="mc-reg-btn" onclick="handleRegisterVehicle()">Register &amp; Assign Token</button>
                            <div id="mcFb" class="mc-fb"><span></span><div><span id="mcFbMsg"></span><span class="fb-tk" id="mcFbTk"></span></div></div>

                            <div class="mc-divider"></div>
                            <div class="mc-subhead">Bulk Import Plates</div>
                            <button type="button" class="clr-btn blue" style="width:100%" onclick="showBulkImport()">Open Bulk Import</button>
                        </div>
                    </div>

                    <!-- Active Queue panel -->
                    <div class="pnl">
                        <div class="pnl-h">
                            <h3>Active Queue</h3>
                            <span class="pnl-h-count" id="queueCount"></span>
                            <div class="pnl-h-r">
                                <label for="vehicleTypeFilter" style="display:none">Vehicle Type</label>
                                <select id="vehicleTypeFilter" onchange="loadQueue(true)">
                                    <option value="0">All Types</option>
                                </select>
                                <label for="pageSizeSelect" style="display:none">Per Page</label>
                                <select id="pageSizeSelect" onchange="changePageSize()">
                                    <option value="25">25</option>
                                    <option value="50" selected>50</option>
                                    <option value="100">100</option>
                                    <option value="200">200</option>
                                    <option value="all">All</option>
                                </select>
                                <button type="button" class="clr-btn" onclick="loadQueue()">Refresh</button>
                            </div>
                        </div>
                        <div class="mc-search-bar">
                            <label for="queueSearchInput" style="display:none">Search plate number</label>
                            <input type="text" id="queueSearchInput" placeholder="Search plate number..." oninput="searchQueueRealtime()">
                            <button type="button" class="clr-btn" onclick="clearQueueSearch()">Clear</button>
                        </div>
                        <div class="mc-tbl-wrap">
                            <table class="qtbl">
                                <thead><tr><th>#</th><th>Plate</th><th>Type</th><th>Parity</th><th>Token</th><th>Registered</th><th>Action</th></tr></thead>
                                <tbody id="queueTableBody">
                                    <tr><td colspan="7" class="eq"><p>Loading queue…</p></td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div id="paginationBar" class="pagination-bar" style="display:none">
                            <div class="pagination-info" id="paginationInfo"></div>
                            <div class="pagination-controls" id="paginationControls"></div>
                        </div>
                    </div>
                </div>
            </div><!-- end tab-register -->

            <!-- Tab: Waiting -->
            <div id="tab-waiting" class="mc-tab-panel">
                <div style="padding:16px 20px;">
                    <div class="pnl">
                        <div class="pnl-h wait-pnl-h">
                            <h3 class="wait-title">Waiting Queue</h3>
                            <span class="pnl-h-count" id="waitingCount"></span>
                        </div>
                        <div class="mc-tbl-wrap" style="max-height:calc(100vh - 300px);">
                            <table class="qtbl">
                                <thead><tr><th>#</th><th>Plate</th><th>Type</th><th>Parity</th><th>Token</th><th>Time</th><th>Action</th></tr></thead>
                                <tbody id="waitingTableBody">
                                    <tr><td colspan="7" class="eq"><p>Loading…</p></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div><!-- end tab-waiting -->

            <!-- Tab: VIP -->
            <div id="tab-vip" class="mc-tab-panel">
                <div style="padding:16px 20px;">
                    <div class="pnl vip-pnl">
                        <div class="pnl-h vip-pnl-h">
                            <h3 class="vip-title">VIP Queue<span class="vip-badge">Paid Priority</span></h3>
                            <span class="pnl-h-count" id="vipCount"></span>
                        </div>
                        <div class="vip-grid">
                            <div class="vip-grid-l">
                                <div class="mc-subhead" style="color:#e74c3c;">Register VIP Vehicle</div>
                                <input type="text" id="vipPlate" class="vip-plate-inp" placeholder="VIP PLATE" maxlength="20" autocomplete="off">
                                <div class="mc-field">
                                    <label for="vipVehicleType">Vehicle Type</label>
                                    <select id="vipVehicleType"><option value="">Select type...</option></select>
                                </div>
                                <button type="button" class="vip-reg-btn" id="vipRegBtn" onclick="handleVipRegister()">Add to VIP Queue</button>
                                <div id="vipFb" class="vip-fb"><span id="vipFbMsg"></span></div>
                            </div>
                            <div class="mc-tbl-wrap" style="max-height:340px;">
                                <table class="qtbl">
                                    <thead><tr><th>#</th><th>Plate</th><th>Type</th><th>Parity</th><th>Token</th><th>Time</th><th>Action</th></tr></thead>
                                    <tbody id="vipTableBody">
                                        <tr><td colspan="7" class="eq"><p>Loading…</p></td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div><!-- end tab-vip -->

            <!-- Tab: Report (served vehicles) -->
            <div id="tab-report" class="mc-tab-panel">
                <div class="pnl" style="margin:16px 20px;">
                    <div class="rpt-toolbar">
                        <div class="rpt-field">
                            <label for="reportDate">Report Date</label>
                            <input type="date" id="reportDate" onchange="loadReport()">
                        </div>
                        <div class="rpt-field grow">
                            <label for="reportSearch">Search Plate</label>
                            <input type="text" id="reportSearch" placeholder="Search plate number..." oninput="filterReport()">
                        </div>
                        <button type="button" class="clr-btn" onclick="clearReportSearch()">Clear</button>
                        <span class="rpt-count" id="reportCount"></span>
                    </div>
                    <div id="reportMatchAlert" class="rpt-match-alert" style="display:none">
                        <span id="reportMatchText"></span>
                    </div>
                    <div class="mc-tbl-wrap" style="max-height:calc(100vh - 340px); margin-top:14px;">
                        <table class="qtbl">
                            <thead><tr><th>#</th><th>Plate Number</th><th>Vehicle Type</th><th>Parity</th><th>Token</th><th>Served At</th></tr></thead>
                            <tbody id="reportTableBody">
                                <tr><td colspan="6" class="eq"><p>Select a date to load report</p></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div><!-- end tab-report -->

            <!-- Tab: Duplicates (Import & Check) -->
            <div id="tab-duplicates" class="mc-tab-panel">
                <div class="pnl" style="margin:16px 20px;">
                    <div class="pnl-h"><h3>Import &amp; Duplicate Check</h3></div>
                    <div class="imp-panel">

                        <!-- Drop Zone -->
                        <div class="imp-dropzone" id="impDropzone"
                             ondragover="impDragOver(event)" ondragleave="impDragLeave(event)" ondrop="impDrop(event)">
                            <div class="imp-drop-icon"></div>
                            <div class="imp-drop-title">Drop TXT or ZIP files here</div>
                            <div class="imp-drop-sub">or click to browse</div>
                            <label for="impFileInput" style="display:none">Choose files</label>
                            <input type="file" id="impFileInput" accept=".txt,.zip" multiple style="display:none" onchange="impFilesChosen(this.files)">
                            <button type="button" class="imp-browse-btn" onclick="document.getElementById('impFileInput').click()">Browse Files</button>
                        </div>

                        <!-- File List -->
                        <div id="impFileList" class="imp-file-list" style="display:none"></div>

                        <!-- Vehicle Type + Check Button -->
                        <div class="imp-controls" id="impControls" style="display:none">
                            <div class="imp-controls-row">
                                <div class="imp-ctrl-group">
                                    <label for="impVehicleType">Vehicle Type</label>
                                    <select id="impVehicleType"></select>
                                </div>
                                <button type="button" class="imp-check-btn" onclick="runImportCheck()">Check for Duplicates</button>
                                <button type="button" class="imp-clear-btn" onclick="clearImport()">Clear</button>
                            </div>
                        </div>

                        <!-- Results -->
                        <div id="impResults" style="display:none">

                            <!-- Summary Cards -->
                            <div class="imp-summary">
                                <div class="imp-card imp-card-total">
                                    <div class="imp-card-num" id="impTotal">0</div>
                                    <div class="imp-card-label">Total Plates</div>
                                </div>
                                <div class="imp-card imp-card-clean">
                                    <div class="imp-card-num" id="impClean">0</div>
                                    <div class="imp-card-label">Ready to Import</div>
                                </div>
                                <div class="imp-card imp-card-infile">
                                    <div class="imp-card-num" id="impInFile">0</div>
                                    <div class="imp-card-label">Duplicates in File</div>
                                </div>
                                <div class="imp-card imp-card-inqueue">
                                    <div class="imp-card-num" id="impInQueue">0</div>
                                    <div class="imp-card-label">Already in Queue</div>
                                </div>
                                <div class="imp-card imp-card-served">
                                    <div class="imp-card-num" id="impServed">0</div>
                                    <div class="imp-card-label">Already Served Today</div>
                                </div>
                            </div>

                            <!-- Duplicate List -->
                            <div id="impDupSection" style="display:none">
                                <div class="imp-section-title">Duplicate &amp; Conflict List</div>
                                <div class="imp-legend">
                                    <span class="imp-badge imp-badge-infile">Dup in File</span>
                                    <span class="imp-badge imp-badge-inqueue">In Queue</span>
                                    <span class="imp-badge imp-badge-served">Served Today</span>
                                </div>
                                <div class="imp-dup-table-wrap">
                                    <table class="imp-dup-table">
                                        <thead><tr><th>#</th><th>Plate Number</th><th>Status</th><th>Detail</th></tr></thead>
                                        <tbody id="impDupBody"></tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Clean Plates Preview -->
                            <div id="impCleanSection" style="display:none">
                                <div class="imp-section-title">Clean Plates Ready to Import</div>
                                <div class="imp-clean-wrap" id="impCleanList"></div>
                                <button type="button" class="imp-import-btn" id="impImportBtn" onclick="doImportClean()">
                                    Import <span id="impImportCount">0</span> Plates into Queue
                                </button>
                                <div id="impImportFb" class="imp-import-fb" style="display:none"></div>
                            </div>

                        </div>
                    </div>
                </div>
            </div><!-- end tab-duplicates -->
        </div>

        <!-- Modals -->
        <div id="modalOverlay" class="modal-overlay" style="display:none" onclick="closeModal()"></div>

        <!-- Bulk Import Modal -->
        <div id="bulkImportModal" class="modal" style="display:none">
            <div class="modal-content mc-modal">
                <div class="modal-header">
                    <h2>Bulk Import Vehicles</h2>
                    <button onclick="closeModal()" class="close-btn">✕</button>
                </div>
                <form id="bulkImportForm" onsubmit="handleBulkImport(event)">
                    <div class="form-group">
                        <label for="bulkVehicleType">Vehicle Type *</label>
                        <select id="bulkVehicleType" required></select>
                    </div>
                    <div class="form-group">
                        <label for="bulkPlates">Plate Numbers (one per line) *</label>
                        <textarea id="bulkPlates" rows="10" required placeholder="AA-3-12345&#10;AA-3-12346&#10;AA-3-12347"></textarea>
                    </div>
                    <div class="modal-actions">
                        <button type="button" onclick="closeModal()" class="btn-secondary">Cancel</button>
                        <button type="submit" class="btn-primary">Import</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Serve Vehicle Modal -->
        <div id="serveModal" class="modal" style="display:none">
            <div class="modal-content mc-modal">
                <div class="modal-header">
                    <h2>Serve Vehicle</h2>
                    <button onclick="closeModal()" class="close-btn">✕</button>
                </div>
                <form id="serveForm" onsubmit="handleServe(event)">
                    <input type="hidden" id="serveQueueId">
                    <div class="serve-info">
                        <p><strong>Plate:</strong> <span id="servePlate"></span></p>
                        <p><strong>Type:</strong> <span id="serveType"></span></p>
                    </div>
                    <div class="form-group">
                        <label for="serveFuelGallons">Fuel Amount (Gallons)</label>
                        <input type="number" id="serveFuelGallons" step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label for="serveFuelLiters">Fuel Amount (Liters)</label>
                        <input type="number" id="serveFuelLiters" step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div class="modal-actions">
                        <button type="button" onclick="closeModal()" class="btn-secondary">Cancel</button>
                        <button type="submit" class="btn-success">Serve</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Notification -->
        <div id="notification" class="notification mc-toast" style="display:none"></div>
    </div>

    <script>
    const API = 'index.php?route=api';
    let currentController = null;
    let vehicleTypes = [];
    </script>
    <script src="assets/js/icons.js"></script>
    <script src="assets/js/controller-app.js"></script>
</body>
</html>

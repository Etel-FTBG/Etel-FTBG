<?php require_once __DIR__ . '/includes/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Controller Panel - Etel Madeya</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@300;400;500;600;700&family=Cormorant+Garamond:wght@500;600;700&family=Rajdhani:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/controller-style.css">
    <link rel="stylesheet" href="assets/css/etel-premium.css">
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
            <header class="header">
                <div class="header-left">
                    <img src="assets/img/etel-logo.png" alt="Logo" class="header-logo">
                    <div>
                        <h1 class="header-title">Etel Madeya</h1>
                        <p class="header-subtitle" id="stationName">Gas Station</p>
                    </div>
                </div>
                <div class="header-right">
                    <span class="controller-name" id="controllerName">Controller</span>
                    <button onclick="handleLogout()" class="btn-logout">Logout</button>
                </div>
            </header>

            <!-- Stats Bar -->
            <div class="stats-bar">
                <div class="stat-card">
                    <div class="stat-icon">📊</div>
                    <div class="stat-info">
                        <div class="stat-label">Total Queued</div>
                        <div class="stat-value" id="statQueued">0</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">✅</div>
                    <div class="stat-info">
                        <div class="stat-label">Served Today</div>
                        <div class="stat-value" id="statServed">0</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">⭐</div>
                    <div class="stat-info">
                        <div class="stat-label">VIP Queue</div>
                        <div class="stat-value" id="statVip">0</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">⏳</div>
                    <div class="stat-info">
                        <div class="stat-label">Waiting</div>
                        <div class="stat-value" id="statWaiting">0</div>
                    </div>
                </div>
            </div>

            <!-- Controls -->
            <div class="controls-bar">
                <div class="control-group">
                    <label for="vehicleTypeFilter">Vehicle Type:</label>
                    <select id="vehicleTypeFilter" onchange="loadQueue(true)">
                        <option value="0">All Types</option>
                    </select>
                </div>
                <div class="control-group">
                    <label for="queueTypeFilter">Queue Type:</label>
                    <select id="queueTypeFilter" onchange="loadQueue(true)">
                        <option value="all">All Queues</option>
                        <option value="normal">Normal</option>
                        <option value="vip">VIP</option>
                        <option value="waiting">Waiting</option>
                    </select>
                </div>
                <div class="control-group">
                    <label for="pageSizeSelect">Per Page:</label>
                    <select id="pageSizeSelect" onchange="changePageSize()">
                        <option value="25">25</option>
                        <option value="50" selected>50</option>
                        <option value="100">100</option>
                        <option value="200">200</option>
                        <option value="all">All</option>
                    </select>
                </div>
                <div class="control-actions">
                    <button onclick="showBulkImport()" class="btn-secondary">📥 Bulk Import</button>
                    <button onclick="showAddVehicle()" class="btn-primary">➕ Add Vehicle</button>
                    <button onclick="loadQueue()" class="btn-refresh">🔄 Refresh</button>
                </div>
            </div>

            <!-- Tab Navigation -->
            <div class="ctrl-tab-nav">
                <button class="ctrl-tab active" onclick="switchTab('queue', this)">📋 Queue</button>
                <button class="ctrl-tab" onclick="switchTab('report', this)">📑 Queue Report</button>
                <button class="ctrl-tab" onclick="switchTab('import', this)">📂 Import & Check</button>
            </div>

            <!-- Queue Table -->
            <div id="tab-queue" class="ctrl-tab-panel active">
            <div class="queue-container">
                <table class="queue-table">
                    <thead>
                        <tr>
                            <th>Position</th>
                            <th>Plate Number</th>
                            <th>Vehicle Type</th>
                            <th>Queue Type</th>
                            <th>Priority</th>
                            <th>Token</th>
                            <th>Registered</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="queueTableBody">
                        <tr>
                            <td colspan="8" class="empty-state">Loading queue...</td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div id="paginationBar" class="pagination-bar" style="display:none">
                    <div class="pagination-info" id="paginationInfo"></div>
                    <div class="pagination-controls" id="paginationControls"></div>
                </div>
            </div>
            </div><!-- end tab-queue -->

            <!-- Queue Report Panel -->
            <div id="tab-report" class="ctrl-tab-panel">
                <div class="report-panel">
                    <div class="report-toolbar">
                        <div class="report-toolbar-left">
                            <span class="report-title">🔴 Served Vehicles</span>
                            <span class="report-count" id="reportCount"></span>
                        </div>
                        <div class="report-toolbar-right">
                            <input type="date" id="reportDate" onchange="loadReport()">
                        </div>
                    </div>
                    <div class="report-search-bar">
                        <input type="text" id="reportSearch" placeholder="🔍 Search plate number..." oninput="filterReport()">
                        <button onclick="clearReportSearch()">✕ Clear</button>
                    </div>
                    <div id="reportMatchAlert" class="report-match-alert" style="display:none">
                        ⚠️ <span id="reportMatchText"></span>
                    </div>
                    <div class="report-table-wrap">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Plate Number</th>
                                    <th>Vehicle Type</th>
                                    <th>Parity</th>
                                    <th>Token</th>
                                    <th>Served At</th>
                                </tr>
                            </thead>
                            <tbody id="reportTableBody">
                                <tr><td colspan="6" class="empty-state">Select a date to load report</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div><!-- end tab-report -->

            <!-- Import & Check Panel -->
            <div id="tab-import" class="ctrl-tab-panel">
                <div class="imp-panel">

                    <!-- Drop Zone -->
                    <div class="imp-dropzone" id="impDropzone"
                         ondragover="impDragOver(event)" ondragleave="impDragLeave(event)" ondrop="impDrop(event)">
                        <div class="imp-drop-icon">📂</div>
                        <div class="imp-drop-title">Drop TXT or ZIP files here</div>
                        <div class="imp-drop-sub">or click to browse</div>
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
                            <button type="button" class="imp-check-btn" onclick="runImportCheck()">🔍 Check for Duplicates</button>
                            <button type="button" class="imp-clear-btn" onclick="clearImport()">✕ Clear</button>
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
                            <div class="imp-section-title">⚠️ Duplicate & Conflict List</div>
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
                            <div class="imp-section-title">✅ Clean Plates Ready to Import</div>
                            <div class="imp-clean-wrap" id="impCleanList"></div>
                            <button type="button" class="imp-import-btn" id="impImportBtn" onclick="doImportClean()">
                                ⬆️ Import <span id="impImportCount">0</span> Plates into Queue
                            </button>
                            <div id="impImportFb" class="imp-import-fb" style="display:none"></div>
                        </div>

                    </div>
                </div>
            </div><!-- end tab-import -->
        </div>

        <!-- Modals -->
        <div id="modalOverlay" class="modal-overlay" style="display:none" onclick="closeModal()"></div>
        
        <!-- Add Vehicle Modal -->
        <div id="addVehicleModal" class="modal" style="display:none">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Add Vehicle to Queue</h2>
                    <button onclick="closeModal()" class="close-btn">✕</button>
                </div>
                <form id="addVehicleForm" onsubmit="handleAddVehicle(event)">
                    <div class="form-group">
                        <label for="addPlate">Plate Number *</label>
                        <input type="text" id="addPlate" required placeholder="AA-3-12345">
                    </div>
                    <div class="form-group">
                        <label for="addVehicleType">Vehicle Type *</label>
                        <select id="addVehicleType" required></select>
                    </div>
                    <div class="form-group">
                        <label for="addDriverName">Driver Name</label>
                        <input type="text" id="addDriverName" placeholder="Optional">
                    </div>
                    <div class="form-group">
                        <label for="addDriverPhone">Driver Phone</label>
                        <input type="tel" id="addDriverPhone" placeholder="Optional">
                    </div>
                    <div class="modal-actions">
                        <button type="button" onclick="closeModal()" class="btn-secondary">Cancel</button>
                        <button type="submit" class="btn-primary">Add to Queue</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Bulk Import Modal -->
        <div id="bulkImportModal" class="modal" style="display:none">
            <div class="modal-content">
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
            <div class="modal-content">
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
                        <button type="submit" class="btn-success">✅ Serve</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Notification -->
        <div id="notification" class="notification" style="display:none"></div>
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

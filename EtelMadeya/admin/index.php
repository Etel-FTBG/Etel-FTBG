<?php require_once __DIR__ . '/../includes/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Etel Madeya</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@300;400;500;600;700&family=Cormorant+Garamond:wght@500;600;700&family=Rajdhani:wght@400;500;600;700&family=Barlow+Condensed:wght@400;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin-style.css">
    <link rel="stylesheet" href="../assets/css/etel-premium.css">
    <link rel="stylesheet" href="../assets/css/admin-mainpanel.css">
    <link rel="icon" href="../assets/img/etel-logo.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div id="app">
        <!-- Login Screen -->
        <div id="loginScreen" class="login-screen">
            <div class="login-orb login-orb-1"></div>
            <div class="login-orb login-orb-2"></div>
            <div class="login-box login-box-etelid">
                <div class="login-logo-ring">
                    <img src="../assets/img/etel-logo.png" alt="Etel Madeya" class="login-logo">
                </div>
                <h1>Admin Panel</h1>
                <p class="login-subtitle">የኢትል ማደያ አስተዳደር ፓነል</p>
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
            <header class="am-hdr">
                <div class="am-hdr-l">
                    <img src="../assets/img/etel-logo.png" alt="Logo" class="am-hdr-logo">
                    <div>
                        <div class="am-hdr-name">Etel Madeya</div>
                        <div class="am-hdr-sub">Admin Panel</div>
                    </div>
                </div>
                <div class="am-hdr-r">
                    <span class="am-hdr-admin"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 4v6c0 5-3.4 8.4-8 10-4.6-1.6-8-5-8-10V6l8-4z"/></svg><span id="adminName">Admin</span></span>
                    <div class="am-hdr-time"><span id="amHdrDate"></span><b id="amHdrTime"></b></div>
                    <button onclick="handleLogout()" class="btn-logout"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>Logout</button>
                </div>
            </header>

            <!-- Tab Navigation -->
            <nav class="am-tab-nav">
                <button class="am-tab active" data-tab="dashboard" onclick="showTab('dashboard')">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10"/><path d="M12 20V4"/><path d="M20 20v-7"/><path d="M2 20h20"/></svg>
                    <span>Dashboard</span>
                </button>
                <button class="am-tab" data-tab="controllers" onclick="showTab('controllers')">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 5 18.5V20"/><circle cx="9.5" cy="8" r="3.5"/><path d="M19 20v-1.5a3.5 3.5 0 0 0-2.5-3.35"/><path d="M15 4.6a3.5 3.5 0 0 1 0 6.8"/></svg>
                    <span>Controllers</span>
                </button>
                <button class="am-tab" data-tab="vehicles" onclick="showTab('vehicles')">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="7" width="13" height="10" rx="1"/><path d="M14 10h4l3 3v4h-7z"/><circle cx="6" cy="19" r="1.6"/><circle cx="17.5" cy="19" r="1.6"/></svg>
                    <span>Vehicle Types</span>
                </button>
                <button class="am-tab" data-tab="queue" onclick="showTab('queue')">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/></svg>
                    <span>Queue Monitor</span>
                </button>
                <button class="am-tab" data-tab="reports" onclick="showTab('reports')">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 17 6-6 4 4 8-8"/><path d="M17 7h4v4"/></svg>
                    <span>Reports</span>
                </button>
                <button class="am-tab" data-tab="settings" onclick="showTab('settings')">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg>
                    <span>Settings</span>
                </button>
            </nav>

            <!-- Main Content -->
            <main>
                <!-- Dashboard Tab -->
                <div id="dashboardTab" class="tab-content active">
                    <div class="am-page-hdr">
                        <h1>Dashboard</h1>
                        <button onclick="loadDashboard()" class="am-btn ghost"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/></svg> Refresh</button>
                    </div>

                    <div class="am-stats">
                        <div class="am-sc blue"><b id="statControllers">0</b><span>Active Controllers</span></div>
                        <div class="am-sc green"><b id="statQueued">0</b><span>Total Queued</span></div>
                        <div class="am-sc orange"><b id="statServedToday">0</b><span>Served Today</span></div>
                        <div class="am-sc purple"><b id="statVehicleTypes">0</b><span>Vehicle Types</span></div>
                    </div>

                    <div class="am-grid-2">
                        <div class="pnl">
                            <div class="pnl-h"><h3>Vehicles Served -- Last 14 Days</h3></div>
                            <div class="pnl-b">
                                <div style="height:220px;"><canvas id="chartServedTrend"></canvas></div>
                            </div>
                        </div>
                        <div class="pnl">
                            <div class="pnl-h"><h3>Fuel Stock Snapshot</h3></div>
                            <div class="pnl-b">
                                <div id="fuelSnapshot" class="am-stats" style="grid-template-columns:1fr;padding:0;">
                                    <p class="empty-state">Loading...</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="am-grid-2">
                        <div class="pnl">
                            <div class="pnl-h"><h3>Recent Activity</h3></div>
                            <div class="pnl-b">
                                <div id="recentActivity" class="activity-list">
                                    <p class="empty-state">Loading...</p>
                                </div>
                            </div>
                        </div>
                        <div class="pnl">
                            <div class="pnl-h"><h3>Controller Status</h3></div>
                            <div class="pnl-b">
                                <div id="controllerStatus" class="controller-list">
                                    <p class="empty-state">Loading...</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Controllers Tab -->
                <div id="controllersTab" class="tab-content">
                    <div class="am-page-hdr">
                        <h1>Controllers</h1>
                        <button onclick="showAddController()" class="am-btn"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg> Add Controller</button>
                    </div>
                    <div class="am-notice" id="ctrlNotice"></div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Full Name</th>
                                    <th>Station</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th>Last Login</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="controllersTableBody">
                                <tr><td colspan="8" class="empty-state">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Vehicle Types Tab -->
                <div id="vehiclesTab" class="tab-content">
                    <div class="am-page-hdr">
                        <h1>Vehicle Types</h1>
                        <button onclick="showAddVehicleType()" class="am-btn"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg> Add Vehicle Type</button>
                    </div>
                    <div class="am-notice" id="vtNotice"></div>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Icon</th>
                                    <th>Name (EN)</th>
                                    <th>Name (AM)</th>
                                    <th>Sort Order</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="vehiclesTableBody">
                                <tr><td colspan="7" class="empty-state">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Queue Monitor Tab -->
                <div id="queueTab" class="tab-content">
                    <div class="am-page-hdr">
                        <h1>Queue Monitor</h1>
                        <button onclick="loadQueueMonitor()" class="am-btn ghost"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/></svg> Refresh</button>
                    </div>
                    <div id="queueMonitor" style="padding-top:12px;">
                        <p class="empty-state">Loading...</p>
                    </div>
                </div>

                <!-- Reports Tab -->
                <div id="reportsTab" class="tab-content">
                    <div class="am-page-hdr">
                        <h1>Reports</h1>
                        <div class="report-filters">
                            <label for="reportDate" style="display:none">Report date</label>
                            <input type="date" id="reportDate" value="<?= date('Y-m-d') ?>">
                            <button onclick="loadReports()" class="am-btn">Generate Report</button>
                        </div>
                    </div>
                    <div id="reportsContent">
                        <p class="empty-state">Select a date and click Generate Report</p>
                    </div>
                </div>

                <!-- Settings Tab -->
                <div id="settingsTab" class="tab-content">
                    <div class="am-page-hdr">
                        <h1>Settings</h1>
                    </div>
                    <div class="am-notice" id="settNotice"></div>
                    <div class="settings-grid">
                        <div class="settings-card">
                            <h3>General Settings</h3>
                            <form id="generalSettingsForm" onsubmit="saveSettings(event, 'general')">
                                <div class="am-field">
                                    <label for="settingStationName">Station Name</label>
                                    <input type="text" id="settingStationName" required>
                                </div>
                                <div class="am-field">
                                    <label for="settingStationNameAm">Station Name (Amharic)</label>
                                    <input type="text" id="settingStationNameAm" required>
                                </div>
                                <div class="am-field">
                                    <label for="settingPhone">Phone</label>
                                    <input type="tel" id="settingPhone" required>
                                </div>
                                <div class="am-field">
                                    <label for="settingEmail">Email</label>
                                    <input type="email" id="settingEmail" required>
                                </div>
                                <button type="submit" class="am-btn">Save Changes</button>
                            </form>
                        </div>
                        <div class="settings-card">
                            <h3>Queue Settings</h3>
                            <form id="queueSettingsForm" onsubmit="saveSettings(event, 'queue')">
                                <div class="am-field">
                                    <label for="settingAlertThreshold">Alert Threshold (minutes)</label>
                                    <input type="number" id="settingAlertThreshold" required>
                                </div>
                                <div class="am-field">
                                    <label for="settingMaxQueueSize">Max Queue Size (vehicles)</label>
                                    <input type="number" id="settingMaxQueueSize" required>
                                </div>
                                <div class="am-field">
                                    <label class="am-check-label" for="settingEnableVip">
                                        <input type="checkbox" id="settingEnableVip">
                                        Enable VIP Queue
                                    </label>
                                </div>
                                <div class="am-field">
                                    <label class="am-check-label" for="settingEnableWaiting">
                                        <input type="checkbox" id="settingEnableWaiting">
                                        Enable Waiting Queue
                                    </label>
                                </div>
                                <button type="submit" class="am-btn">Save Changes</button>
                            </form>
                        </div>
                    </div>
                </div>
            </main>
        </div>

        <!-- Modals -->
        <div id="modalOverlay" class="modal-overlay" style="display:none" onclick="closeModal()"></div>
        <div id="modalContainer"></div>

        <!-- Notification -->
        <div id="notification" class="notification" style="display:none"></div>
    </div>

    <script>
    const API = 'api.php';
    let currentAdmin = null;
    </script>
    <script src="../assets/js/icons.js"></script>
    <script src="../assets/js/admin-app.js"></script>
</body>
</html>

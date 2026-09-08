<?php require_once __DIR__ . '/../includes/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Etel Madeya</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin-style.css">
    <link rel="icon" href="../assets/img/etel-logo.png">
</head>
<body>
    <div id="app">
        <!-- Login Screen -->
        <div id="loginScreen" class="login-screen">
            <div class="login-box">
                <img src="../assets/img/etel-logo.png" alt="Etel Madeya" class="login-logo">
                <h1>Admin Panel</h1>
                <p class="login-subtitle">የኢትል ማደያ አስተዳደር ፓነል</p>
                <form id="loginForm" onsubmit="handleLogin(event)">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" id="username" required autocomplete="username">
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" id="password" required autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn-primary">Login</button>
                </form>
                <div id="loginError" class="error-message" style="display:none"></div>
            </div>
        </div>

        <!-- Main Panel -->
        <div id="mainPanel" style="display:none">
            <!-- Sidebar -->
            <aside class="sidebar">
                <div class="sidebar-header">
                    <img src="../assets/img/etel-logo.png" alt="Logo">
                    <h2>Etel Madeya</h2>
                </div>
                <nav class="sidebar-nav">
                    <a href="#" onclick="showTab('dashboard')" class="nav-item active" data-tab="dashboard">
                        <span class="nav-icon">📊</span>
                        <span>Dashboard</span>
                    </a>
                    <a href="#" onclick="showTab('controllers')" class="nav-item" data-tab="controllers">
                        <span class="nav-icon">👥</span>
                        <span>Controllers</span>
                    </a>
                    <a href="#" onclick="showTab('vehicles')" class="nav-item" data-tab="vehicles">
                        <span class="nav-icon">🚗</span>
                        <span>Vehicle Types</span>
                    </a>
                    <a href="#" onclick="showTab('queue')" class="nav-item" data-tab="queue">
                        <span class="nav-icon">📋</span>
                        <span>Queue Monitor</span>
                    </a>
                    <a href="#" onclick="showTab('reports')" class="nav-item" data-tab="reports">
                        <span class="nav-icon">📈</span>
                        <span>Reports</span>
                    </a>
                    <a href="#" onclick="showTab('settings')" class="nav-item" data-tab="settings">
                        <span class="nav-icon">⚙️</span>
                        <span>Settings</span>
                    </a>
                </nav>
                <div class="sidebar-footer">
                    <div class="admin-info">
                        <span id="adminName">Admin</span>
                    </div>
                    <button onclick="handleLogout()" class="btn-logout">Logout</button>
                </div>
            </aside>

            <!-- Main Content -->
            <main class="main-content">
                <!-- Dashboard Tab -->
                <div id="dashboardTab" class="tab-content active">
                    <div class="page-header">
                        <h1>Dashboard</h1>
                        <button onclick="loadDashboard()" class="btn-refresh">🔄 Refresh</button>
                    </div>
                    
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon blue">👥</div>
                            <div class="stat-info">
                                <div class="stat-value" id="statControllers">0</div>
                                <div class="stat-label">Active Controllers</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon green">📊</div>
                            <div class="stat-info">
                                <div class="stat-value" id="statQueued">0</div>
                                <div class="stat-label">Total Queued</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon orange">✅</div>
                            <div class="stat-info">
                                <div class="stat-value" id="statServedToday">0</div>
                                <div class="stat-label">Served Today</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon purple">🚗</div>
                            <div class="stat-info">
                                <div class="stat-value" id="statVehicleTypes">0</div>
                                <div class="stat-label">Vehicle Types</div>
                            </div>
                        </div>
                    </div>

                    <div class="dashboard-grid">
                        <div class="dashboard-card">
                            <h3>Recent Activity</h3>
                            <div id="recentActivity" class="activity-list">
                                <p class="empty-state">Loading...</p>
                            </div>
                        </div>
                        <div class="dashboard-card">
                            <h3>Controller Status</h3>
                            <div id="controllerStatus" class="controller-list">
                                <p class="empty-state">Loading...</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Controllers Tab -->
                <div id="controllersTab" class="tab-content">
                    <div class="page-header">
                        <h1>Controllers</h1>
                        <button onclick="showAddController()" class="btn-primary">➕ Add Controller</button>
                    </div>
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
                    <div class="page-header">
                        <h1>Vehicle Types</h1>
                        <button onclick="showAddVehicleType()" class="btn-primary">➕ Add Vehicle Type</button>
                    </div>
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
                    <div class="page-header">
                        <h1>Queue Monitor</h1>
                        <button onclick="loadQueueMonitor()" class="btn-refresh">🔄 Refresh</button>
                    </div>
                    <div id="queueMonitor">
                        <p class="empty-state">Loading...</p>
                    </div>
                </div>

                <!-- Reports Tab -->
                <div id="reportsTab" class="tab-content">
                    <div class="page-header">
                        <h1>Reports</h1>
                        <div class="report-filters">
                            <input type="date" id="reportDate" value="<?= date('Y-m-d') ?>">
                            <button onclick="loadReports()" class="btn-primary">Generate Report</button>
                        </div>
                    </div>
                    <div id="reportsContent">
                        <p class="empty-state">Select a date and click Generate Report</p>
                    </div>
                </div>

                <!-- Settings Tab -->
                <div id="settingsTab" class="tab-content">
                    <div class="page-header">
                        <h1>Settings</h1>
                    </div>
                    <div class="settings-grid">
                        <div class="settings-card">
                            <h3>General Settings</h3>
                            <form id="generalSettingsForm" onsubmit="saveSettings(event, 'general')">
                                <div class="form-group">
                                    <label>Station Name</label>
                                    <input type="text" id="settingStationName" required>
                                </div>
                                <div class="form-group">
                                    <label>Station Name (Amharic)</label>
                                    <input type="text" id="settingStationNameAm" required>
                                </div>
                                <div class="form-group">
                                    <label>Phone</label>
                                    <input type="tel" id="settingPhone" required>
                                </div>
                                <div class="form-group">
                                    <label>Email</label>
                                    <input type="email" id="settingEmail" required>
                                </div>
                                <button type="submit" class="btn-primary">Save Changes</button>
                            </form>
                        </div>
                        <div class="settings-card">
                            <h3>Queue Settings</h3>
                            <form id="queueSettingsForm" onsubmit="saveSettings(event, 'queue')">
                                <div class="form-group">
                                    <label>Alert Threshold</label>
                                    <input type="number" id="settingAlertThreshold" required>
                                </div>
                                <div class="form-group">
                                    <label>Max Queue Size</label>
                                    <input type="number" id="settingMaxQueueSize" required>
                                </div>
                                <div class="form-group">
                                    <label>
                                        <input type="checkbox" id="settingEnableVip">
                                        Enable VIP Queue
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label>
                                        <input type="checkbox" id="settingEnableWaiting">
                                        Enable Waiting Queue
                                    </label>
                                </div>
                                <button type="submit" class="btn-primary">Save Changes</button>
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
    <script src="../assets/js/admin-app.js"></script>
</body>
</html>

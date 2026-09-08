<?php require_once __DIR__ . '/includes/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Controller Panel - Etel Madeya</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/controller-style.css">
    <link rel="icon" href="assets/img/etel-logo.png">
</head>
<body>
    <div id="app">
        <!-- Login Screen -->
        <div id="loginScreen" class="login-screen">
            <div class="login-box">
                <img src="assets/img/etel-logo.png" alt="Etel Madeya" class="login-logo">
                <h1>Controller Panel</h1>
                <p class="login-subtitle">የኢትል ማደያ የቁጥጥር ፓነል</p>
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

                <div class="login-divider"><span>or</span></div>
                <button type="button" class="btn-secondary" id="showEtelIDLoginBtn" onclick="toggleEtelIDLogin()">Sign in with EtelID</button>

                <form id="etelidLoginForm" style="display:none" onsubmit="handleEtelIDLogin(event)">
                    <div class="form-group">
                        <label>EtelID, FIN, or Phone</label>
                        <input type="text" id="etelidIdentifier" placeholder="EG-... or +251 9XX XXX XXX" autocomplete="username">
                    </div>
                    <div class="form-group">
                        <label>EtelID Password</label>
                        <input type="password" id="etelidPassword" autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn-primary">Sign in with EtelID</button>
                    <button type="button" class="btn-secondary" onclick="toggleEtelIDLogin()">Back</button>
                </form>
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
                    <label>Vehicle Type:</label>
                    <select id="vehicleTypeFilter" onchange="loadQueue(true)">
                        <option value="0">All Types</option>
                    </select>
                </div>
                <div class="control-group">
                    <label>Queue Type:</label>
                    <select id="queueTypeFilter" onchange="loadQueue(true)">
                        <option value="all">All Queues</option>
                        <option value="normal">Normal</option>
                        <option value="vip">VIP</option>
                        <option value="waiting">Waiting</option>
                    </select>
                </div>
                <div class="control-group">
                    <label>Per Page:</label>
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

            <!-- Queue Table -->
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
                        <label>Plate Number *</label>
                        <input type="text" id="addPlate" required placeholder="AA-3-12345">
                    </div>
                    <div class="form-group">
                        <label>Vehicle Type *</label>
                        <select id="addVehicleType" required></select>
                    </div>
                    <div class="form-group">
                        <label>Driver Name</label>
                        <input type="text" id="addDriverName" placeholder="Optional">
                    </div>
                    <div class="form-group">
                        <label>Driver Phone</label>
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
                        <label>Vehicle Type *</label>
                        <select id="bulkVehicleType" required></select>
                    </div>
                    <div class="form-group">
                        <label>Plate Numbers (one per line) *</label>
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
                        <label>Fuel Amount (Gallons)</label>
                        <input type="number" id="serveFuelGallons" step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Fuel Amount (Liters)</label>
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
    <script src="assets/js/controller-app.js"></script>
</body>
</html>

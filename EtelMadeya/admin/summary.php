<?php
// Etel Madeya - printable end-of-day summary for admins (use the browser's Print > Save as PDF).
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/stations.php';
if (!isAdminAuthenticated()) { http_response_code(401); echo 'Admin sign-in required'; exit; }
ensureStationColumns(); ensureTankColumn();
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
$cid  = (int)($_GET['controller_id'] ?? 0);
$stations = db()->fetchAll("SELECT id, station_name, station_address, region FROM controllers WHERE is_active = 1" . ($cid ? " AND id = " . $cid : "") . " ORDER BY region, station_name");
$assets = '../'; $picker = true;
include __DIR__ . '/../includes/summary_page.php';

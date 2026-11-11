<?php
// Etel Madeya - printable end-of-day summary for a station controller (own station only).
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/stations.php';
if (!isControllerAuthenticated()) { http_response_code(401); echo 'Controller sign-in required'; exit; }
ensureStationColumns(); ensureTankColumn();
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
if ($date > date('Y-m-d')) $date = date('Y-m-d');
$cid = (int)$_SESSION['controller_id'];
$stations = db()->fetchAll("SELECT id, station_name, station_address, region FROM controllers WHERE id = ?", [$cid]);
$assets = ''; $picker = false;
include __DIR__ . '/includes/summary_page.php';

<?php
// ============================================================
// ETEL AI — Global Settings Store
// File: EtelAi/api/settings.php
//
// Admin saves global settings here. All users load from here.
// Settings are stored in etel-global-settings.json next to this file.
// ============================================================
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$file   = __DIR__ . '/etel-global-settings.json';
$action = $_GET['action'] ?? 'load';

if ($action === 'load') {
    ob_clean();
    if (file_exists($file)) {
        $settings = json_decode(file_get_contents($file), true);
        echo json_encode(['ok' => true, 'settings' => $settings]);
    } else {
        echo json_encode(['ok' => true, 'settings' => null]);
    }
    exit;
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) { ob_clean(); http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Invalid JSON']); exit; }

    $allowed = ['engine','openrouterKey','openrouterModel','agentName','agentPersona','ollamaUrl','ollamaModel','compatUrl','compatModel'];
    $clean   = array_intersect_key($data, array_flip($allowed));

    file_put_contents($file, json_encode($clean, JSON_PRETTY_PRINT));
    ob_clean();
    echo json_encode(['ok' => true]);
    exit;
}

ob_clean();
echo json_encode(['ok' => false, 'error' => 'Unknown action']);

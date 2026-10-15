<?php
// ============================================================
// ETEL Delivery & Ride - payment / bank connections (Admin -> Payments & banks)
//   admin_integrations_get   {}                              -> every provider, which keys are set (never the secrets)
//   admin_integrations_save  { provider, values:{key:text} } -> save keys (encrypted). '' = keep, '__clear__' = remove
//   admin_integrations_test  { provider }                    -> ask the company if the saved key works
// Super-admin only (capability integrations.manage is held by '*' roles alone): these keys move real money.
// ============================================================
require_once __DIR__ . '/../lib/integrations.php';

switch ($action) {

case 'admin_integrations_get': {
    $pdo = db();
    require_admin($pdo, 'integrations.manage');
    ok(array('providers' => etel_integration_overview($pdo)));
    break;
}

case 'admin_integrations_save': {
    $pdo = db();
    $admin = require_admin($pdo, 'integrations.manage');
    $provider = isset($body['provider']) ? strtolower(trim((string) $body['provider'])) : '';
    $values = (isset($body['values']) && is_array($body['values'])) ? $body['values'] : array();
    $r = etel_integration_save($pdo, $provider, $values, intval($admin['id']));
    if (!$r['ok']) fail($r['message'], 400);
    ok(array('message' => $r['message'], 'providers' => etel_integration_overview($pdo)));
    break;
}

case 'admin_integrations_test': {
    $pdo = db();
    require_admin($pdo, 'integrations.manage');
    $provider = isset($body['provider']) ? strtolower(trim((string) $body['provider'])) : '';
    etel_integration_saved($pdo, true);
    ok(etel_integration_test($provider));
    break;
}

default:
    fail('Unknown action: ' . $action, 404);
}

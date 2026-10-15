<?php
// Payment and bank connections, set from the Admin page instead of editing api/.env on the server.
//
//   Admin pastes the keys once a deal is made -> they are stored ENCRYPTED in `integration_settings`
//   -> the payment code asks etel_integration_get('chapa', 'secret_key') and gets the saved value,
//      or the old api/.env value when nothing is saved (so nothing already working changes).
//
// Keys are encrypted with a key derived from INTEGRATIONS_KEY (api/.env) or, if that is not set, TOKEN_SECRET.
// If that secret ever changes the saved keys cannot be read: they count as "not set" (the .env value is used)
// and Admin shows "Re-enter". Secrets are never sent back to the browser: only whether one is saved.
// Which providers have working checkout code is stated honestly in etel_integration_providers() ('wired').

if (!defined('APP_VER')) { http_response_code(403); exit('Direct access not permitted'); }

// Providers and the settings each one takes. 'env' is the old api/.env name, kept as the fallback.
// wired = the server can already take payments with it; false = the keys are stored for when its checkout is built.
function etel_integration_providers($reset = false) {
    static $custom = null;
    if ($reset) { $custom = null; return array(); }
    $std = array(
        array('key' => 'merchant_id', 'label' => 'Merchant / shop ID', 'secret' => false),
        array('key' => 'api_key', 'label' => 'API key', 'secret' => true),
        array('key' => 'secret_key', 'label' => 'Secret key', 'secret' => true),
        array('key' => 'base_url', 'label' => 'API address (URL)', 'secret' => false),
    );
    if ($custom === null) {
        $custom = array();
        try {
            $pdo = etel_integ_pdo();
            etel_integration_ensure_schema($pdo);
            foreach ($pdo->query('SELECT provider, name, kind FROM integration_custom ORDER BY created_at, provider')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $custom[$r['provider']] = array('name' => $r['name'], 'icon' => $r['kind'] === 'bank' ? '🏦' : '💰', 'wired' => false, 'testable' => false, 'custom' => true,
                    'help' => 'Added by you. Keys are saved safely now; checkout for this ' . ($r['kind'] === 'bank' ? 'bank' : 'company') . ' is connected when the deal is signed.', 'fields' => $std);
            }
        } catch (Exception $e) { error_log('[ETEL] integrations custom: ' . $e->getMessage()); }
        catch (Throwable $e) { error_log('[ETEL] integrations custom: ' . $e->getMessage()); }
    }
    $built = array(
        'chapa' => array('name' => 'Chapa', 'icon' => '💳', 'wired' => true, 'testable' => true,
            'help' => 'Card, Telebirr and bank payments through Chapa. Copy the Secret key from your Chapa dashboard (Settings → API).',
            'fields' => array(array('key' => 'secret_key', 'label' => 'Secret key', 'secret' => true, 'env' => 'CHAPA_SECRET_KEY'))),
        'telebirr' => array('name' => 'Telebirr (direct)', 'icon' => '📱', 'wired' => true, 'testable' => false,
            'help' => 'Direct Telebirr USSD payments. Use the details Ethio telecom gave you when the merchant account was approved.',
            'fields' => array(
                array('key' => 'merchant_id', 'label' => 'Merchant ID', 'secret' => false, 'env' => 'TELEBIRR_MERCHANT_ID'),
                array('key' => 'api_key', 'label' => 'API key', 'secret' => true, 'env' => 'TELEBIRR_API_KEY'),
                array('key' => 'secret_key', 'label' => 'Secret key', 'secret' => true, 'env' => 'TELEBIRR_SECRET_KEY'))),
        'santimpay' => array('name' => 'SantimPay', 'icon' => '🏦', 'wired' => false, 'testable' => false, 'help' => 'Keys are saved safely now. Checkout for this company is connected when you sign the deal.', 'fields' => $std),
        'arifpay' => array('name' => 'ArifPay', 'icon' => '🏦', 'wired' => false, 'testable' => false, 'help' => 'Keys are saved safely now. Checkout for this company is connected when you sign the deal.', 'fields' => $std),
        'yaya' => array('name' => 'YaYa Wallet', 'icon' => '👛', 'wired' => false, 'testable' => false, 'help' => 'Keys are saved safely now. Checkout for this company is connected when you sign the deal.', 'fields' => $std),
        'cbe_birr' => array('name' => 'CBE Birr', 'icon' => '🏦', 'wired' => false, 'testable' => false, 'help' => 'Keys are saved safely now. Checkout for this bank is connected when you sign the deal.', 'fields' => $std),
    );
    return array_merge($built, $custom);
}

function etel_integration_field($provider, $key) {
    $all = etel_integration_providers();
    if (!isset($all[$provider])) return null;
    foreach ($all[$provider]['fields'] as $f) { if ($f['key'] === $key) return $f; }
    return null;
}

function etel_integ_env($key) {
    if (function_exists('etel_env')) return (string) etel_env($key, '', '');
    static $env = null;
    if ($env === null) {
        require_once __DIR__ . '/../env_loader.php';
        $env = etel_parse_env_file(__DIR__ . '/../.env');
    }
    if (isset($env[$key]) && $env[$key] !== '') return (string) $env[$key];
    $g = getenv($key);
    return ($g !== false && $g !== '') ? (string) $g : '';
}

function etel_integ_secret() {
    $k = etel_integ_env('INTEGRATIONS_KEY');
    if ($k === '') $k = etel_integ_env('TOKEN_SECRET');
    return $k;
}

// Encrypt-then-MAC (AES-256-CBC + HMAC-SHA256). Returns '' when there is no server secret to encrypt with.
function etel_integ_encrypt($plain) {
    $s = etel_integ_secret();
    if ($s === '' || !function_exists('openssl_encrypt')) return '';
    $ek = hash('sha256', 'etel-integ-enc|' . $s, true);
    $mk = hash('sha256', 'etel-integ-mac|' . $s, true);
    $iv = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
    $ct = openssl_encrypt((string) $plain, 'aes-256-cbc', $ek, OPENSSL_RAW_DATA, $iv);
    if ($ct === false) return '';
    return base64_encode($iv . hash_hmac('sha256', $iv . $ct, $mk, true) . $ct);
}

// Returns the plain text, or null when the value is damaged or the server secret has changed.
function etel_integ_decrypt($blob) {
    $s = etel_integ_secret();
    $raw = base64_decode((string) $blob, true);
    if ($s === '' || $raw === false || strlen($raw) < 49 || !function_exists('openssl_decrypt')) return null;
    $iv = substr($raw, 0, 16); $mac = substr($raw, 16, 32); $ct = substr($raw, 48);
    $ek = hash('sha256', 'etel-integ-enc|' . $s, true);
    $mk = hash('sha256', 'etel-integ-mac|' . $s, true);
    if (!hash_equals(hash_hmac('sha256', $iv . $ct, $mk, true), $mac)) return null;
    $p = openssl_decrypt($ct, 'aes-256-cbc', $ek, OPENSSL_RAW_DATA, $iv);
    return $p === false ? null : $p;
}

function etel_integration_ensure_schema($pdo) {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_settings (
        provider VARCHAR(30) NOT NULL,
        setting_key VARCHAR(40) NOT NULL,
        value_enc TEXT NOT NULL,
        updated_by INT UNSIGNED NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (provider, setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_custom (
        provider VARCHAR(30) NOT NULL,
        name VARCHAR(60) NOT NULL,
        kind VARCHAR(10) NOT NULL DEFAULT 'payment',
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (provider)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_changes (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        provider VARCHAR(30) NOT NULL,
        setting_key VARCHAR(40) NOT NULL,
        action VARCHAR(12) NOT NULL,
        admin_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY idx_provider (provider, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function etel_integ_pdo() {
    if (function_exists('db')) return db();
    $h = etel_integ_env('DB_HOST'); if ($h === '') $h = 'localhost';
    $n = etel_integ_env('DB_NAME'); if ($n === '') $n = 'etel_delivery';
    return new PDO('mysql:host=' . $h . ';dbname=' . $n . ';charset=utf8mb4', etel_integ_env('DB_USER'), etel_integ_env('DB_PASS'),
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
}

// All saved values, decrypted, read once per request: array('chapa.secret_key' => '...'). $pdo = null opens its own connection.
// $reset = true forgets the cache (after a save, and in tests).
function etel_integration_saved($pdo = null, $reset = false) {
    static $cache = null;
    if ($reset) { $cache = null; return array(); }
    if ($cache !== null) return $cache;
    $cache = array();
    try {
        if (!$pdo) $pdo = etel_integ_pdo();
        etel_integration_ensure_schema($pdo);
        foreach ($pdo->query('SELECT provider, setting_key, value_enc FROM integration_settings')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $v = etel_integ_decrypt($r['value_enc']);
            if ($v !== null && $v !== '') $cache[$r['provider'] . '.' . $r['setting_key']] = $v;
        }
    } catch (Exception $e) { error_log('[ETEL] integrations read: ' . $e->getMessage()); }
    catch (Throwable $e) { error_log('[ETEL] integrations read: ' . $e->getMessage()); }
    return $cache;
}

// The value to use: saved in Admin first, else the old api/.env value, else $default.
function etel_integration_get($provider, $key, $default = '') {
    $saved = etel_integration_saved();
    if (isset($saved[$provider . '.' . $key])) return $saved[$provider . '.' . $key];
    $f = etel_integration_field($provider, $key);
    if ($f && !empty($f['env'])) { $v = etel_integ_env($f['env']); if ($v !== '') return $v; }
    return $default;
}

// Where the value in use comes from: 'admin', 'env' or ''.
function etel_integration_source($provider, $key) {
    $saved = etel_integration_saved();
    if (isset($saved[$provider . '.' . $key])) return 'admin';
    $f = etel_integration_field($provider, $key);
    if ($f && !empty($f['env']) && etel_integ_env($f['env']) !== '') return 'env';
    return '';
}

// For the Admin page. Secrets are NEVER returned: only whether one is in use and where it comes from.
function etel_integration_overview($pdo) {
    etel_integration_ensure_schema($pdo);
    etel_integration_saved($pdo, true);
    $saved = etel_integration_saved($pdo);
    $unreadable = array();
    try {
        foreach ($pdo->query('SELECT provider, setting_key, value_enc FROM integration_settings')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($saved[$r['provider'] . '.' . $r['setting_key']])) $unreadable[$r['provider'] . '.' . $r['setting_key']] = true;
        }
    } catch (Exception $e) { /* none */ }
    $out = array();
    foreach (etel_integration_providers() as $id => $p) {
        $fields = array(); $all = true; $any = false;
        foreach ($p['fields'] as $f) {
            $src = etel_integration_source($id, $f['key']);
            if ($src === '') $all = false; else $any = true;
            $fields[] = array('key' => $f['key'], 'label' => $f['label'], 'secret' => $f['secret'], 'source' => $src,
                'value' => ($f['secret'] || $src === '') ? '' : etel_integration_get($id, $f['key']),
                'unreadable' => isset($unreadable[$id . '.' . $f['key']]));
        }
        $out[] = array('provider' => $id, 'name' => $p['name'], 'icon' => $p['icon'], 'help' => $p['help'], 'wired' => $p['wired'],
            'testable' => $p['testable'], 'custom' => !empty($p['custom']), 'fields' => $fields, 'state' => $all ? 'ready' : ($any ? 'partial' : 'empty'));
    }
    return $out;
}

// values: key => text. '' leaves a saved value alone; the exact word __clear__ removes it. Returns array(ok, message).
function etel_integration_save($pdo, $provider, $values, $admin_id) {
    $all = etel_integration_providers();
    if (!isset($all[$provider])) return array('ok' => false, 'message' => 'Unknown provider.');
    if (etel_integ_secret() === '') return array('ok' => false, 'message' => 'The server has no secret to encrypt with. Set TOKEN_SECRET in api/.env first.');
    etel_integration_ensure_schema($pdo);
    $changed = 0;
    foreach ($all[$provider]['fields'] as $f) {
        if (!isset($values[$f['key']])) continue;
        $v = trim((string) $values[$f['key']]);
        if ($v === '') continue;
        if ($v === '__clear__') {
            $pdo->prepare('DELETE FROM integration_settings WHERE provider=? AND setting_key=?')->execute(array($provider, $f['key']));
            $act = 'cleared';
        } else {
            if (strlen($v) > 500 || preg_match('/[\x00-\x1F]/', $v)) return array('ok' => false, 'message' => $f['label'] . ' looks wrong (too long or has hidden characters).');
            if ($f['key'] === 'base_url' && !preg_match('#^https://[A-Za-z0-9.-]+(:\d+)?(/[^\s]*)?$#', $v)) return array('ok' => false, 'message' => 'The API address must start with https://');
            $enc = etel_integ_encrypt($v);
            if ($enc === '') return array('ok' => false, 'message' => 'Could not encrypt the value on this server.');
            $pdo->prepare('INSERT INTO integration_settings (provider, setting_key, value_enc, updated_by) VALUES (?,?,?,?)
                           ON DUPLICATE KEY UPDATE value_enc=VALUES(value_enc), updated_by=VALUES(updated_by), updated_at=CURRENT_TIMESTAMP')
                ->execute(array($provider, $f['key'], $enc, $admin_id ? intval($admin_id) : null));
            $act = 'saved';
        }
        $pdo->prepare('INSERT INTO integration_changes (provider, setting_key, action, admin_id) VALUES (?,?,?,?)')
            ->execute(array($provider, $f['key'], $act, $admin_id ? intval($admin_id) : null));
        $changed++;
    }
    etel_integration_saved($pdo, true);
    return $changed ? array('ok' => true, 'message' => 'Saved. New payments use it straight away.') : array('ok' => false, 'message' => 'Nothing to save: type a value first.');
}

// Asks the company whether the saved key works. Only Chapa has a safe read-only call; the rest say so.
function etel_integration_test($provider) {
    $all = etel_integration_providers();
    if (!isset($all[$provider])) return array('ok' => false, 'message' => 'Unknown provider.');
    if ($provider !== 'chapa') return array('ok' => false, 'message' => 'There is no automatic test for ' . $all[$provider]['name'] . ' yet. Make a small real payment to check it.');
    $key = etel_integration_get('chapa', 'secret_key');
    if ($key === '') return array('ok' => false, 'message' => 'No Chapa secret key is set yet.');
    $ch = curl_init('https://api.chapa.co/v1/banks');
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . $key, 'Accept: application/json')));
    $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($raw === false) return array('ok' => false, 'message' => 'Could not reach Chapa from the server (' . ($err !== '' ? $err : 'network') . ').');
    if ($code === 200) return array('ok' => true, 'message' => 'Chapa accepted the key. Connection works.');
    if ($code === 401 || $code === 403) return array('ok' => false, 'message' => 'Chapa refused this key. Check you copied the whole Secret key.');
    return array('ok' => false, 'message' => 'Chapa answered with an unexpected error (HTTP ' . $code . '). Try again in a minute.');
}

// Admin: add a payment company or bank that is not in the list. $kind = 'payment' | 'bank'. Gets the key c1, c2, ...
// Returns array(ok, message, provider).
function etel_integration_add_custom($pdo, $name, $kind, $admin_id) {
    etel_integration_ensure_schema($pdo);
    $name = trim((string) $name);
    if ($name === '' || strlen($name) > 60 || preg_match('/[\x00-\x1F<>]/', $name)) return array('ok' => false, 'message' => 'Type the name of the company or bank (up to 60 letters).');
    $kind = $kind === 'bank' ? 'bank' : 'payment';
    foreach (etel_integration_providers() as $p) {
        if (strcasecmp($p['name'], $name) === 0) return array('ok' => false, 'message' => 'There is already a connection called ' . $p['name'] . '.');
    }
    $count = intval($pdo->query('SELECT COUNT(*) FROM integration_custom')->fetchColumn());
    if ($count >= 30) return array('ok' => false, 'message' => 'You already added 30. Remove one you do not use first.');
    $key = '';
    for ($i = $count + 1; $i <= $count + 40; $i++) {
        $ex = $pdo->prepare('SELECT 1 FROM integration_custom WHERE provider=?'); $ex->execute(array('c' . $i));
        if (!$ex->fetchColumn()) { $key = 'c' . $i; break; }
    }
    if ($key === '') return array('ok' => false, 'message' => 'Could not add it.');
    $pdo->prepare('INSERT INTO integration_custom (provider, name, kind, created_by) VALUES (?,?,?,?)')->execute(array($key, $name, $kind, $admin_id ? intval($admin_id) : null));
    $pdo->prepare('INSERT INTO integration_changes (provider, setting_key, action, admin_id) VALUES (?,?,?,?)')->execute(array($key, '-', 'added', $admin_id ? intval($admin_id) : null));
    etel_integration_providers(true);
    return array('ok' => true, 'message' => $name . ' added. Now paste its keys and press Save.', 'provider' => $key);
}

// Admin: remove an added company and its saved keys. Built-in providers cannot be removed.
function etel_integration_remove_custom($pdo, $provider, $admin_id) {
    etel_integration_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT 1 FROM integration_custom WHERE provider=?'); $st->execute(array($provider));
    if (!$st->fetchColumn()) return array('ok' => false, 'message' => 'Only companies you added can be removed.');
    $pdo->prepare('DELETE FROM integration_settings WHERE provider=?')->execute(array($provider));
    $pdo->prepare('DELETE FROM integration_custom WHERE provider=?')->execute(array($provider));
    $pdo->prepare('INSERT INTO integration_changes (provider, setting_key, action, admin_id) VALUES (?,?,?,?)')->execute(array($provider, '-', 'removed', $admin_id ? intval($admin_id) : null));
    etel_integration_providers(true);
    etel_integration_saved($pdo, true);
    return array('ok' => true, 'message' => 'Removed, with its saved keys.');
}

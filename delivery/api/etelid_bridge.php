<?php
/**
 * ETEL Delivery - EtelID Bridge
 * Pure PHP/MySQL . cPanel compatible . PHP 5.6+
 *
 * Server-to-server link to the EtelID system (the group's single
 * identity). Reads the sibling EtelID WordPress install's database
 * by parsing its wp-config.php at runtime - no credentials are
 * duplicated into this project.
 *
 * Used by:
 *   - AuthController  (etelid_login: one EtelID for the whole system)
 *   - RideController  (street hail: resolve scanned QR / FIN / card)
 */

if (!defined('APP_VER')) { http_response_code(403); exit; }

/* ---- Official Service API (preferred path) --------------------
   Calls the EtelID plugin's authenticated REST endpoints. Configure
   the shared secret BOTH sides:
     EtelID wp-config.php:  define('ETELID_SERVICE_KEY', '<key>');
     Delivery api/.env:     ETELID_SERVICE_KEY=<key>
       (or config.php:      define('CFG_ETELID_SERVICE_KEY','<key>');)
   If the key or endpoint is unavailable, the bridge falls back to
   direct database access automatically. */
function etelid_bridge_service_key() {
    /* Priority: .env (via etel_env helper) > getenv > CFG_ETELID_SERVICE_KEY constant.
       etel_env() is defined in api.php and handles BOM, Amharic comments, quoted
       values  it is always available when called from within an api.php request.
       The getenv() fallback covers CLI/test contexts where api.php may not be loaded. */
    if (function_exists('etel_env')) {
        $k = etel_env('ETELID_SERVICE_KEY', 'CFG_ETELID_SERVICE_KEY', '');
        if ($k) return $k;
    }
    $k = getenv('ETELID_SERVICE_KEY');
    if (!$k && defined('CFG_ETELID_SERVICE_KEY')) $k = CFG_ETELID_SERVICE_KEY;
    return $k ? $k : '';
}

function etelid_bridge_service_call($path, $payload, $timeout = 5) {
    $key = etelid_bridge_service_key();
    if (!$key) return null;                       /* not configured -> fallback */
    // NEVER build this URL from $_SERVER['HTTP_HOST'] -- that header is
    // fully attacker-controlled (a client can send any Host: value it
    // wants). Using it here would let an attacker redirect this request,
    // sensitive X-Etel-Service-Key header and all, to a server of their
    // choosing. Use a fixed, trusted domain instead -- same pattern as
    // ETEL_PAY_URL elsewhere -- with an optional config override for
    // staging, never a request-supplied one.
    $host = defined('CFG_ETELID_HOST') ? CFG_ETELID_HOST : 'ethelmarket.com';
    $url  = 'https://' . $host . '/EtelId/wp-json/etelid/v1/service/' . $path;
    $body = json_encode($payload);
    $resp = null;
    if (function_exists('curl_init')) {
        // One quick retry if the connection itself could not be made (DNS or
        // connect failure on a busy shared host). A request that connected
        // but is slow is NOT retried - the password was already sent once.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => array('Content-Type: application/json',
                                                'X-Etel-Service-Key: ' . $key),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT        => $timeout,
            ));
            $resp = curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            if ($resp !== false || !in_array($errno, array(6, 7), true)) break;   // 6 = resolve, 7 = connect
        }
    } else {
        $ctx = stream_context_create(array('http' => array(
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\nX-Etel-Service-Key: " . $key . "\r\n",
            'content' => $body,
            'timeout' => 5,
            'ignore_errors' => true,
        )));
        $resp = @file_get_contents($url, false, $ctx);
    }
    if (!$resp) return null;
    $d = @json_decode($resp, true);
    return is_array($d) ? $d : null;
}

/* Map service payload to the shape the rest of this bridge returns */
function etelid_bridge_member_from_service($d) {
    if (!isset($d['ok']) || !$d['ok'] || empty($d['member'])) return null;
    $m = $d['member'];
    return array(
        'id'        => 0,
        'etel_id'   => isset($m['etel_id'])   ? $m['etel_id']   : '',
        'full_name' => isset($m['full_name']) ? $m['full_name'] : '',
        'phone'     => isset($m['phone'])     ? $m['phone']     : '',
        'status'    => isset($m['status'])    ? $m['status']    : '',
        'pin_hash'  => '__VERIFIED_BY_SERVICE__',
        /* Limited (Ride-only) EtelID: created by the simplified Quick
           Register, no profile photo / Fayda ID on file yet. */
        'profile_limited' => !empty($m['profile_limited']),
    );
}

/* Makes sure users.etelid_limited exists (migration 061 adds it; this is the
   self-heal for a deploy where the code lands first) and returns whether the
   column is available. DDL is done here, never inside a transaction. */
function etelid_bridge_limited_col($pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        if (function_exists('etel_table_columns') && in_array('etelid_limited', etel_table_columns($pdo, 'users'), true)) { return $ok = true; }
        $pdo->exec("ALTER TABLE users ADD COLUMN etelid_limited TINYINT(1) NOT NULL DEFAULT 0");
        return $ok = true;
    } catch (Exception $e) {
        error_log('[ETEL] etelid_limited column: ' . $e->getMessage());
        return $ok = false;
    }
}

/* Records on the local users row whether the linked EtelID is limited. */
function etelid_bridge_set_limited($pdo, $user_id, $limited) {
    if (!$limited && !function_exists('etel_table_columns')) return;
    if ($limited) {
        if (!etelid_bridge_limited_col($pdo)) return;
    } else {
        if (!in_array('etelid_limited', etel_table_columns($pdo, 'users'), true)) return;
    }
    try {
        $pdo->prepare('UPDATE users SET etelid_limited = ? WHERE id = ?')->execute(array($limited ? 1 : 0, $user_id));
    } catch (Exception $e) {
        error_log('[ETEL] etelid_bridge_set_limited: ' . $e->getMessage());
    }
}

/* Narrow, best-effort use only: looking up a Telegram chat_id for OTP
   delivery (boot.php's password-reset flow) when no Service API route
   exists for that yet. NOT used for authentication or member lookups
   anymore (see etelid_bridge_find/authenticate/company_admin_phone
   below) - a 2026-07-22 audit flagged the direct wp-config.php parsing
   as fragile for auth ("breaks EtelID login if the WP install moves"),
   so those paths now rely strictly on the Service API and fail closed
   instead of falling back to this. Left in place only for the Telegram
   lookup, which was already optional/best-effort and degrades fine if
   this returns null (falls through to other OTP channels). */
function etelid_bridge_db() {
    static $pdo = false;
    if ($pdo !== false) return $pdo;
    $pdo = null;
    $candidates = array(
        (isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '') . '/EtelId/wp-config.php',
        __DIR__ . '/../../EtelId/wp-config.php',
        __DIR__ . '/../../../EtelId/wp-config.php',
    );
    foreach ($candidates as $cfg) {
        if (!$cfg || !@is_readable($cfg)) continue;
        $src = @file_get_contents($cfg);
        if (!$src) continue;
        $name = $user = $pass = $host = null;
        if (preg_match("/define\\(\\s*['\"]DB_NAME['\"]\\s*,\\s*['\"]([^'\"]*)['\"]/", $src, $m)) $name = $m[1];
        if (preg_match("/define\\(\\s*['\"]DB_USER['\"]\\s*,\\s*['\"]([^'\"]*)['\"]/", $src, $m)) $user = $m[1];
        if (preg_match("/define\\(\\s*['\"]DB_PASSWORD['\"]\\s*,\\s*['\"]([^'\"]*)['\"]/", $src, $m)) $pass = $m[1];
        if (preg_match("/define\\(\\s*['\"]DB_HOST['\"]\\s*,\\s*['\"]([^'\"]*)['\"]/", $src, $m)) $host = $m[1];
        $prefix = 'wp_';
        if (preg_match("/\\\$table_prefix\\s*=\\s*['\"]([^'\"]+)['\"]/", $src, $m)) $prefix = $m[1];
        if (!$name || !$user) continue;
        try {
            $p = new PDO('mysql:host=' . ($host ? $host : 'localhost') . ';dbname=' . $name . ';charset=utf8mb4',
                         $user, $pass,
                         array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                               PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
            $p->etelid_members_table = $prefix . 'etelid_members';
            $pdo = $p;
            return $pdo;
        } catch (Exception $e) {
            error_log('[ETEL][etelid] DB connect: ' . $e->getMessage());
        }
    }
    return $pdo;
}

/**
 * Resolve the phone number of the designated admin/owner for a company,
 * using the etelid_companies links every project already maintains (the
 * same table EtelAi/EtelGebeya/etc. auto-link members into on login).
 * This replaces needing a hand-configured merchant phone in every
 * project's .env -- whoever holds admin/owner/creator for company_slug
 * in Etel-ID IS the receiving account, and it can be changed by editing
 * that one row instead of redeploying every project.
 * Returns the phone string, or null if no admin is linked yet.
 */
function etelid_bridge_company_admin_phone($company_slug) {
    static $cache = array();
    if (array_key_exists($company_slug, $cache)) return $cache[$company_slug];

    /* Service API only - see the fragility note on etelid_bridge_db()
       above for why the old direct-DB/wp-config.php fallback was
       removed here. If ETELID_SERVICE_KEY isn't configured or the
       endpoint is unreachable, this fails closed (null) rather than
       falling back to fragile file-system coupling. */
    $svc = etelid_bridge_service_call('company_admin', array('company_slug' => $company_slug));
    if (is_array($svc) && !empty($svc['ok']) && !empty($svc['phone'])) {
        return $cache[$company_slug] = $svc['phone'];
    }
    return $cache[$company_slug] = null;
}

/**
 * Any active EtelGroup-assigned employee role for this phone at
 * $company_slug (not just admin/owner/creator) - powers "Sign in with
 * EtelID" auto-provisioning for staff accounts managed centrally from
 * the EtelGroup admin's Employees panel. Returns
 * { ok, phone, full_name, etel_id, role_at } or null if none/unreachable.
 * Same fail-closed behavior as etelid_bridge_company_admin_phone.
 */
function etelid_bridge_check_role($company_slug, $phone) {
    $svc = etelid_bridge_service_call('check_role', array('company_slug' => $company_slug, 'phone' => $phone));
    if (is_array($svc) && !empty($svc['ok'])) return $svc;
    return null;
}

/**
 * Find an EtelID member by ANY identifier:
 * verify URL, EG-... id, FIN (12 digits), card number (16 digits), or phone.
 * Returns assoc row (id, etel_id, full_name, phone, status, pin_hash) or null.
 */
function etelid_bridge_find($identifier) {
    /* Service API only - see the fragility note on etelid_bridge_db()
       above. The old fallback parsed identifiers itself and queried the
       WordPress DB directly; that entire code path (and the pin_hash it
       returned) is gone, so there's nothing left needing local password
       verification either - see the removed etelid_bridge_check_password(). */
    $svc = etelid_bridge_service_call('resolve', array('identifier' => $identifier));
    if ($svc === null) return null; // Service API unreachable/not configured - fail closed
    return etelid_bridge_member_from_service($svc);
}

/**
 * Find-or-create the local delivery user for an EtelID member.
 * Returns the local users row. New accounts get role 'customer'.
 */
function etelid_bridge_local_user($pdo, $member) {
    $digits = preg_replace('/[^0-9]/', '', $member['phone']);
    $last9  = strlen($digits) >= 9 ? substr($digits, -9) : $digits;
    $hasVer = function_exists('etel_table_columns') && in_array('token_version', etel_table_columns($pdo, 'users'), true);
    $hasEtelId = function_exists('etel_table_columns') && in_array('etel_id', etel_table_columns($pdo, 'users'), true);
    /* Oldest matching account wins, deterministically (this is the order the
       database effectively returned before). When one number has two accounts
       (e.g. an admin login and a separate shop-owner login), preferring the
       exact +251 form would hand the EtelID session to the wrong one. */
    $s = $pdo->prepare(
        "SELECT id, name, phone, role" . ($hasVer ? ", token_version" : "") . " FROM users
         WHERE REPLACE(REPLACE(phone,'+',''),' ','') = ?
            OR RIGHT(REPLACE(REPLACE(phone,'+',''),' ',''), 9) = ?
         ORDER BY id ASC
         LIMIT 1");
    $s->execute(array($digits, $last9));
    $u = $s->fetch();
    $isLimited = !empty($member['profile_limited']);
    /* Only the Service API payload carries profile_limited. The agent
       connector's direct SSO-token lookup does not, and must never be
       mistaken for "not limited" - so the local flag is only ever written
       when the member data actually says which it is. */
    $limitKnown = array_key_exists('profile_limited', $member);
    if ($u && $isLimited) {
        /* A limited (Ride-only) EtelID has no ID documents on file and its
           phone number is not verified at sign-up, so it must NOT be able to
           take over a pre-existing Delivery/Ride account that merely shares
           that phone number. Only an account already linked to this same
           EtelID may be used; anything else is refused. */
        $linked = false;
        if ($hasEtelId && !empty($member['etel_id'])) {
            $ck = $pdo->prepare('SELECT etel_id FROM users WHERE id = ?');
            $ck->execute(array($u['id']));
            $linked = ($ck->fetchColumn() === $member['etel_id']);
        }
        if (!$linked) {
            $GLOBALS['etelid_link_error'] = 'A Delivery/Ride account with this phone number already exists. Sign in with that account, or add your profile photo and Fayda ID to your Etel-ID first to link them.';
            return null;
        }
    }
    if ($u) {
        if ($limitKnown) etelid_bridge_set_limited($pdo, $u['id'], $isLimited);
        /* Backfill the etel_id link the first time this account is seen
           through EtelID SSO/service calls - existing accounts predate
           this column, and a courier's NFC card lookup (nfc_list_courier_orders
           etc.) needs it to resolve directly by etel_id, not only by phone. */
        if ($hasEtelId && !empty($member['etel_id'])) {
            $pdo->prepare("UPDATE users SET etel_id = ? WHERE id = ? AND (etel_id IS NULL OR etel_id = '')")
                ->execute(array($member['etel_id'], $u['id']));
        }
        return $u;
    }

    /* Auto-provision: one EtelID = access to the whole system */
    $hash = (strpos($member['pin_hash'], '$2') === 0)
        ? $member['pin_hash']                                   /* bcrypt: same password also works on classic login */
        : password_hash(bin2hex(openssl_random_pseudo_bytes(16)), PASSWORD_DEFAULT);
    $etelIdVal = $hasEtelId && !empty($member['etel_id']) ? $member['etel_id'] : null;
    try {
        if ($hasEtelId) {
            $pdo->prepare("INSERT INTO users (name, phone, etel_id, password_hash, role, active, created_at)
                           VALUES (?,?,?,?, 'customer', 1, NOW())")
                ->execute(array($member['full_name'], $member['phone'], $etelIdVal, $hash));
        } else {
            $pdo->prepare("INSERT INTO users (name, phone, password_hash, role, active, created_at)
                           VALUES (?,?,?, 'customer', 1, NOW())")
                ->execute(array($member['full_name'], $member['phone'], $hash));
        }
    } catch (Exception $e) {
        /* schema variant without active column */
        $pdo->prepare("INSERT INTO users (name, phone, password_hash, role, created_at)
                       VALUES (?,?,?, 'customer', NOW())")
            ->execute(array($member['full_name'], $member['phone'], $hash));
    }
    $s = $pdo->prepare("SELECT id, name, phone, role" . ($hasVer ? ", token_version" : "") . " FROM users WHERE id = ?");
    $s->execute(array($pdo->lastInsertId()));
    $created = $s->fetch();
    if ($created && $limitKnown) etelid_bridge_set_limited($pdo, $created['id'], $isLimited);
    return $created;
}

/* True when this local user is a limited (Ride-only) EtelID account right
   now (re-checked against EtelID, see etelid_bridge_still_limited()). */
function etelid_bridge_user_is_limited($pdo, $user_id) {
    try {
        if (!in_array('etelid_limited', etel_table_columns($pdo, 'users'), true)) return false;
        $hasEtelId = in_array('etel_id', etel_table_columns($pdo, 'users'), true);
        $q = $pdo->prepare('SELECT id, etelid_limited' . ($hasEtelId ? ', etel_id' : '') . ' FROM users WHERE id = ?');
        $q->execute(array($user_id));
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r || empty($r['etelid_limited'])) return false;
        return etelid_bridge_still_limited($pdo, $r);
    } catch (Exception $e) {
        return false;
    }
}

/* Fresh check of a limited account against EtelID (the member may have added
   their photo + Fayda ID since they signed in). Returns true while the EtelID
   is STILL limited, false once it is not (and clears the local flag). Fails
   closed (still limited) when EtelID cannot be reached. Cached per user for
   60 seconds in the system temp dir so a blocked client that keeps retrying
   cannot hammer EtelID. */
function etelid_bridge_still_limited($pdo, $user) {
    if (empty($user['etel_id'])) return true;
    $cache = rtrim(sys_get_temp_dir(), '/\\') . '/etel_limited_' . intval($user['id']) . '.txt';
    if (is_file($cache) && (time() - @filemtime($cache)) < 60) {
        return trim((string)@file_get_contents($cache)) !== '0';
    }
    $svc = etelid_bridge_service_call('resolve', array('identifier' => $user['etel_id']));
    if ($svc === null || empty($svc['member'])) return true;
    $still = !empty($svc['member']['profile_limited']);
    @file_put_contents($cache, $still ? '1' : '0');
    if (!$still) etelid_bridge_set_limited($pdo, $user['id'], false);
    return $still;
}


/**
 * Authenticate an EtelID (identifier + password) for SSO.
 * Prefers the official Service API (password verified inside EtelID);
 * falls back to direct DB + local hash check.
 * Returns array('member' => ...) on success or array('error' => msg, 'code' => http).
 */
// Resolve a member from an existing cross-app SSO token (Etelid_sessions
// table) rather than identifier+password - this is what lets the agent
// connector (EtelAI calling into Delivery on a user's behalf) identify
// WHO is asking without ever touching a password. Returns null on any
// invalid/expired/missing token, same fail-closed shape as authenticate().
function etelid_bridge_member_from_sso_token($pdo, $token) {
    $token = trim((string) $token);
    if ($token === '') return null;
    try {
        // BUG FIX: this previously queried "Etelid_sessions"/"Etelid_members"
        // (missing the plugin's own "etelid_" segment nested inside the
        // WordPress table prefix) - the real tables are
        // Etelid_etelid_sessions / Etelid_etelid_members, exactly as this
        // same file's own $prefix . 'etelid_members' construction further
        // up already does, and exactly as EtelAI's chat.php's tbl() helper
        // resolves them. Because these wrong names never matched any real
        // table, every single call here silently failed (caught exception
        // -> null -> "session expired") regardless of whether the token
        // was actually valid - this was the real cause of ride bookings
        // failing right after a perfectly successful quote, every time.
        // Confirmed via direct phpMyAdmin inspection: these tables are
        // correctly named Etelid_etelid_sessions/Etelid_etelid_members,
        // but they live in a SEPARATE database ("etelid") from
        // Delivery's own - $pdo here is Delivery's own connection, so an
        // unqualified table name would never resolve even though the
        // table genuinely exists. Fully-qualifying with the database
        // name is what was still missing.
        $stmt = $pdo->prepare(
            'SELECT m.id, m.etel_id, m.full_name, m.phone, m.pin_hash, m.status
             FROM etelid.Etelid_etelid_sessions s
             JOIN etelid.Etelid_etelid_members m ON m.id = s.member_id
             WHERE s.token = ? AND s.expires_at > NOW() LIMIT 1'
        );
        $stmt->execute(array($token));
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$m || $m['status'] !== 'active') return null;
        return $m;
    } catch (Exception $e) {
        error_log('[ETEL] etelid_bridge_member_from_sso_token query failed: ' . $e->getMessage());
        return null;
    }
}

function etelid_bridge_authenticate($identifier, $password) {
    // Sign-in gets a longer timeout than the other lookups: live timings showed
    // it averaging ~1.2 s with a worst case of 5.2 s, i.e. exactly the old
    // 5 s cut-off, so the slowest sign-ins were failing instead of finishing.
    $svc = etelid_bridge_service_call('authenticate',
        array('identifier' => $identifier, 'password' => $password, 'create_session' => true), 10);
    if ($svc !== null) {
        $m = etelid_bridge_member_from_service($svc);
        if ($m) {
            $result = array('member' => $m);
            // Real cross-app SSO token (Etelid_sessions table) - the same
            // token the EtelID portal's own login sets, so any other Etel
            // Group app (EtelAI, future ones) that checks that table
            // recognizes this login too, not just Delivery/Ride locally.
            if (!empty($svc['session_token'])) $result['sso_token'] = $svc['session_token'];
            return $result;
        }
        $msg  = isset($svc['message']) ? $svc['message'] : 'EtelID sign-in failed';
        $code = (isset($svc['data']['status'])) ? intval($svc['data']['status']) : 401;
        return array('error' => $msg, 'code' => $code);
    }
    // Service API unreachable/not configured - fail closed instead of the
    // old direct-DB/wp-config.php fallback (a 2026-07-22 audit flagged
    // that coupling as fragile: it breaks silently if the WordPress
    // install moves, updates, or tightens file permissions). Verify
    // ETELID_SERVICE_KEY is set in api/.env on both this app and EtelID's
    // wp-config.php if EtelID sign-in stops working - that's now the only
    // path.
    return array('error' => 'EtelID sign-in is temporarily unavailable. Please try again shortly.', 'code' => 503);
}

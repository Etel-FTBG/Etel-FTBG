<?php
switch ($action) {
case 'register': {
    $pdo   = db();
    $name  = isset($body['name'])     ? trim($body['name'])     : '';
    $phone = isset($body['phone'])    ? trim($body['phone'])    : '';
    $pw    = isset($body['password']) ? $body['password']       : '';
    $role  = isset($body['role'])     ? trim($body['role'])     : 'customer';
    if (!$name)  fail('Name is required');
    if (!$phone) fail('Phone is required');
    if (!$pw || strlen($pw) < 8) fail('Password must be at least 8 characters');
    if (!in_array($role, array('customer', 'courier'))) $role = 'customer';
    // New account - safe to enforce the strict Ethiopian mobile format here
    // (unlike login, which has to keep matching whatever shape is already
    // stored for existing accounts).
    $validated_phone = etel_validate_phone($phone);
    if (!$validated_phone) fail('Enter a valid Ethiopian mobile number, e.g. 0911234567');
    $phone = $validated_phone;
    // Registration had no rate limiting at all - unlike login, which makes it
    // an easy target for account-enumeration / spam-signup scripts. A later
    // pass added a pure-IP-scoped limiter here, but a 2026-07-22 audit
    // correctly flagged that as still NAT-hostile: Ethio Telecom mobile
    // traffic commonly shares a handful of carrier-grade NAT IPs across
    // thousands of subscribers, so any single shared bucket keyed only on
    // IP eventually locks out genuinely different people signing up close
    // together. Fix: key the tight limiter on the phone number itself
    // (repeatedly hammering ONE number is what actually indicates abuse of
    // that number) and keep IP scoping only as a much looser secondary
    // ceiling to still catch a bot cycling through many fake numbers from
    // one address, rather than as the primary/only signal.
    rate_limit_check($pdo, 'register:' . $phone, 5, 3600, 1800);   // same number, max 5/hour
    rate_limit_check($pdo, 'register:ip', 60, 3600, 1800);          // same IP, many different numbers, max 60/hour
    rate_limit_fail($pdo, 'register:' . $phone); // count this attempt whether or not it ultimately succeeds
    rate_limit_fail($pdo, 'register:ip');
    // Check duplicate
    $chk = $pdo->prepare('SELECT id FROM users WHERE phone = ?');
    $chk->execute(array($phone));
    if ($chk->fetch()) fail('Phone number already registered');
    $hash = password_hash($pw, PASSWORD_DEFAULT);
    // Build one INSERT from the actual schema instead of trying several
    // variants and swallowing failures - see etel_table_columns() in
    // boot.php for why that pattern is risky (it hides real DB errors,
    // not just missing columns).
    $uid = 0;
    $userCols = etel_table_columns($pdo, 'users');
    $fields = array('name', 'phone', 'password_hash');
    $marks  = array('?', '?', '?');
    $values = array($name, $phone, $hash);
    $roleInInsert = in_array('role', $userCols, true);
    if ($roleInInsert) { $fields[] = 'role'; $marks[] = '?'; $values[] = $role; }
    if (in_array('active', $userCols, true)) { $fields[] = 'active'; $marks[] = '1'; }
    $fields[] = 'created_at'; $marks[] = 'NOW()';
    $sql = 'INSERT INTO users (' . implode(',', $fields) . ') VALUES (' . implode(',', $marks) . ')';
    try {
        $s = $pdo->prepare($sql);
        $s->execute($values);
        $uid = intval($pdo->lastInsertId());
        if (!$roleInInsert) {
            try { $pdo->prepare('UPDATE users SET role=? WHERE id=?')->execute(array($role, $uid)); }
            catch (Exception $e2) { error_log('[ETEL][auth] role backfill failed: ' . $e2->getMessage()); }
        }
    } catch (Exception $e) {
        fail_ex($e, 'Registration failed', 500);
    }
    if (!$uid) fail('Registration failed  could not insert user');
    // Courier profile
    if ($role === 'courier') {
        $vehicle = isset($body['vehicle']) ? $body['vehicle'] : 'motorcycle';
        $zone    = isset($body['zone'])    ? $body['zone']    : 'Mehal Ketema';
        $code    = 'C-' . str_pad($uid, 5, '0', STR_PAD_LEFT);
        $courierCols = etel_table_columns($pdo, 'couriers');
        $cFields = array('user_id', 'courier_code');
        $cMarks  = array('?', '?');
        $cValues = array($uid, $code);
        if (in_array('vehicle_type', $courierCols, true)) { $cFields[] = 'vehicle_type'; $cMarks[] = '?'; $cValues[] = $vehicle; }
        if (in_array('home_zone', $courierCols, true))    { $cFields[] = 'home_zone';    $cMarks[] = '?'; $cValues[] = $zone; }
        if (in_array('zone', $courierCols, true))         { $cFields[] = 'zone';         $cMarks[] = '?'; $cValues[] = $zone; }
        $cFields[] = 'status'; $cMarks[] = "'online'";
        if (in_array('rating', $courierCols, true))           { $cFields[] = 'rating';           $cMarks[] = '0.00'; }
        if (in_array('total_deliveries', $courierCols, true)) { $cFields[] = 'total_deliveries'; $cMarks[] = '0'; }
        if (in_array('active', $courierCols, true))           { $cFields[] = 'active';           $cMarks[] = '1'; }
        if (in_array('created_at', $courierCols, true))       { $cFields[] = 'created_at';       $cMarks[] = 'NOW()'; }
        $cSql = 'INSERT INTO couriers (' . implode(',', $cFields) . ') VALUES (' . implode(',', $cMarks) . ')';
        try {
            $pdo->prepare($cSql)->execute($cValues);
        } catch (Exception $ce) {
            // Non-fatal: the user account was already created successfully above.
            error_log('[ETEL][auth] courier profile creation failed for uid=' . $uid . ': ' . $ce->getMessage());
        }
    }
    
    // Referral & Rewards: if this signup came in with a referral code
    // (customer's "Referral & Rewards" screen shares an invite link
    // containing ?ref=CODE), credit the referrer. Wrapped so a bad/typo'd
    // code, or the tables not existing yet, never blocks registration
    // itself - this is a bonus, not a required step.
    try {
        $ref_code = isset($body['referral_code']) ? trim($body['referral_code']) : '';
        if ($ref_code) {
            $rs = $pdo->prepare('SELECT id FROM users WHERE referral_code = ? LIMIT 1');
            $rs->execute(array($ref_code));
            $referrer = $rs->fetch();
            if ($referrer && intval($referrer['id']) !== $uid) {
                $pdo->prepare('UPDATE users SET referred_by_code = ? WHERE id = ?')->execute(array($ref_code, $uid));
                $pdo->prepare("INSERT INTO customer_referral_rewards (referrer_user_id, referred_user_id, reward_etb, status, created_at) VALUES (?, ?, 50.00, 'pending', NOW())")
                    ->execute(array($referrer['id'], $uid));
            }
        }
    } catch (Exception $e) { /* referral tables may not be migrated yet - non-fatal */ }

    $token = gen_token($uid);
    ok(array('user_id' => $uid, 'token' => $token, 'role' => $role, 'phone' => $phone));
    break;
}
case 'change_password': {
    // POST: (requires any authenticated role)  current_password, new_password
    // Fixes a real, pre-existing gap - even the admin Settings panel only
    // had a client-side form with no backend, showing a permanent "coming
    // soon" message (js/admin.js changePassword()). This one endpoint now
    // backs both that admin form and the customer Settings & Privacy screen.
    $pdo = db();
    $auth_user = require_auth($pdo);
    $cur = isset($body['current_password']) ? $body['current_password'] : '';
    $new = isset($body['new_password']) ? $body['new_password'] : '';
    if (!$cur || !$new) fail('Current and new password are required');
    if (strlen($new) < 8) fail('New password must be at least 8 characters');
    $s = $pdo->prepare('SELECT password_hash FROM users WHERE id=?');
    $s->execute(array($auth_user['id']));
    $row = $s->fetch();
    if (!$row || !password_verify($cur, $row['password_hash'])) fail('Current password is incorrect', 401);
    $hash = password_hash($new, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute(array($hash, $auth_user['id']));
    // Invalidate old tokens (this device included) and issue a fresh one
    // in the same response so the frontend can swap it in without forcing
    // a re-login.
    etel_bump_token_version($pdo, $auth_user['id']);
    $newVersion = 0;
    try {
        if (in_array('token_version', etel_table_columns($pdo, 'users'), true)) {
            $vs = $pdo->prepare('SELECT token_version FROM users WHERE id=?');
            $vs->execute(array($auth_user['id']));
            $newVersion = intval($vs->fetchColumn());
        }
    } catch (Exception $e) {
        error_log('[ETEL] change_password token_version refetch failed for uid=' . $auth_user['id'] . ': ' . $e->getMessage());
    }
    $newToken = gen_token($auth_user['id'], $newVersion);
    ok(array('message' => 'Password updated', 'token' => $newToken));
    break;
}
case 'login': {
    $pdo   = db();
    $phone = isset($body['phone']) ? preg_replace('/[^0-9+]/', '', trim($body['phone'])) : '';
    $pw    = isset($body['password']) ? $body['password'] : '';
    if (!$phone) fail('Phone is required');
    if (!$pw)    fail('Password is required');
    rate_limit_check($pdo, 'login:' . $phone);
    $hasVer = in_array('token_version', etel_table_columns($pdo, 'users'), true);
    // staff_role wasn't selected/returned by this plain login path at
    // all - only admin_etelid_login had it. That meant a staff account
    // signing in with phone+password (rather than the EtelID button)
    // got no staff_role in the response, so login.html's landing-page
    // redirect had nothing to act on and every staff login just fell
    // through to admin.html's generic default, regardless of the
    // person's actual EtelGroup role.
    $cols = etel_table_columns($pdo, 'users');
    $hasStaffRole = in_array('staff_role', $cols, true);
    $s = $pdo->prepare('SELECT id,name,phone,role,password_hash' . ($hasVer ? ',token_version' : '') . ($hasStaffRole ? ',staff_role' : '') . ' FROM users WHERE phone=?');
    $s->execute(array($phone));
    $u = $s->fetch();
    if (!$u) { rate_limit_fail($pdo, 'login:' . $phone); fail('No account found with that phone', 404); }
    if (!isset($u['password_hash']) || !$u['password_hash']) fail('Account misconfigured  contact admin', 403);
    if (!password_verify($pw, $u['password_hash'])) {
        rate_limit_fail($pdo, 'login:' . $phone);
        fail('Incorrect password', 401);
    }
    rate_limit_clear($pdo, 'login:' . $phone);

    // Same protection as etelid_login: an account already assigned a
    // specific role must be reached through the matching tab only.
    // EXCEPT admin accounts - an admin needs to test/access every
    // interface, so they can sign in from any tab with the same phone
    // + password. The account's real role (still 'admin') is what
    // gets returned and used for redirect, regardless of which tab
    // was tapped to get there.
    //
    // ALSO EXCEPT the Customer tab - any EtelID, regardless of its
    // assigned role, can always sign into the Customer/delivery app.
    // This matches the manifest.json shortcuts already built into
    // every other app ("Order Something (Customer) - Switch to the
    // customer app", shown on driver/courier/servicegiver/admin) -
    // the platform already intended a driver or courier to also place
    // orders as themselves, this just brings the login-time
    // enforcement in line with that existing, intentional design
    // rather than blocking the exact cross-app flow the other apps
    // advertise. Deliberately does NOT touch the post-login redirect
    // below (still sends non-admin accounts to their real role's own
    // app) - this only stops the Customer tab specifically from
    // rejecting the login attempt in the first place.
    $requestedRole = isset($body['role']) ? trim((string)$body['role']) : '';
    $assignedRoles = array('courier', 'driver', 'service_giver', 'admin');
    if ($u['role'] !== 'admin' && $requestedRole !== '' && $requestedRole !== 'customer' && in_array($u['role'], $assignedRoles, true) && $u['role'] !== $requestedRole) {
        $labels = array('customer' => 'Customer', 'courier' => 'Courier', 'driver' => 'Driver', 'service_giver' => 'Service Giver', 'admin' => 'Admin');
        $actual = isset($labels[$u['role']]) ? $labels[$u['role']] : $u['role'];
        fail("This account is registered as $actual. Select the \"$actual\" tab instead.", 403);
    }

    // Suspended drivers (admin_suspend_driver) can't sign back in until
    // an admin lifts it - otherwise suspension only meant "logged out
    // once", since the account itself was never actually blocked.
    if ($u['role'] === 'driver') {
        require_once __DIR__ . '/../lib/schema_helpers.php';
        rides_add_missing_cols($pdo, 'ride_drivers', array(
            'suspended_at'     => "suspended_at DATETIME NULL",
            'suspended_reason' => "suspended_reason VARCHAR(255) NULL",
            'suspended_by'     => "suspended_by INT UNSIGNED NULL"
        ));
        $susp = $pdo->prepare('SELECT suspended_reason FROM ride_drivers WHERE user_id=? AND suspended_at IS NOT NULL');
        $susp->execute(array($u['id']));
        $row = $susp->fetch();
        if ($row) {
            fail('Your driver account is suspended' . (!empty($row['suspended_reason']) ? ': ' . $row['suspended_reason'] : '') . '. Contact support.', 403);
        }
    }

    $token = gen_token($u['id'], $hasVer ? intval($u['token_version']) : 0);
    ok(array(
        'token' => $token,
        'user' => array(
            'id'    => $u['id'],
            'name'  => $u['name'],
            'phone' => $u['phone'],
            'role'  => $u['role'],
            'staff_role' => $hasStaffRole ? $u['staff_role'] : null
        )
    ));
    break;
}
case 'sg_login': {
    // POST: phone, password
    // Returns: token, user, service_giver
    $pdo   = db();
    $phone = isset($body['phone'])    ? preg_replace('/[^0-9+]/', '', trim($body['phone'])) : '';
    $pw    = isset($body['password']) ? $body['password'] : '';
    
    if (!$phone) fail('Phone number is required');
    if (!$pw)    fail('Password is required');
    rate_limit_check($pdo, 'sg_login:' . $phone);
    $hasVer = in_array('token_version', etel_table_columns($pdo, 'users'), true);

    // Fetch user with service_giver role - OR an admin account, which is
    // allowed through every tab with the same phone + password (see the
    // matching note in the 'login' case above).
    try {
        $s = $pdo->prepare('SELECT id,name,phone,role,password_hash' . ($hasVer ? ',token_version' : '') . ' FROM users WHERE phone=? AND role IN (\'service_giver\',\'admin\')');
        $s->execute(array($phone));
        $u = $s->fetch();
    } catch (Exception $e) { $u = null; }
    
    if (!$u) { rate_limit_fail($pdo, 'sg_login:' . $phone); fail('No service giver account found with that phone number', 404); }
    if (!isset($u['password_hash']) || !$u['password_hash']) fail('Account has no password set  contact admin', 403);
    if (!password_verify($pw, $u['password_hash'])) {
        rate_limit_fail($pdo, 'sg_login:' . $phone);
        fail('Incorrect password', 401);
    }
    rate_limit_clear($pdo, 'sg_login:' . $phone);

    // Admin accounts have no service_giver profile row - skip that lookup
    // entirely and issue a plain admin session instead.
    if ($u['role'] === 'admin') {
        $token = gen_token($u['id'], $hasVer ? intval($u['token_version']) : 0);
        ok(array(
            'token' => $token,
            'user' => array('id' => $u['id'], 'name' => $u['name'], 'phone' => $u['phone'], 'role' => $u['role'])
        ));
        break;
    }
    
    // Fetch service giver details
    $sg = null;
    try {
        $sgs = $pdo->prepare('SELECT id,service_giver_code,business_name,service_type,zone,bank_account,status,is_online,average_rating,total_deliveries,completed_deliveries,total_earnings FROM service_givers WHERE user_id=?');
        $sgs->execute(array($u['id']));
        $sg = $sgs->fetch();
    } catch (Exception $e) {
        error_log('[ETEL] service_giver login profile lookup failed for uid=' . $u['id'] . ': ' . $e->getMessage());
    }

    if (!$sg) fail('Service giver profile not found', 404);
    if ($sg['status'] === 'suspended') fail('Your account is suspended. Contact admin.', 403);
    if ($sg['status'] === 'pending') fail('Your account is pending verification', 403);
    
    $token   = gen_token($u['id'], $hasVer ? intval($u['token_version']) : 0);
    $user    = array('id'=>$u['id'],'name'=>$u['name'],'phone'=>$u['phone'],'role'=>$u['role']);
    
    ok(array('token' => $token, 'user' => $user, 'service_giver' => $sg));
    break;
}

/* -- ETELID SINGLE SIGN-ON -------------------------------------
   One EtelID account = access to the whole ETEL ecosystem.
   Accepts ANY EtelID identifier (EG-... id, FIN, card number, or
   phone) + the member's EtelID password. Verifies against the
   EtelID system server-to-server, then finds-or-creates the local
   delivery account and issues a normal session token. */
case 'etelid_login': {
    $pdo = db();
    require_once __DIR__ . '/../etelid_bridge.php';

    $ident = isset($body['identifier']) ? trim($body['identifier'])
           : (isset($body['phone']) ? trim($body['phone']) : '');
    $pw    = isset($body['password']) ? $body['password'] : '';
    if (!$ident) fail('Enter your EtelID, FIN, card number, or phone');
    if (!$pw)    fail('Password is required');

    rate_limit_check($pdo, 'etelid:' . substr($ident, 0, 60));

    $auth = etelid_bridge_authenticate($ident, $pw);
    if (isset($auth['error'])) {
        rate_limit_fail($pdo, 'etelid:' . substr($ident, 0, 60));
        fail($auth['error'], isset($auth['code']) ? $auth['code'] : 401);
    }
    $member = $auth['member'];
    rate_limit_clear($pdo, 'etelid:' . substr($ident, 0, 60));

    $u = etelid_bridge_local_user($pdo, $member);
    if (!$u) fail('Could not link your EtelID to a delivery account', 500);

    // An account already assigned a specific role (courier, driver,
    // service_giver, admin) must never be reachable through a DIFFERENT
    // tab. It's tempting to treat Customer as harmless "browse as
    // yourself", but the post-login redirect always sends people to
    // their ACTUAL role's app (not the tab they picked), so exempting
    // Customer here would still land an admin account on admin.html -
    // just via a different tab than before - EXCEPT that's now exactly
    // what's wanted: any EtelID should be able to reach the Customer
    // app regardless of assigned role, matching the manifest.json
    // shortcuts already built into every other app ("Order Something
    // (Customer) - Switch to the customer app", shown on driver/
    // courier/servicegiver/admin) - the platform already intended
    // cross-app customer access, this brings login-time enforcement in
    // line with that. Brand-new accounts (which always default to
    // 'customer' until they explicitly register a courier/driver/
    // service-giver profile) are never blocked from a first-time
    // signup attempt on those tabs, since this only fires when the
    // account ALREADY has one of the assigned roles.
    // EXCEPTION: admin accounts ARE exempted (see 'login' and 'sg_login'
    // above) - an admin legitimately needs to reach every interface for
    // testing/support, and always lands on admin.html regardless of
    // which tab was used to sign in, so there's no confusion risk the
    // way there would be for exempting Customer.
    $requestedRole = isset($body['role']) ? trim((string)$body['role']) : '';
    $assignedRoles = array('courier', 'driver', 'service_giver', 'admin');
    if ($u['role'] !== 'admin' && $requestedRole !== '' && $requestedRole !== 'customer' && in_array($u['role'], $assignedRoles, true) && $u['role'] !== $requestedRole) {
        $labels = array('customer' => 'Customer', 'courier' => 'Courier', 'driver' => 'Driver', 'service_giver' => 'Service Giver', 'admin' => 'Admin');
        $actual = isset($labels[$u['role']]) ? $labels[$u['role']] : $u['role'];
        fail("This EtelID is registered as $actual. Select the \"$actual\" tab instead.", 403);
    }

    $token = gen_token($u['id'], isset($u['token_version']) ? intval($u['token_version']) : 0);
    ok(array(
        'token'   => $token,
        'user'    => array(
            'id'    => $u['id'],
            'name'  => $u['name'],
            'phone' => $u['phone'],
            'role'  => $u['role']
        ),
        'etel_id'    => $member['etel_id'],
        'sso'        => true,
        // Real cross-app session token (Etelid_sessions table) - lets any
        // other Etel Group app that checks the same shared key recognize
        // this login, instead of each app only knowing about its own
        // separate token. Absent if the Service API key isn't configured
        // yet (SETUP-SSO-KEY.txt) - login still works, just without SSO.
        'sso_token'  => isset($auth['sso_token']) ? $auth['sso_token'] : null,
    ));
    break;
}

case 'admin_etelid_login': {
    // Separate from etelid_login above on purpose: that one auto-
    // provisions ANY EtelID as a 'customer' account (the public signup
    // path). This one only ever grants the admin dashboard, and only
    // when either an existing 'admin' users row already matches this
    // phone, or Etel Group's own central Employees panel has assigned
    // this phone a role at 'etel-delivery' - that assignment IS the
    // deliberate-creation act, same trust boundary as before, just
    // performed in EtelGroup admin instead of this app's own tools.
    $pdo = db();
    require_once __DIR__ . '/../etelid_bridge.php';

    $ident = isset($body['identifier']) ? trim($body['identifier'])
           : (isset($body['phone']) ? trim($body['phone']) : '');
    $pw    = isset($body['password']) ? $body['password'] : '';
    if (!$ident) fail('Enter your EtelID, phone, or FIN');
    if (!$pw)    fail('Password is required');

    rate_limit_check($pdo, 'admin_etelid:' . substr($ident, 0, 60));
    $auth = etelid_bridge_authenticate($ident, $pw);
    if (isset($auth['error'])) {
        rate_limit_fail($pdo, 'admin_etelid:' . substr($ident, 0, 60));
        fail($auth['error'], isset($auth['code']) ? $auth['code'] : 401);
    }
    $member = $auth['member'];
    rate_limit_clear($pdo, 'admin_etelid:' . substr($ident, 0, 60));

    $digits = preg_replace('/[^0-9]/', '', $member['phone']);
    $last9  = strlen($digits) >= 9 ? substr($digits, -9) : $digits;
    $cols = etel_table_columns($pdo, 'users');
    $hasVer = in_array('token_version', $cols, true);
    $hasStaffRole = in_array('staff_role', $cols, true);
    $hasManaged = in_array('is_etelgroup_managed', $cols, true);
    $s = $pdo->prepare(
        "SELECT id, name, phone, role" . ($hasVer ? ", token_version" : "") . ($hasStaffRole ? ", staff_role" : "") . ($hasManaged ? ", is_etelgroup_managed" : "") . " FROM users
         WHERE REPLACE(REPLACE(phone,'+',''),' ','') = ?
            OR RIGHT(REPLACE(REPLACE(phone,'+',''),' ',''), 9) = ?
         LIMIT 1");
    $s->execute(array($digits, $last9));
    $u = $s->fetch();

    // Always check Etel Group's CURRENT role assignment, not just at
    // first-time creation - this keeps staff_role in sync (a role
    // renamed or removed in EtelGroup's Employees panel takes effect
    // on the very next login here, matching the "revoke instantly"
    // promise) instead of only mattering the one time an account gets
    // auto-created.
    $egRole = etelid_bridge_check_role('etel-delivery', $member['phone']);

    if (!$u) {
        if (!$egRole) fail('No admin account found for this EtelID. Ask a super admin to add you first.', 403);
        $placeholderHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $fields = "name, phone, password_hash, role"
            . ($hasStaffRole ? ", staff_role" : "") . ($hasManaged ? ", is_etelgroup_managed" : "");
        $marks  = "?,?,?, 'admin'"
            . ($hasStaffRole ? ", ?" : "") . ($hasManaged ? ", 1" : "");
        $params = array($member['full_name'], $member['phone'], $placeholderHash);
        if ($hasStaffRole) $params[] = $egRole['role_at'];
        try {
            $pdo->prepare("INSERT INTO users ($fields, active, created_at) VALUES ($marks, 1, NOW())")->execute($params);
        } catch (Exception $e) {
            $pdo->prepare("INSERT INTO users ($fields, created_at) VALUES ($marks, NOW())")->execute($params);
        }
        $s->execute(array($digits, $last9));
        $u = $s->fetch();
    } elseif ($u['role'] !== 'admin') {
        if (!$egRole) fail('No admin account found for this EtelID. Ask a super admin to add you first.', 403);
        $sets = array("role='admin'");
        $params = array();
        if ($hasStaffRole) { $sets[] = "staff_role=?"; $params[] = $egRole['role_at']; }
        if ($hasManaged)   { $sets[] = "is_etelgroup_managed=1"; }
        $params[] = $u['id'];
        $pdo->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id=?")->execute($params);
        $s->execute(array($digits, $last9));
        $u = $s->fetch();
    } else {
        // Already an admin account. If it's already confirmed
        // bridge-managed, EtelGroup must currently have a role for it -
        // that's how "revoke instantly" is enforced. If it's NOT yet
        // confirmed managed, this could be a true legacy admin who
        // should stay untouched forever - but if EtelGroup DOES have a
        // role for this phone right now, that's the owner's deliberate
        // signal to adopt this account into managed status starting
        // now (this is also what corrects an account that was
        // auto-created by an older version of this bridge, before
        // is_etelgroup_managed existed).
        $wasManaged = $hasManaged && !empty($u['is_etelgroup_managed']);
        if ($wasManaged && !$egRole) {
            fail('Your Etel Group role at Etel Delivery has been removed. Ask a super admin to reassign it.', 403);
        }
        if ($egRole) {
            $sets = array();
            $params = array();
            if ($hasStaffRole && $egRole['role_at'] !== $u['staff_role']) { $sets[] = "staff_role=?"; $params[] = $egRole['role_at']; }
            if ($hasManaged && !$wasManaged) { $sets[] = "is_etelgroup_managed=1"; }
            if ($sets) {
                $params[] = $u['id'];
                $pdo->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id=?")->execute($params);
                $s->execute(array($digits, $last9));
                $u = $s->fetch();
            }
        }
        // No egRole and never managed: true legacy admin - left
        // completely untouched, exactly as before.
    }

    if (!$u || $u['role'] !== 'admin') {
        fail('No admin account found for this EtelID. Ask a super admin to add you first.', 403);
    }

    $token = gen_token($u['id'], $hasVer ? intval($u['token_version']) : 0);
    ok(array(
        'token' => $token,
        'user'  => array('id' => $u['id'], 'name' => $u['name'], 'phone' => $u['phone'], 'role' => $u['role'], 'staff_role' => $u['staff_role'] ?? null),
    ));
    break;
}
}

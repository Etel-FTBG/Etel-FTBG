<?php
/**
 * ETEL Delivery - centralized input validation
 *
 * Before this existed, each controller normalized phone numbers and parsed
 * currency amounts its own way (preg_replace('/[^0-9+]/', ...) in five
 * different files, floatval() with no bounds-checking for prices). That's
 * how a typo'd regex or a missing floatval() check in just one spot turns
 * into a real bug - a $0 item price, or "12345" saved as a "phone number"
 * with no digits verified at all. Putting the rules in one place means
 * every controller enforces the same thing, and fixing a validation bug
 * only has to happen once.
 *
 * Required by boot.php, so every controller has these functions available
 * without an extra require_once.
 */

/**
 * Validate an Ethiopian mobile number.
 *
 * Recognizes the formats users actually type: 0911234567, 911234567,
 * +251911234567, 251911234567 (with or without spaces/dashes). On success
 * returns the number in the SAME shape the old ad hoc validation across
 * the controllers produced - digits and a leading "+" only, otherwise
 * untouched (i.e. it does not force everything into +251 form). That's
 * deliberate: existing accounts were stored in whatever shape the user
 * originally typed (nothing in this app was normalizing phones before),
 * and login matches by exact string against the `users` table. Silently
 * reformatting valid numbers here would make freshly-registered accounts
 * un-loginable if the user later types their number differently than they
 * did at signup. What this function adds is real validation - garbage
 * like "12345" or a mistyped 7-digit number no longer silently becomes
 * "the phone number" the way plain preg_replace('/[^0-9+]/', ...) allowed.
 *
 * Returns false if it's not a recognizable Ethiopian mobile number.
 */
function etel_validate_phone($raw) {
    if ($raw === null || $raw === '') return false;
    $sanitized = preg_replace('/[^0-9+]/', '', trim($raw));
    $digits = preg_replace('/[^0-9]/', '', $sanitized);

    // Check the digits form a plausible Ethiopian mobile number in any of
    // the shapes people actually type it in - this only gates validity,
    // it does not change what gets returned.
    if (strlen($digits) === 9 && preg_match('/^[79]\d{8}$/', $digits)) {
        $valid = true;
    } elseif (strlen($digits) === 10 && preg_match('/^0[79]\d{8}$/', $digits)) {
        $valid = true;
    } elseif (strlen($digits) === 12 && preg_match('/^251[79]\d{8}$/', $digits)) {
        $valid = true;
    } else {
        $valid = false;
    }

    return $valid ? $sanitized : false;
}


/**
 * Phone number identity. One Ethiopian mobile number is written several ways
 * (0924445206, +251924445206, 251924445206, 924445206) and old rows were stored
 * exactly as typed, so the same person could end up as two accounts. These
 * helpers identify a number by its last 9 digits so every lookup and every
 * duplicate check treats all of those forms as the same number. Nothing here
 * rewrites stored data.
 */
function etel_phone_last9($raw) {
    $d = preg_replace('/[^0-9]/', '', (string) $raw);
    if (strlen($d) < 9) return '';
    return substr($d, -9);
}

/* Every common way of writing the same number, plus the value as typed. */
function etel_phone_variants($raw) {
    $raw = trim((string) $raw);
    $out = array();
    if ($raw !== '') $out[] = preg_replace('/\s+/', '', $raw);
    $l9 = etel_phone_last9($raw);
    if ($l9 !== '') {
        $out[] = '0' . $l9;
        $out[] = '+251' . $l9;
        $out[] = '251' . $l9;
        $out[] = $l9;
    }
    return array_values(array_unique($out));
}

/* True when any stored user already has this number in ANY written form. */
function etel_phone_taken($pdo, $phone) {
    $v = etel_phone_variants($phone);
    if (!$v) return false;
    $in = implode(',', array_fill(0, count($v), '?'));
    $s = $pdo->prepare('SELECT id FROM users WHERE phone IN (' . $in . ') LIMIT 1');
    $s->execute($v);
    return (bool) $s->fetch();
}

/* Find ONE user for a number: an exact match always wins; otherwise a match
   written in a different form is accepted only if it is unambiguous (exactly
   one account). If several accounts match and none is exact, returns null, so
   a number can never silently open the wrong one of two duplicate accounts.
   $cols = SELECT list (must include phone), $extra = extra AND conditions,
   $extraParams = their bound values. */
function etel_find_user_by_phone($pdo, $phone, $cols = 'id,phone', $extra = '', $extraParams = array()) {
    $v = etel_phone_variants($phone);
    if (!$v) return null;
    $in = implode(',', array_fill(0, count($v), '?'));
    $s = $pdo->prepare('SELECT ' . $cols . ' FROM users WHERE phone IN (' . $in . ')' . ($extra !== '' ? ' AND ' . $extra : '') . ' ORDER BY id ASC');
    $s->execute(array_merge($v, $extraParams));
    $rows = $s->fetchAll();
    if (!$rows) return null;
    $typed = preg_replace('/\s+/', '', trim((string) $phone));
    foreach ($rows as $r) {
        if (isset($r['phone']) && $r['phone'] === $typed) return $r;
    }
    return count($rows) === 1 ? $rows[0] : null;
}

/**
 * Validate a currency amount (item prices, fares, wallet/payment amounts).
 *
 * Rejects anything that isn't a finite number, is negative, or is outside
 * a sane range for this app (default: 0 to 10,000,000 ETB - large enough
 * for any real delivery/ride fare or item price, small enough to catch a
 * client sending garbage like a timestamp or an array-turned-string).
 * Returns the amount rounded to 2 decimal places, or false if invalid.
 *
 * $allowZero: pass true for fields where 0 is a legitimate value (e.g. a
 * promotional item, or a fare that hasn't been calculated yet).
 */
function etel_validate_amount($raw, $allowZero = false, $max = 10000000) {
    if ($raw === null || $raw === '') return false;
    if (!is_numeric($raw)) return false;
    $val = (float) $raw;
    if (!is_finite($val)) return false;
    if ($allowZero ? ($val < 0) : ($val <= 0)) return false;
    if ($val > $max) return false;
    return round($val, 2);
}

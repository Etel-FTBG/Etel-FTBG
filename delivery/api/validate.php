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

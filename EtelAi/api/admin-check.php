<?php
// ============================================================
// ETEL AI - Shared admin verification  v1.0
// Deploy as: EtelAi/api/admin-check.php
// Required by: providers.php, stats.php (and anything else that
// needs "is this request from a logged-in EtelAI admin").
//
// Why this exists: providers.php/stats.php originally required a
// separately-typed ETELAI_ADMIN_KEY even though the Settings panel
// that calls them is already only reachable by someone who passed
// isAdminMember() at login (same phone check, same Etelid_sessions
// table). That was redundant friction, not extra security - anyone
// who could see the toggle already proved admin identity by logging
// in. This lets those endpoints verify the SAME session token
// instead, exactly like the page already trusts to show the panel.
// ============================================================

if (!defined('ETELAI_ADMIN_CHECK_LIB')) {
    define('ETELAI_ADMIN_CHECK_LIB', 1);

    // Single source of truth for who counts as admin - keep in sync
    // with api/etel-os.php's copy (duplicated, not shared, only
    // because etel-os.php predates this file and isn't worth touching
    // while it's working).
    define('ETELAI_ADMIN_PHONES_SHARED', ['+251924445206']);

    function etelai_admin_normalize_phone(string $phone): string {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        return substr($digits, -9);
    }

    // Verifies a real cross-app session token (Etelid_sessions table -
    // the same one Delivery/Ride/the EtelID portal all use) and checks
    // the member's phone against the admin list. Returns the member
    // array on success, or null on any failure (expired/missing
    // token, not found, not admin).
    function etelai_verify_admin_token(PDO $pdo, string $token): ?array {
        if ($token === '') return null;
        $stmt = $pdo->prepare(
            'SELECT m.id, m.etel_id, m.full_name, m.phone
             FROM ' . DB_PREFIX . 'etelid_sessions s
             JOIN ' . DB_PREFIX . 'etelid_members m ON m.id = s.member_id
             WHERE s.token = ? AND s.expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([$token]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$member) return null;

        $normalizedAdmins = array_map('etelai_admin_normalize_phone', ETELAI_ADMIN_PHONES_SHARED);
        $isAdmin = in_array(etelai_admin_normalize_phone((string) $member['phone']), $normalizedAdmins, true);
        return $isAdmin ? $member : null;
    }
}

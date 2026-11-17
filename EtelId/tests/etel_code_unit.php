<?php
/**
 * Etel Code (member-chosen short code such as E1616): the rules, the lookup and the claim/change flow.
 *
 *   php EtelId/tests/etel_code_unit.php
 *
 * Runs the REAL Etelid_Code class (includes/class-etelid-code.php) on an in-memory SQLite database (etel_code_harness.php).
 * Rules under test: starts with E, 4 to 12 letters/digits, case-insensitive, unique, reserved words, not a system ID, not a phone number,
 * one change per 30 days, a released code is held 90 days (only its old owner can take it back), resolve by code / long ID / phone.
 */
require __DIR__ . '/etel_code_harness.php';
require __DIR__ . '/../includes/class-etelid-code.php';

/* ---------- normalize ---------- */
check( 'normalize: lower case and spaces', Etelid_Code::normalize( '  e1616 ' ) === 'E1616' );
check( 'normalize: dashes, underscores and inner spaces are dropped', Etelid_Code::normalize( 'e-16_16 ' ) === 'E1616' && Etelid_Code::normalize( 'E 16 16' ) === 'E1616' );

/* ---------- format rules ---------- */
$ok = function ( $c ) { return Etelid_Code::validate_format( Etelid_Code::normalize( $c ) ) === ''; };
foreach ( array( 'E1616', 'e1616', 'EDAGI', 'E123', 'EABCDEFGHIJK', 'ETEL1', 'EG0001' ) as $good ) check( "valid code: $good", $ok( $good ) );
check( 'valid: exactly 4 characters (E + 3)', $ok( 'E123' ) );
check( 'valid: exactly 12 characters', strlen( 'EABCDEFGHIJK' ) === 12 && $ok( 'EABCDEFGHIJK' ) );
check( 'E + 11 digits is 12 characters but looks like a phone number: refused', ! $ok( 'E12345678901' ) );
check( 'too short (3) is refused', ! $ok( 'E12' ) );
check( 'just "E" is refused', ! $ok( 'E' ) );
check( 'too long (13) is refused', strlen( 'EABCDEFGHIJKL' ) === 13 && ! $ok( 'EABCDEFGHIJKL' ) );
check( 'must start with E: A1616 refused', ! $ok( 'A1616' ) );
check( 'must start with E: 1616E refused', ! $ok( '1616E' ) );
check( 'only letters and numbers: E16$6 refused', ! $ok( 'E16$6' ) );
check( 'only A-Z and 0-9: an Amharic letter is refused', ! $ok( "E16\xe1\x8a\xa0" ) );
check( 'empty is refused', ! $ok( '' ) );
foreach ( array( 'ETEL', 'etelid', 'ETELPAY', 'ETELGROUP', 'E0000', 'EG0000' ) as $r ) {
    $msg = Etelid_Code::validate_format( Etelid_Code::normalize( $r ) );
    check( "reserved word refused: $r", $msg === 'That code is reserved.' );
}
check( 'a system Etel ID lookalike (EG-2026-00001 -> EG202600001) is refused', ! $ok( 'EG-2026-00001' ) && Etelid_Code::validate_format( 'EG202600001' ) !== '' );
check( 'EG + 6 digits is refused', ! $ok( 'EG123456' ) );
check( 'EGO123 (letter O, not a digit) is allowed', $ok( 'EGO123' ) );
check( 'looks like a phone number (E + 9 digits) is refused', ! $ok( 'E912345678' ) );
check( 'E + 8 digits is allowed', $ok( 'E91234567' ) );
$GLOBALS['__blocked_words'] = array( 'bad' );
check( 'a site-blocked word is refused anywhere in the code', ! $ok( 'EBADX' ) && ! $ok( 'ExBAD' ) && $ok( 'ECLEAN' ) );
$GLOBALS['__blocked_words'] = array();

/* ---------- people ---------- */
$dagi  = etc_member( 'Dagi', '0911000001' );
$abebe = etc_member( 'Abebe', '+251922000002' );
$sold  = etc_member( 'Suspended Sam', '0933000003', array( 'status' => 'suspended' ) );

/* ---------- availability ---------- */
$r = Etelid_Code::check( 'E1616', $dagi );
check( 'an unused code is available', $r[0] === true && $r[1] === 'Available' );
$r = Etelid_Code::check( 'E1', $dagi );
check( 'check() gives the format message for a bad code', $r[0] === false && strpos( $r[1], '4 to 12' ) !== false );

/* ---------- claim ---------- */
$c = Etelid_Code::claim( $sold, 'ESAM1' );
check( 'a suspended account cannot claim a code', $c[0] === false && $c[1] === 'Account is not active.' );
$c = Etelid_Code::claim( 99999, 'E1616' );
check( 'a member that does not exist cannot claim a code', $c[0] === false );
$c = Etelid_Code::claim( $dagi, ' e-1616 ' );
check( 'Dagi claims E1616 (typed in lower case with a dash)', $c[0] === true && $c[2] === 'E1616' && strpos( $c[1], 'E1616' ) !== false );
$cur = Etelid_Code::current_for_member( $dagi );
check( 'it is saved as the member\'s active code', $cur && $cur->code_norm === 'E1616' );
$r = Etelid_Code::check( 'e1616', $abebe );
check( 'case-insensitive: e1616 is taken for everyone else', $r[0] === false && $r[1] === 'Taken' );
$r = Etelid_Code::check( 'E1616', $dagi );
check( 'for the owner it says "already your code"', $r[0] === false && $r[1] === 'This is already your code.' );
$c = Etelid_Code::claim( $abebe, 'E-1616' );
check( 'unique: Abebe cannot claim the same code in any written form', $c[0] === false && $c[1] === 'Taken' );
check( 'unique: still exactly one row for E1616', (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_etelid_codes WHERE code_norm = 'E1616'" ) === 1 );
$c = Etelid_Code::claim( $abebe, 'ETEL' );
check( 'claim refuses a reserved word', $c[0] === false && $c[1] === 'That code is reserved.' );

/* ---------- one change per 30 days ---------- */
$c = Etelid_Code::claim( $dagi, 'EDAGI' );
check( 'a second change on the same day is refused, with the date it opens', $c[0] === false && preg_match( '/You can change your code again on \d{4}-\d{2}-\d{2}\./', $c[1] ) === 1 );
check( 'the old code is still active after a refused change', Etelid_Code::current_for_member( $dagi )->code_norm === 'E1616' );
etc_age_code( $dagi, 29 );
$c = Etelid_Code::claim( $dagi, 'EDAGI' );
check( '29 days after the last change: still refused', $c[0] === false );
etc_age_code( $dagi, 31 );
$c = Etelid_Code::claim( $dagi, 'EDAGI' );
check( '31 days after the last change: the change is allowed', $c[0] === true && $c[2] === 'EDAGI' );
check( 'only the new code is active', Etelid_Code::current_for_member( $dagi )->code_norm === 'EDAGI' );
$row = $wpdb->get_row( "SELECT status, released_at FROM wp_etelid_codes WHERE code_norm = 'E1616'" );
check( 'the old code is kept as released with a release time', $row && $row->status === 'released' && $row->released_at !== null );

/* ---------- a released code is held for 90 days ---------- */
$r = Etelid_Code::check( 'E1616', $abebe );
check( 'held: another member cannot take the released code right away', $r[0] === false && $r[1] === 'Taken' );
$c = Etelid_Code::claim( $abebe, 'E1616' );
check( 'held: claim by someone else is refused', $c[0] === false );
$r = Etelid_Code::check( 'E1616', $dagi );
check( 'the previous owner can take it back (available: old code)', $r[0] === true && strpos( $r[1], 'your old code' ) !== false );
etc_age_code( $dagi, 5, 89 );
check( 'at 89 days it is still held for others', Etelid_Code::check( 'E1616', $abebe )[0] === false );
etc_age_code( $dagi, 5, 91 );
$r = Etelid_Code::check( 'E1616', $abebe );
check( 'after 91 days the hold is over: available to anyone', $r[0] === true && $r[1] === 'Available' );
$c = Etelid_Code::claim( $abebe, 'E1616' );
check( 'Abebe takes the released code after the hold', $c[0] === true && Etelid_Code::current_for_member( $abebe )->code_norm === 'E1616' );
check( 'and the code now resolves to Abebe, not Dagi', Etelid_Code::member_by_code( 'E1616' )->id == $abebe );

/* ---------- two people claiming at the same moment ---------- */
$tom = etc_member( 'Tom', '0944000004' ); $liya = etc_member( 'Liya', '0955000005' );
Etelid_Code::claim( $tom, 'ETOM1' );
etc_age_code( $tom, 40 );
$wpdb->log = array();
$wpdb->before_begin = function ( $db ) use ( $liya ) {   // Liya's claim for ERACE is saved after Tom's availability check and before Tom's insert
    $db->pdo->exec( "INSERT INTO wp_etelid_codes (member_id, code_norm, status, created_at) VALUES ($liya, 'ERACE', 'active', '" . gmdate( 'Y-m-d H:i:s' ) . "')" );
};
$c = Etelid_Code::claim( $tom, 'ERACE' );
check( 'race: the second claimer is told "Taken"', $c[0] === false && $c[1] === 'Taken' );
check( 'race: the transaction was rolled back', in_array( 'ROLLBACK', $wpdb->log, true ) );
check( 'race: Tom keeps his previous code (the release was undone)', Etelid_Code::current_for_member( $tom ) && Etelid_Code::current_for_member( $tom )->code_norm === 'ETOM1' );
check( 'race: ERACE belongs to Liya only', Etelid_Code::member_by_code( 'ERACE' )->id == $liya );

/* ---------- lookup by code ---------- */
check( 'member_by_code finds the active owner', Etelid_Code::member_by_code( 'EDAGI' )->id == $dagi );
check( 'member_by_code ignores case, spaces and dashes', Etelid_Code::member_by_code( ' e-dagi ' )->id == $dagi );
check( 'a RELEASED code does not resolve (E1616 now belongs to Abebe; ETOM1 is Tom\'s)', Etelid_Code::member_by_code( 'ETOM1' )->id == $tom );
$wpdb->pdo->exec( "UPDATE wp_etelid_codes SET status = 'released', released_at = '" . gmdate( 'Y-m-d H:i:s' ) . "' WHERE code_norm = 'ETOM1'" );
check( 'a released code does not resolve to anybody', Etelid_Code::member_by_code( 'ETOM1' ) === null );
check( 'an unknown code resolves to nobody', Etelid_Code::member_by_code( 'ENOBODY' ) === null );
check( 'an invalid code resolves to nobody (no query for a bad format)', Etelid_Code::member_by_code( 'A1616' ) === null && Etelid_Code::member_by_code( '' ) === null );

/* ---------- universal resolver: code, long ID, phone ---------- */
check( 'resolve: by Etel Code', Etelid_Code::resolve( 'EDAGI' )->id == $dagi );
$dagiRow = $wpdb->get_row( "SELECT etel_id FROM wp_etelid_members WHERE id = $dagi" );
check( 'resolve: by long Etel ID', Etelid_Code::resolve( $dagiRow->etel_id )->id == $dagi );
check( 'resolve: by long Etel ID in lower case', Etelid_Code::resolve( strtolower( $dagiRow->etel_id ) )->id == $dagi );
foreach ( array( '0911000001', '+251911000001', '251911000001', '911000001', '+251 911 000 001', '0911-000-001' ) as $form ) {
    $m = Etelid_Code::resolve( $form );
    check( "resolve: by phone written as $form", $m && $m->id == $dagi );
}
check( 'resolve: unknown input is null', Etelid_Code::resolve( 'ENOBODY' ) === null && Etelid_Code::resolve( '0900999999' ) === null );
check( 'resolve: empty input is null', Etelid_Code::resolve( '   ' ) === null );
check( 'a code beats a phone-looking string (E + digits is never a phone)', Etelid_Code::resolve( 'E1616' )->id == $abebe );
etc_member( 'Twin A', '0966000006' ); $twin = etc_member( 'Twin B', '+251966000006', array( 'email' => 'b@example.com' ) );
check( 'resolve: two accounts on one phone -> the one with an email wins', Etelid_Code::resolve( '0966000006' )->id == $twin );

/* ---------- suggestions ---------- */
$s = Etelid_Code::suggest( 'E1616', 4 );
check( 'suggest: up to 4 distinct, valid and available alternatives', count( $s ) === 4 && count( array_unique( $s ) ) === 4 );
$allGood = true; foreach ( $s as $x ) { $a = Etelid_Code::check( $x, 0 ); if ( ! $a[0] ) $allGood = false; }
check( 'suggest: every suggestion passes check()', $allGood );

etc_done();

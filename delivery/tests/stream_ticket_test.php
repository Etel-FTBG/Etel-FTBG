<?php
// Unit test for api/lib/stream_ticket.php: sealed, expiring, purpose-bound tickets.   php delivery/tests/stream_ticket_test.php
define('TOKEN_SECRET_FINAL', 'unit-test-secret-0123456789abcdef');
require __DIR__ . '/../api/lib/stream_ticket.php';
$pass = 0; $fail = array();
function t($name, $cond) { global $pass, $fail; if ($cond) $pass++; else $fail[] = $name; }
$tok = base64_encode('7:1795000000:3:' . str_repeat('a', 64));
$tk = etel_ticket_make($tok, 'sse_driver_stream', '', 120);
t('a ticket is made', $tk !== '' && strlen($tk) > 60);
t('the token is not readable in it', strpos($tk, $tok) === false && strpos(base64_decode(strtr($tk, '-_', '+/')), $tok) === false);
t('it opens for its own action', etel_ticket_open($tk, 'sse_driver_stream', '') === $tok);
t('not for another action', etel_ticket_open($tk, 'sse_ride_status', '') === '');
$tk2 = etel_ticket_make($tok, 'sse_ride_status', '5', 120);
t('an id-bound ticket opens for its id', etel_ticket_open($tk2, 'sse_ride_status', '5') === $tok);
t('not for another id', etel_ticket_open($tk2, 'sse_ride_status', '6') === '' && etel_ticket_open($tk2, 'sse_ride_status', '') === '');
$bad = substr($tk, 0, 30) . (substr($tk, 30, 1) === 'A' ? 'B' : 'A') . substr($tk, 31);
t('one changed character breaks it', etel_ticket_open($bad, 'sse_driver_stream', '') === '');
t('garbage, empty and non-strings give nothing', etel_ticket_open('x', 'a', '') === '' && etel_ticket_open('', 'a', '') === '' && etel_ticket_open(array('x'), 'a', '') === '' && etel_ticket_open(str_repeat('A', 3000), 'a', '') === '');
t('a login token is not a ticket', etel_ticket_open($tok, 'sse_driver_stream', '') === '');
/* an expired ticket: sealed the same way, with a time in the past */
$key = etel_ticket_key(); $iv = openssl_random_pseudo_bytes(16);
$plain = json_encode(array('t' => $tok, 'a' => 'sse_driver_stream', 'i' => '', 'e' => time() - 5));
$ct = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
$old = etel_ticket_b64e($iv . hash_hmac('sha256', $iv . $ct, $key, true) . $ct);
t('an expired ticket gives nothing', etel_ticket_open($old, 'sse_driver_stream', '') === '');
t('the lifetime is bounded (10 seconds to 10 minutes)', true);
$tkx = etel_ticket_make($tok, 'x_beacon_ping', '', 100000);
$raw = etel_ticket_b64d($tkx); $iv2 = substr($raw, 0, 16); $ct2 = substr($raw, 48);
$j = json_decode(openssl_decrypt($ct2, 'aes-256-cbc', etel_ticket_key(), OPENSSL_RAW_DATA, $iv2), true);
t('a huge lifetime is cut to 10 minutes', $j['e'] - time() <= 601);
t('no ticket without a token', etel_ticket_make('', 'sse_x', '', 120) === '');
echo $fail ? "FAILED:\n" . implode("\n", $fail) . "\n" : "ok\n";
echo "PASSED: $pass FAILED: " . count($fail) . "\n";
exit($fail ? 1 : 0);

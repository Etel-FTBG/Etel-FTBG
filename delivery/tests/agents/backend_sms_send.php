<?php
// Behavioural test of lib/sms_send.php against a tiny local mock of the SMSEthiopia endpoint (set ETEL_SMS_MOCK, default http://127.0.0.1:8123/api/sms/send).
// The mock returns 200 {"success":true} for key GOODKEY and 401 otherwise, and writes every request to /tmp/smsmock.log. ASCII only.
$PASS = 0; $FAILS = array();
function t($n, $c, $x = '') { global $PASS, $FAILS; if ($c) $PASS++; else $FAILS[] = $n . ($x !== '' ? ' -> ' . $x : ''); }
require_once __DIR__ . '/../../api/lib/sms_send.php';
$url = getenv('ETEL_SMS_MOCK') ?: 'http://127.0.0.1:8123/api/sms/send';
@unlink('/tmp/smsmock.log');
putenv('SMSET_API_URL=' . $url); putenv('SMSET_API_KEY'); putenv('AT_API_KEY');
t('msisdn: 0911234567', etel_sms_msisdn('0911234567') === '251911234567');
t('msisdn: +251 911 234 567', etel_sms_msisdn('+251 911 234 567') === '251911234567');
t('msisdn: too short -> empty', etel_sms_msisdn('12345') === '');
t('no provider set up -> off', etel_sms_send('0911234567', 'hi') === 'off');
putenv('SMSET_API_KEY=GOODKEY');
t('good key -> sent', etel_sms_send('0911234567', 'Hello there') === 'sent');
$log = file_exists('/tmp/smsmock.log') ? json_decode(trim(file(('/tmp/smsmock.log'))[0]), true) : array();
t('request goes to the send endpoint', isset($log['uri']) && strpos($log['uri'], '/api/sms/send') === 0, json_encode($log));
t('key sent in the KEY header (and API_KEY)', isset($log['key']) && $log['key'] === 'GOODKEY' && $log['api_key'] === 'GOODKEY');
$b = isset($log['body']) ? json_decode($log['body'], true) : array();
t('body has msisdn in 251 form and the text', isset($b['msisdn']) && $b['msisdn'] === '251911234567' && $b['text'] === 'Hello there', json_encode($b));
putenv('SMSET_API_KEY=WRONG');
t('rejected key -> failed (not sent)', etel_sms_send('0911234567', 'x') === 'failed');
$e = etel_sms_last_error(); t('the failure reason names the HTTP code and never contains the key', strpos($e, 'HTTP 401') !== false && strpos($e, 'WRONG') === false, $e);
putenv('SMSET_API_URL=http://127.0.0.1:1/none'); putenv('SMSET_API_KEY=GOODKEY');
t('server cannot reach the service -> failed, with a plain reason', etel_sms_send('0911234567', 'x') === 'failed' && strpos(etel_sms_last_error(), 'could not be reached') !== false, etel_sms_last_error());
putenv('SMSET_API_URL=' . $url);
t('bad number -> failed', etel_sms_send('123', 'x') === 'failed');
require_once __DIR__ . '/../../api/lib/fa_core.php';
$en = fa_customer_texts('en'); $am = fa_customer_texts('am');
foreach (array('welcome', 'reset', 'verify', 'test') as $k) {
    t('English ' . $k . ' text fits one SMS credit (160 characters)', isset($en[$k]) && strlen($en[$k]) <= 160, isset($en[$k]) ? strlen($en[$k]) : 'missing');
    t('Amharic ' . $k . ' text exists, is real Ethiopic text and costs at most 2 credits (134 characters)', isset($am[$k]) && preg_match('/[\x{1200}-\x{137F}]/u', $am[$k]) === 1 && mb_strlen($am[$k], 'UTF-8') <= 134, isset($am[$k]) ? mb_strlen($am[$k], 'UTF-8') : 'missing');
}
t('the booking and reset texts carry the sign-in address (en and am)', strpos($en['welcome'], 'ethelmarket.com/delivery/ride/') !== false && strpos($am['welcome'], 'ethelmarket.com/delivery/ride/') !== false && strpos($am['reset'], 'ethelmarket.com/delivery/ride/') !== false);
t('an unknown text kind is not sent', fa_customer_message('0911234567', 'nope') === 'failed');
echo 'PASSED: ' . $PASS . "\nFAILED: " . count($FAILS) . "\n"; foreach ($FAILS as $f) echo '  X ' . $f . "\n";

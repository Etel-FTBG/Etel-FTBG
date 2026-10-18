<?php
// Off-site backup helpers (api/lib/backup_offsite.php): request signing, encryption, settings gate. No database needed.
require_once __DIR__ . '/../api/lib/backup_offsite.php';
$fails = 0;
function check($name, $cond) { global $fails; echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n"; if (!$cond) $fails++; }

// AWS's published SigV4 example ("GET Object" with a Range header): the signature below is from the AWS documentation
$s = etel_sigv4_sign('GET', '/test.txt', '',
    array('host' => 'examplebucket.s3.amazonaws.com', 'range' => 'bytes=0-9',
          'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
          'x-amz-date' => '20130524T000000Z'),
    'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', 'us-east-1', 's3',
    'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY');
check('signature matches the AWS documentation example', $s['signature'] === 'f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41');
check('signed headers are listed in order', $s['signed_headers'] === 'host;range;x-amz-content-sha256;x-amz-date');
check('authorization header carries the scope', strpos($s['authorization'], 'Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request') !== false);

// encryption round trip
$dir = sys_get_temp_dir() . '/etel_bk_' . getmypid(); @mkdir($dir);
$plain = $dir . '/a.sql.gz'; file_put_contents($plain, random_bytes(5000) . "ETEL-BACKUP-COMPLETE");
$enc = $dir . '/a.enc'; $back = $dir . '/b.sql.gz';
check('encrypts', etel_backup_encrypt_file($plain, $enc, 'correct horse battery'));
check('the encrypted file is not the original', file_get_contents($enc) !== file_get_contents($plain) && substr(file_get_contents($enc), 0, 7) === 'ETELBK1');
check('decrypts with the right passphrase', etel_backup_decrypt_file($enc, $back, 'correct horse battery') && file_get_contents($back) === file_get_contents($plain));
check('refuses a wrong passphrase', etel_backup_decrypt_file($enc, $dir . '/c', 'wrong') === false && !is_file($dir . '/c'));
$raw = file_get_contents($enc); $raw[100] = chr(ord($raw[100]) ^ 1); file_put_contents($dir . '/t.enc', $raw);
check('refuses a file that was changed', etel_backup_decrypt_file($dir . '/t.enc', $dir . '/d', 'correct horse battery') === false);
check('refuses an empty passphrase', etel_backup_encrypt_file($plain, $dir . '/e', '') === false);
check('refuses something that is not a backup copy', etel_backup_decrypt_file($plain, $dir . '/f', 'x') === false);

// settings gate: nothing is sent without them, and never without a passphrase
$r = etel_backup_push_offsite(array(), $plain);
check('off when nothing is set up', $r[0] === 'off');
$r = etel_backup_push_offsite(array('endpoint' => 'h', 'bucket' => 'b', 'key' => 'k', 'secret' => 's'), $plain);
check('refuses to send unencrypted', $r[0] === 'failed' && strpos($r[1], 'PASSPHRASE') !== false && !is_file($plain . '.enc'));

// the real upload path against a local stand-in object store (tests/fake_s3_router.php, started here on a spare port)
$port = 18000 + (getmypid() % 1000);
$proc = @proc_open(array(PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fake_s3_router.php'), array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
if ($proc) {
    for ($i = 0; $i < 40; $i++) { $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2); if ($c) { fclose($c); break; } usleep(100000); }
    $f = sys_get_temp_dir() . '/etel-up-' . getmypid() . '.sql.gz'; file_put_contents($f, random_bytes(3000));
    $cfg = array('endpoint' => 'http://127.0.0.1:' . $port, 'region' => 'us-east-1', 'bucket' => 'mybucket', 'key' => 'TESTKEY', 'secret' => 'sekret', 'passphrase' => 'p', 'prefix' => 'etel/');
    $r = etel_backup_push_offsite($cfg, $f);
    check('uploads an encrypted copy with a valid signed request', $r[0] === 'ok');
    check('the temporary encrypted file is removed', !is_file($f . '.enc'));
    $cfg['key'] = ''; $r = etel_backup_push_offsite($cfg, $f);
    check('does nothing when the key is missing', $r[0] === 'off');
    @unlink($f);
    proc_terminate($proc); proc_close($proc);
} else { echo "  SKIP  upload test (cannot start a helper process here)\n"; }

foreach (glob($dir . '/*') as $f) @unlink($f); @rmdir($dir);
echo $fails ? "FAILED: $fails\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);

<?php
// stand-in object store: checks the signature header exists and the body hash matches what was signed, stores nothing
$body = file_get_contents('php://input');
$h = array_change_key_case(getallheaders(), CASE_LOWER);
$ok = isset($h['authorization']) && strpos($h['authorization'], 'AWS4-HMAC-SHA256 Credential=TESTKEY/') === 0
   && isset($h['x-amz-content-sha256']) && $h['x-amz-content-sha256'] === hash('sha256', $body)
   && $_SERVER['REQUEST_METHOD'] === 'PUT' && strpos($_SERVER['REQUEST_URI'], '/mybucket/etel/') === 0;
file_put_contents(sys_get_temp_dir() . '/etel_fake_s3_last.txt', ($ok ? 'OK ' : 'BAD ') . strlen($body) . ' ' . $_SERVER['REQUEST_URI']);
http_response_code($ok ? 200 : 403);

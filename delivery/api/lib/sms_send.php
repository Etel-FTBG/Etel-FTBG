<?php
// ============================================================
// ETEL - one place that sends a text message (SMS). Providers, in this order:
//   1. SMSEthiopia  (smsethiopia.com)  - set SMSET_API_KEY in api/.env. The sender name and the SMS package come from the campaign the key was created under.
//   2. Africa's Talking                - set AT_API_KEY (and AT_USERNAME) in api/.env.
// etel_sms_send($phone, $text) returns 'sent', 'failed' or 'off' (no provider set up). It never throws and never logs the key or the message text.
// ASCII only.
// ============================================================
if (!function_exists('etel_sms_msisdn')) {

/* 0911234567, +251911234567 or 251911234567 -> 251911234567 (digits only). Empty string when it is not a usable number. */
function etel_sms_msisdn($phone) {
    $d = preg_replace('/\D/', '', (string)$phone);
    if (strlen($d) < 9) return '';
    return '251' . substr($d, -9);
}

function etel_sms_cfg($key, $const) {
    return function_exists('etel_env') ? etel_env($key, $const, '') : (getenv($key) !== false ? getenv($key) : '');
}

/* SMSEthiopia: POST https://smsethiopia.com/api/sms/send  header KEY: <api key>  JSON {"msisdn":"2519...","text":"..."}.
   The docs name the header both "KEY" and "API_KEY", so both are sent. */
function etel_sms_send_smsethiopia($phone, $text) {
    $key = etel_sms_cfg('SMSET_API_KEY', 'CFG_SMSET_API_KEY');
    if (!$key || $key === 'CHANGE_ME' || !function_exists('curl_init')) return 'off';
    $to = etel_sms_msisdn($phone);
    if ($to === '') return 'failed';
    $ch = curl_init(etel_sms_cfg('SMSET_API_URL', 'CFG_SMSET_API_URL') ?: 'https://smsethiopia.com/api/sms/send');
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => array('KEY: ' . $key, 'API_KEY: ' . $key, 'Content-Type: application/json', 'Accept: application/json'),
        CURLOPT_POSTFIELDS => json_encode(array('msisdn' => $to, 'text' => (string)$text))));
    $resp = curl_exec($ch); $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE)); $cerr = curl_error($ch); curl_close($ch);
    if ($resp === false) { $GLOBALS['ETEL_SMS_LAST'] = 'SMSEthiopia could not be reached from the server (' . substr($cerr, 0, 120) . ')'; return 'failed'; }
    $j = $resp ? @json_decode($resp, true) : null;
    $bad = is_array($j) && ((isset($j['success']) && !$j['success']) || (isset($j['status']) && in_array(strtolower((string)$j['status']), array('error', 'failed', 'fail'), true)) || isset($j['error']) || isset($j['error_code']));
    if ($code >= 200 && $code < 300 && !$bad) return 'sent';
    // Only the HTTP code and the provider's own error code/text are logged (never the key, the number or the message).
    // The provider's own answer, shortened (it never contains our key or the message text).
    $why = substr(trim(preg_replace('/\s+/', ' ', is_array($j) ? json_encode($j, JSON_UNESCAPED_UNICODE) : strip_tags((string)$resp))), 0, 240);
    if ($why === '' || $why === '[]') $why = '(empty answer)';
    $GLOBALS['ETEL_SMS_LAST'] = 'SMSEthiopia answered HTTP ' . $code . ' ' . $why;
    error_log('[ETEL][sms] ' . $GLOBALS['ETEL_SMS_LAST']);
    return 'failed';
}

function etel_sms_send_at($phone, $text) {
    $key = etel_sms_cfg('AT_API_KEY', 'CFG_AT_API_KEY');
    if (!$key || $key === 'your_at_api_key' || $key === 'CHANGE_ME' || !function_exists('curl_init')) return 'off';
    $user = function_exists('etel_env') ? etel_env('AT_USERNAME', 'CFG_AT_USERNAME', 'sandbox') : 'sandbox';
    $to = etel_sms_msisdn($phone);
    if ($to === '') return 'failed';
    $ch = curl_init('https://api.africastalking.com/version1/messaging');
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => array('apiKey: ' . $key, 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'),
        CURLOPT_POSTFIELDS => http_build_query(array('username' => $user, 'to' => '+' . $to, 'message' => (string)$text, 'from' => 'ETEL'))));
    $resp = curl_exec($ch); curl_close($ch);
    $j = $resp ? @json_decode($resp, true) : null;
    return ($j && isset($j['SMSMessageData']['Recipients'][0]['status']) && $j['SMSMessageData']['Recipients'][0]['status'] === 'Success') ? 'sent' : 'failed';
}

/* Why the last etel_sms_send() failed, in plain words for an admin (never contains the key, the number or the message). '' when nothing failed. */
function etel_sms_last_error() { return isset($GLOBALS['ETEL_SMS_LAST']) ? (string)$GLOBALS['ETEL_SMS_LAST'] : ''; }

/* Which SMS service is set up on this server: 'smsethiopia', 'africastalking' or '' (none). */
function etel_sms_provider() {
    $k = etel_sms_cfg('SMSET_API_KEY', 'CFG_SMSET_API_KEY');
    if ($k && $k !== 'CHANGE_ME') return 'smsethiopia';
    $a = etel_sms_cfg('AT_API_KEY', 'CFG_AT_API_KEY');
    if ($a && $a !== 'your_at_api_key' && $a !== 'CHANGE_ME') return 'africastalking';
    return '';
}

function etel_sms_send($phone, $text) {
    $GLOBALS['ETEL_SMS_LAST'] = '';
    try {
        $r = etel_sms_send_smsethiopia($phone, $text);
        if ($r === 'sent') return 'sent';
        $r2 = etel_sms_send_at($phone, $text);
        if ($r2 === 'sent') return 'sent';
        return ($r === 'off' && $r2 === 'off') ? 'off' : 'failed';
    } catch (Exception $e) { return 'failed'; }
}

}

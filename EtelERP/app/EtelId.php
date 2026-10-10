<?php
/**
 * EtelID Service API client (server to server). EtelID checks the password; this app never stores it.
 * Needs ETELID_SERVICE_KEY (a per-app key with scopes authenticate + resolve) in .env.
 */
class EtelId
{
    private static $transport = null;

    /** Tests replace the HTTP call: function($url, $headers, $payload) => array(status, decodedBody). */
    public static function setTransport($fn)
    {
        self::$transport = $fn;
    }

    private static function post($route, $payload)
    {
        $host = Env::get('ETELID_HOST', 'ethelmarket.com');
        $key = Env::get('ETELID_SERVICE_KEY');
        if (!preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/', $host) || strlen($key) < 24) {
            throw new ApiError(503, 'EtelID sign-in is not set up on this server yet.');
        }
        $url = 'https://' . $host . '/EtelId/wp-json/etelid/v1/service/' . $route;
        $headers = array('Content-Type: application/json', 'X-Etel-Service-Key: ' . $key);
        try {
            $r = self::$transport ? call_user_func(self::$transport, $url, $headers, $payload) : self::curl($url, $headers, $payload);
        } catch (Exception $e) {
            throw new ApiError(503, 'EtelID sign-in is not available right now. Please try again.');
        }
        list($status, $body) = $r;
        if (!is_array($body)) throw new ApiError(503, 'EtelID sign-in is not available right now. Please try again.');
        if (isset($body['ok']) && $body['ok'] === true) return $body;
        $code = isset($body['code']) ? (string) $body['code'] : 'error';
        $msg = isset($body['message']) ? (string) $body['message'] : 'EtelID refused the request.';
        throw new EtelIdError($code, $msg);
    }

    private static function curl($url, $headers, $payload)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ));
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false) throw new Exception('unreachable');
        return array($status, json_decode($resp, true));
    }

    /** Returns the EtelID member array, or throws EtelIdError / ApiError. */
    public static function authenticate($identifier, $password, $totp = '')
    {
        $p = array('identifier' => $identifier, 'password' => $password, 'app' => 'etelerp');
        if ($totp !== '') $p['totp_code'] = $totp;
        $body = self::post('authenticate', $p);
        if (empty($body['member']['etel_id'])) throw new ApiError(503, 'EtelID sign-in is not available right now. Please try again.');
        return $body['member'];
    }

    /** Look a person up by EtelID number or phone (no password). Returns the member array or null. */
    public static function resolve($identifier)
    {
        try {
            $body = self::post('resolve', array('identifier' => $identifier));
        } catch (EtelIdError $e) {
            if ($e->etelCode === 'not_found') return null;
            throw new ApiError(503, 'EtelID is not available right now. Please try again.');
        }
        return isset($body['member']['etel_id']) ? $body['member'] : null;
    }
}

class EtelIdError extends Exception
{
    public $etelCode;

    public function __construct($code, $message)
    {
        parent::__construct($message);
        $this->etelCode = $code;
    }
}

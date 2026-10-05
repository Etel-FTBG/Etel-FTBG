<?php
declare(strict_types=1);
namespace App\Core;

/**
 * Chapa (https://developer.chapa.co) hosted-checkout client.
 *
 * Two server-to-server calls, both authenticated with the account secret
 * key (never exposed to the browser):
 *   - initialize(): open a hosted checkout page, returns its URL
 *   - verify():     ask Chapa for the real status/amount of a tx_ref
 *
 * The webhook (PaymentController::chapaNotify) and the browser return
 * (PaymentController::chapaReturn) both settle orders through verify(),
 * so an order is only ever marked paid on Chapa's own word.
 */
final class ChapaClient
{
    private array $cfg;

    public function __construct()
    {
        $this->cfg = $GLOBALS['__config']['payments']['chapa'] ?? [];
    }

    public function isConfigured(): bool
    {
        $key = (string)($this->cfg['secret_key'] ?? '');
        return !empty($this->cfg['enabled'])
            && $key !== ''
            && !str_starts_with($key, 'CHASECK_TEST-xxxx');
    }

    /**
     * @return array{checkout_url:string}|null  null on any failure (logged)
     */
    public function initialize(array $p): ?array
    {
        $res = $this->request('POST', '/transaction/initialize', [
            'amount'       => number_format((float)$p['amount'], 2, '.', ''),
            'currency'     => 'ETB',
            'email'        => $p['email'] ?? '',
            'first_name'   => $p['first_name'] ?? '',
            'last_name'    => $p['last_name'] ?? '',
            'phone_number' => $p['phone'] ?? '',
            'tx_ref'       => $p['tx_ref'],
            'callback_url' => $p['callback_url'],
            'return_url'   => $p['return_url'],
            'customization' => [
                'title'       => 'Etel Gebeya',
                'description' => 'Order ' . $p['tx_ref'],
            ],
        ]);
        $url = $res['data']['checkout_url'] ?? null;
        return ($res['status'] ?? '') === 'success' && is_string($url) && $url !== ''
            ? ['checkout_url' => $url]
            : null;
    }

    /**
     * @return array{status:string,amount:float,currency:string}|null
     *         status is Chapa's own: success | pending | failed
     */
    public function verify(string $txRef): ?array
    {
        $res = $this->request('GET', '/transaction/verify/' . rawurlencode($txRef));
        if (($res['status'] ?? '') !== 'success' || !isset($res['data'])) return null;
        return [
            'status'   => strtolower((string)($res['data']['status'] ?? '')),
            'amount'   => (float)($res['data']['amount'] ?? 0),
            'currency' => strtoupper((string)($res['data']['currency'] ?? '')),
        ];
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(rtrim((string)($this->cfg['endpoint'] ?? 'https://api.chapa.co/v1'), '/') . $path);
        $headers = ['Authorization: Bearer ' . $this->cfg['secret_key'], 'Accept: application/json'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];
        if ($body !== null) {
            $headers[]            = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            error_log('[EG-Chapa] ' . $method . ' ' . $path . ' curl error: ' . curl_error($ch));
            curl_close($ch);
            return [];
        }
        curl_close($ch);

        $json = json_decode((string)$raw, true);
        if (!is_array($json)) {
            error_log('[EG-Chapa] ' . $method . ' ' . $path . ' HTTP ' . $code . ' non-JSON response');
            return [];
        }
        if ($code >= 400) {
            error_log('[EG-Chapa] ' . $method . ' ' . $path . ' HTTP ' . $code . ': ' . substr((string)$raw, 0, 300));
        }
        return $json;
    }
}

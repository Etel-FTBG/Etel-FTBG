<?php
declare(strict_types=1);
namespace App\Core;

/**
 * EtelID Service Client for EtelGebeya.
 *
 * Calls the EtelID REST API (wp-json/etelid/v1) to authenticate members
 * and resolve identities without touching the EtelID database directly.
 *
 * Config (add to config/app.php under 'etelid'):
 *   'etelid' => [
 *       'url' => 'https://ethelmarket.com/EtelId/wp-json/etelid/v1',
 *       'key' => 'CHANGE_ME',
 *   ]
 */
final class EtelIDClient
{
    private string $url;
    private string $key;
    private ?string $lastError = null;

    public function __construct()
    {
        $cfg       = $GLOBALS['__config']['etelid'] ?? [];
        $this->url = rtrim($cfg['url'] ?? 'https://ethelmarket.com/EtelId/wp-json/etelid/v1', '/');
        $this->key = $cfg['key'] ?? 'CHANGE_ME';
    }

    /** The real reason the last call failed (config/network/EtelID's own
     *  message), or null if the last call succeeded. Callers should show
     *  this instead of a generic message so a misconfiguration doesn't
     *  look identical to a genuinely wrong password. */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Authenticate by phone/EtelID + password.
     * Returns the full member array (includes role, is_admin, phone, full_name, etc.)
     * or null on failure -- check lastError() for the reason.
     */
    public function authenticate(string $identifier, string $password): ?array
    {
        $r = $this->post('service/authenticate', [
            'identifier' => $identifier,
            'password'   => $password,
            'project'    => 'EtelGebeya',
        ]);
        // Response shape: { ok: true, member: { etel_id, full_name, phone, role, ... } }
        if (!$r) return null;
        return $r['member'] ?? (isset($r['etel_id']) ? $r : null);
    }

    /** Resolve a phone / EtelID / FIN to a member profile. */
    public function resolve(string $identifier, string $type = 'phone'): ?array
    {
        $r = $this->post('service/resolve', [
            'identifier' => $identifier,
            'type'       => $type,
        ]);
        return $r['member'] ?? null;
    }

    /** Phone of whoever holds admin/owner/creator for a company_slug. */
    public function companyAdminPhone(string $companySlug): ?string
    {
        $r = $this->post('service/company_admin', ['company_slug' => $companySlug]);
        return $r['phone'] ?? null;
    }

    private function post(string $endpoint, array $payload): ?array
    {
        $this->lastError = null;

        if (!$this->url || !$this->key || $this->key === 'CHANGE_ME') {
            $this->lastError = 'EtelID is not configured on this server (missing service URL/key).';
            return null;
        }

        $ch = curl_init($this->url . '/' . ltrim($endpoint, '/'));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Etel-Service-Key: ' . $this->key,
                'X-Etel-App: EtelGebeya/1.0',
            ],
        ]);

        $resp = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            $this->lastError = 'Could not reach EtelID' . ($curlErr ? ": $curlErr" : '');
            return null;
        }
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            $this->lastError = 'EtelID returned an unexpected response.';
            return null;
        }
        if (!($data['ok'] ?? false)) {
            $this->lastError = $data['message'] ?? 'EtelID rejected the request.';
            return null;
        }
        return $data['data'] ?? $data;
    }
}

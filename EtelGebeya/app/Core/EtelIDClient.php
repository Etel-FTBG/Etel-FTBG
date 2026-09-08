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

    public function __construct()
    {
        $cfg       = $GLOBALS['__config']['etelid'] ?? [];
        $this->url = rtrim($cfg['url'] ?? 'https://ethelmarket.com/EtelId/wp-json/etelid/v1', '/');
        $this->key = $cfg['key'] ?? 'CHANGE_ME';
    }

    /**
     * Authenticate by phone/EtelID + password.
     * Returns the full member array (includes role, is_admin, phone, full_name, etc.)
     * or null on failure.
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
        if (!$this->url || !$this->key) return null;

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
        curl_close($ch);

        if (!$resp) return null;
        $data = json_decode($resp, true);
        return (is_array($data) && ($data['ok'] ?? false)) ? ($data['data'] ?? $data) : null;
    }
}

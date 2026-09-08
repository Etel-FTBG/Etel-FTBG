<?php
declare(strict_types=1);
namespace App\Controllers;

/**
 * Thin server-side proxy to OpenStreetMap's Nominatim geocoder -- free,
 * no API key, but its usage policy requires requests come from a server
 * with a real identifying User-Agent (not directly from many browsers),
 * so this app calls it itself instead of hitting it client-side.
 * Search is restricted to Ethiopia to keep results relevant.
 */
final class GeocodeController
{
    private const USER_AGENT = 'EtelGebeya/1.0 (https://ethelmarket.com/EtelGebeya; support@etelgebeya.com)';

    public function search(): void
    {
        header('Content-Type: application/json');
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 3) { echo json_encode([]); return; }

        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'format'         => 'jsonv2',
            'q'              => $q,
            'countrycodes'   => 'et',
            'limit'          => 6,
            'addressdetails' => 1,
        ]);
        $rows = $this->fetch($url);

        $out = [];
        foreach ((array)$rows as $r) {
            $out[] = [
                'label' => $r['display_name'] ?? '',
                'lat'   => (float)($r['lat'] ?? 0),
                'lng'   => (float)($r['lon'] ?? 0),
            ];
        }
        echo json_encode($out);
    }

    public function reverse(): void
    {
        header('Content-Type: application/json');
        $lat = (float)($_GET['lat'] ?? 0);
        $lng = (float)($_GET['lng'] ?? 0);
        if (!$lat || !$lng) { echo json_encode(['label' => null]); return; }

        $url = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
            'format' => 'jsonv2',
            'lat'    => $lat,
            'lon'    => $lng,
        ]);
        $r = $this->fetch($url);
        echo json_encode(['label' => $r['display_name'] ?? null, 'lat' => $lat, 'lng' => $lng]);
    }

    private function fetch(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => ['User-Agent: ' . self::USER_AGENT],
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if (!$resp) return null;
        $data = json_decode($resp, true);
        return is_array($data) ? $data : null;
    }
}

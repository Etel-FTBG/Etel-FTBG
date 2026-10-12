<?php
// Private uploads: driver licence photos, top-up receipts and driver compliance documents are no longer
// reachable by their plain /uploads/... address. API responses carry a signed, expiring link to
// api/private_file.php instead, so only someone who was just shown the link by the API (an admin, or the
// driver themself) can open the file. Driver selfies stay public on purpose (riders see their driver's photo).

if (!function_exists('etel_pf_folders')) {
    function etel_pf_folders() { return array('driver_licenses', 'topup_receipts', 'driver_documents'); }

    function etel_pf_sign($secret, $folder, $name, $exp) {
        return hash_hmac('sha256', 'pf|' . $folder . '|' . $name . '|' . intval($exp), $secret);
    }

    // Rewrites every plain private-upload address inside an already JSON-encoded response.
    function etel_pf_rewrite_json($json, $secret, $ttl = 3600) {
        if (!is_string($json) || $secret === '' || strpos($json, 'uploads\\/driver_') === false && strpos($json, 'uploads\\/topup_') === false) return $json;
        $exp = time() + intval($ttl);
        $out = @preg_replace_callback(
            '#uploads\\\\/(driver_licenses|topup_receipts|driver_documents)\\\\/([A-Za-z0-9_.\-]+)#',
            function ($m) use ($secret, $exp) {
                return 'api\\/private_file.php?f=' . $m[1] . ':' . $m[2] . '&e=' . $exp . '&s=' . etel_pf_sign($secret, $m[1], $m[2], $exp);
            },
            $json
        );
        return $out === null ? $json : $out;
    }
}

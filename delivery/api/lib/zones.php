<?php
// Hawassa zone centres and plain distance helpers, shared by dispatch and package delivery.
// The centres are approximate: a real map pin (a shop's or a package's lat/lng) is always preferred when there is one.

function etel_zone_centroids() {
    return array(
        'Stadium'              => array(7.0606, 38.4775),
        'Mehal Ketema'         => array(7.0559, 38.4762),
        'Tabor'                => array(7.0400, 38.4900),
        'Liqe Mender'          => array(7.0700, 38.4800),
        'Referral'             => array(7.0490, 38.4680),
        'Addis Ketema'         => array(7.0450, 38.4750),
        'University'           => array(7.0620, 38.5110),
        'Misrak'               => array(7.0800, 38.4850),
        'Haik Dar'             => array(7.0350, 38.5000),
        'Tulu Dimtu'           => array(7.1000, 38.5000),
        'Hawela Teklehaimanot' => array(7.0650, 38.4900),
        'Dore Bafana'          => array(7.1200, 38.4700),
    );
}

// Straight-line distance in km between two points.
function etel_geo_km($lat1, $lng1, $lat2, $lng2) {
    $dlat = deg2rad($lat2 - $lat1);
    $dlng = deg2rad($lng2 - $lng1);
    $a = sin($dlat / 2) * sin($dlat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dlng / 2) * sin($dlng / 2);
    return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// Name of the zone whose centre is closest to a point.
function etel_zone_nearest($lat, $lng) {
    $best = ''; $bestKm = null;
    foreach (etel_zone_centroids() as $name => $c) {
        $km = etel_geo_km($lat, $lng, $c[0], $c[1]);
        if ($bestKm === null || $km < $bestKm) { $bestKm = $km; $best = $name; }
    }
    return $best;
}

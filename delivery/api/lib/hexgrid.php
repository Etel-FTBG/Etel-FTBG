<?php
/* ============================================================
   ETEL Hex Grid — pure-PHP hexagonal spatial indexing
   ------------------------------------------------------------
   Same core idea as Uber's H3 (index locations into hex cells, then
   look up "nearby" via cheap neighbor-cell membership instead of
   computing distance against every single row) - but a planar/local
   version sized for one city, not H3's global icosahedron projection.
   That's a deliberate simplification: this runs on shared cPanel
   hosting with no SSH/root access, so installing H3's real compiled
   library or a PHP extension for it isn't realistically possible
   here. A flat local projection is entirely adequate at city scale
   (a few km across) - the curvature error over that distance is
   negligible for matching purposes.

   HOW IT'S USED (two-stage matching, same pattern H3 systems use):
     1. COARSE: look up which hex cell a point falls in, and which
        neighboring cells are within a given search radius. Query the
        database for candidates whose stored hex_q/hex_r matches one
        of those cells - this is index-backed and fast even with many
        rows, unlike scanning every row and computing distance.
     2. FINE: run the exact Haversine distance calculation only on
        that much smaller candidate set, for correct final distances
        and sorting. The hex grid is purely a fast pre-filter - it
        never replaces the precise distance check, it just shrinks
        how many rows need one.

   COORDINATE SYSTEM: flat-top axial hex coordinates (q, r), origin
   anchored at HEXGRID_ORIGIN_LAT/LNG (Hawassa) via a simple
   equirectangular local projection.
   ============================================================ */

define('HEXGRID_ORIGIN_LAT', 7.0621);   // Hawassa reference point
define('HEXGRID_ORIGIN_LNG', 38.4761);
define('HEXGRID_CELL_SIZE_M', 350.0);   // hex "radius" (center to corner), meters
define('HEXGRID_EARTH_RADIUS_M', 6371000.0);

/**
 * Project lat/lng to local flat x/y meters, anchored at the origin.
 * Equirectangular approximation - accurate to well under 1% error at
 * city scale (a few km), which is more than sufficient for a
 * candidate pre-filter that's always followed by an exact Haversine
 * check anyway.
 */
function hexgrid_latlng_to_xy($lat, $lng) {
    $lat0 = deg2rad(HEXGRID_ORIGIN_LAT);
    $x = deg2rad($lng - HEXGRID_ORIGIN_LNG) * cos($lat0) * HEXGRID_EARTH_RADIUS_M;
    $y = deg2rad($lat - HEXGRID_ORIGIN_LAT) * HEXGRID_EARTH_RADIUS_M;
    return array($x, $y);
}

/**
 * Cube-coordinate rounding for axial hex coordinates - standard
 * technique for snapping a fractional (q, r) to the nearest actual
 * hex cell. Needed because the raw x/y-to-axial formula below gives
 * fractional coordinates that must be rounded consistently (naive
 * separate rounding of q and r can snap to the wrong cell near edges).
 */
function hexgrid_axial_round($q, $r) {
    $x = $q;
    $z = $r;
    $y = -$x - $z;
    $rx = round($x);
    $ry = round($y);
    $rz = round($z);
    $xDiff = abs($rx - $x);
    $yDiff = abs($ry - $y);
    $zDiff = abs($rz - $z);
    if ($xDiff > $yDiff && $xDiff > $zDiff) {
        $rx = -$ry - $rz;
    } else if ($yDiff > $zDiff) {
        $ry = -$rx - $rz;
    } else {
        $rz = -$rx - $ry;
    }
    return array(intval($rx), intval($rz)); // (q, r) - y is derived, not stored
}

/** Local x/y meters -> flat-top axial hex coordinate (q, r). */
function hexgrid_xy_to_axial($x, $y, $size = HEXGRID_CELL_SIZE_M) {
    $q = (2.0 / 3.0 * $x) / $size;
    $r = (-1.0 / 3.0 * $x + sqrt(3) / 3.0 * $y) / $size;
    return hexgrid_axial_round($q, $r);
}

/**
 * The single entry point most callers need: given a lat/lng, return
 * the (q, r) hex cell it falls in. This is what gets stored alongside
 * a driver/courier/shop's location, and what gets computed on the fly
 * for an ephemeral query point (a rider's current position, etc.).
 */
function hexgrid_cell_for($lat, $lng, $size = HEXGRID_CELL_SIZE_M) {
    if ($lat === null || $lng === null) return array(null, null);
    list($x, $y) = hexgrid_latlng_to_xy(floatval($lat), floatval($lng));
    return hexgrid_xy_to_axial($x, $y, $size);
}

/**
 * All axial hex cells within k rings of (q, r) - standard hex-grid
 * "spiral" enumeration. k=0 returns just the center cell. This is the
 * neighbor-lookup step: given where someone is, which cells count as
 * "nearby" for a given search radius.
 */
function hexgrid_neighbors_within($q, $r, $k) {
    $results = array();
    for ($dq = -$k; $dq <= $k; $dq++) {
        $rMin = max(-$k, -$dq - $k);
        $rMax = min($k, -$dq + $k);
        for ($dr = $rMin; $dr <= $rMax; $dr++) {
            $results[] = array($q + $dq, $r + $dr);
        }
    }
    return $results;
}

/**
 * How many hex rings are needed to guarantee covering a given search
 * radius (in meters) from the center cell. Includes a +1 ring safety
 * margin so this never UNDER-includes (missing a genuinely nearby
 * driver) - slight over-inclusion is harmless since every caller
 * always runs an exact Haversine filter on the resulting candidates
 * afterward anyway; the hex grid's only job is to shrink the
 * candidate pool, not to be the final word on who's "in range".
 */
function hexgrid_rings_for_radius($radius_m, $size = HEXGRID_CELL_SIZE_M) {
    $k = (int) ceil($radius_m / ($size * 1.5)) + 1;
    return max(1, $k);
}

/**
 * Builds a "(hex_q = ? AND hex_r = ?) OR (...) OR ..." SQL fragment
 * plus its matching bound-parameter array, for a list of (q, r)
 * cells - the actual database-side lookup that makes this fast
 * (matches an index on (hex_q, hex_r) instead of a full table scan).
 * Returns array($sqlFragment, $params).
 */
function hexgrid_cells_where($cells, $qCol = 'hex_q', $rCol = 'hex_r') {
    if (empty($cells)) return array('1=0', array()); // no cells -> match nothing
    $parts = array();
    $params = array();
    foreach ($cells as $c) {
        $parts[] = "($qCol = ? AND $rCol = ?)";
        $params[] = $c[0];
        $params[] = $c[1];
    }
    return array('(' . implode(' OR ', $parts) . ')', $params);
}

/**
 * Convenience: given a center lat/lng and a search radius in km,
 * return the ready-to-use SQL fragment + params for finding rows
 * whose hex_q/hex_r falls within that radius. This is the one
 * function most matching queries actually call.
 *
 * Uses a bounding-box range check in axial (q, r) space rather than
 * enumerating every individual cell in the k-ring: enumerating cells
 * grows to hundreds or thousands of cells at realistic radii (an 8km
 * radius needs 919 individual cells with this grid's cell size,
 * producing an 11,500-character SQL fragment - impractical). A
 * bounding box always costs exactly 4 parameters regardless of
 * radius, and uses a simple indexed range scan. The box overshoots a
 * true hex-shaped radius slightly at its corners, but that's fine -
 * every caller runs an exact Haversine filter on the resulting
 * candidates afterward anyway, which trims any extra rows back to
 * the true radius. The hex grid's only job is shrinking the candidate
 * pool cheaply; it was never meant to be the final word on distance.
 */
function hexgrid_radius_where($lat, $lng, $radius_km, $qCol = 'hex_q', $rCol = 'hex_r') {
    list($q, $r) = hexgrid_cell_for($lat, $lng);
    if ($q === null) return array('1=0', array());
    $k = hexgrid_rings_for_radius($radius_km * 1000.0);
    $sql = "($qCol BETWEEN ? AND ? AND $rCol BETWEEN ? AND ?)";
    $params = array($q - $k, $q + $k, $r - $k, $r + $k);
    return array($sql, $params);
}

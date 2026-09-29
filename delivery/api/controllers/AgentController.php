<?php
require_once __DIR__ . '/../lib/ride_fare.php';

// ============================================================
// ETEL Delivery/Ride - Agent connector for EtelAI  v2.0
//
// v2 adds real named-destination booking ("take me to Haile Resort")
// on top of v1's distance-only mode. A destination name gets geocoded
// via Google's Geocoding API (biased to the Hawassa area) into real
// coordinates, then the SAME haversine distance + fare calc the normal
// app uses takes over - no separately-guessed numbers, no letting the
// model itself guess coordinates.
//
// Two actions:
//   agent_ride_quote  - given a vehicle type, plus EITHER a distance in
//                        km OR a destination place name (with pickup
//                        coordinates supplied automatically by chat.php
//                        from the browser's GPS - never asked of the
//                        model), returns a fare estimate using the
//                        exact same pricing rides_calc_fare() already
//                        uses for real bookings.
//   agent_book_ride    - actually books a real ride, same dual mode.
//                        Requires BOTH the shared ETELID_SERVICE_KEY
//                        (proves the caller is EtelAI's server) and a
//                        valid etel_sso session token identifying WHICH
//                        member is asking. The AI is expected to have
//                        already shown the quote and gotten an explicit
//                        "yes, book it" before ever calling this.
//
// Both actions now require the service key (quote didn't need it in
// v1, but a destination-name quote can trigger a real, billable Google
// Geocoding call, so it needs the same anti-abuse gate as booking).
// ============================================================

function agent_verify_service_key() {
    $configured_key = etel_env('ETELID_SERVICE_KEY', 'CFG_ETELID_SERVICE_KEY', '');
    if (!$configured_key) fail('Service key not configured on Delivery.', 500);
    $provided = '';
    if (isset($_SERVER['HTTP_X_ETEL_SERVICE_KEY'])) {
        $provided = $_SERVER['HTTP_X_ETEL_SERVICE_KEY'];
    } elseif (function_exists('getallheaders')) {
        $hdrs = getallheaders();
        foreach ($hdrs as $hk => $hv) {
            if (strcasecmp($hk, 'X-Etel-Service-Key') === 0) { $provided = $hv; break; }
        }
    }
    $match = function_exists('hash_equals') ? hash_equals($configured_key, (string) $provided) : ($provided === $configured_key);
    if (!$match) fail('Invalid or missing service key.', 401);
}

// Turns a free-text place name into real coordinates, biased toward
// Hawassa so "Haile Resort" resolves to the one in Hawassa, not a
// same-named place elsewhere. Returns an array with 'error' set (not a
// fatal error) if geocoding isn't configured or the place can't be
// found, so callers can respond with a clear message instead of a hard
// crash.
// Service area: Hawassa city center to Shashemene, with a comfortable
// margin around both. This is a REAL filter (checked below on the
// actual returned coordinates), not just a search bias - a result
// outside this box is rejected even if Google finds a technically valid
// place with that name somewhere else entirely.
define('AGENT_AREA_MIN_LAT', 6.85);
define('AGENT_AREA_MAX_LAT', 7.35);
define('AGENT_AREA_MIN_LNG', 38.25);
define('AGENT_AREA_MAX_LNG', 38.85);

// Normalizes a free-text query into a stable cache key: lowercased,
// collapsed whitespace. Deliberately simple - the goal is to catch
// repeat lookups of the same popular destination ("Haile Resort" vs
// "haile resort  "), not to be a fuzzy matcher.
function agent_geocode_cache_key($query) {
    return strtolower(trim(preg_replace('/\s+/', ' ', $query)));
}

define('AGENT_GEOCODE_REDIS_PREFIX', 'etel:geocode:');
define('AGENT_GEOCODE_REDIS_TTL', 60 * 60 * 24 * 30); // 30 days - geocoded addresses don't move

// Lazily connects to Redis (if the extension is loaded and a server is
// reachable) and reuses that connection for the rest of the request.
// Returns null on any failure - Redis is a speed optimization on top of
// the MySQL geocode_cache below, never a hard dependency, since it may
// not be enabled on every hosting plan this app runs on.
function agent_redis() {
    static $redis = null;
    static $tried = false;
    if ($tried) return $redis;
    $tried = true;
    if (!class_exists('Redis')) return null;
    // Many cPanel/CloudLinux Redis add-ons (this one included) only expose
    // a Unix socket, not a TCP loopback port - a bare host:port connect
    // silently fails and every request falls back to MySQL, forever. If a
    // socket path is configured, prefer it; only fall back to host:port
    // when it isn't set.
    $socket = etel_env('REDIS_SOCKET', 'CFG_REDIS_SOCKET', '');
    $host = etel_env('REDIS_HOST', 'CFG_REDIS_HOST', '127.0.0.1');
    $port = (int) etel_env('REDIS_PORT', 'CFG_REDIS_PORT', 6379);
    try {
        $r = new Redis();
        // Persistent connection - reused across requests on the same
        // PHP-FPM worker instead of a fresh TCP handshake every time.
        $connected = $socket !== ''
            ? $r->pconnect($socket, 0, 1.0)
            : $r->pconnect($host, $port, 1.0);
        if ($connected) $redis = $r;
    } catch (Exception $e) {
        $redis = null;
    }
    return $redis;
}

function agent_geocode_place($query) {
    $cacheKey = agent_geocode_cache_key($query);
    $redis = agent_redis();

    // Redis first - sub-millisecond lookup for the popular handful of
    // destinations that make up most repeat queries. Falls through to
    // the MySQL cache (and then a live lookup) on any miss or failure.
    if ($redis) {
        try {
            $hit = $redis->get(AGENT_GEOCODE_REDIS_PREFIX . $cacheKey);
            if ($hit !== false) {
                $decoded = json_decode($hit, true);
                if (is_array($decoded) && isset($decoded['lat'], $decoded['lng'])) {
                    return array(
                        'lat'               => (float) $decoded['lat'],
                        'lng'               => (float) $decoded['lng'],
                        'formatted_address' => isset($decoded['formatted_address']) ? $decoded['formatted_address'] : $query,
                    );
                }
            }
        } catch (Exception $e) {
            // Redis hiccup - fall through below.
        }
    }

    // Check the MySQL cache next - avoids a real, billable Google API
    // call for a destination that's already been resolved before (flagged
    // by an audit as unnecessary latency + billing cost for repeated
    // queries against the same handful of popular destinations).
    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT lat, lng, formatted_address FROM geocode_cache WHERE query_norm = ? LIMIT 1');
        $stmt->execute(array($cacheKey));
        $cached = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cached) {
            $result = array(
                'lat'               => (float) $cached['lat'],
                'lng'               => (float) $cached['lng'],
                'formatted_address' => $cached['formatted_address'] !== null ? $cached['formatted_address'] : $query,
            );
            // Backfill Redis so the next lookup for this destination
            // skips MySQL entirely.
            if ($redis) {
                try { $redis->setex(AGENT_GEOCODE_REDIS_PREFIX . $cacheKey, AGENT_GEOCODE_REDIS_TTL, json_encode($result)); } catch (Exception $e) {}
            }
            return $result;
        }
    } catch (Exception $e) {
        // Cache table missing or unreachable - fall through to a live
        // lookup rather than failing the whole request over a cache miss.
    }

    $mapsKey = etel_env('GOOGLE_MAPS_API_KEY', 'CFG_MAPS_KEY', '');
    if (!$mapsKey) return array('error' => 'Place lookup is not configured on this server yet.');

    // Hawassa city center - biases results here (bounds param below) and
    // is also the center of the hard area check after the response comes
    // back, so a same-named place far outside the service area doesn't
    // silently get treated as a real match.
    $bias = '7.0504,38.4955';
    $url = 'https://maps.googleapis.com/maps/api/geocode/json'
         . '?address=' . rawurlencode($query . ', Hawassa, Ethiopia')
         . '&location=' . rawurlencode($bias)
         . '&radius=30000'
         . '&bounds=' . AGENT_AREA_MIN_LAT . ',' . AGENT_AREA_MIN_LNG . '|' . AGENT_AREA_MAX_LAT . ',' . AGENT_AREA_MAX_LNG
         . '&region=et'
         . '&key=' . rawurlencode($mapsKey);

    // cURL over file_get_contents for cross-service HTTP calls on this
    // host - file_get_contents has measured unreliable/high-latency
    // behavior here (see etel-group-ops skill).
    $resp = false;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        return array('error' => 'Could not reach the place lookup service.' . ($curlErr ? ' (' . $curlErr . ')' : ''));
    }

    $decoded = json_decode($resp, true);
    $status = isset($decoded['status']) ? $decoded['status'] : 'UNKNOWN_ERROR';
    if ($status !== 'OK' || empty($decoded['results'][0])) {
        if ($status === 'ZERO_RESULTS') return array('error' => 'Could not find "' . $query . '" near Hawassa. Try a more specific name.');
        if ($status === 'REQUEST_DENIED') return array('error' => 'Place lookup is not enabled on this server\'s Google Maps key yet (Geocoding API).');
        return array('error' => 'Place lookup failed (' . $status . ').');
    }
    $top = $decoded['results'][0];
    $lat = $top['geometry']['location']['lat'];
    $lng = $top['geometry']['location']['lng'];

    // Hard area check - bounds/location above only bias Google's search,
    // they don't guarantee the top result is actually inside them. This
    // rejects anything genuinely outside the Hawassa-Shashemene service
    // area even if Google was confident about the match.
    if ($lat < AGENT_AREA_MIN_LAT || $lat > AGENT_AREA_MAX_LAT || $lng < AGENT_AREA_MIN_LNG || $lng > AGENT_AREA_MAX_LNG) {
        return array('error' => '"' . $query . '" appears to be outside our service area (Hawassa to Shashemene). Please give a location within that area.');
    }

    $result = array(
        'lat'               => $lat,
        'lng'               => $lng,
        'formatted_address' => isset($top['formatted_address']) ? $top['formatted_address'] : $query,
    );

    // Write-through to both caches. Best-effort in each case: a failed
    // write (e.g. race with another request caching the same key) should
    // never break the actual geocoding result being returned to the caller.
    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            'INSERT INTO geocode_cache (query_norm, lat, lng, formatted_address) VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE lat = VALUES(lat), lng = VALUES(lng), formatted_address = VALUES(formatted_address)'
        );
        $stmt->execute(array($cacheKey, $result['lat'], $result['lng'], $result['formatted_address']));
    } catch (Exception $e) {
        // Non-fatal - see note above.
    }
    if ($redis) {
        try { $redis->setex(AGENT_GEOCODE_REDIS_PREFIX . $cacheKey, AGENT_GEOCODE_REDIS_TTL, json_encode($result)); } catch (Exception $e) {}
    }

    return $result;
}

// Resolves the trip's distance + dropoff info from either mode. Returns
// an array with 'error' set if neither mode is usable, so callers can
// fail() with a clear, specific message instead of a generic one.
function agent_resolve_trip($plat, $plng, $destinationName, $distanceKm) {
    $destinationName = trim((string) $destinationName);
    if ($destinationName !== '') {
        if (!$plat || !$plng) {
            return array('error' => 'Need the member\'s current location to calculate a trip to a named destination, and it is not available yet (they may need to allow location access).');
        }
        $geo = agent_geocode_place($destinationName);
        if (isset($geo['error'])) return array('error' => $geo['error']);
        $dist = rides_haversine_km($plat, $plng, $geo['lat'], $geo['lng']);
        if ($dist > 100) return array('error' => 'That destination is too far for a ride booking (' . round($dist) . ' km).');
        return array(
            'distance_km'     => round($dist, 2),
            'dropoff_lat'     => $geo['lat'],
            'dropoff_lng'     => $geo['lng'],
            'dropoff_address' => $geo['formatted_address'],
            'mode'            => 'destination',
        );
    }
    $km = floatval($distanceKm);
    if ($km <= 0 || $km > 100) {
        return array('error' => 'Give either a destination name or a distance between 0 and 100 km.');
    }
    return array(
        'distance_km'     => round($km, 2),
        'dropoff_lat'     => null,
        'dropoff_lng'     => null,
        'dropoff_address' => 'By distance: ' . round($km, 2) . ' km (booked via ETEL AI - driver directed on the way)',
        'mode'            => 'distance',
    );
}

// Resolves which real Delivery/Ride member this is from the SSO token,
// the same way agent_book_ride does - factored out so the status/cancel
// actions below don't repeat it three times.
function agent_require_member($pdo, $body) {
    require_once __DIR__ . '/../etelid_bridge.php';
    $ssoToken = isset($body['etel_sso_token']) ? trim($body['etel_sso_token']) : '';
    $member = etelid_bridge_member_from_sso_token($pdo, $ssoToken);
    if (!$member) fail('This person is not currently signed in with EtelID, or their session expired. Ask them to sign in again.', 401);
    $u = etelid_bridge_local_user($pdo, $member);
    if (!$u) fail(!empty($GLOBALS['etelid_link_error']) ? $GLOBALS['etelid_link_error'] : 'Could not link this EtelID to a Delivery account.', !empty($GLOBALS['etelid_link_error']) ? 403 : 500);
    return $u;
}

switch ($action) {

// Combines item lookup + full pricing into ONE call - previously the
// model had to call search_items, wait for that round-trip, THEN call
// get_order_quote in a second round-trip, burning 2 separate API calls
// (and 2 separate quota units) for the single most common request
// shape: "order X". This does both in one shot when possible.
case 'agent_find_and_quote': {
    agent_verify_service_key();
    $reqItems = isset($body['items']) && is_array($body['items']) ? $body['items'] : array();
    $toZone = isset($body['to_zone']) ? trim($body['to_zone']) : '';
    if (!$reqItems) fail('items is required - a list of {name, quantity}');

    $pdo = db();
    require_once __DIR__ . '/../config-etb.php';
    require_once __DIR__ . '/../lib/delivery_pricing.php';

    $resolvedItems = array();
    $needsClarification = array();
    foreach ($reqItems as $reqItem) {
        $name = isset($reqItem['name']) ? trim($reqItem['name']) : '';
        $qty  = isset($reqItem['quantity']) ? max(1, intval($reqItem['quantity'])) : 1;
        if ($name === '') continue;

        $like = '%' . $name . '%';
        $s = $pdo->prepare(
            "SELECT i.id, i.name, i.price, sg.business_name
             FROM items i JOIN service_givers sg ON i.service_giver_id = sg.id
             WHERE i.is_available = 1 AND i.name LIKE ? ORDER BY i.name ASC LIMIT 5"
        );
        $s->execute(array($like));
        $matches = $s->fetchAll();

        if (count($matches) === 0) {
            $needsClarification[] = array('query' => $name, 'reason' => 'no_match', 'options' => array());
        } elseif (count($matches) === 1 || strtolower($matches[0]['name']) === strtolower($name)) {
            // Single match, or an exact name match among several -
            // confident enough to use directly without asking.
            $resolvedItems[] = array('item_id' => $matches[0]['id'], 'quantity' => $qty, 'name' => $matches[0]['name'], 'price' => $matches[0]['price'], 'business_name' => $matches[0]['business_name']);
        } else {
            // Genuinely ambiguous (e.g. "chicken" matches 4 different
            // dishes) - don't guess, surface the options so the model
            // can ask the member which one, same as before.
            $needsClarification[] = array('query' => $name, 'reason' => 'ambiguous', 'options' => $matches);
        }
    }

    if ($needsClarification) {
        ok(array('resolved' => false, 'needs_clarification' => $needsClarification, 'resolved_items' => $resolvedItems));
    }

    // Every item resolved cleanly - compute the full quote right here,
    // same logic as agent_order_quote, just without the extra round-trip.
    $subtotal = 0; $lines = array();
    foreach ($resolvedItems as $it) {
        $lineTotal = $it['price'] * $it['quantity'];
        $subtotal += $lineTotal;
        $lines[] = array('item_id' => $it['item_id'], 'name' => $it['name'], 'quantity' => $it['quantity'], 'unit_price' => $it['price'], 'line_total' => $lineTotal);
    }

    $autoResolvedZone = false;
    if ($toZone) {
        $priced = delivery_calc_fee($pdo, $toZone, 'moto');
        if ($priced === null) fail('"' . $toZone . '" is not a recognized delivery zone. Ask the member to share their current location instead.');
    } elseif (isset($body['pickup_lat'], $body['pickup_lng']) && $body['pickup_lat'] && $body['pickup_lng']) {
        $distanceKm = delivery_distance_from_center(floatval($body['pickup_lat']), floatval($body['pickup_lng']));
        $priced = delivery_calc_fee_by_distance($pdo, $distanceKm);
        $toZone = 'GPS location (' . $priced['distance_km'] . ' km from Hawassa center)';
        $autoResolvedZone = true;
    } else {
        fail('Please share your current location so I can calculate the delivery fee, or name the area you\'re in.');
    }

    $serviceCharge = delivery_calc_service_charge($subtotal);
    $total = $subtotal + $priced['delivery_fee'] + $serviceCharge;

    ok(array(
        'resolved'       => true,
        'items'          => $lines,
        'subtotal'       => $subtotal,
        'delivery_fee'   => $priced['delivery_fee'],
        'service_charge' => $serviceCharge,
        'total'          => $total,
        'to_zone'        => $toZone,
        'zone_auto_detected' => $autoResolvedZone,
        'currency'       => 'ETB',
    ));
    break;
}

case 'agent_search_items': {
    agent_verify_service_key();
    $q = isset($body['query']) ? trim($body['query']) : '';
    if ($q === '') fail('query is required (what the member wants to order, e.g. "coffee" or "paracetamol")');
    $limit = isset($body['limit']) ? max(1, min(10, intval($body['limit']))) : 5;

    $pdo = db();
    $s = $pdo->prepare(
        "SELECT i.id, i.name, i.price, i.category, sg.business_name, sg.zone AS from_zone
         FROM items i JOIN service_givers sg ON i.service_giver_id = sg.id
         WHERE i.is_available = 1 AND (i.name LIKE ? OR i.category LIKE ?)
         ORDER BY i.name ASC LIMIT " . $limit
    );
    $like = '%' . $q . '%';
    $s->execute(array($like, $like));
    $rows = $s->fetchAll();
    ok(array('items' => $rows, 'count' => count($rows)));
    break;
}

case 'agent_order_quote': {
    agent_verify_service_key();
    $items = isset($body['items']) && is_array($body['items']) ? $body['items'] : array();
    $toZone = isset($body['to_zone']) ? trim($body['to_zone']) : '';
    if (!$items) fail('items is required - a list of {item_id, quantity}');

    $pdo = db();
    require_once __DIR__ . '/../config-etb.php';
    require_once __DIR__ . '/../lib/delivery_pricing.php';

    $ids = array();
    foreach ($items as $it) if (isset($it['item_id'])) $ids[] = intval($it['item_id']);
    $ids = array_values(array_unique(array_filter($ids)));
    if (!$ids) fail('No valid item_id values given.');

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rowsQ = $pdo->prepare("SELECT id, name, price FROM items WHERE id IN ($ph) AND is_available = 1");
    $rowsQ->execute($ids);
    $byId = array();
    foreach ($rowsQ->fetchAll() as $r) $byId[$r['id']] = $r;

    $subtotal = 0; $lines = array();
    foreach ($items as $it) {
        $iid = intval($it['item_id']);
        $qty = isset($it['quantity']) ? max(1, intval($it['quantity'])) : 1;
        if (!isset($byId[$iid])) fail('Item ' . $iid . ' is not available.');
        $lineTotal = $byId[$iid]['price'] * $qty;
        $subtotal += $lineTotal;
        $lines[] = array('item_id' => $iid, 'name' => $byId[$iid]['name'], 'quantity' => $qty, 'unit_price' => $byId[$iid]['price'], 'line_total' => $lineTotal);
    }

    // Distance-based fee is now the PRIMARY path (GPS -> straight-line
    // distance from Hawassa's center -> fee formula) - named zones were
    // confusing since most customers don't know their official zone
    // name. An explicit zone name is still honored if the member
    // happens to give one (keeps the admin's zone pricing table
    // meaningful), but GPS no longer tries to match against zone names
    // at all.
    $autoResolvedZone = false;
    if ($toZone) {
        $priced = delivery_calc_fee($pdo, $toZone, 'moto');
        if ($priced === null) fail('"' . $toZone . '" is not a recognized delivery zone. Ask the member to share their current location instead.');
    } elseif (isset($body['pickup_lat'], $body['pickup_lng']) && $body['pickup_lat'] && $body['pickup_lng']) {
        $distanceKm = delivery_distance_from_center(floatval($body['pickup_lat']), floatval($body['pickup_lng']));
        $priced = delivery_calc_fee_by_distance($pdo, $distanceKm);
        $toZone = 'GPS location (' . $priced['distance_km'] . ' km from Hawassa center)';
        $autoResolvedZone = true;
    } else {
        fail('Please share your current location so I can calculate the delivery fee, or name the area you\'re in.');
    }

    $serviceCharge = delivery_calc_service_charge($subtotal);
    $total = $subtotal + $priced['delivery_fee'] + $serviceCharge;

    ok(array(
        'items'          => $lines,
        'subtotal'       => $subtotal,
        'delivery_fee'   => $priced['delivery_fee'],
        'service_charge' => $serviceCharge,
        'total'          => $total,
        'to_zone'        => $toZone,
        'zone_auto_detected' => $autoResolvedZone,
        'currency'       => 'ETB',
    ));
    break;
}

case 'agent_place_order': {
    agent_verify_service_key();
    $pdo = db();
    require_once __DIR__ . '/../etelid_bridge.php';

    $ssoToken = isset($body['etel_sso_token']) ? trim($body['etel_sso_token']) : '';
    $member = etelid_bridge_member_from_sso_token($pdo, $ssoToken);
    if (!$member) fail('This person is not currently signed in with EtelID, or their session expired. Ask them to sign in again.', 401);

    $u = etelid_bridge_local_user($pdo, $member);
    if (!$u) fail(!empty($GLOBALS['etelid_link_error']) ? $GLOBALS['etelid_link_error'] : 'Could not link this EtelID to a Delivery account.', !empty($GLOBALS['etelid_link_error']) ? 403 : 500);
    if (etelid_bridge_user_is_limited($pdo, $u['id'])) etel_limited_refuse();

    $items = isset($body['items']) && is_array($body['items']) ? $body['items'] : array();
    $toZone = isset($body['to_zone']) ? trim($body['to_zone']) : '';
    $pm = isset($body['payment_method']) ? trim($body['payment_method']) : 'cash';
    $custLat = isset($body['pickup_lat']) ? floatval($body['pickup_lat']) : 0;
    $custLng = isset($body['pickup_lng']) ? floatval($body['pickup_lng']) : 0;
    if (!$items) fail('items is required.');
    if (!$toZone && !($custLat && $custLng)) fail('to_zone or the member\'s current location is required.');

    // Recipient defaults to the member themself - the AI should only
    // override this if the member explicitly says the order is for
    // someone else and gives that person's name and phone.
    $recipientName  = isset($body['recipient_name'])  && trim($body['recipient_name'])  !== '' ? trim($body['recipient_name'])  : $member['full_name'];
    $recipientPhone = isset($body['recipient_phone']) && trim($body['recipient_phone']) !== '' ? trim($body['recipient_phone']) : $member['phone'];

    // Calls the real order-creation logic DIRECTLY (in-process, same PHP
    // request) instead of the fragile self-HTTP-call this used to make
    // back into this exact same server. That self-call was confirmed to
    // be an unreliable point of failure (repeated "system error"/timeout
    // reports even when nothing else was wrong) - this in-process call
    // has no network round-trip to fail at all, and is the exact same
    // logic the real checkout uses (see OrderController.php).
    require_once __DIR__ . '/../lib/delivery_order_core.php';
    $orderPayload = array(
        'items'           => $items,
        'to_zone'         => $toZone,
        'customer_lat'    => $custLat,
        'customer_lng'    => $custLng,
        'recipient_name'  => $recipientName,
        'recipient_phone' => $recipientPhone,
        'payment_method'  => $pm,
        'notes'           => 'Ordered via ETEL AI',
    );
    try {
        $result = delivery_execute_order_creation($pdo, $u, $orderPayload);
        ok($result);
    } catch (Exception $e) {
        fail($e->getMessage(), 400);
    }
    break;
}

case 'agent_ride_quote': {
    agent_verify_service_key();

    $vtype = isset($body['vehicle_type']) ? strtolower(trim($body['vehicle_type'])) : 'bajaj';
    if (!in_array($vtype, array('bajaj', 'moto', 'car', 'minibus'), true)) $vtype = 'bajaj';
    $plat = isset($body['pickup_lat']) ? floatval($body['pickup_lat']) : 0;
    $plng = isset($body['pickup_lng']) ? floatval($body['pickup_lng']) : 0;

    $trip = agent_resolve_trip($plat, $plng, isset($body['destination_name']) ? $body['destination_name'] : '', isset($body['distance_km']) ? $body['distance_km'] : 0);
    if (isset($trip['error'])) fail($trip['error']);

    $pdo = db();
    $fareResult = rides_calc_fare($pdo, $trip['distance_km'], $vtype, false);
    ok(array(
        'vehicle_type' => $vtype,
        'distance_km'  => $trip['distance_km'],
        'destination'  => $trip['mode'] === 'destination' ? $trip['dropoff_address'] : null,
        'fare'         => $fareResult['fare'],
        'surge_active' => !empty($fareResult['surge']),
        'currency'     => 'ETB',
    ));
    break;
}

case 'agent_book_ride': {
    agent_verify_service_key();

    $pdo = db();
    require_once __DIR__ . '/../etelid_bridge.php';

    // ---- Prove WHICH real member this is (their own session, not
    //      a password - EtelAI never sees or handles passwords) ----
    $ssoToken = isset($body['etel_sso_token']) ? trim($body['etel_sso_token']) : '';
    $member = etelid_bridge_member_from_sso_token($pdo, $ssoToken);
    if (!$member) fail('This person is not currently signed in with EtelID, or their session expired. Ask them to sign in again.', 401);

    $u = etelid_bridge_local_user($pdo, $member);
    if (!$u) fail('Could not link this EtelID to a Delivery account.', 500);

    $plat  = isset($body['pickup_lat']) ? floatval($body['pickup_lat']) : 0;
    $plng  = isset($body['pickup_lng']) ? floatval($body['pickup_lng']) : 0;
    $vtype = isset($body['vehicle_type']) ? strtolower(trim($body['vehicle_type'])) : 'bajaj';
    $pm    = isset($body['payment_method']) ? substr(preg_replace('/[^a-z_]/', '', strtolower($body['payment_method'])), 0, 20) : 'cash';
    if (!in_array($vtype, array('bajaj', 'moto', 'car', 'minibus'), true)) $vtype = 'bajaj';
    if (!$plat || !$plng) fail('Pickup location (the person\'s current GPS position) is required.');

    $trip = agent_resolve_trip($plat, $plng, isset($body['destination_name']) ? $body['destination_name'] : '', isset($body['distance_km']) ? $body['distance_km'] : 0);
    if (isset($trip['error'])) fail($trip['error']);

    // Same one-active-ride-at-a-time rule as the normal booking flow -
    // an AI-booked ride is a real ride, same limits apply.
    $s = $pdo->prepare(
        "SELECT id, status FROM rides
         WHERE passenger_id=? AND status IN ('searching','accepted','in_progress','arrived')
         ORDER BY id DESC LIMIT 1"
    );
    $s->execute(array($u['id']));
    $existing = $s->fetch();
    if ($existing) {
        fail('This person already has an active ride.', 409, array(
            'existing_ride_id'     => intval($existing['id']),
            'existing_ride_status' => $existing['status'],
        ));
    }

    $dist = $trip['distance_km'];
    $fareResult = rides_calc_fare($pdo, $dist, $vtype, false);
    $fare = $fareResult['fare'];

    // Same legacy-code-column handling as the normal booking flow, so a
    // schema with a unique ride_code/tracking_code column doesn't reject
    // this insert with a duplicate-key error on an empty string.
    $extraCols = array(); $extraVals = array();
    try {
        $have = array();
        foreach ($pdo->query("SHOW COLUMNS FROM `rides`")->fetchAll() as $c) {
            $have[strtolower($c['Field'])] = true;
        }
        foreach (array('ride_code', 'request_code', 'code', 'tracking_code', 'order_code') as $cc) {
            if (isset($have[$cc])) {
                $extraCols[] = "`$cc`";
                $extraVals[] = 'R' . date('ymdHis') . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 5));
            }
        }
    } catch (Exception $e) { /* non-fatal */ }

    $cols = "passenger_id, pickup_lat, pickup_lng, dropoff_lat, dropoff_lng,
             pickup_address, dropoff_address, distance_km, fare_estimate, fare_final,
             payment_method, status, expires_at, created_at";
    $ph   = "?,?,?,?,?,?,?,?,?,?,?, 'searching', DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW()";
    $vals = array($u['id'], $plat, $plng, $trip['dropoff_lat'], $trip['dropoff_lng'],
                  'Picked up via ETEL AI', $trip['dropoff_address'], round($dist, 2), $fare, $fare, $pm);
    if ($extraCols) {
        $cols .= ', ' . implode(', ', $extraCols);
        $ph   .= str_repeat(', ?', count($extraCols));
        $vals  = array_merge($vals, $extraVals);
    }
    $pdo->prepare("INSERT INTO rides ($cols) VALUES ($ph)")->execute($vals);
    $new_ride_id = intval($pdo->lastInsertId());

    // Best-effort driver push, same as the normal booking flow - a
    // missing/failed push never blocks the booking itself.
    try {
        $nearbyDrivers = $pdo->prepare(
            "SELECT user_id FROM ride_drivers WHERE online = 1 LIMIT 50"
        );
        $nearbyDrivers->execute();
        foreach ($nearbyDrivers->fetchAll() as $nd) {
            if (function_exists('etel_push_send')) {
                etel_push_send($pdo, intval($nd['user_id']), 'New ride request nearby!',
                    'Booked via ETEL AI - ' . round($dist, 1) . ' km, ETB ' . number_format($fare, 0),
                    array('action' => 'new_ride', 'ride_id' => $new_ride_id, 'url' => '/delivery/ride/'));
            }
        }
    } catch (Exception $e) { /* best-effort */ }

    ok(array(
        'ride_id'      => $new_ride_id,
        'status'       => 'searching',
        'fare'         => $fare,
        'distance_km'  => round($dist, 2),
        'destination'  => $trip['mode'] === 'destination' ? $trip['dropoff_address'] : null,
        'vehicle_type' => $vtype,
        'message'      => 'Ride booked - searching for a nearby driver.',
    ));
    break;
}

case 'agent_ride_status': {
    agent_verify_service_key();
    $pdo = db();
    $u = agent_require_member($pdo, $body);

    // No ride_id is ever asked of the model - a member chatting about
    // "my ride" means whichever one is currently active, same as the
    // app's own my_active_ride screen.
    $s = $pdo->prepare(
        "SELECT r.id, r.status, r.pickup_address, r.dropoff_address, r.distance_km,
                r.fare_estimate, r.fare_final, r.traveled_km,
                d.name AS driver_name, d.phone AS driver_phone,
                rd.vehicle_type, rd.vehicle_make, rd.vehicle_color, rd.vehicle_plate,
                CASE WHEN rd.rating_count > 0 THEN ROUND(rd.rating_sum/rd.rating_count,1) ELSE 0 END AS driver_rating
         FROM rides r
         LEFT JOIN users d ON d.id = r.driver_id
         LEFT JOIN ride_drivers rd ON rd.user_id = r.driver_id
         WHERE r.passenger_id=? AND r.status IN ('searching','accepted','in_progress','arrived')
         ORDER BY r.id DESC LIMIT 1"
    );
    $s->execute(array($u['id']));
    $ride = $s->fetch();
    if (!$ride) { ok(array('has_active_ride' => false, 'message' => 'No active ride right now.')); break; }

    ok(array(
        'has_active_ride' => true,
        'ride_id'         => intval($ride['id']),
        'status'          => $ride['status'],
        'pickup'          => $ride['pickup_address'],
        'destination'     => $ride['dropoff_address'],
        'distance_km'     => floatval($ride['distance_km'] ?: 0),
        'traveled_km'     => floatval($ride['traveled_km'] ?: 0),
        'fare'            => floatval($ride['fare_final'] ?: $ride['fare_estimate'] ?: 0),
        'driver_name'     => $ride['driver_name'],
        'driver_phone'    => $ride['driver_phone'],
        'driver_rating'   => floatval($ride['driver_rating'] ?: 0),
        'vehicle'         => trim(($ride['vehicle_color'] ?: '') . ' ' . ($ride['vehicle_make'] ?: '') . ' ' . ($ride['vehicle_type'] ?: '')),
        'vehicle_plate'   => $ride['vehicle_plate'],
    ));
    break;
}

case 'agent_cancel_ride': {
    agent_verify_service_key();
    $pdo = db();
    $u = agent_require_member($pdo, $body);

    // Same eligibility rule as the app's own cancel_ride action
    // (RideController.php) - never lets a ride already picked up/
    // completed get silently marked cancelled.
    $s = $pdo->prepare(
        "SELECT id, status FROM rides
         WHERE passenger_id=? AND status IN ('searching','accepted','in_progress','arrived')
         ORDER BY id DESC LIMIT 1"
    );
    $s->execute(array($u['id']));
    $ride = $s->fetch();
    if (!$ride) fail('No active ride to cancel.', 404);

    $pdo->prepare("UPDATE rides SET status='cancelled', cancelled_at=NOW() WHERE id=?")->execute(array($ride['id']));
    ok(array('cancelled' => true, 'ride_id' => intval($ride['id']), 'message' => 'Ride cancelled.'));
    break;
}

case 'agent_order_status': {
    agent_verify_service_key();
    $pdo = db();
    $u = agent_require_member($pdo, $body);
    if (etelid_bridge_user_is_limited($pdo, $u['id'])) etel_limited_refuse();

    // Same as ride status - no order_id ever asked of the model. Finds
    // the member's most recent order that isn't finished/cancelled yet;
    // falls back to their most recent order at all if everything is
    // already delivered, so "where's my order" still gets an answer
    // right after delivery instead of a flat "nothing found".
    $s = $pdo->prepare(
        "SELECT o.id, o.order_code, o.status, o.to_zone, o.fare_total,
                o.delivery_fee, o.service_charge, o.placed_at,
                u_c.name AS courier_name, u_c.phone AS courier_phone,
                sg.business_name AS service_giver_name
         FROM orders o
         LEFT JOIN order_assignments oa ON o.id = oa.order_id
         LEFT JOIN service_givers sg ON oa.service_giver_id = sg.id
         LEFT JOIN couriers c ON o.courier_id = c.id
         LEFT JOIN users u_c ON c.user_id = u_c.id
         WHERE (o.customer_id=? OR o.recipient_phone=?)
         ORDER BY (o.status NOT IN ('delivered','cancelled')) DESC, o.id DESC
         LIMIT 1"
    );
    $s->execute(array($u['id'], isset($u['phone']) ? $u['phone'] : ''));
    $order = $s->fetch();
    if (!$order) { ok(array('has_order' => false, 'message' => 'No orders found for this member.')); break; }

    ok(array(
        'has_order'          => true,
        'order_id'           => intval($order['id']),
        'order_code'         => $order['order_code'],
        'status'             => $order['status'],
        'to_zone'            => $order['to_zone'],
        'total'              => floatval($order['fare_total'] ?: 0),
        'placed_at'          => $order['placed_at'],
        'courier_name'       => $order['courier_name'],
        'courier_phone'      => $order['courier_phone'],
        'service_giver_name' => $order['service_giver_name'],
    ));
    break;
}

}

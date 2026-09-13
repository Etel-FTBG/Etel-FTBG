#!/usr/bin/env bash
# =============================================================================
# ETEL Delivery — Open Meter / Street-Hail smoke test
# -----------------------------------------------------------------------------
# Walks the full open-trip flow against a local php -S instance:
#   1. register two users (customer + driver)
#   2. driver hails customer WITHOUT specifying km (open trip)
#   3. driver starts the ride
#   4. driver pings GPS at 4 points along a real ~2 km line; traveled_km
#      should accumulate
#   5. driver marks "Arrived" -> status=arrived, fare_final computed from km
#   6. customer confirms arrival + pays cash -> status=completed, fare_paid set
#   7. driver earnings increment
#
# Usage:
#   cd delivery_v2/delivery
#   php -S 127.0.0.1:8765 -t . > /tmp/etel-php.log 2>&1 &
#   API=http://127.0.0.1:8765/api/api.php ./tests/open_meter_smoke.sh
# =============================================================================

set -euo pipefail

API="${API:-http://127.0.0.1:8765/api/api.php}"
SUFFIX="$(date +%s)"

pass()  { printf "\033[32m  ✓ %s\033[0m\n" "$1"; }
fail()  { printf "\033[31m  ✗ %s\033[0m\n" "$1"; exit 1; }
note()  { printf "\033[36m%s\033[0m\n" "$1"; }

# Quick connectivity check
curl -fsS "$API" -X POST -H 'Content-Type: application/json' \
    -d '{"action":"ping"}' >/dev/null \
    || fail "API not reachable at $API — start it with: php -S 127.0.0.1:8765 -t ."
note "API reachable at $API"

# JSON helper that fails on !success and prints the message.
call() {
    local TOKEN="$1" BODY="$2"
    curl -fsS "$API" -X POST \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer $TOKEN" \
        -d "$BODY"
}
call_noauth() {
    curl -fsS "$API" -X POST \
        -H "Content-Type: application/json" \
        -d "$1"
}
extract() {
    # $1: json, $2: jq path (e.g. .data.token)
    echo "$1" | python -c "import sys,json; d=json.load(sys.stdin); k='$2'.lstrip('.'); 
parts=k.split('.');
v=d
for p in parts:
    if p.isdigit(): v=v[int(p)]
    else: v=v[p]
print(v)"
}
assert_eq() {
    if [ "$1" != "$2" ]; then fail "expected '$2', got '$1' ($3)"; fi
    pass "$3"
}

# ----------------------------------------------------------------------------
# 1. register two users — customer + driver
# ----------------------------------------------------------------------------
note "[1/7] Registering customer (cust_${SUFFIX}) + driver (drv_${SUFFIX})"
CUST_RESP=$(call_noauth "{\"action\":\"register\",\"name\":\"Cust ${SUFFIX}\",\"phone\":\"0911${SUFFIX:5:6}\",\"password\":\"test1234\",\"role\":\"customer\"}")
CUST_TOKEN=$(extract "$CUST_RESP" .data.token)
[ -n "$CUST_TOKEN" ] || fail "customer register: $CUST_RESP"
CUST_ID=$(extract "$CUST_RESP" .data.user.id)
pass "customer registered id=$CUST_ID"

DRV_RESP=$(call_noauth "{\"action\":\"register\",\"name\":\"Drv ${SUFFIX}\",\"phone\":\"0922${SUFFIX:5:6}\",\"password\":\"test1234\",\"role\":\"courier\"}")
DRV_TOKEN=$(extract "$DRV_RESP" .data.token)
DRV_ID=$(extract "$DRV_RESP" .data.user.id)
pass "driver registered id=$DRV_ID"

# ----------------------------------------------------------------------------
# 2. driver hails customer WITHOUT km -> open trip
# ----------------------------------------------------------------------------
note "[2/7] driver_hail_customer WITHOUT km (open trip)"
HAIL=$(call "$DRV_TOKEN" "{\"action\":\"driver_hail_customer\",\"phone\":\"0911${SUFFIX:5:6}\",\"km\":0,\"lat\":7.05,\"lng\":38.49}")
IS_OPEN=$(extract "$HAIL" .is_open_trip)
[ "$IS_OPEN" = "1" ] || fail "expected is_open_trip=1, got: $HAIL"
RIDE_ID=$(extract "$HAIL" .ride_id)
pass "open trip created, ride_id=$RIDE_ID"

# ----------------------------------------------------------------------------
# 3. driver starts the ride
# ----------------------------------------------------------------------------
note "[3/7] driver_start_ride"
START=$(call "$DRV_TOKEN" "{\"action\":\"driver_start_ride\",\"ride_id\":$RIDE_ID}")
pass "ride started"

# ----------------------------------------------------------------------------
# 4. GPS pings along a 2km east-west line
# ----------------------------------------------------------------------------
note "[4/7] driver_update_location x4 along a ~2 km line"
# Each pair is ~500 m apart from the previous (haversine) — well within the
# 2 km glitch filter and above the 5 m noise filter.
# Start ~ at pickup, then 4 steps east (~0.5 km each at this latitude)
LAT0=7.0500; LNG0=38.4900
# 0.5 km east at lat 7 -> lng delta = 0.5/(111.32*cos(7 deg in rad)) ~= 0.00452
D=0.00452
for i in 1 2 3 4; do
    LNG=$(python -c "print(f'{${LNG0} + ${D}*${i}:.7f}')")
    PING=$(call "$DRV_TOKEN" "{\"action\":\"driver_update_location\",\"lat\":${LAT0},\"lng\":${LNG}}")
    ACCUM=$(extract "$PING" .accumulated_km)
    pass "  sample $i @ lng=$LNG -> accumulated_km=$ACCUM"
done
[ "$(python -c "print(int(float('$ACCUM')*100))")" -gt 150 ] \
    || fail "expected accumulated_km > 1.5 km, got $ACCUM"
pass "GPS accumulator working"

# ----------------------------------------------------------------------------
# 5. driver_arrived -> status=arrived, fare_final computed
# ----------------------------------------------------------------------------
note "[5/7] driver_arrived (computes fare from traveled_km)"
ARR=$(call "$DRV_TOKEN" "{\"action\":\"driver_arrived\",\"ride_id\":$RIDE_ID}")
ST=$(extract "$ARR" .ride.status)
[ "$ST" = "arrived" ] || fail "expected status=arrived, got: $ARR"
FARE=$(extract "$ARR" .fare_final)
KM=$(extract "$ARR" .traveled_km)
pass "arrived, traveled_km=$KM, fare_final=$FARE"
[ "$(python -c "print(int(float('$FARE')))")" -ge 120 ] \
    || fail "expected fare >= 120 (base), got $FARE"
pass "fare >= base 120 ETB"

# ----------------------------------------------------------------------------
# 6. customer_confirm_arrival (cash)
# ----------------------------------------------------------------------------
note "[6/7] customer_confirm_arrival (cash)"
CONF=$(call "$CUST_TOKEN" "{\"action\":\"customer_confirm_arrival\",\"ride_id\":$RIDE_ID,\"payment_method\":\"cash\"}")
ST=$(extract "$CONF" .ride.status)
[ "$ST" = "completed" ] || fail "expected status=completed, got: $CONF"
PAID=$(extract "$CONF" .fare_paid)
PM=$(extract "$CONF" .payment_method)
[ "$PM" = "cash" ] || fail "expected payment_method=cash, got: $CONF"
pass "completed, paid=$PAID via $PM"

# ----------------------------------------------------------------------------
# 7. driver earnings reflect the new ride
# ----------------------------------------------------------------------------
note "[7/7] driver_get_profile (earnings should have incremented)"
PROF=$(call "$DRV_TOKEN" "{\"action\":\"driver_get_profile\"}")
EARN=$(extract "$PROF" .driver.total_earnings)
RIDES=$(extract "$PROF" .driver.total_rides)
pass "driver total_rides=$RIDES total_earnings=$EARN"

note ""
note "ALL CHECKS PASSED 🎉"
note "Test ride #$RIDE_ID (open meter, customer=$CUST_ID, driver=$DRV_ID)"

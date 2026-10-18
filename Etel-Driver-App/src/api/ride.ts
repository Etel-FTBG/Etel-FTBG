import { boot } from './client';
import { getDriverProfile } from './driver';
import { toNum } from '../utils/coords';
import { ActiveRide, FareMeter, NearbyRideRequest } from '../types/ride';

// All actions below map 1:1 to RideController.php (line refs current as of the
// codebase investigation this app was built against — re-check if the backend
// has moved on):
//   driver_go_online / driver_go_offline   :1304 / :1340
//   driver_update_location                 :1352
//   driver_get_nearby_requests             :2253
//   driver_accept_ride                     :3128
//   driver_start_ride                      :3268
//   driver_toggle_wait                     :3294
//   driver_arrived                         :3344 (computes final fare from GPS)
//   driver_cancel_ride                     :3541
//   driver_get_profile (active_ride)       :2404

export async function goOnline(lat: number, lng: number) {
  return boot('driver_go_online', { lat, lng });
}

export async function goOffline() {
  return boot('driver_go_offline', {});
}

function normalizeCoords<T extends object>(row: T): T {
  const r = row as Record<string, unknown>;
  return {
    ...row,
    pickup_lat: toNum(r.pickup_lat),
    pickup_lng: toNum(r.pickup_lng),
    dropoff_lat: toNum(r.dropoff_lat),
    dropoff_lng: toNum(r.dropoff_lng),
  };
}

export async function getNearbyRequests(): Promise<{ requests: NearbyRideRequest[] }> {
  const res = await boot<{ requests?: NearbyRideRequest[] }>('driver_get_nearby_requests', {});
  return {
    requests: (res.requests || []).map((q) => ({
      ...normalizeCoords(q),
      distance_from_driver_km: toNum(q.distance_from_driver_km) ?? undefined,
    })),
  };
}

// The driver's own current ride. `my_active_ride` (RideController.php:950) is
// the PASSENGER lookup (WHERE passenger_id=?), so it never returns a ride the
// user is driving. The driver-side source is driver_get_profile's
// `active_ride` (RideController.php:2404 -> rides_get_active_for_driver), the
// same one the web driver app uses.
export async function getMyActiveRide(): Promise<ActiveRide | null> {
  const res = await getDriverProfile();
  const ride = res.active_ride as ActiveRide | null | undefined;
  return ride ? normalizeCoords(ride) : null;
}

export async function acceptRide(requestId: number) {
  return boot('driver_accept_ride', { request_id: requestId });
}

// Tells the server this driver declined the request, so it stops coming back in their list.
export async function declineRide(requestId: number) {
  return boot('driver_decline_ride', { request_id: requestId });
}

export async function startRide(rideId: number) {
  return boot('driver_start_ride', { ride_id: rideId });
}

export async function toggleWait(rideId: number) {
  return boot('driver_toggle_wait', { ride_id: rideId });
}

export async function markArrived(rideId: number) {
  return boot('driver_arrived', { ride_id: rideId });
}

export async function cancelRide(rideId: number) {
  return boot('driver_cancel_ride', { ride_id: rideId });
}

// RideController.php:3173 — `phone` accepts a phone number, EtelID
// (EG-...), or scanned QR/verify-link string; the server figures out
// which. `km` + `open` are mutually exclusive: pass km for a
// by-distance fixed quote, or open:1 (or omit km) for a GPS-metered
// open trip. Returns the newly accepted ride directly (status
// 'accepted'), same shape as driver_accept_ride.
export async function hailCustomer(opts: {
  phone: string;
  km?: number;
  open?: boolean;
  lat: number;
  lng: number;
}) {
  return boot('driver_hail_customer', {
    phone: opts.phone,
    lat: opts.lat,
    lng: opts.lng,
    ...(opts.open || !opts.km ? { open: 1 } : { km: opts.km }),
  });
}

export interface UpdateLocationResult {
  updated: boolean;
  meter: FareMeter | null;
}

export async function updateLocation(
  lat: number,
  lng: number,
  accuracy: number,
  rideId?: number
): Promise<UpdateLocationResult> {
  return boot('driver_update_location', {
    lat,
    lng,
    accuracy,
    ...(rideId ? { ride_id: rideId } : {}),
  });
}

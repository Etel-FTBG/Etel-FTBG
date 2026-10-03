// Field names verified against RideController.php's actual SELECT/ok() calls
// (driver_get_nearby_requests :2253, rides_get_active_for_driver in
// lib/ride_fare.php:264) rather than assumed.

export interface NearbyRideRequest {
  id: number;
  pickup_lat: number | null;
  pickup_lng: number | null;
  pickup_address?: string;
  dropoff_lat: number | null;
  dropoff_lng: number | null;
  dropoff_address?: string;
  distance_km?: number;
  fare_final?: number;
  expires_at?: string;
  created_at?: string;
  passenger_name?: string;
  distance_from_driver_km?: number;
}

export type RideStatus =
  | 'searching'
  | 'accepted'
  | 'in_progress'
  | 'arrived'
  | 'completed'
  | 'cancelled';

// `rides.*` plus passenger_name/passenger_phone (joined), plus a fare
// breakdown merged in only while in_progress/arrived (fare_base,
// fare_distance, fare_wait, fare_subtotal, fare_surge_mult, fare_surge_etb,
// fare_total, wait_minutes, wait_active).
export interface ActiveRide {
  id: number;
  status: RideStatus;
  pickup_lat: number | null;
  pickup_lng: number | null;
  pickup_address?: string;
  dropoff_lat: number | null;
  dropoff_lng: number | null;
  dropoff_address?: string;
  vehicle_type: string;
  passenger_name?: string;
  passenger_phone?: string;
  distance_km?: number;
  fare_estimate?: number;
  fare_final?: number;
  fare_base?: number;
  fare_distance?: number;
  fare_wait?: number;
  fare_subtotal?: number;
  fare_surge_mult?: number;
  fare_surge_etb?: number;
  fare_total?: number;
  meter_fare?: number;
  traveled_km?: number;
  wait_active?: boolean;
  wait_minutes?: number;
  [key: string]: unknown;
}

// driver_update_location's `meter` field (RideController.php:1352).
export interface FareMeter {
  ride_id: number;
  traveled_km: number;
  meter_fare: number;
  wait_active: boolean;
  wait_minutes?: number;
  wait_auto_stopped?: boolean;
  low_accuracy?: boolean;
  [key: string]: unknown;
}

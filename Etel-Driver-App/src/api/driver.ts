import { boot } from './client';

// RideController.php:2357 — the row itself comes from ride_drivers (not
// users), merged with today's trip count/earnings. See AuthContext's
// mapping comment for why this shape differs from the login `user`.
export interface DriverProfile {
  user_id: number;
  name: string;
  phone: string;
  vehicle_type?: string;
  vehicle_plate?: string;
  vehicle_make?: string;
  vehicle_color?: string;
  rating: number;
  rating_count?: number;
  available_balance: number;
  etel_money_balance: number;
  trips_today: number;
  earnings_today: number;
  online?: number;
  [key: string]: unknown;
}

export async function getDriverProfile(): Promise<{ driver: DriverProfile; active_ride: unknown }> {
  return boot('driver_get_profile', {});
}

export interface KpiSummary {
  period: 'today' | 'week' | 'month';
  completed_rides: number;
  gross_fare: number;
  platform_fee: number;
  net_earnings: number;
  distance_km: number;
  online_hours: number;
  net_etb_per_hour: number | null;
  estimated_fuel_cost: number;
  net_after_fuel: number;
  net_etb_per_hour_after_fuel: number | null;
  note: string | null;
}

// RideController.php:2409
export async function getKpiSummary(period: 'today' | 'week' | 'month' = 'today'): Promise<KpiSummary> {
  return boot('driver_get_kpi_summary', { period });
}

export interface EarningsForDate {
  date: string;
  trips: number;
  earnings: number;
}

// RideController.php:2388 — only the last 7 days are accepted.
export async function getEarningsForDate(dateStr: string): Promise<EarningsForDate> {
  return boot('driver_get_earnings_for_date', { date: dateStr });
}

export interface DriverRideHistoryItem {
  id: number;
  pickup_address?: string;
  dropoff_address?: string;
  distance_km?: number;
  fare_paid?: number;
  fare_final?: number;
  status: string;
  rating?: number;
  created_at: string;
  completed_at?: string;
  passenger_name?: string;
}

// New backend action (RideController.php, added alongside ride_history) —
// mirrors the passenger-side ride_history query, filtered by driver_id.
// Requires deploying the updated boot.php/RideController.php to the live
// server before this will work there.
export async function getDriverRideHistory(): Promise<{ rides: DriverRideHistoryItem[] }> {
  return boot('driver_get_ride_history', {});
}

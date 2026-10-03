import { boot } from './client';

// RideController.php:2590
export async function triggerSos(opts: { rideId?: number; lat?: number; lng?: number; message?: string }) {
  return boot('sos_trigger', {
    ...(opts.rideId ? { ride_id: opts.rideId } : {}),
    ...(opts.lat != null ? { lat: opts.lat } : {}),
    ...(opts.lng != null ? { lng: opts.lng } : {}),
    ...(opts.message ? { message: opts.message } : {}),
  });
}

// RideController.php:1097 — the DRIVER rating the passenger back (not
// rate_ride, which is the passenger rating the driver).
export async function rateRider(rideId: number, rating: number, comment?: string) {
  return boot('rate_rider', { ride_id: rideId, rating, ...(comment ? { comment } : {}) });
}

export interface GoalTier {
  trips_required: number;
  projected_etb: number;
}

// RideController.php:2319 — projected earnings per tier count based on this
// driver's own real average fare; not a fixed admin-set "daily goal".
export async function getGoalTiers(): Promise<{ tiers: GoalTier[]; avg_fare: number }> {
  return boot('driver_get_goal_tiers', {});
}

export interface Announcement {
  id: number;
  category: 'news' | 'notification' | 'partner';
  title: string;
  body: string;
  created_at: string;
  is_read: boolean;
}

// RideController.php:2496
export async function getAnnouncements(): Promise<{ announcements: Announcement[] }> {
  return boot('driver_get_announcements', {});
}

export async function markAnnouncementRead(id: number) {
  return boot('driver_mark_announcement_read', { announcement_id: id });
}

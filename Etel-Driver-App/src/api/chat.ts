import { boot } from './client';

export interface RideMessage {
  id: number;
  sender_id: number;
  sender_role: 'driver' | 'passenger';
  body: string;
  sent_at: string;
  read_at: string | null;
  sender_name: string;
}

// RideController.php:3646
export async function getRideMessages(
  rideId: number,
  sinceId?: number
): Promise<{ messages: RideMessage[]; other_typing: boolean }> {
  return boot('get_ride_messages', { ride_id: rideId, ...(sinceId ? { since_id: sinceId } : {}) });
}

// RideController.php:3587
export async function sendRideMessage(rideId: number, body: string) {
  return boot('send_ride_message', { ride_id: rideId, body });
}

// RideController.php:3617
export async function pingTyping(rideId: number) {
  return boot('ping_ride_typing', { ride_id: rideId });
}

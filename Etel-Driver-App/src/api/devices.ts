import { boot } from './client';

// Signed-in devices (api actions my_devices, device_sign_out, devices_sign_out_others): where this account is signed in, and a way to sign one out.
export interface Device {
  id: number;
  label: string;
  ip: string;
  signed_in_at: string | null;
  last_seen: string | null;
  current: boolean;
}

// `now` is the server's clock, so "5 min ago" does not depend on the phone's own (possibly wrong) clock.
export async function myDevices(): Promise<{ devices: Device[]; now: string }> {
  const r = await boot<{ devices?: Device[]; now?: string }>('my_devices', {});
  return { devices: Array.isArray(r.devices) ? r.devices : [], now: typeof r.now === 'string' ? r.now : '' };
}
export async function signOutDevice(id: number): Promise<void> {
  await boot('device_sign_out', { id });
}
export async function signOutOtherDevices(): Promise<number> {
  const r = await boot<{ signed_out?: number }>('devices_sign_out_others', {});
  return typeof r.signed_out === 'number' ? r.signed_out : 0;
}

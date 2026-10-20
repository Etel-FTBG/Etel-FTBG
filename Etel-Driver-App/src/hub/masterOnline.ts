import * as Location from 'expo-location';
import { boot, getToken } from '../api/client';
import {
  startDriverLocationTracking,
  stopDriverLocationTracking,
  setCourierLocationEnabled,
} from '../location/backgroundLocationTask';
import { setOnlineState } from '../online/onlineState';
import { getSession } from './session';

// One online switch for every ETEL company. The account is signed in once (through any web app in the hub); this
// puts it online in each company it belongs to. A company the account is not part of simply refuses and is skipped.
const ALL_ONLINE_ACTIONS: { role: string; name: string; label: string; body: (lat: number, lng: number) => Record<string, unknown> }[] = [
  { role: 'driver', name: 'driver_go_online', label: 'Ride', body: (lat, lng) => ({ lat, lng }) },
  { role: 'courier', name: 'courier_go_online', label: 'Delivery', body: (lat, lng) => ({ lat, lng }) },
  { role: 'service_giver', name: 'sg_set_online', label: 'Services', body: () => ({ is_online: 1 }) },
];
const OFFLINE_ACTIONS: { name: string; body: Record<string, unknown> }[] = [
  { name: 'driver_go_offline', body: {} },
  { name: 'courier_go_offline', body: {} },
  { name: 'sg_set_online', body: { is_online: 0 } },
];

export async function goOnlineEverywhere(): Promise<string[]> {
  if (!(await getToken())) throw new Error('Sign in with your EtelID first.');
  // An account goes online only in its own company; the others would refuse it anyway.
  const role = getSession()?.role;
  const ONLINE_ACTIONS = role ? ALL_ONLINE_ACTIONS.filter((a) => a.role === role) : ALL_ONLINE_ACTIONS;
  const fg = await Location.requestForegroundPermissionsAsync();
  if (fg.status !== 'granted') throw new Error('ETEL needs your location to go online.');
  const pos = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
  const { latitude, longitude } = pos.coords;

  const results = await Promise.allSettled(
    ONLINE_ACTIONS.map((a) => boot(a.name, a.body(latitude, longitude)))
  );
  const on: string[] = [];
  let firstError = '';
  results.forEach((r, i) => {
    if (r.status === 'fulfilled') on.push(ONLINE_ACTIONS[i].label);
    else if (!firstError && r.reason instanceof Error) firstError = r.reason.message;
  });
  if (on.length === 0) throw new Error(firstError || 'This account is not set up to go online yet.');

  setCourierLocationEnabled(on.includes('Delivery'));
  try {
    await startDriverLocationTracking(); // keeps location running while the app is in the background
  } catch {
    // background permission refused: still online while the app is open
  }
  setOnlineState(true);
  return on;
}

export async function goOfflineEverywhere(): Promise<void> {
  await Promise.allSettled(OFFLINE_ACTIONS.map((a) => boot(a.name, a.body)));
  setCourierLocationEnabled(false);
  await stopDriverLocationTracking().catch(() => {});
  setOnlineState(false);
}

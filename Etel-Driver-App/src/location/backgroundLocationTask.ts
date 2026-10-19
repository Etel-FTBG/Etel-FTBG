import * as Location from 'expo-location';
import * as TaskManager from 'expo-task-manager';
import Constants, { ExecutionEnvironment } from 'expo-constants';
import { updateLocation } from '../api/ride';
import { boot } from '../api/client';
import { setOnlineState } from '../online/onlineState';

export const LOCATION_TASK_NAME = 'etel-driver-location-task';

// Expo Go has no custom native config, so it can't run a real Android
// foreground service — calling startLocationUpdatesAsync's
// `foregroundService` option there doesn't just fail, it crashes the
// whole app (and can leave a broken task registration that crash-loops
// the app on next launch too). This is the same "no real background
// location in Expo Go" limitation flagged from the start of this
// project — skip it gracefully there instead of attempting it, same
// treatment as the push-notifications module gets.
const isExpoGo = Constants.executionEnvironment === ExecutionEnvironment.StoreClient;

// Set from the app whenever a ride becomes active/inactive, so the background
// task (which runs outside React state) knows whether to include ride_id.
// RideController.php:1352 falls back to "WHERE driver_id=? AND status='in_progress'
// LIMIT 1" when ride_id is omitted, which is fragile with a stale ride lying
// around — always send it explicitly when we have one.
let currentRideId: number | undefined;

// Set by the hub's master online switch: also keep the delivery (courier) position fresh in the background.
let courierLocation = false;
export function setCourierLocationEnabled(on: boolean) {
  courierLocation = on;
}
export function setActiveRideIdForLocationTask(rideId: number | undefined) {
  currentRideId = rideId;
}

TaskManager.defineTask(LOCATION_TASK_NAME, async ({ data, error }) => {
  if (error) {
    console.warn('[locationTask] error', error);
    return;
  }
  const { locations } = (data as { locations: Location.LocationObject[] }) || {};
  const last = locations?.[locations.length - 1];
  if (!last) return;

  try {
    await updateLocation(
      last.coords.latitude,
      last.coords.longitude,
      last.coords.accuracy ?? 999,
      currentRideId
    );
  } catch (e) {
    // Swallow — a single missed ping isn't fatal, the next ~5s tick will retry.
    // (Matches the web app's tolerance for gaps: RideController.php caps
    // implausible jumps by elapsed time instead of rejecting them outright.)
    console.warn('[locationTask] update failed', e);
  }
  if (courierLocation) {
    boot('courier_update_location', { lat: last.coords.latitude, lng: last.coords.longitude }).catch(() => {});
  }
});

export async function startDriverLocationTracking() {
  if (isExpoGo) {
    // No real background service available here — the driver still goes
    // online and everything else works, just without a persistent
    // notification or updates while the app is fully backgrounded. A real
    // build is required to test that part.
    return;
  }

  const { status: fgStatus } = await Location.requestForegroundPermissionsAsync();
  if (fgStatus !== 'granted') throw new Error('Foreground location permission denied');

  const { status: bgStatus } = await Location.requestBackgroundPermissionsAsync();
  if (bgStatus !== 'granted') throw new Error('Background location permission denied');

  const already = await Location.hasStartedLocationUpdatesAsync(LOCATION_TASK_NAME);
  if (already) {
    setOnlineState(true);
    return;
  }

  await Location.startLocationUpdatesAsync(LOCATION_TASK_NAME, {
    accuracy: Location.Accuracy.High,
    timeInterval: 5000, // matches driver.js's 5s broadcastLocation cadence
    distanceInterval: 0,
    showsBackgroundLocationIndicator: true,
    foregroundService: {
      notificationTitle: 'ETEL Drive — Online',
      notificationBody: 'Sharing your location so riders and dispatch can find you.',
      notificationColor: '#D4AF37',
    },
    pausesUpdatesAutomatically: false,
  });
  setOnlineState(true);
}

export async function stopDriverLocationTracking() {
  courierLocation = false;
  setOnlineState(false);
  if (isExpoGo) {
    currentRideId = undefined;
    return;
  }
  const already = await Location.hasStartedLocationUpdatesAsync(LOCATION_TASK_NAME);
  if (already) {
    await Location.stopLocationUpdatesAsync(LOCATION_TASK_NAME);
  }
  currentRideId = undefined;
}

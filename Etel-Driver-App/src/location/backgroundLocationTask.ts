import * as Location from 'expo-location';
import * as TaskManager from 'expo-task-manager';
import Constants, { ExecutionEnvironment } from 'expo-constants';
import { updateLocation } from '../api/ride';
import { boot } from '../api/client';
import { setOnlineState } from '../online/onlineState';
import { refreshCounts } from '../online/liveCounts';

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
// True while the web Driver app is open on screen. It reports its own position every 5 s (with the phone's GPS speed), so the native task
// stays quiet then: two different position sources interleaved on one trip made the meter count kilometres and stop the waiting clock
// while the vehicle stood still. When the app goes to the background the web page stops, and the native task takes over again.
let webDriverForeground = false;
export function setWebDriverForeground(on: boolean) {
  webDriverForeground = on;
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
    if (!webDriverForeground) {
      await updateLocation(
        last.coords.latitude,
        last.coords.longitude,
        last.coords.accuracy ?? 999,
        currentRideId
      );
    }
  } catch (e) {
    // Swallow — a single missed ping isn't fatal, the next ~5s tick will retry.
    // (Matches the web app's tolerance for gaps: RideController.php caps
    // implausible jumps by elapsed time instead of rejecting them outright.)
    console.warn('[locationTask] update failed', e);
  }
  if (courierLocation) {
    boot('courier_update_location', { lat: last.coords.latitude, lng: last.coords.longitude }).catch(() => {});
  }
  // Keeps the golden light's trip / order / delivery counts fresh while the app is in the background (throttled).
  refreshCounts().catch(() => {});
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
      notificationTitle: 'ETEL — Online',
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

import * as Device from 'expo-device';
import { Platform } from 'react-native';
import Constants, { ExecutionEnvironment } from 'expo-constants';
import { savePushToken, removePushToken } from '../api/push';

// Expo Go dropped remote-push support in SDK 53 - merely IMPORTING
// expo-notifications there throws a fatal "[runtime not ready]" error,
// since the library registers a native listener as a module-load side
// effect that Expo Go's build doesn't support. A static `import` at the
// top of this file would run that side effect unconditionally regardless
// of any if-guard around our own code, so the module is loaded with a
// conditional require() instead - never even touched when isExpoGo is
// true. Same "no-op in Expo Go, only works in a real build" treatment as
// the background-location module gets.
const isExpoGo = Constants.executionEnvironment === ExecutionEnvironment.StoreClient;

// eslint-disable-next-line @typescript-eslint/no-var-requires
type NotificationsModule = typeof import('expo-notifications');
let Notifications: NotificationsModule | null = null;

if (!isExpoGo) {
  Notifications = require('expo-notifications') as NotificationsModule;
  // Foreground behavior — show the alert + play the sound even while the
  // app is open, since a driver needs to notice a new ride request
  // immediately regardless of whether they're looking at the screen.
  Notifications.setNotificationHandler({
    handleNotification: async () => ({
      shouldShowAlert: true,
      shouldPlaySound: true,
      shouldSetBadge: false,
      shouldShowBanner: true,
      shouldShowList: true,
    }),
  });
}

let cachedToken: string | null = null;

export async function registerForPushNotifications(): Promise<string | null> {
  if (!Notifications || !Device.isDevice) {
    // Push tokens aren't issued to simulators/emulators, and Expo Go
    // can't register at all (see above) — fail quietly, the app still
    // works via the existing polling either way.
    return null;
  }

  if (Platform.OS === 'android') {
    await Notifications.setNotificationChannelAsync('default', {
      name: 'Ride requests & updates',
      importance: Notifications.AndroidImportance.MAX,
      vibrationPattern: [0, 250, 250, 250],
      lightColor: '#D4AF37',
      sound: 'default',
    });
  }

  const existing = await Notifications.getPermissionsAsync();
  let finalStatus = existing.status;
  if (finalStatus !== 'granted') {
    const requested = await Notifications.requestPermissionsAsync();
    finalStatus = requested.status;
  }
  if (finalStatus !== 'granted') return null;

  const projectId = Constants.expoConfig?.extra?.eas?.projectId;
  const tokenResponse = await Notifications.getExpoPushTokenAsync(
    projectId ? { projectId } : undefined
  );
  cachedToken = tokenResponse.data;

  try {
    await savePushToken(cachedToken);
  } catch {
    // Non-fatal — the app still works via polling; retried on next
    // registerForPushNotifications() call (e.g. next login).
  }

  return cachedToken;
}

export async function unregisterPushNotifications() {
  if (!Notifications || !cachedToken) return;
  try {
    await removePushToken(cachedToken);
  } catch {
    // best-effort; a stale server-side token just means one dead row
  } finally {
    cachedToken = null;
  }
}

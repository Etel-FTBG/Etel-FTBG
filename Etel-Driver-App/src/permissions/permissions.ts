import { Linking, Platform } from 'react-native';
import Constants, { ExecutionEnvironment } from 'expo-constants';
import * as ImagePicker from 'expo-image-picker';
import * as Location from 'expo-location';
import { canDrawOverlays, overlaySupported, requestOverlayPermission } from '../../modules/etel-overlay';

export type PermKey = 'camera' | 'location' | 'locationAlways' | 'notifications' | 'overlay';
// granted = done; ask = can still be asked here; settings = refused for good, only the phone's Settings can change it; na = this phone/build has no such permission
export type PermState = 'granted' | 'ask' | 'settings' | 'na';

export const PERMS: { key: PermKey; icon: string; title: string; why: string }[] = [
  { key: 'camera', icon: 'camera', title: 'Camera', why: 'To photograph your licence, ID and selfie, and your vehicle papers.' },
  { key: 'location', icon: 'location', title: 'Location', why: 'To match you with nearby trips and orders and show your position on the map.' },
  { key: 'locationAlways', icon: 'navigate', title: 'Location all the time', why: 'So riders and dispatch can follow your trip while ETEL is in the background. Choose "Allow all the time".' },
  { key: 'notifications', icon: 'notifications', title: 'Notifications', why: 'So a new trip, order or message wakes your phone, even when ETEL is closed.' },
  { key: 'overlay', icon: 'layers', title: 'Display over other apps', why: 'Shows the golden ETEL light while you use other apps, so you never miss a request.' },
];

// Expo Go has no real notifications module (importing it there is fatal), so it is only loaded in a real build.
const isExpoGo = Constants.executionEnvironment === ExecutionEnvironment.StoreClient;
type NotificationsModule = typeof import('expo-notifications');
function notifications(): NotificationsModule | null {
  if (isExpoGo) return null;
  try {
    // eslint-disable-next-line @typescript-eslint/no-var-requires
    return require('expo-notifications') as NotificationsModule;
  } catch {
    return null;
  }
}

type Resp = { granted: boolean; canAskAgain?: boolean };
const fromResp = (r: Resp): PermState => (r.granted ? 'granted' : r.canAskAgain === false ? 'settings' : 'ask');

export async function checkPerm(key: PermKey): Promise<PermState> {
  try {
    if (key === 'camera') return fromResp(await ImagePicker.getCameraPermissionsAsync());
    if (key === 'location') return fromResp(await Location.getForegroundPermissionsAsync());
    if (key === 'locationAlways') {
      const fg = await Location.getForegroundPermissionsAsync();
      if (!fg.granted) return 'ask';
      const bg = await Location.getBackgroundPermissionsAsync();
      // Android only lets "all the time" be chosen on the phone's Settings page, so a refusal is final here.
      return bg.granted ? 'granted' : Platform.OS === 'android' ? 'settings' : fromResp(bg);
    }
    if (key === 'notifications') {
      const n = notifications();
      if (!n) return 'na';
      return fromResp(await n.getPermissionsAsync());
    }
    if (key === 'overlay') {
      if (Platform.OS !== 'android' || !overlaySupported) return 'na';
      return canDrawOverlays() ? 'granted' : 'settings';
    }
  } catch {
    return 'na';
  }
  return 'na';
}

export async function checkAll(): Promise<Record<PermKey, PermState>> {
  const out = {} as Record<PermKey, PermState>;
  for (const p of PERMS) out[p.key] = await checkPerm(p.key);
  return out;
}

// Asks for one permission. What the app cannot ask for in a dialog opens the phone's own Settings page instead.
export async function askPerm(key: PermKey): Promise<void> {
  try {
    const cur = await checkPerm(key);
    if (cur === 'granted' || cur === 'na') return;
    if (key === 'camera') {
      const r = await ImagePicker.requestCameraPermissionsAsync();
      if (!r.granted && r.canAskAgain === false) await Linking.openSettings();
    } else if (key === 'location') {
      const r = await Location.requestForegroundPermissionsAsync();
      if (!r.granted && r.canAskAgain === false) await Linking.openSettings();
    } else if (key === 'locationAlways') {
      const fg = await Location.getForegroundPermissionsAsync();
      if (!fg.granted) {
        await Location.requestForegroundPermissionsAsync();
        return;
      }
      const r = await Location.requestBackgroundPermissionsAsync();
      if (!r.granted) await Linking.openSettings();
    } else if (key === 'notifications') {
      const n = notifications();
      if (!n) return;
      const r = await n.requestPermissionsAsync();
      if (!r.granted && r.canAskAgain === false) await Linking.openSettings();
    } else if (key === 'overlay') {
      requestOverlayPermission();
    }
  } catch {
    await Linking.openSettings().catch(() => {});
  }
}

import { Alert, Linking, Platform } from 'react-native';
import * as SecureStore from 'expo-secure-store';
import Constants, { ExecutionEnvironment } from 'expo-constants';
import * as ImagePicker from 'expo-image-picker';
import * as Location from 'expo-location';
import { showLocationDisclosure } from './locationDisclosure';
import { batteryExempt, canDrawOverlays, overlaySupported, requestBatteryExemption, requestOverlayPermission, batterySupported } from '../../modules/etel-overlay';

export type PermKey = 'camera' | 'location' | 'locationAlways' | 'notifications' | 'overlay' | 'battery' | 'autostart';
// granted = done; ask = can still be asked here; settings = refused for good, only the phone's Settings can change it; na = this phone/build has no such permission
export type PermState = 'granted' | 'ask' | 'settings' | 'na';

export const PERMS: { key: PermKey; icon: string; title: string; why: string }[] = [
  { key: 'camera', icon: 'camera', title: 'Camera', why: 'To photograph your licence, ID and selfie, and your vehicle papers.' },
  { key: 'location', icon: 'location', title: 'Location', why: 'To match you with nearby trips and orders and show your position on the map.' },
  { key: 'locationAlways', icon: 'navigate', title: 'Location all the time', why: 'So riders and dispatch can follow your trip while ETEL is in the background. Choose "Allow all the time".' },
  { key: 'notifications', icon: 'notifications', title: 'Notifications', why: 'So a new trip, order or message wakes your phone, even when ETEL is closed.' },
  { key: 'overlay', icon: 'layers', title: 'Display over other apps', why: 'Shows the golden ETEL light while you use other apps, so you never miss a request.' },
  { key: 'battery', icon: 'battery-charging', title: 'No battery limits', why: 'Many phones stop an app in the background to save battery, which delays new trips and orders. In the list that opens, find ETEL and choose "Don\'t optimise" (or "Unrestricted"), then come back.' },
  { key: 'autostart', icon: 'power', title: 'Auto-start and background activity', why: 'Some phones (Tecno, Infinix, Xiaomi, Huawei, Oppo) have a separate switch in Settings > Apps > ETEL. Turn it on, then tap Done here.' },
];

// What each kind of account must allow. Someone who only books rides or orders needs less than someone who drives, delivers or serves
// customers and must be reached in the background. Signed out (before login) asks only for what every account needs.
export type PermRole = 'customer' | 'driver' | 'courier' | 'service_giver' | 'admin' | undefined;
const EVERYONE: PermKey[] = ['location', 'notifications'];
const WORKING: PermKey[] = ['camera', 'location', 'locationAlways', 'notifications', 'overlay', 'battery', 'autostart'];
export function requiredFor(role: PermRole): PermKey[] {
  if (role === 'driver' || role === 'courier' || role === 'service_giver') return WORKING;
  return EVERYONE;
}
export function permsFor(role: PermRole) {
  const need = requiredFor(role);
  return PERMS.filter((p) => need.includes(p.key));
}

export { LOCATION_DISCLOSURE, showLocationDisclosure } from './locationDisclosure';

const AUTOSTART_KEY = 'etel_autostart_done';

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
    if (key === 'battery') {
      if (Platform.OS !== 'android' || !batterySupported()) return 'na';   // an older install cannot ask: nothing to enforce
      return batteryExempt() ? 'granted' : 'settings';
    }
    if (key === 'autostart') {
      if (Platform.OS !== 'android') return 'na';
      return (await SecureStore.getItemAsync(AUTOSTART_KEY).catch(() => null)) ? 'granted' : 'ask';   // cannot be read from the phone: the person confirms it
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
      if (!(await showLocationDisclosure())) return;   // Google Play: say what the location is used for BEFORE the system dialog, and let the person say no
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
    } else if (key === 'battery') {
      requestBatteryExemption();
    } else if (key === 'autostart') {
      await new Promise<void>((resolve) => {
        Alert.alert(
          'Auto-start and background activity',
          'Open Settings > Apps > ETEL (or Battery / Auto-start) and turn ON "Auto-start", "Background activity" or "Allow background running". Then come back and tap Done.',
          [
            { text: 'Open Settings', onPress: () => { Linking.openSettings().catch(() => {}); resolve(); } },
            { text: 'Done', onPress: () => { SecureStore.setItemAsync(AUTOSTART_KEY, '1').catch(() => {}); resolve(); } },
          ],
          { cancelable: true, onDismiss: () => resolve() }
        );
      });
    }
  } catch {
    await Linking.openSettings().catch(() => {});
  }
}

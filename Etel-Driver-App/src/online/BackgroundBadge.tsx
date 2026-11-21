import { useEffect, useRef } from 'react';
import { Alert, AppState, AppStateStatus } from 'react-native';
import * as SecureStore from 'expo-secure-store';
import { subscribeOnline, isOnline } from './onlineState';
import { pollMs } from '../data/lowData';
import { getCounts, refreshCounts, resetCounts, subscribeCounts } from './liveCounts';
import { subscribeSession, getSession } from '../hub/session';
import { applyAlertPrefs, loadAlertPrefs } from '../hub/companies';
import {
  overlaySupported,
  canDrawOverlays,
  requestOverlayPermission,
  showOnlineBadge,
  hideOnlineBadge,
  updateOverlayCounts,
  setOverlayAlerts,
  setOverlayLabels,
  batteryExempt,
  requestBatteryExemption,
} from '../../modules/etel-overlay';
import { featureOn } from '../hub/apps';
import { labelsFor } from './overlayLabels';
import { shouldShowLight } from './lightLogic';
import { loadHubConfigCache, refreshHubConfig } from '../hub/appConfig';

const ASKED_KEY = 'etel_overlay_asked';
const BATTERY_ASKED_KEY = 'etel_battery_asked';
const POLL_MS = 5000;   // refreshCounts() keeps its own gap (4 s while online, 12 s otherwise), so this is only how often it is asked

// When the user leaves the app, a golden blinking ETEL logo sits at the top-left of the screen (Android). It shows
// while the account is online (slid to online; any role), with the live trip, new request, new order or delivery counts,
// and carries those counts. Tapping it brings the app back. Back in the app it hides.
export default function BackgroundBadge() {
  const appState = useRef<AppStateStatus>(AppState.currentState);

  useEffect(() => {
    if (!overlaySupported) return;

    let timer: ReturnType<typeof setInterval> | null = null;

    const sync = () => {
      const role = getSession()?.role;
      const c = applyAlertPrefs(getCounts(), role);
      const away = appState.current !== 'active';
      // The owner can switch the light off, or limit it to admins, in Admin > ETEL app > Features.
      // One rule for every account: the light shows only while the account is online (slid to online), whatever its role and whatever its counts.
      // Offline, a live trip or order does not bring it back; online with nothing going on it still shows (with zero counts).
      if (shouldShowLight({ away, online: isOnline(), featureOn: featureOn('light', role), canDraw: canDrawOverlays() })) {
        const [t, r, o, d] = labelsFor(role);
        setOverlayLabels(t, r, o, d);
        setOverlayAlerts(featureOn('light_alert', role), featureOn('light_alert', role));
        updateOverlayCounts(c.trips, c.requests, c.orders, c.deliveries);
        showOnlineBadge();
      } else {
        hideOnlineBadge();
      }
    };

    // While the app is out of sight, keep the counts fresh (the background location tick also refreshes them).
    const startPolling = () => {
      if (timer || !getSession()) return;
      refreshCounts(true).catch(() => {});
      refreshHubConfig().then(sync).catch(() => {});   // the owner's light / sound switches
      timer = setInterval(() => refreshCounts().catch(() => {}), pollMs(POLL_MS));
    };
    const stopPolling = () => {
      if (timer) clearInterval(timer);
      timer = null;
    };

    const askOnce = async () => {
      if (canDrawOverlays()) return;
      const asked = await SecureStore.getItemAsync(ASKED_KEY).catch(() => null);
      if (asked) return;
      await SecureStore.setItemAsync(ASKED_KEY, '1').catch(() => {});
      Alert.alert(
        'Show the golden ETEL light?',
        'While you use other apps, ETEL can show a small blinking golden logo at the top-left of the screen. It shows that you are online and counts your live trips, new orders and deliveries. This needs the "Display over other apps" permission.',
        [
          { text: 'Not now', style: 'cancel' },
          { text: 'Allow', onPress: () => requestOverlayPermission() },
        ]
      );
    };

    // Many phones (Tecno, Infinix, Xiaomi, Samsung...) stop an app in the background to save battery, which delays ride requests.
    // Asked once, when the driver goes online: the phone's own screen then lets them choose "Unrestricted" / "Don't optimise".
    const askBattery = async () => {
      if (batteryExempt()) return;
      const asked = await SecureStore.getItemAsync(BATTERY_ASKED_KEY).catch(() => null);
      if (asked) return;
      await SecureStore.setItemAsync(BATTERY_ASKED_KEY, '1').catch(() => {});
      Alert.alert(
        'Keep ETEL awake in the background?',
        'To get ride requests fast while you use other apps, allow ETEL to run without battery limits. On the next screen choose Allow. Some phones also have an Auto-start or Background activity switch in Settings > Apps > ETEL: turn it on.',
        [
          { text: 'Not now', style: 'cancel' },
          { text: 'Allow', onPress: () => requestBatteryExemption() },
        ]
      );
    };

    const offOnline = subscribeOnline((on) => {
      if (on) askOnce().then(askBattery).catch(() => {});
      sync();
    });
    const offCounts = subscribeCounts(sync);
    const offSession = subscribeSession((s) => {
      if (s) askOnce();
      else resetCounts();
      sync();
    });
    const sub = AppState.addEventListener('change', (next) => {
      appState.current = next;
      if (next === 'active') stopPolling();
      else startPolling();
      sync();
    });
    loadAlertPrefs().then(sync);
    loadHubConfigCache().then(sync).catch(() => {});
    sync();
    return () => {
      offOnline();
      offCounts();
      offSession();
      sub.remove();
      stopPolling();
      hideOnlineBadge();
    };
  }, []);

  return null;
}

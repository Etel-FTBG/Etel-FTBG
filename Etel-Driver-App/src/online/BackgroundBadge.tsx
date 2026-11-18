import { useEffect, useRef } from 'react';
import { Alert, AppState, AppStateStatus } from 'react-native';
import * as SecureStore from 'expo-secure-store';
import { subscribeOnline, isOnline } from './onlineState';
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
} from '../../modules/etel-overlay';
import { featureOn } from '../hub/apps';
import { loadHubConfigCache, refreshHubConfig } from '../hub/appConfig';

// The words on the pills depend on what the account does, so a customer sees "Your trip" and a driver sees "New request".
function labelsFor(role: string | undefined): [string, string, string, string] {
  switch (role) {
    case 'driver':
      return ['Trip', 'New request', 'Order', 'Delivery'];
    case 'courier':
      return ['Trip', 'New request', 'New order', 'Delivery'];
    case 'service_giver':
      return ['Trip', 'Request', 'New order', 'In progress'];
    default:
      return ['Your trip', 'Request', 'Order', 'Your order'];
  }
}

const ASKED_KEY = 'etel_overlay_asked';
const POLL_MS = 20000;

// When the user leaves the app, a golden blinking ETEL logo sits at the top-left of the screen (Android). It shows
// while they are online, or while they have a live trip, a new ride request, a new order or a delivery in progress,
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
      if (away && featureOn('light', role) && canDrawOverlays() && (isOnline() || c.total > 0)) {
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
      timer = setInterval(() => refreshCounts().catch(() => {}), POLL_MS);
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

    const offOnline = subscribeOnline((on) => {
      if (on) askOnce();
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

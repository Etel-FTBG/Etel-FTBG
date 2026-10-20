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
} from '../../modules/etel-overlay';

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
      const c = applyAlertPrefs(getCounts(), getSession()?.role);
      const away = appState.current !== 'active';
      if (away && canDrawOverlays() && (isOnline() || c.total > 0)) {
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

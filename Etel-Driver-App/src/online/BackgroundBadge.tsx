import { useEffect, useRef } from 'react';
import { Alert, AppState, AppStateStatus } from 'react-native';
import * as SecureStore from 'expo-secure-store';
import { subscribeOnline, isOnline } from './onlineState';
import {
  overlaySupported,
  canDrawOverlays,
  requestOverlayPermission,
  showOnlineBadge,
  hideOnlineBadge,
} from '../../modules/etel-overlay';

const ASKED_KEY = 'etel_overlay_asked';

// While the user is online and leaves the app, a golden blinking ETEL logo sits at the top-left of the screen
// (Android) so they can see ETEL is still online and running. Tapping it brings the app back. Back in the app it hides.
export default function BackgroundBadge() {
  const appState = useRef<AppStateStatus>(AppState.currentState);

  useEffect(() => {
    if (!overlaySupported) return;

    const sync = () => {
      if (isOnline() && appState.current !== 'active' && canDrawOverlays()) showOnlineBadge();
      else hideOnlineBadge();
    };

    const askOnce = async () => {
      if (canDrawOverlays()) return;
      const asked = await SecureStore.getItemAsync(ASKED_KEY).catch(() => null);
      if (asked) return;
      await SecureStore.setItemAsync(ASKED_KEY, '1').catch(() => {});
      Alert.alert(
        'Show the golden ETEL light?',
        'While you are online and using another app, ETEL can show a small blinking golden logo at the top-left of the screen, so you know it is still online. This needs the "Display over other apps" permission.',
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
    const sub = AppState.addEventListener('change', (next) => {
      appState.current = next;
      sync();
    });
    sync();
    return () => {
      offOnline();
      sub.remove();
      hideOnlineBadge();
    };
  }, []);

  return null;
}

import { applyAlertPrefs, loadAlertPrefs } from '../hub/companies';
import { loadSession } from '../hub/session';
import { featureOn } from '../hub/apps';
import { loadHubConfigCache } from '../hub/appConfig';
import { refreshCounts } from './liveCounts';
import { labelsFor } from './overlayLabels';
import { wakeMayShowLight } from './lightLogic';
import {
  canDrawOverlays,
  overlayIsShowing,
  overlayRingNow,
  setOverlayAlerts,
  setOverlayLabels,
  showOnlineBadge,
  updateOverlayCounts,
} from '../../modules/etel-overlay';

// A new ride request (or order) was pushed to this phone while the app is in the background or closed. Do not wait for the next
// check: ask the server for the real counts now, light the golden light and make it ring. Runs without any screen (headless), so it
// loads what the screens normally have in memory. Never throws.
export async function wakeFromPush(): Promise<void> {
  try {
    const session = await loadSession();
    if (!session) return;
    await Promise.all([loadHubConfigCache(), loadAlertPrefs()]);
    const role = session.role;
    if (!wakeMayShowLight(role) || !featureOn('light', role) || !canDrawOverlays()) return;
    const raw = await refreshCounts(true);
    // A push only goes to a driver who is online, so a wake-up counts as online (the in-memory flag is empty in a fresh background run).
    const c = applyAlertPrefs(raw, role, true);
    if (c.total <= 0) return;
    const wasShowing = overlayIsShowing();
    const [t, r, o, d] = labelsFor(role);
    setOverlayLabels(t, r, o, d);
    const loud = featureOn('light_alert', role);
    setOverlayAlerts(loud, loud);
    updateOverlayCounts(c.trips, c.requests, c.orders, c.deliveries);
    showOnlineBadge();
    // When the light was already showing, the update itself notices the new request and rings. When it was not, ring it here.
    if (!wasShowing && c.requests + c.orders > 0) overlayRingNow();
  } catch {
    // never let a background run crash the app
  }
}

import { Platform } from 'react-native';
import { requireOptionalNativeModule } from 'expo-modules-core';

// Android only, and only in a real build (Expo Go does not contain this module).
type EtelOverlayNative = {
  canDrawOverlays(): boolean;
  requestPermission(): void;
  show(): void;
  hide(): void;
  update(trips: number, requests: number, orders: number, deliveries: number): void;
  isShowing(): boolean;
  // Newer builds only (a phone with an older install does not have them, so they are called with ?.).
  setAlerts?(sound: boolean, vibrate: boolean): void;
  setLabels?(trip: string, request: string, order: string, delivery: string): void;
  ring?(): void;
  isIgnoringBatteryOptimizations?(): boolean;
  requestIgnoreBatteryOptimizations?(): void;
};

const native: EtelOverlayNative | null =
  Platform.OS === 'android' ? requireOptionalNativeModule<EtelOverlayNative>('EtelOverlay') : null;

export const overlaySupported = native !== null;
export function canDrawOverlays(): boolean { return native ? native.canDrawOverlays() : false; }
export function requestOverlayPermission(): void { native?.requestPermission(); }
export function showOnlineBadge(): void { native?.show(); }
export function hideOnlineBadge(): void { native?.hide(); }
export function updateOverlayCounts(trips: number, requests: number, orders: number, deliveries: number): void { native?.update(trips, requests, orders, deliveries); }
// Sound and vibration when a new request or order arrives. Ignored by an older install.
export function setOverlayAlerts(sound: boolean, vibrate: boolean): void { native?.setAlerts?.(sound, vibrate); }
// The words on the small pills (for example "New request"). Ignored by an older install.
export function setOverlayLabels(trip: string, request: string, order: string, delivery: string): void { native?.setLabels?.(trip, request, order, delivery); }
export function overlayIsShowing(): boolean { return native ? native.isShowing() : false; }
// Rings the light now (a new request that arrived while the light was not on screen). Ignored by an older install.
export function overlayRingNow(): void { native?.ring?.(); }
// Android battery saver: a phone that "optimises" the app stops it in the background and delays its messages. These ask to be left alone.
export function batteryExempt(): boolean { return native?.isIgnoringBatteryOptimizations ? native.isIgnoringBatteryOptimizations() : true; }
export function requestBatteryExemption(): void { native?.requestIgnoreBatteryOptimizations?.(); }

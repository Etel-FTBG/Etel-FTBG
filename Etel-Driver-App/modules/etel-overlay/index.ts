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
};

const native: EtelOverlayNative | null =
  Platform.OS === 'android' ? requireOptionalNativeModule<EtelOverlayNative>('EtelOverlay') : null;

export const overlaySupported = native !== null;
export function canDrawOverlays(): boolean { return native ? native.canDrawOverlays() : false; }
export function requestOverlayPermission(): void { native?.requestPermission(); }
export function showOnlineBadge(): void { native?.show(); }
export function hideOnlineBadge(): void { native?.hide(); }
export function updateOverlayCounts(trips: number, requests: number, orders: number, deliveries: number): void { native?.update(trips, requests, orders, deliveries); }

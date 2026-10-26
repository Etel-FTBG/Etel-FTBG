import { Platform } from 'react-native';
import { requireOptionalNativeModule } from 'expo-modules-core';

// Android only, and only in a real build (Expo Go does not contain this module).
type Progress = { received: number; total: number };
type Subscription = { remove(): void };
type EtelInstallerNative = {
  versionCode(): number;
  versionName(): string;
  canInstall(): boolean;
  openInstallSettings(): void;
  cancel(): void;
  download(url: string): Promise<{ path: string; sha256: string; bytes: number }>;
  install(path: string): void;
  addListener(event: 'onProgress', cb: (p: Progress) => void): Subscription;
};

const native: EtelInstallerNative | null =
  Platform.OS === 'android' ? requireOptionalNativeModule<EtelInstallerNative>('EtelInstaller') : null;

export const installerSupported = native !== null;
export const installedVersionCode = (): number => (native ? native.versionCode() : 0);
export const installedVersionName = (): string => (native ? native.versionName() : '');
export const canInstallApks = (): boolean => (native ? native.canInstall() : false);
export const openInstallSettings = (): void => native?.openInstallSettings();
export const cancelDownload = (): void => native?.cancel();
export const downloadApk = (url: string) => {
  if (!native) return Promise.reject(new Error('Not available in this build'));
  return native.download(url);
};
export const installApk = (path: string): void => native?.install(path);
export function onDownloadProgress(cb: (p: Progress) => void): Subscription {
  return native ? native.addListener('onProgress', cb) : { remove() {} };
}

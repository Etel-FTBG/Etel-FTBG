import * as SecureStore from 'expo-secure-store';

const KEY = 'etel_app_lock_enabled';

// Loaded lazily and defensively: a build that predates this native module
// must still start instead of crashing at import time.
function localAuth(): typeof import('expo-local-authentication') | null {
  try {
    return require('expo-local-authentication');
  } catch {
    return null;
  }
}

export async function isLockSupported(): Promise<boolean> {
  const LA = localAuth();
  if (!LA) return false;
  try {
    return (await LA.hasHardwareAsync()) && (await LA.isEnrolledAsync());
  } catch {
    return false;
  }
}

export async function isLockEnabled(): Promise<boolean> {
  try {
    return (await SecureStore.getItemAsync(KEY)) === '1';
  } catch {
    return false;
  }
}

export async function authenticate(): Promise<boolean> {
  const LA = localAuth();
  if (!LA) return true;
  try {
    const res = await LA.authenticateAsync({
      promptMessage: 'Unlock ETEL Drive',
      cancelLabel: 'Cancel',
    });
    return res.success;
  } catch {
    return false;
  }
}

// Turning the lock on requires one successful unlock first, so a driver can
// never enable it in a state where they cannot pass it.
export async function setLockEnabled(enabled: boolean): Promise<boolean> {
  if (enabled) {
    if (!(await isLockSupported())) return false;
    if (!(await authenticate())) return false;
  }
  await SecureStore.setItemAsync(KEY, enabled ? '1' : '0');
  return true;
}

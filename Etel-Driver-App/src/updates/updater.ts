import { useEffect, useState } from 'react';
import * as Updates from 'expo-updates';

// One place that runs "check, download, install" so the hub's refresh button, the footer line and the progress sheet all
// show the same thing. The download percentage itself comes from expo-updates (useUpdates().downloadProgress).
export type UpdatePhase = 'idle' | 'checking' | 'installing' | 'current' | 'error';

interface State {
  phase: UpdatePhase;
  message: string;
}

let state: State = { phase: 'idle', message: '' };
const listeners = new Set<(s: State) => void>();

function set(next: State) {
  state = next;
  listeners.forEach((l) => l(state));
}

export function useUpdaterState(): State {
  const [s, setS] = useState(state);
  useEffect(() => {
    listeners.add(setS);
    setS(state);
    return () => {
      listeners.delete(setS);
    };
  }, []);
  return s;
}

export function dismissUpdater() {
  set({ phase: 'idle', message: '' });
}

// Restart into the downloaded version. The short pause lets the "Installing" step show, like a store install.
export async function installUpdate(): Promise<void> {
  set({ phase: 'installing', message: '' });
  try {
    await new Promise((r) => setTimeout(r, 900));
    await Updates.reloadAsync();
  } catch (e: any) {
    set({ phase: 'error', message: String(e?.message || e) });
  }
}

// Asks the update server if there is a newer version and downloads it when there is. The progress sheet shows the
// download; the driver then taps Install, so the app never restarts in the middle of a trip.
export async function checkForUpdate(): Promise<'unavailable' | 'current' | 'ready' | 'error'> {
  if (!Updates.isEnabled) {
    set({ phase: 'error', message: 'Updates are off in this build' });
    return 'unavailable';
  }
  if (state.phase === 'checking' || state.phase === 'installing') return 'current';
  set({ phase: 'checking', message: '' });
  try {
    const r = await Updates.checkForUpdateAsync();
    if (!r.isAvailable) {
      set({ phase: 'current', message: '' });
      return 'current';
    }
    await Updates.fetchUpdateAsync();
    set({ phase: 'idle', message: '' });
    return 'ready';
  } catch (e: any) {
    set({ phase: 'error', message: String(e?.message || e) });
    return 'error';
  }
}

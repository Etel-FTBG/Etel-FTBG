import * as SecureStore from 'expo-secure-store';
import { parseStored, scaleInterval, toStored } from './lowDataLogic';

// The "Save mobile data" switch (Profile card). Kept on the phone; read once at start, then held in memory so screens can ask cheaply.
const KEY = 'etel_low_data';
type Listener = (on: boolean) => void;
let on = false;
const listeners = new Set<Listener>();

export function isLowData(): boolean {
  return on;
}
export async function loadLowData(): Promise<boolean> {
  try {
    on = parseStored(await SecureStore.getItemAsync(KEY));
  } catch {
    on = false;
  }
  listeners.forEach((l) => l(on));
  return on;
}
export async function setLowData(next: boolean): Promise<void> {
  on = next;
  listeners.forEach((l) => l(on));
  try {
    await SecureStore.setItemAsync(KEY, toStored(next));
  } catch {
    // the switch still works until the app closes
  }
}
export function subscribeLowData(l: Listener): () => void {
  listeners.add(l);
  return () => {
    listeners.delete(l);
  };
}
// The gap to use for a repeating check: the normal one, or a longer one while the switch is on.
export function pollMs(normalMs: number): number {
  return scaleInterval(normalMs, on);
}

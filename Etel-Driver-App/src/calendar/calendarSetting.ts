import * as SecureStore from 'expo-secure-store';
import { useEffect, useState } from 'react';
import { Calendar, parseCalendar } from './dateFormat';

// The "Calendar" choice (Profile card): Gregorian (the default, as before) or Ethiopian. Kept on the phone, read once at start, then held in memory.
const KEY = 'etel_calendar';
type Listener = (c: Calendar) => void;
let current: Calendar = 'gregorian';
const listeners = new Set<Listener>();

export const getCalendar = (): Calendar => current;
export async function loadCalendar(): Promise<Calendar> {
  try {
    current = parseCalendar(await SecureStore.getItemAsync(KEY));
  } catch {
    current = 'gregorian';
  }
  listeners.forEach((l) => l(current));
  return current;
}
export async function setCalendar(next: Calendar): Promise<void> {
  current = next;
  listeners.forEach((l) => l(current));
  try {
    await SecureStore.setItemAsync(KEY, next);
  } catch {
    // still works until the app closes
  }
}
export function subscribeCalendar(l: Listener): () => void {
  listeners.add(l);
  return () => {
    listeners.delete(l);
  };
}
// The current calendar; the screen redraws when it changes.
export function useCalendar(): Calendar {
  const [c, setC] = useState<Calendar>(current);
  useEffect(() => subscribeCalendar(setC), []);
  return c;
}

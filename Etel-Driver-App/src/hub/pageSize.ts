import * as SecureStore from 'expo-secure-store';
import { useEffect, useState } from 'react';

// The "Page size" choice (Profile card): how much of a web page fits across the phone. Normal draws the page at the phone's own width; "Smaller" and "Like the TWA"
// lay it out wider and shrink it to fit, like Chrome and the Courier/Driver TWA on a phone with a smaller page zoom. Kept on the phone, read once at start.
export type PageSize = 'normal' | 'smaller' | 'twa';
export const PAGE_SIZES: PageSize[] = ['normal', 'smaller', 'twa'];
export const PAGE_SCALE: Record<PageSize, number> = { normal: 1, smaller: 0.8, twa: 0.62 };
export const PAGE_SIZE_LABEL: Record<PageSize, string> = { normal: 'Normal', smaller: 'Smaller', twa: 'Like the TWA' };
export const DEFAULT_PAGE_SIZE: PageSize = 'twa';

const KEY = 'etel_page_size';
type Listener = (s: PageSize) => void;
let current: PageSize = DEFAULT_PAGE_SIZE;
const listeners = new Set<Listener>();

export function parsePageSize(v: unknown): PageSize {
  return v === 'normal' || v === 'smaller' || v === 'twa' ? v : DEFAULT_PAGE_SIZE;
}
export function nextPageSize(s: PageSize): PageSize {
  return PAGE_SIZES[(PAGE_SIZES.indexOf(s) + 1) % PAGE_SIZES.length];
}
export const getPageSize = (): PageSize => current;
export async function loadPageSize(): Promise<PageSize> {
  try {
    current = parsePageSize(await SecureStore.getItemAsync(KEY));
  } catch {
    current = DEFAULT_PAGE_SIZE;
  }
  listeners.forEach((l) => l(current));
  return current;
}
export async function setPageSize(next: PageSize): Promise<void> {
  current = next;
  listeners.forEach((l) => l(current));
  try {
    await SecureStore.setItemAsync(KEY, next);
  } catch {
    // still works until the app closes
  }
}
export function usePageSize(): PageSize {
  const [s, setS] = useState<PageSize>(current);
  useEffect(() => {
    listeners.add(setS);
    return () => {
      listeners.delete(setS);
    };
  }, []);
  return s;
}

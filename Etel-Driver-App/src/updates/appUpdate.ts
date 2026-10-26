import { useEffect, useState } from 'react';
import { AppState } from 'react-native';
import { SITE_ORIGIN } from '../hub/apps';
import {
  installerSupported,
  installedVersionCode,
  installedVersionName,
  canInstallApks,
  openInstallSettings,
  cancelDownload,
  downloadApk,
  installApk,
  onDownloadProgress,
} from '../../modules/etel-installer';

// Updates that over-the-air cannot carry (new native code) come as a new APK. The app asks our server for version.json, and when
// the build there is newer than this one it downloads the APK with live progress, checks it, and opens Android's installer.
// version.json lives at <site>/app/version.json: { versionCode, versionName, url, sha256?, sizeBytes?, notes?: string[], required? }
export type AppUpdatePhase = 'none' | 'available' | 'downloading' | 'verifying' | 'permission' | 'ready' | 'installing' | 'error';

export interface AppUpdateInfo {
  versionCode: number;
  versionName: string;
  url: string;
  sha256?: string;
  sizeBytes?: number;
  notes?: string[];
  required?: boolean;
}

export interface AppUpdateState {
  phase: AppUpdatePhase;
  info: AppUpdateInfo | null;
  received: number;
  total: number;
  speed: number; // bytes per second, smoothed
  message: string;
}

const BASE = SITE_ORIGIN + '/app/';
let state: AppUpdateState = { phase: 'none', info: null, received: 0, total: 0, speed: 0, message: '' };
let apkPath = '';
let lastCheck = 0;
const listeners = new Set<(s: AppUpdateState) => void>();

function set(patch: Partial<AppUpdateState>) {
  state = { ...state, ...patch };
  listeners.forEach((l) => l(state));
}

export function useAppUpdate(): AppUpdateState {
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

export const currentBuild = () => ({ code: installedVersionCode(), name: installedVersionName() });

// Looks for a newer APK. Quiet when there is none, the file is missing, or the phone is offline.
export async function checkAppUpdate(force = false): Promise<void> {
  if (!installerSupported) return;
  if (state.phase !== 'none' && state.phase !== 'available') return;
  if (!force && Date.now() - lastCheck < 10 * 60 * 1000) return;
  lastCheck = Date.now();
  try {
    const res = await fetch(BASE + 'version.json?t=' + Date.now(), { headers: { 'Cache-Control': 'no-cache' } });
    if (!res.ok) return;
    const j = (await res.json()) as Partial<AppUpdateInfo>;
    const code = Number(j.versionCode);
    if (!j.url || !Number.isFinite(code) || code <= installedVersionCode()) {
      if (state.phase === 'available') set({ phase: 'none', info: null });
      return;
    }
    const url = /^https:\/\//.test(j.url) ? j.url : BASE + j.url.replace(/^\/+/, '');
    set({
      phase: 'available',
      info: {
        versionCode: code,
        versionName: String(j.versionName || ''),
        url,
        sha256: j.sha256 ? String(j.sha256).toLowerCase() : undefined,
        sizeBytes: j.sizeBytes ? Number(j.sizeBytes) : undefined,
        notes: Array.isArray(j.notes) ? j.notes.map(String).slice(0, 8) : undefined,
        required: !!j.required,
      },
    });
  } catch {
    // Offline or the file is not there yet: nothing to show.
  }
}

function openInstaller() {
  if (!canInstallApks()) {
    set({ phase: 'permission' });
    return;
  }
  set({ phase: 'installing' });
  try {
    installApk(apkPath);
    // The installer takes over. If the person backs out, the sheet offers to open it again.
    setTimeout(() => {
      if (state.phase === 'installing') set({ phase: 'ready' });
    }, 4000);
  } catch (e: unknown) {
    set({ phase: 'error', message: e instanceof Error ? e.message : String(e) });
  }
}

export async function startAppUpdate(): Promise<void> {
  const info = state.info;
  if (!info || state.phase === 'downloading' || state.phase === 'verifying') return;
  set({ phase: 'downloading', received: 0, total: info.sizeBytes || 0, speed: 0, message: '' });
  let lastT = Date.now();
  let lastB = 0;
  const sub = onDownloadProgress((p) => {
    const now = Date.now();
    const dt = (now - lastT) / 1000;
    let speed = state.speed;
    if (dt > 0.25) {
      const inst = (p.received - lastB) / dt;
      speed = speed ? speed * 0.7 + inst * 0.3 : inst;
      lastT = now;
      lastB = p.received;
    }
    set({ received: p.received, total: p.total > 0 ? p.total : state.total, speed });
  });
  try {
    const r = await downloadApk(info.url);
    set({ phase: 'verifying', received: r.bytes, total: r.bytes });
    await new Promise((res) => setTimeout(res, 900)); // lets the Verify step be seen
    if (info.sha256 && info.sha256 !== r.sha256.toLowerCase()) {
      set({ phase: 'error', message: 'The downloaded file is damaged. Please try again.' });
      return;
    }
    apkPath = r.path;
    openInstaller();
  } catch (e: unknown) {
    const msg = e instanceof Error ? e.message : String(e);
    if (/CANCELLED|cancelled/.test(msg)) set({ phase: 'available', received: 0, speed: 0, message: '' });
    else set({ phase: 'error', message: msg });
  } finally {
    sub.remove();
  }
}

export function cancelAppUpdate() {
  cancelDownload();
}
export function openAppInstaller() {
  if (apkPath) openInstaller();
  else startAppUpdate();
}
export function allowInstallsInSettings() {
  openInstallSettings();
}
export function dismissAppUpdate() {
  if (state.info?.required) return;
  if (state.phase === 'downloading' || state.phase === 'verifying') return;
  set({ phase: state.info ? 'available' : 'none', message: '' });
}
export function retryAppUpdate() {
  apkPath = '';
  set({ phase: 'available', message: '' });
  startAppUpdate();
}

// Back from the "allow installs" settings page: carry on straight into the installer.
AppState.addEventListener('change', (st) => {
  if (st === 'active' && state.phase === 'permission' && apkPath && canInstallApks()) openInstaller();
});

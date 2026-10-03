// Tracks whether the last server request reached the server. Driven by real
// request outcomes (not a device-level network check), so it also catches the
// common "connected to Wi-Fi/data but nothing gets through" case.
type Listener = (online: boolean) => void;

let online = true;
const listeners = new Set<Listener>();

export function reportConnectivity(next: boolean) {
  if (next === online) return;
  online = next;
  listeners.forEach((l) => l(online));
}

export function isOnline(): boolean {
  return online;
}

export function subscribeConnectivity(listener: Listener): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

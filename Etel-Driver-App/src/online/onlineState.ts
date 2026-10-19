// One shared "the user is online" flag. The background location task, the web driver app inside the hub and the
// native Requests screen all feed it; the golden badge listens to it.
type Listener = (online: boolean) => void;

let online = false;
const listeners = new Set<Listener>();

export function isOnline(): boolean {
  return online;
}

export function setOnlineState(next: boolean): void {
  if (online === next) return;
  online = next;
  listeners.forEach((l) => l(online));
}

export function subscribeOnline(l: Listener): () => void {
  listeners.add(l);
  return () => {
    listeners.delete(l);
  };
}

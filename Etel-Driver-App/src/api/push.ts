import { boot } from './client';

// PushController.php's save_push_token/remove_push_token (added alongside
// the existing Web Push actions) — the Expo push token variant used by
// this native app instead of a browser PushSubscription object.
export async function savePushToken(token: string) {
  return boot('save_push_token', { token });
}

export async function removePushToken(token: string) {
  return boot('remove_push_token', { token });
}

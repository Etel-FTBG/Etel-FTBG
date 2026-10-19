import * as Haptics from 'expo-haptics';

// Light touch feedback. Never throws: a phone without a vibration motor simply does nothing.
export function tap() {
  Haptics.selectionAsync().catch(() => {});
}
export function success() {
  Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => {});
}

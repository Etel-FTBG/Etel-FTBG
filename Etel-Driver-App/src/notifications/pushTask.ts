import * as TaskManager from 'expo-task-manager';
import Constants, { ExecutionEnvironment } from 'expo-constants';
import { wakeFromPush } from '../online/pushWake';

// Runs when a push arrives while the app is in the background or closed (a "headless background notification": the server sends a
// data-only message together with the visible one for a new ride request). It refreshes the golden light and rings it at once.
// Defined and registered in the module scope of a file that index.ts loads first, as expo-notifications requires.
export const PUSH_WAKE_TASK = 'etel-push-wake-task';

const isExpoGo = Constants.executionEnvironment === ExecutionEnvironment.StoreClient;

if (!isExpoGo) {
  TaskManager.defineTask(PUSH_WAKE_TASK, async () => {
    await wakeFromPush();
  });
  try {
    // eslint-disable-next-line @typescript-eslint/no-var-requires
    const Notifications = require('expo-notifications') as typeof import('expo-notifications');
    Notifications.registerTaskAsync(PUSH_WAKE_TASK).catch(() => {});
  } catch {
    // not available in this build: the light still refreshes by itself every few seconds
  }
}

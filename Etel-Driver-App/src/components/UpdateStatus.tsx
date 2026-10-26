import React from 'react';
import { Pressable, StyleSheet } from 'react-native';
import * as Updates from 'expo-updates';
import { Text } from '../i18n';
import { useTheme } from '../theme/ThemeContext';
import { tap } from '../utils/haptics';
import { checkForUpdate } from '../updates/updater';
import { currentBuild } from '../updates/appUpdate';

// Small footer line: which update this phone runs, and a tap to check for a new one right now.
export default function UpdateStatus() {
  const { p } = useTheme();
  const { currentlyRunning, isUpdateAvailable, isUpdatePending, checkError, downloadError } = Updates.useUpdates();
  const err = checkError?.message || downloadError?.message || '';
  const id = currentlyRunning.updateId ? currentlyRunning.updateId.slice(0, 8) : 'built-in';

  // The progress card (UpdateBanner) shows checking, downloading and installing; this line only starts the check.
  const check = () => {
    tap();
    checkForUpdate();
  };

  const line =
    `v${currentlyRunning.runtimeVersion ?? '?'} · build ${currentBuild().code || '?'} · ${Updates.channel ?? 'no channel'} · ${id}` +
    (isUpdatePending ? ' · update ready' : isUpdateAvailable ? ' · update found' : '');

  return (
    <Pressable onPress={check} accessibilityRole="button" accessibilityLabel="Check for updates" style={styles.wrap}>
      <Text style={[styles.line, { color: p.textMuted }]}>{line}</Text>
      <Text style={[styles.line, { color: p.textSecondary }]}>{err || 'Tap to check for updates'}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  wrap: { alignItems: 'center', paddingVertical: 10 },
  line: { fontSize: 11, textAlign: 'center', marginTop: 2 },
});

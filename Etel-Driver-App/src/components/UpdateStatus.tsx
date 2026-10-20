import React, { useState } from 'react';
import { Pressable, StyleSheet } from 'react-native';
import * as Updates from 'expo-updates';
import { Text } from '../i18n';
import { useTheme } from '../theme/ThemeContext';

// Small footer line: which update this phone runs, and a tap to check for a new one right now.
export default function UpdateStatus() {
  const { p } = useTheme();
  const { currentlyRunning, isUpdateAvailable, isUpdatePending, checkError, downloadError } = Updates.useUpdates();
  const [note, setNote] = useState('');

  const id = currentlyRunning.updateId ? currentlyRunning.updateId.slice(0, 8) : 'built-in';
  const err = checkError?.message || downloadError?.message || '';

  const check = async () => {
    if (!Updates.isEnabled) {
      setNote('Updates are off in this build');
      return;
    }
    setNote('Checking...');
    try {
      const r = await Updates.checkForUpdateAsync();
      if (!r.isAvailable) {
        setNote('Up to date');
        return;
      }
      setNote('Downloading...');
      await Updates.fetchUpdateAsync();
      setNote('Ready, restarting');
      await Updates.reloadAsync();
    } catch (e: any) {
      setNote('Error: ' + String(e?.message || e));
    }
  };

  const line =
    `v${currentlyRunning.runtimeVersion ?? '?'} · ${Updates.channel ?? 'no channel'} · ${id}` +
    (isUpdatePending ? ' · update ready' : isUpdateAvailable ? ' · update found' : '');

  return (
    <Pressable onPress={check} accessibilityRole="button" accessibilityLabel="Check for updates" style={styles.wrap}>
      <Text style={[styles.line, { color: p.textMuted }]}>{line}</Text>
      <Text style={[styles.line, { color: p.textSecondary }]}>{note || err || 'Tap to check for updates'}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  wrap: { alignItems: 'center', paddingVertical: 10 },
  line: { fontSize: 11, textAlign: 'center', marginTop: 2 },
});

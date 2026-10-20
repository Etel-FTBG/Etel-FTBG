import React, { useCallback, useEffect, useRef, useState } from 'react';
import { AppState, View, Pressable, StyleSheet } from 'react-native';
import { Text } from '../i18n';
import { Ionicons } from '@expo/vector-icons';
import { colors, typography } from '../theme/colors';
import { useSession, hubSignOut } from '../hub/session';
import { authenticate, isLockEnabled } from './appLock';

// Re-lock only after the app has been away this long, so switching to a maps
// app mid-trip does not force a fingerprint every time.
const RELOCK_AFTER_MS = 60 * 1000;

export default function AppLockGate({ children }: { children: React.ReactNode }) {
  const user = useSession();
  const [locked, setLocked] = useState(false);
  const [checking, setChecking] = useState(false);
  const backgroundedAt = useRef<number | null>(null);
  const coldStartChecked = useRef(false);

  const unlock = useCallback(async () => {
    setChecking(true);
    const ok = await authenticate();
    setChecking(false);
    if (ok) setLocked(false);
  }, []);

  // Cold start: lock once a saved session is restored.
  useEffect(() => {
    if (!user || coldStartChecked.current) return;
    coldStartChecked.current = true;
    isLockEnabled().then((enabled) => {
      if (enabled) {
        setLocked(true);
        unlock();
      }
    });
  }, [user, unlock]);

  useEffect(() => {
    if (!user) {
      setLocked(false);
      coldStartChecked.current = false;
      return;
    }
    const sub = AppState.addEventListener('change', async (state) => {
      if (state === 'background') {
        backgroundedAt.current = Date.now();
      } else if (state === 'active' && backgroundedAt.current != null) {
        const away = Date.now() - backgroundedAt.current;
        backgroundedAt.current = null;
        if (away >= RELOCK_AFTER_MS && (await isLockEnabled())) {
          setLocked(true);
          unlock();
        }
      }
    });
    return () => sub.remove();
  }, [user, unlock]);

  return (
    <View style={{ flex: 1 }}>
      {children}
      {locked && (
        <View style={styles.overlay}>
          <Ionicons name="lock-closed" size={52} color={colors.gold} />
          <Text style={styles.title}>ETEL is locked</Text>
          <Text style={styles.body}>Use your fingerprint or screen lock to continue.</Text>
          <Pressable style={styles.button} onPress={unlock} disabled={checking}>
            <Text style={styles.buttonText}>{checking ? 'Checking…' : 'Unlock'}</Text>
          </Pressable>
          <Pressable style={styles.link} onPress={() => hubSignOut()}>
            <Text style={styles.linkText}>Sign out instead</Text>
          </Pressable>
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  overlay: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    bottom: 0,
    zIndex: 1000,
    backgroundColor: colors.bg,
    alignItems: 'center',
    justifyContent: 'center',
    padding: 32,
  },
  title: { ...typography.h2, color: colors.text, marginTop: 16 },
  body: { color: colors.textSecondary, ...typography.body, textAlign: 'center', marginTop: 8 },
  button: {
    marginTop: 24,
    backgroundColor: colors.gold,
    borderRadius: 30,
    paddingVertical: 14,
    paddingHorizontal: 40,
  },
  buttonText: { color: colors.bg, fontWeight: '700', fontSize: 15 },
  link: { marginTop: 18, padding: 8 },
  linkText: { color: colors.textMuted, fontSize: 13 },
});

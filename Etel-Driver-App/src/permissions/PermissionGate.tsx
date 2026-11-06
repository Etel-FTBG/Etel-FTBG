import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, AppState, Linking, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { Text } from '../i18n';
import { colors, typography } from '../theme/colors';
import { PERMS, PermKey, PermState, askPerm, checkAll } from './permissions';

// ETEL cannot do its job without these, so the app stays on this page until every one is allowed. It re-checks each time the
// person comes back from the phone's Settings, and goes away by itself once nothing is missing.
export default function PermissionGate({ children }: { children: React.ReactNode }) {
  const insets = useSafeAreaInsets();
  const [state, setState] = useState<Record<PermKey, PermState> | null>(null);
  const [busy, setBusy] = useState(false);
  const running = useRef(false);

  const refresh = useCallback(async () => setState(await checkAll()), []);
  useEffect(() => {
    refresh();
    const sub = AppState.addEventListener('change', (s) => {
      if (s === 'active') refresh();
    });
    return () => sub.remove();
  }, [refresh]);

  const missing = state ? PERMS.filter((p) => state[p.key] === 'ask' || state[p.key] === 'settings') : [];

  // One at a time, in order: each system dialog (or Settings page) has to finish before the next one opens.
  const allowAll = async () => {
    if (running.current) return;
    running.current = true;
    setBusy(true);
    try {
      for (const p of PERMS) {
        const cur = (await checkAll())[p.key];
        if (cur === 'ask' || cur === 'settings') await askPerm(p.key);
      }
    } finally {
      running.current = false;
      setBusy(false);
      refresh();
    }
  };

  if (!state) {
    return (
      <View style={[styles.root, styles.center]}>
        <ActivityIndicator color={colors.gold} size="large" />
      </View>
    );
  }
  if (!missing.length) return <>{children}</>;

  return (
    <View style={[styles.root, { paddingTop: Math.max(insets.top, 24) + 8, paddingBottom: Math.max(insets.bottom, 12) }]}>
      <ScrollView contentContainerStyle={styles.scroll}>
        <View style={styles.badge}>
          <Ionicons name="shield-checkmark" size={34} color={colors.gold} />
        </View>
        <Text style={styles.h1}>Allow what ETEL needs</Text>
        <Text style={styles.sub}>
          To take photos of your papers, find trips near you, follow a trip in the background and wake your phone for new requests, ETEL needs the permissions below. Photos and files from your gallery open through Android's own picker, so they need no permission.
        </Text>
        {PERMS.map((p) => {
          const st = state[p.key];
          const ok = st === 'granted' || st === 'na';
          return (
            <View key={p.key} style={[styles.row, ok && styles.rowOk]}>
              <View style={[styles.ico, ok && styles.icoOk]}>
                <Ionicons name={(ok ? 'checkmark' : p.icon) as keyof typeof Ionicons.glyphMap} size={20} color={ok ? colors.bg : colors.gold} />
              </View>
              <View style={{ flex: 1 }}>
                <Text style={styles.rt}>{p.title}</Text>
                <Text style={styles.rw}>{p.why}</Text>
              </View>
              {!ok ? (
                <Pressable style={styles.allow} onPress={() => askPerm(p.key).then(refresh)} accessibilityLabel={'Allow ' + p.title}>
                  <Text style={styles.allowT}>{st === 'settings' ? 'Settings' : 'Allow'}</Text>
                </Pressable>
              ) : null}
            </View>
          );
        })}
      </ScrollView>
      <Pressable style={[styles.cta, busy && { opacity: 0.6 }]} onPress={allowAll} disabled={busy}>
        <Text style={styles.ctaT}>{busy ? 'Please wait…' : 'Allow everything (' + missing.length + ' left)'}</Text>
      </Pressable>
      <Pressable onPress={() => Linking.openSettings().catch(() => {})} style={styles.link}>
        <Text style={styles.linkT}>Open the phone's app settings</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: colors.bg, paddingHorizontal: 18 },
  center: { alignItems: 'center', justifyContent: 'center' },
  scroll: { paddingBottom: 16 },
  badge: { width: 64, height: 64, borderRadius: 32, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface, alignItems: 'center', justifyContent: 'center', alignSelf: 'center', marginBottom: 14 },
  h1: { ...typography.h2, color: colors.text, textAlign: 'center' },
  sub: { ...typography.secondary, color: colors.textSecondary, textAlign: 'center', marginTop: 8, marginBottom: 16, lineHeight: 19 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: 16, padding: 12, marginBottom: 10 },
  rowOk: { opacity: 0.65 },
  ico: { width: 40, height: 40, borderRadius: 20, backgroundColor: colors.surface3, borderWidth: 1, borderColor: colors.border, alignItems: 'center', justifyContent: 'center' },
  icoOk: { backgroundColor: colors.successLight, borderColor: colors.successLight },
  rt: { color: colors.text, fontWeight: '700', fontSize: 15 },
  rw: { color: colors.textMuted, fontSize: 12, marginTop: 2, lineHeight: 16 },
  allow: { borderWidth: 1, borderColor: colors.gold, borderRadius: 10, paddingVertical: 8, paddingHorizontal: 12 },
  allowT: { color: colors.gold, fontWeight: '700', fontSize: 13 },
  cta: { backgroundColor: colors.gold, borderRadius: 16, paddingVertical: 16, alignItems: 'center' },
  ctaT: { color: colors.bg, fontWeight: '800', fontSize: 16 },
  link: { alignItems: 'center', paddingVertical: 12 },
  linkT: { color: colors.textMuted, fontSize: 13, textDecorationLine: 'underline' },
});

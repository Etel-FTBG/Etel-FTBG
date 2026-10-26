import React, { useEffect, useRef } from 'react';
import { Animated, Easing, Pressable, StyleSheet, View } from 'react-native';
import * as Updates from 'expo-updates';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Text } from '../i18n';
import { useTheme } from '../theme/ThemeContext';
import { success } from '../utils/haptics';
import { isOnline } from '../online/onlineState';
import { checkForUpdate, dismissUpdater, installUpdate, useUpdaterState } from '../updates/updater';

const STARTED = Date.now();

type Step = 'checking' | 'downloading' | 'ready' | 'installing' | 'current' | 'error' | null;

// The update card, in the style of a store update: Checking, Downloading with a percentage, Ready, Installing.
// A download that starts by itself in the background shows here too, so nobody wonders why the app is busy.
export default function UpdateBanner() {
  const { p } = useTheme();
  const insets = useSafeAreaInsets();
  const { isDownloading, isUpdatePending, downloadProgress } = Updates.useUpdates();
  const u = useUpdaterState();
  const y = useRef(new Animated.Value(-140)).current;
  const sweep = useRef(new Animated.Value(0)).current;

  let step: Step = null;
  if (u.phase === 'installing') step = 'installing';
  else if (isDownloading) step = 'downloading';
  else if (u.phase === 'checking') step = 'checking';
  else if (u.phase === 'error') step = 'error';
  else if (isUpdatePending) step = 'ready';
  else if (u.phase === 'current') step = 'current';

  // Fresh start (cleared storage, new install): the update that finishes downloading in the first moments installs by
  // itself, so the driver opens straight into the new version. Later updates, or while online, still wait for the tap.
  useEffect(() => {
    if (step === 'ready' && Date.now() - STARTED < 30000 && !isOnline()) installUpdate();
  }, [step]);

  useEffect(() => {
    if (step === 'ready') success();
    Animated.spring(y, { toValue: step ? 0 : -140, useNativeDriver: true, bounciness: 5 }).start();
  }, [step, y]);

  // "Up to date" and a failed check clear themselves.
  useEffect(() => {
    if (step !== 'current' && step !== 'error') return;
    const t = setTimeout(dismissUpdater, step === 'current' ? 2500 : 6000);
    return () => clearTimeout(t);
  }, [step]);

  // A bar with no known percentage (checking, installing) sweeps back and forth.
  const indeterminate = step === 'checking' || step === 'installing' || (step === 'downloading' && downloadProgress == null);
  useEffect(() => {
    if (!indeterminate) return;
    sweep.setValue(0);
    const loop = Animated.loop(Animated.timing(sweep, { toValue: 1, duration: 1100, easing: Easing.inOut(Easing.ease), useNativeDriver: true }));
    loop.start();
    return () => loop.stop();
  }, [indeterminate, sweep]);

  if (!step) return null;

  const pct = Math.max(0, Math.min(100, Math.round((downloadProgress ?? 0) * 100)));
  const on = p.mode === 'dark' ? '#0a0703' : '#FFFFFF';
  const icon: keyof typeof Ionicons.glyphMap =
    step === 'current' ? 'checkmark-circle' : step === 'error' ? 'alert-circle' : step === 'ready' ? 'sparkles' : step === 'installing' ? 'construct' : 'cloud-download';
  const title =
    step === 'checking' ? 'Checking for updates' :
    step === 'downloading' ? 'Downloading update' :
    step === 'ready' ? 'Update ready to install' :
    step === 'installing' ? 'Installing update' :
    step === 'current' ? 'ETEL is up to date' : 'Update failed';
  const sub =
    step === 'downloading' ? (downloadProgress == null ? 'Please wait' : pct + '%') :
    step === 'ready' ? 'Install now, the app restarts for a moment' :
    step === 'installing' ? 'The app will restart' :
    step === 'error' ? u.message : '';

  return (
    <Animated.View pointerEvents="box-none" style={[styles.wrap, { top: Math.max(insets.top, 24) + 20, transform: [{ translateY: y }] }]}>
      <View style={[styles.bar, { backgroundColor: p.surface, borderColor: step === 'error' ? '#E5484D' : p.gold }]}>
        <View style={styles.row}>
          <Ionicons name={icon} size={22} color={step === 'error' ? '#E5484D' : p.gold} />
          <View style={styles.txt}>
            <Text style={[styles.title, { color: p.text }]}>{title}</Text>
            {sub ? <Text style={[styles.sub, { color: p.textSecondary }]} numberOfLines={2}>{sub}</Text> : null}
          </View>
          {step === 'ready' ? (
            <Pressable onPress={installUpdate} style={[styles.btn, { backgroundColor: p.gold }]} accessibilityRole="button" accessibilityLabel="Install update">
              <Text style={[styles.btnText, { color: on }]}>Install</Text>
            </Pressable>
          ) : step === 'error' ? (
            <Pressable onPress={checkForUpdate} style={[styles.btn, { borderColor: p.gold, borderWidth: 1 }]} accessibilityRole="button" accessibilityLabel="Try again">
              <Text style={[styles.btnText, { color: p.gold }]}>Retry</Text>
            </Pressable>
          ) : null}
        </View>
        {step === 'checking' || step === 'downloading' || step === 'installing' ? (
          <View style={[styles.track, { backgroundColor: p.surfaceAlt }]}>
            {indeterminate ? (
              <Animated.View
                style={[styles.fill, styles.sweep, { backgroundColor: p.gold, transform: [{ translateX: sweep.interpolate({ inputRange: [0, 1], outputRange: [-90, 300] }) }] }]}
              />
            ) : (
              <View style={[styles.fill, { backgroundColor: p.gold, width: `${pct}%` as `${number}%` }]} />
            )}
          </View>
        ) : null}
      </View>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  wrap: { position: 'absolute', top: 44, left: 14, right: 14, zIndex: 900 },
  bar: { padding: 12, borderRadius: 16, borderWidth: 1 },
  row: { flexDirection: 'row', alignItems: 'center' },
  txt: { flex: 1, marginLeft: 10 },
  title: { fontSize: 14, fontWeight: '700' },
  sub: { fontSize: 12, marginTop: 2 },
  btn: { paddingVertical: 8, paddingHorizontal: 16, borderRadius: 12, marginLeft: 8 },
  btnText: { fontWeight: '800', fontSize: 13 },
  track: { height: 6, borderRadius: 3, marginTop: 10, overflow: 'hidden' },
  fill: { height: 6, borderRadius: 3 },
  sweep: { width: 90 },
});

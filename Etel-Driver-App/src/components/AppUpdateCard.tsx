import React, { useEffect, useRef, useState } from 'react';
import { Animated, Easing, Modal, Pressable, StyleSheet, View } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Text } from '../i18n';
import { useTheme } from '../theme/ThemeContext';
import { colors, typography } from '../theme/colors';
import { success, tap } from '../utils/haptics';
import {
  AppUpdateState,
  allowInstallsInSettings,
  cancelAppUpdate,
  checkAppUpdate,
  currentBuild,
  dismissAppUpdate,
  openAppInstaller,
  retryAppUpdate,
  startAppUpdate,
  useAppUpdate,
} from '../updates/appUpdate';

const TICKS = 72;
const RING = 196;
const mb = (b: number) => (b / 1048576).toFixed(b >= 10485760 ? 1 : 2);

function fmtEta(sec: number): string {
  if (!isFinite(sec) || sec <= 0) return '–';
  if (sec < 60) return Math.ceil(sec) + ' s';
  return Math.floor(sec / 60) + ' min ' + Math.ceil(sec % 60) + ' s';
}

// A ring of 72 gold ticks that fill as the download runs. With no known percentage a bright comet circles the ring instead.
function TickRing({ progress, spinning, done, children }: { progress: number; spinning: boolean; done: boolean; children?: React.ReactNode }) {
  const { p } = useTheme();
  const [head, setHead] = useState(0);
  const pulse = useRef(new Animated.Value(0)).current;
  useEffect(() => {
    const loop = Animated.loop(
      Animated.sequence([
        Animated.timing(pulse, { toValue: 1, duration: 900, easing: Easing.inOut(Easing.quad), useNativeDriver: true }),
        Animated.timing(pulse, { toValue: 0, duration: 900, easing: Easing.inOut(Easing.quad), useNativeDriver: true }),
      ])
    );
    loop.start();
    return () => loop.stop();
  }, [pulse]);
  useEffect(() => {
    if (!spinning) return;
    const t = setInterval(() => setHead((h) => (h + 1) % TICKS), 32);
    return () => clearInterval(t);
  }, [spinning]);

  const lit = Math.round(Math.max(0, Math.min(1, progress)) * TICKS);
  const ticks = [];
  for (let i = 0; i < TICKS; i++) {
    let bg: string = 'rgba(212,175,55,0.16)';
    let h = 12;
    if (done) {
      bg = '#4ADE80';
    } else if (spinning) {
      const d = (head - i + TICKS) % TICKS;
      if (d < 14) {
        bg = `rgba(242,204,107,${1 - d / 14})`;
        h = 12 + (14 - d) * 0.5;
      }
    } else if (i < lit) {
      bg = i === lit - 1 ? colors.goldLight : p.gold;
      h = i === lit - 1 ? 18 : 12;
    }
    ticks.push(
      <View
        key={i}
        style={[
          styles.tick,
          { height: h, backgroundColor: bg, transform: [{ rotate: (i * 360) / TICKS + 'deg' }, { translateY: -(RING / 2 - 12) }] },
        ]}
      />
    );
  }
  return (
    <View style={styles.ring}>
      <Animated.View
        pointerEvents="none"
        style={[styles.glow, { opacity: pulse.interpolate({ inputRange: [0, 1], outputRange: done ? [0.35, 0.5] : [0.18, 0.5] }), backgroundColor: done ? '#4ADE80' : colors.gold }]}
      />
      {ticks}
      <View style={styles.ringCenter}>{children}</View>
    </View>
  );
}

function Steps({ phase }: { phase: AppUpdateState['phase'] }) {
  const { p } = useTheme();
  const idx = phase === 'downloading' ? 0 : phase === 'verifying' ? 1 : phase === 'permission' || phase === 'ready' || phase === 'installing' ? 2 : -1;
  const done = phase === 'installing';
  const steps = ['Download', 'Verify', 'Install'];
  return (
    <View style={styles.steps}>
      {steps.map((label, i) => {
        const complete = i < idx || (done && i === 2);
        const active = i === idx && !done;
        return (
          <React.Fragment key={label}>
            {i > 0 ? <View style={[styles.stepLine, { backgroundColor: i <= idx ? p.gold : p.borderSoft }]} /> : null}
            <View style={styles.step}>
              <View
                style={[
                  styles.stepDot,
                  { borderColor: complete || active ? p.gold : p.borderSoft, backgroundColor: complete ? p.gold : 'transparent' },
                ]}>
                {complete ? <Ionicons name="checkmark" size={14} color="#0a0703" /> : <Text style={{ color: active ? p.gold : p.textMuted, fontSize: 12, fontWeight: '700' }}>{i + 1}</Text>}
              </View>
              <Text style={{ color: complete || active ? p.text : p.textMuted, fontSize: 11, marginTop: 4 }}>{label}</Text>
            </View>
          </React.Fragment>
        );
      })}
    </View>
  );
}

function Sheet({ s, onClose }: { s: AppUpdateState; onClose: () => void }) {
  const { p } = useTheme();
  const insets = useSafeAreaInsets();
  const rise = useRef(new Animated.Value(0)).current;
  useEffect(() => {
    Animated.spring(rise, { toValue: 1, useNativeDriver: true, bounciness: 4, speed: 12 }).start();
  }, [rise]);
  useEffect(() => {
    if (s.phase === 'installing') success();
  }, [s.phase]);

  const info = s.info;
  const busy = s.phase === 'downloading' || s.phase === 'verifying';
  const pct = s.total > 0 ? Math.min(1, s.received / s.total) : 0;
  const eta = s.speed > 0 && s.total > 0 ? (s.total - s.received) / s.speed : NaN;
  const totalBytes = s.total || info?.sizeBytes || 0;
  const cur = currentBuild();

  const title =
    s.phase === 'downloading' ? 'Downloading ETEL' + (info?.versionName ? ' ' + info.versionName : '') :
    s.phase === 'verifying' ? 'Checking the file' :
    s.phase === 'permission' ? 'One permission needed' :
    s.phase === 'installing' ? 'Opening the installer' :
    s.phase === 'ready' ? 'Ready to install' :
    s.phase === 'error' ? 'Update failed' :
    'ETEL ' + (info?.versionName || 'update') + ' is ready';
  const sub =
    s.phase === 'downloading' ? 'Keep the app open. This takes a moment.' :
    s.phase === 'verifying' ? 'Making sure the download is complete and safe.' :
    s.phase === 'permission' ? 'Allow ETEL to install updates, then come back. It continues by itself.' :
    s.phase === 'installing' ? 'Tap Install on the Android screen. ETEL restarts when it finishes.' :
    s.phase === 'ready' ? 'The file is downloaded. Open the installer to finish.' :
    s.phase === 'error' ? s.message || 'Something went wrong. Please try again.' :
    'A new version of the app is available' + (cur.name ? ' (you have ' + cur.name + ')' : '') + '.';

  let primary: { label: string; icon: keyof typeof Ionicons.glyphMap; run: () => void } | null = null;
  if (s.phase === 'available') primary = { label: 'Download and install', icon: 'cloud-download', run: startAppUpdate };
  else if (s.phase === 'permission') primary = { label: 'Allow installing', icon: 'shield-checkmark', run: allowInstallsInSettings };
  else if (s.phase === 'ready' || s.phase === 'installing') primary = { label: 'Open installer', icon: 'open', run: openAppInstaller };
  else if (s.phase === 'error') primary = { label: 'Try again', icon: 'refresh', run: retryAppUpdate };

  const spinning = s.phase === 'verifying' || s.phase === 'permission' || (s.phase === 'downloading' && s.total <= 0);
  const done = s.phase === 'ready' || s.phase === 'installing';
  const failed = s.phase === 'error';

  return (
    <Modal visible transparent animationType="fade" onRequestClose={busy ? undefined : onClose} statusBarTranslucent>
      <View style={styles.backdrop}>
        <Pressable style={StyleSheet.absoluteFill} onPress={busy || info?.required ? undefined : onClose} />
        <Animated.View style={{ transform: [{ translateY: rise.interpolate({ inputRange: [0, 1], outputRange: [80, 0] }) }], opacity: rise }}>
          <LinearGradient
            colors={['#1d1409', '#0e0905', '#0a0703']}
            style={[styles.sheet, { borderColor: p.gold, paddingBottom: Math.max(insets.bottom, 16) + 8 }]}>
            <View style={[styles.grab, { backgroundColor: p.borderSoft }]} />
            <View style={styles.center}>
              <TickRing progress={pct} spinning={spinning} done={done}>
                {failed ? (
                  <Ionicons name="alert-circle" size={64} color="#E5484D" />
                ) : done ? (
                  <Ionicons name="checkmark" size={72} color="#4ADE80" />
                ) : s.phase === 'downloading' && s.total > 0 ? (
                  <>
                    <Text style={styles.pct}>{Math.round(pct * 100)}<Text style={styles.pctSign}>%</Text></Text>
                    <Text style={[styles.pctSub, { color: p.textMuted }]}>{mb(s.received)} / {mb(totalBytes)} MB</Text>
                  </>
                ) : (
                  <Ionicons name={s.phase === 'permission' ? 'shield-checkmark' : s.phase === 'verifying' ? 'lock-closed' : 'cloud-download'} size={56} color={p.gold} />
                )}
              </TickRing>
              <Text style={[styles.title, { color: p.text }]}>{title}</Text>
              <Text style={[styles.sub, { color: p.textSecondary }]}>{sub}</Text>
            </View>

            {s.phase === 'downloading' ? (
              <View style={styles.stats}>
                <View style={[styles.stat, { borderColor: p.borderSoft }]}>
                  <Ionicons name="speedometer-outline" size={16} color={p.gold} />
                  <Text style={[styles.statVal, { color: p.text }]}>{s.speed > 0 ? mb(s.speed) + ' MB/s' : '–'}</Text>
                  <Text style={[styles.statLbl, { color: p.textMuted }]}>Speed</Text>
                </View>
                <View style={[styles.stat, { borderColor: p.borderSoft }]}>
                  <Ionicons name="time-outline" size={16} color={p.gold} />
                  <Text style={[styles.statVal, { color: p.text }]}>{fmtEta(eta)}</Text>
                  <Text style={[styles.statLbl, { color: p.textMuted }]}>Time left</Text>
                </View>
                <View style={[styles.stat, { borderColor: p.borderSoft }]}>
                  <Ionicons name="archive-outline" size={16} color={p.gold} />
                  <Text style={[styles.statVal, { color: p.text }]}>{totalBytes > 0 ? mb(totalBytes) + ' MB' : '–'}</Text>
                  <Text style={[styles.statLbl, { color: p.textMuted }]}>Size</Text>
                </View>
              </View>
            ) : null}

            {s.phase !== 'available' && s.phase !== 'error' ? <Steps phase={s.phase} /> : null}

            {s.phase === 'available' && info?.notes?.length ? (
              <View style={[styles.notes, { borderColor: p.borderSoft }]}>
                <Text style={[styles.notesHead, { color: p.gold }]}>WHAT'S NEW</Text>
                {info.notes.map((n, i) => (
                  <View key={i} style={styles.noteRow}>
                    <Ionicons name="sparkles" size={13} color={p.gold} style={{ marginTop: 3 }} />
                    <Text style={[styles.noteTxt, { color: p.text }]}>{n}</Text>
                  </View>
                ))}
              </View>
            ) : null}

            <View style={styles.actions}>
              {primary ? (
                <Pressable
                  onPress={() => {
                    tap();
                    primary!.run();
                  }}
                  accessibilityRole="button"
                  style={styles.primaryWrap}>
                  <LinearGradient colors={[colors.goldLight, colors.gold, colors.goldDark]} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.primary}>
                    <Ionicons name={primary.icon} size={20} color="#0a0703" />
                    <Text style={styles.primaryTxt}>{primary.label}</Text>
                  </LinearGradient>
                </Pressable>
              ) : null}
              {busy ? (
                s.phase === 'downloading' ? (
                  <Pressable onPress={cancelAppUpdate} style={[styles.ghost, { borderColor: p.borderSoft }]} accessibilityRole="button">
                    <Text style={{ color: p.textSecondary, fontWeight: '600' }}>Cancel</Text>
                  </Pressable>
                ) : null
              ) : !info?.required ? (
                <Pressable onPress={onClose} style={[styles.ghost, { borderColor: p.borderSoft }]} accessibilityRole="button">
                  <Text style={{ color: p.textSecondary, fontWeight: '600' }}>{done ? 'Close' : 'Later'}</Text>
                </Pressable>
              ) : null}
            </View>
          </LinearGradient>
        </Animated.View>
      </View>
    </Modal>
  );
}

// The card on the hub that announces a new app version, and the sheet it opens.
export default function AppUpdateCard() {
  const { p } = useTheme();
  const s = useAppUpdate();
  const [open, setOpen] = useState(false);

  useEffect(() => {
    checkAppUpdate();
  }, []);
  // A required update opens by itself; the card is the way back in once it is closed.
  useEffect(() => {
    if (s.info?.required && s.phase !== 'none') setOpen(true);
  }, [s.info?.required, s.phase]);

  if (s.phase === 'none' || !s.info) return null;
  const busy = s.phase === 'downloading' || s.phase === 'verifying';
  const pct = s.total > 0 ? Math.round((s.received / s.total) * 100) : 0;
  return (
    <>
      <Pressable
        onPress={() => {
          tap();
          setOpen(true);
        }}
        accessibilityRole="button"
        style={styles.cardWrap}>
        <LinearGradient colors={['#2a1d0b', '#150e06']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={[styles.card, { borderColor: p.gold }]}>
          <View style={[styles.cardIcon, { borderColor: p.gold }]}>
            <Ionicons name={busy ? 'cloud-download' : 'rocket'} size={22} color={p.gold} />
          </View>
          <View style={{ flex: 1 }}>
            <Text style={[styles.cardTitle, { color: p.text }]}>{busy ? 'Downloading ' + pct + '%' : 'New ETEL version ' + (s.info.versionName || '')}</Text>
            <Text style={[styles.cardSub, { color: p.textSecondary }]}>
              {busy ? 'Tap to see progress' : (s.info.sizeBytes ? mb(s.info.sizeBytes) + ' MB · ' : '') + 'Tap to update'}
            </Text>
          </View>
          <Ionicons name="chevron-forward" size={20} color={p.gold} />
        </LinearGradient>
      </Pressable>
      {open ? <Sheet s={s} onClose={() => { dismissAppUpdate(); setOpen(false); }} /> : null}
    </>
  );
}

const styles = StyleSheet.create({
  cardWrap: { marginBottom: 14 },
  card: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: 14, borderRadius: 18, borderWidth: 1 },
  cardIcon: { width: 42, height: 42, borderRadius: 21, borderWidth: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: 'rgba(212,175,55,0.1)' },
  cardTitle: { fontSize: 15, fontFamily: typography.h2.fontFamily },
  cardSub: { fontSize: 12, marginTop: 2 },
  backdrop: { flex: 1, backgroundColor: 'rgba(0,0,0,0.72)', justifyContent: 'flex-end' },
  sheet: { borderTopLeftRadius: 30, borderTopRightRadius: 30, borderWidth: 1, borderBottomWidth: 0, paddingHorizontal: 22, paddingTop: 10 },
  grab: { alignSelf: 'center', width: 44, height: 4, borderRadius: 2, marginBottom: 14 },
  center: { alignItems: 'center' },
  ring: { width: RING, height: RING, alignItems: 'center', justifyContent: 'center', marginTop: 6 },
  glow: { position: 'absolute', width: RING - 56, height: RING - 56, borderRadius: RING, opacity: 0.25, transform: [{ scale: 1.1 }] },
  tick: { position: 'absolute', width: 3, borderRadius: 2 },
  ringCenter: { position: 'absolute', alignItems: 'center', justifyContent: 'center' },
  pct: { color: colors.goldLight, fontSize: 46, fontFamily: typography.metric.fontFamily },
  pctSign: { fontSize: 20 },
  pctSub: { fontSize: 11, marginTop: 2, fontFamily: typography.label.fontFamily },
  title: { fontSize: 22, textAlign: 'center', marginTop: 18, fontFamily: typography.h2.fontFamily },
  sub: { fontSize: 13.5, textAlign: 'center', marginTop: 6, lineHeight: 20, paddingHorizontal: 8 },
  stats: { flexDirection: 'row', gap: 10, marginTop: 20 },
  stat: { flex: 1, alignItems: 'center', paddingVertical: 12, borderRadius: 16, borderWidth: 1, backgroundColor: 'rgba(212,175,55,0.05)' },
  statVal: { fontSize: 15, marginTop: 6, fontFamily: typography.metric.fontFamily },
  statLbl: { fontSize: 10.5, marginTop: 2 },
  steps: { flexDirection: 'row', alignItems: 'flex-start', justifyContent: 'center', marginTop: 20 },
  step: { alignItems: 'center', width: 64 },
  stepDot: { width: 26, height: 26, borderRadius: 13, borderWidth: 1.5, alignItems: 'center', justifyContent: 'center' },
  stepLine: { height: 2, width: 44, marginTop: 12 },
  notes: { marginTop: 18, padding: 14, borderRadius: 16, borderWidth: 1, backgroundColor: 'rgba(212,175,55,0.05)' },
  notesHead: { fontSize: 11, letterSpacing: 1.6, marginBottom: 8, fontFamily: typography.label.fontFamily },
  noteRow: { flexDirection: 'row', gap: 8, marginTop: 4 },
  noteTxt: { flex: 1, fontSize: 13.5, lineHeight: 19 },
  actions: { marginTop: 22, gap: 10 },
  primaryWrap: { borderRadius: 18, overflow: 'hidden' },
  primary: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 10, paddingVertical: 16 },
  primaryTxt: { color: '#0a0703', fontSize: 16, fontFamily: typography.h2.fontFamily },
  ghost: { alignItems: 'center', paddingVertical: 14, borderRadius: 18, borderWidth: 1 },
});

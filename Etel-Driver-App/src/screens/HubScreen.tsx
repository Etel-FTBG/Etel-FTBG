import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Alert as RNAlert, Animated, Easing, Image, Pressable, RefreshControl, ScrollView, StyleSheet, View } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { Text, translate, useLanguage } from '../i18n';
import { typography } from '../theme/colors';
import { useTheme } from '../theme/ThemeContext';
import { RootStackParamList } from '../navigation/types';
import { HUB_GROUPS, HubApp } from '../hub/apps';
import { goOfflineEverywhere, goOnlineEverywhere, goOnlinePreview } from '../hub/masterOnline';
import SlideToConfirm from '../components/SlideToConfirm';
import PillSwitch from '../components/PillSwitch';
import Reveal from '../components/Reveal';
import UpdateStatus from '../components/UpdateStatus';
import AppUpdateCard from '../components/AppUpdateCard';
import { success, tap } from '../utils/haptics';
import { isOnline, subscribeOnline } from '../online/onlineState';
import SignInCard from '../components/SignInCard';
import ProfileCard from '../components/ProfileCard';
import CompaniesCard from '../components/CompaniesCard';
import { canGoOnline, canOpen, useSession } from '../hub/session';
import { checkForUpdate } from '../updates/updater';

type Props = NativeStackScreenProps<RootStackParamList, 'Hub'>;

function greeting(): string {
  const h = new Date().getHours();
  return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening';
}

export default function HubScreen({ navigation }: Props) {
  const insets = useSafeAreaInsets();
  const { p, mode, toggle: toggleTheme } = useTheme();
  const { lang, toggle: toggleLang } = useLanguage();
  const [online, setOnline] = useState(isOnline());
  const [busy, setBusy] = useState(false);
  const [where, setWhere] = useState<string[]>([]);
  const session = useSession();
  const working = canGoOnline(session?.role);
  useEffect(() => subscribeOnline(setOnline), []);

  // Refresh: reloads the profile and company cards (new key = fresh fetch) and looks for a new app version.
  const [refreshing, setRefreshing] = useState(false);
  const [refreshKey, setRefreshKey] = useState(0);
  const spin = useRef(new Animated.Value(0)).current;
  const refresh = useCallback(async () => {
    if (refreshing) return;
    tap();
    setRefreshing(true);
    setRefreshKey((k) => k + 1);
    spin.setValue(0);
    const loop = Animated.loop(Animated.timing(spin, { toValue: 1, duration: 800, easing: Easing.linear, useNativeDriver: true }));
    loop.start();
    try {
      await Promise.all([checkForUpdate(), new Promise((r) => setTimeout(r, 700))]);
    } finally {
      loop.stop();
      setRefreshing(false);
    }
  }, [refreshing, spin]);

  async function goOnline() {
    if (!working) {
      goOnlinePreview();
      success();
      return;
    }
    setBusy(true);
    try {
      setWhere(await goOnlineEverywhere());
      success();
    } catch (e) {
      RNAlert.alert(translate('Could not go online'), e instanceof Error ? translate(e.message) : translate('Please try again.'));
    } finally {
      setBusy(false);
    }
  }

  async function goOffline() {
    setBusy(true);
    try {
      await goOfflineEverywhere();
      setWhere([]);
    } finally {
      setBusy(false);
    }
  }

  function open(a: HubApp) {
    if (a.status !== 'live') return;
    if (a.key !== 'id' && !session) {
      RNAlert.alert(translate('Sign in first'), translate('Sign in with your EtelID above to open this app.'));
      return;
    }
    if (!canOpen(a.key, session?.role)) {
      RNAlert.alert(translate('Not for your account'), translate('This app is for a different role. Your EtelID opens the apps that match your role.'));
      return;
    }
    tap();
    navigation.navigate('WebApp', { appKey: a.key });
  }

  return (
    <ScrollView
      style={[styles.root, { backgroundColor: p.bg }]}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={p.gold} colors={[p.gold]} progressBackgroundColor={p.surface} />}
      contentContainerStyle={[styles.content, { paddingTop: Math.max(insets.top, 24) + 24, paddingBottom: insets.bottom + 40 }]}>
      <View style={styles.top}>
        <PillSwitch
          value={mode === 'light'}
          onToggle={() => { tap(); toggleTheme(); }}
          left={{ icon: 'moon' }}
          right={{ icon: 'sunny' }}
          accessibilityLabel="Light or dark"
        />
        <Pressable
          onPress={refresh}
          disabled={refreshing}
          accessibilityRole="button"
          accessibilityLabel="Refresh"
          style={[styles.refresh, { backgroundColor: p.surface, borderColor: p.border }]}
        >
          <Animated.View style={{ transform: [{ rotate: spin.interpolate({ inputRange: [0, 1], outputRange: ['0deg', '360deg'] }) }] }}>
            <Ionicons name="refresh" size={19} color={p.gold} />
          </Animated.View>
        </Pressable>
        <PillSwitch
          value={lang === 'am'}
          onToggle={() => { tap(); toggleLang(); }}
          left={{ label: 'EN' }}
          right={{ label: 'አማ' }}
          accessibilityLabel="English or Amharic"
        />
      </View>

      <Reveal delay={60} style={styles.brand}>
        <View style={[styles.halo, { backgroundColor: p.glow }]}>
          <Image source={require('../../assets/etel-master.png')} style={styles.master} accessibilityLabel="ETEL" />
        </View>
        <Text style={[styles.wordmark, { color: p.gold }]}>ETEL</Text>
        <Text style={[styles.tagline, { color: p.textSecondary }]}>One account. One ecosystem.</Text>
        <Text style={[styles.greet, { color: p.goldStrong }]}>{greeting()}</Text>
      </Reveal>

      <Reveal delay={160}>
        <AppUpdateCard />
        {session ? <ProfileCard key={refreshKey} session={session} /> : <SignInCard />}
      </Reveal>

      {session ? (
      <Reveal delay={160}>
      <LinearGradient
        colors={online ? [p.glow, p.surface] : [p.surface, p.surface]}
        style={[styles.card, { borderColor: online ? p.gold : p.border }]}
      >
        <View style={styles.cardHead}>
          <View style={[styles.dot, { backgroundColor: online ? p.gold : p.textMuted }]} />
          <Text style={[styles.cardTitle, { color: p.text }]}>{online ? 'You are online' : working ? 'Go online everywhere' : 'Try going online'}</Text>
        </View>
        <Text style={[styles.cardSub, { color: p.textSecondary }]}>
          {!working
            ? online
              ? 'Preview: the golden light shows with sample counts while you use other apps.'
              : 'Preview mode. Your account is not a driver, courier or service giver, so this only shows the golden light with sample counts.'
            : online
              ? where.length
                ? translate('Online in: ') + where.map((w) => translate(w)).join(', ')
                : 'The golden ETEL light shows while you use other apps.'
              : 'One slide puts you online in every ETEL company you belong to.'}
        </Text>
        <View style={styles.slider}>
          {busy ? (
            <ActivityIndicator color={p.gold} />
          ) : online ? (
            <SlideToConfirm
              label={translate('Slide to go offline')}
              onConfirm={goOffline}
              trackColor={'rgba(229,72,77,0.22)'}
              thumbColor={'#E5484D'}
              textColor={p.text}
            />
          ) : (
            <SlideToConfirm
              label={translate('Slide to go online')}
              onConfirm={goOnline}
              trackColor={p.surfaceAlt}
              thumbColor={p.gold}
              textColor={p.text}
            />
          )}
        </View>
      </LinearGradient>
      </Reveal>
      ) : null}

      {session && canGoOnline(session.role) ? (
        <Reveal delay={200}>
          <CompaniesCard key={refreshKey} role={session.role} />
        </Reveal>
      ) : null}

      {HUB_GROUPS.map((g, gi) => (
        <Reveal key={g.title} delay={260 + gi * 120} style={styles.group}>
          <Text style={[styles.groupTitle, { color: p.goldStrong }]}>{g.title}</Text>
          <View style={styles.grid}>
            {g.apps.map((a) => {
              const soon = a.status === 'soon';
              const allowed = a.key === 'id' || (!!session && canOpen(a.key, session.role));
              const wrongRole = !soon && !!session && !allowed;
              return (
                <Pressable
                  key={a.key}
                  disabled={soon}
                  onPress={() => open(a)}
                  accessibilityRole="button"
                  accessibilityState={{ disabled: soon }}
                  accessibilityLabel={a.title + (soon ? ', coming soon' : '')}
                  style={({ pressed }) => [
                    styles.tile,
                    { backgroundColor: p.surface, borderColor: soon ? p.borderSoft : p.border },
                    mode === 'light' && styles.shadow,
                    pressed && !soon && { transform: [{ scale: 0.97 }] },
                  ]}
                >
                  <View style={soon || wrongRole ? styles.dim : !allowed ? styles.faded : undefined}>
                    <Image source={a.logo} style={styles.logo} />
                  </View>
                  <Text style={[styles.tileTitle, { color: soon ? p.textMuted : p.text }]}>{a.title}</Text>
                  {soon ? (
                    <View style={[styles.badge, { borderColor: p.borderSoft, backgroundColor: p.surfaceAlt }]}>
                      <Ionicons name="lock-closed" size={11} color={p.textMuted} />
                      <Text style={[styles.badgeText, { color: p.textMuted }]}>Coming soon</Text>
                    </View>
                  ) : wrongRole ? (
                    <View style={[styles.badge, { borderColor: p.borderSoft, backgroundColor: p.surfaceAlt }]}>
                      <Ionicons name="lock-closed" size={11} color={p.textMuted} />
                      <Text style={[styles.badgeText, { color: p.textMuted }]}>Other role</Text>
                    </View>
                  ) : (
                    <Text style={[styles.tileSub, { color: p.textMuted }]}>{a.subtitle}</Text>
                  )}
                </Pressable>
              );
            })}
          </View>
        </Reveal>
      ))}

      <UpdateStatus />
      <Text style={[styles.foot, { color: p.textMuted }]}>ETEL Family Technology and Business Group</Text>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1 },
  content: { padding: 20, paddingTop: 48, paddingBottom: 40 },
  top: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  refresh: { width: 38, height: 38, borderRadius: 19, borderWidth: 1, alignItems: 'center', justifyContent: 'center' },
  brand: { alignItems: 'center', marginTop: 8, marginBottom: 20 },
  halo: { width: 132, height: 132, borderRadius: 66, alignItems: 'center', justifyContent: 'center' },
  master: { width: 112, height: 112, resizeMode: 'contain' },
  wordmark: { fontSize: 30, letterSpacing: 8, marginTop: 8, fontFamily: typography.h1.fontFamily },
  tagline: { fontSize: 13, marginTop: 4, letterSpacing: 0.4 },
  greet: { fontSize: 20, marginTop: 10, fontFamily: 'CormorantGaramond_600SemiBold' },
  card: { padding: 16, borderRadius: 20, borderWidth: 1, marginBottom: 8 },
  cardHead: { flexDirection: 'row', alignItems: 'center' },
  dot: { width: 9, height: 9, borderRadius: 5, marginRight: 8 },
  cardTitle: { fontSize: 17, fontFamily: typography.h2.fontFamily },
  cardSub: { marginTop: 6, marginBottom: 14, lineHeight: 19, fontSize: 13 },
  slider: { minHeight: 60, justifyContent: 'center' },
  group: { marginTop: 18 },
  groupTitle: { fontSize: 12, letterSpacing: 2, textTransform: 'uppercase', marginBottom: 10, fontWeight: '700' },
  grid: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between' },
  tile: {
    width: '48%',
    minHeight: 158,
    marginBottom: 12,
    padding: 14,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 20,
    borderWidth: 1,
  },
  dim: { opacity: 0.38 },
  faded: { opacity: 0.7 },
  shadow: { shadowColor: '#8A6508', shadowOpacity: 0.16, shadowRadius: 10, shadowOffset: { width: 0, height: 4 }, elevation: 3 },
  logo: { width: 78, height: 78, resizeMode: 'contain' },
  tileTitle: { fontSize: 15, marginTop: 8, textAlign: 'center', fontFamily: typography.h2.fontFamily },
  tileSub: { fontSize: 12, marginTop: 2, textAlign: 'center' },
  badge: {
    flexDirection: 'row',
    alignItems: 'center',
    marginTop: 6,
    paddingVertical: 3,
    paddingHorizontal: 9,
    borderRadius: 10,
    borderWidth: 1,
  },
  badgeText: { fontSize: 11, marginLeft: 4, fontWeight: '700' },
  foot: { textAlign: 'center', fontSize: 11, marginTop: 20, letterSpacing: 0.5 },
});

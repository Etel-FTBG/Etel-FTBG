import React, { useEffect, useState } from 'react';
import { ActivityIndicator, Alert as RNAlert, Image, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { Ionicons } from '@expo/vector-icons';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { Text, translate, useLanguage } from '../i18n';
import { typography } from '../theme/colors';
import { useTheme } from '../theme/ThemeContext';
import { RootStackParamList } from '../navigation/types';
import { HUB_GROUPS, HubApp } from '../hub/apps';
import { goOfflineEverywhere, goOnlineEverywhere } from '../hub/masterOnline';
import SlideToConfirm from '../components/SlideToConfirm';
import PillSwitch from '../components/PillSwitch';
import { isOnline, subscribeOnline } from '../online/onlineState';

type Props = NativeStackScreenProps<RootStackParamList, 'Hub'>;

export default function HubScreen({ navigation }: Props) {
  const { p, mode, toggle: toggleTheme } = useTheme();
  const { lang, toggle: toggleLang } = useLanguage();
  const [online, setOnline] = useState(isOnline());
  const [busy, setBusy] = useState(false);
  const [where, setWhere] = useState<string[]>([]);
  useEffect(() => subscribeOnline(setOnline), []);

  async function goOnline() {
    setBusy(true);
    try {
      setWhere(await goOnlineEverywhere());
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
    navigation.navigate('WebApp', { appKey: a.key });
  }

  return (
    <ScrollView style={[styles.root, { backgroundColor: p.bg }]} contentContainerStyle={styles.content}>
      <View style={styles.top}>
        <PillSwitch
          value={mode === 'light'}
          onToggle={toggleTheme}
          left={{ icon: 'moon' }}
          right={{ icon: 'sunny' }}
          accessibilityLabel="Light or dark"
        />
        <PillSwitch
          value={lang === 'am'}
          onToggle={toggleLang}
          left={{ label: 'EN' }}
          right={{ label: 'አማ' }}
          accessibilityLabel="English or Amharic"
        />
      </View>

      <View style={styles.brand}>
        <View style={[styles.halo, { backgroundColor: p.glow }]}>
          <Image source={require('../../assets/etel-master.png')} style={styles.master} accessibilityLabel="ETEL" />
        </View>
        <Text style={[styles.wordmark, { color: p.gold }]}>ETEL</Text>
        <Text style={[styles.tagline, { color: p.textSecondary }]}>One account. One ecosystem.</Text>
      </View>

      <LinearGradient
        colors={online ? [p.glow, p.surface] : [p.surface, p.surface]}
        style={[styles.card, { borderColor: online ? p.gold : p.border }]}
      >
        <View style={styles.cardHead}>
          <View style={[styles.dot, { backgroundColor: online ? p.gold : p.textMuted }]} />
          <Text style={[styles.cardTitle, { color: p.text }]}>{online ? 'You are online' : 'Go online everywhere'}</Text>
        </View>
        <Text style={[styles.cardSub, { color: p.textSecondary }]}>
          {online
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
              trackColor={p.surfaceAlt}
              thumbColor={p.gold}
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

      {HUB_GROUPS.map((g) => (
        <View key={g.title} style={styles.group}>
          <Text style={[styles.groupTitle, { color: p.goldStrong }]}>{g.title}</Text>
          <View style={styles.grid}>
            {g.apps.map((a) => {
              const soon = a.status === 'soon';
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
                    pressed && !soon && { transform: [{ scale: 0.97 }] },
                  ]}
                >
                  <View style={soon ? styles.dim : undefined}>
                    <Image source={a.logo} style={styles.logo} />
                  </View>
                  <Text style={[styles.tileTitle, { color: soon ? p.textMuted : p.text }]}>{a.title}</Text>
                  {soon ? (
                    <View style={[styles.badge, { borderColor: p.borderSoft, backgroundColor: p.surfaceAlt }]}>
                      <Ionicons name="lock-closed" size={11} color={p.textMuted} />
                      <Text style={[styles.badgeText, { color: p.textMuted }]}>Coming soon</Text>
                    </View>
                  ) : (
                    <Text style={[styles.tileSub, { color: p.textMuted }]}>{a.subtitle}</Text>
                  )}
                </Pressable>
              );
            })}
          </View>
        </View>
      ))}

      <Text style={[styles.foot, { color: p.textMuted }]}>ETEL Family Technology and Business Group</Text>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1 },
  content: { padding: 20, paddingTop: 48, paddingBottom: 40 },
  top: { flexDirection: 'row', justifyContent: 'space-between' },
  brand: { alignItems: 'center', marginTop: 8, marginBottom: 20 },
  halo: { width: 132, height: 132, borderRadius: 66, alignItems: 'center', justifyContent: 'center' },
  master: { width: 112, height: 112, resizeMode: 'contain' },
  wordmark: { fontSize: 30, letterSpacing: 8, marginTop: 8, fontFamily: typography.h1.fontFamily },
  tagline: { fontSize: 13, marginTop: 4, letterSpacing: 0.4 },
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

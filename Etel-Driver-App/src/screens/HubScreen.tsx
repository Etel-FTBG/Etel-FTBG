import React, { useEffect, useState } from 'react';
import { ActivityIndicator, Alert, Image, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { Text } from '../i18n';
import { colors, typography } from '../theme/colors';
import { RootStackParamList } from '../navigation/types';
import { HUB_APPS } from '../hub/apps';
import { goOfflineEverywhere, goOnlineEverywhere } from '../hub/masterOnline';
import SlideToConfirm from '../components/SlideToConfirm';
import { useAuth } from '../context/AuthContext';
import { isOnline, subscribeOnline } from '../online/onlineState';

type Props = NativeStackScreenProps<RootStackParamList, 'Hub'>;

export default function HubScreen({ navigation }: Props) {
  const { user } = useAuth();
  const [online, setOnline] = useState(isOnline());
  const [busy, setBusy] = useState(false);
  const [where, setWhere] = useState<string[]>([]);
  useEffect(() => subscribeOnline(setOnline), []);

  async function goOnline() {
    setBusy(true);
    try {
      setWhere(await goOnlineEverywhere());
    } catch (e) {
      Alert.alert('Could not go online', e instanceof Error ? e.message : 'Please try again.');
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

  return (
    <ScrollView style={styles.root} contentContainerStyle={styles.content}>
      <View style={styles.head}>
        <Image source={require('../../assets/etel-master.png')} style={styles.master} accessibilityLabel="ETEL" />
        <View style={[styles.pill, online && styles.pillOn]}>
          <View style={[styles.dot, online && styles.dotOn]} />
          <Text style={[styles.pillText, online && styles.pillTextOn]}>{online ? 'Online' : 'Offline'}</Text>
        </View>
      </View>

      <View style={styles.card}>
        <Text style={styles.cardTitle}>{online ? 'You are online' : 'Go online everywhere'}</Text>
        <Text style={styles.cardSub}>
          {online
            ? where.length
              ? 'Online in: ' + where.join(', ') + '. The golden ETEL light shows while you use other apps.'
              : 'The golden ETEL light shows while you use other apps.'
            : 'One slide puts you online in every ETEL company you belong to: Ride, Delivery and Services.'}
        </Text>
        <View style={styles.slider}>
          {busy ? (
            <ActivityIndicator color={colors.gold} />
          ) : online ? (
            <SlideToConfirm label="Slide to go offline" onConfirm={goOffline} trackColor={colors.surface3} />
          ) : (
            <SlideToConfirm label="Slide to go online" onConfirm={goOnline} trackColor={colors.surface3} />
          )}
        </View>
      </View>

      <View style={styles.grid}>
        {HUB_APPS.map((a) => (
          <Pressable
            key={a.key}
            style={styles.tile}
            onPress={() => navigation.navigate('WebApp', { appKey: a.key })}
            accessibilityRole="button"
            accessibilityLabel={a.title}
          >
            <Image source={a.logo} style={styles.logo} />
            <Text style={styles.tileTitle}>{a.title}</Text>
            <Text style={styles.tileSub}>{a.subtitle}</Text>
          </Pressable>
        ))}
        <Pressable
          style={styles.tile}
          onPress={() => navigation.navigate(user ? 'MainTabs' : 'Login')}
          accessibilityRole="button"
          accessibilityLabel="Driver (native)"
        >
          <Image source={require('../../assets/etel-master.png')} style={styles.logo} />
          <Text style={styles.tileTitle}>Driver (native)</Text>
          <Text style={styles.tileSub}>{user ? 'Open' : 'Sign in'}</Text>
        </Pressable>
      </View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: colors.bg },
  content: { padding: 20, paddingTop: 52, paddingBottom: 40 },
  head: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  master: { width: 72, height: 72, resizeMode: 'contain' },
  pill: {
    flexDirection: 'row',
    alignItems: 'center',
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 20,
    paddingVertical: 6,
    paddingHorizontal: 12,
  },
  pillOn: { borderColor: colors.gold, backgroundColor: 'rgba(212,175,55,0.12)' },
  dot: { width: 8, height: 8, borderRadius: 4, backgroundColor: colors.textMuted, marginRight: 8 },
  dotOn: { backgroundColor: colors.gold },
  pillText: { color: colors.textMuted, fontSize: 13, fontWeight: '700' },
  pillTextOn: { color: colors.goldLight },
  card: {
    marginTop: 18,
    marginBottom: 20,
    padding: 16,
    borderRadius: 18,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  cardTitle: { color: colors.text, fontSize: 18, fontFamily: typography.h2.fontFamily },
  cardSub: { color: colors.textSecondary, marginTop: 6, marginBottom: 14, lineHeight: 19, fontSize: 13 },
  slider: { minHeight: 60, justifyContent: 'center' },
  grid: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between' },
  tile: {
    width: '48%',
    minHeight: 150,
    marginBottom: 14,
    padding: 14,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 18,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  logo: { width: 76, height: 76, resizeMode: 'contain' },
  tileTitle: { color: colors.text, fontSize: 15, marginTop: 8, textAlign: 'center', fontFamily: typography.h2.fontFamily },
  tileSub: { color: colors.textMuted, fontSize: 12, marginTop: 2, textAlign: 'center' },
});

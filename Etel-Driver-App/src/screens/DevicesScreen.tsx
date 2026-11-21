import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, ScrollView, StyleSheet, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Text, Alert, translate, useLanguage } from '../i18n';
import { colors, typography } from '../theme/colors';
import GoldBoxCard from '../components/GoldBoxCard';
import { Device, myDevices, signOutDevice, signOutOtherDevices } from '../api/devices';
import { ago, deviceIcon, ordered, othersCount } from '../devices/devicesLogic';

// "Signed-in devices": every phone or browser this account is signed in on, with a button to sign one out (for a lost phone) or all the others.
export default function DevicesScreen() {
  const { lang } = useLanguage();
  const am = lang === 'am';
  const [list, setList] = useState<Device[] | null>(null);
  const [now, setNow] = useState('');
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState(false);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async () => {
    try {
      const r = await myDevices();
      setList(ordered(r.devices));
      setNow(r.now);
      setErr('');
    } catch (e) {
      setErr(e instanceof Error && e.message ? e.message : 'Could not load your devices.');
      setList((cur) => cur || []);
    } finally {
      setRefreshing(false);
    }
  }, []);
  useEffect(() => {
    load();
  }, [load]);

  function signOutOne(d: Device) {
    Alert.alert(translate('Sign out this device?'), d.label + '. ' + translate('It will need to sign in again.'), [
      { text: translate('Cancel'), style: 'cancel' },
      {
        text: translate('Sign out'),
        style: 'destructive',
        onPress: async () => {
          setBusy(true);
          try {
            await signOutDevice(d.id);
          } catch (e) {
            setErr(e instanceof Error && e.message ? e.message : 'Could not sign it out.');
          }
          setBusy(false);
          load();
        },
      },
    ]);
  }
  function signOutOthers() {
    Alert.alert(translate('Sign out all other devices?'), translate('Only this phone stays signed in.'), [
      { text: translate('Cancel'), style: 'cancel' },
      {
        text: translate('Sign out all'),
        style: 'destructive',
        onPress: async () => {
          setBusy(true);
          try {
            await signOutOtherDevices();
          } catch (e) {
            setErr(e instanceof Error && e.message ? e.message : 'Could not sign them out.');
          }
          setBusy(false);
          load();
        },
      },
    ]);
  }

  return (
    <ScrollView
      style={styles.container}
      contentContainerStyle={styles.content}
      refreshControl={<RefreshControl refreshing={refreshing} tintColor={colors.gold} onRefresh={() => { setRefreshing(true); load(); }} />}
    >
      <Text style={styles.title}>Signed-in devices</Text>
      <Text style={styles.lead}>Phones and browsers where this account is signed in. If you lost a phone, sign it out here.</Text>
      {err ? <Text style={styles.error}>{err}</Text> : null}
      {list === null ? <ActivityIndicator color={colors.gold} style={{ marginTop: 24 }} /> : null}
      {list && list.length === 0 && !err ? (
        <GoldBoxCard style={styles.card}>
          <View style={styles.empty}>
            <Text style={styles.emptyIcon}>📱</Text>
            <Text style={styles.emptyTitle}>No devices to show.</Text>
          </View>
        </GoldBoxCard>
      ) : null}
      {(list || []).map((d) => (
        <GoldBoxCard key={d.id} style={styles.card} radius={14}>
          <View style={styles.row}>
            <View style={[styles.icon, d.current && styles.iconOn]}>
              <Ionicons name={deviceIcon(d.label)} size={22} color={d.current ? '#1a1200' : colors.gold} />
            </View>
            <View style={{ flex: 1 }}>
              <Text style={styles.name}>{d.label}</Text>
              <Text style={styles.sub}>
                {(d.current ? translate('This phone') + ' - ' : '') + translate('Active') + ' ' + (ago(d.last_seen, now, am) || '-') + (d.ip ? ' - ' + d.ip : '')}
              </Text>
              <Text style={styles.sub}>{translate('Signed in') + ' ' + (ago(d.signed_in_at, now, am) || '-')}</Text>
            </View>
            {!d.current ? (
              <Pressable disabled={busy} onPress={() => signOutOne(d)} style={styles.out} accessibilityRole="button" accessibilityLabel={'Sign out ' + d.label}>
                <Text style={styles.outText}>Sign out</Text>
              </Pressable>
            ) : null}
          </View>
        </GoldBoxCard>
      ))}
      {list && othersCount(list) > 0 ? (
        <Pressable disabled={busy} onPress={signOutOthers} style={styles.primary} accessibilityRole="button">
          <Text style={styles.primaryText}>Sign out all other devices</Text>
        </Pressable>
      ) : null}
      <Text style={styles.foot}>If you do not recognise a device, sign it out and change your password.</Text>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.bg },
  content: { paddingTop: 56, paddingHorizontal: 20, paddingBottom: 60 },
  title: { ...typography.h1, color: colors.text, marginTop: 8, marginBottom: 6 },
  lead: { color: colors.textSecondary, marginBottom: 14, lineHeight: 20 },
  error: { color: colors.danger, marginBottom: 8 },
  card: { marginBottom: 10 },
  row: { flexDirection: 'row', alignItems: 'center', padding: 14, gap: 12 },
  icon: { width: 42, height: 42, borderRadius: 21, borderWidth: 1, borderColor: colors.gold, alignItems: 'center', justifyContent: 'center' },
  iconOn: { backgroundColor: colors.gold },
  name: { color: colors.text, fontWeight: '700' },
  sub: { color: colors.textMuted, fontSize: 12, marginTop: 2 },
  out: { borderWidth: 1, borderColor: colors.danger, borderRadius: 10, paddingVertical: 7, paddingHorizontal: 12 },
  outText: { color: colors.danger, fontWeight: '700', fontSize: 13 },
  primary: { backgroundColor: colors.gold, borderRadius: 14, paddingVertical: 14, alignItems: 'center', marginTop: 8 },
  primaryText: { color: '#1a1200', fontWeight: '700', fontSize: 16 },
  empty: { alignItems: 'center', padding: 24 },
  emptyIcon: { fontSize: 36, marginBottom: 6 },
  emptyTitle: { color: colors.text, fontWeight: '700' },
  foot: { color: colors.textMuted, fontSize: 12, textAlign: 'center', marginTop: 14, lineHeight: 18 },
});

import React, { useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator, Pressable, ScrollView, Switch, Alert } from 'react-native';
import { isLockEnabled, isLockSupported, setLockEnabled } from '../security/appLock';
import { useNavigation } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { colors, typography } from '../theme/colors';
import { useAuth } from '../context/AuthContext';
import { useNightMode } from '../context/NightModeContext';
import { getDriverProfile, DriverProfile } from '../api/driver';
import { RootStackParamList } from '../navigation/types';
import GoldBoxCard from '../components/GoldBoxCard';

type Nav = NativeStackNavigationProp<RootStackParamList>;

export default function ProfileScreen() {
  const navigation = useNavigation<Nav>();
  const { user, logout } = useAuth();
  const { nightMode, toggle } = useNightMode();
  const [driver, setDriver] = useState<DriverProfile | null>(null);
  const [loading, setLoading] = useState(true);
  const [lockSupported, setLockSupported] = useState(false);
  const [lockOn, setLockOn] = useState(false);

  useEffect(() => {
    isLockSupported().then(setLockSupported);
    isLockEnabled().then(setLockOn);
  }, []);

  async function toggleLock(next: boolean) {
    const ok = await setLockEnabled(next);
    if (ok) setLockOn(next);
    else if (next) Alert.alert('Not enabled', 'Could not verify your fingerprint or screen lock, so the lock was left off.');
  }

  useEffect(() => {
    getDriverProfile()
      .then((res) => setDriver(res.driver))
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <View style={styles.avatar}>
        <Text style={styles.avatarInitial}>{(user?.name || driver?.name || '?').charAt(0).toUpperCase()}</Text>
      </View>
      <Text style={styles.name}>{driver?.name || user?.name || '—'}</Text>
      <Text style={styles.phone}>{driver?.phone || user?.phone || ''}</Text>

      {loading ? (
        <ActivityIndicator color={colors.gold} style={{ marginTop: 24 }} />
      ) : (
        <>
          <View style={styles.ratingRow}>
            <Text style={styles.ratingValue}>★ {driver?.rating?.toFixed(1) ?? '—'}</Text>
            {driver?.rating_count ? (
              <Text style={styles.ratingCount}>({driver.rating_count} trips)</Text>
            ) : null}
          </View>

          <GoldBoxCard style={styles.sectionWrap}>
            <Text style={styles.sectionTitle}>Vehicle Information</Text>
            <Row label="Type" value={driver?.vehicle_type} />
            <Row label="Make" value={driver?.vehicle_make} />
            <Row label="Color" value={driver?.vehicle_color} />
            <Row label="Plate" value={driver?.vehicle_plate} />
          </GoldBoxCard>

          <GoldBoxCard style={styles.sectionWrap}>
            <MenuRow label="Documents" onPress={() => navigation.navigate('Documents')} />
            <MenuRow label="Account & Wallet" onPress={() => navigation.navigate('Wallet')} />
            <MenuRow label="Notifications" onPress={() => navigation.navigate('Notifications')} />
            <MenuRow label="Help & Support" onPress={() => navigation.navigate('Help')} last />
          </GoldBoxCard>

          <GoldBoxCard style={styles.sectionWrap}>
            <Text style={styles.sectionTitle}>Settings</Text>
            <View style={styles.row}>
              <Text style={styles.rowLabel}>Night Mode — better visibility at night</Text>
              <Switch
                value={nightMode}
                onValueChange={toggle}
                trackColor={{ true: colors.gold, false: colors.surface3 }}
                thumbColor={colors.text}
              />
            </View>
            {lockSupported && (
              <View style={styles.row}>
                <Text style={styles.rowLabel}>Require fingerprint / screen lock to open</Text>
                <Switch
                  value={lockOn}
                  onValueChange={toggleLock}
                  trackColor={{ true: colors.gold, false: colors.surface3 }}
                  thumbColor={colors.text}
                />
              </View>
            )}
          </GoldBoxCard>
        </>
      )}

      <Pressable style={styles.signOutButton} onPress={() => logout()}>
        <Text style={styles.signOutText}>Sign out</Text>
      </Pressable>
    </ScrollView>
  );
}

function Row({ label, value }: { label: string; value?: string | number | null }) {
  return (
    <View style={styles.row}>
      <Text style={styles.rowLabel}>{label}</Text>
      <Text style={styles.rowValue}>{value != null && value !== '' ? value : '—'}</Text>
    </View>
  );
}

function MenuRow({ label, onPress, last }: { label: string; onPress: () => void; last?: boolean }) {
  return (
    <Pressable style={[styles.menuRow, last && { borderBottomWidth: 0 }]} onPress={onPress}>
      <Text style={styles.menuRowLabel}>{label}</Text>
      <Text style={styles.menuRowChevron}>›</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.bg },
  content: { paddingTop: 60, paddingHorizontal: 20, paddingBottom: 40, alignItems: 'center' },
  avatar: {
    width: 84,
    height: 84,
    borderRadius: 42,
    backgroundColor: colors.surface3,
    borderWidth: 2,
    borderColor: colors.gold,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 12,
  },
  avatarInitial: { color: colors.gold, fontSize: 32, fontWeight: '700' },
  name: { ...typography.h2, color: colors.text },
  phone: { color: colors.textMuted, marginTop: 2 },
  ratingRow: { flexDirection: 'row', alignItems: 'center', marginTop: 10, gap: 6 },
  ratingValue: { color: colors.gold, fontWeight: '700', fontSize: 16 },
  ratingCount: { color: colors.textMuted, fontSize: 13 },
  sectionWrap: { width: '100%', marginTop: 18 },
  sectionTitle: { color: colors.textMuted, ...typography.small, marginBottom: 10, letterSpacing: 0.5 },
  row: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 8,
    borderBottomColor: colors.border,
    borderBottomWidth: StyleSheet.hairlineWidth,
  },
  rowLabel: { color: colors.textSecondary },
  rowValue: { color: colors.text, fontWeight: '600' },
  menuRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingVertical: 12,
    borderBottomColor: colors.border,
    borderBottomWidth: StyleSheet.hairlineWidth,
  },
  menuRowLabel: { color: colors.text },
  menuRowChevron: { color: colors.textMuted, fontSize: 18 },
  signOutButton: { marginTop: 30, paddingVertical: 12, paddingHorizontal: 24 },
  signOutText: { color: colors.danger, fontWeight: '600' },
});

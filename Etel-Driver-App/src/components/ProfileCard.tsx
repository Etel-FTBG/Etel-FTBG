import React, { useEffect, useState } from 'react';
import { Alert as RNAlert, Pressable, StyleSheet, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Text, translate } from '../i18n';
import { useTheme } from '../theme/ThemeContext';
import { HubSession, ROLE_LABEL, hubSignOut } from '../hub/session';
import { goOfflineEverywhere } from '../hub/masterOnline';
import { isLockEnabled, isLockSupported, setLockEnabled } from '../security/appLock';
import { tap } from '../utils/haptics';
import { isLowData, setLowData } from '../data/lowData';
import { setCalendar, useCalendar } from '../calendar/calendarSetting';
import { nextPageSize, PAGE_SIZE_LABEL, setPageSize, usePageSize } from '../hub/pageSize';
import { reloadOpenPages } from '../hub/webHost';
import { useNavigation } from '@react-navigation/native';

// Who is signed in, their role, the fingerprint-lock switch and sign out.
export default function ProfileCard({ session }: { session: HubSession }) {
  const { p } = useTheme();
  const navigation = useNavigation<any>();
  const [lockOn, setLockOn] = useState(false);
  const [lockOk, setLockOk] = useState(false);
  const [lowOn, setLowOn] = useState(isLowData());
  const cal = useCalendar();
  const pageSize = usePageSize();

  useEffect(() => {
    isLockSupported().then(setLockOk);
    isLockEnabled().then(setLockOn);
  }, []);

  async function toggleLock() {
    tap();
    const next = !lockOn;
    const done = await setLockEnabled(next);
    if (done) setLockOn(next);
    else if (next) RNAlert.alert(translate('Could not turn on the lock'), translate('Check that a fingerprint, face or screen lock is set up on this phone.'));
  }

  function toggleLow() {
    tap();
    const next = !lowOn;
    setLowOn(next);
    setLowData(next);
    if (next) RNAlert.alert(translate('Save mobile data is ON'), translate('The app checks for news less often and skips decorative pictures. Open the app again so every screen uses it.'));
  }

  function signOut() {
    RNAlert.alert(translate('Sign out of ETEL?'), translate('You will go offline and need to sign in again.'), [
      { text: translate('Cancel'), style: 'cancel' },
      {
        text: translate('Sign out'),
        style: 'destructive',
        onPress: async () => {
          await goOfflineEverywhere().catch(() => {});
          await hubSignOut();
        },
      },
    ]);
  }

  const initial = (session.name || '?').trim().charAt(0).toUpperCase();
  return (
    <View style={[styles.card, { backgroundColor: p.surface, borderColor: p.border }]}>
      <View style={styles.row}>
        <View style={[styles.avatar, { backgroundColor: p.gold }]}>
          <Text style={[styles.avatarText, { color: p.mode === 'dark' ? '#0a0703' : '#FFFFFF' }]}>{initial}</Text>
        </View>
        <View style={styles.who}>
          <Text style={[styles.name, { color: p.text }]} numberOfLines={1}>{session.name}</Text>
          <Text style={[styles.meta, { color: p.textSecondary }]} numberOfLines={1}>
            {translate(ROLE_LABEL[session.role] || session.role)}{session.etelId ? '  ·  ' + session.etelId : ''}
          </Text>
        </View>
        <Pressable onPress={signOut} style={[styles.out, { borderColor: p.border }]} accessibilityLabel="Sign out">
          <Ionicons name="log-out-outline" size={18} color={p.textSecondary} />
        </Pressable>
      </View>
      <Pressable onPress={() => navigation.navigate('Devices')} style={[styles.lockRow, { borderTopColor: p.borderSoft }]} accessibilityRole="button" accessibilityLabel="Signed-in devices">
        <Ionicons name="phone-portrait-outline" size={20} color={p.gold} />
        <Text style={[styles.lockText, { color: p.text }]}>Signed-in devices</Text>
        <Ionicons name="chevron-forward" size={18} color={p.textMuted} />
      </Pressable>
      <Pressable onPress={toggleLow} style={[styles.lockRow, { borderTopColor: p.borderSoft }]} accessibilityRole="switch" accessibilityState={{ checked: lowOn }}>
        <Ionicons name={lowOn ? 'cellular' : 'cellular-outline'} size={20} color={lowOn ? p.gold : p.textMuted} />
        <Text style={[styles.lockText, { color: p.text }]}>Save mobile data</Text>
        <Text style={[styles.lockState, { color: lowOn ? p.gold : p.textMuted }]}>{lowOn ? translate('On') : translate('Off')}</Text>
      </Pressable>
      <Pressable onPress={() => { tap(); setCalendar(cal === 'ethiopian' ? 'gregorian' : 'ethiopian'); }} style={[styles.lockRow, { borderTopColor: p.borderSoft }]} accessibilityRole="button" accessibilityLabel="Calendar">
        <Ionicons name="calendar-outline" size={20} color={cal === 'ethiopian' ? p.gold : p.textMuted} />
        <Text style={[styles.lockText, { color: p.text }]}>Calendar</Text>
        <Text style={[styles.lockState, { color: cal === 'ethiopian' ? p.gold : p.textMuted }]}>{cal === 'ethiopian' ? translate('Ethiopian') : translate('Gregorian')}</Text>
      </Pressable>
      <Pressable onPress={() => { tap(); setPageSize(nextPageSize(pageSize)); setTimeout(reloadOpenPages, 50); }} style={[styles.lockRow, { borderTopColor: p.borderSoft }]} accessibilityRole="button" accessibilityLabel="Page size">
        <Ionicons name="resize-outline" size={20} color={pageSize === 'normal' ? p.textMuted : p.gold} />
        <Text style={[styles.lockText, { color: p.text }]}>Page size</Text>
        <Text style={[styles.lockState, { color: pageSize === 'normal' ? p.textMuted : p.gold }]}>{translate(PAGE_SIZE_LABEL[pageSize])}</Text>
      </Pressable>
      {lockOk ? (
        <Pressable onPress={toggleLock} style={[styles.lockRow, { borderTopColor: p.borderSoft }]} accessibilityRole="switch">
          <Ionicons name={lockOn ? 'finger-print' : 'lock-open-outline'} size={20} color={lockOn ? p.gold : p.textMuted} />
          <Text style={[styles.lockText, { color: p.text }]}>Fingerprint or face lock</Text>
          <Text style={[styles.lockState, { color: lockOn ? p.gold : p.textMuted }]}>{lockOn ? translate('On') : translate('Off')}</Text>
        </Pressable>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  card: { padding: 14, borderRadius: 20, borderWidth: 1, marginBottom: 8 },
  row: { flexDirection: 'row', alignItems: 'center' },
  avatar: { width: 44, height: 44, borderRadius: 22, alignItems: 'center', justifyContent: 'center' },
  avatarText: { fontSize: 18, fontWeight: '800' },
  who: { flex: 1, marginHorizontal: 12 },
  name: { fontSize: 16, fontWeight: '700' },
  meta: { fontSize: 12, marginTop: 2 },
  out: { width: 38, height: 38, borderRadius: 19, borderWidth: 1, alignItems: 'center', justifyContent: 'center' },
  lockRow: { flexDirection: 'row', alignItems: 'center', marginTop: 12, paddingTop: 12, borderTopWidth: 1 },
  lockText: { flex: 1, marginLeft: 10, fontSize: 14 },
  lockState: { fontSize: 13, fontWeight: '700' },
});

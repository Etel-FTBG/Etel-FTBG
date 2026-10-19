import React, { useEffect, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { Text } from '../i18n';
import { colors, typography } from '../theme/colors';
import { RootStackParamList } from '../navigation/types';
import { HUB_APPS } from '../hub/apps';
import { useAuth } from '../context/AuthContext';
import { isOnline, subscribeOnline } from '../online/onlineState';

type Props = NativeStackScreenProps<RootStackParamList, 'Hub'>;

export default function HubScreen({ navigation }: Props) {
  const { user } = useAuth();
  const [online, setOnline] = useState(isOnline());
  useEffect(() => subscribeOnline(setOnline), []);

  return (
    <ScrollView style={styles.root} contentContainerStyle={styles.content}>
      <View style={styles.head}>
        <Text style={styles.brand}>ETEL</Text>
        <View style={[styles.pill, online && styles.pillOn]}>
          <View style={[styles.dot, online && styles.dotOn]} />
          <Text style={[styles.pillText, online && styles.pillTextOn]}>{online ? 'Online' : 'Offline'}</Text>
        </View>
      </View>
      <Text style={styles.sub}>
        Open any ETEL app here. Stay online while you use your phone: the golden ETEL light shows it is still running.
      </Text>
      <View style={styles.grid}>
        {HUB_APPS.map((a) => (
          <Pressable
            key={a.key}
            style={styles.tile}
            onPress={() => navigation.navigate('WebApp', { appKey: a.key })}
            accessibilityRole="button"
            accessibilityLabel={a.title}
          >
            <Ionicons name={a.icon as keyof typeof Ionicons.glyphMap} size={30} color={colors.gold} />
            <Text style={styles.tileTitle}>{a.title}</Text>
            <Text style={styles.tileSub}>{a.subtitle}</Text>
          </Pressable>
        ))}
        <Pressable
          style={styles.tile}
          onPress={() => navigation.navigate(user ? 'MainTabs' : 'Login')}
          accessibilityRole="button"
          accessibilityLabel="Native driver"
        >
          <Ionicons name="navigate" size={30} color={colors.gold} />
          <Text style={styles.tileTitle}>Driver (native)</Text>
          <Text style={styles.tileSub}>{user ? 'Open' : 'Sign in'}</Text>
        </Pressable>
      </View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: colors.bg },
  content: { padding: 20, paddingTop: 56, paddingBottom: 40 },
  head: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  brand: { color: colors.gold, fontSize: 30, letterSpacing: 4, fontFamily: typography.h1.fontFamily },
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
  sub: { color: colors.textSecondary, marginTop: 14, marginBottom: 22, lineHeight: 20 },
  grid: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between' },
  tile: {
    width: '48%',
    minHeight: 130,
    marginBottom: 14,
    padding: 16,
    justifyContent: 'center',
    borderRadius: 18,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  tileTitle: { color: colors.text, fontSize: 16, marginTop: 10, fontFamily: typography.h2.fontFamily },
  tileSub: { color: colors.textMuted, fontSize: 12, marginTop: 2 },
});

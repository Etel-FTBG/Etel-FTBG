import React, { useEffect, useState } from 'react';
import { View, StyleSheet } from 'react-native';
import { Text } from '../i18n';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '../theme/colors';
import { isOnline, subscribeConnectivity } from '../net/connectivity';

export default function OfflineBanner() {
  const [online, setOnline] = useState(isOnline());

  useEffect(() => subscribeConnectivity(setOnline), []);

  if (online) return null;
  return (
    <View style={styles.banner} pointerEvents="none">
      <Ionicons name="cloud-offline-outline" size={16} color={colors.bg} />
      <Text style={styles.text}>No connection — retrying</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  banner: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    zIndex: 100,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    paddingTop: 34,
    paddingBottom: 8,
    backgroundColor: colors.warning,
  },
  text: { color: colors.bg, fontWeight: '700', fontSize: 12 },
});

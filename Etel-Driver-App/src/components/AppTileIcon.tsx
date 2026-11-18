import React, { useState } from 'react';
import { Image, StyleSheet } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { Ionicons } from '@expo/vector-icons';
import { HubApp } from '../hub/apps';
import { appIconUri } from '../hub/hubContent';

// The picture of an app on the hub. The owner's uploaded icon when there is one; if it cannot be loaded (offline, removed) the built-in logo,
// or the gold badge, is shown instead, so a tile is never empty.
export default function AppTileIcon({ app }: { app: HubApp }) {
  const uri = appIconUri(app);
  const [failed, setFailed] = useState('');
  if (uri && failed !== uri) {
    return <Image source={{ uri }} style={styles.custom} onError={() => setFailed(uri)} accessibilityIgnoresInvertColors />;
  }
  if (app.logo) return <Image source={app.logo} style={styles.logo} />;
  return (
    <LinearGradient colors={['#F2CC6B', '#D4AF37', '#B8860B']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.badgeIcon}>
      <Ionicons name={(app.icon || 'apps') as any} size={38} color="#1A1206" />
    </LinearGradient>
  );
}

const styles = StyleSheet.create({
  logo: { width: 78, height: 78, resizeMode: 'contain' },
  custom: { width: 78, height: 78, borderRadius: 18, resizeMode: 'cover' },
  badgeIcon: { width: 78, height: 78, borderRadius: 39, alignItems: 'center', justifyContent: 'center' },
});

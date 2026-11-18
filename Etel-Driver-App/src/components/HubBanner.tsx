import React, { useEffect, useState } from 'react';
import { Image, Pressable, StyleSheet, View } from 'react-native';
import * as SecureStore from 'expo-secure-store';
import { Ionicons } from '@expo/vector-icons';
import { Text, useLanguage } from '../i18n';
import { useTheme } from '../theme/ThemeContext';
import { bannerText, getBanner } from '../hub/hubContent';

// The home banner the owner sets in Admin (a picture, a title, a short text, optionally a link to one of the apps). The person can close it;
// a banner that the owner changes has a new id, so it shows again.
export default function HubBanner({ onOpenApp }: { onOpenApp: (appKey: string) => void }) {
  const { lang } = useLanguage();
  const { p } = useTheme();
  const b = getBanner();
  const [closed, setClosed] = useState<string>('');
  useEffect(() => {
    SecureStore.getItemAsync('etel_banner_closed').then((v) => setClosed(v || '')).catch(() => {});
  }, []);
  if (!b || b.id === closed) return null;
  const { title, text } = bannerText(b, lang);
  if (!title && !text && !b.image_url) return null;
  const close = () => {
    setClosed(b.id);
    SecureStore.setItemAsync('etel_banner_closed', b.id).catch(() => {});
  };
  return (
    <Pressable
      disabled={!b.link_app}
      onPress={() => b.link_app && onOpenApp(b.link_app)}
      accessibilityRole={b.link_app ? 'button' : 'text'}
      accessibilityLabel={(title ? title + '. ' : '') + text}
      style={[styles.box, { backgroundColor: p.surface, borderColor: p.gold }]}
    >
      {b.image_url ? <Image source={{ uri: b.image_url }} style={styles.img} accessibilityIgnoresInvertColors /> : null}
      <View style={styles.body}>
        {title ? <Text style={[styles.title, { color: p.goldStrong }]}>{title}</Text> : null}
        {text ? <Text style={[styles.text, { color: p.text }]}>{text}</Text> : null}
      </View>
      <Pressable onPress={close} hitSlop={12} accessibilityRole="button" accessibilityLabel="Close" style={styles.x}>
        <Ionicons name="close" size={18} color={p.textMuted} />
      </Pressable>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  box: { borderWidth: 1, borderRadius: 18, overflow: 'hidden', marginBottom: 12 },
  img: { width: '100%', height: 120, resizeMode: 'cover' },
  body: { padding: 14, paddingRight: 36 },
  title: { fontSize: 16, fontWeight: '700' },
  text: { fontSize: 13, marginTop: 4, lineHeight: 19 },
  x: { position: 'absolute', top: 8, right: 8, width: 26, height: 26, borderRadius: 13, alignItems: 'center', justifyContent: 'center', backgroundColor: 'rgba(0,0,0,0.25)' },
});

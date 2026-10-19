import React, { useEffect, useRef } from 'react';
import { Animated, Pressable, StyleSheet, View } from 'react-native';
import * as Updates from 'expo-updates';
import { Ionicons } from '@expo/vector-icons';
import { Text } from '../i18n';
import { useTheme } from '../theme/ThemeContext';
import { success } from '../utils/haptics';

// When a new version has been downloaded in the background, offer a one-tap restart into it.
export default function UpdateBanner() {
  const { p } = useTheme();
  const { isUpdatePending } = Updates.useUpdates();
  const y = useRef(new Animated.Value(-80)).current;

  useEffect(() => {
    if (isUpdatePending) {
      success();
      Animated.spring(y, { toValue: 0, useNativeDriver: true, bounciness: 6 }).start();
    }
  }, [isUpdatePending, y]);

  if (!isUpdatePending) return null;
  return (
    <Animated.View style={[styles.wrap, { transform: [{ translateY: y }] }]}>
      <View style={[styles.bar, { backgroundColor: p.surface, borderColor: p.gold }]}>
        <Ionicons name="sparkles" size={18} color={p.gold} />
        <Text style={[styles.msg, { color: p.text }]}>A new version is ready</Text>
        <Pressable
          onPress={() => Updates.reloadAsync().catch(() => {})}
          style={[styles.btn, { backgroundColor: p.gold }]}
          accessibilityRole="button"
          accessibilityLabel="Restart to update"
        >
          <Text style={[styles.btnText, { color: p.mode === 'dark' ? '#0a0703' : '#FFFFFF' }]}>Update</Text>
        </Pressable>
      </View>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  wrap: { position: 'absolute', top: 44, left: 14, right: 14, zIndex: 900 },
  bar: { flexDirection: 'row', alignItems: 'center', padding: 10, borderRadius: 16, borderWidth: 1 },
  msg: { flex: 1, marginLeft: 10, fontSize: 14, fontWeight: '600' },
  btn: { paddingVertical: 8, paddingHorizontal: 16, borderRadius: 12 },
  btnText: { fontWeight: '800', fontSize: 13 },
});

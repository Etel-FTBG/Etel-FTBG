import React, { useEffect, useRef } from 'react';
import { Animated, Pressable, StyleSheet, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useTheme } from '../theme/ThemeContext';

interface Props {
  // false = left option, true = right option
  value: boolean;
  onToggle: () => void;
  left: { label?: string; icon?: keyof typeof Ionicons.glyphMap };
  right: { label?: string; icon?: keyof typeof Ionicons.glyphMap };
  accessibilityLabel: string;
}

const W = 92;
const H = 38;
const KNOB = (W - 6) / 2;

// A two-option sliding switch (light / dark, EN / አማ). The gold knob slides under the chosen option.
export default function PillSwitch({ value, onToggle, left, right, accessibilityLabel }: Props) {
  const { p } = useTheme();
  const x = useRef(new Animated.Value(value ? 1 : 0)).current;
  useEffect(() => {
    Animated.timing(x, { toValue: value ? 1 : 0, duration: 180, useNativeDriver: true }).start();
  }, [value, x]);

  const tx = x.interpolate({ inputRange: [0, 1], outputRange: [3, 3 + KNOB] });
  const on = p.mode === 'dark' ? '#0a0703' : '#FFFFFF';

  const opt = (o: Props['left'], active: boolean) =>
    o.icon ? (
      <Ionicons name={o.icon} size={17} color={active ? on : p.textSecondary} />
    ) : (
      <Text style={[styles.lbl, { color: active ? on : p.textSecondary }]}>
        {o.label}
      </Text>
    );

  return (
    <Pressable
      onPress={onToggle}
      accessibilityRole="switch"
      accessibilityLabel={accessibilityLabel}
      accessibilityState={{ checked: value }}
      style={[styles.track, { backgroundColor: p.surfaceAlt, borderColor: p.border }]}
    >
      <Animated.View style={[styles.knob, { backgroundColor: p.gold, transform: [{ translateX: tx }] }]} />
      <View style={styles.row} pointerEvents="none">
        <View style={styles.cell}>{opt(left, !value)}</View>
        <View style={styles.cell}>{opt(right, value)}</View>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  track: { width: W, height: H, borderRadius: H / 2, borderWidth: 1, justifyContent: 'center' },
  knob: { position: 'absolute', top: 3, width: KNOB, height: H - 8, borderRadius: (H - 8) / 2 },
  row: { flexDirection: 'row', height: '100%' },
  cell: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  lbl: { fontSize: 13, fontWeight: '800' },
});

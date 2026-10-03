import React from 'react';
import { View, StyleSheet, ViewStyle, StyleProp } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { colors } from '../theme/colors';

// The "gold box" card treatment used across EtelID/EtelPay/Delivery admin
// and portal surfaces: a warm dark-carbon gradient fill, a 1px gold-35%
// border, a 3px gold gradient trim bar across the top, and a thin
// holographic gold strip near the right edge — mirrors the physical ID
// card's holo-strip design. See etel-group-ops project notes.
export default function GoldBoxCard({
  children,
  style,
  radius = 16,
}: {
  children: React.ReactNode;
  style?: StyleProp<ViewStyle>;
  radius?: number;
}) {
  return (
    <View style={[styles.outer, { borderRadius: radius }, style]}>
      <LinearGradient
        colors={[colors.surface, colors.bg]}
        start={{ x: 0, y: 0 }}
        end={{ x: 0.35, y: 1 }}
        style={[StyleSheet.absoluteFill, { borderRadius: radius }]}
      />
      <LinearGradient
        colors={[colors.goldDark, colors.gold, colors.goldLight, colors.gold, colors.goldDark]}
        start={{ x: 0, y: 0 }}
        end={{ x: 1, y: 0 }}
        style={[styles.topTrim, { borderTopLeftRadius: radius, borderTopRightRadius: radius }]}
      />
      <LinearGradient
        colors={['rgba(242,204,107,0)', 'rgba(242,204,107,0.55)', 'rgba(212,175,55,0.15)', 'rgba(242,204,107,0.45)', 'rgba(242,204,107,0)']}
        start={{ x: 0, y: 0 }}
        end={{ x: 0, y: 1 }}
        style={styles.holoStrip}
      />
      <View style={styles.content}>{children}</View>
    </View>
  );
}

const styles = StyleSheet.create({
  outer: {
    borderWidth: 1,
    borderColor: colors.border,
    overflow: 'hidden',
  },
  topTrim: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    height: 3,
  },
  holoStrip: {
    position: 'absolute',
    top: 0,
    bottom: 0,
    right: 16,
    width: 3,
    opacity: 0.8,
  },
  content: { padding: 16 },
});

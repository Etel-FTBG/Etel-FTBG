import React, { useEffect, useRef, useState } from 'react';
import { Animated, Easing, Image, StyleSheet, View } from 'react-native';
import { useTheme } from '../theme/ThemeContext';

// A short branded opening: the master logo rises out of a golden glow, then the screen fades into the hub.
export default function IntroSplash() {
  const { p } = useTheme();
  const [done, setDone] = useState(false);
  const logo = useRef(new Animated.Value(0)).current;
  const glow = useRef(new Animated.Value(0)).current;
  const out = useRef(new Animated.Value(1)).current;

  useEffect(() => {
    Animated.parallel([
      Animated.timing(logo, { toValue: 1, duration: 700, easing: Easing.out(Easing.cubic), useNativeDriver: true }),
      Animated.loop(
        Animated.sequence([
          Animated.timing(glow, { toValue: 1, duration: 650, easing: Easing.inOut(Easing.quad), useNativeDriver: true }),
          Animated.timing(glow, { toValue: 0, duration: 650, easing: Easing.inOut(Easing.quad), useNativeDriver: true }),
        ]),
        { iterations: 2 }
      ),
    ]).start();
    const t = setTimeout(() => {
      Animated.timing(out, { toValue: 0, duration: 380, useNativeDriver: true }).start(() => setDone(true));
    }, 1500);
    return () => clearTimeout(t);
  }, [logo, glow, out]);

  if (done) return null;
  return (
    <Animated.View style={[styles.root, { backgroundColor: p.bg, opacity: out }]} pointerEvents="auto">
      <View style={styles.center}>
        <Animated.View
          style={[
            styles.halo,
            {
              backgroundColor: p.glow,
              opacity: glow.interpolate({ inputRange: [0, 1], outputRange: [0.45, 1] }),
              transform: [{ scale: glow.interpolate({ inputRange: [0, 1], outputRange: [0.92, 1.12] }) }],
            },
          ]}
        />
        <Animated.Image
          source={require('../../assets/etel-master.png')}
          style={[
            styles.logo,
            {
              opacity: logo,
              transform: [{ scale: logo.interpolate({ inputRange: [0, 1], outputRange: [0.78, 1] }) }],
            },
          ]}
        />
      </View>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  root: { ...({ position: 'absolute', left: 0, right: 0, top: 0, bottom: 0 } as const), zIndex: 1000, alignItems: 'center', justifyContent: 'center' },
  center: { width: 240, height: 240, alignItems: 'center', justifyContent: 'center' },
  halo: { position: 'absolute', width: 240, height: 240, borderRadius: 120 },
  logo: { width: 168, height: 168, resizeMode: 'contain' },
});

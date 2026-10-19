import React, { useEffect, useRef } from 'react';
import { Animated, Easing, StyleSheet } from 'react-native';

// Shown while a company's page loads: the master logo breathes in gold instead of a plain spinner.
export default function BrandLoader() {
  const v = useRef(new Animated.Value(0)).current;
  useEffect(() => {
    const loop = Animated.loop(
      Animated.sequence([
        Animated.timing(v, { toValue: 1, duration: 800, easing: Easing.inOut(Easing.quad), useNativeDriver: true }),
        Animated.timing(v, { toValue: 0, duration: 800, easing: Easing.inOut(Easing.quad), useNativeDriver: true }),
      ])
    );
    loop.start();
    return () => loop.stop();
  }, [v]);
  return (
    <Animated.Image
      source={require('../../assets/etel-master.png')}
      style={[
        styles.logo,
        { opacity: v.interpolate({ inputRange: [0, 1], outputRange: [0.45, 1] }), transform: [{ scale: v.interpolate({ inputRange: [0, 1], outputRange: [0.94, 1.04] }) }] },
      ]}
    />
  );
}

const styles = StyleSheet.create({ logo: { width: 84, height: 84, resizeMode: 'contain' } });

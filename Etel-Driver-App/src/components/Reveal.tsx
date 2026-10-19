import React, { useEffect, useRef } from 'react';
import { Animated, ViewStyle } from 'react-native';

// Fades and lifts its content in, one after another (delay in ms). Used for the hub's cards and tiles.
export default function Reveal({ delay = 0, style, children }: { delay?: number; style?: ViewStyle | ViewStyle[]; children: React.ReactNode }) {
  const v = useRef(new Animated.Value(0)).current;
  useEffect(() => {
    Animated.timing(v, { toValue: 1, duration: 420, delay, useNativeDriver: true }).start();
  }, [v, delay]);
  return (
    <Animated.View
      style={[
        style,
        { opacity: v, transform: [{ translateY: v.interpolate({ inputRange: [0, 1], outputRange: [14, 0] }) }] },
      ]}
    >
      {children}
    </Animated.View>
  );
}

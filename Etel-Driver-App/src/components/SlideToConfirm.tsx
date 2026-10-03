import React, { useRef, useState } from 'react';
import { Animated, PanResponder, StyleSheet, View, LayoutChangeEvent } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '../theme/colors';

interface Props {
  label: string;
  onConfirm: () => void;
  disabled?: boolean;
  trackColor?: string;
  thumbColor?: string;
  textColor?: string;
}

const THUMB_SIZE = 52;
const THRESHOLD_RATIO = 0.72;

// A real drag-to-confirm control (not a disguised button) — matches the
// existing web driver PWA's "slide to go online" / "slide to accept"
// pattern, which is worth keeping: it's a deliberate friction against
// accidentally going online or accepting a ride with a stray tap.
export default function SlideToConfirm({
  label,
  onConfirm,
  disabled,
  trackColor = colors.surface3,
  thumbColor = colors.gold,
  textColor = colors.text,
}: Props) {
  const [trackWidth, setTrackWidth] = useState(0);
  const pan = useRef(new Animated.Value(0)).current;
  const confirmedRef = useRef(false);

  const maxDrag = Math.max(trackWidth - THUMB_SIZE - 8, 1);

  const panResponder = useRef(
    PanResponder.create({
      onStartShouldSetPanResponder: () => !disabled,
      onMoveShouldSetPanResponder: () => !disabled,
      onPanResponderMove: (_, gesture) => {
        const x = Math.min(Math.max(gesture.dx, 0), maxDrag);
        pan.setValue(x);
      },
      onPanResponderRelease: (_, gesture) => {
        const x = Math.min(Math.max(gesture.dx, 0), maxDrag);
        if (x >= maxDrag * THRESHOLD_RATIO && !confirmedRef.current) {
          confirmedRef.current = true;
          Animated.timing(pan, { toValue: maxDrag, duration: 120, useNativeDriver: false }).start(() => {
            onConfirm();
            setTimeout(() => {
              confirmedRef.current = false;
              Animated.timing(pan, { toValue: 0, duration: 150, useNativeDriver: false }).start();
            }, 400);
          });
        } else {
          Animated.spring(pan, { toValue: 0, useNativeDriver: false }).start();
        }
      },
    })
  ).current;

  function handleLayout(e: LayoutChangeEvent) {
    setTrackWidth(e.nativeEvent.layout.width);
  }

  const opacity = trackWidth
    ? pan.interpolate({ inputRange: [0, maxDrag], outputRange: [1, 0.3], extrapolate: 'clamp' })
    : 1;

  return (
    <View
      style={[styles.track, { backgroundColor: trackColor, opacity: disabled ? 0.5 : 1 }]}
      onLayout={handleLayout}
    >
      <Animated.Text style={[styles.label, { color: textColor, opacity }]}>{label}</Animated.Text>
      <Animated.View
        style={[styles.thumb, { backgroundColor: thumbColor, transform: [{ translateX: pan }] }]}
        {...panResponder.panHandlers}
      >
        <Ionicons name="arrow-forward" size={22} color={colors.bg} />
      </Animated.View>
    </View>
  );
}

const styles = StyleSheet.create({
  track: {
    height: THUMB_SIZE + 8,
    borderRadius: (THUMB_SIZE + 8) / 2,
    justifyContent: 'center',
    alignItems: 'center',
    overflow: 'hidden',
  },
  label: { fontWeight: '600', fontSize: 15, position: 'absolute' },
  thumb: {
    position: 'absolute',
    left: 4,
    width: THUMB_SIZE,
    height: THUMB_SIZE,
    borderRadius: THUMB_SIZE / 2,
    justifyContent: 'center',
    alignItems: 'center',
  },
});

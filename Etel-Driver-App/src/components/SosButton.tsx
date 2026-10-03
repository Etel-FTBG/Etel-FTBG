import React, { useState } from 'react';
import { Pressable, Text, StyleSheet, Alert } from 'react-native';
import * as Location from 'expo-location';
import { colors } from '../theme/colors';
import { triggerSos } from '../api/extras';
import { ApiError } from '../api/client';

// Hold-to-activate (not a single tap) — matches the spec's requirement that
// an emergency control must never fire from an accidental touch.
export default function SosButton({ rideId }: { rideId?: number }) {
  const [sending, setSending] = useState(false);

  async function fire() {
    setSending(true);
    try {
      let lat: number | undefined;
      let lng: number | undefined;
      try {
        const pos = await Location.getLastKnownPositionAsync();
        if (pos) {
          lat = pos.coords.latitude;
          lng = pos.coords.longitude;
        }
      } catch {
        // SOS still sends without a location fix
      }
      await triggerSos({ rideId, lat, lng });
      Alert.alert('Alert sent', 'ETEL support has been notified of your emergency.');
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Could not send the alert. Try again or call support directly.';
      Alert.alert('SOS failed', msg);
    } finally {
      setSending(false);
    }
  }

  return (
    <Pressable
      style={({ pressed }) => [styles.button, pressed && styles.buttonPressed]}
      onLongPress={fire}
      delayLongPress={1200}
      disabled={sending}
    >
      <Text style={styles.icon}>⛔</Text>
      <Text style={styles.label}>{sending ? 'Sending…' : 'SOS'}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  button: {
    width: 56,
    height: 56,
    borderRadius: 28,
    backgroundColor: colors.danger,
    alignItems: 'center',
    justifyContent: 'center',
  },
  buttonPressed: { opacity: 0.7 },
  icon: { fontSize: 14 },
  label: { color: colors.text, fontSize: 9, fontWeight: '700', marginTop: -2 },
});

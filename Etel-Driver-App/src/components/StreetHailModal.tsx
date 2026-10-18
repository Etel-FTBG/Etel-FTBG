import React, { useState } from 'react';
import { Modal, View, Pressable, ActivityIndicator, StyleSheet } from 'react-native';
import { Text, TextInput, Alert } from '../i18n';
import * as Location from 'expo-location';
import { colors, typography } from '../theme/colors';
import { hailCustomer } from '../api/ride';
import { ApiError } from '../api/client';

interface Props {
  visible: boolean;
  onClose: () => void;
  onHailed: (rideId: number) => void;
}

export default function StreetHailModal({ visible, onClose, onHailed }: Props) {
  const [identifier, setIdentifier] = useState('');
  const [mode, setMode] = useState<'open' | 'distance'>('open');
  const [km, setKm] = useState('');
  const [submitting, setSubmitting] = useState(false);

  function reset() {
    setIdentifier('');
    setMode('open');
    setKm('');
  }

  async function handleSubmit() {
    if (!identifier.trim()) {
      Alert.alert('Required', 'Enter the customer phone number or EtelID.');
      return;
    }
    if (mode === 'distance' && (!km || parseFloat(km) <= 0)) {
      Alert.alert('Required', 'Enter the trip distance in km.');
      return;
    }
    setSubmitting(true);
    try {
      const { status } = await Location.requestForegroundPermissionsAsync();
      if (status !== 'granted') {
        Alert.alert('Location required', 'ETEL Drive needs your location to start a street hail trip.');
        return;
      }
      const pos = await Location.getCurrentPositionAsync({});
      const ride = await hailCustomer({
        phone: identifier.trim(),
        lat: pos.coords.latitude,
        lng: pos.coords.longitude,
        ...(mode === 'distance' ? { km: parseFloat(km) } : { open: true }),
      });
      reset();
      onHailed((ride as any).id);
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Could not start the trip. Check your connection.';
      Alert.alert('Street Hail failed', msg);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose}>
      <View style={styles.backdrop}>
        <View style={styles.sheet}>
          <Text style={styles.title}>🚕 Street Hail</Text>
          <Text style={styles.subtitle}>Link a walk-up customer to start a trip with them right now.</Text>

          <TextInput
            style={styles.input}
            placeholder="Customer phone or EtelID"
            placeholderTextColor={colors.textMuted}
            autoCapitalize="none"
            autoCorrect={false}
            value={identifier}
            onChangeText={setIdentifier}
          />

          <View style={styles.modeRow}>
            <Pressable
              style={[styles.modeButton, mode === 'open' && styles.modeButtonActive]}
              onPress={() => setMode('open')}
            >
              <Text style={[styles.modeButtonText, mode === 'open' && styles.modeButtonTextActive]}>
                Open trip (GPS meter)
              </Text>
            </Pressable>
            <Pressable
              style={[styles.modeButton, mode === 'distance' && styles.modeButtonActive]}
              onPress={() => setMode('distance')}
            >
              <Text style={[styles.modeButtonText, mode === 'distance' && styles.modeButtonTextActive]}>
                By distance
              </Text>
            </Pressable>
          </View>

          {mode === 'distance' && (
            <TextInput
              style={styles.input}
              placeholder="Distance (km)"
              placeholderTextColor={colors.textMuted}
              keyboardType="decimal-pad"
              value={km}
              onChangeText={setKm}
            />
          )}

          <Pressable style={styles.submitButton} onPress={handleSubmit} disabled={submitting}>
            {submitting ? (
              <ActivityIndicator color={colors.bg} />
            ) : (
              <Text style={styles.submitButtonText}>Start Trip</Text>
            )}
          </Pressable>

          <Pressable
            style={styles.cancelButton}
            onPress={() => {
              reset();
              onClose();
            }}
            disabled={submitting}
          >
            <Text style={styles.cancelButtonText}>Cancel</Text>
          </Pressable>
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: { flex: 1, backgroundColor: 'rgba(0,0,0,0.6)', justifyContent: 'flex-end' },
  sheet: {
    backgroundColor: colors.surface,
    borderTopLeftRadius: 22,
    borderTopRightRadius: 22,
    borderColor: colors.border,
    borderWidth: 1,
    padding: 20,
    paddingBottom: 32,
  },
  title: { ...typography.h2, color: colors.gold, fontSize: 18 },
  subtitle: { color: colors.textSecondary, marginTop: 4, marginBottom: 18 },
  input: {
    backgroundColor: colors.surface2,
    borderColor: colors.border,
    borderWidth: 1,
    borderRadius: 12,
    paddingHorizontal: 14,
    paddingVertical: 12,
    color: colors.text,
    marginBottom: 12,
  },
  modeRow: { flexDirection: 'row', gap: 10, marginBottom: 12 },
  modeButton: {
    flex: 1,
    borderColor: colors.border,
    borderWidth: 1,
    borderRadius: 12,
    paddingVertical: 12,
    alignItems: 'center',
  },
  modeButtonActive: { borderColor: colors.gold, backgroundColor: 'rgba(212,175,55,0.12)' },
  modeButtonText: { color: colors.textSecondary, fontSize: 13 },
  modeButtonTextActive: { color: colors.gold, fontWeight: '700' },
  submitButton: { backgroundColor: colors.gold, borderRadius: 12, paddingVertical: 14, alignItems: 'center', marginTop: 6 },
  submitButtonText: { color: colors.bg, fontWeight: '700', fontSize: 16 },
  cancelButton: { alignItems: 'center', paddingVertical: 12, marginTop: 4 },
  cancelButtonText: { color: colors.textMuted },
});

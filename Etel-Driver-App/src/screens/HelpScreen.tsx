import React from 'react';
import { View, StyleSheet, ScrollView } from 'react-native';
import { Text } from '../i18n';
import { colors, typography } from '../theme/colors';

// No support phone/email/Telegram handle was found anywhere in the
// existing codebase to link here — rather than invent one, this screen
// stays intentionally minimal until real contact details are provided.
export default function HelpScreen() {
  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <Text style={styles.title}>Help & Support</Text>

      <View style={styles.card}>
        <Text style={styles.cardTitle}>Emergency</Text>
        <Text style={styles.cardBody}>
          For an emergency during a trip, use the SOS button (hold for a second) on the Requests or Active
          Ride screen — it alerts ETEL support immediately with your location.
        </Text>
      </View>

      <View style={styles.card}>
        <Text style={styles.cardTitle}>Account issues</Text>
        <Text style={styles.cardBody}>
          For account, payout, or compliance document issues, contact your onboarding admin directly — a
          general support channel hasn't been set up in the app yet.
        </Text>
      </View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.bg },
  content: { paddingTop: 56, paddingHorizontal: 20, paddingBottom: 40 },
  title: { ...typography.h1, color: colors.text, marginBottom: 18 },
  card: {
    backgroundColor: colors.surface2,
    borderColor: colors.border,
    borderWidth: 1,
    borderRadius: 14,
    padding: 16,
    marginBottom: 12,
  },
  cardTitle: { color: colors.gold, fontWeight: '700', marginBottom: 6 },
  cardBody: { color: colors.textSecondary, lineHeight: 20 },
});

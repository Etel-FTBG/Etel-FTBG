import React from 'react';
import { View, StyleSheet, ScrollView, Pressable, Linking } from 'react-native';
import { Text } from '../i18n';
import { colors, typography } from '../theme/colors';
import { supportContacts } from '../hub/hubContent';
import { useHubConfig } from '../hub/appConfig';

// The support contacts (phone, Telegram, email) are the ones the owner types in Admin > ETEL app > Names, icons and texts. Until the owner sets
// them, this screen shows no contact (nothing is invented).
export default function HelpScreen() {
  useHubConfig(true, 0);                         // loads the owner's texts and draws this screen again when they arrive
  const c = supportContacts();
  const rows: { icon: string; label: string; url: string }[] = [];
  if (c.phone) rows.push({ icon: 'Call', label: c.phone, url: 'tel:' + c.phone.replace(/[^+0-9]/g, '') });
  if (c.telegram) rows.push({ icon: 'Telegram', label: c.telegram.replace('https://t.me/', '@'), url: c.telegram });
  if (c.email) rows.push({ icon: 'Email', label: c.email, url: 'mailto:' + c.email });
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
        {rows.length ? (
          <>
            <Text style={styles.cardBody}>For account, payout or document issues, contact ETEL support:</Text>
            {rows.map((r) => (
              <Pressable key={r.icon} onPress={() => Linking.openURL(r.url).catch(() => {})} accessibilityRole="link" accessibilityLabel={r.icon + ' ' + r.label} style={styles.contact}>
                <Text style={styles.contactKind}>{r.icon}</Text>
                <Text style={styles.contactVal}>{r.label}</Text>
              </Pressable>
            ))}
          </>
        ) : (
          <Text style={styles.cardBody}>
            For account, payout, or compliance document issues, contact your onboarding admin directly — a
            general support channel hasn't been set up in the app yet.
          </Text>
        )}
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
  contact: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', borderTopWidth: 1, borderTopColor: colors.border, paddingVertical: 12, marginTop: 8 },
  contactKind: { color: colors.gold, fontWeight: '700' },
  contactVal: { color: colors.text },
});

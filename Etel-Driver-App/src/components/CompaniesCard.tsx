import React, { useEffect, useState } from 'react';
import { ActivityIndicator, Alert as RNAlert, Image, StyleSheet, Switch, View } from 'react-native';
import { Text, translate } from '../i18n';
import { useTheme } from '../theme/ThemeContext';
import { Company, companiesFor, loadAlertPrefs, setAlerts, setCompanyOnline, useCompanyState } from '../hub/companies';
import { EtelRole } from '../hub/session';
import { tap } from '../utils/haptics';

// One row per company the account works in, each with its own Online switch and its own Alerts switch.
export default function CompaniesCard({ role }: { role: EtelRole }) {
  const { p } = useTheme();
  const st = useCompanyState();
  const [busy, setBusy] = useState<string | null>(null);
  const list = companiesFor(role);

  useEffect(() => {
    loadAlertPrefs();
  }, []);

  if (list.length === 0) return null;

  async function toggleOnline(c: Company, on: boolean) {
    tap();
    setBusy(c.key);
    try {
      await setCompanyOnline(c.key, on);
    } catch (e) {
      RNAlert.alert(translate('Could not go online'), e instanceof Error ? translate(e.message) : translate('Please try again.'));
    } finally {
      setBusy(null);
    }
  }

  const track = { false: p.surfaceAlt, true: p.gold };
  return (
    <View style={[styles.card, { backgroundColor: p.surface, borderColor: p.border }]}>
      <Text style={[styles.head, { color: p.goldStrong }]}>Your companies</Text>
      {list.map((c, i) => {
        const on = st.online[c.key];
        return (
          <View key={c.key} style={[styles.block, i > 0 && { borderTopColor: p.borderSoft, borderTopWidth: 1 }]}>
            <View style={styles.row}>
              <Image source={c.logo} style={styles.logo} />
              <View style={styles.who}>
                <Text style={[styles.title, { color: p.text }]}>{c.title}</Text>
                <Text style={[styles.sub, { color: on ? p.gold : p.textMuted }]}>{on ? translate('Online') : translate('Offline')}</Text>
              </View>
              {busy === c.key ? (
                <ActivityIndicator color={p.gold} />
              ) : (
                <Switch
                  value={on}
                  onValueChange={(v) => toggleOnline(c, v)}
                  trackColor={track}
                  thumbColor={on ? p.goldStrong : p.textMuted}
                  accessibilityLabel={c.title + ' online'}
                />
              )}
            </View>
            <View style={styles.row}>
              <View style={[styles.who, styles.alertsWho]}>
                <Text style={[styles.alertsTitle, { color: p.text }]}>Alerts</Text>
                <Text style={[styles.sub, { color: p.textMuted }]}>{c.subtitle + ' · ' + translate('shown on the golden light')}</Text>
              </View>
              <Switch
                value={st.alerts[c.key]}
                onValueChange={(v) => {
                  tap();
                  setAlerts(c.key, v);
                }}
                trackColor={track}
                thumbColor={st.alerts[c.key] ? p.goldStrong : p.textMuted}
                accessibilityLabel={c.title + ' alerts'}
              />
            </View>
          </View>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  card: { padding: 14, borderRadius: 20, borderWidth: 1, marginBottom: 8 },
  head: { fontSize: 12, letterSpacing: 2, textTransform: 'uppercase', fontWeight: '700', marginBottom: 8 },
  block: { paddingVertical: 8 },
  row: { flexDirection: 'row', alignItems: 'center' },
  logo: { width: 40, height: 40, resizeMode: 'contain' },
  who: { flex: 1, marginHorizontal: 12 },
  alertsWho: { marginLeft: 52, marginTop: 4 },
  title: { fontSize: 15, fontWeight: '700' },
  alertsTitle: { fontSize: 14 },
  sub: { fontSize: 12, marginTop: 2 },
});

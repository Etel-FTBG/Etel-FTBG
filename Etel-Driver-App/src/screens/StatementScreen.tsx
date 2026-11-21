import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, ScrollView, Share, StyleSheet, View } from 'react-native';
import { Text, useLanguage } from '../i18n';
import { useCalendar } from '../calendar/calendarSetting';
import { colors, typography } from '../theme/colors';
import GoldBoxCard from '../components/GoldBoxCard';
import { Statement, getStatement } from '../api/statement';
import { barFraction, dayName, money, payLabel, percent, shareText, shortDate, tripTime, weekLabel } from '../statement/statementLogic';

// "My weekly statement": for one week (Monday to Sunday, up to 12 weeks back) what riders paid, what ETEL kept and what the driver keeps,
// day by day and trip by trip. It is the same statement the web driver page shows, so the numbers always agree.
export default function StatementScreen() {
  const cal = useCalendar();
  const { lang } = useLanguage();
  const am = lang === 'am';
  const [st, setSt] = useState<Statement | null>(null);
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async (week?: string) => {
    setBusy(true);
    try {
      setSt(await getStatement(week));
      setErr('');
    } catch (e) {
      setErr(e instanceof Error && e.message ? e.message : 'Could not load your statement.');
    } finally {
      setBusy(false);
      setRefreshing(false);
    }
  }, []);
  useEffect(() => {
    load();
  }, [load]);

  const share = () => {
    if (st) Share.share({ message: shareText(st, cal, am) }).catch(() => {});
  };

  const best = st ? Math.max(0, ...st.days.map((d) => d.you_keep)) : 0;
  return (
    <ScrollView
      style={styles.container}
      contentContainerStyle={styles.content}
      refreshControl={<RefreshControl refreshing={refreshing} tintColor={colors.gold} onRefresh={() => { setRefreshing(true); load(st ? st.week_start : undefined); }} />}
    >
      <Text style={styles.title}>My statement</Text>
      {err ? <Text style={styles.error}>{err}</Text> : null}
      {!st && busy ? <ActivityIndicator color={colors.gold} style={{ marginTop: 30 }} /> : null}
      {st ? (
        <View>
          <View style={styles.nav}>
            <Pressable disabled={!st.prev_week || busy} onPress={() => st.prev_week && load(st.prev_week)} style={[styles.navBtn, !st.prev_week && styles.navOff]} accessibilityRole="button" accessibilityLabel="Previous week">
              <Text style={styles.navText}>{'<'}</Text>
            </Pressable>
            <Text style={styles.week}>{weekLabel(st.week_start, st.week_end, cal, am)}</Text>
            <Pressable disabled={!st.next_week || busy} onPress={() => st.next_week && load(st.next_week)} style={[styles.navBtn, !st.next_week && styles.navOff]} accessibilityRole="button" accessibilityLabel="Next week">
              <Text style={styles.navText}>{'>'}</Text>
            </Pressable>
          </View>

          <GoldBoxCard style={styles.card}>
            <View style={styles.pad}>
              <Text style={styles.kicker}>You keep this week</Text>
              <Text style={styles.big}>{money(st.total_with_tips)}</Text>
              <Text style={styles.sub}>
                {st.totals.trips + (st.totals.trips === 1 ? ' trip' : ' trips') + ' - ' + st.online_hours + ' h online' + (st.per_hour != null ? ' - ' + money(st.per_hour) + ' per hour' : '')}
              </Text>
            </View>
          </GoldBoxCard>

          <GoldBoxCard style={styles.card}>
            <View style={styles.pad}>
              <Line label="Riders paid" value={money(st.totals.rider_paid)} />
              {st.totals.promo > 0 ? <Line label="Promo codes (paid by ETEL)" value={'+' + money(st.totals.promo)} /> : null}
              <Line label={'ETEL fee' + (st.commission_rate ? ' (' + percent(st.commission_rate) + ')' : '')} value={'-' + money(st.totals.commission)} />
              <Line label="You keep from trips" value={money(st.totals.you_keep)} bold />
              <Line label="Tips" value={money(st.tips)} />
              <Line label="Total with tips" value={money(st.total_with_tips)} bold last />
            </View>
          </GoldBoxCard>

          <GoldBoxCard style={styles.card}>
            <View style={styles.pad}>
              <Text style={styles.sectionTitle}>Day by day</Text>
              {st.days.map((d) => (
                <View key={d.date} style={styles.dayRow}>
                  <Text style={styles.dayName}>{dayName(d.date, am)}</Text>
                  <View style={styles.track}>
                    <View style={[styles.fill, { width: (Math.round(barFraction(d.you_keep, best) * 100) + '%') as `${number}%` }]} />
                  </View>
                  <Text style={styles.dayVal}>{d.trips > 0 ? money(d.you_keep) : '-'}</Text>
                </View>
              ))}
            </View>
          </GoldBoxCard>

          <Text style={styles.sectionTitle}>Trips</Text>
          {st.trips.length === 0 ? (
            <GoldBoxCard style={styles.card}>
              <View style={styles.empty}>
                <Text style={styles.emptyIcon}>🧾</Text>
                <Text style={styles.emptyTitle}>No finished trips this week.</Text>
              </View>
            </GoldBoxCard>
          ) : (
            st.trips.map((tr) => (
              <GoldBoxCard key={tr.id} style={styles.card} radius={14}>
                <View style={styles.pad}>
                  <View style={styles.tripTop}>
                    <Text style={styles.tripTime}>{tripTime(tr.completed_at, cal, am)}</Text>
                    <Text style={styles.tripKeep}>{money(tr.you_keep)}</Text>
                  </View>
                  <Text style={styles.tripWhere} numberOfLines={1}>{(tr.pickup || '-') + '  >  ' + (tr.dropoff || '-')}</Text>
                  <Text style={styles.tripSub}>
                    {payLabel(tr.collected_by) + ' - rider paid ' + money(tr.rider_paid) + ' - ETEL fee ' + money(tr.commission) + (tr.promo_code ? ' - promo ' + tr.promo_code : '')}
                  </Text>
                </View>
              </GoldBoxCard>
            ))
          )}

          <Pressable style={styles.primary} onPress={share} accessibilityRole="button" accessibilityLabel="Share this statement">
            <Text style={styles.primaryText}>Share this statement</Text>
          </Pressable>
          <Text style={styles.foot}>
            {'Cash collected ' + money(st.totals.cash_collected) + ' - paid through ETEL ' + money(st.totals.etel_collected) + ' - ' + shortDate(st.week_start, cal, am) + ' to ' + shortDate(st.week_end, cal, am)}
          </Text>
        </View>
      ) : null}
    </ScrollView>
  );
}

function Line({ label, value, bold, last }: { label: string; value: string; bold?: boolean; last?: boolean }) {
  return (
    <View style={[styles.line, last && { borderBottomWidth: 0 }]}>
      <Text style={[styles.lineLabel, bold && styles.bold]}>{label}</Text>
      <Text style={[styles.lineValue, bold && styles.bold]}>{value}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.bg },
  content: { paddingTop: 56, paddingHorizontal: 20, paddingBottom: 60 },
  title: { ...typography.h1, color: colors.text, marginBottom: 12, marginTop: 8 },
  error: { color: colors.danger, marginBottom: 8 },
  nav: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 },
  navBtn: { width: 44, height: 44, borderRadius: 22, borderWidth: 1, borderColor: colors.gold, alignItems: 'center', justifyContent: 'center' },
  navOff: { opacity: 0.3 },
  navText: { color: colors.gold, fontSize: 20, fontWeight: '700' },
  week: { color: colors.text, fontWeight: '700', fontSize: 16 },
  card: { marginBottom: 12 },
  pad: { padding: 16 },
  kicker: { color: colors.textSecondary, fontSize: 13 },
  big: { color: colors.gold, fontSize: 34, fontWeight: '800', marginTop: 2 },
  sub: { color: colors.textSecondary, marginTop: 4 },
  line: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 9, borderBottomWidth: 1, borderBottomColor: colors.border },
  lineLabel: { color: colors.textSecondary, flex: 1, paddingRight: 10 },
  lineValue: { color: colors.text },
  bold: { color: colors.text, fontWeight: '800' },
  sectionTitle: { color: colors.gold, fontWeight: '700', marginBottom: 8, marginTop: 6 },
  dayRow: { flexDirection: 'row', alignItems: 'center', marginBottom: 8, gap: 10 },
  dayName: { color: colors.textSecondary, width: 34 },
  track: { flex: 1, height: 10, borderRadius: 5, backgroundColor: colors.surface3, overflow: 'hidden' },
  fill: { height: 10, borderRadius: 5, backgroundColor: colors.gold },
  dayVal: { color: colors.text, width: 96, textAlign: 'right', fontSize: 13 },
  tripTop: { flexDirection: 'row', justifyContent: 'space-between' },
  tripTime: { color: colors.textMuted, fontSize: 12 },
  tripKeep: { color: colors.gold, fontWeight: '800' },
  tripWhere: { color: colors.text, marginTop: 4, fontWeight: '600' },
  tripSub: { color: colors.textMuted, fontSize: 12, marginTop: 4 },
  empty: { alignItems: 'center', padding: 24 },
  emptyIcon: { fontSize: 36, marginBottom: 6 },
  emptyTitle: { color: colors.text, fontWeight: '700' },
  primary: { backgroundColor: colors.gold, borderRadius: 14, paddingVertical: 14, alignItems: 'center', marginTop: 10 },
  primaryText: { color: '#1a1200', fontWeight: '700', fontSize: 16 },
  foot: { color: colors.textMuted, fontSize: 12, textAlign: 'center', marginTop: 12 },
});

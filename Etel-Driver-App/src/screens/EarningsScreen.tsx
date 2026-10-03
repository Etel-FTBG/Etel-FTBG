import React, { useCallback, useEffect, useState } from 'react';
import { View, Text, StyleSheet, ActivityIndicator, RefreshControl, ScrollView } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { colors, typography } from '../theme/colors';
import { getKpiSummary, KpiSummary, getDriverRideHistory, DriverRideHistoryItem } from '../api/driver';
import { ApiError } from '../api/client';
import GoldBoxCard from '../components/GoldBoxCard';

export default function EarningsScreen() {
  const [kpi, setKpi] = useState<KpiSummary | null>(null);
  const [history, setHistory] = useState<DriverRideHistoryItem[]>([]);
  // True once we've confirmed the new driver_get_ride_history action isn't
  // deployed yet (404/"Unknown action" from the live server) — shown as a
  // real note rather than silently hiding the section, since this action
  // only exists locally until the updated boot.php/RideController.php are
  // deployed via the Updater.
  const [historyUnavailable, setHistoryUnavailable] = useState(false);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async () => {
    try {
      const res = await getKpiSummary('today');
      setKpi(res);
    } catch {
      // keep last known value; screen still renders
    }
    try {
      const res = await getDriverRideHistory();
      setHistory(res.rides || []);
      setHistoryUnavailable(false);
    } catch (e) {
      setHistoryUnavailable(e instanceof ApiError);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  if (loading) {
    return (
      <View style={styles.loading}>
        <ActivityIndicator color={colors.gold} size="large" />
      </View>
    );
  }

  return (
    <ScrollView
      style={styles.container}
      contentContainerStyle={styles.content}
      refreshControl={
        <RefreshControl
          refreshing={refreshing}
          onRefresh={() => {
            setRefreshing(true);
            load();
          }}
          tintColor={colors.gold}
        />
      }
    >
      <Text style={styles.title}>Earnings</Text>
      <Text style={styles.subtitle}>Today</Text>

      <GoldBoxCard style={{ marginBottom: 14 }}>
        <Text style={styles.bigLabel}>Total Earnings</Text>
        <Text style={styles.bigValue}>ETB {kpi?.net_earnings?.toFixed(2) ?? '0.00'}</Text>
        <Text style={styles.tripsLine}>{kpi?.completed_rides ?? 0} trips</Text>
      </GoldBoxCard>

      <View style={styles.row}>
        <View style={styles.smallCard}>
          <Text style={styles.smallLabel}>Gross Fare</Text>
          <Text style={styles.smallValue}>ETB {kpi?.gross_fare?.toFixed(2) ?? '0.00'}</Text>
        </View>
        <View style={styles.smallCard}>
          <Text style={styles.smallLabel}>Platform Fee</Text>
          <Text style={styles.smallValue}>ETB {kpi?.platform_fee?.toFixed(2) ?? '0.00'}</Text>
        </View>
      </View>

      <View style={styles.row}>
        <View style={styles.smallCard}>
          <Text style={styles.smallLabel}>Distance</Text>
          <Text style={styles.smallValue}>{kpi?.distance_km?.toFixed(1) ?? '0.0'} km</Text>
        </View>
        <View style={styles.smallCard}>
          <Text style={styles.smallLabel}>Online</Text>
          <Text style={styles.smallValue}>{kpi?.online_hours?.toFixed(1) ?? '0.0'} h</Text>
        </View>
      </View>

      <GoldBoxCard style={{ marginBottom: 14 }}>
        <Text style={styles.bigLabel}>Net ETB / hour (after fuel est.)</Text>
        <Text style={styles.bigValueSmaller}>
          {kpi?.net_etb_per_hour_after_fuel != null ? `ETB ${kpi.net_etb_per_hour_after_fuel.toFixed(2)}` : '—'}
        </Text>
        {kpi?.note ? <Text style={styles.note}>{kpi.note}</Text> : null}
      </GoldBoxCard>

      <Text style={styles.sectionTitle}>Trip History</Text>
      {historyUnavailable ? (
        <Text style={styles.note}>
          Trip history isn't available yet — the backend update for this hasn't been deployed to the live
          server.
        </Text>
      ) : history.length === 0 ? (
        <Text style={styles.emptyText}>No completed trips yet.</Text>
      ) : (
        history.map((trip) => (
          <View key={trip.id} style={styles.tripRow}>
            <View style={{ flex: 1 }}>
              <View style={styles.tripRouteRow}>
                <Text style={styles.tripRoute} numberOfLines={1}>
                  {trip.pickup_address || '—'}
                </Text>
                <Ionicons name="arrow-forward" size={11} color={colors.textMuted} style={{ marginHorizontal: 4 }} />
                <Text style={styles.tripRoute} numberOfLines={1}>
                  {trip.dropoff_address || '—'}
                </Text>
              </View>
              <Text style={styles.tripMeta}>
                {trip.distance_km != null ? `${trip.distance_km} km` : ''}
                {trip.completed_at ? `  •  ${trip.completed_at}` : ''}
              </Text>
            </View>
            <Text style={styles.tripFare}>
              {trip.fare_paid != null ? `ETB ${trip.fare_paid}` : trip.fare_final != null ? `ETB ${trip.fare_final}` : '—'}
            </Text>
          </View>
        ))
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.bg },
  loading: { flex: 1, backgroundColor: colors.bg, alignItems: 'center', justifyContent: 'center' },
  content: { paddingTop: 60, paddingHorizontal: 20, paddingBottom: 40 },
  title: { ...typography.h1, color: colors.text },
  subtitle: { color: colors.textMuted, marginTop: 2, marginBottom: 18 },
  bigLabel: { color: colors.textMuted, ...typography.small, marginBottom: 6 },
  bigValue: { ...typography.metric, color: colors.gold, fontSize: 32 },
  bigValueSmaller: { ...typography.metric, color: colors.text },
  tripsLine: { color: colors.textSecondary, marginTop: 4 },
  note: { color: colors.warning, marginTop: 8, fontSize: 12 },
  row: { flexDirection: 'row', gap: 12, marginBottom: 14 },
  smallCard: {
    flex: 1,
    backgroundColor: colors.surface2,
    borderColor: colors.border,
    borderWidth: 1,
    borderRadius: 14,
    padding: 14,
  },
  smallLabel: { color: colors.textMuted, ...typography.small, marginBottom: 4 },
  smallValue: { color: colors.text, fontSize: 18, fontWeight: '700' },
  sectionTitle: { color: colors.textMuted, ...typography.small, marginTop: 10, marginBottom: 10, letterSpacing: 0.5 },
  emptyText: { color: colors.textMuted },
  tripRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    backgroundColor: colors.surface2,
    borderColor: colors.border,
    borderWidth: 1,
    borderRadius: 12,
    padding: 14,
    marginBottom: 8,
  },
  tripRouteRow: { flexDirection: 'row', alignItems: 'center' },
  tripRoute: { color: colors.text, fontWeight: '600', flexShrink: 1 },
  tripMeta: { color: colors.textMuted, fontSize: 12, marginTop: 2 },
  tripFare: { color: colors.gold, fontWeight: '700', marginLeft: 10 },
});

import React, { useEffect, useState } from 'react';
import { View, StyleSheet, ActivityIndicator, ScrollView, Pressable } from 'react-native';
import { Text } from '../i18n';
import { Ionicons } from '@expo/vector-icons';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../navigation/types';
import { colors, typography } from '../theme/colors';
import { getGoalTiers, GoalTier } from '../api/extras';
import { getDriverProfile } from '../api/driver';
import GoldBoxCard from '../components/GoldBoxCard';

type Props = NativeStackScreenProps<RootStackParamList, 'Goals'>;

export default function GoalsScreen({ navigation }: Props) {
  const [tiers, setTiers] = useState<GoalTier[]>([]);
  const [tripsToday, setTripsToday] = useState(0);
  const [earningsToday, setEarningsToday] = useState(0);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([getGoalTiers(), getDriverProfile()])
      .then(([goals, profile]) => {
        setTiers(goals.tiers);
        setTripsToday(profile.driver.trips_today || 0);
        setEarningsToday(profile.driver.earnings_today || 0);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={colors.gold} size="large" />
      </View>
    );
  }

  // The "next" milestone is the smallest tier above today's trip count —
  // there's no fixed admin-set daily target in this backend, just
  // projected-earnings tiers based on this driver's own average fare
  // (RideController.php:2319), so "goal" here means "next milestone".
  const nextTier = tiers.find((t) => t.trips_required > tripsToday) || tiers[tiers.length - 1];
  const progress = nextTier ? Math.min(1, tripsToday / nextTier.trips_required) : 0;

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <Pressable onPress={() => navigation.goBack()} style={styles.back}>
        <Ionicons name="arrow-back" size={16} color={colors.gold} style={{ marginRight: 4 }} />
        <Text style={styles.backText}>Back</Text>
      </Pressable>
      <Text style={styles.title}>Goals & Performance</Text>

      <GoldBoxCard style={{ marginBottom: 24 }}>
        <Text style={styles.progressLabel}>Today's Progress</Text>
        <Text style={styles.progressValue}>
          {tripsToday} / {nextTier?.trips_required ?? '—'} trips
        </Text>
        <View style={styles.progressTrack}>
          <View style={[styles.progressFill, { width: `${progress * 100}%` }]} />
        </View>
        <Text style={styles.progressSub}>ETB {earningsToday.toFixed(2)} earned</Text>
        {nextTier && (
          <Text style={styles.progressSub}>
            {Math.max(0, nextTier.trips_required - tripsToday)} trips remaining
          </Text>
        )}
      </GoldBoxCard>

      <Text style={styles.sectionTitle}>Goal Tiers</Text>
      {tiers.map((tier) => {
        const reached = tripsToday >= tier.trips_required;
        return (
          <View key={tier.trips_required} style={styles.tierRow}>
            <Text style={styles.tierLabel}>{tier.trips_required} trips</Text>
            <Text style={[styles.tierValue, reached && { color: colors.success }]}>
              ETB {tier.projected_etb.toFixed(2)} {reached ? '✓' : ''}
            </Text>
          </View>
        );
      })}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.bg },
  center: { flex: 1, backgroundColor: colors.bg, alignItems: 'center', justifyContent: 'center' },
  content: { paddingTop: 56, paddingHorizontal: 20, paddingBottom: 40 },
  back: { flexDirection: 'row', alignItems: 'center', marginBottom: 10 },
  backText: { color: colors.gold },
  title: { ...typography.h1, color: colors.text, marginBottom: 18 },
  progressLabel: { color: colors.textMuted, ...typography.small },
  progressValue: { ...typography.h2, color: colors.text, marginTop: 4 },
  progressTrack: {
    height: 8,
    borderRadius: 4,
    backgroundColor: colors.surface3,
    marginTop: 12,
    overflow: 'hidden',
  },
  progressFill: { height: '100%', backgroundColor: colors.gold, borderRadius: 4 },
  progressSub: { color: colors.textSecondary, marginTop: 8 },
  sectionTitle: { color: colors.textMuted, ...typography.small, marginBottom: 10, letterSpacing: 0.5 },
  tierRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    backgroundColor: colors.surface2,
    borderColor: colors.border,
    borderWidth: 1,
    borderRadius: 12,
    padding: 14,
    marginBottom: 8,
  },
  tierLabel: { color: colors.text, fontWeight: '600' },
  tierValue: { color: colors.gold, fontWeight: '600' },
});

import React, { useCallback, useEffect, useRef, useState } from 'react';
import { View, StyleSheet, Pressable, ActivityIndicator, Linking } from 'react-native';
import { Text, Alert } from '../i18n';
import MapView, { Marker } from 'react-native-maps';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { RootStackParamList } from '../navigation/types';
import { colors, typography } from '../theme/colors';
import { ActiveRide } from '../types/ride';
import { isValidCoord } from '../utils/coords';
import {
  cancelRide,
  getMyActiveRide,
  markArrived,
  startRide,
  toggleWait,
} from '../api/ride';
import { setActiveRideIdForLocationTask } from '../location/backgroundLocationTask';
import { ApiError } from '../api/client';
import { rateRider } from '../api/extras';
import SosButton from '../components/SosButton';
import GoldBoxCard from '../components/GoldBoxCard';
import { useNightMode, DAY_MAP_STYLE, NIGHT_MAP_STYLE } from '../context/NightModeContext';

// Matches ride.js's pollRideStatus fallback cadence (6s).
const STATUS_POLL_MS = 6000;

function openNavigation(lat: number | null, lng: number | null) {
  if (!isValidCoord(lat, lng)) return;
  Linking.openURL(`https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}&travelmode=driving`).catch(
    () => {}
  );
}

function formatElapsed(ms: number): string {
  const totalSeconds = Math.max(0, Math.floor(ms / 1000));
  const mins = Math.floor(totalSeconds / 60);
  const secs = totalSeconds % 60;
  return `${mins}:${String(secs).padStart(2, '0')}`;
}

type Props = NativeStackScreenProps<RootStackParamList, 'ActiveRide'>;

const STATE_LABEL: Record<ActiveRide['status'], string> = {
  searching: 'SEARCHING',
  accepted: 'GOING TO PICKUP',
  in_progress: 'ON TRIP',
  arrived: 'AT PICKUP',
  completed: 'COMPLETED',
  cancelled: 'CANCELLED',
};

export default function ActiveRideScreen({ route, navigation }: Props) {
  const { rideId } = route.params;
  const { nightMode } = useNightMode();
  const [ride, setRide] = useState<ActiveRide | null>(null);
  // Snapshot of the last known 'arrived' ride so we can show a real Trip
  // Completed summary once the passenger confirms and my_active_ride stops
  // returning it, instead of yanking the driver straight back to Requests
  // with no confirmation of what they were just paid.
  const [completedSnapshot, setCompletedSnapshot] = useState<ActiveRide | null>(null);
  const [busy, setBusy] = useState(false);
  const [now, setNow] = useState(Date.now());
  const [rating, setRating] = useState(0);
  const [ratingSubmitted, setRatingSubmitted] = useState(false);
  const timer = useRef<ReturnType<typeof setInterval> | null>(null);
  const tickTimer = useRef<ReturnType<typeof setInterval> | null>(null);
  const lastRideRef = useRef<ActiveRide | null>(null);
  // Client-observed trip start — this backend doesn't expose a dedicated
  // "started_at" timestamp for in_progress rides, so the elapsed timer is
  // measured from when this screen first saw the trip start, not faked
  // from a server field that doesn't exist.
  const tripStartRef = useRef<number | null>(null);

  const refresh = useCallback(async () => {
    try {
      const activeRide = await getMyActiveRide();
      if (!activeRide) {
        if (timer.current) clearInterval(timer.current);
        setActiveRideIdForLocationTask(undefined);
        if (lastRideRef.current && lastRideRef.current.status === 'arrived') {
          setCompletedSnapshot(lastRideRef.current);
        } else {
          navigation.replace('MainTabs');
        }
        return;
      }
      if (activeRide.status === 'in_progress' && tripStartRef.current == null) {
        tripStartRef.current = Date.now();
      }
      lastRideRef.current = activeRide;
      setRide(activeRide);
    } catch {
      // keep last known state, retry next tick
    }
  }, [navigation]);

  useEffect(() => {
    setActiveRideIdForLocationTask(rideId);
    refresh();
    timer.current = setInterval(refresh, STATUS_POLL_MS);
    tickTimer.current = setInterval(() => setNow(Date.now()), 1000);
    return () => {
      if (timer.current) clearInterval(timer.current);
      if (tickTimer.current) clearInterval(tickTimer.current);
    };
  }, [rideId, refresh]);

  async function run(action: () => Promise<unknown>) {
    setBusy(true);
    try {
      await action();
      await refresh();
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Action failed. Check your connection.';
      Alert.alert('Error', msg);
    } finally {
      setBusy(false);
    }
  }

  function callPassenger(phone?: string) {
    if (!phone) return;
    Linking.openURL(`tel:${phone}`).catch(() => {});
  }

  function goToChat() {
    navigation.navigate('MainTabs', { screen: 'Chats' } as never);
  }

  async function submitRating(rideIdToRate: number, stars: number) {
    setRating(stars);
    try {
      await rateRider(rideIdToRate, stars);
      setRatingSubmitted(true);
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Could not submit rating.';
      Alert.alert('Error', msg);
    }
  }

  if (completedSnapshot) {
    const fare = completedSnapshot.fare_total ?? completedSnapshot.fare_final ?? completedSnapshot.meter_fare;
    return (
      <View style={styles.completedContainer}>
        <View style={styles.checkCircle}>
          <Text style={styles.checkMark}>✓</Text>
        </View>
        <Text style={styles.completedTitle}>TRIP COMPLETED</Text>
        <Text style={styles.completedFare}>{fare != null ? `ETB ${fare}` : '—'}</Text>

        <GoldBoxCard style={{ width: '100%', marginBottom: 24 }}>
          {completedSnapshot.fare_base != null && <Row label="Base fare" value={`ETB ${completedSnapshot.fare_base}`} />}
          {completedSnapshot.fare_distance != null && (
            <Row label="Distance fare" value={`ETB ${completedSnapshot.fare_distance}`} />
          )}
          {completedSnapshot.fare_wait != null && completedSnapshot.fare_wait > 0 && (
            <Row label="Wait fee" value={`ETB ${completedSnapshot.fare_wait}`} />
          )}
          {completedSnapshot.fare_surge_etb != null && completedSnapshot.fare_surge_etb > 0 && (
            <Row label="Surge" value={`ETB ${completedSnapshot.fare_surge_etb}`} />
          )}
          {completedSnapshot.distance_km != null && (
            <Row label="Distance" value={`${completedSnapshot.distance_km} km`} />
          )}
          {completedSnapshot.passenger_name && <Row label="Passenger" value={completedSnapshot.passenger_name} />}
        </GoldBoxCard>

        {!ratingSubmitted ? (
          <>
            <Text style={styles.rateLabel}>Rate {completedSnapshot.passenger_name || 'your passenger'}</Text>
            <View style={styles.starsRow}>
              {[1, 2, 3, 4, 5].map((n) => (
                <Pressable key={n} onPress={() => submitRating(completedSnapshot.id, n)}>
                  <Text style={[styles.star, n <= rating && styles.starFilled]}>★</Text>
                </Pressable>
              ))}
            </View>
          </>
        ) : (
          <Text style={styles.rateThanks}>Thanks for rating!</Text>
        )}

        <Pressable style={styles.primaryButton} onPress={() => navigation.replace('MainTabs')}>
          <Text style={styles.primaryButtonText}>Done</Text>
        </Pressable>
      </View>
    );
  }

  if (!ride) {
    return (
      <View style={styles.loading}>
        <ActivityIndicator color={colors.gold} size="large" />
      </View>
    );
  }

  const currentFare = ride.meter_fare ?? ride.fare_total ?? ride.fare_estimate;

  return (
    <View style={styles.container}>
      <View style={styles.topBar}>
        <View>
          <Text style={styles.topBarTitle}>ETEL Driver</Text>
          <Text style={styles.topBarStatus}>● {STATE_LABEL[ride.status]}</Text>
        </View>
        <SosButton rideId={ride.id} />
      </View>

      {isValidCoord(ride.pickup_lat, ride.pickup_lng) ? (
        <MapView
          style={styles.map}
          initialRegion={{
            latitude: ride.pickup_lat,
            longitude: ride.pickup_lng as number,
            latitudeDelta: 0.05,
            longitudeDelta: 0.05,
          }}
          customMapStyle={nightMode ? NIGHT_MAP_STYLE : DAY_MAP_STYLE}
        >
          <Marker
            coordinate={{ latitude: ride.pickup_lat, longitude: ride.pickup_lng as number }}
            title="Pickup"
            pinColor={colors.gold}
          />
          {isValidCoord(ride.dropoff_lat, ride.dropoff_lng) && (
            <Marker
              coordinate={{ latitude: ride.dropoff_lat, longitude: ride.dropoff_lng as number }}
              title="Dropoff"
              pinColor={colors.info}
            />
          )}
        </MapView>
      ) : (
        <View style={[styles.map, styles.loading]}>
          <Text style={{ color: colors.textMuted }}>Map unavailable for this ride</Text>
        </View>
      )}

      <View style={styles.sheet}>
        {ride.status === 'accepted' && (
          <>
            <Text style={styles.cardTitle}>GOING TO PICKUP</Text>
            <Text style={styles.addressLabel}>Pickup</Text>
            <Text style={styles.addressLine} numberOfLines={2}>{ride.pickup_address}</Text>
            {ride.passenger_name ? <Text style={styles.passenger}>{ride.passenger_name}</Text> : null}
            <CallChatRow onCall={() => callPassenger(ride.passenger_phone)} onChat={goToChat} />
            <Pressable
              style={styles.secondaryButton}
              onPress={() => openNavigation(ride.pickup_lat, ride.pickup_lng)}
            >
              <Text style={styles.secondaryButtonText}>🧭 Navigate</Text>
            </Pressable>
            <Pressable style={styles.primaryButton} onPress={() => run(() => startRide(ride.id))} disabled={busy}>
              <Text style={styles.primaryButtonText}>Start Trip</Text>
            </Pressable>
          </>
        )}

        {ride.status === 'in_progress' && (
          <>
            <View style={styles.tripHeaderRow}>
              <Text style={styles.cardTitle}>TRIP IN PROGRESS</Text>
              {tripStartRef.current != null && (
                <Text style={styles.elapsed}>{formatElapsed(now - tripStartRef.current)}</Text>
              )}
            </View>
            <Text style={styles.addressLabel}>From</Text>
            <Text style={styles.addressLine} numberOfLines={1}>{ride.pickup_address}</Text>
            <Text style={styles.addressLabel}>To</Text>
            <Text style={styles.addressLine} numberOfLines={1}>{ride.dropoff_address}</Text>
            <Text style={styles.fareLabel}>Fare</Text>
            <Text style={styles.fareValue}>{currentFare != null ? `ETB ${currentFare}` : '—'}</Text>
            <Pressable
              style={styles.secondaryButton}
              onPress={() => openNavigation(ride.dropoff_lat, ride.dropoff_lng)}
            >
              <Text style={styles.secondaryButtonText}>🧭 Navigate to Destination</Text>
            </Pressable>
            <Pressable
              style={styles.secondaryButton}
              onPress={() => run(() => toggleWait(ride.id))}
              disabled={busy}
            >
              <Text style={styles.secondaryButtonText}>
                {ride.wait_active ? 'Stop Wait Timer' : 'Start Wait Timer'}
              </Text>
            </Pressable>
            <Pressable style={styles.primaryButton} onPress={() => run(() => markArrived(ride.id))} disabled={busy}>
              <Text style={styles.primaryButtonText}>Arrived — Bill Customer</Text>
            </Pressable>
          </>
        )}

        {ride.status === 'arrived' && (
          <>
            <Text style={[styles.cardTitle, { color: colors.success }]}>✓ ARRIVED</Text>
            <Text style={styles.addressLine}>{ride.pickup_address}</Text>
            <Text style={styles.waitingText}>Waiting for passenger to confirm arrival…</Text>
            {ride.wait_minutes != null && <Text style={styles.metaLine}>Wait time: {ride.wait_minutes} min</Text>}
            {ride.passenger_name ? <Text style={styles.passenger}>{ride.passenger_name}</Text> : null}
            <CallChatRow onCall={() => callPassenger(ride.passenger_phone)} onChat={goToChat} />
          </>
        )}

        {busy && <ActivityIndicator style={{ marginVertical: 10 }} color={colors.gold} />}

        {(ride.status === 'accepted' || ride.status === 'in_progress') && (
          <Pressable
            style={styles.cancelButton}
            onPress={() =>
              Alert.alert('Cancel ride?', 'This will reopen the request for other drivers.', [
                { text: 'No', style: 'cancel' },
                { text: 'Yes, cancel', style: 'destructive', onPress: () => run(() => cancelRide(ride.id)) },
              ])
            }
            disabled={busy}
          >
            <Text style={styles.cancelButtonText}>Cancel Ride</Text>
          </Pressable>
        )}
      </View>
    </View>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <View style={styles.breakdownRow}>
      <Text style={styles.breakdownLabel}>{label}</Text>
      <Text style={styles.breakdownValue}>{value}</Text>
    </View>
  );
}

function CallChatRow({ onCall, onChat }: { onCall: () => void; onChat: () => void }) {
  return (
    <View style={styles.callChatRow}>
      <Pressable style={styles.callButton} onPress={onCall}>
        <Text style={styles.callButtonText}>📞 Call</Text>
      </Pressable>
      <Pressable style={styles.callButton} onPress={onChat}>
        <Text style={styles.callButtonText}>💬 Chat</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.bg },
  loading: { flex: 1, backgroundColor: colors.bg, alignItems: 'center', justifyContent: 'center' },
  topBar: {
    paddingTop: 56,
    paddingHorizontal: 20,
    paddingBottom: 10,
    backgroundColor: colors.bg,
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
  },
  topBarTitle: { color: colors.text, fontWeight: '700', fontSize: 16 },
  topBarStatus: { color: colors.gold, fontWeight: '700', fontSize: 12, marginTop: 2, letterSpacing: 0.5 },
  map: { flex: 1 },
  sheet: {
    backgroundColor: colors.surface,
    borderTopLeftRadius: 22,
    borderTopRightRadius: 22,
    borderTopColor: colors.border,
    borderTopWidth: 1,
    padding: 20,
  },
  cardTitle: { ...typography.h2, color: colors.gold, fontSize: 16, letterSpacing: 0.5 },
  addressLabel: { color: colors.textMuted, ...typography.small, marginTop: 10 },
  addressLine: { color: colors.text, ...typography.body },
  passenger: { color: colors.textSecondary, marginTop: 6, fontWeight: '600' },
  metaLine: { color: colors.textSecondary, marginTop: 4 },
  fareLabel: { color: colors.textMuted, ...typography.small, marginTop: 12 },
  fareValue: { ...typography.metric, color: colors.gold },
  callChatRow: { flexDirection: 'row', gap: 10, marginTop: 14 },
  callButton: {
    flex: 1,
    backgroundColor: colors.surface3,
    borderRadius: 12,
    paddingVertical: 12,
    alignItems: 'center',
  },
  callButtonText: { color: colors.text, fontWeight: '600' },
  primaryButton: { backgroundColor: colors.gold, borderRadius: 12, paddingVertical: 14, alignItems: 'center', marginTop: 14 },
  primaryButtonText: { color: colors.bg, fontWeight: '700', fontSize: 16 },
  secondaryButton: {
    backgroundColor: 'transparent',
    borderColor: colors.gold,
    borderWidth: 1,
    borderRadius: 12,
    paddingVertical: 12,
    alignItems: 'center',
    marginTop: 14,
  },
  secondaryButtonText: { color: colors.gold, fontWeight: '600' },
  cancelButton: { paddingVertical: 10, alignItems: 'center', marginTop: 8 },
  cancelButtonText: { color: colors.danger },
  waitingText: { color: colors.textSecondary, marginTop: 8 },

  completedContainer: { flex: 1, backgroundColor: colors.bg, alignItems: 'center', paddingTop: 100, paddingHorizontal: 24 },
  checkCircle: {
    width: 72,
    height: 72,
    borderRadius: 36,
    borderWidth: 3,
    borderColor: colors.success,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 16,
  },
  checkMark: { color: colors.success, fontSize: 34, fontWeight: '700' },
  completedTitle: { color: colors.text, fontWeight: '700', letterSpacing: 1, marginBottom: 8 },
  completedFare: { ...typography.metric, color: colors.gold, fontSize: 36, marginBottom: 20 },
  breakdownRow: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 6 },
  breakdownLabel: { color: colors.textSecondary },
  breakdownValue: { color: colors.text, fontWeight: '600' },

  tripHeaderRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  elapsed: { color: colors.text, fontWeight: '700', fontSize: 16 },
  rateLabel: { color: colors.textSecondary, marginBottom: 10 },
  starsRow: { flexDirection: 'row', gap: 10, marginBottom: 24 },
  star: { fontSize: 34, color: colors.surface3 },
  starFilled: { color: colors.gold },
  rateThanks: { color: colors.success, marginBottom: 24 },
});

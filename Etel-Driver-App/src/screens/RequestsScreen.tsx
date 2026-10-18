import React, { useCallback, useEffect, useRef, useState } from 'react';
import { View, Text, StyleSheet, Pressable, ActivityIndicator, Alert } from 'react-native';
import MapView, { Marker } from 'react-native-maps';
import * as Location from 'expo-location';
import { useNavigation } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { RootStackParamList } from '../navigation/types';
import { colors, typography } from '../theme/colors';
import SlideToConfirm from '../components/SlideToConfirm';
import SosButton from '../components/SosButton';
import StreetHailModal from '../components/StreetHailModal';
import { useAuth } from '../context/AuthContext';
import { useNightMode, DAY_MAP_STYLE, NIGHT_MAP_STYLE } from '../context/NightModeContext';
import { acceptRide, declineRide, getMyActiveRide, getNearbyRequests, goOffline, goOnline } from '../api/ride';
import { getDriverProfile } from '../api/driver';
import { NearbyRideRequest } from '../types/ride';
import {
  setActiveRideIdForLocationTask,
  startDriverLocationTracking,
  stopDriverLocationTracking,
} from '../location/backgroundLocationTask';
import { ApiError } from '../api/client';
import { formatCoord, isValidCoord } from '../utils/coords';

// Matches the web app's SSE-fallback polling cadence (js/driver.js: 10s).
const NEARBY_POLL_MS = 10000;
const ACTIVE_RIDE_POLL_MS = 6000;

type Nav = NativeStackNavigationProp<RootStackParamList>;

function secondsUntil(isoLike?: string): number {
  if (!isoLike) return 0;
  const t = new Date(isoLike.replace(' ', 'T')).getTime();
  if (Number.isNaN(t)) return 0;
  return Math.max(0, Math.round((t - Date.now()) / 1000));
}

// The Android emulator's virtual GPS often never produces a fix, so debug
// (__DEV__) builds fall back to Hawassa. Release builds never take this path.
async function getPosition(): Promise<Location.LocationObject> {
  try {
    return await Location.getCurrentPositionAsync({});
  } catch (e) {
    if (__DEV__) {
      return {
        coords: { latitude: 7.0621, longitude: 38.4763, accuracy: 10, altitude: null, altitudeAccuracy: null, heading: null, speed: null },
        timestamp: Date.now(),
      };
    }
    throw e;
  }
}

export default function RequestsScreen() {
  const navigation = useNavigation<Nav>();
  const { user } = useAuth();
  const { nightMode } = useNightMode();
  const [online, setOnline] = useState(false);
  const [busy, setBusy] = useState(false);
  const [requests, setRequests] = useState<NearbyRideRequest[]>([]);
  const [todayEarnings, setTodayEarnings] = useState(0);
  const [todayTrips, setTodayTrips] = useState(0);
  const [region, setRegion] = useState<{ latitude: number; longitude: number } | null>(null);
  const [gpsAccuracy, setGpsAccuracy] = useState<number | null>(null);
  const [connected, setConnected] = useState(true);
  const [now, setNow] = useState(Date.now());
  const [hailModalVisible, setHailModalVisible] = useState(false);

  const nearbyTimer = useRef<ReturnType<typeof setInterval> | null>(null);
  const activeRideTimer = useRef<ReturnType<typeof setInterval> | null>(null);
  const tickTimer = useRef<ReturnType<typeof setInterval> | null>(null);

  useEffect(() => {
    tickTimer.current = setInterval(() => setNow(Date.now()), 1000);
    return () => {
      if (tickTimer.current) clearInterval(tickTimer.current);
    };
  }, []);

  useEffect(() => {
    getPosition().then(
      (pos) => {
        if (isValidCoord(pos.coords.latitude, pos.coords.longitude)) {
          setRegion({ latitude: pos.coords.latitude, longitude: pos.coords.longitude });
        }
        setGpsAccuracy(pos.coords.accuracy ?? null);
      },
      () => {}
    );
  }, []);

  useEffect(() => {
    getDriverProfile()
      .then((res) => {
        setTodayEarnings(res.driver.earnings_today || 0);
        setTodayTrips(res.driver.trips_today || 0);
      })
      .catch(() => {});
  }, [online]);

  const stopPolling = useCallback(() => {
    if (nearbyTimer.current) clearInterval(nearbyTimer.current);
    if (activeRideTimer.current) clearInterval(activeRideTimer.current);
    nearbyTimer.current = null;
    activeRideTimer.current = null;
  }, []);

  const pollNearby = useCallback(async () => {
    try {
      const res = await getNearbyRequests();
      const sorted = [...(res.requests || [])].sort(
        (a, b) => (a.distance_from_driver_km ?? 999) - (b.distance_from_driver_km ?? 999)
      );
      setRequests(sorted);
      setConnected(true);
    } catch {
      // A real failed poll — surfaced as a connection warning rather than
      // silently retried, since a driver online with no requests reaching
      // them looks identical to "genuinely nothing nearby" otherwise.
      setConnected(false);
    }
  }, []);

  const pollActiveRide = useCallback(async () => {
    try {
      const ride = await getMyActiveRide();
      if (ride) {
        stopPolling();
        setActiveRideIdForLocationTask(ride.id);
        navigation.replace('ActiveRide', { rideId: ride.id });
      }
    } catch {
      // ignore, retry next tick
    }
  }, [navigation, stopPolling]);

  useEffect(() => stopPolling, [stopPolling]);

  async function handleGoOnline() {
    setBusy(true);
    try {
      const { status } = await Location.requestForegroundPermissionsAsync();
      if (status !== 'granted') {
        Alert.alert('Location required', 'ETEL Drive needs your location to go online.');
        return;
      }
      const pos = await getPosition();
      if (!isValidCoord(pos.coords.latitude, pos.coords.longitude)) {
        throw new Error('Got an invalid GPS fix — move somewhere with better signal and try again.');
      }
      setRegion({ latitude: pos.coords.latitude, longitude: pos.coords.longitude });
      setGpsAccuracy(pos.coords.accuracy ?? null);
      await goOnline(pos.coords.latitude, pos.coords.longitude);
      await startDriverLocationTracking();
      setOnline(true);
      pollNearby();
      nearbyTimer.current = setInterval(pollNearby, NEARBY_POLL_MS);
      activeRideTimer.current = setInterval(pollActiveRide, ACTIVE_RIDE_POLL_MS);
    } catch (e) {
      const msg =
        e instanceof ApiError || e instanceof Error ? e.message : 'Could not go online. Check your connection.';
      Alert.alert('Error', msg);
    } finally {
      setBusy(false);
    }
  }

  async function handleGoOffline() {
    setBusy(true);
    try {
      stopPolling();
      await goOffline();
      await stopDriverLocationTracking();
      setOnline(false);
      setRequests([]);
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Could not go offline.';
      Alert.alert('Error', msg);
    } finally {
      setBusy(false);
    }
  }

  async function handleAccept(requestId: number) {
    setBusy(true);
    try {
      const ride = await acceptRide(requestId);
      const rideId = (ride as any)?.id ?? requestId;
      stopPolling();
      setActiveRideIdForLocationTask(rideId);
      navigation.navigate('ActiveRide', { rideId });
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Could not accept this ride — it may already be taken.';
      Alert.alert('Error', msg);
      pollNearby();
    } finally {
      setBusy(false);
    }
  }

  function handleDecline(requestId: number) {
    setRequests((rs) => rs.filter((r) => r.id !== requestId));
    // Best effort: if this fails the card is still hidden locally, the request just may reappear.
    declineRide(requestId).catch(() => {});
  }

  function handleHailed(rideId: number) {
    setHailModalVisible(false);
    stopPolling();
    setActiveRideIdForLocationTask(rideId);
    navigation.navigate('ActiveRide', { rideId });
  }

  const topRequest = requests[0];
  const remaining = topRequest ? secondsUntil(topRequest.expires_at) : 0;

  return (
    <View style={styles.container}>
      <View style={styles.header}>
        <View>
          <Text style={styles.headerTitle}>ETEL Driver</Text>
          <View style={styles.statusRow}>
            <View style={[styles.dot, { backgroundColor: online ? colors.success : colors.textMuted }]} />
            <Text style={styles.statusText}>{online ? 'ONLINE' : 'OFFLINE'}</Text>
            {online && !topRequest ? <Text style={styles.statusSub}>· Ready for rides</Text> : null}
          </View>
          {online && (
            <View style={styles.statusRow}>
              <Text style={styles.statusSub}>
                GPS: {gpsAccuracy == null ? '—' : gpsAccuracy <= 30 ? 'Good' : 'Weak'}
              </Text>
              <Text style={[styles.statusSub, !connected && { color: colors.warning }]}>
                · {connected ? 'Connected' : 'Connection lost — reconnecting'}
              </Text>
            </View>
          )}
        </View>
        {online && <SosButton />}
      </View>

      <View style={styles.mapWrap}>
        {region && isValidCoord(region.latitude, region.longitude) ? (
          <MapView
            style={styles.map}
            initialRegion={{ ...region, latitudeDelta: 0.03, longitudeDelta: 0.03 }}
            customMapStyle={nightMode ? NIGHT_MAP_STYLE : DAY_MAP_STYLE}
          >
            <Marker coordinate={region} pinColor={colors.gold} title={user?.name || 'You'} />
            {topRequest && remaining > 0 && isValidCoord(topRequest.pickup_lat, topRequest.pickup_lng) && (
              <Marker
                coordinate={{ latitude: topRequest.pickup_lat, longitude: topRequest.pickup_lng as number }}
                pinColor={colors.info}
                title="Pickup"
              />
            )}
          </MapView>
        ) : (
          <View style={[styles.map, styles.mapPlaceholder]}>
            <ActivityIndicator color={colors.gold} />
          </View>
        )}
      </View>

      <View style={styles.sheet}>
        {topRequest && remaining > 0 ? (
          <>
            <View style={styles.requestHeaderRow}>
              <Text style={styles.requestTitle}>NEW RIDE REQUEST</Text>
              <Text style={styles.countdown}>{remaining}s</Text>
            </View>
            <Text style={styles.fare}>
              {topRequest.fare_final ? `ETB ${topRequest.fare_final}` : 'Fare on arrival'}
            </Text>
            <Text style={styles.metaLine}>
              {topRequest.distance_from_driver_km != null
                ? `${topRequest.distance_from_driver_km.toFixed(1)} km away`
                : ''}
            </Text>
            {topRequest.passenger_name ? (
              <Text style={styles.passengerLine}>{topRequest.passenger_name}</Text>
            ) : null}
            <Text style={styles.addressLabel}>Pickup</Text>
            <Text style={styles.addressLine} numberOfLines={1}>
              {topRequest.pickup_address || formatCoord(topRequest.pickup_lat, topRequest.pickup_lng)}
            </Text>
            <Text style={styles.addressLabel}>Drop-off</Text>
            <Text style={styles.addressLine} numberOfLines={1}>
              {topRequest.dropoff_address || formatCoord(topRequest.dropoff_lat, topRequest.dropoff_lng)}
            </Text>

            <View style={styles.requestActions}>
              <Pressable
                style={styles.declineButton}
                onPress={() => handleDecline(topRequest.id)}
                disabled={busy}
              >
                <Text style={styles.declineButtonText}>Decline</Text>
              </Pressable>
              <View style={{ flex: 1 }}>
                <SlideToConfirm
                  label="Slide to Accept"
                  onConfirm={() => handleAccept(topRequest.id)}
                  disabled={busy}
                />
              </View>
            </View>
          </>
        ) : online ? (
          <>
            <Text style={styles.stateTitle}>ONLINE</Text>
            <Text style={styles.stateSubtitle}>Waiting for nearby requests</Text>
            <Pressable onPress={() => navigation.navigate('Goals')}>
              <TodayRow earnings={todayEarnings} trips={todayTrips} />
            </Pressable>
            <StreetHailButton disabled={busy} onPress={() => setHailModalVisible(true)} />
            <Pressable style={styles.goOfflineLink} onPress={handleGoOffline} disabled={busy}>
              <Text style={styles.goOfflineLinkText}>Go offline</Text>
            </Pressable>
          </>
        ) : (
          <>
            <Text style={styles.stateTitle}>OFFLINE</Text>
            <Text style={styles.stateSubtitle}>You are not receiving ride requests.</Text>
            <View style={{ marginTop: 16, marginBottom: 16 }}>
              <SlideToConfirm label="Slide to go online" onConfirm={handleGoOnline} disabled={busy} />
            </View>
            <Pressable onPress={() => navigation.navigate('Goals')}>
              <TodayRow earnings={todayEarnings} trips={todayTrips} />
            </Pressable>
            <StreetHailButton disabled={busy} onPress={() => setHailModalVisible(true)} />
          </>
        )}
        {busy && <ActivityIndicator style={{ marginTop: 12 }} color={colors.gold} />}
      </View>

      <StreetHailModal
        visible={hailModalVisible}
        onClose={() => setHailModalVisible(false)}
        onHailed={handleHailed}
      />
    </View>
  );
}

function TodayRow({ earnings, trips }: { earnings: number; trips: number }) {
  return (
    <View style={styles.todayRow}>
      <View>
        <Text style={styles.todayLabel}>Today's earnings</Text>
        <Text style={styles.todayValue}>ETB {earnings.toFixed(2)}</Text>
      </View>
      <View>
        <Text style={styles.todayLabel}>Today's trips</Text>
        <Text style={styles.todayValue}>{trips}</Text>
      </View>
    </View>
  );
}

function StreetHailButton({ disabled, onPress }: { disabled?: boolean; onPress: () => void }) {
  return (
    <Pressable style={styles.streetHailButton} disabled={disabled} onPress={onPress}>
      <Text style={styles.streetHailText}>🚕 Street Hail — link a customer</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.bg },
  header: {
    paddingTop: 56,
    paddingHorizontal: 20,
    paddingBottom: 12,
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
  },
  headerTitle: { ...typography.h2, color: colors.text },
  statusRow: { flexDirection: 'row', alignItems: 'center', marginTop: 4, gap: 6 },
  dot: { width: 8, height: 8, borderRadius: 4 },
  statusText: { color: colors.textSecondary, fontWeight: '700', fontSize: 12, letterSpacing: 0.5 },
  statusSub: { color: colors.textMuted, fontSize: 12 },
  mapWrap: { flex: 1 },
  map: { flex: 1 },
  mapPlaceholder: { backgroundColor: colors.surface, alignItems: 'center', justifyContent: 'center' },
  sheet: {
    backgroundColor: colors.surface,
    borderTopLeftRadius: 22,
    borderTopRightRadius: 22,
    borderTopWidth: 1,
    borderColor: colors.border,
    padding: 20,
  },
  stateTitle: { ...typography.h2, color: colors.success, marginBottom: 4 },
  stateSubtitle: { color: colors.textSecondary, ...typography.body },
  todayRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    marginTop: 18,
    marginBottom: 14,
  },
  todayLabel: { color: colors.textMuted, ...typography.small, marginBottom: 2 },
  todayValue: { color: colors.text, ...typography.metric },
  streetHailButton: {
    backgroundColor: colors.surface3,
    borderColor: colors.goldDark,
    borderWidth: 1,
    borderRadius: 12,
    paddingVertical: 14,
    alignItems: 'center',
  },
  streetHailText: { color: colors.gold, fontWeight: '600' },
  goOfflineLink: { alignItems: 'center', marginTop: 14 },
  goOfflineLinkText: { color: colors.textMuted, fontSize: 13 },

  requestHeaderRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  requestTitle: { color: colors.warning, fontWeight: '700', letterSpacing: 0.5, fontSize: 13 },
  countdown: { color: colors.warning, fontWeight: '700', fontSize: 13 },
  fare: { ...typography.metric, color: colors.gold, marginTop: 6 },
  metaLine: { color: colors.textSecondary, marginTop: 2 },
  passengerLine: { color: colors.text, marginTop: 8, fontWeight: '600' },
  addressLabel: { color: colors.textMuted, ...typography.small, marginTop: 10 },
  addressLine: { color: colors.text, ...typography.body },
  requestActions: { flexDirection: 'row', gap: 10, marginTop: 18, alignItems: 'center' },
  declineButton: {
    borderColor: colors.danger,
    borderWidth: 1,
    borderRadius: 30,
    paddingHorizontal: 18,
    paddingVertical: 16,
  },
  declineButtonText: { color: colors.danger, fontWeight: '600' },
});

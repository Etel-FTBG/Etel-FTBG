import React, { useCallback, useEffect, useState } from 'react';
import {
  View,
  Text,
  StyleSheet,
  ScrollView,
  Pressable,
  ActivityIndicator,
  TextInput,
  Alert,
  Image,
} from 'react-native';
import * as ImagePicker from 'expo-image-picker';
import { colors, typography } from '../theme/colors';
import { getDriverProfile, DriverProfile } from '../api/driver';
import { cashout, submitManualTopup, getTopupHistory, TopupRecord } from '../api/wallet';
import { uploadImage } from '../api/upload';
import { ApiError } from '../api/client';
import GoldBoxCard from '../components/GoldBoxCard';

export default function WalletScreen() {
  const [driver, setDriver] = useState<DriverProfile | null>(null);
  const [topups, setTopups] = useState<TopupRecord[]>([]);
  const [loading, setLoading] = useState(true);
  const [cashoutAmount, setCashoutAmount] = useState('');
  const [cashingOut, setCashingOut] = useState(false);
  const [topupAmount, setTopupAmount] = useState('');
  const [topupNote, setTopupNote] = useState('');
  const [receiptUri, setReceiptUri] = useState<string | null>(null);
  const [submittingTopup, setSubmittingTopup] = useState(false);

  const load = useCallback(async () => {
    try {
      const [profile, history] = await Promise.all([getDriverProfile(), getTopupHistory()]);
      setDriver(profile.driver);
      setTopups(history.topups || []);
    } catch {
      // screen still renders
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  async function handleCashout() {
    const amount = parseFloat(cashoutAmount);
    if (!amount || amount <= 0) {
      Alert.alert('Invalid amount', 'Enter an amount greater than 0.');
      return;
    }
    setCashingOut(true);
    try {
      await cashout(amount);
      setCashoutAmount('');
      Alert.alert('Cashed out', `ETB ${amount.toFixed(2)} sent to your Etel Pay wallet.`);
      load();
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Cashout failed.';
      Alert.alert('Error', msg);
    } finally {
      setCashingOut(false);
    }
  }

  async function pickReceipt() {
    const { status } = await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (status !== 'granted') {
      Alert.alert('Permission needed', 'Allow photo library access to attach the receipt.');
      return;
    }
    const result = await ImagePicker.launchImageLibraryAsync({ quality: 0.7, mediaTypes: ImagePicker.MediaTypeOptions.Images });
    if (!result.canceled && result.assets?.[0]) setReceiptUri(result.assets[0].uri);
  }

  async function handleManualTopup() {
    const amount = parseFloat(topupAmount);
    if (!amount || amount <= 0) {
      Alert.alert('Invalid amount', 'Enter an amount greater than 0.');
      return;
    }
    if (!receiptUri) {
      Alert.alert('Receipt required', 'Attach a photo of the receipt.');
      return;
    }
    setSubmittingTopup(true);
    try {
      const url = await uploadImage(receiptUri);
      const res = await submitManualTopup(amount, url, topupNote || undefined);
      Alert.alert('Submitted', (res as any)?.message || 'Your top-up request was submitted.');
      setTopupAmount('');
      setTopupNote('');
      setReceiptUri(null);
      load();
    } catch (e) {
      const msg = e instanceof ApiError || e instanceof Error ? e.message : 'Submission failed.';
      Alert.alert('Error', msg);
    } finally {
      setSubmittingTopup(false);
    }
  }

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={colors.gold} size="large" />
      </View>
    );
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <Text style={styles.title}>Account & Wallet</Text>

      <GoldBoxCard style={{ marginBottom: 20 }}>
        <Text style={styles.balanceLabel}>Available balance</Text>
        <Text style={styles.balanceValue}>ETB {driver?.available_balance?.toFixed(2) ?? '0.00'}</Text>
        <Text style={styles.balanceSub}>ETEL Money: ETB {driver?.etel_money_balance?.toFixed(2) ?? '0.00'}</Text>
      </GoldBoxCard>

      <Text style={styles.sectionTitle}>Cash Out to Etel Pay</Text>
      <View style={styles.row}>
        <TextInput
          style={[styles.input, { flex: 1 }]}
          placeholder="Amount (ETB)"
          placeholderTextColor={colors.textMuted}
          keyboardType="decimal-pad"
          value={cashoutAmount}
          onChangeText={setCashoutAmount}
        />
        <Pressable style={styles.actionButton} onPress={handleCashout} disabled={cashingOut}>
          {cashingOut ? <ActivityIndicator color={colors.bg} /> : <Text style={styles.actionButtonText}>Cash Out</Text>}
        </Pressable>
      </View>

      <Text style={styles.sectionTitle}>Manual Top-Up (bank transfer / cash deposit)</Text>
      <TextInput
        style={styles.input}
        placeholder="Amount (ETB)"
        placeholderTextColor={colors.textMuted}
        keyboardType="decimal-pad"
        value={topupAmount}
        onChangeText={setTopupAmount}
      />
      <TextInput
        style={[styles.input, { marginTop: 8 }]}
        placeholder="Note (optional)"
        placeholderTextColor={colors.textMuted}
        value={topupNote}
        onChangeText={setTopupNote}
      />
      <Pressable style={styles.photoButton} onPress={pickReceipt}>
        {receiptUri ? (
          <Image source={{ uri: receiptUri }} style={styles.photoPreview} />
        ) : (
          <Text style={styles.photoButtonText}>📎 Attach Receipt Photo</Text>
        )}
      </Pressable>
      <Pressable style={styles.actionButtonFull} onPress={handleManualTopup} disabled={submittingTopup}>
        {submittingTopup ? (
          <ActivityIndicator color={colors.bg} />
        ) : (
          <Text style={styles.actionButtonText}>Submit Top-Up Request</Text>
        )}
      </Pressable>

      <Text style={styles.sectionTitle}>History</Text>
      {topups.length === 0 ? (
        <Text style={styles.emptyText}>No top-up requests yet.</Text>
      ) : (
        topups.map((t) => (
          <View key={t.id} style={styles.historyRow}>
            <View style={{ flex: 1 }}>
              <Text style={styles.historyAmount}>ETB {t.amount}</Text>
              <Text style={styles.historyMeta}>
                {t.source} · {t.created_at}
              </Text>
            </View>
            <Text
              style={[
                styles.historyStatus,
                {
                  color:
                    t.status === 'completed' || t.status === 'approved'
                      ? colors.success
                      : t.status === 'rejected' || t.status === 'failed'
                        ? colors.danger
                        : colors.warning,
                },
              ]}
            >
              {t.status}
            </Text>
          </View>
        ))
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.bg },
  center: { flex: 1, backgroundColor: colors.bg, alignItems: 'center', justifyContent: 'center' },
  content: { paddingTop: 56, paddingHorizontal: 20, paddingBottom: 40 },
  title: { ...typography.h1, color: colors.text, marginBottom: 18 },
  balanceLabel: { color: colors.textMuted, ...typography.small },
  balanceValue: { ...typography.metric, color: colors.gold, fontSize: 32, marginTop: 4 },
  balanceSub: { color: colors.textSecondary, marginTop: 6 },
  sectionTitle: { color: colors.textMuted, ...typography.small, marginTop: 18, marginBottom: 10, letterSpacing: 0.5 },
  row: { flexDirection: 'row', gap: 10 },
  input: {
    backgroundColor: colors.surface2,
    borderColor: colors.border,
    borderWidth: 1,
    borderRadius: 10,
    paddingHorizontal: 14,
    paddingVertical: 10,
    color: colors.text,
  },
  actionButton: { backgroundColor: colors.gold, borderRadius: 10, paddingHorizontal: 18, justifyContent: 'center' },
  actionButtonFull: { backgroundColor: colors.gold, borderRadius: 10, paddingVertical: 12, alignItems: 'center', marginTop: 10 },
  actionButtonText: { color: colors.bg, fontWeight: '700' },
  photoButton: {
    backgroundColor: colors.surface2,
    borderColor: colors.border,
    borderWidth: 1,
    borderRadius: 10,
    height: 90,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 8,
    overflow: 'hidden',
  },
  photoButtonText: { color: colors.textSecondary },
  photoPreview: { width: '100%', height: '100%' },
  emptyText: { color: colors.textMuted },
  historyRow: {
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
  historyAmount: { color: colors.text, fontWeight: '700' },
  historyMeta: { color: colors.textMuted, fontSize: 12, marginTop: 2 },
  historyStatus: { fontWeight: '700', fontSize: 12, textTransform: 'uppercase' },
});

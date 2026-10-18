import React, { useCallback, useEffect, useState } from 'react';
import { View, StyleSheet, ScrollView, Pressable, ActivityIndicator, Image } from 'react-native';
import { Text, TextInput, Alert } from '../i18n';
import * as ImagePicker from 'expo-image-picker';
import { colors, typography } from '../theme/colors';
import {
  getComplianceStatus,
  submitComplianceDocument,
  ComplianceDocStatus,
  ComplianceDocType,
} from '../api/wallet';
import { uploadImage } from '../api/upload';
import { ApiError } from '../api/client';

const DOC_LABELS: Record<ComplianceDocType, string> = {
  insurance: 'Insurance',
  technical_inspection: 'Technical Inspection',
  road_fund: 'Road Fund',
};

export default function DocumentsScreen() {
  const [docs, setDocs] = useState<Record<ComplianceDocType, ComplianceDocStatus> | null>(null);
  const [loading, setLoading] = useState(true);
  const [expanded, setExpanded] = useState<ComplianceDocType | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await getComplianceStatus();
      setDocs(res.documents);
    } catch {
      // screen still renders with an empty state
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={colors.gold} size="large" />
      </View>
    );
  }

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <Text style={styles.title}>Documents</Text>
      {(Object.keys(DOC_LABELS) as ComplianceDocType[]).map((docType) => (
        <DocRow
          key={docType}
          docType={docType}
          status={docs?.[docType]}
          expanded={expanded === docType}
          onToggle={() => setExpanded(expanded === docType ? null : docType)}
          onSubmitted={load}
        />
      ))}
    </ScrollView>
  );
}

function DocRow({
  docType,
  status,
  expanded,
  onToggle,
  onSubmitted,
}: {
  docType: ComplianceDocType;
  status?: ComplianceDocStatus;
  expanded: boolean;
  onToggle: () => void;
  onSubmitted: () => void;
}) {
  const [expiryDate, setExpiryDate] = useState('');
  const [photoUri, setPhotoUri] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function pickPhoto() {
    const { status: permStatus } = await ImagePicker.requestCameraPermissionsAsync();
    if (permStatus !== 'granted') {
      Alert.alert('Camera permission needed', 'Allow camera access to photograph the document.');
      return;
    }
    const result = await ImagePicker.launchCameraAsync({ quality: 0.7, mediaTypes: ImagePicker.MediaTypeOptions.Images });
    if (!result.canceled && result.assets?.[0]) {
      setPhotoUri(result.assets[0].uri);
    }
  }

  async function submit() {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(expiryDate)) {
      Alert.alert('Invalid date', 'Enter the expiry date as YYYY-MM-DD.');
      return;
    }
    if (!photoUri) {
      Alert.alert('Photo required', 'Take a photo of the document first.');
      return;
    }
    setSubmitting(true);
    try {
      const url = await uploadImage(photoUri);
      await submitComplianceDocument(docType, expiryDate, url);
      Alert.alert('Submitted', 'Your document was submitted for admin review.');
      setExpiryDate('');
      setPhotoUri(null);
      onSubmitted();
    } catch (e) {
      const msg = e instanceof ApiError || e instanceof Error ? e.message : 'Submission failed.';
      Alert.alert('Error', msg);
    } finally {
      setSubmitting(false);
    }
  }

  const badgeColor =
    status?.status === 'valid' ? colors.success : status?.status === 'expired' ? colors.danger : colors.textMuted;

  return (
    <View style={styles.card}>
      <Pressable style={styles.cardHeader} onPress={onToggle}>
        <Text style={styles.docLabel}>{DOC_LABELS[docType]}</Text>
        <Text style={[styles.badge, { color: badgeColor }]}>
          {status?.status === 'valid'
            ? `Verified · exp ${status.expiry_date}`
            : status?.status === 'expired'
              ? 'Expired'
              : 'Not on file'}
        </Text>
      </Pressable>
      {status?.pending && <Text style={styles.pendingNote}>Pending review since {status.pending.created_at}</Text>}

      {expanded && (
        <View style={styles.form}>
          <TextInput
            style={styles.input}
            placeholder="Expiry date (YYYY-MM-DD)"
            placeholderTextColor={colors.textMuted}
            value={expiryDate}
            onChangeText={setExpiryDate}
          />
          <Pressable style={styles.photoButton} onPress={pickPhoto}>
            {photoUri ? (
              <Image source={{ uri: photoUri }} style={styles.photoPreview} />
            ) : (
              <Text style={styles.photoButtonText}>📷 Take Photo</Text>
            )}
          </Pressable>
          <Pressable style={styles.submitButton} onPress={submit} disabled={submitting}>
            {submitting ? (
              <ActivityIndicator color={colors.bg} />
            ) : (
              <Text style={styles.submitButtonText}>Submit</Text>
            )}
          </Pressable>
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.bg },
  center: { flex: 1, backgroundColor: colors.bg, alignItems: 'center', justifyContent: 'center' },
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
  cardHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  docLabel: { color: colors.text, fontWeight: '600' },
  badge: { fontSize: 12, fontWeight: '600' },
  pendingNote: { color: colors.warning, fontSize: 12, marginTop: 6 },
  form: { marginTop: 14, gap: 10 },
  input: {
    backgroundColor: colors.surface3,
    borderColor: colors.border,
    borderWidth: 1,
    borderRadius: 10,
    paddingHorizontal: 14,
    paddingVertical: 10,
    color: colors.text,
  },
  photoButton: {
    backgroundColor: colors.surface3,
    borderColor: colors.border,
    borderWidth: 1,
    borderRadius: 10,
    height: 100,
    alignItems: 'center',
    justifyContent: 'center',
    overflow: 'hidden',
  },
  photoButtonText: { color: colors.textSecondary },
  photoPreview: { width: '100%', height: '100%' },
  submitButton: { backgroundColor: colors.gold, borderRadius: 10, paddingVertical: 12, alignItems: 'center' },
  submitButtonText: { color: colors.bg, fontWeight: '700' },
});

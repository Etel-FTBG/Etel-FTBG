import React, { useCallback, useEffect, useState } from 'react';
import { View, StyleSheet, ScrollView, Pressable, ActivityIndicator } from 'react-native';
import { Text } from '../i18n';
import { colors, typography } from '../theme/colors';
import { getAnnouncements, markAnnouncementRead, Announcement } from '../api/extras';

export default function NotificationsScreen() {
  const [items, setItems] = useState<Announcement[]>([]);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    try {
      const res = await getAnnouncements();
      setItems(res.announcements || []);
    } catch {
      // screen still renders empty
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  async function handlePress(a: Announcement) {
    if (a.is_read) return;
    setItems((prev) => prev.map((x) => (x.id === a.id ? { ...x, is_read: true } : x)));
    try {
      await markAnnouncementRead(a.id);
    } catch {
      // best-effort; a retry on next load reconciles state
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
      <Text style={styles.title}>Notifications</Text>
      {items.length === 0 ? (
        <Text style={styles.emptyText}>Nothing here yet.</Text>
      ) : (
        items.map((a) => (
          <Pressable
            key={a.id}
            style={[styles.card, !a.is_read && styles.cardUnread]}
            onPress={() => handlePress(a)}
          >
            <View style={styles.cardHeader}>
              <Text style={styles.cardTitle}>{a.title}</Text>
              {!a.is_read && <View style={styles.unreadDot} />}
            </View>
            <Text style={styles.cardBody}>{a.body}</Text>
            <Text style={styles.cardMeta}>
              {a.category} · {a.created_at}
            </Text>
          </Pressable>
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
  emptyText: { color: colors.textMuted },
  card: {
    backgroundColor: colors.surface2,
    borderColor: colors.border,
    borderWidth: 1,
    borderRadius: 14,
    padding: 16,
    marginBottom: 10,
  },
  cardUnread: { borderColor: colors.gold },
  cardHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  cardTitle: { color: colors.text, fontWeight: '700', flex: 1 },
  unreadDot: { width: 8, height: 8, borderRadius: 4, backgroundColor: colors.gold, marginLeft: 8 },
  cardBody: { color: colors.textSecondary, marginTop: 6 },
  cardMeta: { color: colors.textMuted, fontSize: 11, marginTop: 8 },
});

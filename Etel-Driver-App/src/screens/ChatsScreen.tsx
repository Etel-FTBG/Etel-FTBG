import React, { useCallback, useEffect, useRef, useState } from 'react';
import { View, StyleSheet, FlatList, Pressable, KeyboardAvoidingView, Platform, ActivityIndicator } from 'react-native';
import { Text, TextInput } from '../i18n';
import { colors, typography } from '../theme/colors';
import { getMyActiveRide } from '../api/ride';
import { getRideMessages, sendRideMessage, pingTyping, RideMessage } from '../api/chat';

// Matches ride_chat.js's poll cadence (3s).
const POLL_MS = 3000;

export default function ChatsScreen() {
  const [rideId, setRideId] = useState<number | null>(null);
  const [passengerName, setPassengerName] = useState<string | undefined>();
  const [loading, setLoading] = useState(true);
  const [messages, setMessages] = useState<RideMessage[]>([]);
  const [otherTyping, setOtherTyping] = useState(false);
  const [draft, setDraft] = useState('');
  const lastIdRef = useRef(0);
  const pollTimer = useRef<ReturnType<typeof setInterval> | null>(null);

  useEffect(() => {
    getMyActiveRide()
      .then((ride) => {
        if (ride) {
          setRideId(ride.id);
          setPassengerName(ride.passenger_name);
        }
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  const poll = useCallback(async (id: number) => {
    try {
      const res = await getRideMessages(id, lastIdRef.current);
      if (res.messages.length) {
        setMessages((prev) => [...prev, ...res.messages]);
        lastIdRef.current = res.messages[res.messages.length - 1].id;
      }
      setOtherTyping(res.other_typing);
    } catch {
      // retry next tick
    }
  }, []);

  useEffect(() => {
    if (!rideId) return;
    lastIdRef.current = 0;
    setMessages([]);
    poll(rideId);
    pollTimer.current = setInterval(() => poll(rideId), POLL_MS);
    return () => {
      if (pollTimer.current) clearInterval(pollTimer.current);
    };
  }, [rideId, poll]);

  async function handleSend() {
    if (!rideId || !draft.trim()) return;
    const body = draft.trim();
    setDraft('');
    try {
      await sendRideMessage(rideId, body);
      poll(rideId);
    } catch {
      // leave the draft cleared but let the next poll reconcile state
    }
  }

  function handleChangeDraft(text: string) {
    setDraft(text);
    if (rideId) pingTyping(rideId).catch(() => {});
  }

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={colors.gold} size="large" />
      </View>
    );
  }

  if (!rideId) {
    return (
      <View style={styles.center}>
        <Text style={styles.emptyTitle}>No active conversation</Text>
        <Text style={styles.emptySubtitle}>Chat opens automatically once you accept a ride.</Text>
      </View>
    );
  }

  return (
    <KeyboardAvoidingView
      style={styles.container}
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      keyboardVerticalOffset={80}
    >
      <View style={styles.header}>
        <Text style={styles.headerTitle}>{passengerName || 'Passenger'}</Text>
        {otherTyping ? <Text style={styles.typing}>typing…</Text> : null}
      </View>

      <FlatList
        style={styles.list}
        data={messages}
        keyExtractor={(m) => String(m.id)}
        renderItem={({ item }) => (
          <View style={[styles.bubble, item.sender_role === 'driver' ? styles.bubbleMine : styles.bubbleTheirs]}>
            <Text style={styles.bubbleText}>{item.body}</Text>
          </View>
        )}
        contentContainerStyle={{ padding: 16 }}
      />

      <View style={styles.inputRow}>
        <TextInput
          style={styles.input}
          placeholder="Message…"
          placeholderTextColor={colors.textMuted}
          value={draft}
          onChangeText={handleChangeDraft}
          maxLength={500}
        />
        <Pressable style={styles.sendButton} onPress={handleSend}>
          <Text style={styles.sendButtonText}>Send</Text>
        </Pressable>
      </View>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.bg },
  center: { flex: 1, backgroundColor: colors.bg, alignItems: 'center', justifyContent: 'center', padding: 24 },
  emptyTitle: { ...typography.h2, color: colors.text },
  emptySubtitle: { color: colors.textMuted, marginTop: 6, textAlign: 'center' },
  header: { paddingTop: 56, paddingHorizontal: 20, paddingBottom: 12, borderBottomColor: colors.border, borderBottomWidth: 1 },
  headerTitle: { color: colors.text, fontWeight: '700', fontSize: 16 },
  typing: { color: colors.textMuted, fontSize: 12, marginTop: 2 },
  list: { flex: 1 },
  bubble: { maxWidth: '80%', borderRadius: 14, paddingHorizontal: 14, paddingVertical: 10, marginBottom: 8 },
  bubbleMine: { backgroundColor: colors.gold, alignSelf: 'flex-end' },
  bubbleTheirs: { backgroundColor: colors.surface2, alignSelf: 'flex-start' },
  bubbleText: { color: colors.bg },
  inputRow: {
    flexDirection: 'row',
    padding: 12,
    gap: 8,
    borderTopColor: colors.border,
    borderTopWidth: 1,
    backgroundColor: colors.surface,
  },
  input: {
    flex: 1,
    backgroundColor: colors.surface2,
    borderRadius: 20,
    paddingHorizontal: 16,
    paddingVertical: 10,
    color: colors.text,
  },
  sendButton: { backgroundColor: colors.gold, borderRadius: 20, paddingHorizontal: 18, justifyContent: 'center' },
  sendButtonText: { color: colors.bg, fontWeight: '700' },
});

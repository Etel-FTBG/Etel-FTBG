import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, ScrollView, StyleSheet, View } from 'react-native';
import { Text, TextInput, Alert, useLanguage } from '../i18n';
import { useCalendar } from '../calendar/calendarSetting';
import { fmtStamp } from '../calendar/dateFormat';
import { colors, typography } from '../theme/colors';
import { useSession } from '../hub/session';
import GoldBoxCard from '../components/GoldBoxCard';
import { SupMessage, SupMeta, SupTicket, supClose, supCreate, supGet, supMeta, supMyTickets, supRate, supReply } from '../api/support';
import { SUPPORT_CATEGORIES, appForRole, canMarkSolved, statusLabel, validateReply, validateRequest } from '../support/supportLogic';

type Mode = { kind: 'list' } | { kind: 'new' } | { kind: 'thread'; id: number };

// Ask ETEL support from inside the app: write a request, read the answers, reply, mark it solved and rate it. These are the same requests the staff
// see in Admin > Support desk (and the web Help page shows), so nothing is lost between the app and the desk.
export default function SupportScreen() {
  const [mode, setMode] = useState<Mode>({ kind: 'list' });
  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
      {mode.kind === 'list' && <ListView onNew={() => setMode({ kind: 'new' })} onOpen={(id) => setMode({ kind: 'thread', id })} />}
      {mode.kind === 'new' && <NewView onBack={() => setMode({ kind: 'list' })} onSent={(id) => setMode({ kind: 'thread', id })} />}
      {mode.kind === 'thread' && <ThreadView id={mode.id} onBack={() => setMode({ kind: 'list' })} />}
    </ScrollView>
  );
}

function ListView({ onNew, onOpen }: { onNew: () => void; onOpen: (id: number) => void }) {
  const cal = useCalendar();
  const { lang: listLang } = useLanguage();
  const [tickets, setTickets] = useState<SupTicket[] | null>(null);
  const [meta, setMeta] = useState<SupMeta | null>(null);
  const [err, setErr] = useState('');
  const [refreshing, setRefreshing] = useState(false);
  const load = useCallback(async () => {
    try {
      const [t, m] = await Promise.all([supMyTickets(), supMeta()]);
      setTickets(t.tickets);
      setMeta(m);
      setErr('');
    } catch (e) {
      setErr(e instanceof Error && e.message ? e.message : 'Could not load your requests.');
      setTickets((cur) => cur || []);
    }
  }, []);
  useEffect(() => {
    load();
  }, [load]);
  const unreadCount = (tickets || []).filter((t) => t.unread).length;
  return (
    <View>
      <GoldBoxCard style={styles.hero}>
        <View style={styles.heroRow}>
          <View style={styles.heroIcon}><Text style={styles.heroIconText}>🎧</Text></View>
          <View style={{ flex: 1 }}>
            <Text style={styles.heroTitle}>Support</Text>
            <Text style={styles.heroSub}>We answer here, in the app.</Text>
          </View>
          {unreadCount > 0 ? <View style={styles.badge}><Text style={styles.badgeText}>{String(unreadCount)}</Text></View> : null}
        </View>
      </GoldBoxCard>
      {meta && !meta.enabled ? (
        <View style={styles.card}>
          <Text style={styles.cardBody}>New requests are paused right now. Please try again later, or use the contacts on the Help page.</Text>
        </View>
      ) : (
        <Pressable style={styles.primary} onPress={onNew} accessibilityRole="button" accessibilityLabel="New request">
          <Text style={styles.primaryText}>Ask for help</Text>
        </Pressable>
      )}
      <Text style={styles.sectionTitle}>My requests</Text>
      {err ? <Text style={styles.error}>{err}</Text> : null}
      {tickets === null ? (
        <ActivityIndicator color={colors.gold} style={{ marginTop: 20 }} />
      ) : tickets.length === 0 ? (
        <GoldBoxCard style={styles.empty}>
          <Text style={styles.emptyIcon}>💬</Text>
          <Text style={styles.emptyTitle}>You have no requests yet.</Text>
          <Text style={styles.cardBody}>Tap a request to read the answers.</Text>
        </GoldBoxCard>
      ) : (
        tickets.map((t) => (
          <Pressable key={t.id} onPress={() => onOpen(t.id)} accessibilityRole="button" accessibilityLabel={t.subject} style={styles.rowWrap}>
            <GoldBoxCard radius={14}>
              <View style={styles.row}>
                <View style={[styles.statusDot, { backgroundColor: t.status === 'solved' || t.status === 'closed' ? colors.success : t.status === 'pending' ? colors.gold : colors.warning }]} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.rowTitle}>{t.subject}</Text>
                  <Text style={styles.rowSub}>{t.ref + ' - ' + statusLabel(t.status) + ' - ' + fmtStamp(t.updated_at, cal, listLang === 'am')}</Text>
                </View>
                {t.unread ? <View style={styles.dot} accessibilityLabel="New reply" /> : null}
              </View>
            </GoldBoxCard>
          </Pressable>
        ))
      )}
      <Pressable style={styles.link} onPress={() => { setRefreshing(true); load().finally(() => setRefreshing(false)); }} accessibilityRole="button">
        <Text style={styles.linkText}>{refreshing ? 'Refreshing...' : 'Refresh'}</Text>
      </Pressable>
    </View>
  );
}

function NewView({ onBack, onSent }: { onBack: () => void; onSent: (id: number) => void }) {
  const session = useSession();
  const { lang } = useLanguage();
  const [category, setCategory] = useState('other');
  const [subject, setSubject] = useState('');
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  async function send() {
    const v = validateRequest(subject, body);
    if (v) { setErr(v); return; }
    setBusy(true); setErr('');
    try {
      const t = await supCreate({ app: appForRole(session?.role), category, subject: subject.trim(), body: body.trim(), lang });
      onSent(t.id);
    } catch (e) {
      setErr(e instanceof Error && e.message ? e.message : 'Could not send your request. Check your connection and try again.');
      setBusy(false);
    }
  }
  return (
    <View>
      <Pressable onPress={onBack} accessibilityRole="button"><Text style={styles.linkText}>Back</Text></Pressable>
      <Text style={styles.title}>Ask for help</Text>
      <Text style={styles.label}>What is it about?</Text>
      <View style={styles.chips}>
        {SUPPORT_CATEGORIES.map((c) => (
          <Pressable key={c.key} onPress={() => setCategory(c.key)} style={[styles.chip, category === c.key && styles.chipOn]} accessibilityRole="button" accessibilityState={{ selected: category === c.key }}>
            <Text style={[styles.chipText, category === c.key && styles.chipTextOn]}>{c.label}</Text>
          </Pressable>
        ))}
      </View>
      {category === 'safety' ? <Text style={styles.warn}>In an emergency use the SOS button, it alerts staff at once.</Text> : null}
      <Text style={styles.label}>Short title</Text>
      <TextInput style={styles.input} value={subject} onChangeText={setSubject} maxLength={160} placeholder="For example: My payout did not arrive" placeholderTextColor={colors.textMuted} />
      <Text style={styles.label}>What happened?</Text>
      <TextInput style={[styles.input, styles.multiline]} value={body} onChangeText={setBody} maxLength={4000} multiline placeholder="Tell us what happened, with the date and the trip or order number if you have it." placeholderTextColor={colors.textMuted} textAlignVertical="top" />
      {err ? <Text style={styles.error}>{err}</Text> : null}
      <Pressable style={[styles.primary, busy && { opacity: 0.6 }]} onPress={send} disabled={busy} accessibilityRole="button">
        <Text style={styles.primaryText}>{busy ? 'Sending...' : 'Send'}</Text>
      </Pressable>
    </View>
  );
}

function ThreadView({ id, onBack }: { id: number; onBack: () => void }) {
  const cal = useCalendar();
  const { lang: threadLang } = useLanguage();
  const [data, setData] = useState<{ ticket: SupTicket; messages: SupMessage[]; can_rate: boolean; can_reply: boolean } | null>(null);
  const [err, setErr] = useState('');
  const [reply, setReply] = useState('');
  const [busy, setBusy] = useState(false);
  const load = useCallback(async () => {
    try { setData(await supGet(id)); setErr(''); } catch (e) { setErr(e instanceof Error && e.message ? e.message : 'Could not load this request.'); }
  }, [id]);
  useEffect(() => { load(); }, [load]);
  async function sendReply() {
    const v = validateReply(reply);
    if (v) { setErr(v); return; }
    setBusy(true); setErr('');
    try { await supReply(id, reply.trim()); setReply(''); await load(); } catch (e) { setErr(e instanceof Error && e.message ? e.message : 'Could not send your message.'); }
    setBusy(false);
  }
  function solved() {
    Alert.alert('Mark as solved?', 'Only do this if your problem is solved. You can still write again later.', [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Yes, solved', onPress: async () => { try { await supClose(id); await load(); } catch (e) { setErr(e instanceof Error && e.message ? e.message : 'Could not save.'); } } },
    ]);
  }
  async function rate(n: number) {
    try { await supRate(id, n); await load(); } catch (e) { setErr(e instanceof Error && e.message ? e.message : 'Could not save your rating.'); }
  }
  if (!data) return (
    <View>
      <Pressable onPress={onBack} accessibilityRole="button"><Text style={styles.linkText}>Back</Text></Pressable>
      {err ? <Text style={styles.error}>{err}</Text> : <ActivityIndicator color={colors.gold} style={{ marginTop: 20 }} />}
    </View>
  );
  const t = data.ticket;
  return (
    <View>
      <Pressable onPress={onBack} accessibilityRole="button"><Text style={styles.linkText}>Back</Text></Pressable>
      <Text style={styles.title}>{t.subject}</Text>
      <Text style={styles.rowSub}>{t.ref + ' - ' + statusLabel(t.status)}</Text>
      <View style={{ marginTop: 12 }}>
        {data.messages.map((m, i) => (
          <View key={i} style={[styles.bubble, m.from === 'me' ? styles.mine : m.from === 'support' ? styles.theirs : styles.system]}>
            <Text style={styles.bubbleWho}>{m.from === 'me' ? 'You' : m.from === 'support' ? 'ETEL support' : 'Note'}</Text>
            <Text style={styles.bubbleText}>{m.body}</Text>
            <Text style={styles.bubbleTime}>{fmtStamp(m.at, cal, threadLang === 'am')}</Text>
          </View>
        ))}
      </View>
      {err ? <Text style={styles.error}>{err}</Text> : null}
      {data.can_reply ? (
        <View>
          <TextInput style={[styles.input, styles.multiline, { minHeight: 80 }]} value={reply} onChangeText={setReply} maxLength={4000} multiline placeholder="Write a message" placeholderTextColor={colors.textMuted} textAlignVertical="top" />
          <Pressable style={[styles.primary, busy && { opacity: 0.6 }]} onPress={sendReply} disabled={busy} accessibilityRole="button"><Text style={styles.primaryText}>{busy ? 'Sending...' : 'Send'}</Text></Pressable>
        </View>
      ) : (
        <Text style={styles.cardBody}>This request is closed. Please open a new one if you need more help.</Text>
      )}
      {canMarkSolved(t.status) ? (
        <Pressable style={styles.secondary} onPress={solved} accessibilityRole="button"><Text style={styles.secondaryText}>My problem is solved</Text></Pressable>
      ) : null}
      {data.can_rate ? (
        <View style={styles.card}>
          <Text style={styles.cardTitle}>How was our help?</Text>
          <View style={styles.stars}>
            {[1, 2, 3, 4, 5].map((n) => (
              <Pressable key={n} onPress={() => rate(n)} accessibilityRole="button" accessibilityLabel={n + (n === 1 ? ' star' : ' stars')}><Text style={styles.star}>{'★'}</Text></Pressable>
            ))}
          </View>
        </View>
      ) : t.rating ? <Text style={styles.rowSub}>{'Your rating: ' + t.rating + ' of 5'}</Text> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.bg },
  content: { paddingTop: 56, paddingHorizontal: 20, paddingBottom: 60 },
  title: { ...typography.h1, color: colors.text, marginBottom: 14, marginTop: 8 },
  sectionTitle: { color: colors.gold, fontWeight: '700', marginTop: 22, marginBottom: 8 },
  card: { backgroundColor: colors.surface2, borderColor: colors.border, borderWidth: 1, borderRadius: 14, padding: 16, marginBottom: 12 },
  cardTitle: { color: colors.gold, fontWeight: '700', marginBottom: 6 },
  cardBody: { color: colors.textSecondary, lineHeight: 20 },
  primary: { backgroundColor: colors.gold, borderRadius: 14, paddingVertical: 14, alignItems: 'center', marginTop: 10 },
  primaryText: { color: '#1a1200', fontWeight: '700', fontSize: 16 },
  secondary: { borderColor: colors.border, borderWidth: 1, borderRadius: 14, paddingVertical: 12, alignItems: 'center', marginTop: 14 },
  secondaryText: { color: colors.text, fontWeight: '600' },
  rowWrap: { marginBottom: 10 },
  row: { flexDirection: 'row', alignItems: 'center', padding: 14 },
  statusDot: { width: 10, height: 10, borderRadius: 5, marginRight: 12 },
  hero: { marginTop: 8, marginBottom: 6 },
  heroRow: { flexDirection: 'row', alignItems: 'center', padding: 16, gap: 12 },
  heroIcon: { width: 46, height: 46, borderRadius: 23, backgroundColor: colors.gold, alignItems: 'center', justifyContent: 'center' },
  heroIconText: { fontSize: 22 },
  heroTitle: { ...typography.h1, color: colors.text },
  heroSub: { color: colors.textSecondary, marginTop: 2 },
  badge: { minWidth: 26, height: 26, borderRadius: 13, backgroundColor: colors.gold, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 7 },
  badgeText: { color: '#1a1200', fontWeight: '800' },
  empty: { alignItems: 'center', padding: 24 },
  emptyIcon: { fontSize: 38, marginBottom: 8 },
  emptyTitle: { color: colors.text, fontWeight: '700', marginBottom: 4 },
  rowTitle: { color: colors.text, fontWeight: '600' },
  rowSub: { color: colors.textMuted, fontSize: 12, marginTop: 2 },
  dot: { width: 10, height: 10, borderRadius: 5, backgroundColor: colors.gold, marginLeft: 10 },
  link: { alignItems: 'center', paddingVertical: 14 },
  linkText: { color: colors.gold, fontWeight: '600' },
  label: { color: colors.textSecondary, marginTop: 14, marginBottom: 6, fontWeight: '600' },
  input: { backgroundColor: colors.surface2, borderColor: colors.border, borderWidth: 1, borderRadius: 12, color: colors.text, paddingHorizontal: 12, paddingVertical: 10, fontSize: 15 },
  multiline: { minHeight: 130 },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  chip: { borderColor: colors.border, borderWidth: 1, borderRadius: 99, paddingVertical: 7, paddingHorizontal: 14 },
  chipOn: { backgroundColor: colors.gold },
  chipText: { color: colors.textSecondary },
  chipTextOn: { color: '#1a1200', fontWeight: '700' },
  warn: { color: colors.warning, marginTop: 8 },
  error: { color: colors.danger, marginTop: 10 },
  bubble: { borderRadius: 14, padding: 12, marginBottom: 8, borderWidth: 1 },
  mine: { backgroundColor: colors.surface3, borderColor: colors.border, marginLeft: 30 },
  theirs: { backgroundColor: colors.surface2, borderColor: colors.gold, marginRight: 30 },
  system: { backgroundColor: 'transparent', borderColor: colors.border },
  bubbleWho: { color: colors.gold, fontSize: 12, fontWeight: '700', marginBottom: 2 },
  bubbleText: { color: colors.text, lineHeight: 20 },
  bubbleTime: { color: colors.textMuted, fontSize: 11, marginTop: 4 },
  stars: { flexDirection: 'row', gap: 10 },
  star: { color: colors.gold, fontSize: 34 },
});

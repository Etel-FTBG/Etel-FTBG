import React, { useState } from 'react';
import { ActivityIndicator, Linking, Pressable, StyleSheet, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Text, TextInput, translate } from '../i18n';
import { useTheme } from '../theme/ThemeContext';
import { ApiError } from '../api/client';
import { hubSignIn } from '../hub/session';
import { success } from '../utils/haptics';

const RESET_PASSWORD_URL = 'https://ethelmarket.com/EtelId/reset-password/';
const JOIN_URL = 'https://ethelmarket.com/EtelId/';

// The one place to sign in. The same EtelID opens every ETEL app, each by the account's own role.
export default function SignInCard() {
  const { p } = useTheme();
  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [needCode, setNeedCode] = useState(false);
  const [show, setShow] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  async function submit() {
    if (busy) return;
    if (!identifier.trim() || !password) {
      setError('Enter your EtelID and password.');
      return;
    }
    setError('');
    setBusy(true);
    try {
      await hubSignIn(identifier.trim(), password, needCode ? code.trim() : undefined);
      success();
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Connection problem. Please try again.';
      if (e instanceof ApiError && /authenticator|2fa|verification code/i.test(msg)) setNeedCode(true);
      setError(msg);
    } finally {
      setBusy(false);
    }
  }

  const field = [styles.input, { color: p.text, backgroundColor: p.surfaceAlt, borderColor: p.border }];

  return (
    <View style={[styles.card, { backgroundColor: p.surface, borderColor: p.gold }]}>
      <View style={styles.head}>
        <Ionicons name="shield-checkmark" size={20} color={p.gold} />
        <Text style={[styles.title, { color: p.text }]}>Sign in with EtelID</Text>
      </View>
      <Text style={[styles.sub, { color: p.textSecondary }]}>One sign-in opens every ETEL app that belongs to your account.</Text>
      <TextInput
        style={field}
        value={identifier}
        onChangeText={setIdentifier}
        placeholder={translate('EtelID, FIN, card number or phone')}
        placeholderTextColor={p.textMuted}
        autoCapitalize="none"
        autoCorrect={false}
        returnKeyType="next"
      />
      <View style={styles.pwRow}>
        <TextInput
          style={[...field, styles.pw]}
          value={password}
          onChangeText={setPassword}
          placeholder={translate('Password')}
          placeholderTextColor={p.textMuted}
          secureTextEntry={!show}
          autoCapitalize="none"
          autoCorrect={false}
          onSubmitEditing={submit}
        />
        <Pressable onPress={() => setShow((v) => !v)} style={styles.eye} accessibilityLabel="Show password">
          <Ionicons name={show ? 'eye-off' : 'eye'} size={20} color={p.textMuted} />
        </Pressable>
      </View>
      {needCode ? (
        <TextInput
          style={field}
          value={code}
          onChangeText={setCode}
          placeholder={translate('Authenticator code')}
          placeholderTextColor={p.textMuted}
          keyboardType="number-pad"
          maxLength={8}
          onSubmitEditing={submit}
        />
      ) : null}
      {error ? <Text style={styles.error}>{error}</Text> : null}
      <Pressable
        onPress={submit}
        disabled={busy}
        style={[styles.btn, { backgroundColor: p.gold, opacity: busy ? 0.7 : 1 }]}
        accessibilityRole="button"
      >
        {busy ? <ActivityIndicator color={p.mode === 'dark' ? '#0a0703' : '#FFFFFF'} /> : (
          <Text style={[styles.btnText, { color: p.mode === 'dark' ? '#0a0703' : '#FFFFFF' }]}>Sign in</Text>
        )}
      </Pressable>
      <View style={styles.links}>
        <Pressable onPress={() => Linking.openURL(RESET_PASSWORD_URL).catch(() => {})}>
          <Text style={[styles.link, { color: p.textSecondary }]}>Forgot password?</Text>
        </Pressable>
        <Pressable onPress={() => Linking.openURL(JOIN_URL).catch(() => {})}>
          <Text style={[styles.link, { color: p.gold }]}>Create an EtelID</Text>
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  card: { padding: 16, borderRadius: 20, borderWidth: 1, marginBottom: 8 },
  head: { flexDirection: 'row', alignItems: 'center' },
  title: { fontSize: 17, marginLeft: 8, fontWeight: '700' },
  sub: { marginTop: 6, marginBottom: 12, lineHeight: 19, fontSize: 13 },
  input: { borderWidth: 1, borderRadius: 14, paddingHorizontal: 14, paddingVertical: 12, fontSize: 15, marginBottom: 10 },
  pwRow: { justifyContent: 'center' },
  pw: { paddingRight: 44 },
  eye: { position: 'absolute', right: 12, top: 0, height: 46, justifyContent: 'center' },
  error: { color: '#E5484D', fontSize: 13, marginBottom: 8 },
  btn: { borderRadius: 26, paddingVertical: 14, alignItems: 'center', marginTop: 2 },
  btnText: { fontWeight: '700', fontSize: 15 },
  links: { flexDirection: 'row', justifyContent: 'space-between', marginTop: 14 },
  link: { fontSize: 13, fontWeight: '600' },
});

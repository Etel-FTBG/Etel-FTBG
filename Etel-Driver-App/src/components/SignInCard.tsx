import React, { useRef, useState } from 'react';
import { ActivityIndicator, Linking, Pressable, StyleSheet, TextInput as RNTextInput, View } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { Ionicons } from '@expo/vector-icons';
import { Text, TextInput, translate } from '../i18n';
import { typography } from '../theme/colors';
import { useTheme } from '../theme/ThemeContext';
import { ApiError } from '../api/client';
import { hubSignIn } from '../hub/session';
import { success } from '../utils/haptics';

const RESET_PASSWORD_URL = 'https://ethelmarket.com/EtelId/reset-password/';
const JOIN_URL = 'https://ethelmarket.com/EtelId/';
// Dark text on the gold button in both themes: white on gold was hard to read.
const ON_GOLD = '#1A1206';
const GOLD_BUTTON: [string, string, string] = ['#F2CC6B', '#D4AF37', '#B8860B'];
const GOLD_LINE: [string, string, string] = ['#B8860B', '#F2CC6B', '#B8860B'];

// The one place to sign in. The same EtelID opens every ETEL app, each by the account's own role.
// The first box takes a phone number, an Etel Code (E1616), an EtelID, a FIN or a card number: the server works out which.
export default function SignInCard() {
  const { p } = useTheme();
  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [needCode, setNeedCode] = useState(false);
  const [show, setShow] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [focus, setFocus] = useState<'' | 'id' | 'pw' | 'code'>('');
  const pwRef = useRef<RNTextInput>(null);

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

  const dark = p.mode === 'dark';
  const placeholder = dark ? 'rgba(201,188,160,0.62)' : 'rgba(90,74,34,0.68)';
  const box = (name: 'id' | 'pw' | 'code') => [
    styles.box,
    { backgroundColor: dark ? '#0d0a05' : '#FFFDF6', borderColor: focus === name ? p.gold : p.border },
    focus === name && styles.boxFocus,
  ];
  const icon = (name: 'id' | 'pw' | 'code') => (focus === name ? p.gold : p.textSecondary);

  return (
    <View style={[styles.card, { backgroundColor: p.surface, borderColor: p.border }, !dark && styles.cardShadow]}>
      <LinearGradient colors={GOLD_LINE} start={{ x: 0, y: 0 }} end={{ x: 1, y: 0 }} style={styles.line} />
      <View style={styles.body}>
        <View style={styles.head}>
          <View style={[styles.seal, { backgroundColor: p.glow, borderColor: p.border }]}>
            <Ionicons name="shield-checkmark" size={20} color={p.gold} />
          </View>
          <View style={styles.headText}>
            <Text style={[styles.title, { color: p.text }]}>Sign in with EtelID</Text>
            <Text style={[styles.sub, { color: p.textSecondary }]}>One sign-in opens every ETEL app that belongs to your account.</Text>
          </View>
        </View>

        <Text style={[styles.label, { color: p.goldStrong }]}>Phone, Etel Code or EtelID</Text>
        <View style={box('id')}>
          <Ionicons name="person-circle-outline" size={21} color={icon('id')} style={styles.lead} />
          <TextInput
            style={[styles.input, { color: p.text }]}
            value={identifier}
            onChangeText={setIdentifier}
            placeholder={translate('Phone, Etel Code, EtelID, FIN or card number')}
            placeholderTextColor={placeholder}
            autoCapitalize="none"
            autoCorrect={false}
            returnKeyType="next"
            onFocus={() => setFocus('id')}
            onBlur={() => setFocus('')}
            onSubmitEditing={() => pwRef.current?.focus()}
          />
        </View>

        <Text style={[styles.label, { color: p.goldStrong }]}>Password</Text>
        <View style={box('pw')}>
          <Ionicons name="lock-closed-outline" size={19} color={icon('pw')} style={styles.lead} />
          <TextInput
            ref={pwRef}
            style={[styles.input, { color: p.text }]}
            value={password}
            onChangeText={setPassword}
            placeholder={translate('Password')}
            placeholderTextColor={placeholder}
            secureTextEntry={!show}
            autoCapitalize="none"
            autoCorrect={false}
            onFocus={() => setFocus('pw')}
            onBlur={() => setFocus('')}
            onSubmitEditing={submit}
          />
          <Pressable onPress={() => setShow((v) => !v)} hitSlop={10} style={styles.eye} accessibilityRole="button" accessibilityLabel="Show password">
            <Ionicons name={show ? 'eye-off-outline' : 'eye-outline'} size={21} color={p.textSecondary} />
          </Pressable>
        </View>

        {needCode ? (
          <>
            <Text style={[styles.label, { color: p.goldStrong }]}>Authenticator code</Text>
            <View style={box('code')}>
              <Ionicons name="keypad-outline" size={19} color={icon('code')} style={styles.lead} />
              <TextInput
                style={[styles.input, { color: p.text }]}
                value={code}
                onChangeText={setCode}
                placeholder={translate('Authenticator code')}
                placeholderTextColor={placeholder}
                keyboardType="number-pad"
                maxLength={8}
                onFocus={() => setFocus('code')}
                onBlur={() => setFocus('')}
                onSubmitEditing={submit}
              />
            </View>
          </>
        ) : null}

        {error ? (
          <View style={[styles.errorBox, { borderColor: p.danger }]}>
            <Ionicons name="alert-circle" size={17} color={p.danger} />
            <Text style={[styles.error, { color: p.danger }]}>{error}</Text>
          </View>
        ) : null}

        <Pressable
          onPress={submit}
          disabled={busy}
          accessibilityRole="button"
          style={({ pressed }) => [styles.btnWrap, { opacity: busy ? 0.75 : 1 }, pressed && { transform: [{ scale: 0.985 }] }]}
        >
          <LinearGradient colors={GOLD_BUTTON} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.btn}>
            {busy ? (
              <ActivityIndicator color={ON_GOLD} />
            ) : (
              <>
                <Text style={styles.btnText}>Sign in</Text>
                <Ionicons name="arrow-forward" size={19} color={ON_GOLD} style={styles.btnIcon} />
              </>
            )}
          </LinearGradient>
        </Pressable>

        <View style={styles.links}>
          <Pressable hitSlop={8} onPress={() => Linking.openURL(RESET_PASSWORD_URL).catch(() => {})}>
            <Text style={[styles.link, { color: p.textSecondary }]}>Forgot password?</Text>
          </Pressable>
          <Pressable hitSlop={8} onPress={() => Linking.openURL(JOIN_URL).catch(() => {})}>
            <Text style={[styles.link, { color: p.goldStrong }]}>Create an EtelID</Text>
          </Pressable>
        </View>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  card: { borderRadius: 24, borderWidth: 1, marginBottom: 8, overflow: 'hidden' },
  cardShadow: { shadowColor: '#8A6508', shadowOpacity: 0.2, shadowRadius: 18, shadowOffset: { width: 0, height: 8 }, elevation: 6 },
  line: { height: 4 },
  body: { padding: 20 },
  head: { flexDirection: 'row', alignItems: 'flex-start', marginBottom: 18 },
  seal: { width: 44, height: 44, borderRadius: 22, borderWidth: 1, alignItems: 'center', justifyContent: 'center' },
  headText: { flex: 1, marginLeft: 12 },
  title: { fontSize: 20, fontFamily: typography.h2.fontFamily },
  sub: { marginTop: 4, lineHeight: 19, fontSize: 13 },
  label: { fontSize: 11, letterSpacing: 1.4, textTransform: 'uppercase', fontWeight: '700', marginBottom: 6 },
  box: { flexDirection: 'row', alignItems: 'center', borderWidth: 1, borderRadius: 16, minHeight: 54, paddingHorizontal: 14, marginBottom: 14 },
  boxFocus: { borderWidth: 2, paddingHorizontal: 13 },
  lead: { marginRight: 10 },
  input: { flex: 1, fontSize: 16, paddingVertical: 12 },
  eye: { marginLeft: 8, height: 44, justifyContent: 'center' },
  errorBox: { flexDirection: 'row', alignItems: 'center', borderWidth: 1, borderRadius: 12, paddingVertical: 9, paddingHorizontal: 11, marginBottom: 14 },
  error: { fontSize: 13, marginLeft: 8, flex: 1, lineHeight: 18 },
  btnWrap: { borderRadius: 28, shadowColor: '#B8860B', shadowOpacity: 0.45, shadowRadius: 12, shadowOffset: { width: 0, height: 6 }, elevation: 5 },
  btn: { borderRadius: 28, minHeight: 56, flexDirection: 'row', alignItems: 'center', justifyContent: 'center' },
  btnText: { color: ON_GOLD, fontSize: 16, letterSpacing: 0.6, fontFamily: typography.h2.fontFamily },
  btnIcon: { marginLeft: 8 },
  links: { flexDirection: 'row', justifyContent: 'space-between', marginTop: 18 },
  link: { fontSize: 13, fontWeight: '700' },
});

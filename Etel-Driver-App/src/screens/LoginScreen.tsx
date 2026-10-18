import React, { useState } from 'react';
import { View, Pressable, StyleSheet, ActivityIndicator, Image, KeyboardAvoidingView, Platform, ScrollView, Linking } from 'react-native';
import { Text, TextInput, useLanguage } from '../i18n';
import { LinearGradient } from 'expo-linear-gradient';
import { Ionicons } from '@expo/vector-icons';
import { useAuth } from '../context/AuthContext';
import { ApiError } from '../api/client';
import { colors, typography } from '../theme/colors';

// Real links already used by the web login page (login.html) — reused as-is
// rather than inventing new endpoints/flows.
const RESET_PASSWORD_URL = 'https://ethelmarket.com/EtelId/reset-password/';
const APPLY_URL = 'https://ethelmarket.com/EtelId/';

export default function LoginScreen() {
  const { login } = useAuth();
  const { lang, toggle: toggleLang } = useLanguage();
  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit() {
    if (submitting) return; // guards against duplicate taps
    if (!identifier || !password) {
      setError('Enter your EtelID and password.');
      return;
    }
    setError(null);
    setSubmitting(true);
    try {
      await login(identifier.trim(), password);
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : 'Connection problem. Please try again.';
      setError(msg);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <LinearGradient
      colors={['#5B430D', '#A77A16', '#F0C75E', '#B88620', '#5A410C']}
      start={{ x: 0, y: 0 }}
      end={{ x: 1, y: 1 }}
      style={styles.background}
    >
      <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView
          contentContainerStyle={styles.scrollContent}
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}
        >
          <View style={styles.card}>
            <Image source={require('../../assets/splash-logo.png')} style={styles.logo} resizeMode="contain" />
            <Text style={styles.brandTitle}>ETEL DRIVER</Text>
            <Text style={styles.welcome}>Welcome back, Driver</Text>
            <Text style={styles.tagline}>Sign in to continue.</Text>

            <View style={styles.inputRow}>
              <Ionicons name="call-outline" size={18} color={colors.textMuted} style={styles.inputIcon} />
              <TextInput
                style={styles.input}
                placeholder="Phone number"
                placeholderTextColor={colors.textMuted}
                autoCapitalize="none"
                autoCorrect={false}
                autoComplete="off"
                textContentType="none"
                importantForAutofill="no"
                value={identifier}
                onChangeText={setIdentifier}
              />
            </View>

            <View style={styles.inputRow}>
              <Ionicons name="lock-closed-outline" size={18} color={colors.textMuted} style={styles.inputIcon} />
              <TextInput
                style={[styles.input, { flex: 1 }]}
                placeholder="Password"
                placeholderTextColor={colors.textMuted}
                secureTextEntry={!showPassword}
                autoCapitalize="none"
                autoCorrect={false}
                autoComplete="off"
                textContentType="none"
                importantForAutofill="no"
                value={password}
                onChangeText={setPassword}
              />
              <Pressable onPress={() => setShowPassword((s) => !s)} hitSlop={10}>
                <Ionicons
                  name={showPassword ? 'eye-off-outline' : 'eye-outline'}
                  size={19}
                  color={colors.textMuted}
                />
              </Pressable>
            </View>

            <Pressable
              style={styles.forgotLink}
              onPress={() => Linking.openURL(RESET_PASSWORD_URL).catch(() => {})}
            >
              <Text style={styles.forgotLinkText}>Forgot password?</Text>
            </Pressable>
            <Pressable style={[styles.forgotLink, { alignSelf: 'center' }]} onPress={toggleLang} accessibilityRole="button" accessibilityLabel="Language">
              <Text style={styles.forgotLinkText}>{lang === 'am' ? 'English' : 'አማርኛ'}</Text>
            </Pressable>

            {error ? (
              <View style={styles.errorCard}>
                <Text style={styles.errorTitle}>Unable to sign in</Text>
                <Text style={styles.errorBody}>{error}</Text>
              </View>
            ) : null}

            <Pressable onPress={handleSubmit} disabled={submitting}>
              <LinearGradient
                colors={[colors.goldDark, colors.gold, colors.goldLight, colors.gold, colors.goldDark]}
                start={{ x: 0, y: 0 }}
                end={{ x: 1, y: 0 }}
                style={[styles.button, submitting && styles.buttonDisabled]}
              >
                {submitting ? (
                  <>
                    <ActivityIndicator color={colors.bg} style={{ marginRight: 8 }} />
                    <Text style={styles.buttonText}>Signing in…</Text>
                  </>
                ) : (
                  <>
                    <Text style={styles.buttonText}>SIGN IN</Text>
                    <Ionicons name="arrow-forward" size={18} color={colors.bg} style={{ marginLeft: 8 }} />
                  </>
                )}
              </LinearGradient>
            </Pressable>

            <View style={styles.divider}>
              <View style={styles.dividerLine} />
            </View>
            <Text style={styles.applyPrompt}>Don't have a driver account?</Text>
            <Pressable
              style={styles.applyLinkRow}
              onPress={() => Linking.openURL(APPLY_URL).catch(() => {})}
            >
              <Text style={styles.applyLink}>Apply to drive</Text>
              <Ionicons name="arrow-forward" size={14} color={colors.gold} style={{ marginLeft: 4 }} />
            </Pressable>

            <View style={styles.secureRow}>
              <View style={styles.dividerLineShort} />
              <Ionicons name="shield-checkmark-outline" size={13} color={colors.textMuted} />
              <Text style={styles.secureText}>Secure ETEL Driver</Text>
              <View style={styles.dividerLineShort} />
            </View>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </LinearGradient>
  );
}

const styles = StyleSheet.create({
  background: { flex: 1 },
  flex: { flex: 1 },
  scrollContent: {
    flexGrow: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: 24,
  },
  card: {
    width: '100%',
    maxWidth: 420,
    backgroundColor: 'rgba(8,10,15,0.88)',
    borderColor: 'rgba(255,215,100,0.30)',
    borderWidth: 1,
    borderRadius: 24,
    padding: 28,
    alignItems: 'center',
    shadowColor: '#000',
    shadowOpacity: 0.35,
    shadowRadius: 30,
    shadowOffset: { width: 0, height: 20 },
    elevation: 20,
  },
  logo: { width: 84, height: 84, marginBottom: 10 },
  brandTitle: { ...typography.h1, fontSize: 24, color: colors.gold, letterSpacing: 1.5 },
  welcome: { color: colors.text, fontSize: 18, fontWeight: '700', marginTop: 14 },
  tagline: { color: colors.textMuted, fontSize: 13, marginTop: 4, marginBottom: 26 },
  inputRow: {
    width: '100%',
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: 'rgba(255,255,255,0.03)',
    borderColor: 'rgba(212,175,55,0.30)',
    borderWidth: 1,
    borderRadius: 14,
    paddingHorizontal: 14,
    marginBottom: 14,
    minHeight: 52,
  },
  inputIcon: { marginRight: 10 },
  input: { flex: 1, color: colors.text, fontSize: 15, paddingVertical: 14 },
  forgotLink: { alignSelf: 'flex-end', marginBottom: 20, minHeight: 24, justifyContent: 'center' },
  forgotLinkText: { color: colors.gold, fontSize: 13 },
  errorCard: {
    width: '100%',
    backgroundColor: 'rgba(239,68,68,0.10)',
    borderColor: 'rgba(239,68,68,0.35)',
    borderWidth: 1,
    borderRadius: 12,
    padding: 12,
    marginBottom: 16,
  },
  errorTitle: { color: colors.danger, fontWeight: '700', fontSize: 13, marginBottom: 2 },
  errorBody: { color: colors.textSecondary, fontSize: 13 },
  button: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 14,
    paddingVertical: 16,
    minHeight: 52,
    width: '100%',
  },
  buttonDisabled: { opacity: 0.75 },
  buttonText: { color: colors.bg, fontSize: 16, fontWeight: '800', letterSpacing: 0.5 },
  divider: { width: '100%', marginTop: 22, marginBottom: 14 },
  dividerLine: { height: StyleSheet.hairlineWidth, backgroundColor: colors.border, width: '100%' },
  applyPrompt: { color: colors.textMuted, fontSize: 13, textAlign: 'center' },
  applyLinkRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', marginTop: 6 },
  applyLink: { color: colors.gold, fontWeight: '700', fontSize: 14 },
  secureRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    marginTop: 24,
  },
  dividerLineShort: { width: 28, height: StyleSheet.hairlineWidth, backgroundColor: colors.border },
  secureText: { color: colors.textMuted, fontSize: 11 },
});

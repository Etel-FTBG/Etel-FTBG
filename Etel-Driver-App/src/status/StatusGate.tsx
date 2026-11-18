import React from 'react';
import { Linking, Pressable, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { Text, useLanguage } from '../i18n';
import { colors, typography } from '../theme/colors';
import { useSession } from '../hub/session';
import { useAppStatus } from './appStatus';
import { decide } from './statusLogic';

// Two full-screen pages that sit in front of the whole app, set by the owner in Admin > ETEL app > Maintenance and updates:
//  - maintenance: "we are back soon" (admin accounts still get in, so the owner can test)
//  - update required: this app version is older than the minimum, with a button to the update address
export default function StatusGate({ children }: { children: React.ReactNode }) {
  const insets = useSafeAreaInsets();
  const session = useSession();
  const { lang } = useLanguage();
  const [known, setKnown] = React.useState<boolean>(false);
  const status = useAppStatus(known);
  const blocker = decide(status, session?.role, lang);
  React.useEffect(() => setKnown(!!blocker), [blocker]);

  if (!blocker) return <>{children}</>;
  const update = blocker.kind === 'update';
  // The app stays mounted underneath (nothing is reset: a driver who is online stays online) and this page covers it.
  return (
    <View style={styles.wrap}>
    {children}
    <View style={[styles.root, StyleSheet.absoluteFill, { paddingTop: insets.top + 24, paddingBottom: insets.bottom + 24 }]} accessibilityViewIsModal>
      <View style={styles.center}>
        <View style={styles.icon}>
          <Ionicons name={update ? 'cloud-download' : 'construct'} size={40} color={colors.gold} />
        </View>
        <Text style={styles.title}>{update ? 'Please update ETEL' : 'Back soon'}</Text>
        <Text style={styles.body}>{blocker.text}</Text>
        {update && blocker.min ? <Text style={styles.small}>{'Needed version: ' + blocker.min + ' or newer'}</Text> : null}
        {update && blocker.url ? (
          <Pressable style={styles.button} onPress={() => Linking.openURL(blocker.url).catch(() => {})} accessibilityRole="button" accessibilityLabel="Update ETEL">
            <Text style={styles.buttonText}>Update now</Text>
          </Pressable>
        ) : null}
        {!update ? <Text style={styles.small}>This page checks again by itself.</Text> : null}
      </View>
    </View>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { flex: 1 },
  root: { flex: 1, backgroundColor: colors.bg, paddingHorizontal: 24 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 14 },
  icon: { width: 84, height: 84, borderRadius: 42, borderWidth: 2, borderColor: colors.gold, backgroundColor: colors.surface3, alignItems: 'center', justifyContent: 'center' },
  title: { ...typography.h2, color: colors.text, textAlign: 'center' },
  body: { color: colors.textSecondary, textAlign: 'center', fontSize: 15, lineHeight: 22 },
  small: { color: colors.textMuted, textAlign: 'center', fontSize: 12 },
  button: { marginTop: 8, backgroundColor: colors.gold, borderRadius: 14, paddingVertical: 14, paddingHorizontal: 32 },
  buttonText: { color: '#1a1200', fontWeight: '700', fontSize: 16 },
});

import React, { useEffect, useRef, useState } from 'react';
import { Animated, AppState, BackHandler, Easing, Pressable, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { Text } from '../i18n';
import { colors, typography } from '../theme/colors';
import { RootStackParamList } from '../navigation/types';
import { HUB_APPS } from '../hub/apps';
import { activateApp, deactivateApp, hostGoBack, hostReload, useWebHost } from '../hub/webHost';
import { useTheme } from '../theme/ThemeContext';
import { setWebDriverForeground } from '../location/backgroundLocationTask';

type Props = NativeStackScreenProps<RootStackParamList, 'WebApp'>;

export default function WebAppScreen({ route, navigation }: Props) {
  const app = HUB_APPS.find((a) => a.key === route.params.appKey) || HUB_APPS[0];
  const { p } = useTheme();
  const insets = useSafeAreaInsets();
  const host = useWebHost();
  const page = host.pages[app.key] || { loading: true, failed: false, canGoBack: false };
  // The app draws edge to edge, so the bar must clear the status bar and the page must stop above the system navigation bar.
  const barTop = Math.max(insets.top, 24) + 4;
  const [barBottom, setBarBottom] = useState(barTop + 49);
  // The Reload button spins until the page has finished loading again.
  const [reloading, setReloading] = useState(false);
  const spin = useRef(new Animated.Value(0)).current;
  useEffect(() => {
    if (!reloading) return;
    spin.setValue(0);
    const loop = Animated.loop(Animated.timing(spin, { toValue: 1, duration: 800, easing: Easing.linear, useNativeDriver: true }));
    loop.start();
    return () => loop.stop();
  }, [reloading, spin]);
  useEffect(() => {
    if (!page.loading) setReloading(false);
  }, [page.loading]);
  const reload = () => {
    setReloading(true);
    hostReload(app.key);
    setTimeout(() => setReloading(false), 8000);
  };

  // The page itself lives in WebHost; this screen shows it below the bar and hands it back when you leave.
  useEffect(() => {
    activateApp(app.key, barBottom);
    return () => deactivateApp(app.key);
  }, [app.key, barBottom]);

  // Android back button: go back inside the web app first, then leave to the hub.
  useEffect(() => {
    const sub = BackHandler.addEventListener('hardwareBackPress', () => {
      if (page.canGoBack) {
        hostGoBack(app.key);
        return true;
      }
      return false;
    });
    return () => sub.remove();
  }, [page.canGoBack, app.key]);

  // While the web Driver app is on screen it reports the position itself; the native location task stays quiet (see backgroundLocationTask.ts).
  useEffect(() => {
    if (app.key !== 'driver') return;
    setWebDriverForeground(AppState.currentState === 'active');
    const sub = AppState.addEventListener('change', (st) => setWebDriverForeground(st === 'active'));
    return () => {
      sub.remove();
      setWebDriverForeground(false);
    };
  }, [app.key]);

  return (
    <View style={[styles.root, { backgroundColor: p.bg, paddingBottom: insets.bottom }]}>
      <View
        onLayout={(e) => setBarBottom(Math.round(e.nativeEvent.layout.y + e.nativeEvent.layout.height))}
        style={[styles.bar, { backgroundColor: p.surface, borderBottomColor: p.border, paddingTop: barTop }]}>
        <Pressable onPress={() => navigation.goBack()} style={styles.barBtn} accessibilityLabel="Back to ETEL">
          <Ionicons name="grid" size={20} color={p.gold} />
        </Pressable>
        <Text style={[styles.title, { color: p.text }]}>{app.title}</Text>
        <Pressable onPress={reload} style={styles.barBtn} accessibilityLabel="Reload">
          <Animated.View style={{ transform: [{ rotate: spin.interpolate({ inputRange: [0, 1], outputRange: ['0deg', '360deg'] }) }] }}>
            <Ionicons name="refresh" size={20} color={p.gold} />
          </Animated.View>
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: colors.bg },
  bar: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingTop: 36, // replaced at runtime by the real status bar height (see barTop)
    paddingBottom: 8,
    paddingHorizontal: 10,
    backgroundColor: colors.surface,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  barBtn: { width: 40, height: 40, alignItems: 'center', justifyContent: 'center' },
  title: { color: colors.text, fontSize: 16, fontFamily: typography.h2.fontFamily },
});

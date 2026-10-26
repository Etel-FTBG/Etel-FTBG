import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Animated, AppState, BackHandler, Easing, Linking, Pressable, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { WebView as RNWebView, WebViewMessageEvent } from 'react-native-webview';
import { Ionicons } from '@expo/vector-icons';
import { NativeStackScreenProps } from '@react-navigation/native-stack';
import { Text } from '../i18n';
import { colors, typography } from '../theme/colors';
import { RootStackParamList } from '../navigation/types';
import { HUB_APPS, ALLOWED_ORIGINS } from '../hub/apps';
import { BRIDGE_JS, FIT_JS, prefsJs } from '../hub/webBridge';
import { getSession, sessionJs } from '../hub/session';
import { useTheme } from '../theme/ThemeContext';
import BrandLoader from '../components/BrandLoader';
import { useLanguage } from '../i18n';
import { setToken } from '../api/client';
import { setOnlineState } from '../online/onlineState';
import { markCompanyOnline } from '../hub/companies';
import { setWebDriverForeground, startDriverLocationTracking, stopDriverLocationTracking } from '../location/backgroundLocationTask';
import { registerForPushNotifications } from '../notifications/registerPush';

// react-native-webview's class typing does not line up with React 19's JSX types; the runtime component is fine.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const WebView = RNWebView as unknown as React.ComponentType<any>;
type WebHandle = { goBack(): void; reload(): void };

type Props = NativeStackScreenProps<RootStackParamList, 'WebApp'>;

export default function WebAppScreen({ route, navigation }: Props) {
  const app = HUB_APPS.find((a) => a.key === route.params.appKey) || HUB_APPS[0];
  const web = useRef<WebHandle>(null);
  const { p } = useTheme();
  const { lang } = useLanguage();
  const insets = useSafeAreaInsets();
  // The app draws edge to edge, so the bar must clear the status bar and the page must stop above the system navigation bar.
  const barTop = Math.max(insets.top, 24) + 4;
  const [canGoBack, setCanGoBack] = useState(false);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
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
  const reload = () => {
    setReloading(true);
    setFailed(false);
    web.current?.reload();
    setTimeout(() => setReloading(false), 8000);
  };

  // Android back button: go back inside the web app first, then leave to the hub.
  useEffect(() => {
    const sub = BackHandler.addEventListener('hardwareBackPress', () => {
      if (canGoBack && web.current) {
        web.current.goBack();
        return true;
      }
      return false;
    });
    return () => sub.remove();
  }, [canGoBack]);

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

  const onMessage = useCallback(async (e: WebViewMessageEvent) => {
    let msg: { type?: string; token?: string } = {};
    try {
      msg = JSON.parse(e.nativeEvent.data);
    } catch {
      return;
    }
    try {
      if (msg.type === 'token' && msg.token) {
        // Same login as the web app: lets the background location and push notifications run for this user.
        await setToken(msg.token);
        registerForPushNotifications().catch(() => {});
      } else if (msg.type === 'online') {
        await startDriverLocationTracking();
        markCompanyOnline('ride', true);
        setOnlineState(true);
      } else if (msg.type === 'offline') {
        await stopDriverLocationTracking();
        markCompanyOnline('ride', false);
        setOnlineState(false);
      }
    } catch {
      // Permission refused or no real build: the web app still works while it is open.
    }
  }, []);

  return (
    <View style={[styles.root, { backgroundColor: p.bg, paddingBottom: insets.bottom }]}>
      <View style={[styles.bar, { backgroundColor: p.surface, borderBottomColor: p.border, paddingTop: barTop }]}>
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
      {failed ? (
        <View style={styles.center}>
          <Text style={[styles.msg, { color: p.textSecondary }]}>Could not load. Check your connection.</Text>
          <Pressable
            style={styles.retry}
            onPress={() => {
              setFailed(false);
              setLoading(true);
              web.current?.reload();
            }}
          >
            <Text style={styles.retryText}>Try again</Text>
          </Pressable>
        </View>
      ) : (
        <WebView
          ref={web}
          source={{ uri: app.url }}
          style={[styles.web, { backgroundColor: p.bg }]}
          injectedJavaScriptBeforeContentLoaded={(app.syncPrefs ? sessionJs(getSession()) + prefsJs(lang, p.mode) : '') + BRIDGE_JS}
          injectedJavaScript={FIT_JS}
          onMessage={onMessage}
          onNavigationStateChange={(s: { canGoBack: boolean }) => setCanGoBack(s.canGoBack)}
          onLoadEnd={() => {
            setLoading(false);
            setReloading(false);
          }}
          onError={() => setFailed(true)}
          onHttpError={(ev: { nativeEvent: { statusCode: number } }) => {
            if (ev.nativeEvent.statusCode >= 500) setFailed(true);
          }}
          onShouldStartLoadWithRequest={(req: { url: string }) => {
            // Stay inside ETEL; phone, mail and maps links open their own apps.
            if (ALLOWED_ORIGINS.some((o) => req.url.startsWith(o)) || req.url.startsWith('about:') || req.url.startsWith('blob:') || req.url.startsWith('data:')) return true;
            if (/^(tel:|mailto:|sms:|geo:|intent:|https:\/\/(www\.)?(google\.com\/maps|maps\.google\.com|waze\.com|wa\.me|t\.me))/.test(req.url)) {
              Linking.openURL(req.url).catch(() => {});
              return false;
            }
            return req.url.startsWith('https://');
          }}
          javaScriptEnabled
          domStorageEnabled
          geolocationEnabled
          sharedCookiesEnabled
          thirdPartyCookiesEnabled
          allowsInlineMediaPlayback
          mediaPlaybackRequiresUserAction={false}
          setSupportMultipleWindows={false}
          // Pull-to-refresh is off: it fought the map and the scrolling sheets, and could reload a page in the middle of a trip. The bar has Reload.
          pullToRefreshEnabled={false}
          scalesPageToFit={false}
          setBuiltInZoomControls={false}
          setDisplayZoomControls={false}
          automaticallyAdjustContentInsets={false}
          contentInsetAdjustmentBehavior="never"
          nestedScrollEnabled
          forceDarkOn={false}
          onRenderProcessGone={() => web.current?.reload()}
          cacheEnabled
          cacheMode="LOAD_DEFAULT"
          androidLayerType="hardware"
          overScrollMode="never"
          textZoom={100}
          startInLoadingState={false}
          originWhitelist={['https://*', 'http://localhost*']}
        />
      )}
      {loading && !failed ? (
        <View style={[styles.loading, { top: barTop + 48 }]} pointerEvents="none">
          <BrandLoader />
        </View>
      ) : null}
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
  web: { flex: 1, backgroundColor: colors.bg },
  loading: { position: 'absolute', left: 0, right: 0, bottom: 0, top: 84, alignItems: 'center', justifyContent: 'center' },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24 },
  msg: { color: colors.textSecondary, marginBottom: 16, textAlign: 'center' },
  retry: { borderWidth: 1, borderColor: colors.gold, borderRadius: 12, paddingVertical: 12, paddingHorizontal: 22 },
  retryText: { color: colors.gold, fontWeight: '700' },
});

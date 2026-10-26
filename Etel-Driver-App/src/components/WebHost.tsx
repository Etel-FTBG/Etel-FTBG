import React, { useCallback, useEffect, useRef } from 'react';
import { Linking, Pressable, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { WebView as RNWebView, WebViewMessageEvent } from 'react-native-webview';
import { HUB_APPS, ALLOWED_ORIGINS } from '../hub/apps';
import { BRIDGE_JS, FIT_JS, prefsJs } from '../hub/webBridge';
import { getSession, sessionJs, subscribeSession } from '../hub/session';
import { patchPage, primaryAppFor, registerHandle, resetHost, hostReload, useWebHost, warmApp, knownApp } from '../hub/webHost';
import BrandLoader from './BrandLoader';
import { Text } from '../i18n';
import { colors } from '../theme/colors';
import { useTheme } from '../theme/ThemeContext';
import { useLanguage } from '../i18n';
import { setToken } from '../api/client';
import { setOnlineState } from '../online/onlineState';
import { markCompanyOnline } from '../hub/companies';
import { startDriverLocationTracking, stopDriverLocationTracking } from '../location/backgroundLocationTask';
import { registerForPushNotifications } from '../notifications/registerPush';

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const WebView = RNWebView as unknown as React.ComponentType<any>;
type WebHandle = { goBack(): void; reload(): void };

async function onBridgeMessage(e: WebViewMessageEvent) {
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
}

function Page({ appKey, active, top, bottom, loading, failed }: { appKey: string; active: boolean; top: number; bottom: number; loading: boolean; failed: boolean }) {
  const app = HUB_APPS.find((a) => a.key === appKey);
  const web = useRef<WebHandle>(null);
  const { p } = useTheme();
  const { lang } = useLanguage();
  useEffect(() => {
    registerHandle(appKey, web.current);
    return () => registerHandle(appKey, null);
  }, [appKey]);
  if (!app) return null;
  // Hidden pages keep their full size (so they lay out and load normally) but are invisible and ignore touches.
  return (
    <View style={[styles.page, active ? { top, bottom } : styles.hidden]} pointerEvents={active ? 'auto' : 'none'}>
      <WebView
        ref={web}
        source={{ uri: app.url }}
        style={[styles.web, { backgroundColor: p.bg }]}
        injectedJavaScriptBeforeContentLoaded={(app.syncPrefs ? sessionJs(getSession()) + prefsJs(lang, p.mode) : '') + BRIDGE_JS}
        injectedJavaScript={FIT_JS}
        onMessage={onBridgeMessage}
        onNavigationStateChange={(s: { canGoBack: boolean }) => patchPage(appKey, { canGoBack: s.canGoBack })}
        onLoadEnd={() => patchPage(appKey, { loading: false })}
        onError={() => patchPage(appKey, { failed: true, loading: false })}
        onHttpError={(ev: { nativeEvent: { statusCode: number } }) => {
          if (ev.nativeEvent.statusCode >= 500) patchPage(appKey, { failed: true, loading: false });
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
      {active && failed ? (
        <View style={[styles.cover, styles.center, { backgroundColor: p.bg }]}>
          <Text style={[styles.msg, { color: p.textSecondary }]}>Could not load. Check your connection.</Text>
          <Pressable style={styles.retry} onPress={() => hostReload(appKey)}>
            <Text style={styles.retryText}>Try again</Text>
          </Pressable>
        </View>
      ) : null}
      {active && loading && !failed ? (
        <View style={[styles.cover, styles.center]} pointerEvents="none">
          <BrandLoader />
        </View>
      ) : null}
    </View>
  );
}

// Mounted once above the navigator. Keeps the opened web apps alive and loads the account's main app in the background after sign-in.
export default function WebHost() {
  const h = useWebHost();
  const insets = useSafeAreaInsets();
  const { p } = useTheme();
  const { lang } = useLanguage();
  const lastUser = useRef<string>('');

  const onSession = useCallback(() => {
    const s = getSession();
    const id = s ? s.id + ':' + s.token : '';
    if (id === lastUser.current) return;
    lastUser.current = id;
    resetHost();
    const key = primaryAppFor(s?.role);
    if (key && knownApp(key)) setTimeout(() => warmApp(key), 1500);
  }, []);

  useEffect(() => {
    onSession();
    return subscribeSession(onSession);
  }, [onSession]);

  if (!h.opened.length) return null;
  return (
    <View style={StyleSheet.absoluteFill} pointerEvents="box-none">
      {h.opened.map((k) => (
        // A new account, language or theme needs a fresh page (the login and preferences are injected as it loads).
        <Page key={k + '|' + lastUser.current + '|' + lang + '|' + p.mode} appKey={k} active={h.active === k} top={h.top} bottom={insets.bottom} loading={h.pages[k]?.loading ?? true} failed={h.pages[k]?.failed ?? false} />
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  page: { position: 'absolute', left: 0, right: 0 },
  hidden: { top: 0, bottom: 0, opacity: 0 },
  web: { flex: 1 },
  cover: { position: 'absolute', left: 0, right: 0, top: 0, bottom: 0 },
  center: { alignItems: 'center', justifyContent: 'center', padding: 24 },
  msg: { marginBottom: 16, textAlign: 'center' },
  retry: { borderWidth: 1, borderColor: colors.gold, borderRadius: 12, paddingVertical: 12, paddingHorizontal: 22 },
  retryText: { color: colors.gold, fontWeight: '700' },
});

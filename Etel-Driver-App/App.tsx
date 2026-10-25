import React from 'react';
import { StatusBar } from 'expo-status-bar';
import { NavigationContainer } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { ActivityIndicator, View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { useFonts, Syne_700Bold, Syne_800ExtraBold } from '@expo-google-fonts/syne';
import { JetBrainsMono_700Bold, JetBrainsMono_500Medium } from '@expo-google-fonts/jetbrains-mono';
import { CormorantGaramond_600SemiBold } from '@expo-google-fonts/cormorant-garamond';
import { AuthProvider, useAuth } from './src/context/AuthContext';
import { NightModeProvider } from './src/context/NightModeContext';
import { ThemeProvider, useTheme } from './src/theme/ThemeContext';
import { LanguageProvider } from './src/i18n';
import LoginScreen from './src/screens/LoginScreen';
import BottomTabs from './src/navigation/BottomTabs';
import ActiveRideScreen from './src/screens/ActiveRideScreen';
import GoalsScreen from './src/screens/GoalsScreen';
import DocumentsScreen from './src/screens/DocumentsScreen';
import WalletScreen from './src/screens/WalletScreen';
import NotificationsScreen from './src/screens/NotificationsScreen';
import HelpScreen from './src/screens/HelpScreen';
import HubScreen from './src/screens/HubScreen';
import WebAppScreen from './src/screens/WebAppScreen';
import BackgroundBadge from './src/online/BackgroundBadge';
import IntroSplash from './src/components/IntroSplash';
import UpdateBanner from './src/components/UpdateBanner';
import { RootStackParamList } from './src/navigation/types';
import { colors } from './src/theme/colors';
import ErrorBoundary from './src/components/ErrorBoundary';
import OfflineBanner from './src/components/OfflineBanner';
import AppLockGate from './src/security/AppLockGate';
import './src/location/backgroundLocationTask'; // registers the TaskManager task

const Stack = createNativeStackNavigator<RootStackParamList>();

function ThemedStatusBar() {
  const { mode } = useTheme();
  return <StatusBar style={mode === 'dark' ? 'light' : 'dark'} />;
}

function RootNavigator() {
  const { user, loading } = useAuth();

  if (loading) {
    return (
      <View style={{ flex: 1, backgroundColor: colors.bg, alignItems: 'center', justifyContent: 'center' }}>
        <ActivityIndicator color={colors.gold} size="large" />
      </View>
    );
  }

  return (
    <NavigationContainer>
      <Stack.Navigator initialRouteName="Hub" screenOptions={{ headerShown: false }}>
        <Stack.Screen name="Hub" component={HubScreen} />
        <Stack.Screen name="WebApp" component={WebAppScreen} options={{ animation: 'fade_from_bottom' }} />
        {user ? (
          <>
            <Stack.Screen name="MainTabs" component={BottomTabs} />
            <Stack.Screen name="ActiveRide" component={ActiveRideScreen} />
            <Stack.Screen name="Goals" component={GoalsScreen} />
            <Stack.Screen name="Documents" component={DocumentsScreen} />
            <Stack.Screen name="Wallet" component={WalletScreen} />
            <Stack.Screen name="Notifications" component={NotificationsScreen} />
            <Stack.Screen name="Help" component={HelpScreen} />
          </>
        ) : (
          <Stack.Screen name="Login" component={LoginScreen} />
        )}
      </Stack.Navigator>
      <ThemedStatusBar />
    </NavigationContainer>
  );
}

export default function App() {
  const [fontsLoaded] = useFonts({
    Syne_700Bold,
    Syne_800ExtraBold,
    JetBrainsMono_700Bold,
    JetBrainsMono_500Medium,
    CormorantGaramond_600SemiBold,
  });

  if (!fontsLoaded) {
    return (
      <View style={{ flex: 1, backgroundColor: colors.bg, alignItems: 'center', justifyContent: 'center' }}>
        <ActivityIndicator color={colors.gold} size="large" />
      </View>
    );
  }

  return (
    <SafeAreaProvider>
    <LanguageProvider>
    <ErrorBoundary>
      <AuthProvider>
        <NightModeProvider>
          <ThemeProvider>
            <AppLockGate>
              <RootNavigator />
            </AppLockGate>
            <OfflineBanner />
            <BackgroundBadge />
            <UpdateBanner />
            <IntroSplash />
          </ThemeProvider>
        </NightModeProvider>
      </AuthProvider>
    </ErrorBoundary>
    </LanguageProvider>
    </SafeAreaProvider>
  );
}

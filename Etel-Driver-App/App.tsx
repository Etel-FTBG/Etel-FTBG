import React from 'react';
import { StatusBar } from 'expo-status-bar';
import { NavigationContainer } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { ActivityIndicator, View } from 'react-native';
import { useFonts, Syne_700Bold, Syne_800ExtraBold } from '@expo-google-fonts/syne';
import { JetBrainsMono_700Bold, JetBrainsMono_500Medium } from '@expo-google-fonts/jetbrains-mono';
import { CormorantGaramond_600SemiBold } from '@expo-google-fonts/cormorant-garamond';
import { AuthProvider, useAuth } from './src/context/AuthContext';
import { NightModeProvider } from './src/context/NightModeContext';
import LoginScreen from './src/screens/LoginScreen';
import BottomTabs from './src/navigation/BottomTabs';
import ActiveRideScreen from './src/screens/ActiveRideScreen';
import GoalsScreen from './src/screens/GoalsScreen';
import DocumentsScreen from './src/screens/DocumentsScreen';
import WalletScreen from './src/screens/WalletScreen';
import NotificationsScreen from './src/screens/NotificationsScreen';
import HelpScreen from './src/screens/HelpScreen';
import { RootStackParamList } from './src/navigation/types';
import { colors } from './src/theme/colors';
import ErrorBoundary from './src/components/ErrorBoundary';
import OfflineBanner from './src/components/OfflineBanner';
import AppLockGate from './src/security/AppLockGate';
import './src/location/backgroundLocationTask'; // registers the TaskManager task

const Stack = createNativeStackNavigator<RootStackParamList>();

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
      <Stack.Navigator screenOptions={{ headerShown: false }}>
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
      <StatusBar style="light" />
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
    <ErrorBoundary>
      <AuthProvider>
        <NightModeProvider>
          <AppLockGate>
            <RootNavigator />
          </AppLockGate>
          <OfflineBanner />
        </NightModeProvider>
      </AuthProvider>
    </ErrorBoundary>
  );
}

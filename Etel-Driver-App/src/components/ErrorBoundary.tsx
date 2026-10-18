import React from 'react';
import { View, Pressable, StyleSheet } from 'react-native';
import { Text } from '../i18n';
import { Ionicons } from '@expo/vector-icons';
import { colors, typography } from '../theme/colors';

interface State {
  failed: boolean;
}

// Without this, an uncaught render error in any screen leaves the driver on a
// blank screen with no way out except force-closing the app.
export default class ErrorBoundary extends React.Component<{ children: React.ReactNode }, State> {
  state: State = { failed: false };

  static getDerivedStateFromError(): State {
    return { failed: true };
  }

  componentDidCatch(error: Error) {
    console.warn('[ErrorBoundary]', error.message);
  }

  render() {
    if (!this.state.failed) return this.props.children;
    return (
      <View style={styles.container}>
        <Ionicons name="alert-circle-outline" size={56} color={colors.gold} />
        <Text style={styles.title}>Something went wrong</Text>
        <Text style={styles.body}>The app hit an unexpected problem. Your online status and trips are safe.</Text>
        <Pressable style={styles.button} onPress={() => this.setState({ failed: false })}>
          <Text style={styles.buttonText}>Try again</Text>
        </Pressable>
      </View>
    );
  }
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.bg,
    alignItems: 'center',
    justifyContent: 'center',
    padding: 32,
  },
  title: { ...typography.h2, color: colors.text, marginTop: 16 },
  body: { color: colors.textSecondary, ...typography.body, textAlign: 'center', marginTop: 8 },
  button: {
    marginTop: 24,
    backgroundColor: colors.gold,
    borderRadius: 30,
    paddingVertical: 14,
    paddingHorizontal: 36,
  },
  buttonText: { color: colors.bg, fontWeight: '700', fontSize: 15 },
});

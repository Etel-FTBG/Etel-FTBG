import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import * as SecureStore from 'expo-secure-store';

// Light / dark for the ETEL shell (hub and the bar above each web app). The web apps follow it too (see hub/webBridge).
export type ThemeMode = 'dark' | 'light';

export interface Palette {
  mode: ThemeMode;
  bg: string;
  surface: string;
  surfaceAlt: string;
  text: string;
  textSecondary: string;
  textMuted: string;
  gold: string;
  goldStrong: string;
  border: string;
  borderSoft: string;
  glow: string;
  danger: string;
}

const DARK: Palette = {
  mode: 'dark',
  bg: '#0a0703',
  surface: '#130d05',
  surfaceAlt: '#1a130a',
  text: '#F8FAFC',
  textSecondary: '#C9BCA0',
  textMuted: '#8A7F68',
  gold: '#D4AF37',
  goldStrong: '#F2CC6B',
  border: 'rgba(212,175,55,0.40)',
  borderSoft: 'rgba(212,175,55,0.16)',
  glow: 'rgba(212,175,55,0.14)',
  danger: '#EF4444',
};

const LIGHT: Palette = {
  mode: 'light',
  bg: '#FBF7EC',
  surface: '#FFFFFF',
  surfaceAlt: '#F3ECD6',
  text: '#1A1408',
  textSecondary: '#5A4A22',
  textMuted: '#8A7A55',
  gold: '#B8860B',
  goldStrong: '#8A6508',
  border: 'rgba(138,101,8,0.45)',
  borderSoft: 'rgba(138,101,8,0.18)',
  glow: 'rgba(184,134,11,0.12)',
  danger: '#C62828',
};

const KEY = 'etel_shell_theme';

interface ThemeValue {
  p: Palette;
  mode: ThemeMode;
  toggle: () => void;
}

const ThemeContext = createContext<ThemeValue | undefined>(undefined);

export function ThemeProvider({ children }: { children: React.ReactNode }) {
  const [mode, setMode] = useState<ThemeMode>('dark');

  useEffect(() => {
    SecureStore.getItemAsync(KEY)
      .then((v) => {
        if (v === 'light') setMode('light');
      })
      .catch(() => {});
  }, []);

  const toggle = useCallback(() => {
    setMode((prev) => {
      const next: ThemeMode = prev === 'dark' ? 'light' : 'dark';
      SecureStore.setItemAsync(KEY, next).catch(() => {});
      return next;
    });
  }, []);

  const value = useMemo(() => ({ p: mode === 'dark' ? DARK : LIGHT, mode, toggle }), [mode, toggle]);
  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme(): ThemeValue {
  const ctx = useContext(ThemeContext);
  if (!ctx) throw new Error('useTheme must be used within ThemeProvider');
  return ctx;
}

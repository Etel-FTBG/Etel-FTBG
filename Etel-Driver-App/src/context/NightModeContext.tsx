import React, { createContext, useContext, useEffect, useState, useCallback } from 'react';
import * as SecureStore from 'expo-secure-store';

const KEY = 'etel_driver_night_mode';

interface NightModeContextValue {
  nightMode: boolean;
  toggle: () => void;
}

const NightModeContext = createContext<NightModeContextValue | undefined>(undefined);

export function NightModeProvider({ children }: { children: React.ReactNode }) {
  const [nightMode, setNightMode] = useState(false);

  useEffect(() => {
    SecureStore.getItemAsync(KEY).then((v) => {
      if (v === '1') setNightMode(true);
    });
  }, []);

  const toggle = useCallback(() => {
    setNightMode((prev) => {
      const next = !prev;
      SecureStore.setItemAsync(KEY, next ? '1' : '0').catch(() => {});
      return next;
    });
  }, []);

  return <NightModeContext.Provider value={{ nightMode, toggle }}>{children}</NightModeContext.Provider>;
}

export function useNightMode() {
  const ctx = useContext(NightModeContext);
  if (!ctx) throw new Error('useNightMode must be used within NightModeProvider');
  return ctx;
}

// Both maps use the real Etel Group gold/carbon palette (not a generic
// blue-dark Google style) — the brand is dark by identity, so even the
// "day" map stays carbon-toned, just lighter/more legible than night.
// Day: warm dark carbon land, gold-brown roads, cream labels.
export const DAY_MAP_STYLE = [
  { elementType: 'geometry', stylers: [{ color: '#1a130a' }] },
  { elementType: 'labels.text.fill', stylers: [{ color: '#C9BCA0' }] },
  { elementType: 'labels.text.stroke', stylers: [{ color: '#0d0a05' }] },
  { featureType: 'administrative.country', elementType: 'geometry.stroke', stylers: [{ color: '#8A7F68' }] },
  { featureType: 'landscape.man_made', elementType: 'geometry.stroke', stylers: [{ color: '#3a2c14' }] },
  { featureType: 'landscape.natural', elementType: 'geometry', stylers: [{ color: '#130d05' }] },
  { featureType: 'poi', elementType: 'geometry', stylers: [{ color: '#211a0d' }] },
  { featureType: 'poi', elementType: 'labels.text.fill', stylers: [{ color: '#8A7F68' }] },
  { featureType: 'poi.park', elementType: 'geometry.fill', stylers: [{ color: '#130d05' }] },
  { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#3a2c14' }] },
  { featureType: 'road', elementType: 'labels.text.fill', stylers: [{ color: '#C9BCA0' }] },
  { featureType: 'road.highway', elementType: 'geometry', stylers: [{ color: '#5a4420' }] },
  { featureType: 'road.highway', elementType: 'geometry.stroke', stylers: [{ color: '#C8960C' }] },
  { featureType: 'transit', elementType: 'labels.text.fill', stylers: [{ color: '#8A7F68' }] },
  { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#0d0d0d' }] },
  { featureType: 'water', elementType: 'labels.text.fill', stylers: [{ color: '#8A7F68' }] },
];

// Night: near-black carbon, dimmer gold-brown roads, lower-contrast labels —
// better visibility (less glare) for night driving specifically.
export const NIGHT_MAP_STYLE = [
  { elementType: 'geometry', stylers: [{ color: '#0a0703' }] },
  { elementType: 'labels.text.fill', stylers: [{ color: '#8A7F68' }] },
  { elementType: 'labels.text.stroke', stylers: [{ color: '#030205' }] },
  { featureType: 'administrative.country', elementType: 'geometry.stroke', stylers: [{ color: '#4a4030' }] },
  { featureType: 'landscape.man_made', elementType: 'geometry.stroke', stylers: [{ color: '#241c0e' }] },
  { featureType: 'landscape.natural', elementType: 'geometry', stylers: [{ color: '#0d0a05' }] },
  { featureType: 'poi', elementType: 'geometry', stylers: [{ color: '#130d05' }] },
  { featureType: 'poi', elementType: 'labels.text.fill', stylers: [{ color: '#5a5240' }] },
  { featureType: 'poi.park', elementType: 'geometry.fill', stylers: [{ color: '#0d0a05' }] },
  { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#211a0d' }] },
  { featureType: 'road', elementType: 'labels.text.fill', stylers: [{ color: '#6f6650' }] },
  { featureType: 'road.highway', elementType: 'geometry', stylers: [{ color: '#3a2c14' }] },
  { featureType: 'road.highway', elementType: 'geometry.stroke', stylers: [{ color: '#8A6C1E' }] },
  { featureType: 'transit', elementType: 'labels.text.fill', stylers: [{ color: '#5a5240' }] },
  { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#020202' }] },
  { featureType: 'water', elementType: 'labels.text.fill', stylers: [{ color: '#4a4030' }] },
];

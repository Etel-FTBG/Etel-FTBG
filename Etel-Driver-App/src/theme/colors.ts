// Real Etel Group brand tokens (gold/carbon), matching the design system
// already used across EtelID/EtelPay/Delivery admin & portal surfaces —
// not a generic ride-hailing palette. See etel-group-ops project notes:
// dark carbon backgrounds (#030205/#0d0d0d/#0a0703/#130d05), gold accents
// (#C8960C/#D4AF37/#F2CC6B), gold-bordered "gold box" card treatment.
export const colors = {
  bg: '#0a0703',
  surface: '#130d05',
  surface2: '#0d0d0d',
  surface3: '#1a130a',

  gold: '#D4AF37',
  goldDark: '#C8960C',
  goldLight: '#F2CC6B',

  success: '#16A34A',
  successLight: '#4ADE80',
  info: '#3B82F6',
  danger: '#EF4444',
  warning: '#F59E0B',

  text: '#F8FAFC',
  textSecondary: '#C9BCA0',
  textMuted: '#8A7F68',
  border: 'rgba(212,175,55,0.35)',
};

export const typography = {
  h1: { fontSize: 30, fontWeight: '700' as const, fontFamily: 'Syne_700Bold' },
  h2: { fontSize: 22, fontWeight: '600' as const, fontFamily: 'Syne_700Bold' },
  display: { fontSize: 26, fontFamily: 'CormorantGaramond_600SemiBold' },
  metric: { fontSize: 28, fontWeight: '700' as const, fontFamily: 'JetBrainsMono_700Bold' },
  label: { fontSize: 12, fontFamily: 'JetBrainsMono_500Medium', letterSpacing: 0.5 },
  body: { fontSize: 15 },
  secondary: { fontSize: 13 },
  small: { fontSize: 11 },
};

import { DefaultTheme, type Theme } from 'expo-router';

/**
 * Mirrors the `:root` variables in global.css exactly. The app ships a single
 * light palette (there is no `.dark:root` block), and app/_layout.tsx pins
 * NativeWind to light so a phone in dark mode never flips `dark:` variants.
 */
export const THEME = {
  light: {
    background: 'hsl(0 0% 100%)',
    foreground: 'hsl(0 0% 3.9%)',
    card: 'hsl(0 0% 100%)',
    cardForeground: 'hsl(0 0% 3.9%)',
    popover: 'hsl(0 0% 100%)',
    popoverForeground: 'hsl(0 0% 3.9%)',
    primary: 'hsl(221 84% 23%)',
    primaryForeground: 'hsl(0 0% 100%)',
    secondary: 'hsl(0 0% 96.1%)',
    secondaryForeground: 'hsl(0 0% 9%)',
    muted: 'hsl(0 0% 96.1%)',
    mutedForeground: 'hsl(0 0% 45.1%)',
    accent: 'hsl(220 20% 94%)',
    accentForeground: 'hsl(221 84% 23%)',
    destructive: 'hsl(350 84% 45%)',
    border: 'hsl(0 0% 92.8%)',
    input: 'hsl(0 0% 89.8%)',
    ring: 'hsl(221 84% 23%)',
    radius: '0.5rem',
    chart1: 'hsl(12 76% 61%)',
    chart2: 'hsl(173 58% 39%)',
    chart3: 'hsl(197 37% 24%)',
    chart4: 'hsl(43 74% 66%)',
    chart5: 'hsl(27 87% 67%)',
  },
};

export const NAV_THEME: { light: Theme } = {
  light: {
    ...DefaultTheme,
    colors: {
      background: THEME.light.background,
      border: THEME.light.border,
      card: THEME.light.card,
      notification: THEME.light.destructive,
      primary: THEME.light.primary,
      text: THEME.light.foreground,
    },
  },
};

/**
 * Raw sRGB values for the places className cannot reach (icon `color` props,
 * placeholder colours, ActivityIndicator, inline styles, Reanimated nodes).
 * Every value is the sRGB form of the matching global.css token; scripts/check-colors.js
 * rejects hex literals everywhere else, so reach for these instead.
 */
export const COLORS = {
  /** --om-navy / --primary (brand mark colour) */
  navy: '#0a2a6b',
  /** --om-navy-hover */
  navyHover: '#0c3388',
  /** --om-sale: badges and fills */
  sale: '#eb193c',
  /** --om-sale-text / --destructive: 12px error and price text on white (5.4:1) */
  saleText: '#d31232',
  /** --om-surface: image placeholders, page background wells */
  surface: '#f3f4f6',
  /** --border */
  border: '#ededed',
  /** --muted-foreground (4.7:1 on white) */
  mutedForeground: '#737373',
  /** Cool grey for secondary icons */
  slate: '#64748b',
  /** Input placeholder (3.25:1) */
  placeholder: '#8a8f98',
  /** --om-disabled */
  disabled: '#d8dbe4',
  white: '#ffffff',
  /** --om-star */
  star: '#ffb300',
  /** --om-star-empty */
  starEmpty: '#d9d9d9',
  /** --om-warning (offline strip) */
  warning: '#b0640c',
  /** --om-success (recovery strip) */
  success: '#0c6a4c',
} as const;

/** Spread into an image/container style while the picture loads. */
export const IMAGE_PLACEHOLDER = { backgroundColor: COLORS.surface };

/**
 * Elevation rule: level 0 is a border only (cards, inputs); level 1 is
 * SHADOW.card (a card overlapping a header); level 2 is SHADOW.bar (sticky
 * bars, toasts).
 */
export const SHADOW = {
  card: {
    shadowColor: COLORS.navy,
    shadowOpacity: 0.04,
    shadowRadius: 2,
    shadowOffset: { width: 0, height: 1 },
    elevation: 1,
  },
  bar: {
    shadowColor: COLORS.navy,
    shadowOpacity: 0.06,
    shadowRadius: 8,
    shadowOffset: { width: 0, height: -2 },
    elevation: 8,
  },
} as const;

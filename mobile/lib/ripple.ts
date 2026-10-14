import type { PressableAndroidRippleConfig } from 'react-native';

/**
 * Android press feedback presets for `android_ripple`. iOS ignores the prop, so
 * pair rows with `active:opacity-70` (or PressableScale for cards and tiles).
 */
export const ripple = {
  /** List rows and full-width tappable surfaces on white. */
  row: { color: 'rgba(10,42,107,0.08)' },
  /** Round icon buttons on white (header icons, steppers). */
  icon: { color: 'rgba(10,42,107,0.12)', borderless: true, radius: 20 },
  /** Round icon buttons on a navy surface. */
  onNavy: { color: 'rgba(255,255,255,0.25)', borderless: true, radius: 22 },
} satisfies Record<string, PressableAndroidRippleConfig>;

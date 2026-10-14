import { Text } from '@/components/ui/text';
import { COLORS } from '@/lib/theme';
import { Image } from 'expo-image';
import { ActivityIndicator, Platform, View } from 'react-native';

// Android 12+ (API 31) draws the system splash as an icon inside a circular mask, so the mark is
// the closest match there. iOS and older Android show the white lockup, centred at 200pt.
const SHOWS_MARK = Platform.OS === 'android' && Number(Platform.Version) >= 31;

const LOCKUP = require('@/assets/images/splash-icon.png');
const MARK = require('@/assets/images/logo-mark-white.png');

/**
 * Shown when the app is slow to get ready. Navy in both color schemes (like the native splash),
 * with the logo in the same place and size so the hand-off from the splash does not jump.
 */
export function BootScreen() {
  return (
    <View
      accessible
      accessibilityLabel="Menyiapkan OceanMall"
      accessibilityLiveRegion="polite"
      className="flex-1 items-center justify-center"
      // The exact native-splash navy (not the om-navy token, which computes one unit off).
      style={{ backgroundColor: COLORS.navy }}>
      <Image
        source={SHOWS_MARK ? MARK : LOCKUP}
        style={SHOWS_MARK ? { width: 112, height: 112 } : { width: 200, height: 200 }}
        contentFit="contain"
        accessible={false}
      />
      {/* Absolute so the logo stays exactly where the native splash drew it. */}
      <View
        pointerEvents="none"
        className="absolute inset-x-0 items-center"
        style={{ top: '50%', marginTop: 116 }}>
        <ActivityIndicator color={COLORS.white} />
        <Text className="mt-3 text-[13px] text-white/70" maxFontSizeMultiplier={1.2}>
          Menyiapkan OceanMall…
        </Text>
      </View>
    </View>
  );
}

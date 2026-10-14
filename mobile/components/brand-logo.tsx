import { Image } from 'expo-image';
import { View } from 'react-native';

// logo-wordmark(-white).png are trimmed crops of the OCEANMALL band: 829 x 96.
const WORDMARK_RATIO = 829 / 96;

const MARK = {
  light: require('@/assets/images/logo-mark-white.png'),
  dark: require('@/assets/images/logo-mark.png'),
};

const WORDMARK = {
  light: require('@/assets/images/logo-wordmark-white.png'),
  dark: require('@/assets/images/logo-wordmark.png'),
};

/**
 * The OceanMall mark with the wordmark beside it.
 * `light` is for navy backgrounds (white artwork), `dark` for white backgrounds (two-tone artwork).
 */
export function BrandLogo({
  variant,
  size = 28,
  withWordmark = true,
}: {
  variant: 'light' | 'dark';
  /** Edge of the square the mark is fitted into; the wordmark scales with it. */
  size?: number;
  withWordmark?: boolean;
}) {
  const wordmarkHeight = Math.round(size * 0.5);

  return (
    <View
      accessible
      accessibilityRole="image"
      accessibilityLabel="OceanMall"
      className="flex-row items-center gap-2">
      <Image
        source={MARK[variant]}
        style={{ width: size, height: size }}
        contentFit="contain"
        accessible={false}
      />
      {withWordmark ? (
        <Image
          source={WORDMARK[variant]}
          style={{ width: Math.round(wordmarkHeight * WORDMARK_RATIO), height: wordmarkHeight }}
          contentFit="contain"
          accessible={false}
        />
      ) : null}
    </View>
  );
}
